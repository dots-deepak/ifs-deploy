<?php
declare(strict_types=1);

namespace IfsDeploy\Client;

use IfsDeploy\History\DeploymentRepository;
use IfsDeploy\Queue\QueueRepository;
use IfsDeploy\Support\DebugLog;

/**
 * Stops a push and puts both sites back.
 *
 * ── WHAT "CANCEL" HAS TO MEAN ──────────────────────────────────────────────────────
 *
 * Nothing from this push is left on Production, and nothing is lost from Pending Changes.
 * Not "most of it" and not "whatever had not started yet" — a cancel that leaves three of
 * ten items live is worse than no cancel at all, because the operator believes it worked.
 *
 * ── WHY IT NEEDS A CLASS OF ITS OWN ────────────────────────────────────────────────
 *
 * A push is not one request. The browser sends a batch at a time, and pressing Cancel stops
 * it QUEUEING further batches — it cannot stop one already on the wire, and it cannot stop
 * Production finishing the one it is processing. So a cancel races the very thing it is
 * cancelling, and getting it right is a sequence rather than a call:
 *
 *   1. Record the cancellation LOCALLY FIRST, before any network call.
 *   2. Put the queue rows back, whatever Production says.
 *   3. Tell Production to revert.
 *   4. Ask again until it reports there was nothing left to revert.
 *
 * Step 1 is the one that was wrong, and it is the whole bug. The remote call used to be
 * made first and the local flag written after it returned — a window of however long that
 * HTTP request took. `DeploymentService::deploy_batch()` reads that flag to decide whether
 * to apply a batch's results, so a batch finishing inside the window read "not cancelled",
 * applied its objects to Production and marked its rows deployed. The operator pressed
 * Cancel and watched part of the push go live anyway.
 *
 * Ordering it the other way costs nothing and closes the window completely: the flag is a
 * local database write, so it lands before any concurrent batch can look at it.
 *
 * Step 4 is what makes the guarantee hold rather than merely being likely. A batch that
 * lands DURING the revert creates fresh snapshots on Production after the sweep has already
 * passed over them, so one sweep cannot be enough by construction.
 */
final class PushCancellation {

	/**
	 * How many times to sweep Production before giving up.
	 *
	 * Each pass reverts whatever it finds. The usual cancel takes two or three: one to undo
	 * what had landed, then the consecutive empty passes that confirm nothing arrived
	 * behind it. The bound exists so a push that keeps producing work — a very slow batch
	 * still being processed — ends in a reported failure rather than looping.
	 */
	private const MAX_SWEEPS = 4;

	/**
	 * Seconds to wait between passes.
	 *
	 * A batch still being processed by Production has to be given time to finish, or the
	 * next pass looks at the same unfinished state and learns nothing. Short enough that a
	 * cancel still feels immediate; the dialog says "Cancelling…" throughout.
	 */
	private const PAUSE_SECONDS = 2;

	/**
	 * Consecutive empty passes needed before Production is declared clean.
	 *
	 * ── WHY ONE IS NOT ENOUGH ──────────────────────────────────────────────────────
	 *
	 * A pass that reverts nothing has two possible meanings, and they are opposites:
	 * everything has been undone, or the batch has not landed YET. The second is common —
	 * cancelling quickly gets the request to Production before its import has registered
	 * anything, and a single empty pass then reported "nothing had reached Production"
	 * moments before the whole push went live.
	 *
	 * Two consecutive empty passes, with a pause between, separates the two.
	 */
	private const CLEAN_PASSES = 2;

	private DeployClient $client;
	private QueueRepository $queue;
	private DeploymentRepository $deployments;

	public function __construct(
		?DeployClient $client = null,
		?QueueRepository $queue = null,
		?DeploymentRepository $deployments = null
	) {
		$this->client      = $client ?? new DeployClient();
		$this->queue       = $queue ?? new QueueRepository();
		$this->deployments = $deployments ?? new DeploymentRepository();
	}

	/**
	 * @param int[] $queue_ids Every row in the push, including ones already sent.
	 *
	 * @return array{ok:bool,message:string,reverted:int,restored:int,sweeps:int}
	 */
	public function run( string $uuid, array $queue_ids ): array {
		$this->mark_cancelled( $uuid );

		/*
		 * THE QUEUE GOES BACK EVEN IF PRODUCTION CANNOT BE REACHED.
		 *
		 * If the remote state is unknown, the safe reading is that the row still needs
		 * pushing — so it stays in Pending Changes. Erring the other way drops someone's
		 * work off the list on the strength of a request that may never have arrived.
		 */
		$restored = $this->queue->restore_pending( $queue_ids );

		$sweep = $this->sweep( $uuid );

		if ( ! $sweep['ok'] ) {
			return array(
				'ok'       => false,
				'message'  => $sweep['message'],
				'reverted' => $sweep['reverted'],
				'restored' => $restored,
				'sweeps'   => $sweep['sweeps'],
			);
		}

		return array(
			'ok'       => true,
			'message'  => $this->describe( $sweep['reverted'], $restored ),
			'reverted' => $sweep['reverted'],
			'restored' => $restored,
			'sweeps'   => $sweep['sweeps'],
		);
	}

