<?php
/**
 * Regression tests for the security fixes in SECURITY.md.
 *
 * Each block names the finding it guards. These are the assertions that must never go
 * green-to-red quietly, because every one of them protects against something that was
 * genuinely exploitable in a shipped build.
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

$GLOBALS['dp_transients'] = array();
$GLOBALS['dp_options']    = array();

function __( string $t, string $d = '' ): string {
	return $t;
}
function get_option( string $n, $default = false ) {
	return $GLOBALS['dp_options'][ $n ] ?? $default;
}
function update_option( string $n, $v, $a = null ): bool {
	$GLOBALS['dp_options'][ $n ] = $v;

	return true;
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
function current_time( string $type = 'mysql', $gmt = 0 ) {
	return 'timestamp' === $type ? time() : gmdate( 'Y-m-d H:i:s' );
}
function apply_filters( string $h, $v ) {
	return $v;
}
function wp_json_encode( $d, int $f = 0 ) {
	return json_encode( $d, $f );
}
function is_serialized( $data, $strict = true ): bool {
	if ( ! is_string( $data ) ) {
		return false;
	}
	$data = trim( $data );
	if ( 'N;' === $data ) {
		return true;
	}
	if ( strlen( $data ) < 4 || ':' !== $data[1] ) {
		return false;
	}

	return (bool) preg_match( '/^[aOsbdi]:/', $data );
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

use IfsDeploy\Auth\NonceStore;
use IfsDeploy\Auth\Signer;
use IfsDeploy\Support\Config;
use IfsDeploy\Support\DebugLog;
use IfsDeploy\Support\SafeData;

/* -----------------------------------------------------------------------------
 * CRITICAL — PHP Object Injection via maybe_unserialize() on payload data
 * -------------------------------------------------------------------------- */

echo "=== CRITICAL: object injection in imported meta ===\n";

/**
 * A gadget class. If `unserialize()` instantiates it, the flag flips — which is what a
 * real POP chain would use to reach a destructor.
 */
class DP_Gadget {
	public string $cmd = '';

	public function __wakeup() {
		$GLOBALS['dp_gadget_woke'] = true;
	}
}

$GLOBALS['dp_gadget_woke'] = false;
// The length prefix must match strlen("DP_Gadget") exactly, or unserialize() bails
// before instantiating and the test would pass for the wrong reason.
$payload                   = 'O:9:"DP_Gadget":1:{s:3:"cmd";s:6:"whoami";}';

// Confirm the fixture really is a live vector, so a passing test below means something.
$GLOBALS['dp_gadget_woke'] = false;
$naive                     = unserialize( $payload ); // phpcs:ignore
ok( 'the fixture instantiates via plain unserialize (vector is real)', $naive instanceof DP_Gadget );
ok( 'and its __wakeup() runs', true === $GLOBALS['dp_gadget_woke'] );

// The fix.
$GLOBALS['dp_gadget_woke'] = false;
$safe                      = SafeData::unserialize( $payload );

ok( 'SafeData does NOT instantiate the class', ! $safe instanceof DP_Gadget );
ok( 'no magic method runs', false === $GLOBALS['dp_gadget_woke'] );
ok( 'the object is an incomplete placeholder', is_object( $safe ) && '__PHP_Incomplete_Class' === get_class( $safe ) );

// decode() also strips the placeholder, so nothing object-shaped reaches post meta.
$GLOBALS['dp_gadget_woke'] = false;
ok( 'decode() strips it entirely', null === SafeData::decode( $payload ) );
ok( 'still no magic method', false === $GLOBALS['dp_gadget_woke'] );

// Nested inside an array — the realistic shape of an ACF meta value.
$nested = 'a:2:{s:2:"ok";s:4:"keep";s:4:"evil";O:9:"DP_Gadget":0:{}}';
$out    = SafeData::decode( $nested );

ok( 'legitimate array data survives', is_array( $out ) && 'keep' === ( $out['ok'] ?? null ) );
ok( 'the nested object is removed', is_array( $out ) && ! array_key_exists( 'evil', $out ) );

