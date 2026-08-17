<?php
declare(strict_types=1);

namespace IfsDeploy\Rest;

use IfsDeploy\Auth\Verifier;
use IfsDeploy\Support\Config;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST ifs-deploy/v1/id-space — answers two questions about this (Production) site's
 * post-ID space, so Staging can resolve a media id conflict instead of discovering one.
 *
 * Read-only. It never writes anything, and it never reveals the CONTENT of whatever
 * occupies an id — only that something does, its post type, and its title, which is
 * exactly what the refusal message on the import side already says out loud.
 *
 * ── WHY THIS EXISTS ────────────────────────────────────────────────────────────────
 *
 * `MediaImporter` refuses to create a new attachment at an id that is already taken here,
 * because copying meta verbatim only works when ids agree — ACF fields, galleries and
 * `wp-image-N` classes all store the bare number. Refusing is honest but not useful on its
 * own: the operator is told the id is taken and left to work out what to do.
 *
 * The only site that can still act is Staging, and only while the attachment is not yet
 * referenced anywhere. To move it, Staging needs an id that is free on BOTH sites — and it
 * cannot know Production's ids without asking. That is this route.
 *
 * ── WHY `suggest` RETURNS A FLOOR RATHER THAN A SPECIFIC ID ───────────────────────
 *
 * Reserving an id here would mean writing a placeholder row, which is a real object that
 * something else could stumble over, and which leaks if the push never happens. Returning
 * the top of the used range instead lets Staging pick `max(its own top, ours) + 1` — free
 * on both by construction, with no state to clean up on either side.
 *
 * A concurrent upload on either site could take that number first. The importer's own
 * conflict check is what makes that safe: the worst case is a second refusal, not a wrong
 * id, so this is allowed to be advisory.
 *
 * Body: { id?:int }
 */
final class IdSpaceEndpoint {

	public function register(): void {
		register_rest_route(
			RestController::NAMESPACE,
			'/id-space',
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
		$id     = (int) ( $params['id'] ?? 0 );

		$response = array(
			'ok'        => true,
			'site_url'  => home_url(),
			'max_id'    => self::max_id(),
			'available' => true,
			'occupant'  => null,
		);

		if ( $id > 0 ) {
			$occupant = get_post( $id );

			if ( $occupant instanceof \WP_Post ) {
				$response['available'] = false;
				$response['occupant']  = array(
					'id'     => (int) $occupant->ID,
					'type'   => (string) $occupant->post_type,
					'title'  => (string) $occupant->post_title,
					'status' => (string) $occupant->post_status,
				);
			}
		}

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * The highest post id currently in use here.
	 *
	 * Read from AUTO_INCREMENT as well as MAX(ID), and the larger wins. MAX(ID) alone
	 * would drop back down after the newest posts were deleted, handing out an id that
	 * MySQL is about to allocate anyway — and `wp_insert_post()` with that `import_id`
	 * would then collide with the very next ordinary post somebody creates here.
	 */
	public static function max_id(): int {
		global $wpdb;

		$max = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$status = $wpdb->get_row( "SHOW TABLE STATUS LIKE '{$wpdb->posts}'", ARRAY_A );

		$next = is_array( $status ) ? (int) ( $status['Auto_increment'] ?? 0 ) : 0;

		// Auto_increment is the NEXT id, so the highest in use is one below it.
		return max( $max, $next > 0 ? $next - 1 : 0 );
	}
}
