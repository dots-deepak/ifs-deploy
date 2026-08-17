<?php
declare(strict_types=1);

namespace IfsDeploy\Rest;

use IfsDeploy\Auth\Verifier;
use IfsDeploy\Export\PostExporter;
use IfsDeploy\History\DeploymentRepository;
use IfsDeploy\Import\PostImporter;
use IfsDeploy\Rollback\SnapshotPackage;
use IfsDeploy\Rollback\SnapshotStore;
use IfsDeploy\Support\Config;
use IfsDeploy\Support\PackageDiff;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST ifs-deploy/v1/rollback-preview — what a rollback would actually change.
 *
 * Read-only counterpart to /rollback. Both sides of the comparison live here on
 * Production: the object's CURRENT state, and the snapshot taken before the deploy
 * overwrote it. Staging has neither, which is why this cannot be answered locally.
 *
 * Returns the list of restorable objects always, plus the field-level diff for ONE of
 * them — the requested revision, or the first. That keeps the payload small when a
 * deployment touched many objects, and matches a UI that shows one at a time.
 *
 * Body: { deployment_uuid:string, revision_id?:int }
 */
final class RollbackPreviewEndpoint {

	public function register(): void {
		register_rest_route(
			RestController::NAMESPACE,
			'/rollback-preview',
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
		$uuid   = isset( $params['deployment_uuid'] ) ? sanitize_text_field( (string) $params['deployment_uuid'] ) : '';

		if ( '' === $uuid ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => __( 'Missing deployment_uuid.', 'ifs-deploy' ) ), 400 );
		}

