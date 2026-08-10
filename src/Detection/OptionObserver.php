<?php
declare(strict_types=1);

namespace IfsDeploy\Detection;

use IfsDeploy\Export\OptionExporter;
use IfsDeploy\Queue\Hasher;
use IfsDeploy\Queue\QueueRepository;
use IfsDeploy\Support\OptionAllowlist;

/**
 * Observes changes to allowlisted options (theme/ACF options, widgets, safe
 * plugin settings) and feeds the queue. Default-deny keeps the queue quiet and
 * safe — only known-safe options are ever tracked.
 */
final class OptionObserver {

	private QueueRepository $queue;
	private OptionExporter $exporter;

	public function __construct( ?QueueRepository $queue = null, ?OptionExporter $exporter = null ) {
		$this->queue    = $queue ?? new QueueRepository();
		$this->exporter = $exporter ?? new OptionExporter();
	}

	/** updated_option / added_option handler. */
	public function on_update( string $option ): void {
		if ( ! OptionAllowlist::is_allowed( $option ) ) {
			return;
		}

		$package = $this->exporter->export( $option );
		if ( null === $package ) {
			return;
		}

		$this->queue->upsert(
			'option',
			'',
			OptionExporter::option_id( $option ),
			$option,
			'update',
			Hasher::hash( $package )
		);
	}

	public function on_delete( string $option ): void {
		if ( ! OptionAllowlist::is_allowed( $option ) ) {
			return;
		}

		$this->queue->upsert(
			'option',
			'',
			OptionExporter::option_id( $option ),
			$option,
			'delete',
			md5( 'delete:option:' . $option )
		);
	}
}
