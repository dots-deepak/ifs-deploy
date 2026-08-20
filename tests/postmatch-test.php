<?php
declare(strict_types=1);

/*
 * PostImporter's three review fixes, exercised against the real code:
 *
 *  1. ID PARITY MUST BE CORROBORATED. `match_by_id()` matched on id + post_type
 *     alone, so on sites that were never cloned Staging post 412 silently
 *     overwrote whatever unrelated page Production kept at 412. No error, no
 *     duplicate, no warning — and the pre-deploy snapshot made it look ordinary.
 *
 *  2. post_date_gmt IS SENT AND MUST BE APPLIED. The exporter always sent it and
 *     the importer always dropped it, so core re-derived it from post_date using
 *     the RECEIVING site's timezone and every deployed date shifted by the offset
 *     between the two sites.
 *
 *  3. THE FEATURED IMAGE NEEDS ITS ORIGIN ID. `media_sideload_image()` cannot pass
 *     `import_id`, so the image landed on a fresh Production id and every raw ACF
 *     image field pointing at it broke.
 */

// --- WordPress surface --------------------------------------------------------

// The sideload fallback require_once's three wp-admin includes unconditionally, so a
// path with files at it is needed before that branch can be reached at all. The
// functions are stubbed below; tests/stubs only satisfies the require.
define( 'ABSPATH', __DIR__ . '/stubs/' );

function __( $s, $d = '' ) { return $s; }
function esc_url_raw( $u ) { return $u; }
function wp_parse_url( $u, $c = -1 ) { return -1 === $c ? parse_url( $u ) : parse_url( $u, $c ); }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/' ); }
function trailingslashit( $s ) { return rtrim( (string) $s, '/' ) . '/'; }
function home_url( $p = '' ) { return 'https://prod.test' . $p; }
function apply_filters( $tag, $value, ...$args ) { return $value; }
function wp_slash( $v ) { return $v; }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function get_option( $n, $d = false ) { return $GLOBALS['options'][ $n ] ?? $d; }
function update_option( $n, $v, $a = null ) { $GLOBALS['options'][ $n ] = $v; return true; }
function wp_kses( $c, $a = array(), $p = array() ) { return $c; }
function taxonomy_exists( $t ) { return false; }
function wp_set_object_terms( ...$a ) { return array(); }
function set_post_thumbnail( $post_id, $thumb_id ) { $GLOBALS['thumbnails'][ (int) $post_id ] = (int) $thumb_id; return true; }
function delete_post_meta( $id, $k ) { unset( $GLOBALS['meta'][ (int) $id ][ $k ] ); return true; }
function add_post_meta( $id, $k, $v ) { $GLOBALS['meta'][ (int) $id ][ $k ] = $v; return 1; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['meta'][ (int) $id ][ $k ] = $v; return true; }
function maybe_unserialize( $v ) { return $v; }
function sanitize_text_field( $v ) { return $v; }
function current_time( $t = 'mysql', $gmt = 0 ) { return '2026-08-11 10:00:00'; }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function wp_generate_uuid4() { return 'uuid-0000'; }
function absint( $v ) { return abs( (int) $v ); }

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}

class WP_Post {
	public $ID;
	public $post_type;
	public $post_name;
	public $post_title;
	public $post_date;
	public function __construct( $id, array $row ) {
		$this->ID         = $id;
		$this->post_type  = $row['post_type'] ?? 'page';
		$this->post_name  = $row['post_name'] ?? '';
		$this->post_title = $row['post_title'] ?? '';
		$this->post_date  = $row['post_date'] ?? '';
	}
}

$GLOBALS['posts']      = array();
$GLOBALS['meta']       = array();
$GLOBALS['options']    = array();
$GLOBALS['thumbnails'] = array();
$GLOBALS['written']    = array();   // every $postarr handed to insert/update.
$GLOBALS['media_pkgs'] = array();   // packages handed to MediaImporter::import().
$GLOBALS['sideloaded'] = array();   // urls handed to media_sideload_image().

function get_post( $id = null ) {
	$id = (int) $id;
	return isset( $GLOBALS['posts'][ $id ] ) ? new WP_Post( $id, $GLOBALS['posts'][ $id ] ) : null;
}
function get_post_meta( $id, $key = '', $single = false ) {
	$v = $GLOBALS['meta'][ (int) $id ][ $key ] ?? '';
	return $single ? $v : ( '' === $v ? array() : array( $v ) );
}
function get_attached_file( $id ) { return $GLOBALS['posts'][ (int) $id ]['file'] ?? ''; }
function wp_update_post( $postarr, $error = false ) {
	$GLOBALS['written'][] = $postarr;
	return (int) $postarr['ID'];
}
function wp_insert_post( $postarr, $error = false ) {
	$GLOBALS['written'][] = $postarr;
	return (int) ( $postarr['import_id'] ?? 999 );
}
function media_sideload_image( $url, $post_id, $desc = null, $return = 'html' ) {
	$GLOBALS['sideloaded'][] = $url;
	return 4242;
}

