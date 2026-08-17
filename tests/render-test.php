<?php
/**
 * Renders every tab for real and asserts the markup the tab shell depends on.
 *
 * The other suites read source text. That misses the whole class of bug where a page
 * calls a method that does not exist, or loses a `</div>` — both of which happened
 * while the six screens were being folded into one, and neither of which a text
 * assertion notices. This one actually invokes render() behind enough WordPress stubs
 * to reach the end, and fails on any PHP error.
 *
 * Repositories are stubbed to return empty sets and remote calls to fail, so nothing
 * here touches a database or a network. That is the point: it proves the markup is
 * well-formed on the paths a fresh, unconfigured install takes.
 */

$root = dirname( __DIR__ );

require __DIR__ . '/lib-css.php';
$pass = 0;
$fail = 0;

function ok( string $label, bool $condition ): bool {
	global $pass, $fail;

	if ( $condition ) {
		++$pass;
		echo "  PASS  $label\n";

		return true;
	}

	++$fail;
	echo "  FAIL  $label\n";

	return false;
}

// Any notice, warning or error is a failure — an undefined method or a bad array
// offset must not be allowed to hide behind a partially-rendered page.
$errors = array();
set_error_handler(
	static function ( int $no, string $message, string $file, int $line ) use ( &$errors ): bool {
		$errors[] = sprintf( '%s in %s:%d', $message, basename( $file ), $line );

		return true;
	}
);

/* -----------------------------------------------------------------------------
 * WordPress stubs. Only what the six render() paths actually reach.
 * -------------------------------------------------------------------------- */

define( 'IFS_DEPLOY_VERSION', '1.0.0-test' );
define( 'IFS_DEPLOY_DIR', $root . '/' );
define( 'IFS_DEPLOY_URL', 'http://staging.test/wp-content/plugins/ifs-deploy/' );
define( 'ARRAY_A', 'ARRAY_A' );

/*
 * WordPress time constants. PHP falls back to the global namespace for CONSTANTS (unlike
 * classes), so an unimported `DAY_IN_SECONDS` is correct in a real install — it is only
 * missing here, and its absence used to surface as a fatal in the Production render sweep
 * below that looked like a plugin bug.
 */
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );

$GLOBALS['dp_caps'] = array( 'manage_options' => true );

function current_user_can( string $cap ): bool {
	return ! empty( $GLOBALS['dp_caps'][ $cap ] ) || ! empty( $GLOBALS['dp_caps']['manage_options'] );
}

function __( string $text, string $domain = '' ): string {
	return $text;
}
function wp_parse_url( string $url, int $component = -1 ) {
	return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
}
function _n( string $single, string $plural, int $number, string $domain = '' ): string {
	return 1 === $number ? $single : $plural;
}
function esc_html( $text ): string {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_attr( $text ): string {
	return esc_html( $text );
}
function esc_url( $url ): string {
	return esc_html( $url );
}
function esc_js( $text ): string {
	return esc_html( $text );
}
function esc_html__( string $text, string $domain = '' ): string {
	return esc_html( $text );
}
function esc_attr__( string $text, string $domain = '' ): string {
	return esc_html( $text );
}
function esc_html_e( string $text, string $domain = '' ): void {
	echo esc_html( $text );
}
function esc_attr_e( string $text, string $domain = '' ): void {
	echo esc_html( $text );
}
function _e( string $text, string $domain = '' ): void {
	echo $text;
}
function _x( string $text, string $context, string $domain = '' ): string {
	return $text;
}
function wp_generate_uuid4(): string {
	return '00000000-0000-4000-8000-000000000000';
}
function wp_generate_password( int $length = 12, bool $special = true, bool $extra = false ): string {
	return str_repeat( 'a', $length );
}
function wp_next_scheduled( string $hook ) {
	return $GLOBALS["dp_cron"][ $hook ] ?? false;
}
function wp_schedule_event( int $when, string $r, string $hook ): bool {
	$GLOBALS["dp_cron"][ $hook ] = $when;

	return true;
}
function wp_unschedule_event( int $when, string $hook ): bool {
	unset( $GLOBALS["dp_cron"][ $hook ] );

	return true;
}
function wp_clear_scheduled_hook( string $hook ): void {
	unset( $GLOBALS["dp_cron"][ $hook ] );
}
function wp_parse_args( $args, $defaults = array() ): array {
	return array_merge( (array) $defaults, (array) $args );
}
function wp_rand( int $min = 0, int $max = 0 ): int {
	return $min;
}
function wp_hash( string $data, string $scheme = 'auth' ): string {
	return hash( 'sha256', $data );
}
function current_time( string $type = 'mysql', $gmt = 0 ) {
	return '2026-01-01 00:00:00';
}
function human_time_diff( int $from, int $to = 0 ): string {
	return '1 hour';
}
function size_format( $bytes, int $decimals = 0 ): string {
	return (string) $bytes . ' B';
}
function esc_textarea( $text ): string {
	return esc_html( $text );
}
function wp_kses_post( $text ): string {
	return (string) $text;
}
function sanitize_key( $key ): string {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) );
}
function sanitize_text_field( $str ): string {
	return trim( strip_tags( (string) $str ) );
}
function wp_unslash( $value ) {
	return $value;
}
function absint( $value ): int {
	return abs( (int) $value );
}
function home_url( string $path = '' ): string {
	return 'http://staging.test' . $path;
}
function admin_url( string $path = '' ): string {
	return 'http://staging.test/wp-admin/' . $path;
}
function add_query_arg( $args, $url = '' ) {
	if ( ! is_array( $args ) ) {
		$args = array( $args => func_get_arg( 1 ) );
		$url  = func_get_arg( 2 );
	}

	$sep = false === strpos( (string) $url, '?' ) ? '?' : '&';

	return $url . $sep . http_build_query( $args );
}
function get_option( string $name, $default = false ) {
	return $GLOBALS['dp_options'][ $name ] ?? $default;
}
function update_option( string $name, $value ): bool {
	$GLOBALS['dp_options'][ $name ] = $value;

	return true;
}
function get_userdata( int $id ) {
	return false;
}
function get_editable_roles(): array {
	return array(
		'administrator' => array( 'name' => 'Administrator' ),
		'editor'        => array( 'name' => 'Editor' ),
		'author'        => array( 'name' => 'Author' ),
	);
}
function translate_user_role( string $name ): string {
	return $name;
}

/**
 * WP_Roles stub. Administrator holds manage_options so `Access::is_admin_role()` marks
 * that row uneditable — the case that keeps an administrator from locking themselves out
 * of this screen, and therefore the one worth having in the fixture.
 */
class DP_Test_Roles {
	public function get_names(): array {
		return array(
			'administrator' => 'Administrator',
			'editor'        => 'Editor',
			'author'        => 'Author',
			'subscriber'    => 'Subscriber',
		);
	}

	public function get_role( string $role ) {
		if ( 'administrator' === $role ) {
			return (object) array( 'capabilities' => array( 'manage_options' => true ) );
		}

		if ( in_array( $role, array_keys( $this->get_names() ), true ) ) {
			return (object) array( 'capabilities' => array( 'read' => true ) );
		}

		return null;
	}
}

function wp_roles(): DP_Test_Roles {
	return new DP_Test_Roles();
}
function get_current_user_id(): int {
	return 1;
}
function wp_get_current_user() {
	return (object) array(
		'ID'           => 1,
		'display_name' => 'Tester',
		'user_email'   => 'tester@staging.test',
	);
}
function mysql2date( string $format, string $date ): string {
	return $date;
}
function wp_nonce_field( $action = '', $name = '_wpnonce', $referer = true, $display = true ) {
	$field = '<input type="hidden" name="' . esc_attr( $name ) . '" value="test-nonce" />';

	if ( $display ) {
		echo $field;
	}

	return $field;
}
/**
 * Mirrors core's get_submit_button() closely enough to be worth trusting.
 *
 * The `$other_attributes` array branch matters: real WP renders `array( 'form' => 'x' )`
 * as `form="x"`, and the Save Settings button depends on that to submit a form it sits
 * outside of. A stub that dropped the argument would let the test pass while the real
 * screen quietly posted nothing.
 */
