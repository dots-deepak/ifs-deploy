<?php
declare(strict_types=1);

namespace IfsDeploy\Import;

use IfsDeploy\Support\ContentFirewall;
use IfsDeploy\Support\DebugLog;
use IfsDeploy\Support\MediaIdentity;
use IfsDeploy\Support\SafeData;
use IfsDeploy\Support\UrlRewriter;
use WP_Error;

/**
 * Applies a deployment package to a post on Production using WordPress APIs
 * only — no raw SQL writes to content.
 *
 * Cross-site identity: each deployed post is stamped with the originating post
 * ID + site UUID. Subsequent deploys of the same source post update the same
 * production post, even if its slug changes.
 */
final class PostImporter {

	public const ORIGIN_ID_META   = '_ifs_deploy_origin_id';
	public const ORIGIN_SITE_META = '_ifs_deploy_origin_site';
	public const SRC_SIG_META     = '_ifs_deploy_src_sig';
	private const SOURCE_URL_META  = MediaIdentity::SOURCE_URL_META;

	private MediaUrlResolver $media_urls;

	/**
	 * What the content firewall removed (or would remove) from THIS package.
	 *
	 * Collected rather than logged inline, so one entry names the object it belongs to.
	 * "A script tag was removed" is not actionable; "a script tag was removed from
	 * About Us" is.
	 *
	 * @var array<int,string[]>
	 */
	private array $firewall_notes = array();

	public function __construct( ?MediaUrlResolver $media_urls = null ) {
		$this->media_urls = $media_urls ?? new MediaUrlResolver();
	}