// Ordinary values must round-trip untouched, or every deploy corrupts content.
ok( 'plain strings pass through', 'hello' === SafeData::decode( 'hello' ) );
ok( 'arrays from JSON pass through', array( 1, 2 ) === SafeData::decode( array( 1, 2 ) ) );
ok( 'integers pass through', 42 === SafeData::decode( 42 ) );
ok( 'null passes through', null === SafeData::decode( null ) );
ok( 'serialized arrays still decode', array( 'a' => 'b' ) === SafeData::decode( 'a:1:{s:1:"a";s:1:"b";}' ) );
ok( 'serialized false decodes to false, not to the raw string', false === SafeData::decode( 'b:0;' ) );
// A string that merely looks serialized but is corrupt must not be blanked.
ok( 'a corrupt serialized string is kept verbatim', 'a:9:{bad' === SafeData::decode( 'a:9:{bad' ) );

echo "=== CRITICAL: no import path still calls maybe_unserialize ===\n";

// The fix is only complete if EVERY write path that handles payload data uses SafeData.
// A new importer added later that reaches for maybe_unserialize reopens the hole.
foreach (
	array(
		'src/Import/PostImporter.php',
		'src/Import/MediaImporter.php',
		'src/Import/TermImporter.php',
		'src/Rollback/SnapshotStore.php',
	) as $file
) {
	$src = (string) file_get_contents( $root . '/' . $file );

	ok( basename( $file ) . ' does not unserialize payload data', false === strpos( $src, 'maybe_unserialize' ) );
	ok( basename( $file ) . ' uses SafeData', false !== strpos( $src, 'SafeData::' ) );
}

// Export is the other direction: values this site's own WordPress serialized. Using
// maybe_unserialize there is correct, and swapping it would be a pointless change.
$export = (string) file_get_contents( $root . '/src/Export/PostExporter.php' );
ok( 'export still uses maybe_unserialize (correct — local data)', false !== strpos( $export, 'maybe_unserialize' ) );

/* -----------------------------------------------------------------------------
 * HIGH — replay attacks
 * -------------------------------------------------------------------------- */

echo "=== HIGH: replay protection ===\n";

$GLOBALS['dp_transients'] = array();
$nonce                    = 'f0336546-b798-4a78-9adf-361aeb7d0bfe';

/*
 * `claim()` runs against the DB v5 table. There is no $wpdb here, so `$wpdb->insert()`
 * fails, the confirming SELECT finds nothing, and the store FALLS OPEN to transients — which
 * is exactly the degraded path this suite should be covering, since it is what a site with a
 * failed migration would run. The table path itself is covered by nonce-test.php.
 */
$first = NonceStore::claim( $nonce );
ok( 'a fresh nonce is claimed', true === $first['fresh'] );
ok( 'and has no earlier sighting', 0 === $first['first_seen'] );

$second = NonceStore::claim( $nonce );
ok( 'the same nonce cannot be claimed twice', false === $second['fresh'] );
// The acceptance time comes back so the caller can tell a transport duplicate from a
// deliberate replay.
ok( 'and reports when the original was accepted', $second['first_seen'] > 0 );

ok( 'a different nonce is unaffected', true === NonceStore::claim( 'a-different-nonce' )['fresh'] );

// The entry must outlive the timestamp window in BOTH directions: the window is applied to
// |now - timestamp|, so a request may arrive up to WINDOW seconds in the future.
$key = 'dp_nonce_' . hash( 'sha256', $nonce );
ok( 'the fallback entry is stored', isset( $GLOBALS['dp_transients'][ $key ] ) );
ok(
	'it outlives twice the timestamp window',
	( $GLOBALS['dp_transients'][ $key ][1] - time() ) > ( 2 * Config::TIMESTAMP_WINDOW )
);

// An unusable nonce must fail CLOSED — refused, rather than sailing through with no replay
// protection at all. It is a malformed request, not a database problem.
ok( 'an empty nonce is refused', false === NonceStore::claim( '' )['fresh'] );
ok( 'an over-long nonce is refused', false === NonceStore::claim( str_repeat( 'a', 500 ) )['fresh'] );

// The nonce is attacker-controlled, so it must be hashed rather than used raw — it is about
// to become a database key.
$store_src = (string) file_get_contents( $root . '/src/Auth/NonceStore.php' );
ok( 'nonces are hashed', false !== strpos( $store_src, "hash( 'sha256', \$nonce )" ) );

echo "=== HIGH: the nonce check runs AFTER the signature ===\n";

// Order is the whole defence against someone filling the replay store for free.
$verifier = (string) file_get_contents( $root . '/src/Auth/Verifier.php' );
$sig_at   = strpos( $verifier, 'Signer::verify(' );
$nonce_at = strpos( $verifier, 'NonceStore::claim(' );

ok( 'the verifier claims the nonce', false !== $nonce_at );
ok( 'the signature is verified first', false !== $sig_at && $sig_at < $nonce_at );

