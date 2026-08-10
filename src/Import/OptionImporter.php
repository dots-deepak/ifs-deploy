<?php
declare(strict_types=1);

namespace IfsDeploy\Import;

use IfsDeploy\Export\OptionExporter;
use IfsDeploy\Support\ContentFirewall;
use IfsDeploy\Support\DebugLog;
use IfsDeploy\Support\OptionAllowlist;
use WP_Error;

/**
 * Applies an option package to Production.
 *
 * The allowlist is re-checked here so Production never writes a protected option
 * regardless of what a request claims — the security boundary is on the
 * receiving side, not just the sender.
 */
final class OptionImporter {

	/**
	 * @return array{object_id:int,created:bool}|WP_Error
	 */
	public function import( array $package ) {
		$name = (string) ( $package['name'] ?? '' );
		if ( '' === $name ) {
			return new WP_Error( 'ifs_deploy_bad_option', __( 'Malformed option package.', 'ifs-deploy' ) );
		}

		if ( ! OptionAllowlist::is_allowed( $name ) ) {
			return new WP_Error(
				'ifs_deploy_option_refused',
				sprintf(
					/* translators: %s: option name */
					__( 'Refused to write protected option "%s".', 'ifs-deploy' ),
					$name
				)
			);
		}

		$id = OptionExporter::option_id( $name );

		if ( 'delete' === ( $package['action'] ?? 'update' ) ) {
			delete_option( $name );
			return array( 'object_id' => $id, 'created' => false );
		}

		// A source option that did not exist is treated as a delete on the target.
		if ( empty( $package['existed'] ) ) {
			delete_option( $name );
			return array( 'object_id' => $id, 'created' => false );
		}

		update_option( $name, $this->firewall( $name, $package['value'] ?? '' ) );

		return array( 'object_id' => $id, 'created' => false );
	}

	/**
	 * Run the HTML-bearing parts of a widget option through the content firewall (H-2b).
	 *
	 * `widget_custom_html` and `widget_text` exist TO hold raw HTML, and they are on the
	 * deployable allowlist. So filtering `post_content` alone does not close the injection
	 * path — a signed request could simply inject through a widget instead of a page, and
	 * shipping H-2 without this would have been a false sense of security.
	 *
	 * Only widget options are touched. `theme_mods_*` and ACF `options_*` hold colours,
	 * ids and settings, and running an HTML filter over arbitrary serialised settings would
	 * corrupt values that were never markup in the first place.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	private function firewall( string $name, $value ) {
		if ( 0 !== strpos( $name, 'widget_' ) ) {
			return $value;
		}

		return $this->clean_deep( $value, $name );
	}

	/**
	 * Walk a widget option and filter every string that looks like markup.
	 *
	 * Strings with no tag in them are returned untouched: a widget title is a string too,
	 * and passing plain text through kses would strip a legitimate `<` or `&` from it.
	 *
	 * @param mixed  $value
	 * @param string $option
	 * @return mixed
	 */
	private function clean_deep( $value, string $option ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ $key ] = $this->clean_deep( $item, $option );
			}

			return $out;
		}

		if ( ! is_string( $value ) || false === strpos( $value, '<' ) ) {
			return $value;
		}

		$result = ContentFirewall::apply( $value, $option );

		if ( $result['changed'] ) {
			$filtering = ContentFirewall::MODE_FILTER === ContentFirewall::mode();

			DebugLog::warning(
				sprintf(
					$filtering
						/* translators: 1: option name, 2: what was removed */
						? __( 'Removed disallowed markup from widget option "%1$s" on import: %2$s', 'ifs-deploy' )
						/* translators: 1: option name, 2: what would be removed */
						: __( 'Widget option "%1$s" contains markup that would be removed if the content firewall were switched on: %2$s. It was stored unchanged.', 'ifs-deploy' ),
					$option,
					implode( ', ', $result['removed'] )
				),
				array(
					'option' => $option,
					'mode'   => ContentFirewall::mode(),
				)
			);
		}

		return $result['html'];
	}
}
