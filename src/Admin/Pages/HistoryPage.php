<?php
declare(strict_types=1);

namespace IfsDeploy\Admin\Pages;

use IfsDeploy\Admin\AdminMenu;
use IfsDeploy\Admin\Section;
use IfsDeploy\History\DeploymentRepository;
use IfsDeploy\Support\Access;

/**
 * Deployment History: shows the most recent deployments with status, the object
 * names involved, and a Rollback action where one is possible.
 */
final class HistoryPage {

	/** How many recent deployments to display. */
	private const DISPLAY_LIMIT = 50;

	public function render(): void {
		if ( ! current_user_can( Access::CAP_ACCESS ) ) {
			return;
		}

		// Scoped the same way as Pending Changes: without "see all", a user sees only
		// the deployments they started.
		$deployments = ( new DeploymentRepository() )->recent( self::DISPLAY_LIMIT, Access::scope_user_id() );

		Section::title(
			__( 'Deployment History', 'ifs-deploy' ),
			__( 'Every push, what it contained, and whether it can be rolled back.', 'ifs-deploy' )
		);

		if ( ! Access::sees_all() ) {
			echo '<p class="description">' . esc_html__( 'Showing deployments you started.', 'ifs-deploy' ) . '</p>';
		}

		echo '<p>';
		if ( current_user_can( AdminMenu::CAPABILITY ) ) {
			printf(
				'<button type="button" class="button" id="ifs-deploy-clear-history"%s>%s</button> ',
				empty( $deployments ) ? ' disabled' : '',
				esc_html__( 'Clear History', 'ifs-deploy' )
			);
		}
		echo '<span class="description">' . esc_html__( 'IFS Deploy keeps the latest 3 restore points per page/post, so older deployments of the same object may no longer be rollback-able.', 'ifs-deploy' ) . '</span>';
		echo '</p>';

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Deployment', 'ifs-deploy' ) . '</th>';
		echo '<th>' . esc_html__( 'Date', 'ifs-deploy' ) . '</th>';
		echo '<th>' . esc_html__( 'User', 'ifs-deploy' ) . '</th>';
		echo '<th>' . esc_html__( 'Objects (pages / posts)', 'ifs-deploy' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'ifs-deploy' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'ifs-deploy' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $deployments ) ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No deployments yet.', 'ifs-deploy' ) . '</td></tr>';
		}

		foreach ( $deployments as $deployment ) {
			$log  = json_decode( (string) $deployment->deployment_log, true );
			$log  = is_array( $log ) ? $log : array();
			$user = get_userdata( (int) $deployment->deployed_by );

			printf(
				'<tr>
					<td><code>%1$s</code></td>
					<td>%2$s</td>
					<td>%3$s</td>
					<td>%4$s</td>
					<td>%5$s</td>
					<td>%6$s</td>
				</tr>',
				esc_html( substr( (string) $deployment->deployment_uuid, 0, 8 ) ),
				esc_html( $deployment->deployed_at ),
				esc_html( $user ? $user->display_name : __( '—', 'ifs-deploy' ) ),
				$this->objects_cell( $log ),
				$this->status_badge( (string) $deployment->deployment_status ),
				$this->action_cell( $deployment, $log )
			);
		}

		echo '</tbody></table>';


	}

	/**
	 * Rollback button only when the deployment can actually be rolled back.
	 */
	private function action_cell( object $deployment, array $log ): string {
		if ( ! current_user_can( Access::CAP_ROLLBACK ) ) {
			return '<span class="description">&mdash;</span>';
		}

		if ( ! $this->can_rollback( $deployment, $log ) ) {
			return '<span class="description">&mdash;</span>';
		}

		return sprintf(
			'<button type="button" class="button ifs-deploy-rollback" data-id="%d">%s</button>',
			(int) $deployment->id,
			esc_html__( 'Rollback', 'ifs-deploy' )
		);
	}

	/**
	 * A deployment is rollback-able when it succeeded (fully or partially), has
	 * not already been rolled back, and produced at least one snapshot on
	 * Production (revision_id > 0 — i.e. it overwrote existing content).
	 */
	private function can_rollback( object $deployment, array $log ): bool {
		$status = (string) $deployment->deployment_status;

		// CANCELLED is here for the same reason as ROLLED_BACK: its snapshots were already
		// applied and then deleted, so a Rollback button would offer to re-apply the very
		// state the cancel undid.
		if ( in_array(
			$status,
			array(
				DeploymentRepository::STATUS_FAILED,
				DeploymentRepository::STATUS_ROLLED_BACK,
				DeploymentRepository::STATUS_CANCELLED,
				DeploymentRepository::STATUS_PENDING,
			),
			true
		) ) {
			return false;
		}

		foreach ( $log as $entry ) {
			if ( is_array( $entry ) && (int) ( $entry['revision_id'] ?? 0 ) > 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Render the names of the objects in a deployment so it is clear what a
	 * rollback would revert. Falls back to an error message when no per-object
	 * detail is available (e.g. a connection failure).
	 *
	 * @param array $log Decoded deployment log (list of per-object results).
	 */
	private function objects_cell( array $log ): string {
		$names = array();

		foreach ( $log as $entry ) {
			if ( ! is_array( $entry ) || ! array_key_exists( 'title', $entry ) ) {
				continue;
			}

			$title = '' !== (string) $entry['title'] ? (string) $entry['title'] : __( '(no title)', 'ifs-deploy' );
			$ok    = ! empty( $entry['ok'] );

			$names[] = sprintf(
				'<span class="ifs-deploy-object %1$s">%2$s%3$s</span>',
				$ok ? 'is-ok' : 'is-fail',
				esc_html( $title ),
				$ok ? '' : ' <em>' . esc_html__( '(failed)', 'ifs-deploy' ) . '</em>'
			);
		}

		if ( empty( $names ) ) {
			if ( isset( $log['error'] ) ) {
				return '<span class="description">' . esc_html( (string) $log['error'] ) . '</span>';
			}
			return '<span class="description">&mdash;</span>';
		}

		return '<div class="ifs-deploy-objects">' . implode( '', $names ) . '</div>';
	}

	private function status_badge( string $status ): string {
		$map = array(
			DeploymentRepository::STATUS_SUCCESS     => array( __( 'Success', 'ifs-deploy' ), 'success' ),
			DeploymentRepository::STATUS_PARTIAL     => array( __( 'Partial', 'ifs-deploy' ), 'partial' ),
			DeploymentRepository::STATUS_FAILED      => array( __( 'Failed', 'ifs-deploy' ), 'failed' ),
			// Stopped part-way and everything it had applied was put back. One line, not a
			// deploy followed by an undo of it.
			DeploymentRepository::STATUS_CANCELLED  => array( __( 'Cancelled', 'ifs-deploy' ), 'failed' ),
			DeploymentRepository::STATUS_PENDING     => array( __( 'Pending', 'ifs-deploy' ), '' ),
			DeploymentRepository::STATUS_ROLLED_BACK => array( __( 'Rolled Back', 'ifs-deploy' ), 'rolledback' ),
		);

		$label = $map[ $status ] ?? array( ucfirst( $status ), '' );

		return sprintf(
			'<span class="ifs-deploy-status ifs-deploy-status-%1$s">%2$s</span>',
			esc_attr( $label[1] ),
			esc_html( $label[0] )
		);
	}
}
