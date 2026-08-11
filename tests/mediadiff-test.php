<?php
declare(strict_types=1);

/*
 * "View changes" for a MEDIA row.
 *
 * A page or post has shown a field-level before/after since §19; media showed "—", so
 * after editing an image there was no way to see what had actually changed. This is that
 * diff: title, caption, description, slug, file type, order, alt text and attachment
 * meta.
 *
 * Two comparisons are deliberately absent and are pinned as such below, because both
 * would report a difference on EVERY push that no push could ever resolve:
 *
 *   - `source_url`, which carries the site domain;
 *   - the local filename, which Production changes by itself — `wp_upload_bits()` never
 *     overwrites, so an incoming `hero.png` is stored as `hero-1.png` whenever an
 *     unrelated `hero.png` is already there.
 */

function __( $s, $d = '' ) { return $s; }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function apply_filters( $tag, $value, ...$args ) { return $value; }
function esc_url_raw( $u ) { return $u; }

require __DIR__ . '/../src/Support/Json.php';
require __DIR__ . '/../src/Support/Legacy.php';
require __DIR__ . '/../src/Support/MetaBlocklist.php';
require __DIR__ . '/../src/Support/PackageDiff.php';

use IfsDeploy\Support\PackageDiff;

$pass = 0; $fail = 0;
function ok( string $n, bool $c ): void {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "PASS  $n\n"; } else { $fail++; echo "FAIL  $n\n"; }
}

/** @return array|null The field with this key, or null. */
function field( array $fields, string $key ): ?array {
	foreach ( $fields as $f ) {
		if ( $f['key'] === $key ) { return $f; }
	}
	return null;
}

/** A media package, overridable per test. */
function media( array $attachment = array(), array $top = array() ): array {
	return array_merge(
		array(
			'format'      => 1,
			'type'        => 'media',
			'subtype'     => 'image/png',
			'action'      => 'update',
			'origin_id'   => 63400,
			'origin_site' => 'staging-uuid',
			'source_url'  => 'https://stg.test/wp-content/uploads/2026/08/test.png',
			'filename'    => 'test.png',
			'alt'         => 'A test image',
			'attachment'  => array_merge(
				array(
					'post_title'     => 'test',
					'post_name'      => 'test',
					'post_excerpt'   => 'The caption',
					'post_content'   => 'The description',
					'post_mime_type' => 'image/png',
					'post_parent'    => 12,
					'menu_order'     => 0,
					'post_date'      => '2026-08-11 00:27:25',
				),
				$attachment
			),
			'meta'        => array(),
		),
		$top
	);
}

echo "=== nothing changed -> nothing reported ===\n";
$same = PackageDiff::compare( media(), media() );
ok( 'an identical attachment reports no differences', array() === $same );

echo "\n=== THE REPORTED CASE: editing the details shows what changed ===\n";

$prod    = media();
$staging = media( array( 'post_title' => 'Sunset over the harbour' ) );
$fields  = PackageDiff::compare( $staging, $prod );

$title = field( $fields, 'post_title' );
ok( 'a renamed title is reported',       null !== $title );
ok( 'labelled for a human',              'Title' === ( $title['label'] ?? '' ) );
ok( 'with Production on the left',       'test' === ( $title['before'] ?? '' ) );
ok( 'and Staging on the right',          'Sunset over the harbour' === ( $title['after'] ?? '' ) );
ok( 'and nothing else is reported',      1 === count( $fields ) );

$alt = field( PackageDiff::compare( media( array(), array( 'alt' => 'A harbour at sunset' ) ), $prod ), 'alt' );
ok( 'changed ALT TEXT is reported',      null !== $alt );
ok( 'labelled "Alt text"',               'Alt text' === ( $alt['label'] ?? '' ) );
ok( 'showing both sides',                'A test image' === $alt['before'] && 'A harbour at sunset' === $alt['after'] );

