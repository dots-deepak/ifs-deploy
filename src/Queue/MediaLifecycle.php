<?php
declare(strict_types=1);

namespace IfsDeploy\Queue;

/**
 * What actually HAPPENED to an object, and what a pending change should say about it.
 *
 * ── WHY THIS IS ITS OWN CLASS ──────────────────────────────────────────────────────
 *
 * The queue stores an `action` of `update` or `delete`, because those are the only two
 * things a deploy can do to the far side. That is the right vocabulary for the deploy and
 * the wrong one for the person reading the list: "update" is what the plugin will do,
 * whereas the operator trashed a file, or restored one, or published a draft. Being told
 * "update" for all three is why the Action column could not be trusted.
 *
 * So there are two vocabularies, deliberately, and this class owns the second one. The
 * `action` column keeps driving the deploy and nothing about that changed; the label rides
 * alongside it and is only ever displayed.
 *
 * The other half of the job is the question every removal has to ask — was this object
 * ever actually deployed? — because an upload that is deleted again before anyone pushes
 * it should leave no trace, rather than becoming a request to delete something Production
 * has never heard of. That decision belongs next to the labels: both are about the queue
 * row's history rather than about media specifically, which is why nothing here mentions
 * attachments.
 */
final class MediaLifecycle {

	// Removals.
	public const TRASHED = 'trashed';
	public const DELETED = 'deleted';

	// Everything else.
	public const ADDED     = 'added';
	public const UPDATED   = 'updated';
	public const RESTORED  = 'restored';
	public const PUBLISHED = 'published';
	public const DRAFTED   = 'draft';
	public const SCHEDULED = 'scheduled';
	public const PRIVATED  = 'private';

	/**
	 * Would this removal cancel out a pending change that never reached Production?
	 *
	 * True only when there is a row, it is still PENDING, and nothing has ever been
	 * deployed from it. Each condition is load-bearing:
	 *
	 *   - No row at all means the object predates tracking, so Production may well hold a
	 *     copy. The removal has to be queued.
	 *   - A settled row (deployed, ignored, unchanged) is a row whose object Production
	 *     has seen. Dropping it would strand a real deletion.
	 *   - `deployed_hash` is the proof. A row can be PENDING and still have been deployed
	 *     before — that is exactly what an edit to already-live content looks like — and
	 *     removing that content is a genuine deletion that must still be pushed.
	 *
	 * @param object|null $row The existing queue row, or null when there is none.
	 */
	public static function cancels_out( ?object $row ): bool {
		if ( null === $row ) {
			return false;
		}

		if ( QueueRepository::STATUS_PENDING !== (string) ( $row->status ?? '' ) ) {
			return false;
		}

		return '' === (string) ( $row->deployed_hash ?? '' );
	}

	/**
	 * The label for a change to an object that still exists.
	 *
	 * `$previous` is the row being replaced, and it is what separates a restore from an
	 * ordinary edit: an object whose pending change was a REMOVAL and which is now present
	 * again has been restored, whoever did it and however. Reading the status alone cannot
	 * tell those apart, because a restored attachment and an edited one both end up
	 * `inherit`.
	 *
	 * @param string      $status   The object's post status now.
	 * @param object|null $previous The queue row being superseded, if any.
	 * @param bool        $deployed Whether this object has ever been deployed.
	 */
	public static function label_for_change( string $status, ?object $previous, bool $deployed ): string {
		if ( null !== $previous && 'delete' === (string) ( $previous->action ?? '' ) ) {
			return self::RESTORED;
		}

		switch ( $status ) {
			case 'publish':
				// Publishing something Production already has is an update to it; the
				// distinction only means anything the first time.
				return $deployed ? self::UPDATED : self::PUBLISHED;

			case 'draft':
			case 'auto-draft':
				return self::DRAFTED;

			case 'future':
				return self::SCHEDULED;

			case 'private':
				return self::PRIVATED;
		}

		/*
		 * `inherit` lands here, which is every attachment: WordPress gives attachments the
		 * status of their parent and they are almost never `publish`. So media is
		 * classified by whether Production has it, which is the only distinction that
		 * actually exists for a file.
		 */
		return $deployed ? self::UPDATED : self::ADDED;
	}

	/**
	 * A label rendered for a human, translated at the point of display.
	 *
	 * Unknown values are returned as they are rather than dropped. Rows written before
	 * labels existed carry none at all and fall back to their `action`, and inventing a
	 * word for a value this class does not recognise would be worse than showing it.
	 */
	public static function describe( string $label ): string {
		$labels = array(
			self::ADDED     => __( 'Added', 'ifs-deploy' ),
			self::UPDATED   => __( 'Updated', 'ifs-deploy' ),
			self::RESTORED  => __( 'Restored', 'ifs-deploy' ),
			self::PUBLISHED => __( 'Published', 'ifs-deploy' ),
			self::DRAFTED   => __( 'Draft', 'ifs-deploy' ),
			self::SCHEDULED => __( 'Scheduled', 'ifs-deploy' ),
			self::PRIVATED  => __( 'Private', 'ifs-deploy' ),
			self::TRASHED   => __( 'Trashed', 'ifs-deploy' ),
			self::DELETED   => __( 'Deleted', 'ifs-deploy' ),

			// The two stored `action` values, so a row written before this existed still
			// reads as a word rather than as a database value.
			'update'        => __( 'Updated', 'ifs-deploy' ),
			'delete'        => __( 'Removed', 'ifs-deploy' ),
		);

		return $labels[ $label ] ?? $label;
	}
}
