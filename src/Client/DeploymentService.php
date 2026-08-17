<?php
declare(strict_types=1);

namespace IfsDeploy\Client;

use IfsDeploy\Auth\Credentials;
use IfsDeploy\Export\MediaExporter;
use IfsDeploy\Export\MenuExporter;
use IfsDeploy\Export\OptionExporter;
use IfsDeploy\Export\PostExporter;
use IfsDeploy\Export\TermExporter;
use IfsDeploy\History\DeploymentRepository;
use IfsDeploy\Import\ImportManager;
use IfsDeploy\Queue\QueueRepository;
use IfsDeploy\Support\DebugLog;

/**
 * Staging-side orchestration: turn selected queue items into packages, send the
 * signed batch to Production, then record history and update queue statuses.
 */
final class DeploymentService {

	private QueueRepository $queue;
	private PostExporter $exporter;
	private TermExporter $term_exporter;
	private OptionExporter $option_exporter;
	private MediaExporter $media_exporter;
	private MenuExporter $menu_exporter;
	private DeployClient $client;
	private DeploymentRepository $deployments;

	public function __construct(
		?QueueRepository $queue = null,
		?PostExporter $exporter = null,
		?DeployClient $client = null,
		?DeploymentRepository $deployments = null,
		?TermExporter $term_exporter = null,
		?OptionExporter $option_exporter = null,
		?MediaExporter $media_exporter = null,
		?MenuExporter $menu_exporter = null
	) {
		$this->queue           = $queue ?? new QueueRepository();
		$this->exporter        = $exporter ?? new PostExporter();
		$this->client          = $client ?? new DeployClient();
		$this->deployments     = $deployments ?? new DeploymentRepository();
		$this->term_exporter   = $term_exporter ?? new TermExporter();
		$this->option_exporter = $option_exporter ?? new OptionExporter();
		$this->media_exporter  = $media_exporter ?? new MediaExporter();
		$this->menu_exporter   = $menu_exporter ?? new MenuExporter();
	}

	/**
	 * Deploy a set of queue items.
	 *
	 * @param int[] $queue_ids
	 *
	 * @return array{ok:bool,message:string,uuid:string,status:string}
	 */
	public function deploy( array $queue_ids, int $user_id ): array {
		$objects = array();
		$mapped  = array(); // "type|object id" => queue id.

		foreach ( $queue_ids as $queue_id ) {
			$item = $this->queue->get( (int) $queue_id );
			if ( null === $item || QueueRepository::STATUS_PENDING !== $item->status ) {
				continue;
			}

			$package = $this->build_package( $item );
			if ( null === $package ) {
				// Silent until now: the row just flipped to "failed" with no reason
				// recorded anywhere. Usually the source object was deleted, or an
				// attachment has no resolvable file URL.
				DebugLog::error(
					'Could not build a deployment package',
					array(
						'queue_id' => (int) $item->id,
						'type'     => (string) $item->object_type,
						'subtype'  => (string) $item->object_subtype,
						'objectid' => (int) $item->object_id,
						'title'    => (string) $item->object_title,
					)
				);

				$this->queue->set_status( (int) $item->id, QueueRepository::STATUS_FAILED );
				continue;
			}

			$objects[] = $package;

			$mapped[ self::map_key( (string) $item->object_type, (int) $item->object_id ) ] = (int) $item->id;
		}

		return $this->dispatch( $objects, $mapped, $user_id );
	}

	/**
	 * How many objects one batch carries.
	 *
	 * Small on purpose. The point of batching is that no single request has to survive the
	 * whole push — a hundred media downloads will exceed any server's execution limit —
	 * and that the browser gets to report progress between requests. A large batch gives
	 * back both problems.
	 *
	 * Media is the expensive type by far (a download and a full set of image sizes each),
	 * which is why the planner puts media first: those batches are the slow ones, and
	 * finishing them early makes the remaining estimate honest rather than optimistic.
	 */
	private const BATCH_SIZE = 5;

	/**
	 * Plan a batched push: which rows, in the order Production needs them.
	 *
	 * ── WHY THE ORDER IS DECIDED HERE ──────────────────────────────────────────────
	 *
	 * `ImportManager` sorts each request it receives into dependency order, which is
	 * enough when a push IS one request. Split into batches, sorting inside each one only
	 * orders a batch against itself: a post could be sent in batch 1 and the media it
	 * references in batch 2, and the post would import first — the exact dependency the
	 * order exists to prevent.
	 *
	 * So the whole push is ordered before anything is split, using
	 * `ImportManager::rank()` — the same definition Production applies, read from one
	 * place so the two cannot drift.
	 *
	 * ── WHY THE PLAN CARRIES TITLES AND TYPES ──────────────────────────────────────
	 *
	 * The browser needs to say what it is doing — "Uploading media…", "Pushing: About
	 * Us" — and it cannot learn that from a batch's RESPONSE, because by then the batch
	 * is already finished. Sending the descriptions up front means the dialog can name
	 * the work as it starts it rather than after it is over.
	 *
	 * It also costs nothing extra: these rows are read here anyway to check they are
	 * still pending.
	 *
	 * @param int[] $queue_ids
	 *
	 * @return array{uuid:string,batches:array<int,array<int,array{id:int,type:string,title:string}>>,total:int}
	 */
	public function plan( array $queue_ids ): array {
		$rows = array();

		foreach ( $queue_ids as $queue_id ) {
			$item = $this->queue->get( (int) $queue_id );

			if ( null === $item || QueueRepository::STATUS_PENDING !== $item->status ) {
				continue;
			}

			$rows[] = $item;
		}

		// Stable within a type: PHP's sort is stable, so the queue's own order (most
		// recently edited first) is preserved inside each group.
		usort(
			$rows,
			static fn( object $a, object $b ): int =>
				ImportManager::rank( (string) $a->object_type ) <=> ImportManager::rank( (string) $b->object_type )
		);

		$items = array_map(
			static fn( object $row ): array => array(
				'id'    => (int) $row->id,
				'type'  => (string) $row->object_type,
				'title' => (string) $row->object_title,
			),
			$rows
		);

		return array(
			'uuid'    => wp_generate_uuid4(),
			'batches' => array_chunk( $items, self::BATCH_SIZE ),
			'total'   => count( $items ),
		);
	}

