<?php
/**
 * Confirms every class in src/ sits where the hand-rolled PSR-4 autoloader will
 * look for it. A mismatch is invisible until runtime, when it becomes a fatal
 * "class not found" — the exact failure mode being debugged.
 */
$base = __DIR__ . '/../src';
$rii  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) );

$problems = 0;
$checked  = 0;

foreach ( $rii as $file ) {
	if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
		continue;
	}

	++$checked;
	$src = (string) file_get_contents( $file->getPathname() );

	preg_match( '/^namespace\s+([^;]+);/m', $src, $ns );
	preg_match( '/^(?:final\s+)?(?:abstract\s+)?class\s+(\w+)/m', $src, $cl );

	if ( empty( $ns[1] ) || empty( $cl[1] ) ) {
		echo "NO CLASS OR NAMESPACE: {$file->getPathname()}\n";
		++$problems;
		continue;
	}

	$relative = substr( str_replace( '\\', '/', $file->getPathname() ), strlen( $base ) + 1 );
	$relative = preg_replace( '/\.php$/', '', $relative );
	$expected = 'IfsDeploy\\' . str_replace( '/', '\\', $relative );
	$actual   = trim( $ns[1] ) . '\\' . $cl[1];

	if ( $expected !== $actual ) {
		echo "MISMATCH\n  path implies: {$expected}\n  declares:     {$actual}\n";
		++$problems;
	}
}

printf( "checked %d classes, %d problems\n", $checked, $problems );
exit( $problems > 0 ? 1 : 0 );
