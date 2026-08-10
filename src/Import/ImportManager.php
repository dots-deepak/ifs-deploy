<?php
declare(strict_types=1);

namespace IfsDeploy\Import;

use IfsDeploy\History\DeploymentRepository;
use IfsDeploy\Rollback\SnapshotStore;
use IfsDeploy\Support\DebugLog;

/**
 * Orchestrates the production-side import pipeline for a batch of objects:
 *
 *   1. (request + signature validated by the endpoint permission callback)
 *   2. Open a deployment record to group snapshots.
 *   3. Snapshot each existing object before overwrite.
 *   4. Import object → meta → ACF → taxonomies → media.
 *   5. Record per-object result + overall status.
 *   6. Return the result set (incl. snapshot ids) for rollback.
 */
final class ImportManager {

	private PostImporter $importer;
	private TermImporter $term_importer;
	private OptionImporter $option_importer;
	private MediaImporter $media_importer;
	private MenuImporter $menu_importer;
	private SnapshotStore $snapshots;
	private DeploymentRepository $deployments;

	public function __construct(
		?PostImporter $importer = null,
		?TermImporter $term_importer = null,
		?OptionImporter $option_importer = null,
		?MediaImporter $media_importer = null,
		?MenuImporter $menu_importer = null,
		?SnapshotStore $snapshots = null,
		?DeploymentRepository $deployments = null
	) {
		$this->importer        = $importer ?? new PostImporter();
		$this->term_importer   = $term_importer ?? new TermImporter();
		$this->option_importer = $option_importer ?? new OptionImporter();
		$this->media_importer  = $media_importer ?? new MediaImporter();
		$this->menu_importer   = $menu_importer ?? new MenuImporter();
		$this->snapshots       = $snapshots ?? new SnapshotStore();
		$this->deployments     = $deployments ?? new DeploymentRepository();
	}

	/**
	 * @param array $objects List of deployment packages.
	 *
	 * @return array{uuid:string,status:string,results:array<int,array>}
	 */
	public function run( string $deployment_uuid, array $objects ): array {
		$deployment_id = $this->deployments->create( $deployment_uuid, 0, DeploymentRepository::STATUS_PENDING );

		$results   = array();
		$succeeded = 0;

		foreach ( $this->in_dependency_order( $objects ) as $package ) {
			$results[] = $this->import_one( (array) $package, $deployment_id );
		}

		foreach ( $results as $result ) {
			if ( ! empty( $result['ok'] ) ) {
				++$succeeded;
			}
		}

		$status = $this->resolve_status( count( $results ), $succeeded );
		$this->deployments->update( $deployment_id, $status, $results );

		return array(
			'uuid'    => $deployment_uuid,
			'status'  => $status,
			'results' => $results,
		);
	}

	/**
	 * Import order by object type — dependencies before the things that reference
	 * them. The sender's order (queue order, i.e. most-recently-edited first) is
	 * arbitrary with respect to dependencies.
	 *
	 * Media first is load-bearing: PostImporter looks up each attachment to discover
	 * the filename Production actually stored, and can only find one that already
	 * exists. Terms before posts so assignment finds them; menus after posts so
	 * their targets resolve; options last, as nothing else depends on them.
	 *
	 * @var array<string,int>
	 */
	private const TYPE_ORDER = array(
		'media'  => 0,
		'term'   => 1,
		'post'   => 2,
		'menu'   => 3,
		'option' => 4,
	);

	/**
	 * Sort a batch into dependency order, preserving the sender's relative order
	 * within each type (PHP's sort is stable).
	 *
	 * @param array $objects Packages as received.
	 *
	 * @return array
	 */
	private function in_dependency_order( array $objects ): array {
		usort(
			$objects,
			static function ( $a, $b ): int {
				$rank_a = self::TYPE_ORDER[ (string) ( ( (array) $a )['type'] ?? 'post' ) ] ?? 99;
				$rank_b = self::TYPE_ORDER[ (string) ( ( (array) $b )['type'] ?? 'post' ) ] ?? 99;

				return $rank_a <=> $rank_b;
			}
		);

		return $objects;
	}

