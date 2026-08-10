<?php
declare(strict_types=1);

namespace IfsDeploy\Support;

/**
 * Typed accessor over plugin options.
 *
 * Role is a runtime concept: the same codebase runs on both sites. A "staging"
 * site pushes; a "production" site receives. V1 treats them as distinct.
 */
final class Config {

	public const ROLE_STAGING    = 'staging';
	public const ROLE_PRODUCTION = 'production';

	/** Timestamp tolerance (seconds) for signed requests. */
	public const TIMESTAMP_WINDOW = 300;

	/** Maximum stored revisions per object. */
	public const MAX_REVISIONS = 3;

	public static function role(): string {
		$role = (string) get_option( 'ifs_deploy_role', self::ROLE_STAGING );
		return self::ROLE_PRODUCTION === $role ? self::ROLE_PRODUCTION : self::ROLE_STAGING;
	}

	public static function is_production(): bool {
		return self::ROLE_PRODUCTION === self::role();
	}

	public static function is_staging(): bool {
		return self::ROLE_STAGING === self::role();
	}

	public static function set_role( string $role ): void {
		$role = self::ROLE_PRODUCTION === $role ? self::ROLE_PRODUCTION : self::ROLE_STAGING;
		update_option( 'ifs_deploy_role', $role );
	}

