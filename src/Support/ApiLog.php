<?php
declare(strict_types=1);

namespace IfsDeploy\Support;

/**
 * Who has been calling the signed API, from where, and how it went.
 *
 * Recorded on the RECEIVING side — a Production site — at the single choke point every
 * endpoint passes through (`Auth\Verifier::verify_request()`). That is the only place that
 * sees both the request and its outcome, so it is the only place the log can be complete.
 *
 * Three things this is careful about, because a monitor that fails in any of them is worse
 * than not having one:
 *
 *  1. **It must not become the denial of service.** Anyone can hit the endpoint, and every
 *     hit would otherwise be a database write. Successful requests are logged in full;
 *     repeated FAILURES from one address collapse into a counter (see `record()`), so a
 *     flood costs a bounded number of writes rather than one per packet.
 *
 *  2. **It must not lie.** The address comes from `Support\ClientIp`, which trusts
 *     `REMOTE_ADDR` unless the owner explicitly opted into a proxy header. See that class
 *     for why trusting `X-Forwarded-For` by default would make this log fiction.
 *
 *  3. **It must not grow forever.** Rows are purged by `Support\LogRetention` on the same
 *     schedule and setting as everything else. IP addresses are personal data, so an
 *     unbounded log is a liability, not a feature.
 */
final class ApiLog {

	/** Outcome recorded for a request that passed every check. */
	public const OK = 'ok';

	/**
	 * The same HTTP request delivered twice, refused by the replay store.
	 *
	 * Named as a constant because two decisions turn on it and both would be wrong if this
	 * were treated as an ordinary rejection — see `is_failure()` and `record()`.
	 */
	public const DUPLICATE = 'ifs_deploy_duplicate';

	/** Whether duplicate deliveries get a row of their own. */
	public const OPTION_LOG_DUPLICATES = 'ifs_deploy_log_duplicates';

	/**
	 * How long a failure burst from one address is collapsed into one row, in seconds.
	 *
	 * Long enough that a brute-force attempt is a handful of rows rather than thousands;
	 * short enough that separate attempts hours apart stay separate entries.
	 */
	private const BURST_WINDOW = 300;

	/** Window used when counting recent failures for the "suspicious" flag, in seconds. */
	private const SUSPICION_WINDOW = 3600;

	/** Failures from one address within SUSPICION_WINDOW before it is flagged. */
	private const SUSPICION_THRESHOLD = 5;

	/**
	 * Is this outcome a FAILURE for the purpose of counting and flagging?
	 *
	 * `OK` obviously is not. Neither is `DUPLICATE`, and that is the part worth explaining:
	 * a duplicate is the same HTTP request arriving twice, which means it had already passed
	 * the signature, key and timestamp checks — only the replay store refused it, and it
	 * refused it correctly, so nothing ran twice.
	 *
	 * Counting it as a failure had two visible consequences, both wrong. The API access
	 * summary reported failures a healthy pair was not having; and `maybe_flag_suspicious()`
	 * fires at 5 failures an hour, so a site behind a retrying proxy produced ERROR-level
	 * "Repeated rejected API requests" entries about its own paired Staging site.
	 *
	 * A DELIBERATE replay is a different outcome — `ifs_deploy_replay`, recorded when the
	 * repeat arrives outside `Verifier::DUPLICATE_WINDOW` — and that still counts here. So
	 * this exempts transport noise without exempting the attack it looks like.
	 */
	public static function is_failure( string $outcome ): bool {
		return self::OK !== $outcome && self::DUPLICATE !== $outcome;
	}

	/**
	 * Does a duplicate delivery get a row of its own?
	 *
	 * Off by default. On a pair whose host or proxy retries requests, the duplicate is not an
	 * event — it is the same event twice — and a table full of "Duplicate suppressed" is how a
	 * security log becomes something a team scrolls past. The cause is still reported once an
	 * hour by `Verifier::note_duplicate()`, with what to check, so nothing is actually lost.
	 *
	 * Left switchable because it is genuinely useful while chasing a transport problem: seeing
	 * which endpoint doubles, from which address, and how often.
	 */
	public static function logs_duplicates(): bool {
		return (bool) get_option( self::OPTION_LOG_DUPLICATES, false );
	}

	public static function set_logs_duplicates( bool $enabled ): void {
		update_option( self::OPTION_LOG_DUPLICATES, $enabled );
	}

