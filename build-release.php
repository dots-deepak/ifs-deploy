<?php
/**
 * Build the release zip.
 *
 *     php build-release.php
 *
 * ── THE TRAP THIS EXISTS TO AVOID ──────────────────────────────────────────────
 *
 * The working directory is still named `deploypress` — it is a git checkout, and renaming it
 * would break the clone. WordPress takes the plugin SLUG from the folder inside the zip, and
 * the text domain has to match that slug for translations to load. So zipping the folder by
 * hand produces a package that installs as `deploypress`, with a text domain that no longer
 * matches, and the mismatch is silent: everything works except translated strings.
 *
 * This writes the correct folder name into the archive regardless of what the checkout is
 * called, and refuses to run if the two facts it depends on — the slug and the version — do not
 * agree across the plugin header, the version constant and readme.txt.
 *
 * It also verifies the built CSS is not older than its source, because editing
 * `admin.src.css` and forgetting to run Tailwind is a silent no-op that would otherwise ship.
 *
 * NOTE: scandir(), not glob() — the project path contains "[22020]", which glob() reads as a
 * character class and matches nothing.
 */

declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) {
	exit( 'Run this from the command line.' );
}

$root = __DIR__;
$slug = 'ifs-deploy';
$main = $root . '/' . $slug . '.php';

$errors = array();

/* -----------------------------------------------------------------------------
 * Preflight — refuse to build something inconsistent
 * -------------------------------------------------------------------------- */

if ( ! is_readable( $main ) ) {
	exit( "Cannot find {$slug}.php. Is the slug still correct?\n" );
}

$header = (string) file_get_contents( $main );
$readme = (string) file_get_contents( $root . '/readme.txt' );

preg_match( '/^ \* Version:\s*(\S+)/m', $header, $m );
$header_version = $m[1] ?? '';

preg_match( "/define\(\s*'IFS_DEPLOY_VERSION',\s*'([^']+)'/", $header, $m );
$constant_version = $m[1] ?? '';

preg_match( '/^Stable tag:\s*(\S+)/m', $readme, $m );
$readme_version = $m[1] ?? '';

preg_match( '/^ \* Text Domain:\s*(\S+)/m', $header, $m );
$text_domain = $m[1] ?? '';

if ( '' === $header_version || $header_version !== $constant_version ) {
	$errors[] = "Plugin header says {$header_version}, IFS_DEPLOY_VERSION says {$constant_version}.";
}

if ( $header_version !== $readme_version ) {
	$errors[] = "Plugin header says {$header_version}, readme.txt Stable tag says {$readme_version}.";
}

// The text domain must equal the slug, or `load_plugin_textdomain()` looks in the wrong place.
if ( $text_domain !== $slug ) {
	$errors[] = "Text Domain is '{$text_domain}' but the package slug is '{$slug}'.";
}

// Editing the CSS source without re-running Tailwind is a silent no-op.
$built  = $root . '/assets/css/admin.css';
$source = $root . '/assets/css/src/admin.src.css';

if ( is_readable( $built ) && is_readable( $source ) && filemtime( $built ) < filemtime( $source ) ) {
	$errors[] = 'assets/css/admin.css is older than its source. Run: tools/tailwindcss.exe -i assets/css/src/admin.src.css -o assets/css/admin.css --minify';
}

/*
 * Placeholders WARN rather than refuse, and the split is deliberate.
 *
 * Everything above breaks the plugin: a version mismatch makes updates misbehave, a text
 * domain that does not match the slug silently loses translations, stale CSS ships a
 * stylesheet that does not match its source. Those must stop the build.
 *
 * These three do not break anything. `Contributors` is a wordpress.org-only field and means
 * nothing on a client install; `Plugin URI` is optional; an odd `Author` is cosmetic. They
 * would be embarrassing to publish, not harmful — so they are printed loudly and the build
 * continues, because refusing here would block a perfectly good internal release.
 */
$warnings = array();

foreach (
	array(
		'SET_YOUR_WORDPRESS_ORG_USERNAME' => 'readme.txt `Contributors` is a placeholder (wordpress.org submissions only — irrelevant for a client install)',
		'example.com'                     => 'the `Plugin URI` header is a placeholder',
	) as $placeholder => $what
) {
	if ( false !== strpos( $header . $readme, $placeholder ) ) {
		$warnings[] = $what;
	}
}

// An author is a person or a company. The plugin's own name there means nobody set it.
preg_match( '/^ \* Author:\s*(.+)$/m', $header, $m );
$author = trim( $m[1] ?? '' );

if ( '' === $author || false !== stripos( 'IFS Deploy', $author ) ) {
	$warnings[] = "the `Author` header still says \"{$author}\" — it should name a person or company";
}

if ( ! empty( $errors ) ) {
	echo "REFUSING TO BUILD:\n";
	foreach ( $errors as $error ) {
		echo "  - $error\n";
	}
	echo "\nFix these and run again. Nothing was written.\n";
	exit( 1 );
}

/* -----------------------------------------------------------------------------
 * What ships
 * -------------------------------------------------------------------------- */

