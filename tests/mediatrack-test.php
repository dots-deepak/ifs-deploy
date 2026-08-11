<?php
declare(strict_types=1);

/*
 * WHICH attachments get tracked at all.
 *
 * Reported as "editing one image produces eight pending changes". The ids turned out to
 * be eight CONSECUTIVE attachments created in the same second, so the queue was tracking
 * eight real objects and something else on the site was creating the other seven. No
 * amount of de-duplication inside the queue can fix that, so this covers the two things
 * that can be done about it:
 *
 *   1. A generated SIZE is never tracked. IFS Deploy transfers the original and lets
 *      Production regenerate its own sizes (§12 — the exporter deliberately withholds
 *      `_wp_attachment_metadata` for exactly this reason), so a `-300x200` derivative is
 *      output, not content. Plugins that register those derivatives as real attachments
 *      would otherwise give each one its own pending change.
 *   2. `ifs_deploy_track_attachment` excludes anything else a given site turns out to
 *      produce.
 */

function __( $s, $d = '' ) { return $s; }
function esc_url_raw( $u ) { return $u; }
function current_time( $t = 'mysql', $g = 0 ) { return '2026-08-11 01:52:01'; }
function get_current_user_id() { return 7; }
function current_filter() { return 'edit_attachment'; }
function wp_generate_uuid4() { return 'uuid'; }
function maybe_unserialize( $v ) { return $v; }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, (array) $args ); }
function wp_generate_password( $len = 12, $special = true, $extra = false ) { return str_repeat( 'k', $len ); }
function sanitize_key( $k ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $k ) ); }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function get_option( $n, $d = false ) { return $GLOBALS['options'][ $n ] ?? $d; }
function update_option( $n, $v, $a = null ) { $GLOBALS['options'][ $n ] = $v; return true; }
function add_option( $n, $v, $x = '', $a = 'yes' ) { $GLOBALS['options'][ $n ] = $v; return true; }
function wp_get_attachment_url( $id ) { return 'https://stg.test/wp-content/uploads/' . ( $GLOBALS['files'][ (int) $id ] ?? '' ); }
function get_attached_file( $id ) { return '/uploads/' . ( $GLOBALS['files'][ (int) $id ] ?? '' ); }

$GLOBALS['filters'] = array();
function add_filter( $tag, $cb, $p = 10, $a = 1 ) { $GLOBALS['filters'][ $tag ][] = $cb; return true; }
function remove_all_filters( $tag ) { unset( $GLOBALS['filters'][ $tag ] ); }
function apply_filters( $tag, $value, ...$args ) {
	foreach ( $GLOBALS['filters'][ $tag ] ?? array() as $cb ) { $value = $cb( $value, ...$args ); }
	return $value;
}

class WP_Post {
	public $ID;
	public $post_type   = 'attachment';
	public $post_title  = '';
	public $post_name   = '';
	public $post_excerpt = '';
	public $post_content = '';
	public $post_mime_type = 'image/png';
	public $post_parent = 0;
	public $menu_order  = 0;
	public $post_date   = '2026-08-11 01:52:01';
	public function __construct( $id, $title ) { $this->ID = $id; $this->post_title = $title; $this->post_name = $title; }
}

$GLOBALS['files']   = array();   // attachment id => relative file path
$GLOBALS['titles']  = array();
$GLOBALS['options'] = array();

function get_post( $id = null ) {
	$id = (int) $id;
	return isset( $GLOBALS['files'][ $id ] ) ? new WP_Post( $id, $GLOBALS['titles'][ $id ] ?? '' ) : null;
}
function get_post_meta( $id, $key = '', $single = false ) {
	$id = (int) $id;
	if ( '_wp_attached_file' === $key ) {
		$v = $GLOBALS['files'][ $id ] ?? '';
		return $single ? $v : ( '' === $v ? array() : array( $v ) );
	}
	if ( '' === $key ) { return array(); }
	return $single ? '' : array();
}

/** Resolves the `_wp_attached_file` lookup is_generated_size() makes. */
class WP_Query {
	public $posts = array();
	public function __construct( $args ) {
		$clause = $args['meta_query'][0] ?? array();
		if ( '_wp_attached_file' !== ( $clause['key'] ?? '' ) ) { return; }
		foreach ( $GLOBALS['files'] as $id => $file ) {
			if ( $file === $clause['value'] ) { $this->posts = array( $id ); return; }
		}
	}
}

