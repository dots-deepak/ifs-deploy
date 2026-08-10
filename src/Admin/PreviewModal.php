<?php
declare(strict_types=1);

namespace IfsDeploy\Admin;

/**
 * The shared "View changes" dialog shell.
 *
 * Rendered once per screen and reused by every row on it, so the markup stays in
 * PHP (translated and escaped) instead of being assembled from strings in JS. Both
 * Pending Changes and Compare & Sync print the same shell, which is why it lives
 * here rather than on either page.
 *
 * Requires: the enclosing element to carry the `ifs-deploy` class (both pages use
 * `<div class="wrap ifs-deploy">`), since the diff styles are scoped to it.
 */
final class PreviewModal {

	public const ID = 'ifs-deploy-preview-modal';

	public static function render(): void {
		?>
		<div class="ifs-deploy-modal" id="<?php echo esc_attr( self::ID ); ?>" hidden>
			<div class="ifs-deploy-modal-backdrop" data-ifs-deploy-close="1"></div>
			<div class="ifs-deploy-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="ifs-deploy-modal-title">
				<div class="ifs-deploy-modal-head">
					<h2 id="ifs-deploy-modal-title"><?php esc_html_e( 'Changes to be deployed', 'ifs-deploy' ); ?></h2>
					<button type="button" class="ifs-deploy-modal-close" data-ifs-deploy-close="1" aria-label="<?php esc_attr_e( 'Close', 'ifs-deploy' ); ?>">
						<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
						<path fill-rule="evenodd" clip-rule="evenodd" d="M5.46967 5.46967C5.76256 5.17678 6.23744 5.17678 6.53033 5.46967L18.5303 17.4697C18.8232 17.7626 18.8232 18.2374 18.5303 18.5303C18.2374 18.8232 17.7626 18.8232 17.4697 18.5303L5.46967 6.53033C5.17678 6.23744 5.17678 5.76256 5.46967 5.46967Z" fill="currentColor"/>
						<path fill-rule="evenodd" clip-rule="evenodd" d="M18.5303 5.46967C18.8232 5.76256 18.8232 6.23744 18.5303 6.53033L6.53035 18.5303C6.23745 18.8232 5.76258 18.8232 5.46969 18.5303C5.17679 18.2374 5.17679 17.7626 5.46968 17.4697L17.4697 5.46967C17.7626 5.17678 18.2374 5.17678 18.5303 5.46967Z" fill="currentColor"/>
						</svg>
					</button>
				</div>
				<div class="ifs-deploy-modal-body"></div>
				<div class="ifs-deploy-modal-foot">
					<button type="button" class="button" data-ifs-deploy-close="1">
						<?php esc_html_e( 'Close', 'ifs-deploy' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * A "View changes" trigger.
	 *
	 * Always the compact `button-small` variant: on Compare & Sync it is paired with
	 * an equally small Push button, and on Pending Changes it sits in a narrow
	 * column of its own.
	 *
	 * @param string $id_attribute Either 'queue-id' (Pending Changes) or 'post-id'
	 *                             (Compare & Sync) — the JS sends whichever is set.
	 * @param int    $id           The corresponding id.
	 * @param string $title        Object title, shown in the dialog header.
	 */
	public static function button( string $id_attribute, int $id, string $title ): string {
		return sprintf(
			'<button type="button" class="button button-small ifs-deploy-preview-open" data-%1$s="%2$d" data-title="%3$s" aria-haspopup="dialog">%4$s</button>',
			esc_attr( $id_attribute ),
			$id,
			esc_attr( '' !== $title ? $title : __( '(no title)', 'ifs-deploy' ) ),
			esc_html__( 'View changes', 'ifs-deploy' )
		);
	}
}
