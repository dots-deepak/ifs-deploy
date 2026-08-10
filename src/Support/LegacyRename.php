<?php
declare(strict_types=1);

namespace IfsDeploy\Support;

/**
 * One-time migration of stored data from the plugin's previous identifiers to `ifs_deploy_`.
 *
 * ── WHY THIS HAS TO EXIST ──────────────────────────────────────────────────────
 *
 * The plugin was renamed in place. Renaming the identifiers renamed where the DATA lives, so
 * without this an existing install boots as if it had never been configured:
 *
 *   - `ifs_deploy_credentials` is absent → `Credentials::get()` regenerates → the shared
 *     secret changes → the paired site is refused on every request.
 *   - `ifs_deploy_role` is absent → the site defaults to Staging. A PRODUCTION site that
 *     flips to Staging answers 409 to every import.
 *   - `{prefix}ifs_deploy_revisions` is absent → `Schema::install()` creates a new empty one
 *     → every rollback restore point is still on disk but unreachable.
 *   - `{prefix}ifs_deploy_queue` is absent → the pending queue looks empty.
 *   - And the one that quietly corrupts content: every page loses its
 *     `_ifs_deploy_origin_id`, which is how Production recognises a page as the copy of a
 *     Staging page. Without it the next deploy CREATES A DUPLICATE of every object instead of
 *     updating it. On a 2000-page site that is 2000 duplicates.
 *
 * ── ORDER, AND WHY ─────────────────────────────────────────────────────────────
 *
 * `run()` must fire BEFORE `Schema::maybe_upgrade()`. That reads `ifs_deploy_db_version`,
 * finds nothing on a legacy install, and installs a fresh schema — creating the empty tables
 * described above *next to* the real ones, at which point the rename can no longer be a
 * rename. `Plugin::boot()` and `Activator::activate()` both call this first.
 *
 * ── SAFETY PROPERTIES ──────────────────────────────────────────────────────────
 *
 * - **Idempotent.** A marker option is written at the end; the fast path afterwards is one
 *   autoloaded option read. Re-running mid-way is safe: each step checks for the target
 *   before touching the source.
 * - **Never destructive on a collision.** If both names exist — a half-finished run, or
 *   someone activating an old copy alongside — the NEW one wins and the old row is dropped.
 *   Renaming over a live key would trip the UNIQUE index on `option_name` and abort.
 * - **Fresh installs cost nothing.** No `Legacy::DB_VERSION_OPTION` means nothing to do, and the
 *   marker is written so this never looks again.
 * - **Caches are flushed.** Options and meta are cached in memory and, on many hosts, in a
 *   persistent object cache. Renaming rows underneath a warm cache means the site keeps
 *   reading the old values — which looks exactly like the migration not having run.
 *
 * This runs on BOTH sites. Nothing here touches the wire, so the two are independent — but the
 * REST namespace and the `X-IFS-Deploy-*` headers DID change, so the pair still has to be
 * updated in the same window. See the NAMING note in `ifs-deploy.php`.
 */
final class LegacyRename {

	/** Written once the migration has been considered. Autoloaded, so the check is free. */
	private const DONE = 'ifs_deploy_legacy_migrated';

	/**
	 * The old spellings all come from Support\Legacy, so this file names none of them
	 * itself — one place documents why they cannot change, and this one just uses them.
	 */
	private const LEGACY_MARKER = Legacy::DB_VERSION_OPTION;
	private const OLD_PREFIX    = Legacy::OPTION_PREFIX;
	private const NEW_PREFIX    = 'ifs_deploy_';

	/** Table suffixes, without the `$wpdb->prefix`. */
	private const TABLES = array( 'queue', 'deployments', 'revisions', 'api_log', 'nonces' );

	/**
	 * Every option this plugin owns.
	 *
	 * Listed rather than pattern-matched: a `LIKE` sweep over the old prefix would also catch a
	 * *different* plugin's option that happens to share the prefix, and renaming someone
	 * else's data is not a migration, it is a bug.
	 */
	private const OPTIONS = array(
		'db_version',
		'role',
		'credentials',
		'remote',
		'debug_log',
		'roles',
		'users',
		'log_retention_days',
		'trusted_ip_header',
		'anonymise_ips',
		'ip_allow',
		'ip_block',
		'content_firewall',
		'peer_protocol',
		'log_duplicates',
	);

