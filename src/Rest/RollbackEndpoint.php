<?php
declare(strict_types=1);

namespace IfsDeploy\Rest;

use IfsDeploy\Auth\Verifier;
use IfsDeploy\History\DeploymentRepository;
use IfsDeploy\Rollback\SnapshotStore;
use IfsDeploy\Support\Config;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST ifs-deploy/v1/rollback — restores every object snapshot captured under
 * a given production deployment.
 */
final class RollbackEndpoint {

	public function register(): void {
		register_rest_route(
			RestController::NAMESPACE,
			'/rollback',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( Verifier::class, 'verify_request' ),
			)
		);
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response {
		if ( ! Config::is_production() ) {
			return new WP_REST_Response(
				array( 'ok' => false, 'error' => __( 'This site is not configured to receive deployments.', 'ifs-deploy' ) ),
				409
			);
		}

		$params = $request->get_json_params();
		$uuid   = isset( $params['deployment_uuid'] ) ? sanitize_text_field( (string) $params['deployment_uuid'] ) : '';

		if ( '' === $uuid ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => __( 'Missing deployment_uuid.', 'ifs-deploy' ) ), 400 );
		}

		$deployments = new DeploymentRepository();
		$deployment  = $deployments->get_by_uuid( $uuid );
		if ( null === $deployment ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => __( 'Unknown deployment.', 'ifs-deploy' ) ), 404 );
		}

		/*
		 * ONE ROLLBACK PER DEPLOYMENT, enforced HERE.
		 *
		 * Production never recorded that a deployment had been rolled back — only the
		 * sending site did, in its own history table — so the snapshots stayed eligible
		 * for ever and a second request happily restored them again.
		 *
		 * Re-restoring is not the no-op it looks like. Snapshots are per-deployment
		 * point-in-time captures, so rolling back deploy B and then deploy A leaves the
		 * object at A's "before" state, which is correct; replaying B afterwards silently
		 * drags it forward again to the state A had just been rolled back out of. Staging
		 * hides the button once a deployment reads "Rolled Back", but that guard is on the
		 * wrong side of the wire: it disappears the moment history is cleared, and it was
		 * never a guarantee for a receiver that trusts whatever asks.
		 *
		 * 409 rather than 200, because nothing was done and a caller must be able to tell
		 * that apart from a rollback that restored zero objects.
		 */
		if ( DeploymentRepository::STATUS_ROLLED_BACK === (string) $deployment->deployment_status ) {
			return new WP_REST_Response(
				array(
					'ok'    => false,
					'error' => __( 'This deployment has already been rolled back. Restoring it a second time would undo any rollback applied after it.', 'ifs-deploy' ),
				),
				409
			);
		}

		$store     = new SnapshotStore();
		$revisions = $store->for_deployment( (int) $deployment->id );

		if ( empty( $revisions ) ) {
			return new WP_REST_Response(
				array( 'ok' => false, 'error' => __( 'No snapshots available to roll back (objects may have been newly created).', 'ifs-deploy' ) ),
				422
			);
		}

		$restored = 0;
		foreach ( $revisions as $revision ) {
			if ( $store->restore( (int) $revision->id ) ) {
				++$restored;
			}
		}

		// Recorded only when something was actually restored: a run that restored nothing
		// has changed no state, so refusing the next attempt would strand the deployment.
		if ( $restored > 0 ) {
			$deployments->set_status( (int) $deployment->id, DeploymentRepository::STATUS_ROLLED_BACK );
		}

		return new WP_REST_Response(
			array(
				'ok'       => $restored > 0,
				'restored' => $restored,
				'total'    => count( $revisions ),
			),
			200
		);
	}
}
