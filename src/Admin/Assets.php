<?php
declare(strict_types=1);

namespace IfsDeploy\Admin;

/**
 * Enqueues admin CSS/JS on IFS Deploy screens only.
 *
 * Nothing here loads site-wide: the sidebar menu icon is a Dashicons glyph
 * (see AdminMenu::add_menu()), which core sizes and colours itself, so it needs
 * no stylesheet of ours on every admin page.
 */
final class Assets {

	public function enqueue( string $hook ): void {
		if ( false === strpos( $hook, AdminMenu::SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'ifs-deploy-admin',
			IFS_DEPLOY_URL . 'assets/css/admin.css',
			array(),
			self::asset_version( 'assets/css/admin.css' )
		);

		wp_enqueue_script(
			'ifs-deploy-admin',
			IFS_DEPLOY_URL . 'assets/js/admin.js',
			// jquery-ui-autocomplete powers the user pickers in Role Management. It is
			// registered by core, so this adds no bundled asset.
			array( 'jquery', 'jquery-ui-autocomplete' ),
			self::asset_version( 'assets/js/admin.js' ),
			true
		);

		wp_localize_script(
			'ifs-deploy-admin',
			'IfsDeploy',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( Ajax::NONCE ),
				'i18n'    => array(
					'confirmRollback'       => __( 'Roll back this deployment on Production?', 'ifs-deploy' ),
					'confirmRollbackTitle'  => __( 'Roll back deployment', 'ifs-deploy' ),
					'confirmRollbackButton' => __( 'Confirm Rollback', 'ifs-deploy' ),
					'confirmPushTitle'      => __( 'Push to Live', 'ifs-deploy' ),
					'confirmPushBody'       => __( 'Are you sure you want to push updates to live?', 'ifs-deploy' ),
					/* translators: %d: number of changes about to be pushed */
					'confirmPushCount'      => __( '%d change(s) will be pushed to Production.', 'ifs-deploy' ),
					'confirmPushButton'     => __( 'Push to Live', 'ifs-deploy' ),
					'confirmClear'          => __( 'Clear all deployment history? This cannot be undone. Snapshots already created on Production are not affected.', 'ifs-deploy' ),
					'confirmClearTitle'     => __( 'Clear deployment history', 'ifs-deploy' ),
					'confirmClearButton'    => __( 'Clear History', 'ifs-deploy' ),
					'confirmBlockIpTitle'   => __( 'Block this address', 'ifs-deploy' ),
					/* translators: %s: IP address */
					'confirmBlockIp'        => __( 'Refuse every API request from %s from now on? If this is your own Staging site, deploys will stop working immediately.', 'ifs-deploy' ),
					'confirmBlockIpButton'  => __( 'Block address', 'ifs-deploy' ),
					'confirmAllowIpTitle'   => __( 'Allow this address', 'ifs-deploy' ),
					/* translators: %s: IP address */
					'confirmAllowIp'        => __( 'Add %s to the allow list? If the list is currently empty this RESTRICTS the API to it — every other address, including any other Staging site, is refused from then on.', 'ifs-deploy' ),
					'confirmAllowIpButton'  => __( 'Allow address', 'ifs-deploy' ),
					'confirmClearApiTitle'  => __( 'Clear API access log', 'ifs-deploy' ),
					'confirmClearApi'       => __( 'Delete the record of every API request to this site? This is the security log — once cleared, past unauthorized attempts cannot be reviewed.', 'ifs-deploy' ),
					'working'               => __( 'Working…', 'ifs-deploy' ),
					'genericError'          => __( 'Request failed. Please try again.', 'ifs-deploy' ),
					'loadingPreview'        => __( 'Comparing with Production…', 'ifs-deploy' ),
					'previewTitle'          => __( 'Changes to be deployed', 'ifs-deploy' ),
					'remove'                => __( 'Remove', 'ifs-deploy' ),
				),
			)
		);
	}

	/**
	 * Cache-buster that changes whenever the file changes.
	 *
	 * The plugin version alone is not enough: editing an asset without bumping
	 * IFS_DEPLOY_VERSION leaves browsers serving the old file from cache, so new
	 * markup arrives with stale JS behind it and looks broken. Appending the file
	 * mtime makes every deployed edit a new URL.
	 */
	private static function asset_version( string $relative_path ): string {
		$mtime = @filemtime( IFS_DEPLOY_DIR . $relative_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		return $mtime ? IFS_DEPLOY_VERSION . '.' . $mtime : IFS_DEPLOY_VERSION;
	}
}
