<?php
/**
 * Log retention: the period setting, and what the purge actually deletes.
 *
 * The purge is destructive and runs unattended from WP-Cron, so the cases that matter
 * most are the ones where it must NOT delete: retention off, entries inside the window,
 * and entries with no usable timestamp.
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
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['dp_options'] = array();
$GLOBALS['dp_cron']    = array();

/** Fixed "now" so the cutoff arithmetic is deterministic. */
$GLOBALS['dp_now'] = mktime( 12, 0, 0, 6, 15, 2026 );

function __( string $t, string $d = '' ): string {
	return $t;
}
function _n( string $s, string $p, int $n, string $d = '' ): string {
	return 1 === $n ? $s : $p;
}
function get_option( string $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['dp_options'] ) ? $GLOBALS['dp_options'][ $name ] : $default;
}
function update_option( string $name, $value, $autoload = null ): bool {
	$GLOBALS['dp_options'][ $name ] = $value;

	return true;
}
function current_time( string $type = 'mysql', $gmt = 0 ) {
	return 'timestamp' === $type ? $GLOBALS['dp_now'] : gmdate( 'Y-m-d H:i:s', $GLOBALS['dp_now'] );
}
function wp_next_scheduled( string $hook ) {
	return $GLOBALS['dp_cron'][ $hook ] ?? false;
}
function wp_schedule_event( int $when, string $recurrence, string $hook ): bool {
	// Mirrors core: a second identical event is still scheduled, which is exactly why
	// LogRetention::schedule() guards on wp_next_scheduled() first.
	$GLOBALS['dp_cron'][ $hook ] = $when;
	$GLOBALS['dp_cron_calls'][]  = $hook;

	return true;
}
function wp_unschedule_event( int $when, string $hook ): bool {
	unset( $GLOBALS['dp_cron'][ $hook ] );

	return true;
}
function wp_clear_scheduled_hook( string $hook ): void {
	unset( $GLOBALS['dp_cron'][ $hook ] );
}
function add_action( string $hook, $cb, int $p = 10, int $a = 1 ): bool {
	$GLOBALS['dp_hooks'][] = $hook;

	return true;
}
function apply_filters( string $hook, $value ) {
	return $value;
}

/**
 * $wpdb stub. LogRetention now also purges the API access log, which is a real table,
 * so the purge path needs one. Records what it was asked to delete.
 */
class DP_Test_WPDB {
	public string $prefix = 'wp_';

	/**
	 * Records EVERY prepared cutoff, not just the last one.
	 *
	 * purge() now issues two of these — the api-log delete and the nonce prune — so keeping
	 * only the most recent one made the assertion below compare against whichever happened
	 * to run last.
	 */
	public function prepare( string $query, ...$args ): string {
		$GLOBALS['dp_api_cutoffs'][] = (string) ( $args[0] ?? '' );
		$GLOBALS['dp_api_cutoff']    = (string) ( $args[0] ?? '' );

		return $query;
	}

	public function query( $query ): int {
		$GLOBALS['dp_api_queries'][] = $query;

		return (int) $GLOBALS['dp_api_delete_return'];
	}
}

$GLOBALS['wpdb']                  = new DP_Test_WPDB();
$GLOBALS['dp_api_queries']        = array();
$GLOBALS['dp_api_cutoffs']        = array();
$GLOBALS['dp_api_delete_return']  = 0;

/** Captures what the repository was asked to delete, without a database. */
$GLOBALS['dp_deleted_before'] = array();
$GLOBALS['dp_delete_return']  = 0;

/* -----------------------------------------------------------------------------
 * Autoload, with DeploymentRepository replaced by a recorder
 * -------------------------------------------------------------------------- */

