<?php
declare(strict_types=1);

/*
 * Removing a value on Staging must remove it on Production.
 *
 * THE REPORTED BUG: setting a Yoast SEO meta description on Staging and pushing worked;
 * CLEARING it and pushing did not — the old description stayed live for ever. Yoast
 * deletes `_yoast_wpseo_metadesc` outright when the field is emptied, so the key simply
 * vanishes from the package, and an importer that only wrote the keys it was given had
 * nothing to act on.
 *
 * That is worse than not syncing at all: the two sites disagree while the plugin reports
 * the object as deployed.
 *
 * The safety rule is that `Support\MetaBlocklist` decides BOTH what is exported and what
 * may be deleted. A key that is never exported can never be "missing from the package",
 * so editor locks, `_thumbnail_id`, oEmbed/transient caches and IFS Deploy's own stamps
 * are structurally out of reach.
 */

define( 'ABSPATH', __DIR__ . '/stubs/' );

function __( $s, $d = '' ) { return $s; }
function esc_url_raw( $u ) { return $u; }
function wp_parse_url( $u, $c = -1 ) { return -1 === $c ? parse_url( $u ) : parse_url( $u, $c ); }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/' ); }
function home_url( $p = '' ) { return 'https://prod.test' . $p; }
function wp_slash( $v ) { return $v; }
function is_wp_error( $t ) { return false; }
function get_option( $n, $d = false ) { return $GLOBALS['options'][ $n ] ?? $d; }
function update_option( $n, $v, $a = null ) { $GLOBALS['options'][ $n ] = $v; return true; }
function wp_kses( $c, $a = array(), $p = array() ) { return $c; }
function taxonomy_exists( $t ) { return false; }
function wp_set_object_terms( ...$a ) { return array(); }
function set_post_thumbnail( $id, $t ) { return true; }
function maybe_unserialize( $v ) { return $v; }
function is_serialized( $data, $strict = true ) {
	if ( ! is_string( $data ) || strlen( $data ) < 4 || ':' !== ( $data[1] ?? '' ) ) { return false; }
	return (bool) preg_match( '/^[aOsbdi]:/', $data );
}
function sanitize_text_field( $v ) { return $v; }
function current_time( $t = 'mysql', $g = 0 ) { return '2026-08-11 10:00:00'; }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function wp_update_post( $postarr, $e = false ) { return (int) $postarr['ID']; }
function wp_insert_post( $postarr, $e = false ) { return (int) ( $postarr['import_id'] ?? 999 ); }

/**
 * The `ifs_deploy_ignore_meta_key` and `ifs_deploy_delete_missing_meta` filters are the
 * documented escape hatches, so the stub has to actually run callbacks rather than
 * return the value unchanged — otherwise the assertions about them prove nothing.
 */
$GLOBALS['filters'] = array();
function add_filter( $tag, $cb, $priority = 10, $args = 1 ) { $GLOBALS['filters'][ $tag ][] = $cb; return true; }
function remove_all_filters( $tag ) { unset( $GLOBALS['filters'][ $tag ] ); }
function apply_filters( $tag, $value, ...$args ) {
	foreach ( $GLOBALS['filters'][ $tag ] ?? array() as $cb ) {
		$value = $cb( $value, ...$args );
	}
	return $value;
}

class WP_Error {
	public function get_error_message() { return ''; }
	public function get_error_code() { return ''; }
}
class WP_Post {
	public $ID;
	public $post_type;
	public $post_name;
	public $post_title;
	public $post_date;
	public function __construct( $id, array $r ) {
		$this->ID         = $id;
		$this->post_type  = $r['post_type'] ?? 'page';
		$this->post_name  = $r['post_name'] ?? '';
		$this->post_title = $r['post_title'] ?? '';
		$this->post_date  = $r['post_date'] ?? '';
	}
}

$GLOBALS['posts']   = array( 412 => array( 'post_type' => 'page', 'post_name' => 'about-us', 'post_title' => 'About Us', 'post_date' => '2026-01-04 09:00:00' ) );
$GLOBALS['meta']    = array();
$GLOBALS['options'] = array();

