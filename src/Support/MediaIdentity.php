<?php
declare(strict_types=1);

namespace IfsDeploy\Support;

/**
 * Identifies an attachment in a way that survives Production renaming the file.
 *
 * wp_upload_bits() will not overwrite, so an incoming `hero.png` is stored as
 * `hero-1.png` when Production already holds an unrelated `hero.png`. The image is
 * correct and the featured image points at the right attachment — but comparing the two
 * sides by their LOCAL filenames then reports a difference on every single push, and no
 * amount of pushing can ever resolve it.
 *
 * Both importers stamp the originating URL on every attachment they create, so the
 * original name is recoverable. Comparing by that instead makes the featured-image
 * check rename-proof across the deploy preview, Compare & Sync and the rollback preview.
 */
final class MediaIdentity {

	/**
	 * Meta key holding the URL an attachment was imported from.
	 *
	 * Canonical definition: Import\MediaImporter and Import\PostImporter alias this
	 * rather than repeating the string, since three copies of a magic key is exactly how
	 * they drift apart.
	 */
	public const SOURCE_URL_META = '_ifs_deploy_source_url';

	/**
	 * The attachment's filename as it was on the site it originated from, falling back
	 * to the local filename for media that was uploaded here directly.
	 *
	 * The fallback is what keeps this precise rather than a guess: an unrelated
	 * `hero-1.png` uploaded by hand on Production has no source stamp, so it still
	 * compares as different from Staging's `hero.png` — which is correct, because they
	 * really are different images.
	 */
	public static function stable_filename( int $attachment_id ): string {
		if ( $attachment_id <= 0 ) {
			return '';
		}

		$source_url = (string) get_post_meta( $attachment_id, self::SOURCE_URL_META, true );

		if ( '' !== $source_url ) {
			// Require a host as well as a path: parse_url() happily reports a bare
			// string like "not-a-url" AS a path, so without this a malformed stamp
			// would be trusted instead of falling back.
			$parts = (array) wp_parse_url( $source_url );

			if ( ! empty( $parts['host'] ) && ! empty( $parts['path'] ) ) {
				$name = basename( (string) $parts['path'] );

				if ( '' !== $name ) {
					return $name;
				}
			}
		}

		return basename( (string) get_attached_file( $attachment_id ) );
	}
}
