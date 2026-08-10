<?php
declare(strict_types=1);

namespace IfsDeploy\Client;

use IfsDeploy\Auth\Protocol;
use IfsDeploy\Auth\Signer;
use IfsDeploy\Rest\RestController;
use IfsDeploy\Support\Config;
use IfsDeploy\Support\DebugLog;
use WP_Error;

/**
 * Staging-side HTTP client. Signs every request with the remote secret and
 * POSTs JSON to the Production REST API.
 */
final class DeployClient {

	/** Seconds allowed for a read-only route (ping, index, object, link). */
	private const TIMEOUT_LIGHT = 30;

	/**
	 * Seconds allowed for routes that do real work on the far side.
	 *
	 * An import downloads every media file from this site and regenerates its image
	 * sizes, which comfortably exceeds 30s on a media-heavy push — the symptom being
	 * "cURL error 28: Operation timed out … with 0 bytes received", after which the
	 * deploy is reported as failed even though Production may well have applied part
	 * or all of it.
	 */
	private const TIMEOUT_HEAVY = 180;

	/** Routes that get the longer allowance. */
	private const HEAVY_ROUTES = array( 'import', 'rollback' );

	/**
	 * Routes called while rendering a screen, where waiting is worse than skipping.
	 *
	 * `signatures` runs during the Pending Changes render, so a slow Production must
	 * not hold the page open for 30 seconds — the verification is a convenience and is
	 * simply skipped when it times out.
	 */
	private const QUICK_ROUTES = array( 'signatures' => 10 );

