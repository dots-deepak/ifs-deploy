<?php
declare(strict_types=1);

namespace IfsDeploy\Rest;

use IfsDeploy\Auth\Verifier;
use IfsDeploy\Export\PostExporter;
use IfsDeploy\Import\PostImporter;
use IfsDeploy\Support\Config;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST ifs-deploy/v1/object — returns this (Production) site's CURRENT state for
 * a single post, so Staging can show a before/after preview of what a push would
 * change. Read-only: it never writes anything.
 *
 * The lookup deliberately reuses PostImporter::locate() — the very same matcher
 * the import uses — so the preview reports the object the deploy would actually
 * overwrite, not merely one that looks similar.
 *
 * Body: { origin_id:int, origin_site:string, post_type:string, slug:string }
 */
final class ObjectEndpoint {

	public function register(): void {
		register_rest_route(
			RestController::NAMESPACE,
			'/object',
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

		$params = (array) $request->get_json_params();

		$probe = array(
			'origin_id'   => (int) ( $params['origin_id'] ?? 0 ),
			'origin_site' => sanitize_text_field( (string) ( $params['origin_site'] ?? '' ) ),
			'object'      => array(
				'post_type' => sanitize_key( (string) ( $params['post_type'] ?? 'post' ) ),
				'post_name' => sanitize_title( (string) ( $params['slug'] ?? '' ) ),
			),
		);

		$located = ( new PostImporter() )->locate( $probe );
		$prod_id = (int) $located['id'];

		// home_url() lets Staging apply the same URL rewrite the import would, so
		// the diff does not flag every inline link as changed.
		$response = array(
			'ok'       => true,
			'found'    => $prod_id > 0,
			'prod_id'  => $prod_id,
			'strategy' => (string) $located['strategy'],
			'site_url' => home_url(),
			'object'   => null,
		);

		if ( $prod_id > 0 ) {
			// The same exporter runs on both sides, so both packages share one shape
			// and one meta blocklist — anything that differs is a real difference.
			$response['object'] = ( new PostExporter() )->export( $prod_id );

			// Sent separately because the package carries only the numeric parent,
			// which is meaningless across sites. The slug is comparable.
			$response['parent_slug'] = self::parent_slug( $prod_id );
		}

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Slug of a post's parent, or '' when it has none.
	 */
	public static function parent_slug( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! $post->post_parent ) {
			return '';
		}

		$parent = get_post( (int) $post->post_parent );

		return $parent instanceof \WP_Post ? (string) $parent->post_name : '';
	}
}
