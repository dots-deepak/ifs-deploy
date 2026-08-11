<?php
/**
 * Agreements between files that no single file can enforce.
 *
 * ── WHY THESE NEED A TEST OF THEIR OWN ─────────────────────────────────────────
 *
 * Every assertion here spans two files that must agree on a STRING. PHP registers
 * `wp_ajax_ifs_deploy_deploy`; JavaScript posts `action: 'ifs_deploy_deploy'`. Nothing checks
 * that. A typo in either one produces no error, no warning and no failing unit test — the
 * button simply does nothing, and the only way to find out is to click it.
 *
 * The rename made that concrete: 2799 strings moved across 126 files, and a single missed one
 * on either side of any pair below would have shipped silently. These were verified by hand at
 * the time; this file is what stops the next change needing the same manual pass.
 *
 * Each block states the pair and what breaks when they drift.
 */

$root = dirname( __DIR__ );
$pass = 0;
$fail = 0;

require __DIR__ . '/lib-css.php';

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

$php_dir  = $root . '/src';
$js       = (string) file_get_contents( $root . '/assets/js/admin.js' );
$css      = (string) file_get_contents( $root . '/assets/css/admin.css' );
$ajax     = (string) file_get_contents( $php_dir . '/Admin/Ajax.php' );
$assets   = (string) file_get_contents( $php_dir . '/Admin/Assets.php' );
$screen   = (string) file_get_contents( $php_dir . '/Admin/Screen.php' );
$schema   = (string) file_get_contents( $php_dir . '/Support/Schema.php' );
$uninst   = str_replace( "\r\n", "\n", (string) file_get_contents( $root . '/uninstall.php' ) );

/** Every .php file under src/, comments stripped. */
function all_php( string $dir ): string {
	$out   = '';
	$stack = array( $dir );

	while ( $stack ) {
		$current = array_pop( $stack );

		foreach ( scandir( $current ) ?: array() as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = $current . '/' . $entry;

			if ( is_dir( $path ) ) {
				$stack[] = $path;
			} elseif ( '.php' === substr( $path, -4 ) ) {
				$out .= php_strip_whitespace( $path ) . "\n";
			}
		}
	}

	return $out;
}

$all_php = all_php( $php_dir );

/* -----------------------------------------------------------------------------
 * AJAX action names
 * -------------------------------------------------------------------------- */

echo "=== every AJAX action JS posts is registered in PHP ===\n";

preg_match_all( "/add_action\(\s*'wp_ajax_([a-z_]+)'/", $ajax, $m );
$registered = array_unique( $m[1] );

// `action: 'x'` in a $.post, plus any bare 'ifs_deploy_*' string JS sends.
preg_match_all( "/action:\s*'([a-z_]+)'/", $js, $m2 );
preg_match_all( "/'(ifs_deploy_[a-z_]+)'/", $js, $m3 );
$sent = array_unique( array_merge( $m2[1], $m3[1] ) );

ok( 'PHP registers some actions', count( $registered ) >= 12 );
ok( 'JS sends some actions', count( $sent ) >= 12 );

// THE assertion. A typo on either side is a button that silently does nothing.
$unregistered = array_diff( $sent, $registered );
ok( 'JS posts nothing PHP does not handle', array() === $unregistered )
	or printf( "        orphaned in JS: %s\n", implode( ', ', $unregistered ) );

// The other direction is informational: an action may legitimately be posted from PHP-rendered
// markup rather than admin.js, so an unused registration is not automatically wrong.
$unsent = array_diff( $registered, $sent );
ok( 'and PHP registers nothing JS never calls', array() === $unsent )
	or printf( "        registered but unused: %s\n", implode( ', ', $unsent ) );

// Every handler must go through guard(), which does capability + nonce. Asserted in
// security-test.php too; repeated here because it is the same PHP↔JS surface.
ok( 'the nonce JS sends is the one guard() checks', false !== strpos( $js, 'nonce: IfsDeploy.nonce' ) && false !== strpos( $ajax, "check_ajax_referer( self::NONCE, 'nonce' )" ) );

/* -----------------------------------------------------------------------------
 * The localised JS object
 * -------------------------------------------------------------------------- */

echo "=== the JS object PHP creates is the one JS reads ===\n";

preg_match( "/wp_localize_script\(\s*'([a-z-]+)',\s*'([A-Za-z0-9_]+)'/", $assets, $handle );

$script_handle = $handle[1] ?? '';
$object_name   = $handle[2] ?? '';

ok( 'a localised object is declared', '' !== $object_name );
// A hyphen or a space here is a JavaScript syntax error, not a lookup failure — which is why
// the object name does NOT follow the plugin's slug spelling.
ok( 'its name is a valid JS identifier', (bool) preg_match( '/^[A-Za-z_$][A-Za-z0-9_$]*$/', $object_name ) );
ok( "JS reads that exact object ($object_name)", false !== strpos( $js, $object_name . '.' ) );
ok( 'and declares it as a global', false !== strpos( $js, 'global jQuery, ' . $object_name ) );

// localize attaches to an ENQUEUED handle; a mismatch means the object is never printed.
ok( 'the localise handle is the enqueued one', '' !== $script_handle && 2 <= substr_count( $assets, "'" . $script_handle . "'" ) );

echo "=== every i18n key JS reads is sent ===\n";

preg_match_all( '/i18n\.([a-zA-Z]+)/', $js, $m );
$needed = array_unique( $m[1] );

$start = strpos( $assets, "'i18n'" );
preg_match_all( "/'([a-zA-Z]+)'\s*=>/", false === $start ? '' : substr( $assets, $start ), $m );
$provided = array_unique( $m[1] );

