<?php
/**
 * Every unqualified class reference in src/ must actually resolve.
 *
 *     php tests/missing-imports.php
 *
 * ── WHY THIS EXISTS ────────────────────────────────────────────────────────────
 *
 * Inside a namespace, `ContentFirewall::mode()` does NOT mean the class you were thinking
 * of — it means `IfsDeploy\Admin\Pages\ContentFirewall`, in the current namespace. Without
 * a matching `use`, that is a fatal error at the moment the line runs, and not one second
 * earlier.
 *
 * That is what makes it dangerous here. It is invisible to `php -l`, invisible to the PSR-4
 * check, and invisible to the unused-imports check (which looks for the opposite problem).
 * It only shows up when the branch containing it executes — and the one that shipped was on
 * the Production-role path of a settings screen, so every test rendering that screen as
 * Staging passed while the real thing was a 500.
 *
 * The user-visible symptom was "Request failed. Please try again." — because the screen is
 * loaded over AJAX, so a fatal arrives as a broken JSON response rather than a PHP error
 * anyone would see.
 *
 * NOTE: scandir(), not glob(). The project path contains "[22020]", which glob() reads as a
 * character class and silently matches nothing.
 */

$root = dirname( __DIR__ ) . '/src';

/**
 * Classes that legitimately resolve to the global namespace.
 *
 * Referencing one of these unqualified from inside a namespace is still a bug, so they are
 * listed to be RECOGNISED, not excused — a match here is only accepted when the file either
 * imports it or writes it with a leading backslash, both of which are checked below.
 */
const GLOBAL_CLASSES = array(
	// PHP
	'Throwable', 'Exception', 'Error', 'TypeError', 'ArgumentCountError', 'Closure',
	'ArrayObject', 'DateTime', 'DateTimeImmutable', 'DateTimeZone', 'DateInterval',
	'Generator', 'Iterator', 'IteratorAggregate', 'Countable', 'JsonSerializable',
	'ReflectionClass', 'ReflectionMethod', 'ReflectionProperty', 'SplFileObject',
	// WordPress
	'WP_Error', 'WP_Post', 'WP_Term', 'WP_User', 'WP_Query', 'WP_Roles', 'WP_Role',
	'WP_REST_Request', 'WP_REST_Response', 'WP_REST_Server', 'WP_HTTP_Response',
	'WP_Filesystem_Base', 'WP_Upgrader', 'wpdb',
);

/** @return string[] Every .php file under $dir. */
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

$files    = php_files( $root );
$problems = array();
$checked  = 0;

foreach ( $files as $file ) {
	// Comments stripped: a docblock naming a class it does not use is not a reference, and
	// several here discuss classes deliberately NOT called.
	$code = php_strip_whitespace( $file );

	/*
	 * Unanchored on purpose. `php_strip_whitespace()` collapses the header, so
	 * `namespace …;` frequently ends up on the same line as `<?php declare(…);` and an
	 * `/^…/m` anchor silently matches nothing — which is how the first version of this
	 * check reported "0 references" and looked like a pass.
	 */
	if ( ! preg_match( '/\bnamespace\s+([A-Za-z0-9_\\\\]+)\s*;/', $code, $ns ) ) {
		continue;
	}

	$namespace = trim( $ns[1] );

	// use A\B\C;  and  use A\B\C as D;  (a closure's `use ( $x )` cannot match: no `;`)
	$aliases = array();
	if ( preg_match_all( '/\buse\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?\s*;/i', $code, $uses, PREG_SET_ORDER ) ) {
		foreach ( $uses as $use ) {
			$parts                  = explode( chr( 92 ), $use[1] );
			$alias                  = '' !== ( $use[2] ?? '' ) ? $use[2] : (string) end( $parts );
			$aliases[ $alias ]      = $use[1];
		}
	}

	// Names declared by this file itself.
	$own = array();
	if ( preg_match_all( '/\b(?:class|interface|trait|enum)\s+([A-Za-z0-9_]+)/', $code, $decl ) ) {
		$own = $decl[1];
	}

	/*
	 * Reference sites. Each pattern captures the bare name, and each REQUIRES that the name
	 * is not preceded by a backslash or another name segment — `\WP_Error` and
	 * `IfsDeploy\Support\Config` are already unambiguous and cannot be wrong.
	 */
	$patterns = array(
		'static'     => '/(?<![\\\\$>a-zA-Z0-9_])([A-Z][A-Za-z0-9_]*)::/',
		'new'        => '/\bnew\s+(?<![\\\\])([A-Z][A-Za-z0-9_]*)\s*[\(;]/',
		'instanceof' => '/\binstanceof\s+(?<![\\\\])([A-Z][A-Za-z0-9_]*)/',
		'catch'      => '/\bcatch\s*\(\s*(?<![\\\\])([A-Z][A-Za-z0-9_]*)/',
		'extends'    => '/\bextends\s+(?<![\\\\])([A-Z][A-Za-z0-9_]*)/',
		'implements' => '/\bimplements\s+(?<![\\\\])([A-Z][A-Za-z0-9_]*)/',
	);

	$seen = array();

	foreach ( $patterns as $kind => $pattern ) {
		if ( ! preg_match_all( $pattern, $code, $hits ) ) {
			continue;
		}

		foreach ( $hits[1] as $name ) {
			if ( isset( $seen[ $name ] ) ) {
				continue;
			}

			$seen[ $name ] = true;
			++$checked;

			// Language keywords that share the shape of a class reference.
			if ( in_array( strtolower( $name ), array( 'self', 'static', 'parent' ), true ) ) {
				continue;
			}

			// Imported, or declared right here.
			if ( isset( $aliases[ $name ] ) || in_array( $name, $own, true ) ) {
				continue;
			}

			/*
			 * Unimported, so PHP resolves it inside the CURRENT namespace. That is only
			 * correct if a class of that name really lives there — i.e. a sibling file in the
			 * same directory, which the PSR-4 layout makes a direct path check.
			 */
			$sibling = dirname( $file ) . '/' . $name . '.php';

			if ( is_readable( $sibling ) ) {
				continue;
			}

			$problems[] = sprintf(
				'%s — %s::%s resolves to %s, which does not exist (%s reference)',
				str_replace( chr( 92 ), '/', substr( $file, strlen( dirname( $root ) ) + 1 ) ),
				$namespace,
				$name,
				$namespace . chr( 92 ) . $name,
				$kind
			);
		}
	}
}

if ( ! empty( $problems ) ) {
	echo "MISSING IMPORTS:\n";
	foreach ( $problems as $problem ) {
		echo "  $problem\n";
	}
	printf( "\nchecked %d references, %d unresolvable\n", $checked, count( $problems ) );
	exit( 1 );
}

printf( "checked %d class references, 0 unresolvable\n", $checked );