/**
 * Gather every `['key' => …, 'value' => …]` clause at any depth.
 *
 * Written recursively on purpose. `find_by_origin()` passes
 * `array( 'relation' => 'AND', array(…), array(…) )` while `find_attachment_by_source()`
 * passes `array( array(…) )`, so a stub that reached for a fixed index read only ONE of
 * the two origin clauses — and then happily "matched" a post whose origin SITE was
 * somebody else's, which is precisely the condition these tests exist to detect. The
 * assertions passed while proving nothing.
 */
function collect_meta_clauses( $value, array &$into ): void {
	if ( ! is_array( $value ) ) {
		return;
	}
	if ( isset( $value['key'] ) ) {
		$into[] = $value;
		return;
	}
	foreach ( $value as $item ) {
		collect_meta_clauses( $item, $into );
	}
}

class WP_Query {
	public $posts = array();
	public function __construct( $args ) {
		$clauses = array();
		collect_meta_clauses( $args['meta_query'] ?? array(), $clauses );

		// Slug lookup (match_by_slug) rather than a meta query.
		if ( empty( $clauses ) && isset( $args['name'] ) ) {
			foreach ( $GLOBALS['posts'] as $id => $row ) {
				if ( ( $row['post_name'] ?? '' ) === $args['name'] && ( $row['post_type'] ?? '' ) === $args['post_type'] ) {
					$this->posts = array( $id );
					return;
				}
			}
			return;
		}

		if ( empty( $clauses ) ) { return; }

		// ALL clauses must hold: every meta_query the importer builds is an AND.
		foreach ( $GLOBALS['meta'] as $id => $meta ) {
			$all = true;
			foreach ( $clauses as $c ) {
				if ( (string) ( $meta[ $c['key'] ] ?? '' ) !== (string) $c['value'] ) { $all = false; break; }
			}
			if ( $all ) { $this->posts = array( $id ); return; }
		}
	}
}

require __DIR__ . '/../src/Support/Logger.php';
require __DIR__ . '/../src/Support/Config.php';
require __DIR__ . '/../src/Support/DebugLog.php';
require __DIR__ . '/../src/Support/MediaIdentity.php';
require __DIR__ . '/../src/Support/SafeData.php';
require __DIR__ . '/../src/Support/UrlRewriter.php';
require __DIR__ . '/../src/Support/ContentFirewall.php';
require __DIR__ . '/../src/Import/MediaUrlResolver.php';

// Before PostImporter, so `new MediaImporter()` inside it finds the stand-in rather
// than an autoloaded class that would want to perform a real download.
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

const STG = 'staging-site-uuid';

$pass = 0; $fail = 0;
function ok( string $n, bool $c ): void {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "PASS  $n\n"; } else { $fail++; echo "FAIL  $n\n"; }
}

function reset_store(): void {
	$GLOBALS['posts']      = array();
	$GLOBALS['meta']       = array();
	$GLOBALS['thumbnails'] = array();
	$GLOBALS['written']    = array();
	$GLOBALS['media_pkgs'] = array();
	$GLOBALS['sideloaded'] = array();
}

function add_post( int $id, array $row ): void { $GLOBALS['posts'][ $id ] = $row; }

function pkg( int $origin_id, array $fields = array(), array $extra = array() ): array {
	return array_merge(
		array(
			'type'        => 'post',
			'action'      => 'update',
			'origin_id'   => $origin_id,
			'origin_site' => STG,
			'origin_url'  => 'https://stg.test',
			'object'      => array_merge(
				array(
					'post_title' => 'About Us',
					'post_name'  => 'about-us',
					'post_type'  => 'page',
					'post_date'  => '2026-01-04 09:00:00',
					'post_status' => 'publish',
				),
				$fields
			),
		),
		$extra
	);
}

$importer = new PostImporter();

echo "=== THE DANGEROUS ONE: ID parity may not select an overwrite target alone ===\n";

reset_store();
// Production 412 is a completely unrelated page that merely occupies the same id.
add_post( 412, array( 'post_type' => 'page', 'post_name' => 'careers', 'post_title' => 'Careers', 'post_date' => '2019-06-01 12:00:00' ) );
$located = $importer->locate( pkg( 412 ) );
ok( 'an unrelated page at the same id is NOT matched', 0 === $located['id'] );
ok( 'and the strategy reports no match', 'none' === $located['strategy'] );

