<?php
declare(strict_types=1);

namespace IfsDeploy\Support;


/**
 * Produces a site-independent content hash for a post so Staging and Production
 * can be compared for equality.
 *
 * The signature covers the same content IFS Deploy actually deploys — core
 * fields, post meta (incl. ACF, which lives in postmeta), taxonomy slugs, and
 * the featured image filename — while excluding site-specific/volatile keys
 * (origin stamps, edit locks, oEmbed caches, etc.) so identical content hashes
 * the same on both sites.
 *
 * "Site-independent" has to be earned, not assumed: the site's own home URL is
 * replaced with a neutral token first (see neutralize_urls()), because the import
 * rewrites Staging URLs to the Production domain and the raw content therefore
 * differs on the two sites even when nothing has actually changed.
 *
 * Note: because Phase 1 deploys meta verbatim, ACF image/relationship fields
 * carry raw attachment/post IDs. After a normal deploy both sites match; sites
 * that were set up independently (not via IFS Deploy) may show as "different"
 * on such fields until the Phase 2 ID remapping lands.
 */
final class ContentSignature {

	/**
	 * Stands in for this site's own home URL so the hash is domain-agnostic.
	 *
	 * ── DO NOT RENAME THIS STRING ──────────────────────────────────────────────
	 *
	 * It reads like a leftover from the old plugin name. It is not: it is **stored data
	 * format**. This token is substituted into content *before* hashing, so it is baked into
	 * every signature already written — the `hash` column of every queue row, and the
	 * `_ifs_deploy_src_sig` stamp on every object ever deployed.
	 *
	 * Change one character and the same unchanged content hashes differently, so:
	 *
	 *   - every deployed object's stored stamp stops matching what Staging computes, and
	 *     `Client\QueueVerifier` can no longer clear a row that really is in sync;
	 *   - every existing queue row's hash mismatches, so untouched content reads as changed.
	 *
	 * On the site this was written for that is ~2000 objects reporting a difference that does
	 * not exist. The token is never displayed anywhere, so the old spelling costs nothing.
	 */
	private const SITE_URL_TOKEN = Legacy::SIGNATURE_URL_TOKEN;

	public static function for_post( int $post_id ): ?string {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		return md5( (string) wp_json_encode( self::normalize( $post ) ) );
	}

	/**
	 * @return array Normalized, order-stable representation.
	 */
	public static function normalize( \WP_Post $post ): array {
		$parent = $post->post_parent ? get_post( $post->post_parent ) : null;

		return array(
			'title'       => (string) $post->post_title,
			'content'     => self::neutralize_urls( (string) $post->post_content ),
			'excerpt'     => self::neutralize_urls( (string) $post->post_excerpt ),
			'status'      => (string) $post->post_status,
			'slug'        => (string) $post->post_name,
			'menu_order'  => (int) $post->menu_order,
			'parent_slug' => $parent instanceof \WP_Post ? (string) $parent->post_name : '',
			'meta'        => self::meta( $post->ID ),
			'taxonomies'  => self::taxonomies( $post ),
			'featured'    => self::featured( $post->ID ),
		);
	}

	/**
	 * Replace this site's own home URL with a neutral token before hashing.
	 *
	 * Without this the signature is NOT site-independent: PostImporter rewrites
	 * Staging URLs to the Production domain on arrival, so the very same content
	 * hashes differently on the two sites and every post containing an internal
	 * link or inline image reports as "Different" on Compare & Sync forever — while
	 * the field-level preview, which applies the rewrite before comparing,
	 * correctly finds nothing to deploy.
	 *
	 * Only post_content/post_excerpt are neutralized, because those are the only
	 * fields PostImporter rewrites. Meta is written verbatim, so URLs inside meta
	 * are identical on both sites already and a difference there is genuine.
	 *
	 * Note: the token is not a URL, so it can never collide with real content in a
	 * way that hides a difference — both sides collapse to the same token or
	 * neither does.
	 */
	private static function neutralize_urls( string $content ): string {
		/*
		 * Both the site's own URL and its configured counterpart are collapsed, via
		 * the same helper the preview uses — so the Compare screen's verdict and the
		 * preview dialog can never disagree about whether a URL counts as a change.
		 *
		 * Own URL covers the normal case (Staging links to Staging, and the import
		 * rewrites those to Production). The counterpart covers content where an
		 * editor pasted the LIVE url into Staging: the import leaves it alone, so
		 * Production ends up holding a URL that only Production neutralizes —
		 * without this second entry the pair would mismatch forever.
		 *
		 * On Production the remote URL is normally blank, making it a no-op there.
		 * UrlRewriter handles scheme, `www.`, protocol-relative and escaped-slash
		 * variants, which a plain home_url() match does not.
		 */
		return UrlRewriter::neutralize( $content, self::environment_urls(), self::SITE_URL_TOKEN );
	}

	/**
	 * Deployable post meta (incl. ACF), normalized and order-stable. Mirrors the
	 * exporter's blocklist and drops volatile/cache keys so the hash is stable.
	 *
	 * @return array<string,mixed>
	 */
	private static function meta( int $post_id ): array {
		$all = get_post_meta( $post_id );
		if ( ! is_array( $all ) ) {
			return array();
		}

		$urls = self::environment_urls();
		$out  = array();

		foreach ( $all as $key => $values ) {
			$key = (string) $key;
			if ( self::is_ignored_meta( $key ) ) {
				continue;
			}

			// Environment URLs inside meta (ACF WYSIWYG, repeaters, flexible content)
			// are neutralized at any depth, exactly as the preview does — otherwise
			// Compare would call a page "Different" for a domain the deploy resolves
			// on its own, while the preview dialog showed nothing to deploy.
			$out[ $key ] = (array) UrlRewriter::neutralize_deep(
				array_map( 'maybe_unserialize', (array) $values ),
				$urls,
				self::SITE_URL_TOKEN
			);
		}

		ksort( $out );
		return $out;
	}

	/**
	 * This site's URL plus its configured counterpart.
	 *
	 * @return string[]
	 */
	private static function environment_urls(): array {
		return array( home_url(), Config::remote()['url'] );
	}

	/**
	 * Delegated to the shared list so the signature and the exported package can never
	 * disagree about what counts as deployable content.
	 */
	private static function is_ignored_meta( string $key ): bool {
		return MetaBlocklist::is_ignored( $key );
	}

	/**
	 * @return array<string,string[]> taxonomy => sorted term slugs.
	 */
	private static function taxonomies( \WP_Post $post ): array {
		$out = array();
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$slugs = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'slugs' ) );
			if ( is_wp_error( $slugs ) || ! $slugs ) {
				continue;
			}
			$slugs = array_map( 'strval', (array) $slugs );
			sort( $slugs );
			$out[ $taxonomy ] = $slugs;
		}
		ksort( $out );
		return $out;
	}

	/**
	 * @return array{filename:string,alt:string}|null
	 */
	private static function featured( int $post_id ): ?array {
		$thumb_id = (int) get_post_thumbnail_id( $post_id );
		if ( ! $thumb_id ) {
			return null;
		}

		return array(
			// Rename-proof: see MediaIdentity. Must match what PostExporter reports, or
			// Compare & Sync would call a page "Different" forever over a filename
			// suffix Production added by itself.
			'filename' => MediaIdentity::stable_filename( $thumb_id ),
			'alt'      => (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ),
		);
	}
}