/*
 * Checking and recording are now ONE call, which is the point of H-1a: two steps left a
 * window in which two concurrent deliveries of the same request could both read "not seen"
 * before either wrote. `claim()` is a single INSERT against a UNIQUE index.
 */
ok( 'check and record are one atomic call', false === strpos( $verifier, 'NonceStore::remember(' ) );
ok( 'and there is no separate seen() read', false === strpos( $verifier, 'NonceStore::seen(' ) );
ok( 'a replay is refused with 409', false !== strpos( $verifier, "'status' => 409" ) );

/* -----------------------------------------------------------------------------
 * Signature and key handling
 * -------------------------------------------------------------------------- */

echo "=== signature comparison is timing-safe ===\n";

$secret   = 'dps_' . str_repeat( 'a', 64 );
$expected = Signer::sign( '1700000000', $nonce, '{"a":1}', $secret );

ok( 'a correct signature verifies', Signer::verify( $expected, $expected ) );
ok( 'a wrong signature does not', ! Signer::verify( $expected, str_repeat( '0', 64 ) ) );
ok( 'an empty presented signature does not', ! Signer::verify( $expected, '' ) );
ok( 'an empty expected signature does not', ! Signer::verify( '', $expected ) );

// hash_equals, not ===. A byte-wise comparison leaks the correct prefix through timing.
$signer_src = (string) file_get_contents( $root . '/src/Auth/Signer.php' );
ok( 'comparison uses hash_equals', false !== strpos( $signer_src, 'hash_equals' ) );

// The body is bound to the signature, so a tampered payload cannot reuse one.
ok(
	'the body is part of the signed material',
	Signer::sign( '1700000000', $nonce, '{"a":1}', $secret ) !== Signer::sign( '1700000000', $nonce, '{"a":2}', $secret )
);
ok(
	'the nonce is part of the signed material',
	Signer::sign( '1700000000', 'n1', '{}', $secret ) !== Signer::sign( '1700000000', 'n2', '{}', $secret )
);
ok(
	'the timestamp is part of the signed material',
	Signer::sign( '1700000000', $nonce, '{}', $secret ) !== Signer::sign( '1700000001', $nonce, '{}', $secret )
);

echo "=== credential generation ===\n";

$creds_src = (string) file_get_contents( $root . '/src/Auth/Credentials.php' );

// random_bytes is the only CSPRNG here; rand()/mt_rand()/uniqid() would be predictable.
ok( 'keys come from random_bytes', false !== strpos( $creds_src, 'random_bytes' ) );
ok( 'no weak randomness is used', ! preg_match( '/\b(mt_rand|rand|uniqid)\s*\(/', $creds_src ) );
ok( 'the api key has 128 bits of entropy', false !== strpos( $creds_src, 'random_bytes( 16 )' ) );
ok( 'the secret has 256 bits of entropy', false !== strpos( $creds_src, 'random_bytes( 32 )' ) );
// API key comparison is also timing-safe, so it cannot be brute-forced byte by byte.
ok( 'the api key is compared with hash_equals', false !== strpos( $creds_src, 'hash_equals' ) );
// autoload = false keeps the secret out of every page load's option cache.
ok( 'credentials are not autoloaded', (bool) preg_match( '/update_option\(\s*self::OPTION,\s*\$creds,\s*false\s*\)/', $creds_src ) );

/* -----------------------------------------------------------------------------
 * MEDIUM — credential leakage into logs
 * -------------------------------------------------------------------------- */

echo "=== MEDIUM: credentials never reach the log ===\n";

$GLOBALS['dp_options'] = array();
DebugLog::error(
	'Connection failed',
	array(
		'secret_key' => 'dps_abcdef0123456789',
		'api_key'    => 'dpk_abcdef0123456789',
		'url'        => 'https://prod.test/wp-json?key=dpk_deadbeefcafe',
		'detail'     => 'server said: {"secret_key":"dps_1234567890abcdef"}',
		'hash'       => str_repeat( 'a', 64 ),
		'harmless'   => 'this should survive',
	)
);

$logged = DebugLog::all();
$ctx    = $logged[0]['context'] ?? array();
$blob   = (string) wp_json_encode( $ctx );

