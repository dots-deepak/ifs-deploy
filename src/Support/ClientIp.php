<?php
declare(strict_types=1);

namespace IfsDeploy\Support;

/**
 * Where a request actually came from.
 *
 * THIS IS THE PART THAT IS EASY TO GET WRONG, so read before changing it.
 *
 * `REMOTE_ADDR` is the only value the web server observed itself. Every `X-Forwarded-For`,
 * `X-Real-IP`, `CF-Connecting-IP` and friend is just a REQUEST HEADER — anyone can send
 * any value in one. A monitor that trusts them by default is worse than no monitor:
 *
 *   - the attacker writes whatever IP they like into your security log, so the log lies;
 *   - and any per-IP rate limit or lockout is bypassed by rotating the header, so the
 *     control silently stops working while still appearing to.
 *
 * So `REMOTE_ADDR` is the default and the only value treated as trusted. WordPress core
 * does exactly the same thing, and says why (`wp-includes/comment.php`):
 *
 *     We use `REMOTE_ADDR` here directly. If you are behind a proxy, you should ensure
 *     that it is properly set, such as in wp-config.php, for your environment.
 *
 * THE OPPOSITE PROBLEM IS REAL TOO. Behind a CDN or a managed host's load balancer,
 * `REMOTE_ADDR` is the proxy — so every request appears to come from one address and the
 * monitor is useless. That is why a forwarded header CAN be trusted, but only when the
 * site owner has explicitly said which one, having understood that their edge overwrites
 * it. Both values are always recorded, so a mismatch is visible on the Logs screen rather
 * than hidden.
 */
final class ClientIp {

	/** Option holding the name of the header to trust, or '' for none. */
	public const OPTION_HEADER = 'ifs_deploy_trusted_ip_header';

	/** Option: store IPs anonymised (GDPR). */
	public const OPTION_ANONYMISE = 'ifs_deploy_anonymise_ips';

	/**
	 * Headers a site owner may opt into, mapped to their `$_SERVER` key.
	 *
	 * A fixed list, not free text: an arbitrary header name would let a
	 * mis-configuration point this at something an attacker fully controls with no
	 * proxy in front of it at all.
	 *
	 * @return array<string,string> label => $_SERVER key
	 */
	public static function trustable_headers(): array {
		return array(
			''                    => __( 'None — use the connecting address (recommended)', 'ifs-deploy' ),
			'HTTP_CF_CONNECTING_IP' => __( 'CF-Connecting-IP (Cloudflare)', 'ifs-deploy' ),
			'HTTP_X_REAL_IP'        => __( 'X-Real-IP (nginx proxy)', 'ifs-deploy' ),
			'HTTP_X_FORWARDED_FOR'  => __( 'X-Forwarded-For (generic proxy / load balancer)', 'ifs-deploy' ),
		);
	}

	/**
	 * The configured trusted header's `$_SERVER` key, or '' when none is trusted.
	 */
	public static function trusted_header(): string {
		$stored = (string) get_option( self::OPTION_HEADER, '' );

		// Only a value from the fixed list is honoured.
		return array_key_exists( $stored, self::trustable_headers() ) ? $stored : '';
	}

	public static function set_trusted_header( string $header ): string {
		$header = array_key_exists( $header, self::trustable_headers() ) ? $header : '';

		update_option( self::OPTION_HEADER, $header );

		return $header;
	}

	public static function anonymises(): bool {
		return (bool) get_option( self::OPTION_ANONYMISE, false );
	}

