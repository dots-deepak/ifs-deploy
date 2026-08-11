<?php
declare(strict_types=1);

namespace IfsDeploy\Queue;

use IfsDeploy\Support\Schema;

/**
 * CRUD for the smart queue. Enforces one row per object via upsert: editing the
 * Homepage 20 times yields a single pending row, not twenty.
 */
final class QueueRepository {

	public const STATUS_PENDING  = 'pending';
	public const STATUS_DEPLOYED = 'deployed';
	public const STATUS_FAILED   = 'failed';
	public const STATUS_IGNORED  = 'ignored';

	/**
	 * The object was edited and then put back to where it started, so there is
	 * nothing to push. Distinct from `deployed`, which asserts something this cannot:
	 * that the state in question was ever sent to Production.
	 */
	public const STATUS_UNCHANGED = 'unchanged';

	/**
	 * Insert a new pending item or update the existing one for this object.
	 *
	 * When the object's hash is unchanged from the stored row, this is a no-op
	 * (returns false) — that is the smart-queue skip.
	 *
	 * @param string $baseline Hash of the object BEFORE this pending change began, when
	 *                         the caller knows it and no row exists yet to carry one.
	 *                         Editing back to it resolves the row. See PostObserver.
	 *
	 * @return bool True when a row was written/updated, false when skipped.
	 */
	public function upsert( string $type, string $subtype, int $object_id, string $title, string $action, string $hash, string $baseline = '' ): bool {
		global $wpdb;

		$table    = Schema::queue_table();
		$now      = current_time( 'mysql' );
		$existing = $this->find( $type, $object_id );

		// Attribution is captured here rather than passed in by every observer.
		// One row per object means this records the LAST person to touch it, which is
		// the right answer for "whose pending change is this?".
		$user_id = get_current_user_id();

		if ( $existing ) {
			// Already queued with the same content and still pending → skip.
			if ( $existing->object_hash === $hash && self::STATUS_PENDING === $existing->status ) {
				return false;
			}

			/*
			 * WHAT STATE DOES "NOTHING TO PUSH" MEAN FOR THIS ROW?
			 *
			 * Two answers, and both have to be kept:
			 *
			 *  - `deployed_hash` — what Production last accepted. Editing back to it is
			 *    genuinely nothing to push.
			 *  - `baseline_hash` — what the object held before this pending change began.
			 *    Editing back to THAT is also nothing to push, and it is the only answer
			 *    available for an object IFS Deploy has never deployed. Without it, "I
			 *    changed my mind and undid it" left the row queued for ever, because the
			 *    row only ever remembered the state it was changed TO.
			 *
			 * A row leaving a settled state supplies its own baseline for free: whatever
			 * it held while settled is exactly the state to return to. The caller only
			 * has to provide one when there is no row at all.
			 */
			$settled  = self::STATUS_PENDING !== $existing->status;
			$baseline = $settled
				? (string) $existing->object_hash
				: (string) ( $existing->baseline_hash ?? '' );

			$deployed_hash = (string) ( $existing->deployed_hash ?? '' );

			$resolved_as = '';
			if ( '' !== $deployed_hash && $hash === $deployed_hash ) {
				$resolved_as = self::STATUS_DEPLOYED;
			} elseif ( '' !== $baseline && $hash === $baseline ) {
				$resolved_as = self::STATUS_UNCHANGED;
			}

			if ( '' !== $resolved_as ) {
				if ( self::STATUS_PENDING === $existing->status ) {
					$wpdb->update(
						$table,
						array(
							'object_title' => $title,
							'object_hash'  => $hash,
							'status'       => $resolved_as,
							'updated_at'   => $now,
						),
						array( 'id' => (int) $existing->id ),
						array( '%s', '%s', '%s', '%s' ),
						array( '%d' )
					);
				}

				return false;
			}

			$wpdb->update(
				$table,
				array(
					// The subtype travels with the row rather than identifying it — see
					// find(). A mime type or post type that changes must not strand the
					// row it belongs to.
					'object_subtype' => $subtype,
					'object_title'   => $title,
					'action'         => $action,
					'object_hash'    => $hash,
					'baseline_hash'  => $baseline,
					'status'         => self::STATUS_PENDING,
					'user_id'        => $user_id,
					'updated_at'     => $now,
				),
				array( 'id' => (int) $existing->id ),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ),
				array( '%d' )
			);

			return true;
		}

