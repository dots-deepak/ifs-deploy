<?php
declare(strict_types=1);

namespace IfsDeploy\Support;

/**
 * Finds everything on THIS site that refers to an attachment by its bare numeric id.
 *
 * ── WHAT THIS IS FOR ───────────────────────────────────────────────────────────────
 *
 * It is the safety gate on renumbering. WordPress has no API for changing a post's id,
 * and `wp_posts.ID` is referenced from a genuinely open-ended set of places — so the only
 * attachment that can be safely moved is one that NOTHING refers to yet. This decides
 * whether that is the case, and when it is not, it names what is in the way.
 *
 * ── WHY IT REFUSES RATHER THAN REWRITES ───────────────────────────────────────────
 *
 * Rewriting the references instead would mean editing post content, postmeta, options,
 * term relationships and whatever any page builder or third-party plugin stores, in one
 * unsynchronised pass, with a broken image as the failure mode and no error anywhere. The
 * list below is long and still cannot be complete: any plugin may store an attachment id
 * in a shape nothing here knows to look for.
 *
 * That incompleteness is precisely why this class only ever answers "definitely nothing"
 * or "here is something". A false "in use" costs a refusal. A false "unused" corrupts the
 * site quietly, so every check errs toward finding a reference.
 *
 * ── THE SEARCH IS DELIBERATELY OVER-BROAD ─────────────────────────────────────────
 *
 * `has_meta_reference()` matches the number anywhere in a meta value, not only as the
 * whole value. That catches serialised ACF galleries, Elementor JSON and comma-separated
 * id lists — at the cost of matching an unrelated `123` inside some other number or a
 * timestamp. Over-matching produces a refusal, which is recoverable; under-matching does
 * not.
 */
final class MediaReferences {

	/**
	 * Everything referring to this attachment.
	 *
	 * @param int $attachment_id The attachment to look for.
	 * @param int $limit         Stop after this many; 0 for no limit.
	 * @return array<int,array{kind:string,label:string,id:int}>
	 */
	public static function find( int $attachment_id, int $limit = 0 ): array {
		global $wpdb;

		if ( $attachment_id <= 0 ) {
			return array();
		}

		$found = array();
		$id    = (string) $attachment_id;

		/*
		 * 1. FEATURED IMAGE — an exact meta match, so it is cheap and unambiguous.
		 */
		$thumbs = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s LIMIT 25",
				$id
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		foreach ( $thumbs as $post_id ) {
			$found[] = self::post_ref( 'featured', (int) $post_id );

			if ( self::enough( $found, $limit ) ) {
				return $found;
			}
		}

		/*
		 * 2. INLINE IN CONTENT — the editor writes `wp-image-123` onto every inserted
		 * image, Gutenberg writes `"id":123` into the block comment, and the classic
		 * gallery shortcode writes `ids="1,2,3"`. All three live in post_content.
		 *
		 * LIKE rather than REGEXP because the boundary characters are what disambiguate
		 * `wp-image-12` from `wp-image-123`, and each pattern carries its own.
		 */
		$patterns = array(
			'%wp-image-' . $wpdb->esc_like( $id ) . '"%',
			'%wp-image-' . $wpdb->esc_like( $id ) . ' %',
			'%"id":' . $wpdb->esc_like( $id ) . ',%',
			'%"id":' . $wpdb->esc_like( $id ) . '}%',
			'%ids="' . $wpdb->esc_like( $id ) . '"%',
			'%ids="' . $wpdb->esc_like( $id ) . ',%',
			'%,' . $wpdb->esc_like( $id ) . '"%',
			'%,' . $wpdb->esc_like( $id ) . ',%',
			'%attachment_' . $wpdb->esc_like( $id ) . '%',
		);

		foreach ( $patterns as $pattern ) {
			$posts = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_status != 'auto-draft' AND post_content LIKE %s LIMIT 25",
					$pattern
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			foreach ( $posts as $post_id ) {
				$found[] = self::post_ref( 'content', (int) $post_id );

				if ( self::enough( $found, $limit ) ) {
					return $found;
				}
			}
		}

		/*
		 * 3. ANY OTHER POSTMETA — ACF image/gallery/file fields, page-builder payloads,
		 * theme options stored per post. The key is unknown, so the VALUE is searched.
		 *
		 * `_wp_attached_file` and friends belong to the attachment itself and are excluded
		 * by post_id: meta ON the attachment is not a reference TO it, and would otherwise
		 * make every attachment look permanently in use.
		 */
		$meta = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
				 WHERE post_id != %d
				   AND meta_key != '_thumbnail_id'
				   AND meta_key NOT LIKE '\\_ifs\\_deploy\\_%%'
				   AND meta_value LIKE %s
				 LIMIT 50",
				$attachment_id,
				'%' . $wpdb->esc_like( $id ) . '%'
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		foreach ( $meta as $row ) {
			// The LIKE above matches the digits anywhere, so `1234` came back for `123`
			// too. This is where that is narrowed to the number itself.
			if ( ! self::has_meta_reference( (string) $row['meta_value'], $attachment_id ) ) {
				continue;
			}

			$found[] = self::post_ref( 'meta', (int) $row['post_id'], (string) $row['meta_key'] );

			if ( self::enough( $found, $limit ) ) {
				return $found;
			}
		}

		/*
		 * 4. SITE-WIDE OPTIONS — `site_icon` and `custom_logo` hold a bare attachment id,
		 * and theme mods hold them inside a serialised array. An image used as the site
		 * logo is very much in use even though nothing links to it.
		 */
		foreach ( array( 'site_icon', 'custom_logo' ) as $option ) {
			if ( (int) get_option( $option ) === $attachment_id ) {
				$found[] = array( 'kind' => 'option', 'label' => $option, 'id' => 0 );

				if ( self::enough( $found, $limit ) ) {
					return $found;
				}
			}
		}

		$mods = get_theme_mods();

		if ( is_array( $mods ) && self::has_meta_reference( (string) wp_json_encode( $mods ), $attachment_id ) ) {
			$found[] = array( 'kind' => 'option', 'label' => __( 'theme settings', 'ifs-deploy' ), 'id' => 0 );

			if ( self::enough( $found, $limit ) ) {
				return $found;
			}
		}

		/*
		 * 5. TERMS ON THE ATTACHMENT ITSELF. Not a reference in the same sense, but
		 * renumbering orphans `term_relationships.object_id`, so the categories or tags
		 * would silently detach. Anything that breaks on a renumber belongs here.
		 */
		$terms = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE object_id = %d", $attachment_id )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( $terms > 0 ) {
			$found[] = array( 'kind' => 'term', 'label' => __( 'taxonomy terms on this file', 'ifs-deploy' ), 'id' => 0 );
		}

