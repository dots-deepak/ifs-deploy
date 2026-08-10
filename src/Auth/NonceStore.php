<?php
declare(strict_types=1);

namespace IfsDeploy\Auth;

use IfsDeploy\Support\Config;
use IfsDeploy\Support\DebugLog;
use IfsDeploy\Support\Schema;

/**
 * Replay protection: remembers the nonce of every request already accepted.
 *
 * ── WHAT WAS MISSING ORIGINALLY (H-1) ──────────────────────────────────────────
 *
 * The nonce was part of the signed canonical string from the start, but nothing recorded
 * it. A captured request could therefore be resent verbatim for as long as its timestamp
 * stayed inside `Config::TIMESTAMP_WINDOW`, because none of the signed material changes.
 *
 * ── WHY A TABLE AND NOT TRANSIENTS (H-1a) ──────────────────────────────────────
 *
 * The first fix used transients, and left two holes that a table closes:
 *
 *  1. **It was not atomic.** Two concurrent deliveries of the same request — which is
 *     exactly what a proxy retry produces — could both read "not seen" before either
 *     wrote, and both would be accepted. A UNIQUE index makes the second INSERT fail; the
 *     database does the check, so there is no window between reading and writing.
 *  2. **Transients can be evicted.** On a persistent object cache (Memcached/Redis) a key
 *     can be dropped under memory pressure before it expires, which would silently let one
 *     replay through.
 *
 * ── THE TWO WAYS THIS COULD BREAK THE PLUGIN, AND WHAT PREVENTS THEM ───────────
 *
 * Both live in `claim()`, and both are about a failed INSERT:
 *
 *  - A failed INSERT is NOT proof of a replay. It is also what a missing table, a lost
 *    connection or a full disk look like. Treating those as replays would refuse **every**
 *    request — a total outage caused by the security feature. So a failure is confirmed
 *    with a SELECT before it is believed, and anything else FAILS OPEN.
 *  - Confirming it by parsing `$wpdb->last_error` for "Duplicate entry" would be worse: the
 *    string comes from the server and is not something to depend on. The SELECT is
 *    locale-proof and costs one query on a path that is rare by definition.
 *
 * Failing open means, in the worst case, falling back to where H-1 already was. That is the
 * right trade: replay protection degrading is recoverable, the whole pair going down is not.
 */
final class NonceStore {

	/** Transient prefix for the fallback path. */
	private const PREFIX = 'dp_nonce_';

	/**
	 * Longest nonce accepted, in characters.
	 *
	 * The client sends a uuid4 (36 chars). A cap exists so an attacker cannot push
	 * megabyte-long nonces and have them hashed and stored.
	 */
	private const MAX_LENGTH = 128;

