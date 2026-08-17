<?php
declare(strict_types=1);

/*
 * MEDIA IDS ACROSS THE TWO SITES, and what a removal actually means.
 *
 * Two reports, one file, because they meet in the same code path.
 *
 * ── 1. "Staging ID 1 becomes Production ID 5" ──────────────────────────────────────
 *
 * The ids were never un-synced: `MediaImporter` has always asked for the Staging id via
 * `import_id`. It asked only when that id happened to be FREE, though, and when it was not
 * it let WordPress allocate a fresh one and said nothing. So the mismatch was real, rare,
 * and completely silent — and it is not cosmetic, because this importer copies meta
 * verbatim and ACF fields, galleries and `wp-image-N` classes all store the bare number.
 *
 * The fix is to refuse, before the file is downloaded, and to say what is in the way. What
 * is covered here is that the refusal is narrow: it can only ever affect media that has
 * never been on the far site. Anything already deployed is matched by source URL or origin
 * stamp long before the check is reached, so a mismatch that already exists keeps working.
 *
 * ── 2. "Media does not move to Trash on Production" ───────────────────────────────
 *
 * `wp_delete_attachment( $id, false )` was called on the far side, on the reasoning that
 * the receiving site should decide what removal means. That reasoning was wrong:
 *
 *     // wp-includes/default-constants.php
 *     if ( ! defined( 'MEDIA_TRASH' ) ) {
 *         define( 'MEDIA_TRASH', false );
 *     }
 *
 * MEDIA_TRASH DEFAULTS TO FALSE. So on a Production that had not explicitly enabled media
 * trash — which is most of them — `force = false` fell straight through to a permanent
 * delete, and a file the operator trashed expecting to be able to restore was destroyed
 * while the deploy reported success.
 *
 * Recoverability belongs to the ACTION, not to the receiver's wp-config. These assert that
 * the intent travels and is obeyed, and that the trashed copy stays findable afterwards —
 * which it was not, and that is a second bug the first fix would otherwise have created.
 */

// ---------------------------------------------------------------- WordPress stubs

function __( $s, $d = '' ) { return $s; }
function esc_url_raw( $u ) { return $u; }
function sanitize_text_field( $s ) { return $s; }
function sanitize_file_name( $s ) { return $s; }
function current_time( $t = 'mysql', $g = 0 ) { return '2026-08-14 09:00:00'; }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_slash( $v ) { return $v; }
function size_format( $b, $d = 0 ) { return $b . ' bytes'; }
function get_option( $n, $d = false ) { return $GLOBALS['options'][ $n ] ?? $d; }
function update_option( $n, $v, $a = null ) { $GLOBALS['options'][ $n ] = $v; return true; }
function get_theme_mods() { return $GLOBALS['theme_mods'] ?? array(); }
function wp_get_attachment_url( $id ) { return 'https://stg.test/uploads/' . ( $GLOBALS['files'][ (int) $id ] ?? '' ); }
function get_attached_file( $id ) { return '/uploads/' . ( $GLOBALS['files'][ (int) $id ] ?? '' ); }
function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); return true; }
function clean_post_cache( $id ) { $GLOBALS['cache_cleaned'][] = (int) $id; }
function wp_generate_uuid4() { return 'uuid'; }

$GLOBALS['options']       = array();
$GLOBALS['theme_mods']    = array();
$GLOBALS['transients']    = array();
$GLOBALS['cache_cleaned'] = array();
$GLOBALS['filters']       = array();

function add_filter( $tag, $cb, $p = 10, $a = 1 ) { $GLOBALS['filters'][ $tag ][] = $cb; return true; }
function remove_all_filters( $tag ) { unset( $GLOBALS['filters'][ $tag ] ); }
function apply_filters( $tag, $value, ...$args ) {
	foreach ( $GLOBALS['filters'][ $tag ] ?? array() as $cb ) { $value = $cb( $value, ...$args ); }
	return $value;
}

class WP_Post {
	public $ID;
	public $post_type      = 'attachment';
	public $post_status    = 'inherit';
	public $post_title     = '';
	public $post_name      = '';
	public $post_excerpt   = '';
	public $post_content   = '';
	public $post_mime_type = 'image/png';
	public $post_parent    = 0;
	public $post_date      = '2026-08-14 09:00:00';
	public function __construct( $id ) {
		$this->ID          = (int) $id;
		$this->post_title  = $GLOBALS['titles'][ (int) $id ] ?? '';
		$this->post_name   = $this->post_title;
		$this->post_status = $GLOBALS['statuses'][ (int) $id ] ?? 'inherit';
		$this->post_type   = $GLOBALS['types'][ (int) $id ] ?? 'attachment';
	}
}

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code = $code; $this->message = $message; $this->data = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}

