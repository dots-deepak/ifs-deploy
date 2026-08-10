<?php
declare(strict_types=1);

namespace IfsDeploy\Admin;

use IfsDeploy\Admin\Pages\ComparePage;
use IfsDeploy\Admin\Pages\DashboardPage;
use IfsDeploy\Admin\Pages\HistoryPage;
use IfsDeploy\Admin\Pages\LogsPage;
use IfsDeploy\Admin\Pages\PendingChangesPage;
use IfsDeploy\Admin\Pages\SettingsPage;
use IfsDeploy\Support\Access;
use IfsDeploy\Support\Config;

/**
 * The single-screen tab registry.
 *
 * Everything IFS Deploy does now lives on one admin page, switched client-side. The
 * six former screens are unchanged internally — they simply render their content
 * without a page wrapper, and Screen supplies the chrome.
 *
 * Each tab declares its own capability, and that capability is enforced TWICE: here,
 * so a tab a user may not see is never rendered into the tab bar, and again in
 * Ajax::tab() when the content is fetched. A hidden tab is not an access control.
 */
final class Tabs {

	public const DEFAULT_TAB = 'overview';

	/**
	 * Tabs that only mean anything on the SENDING side.
	 *
	 * All three read data a Production site never has. The queue is filled by editing
	 * hooks on Staging; Compare & Sync signs a request *to* Production, which a Production
	 * site has nowhere to send; and deployment history rows are written only by
	 * `Client\DeploymentService`, which never runs on the receiver — so History on
	 * Production is a permanently empty table.
	 *
	 * Hiding them is a display decision, not a security one. Nothing behind them is
	 * reachable on Production in the first place, so this removes three dead screens from
	 * a shared team install rather than protecting anything.
	 */
	private const STAGING_ONLY = array( 'pending', 'compare', 'history' );

	/**
	 * Does this tab have anything to show on THIS site, given its role?
	 */
	public static function applies_here( string $slug ): bool {
		return ! in_array( $slug, self::STAGING_ONLY, true ) || Config::is_staging();
	}

	/**
	 * Tab definitions in display order.
	 *
	 * `lazy` marks the tabs whose render() performs a REMOTE request to Production —
	 * Compare fetches the whole production index (it signs up to 2000 posts), and
	 * Pending Changes verifies the queue. They must never render on initial page load
	 * unless the user actually asked for them, or every visit to the plugin would fire
	 * two network calls and hammer Production. The lazy flag is informational: all
	 * tabs load on demand anyway, but it documents WHY that is not optional.
	 *
	 * @return array<string,array{label:string,capability:string,class:class-string,lazy:bool}>
	 */
	public static function all(): array {
		return array(
			'overview' => array(
				'label'      => __( 'Overview', 'ifs-deploy' ),
				'capability' => Access::CAP_ACCESS,
				'class'      => DashboardPage::class,
				'lazy'       => false,
			),
			'pending'  => array(
				'label'      => __( 'Pending Changes', 'ifs-deploy' ),
				'capability' => Access::CAP_ACCESS,
				'class'      => PendingChangesPage::class,
				'lazy'       => true,
			),
			'compare'  => array(
				'label'      => __( 'Compare & Sync', 'ifs-deploy' ),
				'capability' => AdminMenu::CAPABILITY,
				'class'      => ComparePage::class,
				'lazy'       => true,
			),
			'history'  => array(
				'label'      => __( 'Deployment History', 'ifs-deploy' ),
				'capability' => Access::CAP_ACCESS,
				'class'      => HistoryPage::class,
				'lazy'       => false,
			),
			'settings' => array(
				'label'      => __( 'Settings', 'ifs-deploy' ),
				'capability' => AdminMenu::CAPABILITY,
				'class'      => SettingsPage::class,
				'lazy'       => false,
			),
			'logs'     => array(
				'label'      => __( 'Logs & Diagnostics', 'ifs-deploy' ),
				'capability' => AdminMenu::CAPABILITY,
				'class'      => LogsPage::class,
				'lazy'       => false,
			),
		);
	}

	/**
	 * Only the tabs this user may open, on this site.
	 *
	 * Two filters, and they are different in kind: the capability is an access control, the
	 * role is applicability. Both end up hiding a tab, so they are applied in one place —
	 * every consumer (the tab bar, `resolve()`, `render()`) then agrees by construction.
	 *
	 * @return array<string,array{label:string,capability:string,class:class-string,lazy:bool}>
	 */
	public static function available(): array {
		return array_filter(
			self::all(),
			static fn( array $tab, string $slug ): bool => current_user_can( $tab['capability'] ) && self::applies_here( $slug ),
			ARRAY_FILTER_USE_BOTH
		);
	}

	/**
	 * Resolve a requested tab slug to one the user may actually open.
	 *
	 * Falls back to the first available tab rather than the hard-coded default,
	 * because a delegated role may not be allowed to see the default one.
	 */
	public static function resolve( string $requested ): string {
		$available = self::available();

		if ( isset( $available[ $requested ] ) ) {
			return $requested;
		}

		if ( isset( $available[ self::DEFAULT_TAB ] ) ) {
			return self::DEFAULT_TAB;
		}

		$first = array_key_first( $available );

		return null !== $first ? (string) $first : self::DEFAULT_TAB;
	}

	/**
	 * Render one tab's content, capability-checked.
	 *
	 * Returns HTML rather than echoing, so the same call serves both the initial page
	 * render and the AJAX response.
	 */
	public static function render( string $slug ): string {
		$tabs = self::available();

		if ( ! isset( $tabs[ $slug ] ) ) {
			/*
			 * Say WHICH of the two reasons it was. A bookmark or an old link to
			 * `tab=history` on a Production site is not a permission problem, and telling
			 * someone they lack access when they do not is how a support question starts.
			 */
			if ( isset( self::all()[ $slug ] ) && ! self::applies_here( $slug ) ) {
				return '<div class="dp-tab-error"><p>'
					. esc_html__( 'That section belongs to the Staging site. This site receives deployments, so there is nothing to show here.', 'ifs-deploy' )
					. '</p></div>';
			}

			return '<div class="dp-tab-error"><p>'
				. esc_html__( 'You do not have permission to view this section.', 'ifs-deploy' )
				. '</p></div>';
		}

		$class = $tabs[ $slug ]['class'];
		$page  = new $class();

		ob_start();
		$page->render();

		return (string) ob_get_clean();
	}

	/**
	 * URL for a tab. Used for real links, so the tabs still work with JS disabled and
	 * a specific tab can be bookmarked or linked to from a notice.
	 */
	public static function url( string $slug ): string {
		return add_query_arg(
			array(
				'page' => AdminMenu::SLUG,
				'tab'  => $slug,
			),
			admin_url( 'admin.php' )
		);
	}
}
