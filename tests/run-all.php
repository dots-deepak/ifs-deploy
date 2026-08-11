<?php
/**
 * Runs every IFS Deploy test suite and the repo-wide checks.
 *
 *     php tests/run-all.php
 *
 * Exits non-zero if anything fails, so it can gate a commit.
 *
 * These suites stub the handful of WordPress functions they need and run on plain
 * PHP — no WordPress, no PHPUnit, no composer install. They are unit-level: nothing
 * here exercises a real signed round-trip between two WordPress installs.
 *
 * NOTE: scandir() rather than glob() throughout the test tooling. The project path
 * contains "[22020]", which glob() parses as a character class and silently matches
 * nothing.
 */

$dir = __DIR__;

/** Suites that report PASS/FAIL lines and exit non-zero on failure. */
$suites = array(
	'access',
	'byref',
	'concurrency',
	'contracts',
	'encoding',
	'firewall',
	'idspace',
	'hardening',
	'ipaccess',
	'ipmonitor',
	'legacy-rename',
	'media-identity',
	'media-match',
	'media-url',
	'meta',
	'metablock',
	'metadelete',
	'nonce',
	'postmatch',
	'protocol',
	'queue-revert',
	'render',
	'retention',
	'security',
	'rollback-diff',
	'signature',
	'syncheck',
	'termmatch',
	'url',
	'ui-hooks',
	'verifier',
);

$php      = PHP_BINARY;
$passed   = 0;
$failed   = 0;
$broken   = array();

echo "== suites ==\n";

foreach ( $suites as $suite ) {
	$file = $dir . '/' . $suite . '-test.php';
	if ( ! is_readable( $file ) ) {
		printf( "  %-16s MISSING\n", $suite );
		$broken[] = $suite;
		continue;
	}

	exec( sprintf( '%s %s 2>&1', escapeshellarg( $php ), escapeshellarg( $file ) ), $output, $status );
	$text = implode( "\n", $output );
	$output = array();

	// Leading whitespace allowed: some suites indent their result lines under a heading.
	$pass = preg_match_all( '/^\s*PASS\b/m', $text );
	$fail = preg_match_all( '/^\s*FAIL\b/m', $text );

	$passed += $pass;
	$failed += $fail;

	printf( "  %-16s %3d pass  %d fail%s\n", $suite, $pass, $fail, 0 !== $status ? '   (exit ' . $status . ')' : '' );

	if ( $fail > 0 || 0 !== $status ) {
		$broken[] = $suite;
	}
}

// diff-test prints a report of scenarios rather than assertions.
exec( sprintf( '%s %s 2>&1', escapeshellarg( $php ), escapeshellarg( $dir . '/diff-test.php' ) ), $diff_out, $diff_status );
printf( "  %-16s %3d scenarios%s\n", 'diff', preg_match_all( '/^=== /m', implode( "\n", $diff_out ) ), 0 !== $diff_status ? '   (exit ' . $diff_status . ')' : '' );
if ( 0 !== $diff_status ) {
	$broken[] = 'diff';
}

echo "\n== repo-wide checks ==\n";

foreach ( array( 'psr4', 'unused-imports', 'missing-imports', 'contrast', 'dead-code' ) as $check ) {
	exec( sprintf( '%s %s 2>&1', escapeshellarg( $php ), escapeshellarg( $dir . '/' . $check . '.php' ) ), $out, $status );

	$lines = array_values( array_filter( array_map( 'trim', $out ), static fn( string $line ): bool => '' !== $line ) );

	/*
	 * The SUMMARY line when a check passes, everything when it fails.
	 *
	 * `contrast.php` prints a full ratio table, which would bury this section; its last
	 * line is the count. But a failure has to show its detail here rather than sending
	 * someone off to re-run the check by hand to find out what broke.
	 */
	if ( 0 === $status ) {
		printf( "  %-16s %s\n", $check, (string) ( end( $lines ) ?: '' ) );
	} else {
		printf( "  %-16s FAILED\n", $check );
		foreach ( $lines as $line ) {
			echo "        $line\n";
		}
		$broken[] = $check;
	}

	$out = array();
}

exec(
	sprintf( '%s %s %s 2>&1', escapeshellarg( $php ), escapeshellarg( $dir . '/jsbalance.php' ), escapeshellarg( $dir . '/../assets/js/admin.js' ) ),
	$js_out,
	$js_status
);
printf( "  %-16s %s\n", 'jsbalance', trim( implode( ' ', $js_out ) ) );
if ( 0 !== $js_status ) {
	$broken[] = 'jsbalance';
}

printf( "\nTOTAL %d passing, %d failing\n", $passed, $failed );

if ( ! empty( $broken ) ) {
	echo 'PROBLEMS IN: ' . implode( ', ', array_unique( $broken ) ) . "\n";
	exit( 1 );
}

echo "all green\n";
