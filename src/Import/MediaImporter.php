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
			// Trashed ones count: a removal often follows one this plugin already trashed.
			$existing = $this->find_existing( $package, true );

			// Same rule as PostImporter::delete_post(): a delete that matched nothing has
			// not succeeded, and reporting it as success is how "the deletion did not
			// reflect on Production" stayed invisible.
			if ( ! $existing ) {
				/*
				 * EVERY INPUT THE THREE STRATEGIES HAD, recorded together.
				 *
				 * "Nothing matched" is the same message whether the file was genuinely never
				 * deployed, the origin stamp is missing because the site was cloned, or the
				 * filename differs because this site renamed it on arrival. Those need
				 * completely different fixes, and telling them apart from the message alone
				 * is impossible — which is how a mismatch here stayed unexplained.
				 */
				DebugLog::error(
					'Media removal matched nothing on this site',
					array(
						'origin_id'      => $origin_id,
						'origin_site'    => $origin_site,
						'source_url'     => (string) ( $package['source_url'] ?? '' ),
						'filename'       => (string) ( $package['filename'] ?? '' ),
						'id_is_taken'    => get_post( $origin_id ) instanceof \WP_Post,
						'local_filename' => MediaIdentity::stable_filename( $origin_id ),
					)
				);

				return new WP_Error(
					'ifs_deploy_delete_no_match',
					sprintf(
						/* translators: %s: file name */
						__( 'No media on this site matched "%s", so there was nothing to delete. It was probably never deployed here — Logs & Diagnostics on this site records what was searched for.', 'ifs-deploy' ),
						(string) ( $package['filename'] ?? $package['attachment']['post_title'] ?? '' )
					)
				);
			}

			/*
			 * MIRROR THE INTENT. Trashed on Staging means trashed here.
			 *
			 * This used to be `wp_delete_attachment( $existing, false )` and nothing else,
			 * on the reasoning that the receiving site should decide what "remove" means.
			 * That destroyed files people expected to be able to restore, because
			 * `MEDIA_TRASH` DEFAULTS TO FALSE:
			 *
			 *     // wp-includes/default-constants.php
			 *     if ( ! defined( 'MEDIA_TRASH' ) ) {
			 *         define( 'MEDIA_TRASH', false );
			 *     }
			 *
			 * So on any Production that had not explicitly switched media trash on — which
			 * is most of them — `force = false` fell through to a permanent delete. Trashing
			 * an image on Staging and pushing it wiped the file from the live site with no
			 * trash entry to recover from, while the deploy reported success.
			 *
			 * Recoverability belongs to the action the operator took, not to the receiving
			 * site's wp-config. `wp_trash_post()` is called DIRECTLY rather than through
			 * `wp_delete_attachment( $id, false )` precisely so it does not consult
			 * `MEDIA_TRASH` here.
			 */
			// Defaults to the RECOVERABLE reading when the field is absent, which is what a
			// package built by a Staging site older than this one looks like. Guessing
			// wrong toward trash leaves a file to empty; guessing wrong toward delete
			// destroys one.
			if ( 'delete' !== ( $package['removal'] ?? 'trash' ) ) {
				$post = get_post( $existing );

				// Matched an id whose post cannot be read. Reporting success here would be
				// the same silent lie the no-match branch above exists to prevent.
				if ( ! $post instanceof \WP_Post ) {
					return new WP_Error(
						'ifs_deploy_delete_unreadable',
						sprintf(
							/* translators: %d: attachment id */
							__( 'Media #%d was matched on this site but could not be read, so it was not trashed.', 'ifs-deploy' ),
							$existing
						)
					);
				}

				// Already trashed here → nothing to do, and that IS the requested state.
				// Reporting it as a failure would make a second push of the same removal
				// look like a broken deploy.
				if ( 'trash' !== $post->post_status && ! wp_trash_post( $existing ) ) {
					/*
					 * `wp_trash_post()` returns false when it did not trash. The one case
					 * that reaches here is `EMPTY_TRASH_DAYS === 0`, where core redirects it
					 * to a permanent delete — so the object is gone rather than trashed, and
					 * calling that a success would misreport what this site did with it.
					 */
					if ( get_post( $existing ) instanceof \WP_Post ) {
						return new WP_Error(
							'ifs_deploy_trash_refused',
							sprintf(
								/* translators: %d: attachment id */
								__( 'Media #%d could not be moved to the Trash on this site.', 'ifs-deploy' ),
								$existing
							)
						);
					}

					DebugLog::warning(
						'Trash is disabled on this site (EMPTY_TRASH_DAYS is 0), so the media was deleted outright rather than trashed',
						array( 'attachment' => $existing )
					);
				}

				return array( 'object_id' => $existing, 'created' => false );
			}

			// Permanent on Staging is permanent here. force = TRUE, so this does not stop
			// at the trash on a site that has MEDIA_TRASH enabled — the instruction was to
			// destroy it, and leaving a copy behind would be its own silent divergence.
			wp_delete_attachment( $existing, true );

			return array( 'object_id' => $existing, 'created' => false );
		}

		$source_url = (string) ( $package['source_url'] ?? '' );
		if ( '' === $source_url ) {
			return new WP_Error( 'ifs_deploy_bad_media', __( 'Media package has no source URL.', 'ifs-deploy' ) );
		}

		$attachment = (array) ( $package['attachment'] ?? array() );
		$filename   = (string) ( $package['filename'] ?? '' );

		/*
		 * Trashed ones count here too, and the reason is a duplicate rather than a miss.
		 *
		 * Trash an image on Staging, push it (Production trashes it), then restore it on
		 * Staging and push again. Matching only live attachments would find nothing, so
		 * this would download the file a second time — and `import_id` would be refused
		 * because the trashed original still occupies that id. The result is two copies of
		 * one file, at two different ids, one of them in the trash.
		 */
		$existing = $this->find_existing( $package, true );

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

			$this->restore_if_trashed( $existing );
			$this->apply_fields( $existing, $package, false );
			return array( 'object_id' => $existing, 'created' => false );
		}

		/*
		 * NOTHING HERE MATCHED, SO THIS FILE IS ABOUT TO BE CREATED. IS ITS ID FREE?
		 *
		 * ── WHAT WAS ACTUALLY WRONG ────────────────────────────────────────────────────
		 *
		 * `sideload()` asks for the Staging id via `import_id`, but only when that id is
		 * free — otherwise it quietly let WordPress allocate a fresh one. So an operator
		 * who uploaded an image as id 1 on Staging could find it sitting at id 5 on
		 * Production, with nothing anywhere saying so. The IDs were never "not synced":
		 * they are synced whenever they CAN be, and the failure to sync them was silent.
		 *
		 * That silence is the whole bug. A mismatched id is not cosmetic — this importer
		 * copies meta verbatim, and ACF image fields, gallery fields, `wp-image-N` classes
		 * and Gutenberg block attributes all store the bare number. An image at a different
		 * id on Production means every one of those references points somewhere else.
		 *
		 * ── WHY THIS REFUSES RATHER THAN RENUMBERING ──────────────────────────────────
		 *
		 * Nothing can be done about it from HERE. The id is occupied by a real object on
		 * this site — moving that object out of the way would break its own references, and
		 * WordPress has no API for renumbering anything. The only site that can still act
		 * is Staging, where the attachment may not be referenced yet.
		 *
		 * So this refuses BEFORE the download, and says exactly what is in the way. No file
		 * is fetched, no attachment is created, and the operator gets the choice rather
		 * than a surprise.
		 *
		 * ── WHY ONLY NEW MEDIA ────────────────────────────────────────────────────────
		 *
		 * Everything above this point returned already. Media that has been deployed before
		 * is matched by source URL or origin stamp and never reaches here, so an id
		 * mismatch that already exists keeps working exactly as it does today. This can
		 * only ever refuse a file that has never been on this site.
		 */
		$conflict = $this->id_conflict( $origin_id );

		if ( $conflict instanceof WP_Error ) {
			return $conflict;
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
	 * ── WHY TRASHED ATTACHMENTS CAN BE INCLUDED ────────────────────────────────────
	 *
	 * `post_status => 'inherit'` is what a live attachment has; a TRASHED one is
	 * `'trash'` and so is invisible to that filter. That was harmless only for as long as
	 * a pushed removal destroyed the file outright. Now that a trash on Staging genuinely
	 * trashes on Production, an attachment this plugin itself put in Production's trash
	 * could no longer be found — so the follow-up permanent delete reported "nothing
	 * matched, it was probably never deployed here" about a file it had trashed minutes
	 * earlier, and re-uploading it created a duplicate at a fresh id.
	 *
	 * Off by default, so the preview endpoint and every other caller keep the exact
	 * behaviour they had. Both import paths opt in.
	 *
	 * @param array $package         Media package.
	 * @param bool  $include_trashed Also match attachments sitting in this site's trash.
	 */
	public function find_existing( array $package, bool $include_trashed = false ): int {
		$origin_id   = (int) ( $package['origin_id'] ?? 0 );
		$origin_site = (string) ( $package['origin_site'] ?? '' );
		$source_url  = (string) ( $package['source_url'] ?? '' );
		$filename    = (string) ( $package['filename'] ?? '' );
		$statuses    = $include_trashed ? array( 'inherit', 'trash' ) : array( 'inherit' );

		if ( '' !== $source_url ) {
			$by_source = $this->find_by_meta( array( array( 'key' => self::SOURCE_URL_META, 'value' => $source_url ) ), $statuses );
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
				),
				$statuses
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
	 * Refuse to create a new attachment at an id this site has already given away.
	 *
	 * Returns null when the id is free (or when there is no id to honour), and a WP_Error
	 * naming the occupant when it is not. The error carries structured data as well as a
	 * sentence, because Staging turns it into a dialog offering to renumber — see
	 * `IdSpaceEndpoint` and `MediaReferences`.
	 *
	 * @return WP_Error|null
	 */
	private function id_conflict( int $origin_id ) {
		/**
		 * Filter whether a new attachment must land on the same id it has on Staging.
		 *
		 * Sites that do not store bare attachment ids anywhere — no ACF image fields, no
		 * galleries, no inline `wp-image-N` classes — can switch this off and let
		 * WordPress allocate ids freely, which is what happened silently before.
		 *
		 * @param bool $required True to refuse the import on a conflict.
		 * @param int  $origin_id The Staging attachment id.
		 */
		if ( $origin_id <= 0 || ! apply_filters( 'ifs_deploy_require_media_id_parity', true, $origin_id ) ) {
			return null;
		}

		$occupant = get_post( $origin_id );

		if ( ! $occupant instanceof \WP_Post ) {
			return null;
		}

		DebugLog::warning(
			'Refused a new media import because its Staging id is already taken on this site',
			array(
				'staging_id'    => $origin_id,
				'occupied_by'   => $occupant->ID,
				'occupant_type' => $occupant->post_type,
				'occupant_title'=> $occupant->post_title,
			)
		);

		return new WP_Error(
			'ifs_deploy_media_id_taken',
			sprintf(
				/* translators: 1: attachment id, 2: post type of the object already at that id, 3: its title */
				__( 'Media ID mismatch: Staging ID %1$d is not available on production — it is already used by a %2$s, "%3$s". Please resolve the ID conflict before pushing.', 'ifs-deploy' ),
				$origin_id,
				$occupant->post_type,
				$occupant->post_title
			),
			array(
				'staging_id' => $origin_id,
				'occupant'   => array(
					'id'     => (int) $occupant->ID,
					'type'   => (string) $occupant->post_type,
					'title'  => (string) $occupant->post_title,
					'status' => (string) $occupant->post_status,
				),
			)
		);
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

		/*
		 * COMPARED BY THE ORIGINAL NAME, NOT THE ONE ON DISK HERE.
		 *
		 * `wp_upload_bits()` never overwrites, so a file that arrived as `photo.png` is
		 * stored as `photo-1.png` whenever this site already held an unrelated `photo.png`.
		 * Comparing the local name then failed for the rest of that file's life:
		 *
		 *   Staging sends  photo.png
		 *   Production has photo-1.png   → no match, on every push, for ever
		 *
		 * On a site whose attachments carry no origin stamp — a clone rather than a
		 * deployment — id parity is the ONLY strategy left, so this comparison failing
		 * meant the object could not be found at all. A removal then reported "nothing
		 * matched, it was probably never deployed here" about a file plainly sitting there
		 * at the very same id, and because the match also feeds `snapshot_for()`, no
		 * restore point was recorded and the History screen offered no Rollback button.
		 * One comparison, both symptoms.
		 *
		 * `MediaIdentity::stable_filename()` reads the name back out of the recorded source
		 * URL and falls back to the local name only when there is no stamp — which is what
		 * keeps this precise rather than lax: a `photo-1.png` genuinely uploaded here by
		 * hand still compares as different, because it really is a different image.
		 */
		$local = MediaIdentity::stable_filename( $origin_id );

		return '' !== $local && strtolower( $local ) === strtolower( $filename );
	}

	/**
	 * First attachment matching a meta query, or 0.
	 *
	 * @param array $meta_query
	 */
	private function find_by_meta( array $meta_query, array $statuses = array( 'inherit' ) ): int {
		$query = new \WP_Query(
			array(
				'post_type'              => 'attachment',
				/*
				 * Listed explicitly rather than using 'any', which would NOT include
				 * 'trash' — WordPress builds 'any' from statuses that are not flagged
				 * `exclude_from_search`, and trash is exactly such a status.
				 */
				'post_status'            => $statuses,
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

		$upload = $this->store_file( $tmp, $filename, $this->upload_time( $attachment ) );
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
	 * Move a downloaded file into the uploads directory WITHOUT reading it into memory.
	 *
	 * ── WHY THIS REPLACED wp_upload_bits() ─────────────────────────────────────────
	 *
	 * That call needs the file's CONTENTS as a string, so this used to run
	 * `file_get_contents( $tmp )` and hand the whole thing over — PHP then held the entire
	 * file in memory and wrote it straight back out to disk. A 40 MB upload therefore
	 * needed 40 MB of memory, often twice over while the string was copied, on top of
	 * everything WordPress already has loaded. That is exactly where a large media push
	 * died, and it died with a fatal rather than a message.
	 *
	 * The file is already on disk when we get here — `download_url()` put it there. Moving
	 * it costs no memory at all whatever its size, so the limit stops being PHP's memory
	 * and goes back to being the configured maximum.
	 *
	 * Everything wp_upload_bits() did that matters is kept:
	 *
	 *  - the attachment's OWN date decides the year/month folder, so a file uploaded in
	 *    August but deployed in September does not land in `2026/09` and diverge (§12);
	 *  - `wp_unique_filename()` gives the same non-overwriting behaviour, which
	 *    `MediaUrlResolver` and `MediaIdentity` both depend on;
	 *  - the return shape is `wp_upload_bits()`'s, so the caller is unchanged.
	 *
	 * `rename()` first because it is atomic on the same filesystem; `copy()` as the
	 * fallback, since the temp directory is not always on the same mount.
	 *
	 * @return array{file:string,url:string,type:string,error:string|false}
	 */
	private function store_file( string $tmp, string $filename, ?string $time ): array {
		$dir = wp_upload_dir( $time );

		if ( ! empty( $dir['error'] ) ) {
			return array( 'file' => '', 'url' => '', 'type' => '', 'error' => (string) $dir['error'] );
		}

		$filename = wp_unique_filename( $dir['path'], $filename );
		$target   = $dir['path'] . '/' . $filename;

		if ( ! @rename( $tmp, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( ! @copy( $tmp, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				return array(
					'file'  => '',
					'url'   => '',
					'type'  => '',
					'error' => sprintf(
						/* translators: %s: destination directory */
						__( 'The uploads directory could not be written to (%s). Check its permissions on this site.', 'ifs-deploy' ),
						$dir['path']
					),
				);
			}
		}

		// Match what WordPress gives its own uploads, or the file is unreadable over HTTP
		// on hosts whose umask is restrictive.
		$permissions = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : ( fileperms( ABSPATH . 'index.php' ) & 0777 | 0644 );
		@chmod( $target, $permissions ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		$type = wp_check_filetype( $filename );

		return array(
			'file'  => $target,
			'url'   => $dir['url'] . '/' . $filename,
			'type'  => (string) ( $type['type'] ?? '' ),
			'error' => false,
		);
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
	/**
	 * Bring a matched attachment back out of this site's trash.
	 *
	 * An UPDATE says the file should exist on Production. If the match is sitting in the
	 * trash — almost always because this plugin put it there for an earlier removal that
	 * has since been undone on Staging — refreshing its fields and leaving it trashed
	 * would report success while the image stayed missing from every page using it.
	 *
	 * `wp_untrash_post()` restores the status WordPress recorded in `_wp_trash_meta_status`
	 * at trash time, which for an attachment is `inherit`.
	 */
	private function restore_if_trashed( int $attachment_id ): void {
		$post = get_post( $attachment_id );

		if ( ! $post instanceof \WP_Post || 'trash' !== $post->post_status ) {
			return;
		}

		wp_untrash_post( $attachment_id );

		DebugLog::info(
			'Media was in this site\'s trash and has been restored by an update',
			array( 'attachment' => $attachment_id )
		);
	}

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
