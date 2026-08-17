<?php
declare(strict_types=1);

namespace IfsDeploy\Rollback;

use IfsDeploy\Export\MenuExporter;
use IfsDeploy\Export\OptionExporter;
use IfsDeploy\Import\MenuImporter;
use IfsDeploy\Support\Config;
use IfsDeploy\Support\DebugLog;
use IfsDeploy\Support\Json;
use IfsDeploy\Support\Legacy;
use IfsDeploy\Support\Schema;
use IfsDeploy\Support\SafeData;

/**
 * Captures and restores point-in-time snapshots of a production object.
 *
 * A snapshot is taken on Production immediately before an import overwrites the
 * object, so the previous state can be restored. Snapshots are same-site, so we
 * store real local IDs (thumbnail id, term ids) rather than URLs/slugs.
 *
 * Only the latest Config::MAX_REVISIONS snapshots per object are retained.
 */
final class SnapshotStore {

	/**
	 * Capture the current state of a post into the revisions table.
	 *
	 * @return int|null Inserted revision id, or null if the post does not exist.
	 */
	public function capture( int $deployment_id, int $post_id ): ?int {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		$snapshot = array(
			'post'         => $post->to_array(),
			'meta'         => get_post_meta( $post_id ),
			'thumbnail_id' => (int) get_post_thumbnail_id( $post_id ),
			'terms'        => $this->capture_terms( $post ),
		);

		return $this->insert_revision( $deployment_id, 'post', $post_id, $snapshot );
	}

	/**
	 * Capture the current state of a term into the revisions table.
	 *
	 * @return int|null Revision id, or null when the term does not exist.
	 */
	public function capture_term( int $deployment_id, int $term_id, string $taxonomy ): ?int {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return null;
		}

		$parent   = $term->parent ? get_term( $term->parent, $taxonomy ) : null;
		$snapshot = array(
			'taxonomy'    => $taxonomy,
			'term_id'     => $term_id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'description' => $term->description,
			'parent_slug' => $parent instanceof \WP_Term ? $parent->slug : '',
			'meta'        => get_term_meta( $term_id ),
		);

