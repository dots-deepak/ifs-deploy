<?php
declare(strict_types=1);

namespace IfsDeploy\Admin;

use IfsDeploy\Support\PackageDiff;
use IfsDeploy\Support\UrlRewriter;

/**
 * Renders a PreviewService result as the HTML panel shown under a Pending Changes
 * row.
 *
 * Line-level diffing is delegated to core's wp_text_diff(), which is the same
 * renderer WordPress uses for post revisions: red/deleted on the left, green/added
 * on the right, with word-level highlighting inside changed lines. That keeps the
 * colours identical to the editor's own revision screen (styled by wp-admin's
 * common.css, already loaded here) instead of inventing a second visual language.
 */
final class DiffRenderer {

	/**
	 * Group display order and headings.
	 *
	 * @return array<string,string>
	 */
	private static function groups(): array {
		return array(
			PackageDiff::GROUP_CORE     => __( 'Content & settings', 'ifs-deploy' ),
			PackageDiff::GROUP_FIELD    => __( 'Custom fields', 'ifs-deploy' ),
			PackageDiff::GROUP_TAXONOMY => __( 'Categories, tags & taxonomies', 'ifs-deploy' ),
			PackageDiff::GROUP_MEDIA    => __( 'Featured image', 'ifs-deploy' ),
		);
	}

	/**
	 * @param array $preview PreviewService::preview() result.
	 */
	public function render( array $preview ): string {
		if ( empty( $preview['ok'] ) ) {
			return $this->message( (string) ( $preview['error'] ?? __( 'Preview unavailable.', 'ifs-deploy' ) ), 'error' );
		}

		if ( 'delete' === ( $preview['action'] ?? '' ) ) {
			return $this->render_delete( $preview );
		}

		$fields = (array) ( $preview['fields'] ?? array() );

		$html  = '<div class="ifs-deploy-preview">';
		$html .= $this->header( $preview, $fields );

		if ( empty( $fields ) ) {
			$html .= $this->message(
				! empty( $preview['resolved'] )
					? __( 'No deployable differences — Production already matches Staging, so this item has been removed from Pending Changes. Refresh the list to see it gone.', 'ifs-deploy' )
					: __( 'No deployable differences — Production already matches Staging for this object, so pushing it would change nothing.', 'ifs-deploy' ),
				'info'
			);
			return $html . '</div>';
		}

		$html .= $this->render_fields( $fields );
		$html .= '</div>';

		return $html;
	}

	/**
	 * Just the grouped field diffs, with no surrounding header.
	 *
	 * Split out so the rollback dialog can reuse the identical rendering under its own
	 * heading, rather than growing a second differ that could drift from this one.
	 *
	 * @param array $fields PackageDiff::compare() output.
	 */
	public function render_fields( array $fields ): string {
		$html = '';

		foreach ( self::groups() as $group => $heading ) {
			$html .= $this->group( $heading, $this->of_group( $fields, $group ) );
		}

		return $html . $this->technical( $this->of_group( $fields, PackageDiff::GROUP_TECHNICAL ) );
	}

	/**
	 * Deletes have no field diff: the object is trashed as a whole.
	 */
	private function render_delete( array $preview ): string {
		$html  = '<div class="ifs-deploy-preview">';
		$html .= '<div class="ifs-deploy-preview-head">';
		$html .= '<span class="ifs-deploy-chip ifs-deploy-chip-removed">' . esc_html__( 'Will be moved to Trash', 'ifs-deploy' ) . '</span>';
		$html .= '</div>';

		if ( empty( $preview['found'] ) ) {
			$html .= $this->message( __( 'No matching object was found on Production, so this push will do nothing.', 'ifs-deploy' ), 'info' );
			return $html . '</div>';
		}

		$html .= $this->message(
			sprintf(
				/* translators: 1: object title, 2: production post ID */
				__( '"%1$s" (Production #%2$d) will be moved to the Trash. It is not permanently deleted and can be restored from Production\'s Trash.', 'ifs-deploy' ),
				(string) ( $preview['title'] ?? '' ),
				(int) ( $preview['prod_id'] ?? 0 )
			),
			'info'
		);

		return $html . '</div>';
	}

