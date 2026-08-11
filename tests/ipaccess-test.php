<?php
/**
 * Allow / block lists for the API.
 *
 * The assertions here are mostly about NOT locking a working pair out of itself. An allow
 * list is the one setting in this plugin that can make Production refuse its own Staging
 * site, so the defaults, the validation, and the reporting of bad entries all matter more
 * than the matching itself.
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

$GLOBALS['dp_options'] = array();

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

use IfsDeploy\Support\IpAccess;

/* -----------------------------------------------------------------------------
 * Defaults
 * -------------------------------------------------------------------------- */

echo "=== an EMPTY allow list means no restriction ===\n";

$GLOBALS['dp_options'] = array();

// The single most important default. Reading an empty list as "deny everything" would mean
// installing the plugin breaks every deploy.
ok( 'no list is configured', ! IpAccess::is_configured() );
ok( 'any address is allowed', IpAccess::RESULT_ALLOWED === IpAccess::evaluate( '203.0.113.9' ) );

// An address we could not determine must not be refused either: failing closed would take
// the pair down on any server presenting REMOTE_ADDR in a form we reject, and the signature
// is still doing the real work.
ok( 'an undeterminable address is not refused', IpAccess::RESULT_ALLOWED === IpAccess::evaluate( '' ) );

echo "=== the allow list restricts once it has entries ===\n";

IpAccess::save( IpAccess::OPTION_ALLOW, '203.0.113.9' );

ok( 'the list is now in force', IpAccess::is_configured() );
ok( 'a listed address is allowed', IpAccess::RESULT_ALLOWED === IpAccess::evaluate( '203.0.113.9' ) );
ok( 'anything else is refused', IpAccess::RESULT_NOT_ALLOWED === IpAccess::evaluate( '203.0.113.10' ) );

echo "=== blocking always wins ===\n";

$GLOBALS['dp_options'] = array();
IpAccess::save( IpAccess::OPTION_ALLOW, '203.0.113.9' );
IpAccess::save( IpAccess::OPTION_BLOCK, '203.0.113.9' );

// One unambiguous answer when both lists name the same address, or nobody can reason about
// which rule applied.
ok( 'an address on both lists is blocked', IpAccess::RESULT_BLOCKED === IpAccess::evaluate( '203.0.113.9' ) );

$GLOBALS['dp_options'] = array();
IpAccess::save( IpAccess::OPTION_BLOCK, '192.0.2.44' );

ok( 'blocking works with no allow list', IpAccess::RESULT_BLOCKED === IpAccess::evaluate( '192.0.2.44' ) );
ok( 'and leaves everything else alone', IpAccess::RESULT_ALLOWED === IpAccess::evaluate( '203.0.113.9' ) );

/* -----------------------------------------------------------------------------
 * Matching
 * -------------------------------------------------------------------------- */

echo "=== CIDR ranges ===\n";

$GLOBALS['dp_options'] = array();
IpAccess::save( IpAccess::OPTION_ALLOW, '198.51.100.0/24' );

ok( 'the first address of the range matches', IpAccess::RESULT_ALLOWED === IpAccess::evaluate( '198.51.100.0' ) );
ok( 'a middle address matches', IpAccess::RESULT_ALLOWED === IpAccess::evaluate( '198.51.100.77' ) );
ok( 'the last address matches', IpAccess::RESULT_ALLOWED === IpAccess::evaluate( '198.51.100.255' ) );
ok( 'one past the range does not', IpAccess::RESULT_NOT_ALLOWED === IpAccess::evaluate( '198.51.101.0' ) );

// A prefix that is not a whole number of bytes is where naive implementations break, so the
// boundary byte is tested explicitly.
ok( '/25 includes .127', IpAccess::matches( '198.51.100.127', '198.51.100.0/25' ) );
ok( '/25 excludes .128', ! IpAccess::matches( '198.51.100.128', '198.51.100.0/25' ) );
ok( '/31 includes .1', IpAccess::matches( '198.51.100.1', '198.51.100.0/31' ) );
ok( '/31 excludes .2', ! IpAccess::matches( '198.51.100.2', '198.51.100.0/31' ) );
ok( '/32 is an exact match', IpAccess::matches( '198.51.100.5', '198.51.100.5/32' ) );
ok( '/32 excludes its neighbour', ! IpAccess::matches( '198.51.100.6', '198.51.100.5/32' ) );