ok( 'JS reads a meaningful number of strings', count( $needed ) >= 20 );

// A missing key is `undefined` in a dialog — the confirm text disappears and the user is asked
// to approve a destructive action with no description.
$missing = array_diff( $needed, $provided );
ok( 'none of them is missing', array() === $missing )
	or printf( "        missing: %s\n", implode( ', ', $missing ) );

$extra = array_diff( $provided, $needed, array( 'i18n' ) );
ok( 'and nothing is sent that JS never uses', array() === $extra )
	or printf( "        unused: %s\n", implode( ', ', $extra ) );

/* -----------------------------------------------------------------------------
 * CSS
 * -------------------------------------------------------------------------- */

echo "=== the CSS scope class PHP emits is the one the stylesheet uses ===\n";

preg_match( '/class="wrap ([a-z-]+)/', $screen, $m );
$scope = $m[1] ?? '';

ok( 'Screen emits a scope class', '' !== $scope );
// Every rule is scoped to this class so the plugin cannot restyle the rest of wp-admin. If the
// class and the selectors disagree, the admin screen renders completely unstyled.
ok( "the stylesheet scopes rules to .$scope", dp_css_has( $css, '.' . $scope . ' .' ) );
ok( 'and there are many such rules', 100 < substr_count( dp_css_normalise( $css ), '.' . $scope . ' .' ) );

/* -----------------------------------------------------------------------------
 * Nonces
 * -------------------------------------------------------------------------- */

echo "=== every nonce created is verified, and vice versa ===\n";

preg_match_all( "/wp_nonce_field\(\s*'([a-z_]+)'/", $all_php, $m );
$created = array_unique( $m[1] );

preg_match_all( "/check_admin_referer\(\s*'([a-z_]+)'/", $all_php, $m );
$verified = array_unique( $m[1] );

sort( $created );
sort( $verified );

ok( 'there are form nonces to check', count( $created ) >= 4 );
// A created-but-unverified nonce is a form that accepts a forged POST. An
// unverified-but-checked one is a form that can never submit.
ok( 'created and verified sets match', $created === $verified )
	or printf( "        created: %s\n        verified: %s\n", implode( ',', $created ), implode( ',', $verified ) );

/* -----------------------------------------------------------------------------
 * Database
 * -------------------------------------------------------------------------- */

echo "=== uninstall knows about every table and option ===\n";

preg_match_all( "/return \\\$wpdb->prefix \. '(ifs_deploy_[a-z_]+)'/", $schema, $m );
$schema_tables = array_unique( $m[1] );

preg_match_all( "/\\\$wpdb->prefix \. '(ifs_deploy_[a-z_]+)'/", $uninst, $m );
$dropped = array_unique( $m[1] );

sort( $schema_tables );
sort( $dropped );

ok( 'Schema declares its tables', count( $schema_tables ) >= 5 );
// A table Schema creates but uninstall does not drop is data left behind forever.
ok( 'every table Schema creates is dropped on uninstall', $schema_tables === $dropped )
	or printf( "        schema: %s\n        uninstall: %s\n", implode( ',', $schema_tables ), implode( ',', $dropped ) );

// Options are the same problem, and there are far more of them. Collected from anywhere the
// plugin writes one, which is the set that has to be cleaned up.
preg_match_all( "/(?:get_option|update_option|delete_option)\(\s*'(ifs_deploy_[a-z_]+)'/", $all_php, $m );
preg_match_all( "/(?:OPTION[A-Z_]*|DONE)\s*=\s*'(ifs_deploy_[a-z_]+)'/", $all_php, $m2 );

$written = array_unique( array_merge( $m[1], $m2[1] ) );

preg_match_all( "/^\t'(ifs_deploy_[a-z_]+)',$/m", $uninst, $m );
$deleted = $m[1];

$leaked = array_diff( $written, $deleted );

ok( 'the plugin writes options', count( $written ) >= 8 );
ok( 'and uninstall deletes all of them', array() === $leaked )
	or printf( "        not deleted: %s\n", implode( ', ', $leaked ) );

/* -----------------------------------------------------------------------------
 * REST + wire format
 * -------------------------------------------------------------------------- */

echo "=== the wire format is declared once and used everywhere ===\n";

$rest   = (string) file_get_contents( $php_dir . '/Rest/RestController.php' );
$client = (string) file_get_contents( $php_dir . '/Client/DeployClient.php' );

ok( 'the REST namespace is a constant', (bool) preg_match( "/NAMESPACE = '[a-z0-9-]+\/v1'/", $rest ) );
// A second literal of the namespace anywhere is a 404 waiting to happen: the client would sign
// a request to a path the server never registered.
ok( 'the client builds its URL from that constant', false !== strpos( $client, 'RestController::NAMESPACE' ) );
ok( 'and hard-codes no path of its own', ! preg_match( "#'[a-z-]+/v1'#", $client ) );

// Headers are read by name on the far side, so both ends must take them from one definition.
preg_match_all( "/HEADER_[A-Z_]+\s*=\s*'(X-[A-Za-z-]+)'/", $all_php, $m );
$headers = array_unique( $m[1] );

ok( 'the signed headers are declared as constants', count( $headers ) >= 6 );
ok( 'and share one prefix', 1 === count( array_unique( array_map( static fn( $h ) => implode( '-', array_slice( explode( '-', $h ), 0, 3 ) ), $headers ) ) ) )
	or printf( "        %s\n", implode( ', ', $headers ) );

$verifier = php_strip_whitespace( $php_dir . '/Auth/Verifier.php' );
ok( 'the verifier reads them through the constants', ! preg_match( "/'X-[A-Za-z-]+'/", $verifier ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
