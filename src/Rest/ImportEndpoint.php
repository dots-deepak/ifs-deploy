<?php
declare(strict_types=1);

namespace IfsDeploy\Rest;

use IfsDeploy\Auth\Verifier;
use IfsDeploy\Import\ImportManager;
use IfsDeploy\Support\Config;
use IfsDeploy\Support\DebugLog;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST ifs-deploy/v1/import — receives a batch of objects and applies them to
 * this (Production) site.
 */
final class ImportEndpoint {

	public function register(): void {
		register_rest_route(
			RestController::NAMESPACE,
			'/import',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( Verifier::class, 'verify_request' ),
			)
		);
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response {
		// Only a site explicitly acting as Production should accept imports.
		if ( ! Config::is_production() ) {
			return new WP_REST_Response(
				array( 'ok' => false, 'error' => __( 'This site is not configured to receive deployments.', 'ifs-deploy' ) ),
				409
			);
		}

		$params  = $request->get_json_params();
		$uuid    = isset( $params['deployment_uuid'] ) ? sanitize_text_field( (string) $params['deployment_uuid'] ) : wp_generate_uuid4();
		$objects = isset( $params['objects'] ) && is_array( $params['objects'] ) ? $params['objects'] : array();

		if ( empty( $objects ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => __( 'No objects provided.', 'ifs-deploy' ) ), 400 );
		}

		/*
		 * Batch cap (SECURITY.md M-5).
		 *
		 * Nothing bounded this, so one signed request could hold a worker for the full
		 * TIMEOUT_HEAVY of 180 seconds and exhaust memory on the way.
		 *
		 * The cap REFUSES rather than truncating. Silently importing the first 200 of 500
		 * objects and reporting success would leave Production half-updated and Staging
		 * believing the deploy completed — far worse than an error that says to push fewer
		 * items. The default is generous enough that no realistic deploy meets it; anyone
		 * whose workflow genuinely needs more can raise it with the filter.
		 */
		$max = (int) apply_filters( 'ifs_deploy_max_objects_per_import', 200 );

		if ( $max > 0 && count( $objects ) > $max ) {
			DebugLog::warning(
				'Refused an import batch larger than the configured limit.',
				array(
					'objects' => (string) count( $objects ),
					'limit'   => (string) $max,
					'uuid'    => $uuid,
				)
			);

			return new WP_REST_Response(
				array(
					'ok'    => false,
					'error' => sprintf(
						/* translators: 1: objects submitted, 2: configured limit */
						__( 'This deployment contains %1$d objects, which is more than the limit of %2$d. Push fewer items at a time, or raise the limit with the ifs_deploy_max_objects_per_import filter on Production.', 'ifs-deploy' ),
						count( $objects ),
						$max
					),
				),
				413
			);
		}

		$result = ( new ImportManager() )->run( $uuid, $objects );

		return new WP_REST_Response(
			array(
				'ok'      => 'failed' !== $result['status'],
				'uuid'    => $result['uuid'],
				'status'  => $result['status'],
				'results' => $result['results'],
			),
			200
		);
	}
}
