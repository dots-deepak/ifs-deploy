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

$GLOBALS['files']    = array();   // attachment id => relative file path
$GLOBALS['titles']   = array();
$GLOBALS['statuses'] = array();   // attachment id => post_status
$GLOBALS['options']  = array();

// `inherit` is the normal status for an attachment; the live site that prompted this had
// duplicates in some OTHER status, which is why the canonical choice has to cope with it.
function get_post_status( $id ) { return $GLOBALS['statuses'][ (int) $id ] ?? 'inherit'; }

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

/**
 * Resolves the `_wp_attached_file` lookups.
 *
 * Returns EVERY match, ascending — not just the first. attachments_sharing_file() needs
 * the whole set to pick a canonical record, and a stub that stopped at the first hit
 * would quietly make that choice untestable.
 */
class WP_Query {
	public $posts = array();
	public function __construct( $args ) {
		$clause = $args['meta_query'][0] ?? array();
		if ( '_wp_attached_file' !== ( $clause['key'] ?? '' ) ) { return; }
		foreach ( $GLOBALS['files'] as $id => $file ) {
			if ( $file === $clause['value'] ) { $this->posts[] = $id; }
		}
		sort( $this->posts );
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
	$GLOBALS['files']    = array();
	$GLOBALS['titles']   = array();
	$GLOBALS['statuses'] = array();
	$GLOBALS['wpdb']     = new FakeWpdb();
}
function attachment( int $id, string $file, string $title = 'test', string $status = 'inherit' ): void {
	$GLOBALS['files'][ $id ]    = $file;
	$GLOBALS['titles'][ $id ]   = $title;
	$GLOBALS['statuses'][ $id ] = $status;
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

echo "\n=== the LIVE record wins, whatever the duplicates' status ===\n";
//
// On the reported site the duplicates were NOT in `inherit` status — which is why an
// earlier version of this lookup could not see them at all and reported "sharing: 1" for
// a file eight records pointed at. The original is the one to deploy, so a live record is
// preferred over a lower-numbered one that is not.
reset_all();
attachment( 63810, '2026/08/sstest-1.png', 'sstest', 'draft' );   // lower id, NOT live
attachment( 63817, '2026/08/sstest-1.png', 'sstest', 'inherit' ); // the real attachment
attachment( 63823, '2026/08/sstest-1.png', 'sstest', 'draft' );

$observer->on_change( 63823 );
ok( 'the live record is tracked, not the lowest id', 63817 === (int) reset( $GLOBALS['wpdb']->rows )->object_id );

// With nothing live at all the lowest id still wins, so the answer is never undefined.
reset_all();
attachment( 63810, '2026/08/x.png', 'x', 'draft' );
attachment( 63823, '2026/08/x.png', 'x', 'draft' );
$observer->on_change( 63823 );
ok( 'no live record -> lowest id, deterministically', 63810 === (int) reset( $GLOBALS['wpdb']->rows )->object_id );

echo "\n=== WPML: a translated copy tracks its ORIGINAL ===\n";
//
// The cause the log finally named on the live site:
//
//     plugins/sitepress-multilingual-cms/classes/media/duplication/
//         class-wpml-media-attachments-duplication.php:287 wp_update_post()
//
// WPML gives every language its own attachment record for the same file. Read through
// WPML's own `wpml_original_element_id` filter rather than inferred, so it identifies the
// real original instead of assuming the lowest id is it — and still works in the
// configurations that duplicate the FILE too, where no shared file exists to spot.
reset_all();
attachment( 63817, '2026/08/sstest-1.png', 'Sample', 'inherit' );
foreach ( range( 63818, 63824 ) as $id ) {
	// WPML creates these as `publish`, not the `inherit` WordPress uses — which is why a
	// status-restricted lookup could not see them at all.
	attachment( $id, '2026/08/sstest-1.png', 'Sample', 'publish' );
}

add_filter( 'wpml_original_element_id', function ( $original, $element_id, $type ) {
	if ( 'post_attachment' !== $type ) { return $original; }
	return in_array( (int) $element_id, range( 63818, 63824 ), true ) ? 63817 : $original;
}, 10, 3 );

foreach ( range( 63818, 63824 ) as $id ) {
	$observer->on_change( $id );
}

ok( 'seven language copies -> ONE row', 1 === rows() );
ok( 'and it is the original', 63817 === (int) reset( $GLOBALS['wpdb']->rows )->object_id );

// The original itself must still be tracked normally — WPML reports no original for it.
$observer->on_change( 63817 );
ok( 'the original is still tracked itself', 1 === rows() );

// WPML's answer wins over the shared-file guess. Here the lowest id is NOT the original,
// so the two rules disagree — and the plugin that owns the relationship is right.
reset_all();
attachment( 63700, '2026/08/other.png', 'Other', 'publish' );  // a translation, LOWER id
attachment( 63817, '2026/08/other.png', 'Other', 'inherit' );  // the original
remove_all_filters( 'wpml_original_element_id' );
add_filter( 'wpml_original_element_id', function ( $original, $element_id, $type ) {
	return ( 'post_attachment' === $type && 63700 === (int) $element_id ) ? 63817 : $original;
}, 10, 3 );

$observer->on_change( 63700 );
ok( 'the stated original beats the lowest id', 63817 === (int) reset( $GLOBALS['wpdb']->rows )->object_id );

// A nonsense answer must never redirect tracking at something that does not exist.
reset_all();
attachment( 63817, '2026/08/solo.png', 'Solo', 'inherit' );
remove_all_filters( 'wpml_original_element_id' );
add_filter( 'wpml_original_element_id', function () { return 999999; }, 10, 3 );
$observer->on_change( 63817 );
ok( 'an original that does not exist is ignored', 63817 === (int) reset( $GLOBALS['wpdb']->rows )->object_id );

remove_all_filters( 'wpml_original_element_id' );

// Without WPML the filter is unregistered, so the null default returns and nothing here
// changes at all.
reset_all();
attachment( 63817, '2026/08/plain.png', 'Plain', 'inherit' );
$observer->on_change( 63817 );
ok( 'no translation plugin -> ordinary tracking', 1 === rows() && 63817 === (int) reset( $GLOBALS['wpdb']->rows )->object_id );

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

// The first attempt reported the first frame that was not this plugin's, which answered
// "wp_insert_post" every time — WordPress's own function, and the very one that fires
// edit_attachment. True, and useless. The caller is identified by FILE now.
ok( 'the caller is found by file, not function name', false !== strpos( $src, "'/wp-content/'" ) );
ok( 'and IfsDeploy frames are NOT excluded', 0 === preg_match( "/strpos\(\s*\\\$class,\s*'IfsDeploy/", $src ) );
// Excluding them would mean this can never implicate the plugin being investigated.
ok( 'a core-only stack still reports something', false !== strpos( $src, 'wp-core' ) );

/*
 * A frame's file/line say where the function IN that frame was called FROM. So frame 0 is
 * caller() reported at its own call site inside AttachmentObserver.php — which lives under
 * wp-content, so a scan that starts at frame 0 returns THIS FILE as the culprit. It did,
 * on a live site, every time. Only frames above the hook dispatch can have caused anything.
 */
ok( 'only frames above the hook dispatch are considered', false !== strpos( $src, '$past_hook' ) );
ok( 'the dispatch is recognised by do_action', (bool) preg_match( "/'do_action',\s*'do_action_ref_array'/", $src ) );
ok( 'and frames below it are skipped outright', (bool) preg_match( '/if\s*\(\s*!\s*\$past_hook\s*\)/', $src ) );

echo "\n=== the routine entries are BEHIND a switch ===\n";
//
// They are per-event and high-volume: a site running WPML writes one for every language
// copy of every media item, on every save. The log holds 200 entries in total, so left on
// they do not merely clutter it — they EVICT the deployment failure someone is hunting
// for. Off by default; errors and warnings are never gated.
$logsrc = (string) php_strip_whitespace( __DIR__ . '/../src/Support/DebugLog.php' );

ok( 'a gated debug() level exists', (bool) preg_match( '/function debug\(/', $logsrc ) );
ok( 'it returns early when off', (bool) preg_match( '/function debug\(.*?if\s*\(\s*!\s*self::verbose\(\)\s*\)\s*\{\s*return;/s', $logsrc ) );
ok( 'and defaults to off', (bool) preg_match( "/get_option\(\s*self::VERBOSE_OPTION,\s*false\s*\)/", $logsrc ) );
ok( 'errors are NOT gated', (bool) preg_match( '/function error\(.*?self::record\(\s*self::LEVEL_ERROR/s', $logsrc ) );
ok( 'warnings are NOT gated', (bool) preg_match( '/function warning\(.*?self::record\(\s*self::LEVEL_WARNING/s', $logsrc ) );

// The three high-volume media entries must actually use it, or the switch does nothing.
ok( 'media tracking uses debug()', false !== strpos( $src, 'DebugLog::debug(' ) );
ok( 'and none of them uses info()', false === strpos( $src, 'DebugLog::info(' ) );

// The option has to be removed on uninstall, and stay in step with the rename migration —
// a key in one list and not the other is how data is left behind for ever.
$uninstall = (string) file_get_contents( __DIR__ . '/../uninstall.php' );
$rename    = (string) file_get_contents( __DIR__ . '/../src/Support/LegacyRename.php' );
ok( 'uninstall removes the option', false !== strpos( $uninstall, 'ifs_deploy_verbose_log' ) );
ok( 'and the rename migration knows it', false !== strpos( $rename, "'verbose_log'" ) );

echo "\n=== the sharing lookup must see records WordPress would not ===\n";
// `post_status => inherit` hid the very duplicates this exists to find.
ok( 'any status is searched', (bool) preg_match( "/'post_status'\s*=>\s*'any'/", $src ) );
ok( 'and the record always counts itself', false !== strpos( $src, '$ids[] = $attachment_id' ) );
ok( 'the status is logged, since it is the clue', false !== strpos( $src, "'status'" ) );
// The duplicate ids are logged so they can be found and removed in the Media Library,
// which is the actual repair — the merge only stops them being listed.
ok( 'the sharing records are named', false !== strpos( $src, "'sharing'" ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
