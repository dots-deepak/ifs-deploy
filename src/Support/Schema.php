<?php
declare(strict_types=1);

namespace IfsDeploy\Support;

use IfsDeploy\Export\OptionExporter;

/**
 * Owns the database schema and version-gated migrations.
 *
 * All three Phase 1 tables are created up front so later phases never need a
 * destructive migration.
 *
 * Version history:
 *   1 — initial three tables.
 *   2 — queue gains `user_id`, recording who made each pending change so the
 *       screens can scope to "own changes" for non-administrators. dbDelta adds the
 *       column in place; existing rows keep 0, shown as an unknown user.
 *   3 — queue gains `deployed_hash`: the hash of what was last successfully sent to
 *       Production. Without it, editing a page and then undoing the edit left the row
 *       sitting in Pending Changes, because upsert() could only compare against the
 *       previous PENDING hash and had no idea what Production already holds.
 *   4 — the `api_log` table.
 *   5 — the `nonces` table (replay protection).
 *   6 — no schema change. `OptionExporter::option_id()` stopped deriving an option's
 *       queue id from crc32, so the rows filed under the old value are re-keyed —
 *       see migrate().
 *   7 — queue gains `baseline_hash`: the state an object held BEFORE its pending change
 *       began. Editing something and then undoing the edit used to leave the row queued
 *       for ever, because the row only remembered the state it was changed TO.
 *       Also collapses duplicate rows for one object, now that identity is
 *       (object_type, object_id) rather than including the subtype.
 */
final class Schema {

	/**
	 * Run dbDelta if the stored schema version is behind the code version.
	 */
	public static function maybe_upgrade(): void {
		$installed = (string) get_option( 'ifs_deploy_db_version', '0' );

		if ( $installed === IFS_DEPLOY_DB_VERSION ) {
			return;
		}

		self::install();
		self::migrate( $installed );
		update_option( 'ifs_deploy_db_version', IFS_DEPLOY_DB_VERSION );
	}

	/**
	 * Data fixes that dbDelta cannot do, run after the columns exist.
	 *
	 * @param string $from The schema version being upgraded from ('0' when unknown).
	 */
	private static function migrate( string $from ): void {
		global $wpdb;

		if ( version_compare( $from, '3', '<' ) ) {
			// Rows already deployed predate deployed_hash, so seed it from the hash
			// they were deployed with. Otherwise "undo an edit and it leaves the queue"
			// would only start working after each object's NEXT deploy.
			$table = self::queue_table();
			$wpdb->query( // phpcs:ignore WordPress.DB
				"UPDATE {$table} SET deployed_hash = object_hash WHERE deployed_hash = '' AND status = 'deployed'"
			);
		}

		if ( version_compare( $from, '6', '<' ) && '0' !== $from ) {
			self::rekey_option_rows();
		}

		if ( version_compare( $from, '7', '<' ) && '0' !== $from ) {
			self::collapse_duplicate_rows();
		}
	}

	/**
	 * Leave one queue row per object, now that the subtype no longer identifies it.
	 *
	 * While identity included the subtype, one object could hold several rows — a mime
	 * type or post type that changed produced a row the earlier ones never deduped
	 * against, and Pending Changes listed the same object several times. `find()` now
	 * ignores the subtype, so new writes converge on their own; this clears what the old
	 * rule already wrote.
	 *
	 * The HIGHEST id wins. Rows are updated in place, so duplicates only ever arise from
	 * a later insert, which therefore holds the most recent state.
	 *
	 * A plain DELETE with a self-join: this runs once, and the row count is the number
	 * of pending changes on a site, not a table scan worth worrying about.
	 */
	private static function collapse_duplicate_rows(): void {
		global $wpdb;

		$table = self::queue_table();

		$wpdb->query( // phpcs:ignore WordPress.DB
			"DELETE older FROM {$table} AS older
			 INNER JOIN {$table} AS newer
			     ON older.object_type = newer.object_type
			    AND older.object_id   = newer.object_id
			    AND older.id          < newer.id"
		);
	}

	/**
	 * Re-file existing option rows under the new `OptionExporter::option_id()`.
	 *
	 * The id is derived from the option NAME, so changing how it is derived moves every
	 * option's row. Left alone, each one becomes an orphan the observer can never find
	 * again: the next change to that option inserts a SECOND row beside it, so Pending
	 * Changes lists the same option twice and the stale copy can never be resolved.
	 *
	 * Row by row in PHP rather than one `UPDATE ... SET object_id = ...`:
	 *
	 *  - the queue's UNIQUE key covers (object_type, object_subtype, object_id), and a
	 *    bulk update walks rows in an arbitrary order, so a row moving onto an id that a
	 *    not-yet-migrated row still occupies would abort the whole statement partway
	 *    through and leave the table half-converted;
	 *  - `$wpdb->update()` failing for one row leaves the others done, which is the
	 *    right failure mode here;
	 *  - and the id has exactly one definition, in `OptionExporter`. Repeating the md5
	 *    as SQL would be a second copy that has to agree with the PHP for ever.
	 *
	 * Skipped on a fresh install ('0'), where there is nothing to re-key.
	 */
	private static function rekey_option_rows(): void {
		global $wpdb;

		$table = self::queue_table();

		$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB
			"SELECT id, object_title FROM {$table} WHERE object_type = 'option'"
		);

