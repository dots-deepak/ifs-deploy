<?php
declare(strict_types=1);

namespace IfsDeploy\Detection;

use IfsDeploy\Export\MenuExporter;
use IfsDeploy\Queue\Hasher;
use IfsDeploy\Queue\QueueRepository;

/**
 * Observes navigation menu changes. wp_update_nav_menu fires when a menu is
 * created, and whenever its items are added, edited, reordered, or removed —
 * so a single hook covers "new menu" and "changed menu".
 */
final class MenuObserver {

	private QueueRepository $queue;
	private MenuExporter $exporter;

	public function __construct( ?QueueRepository $queue = null, ?MenuExporter $exporter = null ) {
		$this->queue    = $queue ?? new QueueRepository();
		$this->exporter = $exporter ?? new MenuExporter();
	}

	public function on_change( int $menu_id ): void {
		$package = $this->exporter->export( $menu_id );
		if ( null === $package ) {
			return;
		}

		$menu = wp_get_nav_menu_object( $menu_id );
		$name = $menu ? $menu->name : '';

		$this->queue->upsert( 'menu', '', $menu_id, $name, 'update', Hasher::hash( $package ) );
	}
}
