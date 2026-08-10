<?php
declare(strict_types=1);

namespace IfsDeploy\Support;

use IfsDeploy\Auth\NonceStore;
use IfsDeploy\History\DeploymentRepository;

/**
 * How long log data is kept, and the scheduled job that enforces it.
 *
 * Two things grow without bound on a busy site:
 *
 *   1. The event log (`DebugLog`), an option capped at 200 entries. Bounded already, but
 *      an install pushing all day fills it within hours, which pushes out the entries
 *      from the failure you are actually investigating.
 *   2. The deployments table, which is NOT capped. Every push adds a row carrying its
 *      full per-object JSON log. That is the real storage cost.
 *
 * Both are purged by age here. Snapshots on the Production side are deliberately NOT
 * touched — see prune_deployments().
 */
final class LogRetention {

	public const OPTION = 'ifs_deploy_log_retention_days';

	/** Daily WP-Cron hook. */
	public const CRON_HOOK = 'ifs_deploy_purge_logs';

	/** Kept for a month by default: long enough to investigate last month's failure. */
	public const DEFAULT_DAYS = 30;

	/**
	 * Selectable periods, in days.
	 *
	 * There is no "keep forever" option. This log holds IP addresses, which are personal
	 * data — an unbounded retention is a liability rather than a feature, and 90 days is
	 * already far longer than any deployment failure stays worth investigating. `days()`
	 * only accepts a value listed here, so a stored `0` from an earlier build falls back
	 * to the default instead of silently disabling the purge.
	 *
	 * @return array<int,string> days => label
	 */
	public static function choices(): array {
		return array(
			7  => __( '7 days', 'ifs-deploy' ),
			14 => __( '14 days', 'ifs-deploy' ),
			30 => __( '30 days', 'ifs-deploy' ),
			90 => __( '90 days', 'ifs-deploy' ),
		);
	}

	/**
	 * The configured retention in days, or 0 for "keep forever".
	 *
	 * Anything not in choices() falls back to the default rather than being trusted — a
	 * hand-edited option must not be able to set a 1-day retention by accident.
	 */
	public static function days(): int {
		$stored = get_option( self::OPTION, null );

		if ( null === $stored ) {
			return self::DEFAULT_DAYS;
		}

		/*
		 * `is_numeric` BEFORE the cast, deliberately.
		 *
		 * `(int) 'nonsense'` is 0, and 0 is a legitimate choice meaning "keep forever" —
		 * so casting first would turn a corrupted option into silently-disabled retention.
		 * The user would believe logs were being purged while nothing was. The select
		 * posts "0" as a string, which is numeric, so the real choice still works.
		 */
		if ( ! is_numeric( $stored ) ) {
			return self::DEFAULT_DAYS;
		}

		$days = (int) $stored;

		return array_key_exists( $days, self::choices() ) ? $days : self::DEFAULT_DAYS;
	}

	/**
	 * @return bool Whether anything is purged at all.
	 */
	public static function is_enabled(): bool {
		return self::days() > 0;
	}

	/**
	 * Store a new retention period. Returns the value actually stored.
	 */
	public static function set_days( int $days ): int {
		$days = array_key_exists( $days, self::choices() ) ? $days : self::DEFAULT_DAYS;

		update_option( self::OPTION, $days );

		return $days;
	}

	/* ---------------------------------------------------------------------------
	 * Scheduling
	 * ------------------------------------------------------------------------- */

	public static function register(): void {
		add_action( self::CRON_HOOK, array( self::class, 'purge' ) );
	}

	/**
	 * Called on activation. `wp_next_scheduled` guards against stacking duplicate
	 * events when the plugin is deactivated and reactivated.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}

		// Belt and braces: clears any duplicate that predates the guard in schedule().
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/* ---------------------------------------------------------------------------
	 * Purging
	 * ------------------------------------------------------------------------- */

	/**
	 * Delete everything older than the retention period.
	 *
	 * @return array{events:int,deployments:int,api_requests:int} How many of each were removed.
	 */
	public static function purge(): array {
		$removed = array( 'events' => 0, 'deployments' => 0, 'api_requests' => 0 );

		if ( ! self::is_enabled() ) {
			return $removed;
		}

		$cutoff = self::cutoff();

		$removed['events']      = self::prune_events( $cutoff );
		$removed['deployments'] = self::prune_deployments( $cutoff );
		$removed['api_requests'] = ApiLog::delete_before( $cutoff );

		/*
		 * Expired replay nonces, swept on the same schedule.
		 *
		 * NOT pruned by the retention cutoff — they carry their OWN `expires_at`, a few
		 * minutes out, and deleting an unexpired one would reopen the replay window it
		 * exists to close. This only clears rows that are already past their expiry, which
		 * `NonceStore` also does opportunistically.
		 */
		NonceStore::prune();

		if ( array_sum( $removed ) > 0 ) {
			DebugLog::info(
				'Log retention purge completed.',
				array(
					'retention_days'      => (string) self::days(),
					'events_removed'      => (string) $removed['events'],
					'deployments_removed' => (string) $removed['deployments'],
					'api_rows_removed'    => (string) $removed['api_requests'],
				)
			);
		}

		return $removed;
	}

	/**
	 * The oldest timestamp that survives, in the site's timezone.
	 *
	 * Site-local rather than UTC because that is what both `DebugLog` (`current_time(
	 * 'mysql' )`) and the deployments table store. Comparing a UTC cutoff against
	 * site-local timestamps would silently shift the window by the UTC offset.
	 */
	public static function cutoff(): string {
		$days = self::days();

		return gmdate( 'Y-m-d H:i:s', (int) current_time( 'timestamp' ) - ( $days * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
	}

	/**
	 * Drop event-log entries older than the cutoff.
	 *
	 * Entries are newest-first, but the list is filtered rather than truncated at the
	 * first old entry: a clock change or an imported entry could put one out of order,
	 * and truncating there would throw away everything after it.
	 */
	private static function prune_events( string $cutoff ): int {
		$entries = DebugLog::all();

		if ( empty( $entries ) ) {
			return 0;
		}

		$kept = array_values(
			array_filter(
				$entries,
				static function ( $entry ) use ( $cutoff ): bool {
					if ( ! is_array( $entry ) ) {
						return false;
					}

					$time = (string) ( $entry['time'] ?? '' );

					// An entry with no timestamp cannot be aged out; keep it so a bug in
					// the writer never silently deletes history.
					return '' === $time || $time >= $cutoff;
				}
			)
		);

		$removed = count( $entries ) - count( $kept );

		if ( $removed > 0 ) {
			DebugLog::replace( $kept );
		}

		return $removed;
	}

	/**
	 * Delete deployment rows older than the cutoff.
	 *
	 * This removes HISTORY, not the ability to roll back arbitrary content: rollback
	 * restore points live as revisions/snapshots on the Production site and are already
	 * capped there at 3 per object. A row old enough to be purged is far past being
	 * rollback-able anyway, which is why age is a safe axis to prune on.
	 */
	private static function prune_deployments( string $cutoff ): int {
		return ( new DeploymentRepository() )->delete_before( $cutoff );
	}
}