	/**
	 * @return array{post_id:int,created:bool}|WP_Error
	 */
	public function import( array $package ) {
		if ( ( $package['type'] ?? '' ) !== 'post' ) {
			return new WP_Error( 'ifs_deploy_bad_type', __( 'Unsupported object type.', 'ifs-deploy' ) );
		}

		$origin_id   = (int) ( $package['origin_id'] ?? 0 );
		$origin_site = (string) ( $package['origin_site'] ?? '' );
		$fields      = (array) ( $package['object'] ?? array() );

		if ( 'delete' === ( $package['action'] ?? 'update' ) ) {
			return $this->delete_post( $package );
		}

		if ( $origin_id <= 0 || empty( $fields ) ) {
			return new WP_Error( 'ifs_deploy_bad_package', __( 'Malformed package.', 'ifs-deploy' ) );
		}

		$existing_id = $this->find_target( $package );
		$origin_url  = (string) ( $package['origin_url'] ?? '' );

		// Media that Production had to save under a different filename (or in a
		// different month folder) needs its URLs pointed at the file that actually
		// exists here — a domain swap alone would leave them broken.
		$media_map = $this->media_urls->build_map( $this->package_strings( $package ), $origin_url );

		$postarr = array(
			'post_title'     => (string) ( $fields['post_title'] ?? '' ),
			'post_content'   => $this->rewrite_content( (string) ( $fields['post_content'] ?? '' ), $origin_url, $media_map ),
			'post_excerpt'   => $this->rewrite_content( (string) ( $fields['post_excerpt'] ?? '' ), $origin_url, $media_map ),
			'post_status'    => (string) ( $fields['post_status'] ?? 'draft' ),
			'post_name'      => (string) ( $fields['post_name'] ?? '' ),
			'post_type'      => (string) ( $fields['post_type'] ?? 'post' ),
			'menu_order'     => (int) ( $fields['menu_order'] ?? 0 ),
			'comment_status' => (string) ( $fields['comment_status'] ?? 'closed' ),
			'ping_status'    => (string) ( $fields['ping_status'] ?? 'closed' ),
			'post_password'  => (string) ( $fields['post_password'] ?? '' ),
			'post_date'      => (string) ( $fields['post_date'] ?? '' ),
			'post_parent'    => $this->map_parent( (int) ( $fields['post_parent'] ?? 0 ), $origin_site, (string) ( $fields['post_type'] ?? 'post' ) ),
		);

		$created = false;
		if ( $existing_id ) {
			$postarr['ID'] = $existing_id;
			$result        = wp_update_post( wp_slash( $postarr ), true );
		} else {
			// Preserve the source ID on Production so cross-references (menus, ACF
			// relations, inline media) line up on both sites. import_id is honored
			// only when that ID is free; WordPress assigns a new one on collision.
			if ( $origin_id > 0 ) {
				$postarr['import_id'] = $origin_id;
			}
			$result  = wp_insert_post( wp_slash( $postarr ), true );
			$created = true;
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$post_id = (int) $result;

		// Stamp identity for future deploys.
		update_post_meta( $post_id, self::ORIGIN_ID_META, $origin_id );
		update_post_meta( $post_id, self::ORIGIN_SITE_META, $origin_site );

		// Stamp the Staging-computed signature of what we just deployed, so the
		// Compare screen can tell "in sync" from "drifted" reliably.
		update_post_meta( $post_id, self::SRC_SIG_META, (string) ( $package['signature'] ?? '' ) );

		// ACF values travel inside the raw post meta below (ACF stores everything
		// in postmeta). We deliberately do NOT call update_field(): writing raw
		// meta verbatim preserves shortcodes and avoids ACF re-formatting.
		$this->apply_meta( $post_id, (array) ( $package['meta'] ?? array() ), $origin_url, $media_map );
		$this->apply_taxonomies( $post_id, (array) ( $package['taxonomies'] ?? array() ) );
		$this->apply_featured_image( $post_id, $package['featured_image'] ?? null );

		$this->report_firewall( $post_id, (string) ( $fields['post_title'] ?? '' ) );

		return array(
			'post_id' => $post_id,
			'created' => $created,
		);
	}

	/**
	 * Say what the content firewall did to this object, once, naming it.
	 *
	 * A lossy deploy the user cannot see is worse than no filter at all — that is the whole
	 * reason `report` mode exists — so this runs in every mode. The wording differs because
	 * the two situations are genuinely different: in `report` nothing was changed, in
	 * `filter` something was.
	 */
	private function report_firewall( int $post_id, string $title ): void {
		if ( empty( $this->firewall_notes ) ) {
			return;
		}

		$removed = array_values( array_unique( array_merge( ...$this->firewall_notes ) ) );

		$this->firewall_notes = array();

		$label = '' !== $title ? $title : sprintf( '#%d', $post_id );

		if ( ContentFirewall::MODE_FILTER === ContentFirewall::mode() ) {
			DebugLog::warning(
				sprintf(
					/* translators: 1: post title, 2: comma-separated list of what was removed */
					__( 'Removed disallowed markup from "%1$s" on import: %2$s', 'ifs-deploy' ),
					$label,
					implode( ', ', $removed )
				),
				array(
					'post_id' => (string) $post_id,
					'removed' => implode( ', ', $removed ),
					'mode'    => ContentFirewall::MODE_FILTER,
				)
			);

			return;
		}

		DebugLog::warning(
			sprintf(
				/* translators: 1: post title, 2: comma-separated list of what would be removed */
				__( '"%1$s" contains markup that would be removed if the content firewall were switched on: %2$s. It was stored unchanged.', 'ifs-deploy' ),
				$label,
				implode( ', ', $removed )
			),
			array(
				'post_id' => (string) $post_id,
				'would_remove' => implode( ', ', $removed ),
				'mode'    => ContentFirewall::mode(),
			)
		);
	}

	/**
	 * Rewrite Staging URLs to the Production domain so inline images and links
	 * resolve after deployment. Matches with and without a trailing slash.
	 *
	 * Shared with the preview via UrlRewriter so both sides always agree on what
	 * the rewritten content will look like.
	 */
	private function rewrite_urls( string $content, string $origin_url ): string {
		return UrlRewriter::rewrite( $content, $origin_url, home_url() );
	}

	/**
	 * Renamed-media URLs first, then the general domain rewrite.
	 *
	 * Order matters: the media map keys are full Staging URLs, so it has to run
	 * before the domain is swapped out from under them. Its values are already
	 * Production URLs, which the domain rewrite then leaves alone.
	 *
	 * @param array<string,string> $media_map
	 */
	private function rewrite_content( string $content, string $origin_url, array $media_map ): string {
		$rewritten = $this->rewrite_urls( $this->media_urls->apply( $content, $media_map ), $origin_url );

		/*
		 * The content firewall runs LAST, after the URL rewrites (SECURITY.md H-2).
		 *
		 * Order matters. Rewriting first means the firewall judges the markup that will
		 * actually be stored — a URL that only becomes `javascript:` after rewriting would
		 * otherwise slip past a check done earlier.
		 *
		 * In `report` mode this returns the content UNCHANGED and only records what would
		 * have been removed, so upgrading changes nothing about the next deploy.
		 */
		$result = ContentFirewall::apply( $rewritten, 'post_content' );

		if ( $result['changed'] ) {
			$this->firewall_notes[] = $result['removed'];
		}

		return (string) $result['html'];
	}

	/**
	 * Every string in a package that could contain a media URL — core content plus
	 * meta at any depth, since ACF WYSIWYG fields and galleries live there.
	 *
	 * @return string[]
	 */
	private function package_strings( array $package ): array {
		$strings = array(
			(string) ( $package['object']['post_content'] ?? '' ),
			(string) ( $package['object']['post_excerpt'] ?? '' ),
		);

		// Hand-rolled rather than array_walk_recursive(): that takes its array by
		// reference, so it cannot be handed an expression like `(array) ( … ?? [] )`
		// — doing so is a fatal Error, not a notice.
		$this->collect_strings( $package['meta'] ?? array(), $strings );

		return $strings;
	}

	/**
	 * Gather every string leaf of a nested value.
	 *
	 * @param mixed    $value
	 * @param string[] $into
	 */
	private function collect_strings( $value, array &$into ): void {
		if ( is_string( $value ) ) {
			$into[] = $value;
			return;
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				$this->collect_strings( $item, $into );
			}
		}
	}

