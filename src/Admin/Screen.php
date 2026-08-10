<?php
declare(strict_types=1);

namespace IfsDeploy\Admin;

use IfsDeploy\Admin\Pages\SettingsPage;
use IfsDeploy\Support\Access;
use IfsDeploy\Support\Config;

/**
 * The single admin screen that hosts every tab.
 *
 * Layout, top to bottom: plugin header · tab bar · active tab panel. The tab panel is
 * the only part that changes when tabs are switched, and switching happens over AJAX
 * so the page never reloads.
 *
 * Only ONE tab is rendered per request. That is a hard requirement, not a nicety:
 * Compare & Sync fetches Production's entire index on render and Pending Changes
 * verifies the queue against Production, so rendering all six would fire two remote
 * calls on every single visit. See Tabs::all().
 */
final class Screen {

	public function render(): void {
		if ( ! current_user_can( Access::CAP_ACCESS ) ) {
			return;
		}

		$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : Tabs::DEFAULT_TAB;
		$active    = Tabs::resolve( $requested );

		// Settings forms POST to this page, so handle the submission on a REAL page
		// load, before anything renders. It cannot live in the AJAX path: a form post
		// is a normal navigation, not an XHR. The returned markup is the save notice,
		// which belongs above the tab bar rather than inside the panel — otherwise
		// switching tabs after a save would carry the notice along with the content.
		$notice = '';
		if ( 'settings' === $active && current_user_can( AdminMenu::CAPABILITY ) ) {
			$notice = SettingsPage::handle_post();
		}

		echo '<div class="wrap ifs-deploy dp-screen">';

		$this->header( $notice );

		echo '<div class="dp-panel">';
		$this->tab_bar( $active );

		printf(
			'<div class="dp-tab-panel" id="dp-tab-panel" data-active-tab="%s">%s</div>',
			esc_attr( $active ),
			// Tab content is assembled from already-escaped markup by the page classes.
			Tabs::render( $active ) // phpcs:ignore WordPress.Security.EscapeOutput
		);

		echo '</div>';

		// Dialog shells live once for the whole screen rather than once per tab, so
		// swapping panels can never leave a stale or duplicated dialog in the DOM.
		PreviewModal::render();
		ConfirmModal::render();

		echo '</div>';
	}

	/**
	 * Plugin header: name, environment badge, and the notice slot beneath it.
	 *
	 * @param string $notice Pre-built notice markup from a settings save, or ''.
	 */
	private function header( string $notice = '' ): void {
		echo '<div class="dp-brand">';

		// A text wordmark, not an image: the plugin ships no bundled artwork, so the
		// heading costs no request and stays legible at any zoom or colour scheme.
		echo '<span class="dp-brand-name">IFS Deploy</span>';

		// Which side of the pair this is decides what every screen can do, so it is
		// worth stating once, permanently, rather than only on the Overview tab.
		printf(
			'<span class="dp-brand-role dp-brand-role--%1$s">%2$s</span>',
			Config::is_production() ? 'production' : 'staging',
			Config::is_production()
				? esc_html__( 'Production', 'ifs-deploy' )
				: esc_html__( 'Staging', 'ifs-deploy' )
		);
		echo '</div>';

		// WordPress relocates every admin notice — ours and other plugins' — to this
		// marker. Without it they render above the header and break the layout.
		echo '<hr class="wp-header-end">';

		if ( '' !== $notice ) {
			echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped by SettingsPage.
		}

		// Target for the AJAX notices admin.js writes.
		echo '<div id="ifs-deploy-notice"></div>';
	}

	/**
	 * The tab bar. Real anchors, so a tab is linkable and works without JavaScript;
	 * admin.js intercepts the click and swaps the panel instead of navigating.
	 */
	private function tab_bar( string $active ): void {
		echo '<nav class="dp-tabs" role="tablist">';

		foreach ( Tabs::available() as $slug => $tab ) {
			printf(
				'<a href="%1$s" class="dp-tab%2$s" data-tab="%3$s" role="tab" aria-selected="%4$s">%5$s</a>',
				esc_url( Tabs::url( $slug ) ),
				$slug === $active ? ' is-active' : '',
				esc_attr( $slug ),
				$slug === $active ? 'true' : 'false',
				esc_html( $tab['label'] )
			);
		}

		echo '</nav>';
	}
}
