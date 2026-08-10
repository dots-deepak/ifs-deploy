<?php
declare(strict_types=1);

namespace IfsDeploy\Support;

/**
 * Which addresses may call the signed API, and which are refused outright.
 *
 * Enforced on the RECEIVING side, first thing in `Auth\Verifier::check()` — before any
 * cryptography, so a refused address costs almost nothing.
 *
 * ── THE ORDER, AND WHY ─────────────────────────────────────────────────────────
 *
 *   1. On the block list  → refused. Block always wins, even over the allow list, so
 *                           there is never a question of which rule applied.
 *   2. Allow list EMPTY   → allowed. An empty list means "no restriction", not "deny
 *                           everything". Reading it the other way would mean simply
 *                           installing the plugin breaks every deploy.
 *   3. Allow list set     → allowed only if it matches.
 *
 * ── THE FOOTGUN THIS CLASS IS SHAPED AROUND ────────────────────────────────────
 *
 * An allow list is the one setting here that can lock a working pair out of itself. Get
 * the address wrong and Production refuses its own Staging site: deploys fail with 403 and
 * nothing on the Staging screen explains why. Three things exist because of that:
 *
 *   - `is_configured()` so callers can tell "no list" from "list that matches nothing".
 *   - Every refusal is written to the access log AND the event log, so the cause is on the
 *     Logs screen rather than only in an HTTP status.
 *   - The Settings screen offers the addresses actually observed (from `ApiLog`) to copy,
 *     instead of asking the admin to guess.
 *
 * ── A PROXY MAKES THIS WORSE, SO SAY SO ────────────────────────────────────────
 *
 * Behind a CDN or load balancer, `REMOTE_ADDR` is the proxy. Allow-listing the real
 * Staging address then blocks everything, because that is not the address being matched.
 * Matching uses `ClientIp::for_matching()`, which honours the trusted-header setting — the
 * two features have to be configured together, and the UI says so.
 *
 * ── WHAT A BLOCK LIST IS AND IS NOT ────────────────────────────────────────────
 *
 * A speed bump, not a wall. An attacker on a rotating address or behind a large NAT simply
 * reappears. It is worth having to stop a specific noisy source cheaply; it is not a
 * substitute for the signature, which is the actual access control.
 */
final class IpAccess {

	public const OPTION_ALLOW = 'ifs_deploy_ip_allow';
	public const OPTION_BLOCK = 'ifs_deploy_ip_block';

	/** Entries per list. A cap so one paste cannot make every request scan thousands. */
	public const MAX_ENTRIES = 200;

	public const RESULT_ALLOWED     = 'allowed';
	public const RESULT_BLOCKED     = 'blocked';
	public const RESULT_NOT_ALLOWED = 'not_allowed';

	/**
	 * @return string[] Allow-list entries: single addresses or CIDR ranges.
	 */
	public static function allow_list(): array {
		return self::read( self::OPTION_ALLOW );
	}

	/**
	 * @return string[] Block-list entries.
	 */
	public static function block_list(): array {
		return self::read( self::OPTION_BLOCK );
	}

	/**
	 * Is an allow list in force? An empty list is not a restriction.
	 */
	public static function is_configured(): bool {
		return array() !== self::allow_list();
	}

	/**
	 * Store a list from admin input.
	 *
	 * @param string $raw One entry per line (or comma separated).
	 * @return array{stored:string[],rejected:string[]} What was kept, and what was not.
	 */
	public static function save( string $option, string $raw ): array {
		$candidates = preg_split( '/[\r\n,]+/', $raw ) ?: array();
		$stored     = array();
		$rejected   = array();

		foreach ( $candidates as $candidate ) {
			$candidate = trim( (string) $candidate );

			if ( '' === $candidate ) {
				continue;
			}

			if ( count( $stored ) >= self::MAX_ENTRIES ) {
				$rejected[] = $candidate;
				continue;
			}

			$clean = self::normalize( $candidate );

			if ( '' === $clean ) {
				// Reported back rather than dropped silently: a typo in an allow list is
				// how a pair locks itself out, and the admin needs to see it.
				$rejected[] = $candidate;
				continue;
			}

			if ( ! in_array( $clean, $stored, true ) ) {
				$stored[] = $clean;
			}
		}

		update_option( $option, $stored, false );

		return array(
			'stored'   => $stored,
			'rejected' => $rejected,
		);
	}

