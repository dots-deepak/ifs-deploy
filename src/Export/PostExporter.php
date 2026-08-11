<?php
declare(strict_types=1);

namespace IfsDeploy\Export;

use IfsDeploy\Auth\Credentials;
use IfsDeploy\Support\ContentSignature;
use IfsDeploy\Support\MediaIdentity;
use IfsDeploy\Support\MetaBlocklist;

/**
 * Builds the deployment package for a single post/page.
 *
 * Phase 1 scope: core post fields, post meta (minus internal keys), ACF via
 * get_fields(), taxonomy terms (by slug), and the featured image (sideloaded by
 * URL on the receiving end). Media checksum dedup + ACF ID remapping arrive in
 * Phase 2.
 */
final class PostExporter {

	public const PACKAGE_FORMAT = 1;

	/*
	 * The list of ignored meta keys lives in Support\MetaBlocklist, shared with the
	 * content signature and the snapshot comparison. Keeping three copies meant they
	 * disagreed about what counts as deployable.
	 */

	/**
	 * @return array|null Package array, or null if the post cannot be exported.
	 */
	public function export( int $post_id ): ?array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		$creds = Credentials::get();

		return array(
			'format'         => self::PACKAGE_FORMAT,
			'type'           => 'post',
			'subtype'        => $post->post_type,
			'action'         => 'update',
			'origin_id'      => $post_id,
			'origin_site'    => $creds['site_id'],
			// Source site URL so the importer can rewrite Staging URLs (inline
			// images, links) to the Production domain.
			'origin_url'     => home_url(),
			// Staging-computed content signature; the importer stamps it on the
			// production post so Compare can detect drift without recomputing the
			// hash on the (filtered) production side.
			'signature'      => (string) ( ContentSignature::for_post( $post_id ) ?? '' ),
			'object'         => $this->object_fields( $post ),
			'meta'           => $this->meta( $post_id ),
			// ACF data is NOT exported via get_fields(): that formats values and
			// runs do_shortcode() on WYSIWYG/text fields, so shortcodes would be
			// deployed as rendered HTML. ACF stores everything in postmeta, which
			// is already copied verbatim above — so ACF round-trips losslessly,
			// shortcodes and all. Kept as null for package-format stability.
			'acf'            => null,
			'taxonomies'     => $this->taxonomies( $post ),
			'featured_image' => $this->featured_image( $post_id ),
			'seo'            => array(), // Yoast/RankMath store in postmeta → already captured.
		);
	}

	/**
	 * Core fields suitable for wp_insert_post / wp_update_post on the far side.
	 *
	 * post_parent is exported as the parent's origin id; the importer remaps it.
	 */
	private function object_fields( \WP_Post $post ): array {
		return array(
			'post_title'      => $post->post_title,
			'post_content'    => $post->post_content,
			'post_excerpt'    => $post->post_excerpt,
			'post_status'     => $post->post_status,
			'post_name'       => $post->post_name,
			'post_type'       => $post->post_type,
			'post_parent'     => (int) $post->post_parent,
			'menu_order'      => (int) $post->menu_order,
			'post_date'       => $post->post_date,
			'post_date_gmt'   => $post->post_date_gmt,
			'comment_status'  => $post->comment_status,
			'ping_status'     => $post->ping_status,
			'post_password'   => $post->post_password,
		);
	}

	/**
	 * @return array<string,array<int,string>>
	 */
	private function meta( int $post_id ): array {
		$all = get_post_meta( $post_id );
		if ( ! is_array( $all ) ) {
			return array();
		}

		$out = array();
		foreach ( $all as $key => $values ) {
			if ( MetaBlocklist::is_ignored( (string) $key ) ) {
				continue;
			}
			// Protected meta beginning with "_" that ACF uses for field-key refs
			// is kept; it is needed for ACF to resolve fields on import.
			$out[ $key ] = array_map( 'maybe_unserialize', (array) $values );
		}

		return $out;
	}

	/**
	 * @return array<string,array<int,array{slug:string,name:string,parent_slug:string}>>
	 */
	private function taxonomies( \WP_Post $post ): array {
		$out        = array();
		$taxonomies = get_object_taxonomies( $post->post_type );

		foreach ( $taxonomies as $taxonomy ) {
			$terms = wp_get_object_terms( $post->ID, $taxonomy );
			if ( is_wp_error( $terms ) || ! $terms ) {
				continue;
			}

			$out[ $taxonomy ] = array_map(
				static function ( \WP_Term $term ): array {
					$parent = $term->parent ? get_term( $term->parent ) : null;
					return array(
						'slug'        => $term->slug,
						'name'        => $term->name,
						'parent_slug' => ( $parent instanceof \WP_Term ) ? $parent->slug : '',
					);
				},
				$terms
			);
		}

		return $out;
	}

	/**
	 * @return array{id:int,url:string,filename:string,alt:string}|null
	 */
	private function featured_image( int $post_id ): ?array {
		$thumb_id = (int) get_post_thumbnail_id( $post_id );
		if ( ! $thumb_id ) {
			return null;
		}

		$url = wp_get_attachment_url( $thumb_id );
		if ( ! $url ) {
			return null;
		}

		return array(
			/*
			 * The source attachment id, so the copy on Production can be created with the
			 * SAME id (PostImporter routes this through MediaImporter's import_id path).
			 * Without it a featured image got a fresh id on arrival and every raw ACF
			 * image field pointing at it broke.
			 *
			 * NOTE: adding a key changes the package hash, so each post that has a
			 * featured image will queue once more on its next save even if nothing
			 * changed. It settles by itself — the CONTENT SIGNATURE is unaffected (see
			 * ContentSignature::featured(), which reports filename and alt only), so
			 * QueueVerifier finds the object already in sync and clears the row. It is
			 * also invisible in the diff, since PackageDiff compares images by filename
			 * and alt text rather than by id.
			 */
			'id'       => $thumb_id,
			'url'      => $url,
			// The ORIGINAL filename, not the local one: Production may have stored the
			// same image as hero-1.png, and comparing local names would report a
			// difference no push can ever resolve.
			'filename' => MediaIdentity::stable_filename( $thumb_id ),
			'alt'      => (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ),
		);
	}
}
