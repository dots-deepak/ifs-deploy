<?php
/**
 * API access monitoring: address resolution, and the log's own guard rails.
 *
 * The assertions that matter most here are the NEGATIVE ones. A monitor that can be fed a
 * fake address, or that an attacker can inflate at will, is worse than no monitor — it
 * reports confidently and wrongly. Those two properties are what this suite pins.
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

define( 'DAY_IN_SECONDS', 86400 );

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
function wp_unslash( $v ) {
	return $v;
}
function sanitize_text_field( $s ): string {
	return trim( strip_tags( (string) $s ) );
}
function current_time( string $type = 'mysql', $gmt = 0 ) {
	return 'timestamp' === $type ? time() : gmdate( 'Y-m-d H:i:s' );
}
function wp_json_encode( $d, int $f = 0 ) {
	return json_encode( $d, $f );
}
function get_transient( string $k ) {
	return $GLOBALS['dp_transients'][ $k ] ?? false;
}
function set_transient( string $k, $v, int $ttl = 0 ): bool {
	$GLOBALS['dp_transients'][ $k ] = $v;

	return true;
}

/** Core's own anonymiser, reimplemented only as far as this suite exercises it. */
function wp_privacy_anonymize_ip( $ip, $ipv6_fallback = false ) {
	if ( false !== strpos( (string) $ip, ':' ) ) {
		return '::';
	}

	$parts = explode( '.', (string) $ip );

	return 4 === count( $parts ) ? $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0' : '0.0.0.0';
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

use IfsDeploy\Support\ApiLog;
use IfsDeploy\Support\ClientIp;

/** Reset $_SERVER to a known state between cases. */
function serve( array $server ): void {
	$_SERVER = $server;
}

/* -----------------------------------------------------------------------------
 * The address is REMOTE_ADDR unless the owner said otherwise
 * -------------------------------------------------------------------------- */

echo "=== CRITICAL: forwarded headers are not trusted by default ===\n";

$GLOBALS['dp_options'] = array();

serve(
	array(
		'REMOTE_ADDR'          => '203.0.113.9',
		'HTTP_X_FORWARDED_FOR' => '198.51.100.7',
	)
);

$who = ClientIp::resolve();

// THE key assertion. X-Forwarded-For is a request header — anyone can send any value.
// Honouring it by default would let the caller choose what appears in the security log,
// and would bypass every per-address count built on top of it.
ok( 'a spoofed X-Forwarded-For is IGNORED', '203.0.113.9' === $who['ip'] );
ok( 'the address is marked untrusted-source', false === $who['trusted'] );

// …but the attempt is still recorded, so it is visible rather than silently discarded.
ok( 'the spoofed value is still captured for the record', '198.51.100.7' === $who['forwarded'] );
ok( 'the connecting address is recorded separately', '203.0.113.9' === $who['remote_addr'] );

echo "=== a forwarded header is honoured ONLY when configured ===\n";

ClientIp::set_trusted_header( 'HTTP_X_FORWARDED_FOR' );
$who = ClientIp::resolve();

ok( 'now the header wins', '198.51.100.7' === $who['ip'] );
ok( 'and it is flagged as coming from a header', true === $who['trusted'] );

// X-Forwarded-For is a chain, client first. Everything after the first entry was appended
// by intermediaries, so the leftmost is the one to attribute.
serve(
	array(
		'REMOTE_ADDR'          => '10.0.0.1',
		'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 10.0.0.5, 10.0.0.6',
	)
);
ok( 'the leftmost entry of the chain is used', '198.51.100.7' === ClientIp::resolve()['ip'] );

// An arbitrary header name must not be selectable, or a misconfiguration could point this
// at something with no proxy in front of it at all.
ClientIp::set_trusted_header( 'HTTP_X_ATTACKER_CHOICE' );
ok( 'an unlisted header name is refused', '' === ClientIp::trusted_header() );

$GLOBALS['dp_options'][ ClientIp::OPTION_HEADER ] = 'HTTP_EVIL';
ok( 'a hand-edited option is refused too', '' === ClientIp::trusted_header() );

echo "=== the stored value is always a real address ===\n";

$GLOBALS['dp_options'] = array();

// The header is attacker input and is about to be stored and rendered in the admin.
foreach (
	array(
		'not-an-ip'                    => 'a word',
		'<script>alert(1)</script>'    => 'markup',
		'999.999.999.999'              => 'an out-of-range quad',
		'127.0.0.1; DROP TABLE x'      => 'an injection attempt',
	) as $bad => $label
) {
	serve( array( 'REMOTE_ADDR' => $bad ) );
	ok( "rejects $label", '' === ClientIp::resolve()['ip'] );
}

serve( array( 'REMOTE_ADDR' => '2001:db8::dead:beef' ) );
ok( 'accepts a valid IPv6 address', '2001:db8::dead:beef' === ClientIp::resolve()['ip'] );

// A forwarded chain is reduced to valid addresses only, and length-capped.
serve(
	array(
		'REMOTE_ADDR'          => '203.0.113.9',
		'HTTP_X_FORWARDED_FOR' => 'junk, 198.51.100.7, <script>, 10.0.0.5',
	)
);
$forwarded = ClientIp::resolve()['forwarded'];
ok( 'junk is stripped from the recorded chain', '198.51.100.7, 10.0.0.5' === $forwarded );

serve(
	array(
		'REMOTE_ADDR'          => '203.0.113.9',
		'HTTP_X_FORWARDED_FOR' => implode( ', ', array_fill( 0, 60, '198.51.100.7' ) ),
	)
);
ok( 'an over-long chain is capped', substr_count( ClientIp::resolve()['forwarded'], '198.51.100.7' ) <= 10 );

echo "=== GDPR: addresses can be stored anonymised ===\n";

$GLOBALS['dp_options'] = array();
serve( array( 'REMOTE_ADDR' => '203.0.113.9' ) );

ok( 'anonymising is off by default', ! ClientIp::anonymises() );
ok( 'the full address is stored', '203.0.113.9' === ClientIp::resolve()['ip'] );

$GLOBALS['dp_options'][ ClientIp::OPTION_ANONYMISE ] = true;
$who = ClientIp::resolve();

ok( 'anonymising masks the last octet', '203.0.113.0' === $who['ip'] );
ok( 'and applies to the connecting address too', '203.0.113.0' === $who['remote_addr'] );
// Still enough to attribute a burst to one source, which is the point of keeping any of it.
ok( 'the network is still identifiable', 0 === strpos( $who['ip'], '203.0.113.' ) );

/* -----------------------------------------------------------------------------
 * The log cannot become the vulnerability
 * -------------------------------------------------------------------------- */

echo "=== the access log has guard rails ===\n";

$api_src = (string) file_get_contents( $root . '/src/Support/ApiLog.php' );

// Only the receiving side has inbound requests to monitor. Logging on Staging would be
// noise, and would write rows on a site that never receives anything.
ok( 'logging is limited to the Production role', false !== strpos( $api_src, 'Config::is_production()' ) );

// Anyone can hit the endpoint, so one row per attempt would let the attacker choose how
// big this table gets. Repeat failures collapse into one row instead.
ok( 'repeat failures are collapsed, not inserted', false !== strpos( $api_src, 'BURST_WINDOW' ) );
ok( 'the collapse updates the existing row', (bool) preg_match( '/\$wpdb->update\(\s*\$table/', $api_src ) );

// Likewise the alert: a sustained attack must not flood the 200-entry event log and push
// out everything else.
ok( 'the suspicious alert is rate-limited', false !== strpos( $api_src, 'dp_ip_flagged_' ) );
ok( 'via a transient marker', false !== strpos( $api_src, 'set_transient(' ) );

// Requirement: a first sighting raises its own event rather than only setting a flag.
ok( 'a new address raises a log entry', (bool) preg_match( '/is_new.*DebugLog::warning/s', $api_src ) );
ok( 'a failure burst raises an error entry', false !== strpos( $api_src, 'DebugLog::error' ) );

// Attacker-controlled free text that gets rendered in the admin.
ok( 'the user agent is sanitised', false !== strpos( $api_src, 'sanitize_text_field( wp_unslash( (string) $_SERVER[\'HTTP_USER_AGENT\'] ) )' ) );
ok( 'and length-capped', (bool) preg_match( '/substr\([^)]*HTTP_USER_AGENT[^)]*\)[^;]*,\s*0,\s*255\s*\)/s', $api_src ) || false !== strpos( $api_src, ', 0, 255 )' ) );

// Every query is parameterised; the only interpolation is the table identifier.
ok( 'queries use prepare()', substr_count( $api_src, '$wpdb->prepare(' ) >= 5 );
ok(
	'no request data is interpolated into SQL',
	! preg_match( '/"[^"]*(SELECT|INSERT|UPDATE|DELETE)[^"]*\{\$(?!table|wpdb)[a-z_]+\}/i', $api_src )
);

echo "=== the log is wired into the one choke point ===\n";

$verifier = (string) file_get_contents( $root . '/src/Auth/Verifier.php' );

// verify_request() wraps check() so no return path inside it can escape the log. An
// unlogged rejection path is exactly the one an attacker probes.
ok( 'verify_request delegates to check()', false !== strpos( $verifier, 'self::check( $request )' ) );
ok( 'and records every outcome', false !== strpos( $verifier, 'ApiLog::record(' ) );
ok( 'the recording is outside check()', strpos( $verifier, 'ApiLog::record(' ) < strpos( $verifier, 'private static function check(' ) );

// The route comes from the matched route, never from user input — it is stored and
// rendered, so a request-controlled value would be an injection point.
ok( 'the route comes from get_route()', false !== strpos( $verifier, '$request->get_route()' ) );
ok( 'and is reduced to a safe slug', false !== strpos( $verifier, "preg_replace( '/[^a-z0-9\\-_]/i'" ) );

echo "=== retention and cleanup cover the new table ===\n";

$retention = (string) file_get_contents( $root . '/src/Support/LogRetention.php' );
ok( 'the api log is purged on the same schedule', false !== strpos( $retention, 'ApiLog::delete_before' ) );

$schema = (string) file_get_contents( $root . '/src/Support/Schema.php' );
ok( 'the table is declared in the schema', false !== strpos( $schema, 'ifs_deploy_api_log' ) );
ok( 'it is indexed by address and time', false !== strpos( $schema, 'KEY ip_time (ip,created_at)' ) );
ok( 'and by time alone for the recent tail', false !== strpos( $schema, 'KEY created_at (created_at)' ) );
// varchar(45) is the minimum that holds a full IPv6 address.
ok( 'the ip column fits IPv6', false !== strpos( $schema, "ip varchar(45)" ) );

$boot = (string) file_get_contents( $root . '/ifs-deploy.php' );
// The api_log table arrived at v4; the nonce table (H-1a) took it to v5. Asserted as
// "at least 4" so adding a later table does not fail a test about this one.
ok( 'the schema version is v4 or later', (bool) preg_match( "/IFS_DEPLOY_DB_VERSION', '([4-9]|\d\d)'/", $boot ) );

$uninstall = (string) file_get_contents( $root . '/uninstall.php' );
ok( 'uninstall drops the table', false !== strpos( $uninstall, 'ifs_deploy_api_log' ) );
ok( 'uninstall removes the trusted-header option', false !== strpos( $uninstall, 'ifs_deploy_trusted_ip_header' ) );
ok( 'uninstall removes the anonymise option', false !== strpos( $uninstall, 'ifs_deploy_anonymise_ips' ) );

echo "=== the admin surface ===\n";

$logs = (string) file_get_contents( $root . '/src/Admin/Pages/LogsPage.php' );
ok( 'Logs & Diagnostics renders the section', false !== strpos( $logs, 'api_access()' ) );
ok( 'it shows a per-address summary', false !== strpos( $logs, 'ApiLog::by_ip(' ) );
ok( 'it shows the per-request tail', false !== strpos( $logs, 'ApiLog::recent(' ) );
ok( 'new addresses are badged', false !== strpos( $logs, 'is_new_ip' ) );
ok( 'suspicious addresses are badged', false !== strpos( $logs, 'Suspicious' ) );
// The honesty warnings — without these the numbers can be read as meaning more than they do.
ok( 'it warns when a trusted header is configured', false !== strpos( $logs, 'trusted_header_notice' ) );
ok( 'the user agent is escaped at output', false !== strpos( $logs, 'esc_html( \'\' !== (string) $row->user_agent' ) );

$ajax = (string) file_get_contents( $root . '/src/Admin/Ajax.php' );
ok( 'clearing the api log is a guarded ajax action', false !== strpos( $ajax, 'wp_ajax_ifs_deploy_clear_api_log' ) );
ok( 'and goes through guard()', (bool) preg_match( '/function clear_api_log\(\): void \{\s*\$this->guard\(/', $ajax ) );
// Separate from the event log on purpose: wiping the security record as a side effect of
// tidying diagnostics would be a nasty surprise.
ok( 'it is separate from clearing the event log', false !== strpos( $ajax, 'function clear_log(): void' ) && false !== strpos( $ajax, 'function clear_api_log(): void' ) );

echo "=== a duplicate DELIVERY is not reported as an attack ===\n";
//
// Reported symptom: reloading the Staging admin once produced a `signatures` request that
// was ACCEPTED and then "REPLAY REFUSED (409)". Both entries are real, and the 409 is
// correct — but calling ordinary transport noise a replay made it look like an intrusion.
//
// The diagnosis that matters: two separate calls would carry two separate nonces
// (wp_generate_uuid4) and BOTH would be accepted. An identical nonce arriving twice can
// only mean one HTTP request delivered twice — a followed redirect or a proxy retry, not a
// code path running twice.
$verifier = (string) file_get_contents( $root . '/src/Auth/Verifier.php' );

ok( 'a short-window repeat gets its own code', false !== strpos( $verifier, 'ifs_deploy_duplicate' ) );
ok( 'a later repeat is still a replay', false !== strpos( $verifier, 'ifs_deploy_replay' ) );
ok( 'the two are separated by elapsed time', false !== strpos( $verifier, 'DUPLICATE_WINDOW' ) );
// Still refused — re-running an import is exactly what must not happen.
ok( 'a duplicate is still refused', (bool) preg_match( '/ifs_deploy_duplicate.*?409/s', $verifier ) );
// Explained once, with what to check, rather than once per retry.
ok( 'the cause is explained in the log', false !== strpos( $verifier, 'transport duplicate, not an attack' ) );
ok( 'and that explanation is rate-limited', false !== strpos( $verifier, 'dp_dup_noted' ) );

$store = (string) file_get_contents( $root . '/src/Auth/NonceStore.php' );
// The acceptance TIME is what separates a transport duplicate from a deliberate replay, so
// it has to be stored — a bare "seen" flag could not tell them apart.
ok( 'the acceptance time is recorded', false !== strpos( $store, "'created_at' => gmdate" ) );
ok( 'and comes back from claim()', false !== strpos( $store, "'first_seen' => (int) strtotime(" ) );
ok( 'the fallback path records it too', false !== strpos( $store, 'set_transient( $key, time()' ) );

$logs = (string) file_get_contents( $root . '/src/Admin/Pages/LogsPage.php' );
ok( 'the log labels it neutrally', false !== strpos( $logs, 'Duplicate suppressed' ) );
// Red made every proxy retry look like an intrusion.
ok( 'and does not paint it red', false !== strpos( $logs, "\$duplicate ? 'rolledback' : 'failed'" ) );

echo "=== the log reads chronologically ===\n";

$api = (string) file_get_contents( $root . '/src/Support/ApiLog.php' );

// record() collapses a failure burst by UPDATING an existing row's created_at, so id no
// longer implies age. Ordering by id put bumped rows back where they were inserted and the
// table came out visibly out of order — 06:04:24 above 06:04:21 above 06:04:24 again.
ok( 'recent() orders by time, not id', false !== strpos( $api, 'ORDER BY created_at DESC, id DESC' ) );
ok( 'id remains the tie-break', false !== strpos( $api, 'created_at DESC, id DESC' ) );

echo "=== an allow-listed address is not announced as suspicious ===\n";

// Reported symptom: an address the admin had explicitly ALLOWED still sat in the event log
// as a standing WARNING. Announcing an approved address as news is how a log becomes
// something people stop reading.
ok( 'the first sighting checks the allow list', false !== strpos( $api, 'IpAccess::is_configured() && IpAccess::matches_any' ) );
ok( 'an expected address is info, not warning', (bool) preg_match( '/\$expected \)\s*\{\s*DebugLog::info/s', $api ) );
ok( 'an unexpected one is still a warning', (bool) preg_match( '/\} else \{\s*DebugLog::warning/s', $api ) );

// And allowing an address RESOLVES the warning it caused, rather than leaving it standing.
$ajax = (string) file_get_contents( $root . '/src/Admin/Ajax.php' );
ok( 'allowing an address clears its warning', false !== strpos( $ajax, "DebugLog::forget( 'new address: '" ) );

$debug = (string) file_get_contents( $root . '/src/Support/DebugLog.php' );
ok( 'DebugLog can forget acknowledged entries', false !== strpos( $debug, 'function forget(' ) );
ok( 'scoped to one level so unrelated entries survive', false !== strpos( $debug, 'string $level' ) );

/* -----------------------------------------------------------------------------
 * Duplicate deliveries are not counted, and not listed by default
 *
 * Reported symptom: the access log filled with "Duplicate suppressed" rows, which a team
 * reads as something being wrong. Two separate problems behind it — the rows themselves, and
 * the fact that they were counted as FAILURES, which inflated the summary and tripped the
 * suspicious-address flag against the site's own paired Staging server.
 * -------------------------------------------------------------------------- */

echo "=== a transport duplicate is not a failure ===\n";

ok( 'a clean request is not a failure', false === ApiLog::is_failure( ApiLog::OK ) );
// The whole point: it had already passed signature, key and timestamp. Only the replay store
// refused it, and correctly — nothing ran twice.
ok( 'nor is a duplicate delivery', false === ApiLog::is_failure( ApiLog::DUPLICATE ) );

// Everything that IS an authentication problem still counts, and so does a deliberate replay.
foreach ( array( 'ifs_deploy_replay', 'ifs_deploy_bad_signature', 'ifs_deploy_bad_key', 'ifs_deploy_missing_auth', 'ifs_deploy_expired', 'ifs_deploy_ip_blocked', 'ifs_deploy_locked_out' ) as $code ) {
	ok( "$code still counts as a failure", true === ApiLog::is_failure( $code ) );
}

echo "=== the counting queries agree with is_failure() ===\n";

// Three queries decide what the admin sees and what raises an alert. If any of them still
// used `outcome <> 'ok'`, the fix above would be contradicted by the numbers on screen.
ok( 'the suspicion count excludes both', (bool) preg_match( '/COUNT\(\*\) FROM \{\$table\} WHERE ip = %s AND outcome NOT IN \( %s, %s \)/', $api ) );
ok( 'the per-address failure total excludes both', 2 === substr_count( $api, 'CASE WHEN outcome NOT IN ( %s, %s )' ) );
ok( 'and nothing compares against ok alone any more', false === strpos( $api, 'outcome <> %s' ) );

echo "=== duplicate rows are off by default, and switchable ===\n";

unset( $GLOBALS['dp_options'][ ApiLog::OPTION_LOG_DUPLICATES ] );
ok( 'off by default', false === ApiLog::logs_duplicates() );

ApiLog::set_logs_duplicates( true );
ok( 'can be turned on', true === ApiLog::logs_duplicates() );

ApiLog::set_logs_duplicates( false );
ok( 'and back off', false === ApiLog::logs_duplicates() );

echo "=== record() drops a duplicate from a known address ===\n";

/** Just enough $wpdb for record(): one indexed lookup, then an insert. */
class DP_ApiLog_WPDB {
	public string $prefix = 'wp_';

	/** Rows written by record(). */
	public array $rows = array();

	/** Addresses record() should treat as already seen. */
	public array $known = array();

	private array $last_args = array();

	public function prepare( string $sql, ...$args ): string {
		$this->last_args = $args;

		return $sql;
	}

	public function get_var( $query ) {
		$sql = (string) $query;

		// The burst-collapse lookup. Both it and is_new_ip() end in LIMIT 1, so the ORDER BY
		// is what separates them — matching on LIMIT 1 alone made every failure look like a
		// burst repeat and nothing was ever inserted.
		if ( false !== strpos( $sql, 'ORDER BY id DESC' ) ) {
			return 0;
		}

		// is_new_ip(): "SELECT id … WHERE ip = %s LIMIT 1"
		if ( false !== strpos( $sql, 'LIMIT 1' ) ) {
			return in_array( (string) ( $this->last_args[0] ?? '' ), $this->known, true ) ? 1 : null;
		}

		// recent_failures()
		return 0;
	}

	public function insert( $table, $data, $format = null ) {
		$this->rows[] = $data;

		return 1;
	}

	public function update( $table, $data, $where, $f = null, $wf = null ) {
		return 1;
	}

	public function get_results( $query ) {
		return array();
	}
}

// Earlier cases left anonymising on and a trusted header configured, either of which changes
// the address record() resolves — and then `known` below would not match it.
unset( $GLOBALS['dp_options'][ ClientIp::OPTION_ANONYMISE ] );
unset( $GLOBALS['dp_options']['ifs_deploy_trusted_ip_header'] );

$GLOBALS['dp_options']['ifs_deploy_role'] = 'production';
$GLOBALS['dp_transients']                  = array();

serve( array( 'REMOTE_ADDR' => '203.0.113.9', 'REQUEST_METHOD' => 'POST' ) );

$db              = new DP_ApiLog_WPDB();
$db->known       = array( '203.0.113.9' );
$GLOBALS['wpdb'] = $db;

ApiLog::record( 'signatures', ApiLog::OK, 200 );
ok( 'an accepted request is recorded', 1 === count( $db->rows ) );

ApiLog::record( 'signatures', ApiLog::DUPLICATE, 409 );
// THE assertion behind the report. The event is already on the table once; the second
// delivery of the same request is not news.
ok( 'the duplicate that follows it is not', 1 === count( $db->rows ) );

ApiLog::record( 'signatures', 'ifs_deploy_replay', 409 );
ok( 'but a deliberate replay still is', 2 === count( $db->rows ) );
ok( 'and it is recorded as a replay, not a duplicate', 'ifs_deploy_replay' === $db->rows[1]['outcome'] );

ApiLog::set_logs_duplicates( true );
ApiLog::record( 'signatures', ApiLog::DUPLICATE, 409 );
ok( 'with the setting on, duplicates are recorded', 3 === count( $db->rows ) );
ApiLog::set_logs_duplicates( false );

echo "=== but a duplicate from an UNKNOWN address is always recorded ===\n";

// is_new_ip() is answered from this table, so silently dropping a first sighting would let an
// address that only ever produced duplicates never appear at all — the one thing this log
// exists to make impossible.
$db->rows  = array();
$db->known = array();

serve( array( 'REMOTE_ADDR' => '198.51.100.77', 'REQUEST_METHOD' => 'POST' ) );

ApiLog::record( 'import', ApiLog::DUPLICATE, 409 );

ok( 'a first sighting is never dropped', 1 === count( $db->rows ) );
ok( 'and is flagged as new', 1 === (int) $db->rows[0]['is_new_ip'] );

echo "=== the omission is stated on the screen ===\n";

// A log that quietly omits rows is worse than a noisy one.
ok( 'the screen says duplicates are not listed', false !== strpos( $logs, 'are not listed' ) );
ok( 'and names the setting that shows them', false !== strpos( $logs, 'Duplicate deliveries' ) );
ok( 'only when they are actually hidden', false !== strpos( $logs, '! ApiLog::logs_duplicates()' ) );
ok( 'the label is the shared constant, not a literal', false !== strpos( $logs, 'ApiLog::DUPLICATE           =>' ) );

$settings = (string) file_get_contents( $root . '/src/Admin/Pages/SettingsPage.php' );
ok( 'the toggle exists on the log settings form', false !== strpos( $settings, "'log_duplicates'" ) );
ok( 'and is saved with the rest of them', false !== strpos( $settings, 'ApiLog::set_logs_duplicates(' ) );
// Explaining why hiding them is safe is the point; a bare checkbox would not be.
ok( 'the copy says a replay is still always listed', false !== strpos( $settings, 'is a different result' ) );

$uninstall_src = (string) file_get_contents( $root . '/uninstall.php' );
ok( 'uninstall removes the setting', false !== strpos( $uninstall_src, 'ifs_deploy_log_duplicates' ) );

$verifier_src = (string) file_get_contents( $root . '/src/Auth/Verifier.php' );
// Nothing about the REFUSAL changed — only whether it gets a row. This is the assertion that
// says the security behaviour is untouched.
ok( 'a duplicate is still refused with 409', (bool) preg_match( '/ifs_deploy_duplicate.*?409/s', $verifier_src ) );
ok( 'and the cause is still explained hourly', false !== strpos( $verifier_src, 'dp_dup_noted' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
