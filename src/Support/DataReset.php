<?php
declare(strict_types=1);

namespace IfsDeploy\Support;

/**
 * Returns this site to the state it was in before IFS Deploy had ever run, without
 * uninstalling the plugin.
 *
 * ── WHAT THIS IS FOR ───────────────────────────────────────────────────────────────
 *
 * Testing a deploy pair means repeatedly pushing the same handful of objects, and the
 * plugin deliberately REMEMBERS what it has done: queue rows carry a `deployed_hash` so an
 * unchanged object is not offered again, and every object that has ever been deployed
 * carries an origin stamp so the next push updates it rather than duplicating it.
 *
 * That memory is exactly right in production and exactly wrong when someone is trying to
 * reproduce a first-time push. Deactivating and reactivating does not clear it — the tables
 * survive, and so do the stamps, which live on the content itself.
 *
 * ── WHAT IT DELIBERATELY DOES NOT TOUCH ────────────────────────────────────────────
 *
 * Content. Not one post, attachment, term or option that belongs to the site is removed or
 * altered beyond having this plugin's own bookkeeping meta stripped from it. A reset that
 * could delete a page would be a far worse thing to have on a Settings screen than
 * anything it saves.
 *
 * The connection is kept unless it is explicitly asked for, because re-pairing two sites
 * means regenerating credentials and copying them across by hand — a much larger
 * interruption than the reset is meant to be, and not what "start testing again" usually
 * means.
 *
 * ── AND WHAT IT CANNOT DO ──────────────────────────────────────────────────────────
 *
 * This is ONE site. Running it on Staging does not clear Production, and the two are
 * independent: Production keeps its own snapshots, its own history, and the origin stamps
 * on the copies it holds. Clearing only one side is a legitimate thing to want, so this
 * does not try to be clever about it — but it is why the confirmation says so out loud.
 */
final class DataReset {

	/**
	 * Post meta this plugin writes onto content, which is what makes an object "already
	 * deployed" as far as the next push is concerned.
	 *
	 * Listed rather than matched with a `LIKE '_ifs_deploy_%'` sweep so that adding a key
	 * is a deliberate act. A prefix match would silently take anything a future version
	 * stored under the same namespace, including something a reset should keep.
	 */
	private const POST_META = array(
		'_ifs_deploy_origin_id',
		'_ifs_deploy_origin_site',
		'_ifs_deploy_source_url',
		'_ifs_deploy_src_sig',
	);

	/** Term meta, same reasoning. */
	private const TERM_META = array(
		'_ifs_deploy_origin_term_id',
		'_ifs_deploy_origin_site',
	);

	/**
	 * Options that are pure bookkeeping — safe to drop without unpairing the sites.
	 */
	private const STATE_OPTIONS = array(
		'ifs_deploy_debug_log',
		'ifs_deploy_peer_protocol',
		'ifs_deploy_log_duplicates',
	);

	/**
	 * Options that ARE the connection. Only removed when explicitly asked for.
	 */
	private const CONNECTION_OPTIONS = array(
		'ifs_deploy_credentials',
		'ifs_deploy_remote',
	);

	/**
	 * Wipe this site's IFS Deploy state.
	 *
	 * @param bool $include_connection Also forget the credentials and the remote site.
	 *
	 * @return array<string,int> What was removed, by kind, for the confirmation message.
	 */
	public static function run( bool $include_connection = false ): array {
		global $wpdb;

		$counts = array(
			'queue'       => 0,
			'deployments' => 0,
			'revisions'   => 0,
			'api_log'     => 0,
			'addresses'   => 0,
			'nonces'      => 0,
			'stamps'      => 0,
		);

		/*
		 * TRUNCATED, not dropped. The tables have to still exist when this returns — the
		 * plugin is running, and the very next page load will write to them.
		 */
		$tables = array(
			'queue'       => Schema::queue_table(),
			'deployments' => Schema::deployments_table(),
			'revisions'   => Schema::revisions_table(),
			'api_log'     => Schema::api_log_table(),
			'addresses'   => Schema::api_addresses_table(),
			'nonces'      => Schema::nonces_table(),
		);

		foreach ( $tables as $key => $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$counts[ $key ] = (int) $wpdb->query( "DELETE FROM {$table}" );
		}

		/*
		 * THE STAMPS ON THE CONTENT, which are the half a reset is usually actually after.
		 *
		 * Without this, every object this site has ever deployed still says so, and the
		 * next push updates the copy on the other side instead of behaving like the first
		 * push it is meant to be. Deleted directly rather than through
		 * `delete_post_meta_by_key()` for the term table's sake — there is no term-meta
		 * equivalent of that function — and kept symmetrical so both read the same way.
		 */
		foreach ( self::POST_META as $key ) {
			$counts['stamps'] += (int) $wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $key ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery
		}

		foreach ( self::TERM_META as $key ) {
			$counts['stamps'] += (int) $wpdb->delete( $wpdb->termmeta, array( 'meta_key' => $key ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery
		}

		wp_cache_flush();

		foreach ( self::STATE_OPTIONS as $option ) {
			delete_option( $option );
		}

		/*
		 * Nonces predate their own table on sites that never reached DB v5, and on a site
		 * with no persistent object cache a transient is a row in wp_options. The table
		 * above is emptied either way; this catches the older shape.
		 */
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_dp_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_dp_' ) . '%'
			)
		);

		if ( $include_connection ) {
			foreach ( self::CONNECTION_OPTIONS as $option ) {
				delete_option( $option );
			}
		}

		/*
		 * Logged AFTER the log has been cleared, on purpose: this entry is the first thing
		 * in the new log, and a reset that left no trace of itself would make the next
		 * report of "everything disappeared" impossible to explain.
		 */
		DebugLog::info(
			'All IFS Deploy data on this site was reset',
			array_merge(
				$counts,
				array(
					'connection' => $include_connection ? 'removed' : 'kept',
					'by'         => get_current_user_id(),
					'note'       => 'This site only. The other site keeps its own history, snapshots and origin stamps.',
				)
			)
		);

		return $counts;
	}

	/**
	 * One sentence naming what was actually removed.
	 */
	public static function summarise( array $counts, bool $include_connection ): string {
		$message = sprintf(
			/* translators: 1: pending changes, 2: deployments, 3: restore points, 4: deployment stamps */
			__( 'Plugin data reset: %1$d pending changes, %2$d deployments, %3$d restore points and %4$d deployment stamps removed on this site.', 'ifs-deploy' ),
			(int) ( $counts['queue'] ?? 0 ),
			(int) ( $counts['deployments'] ?? 0 ),
			(int) ( $counts['revisions'] ?? 0 ),
			(int) ( $counts['stamps'] ?? 0 )
		);

		if ( $include_connection ) {
			$message .= ' ' . __( 'The connection settings were removed too — this site is no longer paired.', 'ifs-deploy' );
		}

		return $message;
	}
}