	/**
	 * Claim this nonce for this request — the atomic "have I seen it?" and "remember it"
	 * in one operation.
	 *
	 * @return array{fresh:bool,first_seen:int}
	 *   `fresh` is true when this is the first time the nonce has been presented and the
	 *   request may proceed. `first_seen` is the Unix time the ORIGINAL was accepted, or 0
	 *   if unknown — the caller uses it to tell a transport duplicate from a deliberate
	 *   replay.
	 */
	public static function claim( string $nonce ): array {
		$hash = self::hash( $nonce );

		if ( '' === $hash ) {
			// An unusable nonce fails CLOSED. It is not a database problem, it is a
			// malformed request, and refusing it costs nothing legitimate.
			return array( 'fresh' => false, 'first_seen' => 0 );
		}

		global $wpdb;

		/*
		 * No usable database handle at all — fall back before touching it.
		 *
		 * Completes the fail-open story rather than being a nicety: without this the first
		 * `$wpdb->` call is a fatal, which would take down every signed request instead of
		 * degrading. WordPress always provides `$wpdb`, so this should never fire in
		 * production; it fires in a bootstrap that has no database, which is also the state
		 * a broken install presents.
		 */
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'insert' ) ) {
			return self::claim_via_transient( $nonce );
		}

		$table = Schema::nonces_table();
		$ttl   = ( 2 * Config::TIMESTAMP_WINDOW ) + 60;
		$now   = time();

		// Suppressed because a duplicate key is an EXPECTED outcome here, not a fault. Left
		// unsuppressed it prints a MySQL error into the response on every proxy retry.
		$previous = $wpdb->suppress_errors( true );

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'nonce_hash' => $hash,
				'created_at' => gmdate( 'Y-m-d H:i:s', $now ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', $now + $ttl ),
			),
			array( '%s', '%s', '%s' )
		);

		$wpdb->suppress_errors( $previous );

		if ( false !== $inserted ) {
			self::maybe_prune();

			return array( 'fresh' => true, 'first_seen' => 0 );
		}

		/*
		 * The insert failed. Before calling this a replay, CONFIRM the row is actually
		 * there. A missing table or a dropped connection fails here too, and refusing every
		 * request on that basis would take the whole pair down.
		 */
		$existing = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$wpdb->prepare( "SELECT created_at FROM {$table} WHERE nonce_hash = %s", $hash ) // phpcs:ignore WordPress.DB.PreparedSQL
		);

		if ( null !== $existing ) {
			// Genuinely seen before.
			return array( 'fresh' => false, 'first_seen' => (int) strtotime( (string) $existing . ' UTC' ) );
		}

		// Not a duplicate — the table is unusable. Fall back to the transient store so
		// replay protection degrades rather than disappearing, and say so once.
		self::note_fallback();

		return self::claim_via_transient( $nonce );
	}

	/**
	 * The pre-H-1a path, kept as the fallback for when the table cannot be used.
	 *
	 * @return array{fresh:bool,first_seen:int}
	 */
	private static function claim_via_transient( string $nonce ): array {
		$key = self::PREFIX . self::hash( $nonce );
		$at  = (int) get_transient( $key );

		if ( $at > 0 ) {
			return array( 'fresh' => false, 'first_seen' => $at );
		}

		set_transient( $key, time(), ( 2 * Config::TIMESTAMP_WINDOW ) + 60 );

		return array( 'fresh' => true, 'first_seen' => 0 );
	}

	/**
	 * Report an unusable nonce table once an hour.
	 *
	 * Worth knowing about — replay protection is running in its degraded form — but not
	 * worth one log line per request while it lasts.
	 */
	private static function note_fallback(): void {
		if ( false !== get_transient( 'dp_nonce_fallback_noted' ) ) {
			return;
		}

		set_transient( 'dp_nonce_fallback_noted', 1, HOUR_IN_SECONDS );

		DebugLog::error(
			'The replay-protection table could not be written to, so replay protection has fallen back to transients.',
			array(
				'table'       => Schema::nonces_table(),
				'consequence' => 'Requests are still checked, but the check is no longer atomic and a cache eviction could let one replay through.',
				'fix'         => 'Deactivate and reactivate the plugin to recreate the table.',
			)
		);
	}

	/**
	 * Delete expired rows, occasionally.
	 *
	 * Probabilistic rather than on every request: the table only ever holds a few minutes
	 * of traffic, so a DELETE on each call would be pure overhead. `LogRetention` also
	 * sweeps it, so this is only about not letting it drift between cron runs.
	 */
	private static function maybe_prune(): void {
		// wp_rand() rather than rand(): it is what WordPress provides, and the value only
		// needs to be spread out, not unpredictable.
		if ( 1 !== wp_rand( 1, 50 ) ) {
			return;
		}

		self::prune();
	}

	/**
	 * Delete every expired nonce.
	 *
	 * @return int Rows removed.
	 */
	public static function prune(): int {
		global $wpdb;

		$table = Schema::nonces_table();

		return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$wpdb->prepare( "DELETE FROM {$table} WHERE expires_at < %s", gmdate( 'Y-m-d H:i:s' ) ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}

	/**
	 * Hash the nonce into a fixed-width key.
	 *
	 * Hashed rather than stored raw for two reasons: the nonce is attacker-controlled and
	 * must never be interpolated anywhere, and a CHAR(64) column indexes better than a
	 * variable-length one.
	 *
	 * @return string 64 hex characters, or '' when the nonce is unusable.
	 */
	private static function hash( string $nonce ): string {
		if ( '' === $nonce || strlen( $nonce ) > self::MAX_LENGTH ) {
			return '';
		}

		return hash( 'sha256', $nonce );
	}
}
