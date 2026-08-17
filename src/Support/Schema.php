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
 *   9 — no schema change. Removes pending queue rows the current rules would never have
 *       created: those with no author (`user_id = 0`), and plugin/theme archives.
 *   8 — the `api_addresses` table. The roster of callers is kept apart from the request
 *       log so it survives Clear and retention, and so a healthy pair no longer needs a
 *       row per accepted request just to stay complete. Backfilled from any existing
 *       api_log rows — see migrate().
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

		if ( version_compare( $from, '8', '<' ) && '0' !== $from ) {
			self::seed_api_addresses();
		}

		if ( version_compare( $from, '9', '<' ) && '0' !== $from ) {
			self::drop_untrackable_rows();
		}
	}

	/**
	 * Remove pending rows that the current rules would never have created.
	 *
	 * Two kinds, both of which were reported sitting in Pending Changes:
	 *
	 *  - **No author.** A row with `user_id = 0` came from cron, WP-CLI or some async
	 *    cleanup rather than from a person. `QueueRepository::upsert()` now refuses those,
	 *    but rows written before it did would sit there for ever as "Changed By: Unknown",
	 *    because nothing else ever revisits a queue row.
	 *  - **Archives.** A plugin or theme ZIP is not content and is no longer tracked, but
	 *    the delete path used to queue one anyway.
	 *
	 * Matched on the TITLE for archives rather than the mime type, deliberately: these are
	 * `delete` rows, so the attachment they describe is usually already gone and there is
	 * no post left to ask. The title is the file name, which is the only evidence still
	 * available.
	 *
	 * Scoped to `pending` — a deployed or ignored row is history, and rewriting history to
	 * match a rule introduced later would be a different and worse kind of surprise.
	 */
	private static function drop_untrackable_rows(): void {
		global $wpdb;

		$table = self::queue_table();

		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "DELETE FROM {$table} WHERE status = %s AND user_id = 0", 'pending' ) // phpcs:ignore WordPress.DB.PreparedSQL
		);

		$archives = array( '%.zip', '%.gz', '%.tgz', '%.tar', '%.bz2', '%.rar', '%.7z', '%.exe', '%.jar', '%.phar' );

		foreach ( $archives as $pattern ) {
			$wpdb->query( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE status = %s AND object_type = %s AND object_title LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL
					'pending',
					'media',
					$pattern
				)
			);
		}
	}

	/**
	 * Build the address roster from the request rows that already exist.
	 *
	 * Without this the roster starts empty on an upgrade, and every address the site has
	 * ever seen would be announced as new the next time it called — the "first API request
	 * from a new address" warning, for the peer it has been talking to all along.
	 *
	 * `INSERT ... SELECT` with `IGNORE` so a re-run cannot duplicate a row: the UNIQUE key
	 * on `ip` is what makes this idempotent, which matters because a partly-completed
	 * upgrade is retried from the start.
	 */
	private static function seed_api_addresses(): void {
		global $wpdb;

		$addresses = self::api_addresses_table();
		$api_log   = self::api_log_table();

		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL
				"INSERT IGNORE INTO {$addresses} (ip, requests, failures, last_route, last_outcome, user_agent, first_seen, last_seen)
				 SELECT ip,
				        COUNT(*),
				        SUM( CASE WHEN outcome NOT IN ( %s, %s ) THEN 1 ELSE 0 END ),
				        '',
				        '',
				        MAX( user_agent ),
				        MIN( created_at ),
				        MAX( created_at )
				 FROM {$api_log}
				 WHERE ip <> ''
				 GROUP BY ip",
				'ok',
				'ifs_deploy_duplicate'
			)
		);
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
	private static function collapse_duplicate_rows(): int {
		global $wpdb;

		$table = self::queue_table();

		return (int) $wpdb->query( // phpcs:ignore WordPress.DB
			"DELETE older FROM {$table} AS older
			 INNER JOIN {$table} AS newer
			     ON older.object_type = newer.object_type
			    AND older.object_id   = newer.object_id
			    AND older.id          < newer.id"
		);
	}

	/**
	 * Make sure the queue's UNIQUE key actually EXISTS, and rebuild it if it does not.
	 *
	 * ── WHY THIS IS NOT PARANOIA ───────────────────────────────────────────────────
	 *
	 * The key is what stops two concurrent saves of one object both writing a row.
	 * `upsert()` reads then writes, and those are two statements: two requests can both
	 * find nothing and both insert. The UNIQUE index is the only thing that makes the
	 * second one fail — exactly the same reasoning as `Auth\NonceStore`.
	 *
	 * `dbDelta()` declares the key in CREATE TABLE, but dbDelta is unreliable at ADDING
	 * an index to a table that already exists, and it reports nothing when it does not.
	 * A site was found with EIGHT queue rows for a single attachment, created within the
	 * same second — the signature of concurrent inserts against a table with no unique
	 * key at all.
	 *
	 * Order matters: MySQL REFUSES to add a unique index while duplicate rows exist, so
	 * the duplicates have to go first. That is also why the missing key is
	 * self-perpetuating — once duplicates are in, no later dbDelta can ever add it back.
	 *
	 * Failure here is logged, never fatal. `QueueRepository` no longer depends on the
	 * index for correctness (it identifies a row by type + object id, dedupes on read
	 * and collapses duplicates), so a database that refuses the index degrades to
	 * "correct, but without the concurrency backstop" rather than breaking.
	 */
	private static function ensure_object_identity_index(): void {
		global $wpdb;

		$table = self::queue_table();

		// SHOW INDEX rather than information_schema: no extra grant needed, and it is
		// answered from the table's own metadata.
		$existing = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'object_identity'" ); // phpcs:ignore WordPress.DB

		if ( ! empty( $existing ) ) {
			return;
		}

		$removed = self::collapse_duplicate_rows();

		// Suppress the error the ALTER would otherwise print: on a database that will not
		// take the index this is a degradation to report, not a screen full of SQL.
		$quiet = $wpdb->suppress_errors( true );
		$added = $wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY object_identity (object_type,object_subtype,object_id)" ); // phpcs:ignore WordPress.DB
		$wpdb->suppress_errors( $quiet );

		if ( false === $added ) {
			DebugLog::error(
				'The queue table has no UNIQUE key and it could not be created. Duplicate pending changes are possible; they are merged when the screen is viewed.',
				array(
					'table' => $table,
					'error' => (string) $wpdb->last_error,
				)
			);

			return;
		}

		DebugLog::warning(
			'The queue table was missing its UNIQUE key and it has been rebuilt.',
			array(
				'table'              => $table,
				'duplicates_removed' => $removed,
			)
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

		/*
		 * DB v8 — one row per ADDRESS, separate from the per-request rows.
		 *
		 * Previously both questions were answered from `api_log`: "what happened recently"
		 * by listing it, and "who calls this site" by GROUP BY over it. That coupling had
		 * two consequences the owner actually hit.
		 *
		 * Clearing the request list also erased the roster of addresses — the one thing
		 * worth keeping, because it answers "how many distinct machines push to us". And
		 * every accepted request needed a row for the roster to stay complete, so a
		 * healthy pair wrote a row per deploy step for ever.
		 *
		 * Split apart, `api_log` can hold only what is worth reading (first sightings and
		 * failures) while the roster stays exact and survives both Clear and retention. It
		 * is bounded by the number of distinct addresses, which on a paired site is one.
		 */
		$addresses = self::api_addresses_table();

		dbDelta(
			"CREATE TABLE {$addresses} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				ip varchar(45) NOT NULL DEFAULT '',
				requests bigint(20) unsigned NOT NULL DEFAULT 0,
				failures bigint(20) unsigned NOT NULL DEFAULT 0,
				last_route varchar(40) NOT NULL DEFAULT '',
				last_outcome varchar(40) NOT NULL DEFAULT '',
				user_agent varchar(255) NOT NULL DEFAULT '',
				first_seen datetime NOT NULL,
				last_seen datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY ip (ip),
				KEY last_seen (last_seen)
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

		/*
		 * LAST, and after every dbDelta above.
		 *
		 * dbDelta declares the queue's UNIQUE key but cannot be trusted to have created
		 * it on a table that already existed — and without it two concurrent saves of one
		 * object both insert. Verified explicitly rather than assumed. Runs on activation
		 * and on every schema upgrade, so re-activating the plugin repairs it.
		 */
		self::ensure_object_identity_index();
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

	public static function api_addresses_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ifs_deploy_api_addresses';
	}

	public static function nonces_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ifs_deploy_nonces';
	}
}