/** In-memory queue table, the same shape queue-revert-test uses. */
class FakeWpdb {
	public $rows = array();
	public $prefix = 'wp_';
	private $next_id = 1;
	public function insert( $t, $data, $f = null ) {
		$data = array_merge( array( 'deployed_hash' => '', 'baseline_hash' => '' ), $data );
		$data['id'] = $this->next_id++;
		$this->rows[ $data['id'] ] = (object) $data;
		return 1;
	}
	public function update( $t, $data, $where, $f = null, $wf = null ) {
		$id = (int) $where['id'];
		if ( ! isset( $this->rows[ $id ] ) ) { return 0; }
		foreach ( $data as $k => $v ) { $this->rows[ $id ]->$k = $v; }
		return 1;
	}
	public function prepare( $sql, ...$args ) { return array( 'sql' => $sql, 'args' => $args ); }
	public function get_row( $q ) {
		list( $type, $object_id ) = $q['args'];
		foreach ( array_reverse( $this->rows, true ) as $row ) {
			if ( $row->object_type === $type && (int) $row->object_id === (int) $object_id ) { return $row; }
		}
		return null;
	}
	public function delete( $t, $where, $f = null ) {
		$removed = 0;
		foreach ( $this->rows as $id => $row ) {
			$match = true;
			foreach ( $where as $k => $v ) {
				if ( (string) $row->$k !== (string) $v ) { $match = false; break; }
			}
			if ( $match ) { unset( $this->rows[ $id ] ); $removed++; }
		}
		return $removed;
	}
	public function query( $q ) { return 1; }
	public function get_results( $q ) { return array(); }
	public function get_col( $q ) { return array(); }
	public function get_var( $q ) { return null; }
	public function get_charset_collate() { return ''; }
}

$GLOBALS['wpdb'] = new FakeWpdb();

require __DIR__ . '/../src/Support/Logger.php';
require __DIR__ . '/../src/Support/Config.php';
require __DIR__ . '/../src/Support/DebugLog.php';
require __DIR__ . '/../src/Support/Schema.php';
require __DIR__ . '/../src/Auth/Credentials.php';
require __DIR__ . '/../src/Export/MediaExporter.php';
require __DIR__ . '/../src/Queue/Hasher.php';
require __DIR__ . '/../src/Support/Json.php';
require __DIR__ . '/../src/Queue/QueueRepository.php';
require __DIR__ . '/../src/Detection/AttachmentObserver.php';

use IfsDeploy\Detection\AttachmentObserver;

$pass = 0; $fail = 0;
function ok( string $n, bool $c ): void {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "PASS  $n\n"; } else { $fail++; echo "FAIL  $n\n"; }
}

function reset_all(): void {
	$GLOBALS['files']  = array();
	$GLOBALS['titles'] = array();
	$GLOBALS['wpdb']   = new FakeWpdb();
}
function attachment( int $id, string $file, string $title = 'test' ): void {
	$GLOBALS['files'][ $id ]  = $file;
	$GLOBALS['titles'][ $id ] = $title;
}
function rows(): int { return count( $GLOBALS['wpdb']->rows ); }

$observer = new AttachmentObserver();

echo "=== an ordinary upload is tracked ===\n";
reset_all();
attachment( 63817, '2026/08/sstest.png', 'sstest' );
$observer->on_change( 63817 );
ok( 'one attachment, one row', 1 === rows() );

echo "\n=== editing it again keeps ONE row ===\n";
$GLOBALS['titles'][63817] = 'sstest renamed';
$observer->on_change( 63817 );
ok( 'still one row after a second edit', 1 === rows() );
$row = reset( $GLOBALS['wpdb']->rows );
ok( 'and it holds the latest title', 'sstest renamed' === $row->object_title );

echo "\n=== SEPARATE uploads of the same file are separate objects ===\n";
//
// The reported list showed seven rows all titled "sstest". WordPress names an attachment
// after its ORIGINAL file, so each re-upload of sstest.png is titled "sstest" while its
// file becomes sstest-1.png, sstest-2.png… They are genuinely different attachments and
// each one is genuinely a pending change; the fix for the confusion is showing the id and
// the file name, not merging them.
reset_all();
attachment( 63818, '2026/08/sstest.png', 'sstest' );
attachment( 63819, '2026/08/sstest-1.png', 'sstest' );
attachment( 63820, '2026/08/sstest-2.png', 'sstest' );
foreach ( array( 63818, 63819, 63820 ) as $id ) { $observer->on_change( $id ); }
ok( 'three uploads -> three rows, correctly', 3 === rows() );

