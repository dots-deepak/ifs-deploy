<?php
/**
 * Protocol v2 — response signing (SECURITY.md H-3) and route binding (M-3).
 *
 * Both change the WIRE FORMAT, which makes the ROLLOUT the dangerous part rather than the
 * cryptography. Two sites are never updated in the same instant, so the suite is built around
 * the four states a real pair passes through:
 *
 *   old Staging → old Production   both v1, must keep working
 *   old Staging → new Production   v1 request accepted, and the reply is STILL signed —
 *                                  that signed reply is the only thing that proves v2
 *   new Staging → old Production   nothing signed, no ratchet, must keep working
 *   new Staging → new Production   route-bound requests, verified replies
 *
 * And the one thing that must be impossible: talking a proven pair back DOWN. A downgrade is
 * the cheapest attack on both halves of this — strip a header and response verification is
 * gone; present a v1 signature and route binding is gone.
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

define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['dp_options']    = array();
$GLOBALS['dp_transients'] = array();

// Staging role keeps ApiLog::record() a no-op — it only writes on the Production receiver, and
// none of the decisions under test depend on it. Verified separately in ipmonitor-test.php.
$GLOBALS['dp_options']['ifs_deploy_role'] = 'staging';

function __( string $t, string $d = '' ): string {
	return $t;
}
function esc_html__( string $t, string $d = '' ): string {
	return $t;
}
function get_option( string $n, $default = false ) {
	return $GLOBALS['dp_options'][ $n ] ?? $default;
}
function update_option( string $n, $v, $a = null ): bool {
	$GLOBALS['dp_options'][ $n ] = $v;

	return true;
}
function delete_option( string $n ): bool {
	unset( $GLOBALS['dp_options'][ $n ] );

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
function delete_transient( string $k ): bool {
	unset( $GLOBALS['dp_transients'][ $k ] );

	return true;
}
function current_time( string $type = 'mysql', $gmt = 0 ) {
	return 'timestamp' === $type ? time() : gmdate( 'Y-m-d H:i:s' );
}
function apply_filters( string $h, $v, ...$args ) {
	return $v;
}
function wp_json_encode( $d, int $f = 0 ) {
	return json_encode( $d, $f );
}
function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( $defaults, (array) $args );
}
function wp_generate_uuid4(): string {
	return '11111111-2222-4333-8444-555555555555';
}
function wp_rand( int $min = 0, int $max = 0 ): int {
	// Never 1, so NonceStore's opportunistic prune does not fire mid-test.
	return $max;
}
function wp_privacy_anonymize_ip( $ip, $f = false ) {
	return preg_replace( '/\.\d+$/', '.0', (string) $ip );
}
function sanitize_text_field( $s ): string {
	return trim( (string) $s );
}
function sanitize_key( $s ): string {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ) ?? '';
}
function wp_unslash( $v ) {
	return is_string( $v ) ? stripslashes( $v ) : $v;
}
function wp_strip_all_tags( $s ): string {
	return strip_tags( (string) $s );
}
function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}

class WP_Error {
	private string $code;
	private string $message;
	private array $data;

	public function __construct( string $code = '', string $message = '', $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = (array) $data;
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_message(): string {
		return $this->message;
	}

	public function get_error_data() {
		return $this->data;
	}
}

/** Only what Verifier reads. Header names are matched the way WordPress matches them. */
class WP_REST_Request {
	private array $headers = array();
	private string $body   = '';
	private string $route  = '';

	public function __construct( string $route, string $body, array $headers ) {
		$this->route = $route;
		$this->body  = $body;

		foreach ( $headers as $name => $value ) {
			$this->headers[ strtolower( (string) $name ) ] = (string) $value;
		}
	}

	public function get_header( $name ) {
		return $this->headers[ strtolower( (string) $name ) ] ?? null;
	}

	public function get_body(): string {
		return $this->body;
	}

	public function get_route(): string {
		return $this->route;
	}
}