echo "=== IPv6 ===\n";

ok( 'an IPv6 range matches', IpAccess::matches( '2001:db8::1', '2001:db8::/32' ) );
ok( 'and excludes outside it', ! IpAccess::matches( '2001:db9::1', '2001:db8::/32' ) );
ok( '/64 boundary works', IpAccess::matches( '2001:db8:0:1::5', '2001:db8:0:1::/64' ) );
ok( '/64 excludes the next subnet', ! IpAccess::matches( '2001:db8:0:2::5', '2001:db8:0:1::/64' ) );

// Compared as PACKED BYTES, not strings: these two spellings are the same address, and a
// string comparison would match only one of them.
ok(
	'expanded and compact spellings are one address',
	IpAccess::matches( '2001:0db8:0000:0000:0000:0000:0000:0001', '2001:db8::1' )
);

// The families must never cross — their packed forms are 4 and 16 bytes.
ok( 'IPv4 never matches an IPv6 range', ! IpAccess::matches( '203.0.113.9', '2001:db8::/32' ) );
ok( 'IPv6 never matches an IPv4 range', ! IpAccess::matches( '2001:db8::1', '203.0.113.0/24' ) );

/* -----------------------------------------------------------------------------
 * Validation
 * -------------------------------------------------------------------------- */

echo "=== bad entries are rejected AND reported ===\n";

$GLOBALS['dp_options'] = array();
$result                = IpAccess::save(
	IpAccess::OPTION_ALLOW,
	"203.0.113.9\nnot-an-ip\n198.51.100.0/24\n<script>alert(1)</script>\n10.0.0.0/99"
);

ok( 'valid entries are kept', array( '203.0.113.9', '198.51.100.0/24' ) === $result['stored'] );

// Reported back rather than dropped silently: a typo in an allow list is exactly how a
// working pair locks itself out, and the admin has to be told.
ok( 'three entries were rejected', 3 === count( $result['rejected'] ) );
ok( 'a word is rejected', in_array( 'not-an-ip', $result['rejected'], true ) );
ok( 'markup is rejected', in_array( '<script>alert(1)</script>', $result['rejected'], true ) );
ok( 'an out-of-range prefix is rejected', in_array( '10.0.0.0/99', $result['rejected'], true ) );

// `/0` is refused on input: in a block list it would refuse every request to the site, and
// in an allow list it is a no-op that looks like a restriction. Neither is ever intended.
ok( 'IPv4 /0 is refused', '' === IpAccess::normalize( '0.0.0.0/0' ) );
ok( 'IPv6 /0 is refused', '' === IpAccess::normalize( '::/0' ) );
ok( 'a missing prefix is refused', '' === IpAccess::normalize( '10.0.0.0/' ) );
ok( 'a non-numeric prefix is refused', '' === IpAccess::normalize( '10.0.0.0/abc' ) );

$result = IpAccess::save( IpAccess::OPTION_ALLOW, '203.0.113.9, 198.51.100.7' );
ok( 'comma-separated input is accepted', 2 === count( $result['stored'] ) );

$result = IpAccess::save( IpAccess::OPTION_ALLOW, "203.0.113.9\n203.0.113.9\n203.0.113.9" );
ok( 'duplicates collapse', array( '203.0.113.9' ) === $result['stored'] );

// A cap, so one paste cannot make every single request scan thousands of entries.
$many   = array();
for ( $i = 0; $i <= 400; $i++ ) {
	$many[] = '10.0.' . intdiv( $i, 256 ) . '.' . ( $i % 256 );
}
$result = IpAccess::save( IpAccess::OPTION_ALLOW, implode( "\n", $many ) );
ok( 'the list is capped', count( $result['stored'] ) <= IpAccess::MAX_ENTRIES );

