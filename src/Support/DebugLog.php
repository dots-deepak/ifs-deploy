<?php
declare(strict_types=1);

namespace IfsDeploy\Support;

/**
 * A small persisted ring buffer of diagnostic events, readable from the Logs
 * admin screen.
 *
 * Logger writes to PHP's error log, which is fine for a developer but useless when
 * the person hitting a failure cannot read the server's log files — and useless on
 * the OTHER site, where the actual error usually happens. This keeps the last
 * entries in an option so both Staging and Production can be inspected from their
 * own admin.
 *
 * Stored in an option rather than a table so no schema migration is needed; it is
 * capped and autoload is off, so it never becomes a page-load cost.
 */
final class DebugLog {

	private const OPTION = 'ifs_deploy_debug_log';

	/** Entries retained, newest first. */
	private const MAX_ENTRIES = 200;

	public const LEVEL_ERROR   = 'error';
	public const LEVEL_WARNING = 'warning';
	public const LEVEL_INFO    = 'info';

	public static function error( string $message, array $context = array() ): void {
		self::record( self::LEVEL_ERROR, $message, $context );
	}

	public static function warning( string $message, array $context = array() ): void {
		self::record( self::LEVEL_WARNING, $message, $context );
	}

	public static function info( string $message, array $context = array() ): void {
		self::record( self::LEVEL_INFO, $message, $context );
	}

	/**
	 * Append an entry, trimming to the retention cap.
	 */
	public static function record( string $level, string $message, array $context = array() ): void {
		// Redact ONCE, then use the redacted copy everywhere. The error-log mirror below
		// used to receive the raw $context, so with WP_DEBUG on any secret in it was
		// written to debug.log in clear — a file that is world-readable on a
		// mis-permissioned host and routinely pasted into support threads.
		$safe = self::sanitize_context( $context );

		$entries = self::all();

		array_unshift(
			$entries,
			array(
				'time'    => current_time( 'mysql' ),
				'level'   => $level,
				'role'    => Config::role(),
				'message' => $message,
				'context' => $safe,
			)
		);

		update_option( self::OPTION, array_slice( $entries, 0, self::MAX_ENTRIES ), false );

		// Mirror to the PHP error log when debugging is on, so tail -f still works.
		Logger::debug( $message, $safe );
	}

	/**
	 * @return array<int,array{time:string,level:string,role:string,message:string,context:array}>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	public static function clear(): void {
		update_option( self::OPTION, array(), false );
	}

	/**
	 * Overwrite the log with an already-filtered list.
	 *
	 * Exists for LogRetention, which drops aged-out entries and writes the survivors
	 * back. Kept here rather than letting the caller touch the option so the cap and the
	 * `autoload = false` flag are applied in exactly one place.
	 *
	 * @param array<int,array> $entries Newest first.
	 */
	/**
	 * Drop entries whose message contains `$needle`, optionally only at `$level`.
	 *
	 * Used when an admin ACKNOWLEDGES something the log warned about — allowing an address
	 * resolves the "new address" warning for it. Leaving the warning standing after the
	 * admin has explicitly approved the address makes the log a list of things that cannot
	 * be cleared, which is how people learn to ignore it.
	 *
	 * @return int Entries removed.
	 */
	public static function forget( string $needle, string $level = '' ): int {
		if ( '' === $needle ) {
			return 0;
		}

		$entries = self::all();

		$kept = array_values(
			array_filter(
				$entries,
				static function ( $entry ) use ( $needle, $level ): bool {
					if ( ! is_array( $entry ) ) {
						return false;
					}

					if ( '' !== $level && $level !== ( $entry['level'] ?? '' ) ) {
						return true;
					}

					return false === strpos( (string) ( $entry['message'] ?? '' ), $needle );
				}
			)
		);

		$removed = count( $entries ) - count( $kept );

		if ( $removed > 0 ) {
			self::replace( $kept );
		}

		return $removed;
	}

	public static function replace( array $entries ): void {
		update_option( self::OPTION, array_slice( array_values( $entries ), 0, self::MAX_ENTRIES ), false );
	}

	/**
	 * Keep entries small and free of secrets.
	 *
	 * Truncates long values (a fatal-error HTML page can be enormous) and redacts
	 * anything that looks like a credential, since the log is rendered in the admin
	 * and could be pasted into a support thread.
	 */
	private static function sanitize_context( array $context ): array {
		$out = array();

		foreach ( $context as $key => $value ) {
			$key = (string) $key;

			if ( preg_match( '#secret|api_key|password|token|nonce|signature|auth|credential#i', $key ) ) {
				$out[ $key ] = '[redacted]';
				continue;
			}

			if ( is_scalar( $value ) || null === $value ) {
				$value = (string) $value;
			} else {
				$value = (string) wp_json_encode( $value );
			}

			/*
			 * Redact by VALUE as well as by key.
			 *
			 * Key matching alone missed the common cases: a response body logged under
			 * `detail` that happens to contain a secret, or a URL logged under `url` with
			 * a key in its query string. Credentials are recognisable by their own
			 * prefixes (`dpk_`/`dps_` from Credentials::regenerate()), so they can be
			 * found wherever they appear.
			 */
			$value = (string) preg_replace( '#\b(dpk_|dps_)[A-Za-z0-9]+#', '$1[redacted]', $value );

			// Anything shaped like a bare HMAC/hex secret, in case a future writer logs
			// one without a recognisable prefix or key name.
			$value = (string) preg_replace( '#\b[0-9a-f]{64,}\b#i', '[redacted-hash]', $value );

			$out[ $key ] = ( strlen( $value ) > 2000 ) ? substr( $value, 0, 2000 ) . '… [truncated]' : $value;
		}

		return $out;
	}
}
