<?php
declare(strict_types=1);

namespace IfsDeploy\Admin;

/**
 * The shared confirmation dialog shell.
 *
 * Used for both "push to live" (a plain question) and "roll back" (a question with a
 * fetched change summary above it), so the two read as one interaction rather than
 * one styled dialog and one browser alert.
 *
 * Its close controls use `data-ifs-deploy-confirm-close` rather than the preview
 * dialog's `data-ifs-deploy-close`, so the two dialogs cannot close each other.
 */
final class ConfirmModal {

	public const ID = 'ifs-deploy-confirm-modal';

	public static function render(): void {
		?>
		<div class="ifs-deploy-modal" id="<?php echo esc_attr( self::ID ); ?>" hidden>
			<div class="ifs-deploy-modal-backdrop" data-ifs-deploy-confirm-close="1"></div>
			<div class="ifs-deploy-modal-dialog ifs-deploy-modal-sm" role="dialog" aria-modal="true" aria-labelledby="ifs-deploy-confirm-title">
				<div class="ifs-deploy-modal-head">
					<h2 id="ifs-deploy-confirm-title"></h2>
					<button type="button" class="ifs-deploy-modal-close" data-ifs-deploy-confirm-close="1" aria-label="<?php esc_attr_e( 'Close', 'ifs-deploy' ); ?>">&times;</button>
				</div>
				<div class="ifs-deploy-modal-body"></div>
				<div class="ifs-deploy-modal-foot">
					<button type="button" class="button" data-ifs-deploy-confirm-close="1"><?php esc_html_e( 'Cancel', 'ifs-deploy' ); ?></button>
					<button type="button" class="button button-primary" id="ifs-deploy-confirm-ok"></button>
				</div>
			</div>
		</div>
		<?php
	}
}