	/**
	 * Handle a delete package: trash (reversible) the matched production post.
	 * No-op when no matching post is found — never force-deletes.
	 *
	 * @return array{post_id:int,created:bool}
	 */
	private function delete_post( array $package ): array {
		$existing = $this->find_target( $package );
		if ( $existing ) {
			wp_trash_post( $existing );
		}
		return array( 'post_id' => $existing, 'created' => false );
	}

	/**
	 * Resolve the existing production post a package should update, so updates go
	 * to the existing page instead of creating a duplicate.
	 *
	 * Strategies, in order:
	 *   1. origin — previously deployed (origin id + site meta). Most precise.
	 *   2. id     — same post ID exists with the same type (cloned sites share IDs).
	 *   3. slug   — same post_name + post_type.
	 *
	 * Returns 0 when no match is found (a genuinely new object → insert).
	 */
	public function find_target( array $package ): int {
		return $this->locate( $package )['id'];
	}

	/**
	 * Same resolution as find_target(), but also reports which strategy matched.
	 * Used by the preview so the admin can see how confident the match is.
	 *
	 * @return array{id:int,strategy:string} strategy is 'origin'|'id'|'slug'|'none'.
	 */
	public function locate( array $package ): array {
		$origin_id   = (int) ( $package['origin_id'] ?? 0 );
		$origin_site = (string) ( $package['origin_site'] ?? '' );
		$fields      = (array) ( $package['object'] ?? array() );
		$type        = (string) ( $fields['post_type'] ?? 'post' );
		$slug        = (string) ( $fields['post_name'] ?? '' );

		/**
		 * Filter the match strategies (and their order) used to find the target
		 * production post.
		 *
		 * @param string[] $strategies Any of: 'origin', 'id', 'slug'.
		 * @param array    $package
		 */
		$strategies = (array) apply_filters( 'ifs_deploy_match_strategies', array( 'origin', 'id', 'slug' ), $package );

		foreach ( $strategies as $strategy ) {
			$match = 0;
			switch ( $strategy ) {
				case 'origin':
					$match = $this->find_by_origin( $origin_id, $origin_site );
					break;
				case 'id':
					$match = $this->match_by_id( $origin_id, $type );
					break;
				case 'slug':
					$match = $this->match_by_slug( $slug, $type );
					break;
			}
			if ( $match ) {
				return array( 'id' => $match, 'strategy' => (string) $strategy );
			}
		}

		return array( 'id' => 0, 'strategy' => 'none' );
	}

	/**
	 * Locate the production post previously deployed from this origin.
	 */
	public function find_by_origin( int $origin_id, string $origin_site ): int {
		if ( $origin_id <= 0 || '' === $origin_site ) {
			return 0;
		}

		$query = new \WP_Query(
			array(
				'post_type'              => 'any',
				'post_status'            => 'any',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					'relation' => 'AND',
					array(
						'key'   => self::ORIGIN_ID_META,
						'value' => $origin_id,
					),
					array(
						'key'   => self::ORIGIN_SITE_META,
						'value' => $origin_site,
					),
				),
			)
		);

