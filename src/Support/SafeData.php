<?php
declare(strict_types=1);

namespace IfsDeploy\Support;

/**
 * Deserialisation of data that arrived from the other site.
 *
 * WHY THIS EXISTS — PHP Object Injection.
 *
 * The import endpoints receive a JSON body. JSON carries only scalars and arrays, so
 * nothing in it is an object — but a JSON *string* can contain PHP's serialized format:
 *
 *     {"meta":{"x":["O:8:\"Evil\":1:{s:3:\"cmd\";s:6:\"whoami\";}"]}}
 *
 * The import path used `maybe_unserialize()` on those values, and `maybe_unserialize()`
 * calls `unserialize()` whenever `is_serialized()` says the string looks serialized. That
 * INSTANTIATES the named class, running its `__wakeup()` and later its `__destruct()`.
 * With a POP-chain gadget from WordPress core or any active plugin, that is a remote code
 * execution primitive — reachable by anything that can present a valid signature, which
 * is precisely what the Production side is supposed to be defended against.
 *
 * `allowed_classes => false` makes `unserialize()` return `__PHP_Incomplete_Class` for any
 * object instead of building it. No constructor, no `__wakeup`, no `__destruct`. Arrays and
 * scalars round-trip exactly as before, which is all a content payload legitimately needs.
 *
 * Use `Safe::unserialize()` for ANYTHING that came off the wire or out of a snapshot built
 * from a payload. Plain `maybe_unserialize()` is still correct on the EXPORT side, which
 * reads values this site's own WordPress serialized.
 */
final class SafeData {

	/**
	 * `maybe_unserialize()` without object instantiation.
	 *
	 * @param mixed $value Raw value from a payload or snapshot.
	 * @return mixed Unserialized arrays/scalars, or the value unchanged.
	 */
	public static function unserialize( $value ) {
		// Only strings can be serialized payloads; arrays from JSON pass straight through.
		if ( ! is_string( $value ) ) {
			return $value;
		}

		if ( ! is_serialized( $value ) ) {
			return $value;
		}

		// `false` disallows EVERY class. Objects become __PHP_Incomplete_Class, so no
		// magic method of any gadget class is ever invoked.
		$result = @unserialize( $value, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		// A serialized string that fails to decode is kept verbatim rather than replaced
		// with `false`, which would silently blank a legitimate meta value.
		return false === $result && 'b:0;' !== $value ? $value : $result;
	}

	/**
	 * Strip any `__PHP_Incomplete_Class` left behind by unserialize(), recursively.
	 *
	 * A payload that genuinely contained an object cannot be stored as one, and writing
	 * `__PHP_Incomplete_Class` into post meta would produce warnings on every later read.
	 * Dropping it is the honest outcome: the value was not representable.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	public static function strip_objects( $value ) {
		if ( is_object( $value ) ) {
			return null;
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		$out = array();
		foreach ( $value as $key => $item ) {
			$clean = self::strip_objects( $item );

			if ( null === $clean && ( is_object( $item ) || is_array( $item ) ) ) {
				continue;
			}

			$out[ $key ] = $clean;
		}

		return $out;
	}

	/**
	 * The combination the import paths want: decode without instantiating, then drop
	 * anything that decoded to an object.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	public static function decode( $value ) {
		return self::strip_objects( self::unserialize( $value ) );
	}
}