/** @return string[] Patterns from .distignore, comments and blanks removed. */
function exclusions( string $root ): array {
	$file = $root . '/.distignore';

	if ( ! is_readable( $file ) ) {
		exit( "No .distignore — refusing to guess what should ship.\n" );
	}

	$out = array();

	foreach ( (array) file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
		$line = trim( (string) $line );

		if ( '' === $line || 0 === strpos( $line, '#' ) ) {
			continue;
		}

		$out[] = $line;
	}

	return $out;
}

/**
 * Is this repo-relative path excluded?
 *
 * A pattern matches the whole path, any path SEGMENT (so `tests` excludes `tests/anything`),
 * or the basename via `fnmatch` for the `*.log` style entries.
 */
function excluded( string $relative, array $patterns ): bool {
	$segments = explode( '/', $relative );
	$basename = (string) end( $segments );

	foreach ( $patterns as $pattern ) {
		if ( $relative === $pattern || in_array( $pattern, $segments, true ) ) {
			return true;
		}

		if ( false !== strpos( $pattern, '*' ) && fnmatch( $pattern, $basename ) ) {
			return true;
		}
	}

	return false;
}

/** @return string[] Repo-relative paths that ship. */
function shipping( string $root, array $patterns ): array {
	$found = array();
	$stack = array( '' );

	while ( $stack ) {
		$relative_dir = array_pop( $stack );
		$absolute     = '' === $relative_dir ? $root : $root . '/' . $relative_dir;

		foreach ( scandir( $absolute ) ?: array() as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$relative = '' === $relative_dir ? $entry : $relative_dir . '/' . $entry;

			if ( excluded( $relative, $patterns ) ) {
				continue;
			}

			if ( is_dir( $absolute . '/' . $entry ) ) {
				$stack[] = $relative;
			} else {
				$found[] = $relative;
			}
		}
	}

	sort( $found );

	return $found;
}

$patterns = exclusions( $root );
$files    = shipping( $root, $patterns );

if ( empty( $files ) ) {
	exit( "Nothing to package.\n" );
}

/* -----------------------------------------------------------------------------
 * Sanity: the things that MUST be in there
 * -------------------------------------------------------------------------- */

$required = array( $slug . '.php', 'uninstall.php', 'readme.txt', 'assets/css/admin.css', 'assets/js/admin.js', 'src/Plugin.php' );
$absent   = array_diff( $required, $files );

if ( ! empty( $absent ) ) {
	echo "REFUSING TO BUILD — .distignore is excluding files the plugin needs:\n";
	foreach ( $absent as $file ) {
		echo "  - $file\n";
	}
	exit( 1 );
}

// And the things that must NOT be.
$forbidden = array_filter(
	$files,
	static fn( string $f ): bool => 0 === strpos( $f, 'tests/' ) || 0 === strpos( $f, 'tools/' ) || in_array( $f, array( 'PLUGIN-CONTEXT.md', 'DESIGN.md', 'SPRINT-PLAN.txt' ), true )
);

if ( ! empty( $forbidden ) ) {
	echo "REFUSING TO BUILD — developer-only files would ship:\n";
	foreach ( $forbidden as $file ) {
		echo "  - $file\n";
	}
	exit( 1 );
}

/* -----------------------------------------------------------------------------
 * Write it
 * -------------------------------------------------------------------------- */

$zip_path = $root . '/' . $slug . '-' . $header_version . '.zip';

if ( file_exists( $zip_path ) && ! unlink( $zip_path ) ) {
	exit( "Cannot overwrite {$zip_path}.\n" );
}

$zip = new ZipArchive();

if ( true !== $zip->open( $zip_path, ZipArchive::CREATE ) ) {
	exit( "Cannot create {$zip_path}.\n" );
}

$bytes = 0;

foreach ( $files as $relative ) {
	// The slug, NOT the checkout's folder name — the whole reason this script exists.
	$zip->addFile( $root . '/' . $relative, $slug . '/' . $relative );

	$bytes += (int) filesize( $root . '/' . $relative );
}

$zip->close();

printf(
	"built %s\n  %d files, %s uncompressed, %s zipped\n  installs as: %s/\n",
	basename( $zip_path ),
	count( $files ),
	size_of( $bytes ),
	size_of( (int) filesize( $zip_path ) ),
	$slug
);

echo "\nexcluded by .distignore:\n";
foreach ( array( 'tests', 'tools', 'DESIGN.md', 'PLUGIN-CONTEXT.md', 'SPRINT-PLAN.txt', 'composer.json', 'tailwind.config.js' ) as $what ) {
	echo "  $what\n";
}

if ( ! empty( $warnings ) ) {
	echo "\nBUILT, but fix these before publishing anywhere public:\n";
	foreach ( $warnings as $warning ) {
		echo "  - $warning\n";
	}
}

function size_of( int $bytes ): string {
	return $bytes > 1048576
		? round( $bytes / 1048576, 1 ) . ' MB'
		: round( $bytes / 1024 ) . ' KB';
}