	/**
	 * Add one address to a list.
	 *
	 * Used by the one-click actions on the Logs screen, where the address comes from a row
	 * that has already been observed — which is the whole point: nobody retypes an IPv6
	 * address, so nobody mistypes one.
	 *
	 * @return array{changed:bool,message:string}
	 */
	public static function add( string $option, string $ip ): array {
		$clean = self::normalize( $ip );

		if ( '' === $clean ) {
			return array(
				'changed' => false,
				'message' => __( 'That is not a valid address or range.', 'ifs-deploy' ),
			);
		}

		$list = self::read( $option );

		if ( in_array( $clean, $list, true ) ) {
			return array(
				'changed' => false,
				'message' => sprintf(
					/* translators: %s: IP address */
					__( '%s is already on that list.', 'ifs-deploy' ),
					$clean
				),
			);
		}

		if ( count( $list ) >= self::MAX_ENTRIES ) {
			return array(
				'changed' => false,
				'message' => sprintf(
					/* translators: %d: maximum number of entries */
					__( 'That list already holds the maximum of %d entries.', 'ifs-deploy' ),
					self::MAX_ENTRIES
				),
			);
		}

		$list[] = $clean;
		update_option( $option, $list, false );

		$is_allow = self::OPTION_ALLOW === $option;

		/*
		 * Warn on the transition from "no allow list" to "one entry", because that single
		 * click changes the rule for EVERY other address from allowed to refused. Adding
		 * the second entry is unremarkable; adding the first is not.
		 */
		if ( $is_allow && 1 === count( $list ) ) {
			return array(
				'changed' => true,
				'message' => sprintf(
					/* translators: %s: IP address */
					__( '%s is now the ONLY address allowed to use the API. Every other address, including any other Staging site, is refused from now on.', 'ifs-deploy' ),
					$clean
				),
			);
		}

		return array(
			'changed' => true,
			'message' => $is_allow
				? sprintf(
					/* translators: %s: IP address */
					__( '%s added to the allowed addresses.', 'ifs-deploy' ),
					$clean
				)
				: sprintf(
					/* translators: %s: IP address */
					__( '%s blocked. It can no longer reach the API.', 'ifs-deploy' ),
					$clean
				),
		);
	}

	/**
	 * Remove one address from a list.
	 *
	 * @return array{changed:bool,message:string}
	 */
	public static function remove( string $option, string $ip ): array {
		$clean = self::normalize( $ip );
		$list  = self::read( $option );
		$kept  = array_values( array_filter( $list, static fn( string $entry ): bool => $entry !== $clean ) );

		if ( count( $kept ) === count( $list ) ) {
			return array(
				'changed' => false,
				'message' => sprintf(
					/* translators: %s: IP address */
					__( '%s was not on that list. Note that an address covered by a CIDR range has to be removed by editing the range in Settings.', 'ifs-deploy' ),
					$ip
				),
			);
		}

		update_option( $option, $kept, false );

		$is_allow = self::OPTION_ALLOW === $option;

		// Removing the LAST allow-list entry lifts the restriction entirely — the opposite
		// of what "remove" might sound like, so it is stated.
		if ( $is_allow && array() === $kept ) {
			return array(
				'changed' => true,
				'message' => sprintf(
					/* translators: %s: IP address */
					__( '%s removed. The allow list is now empty, so any address may use the API again.', 'ifs-deploy' ),
					$clean
				),
			);
		}

		return array(
			'changed' => true,
			'message' => sprintf(
				/* translators: %s: IP address */
				$is_allow ? __( '%s removed from the allowed addresses.', 'ifs-deploy' ) : __( '%s unblocked.', 'ifs-deploy' ),
				$clean
			),
		);
	}

	/**
	 * Decide whether an address may call the API.
	 *
	 * @param string $ip Unmasked address — see ClientIp::for_matching().
	 * @return string One of the RESULT_* constants.
	 */
	public static function evaluate( string $ip ): string {
		// An address we could not determine is NOT refused. Failing closed here would take
		// the whole pair down on any server that presents REMOTE_ADDR in a form we reject,
		// and the signature is still doing the real work. The access log records the
		// blank address, so the situation is visible.
		if ( '' === $ip ) {
			return self::RESULT_ALLOWED;
		}

		if ( self::matches_any( $ip, self::block_list() ) ) {
			return self::RESULT_BLOCKED;
		}

		$allow = self::allow_list();

		if ( array() === $allow ) {
			return self::RESULT_ALLOWED;
		}

		return self::matches_any( $ip, $allow ) ? self::RESULT_ALLOWED : self::RESULT_NOT_ALLOWED;
	}