		$wpdb->insert(
			$table,
			array(
				'object_type'    => $type,
				'object_subtype' => $subtype,
				'object_id'      => $object_id,
				'object_title'   => $title,
				'action'         => $action,
				'object_hash'    => $hash,
				'baseline_hash'  => $baseline,
				'status'         => self::STATUS_PENDING,
				'user_id'        => $user_id,
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return true;
	}

	/**
	 * The one row for an object, whatever its subtype currently says.
	 *
	 * IDENTITY IS (type, object_id) — NOT the subtype.
	 *
	 * The subtype is a mime type for media and a post type for posts, and both are
	 * *content*: they can change while the object stays the same one. Matching on it
	 * meant every distinct value produced its OWN row that the others never deduped
	 * against, so one attachment could accumulate a row per mime type it had ever
	 * reported — the "several tracked items for a single media update" report. Object
	 * ids are unique per type in WordPress (posts, attachments and terms all draw from
	 * their own unique sequences), so dropping the subtype loses no precision.
	 *
	 * The UNIQUE key on (object_type, object_subtype, object_id) still stands; this is
	 * strictly narrower, so it cannot be violated by anything written through here.
	 */
	public function find( string $type, int $object_id ): ?object {
		global $wpdb;

		$table = Schema::queue_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				// Newest first, so a table still holding pre-migration duplicates
				// converges on the most recent one instead of reviving a stale row.
				"SELECT * FROM {$table} WHERE object_type = %s AND object_id = %d ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
				$type,
				$object_id
			)
		);

