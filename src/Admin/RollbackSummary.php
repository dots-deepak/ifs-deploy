<?php
declare(strict_types=1);

namespace IfsDeploy\Admin;

use IfsDeploy\Client\RollbackPreviewService;

/**
 * Renders the rollback confirmation dialog: which objects are affected, and a
 * field-level comparison of the version that would be restored against the version
 * currently live.
 *
 * Objects are listed as buttons and shown ONE at a time. A deployment can touch many
 * objects, and stacking every diff would bury the one detail someone is looking for —
 * so the list is the navigation and the panel below it is the detail.
 *
 * Orientation matches the deploy preview on purpose: left/green is the version being
 * applied (here, the snapshot), right/red is the version it replaces (here, what is
 * live). Someone who has read one dialog can read the other.
 */
final class RollbackSummary {

	private RollbackPreviewService $service;
	private DiffRenderer $diff;

	public function __construct( ?RollbackPreviewService $service = null, ?DiffRenderer $diff = null ) {
		$this->service = $service ?? new RollbackPreviewService();
		$this->diff    = $diff ?? new DiffRenderer();
	}

	/**
	 * @return array{ok:bool,html:string,restorable:int,revision_id:int}
	 */
	public function render( int $deployment_id, int $revision_id = 0 ): array {
		$preview = $this->service->preview( $deployment_id, $revision_id );

		if ( empty( $preview['ok'] ) ) {
			return array(
				'ok'          => false,
				'html'        => $this->note( (string) ( $preview['error'] ?? __( 'Preview unavailable.', 'ifs-deploy' ) ), 'error' ),
				'restorable'  => 0,
				'revision_id' => 0,
			);
		}

		$objects = (array) $preview['objects'];
		$diff    = is_array( $preview['diff'] ?? null ) ? $preview['diff'] : null;

		if ( empty( $objects ) ) {
			return array(
				'ok'          => true,
				'html'        => $this->note(
					__( 'Production has no snapshots for this deployment, so there is nothing to restore. Every object in it was newly created.', 'ifs-deploy' ),
					'info'
				),
				'restorable'  => 0,
				'revision_id' => 0,
			);
		}

		$html  = $this->meta( $preview['deployment'], count( $objects ) );
		$html .= $this->object_list( $objects, (int) ( $diff['revision_id'] ?? 0 ) );
		$html .= '<div class="ifs-deploy-rollback-panel">' . $this->object_diff( $diff ) . '</div>';

		return array(
			'ok'          => true,
			'html'        => $html,
			'restorable'  => count( $objects ),
			'revision_id' => (int) ( $diff['revision_id'] ?? 0 ),
		);
	}

	/**
	 * Just the detail panel, for switching objects without rebuilding the dialog.
	 *
	 * @return array{ok:bool,html:string}
	 */
	public function render_object( int $deployment_id, int $revision_id ): array {
		$preview = $this->service->preview( $deployment_id, $revision_id );

		if ( empty( $preview['ok'] ) ) {
			return array(
				'ok'   => false,
				'html' => $this->note( (string) ( $preview['error'] ?? __( 'Preview unavailable.', 'ifs-deploy' ) ), 'error' ),
			);
		}

		return array(
			'ok'   => true,
			'html' => $this->object_diff( is_array( $preview['diff'] ?? null ) ? $preview['diff'] : null ),
		);
	}

	/**
	 * @param object $deployment
	 */
	private function meta( object $deployment, int $count ): string {
		$user = get_userdata( (int) $deployment->deployed_by );

		return sprintf(
			'<p class="ifs-deploy-rollback-meta"><code>%1$s</code> · %2$s · %3$s · %4$s</p>',
			esc_html( substr( (string) $deployment->deployment_uuid, 0, 8 ) ),
			esc_html( (string) $deployment->deployed_at ),
			esc_html( $user ? (string) $user->display_name : __( 'Unknown user', 'ifs-deploy' ) ),
			esc_html(
				sprintf(
					/* translators: %d: number of restorable objects */
					_n( '%d object will be restored', '%d objects will be restored', $count, 'ifs-deploy' ),
					$count
				)
			)
		);
	}

