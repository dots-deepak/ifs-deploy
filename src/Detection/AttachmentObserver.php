<?php
declare(strict_types=1);

namespace IfsDeploy\Detection;

use IfsDeploy\Export\MediaExporter;
use IfsDeploy\Queue\Hasher;
use IfsDeploy\Queue\QueueRepository;

/**
 * Observes media library changes (upload / edit / delete) and feeds the queue.
 */
final class AttachmentObserver {

	private QueueRepository $queue;
	private MediaExporter $exporter;

	public function __construct( ?QueueRepository $queue = null, ?MediaExporter $exporter = null ) {
		$this->queue    = $queue ?? new QueueRepository();
		$this->exporter = $exporter ?? new MediaExporter();
	}

	public function on_change( int $attachment_id ): void {
		$package = $this->exporter->export( $attachment_id );
		if ( null === $package ) {
			return;
		}

		$post  = get_post( $attachment_id );
		$title = ( $post instanceof \WP_Post ) ? $post->post_title : '';

		$this->queue->upsert( 'media', (string) $package['subtype'], $attachment_id, $title, 'update', Hasher::hash( $package ) );
	}

	public function on_delete( int $attachment_id ): void {
		$post = get_post( $attachment_id );
		if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type ) {
			return;
		}

		$this->queue->upsert( 'media', (string) $post->post_mime_type, $attachment_id, $post->post_title, 'delete', md5( 'delete:media:' . $attachment_id ) );
	}
}
