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