		return $query->posts ? (int) $query->posts[0] : 0;
	}

	/**
	 * Match a post by exact ID when the type also matches (cloned sites).
	 */
	private function match_by_id( int $id, string $type ): int {
		if ( $id <= 0 ) {
			return 0;
		}
		$post = get_post( $id );
		return ( $post instanceof \WP_Post && $post->post_type === $type ) ? $id : 0;
	}

	/**
	 * Match a post by slug + type.
	 */
	private function match_by_slug( string $slug, string $type ): int {
		if ( '' === $slug ) {
			return 0;
		}

		$query = new \WP_Query(
			array(
				'name'                   => $slug,
				'post_type'              => $type,
				'post_status'            => 'any',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return $query->posts ? (int) $query->posts[0] : 0;
	}

	/**
	 * Map a parent's origin id to the local parent id (origin meta, then same id).
	 */
	private function map_parent( int $origin_parent_id, string $origin_site, string $type ): int {
		if ( $origin_parent_id <= 0 ) {
			return 0;
		}
		$by_origin = $this->find_by_origin( $origin_parent_id, $origin_site );
		if ( $by_origin ) {
			return $by_origin;
		}
		return $this->match_by_id( $origin_parent_id, $type );
	}

	/**
	 * Replace provided meta keys. Keys absent from the package are left intact so
	 * production-managed meta is not destroyed.
	 *
	 * Source-site URLs are rewritten here too, at any depth. ACF content (WYSIWYG
	 * fields, repeaters, flexible content) lives in meta, and it routinely contains
	 * absolute internal links. Copying it verbatim used to leave Production pointing
	 * at Staging — the post_content rewrite alone never covered it. Values are
	 * unserialized first, so the rewrite happens on real strings rather than on a
	 * serialized blob whose length prefixes would end up wrong.
	 */
	private function apply_meta( int $post_id, array $meta, string $origin_url = '', array $media_map = array() ): void {
		$target_url = home_url();

		foreach ( $meta as $key => $values ) {
			$key = (string) $key;
			if ( self::ORIGIN_ID_META === $key || self::ORIGIN_SITE_META === $key ) {
				continue;
			}
			delete_post_meta( $post_id, $key );
			foreach ( (array) $values as $value ) {
				$value = SafeData::decode( $value );
				$value = $this->media_urls->apply_deep( $value, $media_map );
				$value = UrlRewriter::rewrite_deep( $value, $origin_url, $target_url );

				// add_post_meta() unslashes its input, so passing an unslashed value
				// strips every backslash from it — silently corrupting ACF WYSIWYG
				// content, regex, escaped JSON and Windows paths on every deploy.
				add_post_meta( $post_id, $key, wp_slash( $value ) );
			}
		}
	}

	/**
	 * Ensure each term exists (creating by slug, honoring parent) and assign.
	 */
	private function apply_taxonomies( int $post_id, array $taxonomies ): void {
		foreach ( $taxonomies as $taxonomy => $terms ) {
			$taxonomy = (string) $taxonomy;
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$term_ids = array();
			foreach ( (array) $terms as $term ) {
				$slug = (string) ( $term['slug'] ?? '' );
				$name = (string) ( $term['name'] ?? $slug );
				if ( '' === $slug ) {
					continue;
				}

				$existing = get_term_by( 'slug', $slug, $taxonomy );
				if ( $existing instanceof \WP_Term ) {
					$term_ids[] = (int) $existing->term_id;
					continue;
				}

				$args   = array( 'slug' => $slug );
				$parent = $this->resolve_parent_term( $taxonomy, (string) ( $term['parent_slug'] ?? '' ) );
				if ( $parent ) {
					$args['parent'] = $parent;
				}

				$created = wp_insert_term( $name, $taxonomy, $args );
				if ( ! is_wp_error( $created ) ) {
					$term_ids[] = (int) $created['term_id'];
				}
			}

			wp_set_object_terms( $post_id, $term_ids, $taxonomy, false );
		}
	}

	private function resolve_parent_term( string $taxonomy, string $parent_slug ): int {
		if ( '' === $parent_slug ) {
			return 0;
		}
		$parent = get_term_by( 'slug', $parent_slug, $taxonomy );
		return $parent instanceof \WP_Term ? (int) $parent->term_id : 0;
	}

	/**
	 * Sideload the featured image, deduping by source URL so repeated deploys do
	 * not create duplicate attachments.
	 *
	 * @param array{url:string,filename:string,alt:string}|null $image
	 */
	private function apply_featured_image( int $post_id, $image ): void {
		if ( ! is_array( $image ) || empty( $image['url'] ) ) {
			return;
		}

		$source_url = (string) $image['url'];
		$existing   = $this->find_attachment_by_source( $source_url );

		if ( $existing ) {
			set_post_thumbnail( $post_id, $existing );
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_sideload_image( $source_url, $post_id, null, 'id' );
		if ( is_wp_error( $attachment_id ) ) {
			return;
		}

		$attachment_id = (int) $attachment_id;
		update_post_meta( $attachment_id, self::SOURCE_URL_META, $source_url );

		if ( ! empty( $image['alt'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $image['alt'] ) );
		}

		set_post_thumbnail( $post_id, $attachment_id );
	}

	private function find_attachment_by_source( string $source_url ): int {
		$query = new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'   => self::SOURCE_URL_META,
						'value' => $source_url,
					),
				),
			)
		);

		return $query->posts ? (int) $query->posts[0] : 0;
	}
}
