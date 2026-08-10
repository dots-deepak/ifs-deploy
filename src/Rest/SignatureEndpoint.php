<?php
declare(strict_types=1);

namespace IfsDeploy\Rest;

use IfsDeploy\Auth\Verifier;
use IfsDeploy\Import\PostImporter;
use IfsDeploy\Support\Config;
use IfsDeploy\Support\ContentSignature;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST ifs-deploy/v1/signatures — content signatures for a specific set of objects.
 *
 * Deliberately narrower than /index, which signs every post on the site (up to 2000)
 * and is far too heavy to call while rendering a screen. This answers "for these few
 * objects, what do you have?", so the cost scales with the queue rather than the site.
 *
 * Used by the Pending Changes verifier to drop rows that no longer differ from
 * Production — the case where someone edited a page and then undid the edit.
 *
 * Body: { objects: [ { origin_id, origin_site, post_type, slug }, … ] }
 */
final class SignatureEndpoint {

	/** Upper bound on objects per request, so one call cannot become expensive. */
	private const MAX_OBJECTS = 200;

	public function register(): void {
		register_rest_route(
			RestController::NAMESPACE,
			'/signatures',
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

		$params  = (array) $request->get_json_params();
		$objects = isset( $params['objects'] ) && is_array( $params['objects'] ) ? $params['objects'] : array();

		if ( empty( $objects ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'signatures' => array() ), 200 );
		}

		$objects  = array_slice( $objects, 0, self::MAX_OBJECTS );
		$importer = new PostImporter();
		$out      = array();

		foreach ( $objects as $object ) {
			$object    = (array) $object;
			$origin_id = (int) ( $object['origin_id'] ?? 0 );

			if ( $origin_id <= 0 ) {
				continue;
			}

			// Same matcher the import uses, so the signature describes the object a
			// push would actually overwrite.
			$probe = array(
				'origin_id'   => $origin_id,
				'origin_site' => sanitize_text_field( (string) ( $object['origin_site'] ?? '' ) ),
				'object'      => array(
					'post_type' => sanitize_key( (string) ( $object['post_type'] ?? 'post' ) ),
					'post_name' => sanitize_title( (string) ( $object['slug'] ?? '' ) ),
				),
			);

			$prod_id = (int) $importer->find_target( $probe );

			if ( $prod_id <= 0 ) {
				$out[ (string) $origin_id ] = array(
					'found'        => false,
					'prod_id'      => 0,
					'signature'    => '',
					'deployed_sig' => '',
				);
				continue;
			}

			$out[ (string) $origin_id ] = array(
				'found'        => true,
				'prod_id'      => $prod_id,
				'signature'    => (string) ( ContentSignature::for_post( $prod_id ) ?? '' ),
				'deployed_sig' => (string) get_post_meta( $prod_id, PostImporter::SRC_SIG_META, true ),
			);
		}

		return new WP_REST_Response(
			array(
				'ok'         => true,
				'signatures' => $out,
			),
			200
		);
	}
}