function is_wp_error( $t ) { return $t instanceof WP_Error; }

/*
 * ENOUGH OF A WORDPRESS FOR `sideload()` TO BE ENTERED AND THEN GIVE UP.
 *
 * Two tests below assert that the id check does NOT refuse — the filtered-off case and the
 * free-id case. Proving a negative here means the import must get PAST the check, and what
 * lies immediately past it is `sideload()`, which `require_once`s three wp-admin files and
 * then downloads.
 *
 * So ABSPATH points at a throwaway tree holding those three files, empty, and
 * `download_url()` fails on purpose. The import therefore ends in a DIFFERENT error, which
 * is exactly the assertion: it got far enough to try to fetch the file, so it was not
 * refused over the id.
 */
$abspath = sys_get_temp_dir() . '/ifs-deploy-mediaid-' . getmypid() . '/';

foreach ( array( 'wp-admin/includes' ) as $dir ) {
	if ( ! is_dir( $abspath . $dir ) ) { mkdir( $abspath . $dir, 0777, true ); }
}
foreach ( array( 'file.php', 'media.php', 'image.php' ) as $stub ) {
	file_put_contents( $abspath . 'wp-admin/includes/' . $stub, '<?php' );
}

define( 'ABSPATH', $abspath );
define( 'MB_IN_BYTES', 1024 * 1024 );
define( 'ARRAY_A', 'ARRAY_A' );

register_shutdown_function(
	static function () use ( $abspath ): void {
		foreach ( array( 'file.php', 'media.php', 'image.php' ) as $stub ) {
			@unlink( $abspath . 'wp-admin/includes/' . $stub );
		}
		@rmdir( $abspath . 'wp-admin/includes' );
		@rmdir( $abspath . 'wp-admin' );
		@rmdir( $abspath );
	}
);

/*
 * COUNTED, not just stubbed.
 *
 * "The file is not downloaded" is half the claim — the refusal is meant to happen BEFORE
 * the fetch, so a conflict costs nothing. Asserting that from the absence of side effects
 * would pass vacuously here, because this stub fails the download anyway.
 */
$GLOBALS['downloads'] = 0;
function download_url( $url, $timeout = 300 ) {
	++$GLOBALS['downloads'];
	return new WP_Error( 'stubbed_download', 'no network in tests' );
}
function wp_max_upload_size() { return 0; }
function wp_safe_remote_head( $url, $args = array() ) { return array( 'headers' => array(), 'response' => array( 'code' => 200 ) ); }
function wp_remote_retrieve_header( $r, $h ) { return ''; }
function wp_remote_retrieve_response_code( $r ) { return 200; }

$GLOBALS['files']    = array();  // id => relative file
$GLOBALS['titles']   = array();
$GLOBALS['statuses'] = array();
$GLOBALS['types']    = array();
$GLOBALS['meta']     = array();  // id => key => array of values

function get_post( $id = null ) {
	$id = (int) $id;
	return isset( $GLOBALS['files'][ $id ] ) || isset( $GLOBALS['types'][ $id ] ) ? new WP_Post( $id ) : null;
}

function get_post_meta( $id, $key = '', $single = false ) {
	$vals = $GLOBALS['meta'][ (int) $id ][ $key ] ?? array();
	if ( '_wp_attached_file' === $key && isset( $GLOBALS['files'][ (int) $id ] ) ) {
		$vals = array( $GLOBALS['files'][ (int) $id ] );
	}
	return $single ? ( $vals[0] ?? '' ) : $vals;
}
function update_post_meta( $id, $k, $v, $prev = '' ) { $GLOBALS['meta'][ (int) $id ][ $k ] = array( $v ); return true; }
function add_post_meta( $id, $k, $v, $u = false ) { $GLOBALS['meta'][ (int) $id ][ $k ][] = $v; return true; }
function delete_post_meta( $id, $k, $v = '' ) { unset( $GLOBALS['meta'][ (int) $id ][ $k ] ); return true; }

// What the far side DID, recorded rather than performed.
$GLOBALS['did'] = array();

