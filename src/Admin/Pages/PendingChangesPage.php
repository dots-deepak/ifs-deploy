<?php
declare(strict_types=1);

namespace IfsDeploy\Admin\Pages;

use IfsDeploy\Admin\AdminMenu;
use IfsDeploy\Admin\Section;
use IfsDeploy\Admin\PreviewModal;
use IfsDeploy\Client\QueueVerifier;
use IfsDeploy\Queue\MediaLifecycle;
use IfsDeploy\Queue\QueueRepository;
use IfsDeploy\Support\Access;
use IfsDeploy\Support\Config;
use IfsDeploy\Support\DebugLog;

/**
 * Pending Changes: lists queued objects with select + Push / Ignore actions.
 *
 * Scoping: a user who cannot "see all" only ever sees rows they own — decided by
 * Access::scope_user_id() rather than by anything in the request, so the filter
 * control below can widen the view only for someone already allowed to have it.
 * Ajax::queue_ids() re-checks ownership on the way back in.
 *
 * A lightweight custom table (rather than WP_List_Table) keeps the MVP simple;
 * a richer list table can replace this in a later phase.
 */
final class PendingChangesPage {

	public function render(): void {
		if ( ! current_user_can( Access::CAP_ACCESS ) ) {
			return;
		}

		$queue    = new QueueRepository();
		$sees_all = Access::sees_all();
		$filter   = $this->requested_filter( $sees_all );

		// Repair before anything reads the table: a stale duplicate left by an older
		// build would otherwise be counted, verified and offered for pushing alongside
		// the row that supersedes it.
		// Two shapes of duplicate, and they are not the same thing: the same object listed
		// twice, and several attachment records for ONE file — which a push can only ever
		// turn into a single attachment on Production.
		$collapsed = $queue->collapse_duplicates() + $queue->collapse_media_by_file();

		// Resolve rows that no longer differ from Production BEFORE listing, so the
		// screen only ever shows work that actually needs doing. Best-effort: if
		// Production is unreachable the list just renders unverified.
		$cleared = $this->verify( $queue->get_by_status( QueueRepository::STATUS_PENDING, $filter ) );

		$items = $queue->get_by_status( QueueRepository::STATUS_PENDING, $filter );

		Section::title(
			__( 'Pending Changes', 'ifs-deploy' ),
			__( 'Content edited here that has not been pushed yet. Review a change before you push it.', 'ifs-deploy' )
		);

		if ( ! Config::is_staging() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'This site is configured as Production. Pending changes are tracked on Staging.', 'ifs-deploy' ) . '</p></div>';
		}

		if ( ! $sees_all ) {
			echo '<p class="description">' . esc_html__( 'Showing the changes you made. Ask an administrator if you need to see everyone’s changes.', 'ifs-deploy' ) . '</p>';
		}