// wp_remote_* accessors over a plain array, enough for verify_response().
function wp_remote_retrieve_header( $response, $name ) {
	return $response['headers'][ strtolower( (string) $name ) ] ?? '';
}
function wp_remote_retrieve_response_code( $response ) {
	return $response['status'] ?? 0;
}

// No $wpdb: NonceStore falls back to the transient path, which is enough to prove a nonce is
// claimed once. Its atomic path has its own suite (nonce-test.php).
$GLOBALS['wpdb'] = null;

spl_autoload_register(
	static function ( string $class ) use ( $root ): void {
		if ( 0 !== strpos( $class, 'IfsDeploy' . chr( 92 ) ) ) {
			return;
		}

		$relative = substr( $class, strlen( 'IfsDeploy' ) + 1 );
		$path     = $root . '/src/' . str_replace( chr( 92 ), '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

use IfsDeploy\Auth\Protocol;
use IfsDeploy\Auth\Signer;
use IfsDeploy\Auth\Verifier;
use IfsDeploy\Client\DeployClient;

$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

const SECRET = 'dps_0123456789abcdef0123456789abcdef';
const KEY    = 'dpk_0123456789abcdef';

$GLOBALS['dp_options']['ifs_deploy_credentials'] = array(
	'site_id'    => 'site',
	'api_key'    => KEY,
	'secret_key' => SECRET,
);

/** Build a signed request the way the client does, and hand it to the verifier. */
function signed_request( int $protocol, string $route, string $body, array $overrides = array() ) {
	static $counter = 0;

	$timestamp = (string) time();
	$nonce     = 'nonce-' . ( ++$counter );

	$sign_route = $overrides['sign_route'] ?? $route;
	$nonce      = $overrides['nonce'] ?? $nonce;

	$signature = Protocol::V2 === $protocol
		? Signer::sign_v2( $timestamp, $nonce, $sign_route, $body, SECRET )
		: Signer::sign( $timestamp, $nonce, $body, SECRET );

	return new WP_REST_Request(
		'/ifs-deploy/v1/' . $route,
		$body,
		array(
			Signer::HEADER_KEY       => $overrides['key'] ?? KEY,
			Signer::HEADER_TIMESTAMP => $timestamp,
			Signer::HEADER_NONCE     => $nonce,
			Signer::HEADER_SIGNATURE => $overrides['signature'] ?? $signature,
		)
	);
}

/** Reset the per-request statics the verifier keeps for response signing. */
function reset_verifier(): void {
	foreach ( array( 'request_protocol', 'request_nonce', 'request_stamp' ) as $name ) {
		$property = new ReflectionProperty( Verifier::class, $name );
		$property->setAccessible( true );
		$property->setValue( null, 'request_protocol' === $name ? 0 : '' );
	}
}

/* -----------------------------------------------------------------------------
 * M-3 · the route is in the signed material
 * -------------------------------------------------------------------------- */

echo "=== the two sides derive the same route string from different inputs ===\n";

// The client holds `import`; the server holds `/ifs-deploy/v1/import`. If these ever
// disagreed, every v2 request would fail its signature — so this is the joint the whole
// upgrade turns on.
ok( 'the client form normalises to the slug', 'import' === Signer::normalize_route( 'import' ) );
ok( 'and so does the server form', 'import' === Signer::normalize_route( '/ifs-deploy/v1/import' ) );
ok( 'a leading slash makes no difference', Signer::normalize_route( 'import' ) === Signer::normalize_route( '/import' ) );
ok( 'a trailing slash makes no difference', Signer::normalize_route( 'import' ) === Signer::normalize_route( 'import/' ) );
ok( 'hyphens survive', 'rollback-preview' === Signer::normalize_route( '/ifs-deploy/v1/rollback-preview' ) );
ok( 'and nothing punctuated gets through', 'import' === Signer::normalize_route( 'im!port' ) );

echo "=== a v2 signature is bound to ONE route ===\n";

$body  = '{"items":[1,2,3]}';
$stamp = '1700000000';
$n     = 'fixed-nonce';

$for_index  = Signer::sign_v2( $stamp, $n, 'index', $body, SECRET );
$for_import = Signer::sign_v2( $stamp, $n, 'import', $body, SECRET );

// THE M-3 assertion. Under v1 these two were the same string, so a captured `/index`
// signature was arithmetically valid for `/import` carrying the same body.
ok( 'the same body signed for two routes gives two signatures', $for_index !== $for_import );
ok( 'and it is stable for the same route', $for_index === Signer::sign_v2( $stamp, $n, 'index', $body, SECRET ) );
ok( 'the server-shaped route signs identically', $for_import === Signer::sign_v2( $stamp, $n, '/ifs-deploy/v1/import', $body, SECRET ) );

// v1 and v2 must not collide, or accepting both would let one stand in for the other.
ok( 'a v1 signature differs from a v2 one', Signer::sign( $stamp, $n, $body, SECRET ) !== $for_import );
ok( 'the canonical strings differ too', Signer::canonical( $stamp, $n, $body ) !== Signer::canonical_v2( $stamp, $n, 'import', $body ) );

/* -----------------------------------------------------------------------------
 * H-3 · what a response signature covers
 * -------------------------------------------------------------------------- */

echo "=== a response signature binds the reply to the request that asked for it ===\n";

$data = array( 'ok' => true, 'items' => array( 'a', 'b' ) );

$sig = Signer::sign_response( $stamp, $n, $data, SECRET );

ok( 'it is deterministic', $sig === Signer::sign_response( $stamp, $n, $data, SECRET ) );
// Reusing the request's nonce is what stops a captured reply being replayed as the answer to
// a different request.
ok( 'a different nonce gives a different signature', $sig !== Signer::sign_response( $stamp, 'other-nonce', $data, SECRET ) );
ok( 'a different timestamp too', $sig !== Signer::sign_response( '1700000001', $n, $data, SECRET ) );
ok( 'and one altered value changes it', $sig !== Signer::sign_response( $stamp, $n, array( 'ok' => true, 'items' => array( 'a', 'c' ) ), SECRET ) );
ok( 'a different secret does not verify', $sig !== Signer::sign_response( $stamp, $n, $data, 'dps_wrong' ) );

// Signed over the NORMALISED body, so whitespace and escaping differences in transit — a
// pretty-printer, a proxy re-encoding — cannot break a genuine reply.
$reencoded = json_decode( json_encode( $data, JSON_PRETTY_PRINT ), true );
ok( 'reformatted JSON still verifies', $sig === Signer::sign_response( $stamp, $n, $reencoded, SECRET ) );

/* -----------------------------------------------------------------------------
 * The ratchet
 * -------------------------------------------------------------------------- */

echo "=== the peer capability only goes up ===\n";

unset( $GLOBALS['dp_options'][ 'ifs_deploy_peer_protocol' ] );

// v1 is the safe default: assuming v2 would break the first request to an un-upgraded site.
ok( 'an unknown peer is assumed to be v1', Protocol::V1 === Protocol::peer() );

Protocol::remember_peer_v2();
ok( 'once proven, the peer is v2', Protocol::V2 === Protocol::peer() );

Protocol::remember_peer_v2();
ok( 'proving it twice is harmless', Protocol::V2 === Protocol::peer() );

$protocol_src = (string) file_get_contents( $root . '/src/Auth/Protocol.php' );

// There is deliberately no way to write a DOWNGRADE. If there were, one stripped header would
// return the pair to v1 permanently.
ok( 'nothing writes v1 into the option', false === strpos( $protocol_src, 'update_option( self::OPTION, self::V1' ) );
ok( 'the only write is v2', 1 === substr_count( $protocol_src, 'update_option( self::OPTION' ) );

// A garbage or downgraded option value reads as v1 rather than being trusted verbatim.
$GLOBALS['dp_options']['ifs_deploy_peer_protocol'] = 99;
ok( 'an out-of-range stored value reads as v1', Protocol::V1 === Protocol::peer() );
$GLOBALS['dp_options']['ifs_deploy_peer_protocol'] = 'v2';
ok( 'a non-numeric stored value reads as v1', Protocol::V1 === Protocol::peer() );

Protocol::forget_peer();
ok( 'forgetting returns it to v1', Protocol::V1 === Protocol::peer() );

echo "=== forgetting is an admin action, never a wire-driven one ===\n";

// This is the anti-downgrade control's one escape hatch, so where it can be reached from is
// the whole of its security. Anything on the response path being able to call it would make
// the ratchet decorative.
$callers = array();
$stack   = array( $root . '/src' );

while ( $stack ) {
	$dir = array_pop( $stack );

	foreach ( scandir( $dir ) ?: array() as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}

		$path = $dir . '/' . $entry;

		if ( is_dir( $path ) ) {
			$stack[] = $path;
			continue;
		}

		if ( '.php' !== substr( $path, -4 ) ) {
			continue;
		}

		// Comments stripped: Protocol's own docblock discusses forget_peer().
		if ( false !== strpos( php_strip_whitespace( $path ), 'forget_peer()' ) ) {
			// Both sides slash-normalised: $root comes from dirname(), so on Windows it
			// carries backslashes while the paths built above use forward slashes.
			$callers[] = str_replace( str_replace( chr( 92 ), '/', $root ) . '/', '', str_replace( chr( 92 ), '/', $path ) );
		}
	}
}

sort( $callers );

ok( 'exactly two files mention it in code', 2 === count( $callers ) );
ok( 'its own definition', in_array( 'src/Auth/Protocol.php', $callers, true ) );
ok( 'and the settings save handler', in_array( 'src/Admin/Pages/SettingsPage.php', $callers, true ) );

$settings_src = (string) file_get_contents( $root . '/src/Admin/Pages/SettingsPage.php' );
ok( 'the save path is nonce-checked', false !== strpos( $settings_src, "check_admin_referer( 'ifs_deploy_save_settings' )" ) );
// Re-saving identical values must not reset a proven capability.
ok( 'and only fires on an actual change', (bool) preg_match( '/if \( \$before !== \$after \) \{\s*Protocol::forget_peer\(\);/', $settings_src ) );

/* -----------------------------------------------------------------------------
 * The verifier: both protocols authenticate
 * -------------------------------------------------------------------------- */

echo "=== a v2 request authenticates ===\n";

reset_verifier();
$result = Verifier::verify_request( signed_request( Protocol::V2, 'import', $body ) );
ok( 'v2 is accepted', true === $result );
ok( 'and is recorded as v2 for the reply', Protocol::V2 === ( new ReflectionProperty( Verifier::class, 'request_protocol' ) )->getValue() );

echo "=== a v1 request STILL authenticates ===\n";

// The compatibility guarantee. Rejecting v1 would mean whichever site is updated first stops
// being able to deploy — an outage caused by the upgrade itself.
reset_verifier();
$result = Verifier::verify_request( signed_request( Protocol::V1, 'import', $body ) );
ok( 'v1 is accepted', true === $result );

$context = Verifier::response_context();

// THE BOOTSTRAP. A newly updated Staging site still signs v1 until it has proof, so if a v1
// caller got no signed reply there would BE no proof, and the pair would sit on v1 forever.
ok( 'a v1 caller still gets a signable reply', is_array( $context ) );
ok( 'with the request nonce', isset( $context['nonce'] ) && '' !== $context['nonce'] );
ok( 'and the request timestamp', isset( $context['timestamp'] ) && '' !== $context['timestamp'] );

echo "=== a v2 signature for the wrong route is refused ===\n";

reset_verifier();

// Signed for `index`, delivered to `import` — what M-3 exists to stop. Note that it is not
// silently downgraded to v1 either: the v1 fallback is computed over a canonical string this
// signature never covered.
$result = Verifier::verify_request( signed_request( Protocol::V2, 'import', $body, array( 'sign_route' => 'index' ) ) );

ok( 'a cross-route signature fails', $result instanceof WP_Error );
ok( 'and nothing is recorded for the reply', null === Verifier::response_context() );

echo "=== a rejected request gets no reply signature ===\n";

reset_verifier();
$result = Verifier::verify_request( signed_request( Protocol::V2, 'import', $body, array( 'signature' => str_repeat( 'a', 64 ) ) ) );

ok( 'a forged signature fails', $result instanceof WP_Error );
// L-5: bad key and bad signature are indistinguishable to the caller.
ok( 'and reports nothing specific', 'ifs_deploy_unauthorized' === $result->get_error_code() );
ok( 'there is no context to sign a reply with', null === Verifier::response_context() );

echo "=== replay protection still applies to both ===\n";

reset_verifier();
$replayed = signed_request( Protocol::V2, 'import', $body, array( 'nonce' => 'reused-nonce' ) );
ok( 'the first delivery is accepted', true === Verifier::verify_request( $replayed ) );

reset_verifier();
ok( 'the second is refused', Verifier::verify_request( $replayed ) instanceof WP_Error );

$verifier_src = (string) file_get_contents( $root . '/src/Auth/Verifier.php' );
ok( 'v2 is tried before v1', strpos( $verifier_src, 'Signer::sign_v2(' ) < strpos( $verifier_src, 'Signer::sign( $timestamp' ) );
// Both comparisons go through hash_equals, so trying two is not a timing oracle.
ok( 'both go through the timing-safe compare', 2 === substr_count( $verifier_src, 'Signer::verify( Signer::sign' ) );
ok( 'a legacy signature is noted, not treated as an error', false !== strpos( $verifier_src, 'note_legacy_signature' ) );

/* -----------------------------------------------------------------------------
 * The client: what it accepts back
 * -------------------------------------------------------------------------- */

echo "=== an unsigned reply is fine from a peer that has never signed ===\n";

/** Call the private response check the way post() does. */
function check_response( array $response, array $decoded, string $timestamp, string $nonce, string $route = 'index' ) {
	$method = new ReflectionMethod( DeployClient::class, 'verify_response' );
	$method->setAccessible( true );

	return $method->invoke(
		new DeployClient(),
		$response,
		$decoded,
		$timestamp,
		$nonce,
		SECRET,
		Protocol::peer(),
		$route
	);
}

Protocol::forget_peer();

$reply = array( 'status' => 200, 'headers' => array() );
ok( 'accepted', true === check_response( $reply, array( 'ok' => true ), $stamp, $n ) );
ok( 'and the peer is still v1', Protocol::V1 === Protocol::peer() );

echo "=== a body claiming protocol 2 proves nothing ===\n";

// It is unsigned, therefore forgeable, and the ratchet is permanent — so an on-path attacker
// injecting this in front of a v1 Production would make every later deploy fail a signature
// check until an admin cleared it. Only a signature is believed.
ok( 'accepted', true === check_response( $reply, array( 'ok' => true, 'protocol' => 2 ), $stamp, $n ) );
ok( 'and it did NOT ratchet the peer', Protocol::V1 === Protocol::peer() );

echo "=== a correctly signed reply verifies and proves v2 ===\n";

$payload = array( 'ok' => true, 'items' => array( 'a', 'b' ) );
$signed  = array(
	'status'  => 200,
	'headers' => array(
		strtolower( Protocol::HEADER_RESPONSE_SIGNATURE ) => Signer::sign_response( $stamp, $n, $payload, SECRET ),
	),
);

ok( 'accepted', true === check_response( $signed, $payload, $stamp, $n ) );
ok( 'and the peer is now proven v2', Protocol::V2 === Protocol::peer() );

echo "=== a forged reply is refused ===\n";

$forged = array(
	'status'  => 200,
	'headers' => array(
		strtolower( Protocol::HEADER_RESPONSE_SIGNATURE ) => Signer::sign_response( $stamp, $n, $payload, SECRET ),
	),
);

// Same signature, altered body — an on-path attacker rewriting an `index` reply so Compare &
// Sync shows fabricated state, or a `rollback-preview` so the confirmation dialog lies.
$result = check_response( $forged, array( 'ok' => true, 'items' => array( 'a', 'evil' ) ), $stamp, $n );

ok( 'a tampered body fails', $result instanceof WP_Error );
ok( 'with its own error code', 'ifs_deploy_bad_response_signature' === $result->get_error_code() );

echo "=== once a peer has signed, an unsigned 200 is a downgrade ===\n";

ok( 'the peer is still proven', Protocol::V2 === Protocol::peer() );

$result = check_response( array( 'status' => 200, 'headers' => array() ), array( 'ok' => true ), $stamp, $n );

// Stripping the header is the cheapest possible attack on H-3, so after one signed reply a
// missing signature is refused rather than read as "an old peer".
ok( 'an unsigned success is refused', $result instanceof WP_Error );
ok( 'and says so plainly', 'ifs_deploy_response_unsigned' === $result->get_error_code() );

echo "=== but an unsigned FAILURE still surfaces its real reason ===\n";

// A response signature is keyed to a verified request, so a rejection from the verifier
// itself cannot carry one. Refusing those would replace "Authentication failed" with a
// confusing downgrade error at the exact moment the owner needs the real one — right after a
// credential rotation.
foreach ( array( 401, 403, 429, 409 ) as $status ) {
	$result = check_response(
		array( 'status' => $status, 'headers' => array() ),
		array( 'code' => 'ifs_deploy_unauthorized' ),
		$stamp,
		$n
	);

	ok( "an unsigned $status is passed through", true === $result );
}

ok( 'and the peer is still v2 afterwards', Protocol::V2 === Protocol::peer() );

/* -----------------------------------------------------------------------------
 * Rollout wiring
 * -------------------------------------------------------------------------- */

echo "=== the client only signs v2 once the peer is proven ===\n";

$client_src = (string) file_get_contents( $root . '/src/Client/DeployClient.php' );

ok( 'the choice is made from the stored capability', false !== strpos( $client_src, '$protocol = Protocol::peer();' ) );
ok( 'v2 when proven', (bool) preg_match( '/Protocol::V2 === \$protocol\s*\?\s*Signer::sign_v2\(/', $client_src ) );
ok( 'v1 otherwise', (bool) preg_match( '/:\s*Signer::sign\( \$timestamp, \$nonce, \$body/', $client_src ) );
ok( 'the protocol is advertised in a header', false !== strpos( $client_src, 'Protocol::HEADER_PROTOCOL => (string) $protocol' ) );
// Verified BEFORE anything reads the body, or a forged reply would already have been acted on.
ok( 'the reply is checked before it is used', strpos( $client_src, '$check = $this->verify_response(' ) < strpos( $client_src, "'body'   => is_array( \$decoded )" ) );

echo "=== the server signs its replies ===\n";

$rest_src = (string) file_get_contents( $root . '/src/Rest/RestController.php' );

ok( 'hooked on rest_post_dispatch', false !== strpos( $rest_src, "add_filter( 'rest_post_dispatch'" ) );
// Late, so anything else that rewrites the payload — context filtering included — has already
// run; the signature must cover what is actually sent.
ok( 'at a late priority', false !== strpos( $rest_src, "'sign_response' ), 99, 3" ) );
ok( 'and only for our own namespace', false !== strpos( $rest_src, "'/' . self::NAMESPACE . '/'" ) );

$ping_src = (string) file_get_contents( $root . '/src/Rest/PingEndpoint.php' );
ok( 'ping reports the protocol for diagnostics', false !== strpos( $ping_src, "'protocol' => Protocol::CURRENT" ) );

$uninstall = (string) file_get_contents( $root . '/uninstall.php' );
ok( 'uninstall removes the remembered capability', false !== strpos( $uninstall, 'ifs_deploy_peer_protocol' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
