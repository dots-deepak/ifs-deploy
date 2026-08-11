<?php
declare(strict_types=1);

namespace IfsDeploy\Admin\Pages;

use IfsDeploy\Admin\AdminMenu;
use IfsDeploy\Admin\PreviewModal;
use IfsDeploy\Admin\Section;
use IfsDeploy\Admin\Tabs;
use IfsDeploy\Client\CompareService;
use IfsDeploy\Support\Config;

/**
 * Compare & Sync: shows how Staging content differs from Production and lets the
 * user link existing objects (Sync IDs) or push differences directly.
 */
final class ComparePage {

	public function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		Section::title(
			__( 'Compare & Sync', 'ifs-deploy' ),
			__( 'How this site differs from Production, object by object.', 'ifs-deploy' )
		);

		if ( ! Config::is_staging() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Comparison runs from the Staging site. Set this site\'s role to Staging to use it.', 'ifs-deploy' ) . '</p></div>';
			return;
		}

		echo '<div class="dp-actions">';
		echo '<button type="button" class="button button-primary" id="ifs-deploy-sync-ids">' . esc_html__( 'Sync IDs', 'ifs-deploy' ) . '</button>';

		// data-tab makes Refresh re-fetch this panel instead of reloading the whole admin
		// page — which is the point of the comparison being expensive.
		printf(
			'<a href="%1$s" class="button" data-tab="compare">%2$s</a>',
			esc_url( Tabs::url( 'compare' ) ),
			esc_html__( 'Refresh', 'ifs-deploy' )
		);
		echo '</div>';

		echo '<p class="dp-help">' . esc_html__( 'Sync IDs links existing Production pages to their Staging originals so future deploys update them instead of creating duplicates.', 'ifs-deploy' ) . '</p>';

		$comparison = ( new CompareService() )->compare();

		if ( empty( $comparison['ok'] ) ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( (string) ( $comparison['error'] ?? __( 'Comparison failed.', 'ifs-deploy' ) ) )
			);
			return;
		}

		// Before the numbers, not after them: a summary drawn from a partial index reads
		// as authoritative, and the whole point of this notice is that it is not.
		$truncated = (string) ( $comparison['truncated'] ?? '' );
		if ( '' !== $truncated ) {
			printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html( $truncated ) );
		}

		$rows      = (array) $comparison['rows'];
		$different = $this->filter_status( $rows, CompareService::STATUS_DIFFERENT );
		$missing   = $this->filter_status( $rows, CompareService::STATUS_MISSING );
		$in_sync   = $this->filter_status( $rows, CompareService::STATUS_IN_SYNC );

		$this->summary( (array) $comparison['summary'] );

		// Most important first: pages that differ and can be pushed.
		$this->objects_table(
			__( 'Different — needs deploy', 'ifs-deploy' ),
			__( 'These pages/posts differ from Production. Push to make Production match Staging.', 'ifs-deploy' ),
			$different,
			true,
			__( 'Nothing is out of sync. 🎉', 'ifs-deploy' )
		);

		if ( $missing ) {
			$this->objects_table(
				__( 'Not on Production', 'ifs-deploy' ),
				__( 'These exist on Staging but not yet on Production.', 'ifs-deploy' ),
				$missing,
				true,
				''
			);
		}

		if ( $in_sync ) {
			$this->objects_table(
				__( 'In sync', 'ifs-deploy' ),
				__( 'These match Production.', 'ifs-deploy' ),
				$in_sync,
				false,
				''
			);
		}

		$this->prod_only_table( (array) $comparison['prod_only'] );


	}

	/**
	 * @return array<int,array> Rows whose status matches, reindexed.
	 */
	private function filter_status( array $rows, string $status ): array {
		return array_values(
			array_filter(
				$rows,
				static fn( array $row ): bool => $row['status'] === $status
			)
		);
	}

	private function summary( array $summary ): void {
		echo '<div class="ifs-deploy-cards">';
		$this->card( __( 'In sync', 'ifs-deploy' ), (string) ( $summary[ CompareService::STATUS_IN_SYNC ] ?? 0 ) );
		$this->card( __( 'Different', 'ifs-deploy' ), (string) ( $summary[ CompareService::STATUS_DIFFERENT ] ?? 0 ) );
		$this->card( __( 'Not on Production', 'ifs-deploy' ), (string) ( $summary[ CompareService::STATUS_MISSING ] ?? 0 ) );
		$this->card( __( 'Only on Production', 'ifs-deploy' ), (string) ( $summary[ CompareService::STATUS_PROD_ONLY ] ?? 0 ) );
		echo '</div>';
	}

	/**
	 * Render one status group as its own table.
	 *
	 * @param array  $rows          Rows for this group.
	 * @param bool   $with_action   Whether to render the push action column.
	 * @param string $empty_message Message when the group is empty ('' = skip).
	 */
	private function objects_table( string $heading, string $description, array $rows, bool $with_action, string $empty_message ): void {
		Section::heading(
			$heading,
			'<span class="ifs-deploy-count">' . (int) count( $rows ) . '</span>'
		);

		if ( '' !== $description ) {
			echo '<p class="dp-help">' . esc_html( $description ) . '</p>';
		}

		if ( empty( $rows ) ) {
			if ( '' !== $empty_message ) {
				echo '<p>' . esc_html( $empty_message ) . '</p>';
			}
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Object', 'ifs-deploy' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'ifs-deploy' ) . '</th>';
		echo '<th>' . esc_html__( 'Match', 'ifs-deploy' ) . '</th>';
		echo '<th>' . esc_html__( 'Production ID', 'ifs-deploy' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'ifs-deploy' ) . '</th>';
		if ( $with_action ) {
			echo '<th>' . esc_html__( 'Actions', 'ifs-deploy' ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$cells = sprintf(
				'<td><strong>%1$s</strong><span class="dp-id">#%2$d</span></td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td>',
				esc_html( $row['title'] ?: __( '(no title)', 'ifs-deploy' ) ),
				(int) $row['id'],
				esc_html( $row['type'] ),
				esc_html( $this->match_label( (string) $row['match_type'] ) ),
				$row['remote_id'] ? (int) $row['remote_id'] : '&mdash;',
				$this->status_badge( (string) $row['status'] )
			);

			if ( $with_action ) {
				$label = CompareService::STATUS_MISSING === $row['status']
					? __( 'Create on Production', 'ifs-deploy' )
					: __( 'Push latest to Production', 'ifs-deploy' );

				// Review before deciding: the preview is keyed by POST id here,
				// because Compare rows are not backed by a queue entry.
				$cells .= sprintf(
					'<td><div class="ifs-deploy-row-actions">%1$s'
						. '<button type="button" class="button button-small button-primary ifs-deploy-push-one" data-id="%2$d">%3$s</button>'
						. '</div></td>',
					PreviewModal::button( 'post-id', (int) $row['id'], (string) $row['title'] ),
					(int) $row['id'],
					esc_html( $label )
				);
			}

			echo '<tr>' . $cells . '</tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts above.
		}

		echo '</tbody></table>';
	}

	private function prod_only_table( array $prod_only ): void {
		if ( empty( $prod_only ) ) {
			return;
		}

		Section::heading( __( 'Only on Production', 'ifs-deploy' ) );
		echo '<p class="dp-help">' . esc_html__( 'These exist on Production but have no match on Staging. IFS Deploy never deletes content automatically.', 'ifs-deploy' ) . '</p>';
		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Object', 'ifs-deploy' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'ifs-deploy' ) . '</th>';
		echo '<th>' . esc_html__( 'Production ID', 'ifs-deploy' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $prod_only as $item ) {
			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$d</td></tr>',
				esc_html( (string) ( $item['title'] ?: __( '(no title)', 'ifs-deploy' ) ) ),
				esc_html( (string) $item['type'] ),
				(int) $item['id']
			);
		}

		echo '</tbody></table>';
	}

	private function match_label( string $type ): string {
		switch ( $type ) {
			case 'origin':
				return __( 'Linked', 'ifs-deploy' );
			case 'id':
				return __( 'By ID', 'ifs-deploy' );
			case 'slug':
				return __( 'By slug', 'ifs-deploy' );
			default:
				return __( 'No match', 'ifs-deploy' );
		}
	}

	private function status_badge( string $status ): string {
		$labels = array(
			CompareService::STATUS_IN_SYNC   => array( __( 'In sync', 'ifs-deploy' ), 'success' ),
			CompareService::STATUS_DIFFERENT => array( __( 'Different', 'ifs-deploy' ), 'partial' ),
			CompareService::STATUS_MISSING   => array( __( 'Not on Production', 'ifs-deploy' ), 'failed' ),
		);

		$label = $labels[ $status ] ?? array( $status, '' );

		return sprintf(
			'<span class="ifs-deploy-status ifs-deploy-status-%1$s">%2$s</span>',
			esc_attr( $label[1] ),
			esc_html( $label[0] )
		);
	}

	private function card( string $label, string $value ): void {
		printf(
			'<div class="ifs-deploy-card"><span class="ifs-deploy-card-label">%1$s</span><span class="ifs-deploy-card-value">%2$s</span></div>',
			esc_html( $label ),
			esc_html( $value )
		);
	}
}
