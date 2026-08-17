<?php
declare(strict_types=1);

namespace IfsDeploy\Rest;

use IfsDeploy\Auth\Credentials;
use IfsDeploy\Auth\Protocol;
use IfsDeploy\Auth\Signer;
use IfsDeploy\Auth\Verifier;

/**
 * Registers all IFS Deploy REST routes under ifs-deploy/v1.
 *
 * Routes are always registered; each endpoint's permission callback enforces
 * signature + role before any work happens.
 */
final class RestController {

	public const NAMESPACE = 'ifs-deploy/v1';

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		// Sign what we send back (SECURITY.md H-3). Late priority so anything else that
		// modifies the payload has already run — the signature must cover the final data.
		add_filter( 'rest_post_dispatch', array( $this, 'sign_response' ), 99, 3 );
	}

	/**
	 * Attach a signature to our own responses.
	 *
	 * ── WHY RESPONSES NEEDED SIGNING AT ALL ────────────────────────────────────
	 *
	 * Requests were signed; responses were not. Staging simply believed whatever came back.
	 * That let an on-path attacker feed it a forged `index` (so Compare & Sync shows
	 * fabricated state and a real change is never pushed) or a forged `rollback-preview` —
	 * which is the diff the user reads *before* confirming a rollback. Forging it defeats the
	 * confirmation dialog entirely.
	 *
	 * ── SCOPE ──────────────────────────────────────────────────────────────────
	 *
	 * Only our own namespace, and only for a request that AUTHENTICATED — including one that
	 * signed with v1, because that reply is how a newly updated peer proves this side speaks
	 * v2 (see Verifier::response_context()). An old Staging site ignores the header.
	 *
	 * A rejected request gets nothing: there is no verified context to sign with, and the
	 * client knows not to expect one on a failure.
	 *
	 * @param \WP_HTTP_Response $result
	 * @param \WP_REST_Server   $server
	 * @param \WP_REST_Request  $request
	 * @return \WP_HTTP_Response
	 */
	public function sign_response( $result, $server, $request ) {
		if ( ! $result instanceof \WP_HTTP_Response || ! $request instanceof \WP_REST_Request ) {
			return $result;
		}

		if ( 0 !== strpos( (string) $request->get_route(), '/' . self::NAMESPACE . '/' ) ) {
			return $result;
		}

		$context = Verifier::response_context();

		if ( null === $context ) {
			return $result;
		}

		$secret = Credentials::get()['secret_key'];

		if ( '' === $secret ) {
			return $result;
		}

		$data = $result->get_data();

		$result->header(
			Protocol::HEADER_RESPONSE_SIGNATURE,
			Signer::sign_response(
				(string) $context['timestamp'],
				(string) $context['nonce'],
				is_array( $data ) ? $data : array( 'data' => $data ),
				$secret
			)
		);

		// Lets the caller learn that this side speaks v2 even on a route whose body carries
		// no protocol field of its own.
		$result->header( Protocol::HEADER_PROTOCOL, (string) Protocol::CURRENT );

		return $result;
	}

	public function register_routes(): void {
		( new PingEndpoint() )->register();
		( new ImportEndpoint() )->register();
		( new RollbackEndpoint() )->register();
		( new CancelEndpoint() )->register();
		( new IndexEndpoint() )->register();
		( new LinkEndpoint() )->register();
		( new ObjectEndpoint() )->register();
		( new IdSpaceEndpoint() )->register();
		( new RollbackPreviewEndpoint() )->register();
		( new SignatureEndpoint() )->register();
	}
}
