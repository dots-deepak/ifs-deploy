<?php
declare(strict_types=1);
function wp_parse_url( $u, $c = -1 ) { return -1 === $c ? parse_url( $u ) : parse_url( $u, $c ); }

function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function maybe_unserialize( $v ) {
	if ( is_string( $v ) ) { $out = @unserialize( $v ); if ( false !== $out || 'b:0;' === $v ) { return $out; } }
	return $v;
}

// Term/post/attachment stand-ins.
class WP_Term { public $slug; public $name; public $parent = 0; public function __construct( $s, $n ) { $this->slug = $s; $this->name = $n; } }
class WP_Post { public $ID; public $post_name; public function __construct( $id, $slug ) { $this->ID = $id; $this->post_name = $slug; } }

function get_term( $id, $tax = '' ) {
	$map = array( 5 => array( 'news', 'News' ), 6 => array( 'events', 'Events' ) );
	return isset( $map[ (int) $id ] ) ? new WP_Term( $map[ (int) $id ][0], $map[ (int) $id ][1] ) : null;
}
function get_post( $id = null ) { return 9 === (int) $id ? new WP_Post( 9, 'about-us' ) : null; }
function get_attached_file( $id ) { return 71 === (int) $id ? '/uploads/hero.jpg' : ''; }
function get_post_meta( $id, $key = '', $single = false ) { return $single ? 'Hero alt' : array(); }

// `Support\Legacy` holds the pre-rename storage literals that MetaBlocklist and
// ContentSignature reference; required explicitly because these suites load individual files
// rather than registering an autoloader.
require __DIR__ . '/../src/Support/Legacy.php';
require __DIR__ . '/../src/Support/MediaIdentity.php';
require __DIR__ . '/../src/Export/PostExporter.php';
function apply_filters( $t, $v, ...$a ) { return $v; }
require __DIR__ . '/../src/Support/MetaBlocklist.php';
require __DIR__ . '/../src/Support/Json.php';
require __DIR__ . '/../src/Support/PackageDiff.php';
require __DIR__ . '/../src/Rollback/SnapshotPackage.php';

use IfsDeploy\Rollback\SnapshotPackage;
use IfsDeploy\Support\PackageDiff;