function wp_trash_post( $id ) {
	$GLOBALS['did'][] = array( 'trash', (int) $id );
	if ( 'trash' === ( $GLOBALS['statuses'][ (int) $id ] ?? 'inherit' ) ) { return false; }
	$GLOBALS['statuses'][ (int) $id ] = 'trash';
	return new WP_Post( $id );
}
function wp_untrash_post( $id ) {
	$GLOBALS['did'][] = array( 'untrash', (int) $id );
	$GLOBALS['statuses'][ (int) $id ] = 'inherit';
	return new WP_Post( $id );
}
function wp_delete_attachment( $id, $force = false ) {
	$GLOBALS['did'][] = array( 'delete', (int) $id, (bool) $force );
	unset( $GLOBALS['files'][ (int) $id ], $GLOBALS['types'][ (int) $id ] );
	return true;
}

/**
 * Honours `post_status`, because that is the whole point of half these tests.
 *
 * A stub that ignored it would make "a trashed attachment is invisible to the matcher"
 * — the bug the trash fix would otherwise have introduced — impossible to observe, and
 * would have passed both before and after the fix.
 */
class WP_Query {
	public $posts = array();
	public function __construct( $args ) {
		$statuses = (array) ( $args['post_status'] ?? array( 'inherit' ) );
		$clauses  = $args['meta_query'][0] ?? array();
		$pairs    = array();

		foreach ( $clauses as $key => $clause ) {
			if ( 'relation' === $key || ! is_array( $clause ) ) { continue; }
			$pairs[ (string) $clause['key'] ] = (string) $clause['value'];
		}

		if ( empty( $pairs ) ) { return; }

		foreach ( array_keys( $GLOBALS['meta'] ) as $id ) {
			if ( ! in_array( $GLOBALS['statuses'][ $id ] ?? 'inherit', $statuses, true ) ) { continue; }

			$all = true;
			foreach ( $pairs as $k => $v ) {
				if ( (string) ( $GLOBALS['meta'][ $id ][ $k ][0] ?? '' ) !== $v ) { $all = false; break; }
			}
			if ( $all ) { $this->posts[] = (int) $id; }
		}

		sort( $this->posts );
		$this->posts = array_slice( $this->posts, 0, 1 );
	}
}

/** Records every UPDATE so the renumber can be checked table by table. */
class FakeWpdb {
	public $posts = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $term_relationships = 'wp_term_relationships';
	public $comments = 'wp_comments';
	public $prefix = 'wp_';
	public $updates = array();
	public $queries = array();
	public $queue = array();
	public $fail_on = '';
	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		return array( 'sql' => $sql, 'args' => $args );
	}
	public function update( $table, $data, $where, $f = null, $wf = null ) {
		if ( $table === $this->fail_on ) { return false; }
		$this->updates[] = array( 'table' => $table, 'data' => $data, 'where' => $where );

		if ( 'wp_ifs_deploy_queue' === $table ) {
			$n = 0;
			foreach ( $this->queue as $row ) {
				$match = true;
				foreach ( $where as $k => $v ) {
					if ( (string) ( $row->$k ?? '' ) !== (string) $v ) { $match = false; break; }
				}
				if ( $match ) { foreach ( $data as $k => $v ) { $row->$k = $v; } $n++; }
			}
			return $n;
		}
		return 1;
	}
	public function query( $q ) { $this->queries[] = is_array( $q ) ? $q['sql'] : $q; return 1; }
	public function get_var( $q ) { return $GLOBALS['db_max_id'] ?? 0; }
	public function get_row( $q, $out = null ) { return $GLOBALS['db_status'] ?? null; }
	public function get_col( $q ) { return $GLOBALS['db_col'] ?? array(); }
	public function get_results( $q, $o = null ) { return $GLOBALS['db_results'] ?? array(); }
	public function esc_like( $t ) { return $t; }
	public function get_charset_collate() { return ''; }
	public function insert( $t, $d, $f = null ) { return 1; }
	public function delete( $t, $w, $f = null ) { return 1; }
}

$GLOBALS['wpdb'] = new FakeWpdb();