	/**
	 * Record one API request.
	 *
	 * @param string $route   Endpoint slug, e.g. `import`.
	 * @param string $outcome `self::OK`, or the WP_Error code of the check that refused it.
	 * @param int    $status  HTTP status the caller will receive.
	 */
	public static function record( string $route, string $outcome, int $status ): void {
		global $wpdb;

		// Only the receiving side has anything to monitor. A Staging site is the one making
		// requests, so logging inbound calls there would be noise.
		if ( ! Config::is_production() ) {
			return;
		}

		$who   = ClientIp::resolve();
		$ip    = (string) $who['ip'];
		$table = Schema::api_log_table();
		$now   = current_time( 'mysql' );

		$is_failure = self::is_failure( $outcome );
		$is_new     = self::is_new_ip( $ip );

		// The roster is updated for EVERY request, including the routine accepted ones
		// that no longer earn a row of their own. It is one upsert against a unique key,
		// and it is what keeps "who calls this site" exact while the list below stays
		// short. Done before the early returns, or a duplicate delivery would not count.
		self::touch_address( $ip, $route, $outcome, $is_failure, $now );

		/*
		 * Drop a duplicate delivery, unless it is this address's FIRST appearance.
		 *
		 * The exception is not decoration. `is_new_ip()` is answered from this table, so
		 * dropping a first sighting would mean an address that only ever produced duplicates
		 * never appeared at all — the one thing this log exists to make impossible. In
		 * practice the first delivery of that pair was already recorded and the address is
		 * therefore known, so the exception almost never fires; when it does, the row carries
		 * its "New" badge and is worth having.
		 */
		if ( self::DUPLICATE === $outcome && ! $is_new && ! self::logs_duplicates() ) {
			return;
		}

		/*
		 * A ROUTINE ACCEPTED REQUEST GETS NO ROW.
		 *
		 * Every step of a deploy is an API call — object, signatures, import, rollback
		 * preview, rollback — so a working pair wrote a handful of "accepted" rows per
		 * push, for ever, and the table grew without ever saying anything. A list where
		 * every entry is "fine" is not a security log; it is a place failures go to hide.
		 *
		 * What is kept is what is worth reading: a FIRST SIGHTING of an address, and
		 * anything that FAILED. Both are exceptions, so the table stays small enough to
		 * scan and every row in it is a reason to look.
		 *
		 * Nothing is lost by counting rather than listing: `touch_address()` above has
		 * already recorded the request against its address, so totals, first/last seen and
		 * the roster stay exact.
		 */
		if ( ! $is_new && ! $is_failure && self::DUPLICATE !== $outcome ) {
			return;
		}

		/*
		 * Collapse a failure burst. Without this, one script hammering /import writes a row
		 * per attempt and the attacker chooses how big this table gets — the log becomes
		 * the vulnerability. A repeat failure from the same address, on the same route,
		 * with the same outcome, inside BURST_WINDOW just bumps the existing row's
		 * timestamp instead of inserting.
		 *
		 * Successes are never collapsed: a legitimate peer makes few requests, and knowing
		 * exactly when each deploy arrived is the point.
		 */
		if ( $is_failure && ! $is_new ) {
			$recent = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE ip = %s AND route = %s AND outcome = %s AND created_at > %s ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
					$ip,
					$route,
					$outcome,
					gmdate( 'Y-m-d H:i:s', (int) current_time( 'timestamp' ) - self::BURST_WINDOW ) // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
				)
			);

			if ( $recent > 0 ) {
				$wpdb->update( $table, array( 'created_at' => $now ), array( 'id' => $recent ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

				self::maybe_flag_suspicious( $ip );

				return;
			}
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'ip'          => $ip,
				'remote_addr' => (string) $who['remote_addr'],
				'forwarded'   => (string) $who['forwarded'],
				'route'       => substr( $route, 0, 40 ),
				'method'      => isset( $_SERVER['REQUEST_METHOD'] ) ? substr( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ), 0, 10 ) : '',
				'outcome'     => substr( $outcome, 0, 40 ),
				'status'      => max( 0, min( 599, $status ) ),
				'user_agent'  => self::user_agent(),
				'is_new_ip'   => $is_new ? 1 : 0,
				'created_at'  => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s' )
		);

		/*
		 * A first sighting is an event worth surfacing — but only when it is actually
		 * news.
		 *
		 * An address the admin has already put on the ALLOW list is expected by
		 * definition, so announcing it as a warning was just wrong: the screen showed a
		 * standing "new address" warning for the very address its owner had explicitly
		 * approved. Allow-listed first sightings are recorded at info level instead.
		 */
		if ( $is_new ) {
			$expected = IpAccess::is_configured() && IpAccess::matches_any(
				ClientIp::for_matching(),
				IpAccess::allow_list()
			);

			$message = sprintf(
				/* translators: 1: IP address, 2: endpoint slug */
				__( 'First API request from a new address: %1$s (endpoint: %2$s)', 'ifs-deploy' ),
				$ip,
				$route
			);

			$context = array(
				'ip'      => $ip,
				'route'   => $route,
				'outcome' => $outcome,
				'agent'   => self::user_agent(),
			);

			if ( $expected ) {
				DebugLog::info( $message . ' ' . __( 'It is on the allow list, so this was expected.', 'ifs-deploy' ), $context );
			} else {
				DebugLog::warning( $message, $context );
			}
		}