	/**
	 * The address to attribute this request to, ready for storage.
	 *
	 * @return array{ip:string,raw:string,remote_addr:string,forwarded:string,trusted:bool}
	 *   `ip` is what to RECORD — masked when anonymising is on. `raw` is the same address
	 *   unmasked and is what access control must match on: with anonymising enabled `ip`
	 *   is `203.0.113.0`, so an allowlist entry of `203.0.113.9` would never match it and
	 *   the list would silently do nothing. `remote_addr` is always the connecting address.
	 *   `forwarded` is the header value when one was present, kept so a spoofing attempt is
	 *   visible even when the header is NOT trusted. `trusted` says whether `ip` came from
	 *   a header the owner opted into.
	 */
	public static function resolve(): array {
		$remote    = self::sanitize( isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '' );
		$header    = self::trusted_header();
		$forwarded = '';

		// Read the header for the RECORD even when it is not trusted — an unexpected
		// X-Forwarded-For on a site with no proxy is itself worth seeing.
		foreach ( array_keys( self::trustable_headers() ) as $candidate ) {
			if ( '' !== $candidate && ! empty( $_SERVER[ $candidate ] ) ) {
				$forwarded = self::sanitize_list( (string) wp_unslash( $_SERVER[ $candidate ] ) );
				break;
			}
		}

		$ip      = $remote;
		$trusted = false;

		if ( '' !== $header && ! empty( $_SERVER[ $header ] ) ) {
			// X-Forwarded-For is a comma-separated chain, client first. The LEFTMOST entry
			// is the client as reported by the first proxy; everything after it was added
			// by intermediaries. Take the first, and only because the owner opted in.
			$chain     = self::sanitize_list( (string) wp_unslash( $_SERVER[ $header ] ) );
			$candidate = self::sanitize( trim( (string) strtok( $chain, ',' ) ) );

			if ( '' !== $candidate ) {
				$ip      = $candidate;
				$trusted = true;
			}
		}

		return array(
			'ip'          => self::maybe_anonymise( $ip ),
			'raw'         => $ip,
			'remote_addr' => self::maybe_anonymise( $remote ),
			'forwarded'   => $forwarded,
			'trusted'     => $trusted,
		);
	}

	/**
	 * Shorthand for the address to attribute a request to, as it will be recorded.
	 */
	public static function get(): string {
		return (string) self::resolve()['ip'];
	}

	/**
	 * The address to make ACCESS DECISIONS on — never masked.
	 *
	 * Kept separate from `get()` on purpose. Matching an allow/block list against a
	 * masked address would compare `203.0.113.0` to `203.0.113.9` and never match, so
	 * turning on anonymised logging would silently disable the lists.
	 */
	public static function for_matching(): string {
		return (string) self::resolve()['raw'];
	}

	/**
	 * Drop the last octet (IPv4) or the interface bits (IPv6) when anonymising.
	 *
	 * IP addresses are personal data under the GDPR. Anonymising keeps the monitoring
	 * useful — a /24 is still enough to spot one source hammering the endpoint — while
	 * storing less. Uses core's own implementation so the behaviour matches the rest of
	 * WordPress's privacy tooling.
	 */
	private static function maybe_anonymise( string $ip ): string {
		if ( '' === $ip || ! self::anonymises() ) {
			return $ip;
		}

		return (string) wp_privacy_anonymize_ip( $ip );
	}

	/**
	 * A single address, or '' if it is not one.
	 *
	 * Validated with `filter_var` rather than a regex: the value is attacker-controlled
	 * and is about to be stored and displayed, so "looks like an IP" is not good enough.
	 */
	private static function sanitize( string $value ): string {
		$value = trim( $value );

		if ( '' === $value || strlen( $value ) > 45 ) {
			return '';
		}

		return false !== filter_var( $value, FILTER_VALIDATE_IP ) ? $value : '';
	}

	/**
	 * A forwarded chain, reduced to valid addresses only.
	 *
	 * Stored for the record, so it is length-capped and stripped of anything that is not
	 * an address — the raw header is arbitrary attacker input.
	 */
	private static function sanitize_list( string $value ): string {
		$parts = array_slice( explode( ',', $value ), 0, 10 );
		$clean = array();

		foreach ( $parts as $part ) {
			$ip = self::sanitize( $part );

			if ( '' !== $ip ) {
				$clean[] = $ip;
			}
		}

		return implode( ', ', $clean );
	}
}