		foreach ( $rows as $row ) {
			$name = (string) $row->object_title;
			if ( '' === $name ) {
				continue;
			}

			$wpdb->update(
				$table,
				array( 'object_id' => OptionExporter::option_id( $name ) ),
				array( 'id' => (int) $row->id ),
				array( '%d' ),
				array( '%d' )
			);
		}
	}

	/**
	 * Create / update all tables via dbDelta.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$queue           = self::queue_table();
		$deployments     = self::deployments_table();
		$revisions       = self::revisions_table();

		// A single object should never have more than one queue row. The unique
		// key enforces the smart-queue "one row per object" rule at the DB layer.
		dbDelta(
			"CREATE TABLE {$queue} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				object_type varchar(40) NOT NULL,
				object_subtype varchar(40) NOT NULL DEFAULT '',
				object_id bigint(20) unsigned NOT NULL,
				object_title text NOT NULL,
				action varchar(20) NOT NULL,
				object_hash varchar(32) NOT NULL DEFAULT '',
				deployed_hash varchar(32) NOT NULL DEFAULT '',
				baseline_hash varchar(32) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'pending',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY object_identity (object_type,object_subtype,object_id),
				KEY status (status),
				KEY user_id (user_id)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$deployments} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				deployment_uuid varchar(36) NOT NULL,
				deployed_by bigint(20) unsigned NOT NULL DEFAULT 0,
				deployed_at datetime NOT NULL,
				deployment_status varchar(20) NOT NULL DEFAULT 'pending',
				deployment_log longtext NULL,
				PRIMARY KEY  (id),
				KEY deployment_uuid (deployment_uuid),
				KEY deployed_at (deployed_at)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$revisions} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				deployment_id bigint(20) unsigned NOT NULL DEFAULT 0,
				object_type varchar(40) NOT NULL,
				object_id bigint(20) unsigned NOT NULL,
				snapshot longtext NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY object_lookup (object_type,object_id)
			) {$charset_collate};"
		);

		/*
		 * DB v4 — the API access log behind Logs & Diagnostics.
		 *
		 * A table rather than an option because the screen aggregates by IP ("how many
		 * failures from this address?"), which needs indexed queries, and because the row
		 * count is unbounded by nature. LogRetention purges it by age.
		 *
		 * `ip` is varchar(45) to hold a full IPv6 address. The two indexes are the two
		 * questions actually asked: everything from one address in time order, and the
		 * recent tail across all addresses.
		 */
		/*
		 * DB v5 — replay-protection nonces (SECURITY.md H-1a).
		 *
		 * The UNIQUE index IS the check. Transients could not give an atomic one: two
		 * concurrent deliveries of the same request could both read "not seen" before either
		 * wrote, and a persistent object cache could evict the key inside the window. Here
		 * the second INSERT simply fails, and it fails because the database says so.
		 *
		 * `nonce_hash` is CHAR(64) — a sha256 in hex — rather than the nonce itself: the
		 * nonce is attacker-controlled and arbitrarily long, and a fixed-width key indexes
		 * better anyway.
		 */
		$nonces = self::nonces_table();

		dbDelta(
			"CREATE TABLE {$nonces} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				nonce_hash char(64) NOT NULL,
				created_at datetime NOT NULL,
				expires_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY nonce_hash (nonce_hash),
				KEY expires_at (expires_at)
			) {$charset_collate};"
		);

		$api_log = self::api_log_table();

		dbDelta(
			"CREATE TABLE {$api_log} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				ip varchar(45) NOT NULL DEFAULT '',
				remote_addr varchar(45) NOT NULL DEFAULT '',
				forwarded varchar(255) NOT NULL DEFAULT '',
				route varchar(40) NOT NULL DEFAULT '',
				method varchar(10) NOT NULL DEFAULT '',
				outcome varchar(40) NOT NULL DEFAULT '',
				status smallint(5) unsigned NOT NULL DEFAULT 0,
				user_agent varchar(255) NOT NULL DEFAULT '',
				is_new_ip tinyint(1) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY ip_time (ip,created_at),
				KEY created_at (created_at),
				KEY outcome (outcome)
			) {$charset_collate};"
		);
	}

	public static function queue_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ifs_deploy_queue';
	}

	public static function deployments_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ifs_deploy_deployments';
	}

	public static function revisions_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ifs_deploy_revisions';
	}

	public static function api_log_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ifs_deploy_api_log';
	}

	public static function nonces_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ifs_deploy_nonces';
	}
}