		if ( $is_failure ) {
			self::maybe_flag_suspicious( $ip );
		}
	}

	/**
	 * Count this request against its address, creating the address on first sight.
	 *
	 * One statement, resolved by the UNIQUE key on `ip`, so two concurrent requests from
	 * one address cannot both insert — the same reasoning as the queue's own identity key.
	 */
	private static function touch_address( string $ip, string $route, string $outcome, bool $is_failure, string $now ): void {
		global $wpdb;

		if ( '' === $ip ) {
			return;
		}

		$table = Schema::api_addresses_table();

		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL
				"INSERT INTO {$table} (ip, requests, failures, last_route, last_outcome, user_agent, first_seen, last_seen)
				 VALUES (%s, 1, %d, %s, %s, %s, %s, %s)
				 ON DUPLICATE KEY UPDATE
				     requests     = requests + 1,
				     failures     = failures + %d,
				     last_route   = VALUES(last_route),
				     last_outcome = VALUES(last_outcome),
				     user_agent   = VALUES(user_agent),
				     last_seen    = VALUES(last_seen)",
				$ip,
				$is_failure ? 1 : 0,
				substr( $route, 0, 40 ),
				substr( $outcome, 0, 40 ),
				self::user_agent(),
				$now,
				$now,
				$is_failure ? 1 : 0
			)
		);
	}

	/**
	 * Has this address been seen before?
	 *
	 * Answered from the ADDRESS ROSTER, not from the request rows. It used to be the
	 * latter, and that tied "is this address known?" to how long request rows happen to be
	 * kept: an address whose rows had aged out — or been cleared by hand — was announced
	 * as a brand new caller the next time the paired site deployed. The roster is not
	 * purged by retention and survives Clear, so the answer is now simply true.
	 */
	private static function is_new_ip( string $ip ): bool {
		global $wpdb;

		if ( '' === $ip ) {
			return false;
		}

		$table = Schema::api_addresses_table();

		$seen = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$wpdb->prepare( "SELECT id FROM {$table} WHERE ip = %s LIMIT 1", $ip ) // phpcs:ignore WordPress.DB.PreparedSQL
		);

		return null === $seen;
	}

	/**
	 * Raise ONE event when an address crosses the failure threshold.
	 *
	 * A transient marks an address as already reported, so a sustained attack produces one
	 * log entry per hour rather than one per request — otherwise the alert would bury the
	 * very log it is written to, and the event log's 200-entry cap would push out
	 * everything else.
	 */
	private static function maybe_flag_suspicious( string $ip ): void {
		if ( '' === $ip ) {
			return;
		}

		$failures = self::recent_failures( $ip );

		if ( $failures < self::SUSPICION_THRESHOLD ) {
			return;
		}

		$marker = 'dp_ip_flagged_' . hash( 'sha256', $ip );

		if ( false !== get_transient( $marker ) ) {
			return;
		}

		set_transient( $marker, 1, self::SUSPICION_WINDOW );

		DebugLog::error(
			sprintf(
				/* translators: 1: number of failed attempts, 2: IP address */
				__( 'Repeated rejected API requests: %1$d failures from %2$s in the last hour.', 'ifs-deploy' ),
				$failures,
				$ip
			),
			array(
				'ip'       => $ip,
				'failures' => (string) $failures,
			)
		);
	}

	/**
	 * Failed attempts from one address inside the suspicion window.
	 */
	public static function recent_failures( string $ip ): int {
		global $wpdb;

		$table = Schema::api_log_table();

		// A transport duplicate is excluded along with a success — see is_failure(). This is
		// the count the "suspicious address" flag is raised from, and flagging a retrying
		// proxy as an intruder is how the flag stops meaning anything.
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE ip = %s AND outcome NOT IN ( %s, %s ) AND created_at > %s", // phpcs:ignore WordPress.DB.PreparedSQL
				$ip,
				self::OK,
				self::DUPLICATE,
				gmdate( 'Y-m-d H:i:s', (int) current_time( 'timestamp' ) - self::SUSPICION_WINDOW ) // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
			)
		);
	}

	/**
	 * Most recent requests, newest first.
	 *
	 * @return object[]
	 */
	public static function recent( int $limit = 100 ): array {
		global $wpdb;

		$table = Schema::api_log_table();
		$limit = max( 1, min( 500, $limit ) );

		/*
		 * Ordered by created_at, NOT by id.
		 *
		 * `record()` collapses a failure burst by UPDATING an existing row's timestamp, so
		 * a row's id no longer implies its age. Ordering by id put those bumped rows back
		 * where they were first inserted, and the table came out visibly non-chronological
		 * — 06:04:24 above 06:04:21 above 06:04:24 again. id is kept as the tie-break for
		 * rows sharing a second.
		 */
		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC, id DESC LIMIT %d", $limit ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}

	/**
	 * One row per address: totals, failure count, first and last seen.
	 *
	 * This is the view that actually answers "is anyone probing us?" — the per-request
	 * list is for reading the detail once an address looks wrong.
	 *
	 * @return object[]
	 */
	public static function by_ip( int $limit = 50 ): array {
		global $wpdb;

		// From the ROSTER, not from the request rows. Those are now only kept for first
		// sightings and failures, so grouping over them would understate every total — and
		// would empty this table entirely the moment the request list was cleared.
		$table = Schema::api_addresses_table();
		$limit = max( 1, min( 200, $limit ) );

		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$wpdb->prepare(
				"SELECT ip,
						requests AS hits,
						failures,
						0 AS was_new,
						first_seen,
						last_seen,
						user_agent
				 FROM {$table}
				 ORDER BY failures DESC, last_seen DESC
				 LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$limit
			)
		);
	}

	/**
	 * Remove ONE address: its roster entry and any request rows it left behind.
	 *
	 * The roster is deliberately not aged out — it is the answer to "which machines push
	 * to this site", and that is worth keeping — so removing an address has to be
	 * something the owner can do deliberately. Both tables go together: leaving the
	 * request rows would resurrect the address in the list below while claiming it was
	 * never seen, and leaving the roster entry would keep counting a machine that has been
	 * dismissed.
	 *
	 * Note the consequence, which is intended: a deleted address is NEW again next time it
	 * calls, and announces itself accordingly.
	 */
	public static function forget_ip( string $ip ): int {
		global $wpdb;

		if ( '' === $ip ) {
			return 0;
		}

		$removed = (int) $wpdb->delete( Schema::api_addresses_table(), array( 'ip' => $ip ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$wpdb->delete( Schema::api_log_table(), array( 'ip' => $ip ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $removed;
	}

	/**
	 * @return array{requests:int,failures:int,addresses:int,new_today:int}
	 */
	public static function summary(): array {
		global $wpdb;

		// Totals come from the roster, which counts every request including the routine
		// accepted ones that no longer earn a row. "New today" is a first_seen inside the
		// window — the roster is the only place that survives long enough to know.
		$table = Schema::api_addresses_table();
		$today = gmdate( 'Y-m-d H:i:s', (int) current_time( 'timestamp' ) - DAY_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$wpdb->prepare(
				"SELECT COALESCE( SUM( requests ), 0 ) AS requests,
						COALESCE( SUM( failures ), 0 ) AS failures,
						COUNT(*) AS addresses,
						SUM( CASE WHEN first_seen > %s THEN 1 ELSE 0 END ) AS new_today
				 FROM {$table}", // phpcs:ignore WordPress.DB.PreparedSQL
				$today
			)
		);

		return array(
			'requests'  => (int) ( $row->requests ?? 0 ),
			'failures'  => (int) ( $row->failures ?? 0 ),
			'addresses' => (int) ( $row->addresses ?? 0 ),
			'new_today' => (int) ( $row->new_today ?? 0 ),
		);
	}

	/**
	 * Clear the REQUEST list, keeping the address roster.
	 *
	 * Deliberately not both. The roster answers "which machines push to this site", which
	 * is the part worth keeping and the part that took time to accumulate; the request
	 * list is a scratchpad of recent first sightings and failures. Wiping the roster as a
	 * side effect of tidying that scratchpad is the kind of surprise that makes people
	 * stop pressing buttons — and it cannot be undone.
	 *
	 * Addresses are removed one at a time with `forget_ip()`.
	 */
	public static function clear(): int {
		global $wpdb;

		$table = Schema::api_log_table();

		return (int) $wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Delete rows older than `$cutoff` (site-local `Y-m-d H:i:s`), for LogRetention.
	 */
	public static function delete_before( string $cutoff ): int {
		global $wpdb;

		$table = Schema::api_log_table();

		return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}

	/**
	 * The caller's user agent, length-capped.
	 *
	 * Attacker-controlled free text that will be rendered in the admin, so it is
	 * sanitised here and escaped again at output.
	 */
	private static function user_agent(): string {
		if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return '';
		}

		return substr( sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 );
	}
}