require __DIR__ . '/../src/Support/Logger.php';
require __DIR__ . '/../src/Support/Config.php';
require __DIR__ . '/../src/Support/DebugLog.php';
require __DIR__ . '/../src/Support/Schema.php';
require __DIR__ . '/../src/Support/SafeData.php';
require __DIR__ . '/../src/Support/MediaIdentity.php';
require __DIR__ . '/../src/Support/MediaReferences.php';
// MediaLifecycle reads QueueRepository::STATUS_PENDING rather than repeating the literal,
// so the constant has to be loadable.
require __DIR__ . '/../src/Queue/QueueRepository.php';
require __DIR__ . '/../src/Queue/MediaLifecycle.php';
require __DIR__ . '/../src/Import/MediaImporter.php';

use IfsDeploy\Import\MediaImporter;
use IfsDeploy\Queue\MediaLifecycle;
use IfsDeploy\Support\MediaReferences;

$pass = 0; $fail = 0;
function ok( string $n, bool $c ): void {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "PASS  $n\n"; } else { $fail++; echo "FAIL  $n\n"; }
}

function reset_all(): void {
	$GLOBALS['files']    = array();
	$GLOBALS['titles']   = array();
	$GLOBALS['statuses'] = array();
	$GLOBALS['types']    = array();
	$GLOBALS['meta']     = array();
	$GLOBALS['did']      = array();
	$GLOBALS['options']  = array();
	$GLOBALS['theme_mods'] = array();
	$GLOBALS['wpdb']     = new FakeWpdb();
	remove_all_filters( 'ifs_deploy_require_media_id_parity' );
}

/** An attachment on the RECEIVING site, stamped as having come from Staging. */
function far_side( int $id, string $file, string $status = 'inherit', int $origin = 0, string $site = 'staging-uuid' ): void {
	$GLOBALS['files'][ $id ]    = $file;
	$GLOBALS['titles'][ $id ]   = $file;
	$GLOBALS['statuses'][ $id ] = $status;
	$GLOBALS['types'][ $id ]    = 'attachment';

	if ( $origin > 0 ) {
		$GLOBALS['meta'][ $id ][ MediaImporter::ORIGIN_ID_META ]   = array( $origin );
		$GLOBALS['meta'][ $id ][ MediaImporter::ORIGIN_SITE_META ] = array( $site );
		$GLOBALS['meta'][ $id ][ MediaImporter::SOURCE_URL_META ]  = array( 'https://stg.test/uploads/' . $file );
	}
}

function removal_package( int $origin, string $removal, string $file = 'photo.png' ): array {
	return array(
		'action'      => 'delete',
		'removal'     => $removal,
		'origin_id'   => $origin,
		'origin_site' => 'staging-uuid',
		'source_url'  => 'https://stg.test/uploads/' . $file,
		'filename'    => $file,
	);
}

function did( string $what ): array {
	return array_values( array_filter( $GLOBALS['did'], static fn( $e ) => $e[0] === $what ) );
}

$importer = new MediaImporter();

echo "\n=== a trash on Staging trashes on Production, whatever MEDIA_TRASH says there ===\n";
//
// The whole report. `wp_trash_post()` is called DIRECTLY, so the receiver's MEDIA_TRASH
// setting — which defaults to false — cannot turn a recoverable removal into a destruction.
reset_all();
far_side( 55, 'photo.png', 'inherit', 9 );

$res = $importer->import( removal_package( 9, 'trash' ) );

ok( 'the removal succeeds', is_array( $res ) && 55 === $res['object_id'] );
ok( 'and it was TRASHED', 1 === count( did( 'trash' ) ) );
ok( 'not deleted', 0 === count( did( 'delete' ) ) );
ok( 'and it is still there to restore', 'trash' === $GLOBALS['statuses'][55] );

echo "\n=== a permanent delete on Staging destroys it there, forcing past the trash ===\n";
//
// force = TRUE on purpose. Without it, a Production that DOES have MEDIA_TRASH enabled
// would stop at its own trash, and the two sites would quietly disagree in the other
// direction.
reset_all();
far_side( 56, 'photo.png', 'inherit', 9 );

$importer->import( removal_package( 9, 'delete' ) );

$deletes = did( 'delete' );

ok( 'it was deleted', 1 === count( $deletes ) );
ok( 'and forced', true === $deletes[0][2] );
ok( 'it did not go to the trash first', 0 === count( did( 'trash' ) ) );

echo "\n=== an intent-less package is read as the RECOVERABLE one ===\n";
//
// What a Staging site older than this build sends. Guessing wrong toward trash leaves a
// file to empty by hand; guessing wrong toward delete destroys one.
reset_all();
far_side( 57, 'photo.png', 'inherit', 9 );

$legacy = removal_package( 9, 'trash' );
unset( $legacy['removal'] );