reset_store();
add_post( 412, array( 'post_type' => 'page', 'post_name' => 'careers', 'post_title' => 'Careers', 'post_date' => '2019-06-01 12:00:00' ) );
ok( 'so a deploy would INSERT rather than overwrite it', 0 === $importer->find_target( pkg( 412 ) ) );

echo "\n=== cloned sites: parity still works, and any ONE signal is enough ===\n";

reset_store();
add_post( 412, array( 'post_type' => 'page', 'post_name' => 'about-us', 'post_title' => 'About Us', 'post_date' => '2026-01-04 09:00:00' ) );
ok( 'everything agrees -> matched by id', 412 === $importer->locate( pkg( 412 ) )['id'] );

reset_store();
// THE REGRESSION THIS GUARDS AGAINST: requiring the slug alone would break a rename
// and insert a duplicate of the very page it was meant to update.
add_post( 412, array( 'post_type' => 'page', 'post_name' => 'about-us-old', 'post_title' => 'About Us', 'post_date' => '2026-01-04 09:00:00' ) );
ok( 'slug renamed on Staging -> still matched (title agrees)', 412 === $importer->locate( pkg( 412 ) )['id'] );

reset_store();
add_post( 412, array( 'post_type' => 'page', 'post_name' => 'about-us', 'post_title' => 'Who We Are', 'post_date' => '2026-01-04 09:00:00' ) );
ok( 'title rewritten on Staging -> still matched (slug agrees)', 412 === $importer->locate( pkg( 412 ) )['id'] );

reset_store();
add_post( 412, array( 'post_type' => 'page', 'post_name' => 'about-us-2', 'post_title' => 'Who We Are', 'post_date' => '2026-01-04 09:00:00' ) );
ok( 'both rewritten but the publish date agrees -> still matched', 412 === $importer->locate( pkg( 412 ) )['id'] );

reset_store();
add_post( 412, array( 'post_type' => 'page', 'post_name' => 'ABOUT-US', 'post_title' => 'x', 'post_date' => 'x' ) );
ok( 'slug comparison is case-insensitive', 412 === $importer->locate( pkg( 412 ) )['id'] );

echo "\n=== a stamp proving different origin always refuses PARITY ===\n";
//
// Note what is asserted, and what is not. These cases pin the ID strategy only. The
// SLUG strategy is a separate, still-legitimate signal, so when the slug also agrees
// the object is matched anyway — just not on the strength of its id. Both shapes are
// covered below so the difference is recorded rather than assumed.

reset_store();
// Same id, same type, and everything else agrees too — but the stamp says this object
// came from somewhere else entirely, which is proof and beats any amount of similarity.
add_post( 412, array( 'post_type' => 'page', 'post_name' => 'about-us', 'post_title' => 'About Us', 'post_date' => '2026-01-04 09:00:00' ) );
$GLOBALS['meta'][412] = array( '_ifs_deploy_origin_site' => 'a-different-site-uuid', '_ifs_deploy_origin_id' => '412' );
ok( 'stamped from another site -> not matched BY ID', 'id' !== $importer->locate( pkg( 412 ) )['strategy'] );

reset_store();
// Nothing else agrees, so with parity refused there is nothing left to match on.
add_post( 412, array( 'post_type' => 'page', 'post_name' => 'careers', 'post_title' => 'Careers', 'post_date' => '2019-06-01 12:00:00' ) );
$GLOBALS['meta'][412] = array( '_ifs_deploy_origin_site' => 'a-different-site-uuid', '_ifs_deploy_origin_id' => '412' );
ok( 'and with no other signal it is not matched at all', 0 === $importer->locate( pkg( 412 ) )['id'] );

reset_store();
add_post( 412, array( 'post_type' => 'page', 'post_name' => 'about-us', 'post_title' => 'About Us', 'post_date' => '2026-01-04 09:00:00' ) );
$GLOBALS['meta'][412] = array( '_ifs_deploy_origin_site' => STG, '_ifs_deploy_origin_id' => '900' );
ok( 'stamped from a different origin id -> not matched BY ID', 'id' !== $importer->locate( pkg( 412 ) )['strategy'] );

reset_store();
add_post( 412, array( 'post_type' => 'page', 'post_name' => 'careers', 'post_title' => 'Careers', 'post_date' => '2019-06-01 12:00:00' ) );
$GLOBALS['meta'][412] = array( '_ifs_deploy_origin_site' => STG, '_ifs_deploy_origin_id' => '900' );
ok( 'and likewise refuses outright with nothing else to go on', 0 === $importer->locate( pkg( 412 ) )['id'] );

