<?php
declare(strict_types=1);

namespace IfsDeploy\Import;

use IfsDeploy\Support\ContentFirewall;
use IfsDeploy\Support\DebugLog;
use IfsDeploy\Support\MediaIdentity;
use IfsDeploy\Support\MetaBlocklist;
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

			/*
			 * post_date_gmt travels too, instead of being recomputed here.
			 *
			 * The exporter has always sent it and the importer always dropped it. Core
			 * then derived it from post_date using THIS site's timezone
			 * (`get_gmt_from_date()`), so whenever the two sites are configured for
			 * different timezones every deployed date silently shifted by the offset —
			 * and because post_date itself was copied verbatim, the drift only showed in
			 * feeds, REST output, scheduling and anything else that reads the GMT column.
			 *
			 * An empty value keeps the old behaviour, which is what an older Staging
			 * sends, so a mixed-version pair is unaffected. Core honours an explicit
			 * post_date_gmt and falls back to deriving one otherwise.
			 */
			'post_date_gmt'  => (string) ( $fields['post_date_gmt'] ?? '' ),

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
		$this->apply_featured_image( $post_id, $package['featured_image'] ?? null, $origin_site );

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

					// ID parity has to be CORROBORATED before it is allowed to select the
					// object a deploy will overwrite. See id_parity_is_same_post().
					if ( $match && ! $this->id_parity_is_same_post( $match, $fields, $origin_id, $origin_site ) ) {
						DebugLog::warning(
							'Refused to match a Production post by ID alone — nothing about it corroborates that it is the same object',
							array(
								'origin_id'   => $origin_id,
								'post_type'   => $type,
								'origin_site' => $origin_site,
								'staging'     => (string) ( $fields['post_title'] ?? '' ),
								'production'  => (string) ( get_post( $match )->post_title ?? '' ),
							)
						);

						$match = 0;
					}
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
	 *
	 * Raw parity, and NOT sufficient on its own to choose an overwrite target — see
	 * id_parity_is_same_post(), which locate() applies on top of this.
	 */
	private function match_by_id( int $id, string $type ): int {
		if ( $id <= 0 ) {
			return 0;
		}
		$post = get_post( $id );
		return ( $post instanceof \WP_Post && $post->post_type === $type ) ? $id : 0;
	}

	/**
	 * Is the post sitting at this id plausibly the same object we are importing?
	 *
	 * ID parity used to be accepted on its own: same id, same post_type, done. On sites
	 * cloned from one another — the case it exists for — that is right. On sites that
	 * were NOT cloned, ids are unrelated, so Staging post 412 would silently overwrite
	 * whatever Production happened to keep at 412. No error, no duplicate, no warning:
	 * an unrelated live page simply became a copy of a different one, and the pre-deploy
	 * snapshot made it look like an ordinary deploy.
	 *
	 * This is the post-side counterpart of MediaImporter::id_parity_is_same_file(), and
	 * it refuses on the same two kinds of evidence:
	 *
	 *   1. The target is STAMPED as somebody else's — a different origin site, or a
	 *      different origin id on this one. That is proof, so it always refuses.
	 *   2. NOTHING about the target agrees with the package: not the slug, not the
	 *      title, not the publish date.
	 *
	 * Any ONE of those three matching is enough, deliberately. Requiring the slug alone
	 * would break the ordinary case of renaming a slug on Staging — parity would refuse,
	 * the slug strategy would miss, and the deploy would insert a DUPLICATE of the page
	 * it was meant to update. Editors rename a slug or a title, rarely both at once, and
	 * almost never the publish date as well; an unrelated post would have to collide on
	 * the id, the type AND one of those three to be mistaken for this one.
	 *
	 * @param int   $id     The Production post at the parity id.
	 * @param array $fields The package's `object` fields.
	 */
	private function id_parity_is_same_post( int $id, array $fields, int $origin_id, string $origin_site ): bool {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		// Stamped from another site, or from a different object on this one → not ours.
		$stamped_site = (string) get_post_meta( $id, self::ORIGIN_SITE_META, true );
		if ( '' !== $stamped_site && '' !== $origin_site && $stamped_site !== $origin_site ) {
			return false;
		}

		$stamped_id = (int) get_post_meta( $id, self::ORIGIN_ID_META, true );
		if ( $stamped_id > 0 && $stamped_id !== $origin_id ) {
			return false;
		}

		$slug = (string) ( $fields['post_name'] ?? '' );
		if ( '' !== $slug && strtolower( $slug ) === strtolower( (string) $post->post_name ) ) {
			return true;
		}

		$title = (string) ( $fields['post_title'] ?? '' );
		if ( '' !== $title && $title === (string) $post->post_title ) {
			return true;
		}

		$date = (string) ( $fields['post_date'] ?? '' );
		if ( '' !== $date && '0000-00-00 00:00:00' !== $date && $date === (string) $post->post_date ) {
			return true;
		}

		return false;
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

		$this->remove_meta_deleted_on_source( $post_id, $meta );

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
	 * Delete deployable meta that Production still holds and the package no longer has.
	 *
	 * THE BUG THIS FIXES: clearing a value on Staging did not clear it on Production.
	 * Yoast is the everyday case — emptying an SEO meta description makes Yoast delete
	 * `_yoast_wpseo_metadesc` outright, so the key simply vanishes from the package, and
	 * an importer that only ever writes the keys it is given left the old description
	 * live for ever. Setting a value synced; removing one did not, which is worse than
	 * not syncing at all, because the two sites disagree while claiming to be in sync.
	 *
	 * The package is the COMPLETE deployable meta set for the object: PostExporter
	 * exports all of `get_post_meta()` minus `Support\MetaBlocklist`. So a key that is
	 * on Production and not in the package is either deleted on Staging, or it is meta
	 * this plugin should not have been touching in the first place.
	 *
	 * That is exactly what the blocklist decides, and it is why deletion is safe to
	 * drive from it: editor locks, `_thumbnail_id`, oEmbed and transient caches, trash
	 * bookkeeping and IFS Deploy's own stamps are all ignored here, so none of them can
	 * be removed. Anything else a Production-only plugin owns can be protected the same
	 * way, with the documented `ifs_deploy_ignore_meta_key` filter — one list decides
	 * both what is deployed and what is left alone, which is the point of having one.
	 *
	 * Whole-hog deletion can still be switched off per site:
	 *
	 *     add_filter( 'ifs_deploy_delete_missing_meta', '__return_false' );
	 *
	 * @param array<string,mixed> $package_meta Meta the package carries.
	 */
	private function remove_meta_deleted_on_source( int $post_id, array $package_meta ): void {
		/**
		 * Filter whether meta absent from a package is deleted on the target.
		 *
		 * @param bool  $delete       Default true.
		 * @param int   $post_id      Target post.
		 * @param array $package_meta The meta the package carries.
		 */
		if ( ! apply_filters( 'ifs_deploy_delete_missing_meta', true, $post_id, $package_meta ) ) {
			return;
		}

		$current = get_post_meta( $post_id );
		if ( ! is_array( $current ) ) {
			return;
		}

		$removed = array();

		foreach ( $current as $key => $unused ) {
			$key = (string) $key;

			if ( array_key_exists( $key, $package_meta ) || MetaBlocklist::is_ignored( $key ) ) {
				continue;
			}

			delete_post_meta( $post_id, $key );
			$removed[] = $key;
		}

		if ( empty( $removed ) ) {
			return;
		}

		// Logged because a deletion is the one meta operation with nothing left behind
		// to show what happened.
		DebugLog::info(
			'Removed post meta that no longer exists on the sending site',
			array(
				'post_id' => $post_id,
				'keys'    => implode( ', ', $removed ),
			)
		);
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
	 * Attach the featured image, deduping by source URL so repeated deploys do not
	 * create duplicate attachments.
	 *
	 * @param array{url:string,filename:string,alt:string,id?:int}|null $image
	 */
	private function apply_featured_image( int $post_id, $image, string $origin_site = '' ): void {
		if ( ! is_array( $image ) || empty( $image['url'] ) ) {
			return;
		}

		$source_url = (string) $image['url'];
		$existing   = $this->find_attachment_by_source( $source_url );

		if ( $existing ) {
			set_post_thumbnail( $post_id, $existing );
			return;
		}

		/*
		 * Prefer the MEDIA PIPELINE whenever the package names the source attachment.
		 *
		 * `media_sideload_image()` below cannot pass `import_id`, so an image that
		 * arrived this way got a fresh Production id. ID parity is what makes raw meta
		 * work: ACF image/gallery fields store the attachment ID verbatim, and this
		 * importer copies meta verbatim — so a featured image that landed on a different
		 * id left every ACF field pointing at it broken, or worse, pointing at whatever
		 * unrelated attachment held that id on Production. It only worked when the same
		 * image also happened to travel as its own queued media object.
		 *
		 * MediaImporter is the one place that knows how to do this properly, and going
		 * through it also picks up the size cap, the concurrent-download claim, the
		 * year/month folder and the origin stamps. `$image['id']` is absent from packages
		 * built by an older Staging, which is exactly when the sideload below is still
		 * the right answer.
		 */
		$origin_attachment_id = (int) ( $image['id'] ?? 0 );

		if ( $origin_attachment_id > 0 ) {
			$imported = ( new MediaImporter() )->import(
				array(
					'type'        => 'media',
					'action'      => 'update',
					'origin_id'   => $origin_attachment_id,
					'origin_site' => $origin_site,
					'source_url'  => $source_url,
					'filename'    => (string) ( $image['filename'] ?? '' ),
					'alt'         => (string) ( $image['alt'] ?? '' ),
				)
			);

			if ( ! is_wp_error( $imported ) && ! empty( $imported['object_id'] ) ) {
				set_post_thumbnail( $post_id, (int) $imported['object_id'] );
				return;
			}

			// Not retried by sideloading: MediaImporter already logged the reason, and the
			// failures it reports (an oversized file, an unreachable Staging) would fail
			// the same way again — at the cost of a second download attempt.
			DebugLog::error(
				'Featured image could not be imported',
				array(
					'post_id'    => $post_id,
					'source_url' => $source_url,
					'error'      => is_wp_error( $imported ) ? $imported->get_error_message() : 'no attachment id returned',
				)
			);

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
