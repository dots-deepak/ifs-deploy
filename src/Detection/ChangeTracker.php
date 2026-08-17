<?php
declare(strict_types=1);

namespace IfsDeploy\Detection;

/**
 * Registers all change-detection hooks (Staging only): posts (all tracked types
 * incl. reusable blocks), taxonomy terms, and allowlisted options (theme/ACF
 * options, widgets, safe plugin settings). Menus and media are handled by their
 * own pipelines in later phases.
 */
final class ChangeTracker {

	private PostObserver $posts;
	private TermObserver $terms;
	private OptionObserver $options;
	private AttachmentObserver $media;
	private MenuObserver $menus;

	public function __construct(
		?PostObserver $posts = null,
		?TermObserver $terms = null,
		?OptionObserver $options = null,
		?AttachmentObserver $media = null,
		?MenuObserver $menus = null
	) {
		$this->posts   = $posts ?? new PostObserver();
		$this->terms   = $terms ?? new TermObserver();
		$this->options = $options ?? new OptionObserver();
		$this->media   = $media ?? new AttachmentObserver();
		$this->menus   = $menus ?? new MenuObserver();
	}

	public function register(): void {
		// Posts (all tracked post types incl. reusable blocks).
		//
		// `pre_post_update` first, and it is not optional bookkeeping: it is the only
		// moment the PREVIOUS state of a post is still readable, which is what lets an
		// edit that is undone leave the queue again. See PostObserver::on_pre_update().
		add_action( 'pre_post_update', array( $this->posts, 'on_pre_update' ), 10, 1 );
		add_action( 'save_post', array( $this->posts, 'on_save' ), 99 );
		add_action( 'acf/save_post', array( $this->posts, 'on_save' ), 20 );
		add_action( 'wp_trash_post', array( $this->posts, 'on_delete' ), 10, 1 );
		add_action( 'before_delete_post', array( $this->posts, 'on_delete' ), 10, 1 );

		// Taxonomy terms.
		add_action( 'created_term', array( $this->terms, 'on_change' ), 10, 3 );
		add_action( 'edited_term', array( $this->terms, 'on_change' ), 10, 3 );
		add_action( 'delete_term', array( $this->terms, 'on_delete' ), 10, 4 );

		// Options (allowlisted: theme/ACF options, widgets, safe plugin settings).
		add_action( 'added_option', array( $this->options, 'on_update' ), 10, 1 );
		add_action( 'updated_option', array( $this->options, 'on_update' ), 10, 1 );
		add_action( 'deleted_option', array( $this->options, 'on_delete' ), 10, 1 );

		// Media library.
		add_action( 'add_attachment', array( $this->media, 'on_change' ), 10, 1 );
		add_action( 'edit_attachment', array( $this->media, 'on_change' ), 10, 1 );

		/*
		 * BOTH ways media can be removed, because they are genuinely different hooks.
		 *
		 * `wp_delete_attachment()` returns early into `wp_trash_post()` when the site has
		 * `MEDIA_TRASH` enabled — so on those sites `delete_attachment` NEVER fires, and
		 * listening only for it meant trashing an image was not tracked at all.
		 *
		 * `wp_trash_post` fires for every post type, so the observer checks the type
		 * itself rather than this registration having to know.
		 */
		add_action( 'wp_trash_post', array( $this->media, 'on_trash' ), 10, 1 );
		add_action( 'delete_attachment', array( $this->media, 'on_delete' ), 10, 1 );

		/*
		 * Coming BACK out of the Trash, listened for explicitly.
		 *
		 * `wp_untrash_post()` restores the status, and whether that also fires
		 * `edit_attachment` depends on how it puts the status back — which is not something
		 * to depend on. Registering for the restore itself makes it deterministic, and lets
		 * the pending change say "Restored" instead of "Updated".
		 */
		add_action( 'untrashed_post', array( $this->media, 'on_untrash' ), 10, 1 );

		// Navigation menus (create + change; a single hook covers both).
		add_action( 'wp_update_nav_menu', array( $this->menus, 'on_change' ), 10, 1 );
	}
}
