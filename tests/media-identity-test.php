<?php
declare(strict_types=1);

function wp_parse_url( $u, $c = -1 ) { return -1 === $c ? parse_url( $u ) : parse_url( $u, $c ); }
function get_post_meta( $id, $key = '', $single = false ) { return $GLOBALS['dp_meta'][ $id ][ $key ] ?? ''; }
function get_attached_file( $id ) { return $GLOBALS['dp_file'][ $id ] ?? ''; }

require __DIR__ . '/../src/Support/MediaIdentity.php';
use IfsDeploy\Support\MediaIdentity;

$pass = 0; $fail = 0;
function ok( string $n, bool $c ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS  $n\n"; } else { $fail++; echo "FAIL  $n\n"; } }

$KEY = MediaIdentity::SOURCE_URL_META;

// STAGING: uploaded directly, no source stamp.
$GLOBALS['dp_file'][10] = '/sites/stg/uploads/2026/08/Blog-8-Hero-Image.png';
$GLOBALS['dp_meta'][10] = array();

// PRODUCTION: same image, renamed on arrival, source stamp recorded.
$GLOBALS['dp_file'][20] = '/sites/prod/uploads/2026/08/Blog-8-Hero-Image-1.png';
$GLOBALS['dp_meta'][20] = array( $KEY => 'https://stg.test/wp-content/uploads/2026/08/Blog-8-Hero-Image.png' );

echo "=== the reported case ===\n";
$staging = MediaIdentity::stable_filename( 10 );
$prod    = MediaIdentity::stable_filename( 20 );
printf( "  staging: %s\n  prod:    %s\n", $staging, $prod );
ok( 'renamed file no longer reads as a difference', $staging === $prod );
ok( 'resolves to the ORIGINAL name', 'Blog-8-Hero-Image.png' === $prod );

echo "\n=== a genuinely different image must still differ ===\n";
$GLOBALS['dp_file'][30] = '/sites/prod/uploads/2026/08/Some-Other-Image.png';
$GLOBALS['dp_meta'][30] = array( $KEY => 'https://stg.test/wp-content/uploads/2026/08/Some-Other-Image.png' );
ok( 'different source -> different', MediaIdentity::stable_filename( 30 ) !== $staging );

echo "\n=== unrelated hand-uploaded file on Production ===\n";
// No source stamp, and its local name happens to carry a -1 suffix.
$GLOBALS['dp_file'][40] = '/sites/prod/uploads/2026/08/Blog-8-Hero-Image-1.png';
$GLOBALS['dp_meta'][40] = array();
ok( 'no stamp -> falls back to local name, still different', MediaIdentity::stable_filename( 40 ) !== $staging );
ok( 'and it is the local name', 'Blog-8-Hero-Image-1.png' === MediaIdentity::stable_filename( 40 ) );

echo "\n=== edge cases ===\n";
ok( 'no attachment -> empty', '' === MediaIdentity::stable_filename( 0 ) );
ok( 'negative id -> empty',   '' === MediaIdentity::stable_filename( -3 ) );

$GLOBALS['dp_file'][50] = '/sites/prod/uploads/x/local.png';
$GLOBALS['dp_meta'][50] = array( $KEY => 'not-a-url' );
ok( 'unparseable source falls back to local', 'local.png' === MediaIdentity::stable_filename( 50 ) );

$GLOBALS['dp_file'][60] = '/sites/prod/uploads/x/local.png';
$GLOBALS['dp_meta'][60] = array( $KEY => 'https://stg.test/uploads/pic.png?ver=2' );
ok( 'query string ignored', 'pic.png' === MediaIdentity::stable_filename( 60 ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
