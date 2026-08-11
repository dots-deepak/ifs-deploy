<?php
declare(strict_types=1);

/*
 * Verifies the Compare & Sync "always Different" fix: the same content must hash
 * identically on Staging and Production even though the import rewrites URLs.
 */

$GLOBALS['dp_home']   = '';
$GLOBALS['dp_remote'] = '';

function home_url() { return $GLOBALS['dp_home']; }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/' ); }
function get_option( $name, $default = false ) {
	return 'ifs_deploy_remote' === $name ? array( 'url' => $GLOBALS['dp_remote'] ) : $default;
}
function get_post( $id = null ) { return null; }
function get_post_meta( $id, $key = '', $single = false ) { return array(); }
function get_object_taxonomies( $t ) { return array(); }
function wp_get_object_terms( $id, $tax, $args = array() ) { return array(); }
function get_post_thumbnail_id( $id ) { return 0; }
function get_attached_file( $id ) { return ''; }
function apply_filters( $tag, $value ) { return $value; }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }

// `Support\Legacy` holds the pre-rename storage literals that MetaBlocklist and
// ContentSignature reference; required explicitly because these suites load individual files
// rather than registering an autoloader.
require __DIR__ . '/../src/Support/Legacy.php';
require __DIR__ . '/../src/Export/PostExporter.php';
function wp_parse_url( $url, $c = -1 ) { return -1 === $c ? parse_url( $url ) : parse_url( $url, $c ); }
require __DIR__ . '/../src/Support/UrlRewriter.php';
require __DIR__ . '/../src/Support/Config.php';
require __DIR__ . '/../src/Support/ContentSignature.php';

use IfsDeploy\Support\ContentSignature;

// Stand-in for WP_Post — normalize() only reads these fields.
class WP_Post {
	public $ID = 1;
	public $post_title = 'How Asset Investment Planning Strengthens Regulatory Readiness';
	public $post_content = '';
	public $post_excerpt = '';
	public $post_status = 'publish';
	public $post_name = 'asset-investment';
	public $post_parent = 0;
	public $menu_order = 0;
	public $post_type = 'post';
}

const STAGING = 'https://copperlfstg.wpengine.com';
const PROD    = 'https://www.copperlf.com';

function sig( string $home, string $remote, string $content ): string {
	$GLOBALS['dp_home']   = $home;
	$GLOBALS['dp_remote'] = $remote;

	$post               = new WP_Post();
	$post->post_content = $content;

	return md5( (string) wp_json_encode( ContentSignature::normalize( $post ) ) );
}

function check( string $name, string $staging_content, string $prod_content, bool $expect_match ): void {
	// Staging knows Production's URL; Production leaves the remote blank.
	$a = sig( STAGING, PROD, $staging_content );
	$b = sig( PROD, '', $prod_content );

	$match = ( $a === $b );
	printf(
		"%s  %-52s (expected %s, got %s)\n",
		$match === $expect_match ? 'PASS' : 'FAIL',
		$name,
		$expect_match ? 'in sync' : 'different',
		$match ? 'in sync' : 'different'
	);
}

// The reported bug: internal link, rewritten by the import. Must be in sync.
check(
	'internal link (rewritten on import)',
	'<p>See <a href="' . STAGING . '/about">about</a>.</p>',
	'<p>See <a href="' . PROD . '/about">about</a>.</p>',
	true
);

// Block markup stores escaped slashes.
check(
	'block markup with escaped slashes',
	'<!-- wp:image {"url":"' . str_replace( '/', '\/', STAGING ) . '\/wp-content\/x.jpg"} -->',
	'<!-- wp:image {"url":"' . str_replace( '/', '\/', PROD ) . '\/wp-content\/x.jpg"} -->',
	true
);

// Editor pasted the LIVE url into Staging; the import leaves it untouched.
check(
	'live URL pasted into Staging content',
	'<p><a href="' . PROD . '/contact">contact</a></p>',
	'<p><a href="' . PROD . '/contact">contact</a></p>',
	true
);

// No URLs at all — unchanged behaviour.
check( 'plain content, identical', '<p>Hello world.</p>', '<p>Hello world.</p>', true );

// A REAL difference must still be caught (the fix must not mask edits).
check( 'genuinely different text', '<p>Version one.</p>', '<p>Version two.</p>', false );

// A real difference that also contains URLs must still be caught.
check(
	'different text plus internal link',
	'<p>New copy</p><a href="' . STAGING . '/about">about</a>',
	'<p>Old copy</p><a href="' . PROD . '/about">about</a>',
	false
);

// Different link TARGET (not just domain) must still be caught.
check(
	'internal link pointing somewhere else',
	'<a href="' . STAGING . '/about">x</a>',
	'<a href="' . PROD . '/services">x</a>',
	false
);
