<?php
declare(strict_types=1);

namespace IfsDeploy\Auth;

use IfsDeploy\Support\ApiLog;
use IfsDeploy\Support\ClientIp;
use IfsDeploy\Support\Config;
use IfsDeploy\Support\DebugLog;
use IfsDeploy\Support\IpAccess;
use WP_Error;
use WP_REST_Request;

/**
 * Server-side verification of an incoming signed request.
 *
 * Checks, in this order: address rules → headers present → timestamp inside the window →
 * API key known → signature valid → nonce not seen before. The order is not arbitrary:
 *
 *  - The cheap checks come first so an unauthenticated flood costs as little as possible.
 *  - The nonce check comes LAST, after the signature. Recording nonces from unverified
 *    requests would let anyone fill the replay store, which is both a denial of service
 *    and a way to pressure genuine entries out of an object cache.
 */
final class Verifier {

	/**
	 * How soon after acceptance a repeat of the same nonce counts as a duplicate DELIVERY
	 * rather than a replay attempt, in seconds.
	 *
	 * A proxy retry or a followed redirect lands within a second or two; anything minutes
	 * later is someone resending a captured request deliberately.
	 */
	private const DUPLICATE_WINDOW = 30;

	/**
	 * What the request that is currently being served signed with, and its nonce.
	 *
	 * Static because the response is signed from a `rest_post_dispatch` filter, long after
	 * `verify_request()` has returned and with no way to pass state through. One request per
	 * PHP process, so there is nothing to collide with.
	 */
	private static int $request_protocol = 0;
	private static string $request_nonce = '';
	private static string $request_stamp = '';

	/**
	 * The material needed to sign this request's response, or null when there is none.
	 *
	 * Returned for a v1-signed request as well as a v2 one, and that is deliberate: it is what
	 * makes the upgrade bootstrap work. A freshly updated Staging site still signs v1 until it
	 * has proof the peer speaks v2 — so if this refused v1 callers there would be no signed
	 * reply, no proof, and the pair would stay on v1 for good. An older Staging site ignores
	 * the extra header, so nothing is broken by sending it.
	 *
	 * Null only when the request never authenticated. A rejected caller gets no signature
	 * because there is no verified context to sign with — see the client's matching rule,
	 * which is why it requires a signature on a SUCCESSFUL reply only.
	 *
	 * @return array{nonce:string,timestamp:string}|null
	 */
	public static function response_context(): ?array {
		if ( 0 === self::$request_protocol || '' === self::$request_nonce ) {
			return null;
		}

		return array(
			'nonce'     => self::$request_nonce,
			'timestamp' => self::$request_stamp,
		);
	}

	/**
	 * Note that a peer is still signing the old way, once an hour.
	 *
	 * Worth surfacing — the pair is running without route binding, and its responses are
	 * probably not being verified either — but the whole point of accepting v1 is that it is
	 * not an error, so it must not read like one.
	 */
	private static function note_legacy_signature( string $route ): void {
		if ( false !== get_transient( 'dp_v1_noted' ) ) {
			return;
		}

		set_transient( 'dp_v1_noted', 1, HOUR_IN_SECONDS );

		DebugLog::info(
			'The paired site is still signing requests the old way, so the route is not bound into its signatures.',
			array(
				'route' => $route,
				'fix'   => 'Update Copperleaf Deploy on the Staging site. Requests upgrade themselves on the first successful call after that; nothing needs configuring.',
			)
		);
	}

	/**
	 * @return true|WP_Error True when the request is authentic.
	 */
	public static function verify_request( WP_REST_Request $request ) {
		$result = self::check( $request );

		/*
		 * ACCESS LOG — every outcome, at the one point that sees all of them.
		 *
		 * This wrapper exists so no future `return` inside check() can quietly escape the
		 * log. That matters more than it looks: an unlogged rejection path is exactly the
		 * one an attacker probes, and the whole value of the monitor is that a gap in it
		 * is impossible rather than merely unlikely.
		 *
		 * `ApiLog::record()` is a no-op unless this site is the Production receiver, and
		 * it collapses repeat failures so a flood cannot inflate the table.
		 */
		$is_error = $result instanceof WP_Error;
		$code     = $is_error ? (string) $result->get_error_code() : ApiLog::OK;

		ApiLog::record(
			self::route_of( $request ),
			$code,
			$is_error ? (int) ( $result->get_error_data()['status'] ?? 403 ) : 200
		);

		// Repeated authentication failures from one address earn a cool-off (M-6). Recorded
		// here so it sees the same complete set of outcomes the log does.
		Lockout::record( $code );

		return $is_error ? self::public_error( $result ) : $result;
	}