function submit_button( $text = 'Save Changes', $type = 'primary', $name = 'submit', $wrap = true, $other = null ): void {
	$attributes = '';

	if ( is_array( $other ) ) {
		foreach ( $other as $attribute => $value ) {
			$attributes .= ' ' . $attribute . '="' . esc_attr( (string) $value ) . '"';
		}
	} elseif ( ! empty( $other ) ) {
		$attributes = ' ' . (string) $other;
	}

	printf(
		'%s<button type="submit" class="button button-%s" name="%s"%s>%s</button>%s',
		$wrap ? '<p class="submit">' : '',
		esc_attr( $type ),
		esc_attr( (string) $name ),
		$attributes,
		esc_html( (string) $text ),
		$wrap ? '</p>' : ''
	);
}
function checked( $checked, $current = true, $display = true ) {
	$result = (string) $checked === (string) $current ? ' checked="checked"' : '';

	if ( $display ) {
		echo $result;
	}

	return $result;
}
function selected( $selected, $current = true, $display = true ) {
	$result = (string) $selected === (string) $current ? ' selected="selected"' : '';

	if ( $display ) {
		echo $result;
	}

	return $result;
}
function disabled( $disabled, $current = true, $display = true ) {
	$result = (string) $disabled === (string) $current ? ' disabled="disabled"' : '';

	if ( $display ) {
		echo $result;
	}

	return $result;
}
function apply_filters( string $hook, $value ) {
	return $value;
}
function do_action( string $hook, ...$args ): void {}
function add_action( string $hook, $callback, int $priority = 10, int $args = 1 ): bool {
	$GLOBALS['dp_hooks'][] = $hook;

	return true;
}
function add_filter( string $hook, $callback, int $priority = 10, int $args = 1 ): bool {
	return true;
}
function wp_json_encode( $data, int $flags = 0 ) {
	return json_encode( $data, $flags );
}
function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}
function wp_remote_retrieve_body( $response ) {
	return '';
}
function wp_remote_retrieve_response_code( $response ): int {
	return 0;
}
function wp_remote_post( $url, $args = array() ) {
	return new WP_Error( 'http_request_failed', 'Stubbed: no network in tests.' );
}
function wp_remote_get( $url, $args = array() ) {
	return wp_remote_post( $url, $args );
}
function wp_upload_dir(): array {
	return array(
		'basedir' => sys_get_temp_dir(),
		'baseurl' => 'http://staging.test/wp-content/uploads',
		'error'   => false,
	);
}
function trailingslashit( string $value ): string {
	return rtrim( $value, '/\\' ) . '/';
}
function number_format_i18n( $number ) {
	return number_format( (float) $number );
}

class WP_Error {
	private string $code;
	private string $message;

	public function __construct( string $code = '', string $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_message(): string {
		return $this->message;
	}

	public function get_error_code(): string {
		return $this->code;
	}
}

/**
 * $wpdb stub. Every query returns nothing, which is the state of a fresh install and
 * the path most likely to divide by zero or index a missing array key.
 */
class DP_Test_WPDB {
	public string $prefix = 'wp_';