function get_post( $id = null ) {
	$id = (int) $id;
	return isset( $GLOBALS['posts'][ $id ] ) ? new WP_Post( $id, $GLOBALS['posts'][ $id ] ) : null;
}
function get_post_meta( $id, $key = '', $single = false ) {
	$id = (int) $id;
	if ( '' === $key ) {
		// The whole map, the shape get_post_meta( $id ) returns: key => array of values.
		$out = array();
		foreach ( $GLOBALS['meta'][ $id ] ?? array() as $k => $v ) { $out[ $k ] = array( $v ); }
		return $out;
	}
	$v = $GLOBALS['meta'][ $id ][ $key ] ?? '';
	return $single ? $v : ( '' === $v ? array() : array( $v ) );
}
function delete_post_meta( $id, $k ) { unset( $GLOBALS['meta'][ (int) $id ][ $k ] ); return true; }
function add_post_meta( $id, $k, $v ) { $GLOBALS['meta'][ (int) $id ][ $k ] = $v; return 1; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['meta'][ (int) $id ][ $k ] = $v; return true; }
function get_attached_file( $id ) { return ''; }

class WP_Query {
	public $posts = array();
	public function __construct( $args ) {}
}

require __DIR__ . '/../src/Support/Logger.php';
require __DIR__ . '/../src/Support/Config.php';
require __DIR__ . '/../src/Support/DebugLog.php';
require __DIR__ . '/../src/Support/MediaIdentity.php';
require __DIR__ . '/../src/Support/Legacy.php';
require __DIR__ . '/../src/Support/MetaBlocklist.php';
require __DIR__ . '/../src/Support/SafeData.php';
require __DIR__ . '/../src/Support/UrlRewriter.php';
require __DIR__ . '/../src/Support/ContentFirewall.php';
require __DIR__ . '/../src/Import/MediaUrlResolver.php';
require __DIR__ . '/lib-media-importer.php';

/*
 * Status registry. PublishPolicy reads it to build its dropdown and to check that a
 * configured status still exists — a workflow plugin can be deactivated after its status
 * was chosen, and applying one WordPress no longer knows makes content unreachable.
 */
function get_post_stati( $args = array(), $output = 'names' ) {
	$all = array(
		'publish' => 'Published',
		'draft'   => 'Draft',
		'pending' => 'Pending Review',
		'private' => 'Private',
		'future'  => 'Scheduled',
		'trash'   => 'Trash',
		'inherit' => 'Inherit',
	);

	if ( 'objects' === $output ) {
		$out = array();
		foreach ( $all as $name => $label ) {
			$out[ $name ] = (object) array( 'name' => $name, 'label' => $label );
		}
		return $out;
	}

	return array_keys( $all );
}

function get_post_status_object( $status ) {
	$known = get_post_stati( array(), 'objects' );

	return $known[ $status ] ?? null;
}

if ( ! function_exists( 'post_type_exists' ) ) {
	function post_type_exists( $type ) {
		return in_array( $type, array( 'post', 'page', 'event', 'knowledge_hub', 'product' ), true );
	}
}

require __DIR__ . '/../src/Support/PublishPolicy.php';
require __DIR__ . '/../src/Import/PostImporter.php';

use IfsDeploy\Import\PostImporter;

$pass = 0; $fail = 0;
function ok( string $n, bool $c ): void {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "PASS  $n\n"; } else { $fail++; echo "FAIL  $n\n"; }
}

function prod_meta(): array { return $GLOBALS['meta'][412] ?? array(); }
function has_key( string $k ): bool { return array_key_exists( $k, prod_meta() ); }

/** A package for post 412 carrying exactly the meta given. */
function pkg( array $meta ): array {
	return array(
		'type'        => 'post',
		'action'      => 'update',
		'origin_id'   => 412,
		'origin_site' => 'staging-uuid',
		'origin_url'  => 'https://stg.test',
		'object'      => array(
			'post_title'  => 'About Us',
			'post_name'   => 'about-us',
			'post_type'   => 'page',
			'post_date'   => '2026-01-04 09:00:00',
			'post_status' => 'publish',
		),
		'meta'        => $meta,
	);
}

$importer = new PostImporter();

echo "=== THE REPORTED BUG: an emptied Yoast description must clear on Production ===\n";

// Production is where a previous deploy left it: description present.
$GLOBALS['meta'][412] = array(
	'_yoast_wpseo_metadesc' => 'An old description nobody wants any more',
	'_yoast_wpseo_title'    => 'About Us | Example',
);

// Staging now has the description CLEARED, so Yoast deleted the key and the package
// simply does not mention it.
$importer->import( pkg( array( '_yoast_wpseo_title' => array( 'About Us | Example' ) ) ) );

ok( 'the removed description is gone from Production', ! has_key( '_yoast_wpseo_metadesc' ) );
ok( 'the SEO title it still has is untouched',         'About Us | Example' === ( prod_meta()['_yoast_wpseo_title'] ?? '' ) );

echo "\n=== setting a value still works, and is not confused with removing one ===\n";
$GLOBALS['meta'][412] = array();
$importer->import( pkg( array( '_yoast_wpseo_metadesc' => array( 'A brand new description' ) ) ) );
ok( 'a newly added description arrives', 'A brand new description' === ( prod_meta()['_yoast_wpseo_metadesc'] ?? '' ) );

