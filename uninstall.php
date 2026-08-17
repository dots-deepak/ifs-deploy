<?php
/**
 * Uninstall handler. Removes IFS Deploy tables and options.
 *
 * @package IFS_Deploy
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$tables = array(
	$wpdb->prefix . 'ifs_deploy_queue',
	$wpdb->prefix . 'ifs_deploy_deployments',
	$wpdb->prefix . 'ifs_deploy_revisions',
	$wpdb->prefix . 'ifs_deploy_api_log',
	$wpdb->prefix . 'ifs_deploy_api_addresses',
	$wpdb->prefix . 'ifs_deploy_nonces',
);

foreach ( $tables as $table ) {
	// Table name cannot be parameterized; it is built from a trusted prefix.
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
}

$options = array(
	'ifs_deploy_db_version',
	'ifs_deploy_role',
	'ifs_deploy_credentials',
	'ifs_deploy_remote',
	'ifs_deploy_debug_log',
	'ifs_deploy_verbose_log',
	'ifs_deploy_roles',
	'ifs_deploy_users',
	'ifs_deploy_log_retention_days',
	'ifs_deploy_trusted_ip_header',
	'ifs_deploy_anonymise_ips',
	'ifs_deploy_ip_allow',
	'ifs_deploy_ip_block',
	'ifs_deploy_content_firewall',
	'ifs_deploy_peer_protocol',
	'ifs_deploy_log_duplicates',
	// Written by Support\LegacyRename once the pre-rename migration has been considered.
	'ifs_deploy_legacy_migrated',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// The daily retention purge. Left behind, WP-Cron keeps firing a hook nothing listens to.
wp_clear_scheduled_hook( 'ifs_deploy_purge_logs' );

/*
 * Belt and braces: a site that was never booted after the rename still has the old event.
 *
 * `Support\Legacy` is required directly because uninstall.php runs WITHOUT the plugin
 * bootstrap — no autoloader, no constants. That class has no dependencies of its own, which is
 * what makes a bare require safe here, and it keeps the pre-rename spelling in one file rather
 * than repeated as a literal.
 */
require_once __DIR__ . '/src/Support/Legacy.php';

wp_clear_scheduled_hook( \IfsDeploy\Support\Legacy::CRON_HOOK );

/*
 * Replay nonces live in their own table (dropped above) since DB v5, but a site that never
 * reached that version — or one that fell back after a failed migration — still has them as
 * transients. On a site without a persistent object cache those are rows in wp_options, and
 * uninstall is meant to leave nothing behind. Deleted directly because there is no API for
 * "every transient matching a prefix", and each is keyed by its own hash.
 */
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_dp_nonce_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_dp_nonce_' ) . '%'
	)
);