		return $this->insert_revision( $deployment_id, 'term', $term_id, $snapshot );
	}

	/**
	 * Capture the current value of an option into the revisions table.
	 *
	 * @return int|null Always returns a revision id (records absence too).
	 */
	public function capture_option( int $deployment_id, string $name ): ?int {
		if ( '' === $name ) {
			return null;
		}

		// Shared with `Export\OptionExporter::ABSENT` from one constant, because the two must
		// agree for a captured absence to round-trip. See `Support\Legacy` for why the value
		// keeps its pre-rename spelling.
		$sentinel = Legacy::ABSENT_SENTINEL;
		$value    = get_option( $name, $sentinel );
		$existed  = ( $sentinel !== $value );

		$snapshot = array(
			'name'    => $name,
			'value'   => $existed ? $value : null,
			'existed' => $existed,
		);

		return $this->insert_revision( $deployment_id, 'option', $this->option_id( $name ), $snapshot );
	}

	/**
	 * Capture the current state of a menu (term + items + locations) as an
	 * exported package, so a rollback can re-apply it.
	 *
	 * @return int|null Revision id, or null when the menu does not exist.
	 */
	public function capture_menu( int $deployment_id, int $menu_id ): ?int {
		$package = ( new MenuExporter() )->export( $menu_id );
		if ( null === $package ) {
			return null;
		}
		return $this->insert_revision( $deployment_id, 'menu', $menu_id, $package );
	}

	/**
	 * Marks a revision that records a CREATION rather than a previous state.
	 *
	 * Public because `Rest\RollbackPreviewEndpoint` has to recognise one: there is nothing
	 * to diff, and offering an empty before/after panel would read as a broken preview.
	 */
	public const CREATED_MARKER = 'ifs_deploy_created';

	/**
	 * Record that this deploy CREATED an object, so the rollback can undo it.
	 *
	 * ── WHY A CREATE NEEDED ITS OWN KIND OF SNAPSHOT ───────────────────────────────
	 *
	 * Every other snapshot answers "what was here before?". For something the deploy
	 * created there is no before — so nothing was captured, no revision row existed, and
	 * the History screen correctly concluded there was nothing to roll back. Reported as
	 * "no rollback option for media", but it was never about media: pushing a NEW page had
	 * exactly the same gap. A deploy that cannot be undone is the one case rollback exists
	 * for.
	 *
	 * So the undo of a create is a REMOVAL, and that is what this records. Written after
	 * the import rather than before it, because the object's id on this site is not known
	 * until it has been created.
	 *
	 * @return int|null Revision id, or null when there is nothing to mark.
	 */
	public function capture_creation( int $deployment_id, string $type, int $object_id ): ?int {
		if ( $object_id <= 0 || '' === $type ) {
			return null;
		}

		return $this->insert_revision(
			$deployment_id,
			$type,
			$object_id,
			array(
				self::CREATED_MARKER => true,
				// The row's own object_type is 'post' for media too (an attachment IS a
				// post), so the real type is carried in the payload — removing an
				// attachment and trashing a page are different operations.
				'type'               => $type,
				'object_id'          => $object_id,
			)
		);
	}

	/**
	 * Restore a stored revision, dispatching by object type. Returns true on
	 * success.
	 */
	public function restore( int $revision_id ): bool {
		$row = $this->get( $revision_id );
		if ( null === $row ) {
			return false;
		}

		$snapshot = json_decode( (string) $row->snapshot, true );
		if ( ! is_array( $snapshot ) ) {
			return false;
		}

		// A creation has no previous state to write back; undoing it means removing what
		// the deploy added.
		if ( ! empty( $snapshot[ self::CREATED_MARKER ] ) ) {
			return $this->undo_creation(
				(string) ( $snapshot['type'] ?? $row->object_type ),
				(int) ( $snapshot['object_id'] ?? $row->object_id )
			);
		}

		switch ( (string) $row->object_type ) {
			case 'term':
				return $this->restore_term( $snapshot );
			case 'option':
				return $this->restore_option( $snapshot );
			case 'menu':
				return $this->restore_menu( $snapshot );
			case 'post':
			default:
				return $this->restore_post( $snapshot );
		}
	}

	/**
	 * Remove something this deploy created.
	 *
	 * REVERSIBLE WHEREVER WORDPRESS ALLOWS IT. A rollback is already the "undo" button;
	 * making it destroy content outright would leave no way back from a mistaken undo. So
	 * posts go to Trash, and attachments go to Trash too on sites where media trash is
	 * enabled — `wp_delete_attachment()` with force = false is the same call the delete
	 * path uses, and it honours that setting.
	 *
	 * Terms and options genuinely have no trash in WordPress, so removing them is the only
	 * available undo. Both were created by this deploy, so nothing that predates it is
	 * lost either way.
	 */
	private function undo_creation( string $type, int $object_id ): bool {
		if ( $object_id <= 0 ) {
			return false;
		}

		switch ( $type ) {
			case 'media':
				return false !== wp_delete_attachment( $object_id, false );

			case 'term':
				// The taxonomy is not recorded on the marker, so it is read back from the
				// term itself — which still exists, because this runs before the removal.
				$term = get_term( $object_id );

				if ( ! $term instanceof \WP_Term ) {
					return false;
				}

				return true === wp_delete_term( $object_id, $term->taxonomy );

			case 'menu':
				return false !== wp_delete_nav_menu( $object_id );

			case 'option':
				/*
				 * Unreachable, and deliberately left in place.
				 *
				 * `capture_option()` always produces a revision — it records ABSENCE with
				 * a sentinel — so a newly created option already has a real snapshot and
				 * never reaches a creation marker. Returning false rather than guessing is
				 * the right answer if that ever changes: an option's id is derived from its
				 * name and the name cannot be recovered from it, so there is nothing here
				 * that could safely be deleted.
				 */
				return false;

			case 'post':
			default:
				return null !== wp_trash_post( $object_id );
		}
	}

	/**
	 * Restore a menu by re-applying its captured package.
	 */
	private function restore_menu( array $snapshot ): bool {
		$result = ( new MenuImporter() )->import( $snapshot );
		return ! is_wp_error( $result );
	}

	private function restore_post( array $snapshot ): bool {
		if ( empty( $snapshot['post']['ID'] ) ) {
			return false;
		}

		$post_id = (int) $snapshot['post']['ID'];

		/*
		 * THE ROW HAS TO STILL BE THERE, and this used to assume it was.
		 *
		 * `wp_update_post()` on an id that no longer exists updates nothing and returns 0 —
		 * it does not insert. Everything below then ran against a post that is not there,
		 * and this returned `true` regardless, so the rollback reported success while
		 * changing nothing at all.
		 *
		 * The case that reaches here is a permanently deleted attachment: the snapshot was
		 * captured before the removal, the removal destroyed the row AND the file on disk,
		 * and a rollback cannot bring either back. Re-inserting the row would be worse than
		 * failing — an attachment pointing at a file that no longer exists is a broken image
		 * everywhere it appears, presented as a successful restore.
		 *
		 * Saying so is the only honest option. A trashed attachment is a different story and
		 * still restores normally: its row survives, so the status simply goes back.
		 */
		if ( ! get_post( $post_id ) instanceof \WP_Post ) {
			DebugLog::error(
				'Cannot roll back: the object no longer exists on this site',
				array(
					'object_id' => $post_id,
					'post_type' => (string) ( $snapshot['post']['post_type'] ?? '' ),
					'title'     => (string) ( $snapshot['post']['post_title'] ?? '' ),
					'why'       => 'it was permanently deleted, so there is no row to restore and, for media, no file either',
				)
			);

			return false;
		}

		// Restore core fields.
		wp_update_post( wp_slash( $snapshot['post'] ) );

		// Restore meta: clear current, then write the snapshot's meta back.
		$this->replace_meta( $post_id, (array) ( $snapshot['meta'] ?? array() ) );

		// Featured image.
		if ( ! empty( $snapshot['thumbnail_id'] ) ) {
			set_post_thumbnail( $post_id, (int) $snapshot['thumbnail_id'] );
		} else {
			delete_post_thumbnail( $post_id );
		}

		// Taxonomies.
		foreach ( (array) ( $snapshot['terms'] ?? array() ) as $taxonomy => $term_ids ) {
			wp_set_object_terms( $post_id, array_map( 'intval', (array) $term_ids ), (string) $taxonomy, false );
		}

		return true;
	}

	private function restore_term( array $snapshot ): bool {
		$taxonomy = (string) ( $snapshot['taxonomy'] ?? '' );
		$term_id  = (int) ( $snapshot['term_id'] ?? 0 );
		if ( $term_id <= 0 || ! taxonomy_exists( $taxonomy ) ) {
			return false;
		}

		$parent = 0;
		if ( ! empty( $snapshot['parent_slug'] ) ) {
			$parent_term = get_term_by( 'slug', (string) $snapshot['parent_slug'], $taxonomy );
			$parent      = $parent_term instanceof \WP_Term ? (int) $parent_term->term_id : 0;
		}

		wp_update_term(
			$term_id,
			$taxonomy,
			array(
				'name'        => (string) ( $snapshot['name'] ?? '' ),
				'slug'        => (string) ( $snapshot['slug'] ?? '' ),
				'description' => (string) ( $snapshot['description'] ?? '' ),
				'parent'      => $parent,
			)
		);

		// Restore term meta.
		foreach ( (array) get_term_meta( $term_id ) as $key => $unused ) {
			delete_term_meta( $term_id, (string) $key );
		}
		foreach ( (array) ( $snapshot['meta'] ?? array() ) as $key => $values ) {
			foreach ( (array) $values as $value ) {
				// add_term_meta() unslashes what it is given, so unslashed input loses
				// every backslash in the value.
				add_term_meta( $term_id, (string) $key, wp_slash( SafeData::decode( $value ) ) );
			}
		}

		return true;
	}

	private function restore_option( array $snapshot ): bool {
		$name = (string) ( $snapshot['name'] ?? '' );
		if ( '' === $name ) {
			return false;
		}

		if ( ! empty( $snapshot['existed'] ) ) {
			update_option( $name, $snapshot['value'] ?? '' );
		} else {
			delete_option( $name );
		}

		return true;
	}

	/**
	 * Insert a revision row and prune to the retention limit.
	 *
	 * Returns 0 when the snapshot could not be encoded, which is the honest answer:
	 * no restore point was created.
	 */
	private function insert_revision( int $deployment_id, string $object_type, int $object_id, array $snapshot ): int {
		global $wpdb;

		$json = Json::encode( $snapshot );

		/*
		 * NEVER WRITE AN UNENCODABLE SNAPSHOT AS AN EMPTY STRING.
		 *
		 * This was `(string) wp_json_encode( $snapshot )`, so a failure stored `''`.
		 * `restore()` json_decodes that to null and returns false — so the revision row
		 * existed, a revision id came back, the History screen therefore offered its
		 * Rollback button, and pressing it restored nothing while reporting "Rolled
		 * back 0 of 1 objects". The one moment rollback matters is the moment it is
		 * least likely to be investigated.
		 *
		 * Refusing the row means ImportManager records revision_id 0, History correctly
		 * shows that no rollback is available, and the reason is in the log.
		 */
		if ( null === $json ) {
			DebugLog::error(
				'Could not encode a rollback snapshot — no restore point was created for this object',
				array(
					'deployment_id' => $deployment_id,
					'object_type'   => $object_type,
					'object_id'     => $object_id,
				)
			);

			return 0;
		}

		$wpdb->insert(
			Schema::revisions_table(),
			array(
				'deployment_id' => $deployment_id,
				'object_type'   => $object_type,
				'object_id'     => $object_id,
				'snapshot'      => $json,
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%d', '%s', '%s' )
		);

		$revision_id = (int) $wpdb->insert_id;
		$this->prune( $object_type, $object_id );

		return $revision_id;
	}

	/**
	 * Delegated, not reimplemented.
	 *
	 * This was a second copy of the same crc32 expression. Two copies of a value that
	 * decides WHERE a row is filed is the kind of drift nothing catches: change one and
	 * snapshots get written under an id that `prune()` and every lookup no longer find,
	 * so old revisions accumulate for ever and the restore points quietly stop lining up
	 * with the options they belong to.
	 */
	private function option_id( string $name ): int {
		return OptionExporter::option_id( $name );
	}

	/**
	 * Drop every restore point belonging to a deployment.
	 *
	 * Used after a CANCELLED push has been reverted. The revisions have already served
	 * their whole purpose at that point, and leaving them would offer a Rollback button
	 * for a deployment that no longer changed anything — pressing it would re-apply the
	 * very state the cancel just undid.
	 *
	 * @return int Revisions removed.
	 */
	public function delete_for_deployment( int $deployment_id ): int {
		global $wpdb;

		return (int) $wpdb->delete( Schema::revisions_table(), array( 'deployment_id' => $deployment_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function get( int $revision_id ): ?object {
		global $wpdb;
		$table = Schema::revisions_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $revision_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		return $row ?: null;
	}

	/**
	 * @return object[] Revisions captured under a deployment, newest first.
	 */
	public function for_deployment( int $deployment_id ): array {
		global $wpdb;
		$table = Schema::revisions_table();

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE deployment_id = %d ORDER BY id DESC", // phpcs:ignore WordPress.DB.PreparedSQL
				$deployment_id
			)
		);
	}

	/**
	 * @return array<string,int[]>
	 */
	private function capture_terms( \WP_Post $post ): array {
		$out = array();
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$ids = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $ids ) ) {
				$out[ $taxonomy ] = array_map( 'intval', (array) $ids );
			}
		}
		return $out;
	}

	/**
	 * Replace a post's meta with the snapshot's, preserving IFS Deploy's own stamps.
	 *
	 * Those stamps are excluded on BOTH sides. The first deploy of a page snapshots it
	 * *before* the origin link is written, so a naive full replace would delete
	 * `_ifs_deploy_origin_id` on rollback — after which the next deploy can no longer
	 * identify the page and falls back to id/slug guessing, or creates a duplicate.
	 * They describe the deployment relationship, not the content, so a content rollback
	 * has no business touching them.
	 */
	private function replace_meta( int $post_id, array $meta ): void {
		$current = get_post_meta( $post_id );

		foreach ( array_keys( (array) $current ) as $key ) {
			$key = (string) $key;
			if ( self::is_preserved_meta( $key ) ) {
				continue;
			}

			delete_post_meta( $post_id, $key );
		}

		foreach ( $meta as $key => $values ) {
			$key = (string) $key;
			if ( self::is_preserved_meta( $key ) ) {
				continue;
			}

			foreach ( (array) $values as $value ) {
				add_post_meta( $post_id, $key, wp_slash( SafeData::decode( $value ) ) );
			}
		}
	}

	/**
	 * Meta a rollback must leave exactly as it is: IFS Deploy's cross-site identity.
	 */
	private static function is_preserved_meta( string $key ): bool {
		// Both prefixes. A snapshot captured before the plugin was renamed carries the old
		// bookkeeping keys in its payload; excluding them here is what stops a restore reviving
		// a stale stamp beside the live one.
		return 0 === strpos( $key, '_ifs_deploy_' ) || 0 === strpos( $key, Legacy::META_PREFIX );
	}

	/**
	 * Keep only the newest Config::MAX_REVISIONS snapshots for an object.
	 */
	private function prune( string $object_type, int $object_id ): void {
		global $wpdb;
		$table = Schema::revisions_table();

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE object_type = %s AND object_id = %d ORDER BY id DESC", // phpcs:ignore WordPress.DB.PreparedSQL
				$object_type,
				$object_id
			)
		);

		$stale = array_slice( array_map( 'intval', (array) $ids ), Config::MAX_REVISIONS );
		foreach ( $stale as $stale_id ) {
			$wpdb->delete( $table, array( 'id' => $stale_id ), array( '%d' ) );
		}
	}
}