	/**
	 * Write the cancellation down before anything else happens.
	 *
	 * Creates the record when there is none, which is the case when a push is stopped
	 * before its first batch has landed — exactly when a still-queued batch is most likely
	 * to slip through, and also the case that used to leave no trace in Deployment History
	 * at all.
	 */
	private function mark_cancelled( string $uuid ): void {
		$deployment = $this->deployments->get_by_uuid( $uuid );

		$this->deployments->set_status(
			null !== $deployment
				? (int) $deployment->id
				: $this->deployments->create( $uuid, get_current_user_id(), DeploymentRepository::STATUS_CANCELLED ),
			DeploymentRepository::STATUS_CANCELLED
		);
	}

	/**
	 * Revert on Production, then confirm nothing arrived behind the revert.
	 *
	 * `/cancel` is idempotent and reports how many objects it put back, so "0 reverted" is
	 * a meaningful answer rather than a failure: it means the deployment has nothing
	 * applied left. That is what turns a hopeful single call into an actual check.
	 *
	 * @return array{ok:bool,message:string,reverted:int,sweeps:int}
	 */
	private function sweep( string $uuid ): array {
		$reverted = 0;
		$clean    = 0;

		for ( $pass = 1; $pass <= self::MAX_SWEEPS; $pass++ ) {
			$response = $this->client->post( 'cancel', array( 'deployment_uuid' => $uuid ) );

			if ( is_wp_error( $response ) ) {
				return array(
					'ok'       => false,
					'reverted' => $reverted,
					'sweeps'   => $pass,
					'message'  => sprintf(
						/* translators: %s: underlying transport error */
						__( 'The push was stopped and your changes are still listed here, but Production could not be reached to undo what had already been sent (%s). Open Deployment History on Production and roll that deployment back before pushing again.', 'ifs-deploy' ),
						$response->get_error_message()
					),
				);
			}

			$body = (array) ( $response['body'] ?? array() );

			if ( 200 !== (int) $response['status'] || empty( $body['ok'] ) ) {
				return array(
					'ok'       => false,
					'reverted' => $reverted,
					'sweeps'   => $pass,
					'message'  => (string) ( $body['error'] ?? __( 'The push was stopped and your changes are still listed here, but Production did not confirm the undo. Check Deployment History there.', 'ifs-deploy' ) ),
				);
			}

			$this_pass = (int) ( $body['restored'] ?? 0 );
			$reverted += $this_pass;

			/*
			 * An empty pass is EVIDENCE, not a conclusion — see CLEAN_PASSES.
			 *
			 * `nothing_applied` counts as empty rather than as proof: it means Production
			 * has never heard of this deployment, which is the best outcome when the cancel
			 * beat the first batch, and the worst possible reading when that batch is
			 * simply still on its way.
			 */
			$clean = ( 0 === $this_pass ) ? $clean + 1 : 0;

			if ( $clean >= self::CLEAN_PASSES ) {
				return array( 'ok' => true, 'reverted' => $reverted, 'sweeps' => $pass, 'message' => '' );
			}

			DebugLog::info(
				$this_pass > 0
					? 'Cancel reverted objects on Production; checking again in case a batch landed behind it'
					: 'Cancel found nothing to revert; confirming once more in case a batch is still arriving',
				array( 'uuid' => $uuid, 'pass' => $pass, 'reverted' => $this_pass )
			);

			// Give Production time to finish whatever it is still processing, or the next
			// pass reads the same unfinished state and learns nothing.
			if ( $pass < self::MAX_SWEEPS ) {
				sleep( self::PAUSE_SECONDS );
			}
		}

		/*
		 * Still finding work after the last allowed pass. Reported as a FAILURE rather
		 * than a success with a caveat: the one thing a cancel must never do is claim
		 * Production is clean when it has not been shown to be.
		 */
		DebugLog::error(
			'Cancel could not confirm Production was clean within the allowed passes',
			array( 'uuid' => $uuid, 'passes' => self::MAX_SWEEPS, 'reverted' => $reverted )
		);

		return array(
			'ok'       => false,
			'reverted' => $reverted,
			'sweeps'   => self::MAX_SWEEPS,
			'message'  => __( 'The push was stopped and your changes are still listed here, but Production kept receiving parts of it while it was being undone. Check Deployment History on Production and roll that deployment back.', 'ifs-deploy' ),
		);
	}

	/**
	 * Say what actually happened, in the two cases that read very differently.
	 */
	private function describe( int $reverted, int $restored ): string {
		if ( 0 === $reverted ) {
			return __( 'Push cancelled. Nothing had reached Production, so nothing needed undoing — your changes are still listed here.', 'ifs-deploy' );
		}

		return sprintf(
			/* translators: 1: objects undone on Production, 2: rows returned to the list */
			_n(
				'Push cancelled. %1$d change had already been sent and was undone on Production, and %2$d item is still listed here.',
				'Push cancelled. %1$d changes had already been sent and were undone on Production, and %2$d items are still listed here.',
				$reverted,
				'ifs-deploy'
			),
			$reverted,
			$restored
		);
	}
}