foreach (
	array(
		'post_excerpt'   => array( 'A better caption', 'Caption' ),
		'post_content'   => array( 'A longer description', 'Description' ),
		'post_name'      => array( 'sunset-harbour', 'Slug' ),
		'post_mime_type' => array( 'image/webp', 'File type' ),
	) as $key => $expected
) {
	$found = field( PackageDiff::compare( media( array( $key => $expected[0] ) ), $prod ), $key );
	ok( "changed $key is reported as \"{$expected[1]}\"", $found && $expected[1] === $found['label'] && $expected[0] === $found['after'] );
}

echo "\n=== attachment meta is diffed like a post's ===\n";
$with_meta = media( array(), array( 'meta' => array( 'photographer' => array( 'A. Smith' ) ) ) );
$meta      = field( PackageDiff::compare( $with_meta, $prod ), 'photographer' );
ok( 'meta added on Staging is reported', $meta && 'A. Smith' === $meta['after'] );
ok( 'as a custom field',                 PackageDiff::GROUP_FIELD === ( $meta['group'] ?? '' ) );

// Same rule as a post: the importer replaces the meta it is given and deletes deployable
// keys the package no longer carries, so live-only meta is REMOVED, not kept.
$prod_meta = media( array(), array( 'meta' => array( 'stale_key' => array( 'x' ) ) ) );
$removed   = field( PackageDiff::compare( media(), $prod_meta ), 'stale_key' );
ok( 'meta only on Production is reported as removed', $removed && PackageDiff::CHANGE_REMOVED === $removed['change'] );

echo "\n=== what must NEVER be reported, or every push shows a difference ===\n";

// The domain differs by definition. Comparing it would flag every media object for ever.
$other_domain = media( array(), array( 'source_url' => 'https://prod.test/wp-content/uploads/2026/08/test.png' ) );
ok( 'a different source URL is not a difference', array() === PackageDiff::compare( media(), $other_domain ) );

// The upload date and the parent post id are per-site facts, not content.
ok( 'a different upload date is not a difference', array() === PackageDiff::compare( media(), media( array( 'post_date' => '2020-01-01 00:00:00' ) ) ) );
ok( 'a different parent id is not a difference',   array() === PackageDiff::compare( media(), media( array( 'post_parent' => 999 ) ) ) );

echo "\n=== the filename IS compared, once Production reports a comparable one ===\n";
//
// ObjectEndpoint substitutes MediaIdentity::stable_filename() before replying, which
// recovers the ORIGINAL name from the source-URL stamp. So a rename Production performed
// on itself never shows, while a genuinely different file does.
ok(
	'a Production-side -1 suffix would show if it were NOT normalised',
	null !== field( PackageDiff::compare( media(), media( array(), array( 'filename' => 'test-1.png' ) ) ), 'filename' )
);
ok(
	'and the normalised name compares equal',
	array() === PackageDiff::compare( media(), media( array(), array( 'filename' => 'test.png' ) ) )
);

echo "\n=== a media object missing on Production reads as all-new ===\n";
$all_new = PackageDiff::compare( media(), null );
ok( 'something is reported',        count( $all_new ) > 0 );
ok( 'the title is an addition',     PackageDiff::CHANGE_ADDED === field( $all_new, 'post_title' )['change'] );
ok( 'the alt text too',             PackageDiff::CHANGE_ADDED === field( $all_new, 'alt' )['change'] );
ok( 'and nothing claims a before',  '' === field( $all_new, 'post_title' )['before'] );

echo "\n=== a media package never takes the POST path ===\n";
// A media package has no post_content, no taxonomies and no featured image. Run through
// the post comparison it would report its real differences as nothing at all.
$post_shaped = PackageDiff::compare(
	array( 'type' => 'post', 'object' => array( 'post_title' => 'A page' ), 'meta' => array() ),
	array( 'type' => 'post', 'object' => array( 'post_title' => 'A page' ), 'meta' => array() )
);
ok( 'posts still compare as posts', array() === $post_shaped );

$dispatched = PackageDiff::compare( media( array( 'post_title' => 'changed' ) ), $prod );
ok( 'media dispatches on the package type', null !== field( $dispatched, 'post_title' ) );
// `object` is the post shape's key; a media package uses `attachment`. If dispatch broke,
// the media package would look empty and report nothing.
ok( 'and does not silently report nothing', count( $dispatched ) > 0 );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
