<?php
declare(strict_types=1);

/*
 * What happens when JSON encoding FAILS.
 *
 * `wp_json_encode()` returns false — on invalid UTF-8 that survives core's sanity
 * pass, on a structure deeper than the 512-level limit, on INF/NAN, on recursion.
 * Every call site used to cast that straight to `(string)`, and an empty string is
 * not obviously wrong anywhere it lands:
 *
 *   - `md5( '' )` is a valid-looking hash, and the SAME one every time, so the queue
 *     reads unrelated objects as identical and a real edit as "nothing changed";
 *   - an empty snapshot decodes to null, so the restore point exists but restores
 *     nothing;
 *   - an empty request body is a well-formed POST that Production accepts.
 *
 * The fixtures below are asserted to be LIVE VECTORS first: a test that cannot
 * demonstrate the bug it guards is not evidence.
 */

function esc_url_raw( $u ) { return $u; }
function __( $s, $d = '' ) { return $s; }

/**
 * Core's wp_json_encode(), reproduced closely enough to fail where core fails.
 *
 * Core retries through `_wp_json_sanity_check()`, which strips invalid UTF-8 — which
 * is why "invalid UTF-8" alone is NOT a reliable vector and depth/recursion are used
 * below instead. Returning json_encode()'s own false keeps that faithful.
 */
function wp_json_encode( $data, $flags = 0, $depth = 512 ) {
	return json_encode( $data, $flags, $depth );
}

require __DIR__ . '/../src/Support/Json.php';
require __DIR__ . '/../src/Queue/Hasher.php';

use IfsDeploy\Queue\Hasher;
use IfsDeploy\Support\Json;

$pass = 0; $fail = 0;
function ok( string $n, bool $c ): void {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "PASS  $n\n"; } else { $fail++; echo "FAIL  $n\n"; }
}

/** An array nested past json_encode()'s depth limit. */
function too_deep( int $levels = 600 ): array {
	$value = array( 'leaf' => 'x' );
	for ( $i = 0; $i < $levels; $i++ ) {
		$value = array( 'down' => $value );
	}
	return $value;
}

echo "=== the fixtures really are unencodable ===\n";
//
// Assert the vector before asserting anything about the handling of it. The first
// object-injection fixture in this repo was a dud — a class name one character short
// of its declared length, so unserialize() bailed before instantiating and the "safe"
// assertion meant nothing. Same discipline here.

$deep_a = too_deep();
$deep_b = too_deep( 601 );

ok( 'a 600-deep array cannot be encoded', false === json_encode( $deep_a ) );
ok( 'nor can a 601-deep one', false === json_encode( $deep_b ) );
ok( 'and the two are genuinely different values', $deep_a !== $deep_b );
ok( 'INF cannot be encoded either', false === json_encode( array( 'n' => INF ) ) );
ok( 'ordinary data still encodes', is_string( json_encode( array( 'ok' => 1 ) ) ) );

echo "\n=== Json::encode reports failure instead of hiding it ===\n";

ok( 'a failure is null, not an empty string', null === Json::encode( $deep_a ) );
ok( 'INF is null too', null === Json::encode( array( 'n' => INF ) ) );
ok( 'success returns the JSON', '{"ok":1}' === Json::encode( array( 'ok' => 1 ) ) );
ok( 'flags are passed through', "{\n    \"ok\": 1\n}" === Json::encode( array( 'ok' => 1 ), JSON_PRETTY_PRINT ) );
ok( 'an empty array is a success, not a failure', '[]' === Json::encode( array() ) );
ok( 'null encodes to "null" rather than reporting failure', 'null' === Json::encode( null ) );

echo "\n=== THE QUEUE BUG: unencodable packages must not all hash alike ===\n";

$hash_a = Hasher::hash( $deep_a );
$hash_b = Hasher::hash( $deep_b );

ok( 'an unencodable package still produces a hash', 32 === strlen( $hash_a ) );
ok( 'it is NOT the hash of an empty string', md5( '' ) !== $hash_a );
ok( 'two different unencodable packages hash DIFFERENTLY', $hash_a !== $hash_b );
ok( 'the same unencodable package hashes stably', $hash_a === Hasher::hash( too_deep() ) );

echo "\n=== and the ordinary path is untouched ===\n";

$package = array( 'type' => 'post', 'origin_id' => 412, 'object' => array( 'post_title' => 'About Us' ) );

ok( 'a normal package still hashes as md5(json)', md5( (string) json_encode( $package ) ) === Hasher::hash( $package ) );
ok( 'a changed field changes the hash', Hasher::hash( $package ) !== Hasher::hash( array_merge( $package, array( 'origin_id' => 413 ) ) ) );
ok( 'an unchanged package is stable', Hasher::hash( $package ) === Hasher::hash( $package ) );

echo "\n=== every call site checks, so none can cast false to '' again ===\n";
//
// Read through php_strip_whitespace(): the comments at these very call sites explain
// the bug, and would otherwise satisfy a search for it.
$root = __DIR__ . '/..';

$guarded = array(
	'Queue/Hasher.php',
	'Support/ContentSignature.php',
	'Support/PackageDiff.php',
	'Rollback/SnapshotStore.php',
	'Client/DeployClient.php',
	'History/DeploymentRepository.php',
);

foreach ( $guarded as $file ) {
	$src = (string) php_strip_whitespace( $root . '/src/' . $file );

	ok( "$file goes through Json::encode", false !== strpos( $src, 'Json::encode(' ) );
	ok( "$file no longer casts the result to string", 0 === preg_match( '/\(string\)\s*wp_json_encode/', $src ) );
}

// The two that must FAIL CLOSED rather than carry on with a placeholder.
$snapshots = (string) php_strip_whitespace( $root . '/src/Rollback/SnapshotStore.php' );
ok( 'an unencodable snapshot creates no revision row', (bool) preg_match( '/if\s*\(\s*null === \$json\s*\)\s*\{.*?return 0;/s', $snapshots ) );

$client = (string) php_strip_whitespace( $root . '/src/Client/DeployClient.php' );
ok( 'an unencodable payload is never sent', (bool) preg_match( '/if\s*\(\s*null === \$body\s*\)\s*\{.*?WP_Error/s', $client ) );

$signature = (string) php_strip_whitespace( $root . '/src/Support/ContentSignature.php' );
ok( 'an unencodable post has NO signature rather than md5( \'\' )', false !== strpos( $signature, 'null === $json ? null : md5( $json )' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