	/**
	 * Send a signed POST to a ifs-deploy/v1 route.
	 *
	 * @param string $route   Route path, e.g. "ping" or "import".
	 * @param array  $payload Body data (encoded to JSON).
	 *
	 * @return array{status:int,body:array}|WP_Error
	 */
	public function post( string $route, array $payload ) {
		$remote = Config::remote();

		if ( '' === $remote['url'] || '' === $remote['api_key'] || '' === $remote['secret_key'] ) {
			return new WP_Error( 'ifs_deploy_not_configured', __( 'Production connection is not configured.', 'ifs-deploy' ) );
		}

		$body      = (string) wp_json_encode( $payload );
		$timestamp = (string) time();
		$nonce     = wp_generate_uuid4();

		/*
		 * Sign v2 only once the peer is KNOWN to understand it (SECURITY.md M-3).
		 *
		 * Signing v2 unconditionally would break every deploy against a Production site that
		 * has not been updated yet — and there is no way for a plugin to update two sites in
		 * the same instant. So the client starts at v1 and upgrades itself the first time the
		 * peer proves it speaks v2 (a response signature, or `protocol: 2` in a ping). Neither
		 * site has to be updated first, and nothing needs configuring.
		 *
		 * `Protocol::remember_peer_v2()` is one-way, so this cannot be walked back down by an
		 * attacker stripping a header.
		 */
		$protocol = Protocol::peer();

		$signature = Protocol::V2 === $protocol
			? Signer::sign_v2( $timestamp, $nonce, $route, $body, $remote['secret_key'] )
			: Signer::sign( $timestamp, $nonce, $body, $remote['secret_key'] );

		$url = $remote['url'] . '/wp-json/' . RestController::NAMESPACE . '/' . ltrim( $route, '/' );

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => $this->timeout_for( $route ),

				/*
				 * DO NOT FOLLOW REDIRECTS. Two separate reasons, both real.
				 *
				 * 1. CREDENTIAL LEAK. WordPress defaults to `redirection => 5`, and on a
				 *    301/302 its Requests library re-sends the request to the `Location`
				 *    target with the SAME method, body and HEADERS — including
				 *    `X-IFS-Deploy-Key` and the entire content payload — to whatever host
				 *    that header names (see wp-includes/Requests/src/Requests.php, the
				 *    `requests.before_redirect` block). One misconfigured redirect, or one
				 *    open redirect on the Production site, and the API key plus every
				 *    deployed page goes to a third party.
				 *
				 * 2. DUPLICATE DELIVERY. A followed redirect is a second POST carrying the
				 *    same nonce. Before replay protection existed, both were accepted — so
				 *    an import could be applied TWICE. It is now refused with 409, which is
				 *    correct but shows up in the access log as a replay and looks like an
				 *    attack. Not following the redirect removes the duplicate at source.
				 *
				 * With this at 0, a redirect arrives as the response itself, so it can be
				 * reported as the configuration error it is rather than silently obeyed.
				 */
				'redirection' => 0,

				'headers' => array(
					'Content-Type'           => 'application/json',
					Signer::HEADER_KEY       => $remote['api_key'],
					Signer::HEADER_TIMESTAMP => $timestamp,
					Signer::HEADER_NONCE     => $nonce,
					Signer::HEADER_SIGNATURE => $signature,

					// Advertises what we signed with. An old Production ignores it; a current
					// one uses it only for diagnostics, never to decide what to accept — the
					// signature itself is the proof.
					Protocol::HEADER_PROTOCOL => (string) $protocol,

					/*
					 * Never serve any of these from a cache.
					 *
					 * They are POSTs, which nothing should cache — but managed hosts,
					 * security plugins and reverse proxies do not always agree, and a
					 * replayed `index` response is indistinguishable from a genuine
					 * "nothing changed": Compare & Sync would report stale state and
					 * clicking Refresh would keep reporting it. These headers are the
					 * protocol's own way to say so, and they are not part of the signed
					 * material (only timestamp, nonce and body are), so adding them
					 * cannot affect verification.
					 */
					'Cache-Control'          => 'no-cache, no-store, must-revalidate',
					'Pragma'                 => 'no-cache',
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			DebugLog::error(
				'Request to Production failed at the transport layer',
				array(
					'route' => $route,
					'url'   => $url,
					'code'  => $response->get_error_code(),
					'error' => $response->get_error_message(),
				)
			);

			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );

		/*
		 * A redirect now surfaces instead of being followed (see `redirection` above).
		 * Reported as its own error because the cause and the cure are specific: the
		 * configured Production URL is not the canonical one — usually http vs https, a
		 * missing or extra `www`, or a trailing slash — and the fix is to correct it in
		 * Settings rather than to debug a signature.
		 */
		if ( in_array( $status, array( 301, 302, 303, 307, 308 ), true ) ) {
			$location = (string) wp_remote_retrieve_header( $response, 'location' );

			DebugLog::error(
				'Production redirected the request instead of answering it',
				array(
					'route'    => $route,
					'url'      => $url,
					'status'   => (string) $status,
					'location' => $location,
				)
			);

			return new WP_Error(
				'ifs_deploy_redirected',
				sprintf(
					/* translators: 1: HTTP status, 2: redirect target */
					__( 'Production answered with an HTTP %1$d redirect to %2$s. Set the Production URL in Settings to that exact address — the redirect is not followed, because doing so would resend the API key to wherever it points.', 'ifs-deploy' ),
					$status,
					'' !== $location ? $location : __( '(no location given)', 'ifs-deploy' )
				)
			);
		}

		$decoded = json_decode( $raw, true );

		/*
		 * VERIFY THE RESPONSE (SECURITY.md H-3).
		 *
		 * Requests were signed; responses were not, so Staging believed whatever came back. A
		 * forged `index` makes Compare & Sync show fabricated state; a forged
		 * `rollback-preview` forges the diff the user reads *before* confirming a rollback,
		 * which defeats the confirmation dialog entirely.
		 *
		 * Done before anything reads `$decoded`, so a forged body never reaches the caller.
		 */
		if ( is_array( $decoded ) ) {
			$check = $this->verify_response(
				$response,
				$decoded,
				$timestamp,
				$nonce,
				$remote['secret_key'],
				$protocol,
				$route
			);

			if ( is_wp_error( $check ) ) {
				return $check;
			}
		}

		// A PHP fatal on the far side arrives as an HTML (or empty) 500. Discarding
		// the raw body there is what reduced every such failure to a bare
		// "Deployment failed." with no cause, so it is kept — trimmed — for the log.
		if ( ! is_array( $decoded ) ) {
			DebugLog::error(
				'Production returned a response that was not JSON',
				array(
					'route'  => $route,
					'status' => $status,
					'raw'    => self::excerpt( $raw ),
				)
			);
		} elseif ( 200 !== $status ) {
			DebugLog::warning(
				'Production returned a non-200 status',
				array(
					'route'  => $route,
					'status' => $status,
					'error'  => (string) ( $decoded['error'] ?? '' ),
				)
			);
		}

		return array(
			'status' => $status,
			'body'   => is_array( $decoded ) ? $decoded : array(),
			'raw'    => self::excerpt( $raw ),
		);
	}