		return $found;
	}

	/**
	 * Does this meta value refer to the id as a NUMBER, rather than merely containing
	 * those digits inside a longer one?
	 *
	 * `(?<![0-9])123(?![0-9])` is the whole idea: it accepts `123`, `"123"`, `i:123;`,
	 * `[122,123,124]` and `123,124`, and rejects `1234` and `41235`. It still accepts a
	 * `123` that happens to be a completely unrelated number — a font size, a term id —
	 * and that is the intended bias. See the class comment.
	 */
	public static function has_meta_reference( string $value, int $attachment_id ): bool {
		if ( '' === $value || $attachment_id <= 0 ) {
			return false;
		}

		return 1 === preg_match( '/(?<![0-9])' . preg_quote( (string) $attachment_id, '/' ) . '(?![0-9])/', $value );
	}

	/**
	 * Describe a referring post, falling back to its id when it cannot be read.
	 *
	 * @return array{kind:string,label:string,id:int}
	 */
	private static function post_ref( string $kind, int $post_id, string $meta_key = '' ): array {
		$post  = get_post( $post_id );
		$title = $post instanceof \WP_Post && '' !== $post->post_title
			? (string) $post->post_title
			/* translators: %d: post id */
			: sprintf( __( 'post %d', 'ifs-deploy' ), $post_id );

		if ( '' !== $meta_key ) {
			/* translators: 1: post title, 2: custom field name */
			$title = sprintf( __( '%1$s (field: %2$s)', 'ifs-deploy' ), $title, $meta_key );
		}

		return array( 'kind' => $kind, 'label' => $title, 'id' => $post_id );
	}

	/**
	 * @param array<int,mixed> $found
	 */
	private static function enough( array $found, int $limit ): bool {
		return $limit > 0 && count( $found ) >= $limit;
	}
}
