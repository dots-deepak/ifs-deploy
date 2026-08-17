<?php
declare(strict_types=1);

namespace IfsDeploy\Detection;

use IfsDeploy\Export\MediaExporter;
use IfsDeploy\Queue\Hasher;
use IfsDeploy\Queue\QueueRepository;
use IfsDeploy\Support\DebugLog;

/**
 * Observes media library changes (upload / edit / delete) and feeds the queue.
 */
final class AttachmentObserver {

	private QueueRepository $queue;
	private MediaExporter $exporter;

	public function __construct( ?QueueRepository $queue = null, ?MediaExporter $exporter = null ) {
		$this->queue    = $queue ?? new QueueRepository();
		$this->exporter = $exporter ?? new MediaExporter();
	}

	public function on_change( int $attachment_id ): void {
		if ( ! $this->should_track( $attachment_id ) ) {
			return;
		}

		/*
		 * A TRASHED ATTACHMENT IS A REMOVAL, NOT AN EDIT.
		 *
		 * Exactly the trap `PostObserver` has: `wp_trash_post()` finishes by calling
		 * `wp_update_post()`, which fires `edit_attachment` for an attachment. So the
		 * delete row written a moment earlier by `on_trash()` would be overwritten by the
		 * save that trashing itself performs, and the queue would hold an "update"
		 * carrying a package whose status happens to be `trash`.
		 *
		 * Restoring is unaffected: `wp_untrash_post()` fires the same hook with the
		 * RESTORED status, so a recovered item is queued as the update it is.
		 */
		$post = get_post( $attachment_id );

		if ( $post instanceof \WP_Post && 'trash' === $post->post_status ) {
			return;
		}

		/*
		 * SEVERAL ATTACHMENT RECORDS CAN POINT AT ONE FILE — track it once.
		 *
		 * Reported as seven pending changes for a single image: seven attachment ids,
		 * consecutive, all with `_wp_attached_file` = `sstest-1.png`. Something on the
		 * site had created seven attachment posts for one file.
		 *
		 * Merging them is not cosmetic tidying, it is what a push ACTUALLY does.
		 * `MediaImporter::find_existing()` resolves an incoming attachment by its
		 * recorded source URL first, and all seven have the same source URL because they
		 * have the same file — so deploying all seven produces exactly ONE attachment on
		 * Production, each one overwriting the last. Listing seven therefore misdescribes
		 * the outcome.
		 *
		 * Every duplicate redirects to the LOWEST id, which makes the choice
		 * deterministic: whichever record is saved, and in whatever order, all of them
		 * converge on the same single row. The lowest id is also the original record —
		 * the duplicates are the artefacts.
		 */
		$translated = $this->translation_original( $attachment_id );
		$sharing    = $this->attachments_sharing_file( $attachment_id );

		// A translation relationship is stated by the translation plugin itself, so it
		// beats inferring one from a shared file.
		$canonical = $translated > 0 ? $translated : $this->canonical_of( $sharing, $attachment_id );

		if ( $canonical !== $attachment_id ) {
			// debug(), not info(): on a multilingual site this fires once per language for
			// every media save. It is the entry that identified WPML in the first place,
			// so it stays available — behind "Detailed logging".
			DebugLog::debug(
				$translated > 0
					? 'A translated copy of a media item; tracking the original only'
					: 'Several attachment records share one file; tracking the original only',
				array(
					'attachment' => $attachment_id,
					'status'     => (string) get_post_status( $attachment_id ),
					'tracking'   => $canonical,
					'reason'     => $translated > 0 ? 'translation' : 'same file',
					'file'       => (string) get_post_meta( $attachment_id, '_wp_attached_file', true ),
					// The scale, and the ids themselves — so the duplicates can be found
					// in the Media Library if they ever need to be.
					'sharing'    => count( $sharing ) . ' records: ' . implode( ', ', $sharing ),
					'hook'       => (string) current_filter(),
					'triggered'  => $this->caller(),
				)
			);

			// A row this duplicate left behind before the rule existed would otherwise
			// keep listing it for ever.
			$this->queue->forget( 'media', $attachment_id );

			$attachment_id = $canonical;
		}

		$package = $this->exporter->export( $attachment_id );
		if ( null === $package ) {
			return;
		}

		$post  = get_post( $attachment_id );
		$title = ( $post instanceof \WP_Post ) ? $post->post_title : '';

		$queued = $this->queue->upsert( 'media', (string) $package['subtype'], $attachment_id, $title, 'update', Hasher::hash( $package ) );

		/*
		 * EVERY media queue write is recorded, naming the FILE and the HOOK.
		 *
		 * Editing one image was reported as producing eight tracked items. The ids turned
		 * out to be eight consecutive attachments created in the same second, so the
		 * queue was tracking eight real objects — something else on the site was creating
		 * the other seven, and no amount of de-duplication in here can fix that.
		 *
		 * Guessing which plugin does it from the outside is hopeless, so this says what
		 * is actually happening: the file each attachment points at answers it outright.
		 * Seven files called `sstest.png`, `sstest-1.png`… means seven separate uploads;
		 * `sstest-300x200.png` means generated sizes registered as attachments; the same
		 * path repeated means duplicate rows for one file. `current_filter()` names which
		 * hook fired, and the call stack names what triggered it.
		 */
		if ( $queued ) {
			DebugLog::debug(
				'Media change tracked',
				array(
					'attachment' => $attachment_id,
					'title'      => $title,
					'file'       => (string) get_post_meta( $attachment_id, '_wp_attached_file', true ),
					'mime'       => (string) $package['subtype'],
					'parent'     => (int) ( $post instanceof \WP_Post ? $post->post_parent : 0 ),
					'hook'       => (string) current_filter(),
					'triggered'  => $this->caller(),
				)
			);
		}
	}

