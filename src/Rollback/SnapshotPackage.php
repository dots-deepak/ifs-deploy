<?php
declare(strict_types=1);

namespace IfsDeploy\Rollback;

use IfsDeploy\Support\MediaIdentity;
use IfsDeploy\Support\MetaBlocklist;

/**
 * Reshapes a stored snapshot into the package shape PackageDiff understands, so a
 * rollback can be previewed with the same field-level differ the deploy preview uses.
 *
 * Snapshots are written for RESTORING, not for comparing: meta is the raw
 * get_post_meta() shape (serialized strings), taxonomies are term IDs, and the
 * featured image is an attachment ID. A package uses unserialized meta, term slugs
 * and a filename. Everything here exists to bridge that gap — and to apply the same
 * meta blocklist the exporter uses, or every comparison would be noisy with
 * IFS Deploy's own bookkeeping keys.
 */
final class SnapshotPackage {

	/**
	 * Convert a post snapshot.
	 *
	 * @param array $snapshot Decoded snapshot row.
	 *
	 * @return array Package-shaped array for PackageDiff::compare().
	 */
	public static function for_post( array $snapshot ): array {
		$post = (array) ( $snapshot['post'] ?? array() );

		return array(
			'type'           => 'post',
			'subtype'        => (string) ( $post['post_type'] ?? 'post' ),
			'object'         => $post,
			'meta'           => self::meta( (array) ( $snapshot['meta'] ?? array() ) ),
			'taxonomies'     => self::taxonomies( (array) ( $snapshot['terms'] ?? array() ) ),
			'featured_image' => self::featured( (int) ( $snapshot['thumbnail_id'] ?? 0 ) ),
			'parent_slug'    => self::parent_slug( (int) ( $post['post_parent'] ?? 0 ) ),
		);
	}

	/**
	 * A human label for any snapshot type, for the object switcher.
	 */
	public static function label( string $object_type, array $snapshot ): string {
		switch ( $object_type ) {
			case 'term':
				return (string) ( $snapshot['name'] ?? '' );
			case 'option':
				return (string) ( $snapshot['name'] ?? '' );
			case 'menu':
				return (string) ( $snapshot['menu']['name'] ?? '' );
			case 'post':
			default:
				return (string) ( $snapshot['post']['post_title'] ?? '' );
		}
	}

	/**
	 * Unserialize and drop ignored keys via the same shared list the exporter uses, so
	 * both sides of the comparison are filtered identically.
	 *
	 * @param array $raw get_post_meta() output as stored.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	private static function meta( array $raw ): array {
		$out = array();

		foreach ( $raw as $key => $values ) {
			$key = (string) $key;
			if ( MetaBlocklist::is_ignored( $key ) ) {
				continue;
			}

			$out[ $key ] = array_map( 'maybe_unserialize', (array) $values );
		}

		return $out;
	}

	/**
	 * Term IDs → the slug list a package carries.
	 *
	 * Empty taxonomies are kept deliberately: SnapshotStore::restore_post() iterates
	 * the snapshot's taxonomies and calls wp_set_object_terms() for each, so a
	 * taxonomy recorded as empty really does get cleared. Dropping the key would make
	 * PackageDiff report that as "kept" instead of "removed".
	 *
	 * @param array<string,array<int,int>> $terms
	 *
	 * @return array<string,array<int,array{slug:string,name:string,parent_slug:string}>>
	 */
	private static function taxonomies( array $terms ): array {
		$out = array();

		foreach ( $terms as $taxonomy => $ids ) {
			$taxonomy = (string) $taxonomy;
			$list     = array();

			foreach ( (array) $ids as $id ) {
				$term = get_term( (int) $id, $taxonomy );
				if ( $term instanceof \WP_Term ) {
					$list[] = array(
						'slug'        => (string) $term->slug,
						'name'        => (string) $term->name,
						'parent_slug' => '',
					);
				}
			}

			$out[ $taxonomy ] = $list;
		}

		return $out;
	}

	/**
	 * @return array{filename:string,alt:string}|null
	 */
	private static function featured( int $thumbnail_id ): ?array {
		if ( $thumbnail_id <= 0 ) {
			return null;
		}

		return array(
			// Same rename-proof basis as the exporter, so the rollback preview does not
			// report a featured-image change that is only a local filename suffix.
			'filename' => MediaIdentity::stable_filename( $thumbnail_id ),
			'alt'      => (string) get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true ),
		);
	}

	private static function parent_slug( int $parent_id ): string {
		if ( $parent_id <= 0 ) {
			return '';
		}

		$parent = get_post( $parent_id );

		return $parent instanceof \WP_Post ? (string) $parent->post_name : '';
	}
}