$importer->import( $legacy );

ok( 'no intent means trash', 1 === count( did( 'trash' ) ) );
ok( 'and nothing is destroyed', 0 === count( did( 'delete' ) ) );

echo "\n=== pushing the same removal twice is not a failure ===\n";
//
// wp_trash_post() returns false for something already trashed. Treating that as an error
// would make an unchanged, correct site look like a broken deploy.
reset_all();
far_side( 58, 'photo.png', 'trash', 9 );

$again = $importer->import( removal_package( 9, 'trash' ) );

ok( 'it still reports success', is_array( $again ) && 58 === $again['object_id'] );
ok( 'and does not trash it a second time', 0 === count( did( 'trash' ) ) );

echo "\n=== the matcher can still see what it trashed ===\n";
//
// The bug the trash fix would otherwise have CREATED. `post_status => 'inherit'` is what a
// live attachment has; a trashed one is 'trash'. Without widening the lookup, an attachment
// this plugin put in Production's trash became invisible — so emptying Staging's trash
// afterwards reported "nothing matched, probably never deployed here" about a file it had
// trashed minutes earlier.
reset_all();
far_side( 59, 'photo.png', 'trash', 9 );

$found_default  = $importer->find_existing( removal_package( 9, 'delete' ) );
$found_trashaware = $importer->find_existing( removal_package( 9, 'delete' ), true );

ok( 'a trashed attachment is invisible by default', 0 === $found_default );
ok( 'but findable when asked for', 59 === $found_trashaware );

reset_all();
far_side( 60, 'photo.png', 'trash', 9 );

$purge = $importer->import( removal_package( 9, 'delete' ) );

ok( 'so a permanent delete after a trash finds it', is_array( $purge ) && 60 === $purge['object_id'] );
ok( 'and destroys it', 1 === count( did( 'delete' ) ) );

echo "\n=== a delete that matched nothing is still reported as a failure ===\n";
reset_all();

$miss = $importer->import( removal_package( 9, 'trash' ) );

ok( 'it is an error, not a silent success', $miss instanceof WP_Error );
ok( 'and says which of the two situations it is', $miss instanceof WP_Error && false !== strpos( $miss->get_error_message(), 'never deployed here' ) );

echo "\n=== an update RESTORES a file this plugin had trashed ===\n";
//
// Otherwise the update reports success while the image stays missing from every page using
// it — and re-uploading would land a duplicate at a fresh id, because the trashed original
// still occupies the one import_id wants.
reset_all();
far_side( 61, 'photo.png', 'trash', 9 );

$restored = $importer->import(
	array(
		'action'      => 'update',
		'origin_id'   => 9,
		'origin_site' => 'staging-uuid',
		'source_url'  => 'https://stg.test/uploads/photo.png',
		'filename'    => 'photo.png',
		'attachment'  => array( 'post_title' => 'photo' ),
	)
);

ok( 'the existing attachment is reused', is_array( $restored ) && 61 === $restored['object_id'] );
ok( 'nothing new is created', is_array( $restored ) && false === $restored['created'] );
ok( 'and it comes back out of the trash', 1 === count( did( 'untrash' ) ) );
ok( 'leaving it live', 'inherit' === $GLOBALS['statuses'][61] );

echo "\n=== a NEW file whose id is taken is refused, before anything is downloaded ===\n";
//
// The silent half of the id report. This used to let WordPress allocate a fresh id and say
// nothing, so an image uploaded as id 1 on Staging turned up as id 5 on Production with
// every ACF and inline reference to it pointing somewhere else.
reset_all();
$GLOBALS['downloads'] = 0;
$GLOBALS['types'][9]  = 'page';         // something else already occupies id 9 here
$GLOBALS['titles'][9] = 'About us';

$blocked = $importer->import(
	array(
		'action'      => 'update',
		'origin_id'   => 9,
		'origin_site' => 'staging-uuid',
		'source_url'  => 'https://stg.test/uploads/new.png',
		'filename'    => 'new.png',
		'attachment'  => array( 'post_title' => 'new' ),
	)
);

ok( 'the import is refused', $blocked instanceof WP_Error );
// Checked as a CODE, not merely as "an error happened" — an import can fail for a dozen
// reasons, and only this one means the operator has something they can do about it.
ok( 'with a code the browser can act on', $blocked instanceof WP_Error && 'ifs_deploy_media_id_taken' === $blocked->get_error_code() );
ok( 'the message names the id', $blocked instanceof WP_Error && false !== strpos( $blocked->get_error_message(), '9' ) );
ok( 'and what is in the way', $blocked instanceof WP_Error && false !== strpos( $blocked->get_error_message(), 'About us' ) );