	/**
	 * Send ONE batch of an already-planned push.
	 *
	 * Every batch carries the same uuid, so Production files them all under one
	 * deployment — see ImportManager::run(), where that is what keeps rollback able to
	 * restore the whole push rather than only its last part.
	 *
	 * Locks are taken and released PER BATCH rather than held across the whole push. A
	 * transient lock cannot be handed from one HTTP request to the next without either
	 * leaking on a crash or being re-acquired anyway, and the property that actually
	 * matters is unchanged: two people cannot have the same object in flight at the same
	 * moment. What is lost is only that a conflict may now be discovered part-way through
	 * rather than before anything is sent — which is why the message says so.
	 *
	 * @param int[] $queue_ids Ids for THIS batch, from plan().
	 *
	 * @return array{ok:bool,message:string,uuid:string,status:string,results:int}
	 */
	public function deploy_batch( string $uuid, array $queue_ids, int $user_id ): array {
		$objects = array();
		$mapped  = array();
		$failed  = 0;

		foreach ( $queue_ids as $queue_id ) {
			$item = $this->queue->get( (int) $queue_id );

			if ( null === $item || QueueRepository::STATUS_PENDING !== $item->status ) {
				continue;
			}

			$package = $this->build_package( $item );

			if ( null === $package ) {
				DebugLog::error(
					'Could not build a deployment package',
					array(
						'queue_id' => (int) $item->id,
						'type'     => (string) $item->object_type,
						'objectid' => (int) $item->object_id,
						'title'    => (string) $item->object_title,
					)
				);

				$this->queue->set_status( (int) $item->id, QueueRepository::STATUS_FAILED );
				++$failed;
				continue;
			}

			$objects[] = $package;

			$mapped[ self::map_key( (string) $item->object_type, (int) $item->object_id ) ] = (int) $item->id;
		}

		if ( empty( $objects ) ) {
			/*
			 * NOTHING WAS SENT — and whether that is fine depends entirely on WHY.
			 *
			 * This returned a bare success for both cases, which is how a push could run to
			 * "Deployment complete" having transmitted nothing at all: the dialog filled,
			 * the page reloaded, and Production was untouched. Reported as "push kar rha hu
			 * lekin live site pe koi changes nahi ho rahe".
			 *
			 * Rows that resolved themselves between planning and sending really are nothing
			 * to do. A row whose PACKAGE COULD NOT BE BUILT is a failure, and it is already
			 * marked failed above — so announcing success on top of that is the one thing
			 * this must not do.
			 */
			if ( $failed > 0 ) {
				return array(
					'ok'      => false,
					'message' => sprintf(
						/* translators: %d: how many items could not be prepared */
						_n(
							'%d item could not be prepared for deployment and nothing was sent. It is marked failed — see Logs & Diagnostics.',
							'%d items could not be prepared for deployment and nothing was sent. They are marked failed — see Logs & Diagnostics.',
							$failed,
							'ifs-deploy'
						),
						$failed
					),
					'uuid'    => $uuid,
					'status'  => DeploymentRepository::STATUS_FAILED,
					'results' => 0,
				);
			}

			return array(
				'ok'      => true,
				'message' => '',
				'uuid'    => $uuid,
				'status'  => DeploymentRepository::STATUS_PENDING,
				'results' => 0,
			);
		}

		$lock  = new DeployLock();
		$claim = $lock->acquire( $this->lock_targets( $objects ) );

		if ( ! $claim['ok'] ) {
			DeployLock::note_conflict( $claim['blocked'] );

			return array(
				'ok'      => false,
				'message' => $this->conflict_message( $claim['blocked'] ),
				'uuid'    => $uuid,
				'status'  => DeploymentRepository::STATUS_FAILED,
				'results' => 0,
			);
		}

		foreach ( $this->lock_targets( $objects ) as $target ) {
			$lock->note_owner( (string) $target['type'], (int) $target['id'], $user_id );
		}

		/*
		 * THE HISTORY RECORD, opened on the first batch and reused by the rest.
		 *
		 * ── WHY THIS WAS MISSING ENTIRELY ──────────────────────────────────────────────
		 *
		 * `send()` — the single-request path behind Compare & Sync's per-row Push — creates
		 * a deployment record. This one never did. So every push started from Pending
		 * Changes, which is every push of media and the overwhelming majority of all
		 * pushes, completed successfully and left NO trace in Deployment History.
		 *
		 * It also took rollback with it. The History screen offers Rollback from a
		 * deployment row, and there was no row — so a batched push, however well it went,
		 * could never be rolled back from this side.
		 *
		 * Batching is what made it invisible in review: the record used to be created by
		 * the path the tests exercised, and the new path simply did not grow one.
		 *
		 * ── WHY BY UUID ────────────────────────────────────────────────────────────────
		 *
		 * Every batch of one push carries the same uuid, exactly as Production relies on in
		 * `ImportManager::run()`. Looking the record up rather than creating one per request
		 * is what keeps a fifty-item push ONE deployment instead of five — and it has to
		 * match Production's grouping, or a rollback would ask that side to undo a
		 * deployment it filed differently.
		 */
		$deployment = $this->deployments->get_by_uuid( $uuid );

		$deployment_id = null !== $deployment
			? (int) $deployment->id
			: $this->deployments->create( $uuid, $user_id, DeploymentRepository::STATUS_PENDING );

		$previous = null !== $deployment ? self::decode_log( $deployment ) : array();

		try {
			$response = $this->client->post(
				'import',
				array(
					'deployment_uuid' => $uuid,
					'objects'         => $objects,
				)
			);

			if ( is_wp_error( $response ) ) {
				$message = $this->transport_message( $response );

				$this->deployments->update(
					$deployment_id,
					DeploymentRepository::STATUS_FAILED,
					array_merge( $previous, array( array( 'ok' => false, 'error' => $message ) ) )
				);

				return array(
					'ok'      => false,
					'message' => $message,
					'uuid'    => $uuid,
					'status'  => DeploymentRepository::STATUS_FAILED,
					'results' => 0,
				);
			}

			$body    = (array) $response['body'];
			$results = is_array( $body['results'] ?? null ) ? $body['results'] : array();

			$this->apply_results( $results, $mapped );

			$batch_failed = false;

			foreach ( $results as $result ) {
				if ( empty( $result['ok'] ) ) {
					$batch_failed = true;
					break;
				}
			}

			/*
			 * THE WHOLE PUSH SO FAR, not just this batch.
			 *
			 * Production sends back only the results for the objects in this request, so
			 * writing them straight to the record would leave the History screen describing
			 * the LAST batch and nothing else — a fifty-item push showing five objects.
			 * They are appended to what earlier batches recorded, which is the same thing
			 * Production does with its own copy.
			 *
			 * The status is recomputed over the merged set rather than taken from this
			 * batch: a push whose first batch failed and whose second succeeded has not
			 * succeeded, and `can_rollback()` reads this column to decide whether to offer
			 * the button at all.
			 */
			$all = array_merge( $previous, array_values( $results ) );

			$this->deployments->update( $deployment_id, self::status_for( $all ), $all );

			return array(
				'ok'        => 200 === $response['status'] && ! $batch_failed,
				'message'   => $batch_failed ? $this->failure_message( $response, $results ) : '',
				'uuid'      => $uuid,
				'status'    => (string) ( $body['status'] ?? DeploymentRepository::STATUS_PENDING ),
				'results'   => count( $results ),
				'conflicts' => self::id_conflicts( $results ),
			);
		} finally {
			$lock->release_all();
		}
	}