	public function get_results( $query, $output = null ): array {
		return array();
	}
	public function get_row( $query, $output = null ) {
		return null;
	}
	public function get_var( $query ) {
		return null;
	}
	public function get_col( $query ): array {
		return array();
	}
	public function prepare( string $query, ...$args ): string {
		return $query;
	}
	public function query( $query ): int {
		return 0;
	}
	public function insert( $table, $data, $format = null ): int {
		return 1;
	}
	public function update( $table, $data, $where, $f = null, $wf = null ): int {
		return 1;
	}
	public function delete( $table, $where, $format = null ): int {
		return 1;
	}
	public function esc_like( string $text ): string {
		return $text;
	}
}

$GLOBALS['wpdb'] = new DP_Test_WPDB();

/* -----------------------------------------------------------------------------
 * Autoloader + render
 * -------------------------------------------------------------------------- */

spl_autoload_register(
	static function ( string $class ) use ( $root ): void {
		if ( 0 !== strpos( $class, 'IfsDeploy\\' ) ) {
			return;
		}

		$path = $root . '/src/' . str_replace( '\\', '/', substr( $class, strlen( 'IfsDeploy\\' ) ) ) . '.php';

		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

/** Every tab, rendered through the same entry point the AJAX endpoint uses. */
$tabs = array( 'overview', 'pending', 'compare', 'history', 'settings', 'logs' );

echo "=== every tab renders without error ===\n";

$html = array();
foreach ( $tabs as $slug ) {
	$errors = array();

	try {
		$html[ $slug ] = IfsDeploy\Admin\Tabs::render( $slug );
		$threw         = '';
	} catch ( Throwable $e ) {
		$html[ $slug ] = '';
		$threw         = get_class( $e ) . ': ' . $e->getMessage();
	}

	ok( "$slug renders", '' === $threw ) or print( "        $threw\n" );

	if ( $errors ) {
		ok( "$slug raises no PHP diagnostics", false );
		foreach ( array_unique( $errors ) as $message ) {
			echo "        $message\n";
		}
	} else {
		ok( "$slug raises no PHP diagnostics", true );
	}

	ok( "$slug produces markup", '' !== trim( $html[ $slug ] ) );
}

echo "=== tab markup is well-formed ===\n";
foreach ( $tabs as $slug ) {
	$opens  = preg_match_all( '/<div\b/', $html[ $slug ] );
	$closes = preg_match_all( '#</div>#', $html[ $slug ] );

	// An unbalanced panel silently swallows the rest of the page when it is injected.
	ok( "$slug has balanced divs ($opens open, $closes close)", $opens === $closes );

	// The chrome belongs to Screen. A tab emitting either would duplicate an id or
	// nest a second .wrap inside the panel on every swap.
	ok( "$slug emits no wrapper", false === strpos( $html[ $slug ] , 'class="wrap' ) );
	ok( "$slug emits no notice slot", false === strpos( $html[ $slug ], 'id="ifs-deploy-notice"' ) );

	// Each tab must say what it is; the panel has no heading of its own.
	ok( "$slug has a section title", false !== strpos( $html[ $slug ], 'dp-section-title' ) );
}

echo "=== the full screen renders ===\n";
$errors = array();
$_GET   = array( 'page' => 'ifs-deploy' );

ob_start();
try {
	( new IfsDeploy\Admin\Screen() )->render();
	$threw = '';
} catch ( Throwable $e ) {
	$threw = get_class( $e ) . ': ' . $e->getMessage();
}
$screen = (string) ob_get_clean();

ok( 'Screen renders', '' === $threw ) or print( "        $threw\n" );
ok( 'Screen raises no PHP diagnostics', empty( $errors ) );
foreach ( array_unique( $errors ) as $message ) {
	echo "        $message\n";
}

ok( 'Screen has balanced divs', preg_match_all( '/<div\b/', $screen ) === preg_match_all( '#</div>#', $screen ) );
ok( 'Screen emits exactly one notice slot', 1 === substr_count( $screen, 'id="ifs-deploy-notice"' ) );
ok( 'Screen emits exactly one panel', 1 === substr_count( $screen, 'id="dp-tab-panel"' ) );
ok( 'Screen emits the wrap once', 1 === substr_count( $screen, 'wrap ifs-deploy' ) );
ok( 'Screen emits wp-header-end once', 1 === substr_count( $screen, 'wp-header-end' ) );
ok( 'Screen defaults to the overview tab', false !== strpos( $screen, 'data-active-tab="overview"' ) );
ok( 'Screen marks the active tab', 1 === substr_count( $screen, 'dp-tab is-active' ) );
// Scoped to the nav: the Overview panel's shortcut buttons carry data-tab too, which
// is deliberate — they switch tabs instead of reloading — so a document-wide count
// would not be measuring the tab bar.
preg_match( '#<nav class="dp-tabs".*?</nav>#s', $screen, $nav );
ok( 'Screen renders one tab per registry entry', count( $tabs ) === substr_count( $nav[0] ?? '', 'data-tab="' ) );

// And the shortcuts really are tab switchers rather than page loads.
ok( 'Overview shortcuts switch tabs', 2 <= substr_count( str_replace( $nav[0] ?? '', '', $screen ), 'data-tab="' ) );

echo "=== a delegated role sees only its tabs ===\n";
$GLOBALS['dp_caps'] = array( 'ifs_deploy_access' => true );
$_GET               = array( 'page' => 'ifs-deploy', 'tab' => 'settings' );

ob_start();
( new IfsDeploy\Admin\Screen() )->render();
$limited = (string) ob_get_clean();

// Settings is manage_options-only. Asking for it without that capability must fall back
// to a tab the user may open rather than render the settings form.
ok( 'settings tab is hidden from a delegated role', false === strpos( $limited, 'data-tab="settings"' ) );
ok( 'compare tab is hidden from a delegated role', false === strpos( $limited, 'data-tab="compare"' ) );
ok( 'logs tab is hidden from a delegated role', false === strpos( $limited, 'data-tab="logs"' ) );
ok( 'a permitted tab is shown instead', false !== strpos( $limited, 'data-tab="overview"' ) );
ok( 'the settings form is not rendered', false === strpos( $limited, 'name="secret_key"' ) );

// And directly through the AJAX entry point, which accepts any slug the client sends.
ok( 'Tabs::render refuses a forbidden slug', false !== strpos( IfsDeploy\Admin\Tabs::render( 'settings' ), 'dp-tab-error' ) );

echo "=== the settings forms still post what the handler reads ===\n";
//
// The role matrix checkboxes were restyled into switches. A switch is CSS over a real
// <input type="checkbox">, so the posted data must be byte-for-byte what it was — if a
// name were lost, permissions would silently stop saving with no error anywhere.
$GLOBALS['dp_caps'] = array( 'manage_options' => true );
$_GET               = array( 'page' => 'ifs-deploy', 'tab' => 'settings' );

$connection = IfsDeploy\Admin\Tabs::render( 'settings' );

$_GET  = array( 'page' => 'ifs-deploy', 'tab' => 'settings', 'section' => 'roles' );
$roles_html = IfsDeploy\Admin\Tabs::render( 'settings' );

foreach ( array( 'ifs_deploy_action', 'role', 'remote_url', 'remote_api_key', 'remote_secret_key' ) as $name ) {
	ok( "connection form posts $name", false !== strpos( $connection, 'name="' . $name . '"' ) );
}

// One switch per role × permission, with the array name the handler indexes by.
ok( 'role matrix posts roles[…][…]', (bool) preg_match( '/name="roles\[[a-z_]+\]\[[a-z_]+\]"/', $roles_html ) );
ok( 'role matrix posts deploy permission', false !== strpos( $roles_html, '[ifs_deploy_deploy]' ) );

// The hidden `dp_users_allow[]` inputs live inside the chips, and a chip only exists once
// a user has been picked — with an empty fixture there are none. So what is asserted here
// is the picker each chip is appended into, keyed by the field name admin.js reads from
// `data-field` when it builds a chip client-side.
foreach ( array( 'dp_users_allow', 'dp_users_block' ) as $field ) {
	ok( "the $field picker is rendered", false !== strpos( $roles_html, 'data-field="' . $field . '"' ) );
}
ok( 'pickers expose a chip list for the script to append to', 2 === substr_count( $roles_html, 'ifs-deploy-chips' ) );

ok( 'allow-caps switches post dp_users_allow_caps[…]', (bool) preg_match( '/name="dp_users_allow_caps\[[a-z_]+\]"/', $roles_html ) );

// Every switch must remain a real checkbox with value="1"; the handler treats presence
// as "on", so a switch that posted nothing would read as off.
ok( 'switches are real checkboxes', substr_count( $roles_html, 'type="checkbox" name="roles[' ) > 0 );
ok( 'switches carry value="1"', (bool) preg_match( '/name="roles\[[a-z_]+\]\[[a-z_]+\]" value="1"/', $roles_html ) );

// Administrator rows stay disabled — that is what stops an admin locking themselves out.
ok( 'administrator switches are disabled', false !== strpos( $roles_html, 'disabled' ) );

// The visibility classes admin.js toggles must survive the layout change.
ok( 'staging-only blocks still marked', false !== strpos( $connection, 'ifs-deploy-staging-only' ) );
ok( 'production-only blocks still marked', false !== strpos( $connection, 'ifs-deploy-production-only' ) );
ok( 'test-connection button intact', false !== strpos( $connection, 'id="ifs-deploy-test-connection"' ) );
ok( 'test-result target intact', false !== strpos( $connection, 'id="ifs-deploy-test-result"' ) );
ok( 'role radios still named "role"', false !== strpos( $connection, 'name="role"' ) );

// Inner sections must not reintroduce tab= (it now selects the top-level tab).
ok( 'section links use section=', false !== strpos( $connection, 'section=roles' ) );

/* -----------------------------------------------------------------------------
 * Every screen, under BOTH site roles
 *
 * Reported symptom: clicking "Log Retention" showed "Request failed. Please try again."
 *
 * Cause: `SettingsPage` called `ContentFirewall::mode()` without importing it, so PHP
 * resolved it as `IfsDeploy\Admin\Pages\ContentFirewall` and fatalled. Only on the
 * PRODUCTION path — the Staging branch returns before that line — and the screen is loaded
 * over AJAX, so the fatal arrived as a broken JSON response rather than a visible PHP error.
 *
 * Every render assertion in this file ran as Staging, which is exactly why a whole pass went
 * green while the real thing was a 500. This sweep exists so a Production-only path can never
 * again be the untested one. `missing-imports.php` catches the specific defect statically;
 * this catches whatever the next one turns out to be.
 * -------------------------------------------------------------------------- */

echo "=== every tab renders under both roles ===\n";

foreach ( array( 'staging', 'production' ) as $role ) {
	foreach ( array( 'overview', 'pending', 'compare', 'history', 'settings', 'logs' ) as $slug ) {
		// Settings has three inner sections, and only one of them was ever exercised.
		$sections = 'settings' === $slug ? array( 'connection', 'roles', 'logs' ) : array( '' );

		foreach ( $sections as $section ) {
			$GLOBALS['dp_caps']    = array( 'manage_options' => true );
			$GLOBALS['dp_options'] = array( 'ifs_deploy_role' => $role );

			$_GET = array( 'page' => 'ifs-deploy', 'tab' => $slug );
			if ( '' !== $section ) {
				$_GET['section'] = $section;
			}

			$errors = array();
			$label  = $role . ' / ' . $slug . ( '' !== $section ? ' / ' . $section : '' );

			ob_start();
			try {
				$out   = IfsDeploy\Admin\Tabs::render( $slug );
				$threw = '';
			} catch ( Throwable $e ) {
				$out   = '';
				$threw = get_class( $e ) . ': ' . $e->getMessage() . ' at ' . basename( $e->getFile() ) . ':' . $e->getLine();
			}
			ob_end_clean();

			ok( "$label renders", '' === $threw ) or print( "        $threw\n" );
			ok( "$label produces markup", '' !== trim( (string) $out ) );

			if ( $errors ) {
				ok( "$label raises no PHP diagnostics", false );
				foreach ( array_unique( $errors ) as $message ) {
					echo "        $message\n";
				}
			} else {
				ok( "$label raises no PHP diagnostics", true );
			}
		}
	}
}

echo "=== the Log Retention section renders ===\n";
//
// A new Settings section: rendered for real, because the earlier bug where roles_tab()
// was never exercised (the default section is `connection`) hid a fatal for a whole pass.
$GLOBALS['dp_caps']    = array( 'manage_options' => true );
$GLOBALS['dp_options'] = array();
$_GET                  = array( 'page' => 'ifs-deploy', 'tab' => 'settings', 'section' => 'logs' );
$errors                = array();

$logs_section = IfsDeploy\Admin\Tabs::render( 'settings' );

ok( 'it renders without error', '' !== trim( $logs_section ) );
ok( 'it raises no PHP diagnostics', empty( $errors ) );
foreach ( array_unique( $errors ) as $message ) {
	echo "        $message\n";
}

ok( 'the section is reachable from the subnav', false !== strpos( $logs_section, 'section=logs' ) );
ok( 'it posts the save_logs action', false !== strpos( $logs_section, 'value="save_logs"' ) );
ok( 'it renders the retention select', false !== strpos( $logs_section, 'name="log_retention_days"' ) );

// Every offered period must be present, and the default pre-selected.
foreach ( array_keys( IfsDeploy\Support\LogRetention::choices() ) as $days ) {
	ok( "the $days-day option is offered", false !== strpos( $logs_section, 'value="' . (int) $days . '"' ) );
}
ok( 'the current period is pre-selected', (bool) preg_match( '/value="30"[^>]*selected/', $logs_section ) );

// The reassurance that rollback is unaffected is load-bearing copy, not decoration:
// without it, pruning history reads as "I can no longer undo a deployment".
ok( 'it states that rollback points are untouched', false !== strpos( $logs_section, 'Rollback restore points are NOT affected' ) );

// Divs must balance or the injected panel swallows the rest of the page.
ok(
	'its markup is balanced',
	preg_match_all( '/<div\b/', $logs_section ) === preg_match_all( '#</div>#', $logs_section )
);

echo "=== the plugin is called IFS Deploy (display only) ===\n";
//
// The DISPLAY name changed. Every INTERNAL identifier deliberately did not: renaming the
// REST namespace, option keys or tables breaks a live paired installation. These
// assertions pin both halves of that, so a later "let's finish the rename" cannot quietly
// take out a working install.
$GLOBALS['dp_caps'] = array( 'manage_options' => true );
$_GET               = array( 'page' => 'ifs-deploy' );

ob_start();
( new IfsDeploy\Admin\Screen() )->render();
$branded = (string) ob_get_clean();

$all_tabs = '';
foreach ( array( 'overview', 'pending', 'compare', 'history', 'settings', 'logs' ) as $slug ) {
	$_GET      = array( 'page' => 'ifs-deploy', 'tab' => $slug );
	$all_tabs .= IfsDeploy\Admin\Tabs::render( $slug );
}

// Nothing a user reads should still say the old name. `.dp-id`/class attributes are
// lower-case `ifs-deploy`, so a case-sensitive search finds only prose and labels.
ok( 'no visible "IFS Deploy" in the screen chrome', false === strpos( $branded, 'IfsDeploy' ) );
ok( 'no visible "IFS Deploy" in any tab', false === strpos( $all_tabs, 'IfsDeploy' ) );

// The heading is a TEXT wordmark; `.dp-brand-name` is the CSS/selector hook and is
// asserted separately from what sits inside it, so the hook survives a change of mind
// about the mark.
ok( 'brand element is still .dp-brand-name', false !== strpos( $branded, 'class="dp-brand-name"' ) );
ok( 'the wordmark reads "IFS Deploy"', false !== strpos( $branded, '<span class="dp-brand-name">IFS Deploy</span>' ) );
ok( 'the header pulls in no image', 0 === preg_match( '/class="dp-brand-name"><img/', $branded ) );

echo "=== the sidebar icon costs no request and no site-wide CSS ===\n";
//
// The icon is core's `dashicons-migrate` glyph rather than an image URL, and that is
// what keeps this whole area free of the two bugs a PNG icon shipped last time:
//
//  1. WordPress does NOT size a PNG menu icon. Core's only rule is
//     `#adminmenu .wp-menu-image img { padding: 9px 0 0; opacity: .6 }` — no width, no
//     height — so the file renders at natural size and overflows the 36x34 container.
//     (`background-size: 20px auto` applies only to `div.wp-menu-image.svg`, i.e. SVG
//     data-URI icons.) Correcting it needs CSS on EVERY admin page, which is exactly
//     what `admin.css` is scoped to avoid.
//  2. A fixed-colour mark is wrong in some colour scheme or other — every built-in
//     scheme except `light` has a dark sidebar. A Dashicons glyph inherits the scheme,
//     including its hover and current states.
//
// Source is read through php_strip_whitespace() so the comments explaining these very
// strings cannot satisfy the assertions themselves.
$menu_src   = (string) php_strip_whitespace( $root . '/src/Admin/AdminMenu.php' );
$assets_src = (string) php_strip_whitespace( $root . '/src/Admin/Assets.php' );

ok( 'the menu icon is a Dashicons glyph', false !== strpos( $menu_src, "'dashicons-migrate'" ) );
ok( 'no image asset is referenced for it', false === strpos( $menu_src, 'assets/images' ) );
ok( 'no artwork is bundled at all', ! is_dir( $root . '/assets/images' ) );

// Nothing of ours may load on every admin page.
$GLOBALS['dp_hooks'] = array();
( new IfsDeploy\Admin\AdminMenu() )->register();
ok( 'nothing is hooked on admin_head', ! in_array( 'admin_head', $GLOBALS['dp_hooks'], true ) );
ok( 'assets enqueue on admin_enqueue_scripts', in_array( 'admin_enqueue_scripts', $GLOBALS['dp_hooks'], true ) );
ok( 'Assets prints no site-wide style', false === strpos( $assets_src, 'menu_icon_style' ) );

// --- and the identifiers that MUST NOT have changed -------------------------
//
// Each of these would break the live pair between two sites if renamed.
$plugin_file = (string) file_get_contents( $root . '/ifs-deploy.php' );

ok( 'plugin display name is IFS Deploy', false !== strpos( $plugin_file, 'Plugin Name:       IFS Deploy' ) );
ok( 'text domain is unchanged', false !== strpos( $plugin_file, 'Text Domain:       ifs-deploy' ) );
ok( 'REST namespace is unchanged', false !== strpos( (string) file_get_contents( $root . '/src/Rest/RestController.php' ), 'ifs-deploy/v1' ) );
ok( 'admin page slug is unchanged', "ifs-deploy" === IfsDeploy\Admin\AdminMenu::SLUG );
ok( 'the role option key is unchanged', false !== strpos( (string) file_get_contents( $root . '/src/Support/Config.php' ), "'ifs_deploy_role'" ) );

$schema = (string) file_get_contents( $root . '/src/Support/Schema.php' );
ok( 'the queue table is unchanged', false !== strpos( $schema, 'ifs_deploy_queue' ) );
ok( 'the deployments table is unchanged', false !== strpos( $schema, 'ifs_deploy_deployments' ) );

// The CSS scope class and the localised JS object are what admin.css and admin.js key on.
ok( 'the CSS scope class is unchanged', false !== strpos( $branded, 'class="wrap ifs-deploy' ) );
ok( 'the JS object name is unchanged', false !== strpos( (string) file_get_contents( $root . '/src/Admin/Assets.php' ), "'IfsDeploy'" ) );

// The HMAC headers are the sharpest edge in the whole rename. Every signed request
// carries them; rename one on either site and the pair stops authenticating instantly,
// with a signature error rather than anything that points at the cause.
$signer = (string) file_get_contents( $root . '/src/Auth/Signer.php' );
foreach ( array( 'Key', 'Timestamp', 'Nonce', 'Signature' ) as $header ) {
	ok( "the X-IFS-Deploy-$header header is unchanged", false !== strpos( $signer, "'X-IFS-Deploy-$header'" ) );
}

// Paths come from __FILE__, so renaming the plugin FOLDER cannot break asset URLs.
$boot = (string) file_get_contents( $root . '/ifs-deploy.php' );
ok( 'DIR is derived from __FILE__', false !== strpos( $boot, 'plugin_dir_path( __FILE__ )' ) );
ok( 'URL is derived from __FILE__', false !== strpos( $boot, 'plugin_dir_url( __FILE__ )' ) );
ok( 'no path is hard-coded to the folder name', false === strpos( $boot, "'ifs-deploy/" ) );

echo "=== credentials belong to Production only ===\n";
//
// On a Staging site your OWN keys are noise: you paste the other site's keys in above.
// Showing them there actively invites pasting the wrong pair, so the whole block —
// heading, values and the Regenerate button — is Production-only.
//
// Both roles are rendered here because the initial visibility is server-side (an inline
// style, so there is no flash before admin.js runs) and only the *changes* are JS. A test
// that rendered one role would miss half the behaviour.
$_GET = array( 'page' => 'ifs-deploy', 'tab' => 'settings' );

/** @return string The connection section as rendered under the given site role. */
$render_as = static function ( string $role ): string {
	$GLOBALS['dp_options']['ifs_deploy_role'] = $role;

	return IfsDeploy\Admin\Tabs::render( 'settings' );
};

$as_staging = $render_as( 'staging' );
$as_prod    = $render_as( 'production' );

// Everything that must hide is inside a container carrying the class admin.js toggles.
foreach ( array( 'credentials heading', 'Site ID field', 'Regenerate form' ) as $i => $what ) {
	unset( $i, $what );
}

// Staging: rendered, but pre-hidden — so switching to Production needs no fetch, and
// switching back cannot briefly flash the keys.
ok(
	'on Staging the credentials block is pre-hidden',
	(bool) preg_match( '/<div class="ifs-deploy-production-only" style="display:none;">\s*<div class="dp-block-heading">/', $as_staging )
);
ok(
	'on Staging the Regenerate form is pre-hidden',
	(bool) preg_match( '/<form[^>]*ifs-deploy-production-only[^>]*style="display:none;"/', $as_staging )
);
ok( 'on Staging Test Connection is visible', (bool) preg_match( '/<span class="ifs-deploy-staging-only">/', $as_staging ) );

// Production: the reverse.
ok(
	'on Production the credentials block is visible',
	(bool) preg_match( '/<div class="ifs-deploy-production-only">\s*<div class="dp-block-heading">/', $as_prod )
);
ok( 'on Production the Regenerate form is visible', ! preg_match( '/<form[^>]*ifs-deploy-production-only[^>]*style="display:none;"/', $as_prod ) );
ok( 'on Production the remote fields are pre-hidden', (bool) preg_match( '/class="ifs-deploy-staging-only" style="display:none;"/', $as_prod ) );

// The two buttons share one row. They post DIFFERENT actions, so they cannot share a
// form — Save Settings sits outside its form and targets it by id. If that attribute is
// lost, the button silently posts nothing and settings stop saving.
ok( 'Save Settings targets the connection form by id', false !== strpos( $as_prod, 'form="dp-connection-form"' ) );
ok( 'the connection form carries that id', false !== strpos( $as_prod, 'id="dp-connection-form"' ) );

// Both submit buttons inside the one actions row, in order.
$row_start = strpos( $as_prod, '<div class="dp-form-actions">' );
$row       = false !== $row_start ? substr( $as_prod, $row_start ) : '';

ok( 'Save Settings is in the actions row', false !== strpos( $row, 'Save Settings' ) );
ok( 'Regenerate Credentials is in the same row', false !== strpos( $row, 'Regenerate Credentials' ) );
ok(
	'Save comes before Regenerate',
	'' !== $row && strpos( $row, 'Save Settings' ) < strpos( $row, 'Regenerate Credentials' )
);

// Forms must not nest — the browser drops the inner one, which would make Regenerate a
// no-op. The connection form has to close before the Regenerate form opens.
$close_connection = strpos( $as_prod, '</form>' );
$open_regenerate  = strpos( $as_prod, 'dp-inline-form' );
ok( 'the connection form closes before Regenerate opens', $close_connection < $open_regenerate );
ok( 'no nested form', ! preg_match( '/<form[^>]*>(?:(?!<\/form>).)*<form/s', $as_prod ) );

// Each form still posts its own action.
ok( 'save action still posted', false !== strpos( $as_prod, 'value="save_settings"' ) );
ok( 'regenerate action still posted', false !== strpos( $as_prod, 'value="regenerate"' ) );

$GLOBALS['dp_options']['ifs_deploy_role'] = 'staging';

echo "=== old submenu URLs still resolve ===\n";
//
// This bit is a hook-ordering trap, and it cost a real 403 in the browser.
// wp-admin/admin.php loads wp-admin/menu.php — which runs user_can_access_admin_page()
// and wp_die()s on failure — BEFORE it fires `admin_init`. A redirect hooked on
// admin_init therefore never runs for a page that is no longer registered, which is
// exactly the case here. `admin_page_access_denied` fires immediately before that
// wp_die and is the hook core provides for it.
$GLOBALS['dp_hooks'] = array();
( new IfsDeploy\Admin\AdminMenu() )->register();

ok(
	'redirect hooks admin_page_access_denied',
	in_array( 'admin_page_access_denied', $GLOBALS['dp_hooks'], true )
);
ok(
	'redirect does NOT hook admin_init (fires after the wp_die)',
	! in_array( 'admin_init', $GLOBALS['dp_hooks'], true )
);

$map = array(
	'ifs-deploy-pending'  => 'tab=pending',
	'ifs-deploy-compare'  => 'tab=compare',
	'ifs-deploy-history'  => 'tab=history',
	'ifs-deploy-settings' => 'tab=settings',
	'ifs-deploy-logs'     => 'tab=logs',
);

foreach ( $map as $slug => $expected ) {
	$url = IfsDeploy\Admin\AdminMenu::legacy_tab_url( array( 'page' => $slug ) );

	ok( "$slug redirects to $expected", is_string( $url ) && false !== strpos( $url, $expected ) );
	ok( "$slug redirect targets the single page", is_string( $url ) && false !== strpos( $url, 'page=ifs-deploy&' ) );
}

// Anything that is not one of ours must be left alone, or this hook would hijack every
// denied admin page in the site.
ok( 'unrelated denied page is ignored', null === IfsDeploy\Admin\AdminMenu::legacy_tab_url( array( 'page' => 'woocommerce-reports' ) ) );
ok( 'no page arg is ignored', null === IfsDeploy\Admin\AdminMenu::legacy_tab_url( array() ) );

// The plugin's own slug must NOT be in the map, or a user who genuinely lacks access
// would be redirected to it, denied again, and loop.
ok( 'the new slug is not redirected (no loop)', null === IfsDeploy\Admin\AdminMenu::legacy_tab_url( array( 'page' => 'ifs-deploy' ) ) );

// Filters and inner sections survive the hop.
$filtered = (string) IfsDeploy\Admin\AdminMenu::legacy_tab_url(
	array(
		'page' => 'ifs-deploy-pending',
		'user' => '7',
	)
);
ok( 'the pending user filter is preserved', false !== strpos( $filtered, 'user=7' ) );

// `tab=roles` was Settings' INNER tab; it has to become `section=roles`, not be read as
// a top-level tab named "roles".
$roles = (string) IfsDeploy\Admin\AdminMenu::legacy_tab_url(
	array(
		'page' => 'ifs-deploy-settings',
		'tab'  => 'roles',
	)
);
ok( 'old tab=roles becomes section=roles', false !== strpos( $roles, 'section=roles' ) );
ok( 'old tab=roles lands on the settings tab', false !== strpos( $roles, 'tab=settings' ) );

/* -----------------------------------------------------------------------------
 * Production hides what it cannot do
 *
 * A shared install has one Production site that several people open. Every screen that
 * reads Staging-only data was showing them an empty table with no explanation — the queue
 * is filled by editing hooks on Staging, and deployment rows are written by
 * `Client\DeploymentService`, which never runs on the receiver.
 *
 * This is a DISPLAY decision throughout, and the assertions are written to keep it that
 * way: stored roles are untouched, `Support\Access` still enforces them on both sites, and
 * every capability check stays where it was. Hiding a tab is not an access control.
 * -------------------------------------------------------------------------- */

/** Render the whole screen under a role, with a given tab requested. */
function screen_as( string $role, string $tab = '', array $extra = array() ): string {
	$GLOBALS['dp_caps']    = array( 'manage_options' => true );
	$GLOBALS['dp_options'] = array( 'ifs_deploy_role' => $role );

	$_GET = array_merge( array( 'page' => 'ifs-deploy' ), '' !== $tab ? array( 'tab' => $tab ) : array(), $extra );

	ob_start();
	( new IfsDeploy\Admin\Screen() )->render();

	return (string) ob_get_clean();
}

echo "=== Staging-only tabs are absent on Production ===\n";

$GLOBALS['dp_caps']    = array( 'manage_options' => true );
$GLOBALS['dp_options'] = array( 'ifs_deploy_role' => 'production' );

$prod_tabs = array_keys( IfsDeploy\Admin\Tabs::available() );

ok( 'Pending Changes is hidden', ! in_array( 'pending', $prod_tabs, true ) );
ok( 'Compare & Sync is hidden', ! in_array( 'compare', $prod_tabs, true ) );
ok( 'Deployment History is hidden', ! in_array( 'history', $prod_tabs, true ) );
ok( 'Overview remains', in_array( 'overview', $prod_tabs, true ) );
ok( 'Settings remains', in_array( 'settings', $prod_tabs, true ) );
ok( 'Logs & Diagnostics remains', in_array( 'logs', $prod_tabs, true ) );

$GLOBALS['dp_options'] = array( 'ifs_deploy_role' => 'staging' );
$stag_tabs             = array_keys( IfsDeploy\Admin\Tabs::available() );

// The other half of the claim: nothing was removed from the side that uses it.
ok( 'Staging still shows all six', 6 === count( $stag_tabs ) );
foreach ( array( 'pending', 'compare', 'history' ) as $slug ) {
	ok( "Staging still shows $slug", in_array( $slug, $stag_tabs, true ) );
}

echo "=== the tab bar and its shortcuts agree with that ===\n";

$prod_screen = screen_as( 'production' );

ok( 'the bar has three tabs', 3 === preg_match_all( '/class="dp-tab[ "]/', $prod_screen ) );
ok( 'no Pending Changes tab', false === strpos( $prod_screen, 'data-tab="pending"' ) );
ok( 'no Compare & Sync tab', false === strpos( $prod_screen, 'data-tab="compare"' ) );
ok( 'no Deployment History tab', false === strpos( $prod_screen, 'data-tab="history"' ) );

// Overview's shortcut buttons are separate markup from the tab bar, and a button leading
// to "there is nothing to show here" is worse than no button.
ok( 'Overview offers no pending shortcut', false === strpos( $prod_screen, 'View Pending Changes' ) );
ok( 'Overview offers no history shortcut', false === strpos( $prod_screen, '>Deployment History<' ) );
ok( 'Overview still offers Settings', false !== strpos( $prod_screen, 'data-tab="settings"' ) );

// Cards that count Staging-only things would read "0" and "Never" forever.
ok( 'no Pending Changes card', false === strpos( $prod_screen, 'Pending Changes' ) );
ok( 'no Last Deployment card', false === strpos( $prod_screen, 'Last Deployment' ) );
ok( 'the Role card stays', false !== strpos( $prod_screen, 'Production' ) );

$stag_screen = screen_as( 'staging' );

ok( 'Staging keeps six tabs', 6 === preg_match_all( '/class="dp-tab[ "]/', $stag_screen ) );
ok( 'Staging keeps the pending shortcut', false !== strpos( $stag_screen, 'View Pending Changes' ) );
ok( 'Staging keeps the pending card', false !== strpos( $stag_screen, 'Pending Changes' ) );

echo "=== an old link to a hidden tab explains itself ===\n";

$GLOBALS['dp_options'] = array( 'ifs_deploy_role' => 'production' );

// A bookmark, or a link in an old email. It must land somewhere sensible…
ok( 'a hidden tab resolves to the default', 'overview' === IfsDeploy\Admin\Tabs::resolve( 'history' ) );
ok( 'a visible tab still resolves to itself', 'logs' === IfsDeploy\Admin\Tabs::resolve( 'logs' ) );

// …and if the content is fetched directly (Ajax::tab validates against all(), then calls
// render()), the reason must be the RIGHT one. Telling someone they lack permission when
// they do not is how a support question starts.
$refused = IfsDeploy\Admin\Tabs::render( 'history' );

ok( 'it says the section belongs to Staging', false !== strpos( $refused, 'belongs to the Staging site' ) );
ok( 'and does NOT claim a permission problem', false === strpos( $refused, 'do not have permission' ) );

// The capability path must still say what it always said.
$GLOBALS['dp_caps'] = array();
ok( 'a real permission refusal is unchanged', false !== strpos( IfsDeploy\Admin\Tabs::render( 'settings' ), 'do not have permission' ) );

echo "=== Role Management is hidden from Production's Settings ===\n";

$GLOBALS['dp_caps']    = array( 'manage_options' => true );
$GLOBALS['dp_options'] = array( 'ifs_deploy_role' => 'production' );
$_GET                  = array( 'page' => 'ifs-deploy', 'tab' => 'settings' );

$prod_settings = IfsDeploy\Admin\Tabs::render( 'settings' );

ok( 'the subnav omits Role Management', false === strpos( $prod_settings, 'section=roles' ) );
ok( 'Connection Settings stays', false !== strpos( $prod_settings, 'section=connection' ) );
ok( 'Log Retention stays', false !== strpos( $prod_settings, 'section=logs' ) );

// An old link to section=roles must fall back rather than render a section the subnav does
// not offer — which would look like a bug from either direction.
$_GET               = array( 'page' => 'ifs-deploy', 'tab' => 'settings', 'section' => 'roles' );
$prod_roles_attempt = IfsDeploy\Admin\Tabs::render( 'settings' );

ok( 'section=roles falls back to Connection', false !== strpos( $prod_roles_attempt, 'value="save_settings"' ) );
ok( 'and the roles matrix is not rendered', false === strpos( $prod_roles_attempt, 'value="save_roles"' ) );

// Staging is untouched.
$GLOBALS['dp_options'] = array( 'ifs_deploy_role' => 'staging' );
$_GET                  = array( 'page' => 'ifs-deploy', 'tab' => 'settings', 'section' => 'roles' );

$stag_roles = IfsDeploy\Admin\Tabs::render( 'settings' );

ok( 'Staging still offers Role Management', false !== strpos( $stag_roles, 'section=roles' ) );
ok( 'and still renders the matrix', false !== strpos( $stag_roles, 'value="save_roles"' ) );

echo "=== Logs & Diagnostics drops what Production cannot have ===\n";

/*
 * Seeded with one entry per level. The filter below is the point of the exercise, so a log
 * that contained only one level could not distinguish "filtered correctly" from "empty".
 */
$seed_log = array(
	array( 'time' => '2026-08-10 10:00:00', 'level' => 'error',   'role' => 'production', 'message' => 'SEEDED-ERROR',   'context' => array() ),
	array( 'time' => '2026-08-10 09:00:00', 'level' => 'warning', 'role' => 'production', 'message' => 'SEEDED-WARNING', 'context' => array() ),
	array( 'time' => '2026-08-10 08:00:00', 'level' => 'info',    'role' => 'production', 'message' => 'SEEDED-INFO',    'context' => array() ),
);

$GLOBALS['dp_options'] = array(
	'ifs_deploy_role'      => 'production',
	'ifs_deploy_debug_log' => $seed_log,
);
$_GET = array( 'page' => 'ifs-deploy', 'tab' => 'logs' );

$prod_logs = IfsDeploy\Admin\Tabs::render( 'logs' );

// Deployment rows are never written on the receiver, so this was a heading followed by
// "No failures" — permanently, whatever happened.
ok( 'no deployment-failure section', false === strpos( $prod_logs, 'Recent deployment failures' ) );
ok( 'API access is still there', false !== strpos( $prod_logs, 'API access' ) );

/*
 * The event log is NOT hidden, and that is deliberate. Each site keeps its own, and
 * Production's holds events Staging never sees — content firewall reports, unexpected-address
 * warnings, the repeated-rejection alert, lockouts, import failures. Hiding the section would
 * make the monitoring unobservable on the one site that does the monitoring.
 */
ok( 'the event log is still shown', false !== strpos( $prod_logs, 'Event log' ) );
ok( 'errors are shown', false !== strpos( $prod_logs, 'SEEDED-ERROR' ) );
ok( 'warnings are shown', false !== strpos( $prod_logs, 'SEEDED-WARNING' ) );
ok( 'routine entries are not', false === strpos( $prod_logs, 'SEEDED-INFO' ) );

// A log that quietly omits entries is worse than a long one.
ok( 'the filter is stated', false !== strpos( $prod_logs, 'warnings and errors only' ) );
ok( 'the hidden count is named', (bool) preg_match( '/1 routine entry is hidden/', $prod_logs ) );
ok( 'and there is a way to see everything', false !== strpos( $prod_logs, 'dp_log=all' ) );

$_GET          = array( 'page' => 'ifs-deploy', 'tab' => 'logs', 'dp_log' => 'all' );
$prod_logs_all = IfsDeploy\Admin\Tabs::render( 'logs' );

ok( 'showing everything really shows it', false !== strpos( $prod_logs_all, 'SEEDED-INFO' ) );

// Staging is the working surface: unfiltered, and it keeps the failure detail.
$GLOBALS['dp_options'] = array(
	'ifs_deploy_role'      => 'staging',
	'ifs_deploy_debug_log' => $seed_log,
);
$_GET = array( 'page' => 'ifs-deploy', 'tab' => 'logs' );

$stag_logs = IfsDeploy\Admin\Tabs::render( 'logs' );

ok( 'Staging keeps the deployment-failure section', false !== strpos( $stag_logs, 'Recent deployment failures' ) );
ok( 'Staging shows routine entries too', false !== strpos( $stag_logs, 'SEEDED-INFO' ) );
ok( 'and states no filter', false === strpos( $stag_logs, 'warnings and errors only' ) );

/* -----------------------------------------------------------------------------
 * Overview says which site you are looking at
 *
 * The same plugin renders the same screens on both sites, and a "Role: Production" card was
 * the only thing telling them apart — so people opened the wrong site's admin, edited there,
 * and wondered why nothing appeared. Overview now draws the pair, marks THIS site, and states
 * the direction once.
 *
 * Everything asserted here comes from options and home_url(). Overview is not lazy — it
 * renders on every visit — so a signed round trip to prove reachability would be a network
 * call per page load. "Connected" means configured; Test Connection proves the rest.
 * -------------------------------------------------------------------------- */

/** @return string Overview, rendered under a role with a given remote configuration. */
function overview_as( string $role, array $remote = array(), array $options = array() ): string {
	$GLOBALS['dp_caps']    = array( 'manage_options' => true );
	$GLOBALS['dp_options'] = array_merge(
		array(
			'ifs_deploy_role'               => $role,
			'ifs_deploy_remote'             => $remote,
			'ifs_deploy_log_retention_days' => 30,
		),
		$options
	);

	$_GET = array( 'page' => 'ifs-deploy', 'tab' => 'overview' );

	return IfsDeploy\Admin\Tabs::render( 'overview' );
}

const CONNECTED = array( 'url' => 'https://www.livesite.com', 'api_key' => 'dpk_x', 'secret_key' => 'dps_y' );

echo "=== the environment pair is drawn on both sites ===\n";

$ov_staging = overview_as( 'staging', CONNECTED );

ok( 'the pair is rendered', false !== strpos( $ov_staging, 'class="dp-env"' ) );
ok( 'two environment cards', 2 === preg_match_all( '/class="dp-env-card/', $ov_staging ) );
ok( 'Staging is named', false !== strpos( $ov_staging, '>Staging</span>' ) );
ok( 'Production is named', false !== strpos( $ov_staging, '>Production</span>' ) );

// The badge is the fastest read on the screen, and there must be exactly one of it.
ok( 'exactly one "This site" badge', 1 === preg_match_all( '/dp-env-you/', $ov_staging ) );
ok( 'and it is on the Staging card', (bool) preg_match( '/dp-env-card is-self.*?dp-env-icon-staging/s', $ov_staging ) );

// The useful information: which hosts these actually are.
ok( 'Staging shows its own host', false !== strpos( $ov_staging, '>staging.test</span>' ) );
ok( 'Production shows the configured host', false !== strpos( $ov_staging, '>www.livesite.com</span>' ) );

echo "=== the same pair, seen from Production ===\n";

$ov_production = overview_as( 'production', array() );

ok( 'still two cards', 2 === preg_match_all( '/class="dp-env-card/', $ov_production ) );
ok( 'the badge moved to Production', (bool) preg_match( '/dp-env-card is-self.*?dp-env-icon-production/s', $ov_production ) );
ok( 'and there is still only one', 1 === preg_match_all( '/dp-env-you/', $ov_production ) );
ok( 'Production shows its own host', false !== strpos( $ov_production, '>staging.test</span>' ) );

// A receiver holds no record of its sender — the credentials live on the other side. Saying
// so beats inventing a URL, and beats a blank line that reads as a bug.
ok( 'Staging is described, not invented', false !== strpos( $ov_production, 'Configured on that site' ) );

echo "=== the direction is PUSH, and only push ===\n";

ok( 'a push indicator is shown', false !== strpos( $ov_staging, 'dp-env-arrow' ) );
ok( 'it says Push', false !== strpos( $ov_staging, ' Push</span>' ) );
ok( 'it points forward', false !== strpos( $ov_staging, '&rarr;' ) );
ok( 'and states that it is one-way', false !== strpos( $ov_staging, 'one-way' ) );

/*
 * NO PULL, anywhere. Pull is a future feature, and a disabled or "coming soon" control is a
 * promise the code cannot keep — someone would click it and file a bug. When pull does land,
 * this assertion is the one that has to be deliberately changed.
 */
foreach ( array( $ov_staging, $ov_production ) as $i => $html ) {
	ok( 'no pull control is offered (' . ( 0 === $i ? 'staging' : 'production' ) . ')', false === stripos( $html, 'pull' ) );
}

echo "=== an unconfigured pair says so on the arrow ===\n";

$ov_unset = overview_as( 'staging', array() );

ok( 'the arrow is muted', false !== strpos( $ov_unset, 'dp-env-arrow is-idle' ) );
ok( 'and says why', false !== strpos( $ov_unset, 'not connected' ) );
// Muted, not removed: a missing arrow would read as "this plugin does not do that".
ok( 'the arrow is still there', false !== strpos( $ov_unset, ' Push</span>' ) );
ok( 'and the Production card says it too', false !== strpos( $ov_unset, 'Not connected' ) );

// Half-configured is still not connected — a URL with no keys cannot sign anything.
$ov_partial = overview_as( 'staging', array( 'url' => 'https://www.livesite.com', 'api_key' => '', 'secret_key' => '' ) );
ok( 'a URL without keys is not "connected"', false !== strpos( $ov_partial, 'dp-env-arrow is-idle' ) );

echo "=== the cards below suit the site's role ===\n";

// Role and Production URL used to be cards; the pair above says both, better.
ok( 'no Role card any more', false === strpos( $ov_staging, '>Role</span>' ) );
ok( 'no Production URL card any more', false === strpos( $ov_staging, 'Production URL' ) );

ok( 'Staging counts the queue', false !== strpos( $ov_staging, 'Pending Changes' ) );
ok( 'and the last deployment', false !== strpos( $ov_staging, 'Last Deployment' ) );
ok( 'Staging shows no API counters', false === strpos( $ov_staging, 'API Requests' ) );

/*
 * Production gets the three numbers that mean something on a RECEIVER: is anything arriving,
 * is anything being turned away, and is incoming content being sanitised. The last one
 * because `report` mode looks like protection and is not.
 */
ok( 'Production counts API requests', false !== strpos( $ov_production, 'API Requests' ) );
ok( 'and refusals', false !== strpos( $ov_production, '>Refused</span>' ) );
ok( 'and reports the content filter', false !== strpos( $ov_production, 'Imported Content' ) );

// A bare count is not information until you know the window it covers.
ok( 'the request count states its window', false !== strpos( $ov_production, 'in the last 30 days' ) );

ok( 'report mode is named', false !== strpos( $ov_production, '>Report only</span>' ) );
ok( 'and its emptiness is spelled out', false !== strpos( $ov_production, 'nothing removed' ) );

$ov_filtering = overview_as( 'production', array(), array( 'ifs_deploy_content_firewall' => 'filter' ) );
ok( 'filter mode reads differently', false !== strpos( $ov_filtering, '>Filtered</span>' ) );
ok( 'and does not claim to be logging only', false === strpos( $ov_filtering, 'nothing removed' ) );

$ov_off = overview_as( 'production', array(), array( 'ifs_deploy_content_firewall' => 'off' ) );
// "Off" would read as a feature that is not in use; "Not filtered" says what it means for
// the content on this site.
ok( 'off mode is stated as not filtered', false !== strpos( $ov_off, '>Not filtered</span>' ) );

echo "=== the environment pair's styles are in the CSS that ships ===\n";

/*
 * Read through `dp_css_has()` / `dp_css_rule()`, never as raw text.
 *
 * `admin.css` is generated and its source header documents two commands — `--watch` gives
 * expanded output, `--minify` gives collapsed. Whichever ran last is what is on disk, so
 * matching raw strings made these assertions depend on the BUILD FLAG instead of on the CSS.
 * They went red the first time the file was rebuilt in watch mode with nothing wrong.
 */
$built_env = (string) file_get_contents( $root . '/assets/css/admin.css' );

foreach ( array( 'dp-env', 'dp-env-card', 'dp-env-you', 'dp-env-icon-staging', 'dp-env-icon-production', 'dp-env-arrow', 'dp-env-flow-note', 'ifs-deploy-card-hint' ) as $class ) {
	ok( "$class is built", dp_css_has( $built_env, '.' . $class ) );
}

// Equal columns are what makes it read as a pair rather than a hierarchy: a long hostname on
// one side must not make that card wider than the other.
ok( 'the pair uses equal columns', dp_css_has( $built_env, 'grid-template-columns:1fr auto 1fr' ) );
// Stacked on narrow screens the arrow has to point down instead.
ok( 'the arrow turns when stacked', dp_css_has( $built_env, 'rotate(90deg)' ) );

echo "=== both card kinds share one surface ===\n";

$src_css = (string) file_get_contents( $root . '/assets/css/src/admin.src.css' );

/*
 * The two card kinds share their BORDER, and that is what is pinned here.
 *
 * `.dp-env-card` and `.ifs-deploy-card` are authored as one surface so they cannot drift
 * apart the next time one is touched — counted in the SOURCE, because that is where the
 * duplication would appear.
 *
 * AT LEAST two, not exactly two: this is a shared surface token, and other components
 * legitimately reuse it (toasts do). Pinning the exact number turned a correct reuse into
 * a test failure. What must hold is that BOTH cards carry it, and the built-rule loop
 * below asserts precisely that.
 */
ok( 'the border is authored on both cards', substr_count( $src_css, 'border: 1px solid #EAEAEA;' ) >= 2 );
ok( 'the ambient glow is authored once', 1 === substr_count( $src_css, 'box-shadow: rgba(149, 157, 165, .2) 0 0 8px;' ) );

foreach ( array( 'dp-env-card', 'ifs-deploy-card' ) as $class ) {
	$rule = dp_css_rule( $built_env, '.ifs-deploy .' . $class );

	if ( '' === $rule ) {
		ok( "$class has a built rule", false );
		continue;
	}

	ok( "$class carries the light border", dp_css_has( $rule, 'border:1px solid #eaeaea' ) );
	// The old drop shadow went through Tailwind's --tw-shadow variables. If any survived,
	// two shadow declarations would be fighting.
	ok( "$class has no leftover shadow variable", ! dp_css_has( $rule, '--tw-shadow' ) );
}

/*
 * These two were suspended for a while, and the reason is worth keeping.
 *
 * The shipped `admin.css` had fallen behind `admin.src.css`: the source declared the card
 * shadow and the whole `.dp-env-card.is-self` rule, and NEITHER was in the built file —
 * `is-self` occurred zero times, so the "this site" marker had no styling at all. That is
 * the silent Tailwind no-op DESIGN.md warns about, and mtime did not catch it because both
 * files carried their checkout time.
 *
 * Re-running the build fixed it, and these assertions are the thing that now stops it
 * happening again unnoticed.
 */
// An offset-less glow on all four sides, which is what distinguishes this from the
// shadow-xs drop it replaced. The minifier re-expresses the colour as hsla(), so only the
// geometry is asserted.
ok(
	'ifs-deploy-card carries the ambient shadow',
	dp_css_has( dp_css_rule( $built_env, '.ifs-deploy .ifs-deploy-card' ), 'box-shadow:0 0 8px' )
);

/*
 * The one edge that MEANS something must still win.
 *
 * The base rule sets `border` as a shorthand, so the "this site" marker only survives
 * because `.is-self` is more specific. That is easy to break by accident — moving the
 * rule, or folding it into the base — and the failure is silent: the pair simply stops
 * telling you which site you are on.
 */
ok(
	'the "this site" border still overrides it',
	dp_css_has( dp_css_rule( $built_env, '.ifs-deploy .dp-env-card.is-self' ), 'border-color:' )
);

echo "=== the toggle's ON state is built into the CSS that ships ===\n";

/*
 * The source is Tailwind `@apply`, so a colour change only reaches the browser after
 * `tools/tailwindcss.exe` is re-run. Editing the .src.css and forgetting to rebuild is a
 * silent no-op, which is exactly the kind of thing to pin.
 */
ok( 'the source asks for the success green', false !== strpos( $src_css, 'tw-bg-success-text' ) );

$on_track     = dp_css_rule( $built_env, '.ifs-deploy .dp-toggle input:checked ~ .dp-toggle-track' );
$locked_track = dp_css_rule( $built_env, '.ifs-deploy .dp-toggle.is-disabled input:checked ~ .dp-toggle-track' );
$on_knob      = dp_css_rule( $built_env, '.ifs-deploy .dp-toggle input:checked ~ .dp-toggle-track .dp-toggle-knob' );

// #2F6B4F as Tailwind emits it. Grey-900 was 25 27 28 — two near-black pills differing only
// in lightness, which is what made the state hard to read at 36x20px.
ok( 'the built CSS fills the ON track green', dp_css_has( $on_track, 'background-color:rgb(47 107 79' ) );
ok( 'and no longer fills it near-black', ! dp_css_has( $on_track, 'background-color:rgb(25 27 28' ) );
// Locked administrator rows read as ON, not OFF, now that green carries the state.
ok( 'a locked ON toggle is muted green', dp_css_has( $locked_track, 'background-color:rgb(195 218 203' ) );
// The knob position is the other half of the signal and must not have moved.
ok( 'the knob still shifts when checked', dp_css_has( $on_knob, 'transform:translateX(16px)' ) );

restore_error_handler();

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