$data = $blocked instanceof WP_Error ? (array) $blocked->get_error_data() : array();

ok( 'the id travels as a field, not only as prose', 9 === ( $data['staging_id'] ?? 0 ) );
ok( 'and so does the occupant', 'page' === ( $data['occupant']['type'] ?? '' ) );
// The refusal costs nothing: it happens before a single byte is fetched.
ok( 'and the file was never fetched', 0 === $GLOBALS['downloads'] );

echo "\n=== but only for media that has never been here ===\n";
//
// The narrowness is the point. Media already on Production is matched by source URL or
// origin stamp long before the id check, so a mismatch that ALREADY exists keeps working
// exactly as it did — which is what "do not break existing features" means here.
reset_all();
far_side( 500, 'photo.png', 'inherit', 9 );   // same file, different id: an old mismatch
$GLOBALS['types'][9]  = 'page';               // and id 9 is taken by something else
$GLOBALS['titles'][9] = 'About us';

$existing = $importer->import(
	array(
		'action'      => 'update',
		'origin_id'   => 9,
		'origin_site' => 'staging-uuid',
		'source_url'  => 'https://stg.test/uploads/photo.png',
		'filename'    => 'photo.png',
		'attachment'  => array( 'post_title' => 'photo' ),
	)
);

ok( 'an existing mismatched attachment still updates', is_array( $existing ) && 500 === $existing['object_id'] );
ok( 'and is not refused', ! $existing instanceof WP_Error );

echo "\n=== and a site that does not care can switch the rule off ===\n";
reset_all();
$GLOBALS['types'][9]  = 'page';
$GLOBALS['titles'][9] = 'About us';

add_filter( 'ifs_deploy_require_media_id_parity', static fn( $on ) => false );

$unfiltered = $importer->import(
	array(
		'action'      => 'update',
		'origin_id'   => 9,
		'origin_site' => 'staging-uuid',
		'source_url'  => 'https://stg.test/uploads/new.png',
		'filename'    => 'new.png',
		'attachment'  => array( 'post_title' => 'new' ),
	)
);

ok( 'the filter lifts the refusal', ! ( $unfiltered instanceof WP_Error && 'ifs_deploy_media_id_taken' === $unfiltered->get_error_code() ) );

echo "\n=== a free id is not a conflict ===\n";
reset_all();

$free = $importer->import(
	array(
		'action'      => 'update',
		'origin_id'   => 4242,
		'origin_site' => 'staging-uuid',
		'source_url'  => 'https://stg.test/uploads/new.png',
		'filename'    => 'new.png',
		'attachment'  => array( 'post_title' => 'new' ),
	)
);

ok( 'nothing is refused over an id nobody holds', ! ( $free instanceof WP_Error && 'ifs_deploy_media_id_taken' === $free->get_error_code() ) );

echo "\n=== which numbers count as a reference to attachment 123 ===\n";
//
// The boundary rule is the whole safety of the renumber. `1234` containing `123` must NOT
// read as a reference, or nothing would ever be movable; `[122,123,124]` must, or a gallery
// would be silently broken by a move.
$refs = array(
	'the bare number'            => array( '123', true ),
	'quoted'                     => array( '"123"', true ),
	'serialised as an integer'   => array( 'i:123;', true ),
	'inside a comma list'        => array( '122,123,124', true ),
	'at the end of a list'       => array( '121,123', true ),
	'a JSON field'               => array( '{"id":123}', true ),
	'a longer number containing it' => array( '1234', false ),
	'a longer number around it'  => array( '41235', false ),
	'a different number'         => array( '124', false ),
	'nothing at all'             => array( '', false ),
);

foreach ( $refs as $name => $case ) {
	ok( $name, $case[1] === MediaReferences::has_meta_reference( $case[0], 123 ) );
}

echo "\n=== an unreferenced file is movable; a used one is not ===\n";
reset_all();

ok( 'an id of zero is never a reference', false === MediaReferences::has_meta_reference( '0', 0 ) );
ok( 'and nothing refers to a file nothing uses', array() === MediaReferences::find( 77 ) );

