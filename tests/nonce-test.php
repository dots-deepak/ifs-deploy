<?php
/**
 * Replay-nonce store — SECURITY.md H-1a.
 *
 * The table exists to make the check ATOMIC. Two things therefore matter more than the
 * happy path, and both are about a failed INSERT:
 *
 *   1. A failed insert must not be believed on its own. It is also what a missing table or a
 *      dropped connection looks like, and treating those as replays would refuse EVERY
 *      request — a total outage caused by the security feature itself.
 *   2. When the table really is unusable, the store must FAIL OPEN to the old transient
 *      path, so replay protection degrades instead of disappearing.
 *
 * The $wpdb stub below can be told to behave as each of those, which is the only way to
 * cover them without breaking a real database.
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

$GLOBALS['dp_transients'] = array();
$GLOBALS['dp_log']        = array();

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
function delete_transient( string $k ): bool {
	unset( $GLOBALS['dp_transients'][ $k ] );

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
function wp_rand( int $min = 0, int $max = 0 ): int {
	// Deterministic, and never 1 — so the opportunistic prune does not fire mid-test and
	// delete rows the assertions are about.
	return $max;
}

/**
 * A $wpdb that behaves like a real one for a UNIQUE-indexed table, and can be told to fail
 * in the two ways that matter.
 */
class DP_Nonce_WPDB {
	public string $prefix = 'wp_';

	/** nonce_hash => created_at */
	public array $rows = array();

	/** 'ok' | 'missing_table' | 'connection_lost' */
	public string $mode = 'ok';

	public array $queries = array();

	private string $last_sql = '';
	private array $last_args = array();

	public function suppress_errors( $suppress = true ) {
		return false;
	}

	public function insert( $table, $data, $format = null ) {
		if ( 'ok' !== $this->mode ) {
			// Both failure modes look identical from here — which is the entire point.
			return false;
		}

		$hash = (string) $data['nonce_hash'];

		// The UNIQUE index: a second insert of the same key fails.
		if ( array_key_exists( $hash, $this->rows ) ) {
			return false;
		}

		$this->rows[ $hash ] = (string) $data['created_at'];

		return 1;
	}

	public function prepare( string $sql, ...$args ): string {
		$this->last_sql  = $sql;
		$this->last_args = $args;

		return $sql;
	}

	public function get_var( $query ) {
		// A missing table cannot answer the confirming SELECT either.
		if ( 'missing_table' === $this->mode || 'connection_lost' === $this->mode ) {
			return null;
		}

		$hash = (string) ( $this->last_args[0] ?? '' );

		return $this->rows[ $hash ] ?? null;
	}

	public function query( $q ) {
		$this->queries[] = $q;

		return 0;
	}
}

// A separate name on purpose: at top level $wpdb IS $GLOBALS['wpdb'], so the
// "no $wpdb at all" case below would null this handle as well and lose it.
$db              = new DP_Nonce_WPDB();
$GLOBALS['wpdb'] = $db;

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

use IfsDeploy\Auth\NonceStore;
use IfsDeploy\Support\Config;

/* -----------------------------------------------------------------------------
 * The happy path
 * -------------------------------------------------------------------------- */

echo "=== a nonce can be claimed exactly once ===\n";

$db->mode = 'ok';
$db->rows = array();
$nonce      = 'f0336546-b798-4a78-9adf-361aeb7d0bfe';

$first = NonceStore::claim( $nonce );
ok( 'the first claim succeeds', true === $first['fresh'] );
ok( 'and reports no earlier sighting', 0 === $first['first_seen'] );

$second = NonceStore::claim( $nonce );
ok( 'the second claim is refused', false === $second['fresh'] );
// The acceptance time is what lets the caller tell a transport duplicate from a deliberate
// replay, so it has to come back.
ok( 'and reports when the original was accepted', $second['first_seen'] > 0 );
ok( 'the recorded time is not in the future', $second['first_seen'] <= time() + 1 );

