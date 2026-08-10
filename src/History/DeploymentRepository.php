<?php
declare(strict_types=1);

namespace IfsDeploy\History;

use IfsDeploy\Support\Schema;

/**
 * CRUD for the deployments (history) table. Used on both sites: Staging records
 * the user-initiated deployment; Production records the receiving deployment
 * that groups the pre-deploy snapshots.
 */
final class DeploymentRepository {

	public const STATUS_PENDING     = 'pending';
	public const STATUS_SUCCESS     = 'success';
	public const STATUS_PARTIAL     = 'partial';
	public const STATUS_FAILED      = 'failed';
	public const STATUS_ROLLED_BACK = 'rolled_back';

	public function create( string $uuid, int $user_id, string $status = self::STATUS_PENDING ): int {
		global $wpdb;

		$wpdb->insert(
			Schema::deployments_table(),
			array(
				'deployment_uuid'   => $uuid,
				'deployed_by'       => $user_id,
				'deployed_at'       => current_time( 'mysql' ),
				'deployment_status' => $status,
				'deployment_log'    => wp_json_encode( array() ),
			),
			array( '%s', '%d', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	public function update( int $id, string $status, array $log ): void {
		global $wpdb;

		$wpdb->update(
			Schema::deployments_table(),
			array(
				'deployment_status' => $status,
				'deployment_log'    => wp_json_encode( $log ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	public function set_status( int $id, string $status ): void {
		global $wpdb;
		$wpdb->update(
			Schema::deployments_table(),
			array( 'deployment_status' => $status ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Delete all deployment history rows. Snapshots on Production are untouched.
	 */
	public function clear(): int {
		global $wpdb;
		$table = Schema::deployments_table();
		return (int) $wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Delete rows deployed strictly before `$cutoff`, for the retention policy.
	 *
	 * `$cutoff` is a `Y-m-d H:i:s` string in the SITE's timezone, matching what
	 * `deployed_at` stores — see LogRetention::cutoff() for why it is not UTC.
	 *
	 * Snapshots on Production are untouched, exactly as in clear(): this removes the
	 * record of a deployment, never anything a rollback would need.
	 *
	 * @return int Rows removed.
	 */
	public function delete_before( string $cutoff ): int {
		global $wpdb;
		$table = Schema::deployments_table();

		return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$wpdb->prepare( "DELETE FROM {$table} WHERE deployed_at < %s", $cutoff ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}

	public function get( int $id ): ?object {
		global $wpdb;
		$table = Schema::deployments_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		return $row ?: null;
	}

	public function get_by_uuid( string $uuid ): ?object {
		global $wpdb;
		$table = Schema::deployments_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE deployment_uuid = %s", $uuid ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		return $row ?: null;
	}

	/**
	 * Recent deployments, optionally only those started by one user.
	 *
	 * @param int|null $user_id Null for every user (the previous behaviour, so
	 *                          existing callers are unaffected).
	 *
	 * @return object[]
	 */
	public function recent( int $limit = 50, ?int $user_id = null ): array {
		global $wpdb;
		$table = Schema::deployments_table();

		if ( null === $user_id ) {
			return (array) $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ) // phpcs:ignore WordPress.DB.PreparedSQL
			);
		}

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE deployed_by = %d ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$user_id,
				$limit
			)
		);
	}
}
