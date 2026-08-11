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
		$canonical = $this->canonical_for_file( $attachment_id );

		if ( $canonical !== $attachment_id ) {
			DebugLog::info(
				'Several attachment records share one file; tracking the original only',
				array(
					'attachment' => $attachment_id,
					'tracking'   => $canonical,
					'file'       => (string) get_post_meta( $attachment_id, '_wp_attached_file', true ),
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
			DebugLog::info(
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
		$track = ! $this->is_generated_size( $attachment_id );

		/**
		 * Filter whether an attachment is tracked for deployment.
		 *
		 * @param bool $track
		 * @param int  $attachment_id
		 */
		$track = (bool) apply_filters( 'ifs_deploy_track_attachment', $track, $attachment_id );

		if ( ! $track ) {
			DebugLog::info(
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
	 * The lowest attachment id sharing this attachment's file — itself, normally.
	 *
	 * Returns the given id unchanged when the file is unknown or unique, so the ordinary
	 * case costs one indexed meta query and changes nothing.
	 */
	private function canonical_for_file( int $attachment_id ): int {
		$file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );

		if ( '' === $file ) {
			return $attachment_id;
		}

		$query = new \WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
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

		return empty( $ids ) ? $attachment_id : min( $ids );
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
	 * The first frame outside this plugin, so the log names what caused the save.
	 *
	 * Limited to a shallow stack and reduced to "Class::method" — enough to identify a
	 * theme or plugin, and nothing that could carry argument values into the log.
	 */
	private function caller(): string {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
		$frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 12 );

		foreach ( $frames as $frame ) {
			$class = (string) ( $frame['class'] ?? '' );

			if ( '' !== $class && 0 === strpos( $class, 'IfsDeploy\\' ) ) {
				continue;
			}

			$function = (string) ( $frame['function'] ?? '' );

			if ( in_array( $function, array( 'do_action', 'apply_filters', 'call_user_func_array', 'caller', 'on_change' ), true ) ) {
				continue;
			}

			return ( '' !== $class ? $class . '::' : '' ) . $function;
		}

		return '';
	}

	public function on_delete( int $attachment_id ): void {
		$post = get_post( $attachment_id );
		if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type ) {
			return;
		}

		$this->queue->upsert( 'media', (string) $post->post_mime_type, $attachment_id, $post->post_title, 'delete', md5( 'delete:media:' . $attachment_id ) );
	}
}
