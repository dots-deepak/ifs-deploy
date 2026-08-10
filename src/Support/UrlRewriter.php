<?php
declare(strict_types=1);

namespace IfsDeploy\Support;

/**
 * Environment-URL handling, shared by the importer (which rewrites URLs for real)
 * and by the preview/signature (which must agree with it exactly).
 *
 * Two operations:
 *
 *  - rewrite()    — turn source-site URLs into target-site URLs. Used on import.
 *  - neutralize() — collapse every known environment URL to a token, so content
 *                   can be COMPARED without the domain counting as a difference.
 *
 * Both work from variants(), which is the important part: a site's URL appears in
 * content in more forms than `home_url()` returns. Editors paste `http://` links
 * into an `https://` site, add or drop `www.`, and block markup stores
 * protocol-relative `//host` and escaped `https:\/\/host` forms. Matching only the
 * exact `home_url()` string misses all of those — which meant hardcoded `http://`
 * staging links were never rewritten and leaked into Production, while the diff and
 * Compare screen reported them as differences forever.
 */
final class UrlRewriter {

	/** Stands in for any environment URL when content is compared, not rewritten. */
	public const TOKEN = '[site-url]';

	/**
	 * Every literal form a site's URL can take in stored content, longest first.
	 *
	 * Longest-first matters: `//host` is a substring of `https://host`, so replacing
	 * the short form first would corrupt the long one into `https:[token]`. Any
	 * string that contains another is strictly longer, so ordering by descending
	 * length is sufficient to make replacement safe.
	 *
	 * The path is preserved for subdirectory installs (https://example.com/blog).
	 *
	 * @return string[]
	 */
	public static function variants( string $url ): array {
		$url = untrailingslashit( trim( $url ) );
		if ( '' === $url ) {
			return array();
		}

		$parts = wp_parse_url( $url );
		$host  = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		if ( '' === $host ) {
			return array();
		}

		$path = untrailingslashit( (string) ( $parts['path'] ?? '' ) );
		$bare = (string) preg_replace( '#^www\.#i', '', $host );

		$origins = array();
		foreach ( array_unique( array( $bare, 'www.' . $bare ) ) as $candidate ) {
			foreach ( array( 'https://', 'http://', '//' ) as $scheme ) {
				$origins[] = $scheme . $candidate . $path;
			}
		}

		usort( $origins, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );

		// Pair each form with its escaped-slash twin (block markup stores those).
		$all = array();
		foreach ( $origins as $origin ) {
			$all[] = $origin;
			$all[] = str_replace( '/', '\/', $origin );
		}

		return $all;
	}

	/**
	 * Replace every variant of $from with $to.
	 *
	 * No-op when either side is empty or both refer to the same site (ignoring
	 * scheme and `www.`), which is the deploy-to-self case.
	 */
	public static function rewrite( string $content, string $from, string $to ): string {
		$to = untrailingslashit( trim( $to ) );
		if ( '' === $to ) {
			return $content;
		}

		if ( self::canonical( $from ) === self::canonical( $to ) ) {
			return $content;
		}

		$variants = self::variants( $from );
		if ( empty( $variants ) ) {
			return $content;
		}

		$escaped_to   = str_replace( '/', '\/', $to );
		$replacements = array();

		foreach ( $variants as $variant ) {
			// An escaped source form must be replaced with an escaped target form,
			// or the resulting block markup becomes invalid JSON.
			$replacements[] = ( false !== strpos( $variant, '\/' ) ) ? $escaped_to : $to;
		}

		return str_replace( $variants, $replacements, $content );
	}

	/**
	 * Collapse every variant of every given URL to $token.
	 *
	 * Used for comparison: an environment URL is not a content difference, since the
	 * deploy resolves it per environment. Both sides of a comparison must be passed
	 * through this with the SAME url list, or the token itself becomes a difference.
	 *
	 * Goes further than rewrite() by also collapsing BARE hostnames, because a link
	 * whose visible text is the domain ("copperlfdev.wpenginepowered.com") differs
	 * per environment just as much as its href does. Bare hosts are matched with
	 * word boundaries so `example.com` never matches inside `sub.example.com`.
	 *
	 * @param string[] $urls  Environment URLs (empty entries are ignored).
	 */
	public static function neutralize( string $content, array $urls, string $token = self::TOKEN ): string {
		$variants = array();
		$hosts    = array();

		foreach ( $urls as $url ) {
			$url      = (string) $url;
			$variants = array_merge( $variants, self::variants( $url ) );
			$hosts    = array_merge( $hosts, self::host_forms( $url ) );
		}

		// Scheme-prefixed forms first: they contain the bare host, so collapsing the
		// host first would leave a mangled "https://[token]".
		if ( ! empty( $variants ) ) {
			$variants = array_unique( $variants );
			usort( $variants, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );
			$content = str_replace( $variants, $token, $content );
		}

		foreach ( self::sorted_unique( $hosts ) as $host ) {
			$content = (string) preg_replace(
				'#(?<![\w.-])' . preg_quote( $host, '#' ) . '(?![\w.-])#i',
				$token,
				$content
			);
		}

		return $content;
	}

	/**
	 * rewrite(), applied recursively through nested arrays.
	 *
	 * Needed for post meta: ACF stores repeaters, flexible content and groups as
	 * serialized arrays, and a URL can sit at any depth inside them. Callers must
	 * pass ALREADY-UNSERIALIZED values — running a string replace over a serialized
	 * blob would leave its length prefixes wrong and corrupt the value.
	 *
	 * @param mixed $value
	 *
	 * @return mixed Same shape, strings rewritten.
	 */
	public static function rewrite_deep( $value, string $from, string $to ) {
		if ( is_string( $value ) ) {
			return self::rewrite( $value, $from, $to );
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::rewrite_deep( $item, $from, $to );
			}
		}

		return $value;
	}

	/**
	 * neutralize(), applied recursively through nested arrays. Same caveat as
	 * rewrite_deep(): pass unserialized values.
	 *
	 * @param mixed    $value
	 * @param string[] $urls
	 *
	 * @return mixed Same shape, strings neutralized.
	 */
	public static function neutralize_deep( $value, array $urls, string $token = self::TOKEN ) {
		if ( is_string( $value ) ) {
			return self::neutralize( $value, $urls, $token );
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::neutralize_deep( $item, $urls, $token );
			}
		}

		return $value;
	}

	/**
	 * Bare hostname forms for a URL, with and without `www.`.
	 *
	 * @return string[]
	 */
	private static function host_forms( string $url ): array {
		$parts = wp_parse_url( untrailingslashit( trim( $url ) ) );
		$host  = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		if ( '' === $host ) {
			return array();
		}

		$bare = (string) preg_replace( '#^www\.#i', '', $host );

		return array( 'www.' . $bare, $bare );
	}

	/**
	 * @param string[] $values
	 *
	 * @return string[] Unique, longest first.
	 */
	private static function sorted_unique( array $values ): array {
		$values = array_unique( array_filter( $values ) );
		usort( $values, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );

		return $values;
	}

	/**
	 * Scheme- and www-insensitive identity for a site URL, for same-site checks.
	 */
	private static function canonical( string $url ): string {
		$parts = wp_parse_url( untrailingslashit( trim( $url ) ) );
		$host  = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';

		return preg_replace( '#^www\.#i', '', $host ) . untrailingslashit( (string) ( $parts['path'] ?? '' ) );
	}
}
