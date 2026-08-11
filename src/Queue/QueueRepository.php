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

		if ( null === $user_id ) {
			return (array) $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY updated_at DESC", $status ) // phpcs:ignore WordPress.DB.PreparedSQL
			);
		}

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s AND user_id = %d ORDER BY updated_at DESC", // phpcs:ignore WordPress.DB.PreparedSQL
				$status,
				$user_id
			)
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