	/**
	 * Panel header: what the push will do, how the object was matched, and the
	 * legend for which side is which.
	 *
	 * @param array $fields Diff fields.
	 */
	private function header( array $preview, array $fields ): string {
		$found   = ! empty( $preview['found'] );
		$prod_id = (int) ( $preview['prod_id'] ?? 0 );

		$html = '<div class="ifs-deploy-preview-head">';

		if ( $found ) {
			$html .= sprintf(
				'<span class="ifs-deploy-chip ifs-deploy-chip-changed">%s</span>',
				esc_html(
					sprintf(
						/* translators: %d: production post ID */
						__( 'Will update Production #%d', 'ifs-deploy' ),
						$prod_id
					)
				)
			);
			$html .= ' <span class="description">' . esc_html( $this->match_note( (string) ( $preview['strategy'] ?? 'none' ) ) ) . '</span>';
		} else {
			$html .= '<span class="ifs-deploy-chip ifs-deploy-chip-added">' . esc_html__( 'Will be created on Production', 'ifs-deploy' ) . '</span>';
			$html .= ' <span class="description">' . esc_html__( 'No matching object exists there yet, so everything below is new.', 'ifs-deploy' ) . '</span>';
		}

		if ( ! empty( $fields ) ) {
			$count = count( $fields );
			$html .= '<p class="ifs-deploy-preview-legend">';
			// Staging first: it is the left-hand column (see diff_table()).
			$html .= '<span class="ifs-deploy-legend-incoming">' . esc_html__( 'Left: Staging, after this push', 'ifs-deploy' ) . '</span>';
			$html .= '<span class="ifs-deploy-legend-current">' . esc_html__( 'Right: Production now', 'ifs-deploy' ) . '</span>';
			$html .= '<span class="ifs-deploy-count">' . (int) $count . '</span> ';
			$html .= esc_html( _n( 'field differs', 'fields differ', $count, 'ifs-deploy' ) );
			$html .= '</p>';

			$html .= '<p class="description ifs-deploy-preview-urlnote">';
			$html .= sprintf(
				/* translators: %s: the placeholder shown in place of a site URL */
				esc_html__( 'Site URLs appear as %s and are not compared — the deploy resolves them for the target environment.', 'ifs-deploy' ),
				'<code>' . esc_html( UrlRewriter::TOKEN ) . '</code>'
			);
			$html .= '</p>';
		}

		return $html . '</div>';
	}

	/**
	 * Explain how confident the Production match is — a slug or ID match is a
	 * guess until it has been linked, and the admin should see that.
	 */
	private function match_note( string $strategy ): string {
		switch ( $strategy ) {
			case 'origin':
				return __( 'Matched by deployment link — this is definitely the same object.', 'ifs-deploy' );
			case 'id':
				return __( 'Matched by ID. Use Compare & Sync → Sync IDs to link them permanently.', 'ifs-deploy' );
			case 'slug':
				return __( 'Matched by slug. Use Compare & Sync → Sync IDs to link them permanently.', 'ifs-deploy' );
			default:
				return '';
		}
	}

	/**
	 * @param array $fields All fields.
	 *
	 * @return array<int,array> Fields in one group.
	 */
	private function of_group( array $fields, string $group ): array {
		return array_values(
			array_filter(
				$fields,
				static fn( array $field ): bool => $group === ( $field['group'] ?? '' )
			)
		);
	}

	/**
	 * @param array $fields Fields in this group.
	 */
	private function group( string $heading, array $fields ): string {
		if ( empty( $fields ) ) {
			return '';
		}

		$html = '<h3 class="ifs-deploy-diff-group">' . esc_html( $heading ) . '</h3>';
		foreach ( $fields as $field ) {
			$html .= $this->field( $field );
		}

		return $html;
	}

	/**
	 * ACF field-key mirrors and other underscore-prefixed internals are real
	 * differences, so they are shown — but collapsed, since they are noise for
	 * anyone reviewing content.
	 *
	 * @param array $fields Technical-group fields.
	 */
	private function technical( array $fields ): string {
		if ( empty( $fields ) ) {
			return '';
		}

		$html  = '<details class="ifs-deploy-diff-technical">';
		$html .= '<summary>' . esc_html(
			sprintf(
				/* translators: %d: number of technical fields */
				_n( '%d technical field (plugin internals, ACF field keys)', '%d technical fields (plugin internals, ACF field keys)', count( $fields ), 'ifs-deploy' ),
				count( $fields )
			)
		) . '</summary>';

		foreach ( $fields as $field ) {
			$html .= $this->field( $field );
		}

		return $html . '</details>';
	}