		$deployment = ( new DeploymentRepository() )->get_by_uuid( $uuid );
		if ( null === $deployment ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => __( 'Production has no record of that deployment.', 'ifs-deploy' ) ), 404 );
		}

		$store     = new SnapshotStore();
		$revisions = $store->for_deployment( (int) $deployment->id );

		if ( empty( $revisions ) ) {
			return new WP_REST_Response(
				array(
					'ok'      => true,
					'objects' => array(),
					'diff'    => null,
				),
				200
			);
		}

		$objects  = array();
		$by_id    = array();
		foreach ( $revisions as $revision ) {
			$snapshot = json_decode( (string) $revision->snapshot, true );
			$snapshot = is_array( $snapshot ) ? $snapshot : array();
			$type     = (string) $revision->object_type;

			// A creation marker has no previous state, so there is nothing to diff — the
			// panel says what will be removed instead of showing an empty before/after,
			// which would read as a broken preview rather than as "there is no before".
			$is_creation = ! empty( $snapshot[ SnapshotStore::CREATED_MARKER ] );

			$objects[] = array(
				'revision_id' => (int) $revision->id,
				'type'        => $type,
				'object_id'   => (int) $revision->object_id,
				'title'       => $is_creation
					? $this->created_title( (string) ( $snapshot['type'] ?? $type ), (int) $revision->object_id )
					: SnapshotPackage::label( $type, $snapshot ),
				'comparable'  => ( 'post' === $type && ! $is_creation ),
				'created'     => $is_creation,
			);

			$by_id[ (int) $revision->id ] = array( 'row' => $revision, 'snapshot' => $snapshot );
		}

		$requested = isset( $params['revision_id'] ) ? absint( $params['revision_id'] ) : 0;
		if ( ! isset( $by_id[ $requested ] ) ) {
			$requested = (int) $objects[0]['revision_id'];
		}

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'objects' => $objects,
				'diff'    => $this->diff( $by_id[ $requested ] ),
			),
			200
		);
	}

	/**
	 * Name something that was created, read from the live object rather than a snapshot.
	 *
	 * A creation marker carries no fields — there was no earlier state to record — so the
	 * only place its title can come from is the object itself, which still exists at the
	 * moment the preview is built.
	 */
	private function created_title( string $type, int $object_id ): string {
		$post = get_post( $object_id );

		if ( $post instanceof \WP_Post && '' !== (string) $post->post_title ) {
			return (string) $post->post_title;
		}

		if ( 'term' === $type ) {
			$term = get_term( $object_id );

			if ( $term instanceof \WP_Term ) {
				return (string) $term->name;
			}
		}

		/* translators: %d: object id */
		return sprintf( __( 'Item #%d', 'ifs-deploy' ), $object_id );
	}

	/**
	 * Field-level comparison of an object's current state against its snapshot.
	 *
	 * @param array{row:object,snapshot:array} $revision
	 *
	 * @return array
	 */
	private function diff( array $revision ): array {
		$row      = $revision['row'];
		$snapshot = $revision['snapshot'];
		$type     = (string) $row->object_type;
		$title    = SnapshotPackage::label( $type, $snapshot );

		$result = array(
			'revision_id' => (int) $row->id,
			'type'        => $type,
			'object_id'   => (int) $row->object_id,
			'title'       => $title,
			'captured_at' => (string) $row->created_at,
			'comparable'  => false,
			'exists'      => true,
			'fields'      => array(),
			'note'        => '',
		);

		/*
		 * A CREATION has no "before", so it gets a plain statement instead of a diff.
		 *
		 * Falling through to the comparison below would produce an empty field list, which
		 * reads as "nothing will change" — the opposite of what a rollback does here. The
		 * user has to know this one REMOVES something before confirming it.
		 */
		if ( ! empty( $snapshot[ SnapshotStore::CREATED_MARKER ] ) ) {
			$created_type = (string) ( $snapshot['type'] ?? $type );

			$result['title'] = $this->created_title( $created_type, (int) $row->object_id );
			$result['note']  = 'media' === $created_type
				? __( 'This media item was added to Production by the deployment. There is no earlier version to restore, so rolling back REMOVES it.', 'ifs-deploy' )
				: __( 'This object was created on Production by the deployment. There is no earlier version to restore, so rolling back moves it to Trash, where it can still be recovered.', 'ifs-deploy' );

			return $result;
		}

		// Only posts get a field-level diff; the other snapshot shapes are restored
		// wholesale and have no comparable per-field structure.
		if ( 'post' !== $type ) {
			$result['note'] = __( 'This object is restored as a whole. No field-level comparison is available for it.', 'ifs-deploy' );

			return $result;
		}

		$result['comparable'] = true;

		$object_id = (int) $row->object_id;
		$current   = get_post( $object_id ) instanceof \WP_Post ? ( new PostExporter() )->export( $object_id ) : null;

		if ( null === $current ) {
			$result['exists'] = false;
			$result['note']   = __( 'This object no longer exists on Production, so everything shown will be recreated by the rollback.', 'ifs-deploy' );
		} else {
			// The exporter stamps origin identity into the package it builds; strip the
			// keys the snapshot side never carries so they are not reported as changes.
			$current = $this->strip_bookkeeping( $current, $object_id );
		}

		// "after" is the snapshot (what you get), "before" is the live version (what
		// it replaces) — same orientation as the deploy preview, where the incoming
		// version is on the left in green. full_replace: restore_post() rewrites meta,
		// terms and thumbnail wholesale, so nothing is silently "kept".
		$result['fields'] = PackageDiff::compare(
			SnapshotPackage::for_post( $snapshot ),
			$current,
			true
		);

		return $result;
	}

	/**
	 * Give the current-state package the same shape the snapshot side has.
	 *
	 * @param array $package
	 */
	private function strip_bookkeeping( array $package, int $object_id ): array {
		unset(
			$package['meta'][ PostImporter::ORIGIN_ID_META ],
			$package['meta'][ PostImporter::ORIGIN_SITE_META ],
			$package['meta'][ PostImporter::SRC_SIG_META ]
		);

		$package['parent_slug'] = ObjectEndpoint::parent_slug( $object_id );

		return $package;
	}
}
