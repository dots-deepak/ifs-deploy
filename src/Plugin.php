<?php
declare(strict_types=1);

namespace IfsDeploy;

use IfsDeploy\Admin\AdminMenu;
use IfsDeploy\Admin\RestrictionNotice;
use IfsDeploy\Admin\Ajax;
use IfsDeploy\Detection\ChangeTracker;
use IfsDeploy\Rest\RestController;
use IfsDeploy\Support\Access;
use IfsDeploy\Support\Config;
use IfsDeploy\Support\LegacyRename;
use IfsDeploy\Support\LogRetention;
use IfsDeploy\Support\Schema;

/**
 * Singleton bootstrap. Wires services on every request and decides what runs
 * based on the site's role.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	private bool $booted = false;

	private function __construct() {}

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		/*
		 * BEFORE everything else, including the schema check.
		 *
		 * This plugin's identifiers were renamed to `ifs_deploy_` from an earlier prefix, which
		 * moved where its data lives. `Schema::maybe_upgrade()` below reads
		 * `ifs_deploy_db_version`; on an install that predates the rename it finds nothing,
		 * concludes the plugin is new, and installs a fresh schema — creating empty tables
		 * beside the real ones, at which point a rename is no longer possible.
		 *
		 * One autoloaded option read once the migration has been considered, so the cost on
		 * every later request is nil.
		 */
		LegacyRename::run();

		// Run any pending schema migration (covers manual file-copy updates that
		// skip the activation hook).
		Schema::maybe_upgrade();

		load_plugin_textdomain( 'ifs-deploy', false, dirname( plugin_basename( IFS_DEPLOY_FILE ) ) . '/languages' );

		// Virtual capabilities must be in place before admin_menu runs, and before
		// any current_user_can() check anywhere.
		Access::register();

		// REST endpoints are always registered. The import/rollback handlers
		// themselves verify role + signature before doing anything.
		( new RestController() )->register();

		// The retention purge runs from WP-Cron, which is not an admin request, so this
		// listener has to be registered outside the is_admin() block below.
		LogRetention::register();

		// A site that was updated rather than freshly activated never ran the activation
		// hook, so the cron event may not exist yet. Cheap: one option read.
		LogRetention::schedule();

		// Staging-only: observe content changes and feed the queue.
		if ( Config::is_staging() ) {
			( new ChangeTracker() )->register();
		}

		// Admin surface (both roles, but the screens adapt to the role).
		if ( is_admin() ) {
			( new AdminMenu() )->register();
			( new Ajax() )->register();

			// Says why the restricted screens are missing, so their absence cannot be
			// mistaken for the plugin being broken. Renders nothing for anyone unaffected.
			( new RestrictionNotice() )->register();
		}
	}
}