$importer->import( pkg( array( '_yoast_wpseo_metadesc' => array( 'An edited description' ) ) ) );
ok( 'an edited description replaces it', 'An edited description' === ( prod_meta()['_yoast_wpseo_metadesc'] ?? '' ) );

echo "\n=== an EMPTY STRING is a value, not an absence ===\n";
// Some fields are emptied rather than deleted. Both have to end up empty on Production,
// by different routes — this one is written, not deleted.
$GLOBALS['meta'][412] = array( '_yoast_wpseo_metadesc' => 'still here' );
$importer->import( pkg( array( '_yoast_wpseo_metadesc' => array( '' ) ) ) );
ok( 'an explicitly empty value is written through', '' === ( prod_meta()['_yoast_wpseo_metadesc'] ?? 'MISSING' ) );

echo "\n=== what CANNOT be deleted, because it is never exported ===\n";
//
// The blocklist is the safety rule: a key that is never exported can never be missing
// from a package, so it is structurally out of reach of the deletion.
$GLOBALS['meta'][412] = array(
	'_edit_lock'              => '1754900000:7',
	'_edit_last'              => '7',
	'_thumbnail_id'           => '3311',
	'_wp_old_slug'            => 'about-us-old',
	'_oembed_abc123'          => '<iframe></iframe>',
	'_transient_thing'        => 'cached',
	'_ifs_deploy_origin_id'   => '412',
	'_ifs_deploy_origin_site' => 'staging-uuid',
	'_ifs_deploy_src_sig'     => 'sig',
	'a_real_field'            => 'gone on staging',
);

$importer->import( pkg( array( 'kept_field' => array( 'x' ) ) ) );

foreach ( array( '_edit_lock', '_edit_last', '_thumbnail_id', '_wp_old_slug', '_oembed_abc123', '_transient_thing' ) as $protected ) {
	ok( "$protected survives", has_key( $protected ) );
}
// The origin stamps in particular: losing them means Production stops recognising its
// own page, and the next deploy creates a duplicate instead of updating it.
ok( '_ifs_deploy_origin_id survives',   has_key( '_ifs_deploy_origin_id' ) );
ok( '_ifs_deploy_origin_site survives', has_key( '_ifs_deploy_origin_site' ) );
ok( '_ifs_deploy_src_sig survives',     has_key( '_ifs_deploy_src_sig' ) );

ok( 'but ordinary meta absent from the package IS removed', ! has_key( 'a_real_field' ) );
ok( 'and the package still writes what it carries',         'x' === ( prod_meta()['kept_field'] ?? '' ) );

echo "\n=== the escape hatches work ===\n";

// A Production-only plugin's meta can be protected with the documented filter — the one
// list decides both what is deployed and what is left alone.
add_filter( 'ifs_deploy_ignore_meta_key', function ( $ignored, $key ) {
	return $ignored || 0 === strpos( (string) $key, '_prod_analytics_' );
}, 10, 2 );

$GLOBALS['meta'][412] = array( '_prod_analytics_views' => '900', 'other' => 'x' );
$importer->import( pkg( array() ) );

ok( 'a key protected by the filter survives', has_key( '_prod_analytics_views' ) );
ok( 'while an unprotected one still goes',    ! has_key( 'other' ) );

remove_all_filters( 'ifs_deploy_ignore_meta_key' );

// And the whole behaviour can be switched off per site.
add_filter( 'ifs_deploy_delete_missing_meta', function () { return false; }, 10, 3 );

$GLOBALS['meta'][412] = array( 'legacy' => 'kept by choice' );
$importer->import( pkg( array( 'new' => array( 'v' ) ) ) );

ok( 'ifs_deploy_delete_missing_meta => false restores the old behaviour', has_key( 'legacy' ) );
ok( 'and the package is still applied',                                   'v' === ( prod_meta()['new'] ?? '' ) );

remove_all_filters( 'ifs_deploy_delete_missing_meta' );

echo "\n=== an empty package does not strip a post bare ===\n";
// Belt and braces: this is the shape that would do the most damage if the rule were
// ever inverted, so it is worth stating outright.
$GLOBALS['meta'][412] = array( '_ifs_deploy_origin_id' => '412', '_edit_lock' => 'x', 'content_field' => 'y' );
$importer->import( pkg( array() ) );
ok( 'protected keys remain', has_key( '_ifs_deploy_origin_id' ) && has_key( '_edit_lock' ) );
ok( 'deployable ones do not', ! has_key( 'content_field' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