	/**
	 * The deployment log a record already holds, as an array.
	 *
	 * Anything unreadable is treated as empty rather than fatal: a corrupt log must not
	 * stop the push that is currently running from recording what it did.
	 */
	private static function decode_log( object $deployment ): array {
		$log = json_decode( (string) ( $deployment->deployment_log ?? '' ), true );

		return is_array( $log ) ? $log : array();
	}

	/**
	 * Overall status for a set of per-object results.
	 *
	 * PARTIAL is a real outcome and the reason this is not a boolean: some objects reached
	 * Production and some did not, and both the History screen and rollback need to know
	 * that rather than being told the push simply failed. The snapshots for whatever DID
	 * land are real, so the deployment is still rollback-able.
	 */
	public static function status_for( array $results ): string {
		if ( empty( $results ) ) {
			return DeploymentRepository::STATUS_PENDING;
		}

		$ok = 0;

		foreach ( $results as $result ) {
			if ( ! empty( ( (array) $result )['ok'] ) ) {
				++$ok;
			}
		}

		if ( 0 === $ok ) {
			return DeploymentRepository::STATUS_FAILED;
		}

		return $ok === count( $results )
			? DeploymentRepository::STATUS_SUCCESS
			: DeploymentRepository::STATUS_PARTIAL;
	}

