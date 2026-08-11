<?php
declare(strict_types=1);

$GLOBALS['dp_filters'] = array();
function apply_filters( $tag, $value, ...$args ) {
	if ( isset( $GLOBALS['dp_filters'][ $tag ] ) ) {
		foreach ( $GLOBALS['dp_filters'][ $tag ] as $cb ) { $value = $cb( $value, ...$args ); }
	}
	return $value;
}
function add_filter( $tag, $cb ) { $GLOBALS['dp_filters'][ $tag ][] = $cb; }

// `Support\Legacy` holds the pre-rename storage literals that MetaBlocklist and
// ContentSignature reference; required explicitly because these suites load individual files
// rather than registering an autoloader.
require __DIR__ . '/../src/Support/Legacy.php';
require __DIR__ . '/../src/Support/MetaBlocklist.php';
use IfsDeploy\Support\MetaBlocklist;

$pass = 0; $fail = 0;
function ok( string $n, bool $c ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS  $n\n"; } else { $fail++; echo "FAIL  $n\n"; } }

echo "=== the reported key ===\n";
ok( '_yoast_indexnow_last_ping ignored', MetaBlocklist::is_ignored( '_yoast_indexnow_last_ping' ) );

echo "\n=== volatile / per-site keys ignored ===\n";
foreach ( array(
	'_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_wp_desired_post_slug',
	'_pingme', '_encloseme', '_thumbnail_id',
	'_wp_trash_meta_status', '_wp_trash_meta_time', '_wp_trash_meta_comments_status',
	'_ifs_deploy_origin_id', '_ifs_deploy_src_sig',
	'_oembed_abc123', '_oembed_time_abc123', '_transient_foo', '_site_transient_bar',
) as $key ) {
	ok( "ignored: $key", MetaBlocklist::is_ignored( $key ) );
}

echo "\n=== real content meta must NOT be ignored ===\n";
foreach ( array(
	'hero_subtitle', '_hero_subtitle', 'cards', '_wp_page_template',
	'_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_focuskw',
	'_wp_attachment_image_alt', 'my_custom_field',
) as $key ) {
	ok( "kept: $key", ! MetaBlocklist::is_ignored( $key ) );
}

echo "\n=== filter can add project-specific keys ===\n";
add_filter( 'ifs_deploy_ignore_meta_key', function ( $ignored, $key ) {
	return $ignored || 0 === strpos( (string) $key, '_elementor_css' );
} );
ok( 'filter adds _elementor_css',       MetaBlocklist::is_ignored( '_elementor_css' ) );
ok( 'filter does not affect others',    ! MetaBlocklist::is_ignored( 'hero_subtitle' ) );
ok( 'built-ins still ignored',          MetaBlocklist::is_ignored( '_edit_lock' ) );

echo "\n=== filter() drops ignored keys from a map ===\n";
$meta = array(
	'hero_subtitle'             => array( 'Hi' ),
	'_yoast_indexnow_last_ping' => array( '1785997268' ),
	'_edit_lock'                => array( '123:1' ),
	'cards'                     => array( array( 'a' ) ),
);
$filtered = MetaBlocklist::filter( $meta );
ok( 'noise removed',   ! isset( $filtered['_yoast_indexnow_last_ping'], $filtered['_edit_lock'] ) );
ok( 'content kept',    isset( $filtered['hero_subtitle'], $filtered['cards'] ) );
ok( 'exactly 2 left',  2 === count( $filtered ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
