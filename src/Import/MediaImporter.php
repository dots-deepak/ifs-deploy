<?php
declare(strict_types=1);

namespace IfsDeploy\Import;

use IfsDeploy\Support\DebugLog;
use IfsDeploy\Support\MediaIdentity;
use WP_Error;
use IfsDeploy\Support\SafeData;

/**
 * Applies a media package to Production: downloads the source file, creates the
 * attachment with the SAME id (import_id) so references line up across sites,
 * regenerates size metadata, and dedupes so repeated deploys don't pile up
 * duplicate files.
 */
final class MediaImporter {

	public const ORIGIN_ID_META   = '_ifs_deploy_origin_id';
	public const ORIGIN_SITE_META = '_ifs_deploy_origin_site';
	public const SOURCE_URL_META  = MediaIdentity::SOURCE_URL_META;

	/**
	 * @return array{object_id:int,created:bool}|WP_Error
	 */
	public function import( array $package ) {
		$origin_id   = (int) ( $package['origin_id'] ?? 0 );
		$origin_site = (string) ( $package['origin_site'] ?? '' );

		if ( 'delete' === ( $package['action'] ?? 'update' ) ) {
			$existing = $this->find_existing( $package );
			if ( $existing ) {
				wp_delete_attachment( $existing, false );
			}
			return array( 'object_id' => $existing, 'created' => false );
		}

		$source_url = (string) ( $package['source_url'] ?? '' );
		if ( '' === $source_url ) {
			return new WP_Error( 'ifs_deploy_bad_media', __( 'Media package has no source URL.', 'ifs-deploy' ) );
		}

		$attachment = (array) ( $package['attachment'] ?? array() );
		$filename   = (string) ( $package['filename'] ?? '' );
		$existing   = $this->find_existing( $package );

		// Already present → refresh DB fields only; keep the existing file. Logged,
		// because "matched an existing attachment" and "uploaded a new file" look
		// identical from the outside and the difference matters a great deal.
		if ( $existing ) {
			DebugLog::info(
				'Media already present on this site; file not re-downloaded',
				array(
					'attachment' => $existing,
					'filename'   => $filename,
					'source_url' => $source_url,
				)
			);

			$this->apply_fields( $existing, $package, false );
			return array( 'object_id' => $existing, 'created' => false );
		}

		/*
		 * CLAIM THIS FILE BEFORE DOWNLOADING IT.
		 *
		 * `find_existing()` above and `sideload()` below are two steps, and two concurrent
		 * imports referencing the same new attachment both found nothing and both uploaded
		 * it — leaving `image.jpg` and `image-1.jpg` on Production, with content pointing at
		 * whichever won. Two people pushing different pages that share one new image is an
		 * ordinary Tuesday on a fifteen-person team, so this is not a rare race.
		 *
		 * Keyed on the SOURCE URL rather than the origin id: that is what identifies the
		 * file, and it is also what a second request would be about to download.
		 */
		$claim = 'dp_media_' . hash( 'sha256', $source_url );

		if ( false !== get_transient( $claim ) ) {
			/*
			 * Another import is fetching this exact file. Wait briefly rather than refusing:
			 * a download is short, and the caller wants the attachment id, not an error.
			 * Polling is crude but it is bounded, and it beats both alternatives — failing
			 * the object, or downloading a duplicate.
			 */
			for ( $waited = 0; $waited < 15; $waited++ ) {
				sleep( 1 );

				$existing = $this->find_existing( $package );

				if ( $existing ) {
					DebugLog::info(
						'Media was being imported by a concurrent deployment; reused it instead of downloading again',
						array( 'attachment' => $existing, 'source_url' => $source_url )
					);

					$this->apply_fields( $existing, $package, false );

					return array( 'object_id' => $existing, 'created' => false );
				}

				if ( false === get_transient( $claim ) ) {
					// The other request finished or died without producing an attachment.
					// Fall through and do it here.
					break;
				}
			}
		}

		// Short-lived on purpose: it only has to cover one download, and an expiry means a
		// crashed import cannot block this file indefinitely.
		set_transient( $claim, 1, 60 );

		$attachment_id = $this->sideload( $source_url, $filename, $origin_id, $attachment );

		delete_transient( $claim );

		if ( is_wp_error( $attachment_id ) ) {
			DebugLog::error(
				'Media download or upload failed',
				array(
					'source_url' => $source_url,
					'filename'   => $filename,
					'error'      => $attachment_id->get_error_message(),
				)
			);

			return $attachment_id;
		}

		DebugLog::info(
			'Media file uploaded',
			array(
				'attachment' => (int) $attachment_id,
				'filename'   => $filename,
				'stored_as'  => basename( (string) get_attached_file( (int) $attachment_id ) ),
			)
		);

		$this->apply_fields( (int) $attachment_id, $package, true );

		return array( 'object_id' => (int) $attachment_id, 'created' => true );
	}