	/**
	 * Media ID conflicts picked out of a batch's results, so the browser can offer to fix
	 * them instead of only reporting them.
	 *
	 * Production refuses to create a new attachment at an id it has already given away,
	 * because this plugin copies meta verbatim and ACF fields, galleries and `wp-image-N`
	 * classes all store the bare number. That refusal is the honest outcome, but on its own
	 * it leaves the operator with a sentence and no way forward — and the site that can
	 * still act is this one, not Production.
	 *
	 * Only this specific code is extracted. Every other failure stays prose, because
	 * nothing here can offer a button for it.
	 *
	 * @param array $results Per-object results as returned by Production.
	 * @return array<int,array{origin_id:int,title:string,message:string,occupant:array}>
	 */
	public static function id_conflicts( array $results ): array {
		$conflicts = array();

		foreach ( $results as $result ) {
			if ( 'ifs_deploy_media_id_taken' !== (string) ( $result['code'] ?? '' ) ) {
				continue;
			}

			$data = (array) ( $result['data'] ?? array() );

			$conflicts[] = array(
				'origin_id' => (int) ( $result['origin_id'] ?? 0 ),
				'title'     => (string) ( $result['title'] ?? '' ),
				'message'   => (string) ( $result['error'] ?? '' ),
				'occupant'  => (array) ( $data['occupant'] ?? array() ),
			);
		}

		return $conflicts;
	}

	/**
	 * Deploy specific posts by ID (used by the Compare screen's per-row Push).
	 *
	 * @param int[] $post_ids
	 *
	 * @return array{ok:bool,message:string,uuid:string,status:string}
	 */
	public function deploy_posts( array $post_ids, int $user_id ): array {
		$objects = array();
		$mapped  = array();

		foreach ( $post_ids as $post_id ) {
			$post_id = (int) $post_id;
			$package = $this->exporter->export( $post_id );
			if ( null === $package ) {
				continue;
			}

			$objects[] = $package;

			// If this post is also in the pending queue, mark it deployed too.
			$queue_item = $this->queue->find_by_object_id( $post_id );

			$mapped[ self::map_key( 'post', $post_id ) ] = $queue_item ? (int) $queue_item->id : 0;
		}

		return $this->dispatch( $objects, $mapped, $user_id );
	}

	/**
	 * Send a batch of packages to Production and record the result.
	 *
	 * @param array          $objects Packages to deploy.
	 * @param array<int,int> $mapped  object id => queue id (0 if none).
	 *
	 * @return array{ok:bool,message:string,uuid:string,status:string}
	 */
	private function dispatch( array $objects, array $mapped, int $user_id ): array {
		$uuid = wp_generate_uuid4();

		if ( empty( $objects ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Nothing to deploy.', 'ifs-deploy' ),
				'uuid'    => $uuid,
				'status'  => DeploymentRepository::STATUS_FAILED,
			);
		}

		/*
		 * CLAIM THE OBJECTS FIRST — before a deployment record exists.
		 *
		 * On a team, two people pushing the same page at the same moment used to produce two
		 * deployments, two imports and two rollback snapshots for one logical change. Nothing
		 * errored; the later write just won. What broke quietly was rollback (two restore
		 * points, newest not the one anyone meant) and the queue row's `deployed_hash`, which
		 * ended up stamped by whichever request finished last.
		 *
		 * Locks are taken here rather than inside the loop above so a refusal costs nothing:
		 * no deployment row, no history entry, nothing to explain afterwards.
		 */
		$lock  = new DeployLock();
		$claim = $lock->acquire( $this->lock_targets( $objects ) );

		if ( ! $claim['ok'] ) {
			DeployLock::note_conflict( $claim['blocked'] );

			return array(
				'ok'      => false,
				'message' => $this->conflict_message( $claim['blocked'] ),
				'uuid'    => $uuid,
				'status'  => DeploymentRepository::STATUS_FAILED,
			);
		}

		foreach ( $this->lock_targets( $objects ) as $target ) {
			$lock->note_owner( (string) $target['type'], (int) $target['id'], $user_id );
		}

		try {
			return $this->send( $objects, $mapped, $user_id, $uuid );
		} finally {
			// `finally`, so a fatal or an exception mid-deploy cannot leave the object locked
			// for the full TTL. The transient expiry is the backstop, not the mechanism.
			$lock->release_all();
		}
	}

	/**
	 * The objects a push must hold a lock on.
	 *
	 * @param array<int,array> $objects
	 * @return array<int,array{type:string,id:int,title:string}>
	 */
	private function lock_targets( array $objects ): array {
		$targets = array();

		foreach ( $objects as $package ) {
			$targets[] = array(
				'type'  => (string) ( $package['type'] ?? 'post' ),
				'id'    => (int) ( $package['origin_id'] ?? 0 ),
				'title' => (string) ( $package['object']['post_title'] ?? $package['term']['name'] ?? $package['name'] ?? '' ),
			);
		}

		return $targets;
	}

