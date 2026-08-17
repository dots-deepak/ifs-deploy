<?php
declare(strict_types=1);

namespace IfsDeploy\Rest;

use IfsDeploy\Auth\Verifier;
use IfsDeploy\History\DeploymentRepository;
use IfsDeploy\Rollback\SnapshotStore;
use IfsDeploy\Support\Config;
use IfsDeploy\Support\DebugLog;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST ifs-deploy/v1/cancel — undo a push that was stopped part-way.
 *
 * ── WHY THIS IS NOT /rollback ──────────────────────────────────────────────────
 *
 * The two look alike and mean different things.
 *
 * A ROLLBACK undoes a deployment that finished and was then judged wrong. It is a
 * deliberate, separate event, and history should show both: the deploy, then the undo.
 *
 * A CANCEL is the user changing their mind while the push is still running. Batching
 * means some objects are already live on Production when they press it, so those have to
 * be put back — but the deployment never completed, and recording it as "deployed, then
 * rolled back" would describe something that did not happen. It leaves ONE line saying it
 * was cancelled.
 *
 * The behavioural differences follow from that:
 *
 *  - `/rollback` REFUSES a second attempt, because replaying a completed deployment's
 *    snapshots would silently undo a later rollback. Cancel has no such risk and is
 *    idempotent: cancelling an already-cancelled push restores nothing and says so.
 *  - `/rollback` keeps its revisions, so the deployment can be rolled back again after a
 *    re-deploy. Cancel DELETES them, because a reverted push has nothing left to restore
 *    and a Rollback button on it would re-apply exactly what the cancel just undid.
 *
 * Body: { deployment_uuid:string }
 */
final class CancelEndpoint {

	public function register(): void {
		register_rest_route(
			RestController::NAMESPACE,
			'/cancel',
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

		/*
		 * NOT AN ERROR when there is nothing here.
		 *
		 * A push can be cancelled before its first batch has landed, in which case
		 * Production has never heard of this uuid. That is the best possible outcome —
		 * nothing to undo — so it is reported as success with a count of zero rather than
		 * as a failure the user has to interpret.
		 */
		if ( null === $deployment ) {
			return new WP_REST_Response(
				array( 'ok' => true, 'restored' => 0, 'total' => 0, 'nothing_applied' => true ),
				200
			);
		}

		$store     = new SnapshotStore();
		$revisions = $store->for_deployment( (int) $deployment->id );

		$restored = 0;
		foreach ( $revisions as $revision ) {
			if ( $store->restore( (int) $revision->id ) ) {
				++$restored;
			}
		}

		// Nothing left to restore, and nothing that should be offered as a rollback.
		$store->delete_for_deployment( (int) $deployment->id );

		$deployments->set_status( (int) $deployment->id, DeploymentRepository::STATUS_CANCELLED );

		DebugLog::warning(
			'A push was cancelled and its applied changes were reverted',
			array(
				'uuid'     => $uuid,
				'restored' => $restored,
				'of'       => count( $revisions ),
			)
		);

		return new WP_REST_Response(
			array(
				'ok'       => true,
				'restored' => $restored,
				'total'    => count( $revisions ),
			),
			200
		);
	}
}