echo "\n=== THE REPORTED CASE: seven attachment records, ONE file ===\n";
//
// Seven consecutive ids, every one with `_wp_attached_file` = sstest-1.png. Merging them
// is not tidying: MediaImporter::find_existing() matches on the recorded source URL, and
// attachments sharing a file share a source URL — so pushing all seven produces exactly
// ONE attachment on Production, each overwriting the last. Seven rows promise an outcome
// that cannot happen.
reset_all();
foreach ( range( 63818, 63824 ) as $id ) {
	attachment( $id, '2026/08/sstest-1.png', 'sstest' );
}

foreach ( range( 63818, 63824 ) as $id ) {
	$observer->on_change( $id );
}

ok( 'seven records for one file -> ONE row', 1 === rows() );

$row = reset( $GLOBALS['wpdb']->rows );
ok( 'and it tracks the ORIGINAL (lowest) id', 63818 === (int) $row->object_id );

// Deterministic regardless of order: whichever record is saved, every one of them
// redirects to the same canonical row, so the outcome cannot depend on save order.
reset_all();
foreach ( range( 63818, 63824 ) as $id ) {
	attachment( $id, '2026/08/sstest-1.png', 'sstest' );
}
foreach ( array( 63824, 63820, 63818, 63823, 63819, 63822, 63821 ) as $id ) {
	$observer->on_change( $id );
}
ok( 'saved in any order -> still one row', 1 === rows() );
ok( 'and still the lowest id', 63818 === (int) reset( $GLOBALS['wpdb']->rows )->object_id );

echo "\n=== a GENERATED SIZE is never tracked ===\n";
reset_all();
attachment( 63821, '2026/08/hero.png', 'hero' );
attachment( 63822, '2026/08/hero-300x200.png', 'hero' );   // a derivative of the above
attachment( 63823, '2026/08/hero-1024x768.png', 'hero' );

$observer->on_change( 63821 );
ok( 'the original is tracked', 1 === rows() );

$observer->on_change( 63822 );
$observer->on_change( 63823 );
ok( 'its generated sizes are NOT', 1 === rows() );

echo "\n=== but a real upload that merely LOOKS like one still is ===\n";
//
// The precision half of the rule: `banner-1920x1080.jpg` is a perfectly ordinary file
// name. It is only a derivative when the un-suffixed file exists as an attachment in its
// own right — which is what makes it a derivative OF something.
reset_all();
attachment( 63824, '2026/08/banner-1920x1080.jpg', 'banner-1920x1080' );
$observer->on_change( 63824 );
ok( 'no un-suffixed sibling -> tracked', 1 === rows() );

reset_all();
attachment( 63825, '2026/08/banner.jpg', 'banner' );
attachment( 63826, '2026/08/banner-1920x1080.jpg', 'banner' );
$observer->on_change( 63826 );
ok( 'with a sibling present -> skipped', 0 === rows() );

echo "\n=== the escape hatch for whatever else a site produces ===\n";
reset_all();
attachment( 63827, '2026/08/generated.png', 'generated' );

add_filter( 'ifs_deploy_track_attachment', function ( $track, $id ) {
	return 63827 === $id ? false : $track;
}, 10, 2 );

$observer->on_change( 63827 );
ok( 'the filter can exclude an attachment', 0 === rows() );

remove_all_filters( 'ifs_deploy_track_attachment' );
$observer->on_change( 63827 );
ok( 'and without it the same attachment is tracked', 1 === rows() );

echo "\n=== every decision is logged, so a live site can be diagnosed ===\n";
//
// Naming the FILE is what settles it: several files means several uploads, a `-WxH` name
// means generated sizes, and the same path twice means duplicate rows for one file.
$src = (string) php_strip_whitespace( __DIR__ . '/../src/Detection/AttachmentObserver.php' );

ok( 'the file is recorded', false !== strpos( $src, "'file'" ) );
ok( 'and which hook fired', false !== strpos( $src, 'current_filter()' ) );
ok( 'and what triggered it', false !== strpos( $src, '$this->caller()' ) );
ok( 'ignored attachments are logged too', (bool) preg_match( '/Media change ignored/', $src ) );
// Argument values must never reach the log — they can hold post content and credentials.
ok( 'the backtrace drops arguments', false !== strpos( $src, 'DEBUG_BACKTRACE_IGNORE_ARGS' ) );
ok( 'and is depth-limited', (bool) preg_match( '/debug_backtrace\(\s*DEBUG_BACKTRACE_IGNORE_ARGS,\s*\d+\s*\)/', $src ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
