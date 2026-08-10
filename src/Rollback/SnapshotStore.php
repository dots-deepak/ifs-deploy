<?php
declare(strict_types=1);

namespace IfsDeploy\Rollback;

use IfsDeploy\Export\MenuExporter;
use IfsDeploy\Import\MenuImporter;
use IfsDeploy\Support\Config;
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
	 */
	private function insert_revision( int $deployment_id, string $object_type, int $object_id, array $snapshot ): int {
		global $wpdb;
		$wpdb->insert(
			Schema::revisions_table(),
			array(
				'deployment_id' => $deployment_id,
				'object_type'   => $object_type,
				'object_id'     => $object_id,
				'snapshot'      => (string) wp_json_encode( $snapshot ),
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%d', '%s', '%s' )
		);

		$revision_id = (int) $wpdb->insert_id;
		$this->prune( $object_type, $object_id );

		return $revision_id;
	}

	private function option_id( string $name ): int {
		return (int) sprintf( '%u', crc32( $name ) );
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