ok( 'the secret_key key is redacted', '[redacted]' === ( $ctx['secret_key'] ?? '' ) );
ok( 'the api_key key is redacted', '[redacted]' === ( $ctx['api_key'] ?? '' ) );
// Key-name matching alone missed these two: a credential inside an innocuous field.
ok( 'a key inside a URL value is redacted', false === strpos( (string) ( $ctx['url'] ?? '' ), 'deadbeefcafe' ) );
ok( 'a secret inside a response body is redacted', false === strpos( (string) ( $ctx['detail'] ?? '' ), '1234567890abcdef' ) );
ok( 'a bare long hex string is redacted', false === strpos( (string) ( $ctx['hash'] ?? '' ), str_repeat( 'a', 64 ) ) );
ok( 'no dps_ secret survives anywhere', ! preg_match( '/dps_[A-Za-z0-9]{6,}/', $blob ) );
ok( 'no dpk_ key survives anywhere', ! preg_match( '/dpk_[A-Za-z0-9]{6,}/', $blob ) );
ok( 'harmless context is preserved', 'this should survive' === ( $ctx['harmless'] ?? '' ) );

// The error_log mirror must get the SAME redacted array. It used to receive the raw one,
// so with WP_DEBUG on every secret went to debug.log in clear.
$log_src = (string) file_get_contents( $root . '/src/Support/DebugLog.php' );
ok( 'the error-log mirror receives the redacted copy', false !== strpos( $log_src, 'Logger::debug( $message, $safe )' ) );
ok( 'and never the raw context', false === strpos( $log_src, 'Logger::debug( $message, $context )' ) );

/* -----------------------------------------------------------------------------
 * Endpoint authorisation
 * -------------------------------------------------------------------------- */

echo "=== every REST route is guarded ===\n";

$routes  = glob_php( $root . '/src/Rest' );
$checked = 0;

foreach ( $routes as $file ) {
	$src = (string) file_get_contents( $file );

	if ( false === strpos( $src, 'register_rest_route' ) ) {
		continue;
	}

	++$checked;
	$name = basename( $file );

	ok( "$name declares a permission_callback", false !== strpos( $src, 'permission_callback' ) );
	ok( "$name routes it through Verifier", (bool) preg_match( '/permission_callback[^,]*=>\s*array\(\s*Verifier::class/', $src ) );
	// __return_true would make the endpoint public. It must never appear here.
	ok( "$name is not publicly callable", false === strpos( $src, '__return_true' ) );
}

ok( 'several endpoints were actually checked', $checked >= 8 );

echo "=== writes are refused in the wrong direction ===\n";

// A Production site must never be talked into pushing, and a Staging site must refuse to
// receive — otherwise a compromised peer could invert the flow.
foreach ( array( 'ImportEndpoint.php', 'RollbackEndpoint.php' ) as $name ) {
	$src = (string) file_get_contents( $root . '/src/Rest/' . $name );

	ok( "$name only accepts writes when acting as Production", false !== strpos( $src, 'Config::is_production()' ) );
	ok( "$name refuses otherwise with 409", false !== strpos( $src, '409' ) );
}

/* -----------------------------------------------------------------------------
 * SQL
 * -------------------------------------------------------------------------- */

echo "=== SQL is parameterised ===\n";

$sql_files = array_merge(
	glob_php( $root . '/src/Queue' ),
	glob_php( $root . '/src/History' ),
	glob_php( $root . '/src/Rollback' ),
	glob_php( $root . '/src/Support' )
);

$unsafe = array();
foreach ( $sql_files as $file ) {
	$src = (string) file_get_contents( $file );

	/*
	 * Interpolation into SQL is only safe for two things, both allowed here by name:
	 *
	 *  - IDENTIFIERS that cannot be parameterised: $table, $queue, $deployments,
	 *    $revisions, $addresses, $api_log, $wpdb->… — all built from $wpdb->prefix,
	 *    never from a request.
	 *  - $charset_collate, the DDL fragment from $wpdb->get_charset_collate().
	 *  - $placeholders, a run of literal `%d` tokens produced by
	 *    array_fill( 0, count( $ids ), '%d' ). The VALUES still go through prepare();
	 *    only the placeholder count is interpolated, which is derived from an array
	 *    length and cannot carry user data.
	 *
	 * Anything else interpolated into a query string is a finding.
	 */
	if ( preg_match_all( '/"[^"]*(SELECT|INSERT|UPDATE|DELETE)[^"]*\{\$(?!table|wpdb|placeholders|queue|deployments|revisions|addresses|api_log|charset_collate)[a-z_]+\}[^"]*"/i', $src, $m ) ) {
		foreach ( $m[0] as $hit ) {
			$unsafe[] = basename( $file ) . ': ' . substr( $hit, 0, 90 );
		}
	}
}

