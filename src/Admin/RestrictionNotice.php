<?php
declare(strict_types=1);

namespace IfsDeploy\Admin;

use IfsDeploy\Support\Access;

/**
 * Explains why Compare & Sync, Settings and Logs & Diagnostics are not there.
 *
 * Those screens are deny-by-default: an administrator reaches them only by being named in
 * `IFS_DEPLOY_ADMIN_USERS` in `wp-config.php` (see Access::may_use_restricted_screens()).
 * That is deliberate, but on its own it is indistinguishable from a bug — tabs that used to
 * exist are simply gone, and Settings, the obvious place to look, is one of the missing
 * ones.
 *
 * So the restriction states itself, with the line to paste and the reader's OWN user id
 * already in it. Nobody has to know the id, guess the constant name, or find the
 * documentation; the way back in is on screen.
 *
 * ── WHO SEES IT ────────────────────────────────────────────────────────────────────
 *
 * Only somebody holding `manage_options` who does NOT hold CAP_RESTRICTED — an
 * administrator actually affected by the rule. An Editor never sees it: these screens were
 * never theirs, the notice would describe a restriction that is not the reason they cannot
 * see them, and it would hand out a wp-config.php recipe to someone with no business
 * editing that file.
 *
 * ── AND WHERE ──────────────────────────────────────────────────────────────────────
 *
 * The plugin's own screens, plus the Plugins list. Not site-wide: a persistent nag on every
 * admin page for a setting that is working as configured is its own bug. The Plugins list
 * matters because on Production the sidebar entry is hidden too, so that page is the only
 * place an affected administrator is guaranteed to pass through.
 */
final class RestrictionNotice {

	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	public function render(): void {
		if ( ! $this->applies() ) {
			return;
		}

		$user_id = get_current_user_id();

		/*
		 * Two different situations, and telling somebody the wrong one wastes their time:
		 * a constant that was never added, versus one that is there and does not include
		 * them. The second is the one where people stare at a line that looks correct.
		 */
		$configured = ! empty( Access::restricted_users() );

		$line = sprintf(
			"define( '%s', '%s' );",
			Access::USERS_CONSTANT,
			$configured
				? implode( ',', array_merge( Access::restricted_users(), array( $user_id ) ) )
				: (string) $user_id
		);

		echo '<div class="notice notice-warning"><p><strong>';
		echo esc_html__( 'Copperleaf Deploy: Compare & Sync, Settings and Logs & Diagnostics are restricted.', 'ifs-deploy' );
		echo '</strong></p><p>';

		echo esc_html(
			$configured
				? sprintf(
					/* translators: %d: the current user's numeric WordPress user id */
					__( 'These screens are limited to named users, and your account (user ID %d) is not one of them. Being an administrator is not enough on its own — that is what this setting is for.', 'ifs-deploy' ),
					$user_id
				)
				: sprintf(
					/* translators: %s: the name of the wp-config.php constant */
					__( 'Nobody can reach them, because %s is not set in wp-config.php. The list decides who may change how deployment works, and it lives in a file rather than in the database so it cannot be altered from inside WordPress.', 'ifs-deploy' ),
					Access::USERS_CONSTANT
				)
		);

		echo '</p><p>';
		echo esc_html__( 'To allow yourself, add this line to wp-config.php above the “stop editing” comment, then reload:', 'ifs-deploy' );
		echo '</p><p><code>' . esc_html( $line ) . '</code></p>';

		echo '<p><em>';
		echo esc_html__( 'Everything else is unaffected: pending changes, pushing and rollback work exactly as before.', 'ifs-deploy' );
		echo '</em></p></div>';
	}

	/**
	 * Is this a user, and a screen, that should be told?
	 */
	private function applies(): bool {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) || current_user_can( Access::CAP_RESTRICTED ) ) {
			return false;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = $screen instanceof \WP_Screen ? (string) $screen->id : '';

		return 'plugins' === $id || false !== strpos( $id, AdminMenu::SLUG );
	}
}