		// Same principle as below: rows must not vanish without a word.
		if ( $collapsed > 0 ) {
			printf(
				'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of duplicate rows merged */
						_n(
							'%d duplicate entry for an object already listed was merged into it.',
							'%d duplicate entries for objects already listed were merged into them.',
							$collapsed,
							'ifs-deploy'
						),
						$collapsed
					)
				)
			);
		}

		// Explain the disappearance rather than letting rows vanish silently.
		if ( $cleared > 0 ) {
			printf(
				'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of items removed from the list */
						_n(
							'%d item no longer differs from Production and was removed from this list.',
							'%d items no longer differ from Production and were removed from this list.',
							$cleared,
							'ifs-deploy'
						),
						$cleared
					)
				)
			);
		}


		if ( $sees_all ) {
			$this->filter_form( $queue );
		}

		echo '<div class="dp-actions">';
		if ( current_user_can( Access::CAP_DEPLOY ) ) {
			echo '<button type="button" class="button button-primary" id="ifs-deploy-push-selected">' . esc_html__( 'Push Selected', 'ifs-deploy' ) . '</button>';
			echo '<button type="button" class="button" id="ifs-deploy-push-all">' . esc_html__( 'Push All', 'ifs-deploy' ) . '</button>';
			echo '<button type="button" class="button" id="ifs-deploy-ignore">' . esc_html__( 'Ignore', 'ifs-deploy' ) . '</button>';
		}
		printf(
			'<a href="%1$s" class="button" data-tab="pending">%2$s</a>',
			esc_url( $this->page_url( $filter, $sees_all ) ),
			esc_html__( 'Refresh', 'ifs-deploy' )
		);
		echo '</div>';

		/*
		 * The opt-in for pushing OTHER people's pending changes.
		 *
		 * Pushing everything used to be what "Push All" did for anyone with "see all", which
		 * meant one click could publish a colleague's half-finished page. On a team that is
		 * the most likely way to deploy something nobody intended, and it looked like an
		 * ordinary action right up to the moment it happened.
		 *
		 * Now the default is "only mine" for everyone, and the wide behaviour has to be asked
		 * for. Only rendered for users who can see all changes — for anyone else the server
		 * narrows the ids regardless of what the page sends.
		 */
		/*
		 * ADMINISTRATORS ONLY — not everyone who can SEE all changes.
		 *
		 * Seeing and publishing are different powers. An editor may legitimately need to
		 * review the whole team's queue without being able to push a colleague's
		 * half-finished page, which is the most likely way to publish something nobody
		 * intended. `Ajax::queue_ids()` enforces the same rule against the database, so
		 * this only decides whether the option is offered.
		 */
		if ( current_user_can( Access::CAP_MANAGE ) && current_user_can( Access::CAP_DEPLOY ) ) {
			echo '<p class="dp-help mb-10">';
			echo '<label><input type="checkbox" id="ifs-deploy-include-others" /> ';
			echo esc_html__( 'Include changes made by other users when pushing. Leave this off to push only your own.', 'ifs-deploy' );
			echo '</label></p>';
		}

		echo '<table class="wp-list-table widefat fixed striped ifs-deploy-queue">';
		echo '<thead><tr>';
		echo '<td class="check-column"><input type="checkbox" id="ifs-deploy-select-all" /></td>';
		echo '<th>' . esc_html__( 'Object', 'ifs-deploy' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'ifs-deploy' ) . '</th>';
		echo '<th>' . esc_html__( 'Action', 'ifs-deploy' ) . '</th>';
		echo '<th>' . esc_html__( 'Changed By', 'ifs-deploy' ) . '</th>';
		echo '<th>' . esc_html__( 'Last Modified', 'ifs-deploy' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'ifs-deploy' ) . '</th>';
		echo '<th>' . esc_html__( 'Preview', 'ifs-deploy' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $items ) ) {
			echo '<tr><td colspan="8">' . esc_html__( 'No pending changes. Edit a post or page on Staging to populate the queue.', 'ifs-deploy' ) . '</td></tr>';
		}

		foreach ( $items as $item ) {
			printf(
				'<tr data-queue-id="%1$d">
					<th scope="row" class="check-column"><input type="checkbox" class="ifs-deploy-item" value="%1$d" data-mine="%9$d" /></th>
					<td><strong>%2$s</strong><span class="dp-id">#%10$d</span>%11$s</td>
					<td>%3$s</td>
					<td>%4$s</td>
					<td>%5$s</td>
					<td>%6$s</td>
					<td>%7$s</td>
					<td>%8$s</td>
				</tr>',
				(int) $item->id,
				esc_html( $item->object_title ?: __( '(no title)', 'ifs-deploy' ) ),
				esc_html( $this->type_label( $item ) ),
				/*
				 * WHAT THE OPERATOR DID, not what the deploy will do.
				 *
				 * This printed the raw `action`, so trashing a file, restoring one and
				 * editing one all read "update" — and a removal read "delete" whether it
				 * went to the Trash or was destroyed. Rows written before labels existed
				 * have an empty one and fall back to their action, which still reads as a
				 * word rather than a database value.
				 */
				// `??` as well as `?:` — the column is new, and a row read before the schema
				// upgrade has run has no such property at all, which PHP 8 warns about.
				esc_html( MediaLifecycle::describe( (string) ( ( $item->action_label ?? '' ) ?: $item->action ) ) ),
				esc_html( $this->user_label( (int) ( $item->user_id ?? 0 ) ) ),
				esc_html( $item->updated_at ),
				esc_html( $item->status ),
				$this->preview_cell( $item ),
				/*
				 * Whose row this is, so the browser can count what will ACTUALLY be pushed.
				 *
				 * Without it the confirmation dialog said "12 changes will be pushed" while
				 * the server narrowed that to the 3 the user owns — a dialog that promises
				 * something different from what happens is worse than no dialog. This is
				 * presentation only; the server still narrows the ids either way.
				 */
				(int) ( (int) ( $item->user_id ?? 0 ) === get_current_user_id() ? 1 : 0 ),

				/*
				 * The OBJECT's id — the attachment/post/term id, not the queue row's.
				 *
				 * Without it, rows are identified by title alone, and titles are not
				 * unique: WordPress names an attachment after its file, so uploading
				 * `test.png` more than once produces several attachments all called
				 * "test". Eight of those in a list read as one item tracked eight times,
				 * when they are eight different files each with a genuine pending change.
				 * Compare & Sync has shown this id for exactly this reason; Pending
				 * Changes did not.
				 */
				(int) $item->object_id,

				$this->file_hint( $item )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * Ask Production whether any queued row is already in sync, and clear those.
	 *
	 * Wrapped in a catch-all on purpose: this is a convenience pass over the list, and
	 * no failure in it — unreachable Production, a PHP error on the far side — is worth
	 * taking the screen down for.
	 *
	 * @param object[] $rows Pending rows.
	 *
	 * @return int Rows cleared.
	 */
	private function verify( array $rows ): int {
		if ( empty( $rows ) ) {
			return 0;
		}

		try {
			return ( new QueueVerifier() )->verify( $rows );
		} catch ( \Throwable $e ) {
			DebugLog::warning(
				'Pending Changes verification failed',
				array( 'error' => get_class( $e ) . ': ' . $e->getMessage() )
			);

			return 0;
		}
	}

	/**
	 * Which user to scope the list to.
	 *
	 * Returns null for "everyone". A user who cannot see all is pinned to their own
	 * id whatever the request asks for.
	 */
	private function requested_filter( bool $sees_all ): ?int {
		if ( ! $sees_all ) {
			return get_current_user_id();
		}

		$requested = isset( $_GET['dp_user'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['dp_user'] ) ) : 'all';

		if ( 'all' === $requested || '' === $requested ) {
			return null;
		}

		if ( 'mine' === $requested ) {
			return get_current_user_id();
		}

		$id = absint( $requested );

		return $id > 0 ? $id : null;
	}

	/**
	 * "Filter by user" control, offering only users who actually have rows queued.
	 */
	private function filter_form( QueueRepository $queue ): void {
		$counts    = $queue->users_with_status( QueueRepository::STATUS_PENDING );
		$requested = isset( $_GET['dp_user'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['dp_user'] ) ) : 'all';
		$total     = array_sum( $counts );
		$me        = get_current_user_id();

		echo '<form method="get" class="ifs-deploy-filter">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( AdminMenu::SLUG . '-pending' ) );

		echo '<label for="dp_user" class="screen-reader-text">' . esc_html__( 'Filter by user', 'ifs-deploy' ) . '</label>';
		echo '<select name="dp_user" id="dp_user">';

		printf(
			'<option value="all"%1$s>%2$s</option>',
			selected( $requested, 'all', false ),
			esc_html( sprintf( /* translators: %d: total pending changes */ __( 'All users (%d)', 'ifs-deploy' ), (int) $total ) )
		);

		printf(
			'<option value="mine"%1$s>%2$s</option>',
			selected( $requested, 'mine', false ),
			esc_html( sprintf( /* translators: %d: the current user's pending changes */ __( 'Only mine (%d)', 'ifs-deploy' ), (int) ( $counts[ $me ] ?? 0 ) ) )
		);

		foreach ( $counts as $user_id => $count ) {
			if ( $user_id === $me ) {
				continue; // already covered by "Only mine".
			}

			printf(
				'<option value="%1$d"%2$s>%3$s</option>',
				(int) $user_id,
				selected( $requested, (string) $user_id, false ),
				esc_html( sprintf( '%s (%d)', $this->user_label( (int) $user_id ), (int) $count ) )
			);
		}

		echo '</select> ';
		submit_button( __( 'Filter', 'ifs-deploy' ), 'secondary', '', false );
		echo '</form>';
	}

	/**
	 * Preserve the active filter across the Refresh link.
	 */
	private function page_url( ?int $filter, bool $sees_all ): string {
		$url = admin_url( 'admin.php?page=' . AdminMenu::SLUG . '-pending' );

		if ( ! $sees_all || null === $filter ) {
			return $url;
		}

		return add_query_arg( 'dp_user', $filter === get_current_user_id() ? 'mine' : (string) $filter, $url );
	}

	/**
	 * Display name for a queue row's owner.
	 *
	 * Rows created before the user column existed carry 0, as do changes made
	 * outside a login (WP-CLI, cron).
	 */
	private function user_label( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return __( 'Unknown', 'ifs-deploy' );
		}

		$user = get_userdata( $user_id );

		return $user ? (string) $user->display_name : __( 'Deleted user', 'ifs-deploy' );
	}

	/**
	 * The file behind a media row, or '' for everything else.
	 *
	 * An attachment's title comes from its file name, so uploading `sstest.png` more
	 * than once gives EVERY copy the title "sstest" — a list of them reads as one item
	 * tracked repeatedly when it is really several separate files. The id distinguishes
	 * them; the file name explains them, because `sstest.png`, `sstest-1.png`,
	 * `sstest-2.png` says at a glance what happened.
	 */
	private function file_hint( object $item ): string {
		if ( 'media' !== (string) $item->object_type ) {
			return '';
		}

		$file = (string) get_post_meta( (int) $item->object_id, '_wp_attached_file', true );

		if ( '' === $file ) {
			return '';
		}

		return '<span class="dp-help ifs-deploy-file">' . esc_html( basename( $file ) ) . '</span>';
	}

	/**
	 * "View changes" trigger for the rows a field-level diff exists for.
	 *
	 * Posts and MEDIA. A media row shows what a push would change about the attachment
	 * — title, caption, description, alt text, slug and its meta — which is exactly the
	 * question "I edited this image, what did I actually change?" asks. Terms, options
	 * and menus still deploy as before; they just have no preview yet.
	 */
	private function preview_cell( object $item ): string {
		if ( ! in_array( (string) $item->object_type, array( 'post', 'media' ), true ) ) {
			return '<span class="description">&mdash;</span>';
		}

		return PreviewModal::button( 'queue-id', (int) $item->id, (string) $item->object_title );
	}

	/**
	 * Human-readable type label for a queue row.
	 */
	private function type_label( object $item ): string {
		switch ( (string) $item->object_type ) {
			case 'term':
				/* translators: %s: taxonomy name */
				return sprintf( __( 'Taxonomy: %s', 'ifs-deploy' ), $item->object_subtype );
			case 'option':
				return __( 'Option / setting', 'ifs-deploy' );
			case 'media':
				/* translators: %s: mime type */
				return sprintf( __( 'Media: %s', 'ifs-deploy' ), $item->object_subtype );
			case 'menu':
				return __( 'Navigation menu', 'ifs-deploy' );
			case 'post':
			default:
				return (string) $item->object_subtype;
		}
	}
}
