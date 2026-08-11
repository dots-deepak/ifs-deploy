<?php
declare(strict_types=1);

namespace IfsDeploy\Support;

/**
 * JSON encoding that reports failure instead of swallowing it.
 *
 * `wp_json_encode()` returns **false** when a value cannot be encoded — invalid
 * UTF-8 that survives core's sanity pass, a structure deeper than the 512-level
 * limit (deeply nested ACF flexible content reaches it), INF/NAN, or a recursive
 * reference. Every call site in this plugin used to cast that straight to
 * `(string)`, which turns a hard failure into an empty string — and an empty string
 * is not obviously wrong anywhere it lands:
 *
 *   - `md5( '' )` is a valid-looking hash, and the SAME one for every object that
 *     failed, so the queue reads unrelated content as identical;
 *   - an empty request body is a well-formed HTTP POST that Production answers;
 *   - an empty snapshot column decodes to null, so the restore point exists in the
 *     History screen but can never restore anything.
 *
 * None of those surface an error. That is the whole reason this class exists: it
 * returns null, so each caller has to decide what its own failure looks like, and
 * every one of those decisions fails closed.
 */
final class Json {

	/**
	 * Encode a value, or null when it cannot be encoded.
	 *
	 * @param mixed $value
	 * @param int   $flags JSON_* flags, as passed to wp_json_encode().
	 */
	public static function encode( $value, int $flags = 0 ): ?string {
		$json = wp_json_encode( $value, $flags );

		return is_string( $json ) ? $json : null;
	}
}