ok( 'a different nonce is unaffected', true === NonceStore::claim( 'some-other-nonce' )['fresh'] );
ok( 'the table holds one row per nonce', 2 === count( $db->rows ) );

echo "=== the row outlives the timestamp window in both directions ===\n";

// The window is applied to |now - timestamp|, so a request may legitimately arrive with a
// timestamp up to WINDOW seconds in the FUTURE. An entry expiring after only WINDOW could
// lapse while that request was still replayable.
$store_src = (string) file_get_contents( $root . '/src/Auth/NonceStore.php' );
ok( 'the ttl is twice the window plus a margin', false !== strpos( $store_src, '( 2 * Config::TIMESTAMP_WINDOW ) + 60' ) );
ok( 'and an expiry is stored per row', false !== strpos( $store_src, "'expires_at'" ) );

echo "=== an unusable nonce fails CLOSED ===\n";

// Not a database problem — a malformed request. Refusing costs nothing legitimate, whereas
// letting it through would mean no replay protection for that request at all.
ok( 'an empty nonce is refused', false === NonceStore::claim( '' )['fresh'] );
ok( 'an over-long nonce is refused', false === NonceStore::claim( str_repeat( 'a', 500 ) )['fresh'] );
ok( 'and neither reached the table', 2 === count( $db->rows ) );

/* -----------------------------------------------------------------------------
 * The failure modes — the reason this suite exists
 * -------------------------------------------------------------------------- */

echo "=== a failed INSERT is not believed on its own ===\n";

$db->mode               = 'missing_table';
$db->rows               = array();
$GLOBALS['dp_transients'] = array();

$claim = NonceStore::claim( 'nonce-with-no-table' );

// THE assertion. If a failed insert were read as "already seen", every single request would
// be refused and the pair would stop deploying entirely — an outage caused by the security
// feature. It must fall open instead.
ok( 'a missing table does NOT refuse the request', true === $claim['fresh'] );

// …and it degrades to the transient path rather than losing protection altogether.
$key = 'dp_nonce_' . hash( 'sha256', 'nonce-with-no-table' );
ok( 'it fell back to the transient store', isset( $GLOBALS['dp_transients'][ $key ] ) );

// The fallback still detects a repeat, just without atomicity.
ok( 'the fallback still catches a repeat', false === NonceStore::claim( 'nonce-with-no-table' )['fresh'] );

echo "=== the degraded state is reported, once ===\n";

// Worth knowing about; not worth one line per request while it lasts.
ok( 'the fallback is logged', false !== strpos( $store_src, 'note_fallback' ) );
ok( 'rate-limited to once an hour', false !== strpos( $store_src, 'dp_nonce_fallback_noted' ) );
ok( 'and says how to fix it', false !== strpos( $store_src, 'Deactivate and reactivate' ) );

echo "=== a duplicate is confirmed by SELECT, not by error text ===\n";

// Parsing $wpdb->last_error for "Duplicate entry" would depend on a server-generated string.
// The confirming SELECT is locale-proof, and costs one query on a path that is rare anyway.
/*
 * Comments stripped first. The docblock MENTIONS `$wpdb->last_error` to explain why it is
 * not used, so searching the raw source finds it and the assertion would be meaningless —
 * it failed for exactly that reason before this was fixed. `php_strip_whitespace()` removes
 * comments, leaving only what actually executes.
 */
$store_code = php_strip_whitespace( $root . '/src/Auth/NonceStore.php' );

ok( 'no CODE reads last_error', false === strpos( $store_code, 'last_error' ) );
ok( 'the failure is confirmed with a SELECT', (bool) preg_match( '/SELECT created_at FROM \{\$table\} WHERE nonce_hash/', $store_src ) );

// Expected duplicates must not print a MySQL error into the response on every proxy retry.
ok( 'errors are suppressed around the insert', false !== strpos( $store_src, 'suppress_errors( true )' ) );
ok( 'and restored afterwards', false !== strpos( $store_src, 'suppress_errors( $previous )' ) );