	/**
	 * Resolve the attachment on THIS site that a package refers to, or 0 for "new".
	 *
	 * Order matters, and it used to be wrong. ID parity was tested first and
	 * unconditionally: if any attachment happened to occupy the incoming id, it was
	 * declared a match and import() then skipped the download entirely ("already
	 * present → keep the existing file"). On sites cloned from one another —
	 * the normal case — attachment ids collide constantly, so uploading a NEW image
	 * on Staging and pushing it silently transferred nothing and still reported
	 * success.
	 *
	 * Now the provable matches come first, and ID parity has to be corroborated:
	 *
	 *   1. `_ifs_deploy_source_url` — this exact file has been imported before.
	 *   2. Origin link — a previous deploy stamped this object from this same site.
	 *   3. ID parity, ONLY if the file already there is plausibly the same file
	 *      (matching filename, and not stamped as belonging to another origin).
	 *
	 * @param array $package Media package.
	 */
	public function find_existing( array $package ): int {
		$origin_id   = (int) ( $package['origin_id'] ?? 0 );
		$origin_site = (string) ( $package['origin_site'] ?? '' );
		$source_url  = (string) ( $package['source_url'] ?? '' );
		$filename    = (string) ( $package['filename'] ?? '' );

		if ( '' !== $source_url ) {
			$by_source = $this->find_by_meta( array( array( 'key' => self::SOURCE_URL_META, 'value' => $source_url ) ) );
			if ( $by_source ) {
				return $by_source;
			}
		}

		if ( $origin_id > 0 && '' !== $origin_site ) {
			$by_origin = $this->find_by_meta(
				array(
					'relation' => 'AND',
					array( 'key' => self::ORIGIN_ID_META, 'value' => $origin_id ),
					array( 'key' => self::ORIGIN_SITE_META, 'value' => $origin_site ),
				)
			);
			if ( $by_origin ) {
				return $by_origin;
			}
		}

		if ( $origin_id > 0 && $this->id_parity_is_same_file( $origin_id, $filename, $origin_site ) ) {
			return $origin_id;
		}

		return 0;
	}

	/**
	 * Is the attachment sitting at this id actually the same file we are importing?
	 *
	 * Two things have to hold. It must not be stamped as coming from a different
	 * origin — that proves it is somebody else's object at a coincidental id. And its
	 * filename must match, which is what separates "the same media on a cloned site"
	 * from "an unrelated image that happens to occupy this id".
	 *
	 * Conservative on purpose: a false match means a file never transfers, which is
	 * silent, whereas a false miss merely uploads a fresh copy.
	 */
	private function id_parity_is_same_file( int $origin_id, string $filename, string $origin_site ): bool {
		$post = get_post( $origin_id );
		if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type ) {
			return false;
		}

		// Stamped from somewhere else, or from a different object → not ours.
		$stamped_site = (string) get_post_meta( $origin_id, self::ORIGIN_SITE_META, true );
		if ( '' !== $stamped_site && $stamped_site !== $origin_site ) {
			return false;
		}

		$stamped_id = (int) get_post_meta( $origin_id, self::ORIGIN_ID_META, true );
		if ( $stamped_id > 0 && $stamped_id !== $origin_id ) {
			return false;
		}

		if ( '' === $filename ) {
			return false;
		}

		$local = basename( (string) get_attached_file( $origin_id ) );