	/**
	 * Name the person and the object, because "deployment failed" would send someone
	 * hunting for a fault that does not exist.
	 *
	 * @param array<int,array{type:string,id:int,title?:string}> $blocked
	 */
	private function conflict_message( array $blocked ): string {
		$first = $blocked[0] ?? array();
		$title = (string) ( $first['title'] ?? '' );
		$label = '' !== $title ? $title : sprintf( '#%d', (int) ( $first['id'] ?? 0 ) );
		$who   = DeployLock::holder_name( (string) ( $first['type'] ?? '' ), (int) ( $first['id'] ?? 0 ) );

		if ( count( $blocked ) > 1 ) {
			return sprintf(
				/* translators: 1: object title, 2: how many more, 3: user name or a fallback */
				_n(
					'"%1$s" and %2$d other item are being pushed by %3$s right now. Nothing was sent — wait for that push to finish and try again.',
					'"%1$s" and %2$d other items are being pushed by %3$s right now. Nothing was sent — wait for that push to finish and try again.',
					count( $blocked ) - 1,
					'ifs-deploy'
				),
				$label,
				count( $blocked ) - 1,
				'' !== $who ? $who : __( 'another user', 'ifs-deploy' )
			);
		}

		return sprintf(
			/* translators: 1: object title, 2: user name or a fallback */
			__( '"%1$s" is being pushed by %2$s right now. Nothing was sent — wait for that push to finish and try again.', 'ifs-deploy' ),
			$label,
			'' !== $who ? $who : __( 'another user', 'ifs-deploy' )
		);
	}

	/**
	 * The deployment itself, once the objects are locked.
	 *
	 * @param array<int,array>   $objects
	 * @param array<int,int>     $mapped
	 */
	private function send( array $objects, array $mapped, int $user_id, string $uuid ): array {
		$deployment_id = $this->deployments->create( $uuid, $user_id, DeploymentRepository::STATUS_PENDING );

		DebugLog::info(
			'Sending deployment to Production',
			array(
				'uuid'    => $uuid,
				'objects' => count( $objects ),
				'types'   => array_count_values(
					array_map(
						static fn( array $package ): string => (string) ( $package['type'] ?? 'post' ),
						$objects
					)
				),
			)
		);

		$response = $this->client->post(
			'import',
			array(
				'deployment_uuid' => $uuid,
				'objects'         => $objects,
			)
		);

		if ( is_wp_error( $response ) ) {
			$message = $this->transport_message( $response );

			$this->deployments->update(
				$deployment_id,
				DeploymentRepository::STATUS_FAILED,
				array( 'error' => $message )
			);

			return array(
				'ok'      => false,
				'message' => $message,
				'uuid'    => $uuid,
				'status'  => DeploymentRepository::STATUS_FAILED,
			);
		}

		return $this->finalize( $deployment_id, $uuid, $response, $mapped );
	}

	/**
	 * Stop a push part-way and undo whatever it had already applied.
	 *
	 * Batching means a cancel can land after some objects are live on Production, so
	 * "cancel" cannot just mean "stop sending". Production reverts them, and this puts the
	 * queue rows back where they were.
	 *
	 * THE ROWS STAY PENDING, deliberately. A cancelled push is a decision to not publish
	 * yet — not a decision to discard the work. Clearing the rows would silently destroy
	 * what someone had queued; leaving them pending means the user decides afterwards
	 * whether to push again or ignore them.
	 *
	 * @param int[] $queue_ids Rows already marked deployed by completed batches.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function cancel( string $uuid, array $queue_ids ): array {
		$response = $this->client->post( 'cancel', array( 'deployment_uuid' => $uuid ) );

		/*
		 * MARK IT CANCELLED HERE TOO, and do it whatever Production says.
		 *
		 * A batched push now opens a history record on this side, so a cancelled one would
		 * otherwise be left sitting at `pending` or `partial` — which is precisely the
		 * state `can_rollback()` offers a Rollback button for. That button would propose
		 * undoing a deployment whose snapshots the cancel has already applied and deleted:
		 * it would re-apply the very state the cancel just reverted.
		 *
		 * Unconditional on purpose. If Production could not be reached, this push is still
		 * not something to offer a rollback of — the honest reading of an unknown remote
		 * state is "do not offer to undo it again", and the message below already tells the
		 * operator to check Production directly.
		 */
		$deployment = $this->deployments->get_by_uuid( $uuid );

		if ( null !== $deployment ) {
			$this->deployments->set_status( (int) $deployment->id, DeploymentRepository::STATUS_CANCELLED );
		}