	/**
	 * Should this attachment be tracked at all?
	 *
	 * One built-in rule, plus a filter for whatever a given site turns out to need.
	 *
	 * The built-in rule is that a GENERATED SIZE is never tracked. IFS Deploy transfers
	 * the original file and lets Production regenerate its own sizes (see §12 — the
	 * exporter deliberately withholds `_wp_attachment_metadata` for exactly this
	 * reason), so a `-300x200` derivative is not content: it is output. Some plugins
	 * register those derivatives as real attachments, and each one then looks like its
	 * own media object with its own pending change.
	 *
	 * Anything else can be excluded per site:
	 *
	 *     add_filter( 'ifs_deploy_track_attachment', function ( $track, $id ) {
	 *         return $track && ! get_post_meta( $id, '_my_plugin_generated', true );
	 *     }, 10, 2 );
	 */
	private function should_track( int $attachment_id ): bool {
		$track = ! $this->is_generated_size( $attachment_id ) && ! $this->is_package_file( $attachment_id );

		/**
		 * Filter whether an attachment is tracked for deployment.
		 *
		 * @param bool $track
		 * @param int  $attachment_id
		 */
		$track = (bool) apply_filters( 'ifs_deploy_track_attachment', $track, $attachment_id );

		if ( ! $track ) {
			DebugLog::debug(
				'Media change ignored',
				array(
					'attachment' => $attachment_id,
					'file'       => (string) get_post_meta( $attachment_id, '_wp_attached_file', true ),
					'hook'       => (string) current_filter(),
				)
			);
		}

		return $track;
	}

