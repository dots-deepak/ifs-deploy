<?php
/**
 * Reports `use` statements whose short name never appears again in the file.
 * Unused imports are harmless at runtime but signal a leftover from an edit.
 */
$rii = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( __DIR__ . '/../src', FilesystemIterator::SKIP_DOTS ) );

$problems = 0;
$checked  = 0;

foreach ( $rii as $file ) {
	if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
		continue;
	}

	$src = (string) file_get_contents( $file->getPathname() );
	if ( ! preg_match_all( '/^use\s+([^;]+);/m', $src, $matches ) ) {
		continue;
	}

	// Everything after the use block, so the import itself is not counted.
	$body = preg_replace( '/^use\s+[^;]+;$/m', '', $src );

	foreach ( $matches[1] as $import ) {
		++$checked;
		$parts = explode( '\\', trim( $import ) );
		$short = end( $parts );

		if ( ! preg_match( '/\b' . preg_quote( $short, '/' ) . '\b/', (string) $body ) ) {
			printf( "UNUSED  %s  in %s\n", trim( $import ), $file->getPathname() );
			++$problems;
		}
	}
}

printf( "checked %d imports, %d unused\n", $checked, $problems );
