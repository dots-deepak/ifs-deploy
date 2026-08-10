<?php
declare(strict_types=1);

namespace IfsDeploy\Support;

use IfsDeploy\Auth\Credentials;

/**
 * Activation / deactivation lifecycle.
 */
final class Activator {

	public static function activate(): void {
		// First, for the same reason Plugin::boot() calls it first: Schema::install() below
		// would create empty tables next to the pre-rename ones, and the rename would then be
		// impossible. Activation is the likelier path here — a rename changes the plugin's
		// folder, so WordPress deactivates it and the owner switches it back on.
		LegacyRename::run();

		Schema::install();
		update_option( 'ifs_deploy_db_version', IFS_DEPLOY_DB_VERSION );

		// Ensure this site has a stable identity + secret available the moment it
		// is asked to act as a Production receiver. Safe no-op if already set.
		Credentials::ensure_exists();

		// Default role is "staging" (source). The user flips Production on in
		// Settings, which is where credentials are surfaced.
		if ( false === get_option( 'ifs_deploy_role' ) ) {
			update_option( 'ifs_deploy_role', Config::ROLE_STAGING );
		}

		/*
		 * A FRESH install gets the secure default; an existing one does not.
		 *
		 * `ContentFirewall::mode()` falls back to `report` when the option is absent, which
		 * is what every upgrading site sees: nothing about its next deploy changes, and the
		 * log starts saying what filtering would do. Writing `filter` here — only when the
		 * option has never been set — means a brand-new pair is protected from its first
		 * deploy without anyone having to find the setting.
		 */
		if ( false === get_option( ContentFirewall::OPTION, false ) ) {
			ContentFirewall::set_mode( ContentFirewall::MODE_FILTER );
		}

		// Daily purge of log data older than the configured retention period.
		LogRetention::schedule();

		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		// Leave the retention SETTING in place — deactivating is not uninstalling — but
		// stop the cron event, or WordPress keeps firing a hook nothing listens to.
		LogRetention::unschedule();

		flush_rewrite_rules();
	}
}