echo "\n=== the precise strategies are untouched ===\n";

reset_store();
add_post( 88, array( 'post_type' => 'page', 'post_name' => 'renamed-on-prod', 'post_title' => 'Renamed', 'post_date' => '2001-01-01 00:00:00' ) );
$GLOBALS['meta'][88] = array( '_ifs_deploy_origin_id' => '412', '_ifs_deploy_origin_site' => STG );
$located = $importer->locate( pkg( 412 ) );
ok( 'the origin link still wins, whatever the fields say', 88 === $located['id'] );
ok( 'and reports the origin strategy', 'origin' === $located['strategy'] );

reset_store();
// Nothing at 412; the slug strategy must still find the page at another id.
add_post( 77, array( 'post_type' => 'page', 'post_name' => 'about-us', 'post_title' => 'About Us', 'post_date' => 'x' ) );
$located = $importer->locate( pkg( 412 ) );
ok( 'slug fallback still resolves', 77 === $located['id'] );
ok( 'and reports the slug strategy', 'slug' === $located['strategy'] );

echo "\n=== post_date_gmt survives the trip ===\n";

reset_store();
add_post( 412, array( 'post_type' => 'page', 'post_name' => 'about-us', 'post_title' => 'About Us', 'post_date' => '2026-01-04 09:00:00' ) );
$importer->import( pkg( 412, array( 'post_date_gmt' => '2026-01-04 03:30:00' ) ) );
$written = $GLOBALS['written'][0] ?? array();

ok( 'post_date_gmt is passed to WordPress', isset( $written['post_date_gmt'] ) );
ok( 'with the sending site\'s value, not a recomputed one', '2026-01-04 03:30:00' === ( $written['post_date_gmt'] ?? '' ) );
ok( 'and post_date is still sent alongside it', '2026-01-04 09:00:00' === ( $written['post_date'] ?? '' ) );

reset_store();
add_post( 412, array( 'post_type' => 'page', 'post_name' => 'about-us', 'post_title' => 'About Us', 'post_date' => '2026-01-04 09:00:00' ) );
$importer->import( pkg( 412 ) ); // an older Staging sends no post_date_gmt at all
ok(
	'an older Staging sends none, and core is left to derive it',
	'' === ( $GLOBALS['written'][0]['post_date_gmt'] ?? 'MISSING' )
);

echo "\n=== the featured image keeps its origin id ===\n";

reset_store();
add_post( 412, array( 'post_type' => 'page', 'post_name' => 'about-us', 'post_title' => 'About Us', 'post_date' => '2026-01-04 09:00:00' ) );
$importer->import(
	pkg( 412, array(), array(
		'featured_image' => array(
			'id'       => 63400,
			'url'      => 'https://stg.test/uploads/2026/08/hero.png',
			'filename' => 'hero.png',
			'alt'      => 'A hero',
		),
	) )
);

$media = $GLOBALS['media_pkgs'][0] ?? array();
ok( 'it goes through the media pipeline', ! empty( $GLOBALS['media_pkgs'] ) );
ok( 'carrying the SOURCE attachment id', 63400 === (int) ( $media['origin_id'] ?? 0 ) );
ok( 'and the origin site, so the stamps are right', STG === ( $media['origin_site'] ?? '' ) );
ok( 'and the original filename for parity checks', 'hero.png' === ( $media['filename'] ?? '' ) );
ok( 'it is NOT sideloaded any more', empty( $GLOBALS['sideloaded'] ) );
ok( 'the thumbnail is set to what the pipeline returned', 7777 === ( $GLOBALS['thumbnails'][412] ?? 0 ) );

reset_store();
add_post( 412, array( 'post_type' => 'page', 'post_name' => 'about-us', 'post_title' => 'About Us', 'post_date' => '2026-01-04 09:00:00' ) );
$importer->import(
	pkg( 412, array(), array(
		// An older Staging sends no id — the sideload fallback must still work, or a
		// mixed-version pair would silently stop transferring featured images.
		'featured_image' => array( 'url' => 'https://stg.test/uploads/2026/08/hero.png', 'filename' => 'hero.png', 'alt' => '' ),
	) )
);
ok( 'without an id it falls back to sideloading', array( 'https://stg.test/uploads/2026/08/hero.png' ) === $GLOBALS['sideloaded'] );
ok( 'and does not call the media pipeline', empty( $GLOBALS['media_pkgs'] ) );

