<?php
declare(strict_types=1);

namespace IfsDeploy\Auth;

use IfsDeploy\Support\DebugLog;

/**
 * Which signing protocol the paired site speaks, and how we find out.
 *
 * ── THE PROBLEM THIS SOLVES ────────────────────────────────────────────────────
 *
 * H-3 and M-3 both change the WIRE FORMAT: responses gain a signature, and the request
 * signature gains the route. A naive rollout means whichever site is updated first stops
 * being able to talk to the other, and on a live pair that is an outage — not a warning, an
 * outage, because every deploy fails signature verification.
 *
 * Updating both sites in the same instant is not something a plugin can require. So the two
 * sides negotiate instead:
 *
 *  - The **verifier** accepts v2 and, failing that, v1. An old client keeps working.
 *  - The **client** signs v1 until it has EVIDENCE the peer understands v2, then v2 from
 *    then on. So it does not matter which site is upgraded first, and nothing has to be
 *    coordinated.
 *
 * Evidence is the peer's own RESPONSE SIGNATURE, and only that. A site running this code
 * signs every successful reply — including a reply to a request that was signed v1, which
 * is exactly what makes the bootstrap work. The first call after the other side is updated
 * comes back signed, the signature verifies against the shared secret, and the client moves
 * to v2 from the next call onwards.
 *
 * `ping` also reports `protocol: 2` in its body, and that is DIAGNOSTICS ONLY —
 * deliberately NOT treated as proof. An unsigned body is unauthenticated, so an on-path
 * attacker could put that field in front of a Production site still running v1; because the
 * ratchet below is permanent, the client would then sign v2 forever against a peer that
 * cannot verify it, every deploy would fail, and only an admin could clear it. A signature
 * cannot be forged that way, so the signature is the only thing believed.
 *
 * ── AND THE DOWNGRADE IT MUST NOT ALLOW ────────────────────────────────────────
 *
 * "Accept v1 if v2 fails" and "accept an unsigned response" are each a downgrade an attacker
 * would love: strip the header, and the protection evaporates. So once a peer has been seen
 * to speak v2, that fact is REMEMBERED and the client stops accepting anything less. The
 * negotiation is one-way — it can only ratchet up.
 */
final class Protocol {

	/** Request signature over `timestamp \n nonce \n sha256(body)`. The original. */
	public const V1 = 1;

	/** Adds the route to the signed material, and signs responses. */
	public const V2 = 2;

	/** What this build speaks. */
	public const CURRENT = self::V2;

	/** Response signature header. */
	public const HEADER_RESPONSE_SIGNATURE = 'X-IFS-Deploy-Response-Signature';

	/** Header the client uses to advertise what it signed with. */
	public const HEADER_PROTOCOL = 'X-IFS-Deploy-Protocol';

	private const OPTION = 'ifs_deploy_peer_protocol';

	/**
	 * The highest protocol the peer has been PROVEN to speak.
	 *
	 * Defaults to v1 — the safe assumption for a peer we have never successfully talked to,
	 * because assuming v2 would break the first request against an un-upgraded Production.
	 */
	public static function peer(): int {
		$stored = (int) get_option( self::OPTION, self::V1 );

		return self::V2 === $stored ? self::V2 : self::V1;
	}

	/**
	 * Record that the peer speaks v2. One-way: it never records a downgrade.
	 *
	 * That ratchet is the anti-downgrade control. If this could be walked back, an attacker
	 * who could strip a header from one response would return the pair to v1 permanently.
	 */
	public static function remember_peer_v2(): void {
		if ( self::V2 === self::peer() ) {
			return;
		}

		update_option( self::OPTION, self::V2 );

		DebugLog::info(
			'The paired site now speaks protocol 2, so requests are route-bound and its responses are verified from here on.',
			array( 'protocol' => (string) self::V2 )
		);
	}

	/**
	 * Reset to v1 — for "Regenerate Credentials" and for a re-pairing.
	 *
	 * Deliberately NOT reachable from anything an attacker influences. A new secret may mean
	 * a different site on the other end, and carrying a stale capability claim across that
	 * would make the first request to a v1 peer fail for no visible reason.
	 */
	public static function forget_peer(): void {
		delete_option( self::OPTION );
	}
}