$pass = 0; $fail = 0;
function ok( string $n, bool $c ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS  $n\n"; } else { $fail++; echo "FAIL  $n\n"; } }
function field( array $fields, string $key ): ?array {
	foreach ( $fields as $f ) { if ( $f['key'] === $key ) { return $f; } }
	return null;
}

// A snapshot exactly as SnapshotStore writes it: raw meta, term IDs, thumbnail ID.
$snapshot = array(
	'post'         => array(
		'ID' => 42, 'post_title' => 'Old Title', 'post_content' => 'Old body.', 'post_excerpt' => '',
		'post_status' => 'publish', 'post_name' => 'the-page', 'post_type' => 'page',
		'post_parent' => 9, 'menu_order' => 0, 'post_date' => '2026-01-01 10:00:00',
		'comment_status' => 'closed', 'ping_status' => 'closed', 'post_password' => '',
	),
	'meta'         => array(
		'hero_subtitle'           => array( 'Old subtitle' ),
		'cards'                   => array( serialize( array( array( 'h' => 'A' ) ) ) ),
		'_ifs_deploy_origin_id'  => array( '42' ),   // must be filtered out
		'_ifs_deploy_src_sig'    => array( 'abc' ),  // must be filtered out
	),
	'thumbnail_id' => 71,
	'terms'        => array( 'category' => array( 5, 6 ), 'post_tag' => array() ),
);

echo "=== snapshot -> package conversion ===\n";
$pkg = SnapshotPackage::for_post( $snapshot );
ok( 'core fields carried through',   'Old Title' === $pkg['object']['post_title'] );
ok( 'meta unserialized',             is_array( $pkg['meta']['cards'][0] ) );
ok( 'blocklisted meta dropped',      ! isset( $pkg['meta']['_ifs_deploy_origin_id'] ) && ! isset( $pkg['meta']['_ifs_deploy_src_sig'] ) );
ok( 'term ids resolved to slugs',    'news' === $pkg['taxonomies']['category'][0]['slug'] );
ok( 'empty taxonomy kept as empty',  array() === $pkg['taxonomies']['post_tag'] );
ok( 'thumbnail id -> filename',      'hero.jpg' === $pkg['featured_image']['filename'] );
ok( 'parent id -> slug',             'about-us' === $pkg['parent_slug'] );
ok( 'label extracted',               'Old Title' === SnapshotPackage::label( 'post', $snapshot ) );

echo "\n=== current vs snapshot comparison ===\n";
// Live version: title edited, subtitle edited, one category removed, extra meta added.
$current = array(
	'object'         => array_merge( $snapshot['post'], array( 'post_title' => 'New Title' ) ),
	'meta'           => array(
		'hero_subtitle' => array( 'New subtitle' ),
		'cards'         => array( array( array( 'h' => 'A' ) ) ),
		'added_later'   => array( 'only live' ),
	),
	'taxonomies'     => array( 'category' => array( array( 'slug' => 'news' ) ) ),
	'featured_image' => array( 'filename' => 'hero.jpg', 'alt' => 'Hero alt' ),
	'parent_slug'    => 'about-us',
);

$fields = PackageDiff::compare( $pkg, $current, true );

$title = field( $fields, 'post_title' );
ok( 'title diff has snapshot on the "after" side', $title && 'Old Title' === $title['after'] && 'New Title' === $title['before'] );

$sub = field( $fields, 'hero_subtitle' );
ok( 'edited ACF field reported', $sub && 'Old subtitle' === $sub['after'] );

$cat = field( $fields, 'taxonomy:category' );
ok( 'restored category reported as added', $cat && 'events, news' === $cat['after'] );

ok( 'identical fields omitted', null === field( $fields, 'post_name' ) && null === field( $fields, 'featured_image' ) );

echo "
=== full_replace changes the meaning of live-only data ===
";
$extra = field( $fields, 'added_later' );
ok( 'live-only meta is REMOVED on rollback', $extra && PackageDiff::CHANGE_REMOVED === $extra['change'] );

/*
 * META IS NOW REMOVED ON BOTH PATHS, and that is a deliberate reversal.
 *
 * The deploy path used to report live-only meta as KEPT, because the importer only
 * wrote the keys it was given. That was the bug behind "clearing a Yoast meta
 * description on Staging leaves it live on Production": setting a value synced,
 * removing one did not. PostImporter now deletes deployable meta the package no longer
 * carries, so KEPT would be a promise the push does not honour.
 *
 * The blocklist is what makes that safe — `_edit_*`, `_thumbnail_id`, `_ifs_deploy_*`,
 * oEmbed and transient caches are never exported, so they can never be "missing from
 * the package" and can never be deleted.
 */
$deploy       = PackageDiff::compare( $pkg, $current, false );
$extra_deploy = field( $deploy, 'added_later' );
ok( 'live-only meta is REMOVED for a deploy too', $extra_deploy && PackageDiff::CHANGE_REMOVED === $extra_deploy['change'] );

/*
 * The flag still means something — just not for meta.
 *
 * A taxonomy the package does not mention is genuinely left alone by a deploy
 * (wp_set_object_terms runs only for taxonomies the package carries) while a rollback
 * clears it, so that is where the distinction now lives. `post_tag` is present-but-empty
 * in the snapshot and absent from the live side, which is exactly that shape.
 */
$prod_only_tax = array_merge( $current, array( 'taxonomies' => array( 'category' => array( array( 'slug' => 'news' ) ), 'post_format' => array( array( 'slug' => 'aside' ) ) ) ) );

$tax_deploy = field( PackageDiff::compare( $pkg, $prod_only_tax, false ), 'taxonomy:post_format' );
ok( 'a taxonomy the package omits is KEPT on a deploy', $tax_deploy && PackageDiff::CHANGE_KEPT === $tax_deploy['change'] );

$tax_rollback = field( PackageDiff::compare( $pkg, $prod_only_tax, true ), 'taxonomy:post_format' );
ok( 'and REMOVED on a rollback', $tax_rollback && PackageDiff::CHANGE_REMOVED === $tax_rollback['change'] );

// And the flag must not leak between calls.
$again = PackageDiff::compare( $pkg, $prod_only_tax, true );
ok( 'flag does not leak: REMOVED again', PackageDiff::CHANGE_REMOVED === field( $again, 'taxonomy:post_format' )['change'] );
$again2 = PackageDiff::compare( $pkg, $prod_only_tax, false );
ok( 'flag does not leak: KEPT again',    PackageDiff::CHANGE_KEPT === field( $again2, 'taxonomy:post_format' )['change'] );

echo "\n=== object deleted on Production ===\n";
$all_new = PackageDiff::compare( $pkg, null, true );
ok( 'missing object -> everything added', count( $all_new ) > 0 && 'added' === field( $all_new, 'post_title' )['change'] );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
