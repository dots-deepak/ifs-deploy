<?php
declare(strict_types=1);

namespace IfsDeploy\Auth;

use IfsDeploy\Support\ClientIp;
use IfsDeploy\Support\DebugLog;

/**
 * Refuses an address that keeps failing authentication (SECURITY.md M-6).
 *
 * ── WHAT THIS IS AND IS NOT FOR ────────────────────────────────────────────────
 *
 * Brute-forcing HMAC-SHA256 is not feasible, so this is NOT key protection. The gap it
 * closes is the *absence of a signal and of a cost*: a wrong signature used to be free and
 * unremarkable, so sustained probing looked exactly like nothing happening.
 *
 * ── WHAT COUNTS AS A FAILURE ───────────────────────────────────────────────────
 *
 * Only genuine authentication failures — missing headers, bad timestamp, expired, unknown
 * key, bad signature. Deliberately NOT counted:
 *
 *  - `duplicate` / `replay`: a proxy retry is a transport quirk, not an attack. Counting it
 *    would lock out the site's own legitimate peer on a bad network day.
 *  - `ip_blocked` / `ip_not_allowed`: already refused, and cheaply. Counting them lets
 *    anyone fill the counter store for free.
 *  - anything that succeeded.
 *
 * ── THE FOOTGUN ───────────────────────────────────────────────────────────────
 *
 * If the paired Staging site holds the WRONG key, it will lock itself out. That is the
 * correct outcome — it genuinely cannot authenticate — but it must never be a mystery. So
 * every lockout writes one log entry naming the address and the reason, the window is
 * short, and a correct request clears the counter immediately.
 */
final class Lockout {

	/** Failures from one address before it is refused. */
	private const THRESHOLD = 12;

	/** How long the counter accumulates over, in seconds. */
	private const WINDOW = 900;

	/** How long a locked-out address stays refused, in seconds. */
	private const COOL_OFF = 900;

	/**
	 * Error codes that count as an authentication failure.
	 *
	 * An allowlist, not a denylist: a new refusal code added later does NOT silently start
	 * counting toward a lockout until someone decides it should.
	 *
	 * @var string[]
	 */
	private const COUNTED = array(
		'ifs_deploy_missing_auth',
		'ifs_deploy_bad_timestamp',
		'ifs_deploy_expired',
		'ifs_deploy_bad_key',
		'ifs_deploy_bad_signature',
	);

	/**
	 * Is this address currently locked out?
	 */
	public static function is_locked(): bool {
		$ip = ClientIp::for_matching();

		if ( '' === $ip ) {
			// An address we cannot identify cannot be rate-limited by address. Refusing
			// everything in that case would take the pair down; the signature still applies.
			return false;
		}

		return false !== get_transient( self::lock_key( $ip ) );
	}

	/**
	 * Seconds left on the current lockout, for the response.
	 */
	public static function retry_after(): int {
		$ip = ClientIp::for_matching();

		if ( '' === $ip ) {
			return 0;
		}

		// Transients do not expose their remaining TTL, so the unlock time is stored as the
		// value rather than inferred.
		$until = (int) get_transient( self::lock_key( $ip ) );

		return max( 0, $until - time() );
	}

	/**
	 * Record the outcome of a verified request.
	 *
	 * @param string $outcome `ApiLog::OK`, or the WP_Error code that refused it.
	 */
	public static function record( string $outcome ): void {
		$ip = ClientIp::for_matching();

		if ( '' === $ip ) {
			return;
		}

		// A request that authenticated clears the slate at once, so a transient
		// misconfiguration that has since been fixed does not keep the peer locked out.
		if ( 'ok' === $outcome ) {
			delete_transient( self::count_key( $ip ) );

			return;
		}

		if ( ! in_array( $outcome, self::COUNTED, true ) ) {
			return;
		}

		$count = (int) get_transient( self::count_key( $ip ) ) + 1;

		set_transient( self::count_key( $ip ), $count, self::WINDOW );

		if ( $count < self::THRESHOLD ) {
			return;
		}

		self::lock( $ip, $outcome, $count );
	}

	/**
	 * Refuse this address for the cool-off period, and say so once.
	 */
	private static function lock( string $ip, string $outcome, int $count ): void {
		$already = false !== get_transient( self::lock_key( $ip ) );

		set_transient( self::lock_key( $ip ), time() + self::COOL_OFF, self::COOL_OFF );

		// The counter is reset so the cool-off is a clean slate rather than an address that
		// re-locks on its very next attempt forever.
		delete_transient( self::count_key( $ip ) );

		if ( $already ) {
			return;
		}

		DebugLog::error(
			sprintf(
				/* translators: 1: number of failures, 2: IP address, 3: minutes */
				__( 'Blocked %2$s for %3$d minutes after %1$d failed authentication attempts.', 'ifs-deploy' ),
				$count,
				$ip,
				(int) round( self::COOL_OFF / 60 )
			),
			array(
				'ip'           => $ip,
				'failures'     => (string) $count,
				'last_outcome' => $outcome,
				// Named explicitly, because the most likely cause is not an attack.
				'if_this_is_your_staging_site' => 'Its API key or secret is wrong. Regenerate the credentials on this site and paste them into Staging under Settings.',
			)
		);
	}

	private static function count_key( string $ip ): string {
		return 'dp_authfail_' . hash( 'sha256', $ip );
	}

	private static function lock_key( string $ip ): string {
		return 'dp_authlock_' . hash( 'sha256', $ip );
	}
}