// A featured image is the cheapest real reference there is, and the one most likely to be
// forgotten: nothing in the post CONTENT mentions the attachment at all.
$GLOBALS['db_col'] = array( 900 );
$GLOBALS['titles'][900] = 'Home page';
$GLOBALS['types'][900]  = 'page';

$used = MediaReferences::find( 77, 1 );

ok( 'a featured image counts', 1 === count( $used ) );
ok( 'and it is named, not just counted', 'Home page' === ( $used[0]['label'] ?? '' ) );
ok( 'has_any agrees', true === MediaReferences::has_any( 77 ) );

$GLOBALS['db_col'] = array();

// Being the site logo is not a reference anything links to, but it is very much "in use".
reset_all();
$GLOBALS['options']['custom_logo'] = 78;

$logo = MediaReferences::find( 78, 1 );

ok( 'the site logo counts too', 1 === count( $logo ) && 'option' === $logo[0]['kind'] );

echo "\n=== a file this site renamed on arrival is still the same file ===\n";
//
// ID PARITY IS THE LAST STRATEGY, and it is the only one left when Staging's URL has
// changed — a moved or renamed staging domain, or a restored backup with a different host.
// The recorded source URL no longer matches, and an attachment that arrived before origin
// stamping carries no stamp either.
//
// It compared the name ON DISK HERE. `wp_upload_bits()` never overwrites, so a file that
// arrived as `photo.png` where an unrelated `photo.png` already sat is stored as
// `photo-1.png` — and from then on Staging's `photo.png` never matched it again. The removal
// found nothing, and because the same matcher feeds `snapshot_for()`, no restore point was
// recorded either.
reset_all();

$GLOBALS['files'][9]    = '2026/08/photo-1.png';   // renamed by this site on arrival
$GLOBALS['titles'][9]   = 'photo';
$GLOBALS['statuses'][9] = 'inherit';
$GLOBALS['types'][9]    = 'attachment';

// Stamped from a Staging URL that no longer exists, so the source-URL strategy cannot match
// and there is no origin stamp to fall back on.
$GLOBALS['meta'][9][ MediaImporter::SOURCE_URL_META ] = array( 'https://old-staging.test/uploads/photo.png' );

$moved = removal_package( 9, 'trash' );   // source_url is https://stg.test/uploads/photo.png

ok( 'the source-URL strategy genuinely misses', 0 === $importer->find_existing( array_merge( $moved, array( 'origin_id' => 0 ) ), true ) );
ok( 'but id parity matches on the ORIGINAL name', 9 === $importer->find_existing( $moved, true ) );

$renamed = $importer->import( $moved );

ok( 'so the removal reaches it', is_array( $renamed ) && 9 === $renamed['object_id'] );
ok( 'and trashes it', 'trash' === $GLOBALS['statuses'][9] );

// The fallback stays strict. A `photo-1.png` genuinely uploaded here by hand has no stamp,
// so it falls back to its own name and must NOT match Staging's `photo.png` — they really
// are different images, and a false match on a DELETE destroys the wrong file.
reset_all();
$GLOBALS['files'][9]    = '2026/08/photo-1.png';
$GLOBALS['titles'][9]   = 'photo';
$GLOBALS['statuses'][9] = 'inherit';
$GLOBALS['types'][9]    = 'attachment';

ok( 'an unstamped local file with a different name does not match', 0 === $importer->find_existing( removal_package( 9, 'trash' ), true ) );

echo "\n=== a match that cannot be read is not a success ===\n";
//
// The trash branch returned success whenever it had an id, without checking it could read
// the post — the same silent lie the no-match branch exists to prevent.
reset_all();
$GLOBALS['meta'][404][ MediaImporter::ORIGIN_ID_META ]   = array( 9 );
$GLOBALS['meta'][404][ MediaImporter::ORIGIN_SITE_META ] = array( 'staging-uuid' );
$GLOBALS['statuses'][404] = 'inherit';
// Deliberately absent from $files/$types, so get_post() returns null for it.

$unreadable = $importer->import( removal_package( 9, 'trash' ) );

ok( 'it is reported as an error', $unreadable instanceof WP_Error );
ok( 'naming what happened', $unreadable instanceof WP_Error && 'ifs_deploy_delete_unreadable' === $unreadable->get_error_code() );