		/*
		 * The queue is restored EVEN IF the remote call failed.
		 *
		 * If Production could not be reached, the safest assumption is that its state is
		 * unknown — and an unknown state must leave the row pending rather than deployed.
		 * `restore_pending()` also clears `deployed_hash`, so nothing later treats the row
		 * as already-pushed. Erring the other way would drop someone's work out of Pending
		 * Changes on the strength of a request that never arrived.
		 */
		$restored = $this->queue->restore_pending( $queue_ids );

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'message' => sprintf(
					/* translators: %s: underlying transport error */
					__( 'The push was stopped, but Production could not be reached to undo what had already been sent (%s). Check Deployment History on Production and roll that deployment back if part of it is live. Your changes are still listed here.', 'ifs-deploy' ),
					$response->get_error_message()
				),
			);
		}

		$body = (array) ( $response['body'] ?? array() );

		if ( 200 !== (int) $response['status'] || empty( $body['ok'] ) ) {
			return array(
				'ok'      => false,
				'message' => (string) ( $body['error'] ?? __( 'The push was stopped, but Production did not confirm the undo. Check Deployment History there. Your changes are still listed here.', 'ifs-deploy' ) ),
			);
		}

		if ( ! empty( $body['nothing_applied'] ) ) {
			return array(
				'ok'      => true,
				'message' => __( 'Push cancelled. Nothing had reached Production yet, so nothing needed undoing — your changes are still listed here.', 'ifs-deploy' ),
			);
		}

		return array(
			'ok'      => true,
			'message' => sprintf(
				/* translators: 1: objects reverted on Production, 2: rows returned to the list */
				_n(
					'Push cancelled. %1$d change already sent was undone on Production, and %2$d item is still listed here.',
					'Push cancelled. %1$d changes already sent were undone on Production, and %2$d items are still listed here.',
					(int) ( $body['restored'] ?? 0 ),
					'ifs-deploy'
				),
				(int) ( $body['restored'] ?? 0 ),
				$restored
			),
		);
	}

	/**
	 * Request rollback of a prior deployment on Production.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function rollback( int $deployment_id ): array {
		$deployment = $this->deployments->get( $deployment_id );
		if ( null === $deployment ) {
			return array( 'ok' => false, 'message' => __( 'Deployment not found.', 'ifs-deploy' ) );
		}

		$response = $this->client->post( 'rollback', array( 'deployment_uuid' => $deployment->deployment_uuid ) );

		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'message' => $response->get_error_message() );
		}

		$ok = ! empty( $response['body']['ok'] );

		if ( $ok ) {
			$this->deployments->set_status( $deployment_id, DeploymentRepository::STATUS_ROLLED_BACK );
		}

		return array(
			'ok'      => $ok,
			'message' => $ok
				? sprintf(
					/* translators: 1: restored count, 2: total count */
					__( 'Rolled back %1$d of %2$d objects.', 'ifs-deploy' ),
					(int) ( $response['body']['restored'] ?? 0 ),
					(int) ( $response['body']['total'] ?? 0 )
				)
				: (string) ( $response['body']['error'] ?? __( 'Rollback failed.', 'ifs-deploy' ) ),
		);
	}

	/**
	 * The key an object's queue row is filed under while a deployment is in flight.
	 *
	 * Public and static so the pairing can be tested without a database: the id space
	 * is shared between object types, so this is the only thing standing between one
	 * object's result and another object's queue row.
	 */
	public static function map_key( string $type, int $object_id ): string {
		return ( '' !== $type ? $type : 'post' ) . '|' . $object_id;
	}

	private function build_package( object $item ): ?array {
		$type = (string) $item->object_type;

		if ( 'delete' === $item->action ) {
			return $this->build_delete_package( $item, $type );
		}

		switch ( $type ) {
			case 'term':
				return $this->term_exporter->export( (int) $item->object_id, (string) $item->object_subtype );
			case 'option':
				return $this->option_exporter->export( (string) $item->object_title );
			case 'media':
				return $this->media_exporter->export( (int) $item->object_id );
			case 'menu':
				return $this->menu_exporter->export( (int) $item->object_id );
			case 'post':
			default:
				return $this->exporter->export( (int) $item->object_id );
		}
	}

	private function build_delete_package( object $item, string $type ): array {
		$origin_site = (string) Credentials::get()['site_id'];

		if ( 'term' === $type ) {
			return array(
				'format'      => TermExporter::PACKAGE_FORMAT,
				'type'        => 'term',
				'subtype'     => (string) $item->object_subtype,
				'action'      => 'delete',
				'origin_id'   => (int) $item->object_id,
				'origin_site' => $origin_site,
				'term'        => array(
					'name' => (string) $item->object_title,
					'slug' => '', // resolved on import by name lookup (slug is gone post-delete).
				),
			);
		}

		if ( 'option' === $type ) {
			return array(
				'format'      => OptionExporter::PACKAGE_FORMAT,
				'type'        => 'option',
				'subtype'     => '',
				'action'      => 'delete',
				'origin_id'   => (int) $item->object_id,
				'origin_site' => $origin_site,
				'name'        => (string) $item->object_title,
			);
		}

		if ( 'media' === $type ) {
			/*
			 * A TRASHED attachment still exists, so it can still be described.
			 *
			 * This used to send an empty source URL and no filename, which left
			 * `MediaImporter::find_existing()` with only the origin stamp to go on — so
			 * media that reached Production any other way could never be matched, and the
			 * removal silently did nothing.
			 *
			 * On a site with `MEDIA_TRASH` enabled — where removing media means trashing
			 * it — the attachment is still readable at push time, and the recorded source
			 * URL is the strongest match the importer has. A permanently deleted one is
			 * gone and these stay empty, which is the old behaviour and is reported
			 * honestly rather than passing as a success.
			 */
			$attachment = get_post( (int) $item->object_id );
			$exists     = $attachment instanceof \WP_Post;

			return array(
				'format'      => MediaExporter::PACKAGE_FORMAT,
				'type'        => 'media',
				'subtype'     => (string) $item->object_subtype,
				'action'      => 'delete',
				'removal'     => self::removal_intent( (int) $item->object_id ),
				'origin_id'   => (int) $item->object_id,
				'origin_site' => $origin_site,
				'source_url'  => $exists ? (string) wp_get_attachment_url( (int) $attachment->ID ) : '',
				'filename'    => $exists ? basename( (string) get_attached_file( (int) $attachment->ID ) ) : '',
			);
		}

		return array(
			'format'      => PostExporter::PACKAGE_FORMAT,
			'type'        => 'post',
			'subtype'     => (string) $item->object_subtype,
			'action'      => 'delete',
			'removal'     => self::removal_intent( (int) $item->object_id ),
			'origin_id'   => (int) $item->object_id,
			'origin_site' => $origin_site,
			'object'      => array_merge(
				array(
					'post_title' => (string) $item->object_title,
					'post_type'  => (string) $item->object_subtype,
				),
				// Slug and date when the post can still be read, which is most of the time.
				$this->delete_identity( (int) $item->object_id )
			),
		);
	}

	/**
	 * Was this object TRASHED here, or permanently DESTROYED? Production is told which.
	 *
	 * ── WHY THE INTENT HAS TO TRAVEL ───────────────────────────────────────────────
	 *
	 * The delete package used to say only "remove this" and let the far side decide how,
	 * on the reasoning that a deploy should not impose one site's settings on the other.
	 * That reasoning was wrong, and it is why trashing media on Staging destroyed it on
	 * Production.
	 *
	 * `MEDIA_TRASH` DEFAULTS TO FALSE — `wp-includes/default-constants.php` defines it
	 * that way when wp-config.php does not. So a Staging site with media trash switched on
	 * paired with an ordinary Production site meant `wp_delete_attachment( $id, false )`
	 * fell straight through to a permanent delete. The operator trashed a file expecting
	 * to be able to change their mind, pushed, and the file was gone from the live site
	 * with no trash entry to restore from.
	 *
	 * "Recoverable" is a property of the ACTION the operator took, not of the receiving
	 * site's configuration. Trash here means trash there.
	 *
	 * ── WHY IT IS INFERRED HERE RATHER THAN RECORDED AT HOOK TIME ──────────────────
	 *
	 * The queue row carries no room for it, and it does not need to: an object that still
	 * exists at push time was trashed, and one that is gone was destroyed. Reading it here
	 * is also MORE accurate than recording it when the hook fired, because the two can
	 * happen in sequence — trash an image, then empty the trash before pushing. The row is
	 * superseded by hash, so only the final state is ever pushed, and this reports that
	 * final state rather than whichever hook happened to fire first.
	 *
	 * Anything still present that is NOT trashed (restored after the row was written, say)
	 * is reported as a trash: the recoverable reading is the safe one when the two sites
	 * disagree about what happened.
	 *
	 * @return string 'trash'|'delete'
	 */
	public static function removal_intent( int $object_id ): string {
		return get_post( $object_id ) instanceof \WP_Post ? 'trash' : 'delete';
	}

	/**
	 * Extra identifying fields for a delete, read from the post if it still exists.
	 *
	 * ── WHY A DELETE NEEDED MORE TO GO ON ──────────────────────────────────────────
	 *
	 * A delete package used to carry only the title and the post type. `PostImporter`
	 * resolves its target by origin stamp, then id parity, then slug — and with no slug
	 * the third strategy could never run, while the second had nothing but the title to
	 * corroborate itself with. So a page whose title had been edited before it was
	 * deleted, or one on a site that was cloned rather than deployed to, simply could not
	 * be found and the deletion did not happen.
	 *
	 * A TRASHED post is still readable, which covers the ordinary case: WordPress trashes
	 * before it deletes, and most deletions never go further than that. A permanently
	 * deleted one is gone, and then this returns nothing and the old behaviour stands —
	 * the honest failure in `PostImporter::delete_post()` reports it either way.
	 *
	 * ── THE `__trashed` TRAP ───────────────────────────────────────────────────────
	 *
	 * `wp_trash_post()` RENAMES the slug, appending `__trashed`, so that the URL is freed
	 * for a replacement page. Sending that would be worse than sending nothing: it can
	 * match nothing on Production, and it looks like a legitimate slug while doing it.
	 * Core keeps the original in `_wp_desired_post_slug`, so that is preferred, with the
	 * suffix stripped as a fallback for posts trashed by something that did not set it.
	 *
	 * @return array<string,string>
	 */
	private function delete_identity( int $post_id ): array {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return array();
		}

		$slug    = (string) $post->post_name;
		$desired = (string) get_post_meta( $post_id, '_wp_desired_post_slug', true );

		if ( '' !== $desired ) {
			$slug = $desired;
		} elseif ( '__trashed' === substr( $slug, -9 ) ) {
			$slug = substr( $slug, 0, -9 );
		}

		return array(
			'post_name' => $slug,
			'post_date' => (string) $post->post_date,
		);
	}

	/**
	 * Apply the import response to history + queue statuses.
	 *
	 * @param array{status:int,body:array} $response
	 * @param array<string,int>            $mapped "type|object_id" => queue_id
	 */
	private function finalize( int $deployment_id, string $uuid, array $response, array $mapped ): array {
		$body    = $response['body'];
		$results = is_array( $body['results'] ?? null ) ? $body['results'] : array();
		$status  = (string) ( $body['status'] ?? DeploymentRepository::STATUS_FAILED );

		$this->apply_results( $results, $mapped );

		$this->deployments->update( $deployment_id, $status, $results );

		$ok = DeploymentRepository::STATUS_FAILED !== $status && 200 === $response['status'];

		if ( $ok ) {
			return array(
				'ok'      => true,
				'message' => __( 'Deployment complete.', 'ifs-deploy' ),
				'uuid'    => $uuid,
				'status'  => $status,
			);
		}

		return array(
			'ok'      => false,
			'message' => $this->failure_message( $response, $results ),
			'uuid'    => $uuid,
			'status'  => $status,
		);
	}

	/**
	 * Mark each queue row with what Production said about its object.
	 *
	 * Shared by the single-request path and the batched one, so a row cannot be resolved
	 * one way by one and another way by the other.
	 *
	 * @param array             $results Per-object results from Production.
	 * @param array<string,int> $mapped  "type|object_id" => queue_id
	 */
	private function apply_results( array $results, array $mapped ): void {
		foreach ( $results as $result ) {
			/*
			 * Keyed by TYPE AND ID, because an id alone is not unique across types.
			 *
			 * Post ids, term ids, attachment ids and crc-derived option ids all live in
			 * the same integer space, so a batch containing post 42 and term 42 used to
			 * cross-assign their outcomes: the term's failure marked the POST's queue row
			 * failed, and the post's success marked the term deployed — stamping a
			 * deployed_hash for content Production never accepted, which then dropped the
			 * row out of Pending Changes for good.
			 *
			 * `?? 'post'` matches ImportManager's own default for a result with no type,
			 * so a Production old enough not to send one behaves exactly as before.
			 */
			$origin_id = (int) ( $result['origin_id'] ?? 0 );
			$queue_id  = $mapped[ self::map_key( (string) ( $result['type'] ?? 'post' ), $origin_id ) ] ?? 0;
			if ( ! $queue_id ) {
				continue;
			}

			if ( ! empty( $result['ok'] ) ) {
				// Records the deployed hash too, so undoing the edit later clears the row.
				$this->queue->mark_deployed( $queue_id );
			} else {
				$this->queue->set_status( $queue_id, QueueRepository::STATUS_FAILED );
			}
		}
	}

	/**
	 * Message for a request that never came back.
	 *
	 * A timeout is not the same as a rejection: Production keeps working after the
	 * client gives up, so some or all of the batch may already be live. Saying so
	 * matters — the honest advice is to check Production before re-pushing, since
	 * "failed" invites a blind retry.
	 */
	private function transport_message( \WP_Error $error ): string {
		$message = $error->get_error_message();

		if ( false === stripos( $message, 'timed out' ) && false === stripos( $message, 'timeout' ) ) {
			return $message;
		}

		return sprintf(
			/* translators: %s: underlying transport error */
			__( '%s — Production kept processing after this site stopped waiting, so part of the batch may already be live. Check Production (and IFS Deploy → Logs there) before pushing again, and try fewer items at a time.', 'ifs-deploy' ),
			$message
		);
	}

	/**
	 * Explain a failure instead of just announcing one.
	 *
	 * Order of preference: the specific per-object errors Production reported, then
	 * an explicit error field, then the HTTP status with the raw body excerpt (which
	 * is where a PHP fatal on the far side shows up). Every branch also records the
	 * detail to the Logs screen.
	 *
	 * @param array{status:int,body:array,raw?:string} $response
	 * @param array                                    $results  Per-object results.
	 */
	private function failure_message( array $response, array $results ): string {
		$body   = (array) $response['body'];
		$errors = array();

		foreach ( $results as $result ) {
			if ( is_array( $result ) && empty( $result['ok'] ) && ! empty( $result['error'] ) ) {
				$title    = (string) ( $result['title'] ?? '' );
				$errors[] = ( '' !== $title ? $title . ': ' : '' ) . (string) $result['error'];
			}
		}

		if ( ! empty( $errors ) ) {
			DebugLog::error( 'Deployment reported per-object failures', array( 'errors' => $errors ) );

			// Two is enough for a notice; the Logs screen has the full set.
			$shown = array_slice( $errors, 0, 2 );
			$more  = count( $errors ) - count( $shown );

			$message = implode( ' | ', $shown );
			if ( $more > 0 ) {
				/* translators: %d: number of additional errors */
				$message .= ' ' . sprintf( _n( '(+%d more)', '(+%d more)', $more, 'ifs-deploy' ), $more );
			}

			return $message;
		}

		if ( ! empty( $body['error'] ) ) {
			DebugLog::error( 'Deployment rejected by Production', array( 'error' => (string) $body['error'] ) );

			return (string) $body['error'];
		}

		$status = (int) $response['status'];
		$raw    = (string) ( $response['raw'] ?? '' );

		DebugLog::error(
			'Deployment failed with no per-object detail',
			array(
				'status' => $status,
				'raw'    => $raw,
			)
		);

		if ( 200 !== $status ) {
			return sprintf(
				/* translators: 1: HTTP status code, 2: response excerpt */
				__( 'Production returned HTTP %1$d. Response: %2$s — see IFS Deploy → Logs on both sites.', 'ifs-deploy' ),
				$status,
				$raw
			);
		}

		return __( 'Deployment failed with no detail from Production. See IFS Deploy → Logs on both sites.', 'ifs-deploy' );
	}
}
