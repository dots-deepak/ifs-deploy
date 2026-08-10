<?php
declare(strict_types=1);

namespace IfsDeploy\Detection;

use IfsDeploy\Export\TermExporter;
use IfsDeploy\Queue\Hasher;
use IfsDeploy\Queue\QueueRepository;
use IfsDeploy\Support\Config;

/**
 * Observes taxonomy term changes (create/edit/delete) and feeds the queue.
 */
final class TermObserver {

	private QueueRepository $queue;
	private TermExporter $exporter;

	public function __construct( ?QueueRepository $queue = null, ?TermExporter $exporter = null ) {
		$this->queue    = $queue ?? new QueueRepository();
		$this->exporter = $exporter ?? new TermExporter();
	}

	public function on_change( int $term_id, int $tt_id, string $taxonomy ): void {
		if ( ! $this->is_trackable( $taxonomy ) ) {
			return;
		}

		$package = $this->exporter->export( $term_id, $taxonomy );
		if ( null === $package ) {
			return;
		}

		$term = get_term( $term_id, $taxonomy );
		$name = ( $term instanceof \WP_Term ) ? $term->name : '';

		$this->queue->upsert( 'term', $taxonomy, $term_id, $name, 'update', Hasher::hash( $package ) );
	}

	/**
	 * @param \WP_Term|int $deleted_term The term object before deletion.
	 */
	public function on_delete( int $term_id, int $tt_id, string $taxonomy, $deleted_term = null ): void {
		if ( ! $this->is_trackable( $taxonomy ) ) {
			return;
		}

		$name = ( $deleted_term instanceof \WP_Term ) ? $deleted_term->name : '';

		$this->queue->upsert( 'term', $taxonomy, $term_id, $name, 'delete', md5( 'delete:term:' . $term_id ) );
	}

	private function is_trackable( string $taxonomy ): bool {
		return in_array( $taxonomy, Config::tracked_taxonomies(), true );
	}
}
