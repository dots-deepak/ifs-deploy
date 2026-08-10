<?php
declare(strict_types=1);

namespace IfsDeploy\Rest;

use IfsDeploy\Auth\Verifier;
use IfsDeploy\Import\PostImporter;
use IfsDeploy\Support\Config;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST ifs-deploy/v1/link — stamps origin linkage onto existing Production
 * posts so future deploys update them precisely instead of relying on id/slug
 * matching. Used by the Compare screen's "Sync IDs" action.
 *
 * Body: { links: [ { prod_id:int, origin_id:int, origin_site:string }, ... ] }
 */
final class LinkEndpoint {

	public function register(): void {
		register_rest_route(
			RestController::NAMESPACE,
			'/link',
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
		$links  = isset( $params['links'] ) && is_array( $params['links'] ) ? $params['links'] : array();

		$linked = 0;
		foreach ( $links as $link ) {
			$prod_id     = (int) ( $link['prod_id'] ?? 0 );
			$origin_id   = (int) ( $link['origin_id'] ?? 0 );
			$origin_site = sanitize_text_field( (string) ( $link['origin_site'] ?? '' ) );

			if ( $prod_id <= 0 || $origin_id <= 0 || '' === $origin_site ) {
				continue;
			}
			if ( ! get_post( $prod_id ) ) {
				continue;
			}

			update_post_meta( $prod_id, PostImporter::ORIGIN_ID_META, $origin_id );
			update_post_meta( $prod_id, PostImporter::ORIGIN_SITE_META, $origin_site );
			++$linked;
		}

		return new WP_REST_Response(
			array(
				'ok'     => true,
				'linked' => $linked,
				'total'  => count( $links ),
			),
			200
		);
	}
}
