<?php
declare(strict_types=1);

namespace IfsDeploy\Support;

/**
 * Compares Production's current post package against the package Staging is about
 * to push, and reports the fields that would change.
 *
 * Two rules keep this honest:
 *
 *  1. Both sides are built by the SAME PostExporter, so both share one shape and
 *     one meta blocklist. Anything that differs is a real difference, not an
 *     artefact of two different serializations.
 *  2. The change label describes what the IMPORT ACTUALLY DOES, not merely how
 *     the two sides differ. A taxonomy or featured image the package does not
 *     mention is left alone by the importer, so it is reported as "kept" rather
 *     than "removed" — the preview must not promise a deletion that will not
 *     happen. Meta is the other way round: the importer now deletes deployable
 *     keys the package no longer carries, so it must not promise a survival that
 *     will not happen either.
 */
final class PackageDiff {

	/** Field will gain a value it does not have on Production. */
	public const CHANGE_ADDED = 'added';

	/** Field exists on both sides with different values. */
	public const CHANGE_CHANGED = 'changed';

	/** The push will actively remove this from Production. */
	public const CHANGE_REMOVED = 'removed';

	/** Only on Production; the push leaves it untouched. */
	public const CHANGE_KEPT = 'kept';

	/**
	 * Whether the current comparison describes an operation that replaces the whole
	 * object. Set per compare() call; see the $full_replace parameter.
	 */
	private static bool $full_replace = false;

	public const GROUP_CORE      = 'core';
	public const GROUP_FIELD     = 'field';
	public const GROUP_TAXONOMY  = 'taxonomy';
	public const GROUP_MEDIA     = 'media';
	public const GROUP_TECHNICAL = 'technical';

	/**
	 * Core post fields worth showing, in display order.
	 *
	 * post_type is identical by construction. post_date_gmt is excluded because it
	 * is recomputed on import, so it would report a difference no push can resolve.
	 * post_parent is excluded here and compared by SLUG instead (see
	 * parent_field()), since the raw ID legitimately differs between sites.
	 *
	 * @var array<string,string>
	 */
	private const CORE_FIELDS = array(
		'post_title'     => 'Title',
		'post_name'      => 'Slug',
		'post_status'    => 'Status',
		'post_content'   => 'Content',
		'post_excerpt'   => 'Excerpt',
		'menu_order'     => 'Menu order',
		'post_date'      => 'Published date',
		'comment_status' => 'Comments',
		'ping_status'    => 'Pingbacks',
		'post_password'  => 'Password protection',
	);

	/**
	 * Compare a Production package against a Staging package.
	 *
	 * @param array      $staging      Package about to be applied (the "after").
	 * @param array|null $prod         Current package, or null when the object does
	 *                                 not exist on the target yet.
	 * @param bool       $full_replace Whether applying the "after" side wipes what it
	 *                                 does not mention. False for a deploy (the
	 *                                 importer never deletes, so unmentioned data is
	 *                                 KEPT); true for a rollback, where
	 *                                 SnapshotStore::restore_post() clears meta,
	 *                                 terms and the thumbnail before writing the
	 *                                 snapshot back — there, "kept" would be a lie.
	 *
	 * @return array<int,array{label:string,key:string,group:string,change:string,before:string,after:string}>
	 */
	public static function compare( array $staging, ?array $prod, bool $full_replace = false ): array {
		self::$full_replace = $full_replace;

		$fields = array();

		$staging_object = (array) ( $staging['object'] ?? array() );
		$prod_object    = (array) ( $prod['object'] ?? array() );

		foreach ( self::CORE_FIELDS as $key => $label ) {
			$after  = self::stringify( $staging_object[ $key ] ?? null );
			$before = null === $prod ? '' : self::stringify( $prod_object[ $key ] ?? null );

			if ( $before === $after ) {
				continue;
			}

			// A zero against nothing is a default, not a change worth showing —
			// otherwise every brand-new object reports "Menu order: 0" as an
			// addition.
			if ( '' === $before && '0' === $after ) {
				continue;
			}

			$fields[] = self::field( $label, $key, self::GROUP_CORE, $before, $after );
		}

		$fields = array_merge( $fields, self::parent_field( $staging, $prod ) );
		$fields = array_merge( $fields, self::meta_fields( $staging, $prod ) );
		$fields = array_merge( $fields, self::taxonomy_fields( $staging, $prod ) );
		$fields = array_merge( $fields, self::media_fields( $staging, $prod ) );

		return $fields;
	}