reset_store();
add_post( 412, array( 'post_type' => 'page', 'post_name' => 'about-us', 'post_title' => 'About Us', 'post_date' => '2026-01-04 09:00:00' ) );
// Already imported once: the source-url stamp must short-circuit before either path.
$GLOBALS['meta'][555] = array( '_ifs_deploy_source_url' => 'https://stg.test/uploads/2026/08/hero.png' );
$importer->import(
	pkg( 412, array(), array(
		'featured_image' => array( 'id' => 63400, 'url' => 'https://stg.test/uploads/2026/08/hero.png', 'filename' => 'hero.png', 'alt' => '' ),
	) )
);
ok( 'an already-imported image is reused, not re-fetched', empty( $GLOBALS['media_pkgs'] ) && empty( $GLOBALS['sideloaded'] ) );
ok( 'and it is the one the thumbnail points at', 555 === ( $GLOBALS['thumbnails'][412] ?? 0 ) );

echo "\n=== DELETES: a trashed post is a deletion, not an edit ===\n";
//
// THE REPORTED BUG. Core's wp_trash_post() runs in this order:
//
//     do_action( 'wp_trash_post', $id );                 // on_delete  → row action 'delete'
//     wp_update_post( [ 'post_status' => 'trash' ] );    // save_post  → on_save
//
// So the delete row was overwritten by the save that trashing itself performs, and the
// queue held an `update` carrying a package whose status happened to be `trash`. Pushing
// that took the UPDATE path: where the object matched it was trashed and looked fine;
// where it did NOT match, the importer did what an update does with an object it cannot
// find — it INSERTED one. Deleting a page on Staging created a trashed copy on Production.
$observer_src = (string) php_strip_whitespace( __DIR__ . '/../src/Detection/PostObserver.php' );

ok( 'a trashed post is not trackable as a save', (bool) preg_match( "/'trash' === \\\$post->post_status/", $observer_src ) );
// Untrashing must still queue normally: wp_untrash_post() fires save_post with the
// RESTORED status, so a recovered post is the update it looks like.
ok( 'and the rule keys off status, not the hook', false === strpos( $observer_src, "'wp_untrash_post'" ) );

echo "\n=== DELETES: the package carries enough to be found ===\n";
//
// It used to carry only the title and post type. `locate()` tries origin stamp, then id
// parity, then slug — with no slug the third could never run and the second had nothing but
// the title to corroborate with. A page renamed before deletion, or one on a cloned site,
// could not be found at all.
$service_src = (string) php_strip_whitespace( __DIR__ . '/../src/Client/DeploymentService.php' );

ok( 'a delete package carries the slug', false !== strpos( $service_src, "'post_name' => \$slug" ) );
ok( 'and the publish date', (bool) preg_match( "/'post_date' => \(string\) \\\$post->post_date/", $service_src ) );

/*
 * The `__trashed` trap.
 *
 * wp_trash_post() RENAMES the slug so the URL is freed for a replacement page. Sending
 * `about-us__trashed` would be worse than sending nothing: it matches nothing on
 * Production while looking like a legitimate slug.
 */
ok( 'the original slug is preferred', false !== strpos( $service_src, '_wp_desired_post_slug' ) );
ok( 'and the __trashed suffix is stripped as a fallback', false !== strpos( $service_src, "'__trashed' === substr( \$slug, -9 )" ) );
// A permanently deleted post is gone; then nothing is sent and the honest failure reports it.
ok( 'a vanished post simply sends nothing extra', (bool) preg_match( '/! \$post instanceof \\\\WP_Post \) \{\s*return array\(\);/', $service_src ) );

echo "\n=== and those fields actually let the matcher work ===\n";
//
// Proving the point rather than asserting the plumbing: a delete package shaped like the
// new one resolves against a Production post that shares only its slug.
reset_store();
add_post( 412, array( 'post_type' => 'page', 'post_name' => 'about-us', 'post_title' => 'Renamed On Prod', 'post_date' => '2020-01-01 00:00:00' ) );

$delete_package = array(
	'type'        => 'post',
	'action'      => 'delete',
	'origin_id'   => 412,
	'origin_site' => STG,
	'object'      => array(
		'post_title' => 'About Us',          // differs from Production
		'post_type'  => 'page',
		'post_name'  => 'about-us',          // and this is what finds it
		'post_date'  => '2026-01-04 09:00:00',
	),
);

ok( 'a delete resolves by slug when the title has drifted', 412 === $importer->find_target( $delete_package ) );

// Without the slug — the old package shape — the same delete finds nothing.
$old_shape = $delete_package;
unset( $old_shape['object']['post_name'], $old_shape['object']['post_date'] );
ok( 'and the old title-only package could not', 0 === $importer->find_target( $old_shape ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
