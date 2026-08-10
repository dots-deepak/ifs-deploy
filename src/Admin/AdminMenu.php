<?php
declare(strict_types=1);

namespace IfsDeploy\Admin;

use IfsDeploy\Support\Access;

/**
 * Registers the IFS Deploy admin menu and its single screen, plus admin assets.
 *
 * Everything lives on ONE page now: `admin.php?page=ifs-deploy&tab=<slug>`, rendered by
 * Admin\Screen. The former submenu slugs (`ifs-deploy-pending` and friends) are no
 * longer registered, so `redirect_legacy_slugs()` forwards them rather than letting a
 * bookmark land on WordPress's "not allowed to access this page".
 */
final class AdminMenu {

	/**
	 * Capability for the administrator-only screens (settings, compare, logs).
	 *
	 * Kept as `manage_options` under its original name so nothing that already
	 * checks AdminMenu::CAPABILITY loosens by accident. Screens a delegated role may
	 * reach use Access::CAP_* instead.
	 */
	public const CAPABILITY = 'manage_options';

	public const SLUG = 'ifs-deploy';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );

		// NOT `admin_init` — that fires too late to help here. wp-admin/admin.php loads
		// wp-admin/menu.php (which runs the access check and calls wp_die) BEFORE it
		// fires admin_init, so a redirect hooked there never runs for an unregistered
		// page. `admin_page_access_denied` fires immediately before that wp_die and is
		// the hook core provides for exactly this.
		add_action( 'admin_page_access_denied', array( $this, 'redirect_legacy_slugs' ) );

		$assets = new Assets();
		add_action( 'admin_enqueue_scripts', array( $assets, 'enqueue' ) );
	}

	/**
	 * Forward the pre-tab submenu URLs to their tab, preserving any other query args
	 * (Pending Changes carries a `user` filter, for instance).
	 *
	 * Runs on every denied admin page, so it must act ONLY on the five slugs this
	 * plugin used to register and return silently for everything else — including
	 * `page=ifs-deploy` itself, which a user genuinely lacking access may hit. That
	 * slug is absent from the map below, so there is no redirect loop.
	 */
	public function redirect_legacy_slugs(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- read-only redirect of a GET.
		$query = isset( $_GET ) ? (array) wp_unslash( $_GET ) : array();
		// phpcs:enable WordPress.Security.NonceVerification

		$target = self::legacy_tab_url( $query );

		if ( null === $target ) {
			return;
		}

		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * Where an old submenu URL should land, or null if this is not one.
	 *
	 * Separate from the hook so the mapping is testable without a redirect and an
	 * exit() in the middle of it.
	 *
	 * @param array<string,mixed> $query Unslashed request query, usually $_GET.
	 */
	public static function legacy_tab_url( array $query ): ?string {
		$page = isset( $query['page'] ) ? sanitize_key( (string) $query['page'] ) : '';

		$legacy = array(
			self::SLUG . '-pending'  => 'pending',
			self::SLUG . '-compare'  => 'compare',
			self::SLUG . '-history'  => 'history',
			self::SLUG . '-settings' => 'settings',
			self::SLUG . '-logs'     => 'logs',
		);

		if ( ! isset( $legacy[ $page ] ) ) {
			return null;
		}

		$tab = $legacy[ $page ];

		// Settings' inner sections moved from `tab` to `section`. An old bookmark of
		// Role Management carries `tab=roles`, which would otherwise be read as a
		// request for a top-level tab called "roles" and fall back to Overview.
		if ( 'settings' === $tab && isset( $query['tab'] ) ) {
			$query['section'] = sanitize_key( (string) $query['tab'] );
		}

		unset( $query['page'] );
		$query['tab'] = $tab;

		return add_query_arg( $query, admin_url( 'admin.php?page=' . self::SLUG ) );
	}

	public function add_menu(): void {
		// One page, registered against the virtual access capability so a delegated
		// role reaches it too. Per-tab capabilities are enforced by Admin\Tabs, both
		// when building the tab bar and again when rendering a tab's content — the
		// AJAX loader shares that second check.
		// The icon is core's `dashicons-migrate` glyph. Passing a Dashicons class rather
		// than an image URL means WordPress sizes and colours the mark itself — it picks
		// up the admin colour scheme and its hover/current states with no CSS of ours,
		// and it costs no extra request on the admin pages that all load Dashicons anyway.
		add_menu_page(
			__( 'IFS Deploy', 'ifs-deploy' ),
			__( 'IFS Deploy', 'ifs-deploy' ),
			Access::CAP_ACCESS,
			self::SLUG,
			array( new Screen(), 'render' ),
			'dashicons-migrate',
			81
		);
	}
}
