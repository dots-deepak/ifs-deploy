<?php
declare(strict_types=1);

namespace IfsDeploy\Support;

/**
 * The single rule for "does Staging's version of this object match Production's?".
 *
 * Shared by Compare & Sync and the Pending Changes verifier so the two can never give
 * different answers about the same object — which would be the worst kind of bug here,
 * since one screen would tell you to push and the other that there is nothing to push.
 */
final class SyncCheck {

	/**
	 * In sync when EITHER:
	 *
	 *  - Staging's current signature equals the signature Staging stamped on the
	 *    Production object at the last deploy (precise — survives Production-side
	 *    content filtering and Production-only meta), OR
	 *  - both sides' freshly computed signatures match (covers cloned or legacy content
	 *    that was never deployed-with-stamp but is genuinely identical).
	 *
	 * Either check can only turn a false "different" into "in sync"; when the content
	 * truly differs, both fail.
	 *
	 * @param string $local    Staging's signature for the object now.
	 * @param string $deployed The signature Staging stamped on Production last deploy.
	 * @param string $remote   Production's freshly computed signature.
	 */
	public static function in_sync( string $local, string $deployed, string $remote ): bool {
		if ( '' === $local ) {
			return false;
		}

		return ( '' !== $deployed && $local === $deployed )
			|| ( '' !== $remote && $local === $remote );
	}
}