	/**
	 * Dispatch one package by its type: post, term, or option. Each snapshots the
	 * current production state (for rollback) before applying the change.
	 */
	private function import_one( array $package, int $deployment_id ): array {
		$type        = (string) ( $package['type'] ?? 'post' );
		$origin_id   = (int) ( $package['origin_id'] ?? 0 );
		$title       = $this->title_for( $type, $package );
		$revision_id = 0;

		// Everything below runs inside a try/catch on purpose. An uncaught Error —
		// a missing class after a partial file upload is the classic one — would
		// otherwise abort the whole REST request with an empty HTTP 500, and the
		// sending site would report a bare "Deployment failed." with no cause at
		// all. Catching per object turns that into a specific, logged message and
		// lets the rest of the batch continue.
		try {
			$revision_id = $this->snapshot_for( $type, $package, $deployment_id );

			switch ( $type ) {
				case 'term':
					$result = $this->term_importer->import( $package );
					break;
				case 'option':
					$result = $this->option_importer->import( $package );
					break;
				case 'media':
					$result = $this->media_importer->import( $package );
					break;
				case 'menu':
					$result = $this->menu_importer->import( $package );
					break;
				case 'post':
					$result = $this->importer->import( $package );
					break;
				default:
					$result = new \WP_Error(
						'ifs_deploy_unknown_type',
						/* translators: %s: object type */
						sprintf( __( 'Unknown object type "%s".', 'ifs-deploy' ), $type )
					);
			}
		} catch ( \Throwable $e ) {
			$result = new \WP_Error(
				'ifs_deploy_exception',
				sprintf(
					'%s: %s (%s:%d)',
					get_class( $e ),
					$e->getMessage(),
					basename( $e->getFile() ),
					$e->getLine()
				)
			);
		}

		if ( is_wp_error( $result ) ) {
			DebugLog::error(
				'Import failed',
				array(
					'type'      => $type,
					'origin_id' => $origin_id,
					'title'     => $title,
					'code'      => $result->get_error_code(),
					'error'     => $result->get_error_message(),
				)
			);

			return array(
				'ok'        => false,
				'type'      => $type,
				'origin_id' => $origin_id,
				'title'     => $title,
				'error'     => $result->get_error_message(),
			);
		}

		return array(
			'ok'          => true,
			'type'        => $type,
			'origin_id'   => $origin_id,
			'title'       => $title,
			'object_id'   => (int) ( $result['post_id'] ?? $result['object_id'] ?? 0 ),
			'created'     => ! empty( $result['created'] ),
			'revision_id' => $revision_id,
		);
	}

	/**
	 * Capture a pre-change snapshot of the production object, returning the
	 * revision id (0 when there is nothing to snapshot / roll back to).
	 */
	private function snapshot_for( string $type, array $package, int $deployment_id ): int {
		switch ( $type ) {
			case 'post':
				$existing_id = $this->importer->find_target( $package );
				return $existing_id ? (int) ( $this->snapshots->capture( $deployment_id, $existing_id ) ?? 0 ) : 0;
			case 'term':
				$taxonomy = (string) ( $package['subtype'] ?? '' );
				$slug     = (string) ( $package['term']['slug'] ?? '' );
				$term     = ( '' !== $slug && taxonomy_exists( $taxonomy ) ) ? get_term_by( 'slug', $slug, $taxonomy ) : null;
				return ( $term instanceof \WP_Term )
					? (int) ( $this->snapshots->capture_term( $deployment_id, (int) $term->term_id, $taxonomy ) ?? 0 )
					: 0;
			case 'option':
				return (int) ( $this->snapshots->capture_option( $deployment_id, (string) ( $package['name'] ?? '' ) ) ?? 0 );
			case 'media':
				// An attachment is a post; snapshot its DB record if it already
				// exists (the binary file itself is not versioned).
				$existing = $this->media_importer->find_existing( $package );
				return $existing ? (int) ( $this->snapshots->capture( $deployment_id, $existing ) ?? 0 ) : 0;
			case 'menu':
				$slug = (string) ( $package['menu']['slug'] ?? '' );
				$name = (string) ( $package['menu']['name'] ?? '' );
				$menu = ( '' !== $slug ) ? wp_get_nav_menu_object( $slug ) : false;
				if ( ! $menu && '' !== $name ) {
					$menu = wp_get_nav_menu_object( $name );
				}
				return $menu ? (int) ( $this->snapshots->capture_menu( $deployment_id, (int) $menu->term_id ) ?? 0 ) : 0;
		}
		return 0;
	}

	private function title_for( string $type, array $package ): string {
		switch ( $type ) {
			case 'term':
				return (string) ( $package['term']['name'] ?? '' );
			case 'option':
				return (string) ( $package['name'] ?? '' );
			case 'media':
				return (string) ( $package['attachment']['post_title'] ?? $package['filename'] ?? '' );
			case 'menu':
				return (string) ( $package['menu']['name'] ?? '' );
			default:
				return (string) ( $package['object']['post_title'] ?? '' );
		}
	}

	private function resolve_status( int $total, int $succeeded ): string {
		if ( 0 === $succeeded ) {
			return DeploymentRepository::STATUS_FAILED;
		}
		if ( $succeeded < $total ) {
			return DeploymentRepository::STATUS_PARTIAL;
		}
		return DeploymentRepository::STATUS_SUCCESS;
	}
}
