<?php
declare(strict_types=1);

namespace IfsDeploy\Detection;

use IfsDeploy\Export\PostExporter;
use IfsDeploy\Queue\Hasher;
use IfsDeploy\Queue\MediaLifecycle;
use IfsDeploy\Queue\QueueRepository;
use IfsDeploy\Support\Config;

/**
 * Observes post/page changes and feeds the smart queue.
 *
 * Multiple edits to the same object collapse into a single pending row thanks
 * to the hash-based upsert in QueueRepository.
 */
final class PostObserver {

	private QueueRepository $queue;
	private PostExporter $exporter;

	/**
	 * Package hash of each post as it stood BEFORE this request modified it.
	 *
	 * @var array<int,string>
	 */
	private array $baselines = array();

	public function __construct( ?QueueRepository $queue = null, ?PostExporter $exporter = null ) {
		$this->queue    = $queue ?? new QueueRepository();
		$this->exporter = $exporter ?? new PostExporter();
	}

	/**
	 * Remember what a post looked like before it is edited (`pre_post_update`).
	 *
	 * Why this exists: the queue records the state an object was changed TO, and
	 * nothing else. So "edit a page, decide against it, put it back" left the row
	 * pending for ever — the row had no idea what the page had looked like before,
	 * and for an object IFS Deploy has never deployed there is no `deployed_hash`
	 * to fall back on either.
	 *
	 * `pre_post_update` fires inside wp_insert_post BEFORE the row is written, and
	 * before any meta box, ACF or block-editor meta write lands, so the package built
	 * here is genuinely the pre-edit one.
	 *
	 * Two things keep the cost down, because this runs on every post save on the site:
	 * an untracked post type is rejected before anything is read, and a post that
	 * already HAS a queue row is skipped — such a row carries its own baseline
	 * (QueueRepository::upsert()) and needs no export at all. So the extra work is one
	 * export on the first edit of an object, and none after that.
	 *
	 * `pre_post_update` never fires for a brand-new post, which is correct: something
	 * that did not exist has no state to be put back to.
	 *
	 * @param int|string $post_id
	 */
	public function on_pre_update( $post_id ): void {
		$post_id = (int) $post_id;

		if ( isset( $this->baselines[ $post_id ] ) || ! $this->is_trackable( $post_id ) ) {
			return;
		}

		if ( null !== $this->queue->find( 'post', $post_id ) ) {
			return;
		}

		$package = $this->exporter->export( $post_id );
		if ( null === $package ) {
			return;
		}

		$this->baselines[ $post_id ] = Hasher::hash( $package );
	}

	/**
	 * Handle a create/update (save_post or acf/save_post).
	 *
	 * @param int|string $post_id Post ID (acf/save_post may pass non-numeric ids
	 *                            for options pages — ignored here).
	 */
	public function on_save( $post_id ): void {
		$post_id = (int) $post_id;
		if ( ! $this->is_trackable( $post_id ) ) {
			return;
		}

		$package = $this->exporter->export( $post_id );
		if ( null === $package ) {
			return;
		}

		$post = get_post( $post_id );
		$hash = Hasher::hash( $package );

		$baseline = (string) ( $this->baselines[ $post_id ] ?? '' );
		unset( $this->baselines[ $post_id ] );

		/*
		 * A baseline equal to the hash we are about to store would resolve the row the
		 * instant it was created — it means the "before" was captured after the change
		 * had already landed, or that nothing changed at all. Dropping it costs only the
		 * old behaviour; keeping it would silently discard a real pending change.
		 *
		 * `on_save` also runs a second time for the same request on ACF posts
		 * (save_post, then acf/save_post), and the baseline is consumed by the first —
		 * which is right, since by then the row exists and carries its own.
		 */
		if ( $baseline === $hash ) {
			$baseline = '';
		}

		// Read before the write: a row whose pending change was a removal, for a post that
		// is present again, is a RESTORE rather than an edit.
		$previous = $this->queue->find( 'post', $post_id );

		$this->queue->upsert(
			'post',
			(string) $post->post_type,
			$post_id,
			(string) $post->post_title,
			'update',
			$hash,
			$baseline,
			MediaLifecycle::label_for_change(
				(string) $post->post_status,
				$previous,
				null !== $previous && '' !== (string) ( $previous->deployed_hash ?? '' )
			)
		);
	}

	/**
	 * Handle trash/delete. We queue a delete action so it can be propagated.
	 */
	public function on_delete( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		if ( ! in_array( $post->post_type, Config::tracked_post_types(), true ) ) {
			return;
		}

		/*
		 * NEVER DEPLOYED, SO THERE IS NOTHING TO REMOVE ON THE OTHER SIDE.
		 *
		 * Drafting a page and deleting it again before pushing left a "delete" row behind,
		 * which asked Production to remove something it had never been given — and failed
		 * with an error about a mistake the author had already corrected themselves. The
		 * two changes cancel out, so the row goes.
		 */
		if ( MediaLifecycle::cancels_out( $this->queue->find( 'post', $post_id ) ) ) {
			$this->queue->forget( 'post', $post_id );

			return;
		}

		$this->queue->upsert(
			'post',
			(string) $post->post_type,
			$post_id,
			(string) $post->post_title,
			'delete',
			// Hash the action so a later re-create supersedes the delete row.
			md5( 'delete:' . $post_id ),
			'',
			// `wp_trash_post` fires this hook too, so the post is still readable and its
			// status says which of the two removals this is.
			'trash' === (string) $post->post_status || 'wp_trash_post' === (string) current_filter()
				? MediaLifecycle::TRASHED
				: MediaLifecycle::DELETED
		);
	}

	private function is_trackable( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return false;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}
		if ( 'auto-draft' === $post->post_status || 'inherit' === $post->post_status ) {
			return false;
		}

		/*
		 * A TRASHED POST IS A DELETION, NOT AN EDIT — and this is why deletes did not sync.
		 *
		 * Core's `wp_trash_post()` runs in this order:
		 *
		 *     do_action( 'wp_trash_post', $id );                    // on_delete → 'delete'
		 *     wp_update_post( [ 'post_status' => 'trash' ] );       // save_post  → on_save
		 *
		 * So the delete row this plugin had just written was immediately overwritten by the
		 * save that trashing itself performs, and the queue ended up holding an `update`
		 * carrying a package whose status happened to be `trash`.
		 *
		 * Pushing that took the UPDATE path on the far side. Where the object matched, it
		 * trashed it and looked like it had worked; where it did NOT match, the importer
		 * did what an update does with an object it cannot find — it INSERTED one, so
		 * deleting a page on Staging quietly created a trashed copy of it on Production.
		 *
		 * Untrashing is unaffected: `wp_untrash_post()` fires `save_post` with the RESTORED
		 * status, not `trash`, so a recovered post is queued as the update it is.
		 */
		if ( 'trash' === $post->post_status ) {
			return false;
		}

		return in_array( $post->post_type, Config::tracked_post_types(), true );
	}
}
