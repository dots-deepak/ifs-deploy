<?php
/**
 * Find code nothing reaches: unreferenced classes, methods and constants.
 *
 *     php tests/dead-code.php
 *
 * ── WHAT THIS IS AND IS NOT ────────────────────────────────────────────────────
 *
 * A HEURISTIC, reported for a human to judge — it does not exit non-zero, because plenty of
 * legitimate code has no caller in this repository:
 *
 *   - hook callbacks, reached by name through `add_action`/`add_filter`;
 *   - REST and AJAX handlers, reached by the router;
 *   - anything a THEME or another plugin is meant to call.
 *
 * Reporting those as dead and failing the build would train everyone to ignore the check. So
 * it lists candidates and names the reason each one might be a false positive.
 *
 * It earns its place by catching the opposite mistake: a method left behind after its last
 * caller was refactored away. That is invisible in review, ships forever, and is exactly the
 * kind of thing a release audit should surface.
 *
 * NOTE: scandir(), not glob() — the project path contains "[22020]", which glob() reads as a
 * character class and matches nothing.
 */

$root = dirname( __DIR__ );

/** @return string[] */
function php_files( string $dir ): array {
	$found = array();
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
				$found[] = $path;
			}
		}
	}

	sort( $found );

	return $found;
}

$src_files = php_files( $root . '/src' );

// Everything that could reference something: plugin code, the bootstrap, uninstall, and JS
// (which names AJAX actions and reads localised keys).
$haystack = '';
foreach ( array_merge( $src_files, array( $root . '/ifs-deploy.php', $root . '/uninstall.php' ) ) as $file ) {
	$haystack .= php_strip_whitespace( $file ) . "\n";
}
$haystack .= (string) file_get_contents( $root . '/assets/js/admin.js' );

$classes   = array();
$methods   = array();
$constants = array();

foreach ( $src_files as $file ) {
	$code  = php_strip_whitespace( $file );
	$short = str_replace( $root . '/', '', str_replace( chr( 92 ), '/', $file ) );

	if ( preg_match( '/\b(?:final\s+)?class\s+([A-Za-z0-9_]+)/', $code, $m ) ) {
		$classes[ $m[1] ] = $short;
	}

	// Methods, with their visibility so the report can weight them.
	if ( preg_match_all( '/\b(public|private|protected)\s+(?:static\s+)?function\s+([a-zA-Z0-9_]+)\s*\(/', $code, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $hit ) {
			$methods[] = array( $hit[2], $hit[1], $short );
		}
	}

	if ( preg_match_all( '/\b(?:public|private|protected)?\s*const\s+([A-Z0-9_]+)\s*=/', $code, $m ) ) {
		foreach ( $m[1] as $name ) {
			$constants[] = array( $name, $short );
		}
	}
}

/** Names PHP or WordPress calls for us, so an absent caller means nothing. */
const MAGIC = array(
	'__construct', '__destruct', '__wakeup', '__clone', '__toString', '__get', '__set',
	'__isset', '__call', '__callStatic', '__invoke', '__serialize', '__unserialize',
);

echo "=== classes never named anywhere ===\n";
$orphan_classes = 0;
foreach ( $classes as $class => $file ) {
	// `Foo::`, `new Foo`, `Foo::class`, or a `use …\Foo;`
	if ( preg_match( '/\b' . preg_quote( $class, '/' ) . '\b/', str_replace( "class $class", '', $haystack ) ) ) {
		continue;
	}

	printf( "  %-24s %s\n", $class, $file );
	++$orphan_classes;
}
echo 0 === $orphan_classes ? "  none\n" : '';

echo "\n=== methods with no caller in this codebase ===\n";
$orphan_methods = 0;
foreach ( $methods as list( $name, $visibility, $file ) ) {
	if ( in_array( $name, MAGIC, true ) ) {
		continue;
	}

	// Count references that are not the declaration itself.
	$uses = preg_match_all( '/\b' . preg_quote( $name, '/' ) . '\s*\(/', $haystack );

	if ( $uses > 1 ) {
		continue;
	}

	// A hook callback is referenced as a STRING, not a call.
	if ( false !== strpos( $haystack, "'" . $name . "'" ) ) {
		continue;
	}

	printf( "  %-9s %-28s %s\n", $visibility, $name . '()', $file );
	++$orphan_methods;
}
echo 0 === $orphan_methods ? "  none\n" : "\n  (public ones may be intended for themes/plugins; private ones are real candidates)\n";

echo "\n=== constants never read ===\n";
$orphan_constants = 0;
foreach ( $constants as list( $name, $file ) ) {
	if ( preg_match_all( '/\b' . preg_quote( $name, '/' ) . '\b/', $haystack ) > 1 ) {
		continue;
	}

	printf( "  %-28s %s\n", $name, $file );
	++$orphan_constants;
}
echo 0 === $orphan_constants ? "  none\n" : '';

printf(
	"\nchecked %d classes, %d methods, %d constants — %d + %d + %d candidates\n",
	count( $classes ),
	count( $methods ),
	count( $constants ),
	$orphan_classes,
	$orphan_methods,
	$orphan_constants
);
