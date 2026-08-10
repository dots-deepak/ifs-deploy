<?php
declare(strict_types=1);

namespace IfsDeploy\Detection;

use IfsDeploy\Export\PostExporter;
use IfsDeploy\Queue\Hasher;
use IfsDeploy\Queue\QueueRepository;
use IfsDeploy\Support\Config;

/**
 * Observes post/page changes and feeds the smart queue.
 *
 * Multiple edits to the same object collapse into a single pending row thanks
 * to the hash-based upsert in QueueRepository.
 */
final class PostObserver {

	private QueueRepository $queue;
	private PostExporter $exporter;

	public function __construct( ?QueueRepository $queue = null, ?PostExporter $exporter = null ) {
		$this->queue    = $queue ?? new QueueRepository();
		$this->exporter = $exporter ?? new PostExporter();
	}

	/**
	 * Handle a create/update (save_post or acf/save_post).
	 *
	 * @param int|string $post_id Post ID (acf/save_post may pass non-numeric ids
	 *                            for options pages — ignored here).
	 */
	public function on_save( $post_id ): void {
		$post_id = (int) $post_id;
		if ( ! $this->is_trackable( $post_id ) ) {
			return;
		}

		$package = $this->exporter->export( $post_id );
		if ( null === $package ) {
			return;
		}

		$post = get_post( $post_id );

		$this->queue->upsert(
			'post',
			(string) $post->post_type,
			$post_id,
			(string) $post->post_title,
			'update',
			Hasher::hash( $package )
		);
	}

	/**
	 * Handle trash/delete. We queue a delete action so it can be propagated.
	 */
	public function on_delete( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		if ( ! in_array( $post->post_type, Config::tracked_post_types(), true ) ) {
			return;
		}

		$this->queue->upsert(
			'post',
			(string) $post->post_type,
			$post_id,
			(string) $post->post_title,
			'delete',
			// Hash the action so a later re-create supersedes the delete row.
			md5( 'delete:' . $post_id )
		);
	}

	private function is_trackable( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return false;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}
		if ( 'auto-draft' === $post->post_status || 'inherit' === $post->post_status ) {
			return false;
		}

		return in_array( $post->post_type, Config::tracked_post_types(), true );
	}
}