	/**
	 * Migrate, if there is anything to migrate.
	 */
	public static function run(): void {
		if ( '' !== (string) get_option( self::DONE, '' ) ) {
			return;
		}

		// Nothing from the old naming? Then this is a fresh install. Mark it and stop looking.
		if ( false === get_option( self::LEGACY_MARKER, false ) ) {
			update_option( self::DONE, 'not-needed' );

			return;
		}

		$moved = array(
			'tables'    => self::rename_tables(),
			'options'   => self::rename_options(),
			'post_meta' => self::rename_meta( 'postmeta' ),
			'term_meta' => self::rename_meta( 'termmeta' ),
			// Renaming the option is not enough for two of them — the old names are also
			// INSIDE the stored value. See rename_capabilities().
			'caps'      => self::rename_capabilities(),
		);

		self::reschedule_cron();

		/*
		 * Flush before the marker is written, so a failure here cannot leave the site running
		 * on cached old values while believing it has migrated.
		 *
		 * A full flush rather than targeted deletes: post meta is cached per object id, and
		 * there is no portable way to invalidate "every post's meta". One flush on a one-time
		 * migration is a brief cache-warming cost; stale meta would be silent wrong content.
		 */
		wp_cache_flush();

		update_option( self::DONE, gmdate( 'Y-m-d H:i:s' ) . ' UTC' );

		/*
		 * Logged AFTER the marker, deliberately — `DebugLog` writes to an option, and doing
		 * that mid-migration would create a row under the new name while the old one is still
		 * being read. Recorded because a silent migration of credentials and content identity
		 * is not something a site owner should have to infer.
		 */
		DebugLog::warning(
			'Upgraded from the previous plugin naming. Settings, deployment history, rollback points and content identity were migrated to the new keys.',
			array(
				'tables'    => (string) $moved['tables'],
				'options'   => (string) $moved['options'],
				'post_meta' => (string) $moved['post_meta'],
				'term_meta' => (string) $moved['term_meta'],
				'caps'      => (string) $moved['caps'],
				'important' => 'The paired site must be updated to this version too — the REST path and request headers changed, so deploys will fail until both sides match.',
			)
		);
	}

	/**
	 * `RENAME TABLE`, skipping anything already moved.
	 *
	 * @return int Tables renamed.
	 */
	private static function rename_tables(): int {
		global $wpdb;

		$done = 0;

		foreach ( self::TABLES as $suffix ) {
			$old = $wpdb->prefix . self::OLD_PREFIX . $suffix;
			$new = $wpdb->prefix . self::NEW_PREFIX . $suffix;

			// Both present means a previous run got this far. The new one is authoritative;
			// the old is a leftover and is left alone rather than dropped, because dropping a
			// table nobody asked us to drop is not recoverable.
			if ( self::table_exists( $new ) || ! self::table_exists( $old ) ) {
				continue;
			}

			// Identifiers cannot be parameterised. Both are built from `$wpdb->prefix` plus a
			// hard-coded suffix from the constant above — no request data reaches this.
			$wpdb->query( "RENAME TABLE `{$old}` TO `{$new}`" ); // phpcs:ignore WordPress.DB

			++$done;
		}

		return $done;
	}

