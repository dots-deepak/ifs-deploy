<?php
declare(strict_types=1);

namespace IfsDeploy\Support;

/**
 * Decides which options are safe to track and deploy.
 *
 * Strategy is DEFAULT-DENY: an option is only deployable if it matches a known
 * safe pattern (or is explicitly allowlisted via filter) AND is not on the
 * dangerous blocklist. This keeps IFS Deploy away from anything tied to files,
 * .htaccess, URLs, the active theme/plugins, or core infrastructure.
 *
 * The same check runs on BOTH sides — the importer re-validates before writing,
 * so a misconfigured or malicious push can never overwrite a protected option.
 */
final class OptionAllowlist {

	/**
	 * Never deployable — infrastructure, security, file/URL/rewrite related.
	 *
	 * @var string[]
	 */
	private const DANGEROUS = array(
		'siteurl',
		'home',
		'blog_charset',
		'template',
		'stylesheet',
		'current_theme',
		'template_root',
		'stylesheet_root',
		'active_plugins',
		'permalink_structure',
		'category_base',
		'tag_base',
		'rewrite_rules',
		'upload_path',
		'upload_url_path',
		'db_version',
		'initial_db_version',
		'secret',
		'auth_key',
		'auth_salt',
		'cron',
		'recovery_keys',
		'https_detection_errors',
		'fresh_site',
		'recently_activated',
		'user_roles',
		'user_count',
		'admin_email',
		'new_admin_email',
		'mailserver_url',
		'mailserver_login',
		'mailserver_pass',
		'mailserver_port',
		'users_can_register',
		'default_role',
		'wp_user_roles',
		'nonce_key',
		'nonce_salt',
	);

	/**
	 * Option-name prefixes considered safe content/settings.
	 *
	 * @var string[]
	 */
	private const SAFE_PREFIXES = array(
		'options_',       // ACF options page values.
		'_options_',      // ACF options page field-key references.
		'theme_mods_',    // Customizer theme mods (colors, logo id, nav locations…).
		'widget_',        // Widget instances (footer/sidebar content).
	);

	/**
	 * Exact option names considered safe.
	 *
	 * @var string[]
	 */
	private const SAFE_EXACT = array(
		'blogname',
		'blogdescription',
		'sidebars_widgets',
		'page_on_front',
		'page_for_posts',
		'show_on_front',
		'sticky_posts',
	);

	public static function is_allowed( string $option ): bool {
		if ( '' === $option ) {
			return false;
		}

		// Transients and site transients are caches — never deploy them.
		if ( 0 === strpos( $option, '_transient_' ) || 0 === strpos( $option, '_site_transient_' ) ) {
			return false;
		}

		// Dangerous options are refused even if a pattern would match them.
		if ( in_array( $option, self::DANGEROUS, true ) ) {
			return false;
		}

		$allowed = in_array( $option, self::SAFE_EXACT, true );

		if ( ! $allowed ) {
			foreach ( self::SAFE_PREFIXES as $prefix ) {
				if ( 0 === strpos( $option, $prefix ) ) {
					$allowed = true;
					break;
				}
			}
		}

		/**
		 * Filter whether an option is safe to track/deploy.
		 *
		 * Use this to add project-specific plugin settings by name. Dangerous
		 * core options above are always refused regardless of this filter.
		 *
		 * @param bool   $allowed
		 * @param string $option
		 */
		$allowed = (bool) apply_filters( 'ifs_deploy_option_is_allowed', $allowed, $option );

		// Belt and braces: the filter can widen the allowlist but never override
		// the dangerous blocklist.
		if ( in_array( $option, self::DANGEROUS, true ) ) {
			return false;
		}

		return $allowed;
	}
}
