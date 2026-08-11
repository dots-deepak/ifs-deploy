<?php
declare(strict_types=1);

namespace IfsDeploy\Queue;

use IfsDeploy\Support\Json;

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
		$json = Json::encode( $package );

		if ( null !== $json ) {
			return md5( $json );
		}

		/*
		 * The hash MUST keep varying with the package, whatever happens.
		 *
		 * This used to be `md5( (string) wp_json_encode( $package ) )`, so a package
		 * the encoder could not handle produced `md5( '' )` — the same constant for
		 * every such object. The queue compares hashes to decide what changed, so that
		 * one cast broke it in both directions at once: a real edit looked like
		 * "nothing changed" and was never queued, and two unrelated objects looked
		 * identical to each other. Silently, and only for the content odd enough to
		 * trip the encoder in the first place.
		 *
		 * serialize() has no equivalent failure mode for the arrays and scalars a
		 * package holds (and handles the objects maybe_unserialize() can hand back
		 * from meta), so it keeps the one property this function has to have.
		 */
		return md5( serialize( $package ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
	}
}
