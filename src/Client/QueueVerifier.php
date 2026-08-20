<?php
declare(strict_types=1);

namespace IfsDeploy\Client;

use IfsDeploy\Export\PostExporter;
use IfsDeploy\Queue\QueueRepository;
use IfsDeploy\Support\Config;
use IfsDeploy\Support\DebugLog;
use IfsDeploy\Support\SyncCheck;

/**
 * Drops pending rows that no longer differ from Production.
 *
 * The queue records that an object was *touched*, which is not the same as it being
 * *different*. Edit a page and undo the edit and the row stays, telling you to push
 * something that would change nothing — the complaint this class exists to answer.
 *
 * `deployed_hash` catches this locally and for free, but only for objects deployed
 * since that column existed. Production is the authority for everything else, so this
 * asks it directly: one signed call to /signatures covering just the queued objects.
 *
 * Everything here is best-effort. If Production is unreachable or slow, verification
 * is skipped and the list simply renders as before — it must never stop the screen from
 * loading.
 */
final class QueueVerifier {

	/**
	 * Rows verified per run, so a large backlog cannot make a page load crawl.
	 */
	private const MAX_ROWS = 50;

	private QueueRepository $queue;
	private PostExporter $exporter;
	private DeployClient $client;

	public function __construct(
		?QueueRepository $queue = null,
		?PostExporter $exporter = null,
		?DeployClient $client = null
	) {
		$this->queue    = $queue ?? new QueueRepository();
		$this->exporter = $exporter ?? new PostExporter();
		$this->client   = $client ?? new DeployClient();
	}

	/**
	 * Verify the pending post rows and resolve the ones already in sync.
	 *
	 * @param object[] $rows Pending queue rows (any type; non-posts are ignored).
	 *
	 * @return int How many rows were cleared.
	 */
	public function verify( array $rows ): int {
		if ( ! $this->can_run() ) {
			return 0;
		}

		$probes = array();
		$local  = array();

		foreach ( $rows as $row ) {
			if ( ! self::is_verifiable_row( $row ) ) {
				continue;
			}

			/*
			 * JUST PUT BACK BY A CANCEL — leave it alone.
			 *
			 * A cancel restores the rows and the page reloads straight into this check. If
			 * the revert on Production has not finished — it may still be applying a batch
			 * this side stopped waiting for — the content matches for a moment, and
			 * resolving the row here removes the very change the cancel just rescued.
			 *
			 * The operator cannot tell that apart from the bug they reported: cancel kiya,
			 * phir bhi Pending Changes se rows chali gayi. A cancel means "not yet", never
			 * "discard this", so these rows are exempt for a short grace period.
			 */
			if ( QueueRepository::is_protected( (int) $row->id ) ) {
				continue;
			}

			$package = $this->exporter->export( (int) $row->object_id );
			if ( null === $package ) {
				continue;
			}

			$signature = (string) ( $package['signature'] ?? '' );
			if ( '' === $signature ) {
				continue;
			}

			$origin_id = (int) ( $package['origin_id'] ?? 0 );
			$fields    = (array) ( $package['object'] ?? array() );

			$probes[] = array(
				'origin_id'   => $origin_id,
				'origin_site' => (string) ( $package['origin_site'] ?? '' ),
				'post_type'   => (string) ( $fields['post_type'] ?? 'post' ),
				'slug'        => (string) ( $fields['post_name'] ?? '' ),
			);

			$local[ (string) $origin_id ] = array(
				'queue_id'  => (int) $row->id,
				'signature' => $signature,
			);

			if ( count( $probes ) >= self::MAX_ROWS ) {
				break;
			}
		}

		if ( empty( $probes ) ) {
			return 0;
		}

		$response = $this->client->post( 'signatures', array( 'objects' => $probes ) );

		if ( is_wp_error( $response ) || 200 !== $response['status'] || empty( $response['body']['ok'] ) ) {
			// Silent by design: an unreachable Production must not break the screen.
			return 0;
		}

		$signatures = (array) ( $response['body']['signatures'] ?? array() );
		$cleared    = 0;

		foreach ( $local as $origin_id => $entry ) {
			$remote = (array) ( $signatures[ (string) $origin_id ] ?? array() );

			if ( ! self::should_clear( $entry['signature'], $remote ) ) {
				continue;
			}

			// Also records deployed_hash, so a later edit-and-undo is caught locally
			// without another round trip.
			$this->queue->mark_deployed( $entry['queue_id'] );
			++$cleared;
		}

		if ( $cleared > 0 ) {
			DebugLog::info(
				'Removed pending changes that no longer differ from Production',
				array( 'cleared' => $cleared, 'checked' => count( $probes ) )
			);
		}

		return $cleared;
	}

	/**
	 * Only meaningful from a configured Staging site.
	 */
	private function can_run(): bool {
		if ( ! Config::is_staging() ) {
			return false;
		}

		$remote = Config::remote();

		return '' !== $remote['url'] && '' !== $remote['api_key'] && '' !== $remote['secret_key'];
	}

	/**
	 * Can this row be checked against a content signature at all?
	 *
	 * Signatures only describe posts, and only an update can be "already in sync" — a
	 * queued delete still has work to do even when the content matches.
	 *
	 * Public and static so the rule can be exercised directly; it depends on nothing.
	 *
	 * @param object $row Queue row.
	 */
	public static function is_verifiable_row( object $row ): bool {
		return 'post' === (string) ( $row->object_type ?? '' )
			&& 'delete' !== (string) ( $row->action ?? '' )
			&& (int) ( $row->object_id ?? 0 ) > 0;
	}

	/**
	 * Should a row be dropped from Pending Changes, given Production's answer?
	 *
	 * Only when Production actually HAS the object and its content matches. "Missing on
	 * Production" is a real pending change, and so is any inconclusive answer — the rule
	 * fails closed, because wrongly clearing a row silently loses someone's work from
	 * the queue.
	 *
	 * @param string $local_signature Staging's signature for the object now.
	 * @param array  $remote          One entry from the /signatures response.
	 */
	public static function should_clear( string $local_signature, array $remote ): bool {
		if ( empty( $remote['found'] ) ) {
			return false;
		}

		return SyncCheck::in_sync(
			$local_signature,
			(string) ( $remote['deployed_sig'] ?? '' ),
			(string) ( $remote['signature'] ?? '' )
		);
	}
}