		return $row ?: null;
	}

	public function get( int $id ): ?object {
		global $wpdb;

		$table = Schema::queue_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		return $row ?: null;
	}

	public function find_by_object_id( int $object_id ): ?object {
		global $wpdb;

		$table = Schema::queue_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE object_id = %d ORDER BY id DESC LIMIT 1", $object_id ) // phpcs:ignore WordPress.DB.PreparedSQL
		);

		return $row ?: null;
	}

	/**
	 * Rows with a status, optionally restricted to one user.
	 *
	 * @param int|null $user_id Null for every user (the previous behaviour, so
	 *                          existing callers are unaffected).
	 *
	 * @return object[]
	 */
	public function get_by_status( string $status, ?int $user_id = null ): array {
		global $wpdb;

		$table = Schema::queue_table();

		/*
		 * ONE ROW PER OBJECT, guaranteed at READ time as well as at write time.
		 *
		 * `find()` already keys an object by (object_type, object_id), so nothing written
		 * through this class can duplicate one. This is the belt to that pair of braces:
		 * rows predating the v7 migration, a row inserted by an older build, or anything
		 * that reaches the table another way must still never show the same object twice.
		 * A list that does is not merely untidy — with several people working at once it
		 * makes "what am I about to push?" unanswerable.
		 *
		 * The subquery picks the HIGHEST id per object, which is the most recent state:
		 * rows are updated in place, so a duplicate is always the later insert.
		 *
		 * STATUS IS NOT PART OF THE SUBQUERY, deliberately. The invariant is one row per
		 * object outright — that is also what the UNIQUE key enforces, and it spans every
		 * status. Scoping the subquery per status would let a stale `pending` row show
		 * alongside the `deployed` row that superseded it, which is the confusion this
		 * whole rule exists to remove.
		 */
		if ( null === $user_id ) {
			return (array) $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL
					"SELECT q.* FROM {$table} q
					 WHERE q.status = %s
					   AND q.id = (
					       SELECT MAX(d.id) FROM {$table} d
					       WHERE d.object_type = q.object_type
					         AND d.object_id = q.object_id
					   )
					 ORDER BY q.updated_at DESC",
					$status
				)
			);
		}

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL
				"SELECT q.* FROM {$table} q
				 WHERE q.status = %s AND q.user_id = %d
				   AND q.id = (
				       SELECT MAX(d.id) FROM {$table} d
				       WHERE d.object_type = q.object_type
				         AND d.object_id = q.object_id
				   )
				 ORDER BY q.updated_at DESC",
				$status,
				$user_id
			)
		);
	}

	/**
	 * Physically remove any duplicate rows for one object, keeping the newest.
	 *
	 * `get_by_status()` already hides them, but hiding is not the same as fixing: a
	 * hidden row still counts in `users_with_status()`, still sits behind the UNIQUE key,
	 * and would reappear the moment a query forgot the subquery. This runs when Pending
	 * Changes is rendered, so the table repairs itself in the course of ordinary use
	 * rather than only at the one moment an upgrade happens to fire.
	 *
	 * @return int Rows removed.
	 */
	public function collapse_duplicates(): int {
		global $wpdb;

		$table = Schema::queue_table();

		// Across every status, matching both the UNIQUE key and get_by_status(): one row
		// per object, and the newest is the truth.
		return (int) $wpdb->query( // phpcs:ignore WordPress.DB
			"DELETE older FROM {$table} AS older
			 INNER JOIN {$table} AS newer
			     ON older.object_type = newer.object_type
			    AND older.object_id   = newer.object_id
			    AND older.id          < newer.id"
		);
	}

	/**
	 * Users who own at least one row with this status, id => row count.
	 *
	 * Drives the "filter by user" control, so it only ever offers users who
	 * actually have something queued.
	 *
	 * @return array<int,int>
	 */
	public function users_with_status( string $status ): array {
		global $wpdb;

		$table = Schema::queue_table();
		$rows  = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, COUNT(*) AS total FROM {$table} WHERE status = %s GROUP BY user_id ORDER BY total DESC", // phpcs:ignore WordPress.DB.PreparedSQL
				$status
			)
		);

		$out = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row->user_id ] = (int) $row->total;
		}

		return $out;
	}

	/**
	 * Narrow a set of queue ids to those a given user owns.
	 *
	 * The server-side half of the ownership rule: a user without "see all" must not
	 * be able to act on someone else's row by posting its id directly.
	 *
	 * @param int[] $ids
	 *
	 * @return int[]
	 */
	public function ids_owned_by( array $ids, int $user_id ): array {
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		global $wpdb;

		$table        = Schema::queue_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$owned = (array) $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL
				"SELECT id FROM {$table} WHERE user_id = %d AND id IN ({$placeholders})",
				array_merge( array( $user_id ), $ids )
			)
		);

		return array_map( 'intval', $owned );
	}

	/**
	 * Record a successful deploy: the row's current hash becomes the deployed hash.
	 *
	 * That stored hash is what later lets upsert() recognise the object being edited
	 * back to its deployed state and drop it from Pending Changes.
	 */
	public function mark_deployed( int $id ): void {
		global $wpdb;

		$table = Schema::queue_table();

		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, deployed_hash = object_hash, updated_at = %s WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL
				self::STATUS_DEPLOYED,
				current_time( 'mysql' ),
				$id
			)
		);
	}

	/**
	 * Drop an object's row entirely.
	 *
	 * Used when an object turns out not to be independently deployable after all — the
	 * case being several attachment records that share one file, where only the original
	 * is tracked. Deleting rather than marking a status: the row describes something that
	 * should never have had one, so leaving it behind under any status would still put it
	 * in front of someone.
	 */
	public function forget( string $type, int $object_id ): void {
		global $wpdb;

		$wpdb->delete(
			Schema::queue_table(),
			array(
				'object_type' => $type,
				'object_id'   => $object_id,
			),
			array( '%s', '%d' )
		);
	}

	/**
	 * Leave one pending media row per FILE, keeping the lowest attachment id.
	 *
	 * The counterpart to `AttachmentObserver`'s rule, for rows already written before it
	 * existed. Those attachments may never be saved again, so nothing would otherwise
	 * ever clean them up.
	 *
	 * Why by file: `MediaImporter::find_existing()` matches an incoming attachment on its
	 * recorded source URL, and attachments sharing a file share a source URL — so a push
	 * of all of them yields exactly ONE attachment on Production. Listing them separately
	 * promises an outcome that cannot happen.
	 *
	 * @return int Rows removed.
	 */
	public function collapse_media_by_file(): int {
		global $wpdb;

		$table = Schema::queue_table();

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, object_id FROM {$table} WHERE object_type = 'media' AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL
				self::STATUS_PENDING
			)
		);

		if ( count( $rows ) < 2 ) {
			return 0;
		}

		// One query for every attachment's meta rather than one per row.
		update_meta_cache( 'post', array_map( static fn( $row ): int => (int) $row->object_id, $rows ) );

		$best = array();  // file => [ 'object_id' => int, 'row_ids' => int[] ]

		foreach ( $rows as $row ) {
			$file = (string) get_post_meta( (int) $row->object_id, '_wp_attached_file', true );

			// An attachment whose file is unknown is left strictly alone: with nothing to
			// group on, "duplicate" cannot be established, and this must never guess.
			if ( '' === $file ) {
				continue;
			}

			if ( ! isset( $best[ $file ] ) ) {
				$best[ $file ] = array( 'object_id' => (int) $row->object_id, 'row_ids' => array() );
			}

			$best[ $file ]['object_id'] = min( $best[ $file ]['object_id'], (int) $row->object_id );
			$best[ $file ]['row_ids'][] = (int) $row->id;
		}

		$remove = array();

		foreach ( $best as $file => $group ) {
			if ( count( $group['row_ids'] ) < 2 ) {
				continue;
			}

			foreach ( $rows as $row ) {
				if ( in_array( (int) $row->id, $group['row_ids'], true ) && (int) $row->object_id !== $group['object_id'] ) {
					$remove[] = (int) $row->id;
				}
			}
		}

		if ( empty( $remove ) ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $remove ), '%d' ) );

		return (int) $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $remove ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}

	public function set_status( int $id, string $status ): void {
		global $wpdb;

		$wpdb->update(
			Schema::queue_table(),
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}
}