	/**
	 * Does this address match any entry in the list?
	 */
	public static function matches_any( string $ip, array $entries ): bool {
		foreach ( $entries as $entry ) {
			if ( self::matches( $ip, (string) $entry ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Match one address against one entry — an exact address or a CIDR range.
	 *
	 * Compared as PACKED BYTES via `inet_pton`, not as strings. Two spellings of the same
	 * IPv6 address (`2001:db8::1` and `2001:0db8:0000:0000:0000:0000:0000:0001`) are the
	 * same address but different strings, so a string comparison would miss one of them.
	 */
	public static function matches( string $ip, string $entry ): bool {
		$ip    = trim( $ip );
		$entry = trim( $entry );

		if ( '' === $ip || '' === $entry ) {
			return false;
		}

		$packed_ip = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( false === $packed_ip ) {
			return false;
		}

		if ( false === strpos( $entry, '/' ) ) {
			$packed_entry = @inet_pton( $entry ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

			return false !== $packed_entry && hash_equals( $packed_entry, $packed_ip );
		}

		list( $subnet, $bits ) = array_pad( explode( '/', $entry, 2 ), 2, '' );

		$packed_subnet = @inet_pton( trim( $subnet ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( false === $packed_subnet ) {
			return false;
		}

		// An IPv4 address never matches an IPv6 range, or vice versa: their packed forms
		// are 4 and 16 bytes, and comparing across them would be meaningless.
		if ( strlen( $packed_subnet ) !== strlen( $packed_ip ) ) {
			return false;
		}

		$max  = strlen( $packed_ip ) * 8;
		$bits = (int) $bits;

		if ( $bits < 0 || $bits > $max ) {
			return false;
		}

		// /0 matches everything of that family. Allowed, but see normalize(): it is
		// refused on input, because "allow the entire internet" is never the intent.
		if ( 0 === $bits ) {
			return true;
		}

		$whole_bytes = intdiv( $bits, 8 );
		$extra_bits  = $bits % 8;

		if ( $whole_bytes > 0 && ! hash_equals( substr( $packed_subnet, 0, $whole_bytes ), substr( $packed_ip, 0, $whole_bytes ) ) ) {
			return false;
		}

		if ( 0 === $extra_bits ) {
			return true;
		}

		// Compare the remaining bits of the boundary byte under a mask.
		$mask = ~( ( 1 << ( 8 - $extra_bits ) ) - 1 ) & 0xFF;

		return ( ord( $packed_ip[ $whole_bytes ] ) & $mask ) === ( ord( $packed_subnet[ $whole_bytes ] ) & $mask );
	}

	/**
	 * Canonicalise one entry, or '' if it is not usable.
	 */
	public static function normalize( string $entry ): string {
		$entry = trim( $entry );

		if ( '' === $entry || strlen( $entry ) > 60 ) {
			return '';
		}

		if ( false === strpos( $entry, '/' ) ) {
			return false !== filter_var( $entry, FILTER_VALIDATE_IP ) ? $entry : '';
		}

		list( $subnet, $bits ) = array_pad( explode( '/', $entry, 2 ), 2, '' );

		$subnet = trim( $subnet );
		$packed = @inet_pton( $subnet ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( false === $packed || '' === $bits || ! ctype_digit( $bits ) ) {
			return '';
		}

		$max  = strlen( $packed ) * 8;
		$bits = (int) $bits;

		// `/0` is refused deliberately. In a block list it would refuse every request on
		// the site; in an allow list it is a no-op that looks like a restriction. Neither
		// is ever what someone means to type.
		if ( $bits < 1 || $bits > $max ) {
			return '';
		}

		return $subnet . '/' . $bits;
	}

	/**
	 * How an address stands relative to the lists, for display on the Logs screen.
	 *
	 * @return string '' when no rule applies to it.
	 */
	public static function status( string $ip ): string {
		if ( '' === $ip ) {
			return '';
		}

		if ( self::matches_any( $ip, self::block_list() ) ) {
			return self::RESULT_BLOCKED;
		}

		if ( self::is_configured() ) {
			return self::matches_any( $ip, self::allow_list() ) ? self::RESULT_ALLOWED : self::RESULT_NOT_ALLOWED;
		}

		return '';
	}

	/**
	 * @return string[] Validated entries from an option.
	 */
	private static function read( string $option ): array {
		$stored = get_option( $option, array() );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$out = array();
		foreach ( array_slice( $stored, 0, self::MAX_ENTRIES ) as $entry ) {
			// Re-validated on READ as well as on write: a hand-edited option must not be
			// able to put an unparseable entry into the matcher.
			$clean = self::normalize( (string) $entry );

			if ( '' !== $clean ) {
				$out[] = $clean;
			}
		}

		return $out;
	}
}
