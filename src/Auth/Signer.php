<?php
declare(strict_types=1);

namespace IfsDeploy\Auth;

/**
 * Builds the canonical string and HMAC-SHA256 signature shared by client and
 * server. Both sides MUST construct the canonical string identically.
 */
final class Signer {

	public const HEADER_KEY       = 'X-IFS-Deploy-Key';
	public const HEADER_TIMESTAMP = 'X-IFS-Deploy-Timestamp';
	public const HEADER_NONCE     = 'X-IFS-Deploy-Nonce';
	public const HEADER_SIGNATURE = 'X-IFS-Deploy-Signature';

	/**
	 * v1 canonical string: timestamp \n nonce \n sha256(raw body).
	 *
	 * Hashing the body keeps the canonical string a fixed size regardless of
	 * payload, and binds the signature to the exact bytes sent.
	 *
	 * Kept because the verifier still accepts v1 from a peer that has not been upgraded yet.
	 * New signatures use `canonical_v2()`.
	 */
	public static function canonical( string $timestamp, string $nonce, string $body ): string {
		return $timestamp . "\n" . $nonce . "\n" . hash( 'sha256', $body );
	}

	/**
	 * v2 canonical string, with the ROUTE bound in (SECURITY.md M-3).
	 *
	 * v1 signed only the timestamp, nonce and body, so a signature valid for `/index` was
	 * arithmetically valid for `/import` carrying the same body. Each endpoint validates its
	 * own body shape, so it was hard to exploit — but binding the route is what every serious
	 * request-signing scheme does (AWS SigV4 included), and leaving it out means relying on
	 * the endpoints' input validation to be the only thing standing between them.
	 *
	 * The route is normalised to its last path segment, exactly as the verifier derives it,
	 * so the two sides cannot disagree over a leading slash or the namespace prefix.
	 */
	public static function canonical_v2( string $timestamp, string $nonce, string $route, string $body ): string {
		return $timestamp . "\n" . $nonce . "\n" . self::normalize_route( $route ) . "\n" . hash( 'sha256', $body );
	}

	/**
	 * The route as it appears in the signed material.
	 *
	 * Both sides must produce the same string from what they each have: the client holds
	 * `import`, the server holds `/ifs-deploy/v1/import`. Reducing to the last segment and
	 * stripping anything outside `[a-z0-9-_]` makes both land on `import`.
	 */
	public static function normalize_route( string $route ): string {
		$parts = array_values( array_filter( explode( '/', $route ) ) );
		$last  = (string) ( end( $parts ) ?: '' );

		return (string) preg_replace( '/[^a-z0-9\-_]/i', '', $last );
	}

	/**
	 * v1 signature. Retained for the compatibility window only.
	 */
	public static function sign( string $timestamp, string $nonce, string $body, string $secret_key ): string {
		return hash_hmac( 'sha256', self::canonical( $timestamp, $nonce, $body ), $secret_key );
	}

	/**
	 * v2 signature.
	 */
	public static function sign_v2( string $timestamp, string $nonce, string $route, string $body, string $secret_key ): string {
		return hash_hmac( 'sha256', self::canonical_v2( $timestamp, $nonce, $route, $body ), $secret_key );
	}

	/**
	 * Sign a RESPONSE (SECURITY.md H-3).
	 *
	 * The request's nonce is reused deliberately: it binds this response to the one request
	 * that asked for it. Without that binding a captured response could be replayed as the
	 * answer to a different request.
	 *
	 * ── WHAT IS SIGNED, AND WHY IT IS NOT THE RAW BYTES ────────────────────────
	 *
	 * Ideally the exact bytes. The server cannot see them: by the time a REST response is
	 * serialised, the filter that could add a header has already run, and the final encoding
	 * depends on request-time flags (`?_pretty` adds JSON_PRETTY_PRINT). Hashing what we
	 * *think* was sent would break the moment those differed.
	 *
	 * So both sides sign the NORMALISED body — `wp_json_encode()` of the decoded data. The
	 * server encodes the array it is returning; the client decodes what arrived and re-encodes
	 * it the same way. Deterministic for the data these endpoints carry (strings, ints, bools,
	 * nested arrays), and immune to whitespace and escaping differences in transit.
	 *
	 * @param array $data The response payload as an array, on either side.
	 */
	public static function sign_response( string $timestamp, string $nonce, array $data, string $secret_key ): string {
		return hash_hmac(
			'sha256',
			$timestamp . "\n" . $nonce . "\n" . hash( 'sha256', (string) wp_json_encode( $data ) ),
			$secret_key
		);
	}

	/**
	 * Timing-safe comparison of an expected vs presented signature.
	 */
	public static function verify( string $expected, string $presented ): bool {
		if ( '' === $expected || '' === $presented ) {
			return false;
		}
		return hash_equals( $expected, $presented );
	}
}