		return '' !== $local && strtolower( $local ) === strtolower( $filename );
	}

	/**
	 * First attachment matching a meta query, or 0.
	 *
	 * @param array $meta_query
	 */
	private function find_by_meta( array $meta_query ): int {
		$query = new \WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => array( $meta_query ),
			)
		);

		return $query->posts ? (int) $query->posts[0] : 0;
	}

	/**
	 * Download the source file and create the attachment, forcing the origin id.
	 *
	 * @return int|WP_Error
	 */
	private function sideload( string $source_url, string $filename, int $origin_id, array $attachment ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		/*
		 * SIZE CAP (SECURITY.md M-5 / L-2).
		 *
		 * `download_url()` imposes no maximum, and `file_get_contents()` below loads the
		 * whole file into PHP's memory — so a signed request naming a very large file could
		 * exhaust the memory limit and take the request down with it.
		 *
		 * Checked in two places, because either alone leaves a gap:
		 *
		 *  1. `Content-Length` from a HEAD request, so an oversized file is refused BEFORE
		 *     it is transferred at all. Cheap, but the header is optional and a server may
		 *     omit it or lie.
		 *  2. The actual file size on disk after the download. Authoritative. The file is
		 *     already written to a temp path by then, which costs disk rather than memory —
		 *     so this catches what step 1 missed, still before anything is read into RAM.
		 */
		$max_bytes = (int) apply_filters( 'ifs_deploy_max_media_bytes', 64 * MB_IN_BYTES );

		if ( $max_bytes > 0 ) {
			$declared = $this->declared_size( $source_url );

			if ( $declared > $max_bytes ) {
				return new WP_Error(
					'ifs_deploy_media_too_large',
					sprintf(
						/* translators: 1: file size, 2: configured limit */
						__( 'The source file is %1$s, which is over the %2$s limit for a single media import. Raise it with the ifs_deploy_max_media_bytes filter if this is expected.', 'ifs-deploy' ),
						size_format( $declared ),
						size_format( $max_bytes )
					)
				);
			}
		}

		$tmp = download_url( $source_url );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		// The authoritative check: what actually arrived, before a single byte is read into
		// memory. The temp file is removed either way.
		$actual = (int) @filesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( $max_bytes > 0 && $actual > $max_bytes ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

			return new WP_Error(
				'ifs_deploy_media_too_large',
				sprintf(
					/* translators: 1: file size, 2: configured limit */
					__( 'The downloaded file is %1$s, which is over the %2$s limit for a single media import.', 'ifs-deploy' ),
					size_format( $actual ),
					size_format( $max_bytes )
				)
			);
		}

		if ( '' === $filename ) {
			$filename = basename( wp_parse_url( $source_url, PHP_URL_PATH ) ?: 'file' );
		}

		// Pass the attachment's own date so the file lands in the same year/month
		// folder as on Staging. Without it wp_upload_bits() uses today's date, so a
		// file uploaded in August but deployed in September would move to 2026/09 —
		// a needless second way for the URL to diverge.
		$upload = wp_upload_bits(
			$filename,
			null,
			(string) file_get_contents( $tmp ), // phpcs:ignore WordPress.WP.AlternativeFunctions
			$this->upload_time( $attachment )
		);
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'ifs_deploy_upload_failed', (string) $upload['error'] );
		}

		$args = array(
			'post_mime_type' => (string) ( $attachment['post_mime_type'] ?? $upload['type'] ),
			'post_title'     => (string) ( $attachment['post_title'] ?? $filename ),
			'post_name'      => (string) ( $attachment['post_name'] ?? '' ),
			'post_excerpt'   => (string) ( $attachment['post_excerpt'] ?? '' ),
			'post_content'   => (string) ( $attachment['post_content'] ?? '' ),
			'post_status'    => 'inherit',
			'guid'           => $upload['url'],
		);

		// Same id across sites so ACF/featured/inline references resolve.
		if ( $origin_id > 0 && ! get_post( $origin_id ) ) {
			$args['import_id'] = $origin_id;
		}

		$attachment_id = wp_insert_attachment( $args, $upload['file'], 0, true );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$metadata = wp_generate_attachment_metadata( (int) $attachment_id, $upload['file'] );
		wp_update_attachment_metadata( (int) $attachment_id, $metadata );

		return (int) $attachment_id;
	}

	/**
	 * What the source says the file's size is, from a HEAD request.
	 *
	 * `wp_safe_remote_head()` rather than `wp_remote_head()`: the URL comes from the
	 * payload, and the safe variant runs `wp_http_validate_url()`, which refuses private
	 * and loopback targets. Using the unsafe one here would open the SSRF hole that
	 * `download_url()` is careful to avoid.
	 *
	 * @return int Bytes, or 0 when the server did not say.
	 */
	private function declared_size( string $url ): int {
		$response = wp_safe_remote_head(
			$url,
			array(
				'timeout'     => 10,
				// Same reasoning as DeployClient: following a redirect would resend the
				// request somewhere we did not validate.
				'redirection' => 0,
			)
		);

		if ( is_wp_error( $response ) ) {
			// Not fatal. A server that refuses HEAD is common, and the authoritative
			// on-disk check after the download still applies.
			return 0;
		}

		return max( 0, (int) wp_remote_retrieve_header( $response, 'content-length' ) );
	}

	/**
	 * The attachment's own upload date, in the form wp_upload_bits() expects, or
	 * null to fall back to today.
	 */
	private function upload_time( array $attachment ): ?string {
		$date = (string) ( $attachment['post_date'] ?? '' );

		return ( '' !== $date && '0000-00-00 00:00:00' !== $date ) ? $date : null;
	}

	/**
	 * Apply alt text, identity stamps, and (on create) parent + transferable meta.
	 */
	private function apply_fields( int $attachment_id, array $package, bool $created ): void {
		$alt = (string) ( $package['alt'] ?? '' );
		if ( '' !== $alt ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		}

		update_post_meta( $attachment_id, self::ORIGIN_ID_META, (int) ( $package['origin_id'] ?? 0 ) );
		update_post_meta( $attachment_id, self::ORIGIN_SITE_META, (string) ( $package['origin_site'] ?? '' ) );
		update_post_meta( $attachment_id, self::SOURCE_URL_META, (string) ( $package['source_url'] ?? '' ) );

		foreach ( (array) ( $package['meta'] ?? array() ) as $key => $values ) {
			$key = (string) $key;
			delete_post_meta( $attachment_id, $key );
			foreach ( (array) $values as $value ) {
				// Slashed on purpose: add_post_meta() unslashes, so raw input would lose
				// every backslash in the value.
				add_post_meta( $attachment_id, $key, wp_slash( SafeData::decode( $value ) ) );
			}
		}
	}
}