	/**
	 * @param array $field One diff field.
	 */
	private function field( array $field ): string {
		$label  = (string) ( $field['label'] ?? '' );
		$change = (string) ( $field['change'] ?? PackageDiff::CHANGE_CHANGED );
		$before = (string) ( $field['before'] ?? '' );
		$after  = (string) ( $field['after'] ?? '' );

		$html  = '<div class="ifs-deploy-diff-field">';
		$html .= '<h4><code>' . esc_html( $label ) . '</code> ' . $this->chip( $change ) . '</h4>';

		if ( PackageDiff::CHANGE_KEPT === $change ) {
			$html .= '<p class="description ifs-deploy-diff-note">'
				. esc_html__( 'Only on Production. Copperleaf Deploy never deletes what a push does not mention, so this is left exactly as it is.', 'ifs-deploy' )
				. '</p>';
		}

		// The scroll container lives outside the table: applying overflow to
		// table.diff itself would need display:block, which discards the <col>
		// widths core emits and collapses the split view.
		$html .= '<div class="ifs-deploy-diff-body">' . $this->diff_table( $before, $after ) . '</div>';

		return $html . '</div>';
	}

	/**
	 * Core's revision differ, with a plain two-column table as the fallback for the
	 * cases wp_text_diff() reports as identical (it normalises trailing whitespace,
	 * which our comparison does not).
	 *
	 * COLUMN ORDER: Staging on the LEFT, Production on the RIGHT — so the arguments
	 * are passed "after, before" rather than the usual order.
	 *
	 * wp_text_diff() hard-codes `diff-deletedline` on its left column and
	 * `diff-addedline` on its right, so swapping the arguments alone would paint the
	 * incoming Staging text red-as-deleted, which inverts its meaning. admin.css
	 * therefore repaints those two core classes inside `.ifs-deploy-diff-body`:
	 * left stays GREEN (what you are about to publish) and right stays RED (what it
	 * replaces). The class names are core's and cannot be renamed; only the colours
	 * are ours. Keep the two in step — changing one without the other silently
	 * reverses the meaning of every diff.
	 */
	private function diff_table( string $before, string $after ): string {
		$diff = wp_text_diff(
			$after,
			$before,
			array( 'show_split_view' => true )
		);

		if ( is_string( $diff ) && '' !== $diff ) {
			return $diff;
		}

		return sprintf(
			'<table class="diff ifs-deploy-diff-plain"><tbody><tr>%1$s%2$s%3$s</tr></tbody></table>',
			'' !== $after
				? '<td class="diff-deletedline"><del>' . esc_html( $after ) . '</del></td>'
				: '<td class="ifs-deploy-diff-blank">&nbsp;</td>',
			'<td class="ifs-deploy-diff-gutter">&nbsp;</td>',
			'' !== $before
				? '<td class="diff-addedline"><ins>' . esc_html( $before ) . '</ins></td>'
				: '<td class="ifs-deploy-diff-blank">&nbsp;</td>'
		);
	}

	private function chip( string $change ): string {
		$labels = array(
			PackageDiff::CHANGE_ADDED   => __( 'Added', 'ifs-deploy' ),
			PackageDiff::CHANGE_CHANGED => __( 'Changed', 'ifs-deploy' ),
			PackageDiff::CHANGE_REMOVED => __( 'Removed', 'ifs-deploy' ),
			PackageDiff::CHANGE_KEPT    => __( 'Kept', 'ifs-deploy' ),
		);

		return sprintf(
			'<span class="ifs-deploy-chip ifs-deploy-chip-%1$s">%2$s</span>',
			esc_attr( $change ),
			esc_html( $labels[ $change ] ?? $change )
		);
	}

	private function message( string $text, string $tone ): string {
		return sprintf(
			'<p class="ifs-deploy-preview-message is-%1$s">%2$s</p>',
			esc_attr( $tone ),
			esc_html( $text )
		);
	}
}
