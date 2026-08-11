<?php
declare(strict_types=1);

/**
 * A stand-in for Import\MediaImporter, declared in its own namespace so that
 * `new MediaImporter()` inside PostImporter resolves to this instead.
 *
 * It exists because `postmatch-test.php` asks one question about the featured image:
 * is it routed through the media pipeline CARRYING ITS ORIGIN ID? What the pipeline
 * then does with that id is media-match-test.php's job, and the real importer would
 * want to perform an HTTP download to answer.
 *
 * Every package it is handed is recorded in `$GLOBALS['media_pkgs']`.
 *
 * Kept in a `lib-` file rather than inline because a bracketed `namespace {}` block
 * cannot sit alongside unbraced code in the same file — and run-all.php only picks up
 * `*-test.php`, so this is never mistaken for a suite.
 */

namespace IfsDeploy\Import;

final class MediaImporter {

	/**
	 * @return array{object_id:int,created:bool}
	 */
	public function import( array $package ) {
		$GLOBALS['media_pkgs'][] = $package;

		return array( 'object_id' => 7777, 'created' => true );
	}
}
