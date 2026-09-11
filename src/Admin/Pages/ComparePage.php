<?php
declare(strict_types=1);

namespace IfsDeploy\Admin\Pages;

use IfsDeploy\Admin\PreviewModal;
use IfsDeploy\Admin\Section;
use IfsDeploy\Admin\Tabs;
use IfsDeploy\Client\CompareService;
use IfsDeploy\Support\Access;
use IfsDeploy\Support\Config;

/**
 * Compare & Sync: shows how Staging content differs from Production and lets the
 * user link existing objects (Sync IDs) or push differences directly.
 */
final class ComparePage {

	/**
	 * How many "In sync" rows are rendered into the page.
	 *
	 * The rest are held in a `<template>` — present, but not laid out — so a site with two
	 * thousand matching pages does not pay to render a list nobody reads.
	 */
	private const INITIAL_ROWS = 10;

	/** How many more each press of "Show more" brings in. */
	private const REVEAL_BATCH = 50;


	public function render(): void {
		if ( ! current_user_can( Access::CAP_RESTRICTED ) ) {
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

		$prod_only = (array) $comparison['prod_only'];

		$this->summary( (array) $comparison['summary'], $different, $missing, $in_sync, $prod_only );

		/*
		 * ── THE ORDER IS "WHAT NEEDS A DECISION", NOT "WHAT MATTERS MOST" ──────────────
		 *
		 * Missing first, then Production-only, then Different, then In sync. It runs from
		 * the groups where the two sites genuinely disagree about what EXISTS, through the
		 * ones where they disagree about content, down to the ones where they agree and
		 * there is nothing to do.
		 *
		 * The card order matches exactly, because the cards are now links INTO these
		 * tables — a summary that lists things in one order and scrolls to them in another
		 * is worse than one that does not scroll at all.
		 */
		$this->objects_table(
			__( 'Not on Production', 'ifs-deploy' ),
			__( 'These exist on Staging but not yet on Production.', 'ifs-deploy' ),
			$missing,
			true,
			'',
			'missing'
		);

		$this->prod_only_table( $prod_only );

		$this->objects_table(
			__( 'Different — needs deploy', 'ifs-deploy' ),
			__( 'These pages/posts differ from Production. Push to make Production match Staging.', 'ifs-deploy' ),
			$different,
			true,
			__( 'Nothing is out of sync. 🎉', 'ifs-deploy' ),
			'different'
		);

		/*
		 * In sync is the biggest group on a healthy site and the least interesting, so only
		 * the first rows are rendered into the page — see `objects_table()`'s $reveal_after.
		 */
		$this->objects_table(
			__( 'In sync', 'ifs-deploy' ),
			__( 'These match Production.', 'ifs-deploy' ),
			$in_sync,
			false,
			'',
			'in_sync',
			self::INITIAL_ROWS
		);
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

	/**
	 * The four counts, in the same order as the tables they scroll to.
	 *
	 * Each card is given the rows of its own group so it can tell whether there is
	 * anything to scroll TO. Three of the four tables are skipped entirely when empty, so a
	 * card showing 0 would otherwise be a button that silently does nothing — which reads
	 * as a broken feature rather than as an empty group.
	 */
	private function summary( array $summary, array $different, array $missing, array $in_sync, array $prod_only ): void {
		echo '<div class="ifs-deploy-cards">';
		$this->card( __( 'Not on Production', 'ifs-deploy' ), (string) ( $summary[ CompareService::STATUS_MISSING ] ?? 0 ), 'missing', ! empty( $missing ) );
		$this->card( __( 'Only on Production', 'ifs-deploy' ), (string) ( $summary[ CompareService::STATUS_PROD_ONLY ] ?? 0 ), 'prod_only', ! empty( $prod_only ) );
		$this->card( __( 'Different', 'ifs-deploy' ), (string) ( $summary[ CompareService::STATUS_DIFFERENT ] ?? 0 ), 'different', true );
		$this->card( __( 'In sync', 'ifs-deploy' ), (string) ( $summary[ CompareService::STATUS_IN_SYNC ] ?? 0 ), 'in_sync', ! empty( $in_sync ) );
		echo '</div>';
	}

	/**
	 * Render one status group as its own table.
	 *
	 * @param array  $rows          Rows for this group.
	 * @param bool   $with_action   Whether to render the push action column.
	 * @param string $empty_message Message when the group is empty ('' = skip).
	 */
	/**
	 * @param int $reveal_after Render only this many rows and hold the rest back behind a
	 *                          "Show more" button. 0 renders every row.
	 */
	private function objects_table( string $heading, string $description, array $rows, bool $with_action, string $empty_message, string $group = '', int $reveal_after = 0 ): void {
		// The anchor the summary card scrolls to. Wraps the whole group — heading,
		// description and table — so the jump lands on the title rather than on a row.
		printf( '<div class="ifs-deploy-group" id="%s">', esc_attr( 'ifs-deploy-group-' . $group ) );

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

			echo '</div>';

			return;
		}

		/*
		 * THE BULK BAR, and the wording is doing real work.
		 *
		 * These rows are not a list of anyone's edits — they are every object whose content
		 * differs from Production's, which includes things nobody touched on Staging (a page
		 * edited directly on Production, say). Pushing several at once therefore overwrites
		 * Production for all of them, and the button has to say that rather than implying it
		 * is publishing work someone did here.
		 */
		if ( $with_action ) {
			printf(
				'<div class="dp-actions ifs-deploy-compare-actions" data-group="%1$s">'
					. '<button type="button" class="button button-primary ifs-deploy-compare-push" data-group="%1$s">%2$s</button>'
					. '<span class="dp-help ifs-deploy-compare-count" data-group="%1$s"></span>'
					. '</div>',
				esc_attr( $group ),
				esc_html__( 'Push Selected to Production', 'ifs-deploy' )
			);
		}

		echo '<table class="wp-list-table widefat fixed striped ifs-deploy-compare">';
		echo '<thead><tr>';
		if ( $with_action ) {
			printf(
				'<td class="check-column"><input type="checkbox" class="ifs-deploy-compare-all" data-group="%s" /></td>',
				esc_attr( $group )
			);
		}
		echo '<th>' . esc_html__( 'Object', 'ifs-deploy' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'ifs-deploy' ) . '</th>';
		echo '<th>' . esc_html__( 'Match', 'ifs-deploy' ) . '</th>';
		echo '<th>' . esc_html__( 'Production ID', 'ifs-deploy' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'ifs-deploy' ) . '</th>';
		if ( $with_action ) {
			echo '<th>' . esc_html__( 'Actions', 'ifs-deploy' ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		/*
		 * ── WHY A <template> RATHER THAN HIDDEN ROWS OR AN AJAX CALL ──────────────────
		 *
		 * On a healthy site "In sync" is nearly every page, and on a large one that is
		 * thousands of table rows the reader almost never looks at. They still cost the
		 * browser a full layout and paint.
		 *
		 * Fetching the rest on demand is not an option: the comparison is deliberately
		 * UNCACHED — `CompareService` bypasses caches so the screen shows live truth — so a
		 * "show more" request would re-run the whole comparison, signing another call to
		 * Production for up to 2000 posts to reveal fifty rows.
		 *
		 * Hiding them with CSS would not help either; a `display:none` row is still parsed
		 * and still built.
		 *
		 * `<template>` is the one that actually does what is wanted: the browser parses the
		 * markup but does not render, lay out or paint it until something moves it into the
		 * document. So the rows are already here — no second request — and cost nothing
		 * until asked for. It also keeps ONE rendering path: the rows are built by the same
		 * PHP loop either way, rather than duplicated as markup-building JavaScript that
		 * would drift from it.
		 *
		 * What this does NOT do is make the page arrive faster. The HTML is the same size
		 * and the request to Production is unchanged; what disappears is the layout cost.
		 */
		$held_back = $reveal_after > 0 && count( $rows ) > $reveal_after;
		$rendered  = 0;

		foreach ( $rows as $row ) {
			if ( $held_back && $rendered === $reveal_after ) {
				echo '</tbody></table>';
				echo '<template class="ifs-deploy-more-rows">';
			}

			++$rendered;

			$check = $with_action
				? sprintf(
					'<th scope="row" class="check-column"><input type="checkbox" class="ifs-deploy-compare-item" data-group="%1$s" value="%2$d" /></th>',
					esc_attr( $group ),
					(int) $row['id']
				)
				: '';

			$cells = $check . sprintf(
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

		if ( $held_back ) {
			// The template closes here; the table it belongs to was closed before it opened.
			echo '</template>';

			$remaining = count( $rows ) - $reveal_after;

			printf(
				'<p class="ifs-deploy-more"><button type="button" class="button ifs-deploy-show-more" data-batch="%1$d">%2$s</button></p>',
				self::REVEAL_BATCH,
				esc_html(
					sprintf(
						/* translators: 1: how many rows are still hidden, 2: how many the button reveals */
						_n( 'Show more (%1$d hidden)', 'Show more (%1$d hidden)', $remaining, 'ifs-deploy' ),
						$remaining,
						self::REVEAL_BATCH
					)
				)
			);
		} else {
			echo '</tbody></table>';
		}

		echo '</div>';
	}

	private function prod_only_table( array $prod_only ): void {
		if ( empty( $prod_only ) ) {
			return;
		}

		// Wrapped like the other groups so its summary card has somewhere to scroll to.
		echo '<div class="ifs-deploy-group" id="ifs-deploy-group-prod_only">';

		Section::heading(
			__( 'Only on Production', 'ifs-deploy' ),
			'<span class="ifs-deploy-count">' . (int) count( $prod_only ) . '</span>'
		);
		echo '<p class="dp-help">' . esc_html__( 'These exist on Production but have no match on Staging. Copperleaf Deploy never deletes content automatically.', 'ifs-deploy' ) . '</p>';
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

		echo '</div>';
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

	/**
	 * One summary figure, which doubles as a jump link to its table.
	 *
	 * A BUTTON rather than an anchor: this scrolls within the page rather than navigating,
	 * and the panel is swapped in by the tab loader, so a `#hash` would survive in the URL
	 * long after the section it names had gone. A button also gets keyboard and
	 * screen-reader behaviour for free, which the plain `<div>` these used to be did not.
	 *
	 * @param bool $has_rows Whether the table this points at was rendered at all.
	 */
	private function card( string $label, string $value, string $group = '', bool $has_rows = true ): void {
		if ( '' === $group || ! $has_rows ) {
			printf(
				'<div class="ifs-deploy-card"><span class="ifs-deploy-card-label">%1$s</span><span class="ifs-deploy-card-value">%2$s</span></div>',
				esc_html( $label ),
				esc_html( $value )
			);

			return;
		}

		printf(
			'<button type="button" class="ifs-deploy-card is-linked" data-scroll-to="%3$s">'
				. '<span class="ifs-deploy-card-label">%1$s</span>'
				. '<span class="ifs-deploy-card-value">%2$s</span>'
				. '</button>',
			esc_html( $label ),
			esc_html( $value ),
			esc_attr( 'ifs-deploy-group-' . $group )
		);
	}
}