	/**
	 * Remote (Production) connection details stored on the Staging site.
	 *
	 * @return array{url:string,api_key:string,secret_key:string}
	 */
	public static function remote(): array {
		$defaults = array(
			'url'        => '',
			'api_key'    => '',
			'secret_key' => '',
		);

		$stored = get_option( 'ifs_deploy_remote', array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge( $defaults, $stored );
	}

	public static function set_remote( string $url, string $api_key, string $secret_key ): void {
		update_option(
			'ifs_deploy_remote',
			array(
				'url'        => untrailingslashit( esc_url_raw( $url ) ),
				'api_key'    => sanitize_text_field( $api_key ),
				'secret_key' => sanitize_text_field( $secret_key ),
			)
		);
	}

	/**
	 * Is this URL safe to send signed requests to? (SECURITY.md M-4)
	 *
	 * The HMAC protects INTEGRITY, not confidentiality. Over `http://`:
	 *
	 *  - every deployed page travels in cleartext;
	 *  - the API key travels in a request header in cleartext;
	 *  - and H-3 becomes trivial — an on-path attacker can forge Production's *responses*,
	 *    so Compare & Sync and the rollback preview can be made to show anything.
	 *
	 * Local development is exempted, because a `.test` host has no certificate and blocking
	 * it would make the plugin undevelopable rather than safer.
	 *
	 * @return array{ok:bool,url:string,error:string} `url` is the cleaned value to store.
	 */
	public static function validate_remote_url( string $url ): array {
		$clean = untrailingslashit( esc_url_raw( trim( $url ) ) );

		// Empty is allowed: it is how a Production-role site legitimately has no remote.
		if ( '' === $clean ) {
			return array( 'ok' => true, 'url' => '', 'error' => '' );
		}

		$scheme = (string) wp_parse_url( $clean, PHP_URL_SCHEME );
		$host   = (string) wp_parse_url( $clean, PHP_URL_HOST );

		if ( '' === $host ) {
			return array(
				'ok'    => false,
				'url'   => $clean,
				'error' => __( 'That does not look like a URL. Enter the full address, for example https://www.example.com', 'ifs-deploy' ),
			);
		}

		if ( 'https' === $scheme ) {
			return array( 'ok' => true, 'url' => $clean, 'error' => '' );
		}

		if ( self::allows_insecure_transport( $host ) ) {
			return array( 'ok' => true, 'url' => $clean, 'error' => '' );
		}

		return array(
			'ok'    => false,
			'url'   => $clean,
			'error' => __( 'The Production URL must use https. The signature protects the content from being altered, but not from being read — over http the deployed content and the API key both travel in the clear.', 'ifs-deploy' ),
		);
	}

	/**
	 * May this host be reached over plain http?
	 *
	 * True for the conventional local-development suffixes only. Anything else needs the
	 * filter, which exists so an unusual internal setup is a deliberate decision rather
	 * than something the plugin quietly permits.
	 */
	public static function allows_insecure_transport( string $host ): bool {
		$host  = strtolower( $host );
		$local = 'localhost' === $host
			|| '127.0.0.1' === $host
			|| '::1' === $host
			|| (bool) preg_match( '/\.(test|local|localhost|invalid|example)$/', $host );

		/**
		 * Filter whether plain http is acceptable for a given host.
		 *
		 * @param bool   $allowed
		 * @param string $host
		 */
		return (bool) apply_filters( 'ifs_deploy_allow_insecure_transport', $local, $host );
	}

	/**
	 * Is the CURRENTLY STORED remote URL insecure?
	 *
	 * Existing configurations are never rewritten or disabled — that would break a working
	 * pair on upgrade, which is exactly what must not happen. They are reported instead, on
	 * the Settings screen and in Diagnostics, so the owner can fix it deliberately.
	 */
	public static function remote_is_insecure(): bool {
		$url = (string) self::remote()['url'];

		if ( '' === $url ) {
			return false;
		}

		return 'https' !== (string) wp_parse_url( $url, PHP_URL_SCHEME )
			&& ! self::allows_insecure_transport( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	}

	/**
	 * System / transactional post types never auto-tracked.
	 *
	 * Media (attachment) is handled by the dedicated media pipeline, and
	 * WooCommerce orders/refunds are excluded on principle — IFS Deploy must
	 * never overwrite live transactional data.
	 *
	 * @var string[]
	 */
	private const EXCLUDED_POST_TYPES = array(
		'attachment',
		'revision',
		'nav_menu_item',
		'custom_css',
		'customize_changeset',
		'oembed_cache',
		'user_request',
		'wp_navigation',
		'wp_template',
		'wp_template_part',
		'wp_global_styles',
		'shop_order',
		'shop_order_refund',
		'scheduled-action',
	);

	/**
	 * Post types eligible for deployment.
	 *
	 * Covers every public post type (posts, pages, and any custom post types)
	 * plus Gutenberg reusable/global blocks (wp_block), minus the system and
	 * transactional types above.
	 *
	 * @return string[]
	 */
	public static function tracked_post_types(): array {
		$types = get_post_types( array( 'public' => true ), 'names' );

		// Reusable / global content blocks are not "public" but must be tracked.
		$types['wp_block'] = 'wp_block';

		$types = array_values( array_diff( $types, self::EXCLUDED_POST_TYPES ) );

		/**
		 * Filter the post types IFS Deploy tracks and deploys.
		 *
		 * Add project-specific CPTs, or remove any you don't want deployed.
		 *
		 * @param string[] $types
		 */
		return (array) apply_filters( 'ifs_deploy_tracked_post_types', $types );
	}

	/** Taxonomies never auto-tracked (menus handled separately; system taxonomies). */
	private const EXCLUDED_TAXONOMIES = array(
		'nav_menu',
		'link_category',
		'post_format',
		'wp_theme',
		'wp_template_part_area',
		'wp_pattern_category',
	);

	/**
	 * Taxonomies eligible for deployment: all public taxonomies minus system
	 * ones. Categories, tags, and custom taxonomies are included.
	 *
	 * @return string[]
	 */
	public static function tracked_taxonomies(): array {
		$taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
		$taxonomies = array_values( array_diff( $taxonomies, self::EXCLUDED_TAXONOMIES ) );

		/**
		 * Filter the taxonomies IFS Deploy tracks and deploys.
		 *
		 * @param string[] $taxonomies
		 */
		return (array) apply_filters( 'ifs_deploy_tracked_taxonomies', $taxonomies );
	}
}