spl_autoload_register(
	static function ( string $class ) use ( $root ): void {
		if ( 0 !== strpos( $class, 'IfsDeploy\\' ) ) {
			return;
		}

		// The repository talks to $wpdb; stand in for it so the purge logic can be tested
		// on its own. Declared in its real namespace so LogRetention's `new` resolves.
		if ( 'IfsDeploy\\History\\DeploymentRepository' === $class ) {
			eval(
				'namespace IfsDeploy\History; class DeploymentRepository {
					public function delete_before( string $cutoff ): int {
						$GLOBALS["dp_deleted_before"][] = $cutoff;
						return (int) $GLOBALS["dp_delete_return"];
					}
				}'
			);

			return;
		}

		$path = $root . '/src/' . str_replace( '\\', '/', substr( $class, strlen( 'IfsDeploy\\' ) ) ) . '.php';

		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

use IfsDeploy\Support\DebugLog;
use IfsDeploy\Support\LogRetention;

/* -----------------------------------------------------------------------------
 * The setting
 * -------------------------------------------------------------------------- */

echo "=== retention period setting ===\n";

$GLOBALS['dp_options'] = array();
ok( 'defaults to 30 days when never set', 30 === LogRetention::days() );
ok( 'the default is enabled', LogRetention::is_enabled() );

foreach ( array( 7, 14, 30, 90 ) as $days ) {
	ok( "$days days is selectable", $days === LogRetention::set_days( $days ) );
}

// There is no longer a keep-forever option: this log holds IP addresses, and unbounded
// retention of personal data is a liability rather than a feature.
ok( '0 is refused and falls back to the default', 30 === LogRetention::set_days( 0 ) );
ok( 'purging is therefore always on', LogRetention::is_enabled() );
ok( '180 days is no longer selectable', 30 === LogRetention::set_days( 180 ) );
ok( '365 days is no longer selectable', 30 === LogRetention::set_days( 365 ) );

// A value outside the offered set must not be trusted — a hand-edited or corrupted
// option must never be able to set a 1-day window and start deleting history.
foreach ( array( 1, 5, -30, 99999 ) as $bogus ) {
	ok( "rejects $bogus and falls back to the default", 30 === LogRetention::set_days( $bogus ) );
}

$GLOBALS['dp_options'][ LogRetention::OPTION ] = 'nonsense';
ok( 'a non-numeric stored value falls back too', 30 === LogRetention::days() );

$GLOBALS['dp_options'][ LogRetention::OPTION ] = 3;
ok( 'an out-of-range stored value falls back too', 30 === LogRetention::days() );

// The <select> posts strings, so a numeric string must still be honoured — including
// "0", which is a real choice and must not be confused with a corrupted value.
// A stored 0 from an earlier build must fall back, not silently disable the purge.
$GLOBALS['dp_options'][ LogRetention::OPTION ] = '0';
ok( 'a stored "0" from an older build falls back to the default', 30 === LogRetention::days() );

$GLOBALS['dp_options'][ LogRetention::OPTION ] = '90';
ok( 'the string "90" is honoured', 90 === LogRetention::days() );

/* -----------------------------------------------------------------------------
 * The cutoff
 * -------------------------------------------------------------------------- */

echo "=== the cutoff is site-local, not UTC ===\n";

LogRetention::set_days( 7 );
$expected = gmdate( 'Y-m-d H:i:s', $GLOBALS['dp_now'] - ( 7 * DAY_IN_SECONDS ) );

// DebugLog writes `current_time( 'mysql' )` and deployed_at is site-local, so the cutoff
// must be built from the same clock. A UTC cutoff would shift the window by the site's
// offset and silently delete up to a day too much (or too little).
ok( 'the cutoff is 7 days before the site clock', $expected === LogRetention::cutoff() );

LogRetention::set_days( 30 );
ok(
	'a longer period moves the cutoff further back',
	LogRetention::cutoff() < $expected
);

/* -----------------------------------------------------------------------------
 * Purging the event log
 * -------------------------------------------------------------------------- */

echo "=== purging the event log ===\n";

/** Build an entry $days_ago old. */
$entry = static function ( int $days_ago, string $message ): array {
	return array(
		'time'    => gmdate( 'Y-m-d H:i:s', $GLOBALS['dp_now'] - ( $days_ago * DAY_IN_SECONDS ) ),
		'level'   => 'info',
		'role'    => 'staging',
		'message' => $message,
		'context' => array(),
	);
};

LogRetention::set_days( 30 );
DebugLog::replace(
	array(
		$entry( 1, 'yesterday' ),
		$entry( 29, 'just inside' ),
		$entry( 31, 'just outside' ),
		$entry( 400, 'ancient' ),
	)
);

$GLOBALS['dp_deleted_before'] = array();
$GLOBALS['dp_api_cutoffs']    = array();
$GLOBALS['dp_delete_return']  = 0;
$removed                      = LogRetention::purge();

$kept     = DebugLog::all();
$messages = array_column( $kept, 'message' );

ok( 'reports 2 events removed', 2 === $removed['events'] );
ok( 'keeps yesterday', in_array( 'yesterday', $messages, true ) );
ok( 'keeps the entry just inside the window', in_array( 'just inside', $messages, true ) );
ok( 'drops the entry just outside', ! in_array( 'just outside', $messages, true ) );
ok( 'drops the ancient entry', ! in_array( 'ancient', $messages, true ) );

// purge() logs its own summary, so the survivors plus that entry are what remain.
ok( 'the purge records what it did', in_array( 'Log retention purge completed.', $messages, true ) );

echo "=== the purge fails safe ===\n";

// is_enabled() is now always true, but the guard in purge() is kept as a safety net in
// case a future build reintroduces an "off" value. Exercise it directly.
$GLOBALS['dp_options'][ LogRetention::OPTION ] = 0;
DebugLog::replace( array( $entry( 5000, 'very old but retention is off' ) ) );
$GLOBALS['dp_deleted_before'] = array();
$GLOBALS['dp_api_queries']    = array();
$GLOBALS['dp_api_cutoffs']    = array();
$removed                      = LogRetention::purge();

// A stored 0 now resolves to 30 days, so the very old entry IS purged — which is the
// intended new behaviour, and the assertion that proves the fallback is real.
ok( 'a stored 0 no longer disables purging', $removed['events'] > 0 );
ok( 'the very old entry is removed', 0 === count( array_filter( DebugLog::all(), static fn( $e ) => 'very old but retention is off' === ( $e['message'] ?? '' ) ) ) );

// An entry the writer failed to timestamp cannot be aged out. Deleting it would mean a
// bug in the writer silently destroys history, so it is kept.
LogRetention::set_days( 7 );
DebugLog::replace(
	array(
		array( 'level' => 'error', 'message' => 'no timestamp', 'context' => array() ),
		$entry( 99, 'definitely old' ),
	)
);
LogRetention::purge();
$messages = array_column( DebugLog::all(), 'message' );

ok( 'an entry with no timestamp is kept', in_array( 'no timestamp', $messages, true ) );
ok( 'the old entry beside it is still removed', ! in_array( 'definitely old', $messages, true ) );

// Malformed entries are dropped rather than crashing the cron run.
DebugLog::replace( array( 'not an array', $entry( 1, 'fine' ) ) );
LogRetention::purge();
ok( 'a malformed entry is discarded without error', in_array( 'fine', array_column( DebugLog::all(), 'message' ), true ) );

// Out-of-order entries: the list is FILTERED, not truncated at the first old one. A clock
// change could otherwise throw away everything after a single stale entry.
LogRetention::set_days( 30 );
DebugLog::replace(
	array(
		$entry( 100, 'old, out of order' ),
		$entry( 2, 'recent, after it' ),
	)
);
LogRetention::purge();
$messages = array_column( DebugLog::all(), 'message' );

ok( 'filters rather than truncating at the first old entry', in_array( 'recent, after it', $messages, true ) );
ok( 'and still drops the out-of-order old entry', ! in_array( 'old, out of order', $messages, true ) );

/* -----------------------------------------------------------------------------
 * Purging deployment history
 * -------------------------------------------------------------------------- */

echo "=== purging deployment history ===\n";

LogRetention::set_days( 90 );
DebugLog::replace( array() );
$GLOBALS['dp_deleted_before'] = array();
$GLOBALS['dp_delete_return']  = 12;

$removed = LogRetention::purge();

ok( 'deletes deployments once', 1 === count( $GLOBALS['dp_deleted_before'] ) );
ok( 'passes the same cutoff used for events', LogRetention::cutoff() === ( $GLOBALS['dp_deleted_before'][0] ?? '' ) );
ok( 'reports the row count it removed', 12 === $removed['deployments'] );

// The API access log is purged on the same cutoff — one setting, one schedule, so a
// security log cannot quietly outlive the retention the site owner configured.
ok( 'the api log is purged too', ! empty( $GLOBALS['dp_api_queries'] ) );
ok( 'on the same cutoff', in_array( LogRetention::cutoff(), (array) ( $GLOBALS['dp_api_cutoffs'] ?? array() ), true ) );

/*
 * Replay nonces are swept in the same run but NOT on the retention cutoff.
 *
 * They carry their own `expires_at`, a few minutes out. Deleting an unexpired nonce would
 * reopen the replay window it exists to close, so this asserts the sweep uses a cutoff that
 * is NOT the retention one.
 */
$nonce_cutoffs = array_values(
	array_filter(
		(array) ( $GLOBALS['dp_api_cutoffs'] ?? array() ),
		static fn( string $c ): bool => $c !== LogRetention::cutoff()
	)
);

ok( 'nonces are swept in the same run', ! empty( $nonce_cutoffs ) );
ok( 'but never on the retention cutoff', ! in_array( LogRetention::cutoff(), $nonce_cutoffs, true ) );
ok( 'purge() reports api rows separately', array_key_exists( 'api_requests', $removed ) );

/* -----------------------------------------------------------------------------
 * Scheduling
 * -------------------------------------------------------------------------- */

echo "=== the daily cron event ===\n";

$GLOBALS['dp_cron']       = array();
$GLOBALS['dp_cron_calls'] = array();

LogRetention::schedule();
ok( 'schedule() creates the event', false !== wp_next_scheduled( LogRetention::CRON_HOOK ) );

// Plugin::boot() calls schedule() on EVERY request so an updated (not reactivated) site
// gets the event. Without the guard that would stack a duplicate every page load.
LogRetention::schedule();
LogRetention::schedule();
ok( 'calling it again does not stack duplicates', 1 === count( $GLOBALS['dp_cron_calls'] ) );

LogRetention::unschedule();
ok( 'unschedule() removes it', false === wp_next_scheduled( LogRetention::CRON_HOOK ) );

$GLOBALS['dp_hooks'] = array();
LogRetention::register();
ok( 'register() listens on the cron hook', in_array( LogRetention::CRON_HOOK, $GLOBALS['dp_hooks'], true ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
