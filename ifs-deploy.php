<?php
/**
 * Plugin Name:       IFS Deploy
 * Plugin URI:        https://example.com/ifs-deploy
 * Description:       Deploy WordPress content from Staging to Production safely. Track changes, push selected content, and roll back.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            IFS Deploy
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ifs-deploy
 *
 * @package IFS_Deploy
 */

/*
 * NAMING — this plugin was renamed on 10 August 2026 and everything now says `ifs-deploy`.
 * Read this before touching any identifier below.
 *
 * ── UPGRADING FROM A PRE-RENAME INSTALL ────────────────────────────────────────
 *
 * Renaming the identifiers renamed where the DATA lives, so an existing install needs a
 * migration or it comes up looking brand new: credentials regenerated, pairing lost, rollback
 * points unreachable, and every page missing its `_ifs_deploy_origin_id` — which means the
 * next deploy would CREATE A DUPLICATE of it rather than updating it.
 *
 * `Support\LegacyRename` handles all of that, once, and runs before anything reads an option.
 * It must run on BOTH sites.
 *
 * ── AND WHY BOTH SITES MUST BE UPDATED TOGETHER ────────────────────────────────
 *
 * Two things here are WIRE FORMAT, shared between the pair:
 *
 *   - REST namespace `ifs-deploy/v1` — Staging signs requests to
 *     `…/wp-json/ifs-deploy/v1/import`. Update one side only and every deploy 404s.
 *   - The six `X-IFS-Deploy-*` headers — the verifier reads them by name.
 *
 * The v1/v2 protocol negotiation in SECURITY.md §11 does NOT cover this. That handles the
 * signature FORMAT and lets the pair upgrade itself in either order; it cannot help when the
 * URL path and the header names change, because the request never reaches the verifier.
 *
 * ── THE FORM EACH CONTEXT USES ─────────────────────────────────────────────────
 *
 * One spelling cannot serve all of them, so:
 *
 *   IfsDeploy       namespace, JS object      — a hyphen is not a valid identifier
 *   IFS_DEPLOY_     constants                 — same
 *   ifs_deploy_     options, tables, post meta, hooks, filters, nonces
 *                                             — `wp_ajax_ifs-deploy_x` matches no hook, and
 *                                               a hyphen in an unquoted SQL identifier is a
 *                                               syntax error
 *   ifs-deploy      text domain, REST namespace, page slug, CSS classes
 *   X-IFS-Deploy-   HTTP headers
 *   IFS Deploy      prose and labels
 *
 * The `dp-` CSS prefix and `dp_` transient prefix are deliberately unchanged: opaque
 * prefixes nobody reads as a brand, and renaming them would touch several hundred call sites
 * across PHP, CSS and JS for no visible gain.
 *
 * The text domain must match the wordpress.org slug to receive translations — so if this is
 * ever submitted under a different slug, that is the one identifier that has to move with it.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'IFS_DEPLOY_VERSION', '0.1.0' );
define( 'IFS_DEPLOY_DB_VERSION', '5' );
define( 'IFS_DEPLOY_FILE', __FILE__ );
define( 'IFS_DEPLOY_DIR', plugin_dir_path( __FILE__ ) );
define( 'IFS_DEPLOY_URL', plugin_dir_url( __FILE__ ) );

/*
 * PSR-4 autoloader for the IfsDeploy\ namespace.
 *
 * We register a lightweight loader instead of requiring `composer install` on the
 * server. composer.json still declares the same mapping for local dev tooling.
 */
spl_autoload_register(
	static function ( string $class ): void {
		$prefix   = 'IfsDeploy\\';
		$base_dir = IFS_DEPLOY_DIR . 'src/';

		$len = strlen( $prefix );
		if ( strncmp( $prefix, $class, $len ) !== 0 ) {
			return;
		}

		$relative = substr( $class, $len );
		$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( \IfsDeploy\Support\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \IfsDeploy\Support\Activator::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		\IfsDeploy\Plugin::instance()->boot();
	}
);
