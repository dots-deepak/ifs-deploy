<?php
declare(strict_types=1);

namespace IfsDeploy\Support;

/**
 * Minimal logger. Phase 1 records deployment-level logs into the deployments
 * table; a dedicated Logs screen and finer-grained logging arrive in Phase 4.
 */
final class Logger {

	/**
	 * Write to the PHP error log when WP_DEBUG logging is on. Never throws.
	 */
	public static function debug( string $message, array $context = array() ): void {
		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return;
		}

		$line = '[Copperleaf Deploy] ' . $message;
		if ( $context ) {
			$line .= ' ' . wp_json_encode( $context );
		}

		error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
	}
}