echo "\n=== the snapshot and the import must look for the same object ===\n";
//
// They did not. The import searched the trash as well and the snapshot did not, so anything
// in this site's trash was changed with NO restore point recorded — `revision_id` came back
// 0 and the History screen offered no Rollback button, because as far as it could tell
// nothing had been overwritten.
//
// Read from the source rather than executed: reaching that line needs the whole import
// pipeline, and what matters is only that the two calls agree.
$import_manager = (string) php_strip_whitespace( __DIR__ . '/../src/Import/ImportManager.php' );
$snapshot_src   = (string) php_strip_whitespace( __DIR__ . '/../src/Rollback/SnapshotStore.php' );

ok(
	'the media snapshot searches the trash, exactly as the import does',
	(bool) preg_match( '/find_existing\(\s*\$package,\s*true\s*\)/', $import_manager )
);
ok(
	'a rollback refuses when the object is gone instead of reporting success',
	(bool) preg_match( '/get_post\(\s*\$post_id\s*\)\s*instanceof/', $snapshot_src )
);
ok(
	'and says why in the log',
	false !== strpos( $snapshot_src, 'Cannot roll back: the object no longer exists on this site' )
);

echo "\n=== what a pending change SAYS happened ===\n";
//
// Two vocabularies on purpose. `action` is what the deploy will do (`update`/`delete`) and
// keeps driving everything; the label is what the operator did, and is only displayed.
// Printing the action was why the column read "update" for trashing, restoring and editing
// alike.
$row = static fn( string $action, string $status = 'pending', string $deployed = '' ): object =>
	(object) array( 'action' => $action, 'status' => $status, 'deployed_hash' => $deployed );

ok( 'a first upload is an addition', 'added' === MediaLifecycle::label_for_change( 'inherit', null, false ) );
ok( 'an edit to deployed media is an update', 'updated' === MediaLifecycle::label_for_change( 'inherit', $row( 'update' ), true ) );
ok( 'a first publish is a publish', 'published' === MediaLifecycle::label_for_change( 'publish', null, false ) );
ok( 'publishing something already live is an update', 'updated' === MediaLifecycle::label_for_change( 'publish', $row( 'update' ), true ) );
ok( 'a draft says draft', 'draft' === MediaLifecycle::label_for_change( 'draft', null, false ) );
ok( 'a scheduled post says scheduled', 'scheduled' === MediaLifecycle::label_for_change( 'future', null, false ) );
ok( 'a private post says private', 'private' === MediaLifecycle::label_for_change( 'private', null, false ) );

// The one that cannot be read off the object: a restored attachment and an edited one are
// both `inherit`, so only the row being replaced can tell them apart.
ok( 'an object whose pending change was a removal is RESTORED', 'restored' === MediaLifecycle::label_for_change( 'inherit', $row( 'delete' ), true ) );
ok( 'and that beats the status it happens to have', 'restored' === MediaLifecycle::label_for_change( 'publish', $row( 'delete' ), true ) );

echo "\n=== every label reads as a word, including rows written before labels existed ===\n";
foreach ( array( 'added', 'updated', 'restored', 'published', 'draft', 'scheduled', 'private', 'trashed', 'deleted' ) as $label ) {
	ok( "{$label} is described", '' !== MediaLifecycle::describe( $label ) && $label !== MediaLifecycle::describe( $label ) );
}

ok( 'an old row falls back to its action', 'Updated' === MediaLifecycle::describe( 'update' ) );
ok( 'and an old removal too', 'Removed' === MediaLifecycle::describe( 'delete' ) );
ok( 'an unrecognised value is shown rather than dropped', 'something-new' === MediaLifecycle::describe( 'something-new' ) );

echo "\n=== when a removal cancels a change nobody pushed ===\n";
//
// The proof is `deployed_hash`, not the status. A row can be PENDING and still have been
// deployed before — that is what editing already-live content looks like — and removing
// that content is a real deletion the far side has to be told about.
ok( 'no row at all means the object predates tracking, so queue it', false === MediaLifecycle::cancels_out( null ) );
ok( 'a pending row that never deployed cancels out', true === MediaLifecycle::cancels_out( $row( 'update' ) ) );
ok( 'a pending row that HAS deployed does not', false === MediaLifecycle::cancels_out( $row( 'update', 'pending', 'abc' ) ) );
ok( 'a settled row does not', false === MediaLifecycle::cancels_out( $row( 'update', 'deployed', 'abc' ) ) );
ok( 'nor an ignored one', false === MediaLifecycle::cancels_out( $row( 'update', 'ignored' ) ) );

echo "\n=== a report ===\n";

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
