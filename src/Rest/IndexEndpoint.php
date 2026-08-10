<?php
declare(strict_types=1);

namespace IfsDeploy\Rest;

use IfsDeploy\Auth\Credentials;
use IfsDeploy\Auth\Verifier;
use IfsDeploy\Support\Config;
use IfsDeploy\Support\SiteIndex;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST ifs-deploy/v1/index — returns this (Production) site's index of
 * posts/pages with content signatures, so Staging can compare and sync.
 */
final class IndexEndpoint {

	public function register(): void {
		register_rest_route(
			RestController::NAMESPACE,
			'/index',
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

		$creds = Credentials::get();

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'site_id' => $creds['site_id'],
				'name'    => get_bloginfo( 'name' ),
				'index'   => SiteIndex::build(),
			),
			200
		);
	}
}
