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
					'confirmForgetIpTitle'  => __( 'Forget this address', 'ifs-deploy' ),
					/* translators: %s: IP address */
					'confirmForgetIp'       => __( 'Remove %s and every request logged from it? This deletes history rather than changing a rule, it cannot be undone, and the address will be reported as new the next time it calls.', 'ifs-deploy' ),
					'confirmForgetIpButton' => __( 'Forget address', 'ifs-deploy' ),
					'noneSelected'          => __( 'Nothing is selected. Tick at least one item first.', 'ifs-deploy' ),
					'nothingPending'        => __( 'There is nothing pending to push.', 'ifs-deploy' ),
					'pushNotYours'          => __( 'You don’t have permission to push these changes. They were made by someone else, and only the person who made a change — or an administrator — can push it.', 'ifs-deploy' ),
					/* translators: %d: how many items belong to other users */
					'pushExcluded'          => __( '%d of these were made by someone else and will NOT be pushed.', 'ifs-deploy' ),
					'pushTitle'             => __( 'Pushing changes to Production', 'ifs-deploy' ),
					/*
					 * ONE percent sign, not two.
					 *
					 * `%%` is how PHP's sprintf() escapes a literal percent, and this string
					 * never reaches sprintf — the browser substitutes it with String.replace().
					 * So the escape was printed verbatim and the dialog read "0%% complete".
					 *
					 * translators: 1: items done, 2: items total, 3: percentage complete
					 */
					'pushProgress'          => __( '%1$d of %2$d items — %3$d% complete', 'ifs-deploy' ),
					'pushComplete'          => __( 'Finished. Refreshing the list…', 'ifs-deploy' ),
					'pushPhaseMedia'        => __( 'Uploading media…', 'ifs-deploy' ),
					'pushPhaseTerm'         => __( 'Syncing categories and tags…', 'ifs-deploy' ),
					'pushPhasePost'         => __( 'Syncing posts and pages…', 'ifs-deploy' ),
					'pushPhaseMenu'         => __( 'Updating menus…', 'ifs-deploy' ),
					'pushPhaseOption'       => __( 'Updating settings…', 'ifs-deploy' ),
					'pushPhaseOther'        => __( 'Syncing content…', 'ifs-deploy' ),
					/* translators: 1: name of the first item in the batch, 2: how many others are in it */
					'pushItemMore'          => __( '%1$s and %2$d more', 'ifs-deploy' ),
					/* translators: %d: seconds remaining */
					'pushEtaSeconds'        => __( 'About %d seconds remaining', 'ifs-deploy' ),
					/* translators: %d: minutes remaining */
					'pushEtaMinutes'        => __( 'About %d minutes remaining', 'ifs-deploy' ),
					'pushEtaAlmost'         => __( 'Almost done…', 'ifs-deploy' ),
					'pushCancel'            => __( 'Cancel push', 'ifs-deploy' ),
					'pushCancelling'        => __( 'Stopping, and undoing what has already been sent…', 'ifs-deploy' ),
					/* translators: %d: number of items pushed */
					'pushDone'              => __( 'Pushed %d items to Production.', 'ifs-deploy' ),
					'working'               => __( 'Working…', 'ifs-deploy' ),
				// Media ID conflict dialog. Production refused to create the attachment
				// because its Staging id is already taken there.
				'conflictTitle'         => __( 'Media ID mismatch detected', 'ifs-deploy' ),
				'conflictMedia'         => __( 'Media', 'ifs-deploy' ),
				'conflictStagingId'     => __( 'Staging ID', 'ifs-deploy' ),
				'conflictProdId'        => __( 'On Production', 'ifs-deploy' ),
				'conflictNotAvailable'  => __( 'Not available', 'ifs-deploy' ),
				/* translators: 1: post type occupying the id, 2: its title */
				'conflictTakenBy'       => __( 'Taken by a %1$s, "%2$s"', 'ifs-deploy' ),
				'conflictChecking'      => __( 'Checking whether this file can be moved…', 'ifs-deploy' ),
				/* translators: %d: the new attachment id being offered */
				'conflictGenerate'      => __( 'Generate new ID (%d)', 'ifs-deploy' ),
				'conflictWorking'       => __( 'Changing the ID…', 'ifs-deploy' ),
				'conflictCancel'        => __( 'Cancel', 'ifs-deploy' ),
					'genericError'          => __( 'Request failed. Please try again.', 'ifs-deploy' ),
					'loadingPreview'        => __( 'Comparing with Production…', 'ifs-deploy' ),
					'previewTitle'          => __( 'Changes to be deployed', 'ifs-deploy' ),
					'remove'                => __( 'Remove', 'ifs-deploy' ),
					// The toast's dismiss button carries no text, so the label is the only
					// thing a screen reader has to announce it by.
					'close'                 => __( 'Dismiss this message', 'ifs-deploy' ),
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