	/**
	 * Is this an archive or executable rather than content?
	 *
	 * Uploading a plugin or theme ZIP was producing a pending change, and that is wrong on
	 * purpose-grounds rather than merely untidy: **IFS Deploy does not deploy code.** That
	 * is a deliberate boundary of the whole plugin — code belongs in version control, and
	 * a deploy that could ship a plugin ZIP to Production would quietly become a way to
	 * install software on the live site through the content channel.
	 *
	 * WordPress's own plugin installer does not create an attachment, so a ZIP that
	 * reaches here arrived some other way — through the Media Library, or through an
	 * uploader that routes everything into it. Either way it is not content to deploy.
	 *
	 * Matched by MIME first, with an extension fallback because `application/octet-stream`
	 * is what a server reports when it does not recognise a file — and an unrecognised
	 * binary is exactly what should not be deployed either.
	 *
	 * A site that genuinely publishes a downloadable ZIP can put it back with the
	 * documented filter:
	 *
	 *     add_filter( 'ifs_deploy_track_attachment', function ( $track, $id ) {
	 *         return get_post_mime_type( $id ) === 'application/zip' ? true : $track;
	 *     }, 10, 2 );
	 */
	private function is_package_file( int $attachment_id ): bool {
		$mime = strtolower( (string) get_post_mime_type( $attachment_id ) );

		$blocked_mimes = array(
			'application/zip',
			'application/x-zip-compressed',
			'multipart/x-zip',
			'application/gzip',
			'application/x-gzip',
			'application/x-tar',
			'application/x-bzip2',
			'application/x-rar-compressed',
			'application/vnd.rar',
			'application/x-7z-compressed',
			'application/java-archive',
			'application/x-msdownload',
			'application/x-executable',
			'application/octet-stream',
		);

		if ( in_array( $mime, $blocked_mimes, true ) ) {
			return true;
		}

		$file      = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$extension = strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) );

		return in_array( $extension, array( 'zip', 'gz', 'tgz', 'tar', 'bz2', 'rar', '7z', 'exe', 'jar', 'phar', 'php' ), true );
	}

	/**
	 * The attachment this one is a TRANSLATED COPY of, or 0.
	 *
	 * ── WHY THIS EXISTS ────────────────────────────────────────────────────────────
	 *
	 * A site reported one image edit producing eight pending changes. The log named the
	 * cause outright:
	 *
	 *     plugins/sitepress-multilingual-cms/classes/media/duplication/
	 *         class-wpml-media-attachments-duplication.php:287 wp_update_post()
	 *
	 * WPML's media duplication gives every language its own attachment record for the
	 * SAME file — eight languages, eight records, one image. They are not eight pieces of
	 * content: the file is identical, and on a multilingual Production site WPML creates
	 * its own copies from the original anyway. Deploying all eight transfers one file
	 * eight times and lets the last one win.
	 *
	 * `wpml_original_element_id` is WPML's own documented filter, so the relationship is
	 * read from the plugin that owns it rather than guessed. That matters over the
	 * shared-file heuristic below in two ways: it identifies the ORIGINAL rather than
	 * assuming the lowest id is it, and it still works in the WPML configurations that
	 * duplicate the FILE as well as the record, where no shared file exists to spot.
	 *
	 * Returns 0 when WPML is absent (the filter is unregistered, so the null default
	 * comes straight back), when this attachment IS the original, or for any other
	 * translation plugin registering the same filter — which is a feature, not an
	 * accident: nothing here is WPML-specific beyond the filter name.
	 */
	private function translation_original( int $attachment_id ): int {
		/**
		 * The original element a translation belongs to.
		 *
		 * Provided by WPML; any plugin implementing the same contract works too.
		 *
		 * @param mixed  $original     Null by default.
		 * @param int    $element_id
		 * @param string $element_type
		 */
		$original = apply_filters( 'wpml_original_element_id', null, $attachment_id, 'post_attachment' );

		if ( ! is_numeric( $original ) ) {
			return 0;
		}

		$original = (int) $original;

		// Guard both ends: the original reports itself, and a broken answer must never
		// redirect tracking to something that does not exist.
		if ( $original <= 0 || $original === $attachment_id || null === get_post( $original ) ) {
			return 0;
		}

		return $original;
	}

	/**
	 * Every attachment id pointing at this attachment's file, lowest first.
	 *
	 * Empty when the file is unknown, so a record with nothing to group on is left
	 * strictly alone — "duplicate" cannot be established without a file, and this must
	 * never guess. The ordinary case costs one indexed meta query and returns a single id.
	 *
	 * @return int[]
	 */
	private function attachments_sharing_file( int $attachment_id ): array {
		$file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );

		if ( '' === $file ) {
			return array();
		}

		$query = new \WP_Query(
			array(
				'post_type' => 'attachment',

				/*
				 * ANY status, not just `inherit`.
				 *
				 * `inherit` is the normal status for an attachment, and restricting to it
				 * made this miss the very records it exists to find: a live site reported
				 * "sharing: 1" for a file that eight records pointed at, because the seven
				 * duplicates were in some other status. Whatever created them did not
				 * create them the way WordPress does.
				 */
				'post_status'            => 'any',
				'posts_per_page'         => 50,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array(
						'key'   => '_wp_attached_file',
						'value' => $file,
					),
				),
			)
		);

		$ids = array_map( 'intval', (array) $query->posts );

		// Always counts itself. Without this the log understated the problem, and a record
		// the query cannot see would silently be treated as having no siblings.
		$ids[] = $attachment_id;

		$ids = array_values( array_unique( $ids ) );
		sort( $ids );

		return $ids;
	}

	/**
	 * Which of the records sharing a file is the one to track.
	 *
	 * The lowest LIVE (`inherit`) record, because that is the original a deploy should
	 * carry — falling back to the lowest id of any status when none is live, so the
	 * choice stays deterministic either way. Deterministic matters more than it sounds:
	 * every duplicate must agree on the same answer, or saving them in a different order
	 * would produce a different row.
	 *
	 * @param int[] $ids Sorted ascending.
	 */
	private function canonical_of( array $ids, int $fallback ): int {
		if ( empty( $ids ) ) {
			return $fallback;
		}

		foreach ( $ids as $id ) {
			if ( 'inherit' === get_post_status( $id ) ) {
				return $id;
			}
		}

		return min( $ids );
	}

	/**
	 * Is this attachment's file a generated size of ANOTHER attachment's file?
	 *
	 * Both halves are required, and the second is what keeps it precise: a real upload
	 * genuinely called `banner-1920x1080.jpg` matches the suffix pattern too, and must
	 * not be silently dropped. It is only treated as a derivative when the un-suffixed
	 * file exists as an attachment in its own right — which is what makes it a
	 * derivative OF something.
	 */
	private function is_generated_size( int $attachment_id ): bool {
		$file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );

		if ( '' === $file || ! preg_match( '/^(.*)-\d+x\d+(\.[A-Za-z0-9]+)$/', $file, $matches ) ) {
			return false;
		}

		$original = $matches[1] . $matches[2];

		$query = new \WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array(
						'key'   => '_wp_attached_file',
						'value' => $original,
					),
				),
			)
		);

		return ! empty( $query->posts );
	}

	/**
	 * WHICH PLUGIN OR THEME caused this save.
	 *
	 * The first attempt reported the first frame that was not this plugin's, and that
	 * answered "wp_insert_post" every time — WordPress's own function, which is what
	 * fires `edit_attachment` in the first place. True, and useless.
	 *
	 * So the rule is by FILE, not by function: walk out through everything living in
	 * `wp-includes` and `wp-admin` and report the first frame under `wp-content`, which
	 * is by definition a plugin, mu-plugin or theme. `plugins/imagify/inc/media.php:120`
	 * names the culprit outright, where a function name cannot.
	 *
	 * IfsDeploy's own frames are deliberately NOT skipped. Excluding them would mean
	 * this can never implicate the one plugin whose behaviour is being investigated —
	 * exactly the blind spot worth avoiding.
	 *
	 * Depth is generous (30) because the interesting frame sits above several layers of
	 * core hook dispatch. `DEBUG_BACKTRACE_IGNORE_ARGS` is not optional: argument values
	 * here would include post content and could include credentials.
	 */
	private function caller(): string {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
		$frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 30 );

		/*
		 * ONLY LOOK ABOVE THE HOOK DISPATCH.
		 *
		 * A frame's `file`/`line` describe where the function IN THAT FRAME was called
		 * FROM, not where it lives. So frame 0 is `caller()` reported at its own call
		 * site inside this very file — which is under wp-content, and which an earlier
		 * version of this method therefore returned as "the culprit". It named itself,
		 * every time.
		 *
		 * Everything below `do_action()` is the hook machinery delivering the event to
		 * us; nothing there caused anything. The frames ABOVE it are the real chain:
		 * do_action was called by wp_insert_post, which was called by whatever actually
		 * saved the attachment. The first of those living under wp-content is the answer.
		 */
		$past_hook = false;
		$fallback  = '';

		foreach ( $frames as $frame ) {
			$function = (string) ( $frame['function'] ?? '' );

			if ( ! $past_hook ) {
				if ( in_array( $function, array( 'do_action', 'do_action_ref_array' ), true ) ) {
					$past_hook = true;
				}
				continue;
			}

			$file = (string) ( $frame['file'] ?? '' );

			if ( '' === $file ) {
				continue;
			}

			$path = str_replace( '\\', '/', $file );
			$line = (int) ( $frame['line'] ?? 0 );
			$at   = strpos( $path, '/wp-content/' );

			if ( false !== $at ) {
				// Everything after wp-content/, so the answer is short and names the
				// plugin or theme directory outright. IfsDeploy is NOT excluded: above
				// the hook it would be a genuine cause, and excluding it would create
				// exactly the blind spot worth avoiding.
				return sprintf( '%s:%d %s()', substr( $path, $at + strlen( '/wp-content/' ) ), $line, $function );
			}

			// Core all the way out — a cron tick or a REST route, say. Report the
			// outermost core frame rather than nothing at all.
			$fallback = sprintf( 'wp-core %s()', $function );
		}

		return '' !== $fallback ? $fallback : 'unknown (no hook dispatch in stack)';
	}

	/**
	 * Media moved to Trash — which on many sites is what "delete" actually does.
	 *
	 * ── WHY THIS HOOK WAS MISSING, AND WHAT IT COST ────────────────────────────────
	 *
	 * `wp_delete_attachment()` begins:
	 *
	 *     if ( ! $force_delete && MEDIA_TRASH && EMPTY_TRASH_DAYS ) {
	 *         return wp_trash_post( $post_id );
	 *     }
	 *     do_action( 'delete_attachment', $post_id );
	 *
	 * So on a site with `MEDIA_TRASH` enabled, trashing an image returns EARLY and
	 * `delete_attachment` — the only media hook this class listened to — never fires at
	 * all. Removing media was simply not tracked: no queue row, nothing to push, and
	 * nothing on screen to suggest anything was missing.
	 *
	 * `wp_trash_post` fires for every post type, so this checks the type itself rather
	 * than assuming.
	 *
	 * The attachment still EXISTS at this point, which is worth more than it sounds: its
	 * URL and filename can be read and sent with the delete package, so the far side can
	 * identify it by recorded source URL — the strongest match there is — rather than
	 * depending on an origin stamp it may never have been given.
	 */
	public function on_trash( int $post_id ): void {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type ) {
			return;
		}

		$this->queue_removal( $post );
	}

	public function on_delete( int $attachment_id ): void {
		$post = get_post( $attachment_id );
		if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type ) {
			return;
		}

		/*
		 * THE SAME RULE AS on_change(), and it was missing here.
		 *
		 * Blocking plugin/theme archives on upload but not on delete meant a ZIP that was
		 * never tracked in the first place still produced a "delete" pending change when it
		 * was removed — a deploy row proposing to delete something Production had never
		 * been given. Reported with `ifs-deploy-0.7.0.zip` sitting in Pending Changes.
		 *
		 * A half-applied rule is worse than no rule: it looks handled while the other half
		 * of the object's life goes on producing exactly what it was meant to stop.
		 */
		if ( ! $this->should_track( $attachment_id ) ) {
			return;
		}

		$this->queue_removal( $post );
	}

	/**
	 * Queue "this media is going away", however it is going.
	 *
	 * Trash and permanent delete arrive on different hooks, and the difference between
	 * them DOES travel — but not from here. `DeploymentService::removal_intent()` reads it
	 * at push time from whether the attachment still exists, which is both simpler than
	 * threading it through the queue and more accurate: trashing an image and then
	 * emptying the trash before pushing collapses to one row by hash, and only the final
	 * state should be sent.
	 *
	 * So both hooks queue the same thing on purpose. One method so they cannot drift.
	 */
	private function queue_removal( \WP_Post $post ): void {
		$this->queue->upsert(
			'media',
			(string) $post->post_mime_type,
			(int) $post->ID,
			(string) $post->post_title,
			'delete',
			// Hash the action, so a later re-upload supersedes the removal row.
			md5( 'delete:media:' . $post->ID )
		);
	}
}
