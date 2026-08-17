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
		/*
		 * ONE DEPLOYMENT RECORD PER UUID, however many requests it arrives in.
		 *
		 * A push is no longer necessarily one HTTP request: the sending site can split a
		 * large one into batches so it can report progress and so no single request has to
		 * survive a hundred media downloads. Every batch carries the SAME uuid.
		 *
		 * Creating a record per request would have made each batch its own deployment —
		 * and snapshots hang off the deployment id, so ROLLBACK WOULD ONLY EVER RESTORE
		 * THE LAST BATCH. That is the trap this reuse exists to avoid, and it is the
		 * reason batching was deferred until now (§18).
		 *
		 * Looking the record up by uuid also makes a retried batch harmless: it lands in
		 * the same deployment rather than fragmenting the history.
		 */
		$existing      = $this->deployments->get_by_uuid( $deployment_uuid );
		$deployment_id = null !== $existing
			? (int) $existing->id
			: $this->deployments->create( $deployment_uuid, 0, DeploymentRepository::STATUS_PENDING );

		$results = array();

		foreach ( $this->in_dependency_order( $objects ) as $package ) {
			$results[] = $this->import_one( (array) $package, $deployment_id );
		}

		/*
		 * The status describes the WHOLE deployment, not this batch.
		 *
		 * Earlier batches are already recorded, so they are read back and merged before
		 * the verdict is recomputed. Without that, a final batch of one successful object
		 * would report `success` for a deployment whose earlier half had failed.
		 */
		$previous = null !== $existing ? json_decode( (string) $existing->deployment_log, true ) : array();
		$previous = is_array( $previous ) ? $previous : array();

		$all       = array_merge( $previous, $results );
		$succeeded = 0;

		foreach ( $all as $result ) {
			if ( ! empty( $result['ok'] ) ) {
				++$succeeded;
			}
		}

		$status = $this->resolve_status( count( $all ), $succeeded );
		$this->deployments->update( $deployment_id, $status, $all );

		return array(
			'uuid'   => $deployment_uuid,
			'status' => $status,

			// Only THIS batch's results: the sender maps them to its own queue rows, and
			// re-sending earlier ones would mark the same rows over and over.
			'results' => $results,

			// Totals for the whole deployment, so the sender can report progress that
			// accounts for what previous batches did.
			'totals'  => array(
				'objects'   => count( $all ),
				'succeeded' => $succeeded,
			),
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
	 * Where an object type sits in the import order.
	 *
	 * Exposed because a BATCHED push has to apply this order on the SENDING side, across
	 * the whole push, before it splits anything up. Sorting within a batch would only
	 * order each batch against itself — a post could still be sent in batch 1 and the
	 * media it references in batch 2, which is exactly the dependency this order exists
	 * to prevent (§12: PostImporter can only resolve an attachment that already exists).
	 *
	 * One definition, read from both sides, so the two cannot disagree about it.
	 */
	public static function rank( string $type ): int {
		return self::TYPE_ORDER[ $type ] ?? 99;
	}

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

			/*
			 * The CODE and DATA travel back, not just the sentence.
			 *
			 * Staging cannot act on prose. A media id conflict is the case that forced
			 * this: the operator is offered a "renumber on Staging" dialog, and building
			 * it needs the id that is taken and what is occupying it as fields, not as
			 * words inside a translated string.
			 *
			 * `data` is whatever the importer attached and is echoed verbatim, so it must
			 * never carry anything about this site beyond what the message already says.
			 */
			$data = $result->get_error_data();

			return array(
				'ok'        => false,
				'type'      => $type,
				'origin_id' => $origin_id,
				'title'     => $title,
				'code'      => (string) $result->get_error_code(),
				'error'     => $result->get_error_message(),
				'data'      => is_array( $data ) ? $data : array(),
			);
		}

		$object_id = (int) ( $result['post_id'] ?? $result['object_id'] ?? 0 );
		$created   = ! empty( $result['created'] );

		/*
		 * A CREATE GETS ITS OWN RESTORE POINT, recorded after the fact.
		 *
		 * `snapshot_for()` runs BEFORE the import and captures what was here already — so
		 * for something that did not exist yet it correctly captured nothing and returned
		 * 0. The History screen then offered no Rollback button, because as far as it could
		 * tell there was nothing to go back to.
		 *
		 * There is: the undo of a create is a removal. It could not be recorded earlier
		 * because the object's id on this site does not exist until it has been created,
		 * which is why this sits here and not with the other snapshot.
		 */
		if ( 0 === $revision_id && $created && $object_id > 0 ) {
			$revision_id = (int) ( $this->snapshots->capture_creation( $deployment_id, $type, $object_id ) ?? 0 );
		}

		return array(
			'ok'          => true,
			'type'        => $type,
			'origin_id'   => $origin_id,
			'title'       => $title,
			'object_id'   => $object_id,
			'created'     => $created,
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
				/*
				 * An attachment is a post; snapshot its DB record if it already exists (the
				 * binary file itself is not versioned).
				 *
				 * TRASHED ONES INCLUDED — the `true` is load-bearing. This matcher decides
				 * whether a restore point exists, and it was looking only at LIVE
				 * attachments while the import beside it looked in the trash as well. So
				 * anything sitting in this site's trash was snapshot-invisible: the removal
				 * or update went through, `revision_id` came back 0, and the History screen
				 * showed no Rollback button because as far as it could tell nothing had been
				 * overwritten. Reported as "media ka rollback track nahi ban raha".
				 *
				 * The two calls have to agree. If the import can act on an object, the
				 * snapshot has to have captured that same object first.
				 */
				$existing = $this->media_importer->find_existing( $package, true );
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