ok( 'no user data is interpolated into SQL', empty( $unsafe ) );
foreach ( $unsafe as $hit ) {
	echo "        $hit\n";
}

/* -----------------------------------------------------------------------------
 * AJAX
 * -------------------------------------------------------------------------- */

echo "=== every AJAX action is capability- and nonce-checked ===\n";

$ajax = (string) file_get_contents( $root . '/src/Admin/Ajax.php' );

preg_match_all( "/add_action\(\s*'wp_ajax_(ifs_deploy_[a-z_]+)'\s*,\s*array\(\s*\\\$this,\s*'([a-z_]+)'\s*\)/", $ajax, $actions, PREG_SET_ORDER );

ok( 'ajax actions were found', count( $actions ) >= 12 );

foreach ( $actions as $action ) {
	$method = $action[2];

	// Every handler's body must reach $this->guard(), which does both the capability
	// check and check_ajax_referer().
	if ( preg_match( '/function ' . preg_quote( $method, '/' ) . '\(\):\s*void\s*\{(.*?)\n\t\}/s', $ajax, $body ) ) {
		ok( "$method() calls guard()", false !== strpos( $body[1], '$this->guard(' ) );
	} else {
		ok( "$method() body was located", false );
	}
}

echo "=== pushing is refused on a receiving site (SECURITY.md, direction guard) ===\n";

/*
 * The REST layer already answers 409 to `/import` and `/rollback` unless the receiver is set
 * to Production. The AJAX layer had no equivalent, so a Production administrator POSTing
 * `ifs_deploy_deploy` directly would open a deployment record and try to push.
 *
 * Three things already made that harmless — a receiver's queue is always empty because
 * ChangeTracker never registers there, Pending Changes is Staging-only, and Production has no
 * remote to push to. This asserts the guard rather than those three staying true.
 */
foreach ( array( 'deploy', 'deploy_posts' ) as $method ) {
	if ( ! preg_match( '/function ' . preg_quote( $method, '/' ) . '\(\):\s*void\s*\{(.*?)\n\t\}/s', $ajax, $body ) ) {
		ok( "$method() body was located", false );
		continue;
	}

	ok( "$method() requires the staging role", false !== strpos( $body[1], '$this->require_staging()' ) );
	// Before any work: after the capability check, before the ids are read.
	ok( "$method() checks it before doing anything", strpos( $body[1], 'require_staging()' ) < strpos( $body[1], 'DeploymentService' ) );
}

ok( 'the guard exists', false !== strpos( $ajax, 'private function require_staging(): void' ) );
ok( 'and is keyed on the role, not a capability', (bool) preg_match( '/require_staging\(\): void \{\s*if \( Config::is_staging\(\) \)/', $ajax ) );
// 409, matching the REST layer, so both halves of one rule report it the same way.
ok( 'it answers 409 like the REST side does', (bool) preg_match( '/receives deployments rather than sending them.*?\n.*?409/s', $ajax ) );

echo "=== a leaked secret can actually be rotated (SECURITY.md M-7) ===\n";

/*
 * The button, its confirm dialog and its nonce field all existed; the handler did not. So the
 * only documented remedy for an exposed shared secret silently did nothing — and §7 of this
 * document asserted that it worked.
 *
 * Rotation is the whole remedy, so these assertions are about the WHOLE path being present:
 * the form that posts it, the nonce that authorises it, and the handler that acts on it.
 */
$settings = (string) file_get_contents( $root . '/src/Admin/Pages/SettingsPage.php' );

ok( 'the form still posts the action', false !== strpos( $settings, "value=\"regenerate\"" ) );
ok( 'and carries its own nonce field', false !== strpos( $settings, "wp_nonce_field( 'ifs_deploy_regenerate' )" ) );

// THE assertion. This is the one that was false.
ok( 'the action is handled', false !== strpos( $settings, "if ( 'regenerate' === \$action ) {" ) );
ok( 'the nonce is verified', false !== strpos( $settings, "check_admin_referer( 'ifs_deploy_regenerate' )" ) );
ok( 'and it really regenerates', false !== strpos( $settings, 'Credentials::regenerate();' ) );

// Order matters: a handler that acted before verifying the referer would be CSRF-able into
// breaking the connection between two live sites.
ok(
	'the nonce is checked before anything is written',
	strpos( $settings, "check_admin_referer( 'ifs_deploy_regenerate' )" ) < strpos( $settings, 'Credentials::regenerate();' )
);

