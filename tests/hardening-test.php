<?php
/**
 * SECURITY.md round-2 "safe batch": M-4, M-5, M-6, L-5.
 *
 * The recurring theme in these assertions is that none of the four may break a WORKING
 * pair. Each fix has an obvious over-strict version that would, and the tests below pin the
 * boundary: an existing http:// config still deploys, a legitimate batch is not refused, a
 * peer that authenticates clears its own failure counter, and only the two codes that form
 * an oracle are genericised.
 */

$root = dirname( __DIR__ );
$pass = 0;
$fail = 0;

function ok( string $label, bool $condition ): bool {
	global $pass, $fail;

	if ( $condition ) {
		++$pass;
		echo "  PASS  $label\n";

		return true;
	}

	++$fail;
	echo "  FAIL  $label\n";

	return false;
}

/* -----------------------------------------------------------------------------
 * Stubs
 * -------------------------------------------------------------------------- */

define( 'MB_IN_BYTES', 1048576 );

$GLOBALS['dp_options']    = array();
$GLOBALS['dp_transients'] = array();
$GLOBALS['dp_log']        = array();
$GLOBALS['dp_ip']         = '203.0.113.9';

function __( string $t, string $d = '' ): string {
	return $t;
}
function get_option( string $n, $default = false ) {
	return array_key_exists( $n, $GLOBALS['dp_options'] ) ? $GLOBALS['dp_options'][ $n ] : $default;
}
function update_option( string $n, $v, $a = null ): bool {
	$GLOBALS['dp_options'][ $n ] = $v;

	return true;
}
function apply_filters( string $hook, $value, ...$args ) {
	return array_key_exists( $hook, $GLOBALS['dp_filters'] ?? array() ) ? $GLOBALS['dp_filters'][ $hook ] : $value;
}
function untrailingslashit( string $v ): string {
	return rtrim( $v, '/\\' );
}
function esc_url_raw( string $v ): string {
	return trim( $v );
}
function wp_parse_url( string $url, int $component = -1 ) {
	$parts = parse_url( $url );

	if ( -1 === $component ) {
		return $parts;
	}

	$map = array( PHP_URL_SCHEME => 'scheme', PHP_URL_HOST => 'host' );

	return $parts[ $map[ $component ] ?? '' ] ?? null;
}
function get_transient( string $k ) {
	if ( ! isset( $GLOBALS['dp_transients'][ $k ] ) ) {
		return false;
	}

	[ $value, $expires ] = $GLOBALS['dp_transients'][ $k ];

	if ( $expires < time() ) {
		unset( $GLOBALS['dp_transients'][ $k ] );

		return false;
	}

	return $value;
}
function set_transient( string $k, $v, int $ttl = 0 ): bool {
	$GLOBALS['dp_transients'][ $k ] = array( $v, time() + $ttl );

	return true;
}
function delete_transient( string $k ): bool {
	unset( $GLOBALS['dp_transients'][ $k ] );

	return true;
}
function wp_privacy_anonymize_ip( $ip, $f = false ) {
	return $ip;
}
function sanitize_text_field( $s ): string {
	return trim( strip_tags( (string) $s ) );
}
function wp_unslash( $v ) {
	return $v;
}
function current_time( string $type = 'mysql', $gmt = 0 ) {
	return 'timestamp' === $type ? time() : gmdate( 'Y-m-d H:i:s' );
}
function wp_json_encode( $d, int $f = 0 ) {
	return json_encode( $d, $f );
}
function size_format( $bytes, int $dec = 0 ): string {
	return (string) round( $bytes / MB_IN_BYTES, 1 ) . ' MB';
}