echo "=== no \$wpdb at all still degrades rather than fatals ===\n";

// Without this guard the first $wpdb-> call is a fatal, which would take down every signed
// request instead of degrading. WordPress always provides $wpdb, so this covers the broken
// install and the bare bootstrap.
$GLOBALS['wpdb']          = null;
$GLOBALS['dp_transients'] = array();

$claim = NonceStore::claim( 'nonce-without-wpdb' );

ok( 'a null $wpdb does not fatal', true === $claim['fresh'] );
ok( 'and the transient path was used', isset( $GLOBALS['dp_transients'][ 'dp_nonce_' . hash( 'sha256', 'nonce-without-wpdb' ) ] ) );

$GLOBALS['wpdb'] = $db;

/* -----------------------------------------------------------------------------
 * Housekeeping
 * -------------------------------------------------------------------------- */

echo "=== expired rows are swept, unexpired ones are not ===\n";

$db->mode    = 'ok';
$db->queries = array();

NonceStore::prune();

ok( 'prune issues a delete', 1 === count( $db->queries ) );
// Its OWN expiry, never the retention cutoff: deleting an unexpired nonce would reopen the
// replay window it exists to close.
ok( 'keyed on expires_at', false !== strpos( (string) $db->queries[0], 'expires_at <' ) );
ok( 'and never on a retention cutoff', false === strpos( $store_src, 'LogRetention::cutoff' ) );

// Pruning on every request would be pure overhead on a table holding minutes of traffic.
ok( 'pruning is opportunistic, not per-request', false !== strpos( $store_src, 'maybe_prune' ) );
ok( 'and uses wp_rand', false !== strpos( $store_src, 'wp_rand(' ) );

echo "=== schema and cleanup ===\n";

$schema = (string) file_get_contents( $root . '/src/Support/Schema.php' );

// The UNIQUE index IS the check. Without it the insert would never fail and the whole
// design collapses back to a non-atomic read-then-write.
ok( 'the table has a UNIQUE index on the hash', false !== strpos( $schema, 'UNIQUE KEY nonce_hash (nonce_hash)' ) );
ok( 'indexed by expiry for the sweep', false !== strpos( $schema, 'KEY expires_at (expires_at)' ) );
// char(64) — a sha256 in hex. The nonce itself is attacker-controlled and arbitrarily long.
ok( 'the hash column is fixed width', false !== strpos( $schema, 'nonce_hash char(64)' ) );
ok( 'and the accessor exists', false !== strpos( $schema, 'function nonces_table()' ) );

$boot = (string) file_get_contents( $root . '/ifs-deploy.php' );

/*
 * AT LEAST 5, not exactly 5.
 *
 * What this guards is that the nonces table arrived behind a schema bump, so
 * `maybe_upgrade()` actually runs `install()` on an existing site — without one the
 * table is only ever created on a fresh activation, and every replay check on an
 * upgraded site falls back to the transient store for ever.
 *
 * Pinning the exact number made every LATER migration fail this assertion, which says
 * nothing about nonces at all.
 */
preg_match( "/IFS_DEPLOY_DB_VERSION',\s*'(\d+)'/", $boot, $db_version );
ok( 'the nonces table is behind a schema bump (db version >= 5)', (int) ( $db_version[1] ?? 0 ) >= 5 );

$uninstall = (string) file_get_contents( $root . '/uninstall.php' );
ok( 'uninstall drops the table', false !== strpos( $uninstall, 'ifs_deploy_nonces' ) );
// A site that never reached v5, or fell back after a failed migration, still has transients.
ok( 'and still clears legacy transients', false !== strpos( $uninstall, '_transient_dp_nonce_' ) );

$retention = (string) file_get_contents( $root . '/src/Support/LogRetention.php' );
ok( 'the daily cron sweeps expired nonces', false !== strpos( $retention, 'NonceStore::prune()' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
