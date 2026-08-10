<?php
declare(strict_types=1);

namespace IfsDeploy\Queue;

/**
 * Produces a stable content hash for an object package. If the hash is
 * unchanged, the queue skips the update (see QueueRepository::upsert).
 */
final class Hasher {

	/**
	 * Hash a deployment package. Mirrors the PRD strategy:
	 * md5( wp_json_encode( $package ) ).
	 */
	public static function hash( array $package ): string {
		return md5( (string) wp_json_encode( $package ) );
	}
}