spl_autoload_register(
	static function ( string $class ) use ( $root ): void {
		if ( 0 !== strpos( $class, 'IfsDeploy\\' ) ) {
			return;
		}

		$path = $root . '/src/' . str_replace( '\\', '/', substr( $class, strlen( 'IfsDeploy\\' ) ) ) . '.php';

		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

// ClientIp reads $_SERVER; drive it from one global so the lockout tests can change address.
function dp_as_ip( string $ip ): void {
	$GLOBALS['dp_ip'] = $ip;
	$_SERVER          = array( 'REMOTE_ADDR' => $ip );
}

dp_as_ip( '203.0.113.9' );

use IfsDeploy\Auth\Lockout;
use IfsDeploy\Support\Config;

/* -----------------------------------------------------------------------------
 * M-4 — HTTPS for the Production URL
 * -------------------------------------------------------------------------- */

echo "=== M-4: the Production URL must be https ===\n";

$GLOBALS['dp_filters'] = array();

ok( 'https is accepted', Config::validate_remote_url( 'https://prod.example.com' )['ok'] );
ok( 'and is stored without a trailing slash', 'https://prod.example.com' === Config::validate_remote_url( 'https://prod.example.com/' )['url'] );

$http = Config::validate_remote_url( 'http://prod.example.com' );
ok( 'plain http is refused', ! $http['ok'] );
// The message has to say WHY, or it reads as an arbitrary restriction.
ok( 'and explains that the signature does not encrypt', false !== strpos( $http['error'], 'travel in the clear' ) );

// Empty is how a Production-role site legitimately has no remote configured.
ok( 'an empty URL is allowed', Config::validate_remote_url( '' )['ok'] );

$junk = Config::validate_remote_url( 'not a url' );
ok( 'a non-URL is refused', ! $junk['ok'] );

echo "=== M-4: local development is not blocked ===\n";

// Refusing these would make the plugin undevelopable rather than safer — a .test host has
// no certificate to present.
foreach ( array( 'http://localhost', 'http://127.0.0.1:8080', 'http://mysite.test', 'http://dev.local' ) as $dev ) {
	ok( "$dev is allowed", Config::validate_remote_url( $dev )['ok'] );
}

// Anything else needs the filter, so an unusual internal setup is a deliberate decision.
ok( 'an internal hostname is refused by default', ! Config::validate_remote_url( 'http://prod.internal' )['ok'] );

$GLOBALS['dp_filters']['ifs_deploy_allow_insecure_transport'] = true;
ok( 'the filter can permit it', Config::validate_remote_url( 'http://prod.internal' )['ok'] );
$GLOBALS['dp_filters'] = array();

echo "=== M-4: an EXISTING http config keeps working ===\n";

// The one thing that must not happen on upgrade. A stored http URL is reported, never
// rewritten or disabled — silently breaking a live pair is worse than the warning.
$GLOBALS['dp_options']['ifs_deploy_remote'] = array(
	'url'        => 'http://prod.example.com',
	'api_key'    => 'dpk_x',
	'secret_key' => 'dps_y',
);

ok( 'the stored URL is unchanged', 'http://prod.example.com' === Config::remote()['url'] );
ok( 'and is flagged as insecure', Config::remote_is_insecure() );

$GLOBALS['dp_options']['ifs_deploy_remote']['url'] = 'https://prod.example.com';
ok( 'an https config is not flagged', ! Config::remote_is_insecure() );

$GLOBALS['dp_options']['ifs_deploy_remote']['url'] = 'http://mysite.test';
ok( 'a local dev config is not flagged', ! Config::remote_is_insecure() );

$GLOBALS['dp_options']['ifs_deploy_remote']['url'] = '';
ok( 'an unconfigured site is not flagged', ! Config::remote_is_insecure() );

// The save path must refuse the URL AND the keys together — storing keys against a URL we
// rejected would leave a connection that looks saved and cannot work.
$settings = (string) file_get_contents( $root . '/src/Admin/Pages/SettingsPage.php' );
ok( 'the save path validates the URL', false !== strpos( $settings, 'Config::validate_remote_url(' ) );
ok( 'a refused URL blocks the whole remote block', false !== strpos( $settings, 'the Production connection was NOT changed' ) );
ok( 'an existing insecure config is warned about', false !== strpos( $settings, 'Config::remote_is_insecure()' ) );

/* -----------------------------------------------------------------------------
 * M-5 — caps
 * -------------------------------------------------------------------------- */

echo "=== M-5: batch and payload caps ===\n";

$import = (string) file_get_contents( $root . '/src/Rest/ImportEndpoint.php' );

ok( 'the import batch is capped', false !== strpos( $import, 'ifs_deploy_max_objects_per_import' ) );
ok( 'the default is generous (200)', false !== strpos( $import, '200' ) );
// REFUSES rather than truncating: importing the first 200 of 500 and reporting success
// would leave Production half-updated while Staging believed it was done.
ok( 'an oversized batch is refused, not truncated', false !== strpos( $import, '413' ) );
ok( 'and never silently sliced', false === strpos( $import, 'array_slice( $objects' ) );
ok( 'the refusal says what to do', false !== strpos( $import, 'Push fewer items at a time' ) );
ok( 'and is logged', (bool) preg_match( '/DebugLog::warning\(\s*\n\s*.Refused an import batch/', $import ) );

$media = (string) file_get_contents( $root . '/src/Import/MediaImporter.php' );

ok( 'media downloads are capped', false !== strpos( $media, 'ifs_deploy_max_media_bytes' ) );
// Checked twice, because either alone leaves a gap: Content-Length is optional and may lie;
// the on-disk size is authoritative but only known after the transfer.
ok( 'Content-Length is checked first', false !== strpos( $media, 'declared_size(' ) );
ok( 'and the real size after download', false !== strpos( $media, 'filesize( $tmp )' ) );
// The whole point is to never load a huge file into PHP's memory.
$size_at = strpos( $media, 'filesize( $tmp )' );
$read_at = strpos( $media, 'file_get_contents( $tmp )' );
ok( 'the size check runs BEFORE the file is read into memory', false !== $size_at && false !== $read_at && $size_at < $read_at );
ok( 'an oversized temp file is deleted', (bool) preg_match( '/media_too_large/s', $media ) && false !== strpos( $media, '@unlink( $tmp )' ) );
// The HEAD probe must not reopen the SSRF hole download_url() carefully avoids.
ok( 'the HEAD probe uses the safe HTTP wrapper', false !== strpos( $media, 'wp_safe_remote_head(' ) );
ok( 'and does not follow redirects', (bool) preg_match( '/wp_safe_remote_head\(.*?redirection.*?0/s', $media ) );

/* -----------------------------------------------------------------------------
 * M-6 — lockout
 * -------------------------------------------------------------------------- */

echo "=== M-6: repeated auth failures earn a cool-off ===\n";

$GLOBALS['dp_transients'] = array();
dp_as_ip( '198.51.100.50' );

ok( 'a fresh address is not locked', ! Lockout::is_locked() );

// Below the threshold nothing happens — a peer with a briefly wrong clock must not be
// locked out on its second try.
for ( $i = 0; $i < 5; $i++ ) {
	Lockout::record( 'ifs_deploy_bad_signature' );
}
ok( 'five failures do not lock it', ! Lockout::is_locked() );

for ( $i = 0; $i < 10; $i++ ) {
	Lockout::record( 'ifs_deploy_bad_signature' );
}
ok( 'sustained failures do lock it', Lockout::is_locked() );
ok( 'and it reports how long to wait', Lockout::retry_after() > 0 );

echo "=== M-6: a working peer is never locked out ===\n";

$GLOBALS['dp_transients'] = array();
dp_as_ip( '198.51.100.51' );

// A success must clear the slate, or a misconfiguration that has since been FIXED would
// keep the peer locked out until the window expired.
for ( $i = 0; $i < 11; $i++ ) {
	Lockout::record( 'ifs_deploy_bad_signature' );
}
Lockout::record( 'ok' );

for ( $i = 0; $i < 11; $i++ ) {
	Lockout::record( 'ifs_deploy_bad_signature' );
}
ok( 'a success resets the counter', ! Lockout::is_locked() );

echo "=== M-6: transport noise is NOT counted ===\n";

$GLOBALS['dp_transients'] = array();
dp_as_ip( '198.51.100.52' );

// A proxy retry is a transport quirk. Counting duplicates would lock out the site's own
// legitimate peer on a bad network day — the exact duplicate-delivery case already seen.
for ( $i = 0; $i < 30; $i++ ) {
	Lockout::record( 'ifs_deploy_duplicate' );
	Lockout::record( 'ifs_deploy_replay' );
}
ok( 'duplicates and replays never lock', ! Lockout::is_locked() );

$GLOBALS['dp_transients'] = array();
dp_as_ip( '198.51.100.53' );

// Already-refused addresses are cheap to reject; counting them would let anyone fill the
// counter store for free.
for ( $i = 0; $i < 30; $i++ ) {
	Lockout::record( 'ifs_deploy_ip_blocked' );
	Lockout::record( 'ifs_deploy_ip_not_allowed' );
}
ok( 'address refusals never lock', ! Lockout::is_locked() );

$GLOBALS['dp_transients'] = array();
dp_as_ip( '198.51.100.54' );

// The counted list is an ALLOWLIST, so a refusal code added later does not silently start
// counting toward a lockout.
for ( $i = 0; $i < 30; $i++ ) {
	Lockout::record( 'ifs_deploy_some_future_code' );
}
ok( 'an unknown outcome never locks', ! Lockout::is_locked() );

echo "=== M-6: lockouts are per address, and explained ===\n";

$GLOBALS['dp_transients'] = array();
dp_as_ip( '198.51.100.55' );
for ( $i = 0; $i < 15; $i++ ) {
	Lockout::record( 'ifs_deploy_bad_key' );
}
ok( 'the offending address is locked', Lockout::is_locked() );

dp_as_ip( '203.0.113.9' );
ok( 'another address is unaffected', ! Lockout::is_locked() );

$lockout = (string) file_get_contents( $root . '/src/Auth/Lockout.php' );

// The most likely cause of a lockout is not an attack — it is the paired site holding a
// stale key. The log entry has to say so or it becomes a mystery.
ok( 'the log names the likely innocent cause', false !== strpos( $lockout, 'if_this_is_your_staging_site' ) );
ok( 'one entry per lockout, not per request', false !== strpos( $lockout, '$already' ) );
ok( 'an unidentifiable address is not locked', false !== strpos( $lockout, "'' === \$ip" ) );

$verifier = (string) file_get_contents( $root . '/src/Auth/Verifier.php' );

// Checked first — one transient read is cheaper than everything after it.
$lock_at = strpos( $verifier, 'Lockout::is_locked()' );
$sig_at  = strpos( $verifier, 'Signer::sign(' );
ok( 'the lockout is checked before signing', false !== $lock_at && $lock_at < $sig_at );
// Inside check(), so the refusal still reaches the access log and is diagnosable.
ok( 'and inside the logged path', $lock_at > strpos( $verifier, 'private static function check(' ) );
ok( 'a locked-out request gets 429', false !== strpos( $verifier, "'status' => 429" ) );
ok( 'with Retry-After information', false !== strpos( $verifier, 'retry_after' ) );

/* -----------------------------------------------------------------------------
 * L-5 — no key-vs-signature oracle
 * -------------------------------------------------------------------------- */

echo "=== L-5: bad key and bad signature are indistinguishable ===\n";

// Distinguishing them told an attacker whether the key they hold is the right one.
ok( 'the reply is genericised', false !== strpos( $verifier, 'ifs_deploy_unauthorized' ) );
ok( 'covering bad_key', (bool) preg_match( '/\$opaque\s*=\s*array\([^)]*ifs_deploy_bad_key/s', $verifier ) );
ok( 'and bad_signature', (bool) preg_match( '/\$opaque\s*=\s*array\([^)]*ifs_deploy_bad_signature/s', $verifier ) );

// …but the SPECIFIC code must still reach the log, or the site owner loses the ability to
// tell a wrong key from a wrong secret. Order proves it: log first, genericise last.
$log_at     = strpos( $verifier, 'ApiLog::record(' );
$generic_at = strpos( $verifier, 'self::public_error(' );
ok( 'the specific code is logged first', false !== $log_at && $log_at < $generic_at );

// Codes that help an HONEST caller must pass through untouched.
ok( 'expired still says so', false !== strpos( $verifier, 'ifs_deploy_expired' ) );
ok( 'duplicate still says so', false !== strpos( $verifier, 'ifs_deploy_duplicate' ) );
ok( 'only two codes are opaque', 1 === preg_match_all( '/\$opaque\s*=\s*array\(/', $verifier ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
