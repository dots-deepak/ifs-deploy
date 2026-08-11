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
			return array(
				'format'      => MediaExporter::PACKAGE_FORMAT,
				'type'        => 'media',
				'subtype'     => (string) $item->object_subtype,
				'action'      => 'delete',
				'origin_id'   => (int) $item->object_id,
				'origin_site' => $origin_site,
				'source_url'  => '',
			);
		}

		return array(
			'format'      => PostExporter::PACKAGE_FORMAT,
			'type'        => 'post',
			'subtype'     => (string) $item->object_subtype,
			'action'      => 'delete',
			'origin_id'   => (int) $item->object_id,
			'origin_site' => $origin_site,
			'object'      => array(
				'post_title' => (string) $item->object_title,
				'post_type'  => (string) $item->object_subtype,
			),
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