	/**
	 * The object switcher. Rendered even for a single object so the dialog always
	 * states plainly what is in scope.
	 *
	 * @param array<int,array> $objects
	 */
	private function object_list( array $objects, int $active ): string {
		$html = '<ul class="ifs-deploy-rollback-objects">';

		foreach ( $objects as $object ) {
			$revision_id = (int) ( $object['revision_id'] ?? 0 );
			$title       = (string) ( $object['title'] ?? '' );

			$html .= sprintf(
				'<li><button type="button" class="ifs-deploy-rollback-object%1$s" data-revision-id="%2$d" aria-pressed="%3$s">'
					. '<span class="ifs-deploy-status ifs-deploy-status-success">%4$s</span> '
					. '<span class="ifs-deploy-rollback-object-title">%5$s</span>'
					. '</button></li>',
				$revision_id === $active ? ' is-active' : '',
				$revision_id,
				$revision_id === $active ? 'true' : 'false',
				esc_html( $this->type_label( (string) ( $object['type'] ?? '' ) ) ),
				esc_html( '' !== $title ? $title : __( '(no title)', 'ifs-deploy' ) )
			);
		}

		return $html . '</ul>';
	}

	/**
	 * One object's comparison.
	 *
	 * @param array|null $diff
	 */
	private function object_diff( ?array $diff ): string {
		if ( null === $diff ) {
			return $this->note( __( 'Select an object to see what would change.', 'ifs-deploy' ), 'info' );
		}

		$title = (string) ( $diff['title'] ?? '' );

		$html = '<h3 class="ifs-deploy-rollback-heading">' . esc_html( '' !== $title ? $title : __( '(no title)', 'ifs-deploy' ) ) . '</h3>';

		if ( ! empty( $diff['captured_at'] ) ) {
			$html .= '<p class="description">' . esc_html(
				sprintf(
					/* translators: %s: date and time the snapshot was taken */
					__( 'Snapshot taken %s', 'ifs-deploy' ),
					(string) $diff['captured_at']
				)
			) . '</p>';
		}

		if ( ! empty( $diff['note'] ) ) {
			$html .= $this->note( (string) $diff['note'], 'info' );
		}

		if ( empty( $diff['comparable'] ) ) {
			return $html;
		}

		$fields = (array) ( $diff['fields'] ?? array() );

		if ( empty( $fields ) ) {
			$html .= $this->note(
				__( 'No differences — the live version already matches the snapshot, so restoring it would change nothing.', 'ifs-deploy' ),
				'info'
			);

			return $html;
		}

		$html .= '<p class="ifs-deploy-preview-legend">';
		$html .= '<span class="ifs-deploy-legend-incoming">' . esc_html__( 'Left: version to restore', 'ifs-deploy' ) . '</span>';
		$html .= '<span class="ifs-deploy-legend-current">' . esc_html__( 'Right: live on Production now', 'ifs-deploy' ) . '</span>';
		$html .= '<span class="ifs-deploy-count">' . (int) count( $fields ) . '</span> ';
		$html .= esc_html( _n( 'field differs', 'fields differ', count( $fields ), 'ifs-deploy' ) );
		$html .= '</p>';

		return $html . $this->diff->render_fields( $fields );
	}

	private function type_label( string $type ): string {
		$labels = array(
			'post'   => __( 'Content', 'ifs-deploy' ),
			'term'   => __( 'Taxonomy', 'ifs-deploy' ),
			'option' => __( 'Setting', 'ifs-deploy' ),
			'media'  => __( 'Media', 'ifs-deploy' ),
			'menu'   => __( 'Menu', 'ifs-deploy' ),
		);

		return $labels[ $type ] ?? ( '' !== $type ? $type : __( 'Object', 'ifs-deploy' ) );
	}

	private function note( string $text, string $tone ): string {
		return sprintf(
			'<p class="ifs-deploy-preview-message is-%1$s">%2$s</p>',
			esc_attr( $tone ),
			esc_html( $text )
		);
	}
}
