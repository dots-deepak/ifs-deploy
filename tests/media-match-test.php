<?php
declare(strict_types=1);

/*
 * Verifies MediaImporter::find_existing() — specifically that ID parity alone no
 * longer counts as a match, which is what silently skipped file transfers between
 * cloned environments.
 */

function __( $s, $d = '' ) { return $s; }
function esc_url_raw( $u ) { return $u; }
function wp_parse_url( $u, $c = -1 ) { return -1 === $c ? parse_url( $u ) : parse_url( $u, $c ); }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/' ); }
function home_url() { return 'https://prod.test'; }

// --- fake WP object store -----------------------------------------------------
$GLOBALS['posts'] = array();   // id => ['type'=>, 'file'=>]
$GLOBALS['meta']  = array();   // id => [key => value]

class WP_Post { public $ID; public $post_type; public function __construct( $id, $t ) { $this->ID = $id; $this->post_type = $t; } }

function get_post( $id = null ) {
	$id = (int) $id;
	return isset( $GLOBALS['posts'][ $id ] ) ? new WP_Post( $id, $GLOBALS['posts'][ $id ]['type'] ) : null;
}
function get_attached_file( $id ) { return $GLOBALS['posts'][ (int) $id ]['file'] ?? ''; }
function get_post_meta( $id, $key = '', $single = false ) {
	$v = $GLOBALS['meta'][ (int) $id ][ $key ] ?? '';
	return $single ? $v : ( '' === $v ? array() : array( $v ) );
}

class WP_Query {
	public $posts = array();
	public function __construct( $args ) {
		$mq = $args['meta_query'][0] ?? array();
		$clauses = array();
		if ( isset( $mq['key'] ) ) { $clauses[] = $mq; }
		else { foreach ( $mq as $k => $c ) { if ( is_array( $c ) && isset( $c['key'] ) ) { $clauses[] = $c; } } }
		if ( empty( $clauses ) ) { return; }
		foreach ( $GLOBALS['meta'] as $id => $meta ) {
			$all = true;
			foreach ( $clauses as $c ) {
				if ( (string) ( $meta[ $c['key'] ] ?? '' ) !== (string) $c['value'] ) { $all = false; break; }
			}
			if ( $all ) { $this->posts = array( $id ); return; }
		}
	}
}

require __DIR__ . '/../src/Support/MediaIdentity.php';
require __DIR__ . '/../src/Support/Logger.php';
require __DIR__ . '/../src/Support/Config.php';
require __DIR__ . '/../src/Support/DebugLog.php';
require __DIR__ . '/../src/Import/MediaImporter.php';

use IfsDeploy\Import\MediaImporter;

const STG_SITE = 'stg-site-uuid';

function pkg( int $origin_id, string $filename, string $source_url, string $site = STG_SITE ): array {
	return array( 'origin_id' => $origin_id, 'origin_site' => $site, 'source_url' => $source_url, 'filename' => $filename );
}

function reset_store(): void { $GLOBALS['posts'] = array(); $GLOBALS['meta'] = array(); }
function add_attachment( int $id, string $file, array $meta = array() ): void {
	$GLOBALS['posts'][ $id ] = array( 'type' => 'attachment', 'file' => '/uploads/' . $file );
	$GLOBALS['meta'][ $id ]  = $meta;
}