// A hand-edited option must not put an unparseable entry into the matcher.
$GLOBALS['dp_options'][ IpAccess::OPTION_ALLOW ] = array( 'garbage', '203.0.113.9', '../../etc/passwd' );
ok( 'garbage in the stored option is ignored on read', array( '203.0.113.9' ) === IpAccess::allow_list() );

$GLOBALS['dp_options'][ IpAccess::OPTION_ALLOW ] = 'not even an array';
ok( 'a non-array option reads as empty', array() === IpAccess::allow_list() );

echo "=== status(), for the admin screen ===\n";

$GLOBALS['dp_options'] = array();
ok( 'no rules means no status', '' === IpAccess::status( '203.0.113.9' ) );

IpAccess::save( IpAccess::OPTION_ALLOW, '203.0.113.9' );
ok( 'a listed address reads as allowed', IpAccess::RESULT_ALLOWED === IpAccess::status( '203.0.113.9' ) );
ok( 'an unlisted one reads as not-allowed', IpAccess::RESULT_NOT_ALLOWED === IpAccess::status( '198.51.100.7' ) );

IpAccess::save( IpAccess::OPTION_BLOCK, '198.51.100.7' );
ok( 'a blocked address reads as blocked', IpAccess::RESULT_BLOCKED === IpAccess::status( '198.51.100.7' ) );

/* -----------------------------------------------------------------------------
 * Wiring
 * -------------------------------------------------------------------------- */

echo "=== enforced first, and no information leak ===\n";

$verifier = (string) file_get_contents( $root . '/src/Auth/Verifier.php' );

// Before the HMAC, so a refused address costs a byte comparison rather than a hash.
$ip_at  = strpos( $verifier, 'IpAccess::evaluate(' );
$sig_at = strpos( $verifier, 'Signer::sign(' );

ok( 'the rules run before signing', false !== $ip_at && false !== $sig_at && $ip_at < $sig_at );

// Matched on the UNMASKED address, or turning on anonymised logging would silently make
// both lists match nothing.
ok( 'matching uses the unmasked address', false !== strpos( $verifier, 'ClientIp::for_matching()' ) );

// Same message and status for both refusals: telling a caller that an allow list exists, or
// which list caught it, is free reconnaissance.
ok(
	'both refusals share one message',
	2 === substr_count( $verifier, 'This address is not permitted to use the API.' )
);
// …but the error CODES differ, so the log keeps the distinction where it is useful.
ok( 'a block has its own log code', false !== strpos( $verifier, 'ifs_deploy_ip_blocked' ) );
ok( 'a non-allowed address has its own log code', false !== strpos( $verifier, 'ifs_deploy_ip_not_allowed' ) );

// The refusal is inside check(), so verify_request()'s wrapper logs it — which is how an
// admin who mistyped an allow list can see the cause instead of a bare 403.
ok( 'refusals are inside the logged path', $ip_at > strpos( $verifier, 'private static function check(' ) );

echo "=== the admin cannot lock itself out silently ===\n";

$settings = (string) file_get_contents( $root . '/src/Admin/Pages/SettingsPage.php' );

// Offer the addresses actually observed rather than asking the admin to guess.
ok( 'observed addresses are offered', false !== strpos( $settings, 'observed_addresses' ) );
ok( 'they come from the access log', false !== strpos( $settings, 'ApiLog::by_ip(' ) );

// State the current effect in words, not just a count of entries.
ok( 'the current effect is spelled out', false !== strpos( $settings, 'IpAccess::is_configured()' ) );

// Rejected entries are surfaced ahead of any success message.
ok( 'rejected entries are reported', false !== strpos( $settings, 'have NOT been stored' ) );

// The rules must never touch wp-admin, or a mistake here would be unrecoverable.
ok( 'the scope is stated as API-only', false !== strpos( $settings, 'never affects who can log in' ) );

// The interaction with the trusted-header setting is called out — allow-listing the real
// Staging address while REMOTE_ADDR is the CDN blocks everything.
ok( 'the proxy interaction is explained', false !== strpos( $settings, 'Behind a CDN that is the CDN' ) );