	/**
	 * The error actually SENT to the caller (SECURITY.md L-5).
	 *
	 * `bad_key` and `bad_signature` were distinguishable, which told an attacker whether the
	 * key they hold is the right one — a small oracle, and free. Both now go out as one
	 * generic `ifs_deploy_unauthorized`.
	 *
	 * The specific code is already in the access log by the time this runs, so nothing is
	 * lost for the site owner: the distinction stays exactly where it is useful and is
	 * removed exactly where it helps an attacker.
	 *
	 * Every OTHER code passes through unchanged, deliberately. `expired` tells a legitimate
	 * peer its clock has drifted; `duplicate` explains a proxy retry; the 409 role mismatch
	 * says the receiving site is set to Staging. Genericising those would only make honest
	 * troubleshooting harder without denying an attacker anything.
	 */
	private static function public_error( WP_Error $error ): WP_Error {
		$opaque = array( 'ifs_deploy_bad_key', 'ifs_deploy_bad_signature' );

		if ( ! in_array( $error->get_error_code(), $opaque, true ) ) {
			return $error;
		}

		return new WP_Error(
			'ifs_deploy_unauthorized',
			__( 'Authentication failed.', 'ifs-deploy' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * The endpoint slug, for the log.
	 *
	 * Taken from the matched ROUTE, never from user input, and reduced to the last path
	 * segment. Using anything request-controlled here would let an attacker write
	 * arbitrary strings into a column rendered in the admin.
	 */
	private static function route_of( WP_REST_Request $request ): string {
		$route = (string) $request->get_route();
		$parts = array_values( array_filter( explode( '/', $route ) ) );
		$last  = (string) ( end( $parts ) ?: '' );

		return preg_replace( '/[^a-z0-9\-_]/i', '', $last ) ?? '';
	}

	/**
	 * Explain a duplicate delivery once, with what is needed to chase it down.
	 *
	 * Rate-limited to one entry per hour: the point is to tell the site owner the cause
	 * exists, not to write a line every time their proxy retries.
	 */
	private static function note_duplicate( WP_REST_Request $request, int $elapsed ): void {
		if ( false !== get_transient( 'dp_dup_noted' ) ) {
			return;
		}

		set_transient( 'dp_dup_noted', 1, HOUR_IN_SECONDS );

		DebugLog::info(
			'The same API request was delivered twice and the duplicate was refused. This is a transport duplicate, not an attack — nothing ran twice.',
			array(
				'route'          => self::route_of( $request ),
				'seconds_apart'  => (string) $elapsed,
				'likely_cause'   => 'A redirect on the Production URL being followed, or a proxy or load balancer retrying a slow request.',
				'what_to_check'  => 'Confirm the Production URL in Settings is the exact canonical address, with the right scheme and no redirect.',
			)
		);
	}

	/**
	 * The checks themselves.
	 *
	 * @return true|WP_Error
	 */
	private static function check( WP_REST_Request $request ) {
		/*
		 * LOCKOUT FIRST — one transient read, cheaper than everything below it.
		 *
		 * Inside check() rather than around it, so the refusal still passes through the
		 * access log. An address locked out by mistake — the paired Staging site holding a
		 * stale key, most likely — must be diagnosable from the Logs screen rather than
		 * being a silent 429.
		 */
		if ( Lockout::is_locked() ) {
			$retry = Lockout::retry_after();

			return new WP_Error(
				'ifs_deploy_locked_out',
				sprintf(
					/* translators: %d: seconds until the address may try again */
					__( 'Too many failed authentication attempts. Try again in %d seconds.', 'ifs-deploy' ),
					$retry
				),
				array(
					'status' => 429,
					// Honoured by well-behaved clients, and the correct way to say "later".
					'retry_after' => $retry,
				)
			);
		}

		/*
		 * ADDRESS RULES — before any cryptography.
		 *
		 * Two reasons for the position. A refused address then costs one option read and a
		 * byte comparison rather than an HMAC, which is what keeps a flood cheap. And
		 * because this sits inside check(), the refusal still goes through the access log
		 * in verify_request() — an admin who locks their own Staging site out with a
		 * mistyped allow list can see exactly that on the Logs screen, instead of being
		 * left with a bare 403.
		 *
		 * Matched on the UNMASKED address: with anonymised logging on, the recorded value
		 * is `203.0.113.0`, and matching that against `203.0.113.9` would quietly make
		 * both lists do nothing.
		 */
		$decision = IpAccess::evaluate( ClientIp::for_matching() );

		if ( IpAccess::RESULT_BLOCKED === $decision ) {
			return new WP_Error(
				'ifs_deploy_ip_blocked',
				__( 'This address is not permitted to use the API.', 'ifs-deploy' ),
				array( 'status' => 403 )
			);
		}

		if ( IpAccess::RESULT_NOT_ALLOWED === $decision ) {
			// Deliberately the SAME message and status as a block. Telling a caller which
			// of the two lists refused it, or that an allow list exists at all, is free
			// reconnaissance. The distinction is kept in the log, where it is useful.
			return new WP_Error(
				'ifs_deploy_ip_not_allowed',
				__( 'This address is not permitted to use the API.', 'ifs-deploy' ),
				array( 'status' => 403 )
			);
		}

		$api_key   = (string) $request->get_header( Signer::HEADER_KEY );
		$timestamp = (string) $request->get_header( Signer::HEADER_TIMESTAMP );
		$nonce     = (string) $request->get_header( Signer::HEADER_NONCE );
		$signature = (string) $request->get_header( Signer::HEADER_SIGNATURE );

		if ( '' === $api_key || '' === $timestamp || '' === $nonce || '' === $signature ) {
			return new WP_Error( 'ifs_deploy_missing_auth', __( 'Missing authentication headers.', 'ifs-deploy' ), array( 'status' => 401 ) );
		}

		if ( ! ctype_digit( $timestamp ) ) {
			return new WP_Error( 'ifs_deploy_bad_timestamp', __( 'Invalid timestamp.', 'ifs-deploy' ), array( 'status' => 401 ) );
		}

		$skew = abs( time() - (int) $timestamp );
		if ( $skew > Config::TIMESTAMP_WINDOW ) {
			return new WP_Error( 'ifs_deploy_expired', __( 'Request timestamp outside the allowed window.', 'ifs-deploy' ), array( 'status' => 401 ) );
		}

		$secret = Credentials::secret_for_api_key( $api_key );
		if ( null === $secret ) {
			return new WP_Error( 'ifs_deploy_bad_key', __( 'Unrecognized API key.', 'ifs-deploy' ), array( 'status' => 403 ) );
		}

		$body  = $request->get_body();
		$route = self::route_of( $request );

		/*
		 * v2 FIRST, then v1 (SECURITY.md M-3).
		 *
		 * v2 binds the route into the signed material. v1 is still accepted because the two
		 * sites are not upgraded in the same instant, and rejecting v1 would mean whichever
		 * one is updated first stops being able to deploy at all — an outage, not a warning.
		 *
		 * Both comparisons use `hash_equals`, so trying two does not introduce a timing
		 * oracle: the work is constant either way and the outcome is the same 403.
		 */
		$signed_with = 0;

		if ( Signer::verify( Signer::sign_v2( $timestamp, $nonce, $route, $body, $secret ), $signature ) ) {
			$signed_with = Protocol::V2;
		} elseif ( Signer::verify( Signer::sign( $timestamp, $nonce, $body, $secret ), $signature ) ) {
			$signed_with = Protocol::V1;
		}

		if ( 0 === $signed_with ) {
			return new WP_Error( 'ifs_deploy_bad_signature', __( 'Signature verification failed.', 'ifs-deploy' ), array( 'status' => 403 ) );
		}

		// Remembered so the response can be signed with the same protocol the caller used,
		// and so a v1 peer is not sent a header it will not understand.
		self::$request_protocol = $signed_with;
		self::$request_nonce    = $nonce;
		self::$request_stamp    = $timestamp;

		if ( Protocol::V1 === $signed_with ) {
			self::note_legacy_signature( $route );
		}

		/*
		 * REPLAY PROTECTION — only meaningful once the signature is known good.
		 *
		 * Nothing in a captured request changes when it is resent, so without this a
		 * recorded `/import` or `/rollback` could be replayed at will for as long as its
		 * timestamp stayed inside the window. The nonce was always signed; it simply was
		 * never remembered.
		 */
		/*
		 * ONE atomic operation: check and record together.
		 *
		 * Two separate steps — "have I seen this?" then "remember it" — left a window in
		 * which two concurrent deliveries of the same request could both pass, which is
		 * precisely what a proxy retry produces. `claim()` is a single INSERT against a
		 * UNIQUE index, so the database decides and there is no window.
		 */
		$claim = NonceStore::claim( $nonce );

		if ( ! $claim['fresh'] ) {
			/*
			 * Both cases are refused — re-running an import is exactly what must not
			 * happen — but they are NOT the same event, and reporting them identically
			 * made ordinary transport noise look like an attack.
			 *
			 *  - Seconds after the original: the same HTTP request was DELIVERED twice.
			 *    A followed redirect, or a proxy/load balancer retrying a slow origin
			 *    request. Two separate calls would carry two separate nonces and both
			 *    would be accepted, so an identical nonce can only mean one request
			 *    arriving twice. Nothing was compromised, and the fix is the transport.
			 *  - Later than that: someone resent a captured request on purpose.
			 */
			$first_seen = (int) $claim['first_seen'];
			$elapsed    = $first_seen > 0 ? time() - $first_seen : PHP_INT_MAX;

			if ( $elapsed <= self::DUPLICATE_WINDOW ) {
				self::note_duplicate( $request, $elapsed );

				return new WP_Error(
					'ifs_deploy_duplicate',
					__( 'This exact request was already delivered and processed.', 'ifs-deploy' ),
					array( 'status' => 409 )
				);
			}

			return new WP_Error(
				'ifs_deploy_replay',
				__( 'This request has already been processed.', 'ifs-deploy' ),
				array( 'status' => 409 )
			);
		}

		return true;
	}
}