	/**
	 * Diff the parent by slug.
	 *
	 * Raw post_parent IDs differ legitimately between sites, so comparing them
	 * would flag hierarchy that is actually identical. Slugs are site-independent —
	 * the same basis ContentSignature uses, which keeps the dialog consistent with
	 * the Compare screen's "Different" verdict when someone re-parents a page.
	 *
	 * Both sides are supplied by PreviewService under a top-level `parent_slug`
	 * key; absent on either side, there is nothing to compare.
	 *
	 * @return array<int,array>
	 */
	private static function parent_field( array $staging, ?array $prod ): array {
		if ( ! array_key_exists( 'parent_slug', $staging ) ) {
			return array();
		}

		$after  = (string) $staging['parent_slug'];
		$before = ( null === $prod ) ? '' : (string) ( $prod['parent_slug'] ?? '' );

		if ( $before === $after ) {
			return array();
		}

		return array( self::field( 'Parent', 'parent_slug', self::GROUP_CORE, $before, $after ) );
	}

	/**
	 * Diff post meta — which is also where every ACF value lives.
	 *
	 * @return array<int,array>
	 */
	private static function meta_fields( array $staging, ?array $prod ): array {
		$after_meta  = (array) ( $staging['meta'] ?? array() );
		$before_meta = null === $prod ? array() : (array) ( $prod['meta'] ?? array() );

		$keys = array_unique( array_merge( array_keys( $before_meta ), array_keys( $after_meta ) ) );
		sort( $keys );

		$fields = array();

		foreach ( $keys as $key ) {
			$key            = (string) $key;
			$in_package     = array_key_exists( $key, $after_meta );
			$on_production  = array_key_exists( $key, $before_meta );

			$after  = $in_package ? self::stringify_meta( $after_meta[ $key ] ) : '';
			$before = $on_production ? self::stringify_meta( $before_meta[ $key ] ) : '';

			if ( $before === $after ) {
				continue;
			}

			/*
			 * Meta absent from the package is REMOVED, on both paths.
			 *
			 * This used to report `kept` for a deploy, because the importer only wrote
			 * the keys it was given. It now deletes deployable meta the package no
			 * longer carries — the fix for "clearing a Yoast description on Staging left
			 * it live on Production" — so `kept` would be the promise that is wrong.
			 *
			 * Nothing else moves with it: taxonomies and the featured image are still
			 * only replaced when the package mentions them, so those two keep the
			 * $full_replace distinction below.
			 */
			$change = self::change_for( $before, $after );

			$fields[] = self::field(
				$key,
				$key,
				self::is_technical_key( $key ) ? self::GROUP_TECHNICAL : self::GROUP_FIELD,
				$before,
				$after,
				$change
			);
		}

		return $fields;
	}

	/**
	 * Diff taxonomy assignments by term slug.
	 *
	 * Removal semantics differ per taxonomy: the importer calls
	 * wp_set_object_terms() with append=false for every taxonomy present in the
	 * package (so terms really do get removed), but a taxonomy absent from the
	 * package entirely is never touched.
	 *
	 * @return array<int,array>
	 */
	private static function taxonomy_fields( array $staging, ?array $prod ): array {
		$after_tax  = (array) ( $staging['taxonomies'] ?? array() );
		$before_tax = null === $prod ? array() : (array) ( $prod['taxonomies'] ?? array() );

		$taxonomies = array_unique( array_merge( array_keys( $before_tax ), array_keys( $after_tax ) ) );
		sort( $taxonomies );

		$fields = array();

		foreach ( $taxonomies as $taxonomy ) {
			$taxonomy = (string) $taxonomy;
			$after    = self::term_slugs( $after_tax[ $taxonomy ] ?? array() );
			$before   = self::term_slugs( $before_tax[ $taxonomy ] ?? array() );

			if ( $before === $after ) {
				continue;
			}

			$in_package = array_key_exists( $taxonomy, $after_tax );

			$fields[] = self::field(
				$taxonomy,
				'taxonomy:' . $taxonomy,
				self::GROUP_TAXONOMY,
				implode( ', ', $before ),
				implode( ', ', $after ),
				( ! $in_package && $before && ! self::$full_replace ) ? self::CHANGE_KEPT : null
			);
		}

		return $fields;
	}