	/**
	 * Check the response signature, and refuse a downgrade (SECURITY.md H-3).
	 *
	 * Two cases, and the difference between them is the whole design:
	 *
	 *  - **Peer not yet known to speak v2.** An unsigned response is expected — it simply has
	 *    not been updated. Accepted; and if it IS signed we verify it and remember, so the
	 *    pair upgrades itself with nothing to configure.
	 *  - **Peer known to speak v2.** An unsigned response is now a DOWNGRADE ATTEMPT rather
	 *    than an old peer. Stripping the header is the cheapest possible way to defeat
	 *    response verification, so once a peer has signed even once, a missing signature is
	 *    refused.
	 *
	 * The ratchet only goes up, so an attacker who controls one response cannot argue the
	 * pair back down to unverified replies.
	 *
	 * @param array|\WP_Error $response Raw wp_remote_* response.
	 * @param array           $decoded  Parsed body.
	 * @return true|\WP_Error
	 */
	private function verify_response( $response, array $decoded, string $timestamp, string $nonce, string $secret, int $protocol_before, string $route ) {
		$presented = (string) wp_remote_retrieve_header( $response, strtolower( Protocol::HEADER_RESPONSE_SIGNATURE ) );
		$status    = (int) wp_remote_retrieve_response_code( $response );

		if ( '' !== $presented ) {
			$expected = Signer::sign_response( $timestamp, $nonce, $decoded, $secret );

			if ( ! Signer::verify( $expected, $presented ) ) {
				DebugLog::error(
					'The reply from Production carried an invalid signature and was discarded.',
					array(
						'route' => $route,
						'why'   => 'Either something on the network altered the reply, or the two sites no longer share the same secret.',
					)
				);

				return new WP_Error(
					'ifs_deploy_bad_response_signature',
					__( 'The reply from Production could not be verified, so nothing in it was trusted.', 'ifs-deploy' )
				);
			}

			// Proven. Every later reply from this peer must be signed too.
			Protocol::remember_peer_v2();

			return true;
		}

		/*
		 * UNSIGNED. Two ways that happens legitimately, and only one of them is enforced.
		 *
		 * 1. The peer has never signed — it is not updated yet. Accepted; that is the point
		 *    of the negotiation.
		 * 2. The request never got PAST AUTHENTICATION on the far side. A response signature
		 *    is keyed to a verified request, so a 401/403/429 from the verifier itself cannot
		 *    carry one. Refusing those would replace the real reason — "Authentication
		 *    failed", "Too many attempts", "This address is not permitted" — with a confusing
		 *    downgrade error at the exact moment the owner needs the real one, which is right
		 *    after a credential rotation.
		 *
		 * So a signature is REQUIRED on a successful reply only. What that concedes is narrow
		 * and worth stating plainly: an on-path attacker can turn a genuine 200 into a forged
		 * error, making a deploy that worked look like it failed. Visible, and recoverable. It
		 * cannot make Staging trust wrong CONTENT, which is what H-3 exists to prevent — every
		 * reply whose body is read as truth (index, object, signatures, rollback-preview) is a
		 * 200, and a 200 must be signed once the peer has ever signed.
		 */
		if ( Protocol::V2 === $protocol_before && 200 === $status ) {
			DebugLog::error(
				'Production stopped signing its replies, so this one was discarded.',
				array(
					'route'       => $route,
					'why'        => 'This site has signed before, so an unsigned reply is treated as an attempt to strip the protection rather than an old peer.',
					'if_genuine' => 'If Production really was downgraded, regenerate the credentials to re-pair the two sites.',
				)
			);

			return new WP_Error(
				'ifs_deploy_response_unsigned',
				__( 'Production did not sign its reply, but it has done so before. The reply was discarded rather than trusted.', 'ifs-deploy' )
			);
		}

		/*
		 * Nothing learned from an unsigned reply, on purpose.
		 *
		 * `ping` reports `protocol: 2` in its body and it is tempting to ratchet on that. It
		 * must not: an unsigned body is unauthenticated, the ratchet is permanent, and an
		 * on-path attacker who injected that field in front of a Production site still on v1
		 * would make every future deploy fail a signature check until an admin intervened.
		 * The signature above is the only proof accepted.
		 */
		return true;
	}

	/**
	 * How long to wait for a given route.
	 *
	 * Note that Production's own PHP execution limit applies independently — if a
	 * host caps requests at 60s, raising this cannot help beyond that, and the fix
	 * is to push fewer items at a time.
	 */
	private function timeout_for( string $route ): int {
		if ( isset( self::QUICK_ROUTES[ $route ] ) ) {
			$timeout = (int) self::QUICK_ROUTES[ $route ];
		} else {
			$timeout = in_array( $route, self::HEAVY_ROUTES, true ) ? self::TIMEOUT_HEAVY : self::TIMEOUT_LIGHT;
		}

		/**
		 * Filter the HTTP timeout, in seconds, for a IFS Deploy request.
		 *
		 * @param int    $timeout
		 * @param string $route Route path, e.g. "import".
		 */
		return max( 5, (int) apply_filters( 'ifs_deploy_request_timeout', $timeout, $route ) );
	}

	/**
	 * A short, readable slice of a response body for logging. HTML error pages are
	 * stripped to text so the actual message is visible rather than buried in markup.
	 */
	private static function excerpt( string $body ): string {
		$body = trim( (string) wp_strip_all_tags( $body ) );
		$body = (string) preg_replace( '#\s+#', ' ', $body );

		if ( '' === $body ) {
			return '(empty response body)';
		}

		return ( strlen( $body ) > 800 ) ? substr( $body, 0, 800 ) . '… [truncated]' : $body;
	}

	/**
	 * Convenience: test the configured connection.
	 *
	 * @return array{status:int,body:array}|WP_Error
	 */
	public function ping() {
		return $this->post( 'ping', array( 'from' => home_url() ) );
	}
}