$m = new MediaImporter();
$pass = 0; $fail = 0;
function ok( string $n, bool $c ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS  $n\n"; } else { $fail++; echo "FAIL  $n\n"; } }

echo "=== THE REPORTED BUG ===\n";
reset_store();
// Production already has an UNRELATED image at the id staging's new upload got.
add_attachment( 63400, 'completely-different.jpg' );
$found = $m->find_existing( pkg( 63400, 'bird-thumbnail.jpg', 'https://stg.test/uploads/2026/08/bird-thumbnail.jpg' ) );
ok( 'unrelated attachment at same id is NOT matched (file will upload)', 0 === $found );

echo "\n=== cloned site: genuinely the same file ===\n";
reset_store();
add_attachment( 63400, 'bird-thumbnail.jpg' );
$found = $m->find_existing( pkg( 63400, 'bird-thumbnail.jpg', 'https://stg.test/uploads/2026/08/bird-thumbnail.jpg' ) );
ok( 'same id + same filename IS matched (no needless re-upload)', 63400 === $found );

echo "\n=== provable matches still win ===\n";
reset_store();
add_attachment( 77, 'bird-thumbnail.jpg', array( '_ifs_deploy_source_url' => 'https://stg.test/uploads/2026/08/bird-thumbnail.jpg' ) );
ok( 'matched by recorded source URL', 77 === $m->find_existing( pkg( 63400, 'bird-thumbnail.jpg', 'https://stg.test/uploads/2026/08/bird-thumbnail.jpg' ) ) );

reset_store();
add_attachment( 88, 'renamed-on-prod.jpg', array( '_ifs_deploy_origin_id' => '63400', '_ifs_deploy_origin_site' => STG_SITE ) );
ok( 'matched by origin link even with a different filename', 88 === $m->find_existing( pkg( 63400, 'bird-thumbnail.jpg', 'https://stg.test/uploads/2026/08/bird-thumbnail.jpg' ) ) );

echo "\n=== ID parity refuses when evidence contradicts it ===\n";
reset_store();
// Same id, same filename, but stamped as coming from a DIFFERENT site.
add_attachment( 63400, 'bird-thumbnail.jpg', array( '_ifs_deploy_origin_site' => 'some-other-site-uuid', '_ifs_deploy_origin_id' => '63400' ) );
ok( 'stamped from another origin site is not matched', 0 === $m->find_existing( pkg( 63400, 'bird-thumbnail.jpg', 'https://stg.test/uploads/2026/08/x.jpg' ) ) );

reset_store();
add_attachment( 63400, 'bird-thumbnail.jpg', array( '_ifs_deploy_origin_id' => '999', '_ifs_deploy_origin_site' => STG_SITE ) );
ok( 'stamped from a different origin id is not matched', 0 === $m->find_existing( pkg( 63400, 'bird-thumbnail.jpg', 'https://stg.test/uploads/2026/08/x.jpg' ) ) );

echo "\n=== edge cases ===\n";
reset_store();
add_attachment( 63400, 'bird-thumbnail.jpg' );
ok( 'no filename in package -> no parity match', 0 === $m->find_existing( pkg( 63400, '', 'https://stg.test/x.jpg' ) ) );

reset_store();
$GLOBALS['posts'][ 63400 ] = array( 'type' => 'page', 'file' => '' ); // a page, not an attachment
ok( 'non-attachment at that id is not matched', 0 === $m->find_existing( pkg( 63400, 'bird-thumbnail.jpg', 'https://stg.test/x.jpg' ) ) );

reset_store();
ok( 'nothing at that id -> new upload', 0 === $m->find_existing( pkg( 63400, 'bird-thumbnail.jpg', 'https://stg.test/x.jpg' ) ) );

reset_store();
add_attachment( 63400, 'BIRD-Thumbnail.JPG' );
ok( 'filename comparison is case-insensitive', 63400 === $m->find_existing( pkg( 63400, 'bird-thumbnail.jpg', 'https://stg.test/x.jpg' ) ) );

echo "\n=== a large file is never read into memory ===\n";
//
// `wp_upload_bits()` takes the file's CONTENTS as a string, so this used to run
// file_get_contents() on the download and hand the whole thing over — PHP then held the
// entire file in memory and wrote it straight back out to disk. A 40 MB upload needed
// 40 MB of memory, often twice while the string was copied, on top of everything
// WordPress already has loaded. That is where a large media push died, and it died with a
// fatal rather than a message.
//
// The file is already on disk when we get there; moving it costs no memory at any size.
$media_src = (string) php_strip_whitespace( __DIR__ . '/../src/Import/MediaImporter.php' );

ok( 'the download is never read into a string', false === strpos( $media_src, 'file_get_contents' ) );
ok( 'and wp_upload_bits is gone with it', false === strpos( $media_src, 'wp_upload_bits' ) );
ok( 'the file is moved into place instead', (bool) preg_match( '/@rename\(\s*\$tmp,\s*\$target\s*\)/', $media_src ) );
// rename() is atomic but only within one filesystem, and the temp directory is not always
// on the same mount.
ok( 'with a copy fallback across filesystems', (bool) preg_match( '/@copy\(\s*\$tmp,\s*\$target\s*\)/', $media_src ) );

// Everything wp_upload_bits() did that the rest of the plugin depends on is kept.
ok( 'the non-overwriting filename rule is kept', false !== strpos( $media_src, 'wp_unique_filename(' ) );
ok( "the attachment's own date still picks the folder", (bool) preg_match( '/wp_upload_dir\(\s*\$time\s*\)/', $media_src ) );
ok( 'and unreadable uploads are reported, not fataled', false !== strpos( $media_src, 'uploads directory could not be written to' ) );

echo "\n=== a delete that matched nothing is not a success ===\n";
//
// It used to return quietly with object_id 0, which ImportManager records as `ok`. So
// deleting media on Staging and pushing reported "Deployment complete" while the file was
// still live on Production — the deploy claimed to have done something it never attempted.
ok( 'an unmatched media delete is an error', (bool) preg_match( "/if \( ! \\\$existing \) \{.*?WP_Error.*?ifs_deploy_delete_no_match/s", $media_src ) );
ok( 'and the message names the file', false !== strpos( $media_src, 'No media on this site matched' ) );

$post_src = (string) php_strip_whitespace( __DIR__ . '/../src/Import/PostImporter.php' );
ok( 'the same holds for a post', false !== strpos( $post_src, 'ifs_deploy_delete_no_match' ) );
// The two real causes are separated, because the fix for each is different.
ok( 'and it points at Sync IDs when the object does exist', false !== strpos( $post_src, 'Sync IDs' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