	private static function table_exists( string $table ): bool {
		global $wpdb;

		return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * Move each option row to its new name.
	 *
	 * Done key by key rather than with one `REPLACE()` sweep, because `option_name` is UNIQUE:
	 * a single UPDATE would abort the whole statement the moment one target already existed,
	 * leaving the rest unmigrated with no indication of how far it got.
	 *
	 * @return int Options moved.
	 */
	private static function rename_options(): int {
		global $wpdb;

		$done = 0;

		foreach ( self::OPTIONS as $suffix ) {
			$old = self::OLD_PREFIX . $suffix;
			$new = self::NEW_PREFIX . $suffix;

			$old_row = $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $old ) ); // phpcs:ignore WordPress.DB

			if ( null === $old_row ) {
				continue;
			}

			$new_row = $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $new ) ); // phpcs:ignore WordPress.DB

			if ( null !== $new_row ) {
				// The new value wins. Deleting the stale row directly, because delete_option()
				// would read through the cache we are about to invalidate anyway.
				$wpdb->delete( $wpdb->options, array( 'option_name' => $old ), array( '%s' ) ); // phpcs:ignore WordPress.DB

				continue;
			}

			$wpdb->update( $wpdb->options, array( 'option_name' => $new ), array( 'option_name' => $old ), array( '%s' ), array( '%s' ) ); // phpcs:ignore WordPress.DB

			++$done;
		}

		return $done;
	}

	/**
	 * Rewrite `Legacy::META_PREFIX` meta keys to `_ifs_deploy_*`.
	 *
	 * One statement, because meta keys carry no UNIQUE constraint so there is nothing to
	 * collide with — and because this is the step that can touch tens of thousands of rows on
	 * a large site. `_ifs_deploy_origin_id` in particular is on every object ever deployed.
	 *
	 * @param string $which `postmeta` or `termmeta`.
	 * @return int Rows rewritten.
	 */
	private static function rename_meta( string $which ): int {
		global $wpdb;

		$table = 'termmeta' === $which ? $wpdb->termmeta : $wpdb->postmeta;
		$old   = Legacy::META_PREFIX;
		$new   = '_' . self::NEW_PREFIX;

		// esc_like() then a manual `%`: the leading underscore in the old prefix is a LIKE
		// wildcard, so an unescaped pattern would also match another plugin's similar key.
		return (int) $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"UPDATE {$table} SET meta_key = REPLACE( meta_key, %s, %s ) WHERE meta_key LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL
				$old,
				$new,
				$wpdb->esc_like( $old ) . '%'
			)
		);
	}

	/**
	 * Rewrite the capability names stored INSIDE two option values.
	 *
	 * ── THE ONE A TABLE RENAME DOES NOT FIX ────────────────────────────────────
	 *
	 * `Support\Access` capabilities are named after the plugin, so each one was renamed with it.
	 * They are not WordPress roles — they are virtual, granted through the
	 * `user_has_cap` filter — so nothing in `wp_user_roles` needs touching.
	 *
	 * But they are stored as ARRAY KEYS inside two option values:
	 *
	 *     ifs_deploy_roles  → [ 'editor' => [ <old>deploy => true, … ] ]
	 *     ifs_deploy_users  → [ 'allow_caps' => [ <old>access => true, … ] ]
	 *
	 * `Access::permissions()` then reads `! empty( $caps['ifs_deploy_deploy'] )` against a
	 * value whose keys still carry the old spelling, and gets false for every one. The effect
	 * is silent and specific: **every delegated role loses its plugin access**, while
	 * administrators keep theirs because that is hard-coded — so the owner sees nothing wrong
	 * and the team cannot open the plugin.
	 *
	 * Rewritten with `update_option()` rather than SQL: these are serialised arrays, and
	 * REPLACE() on a serialised string is how you corrupt one (`s:18:` stops matching its
	 * shortened payload).
	 *
	 * @return int Option values rewritten.
	 */
	private static function rename_capabilities(): int {
		$done = 0;

		// Role matrix: role => [ cap => bool ].
		$roles = get_option( self::NEW_PREFIX . 'roles', array() );

		if ( is_array( $roles ) && ! empty( $roles ) ) {
			$rewritten = array();

			foreach ( $roles as $role => $caps ) {
				$rewritten[ $role ] = is_array( $caps ) ? self::rekey( $caps ) : $caps;
			}

			if ( $rewritten !== $roles ) {
				update_option( self::NEW_PREFIX . 'roles', $rewritten, false );
				++$done;
			}
		}

		// Per-user overrides: allow[] / block[] are ids and untouched; allow_caps[] is keyed.
		$users = get_option( self::NEW_PREFIX . 'users', array() );

		if ( is_array( $users ) && isset( $users['allow_caps'] ) && is_array( $users['allow_caps'] ) ) {
			$rewritten               = $users;
			$rewritten['allow_caps'] = self::rekey( $users['allow_caps'] );

			if ( $rewritten !== $users ) {
				update_option( self::NEW_PREFIX . 'users', $rewritten, false );
				++$done;
			}
		}

		return $done;
	}

	/**
	 * Move `Legacy::OPTION_PREFIX` array keys to `ifs_deploy_*`, keeping their values.
	 *
	 * A key already using the new name wins, so this is safe to run over a partly-migrated
	 * value — the same rule the option and table steps use.
	 *
	 * @param array<string,mixed> $caps
	 * @return array<string,mixed>
	 */
	private static function rekey( array $caps ): array {
		$out = array();

		foreach ( $caps as $key => $value ) {
			$key = (string) $key;

			if ( 0 === strpos( $key, self::OLD_PREFIX ) ) {
				$new = self::NEW_PREFIX . substr( $key, strlen( self::OLD_PREFIX ) );

				// Only if the new key is not already present with its own value.
				if ( ! array_key_exists( $new, $caps ) ) {
					$out[ $new ] = $value;
				}

				continue;
			}

			$out[ $key ] = $value;
		}

		return $out;
	}

	/**
	 * Drop the old daily purge event; `LogRetention::schedule()` creates the new one.
	 *
	 * Left behind, WP-Cron keeps firing a hook nothing listens to — the same reason
	 * `uninstall.php` clears it.
	 */
	private static function reschedule_cron(): void {
		wp_clear_scheduled_hook( Legacy::CRON_HOOK );

		LogRetention::schedule();
	}
}