// It is a security-relevant admin action and it starts an outage until the peer is updated,
// so it has to leave a trace, and at a level people look at.
ok( 'the rotation is logged', (bool) preg_match( '/Credentials::regenerate\(\);.*?DebugLog::warning\(/s', $settings ) );
ok( 'the log names who did it', (bool) preg_match( '/DebugLog::warning\(.*?wp_get_current_user\(\)/s', $settings ) );
// Never the keys themselves. DebugLog redacts dpk_/dps_ values anyway; the point is not to try.
ok( 'and never the new keys', ! preg_match( '/DebugLog::warning\(.*?\$creds/s', $settings ) );

// The notice has to say the connection is now broken — otherwise the next failed deploy is
// the first anyone hears of it.
ok( 'the notice says the old keys are dead', false !== strpos( $settings, 'no longer work' ) );
ok( 'and says what to do next', false !== strpos( $settings, 'copy the new ones below into the Staging site' ) );

/*
 * `ifs_deploy_peer_protocol` must NOT be cleared here. It records what the site this one
 * pushes TO supports — a receiver's own identity and a sender's memory of its peer are
 * separate facts, and resetting a proven capability would be a downgrade for no reason.
 */
if ( preg_match( '/if \( \'regenerate\' === \$action \) \{(.*?)\n\t\t\}\n/s', $settings, $regen ) ) {
	ok( 'it does not reset the protocol ratchet', false === strpos( $regen[1], 'Protocol::forget_peer();' ) );
} else {
	ok( 'the regenerate branch was located', false );
}

// guard() must fail closed: an action added without naming a capability stays admin-only.
ok( 'guard() defaults to the admin capability', (bool) preg_match( '/function guard\(\s*string \$capability = AdminMenu::CAPABILITY/', $ajax ) );
ok( 'guard() verifies the nonce', false !== strpos( $ajax, 'check_ajax_referer( self::NONCE' ) );
ok( 'guard() checks the capability', false !== strpos( $ajax, 'current_user_can( $capability )' ) );

/* -----------------------------------------------------------------------------
 * Hygiene
 * -------------------------------------------------------------------------- */

echo "=== hygiene ===\n";

// Direct file access must be refused, or the file executes outside WordPress.
$boot = (string) file_get_contents( $root . '/ifs-deploy.php' );
ok( 'the bootstrap blocks direct access', false !== strpos( $boot, "if ( ! defined( 'ABSPATH' ) ) {" ) );

$uninstall = (string) file_get_contents( $root . '/uninstall.php' );
ok( 'uninstall requires the WP_UNINSTALL_PLUGIN guard', false !== strpos( $uninstall, "defined( 'WP_UNINSTALL_PLUGIN' )" ) );
ok( 'uninstall removes the retention option', false !== strpos( $uninstall, 'ifs_deploy_log_retention_days' ) );
ok( 'uninstall clears the cron event', false !== strpos( $uninstall, 'wp_clear_scheduled_hook' ) );
ok( 'uninstall clears replay nonces', false !== strpos( $uninstall, '_transient_dp_nonce_' ) );

// No debugging left behind anywhere in src/.
$leftovers = array();
foreach ( array_merge( glob_php( $root . '/src' ), glob_php( $root . '/src/Admin' ) ) as $file ) {
	$src = (string) file_get_contents( $file );

	if ( preg_match( '/\b(var_dump|print_r|die\s*\(|dd\s*\()/', $src, $m ) ) {
		$leftovers[] = basename( $file ) . ': ' . $m[1];
	}
}
ok( 'no debug output functions left in src/', empty( $leftovers ) );
foreach ( $leftovers as $hit ) {
	echo "        $hit\n";
}

// SSL verification must never be disabled on the signed transport.
$client = (string) file_get_contents( $root . '/src/Client/DeployClient.php' );
ok( 'SSL verification is not disabled', false === strpos( $client, 'sslverify' ) || false === strpos( $client, "'sslverify' => false" ) );

/** scandir rather than glob: the repo path contains "[22020]", which glob parses as a character class. */
function glob_php( string $dir ): array {
	if ( ! is_dir( $dir ) ) {
		return array();
	}

	$out = array();
	foreach ( (array) scandir( $dir ) as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}

		$path = $dir . '/' . $entry;
		if ( is_file( $path ) && 'php' === pathinfo( $path, PATHINFO_EXTENSION ) ) {
			$out[] = $path;
		}
	}

	return $out;
}

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