	/**
	 * Diff the featured image by filename + alt text.
	 *
	 * URLs are excluded on purpose: they carry the site domain and would differ on
	 * every single post. A featured image removed on Staging is reported as
	 * "kept", because the importer only ever sets a thumbnail — it never clears
	 * one.
	 *
	 * @return array<int,array>
	 */
	private static function media_fields( array $staging, ?array $prod ): array {
		$after_image  = is_array( $staging['featured_image'] ?? null ) ? $staging['featured_image'] : null;
		$before_image = ( null !== $prod && is_array( $prod['featured_image'] ?? null ) ) ? $prod['featured_image'] : null;

		$after  = self::describe_image( $after_image );
		$before = self::describe_image( $before_image );

		if ( $before === $after ) {
			return array();
		}

		return array(
			self::field(
				'Featured image',
				'featured_image',
				self::GROUP_MEDIA,
				$before,
				$after,
				( null === $after_image && null !== $before_image && ! self::$full_replace ) ? self::CHANGE_KEPT : null
			),
		);
	}

	private static function describe_image( ?array $image ): string {
		if ( null === $image ) {
			return '';
		}

		$filename = (string) ( $image['filename'] ?? '' );
		$alt      = (string) ( $image['alt'] ?? '' );

		return '' !== $alt ? $filename . "\n" . 'Alt text: ' . $alt : $filename;
	}

	/**
	 * @param mixed $terms Exported term list.
	 *
	 * @return string[] Sorted slugs.
	 */
	private static function term_slugs( $terms ): array {
		$slugs = array();
		foreach ( (array) $terms as $term ) {
			$slug = is_array( $term ) ? (string) ( $term['slug'] ?? '' ) : (string) $term;
			if ( '' !== $slug ) {
				$slugs[] = $slug;
			}
		}

		sort( $slugs );
		return $slugs;
	}

	/**
	 * ACF stores a "field_abc123" reference under an underscore-prefixed mirror of
	 * every field name. Those, and other underscore-prefixed internals, are real
	 * differences but not what an editor is reviewing — they get grouped away.
	 */
	private static function is_technical_key( string $key ): bool {
		return '' !== $key && '_' === $key[0];
	}

	/**
	 * @return array{label:string,key:string,group:string,change:string,before:string,after:string}
	 */
	private static function field( string $label, string $key, string $group, string $before, string $after, ?string $change = null ): array {
		return array(
			'label'  => $label,
			'key'    => $key,
			'group'  => $group,
			'change' => $change ?? self::change_for( $before, $after ),
			'before' => $before,
			'after'  => $after,
		);
	}

	private static function change_for( string $before, string $after ): string {
		if ( '' === $before ) {
			return self::CHANGE_ADDED;
		}
		if ( '' === $after ) {
			return self::CHANGE_REMOVED;
		}
		return self::CHANGE_CHANGED;
	}

	/**
	 * Render one meta key's value list for display. Single-value meta (the common
	 * case) shows the bare value rather than a one-element list.
	 *
	 * @param mixed $values
	 */
	private static function stringify_meta( $values ): string {
		$values = (array) $values;

		if ( 1 === count( $values ) ) {
			return self::stringify( reset( $values ) );
		}

		return self::stringify( $values );
	}

	/**
	 * Turn any exported value into a stable, human-readable string.
	 *
	 * @param mixed $value
	 */
	private static function stringify( $value ): string {
		if ( null === $value ) {
			return '';
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		$encoded = Json::encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		// Encoding can fail; fall back to a form that still diffs rather than showing an
		// empty (and therefore falsely "removed") value.
		return null !== $encoded ? $encoded : print_r( $value, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
	}
}