$uninstall = (string) file_get_contents( $root . '/uninstall.php' );
ok( 'uninstall removes the allow list', false !== strpos( $uninstall, 'ifs_deploy_ip_allow' ) );
ok( 'uninstall removes the block list', false !== strpos( $uninstall, 'ifs_deploy_ip_block' ) );

echo "=== one-click add / remove, from the Logs screen ===\n";

$GLOBALS['dp_options'] = array();

$r = IpAccess::add( IpAccess::OPTION_ALLOW, '203.0.113.9' );
ok( 'adding the first allowed address works', $r['changed'] );
// That one click restricts the API to a single address, so the message has to say so.
// Adding the SECOND entry is unremarkable; adding the first is not.
ok( 'and warns it is now the ONLY one', false !== strpos( $r['message'], 'ONLY' ) );

$r = IpAccess::add( IpAccess::OPTION_ALLOW, '203.0.113.9' );
ok( 'adding it twice is refused', ! $r['changed'] );
ok( 'and says why', false !== strpos( $r['message'], 'already' ) );

$r = IpAccess::add( IpAccess::OPTION_ALLOW, 'nonsense' );
ok( 'an invalid address is refused', ! $r['changed'] );

$r = IpAccess::add( IpAccess::OPTION_ALLOW, '198.51.100.7' );
ok( 'a second entry is added quietly', $r['changed'] && false === strpos( $r['message'], 'ONLY' ) );

$r = IpAccess::remove( IpAccess::OPTION_ALLOW, '198.51.100.7' );
ok( 'removing works', $r['changed'] );

// Removing the LAST allow entry lifts the restriction entirely — the opposite of what
// "remove" sounds like, so it is stated rather than left to be discovered.
$r = IpAccess::remove( IpAccess::OPTION_ALLOW, '203.0.113.9' );
ok( 'removing the last entry reports the restriction lifting', false !== strpos( $r['message'], 'any address' ) );
ok( 'and the list really is empty', ! IpAccess::is_configured() );

$r = IpAccess::remove( IpAccess::OPTION_BLOCK, '192.0.2.1' );
ok( 'removing something absent is refused', ! $r['changed'] );
// An address covered by a RANGE cannot be removed individually; saying so avoids a
// confusing no-op.
ok( 'and mentions CIDR ranges', false !== strpos( $r['message'], 'CIDR' ) );

$ajax = (string) file_get_contents( $root . '/src/Admin/Ajax.php' );

ok( 'the ajax action is registered', false !== strpos( $ajax, 'wp_ajax_ifs_deploy_mark_ip' ) );
ok( 'and goes through guard()', (bool) preg_match( '/function mark_ip\(\): void \{\s*\$this->guard\(/', $ajax ) );
// Re-validated server-side: this writes a value that is matched on every API request.
ok( 'the address is re-validated server-side', false !== strpos( $ajax, 'IpAccess::normalize( $ip )' ) );
// Changing who may reach the API is itself a security event.
ok( 'the change is audit-logged', (bool) preg_match( '/function mark_ip.*DebugLog::warning/s', $ajax ) );

$logs = (string) file_get_contents( $root . '/src/Admin/Pages/LogsPage.php' );

ok( 'the log offers the buttons', false !== strpos( $logs, 'ifs-deploy-mark-ip' ) );
ok( 'and shows the rule per address', false !== strpos( $logs, 'IpAccess::status(' ) );

echo "=== redirects are not followed ===\n";

$client = (string) file_get_contents( $root . '/src/Client/DeployClient.php' );

// WordPress defaults to `redirection => 5`, and its Requests library re-sends the same
// method, body AND headers — including X-IFS-Deploy-Key — to whatever `Location` names.
// It also turns one signed request into two deliveries carrying the same nonce.
ok( 'redirects are disabled', false !== strpos( $client, "'redirection' => 0" ) );
ok( 'a redirect is reported instead', false !== strpos( $client, 'ifs_deploy_redirected' ) );
ok( 'and logged with its target', false !== strpos( $client, 'Production redirected the request' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
