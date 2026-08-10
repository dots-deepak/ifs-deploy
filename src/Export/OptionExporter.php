<?php
declare(strict_types=1);

namespace IfsDeploy\Export;

use IfsDeploy\Auth\Credentials;
use IfsDeploy\Support\Legacy;
use IfsDeploy\Support\OptionAllowlist;

/**
 * Builds the deployment package for a single option (theme/ACF options, widget
 * instances, plugin settings). Only allowlisted options are exportable.
 */
final class OptionExporter {

	public const PACKAGE_FORMAT = 1;

	/**
	 * Marks "this option did not exist", as distinct from "it existed and was empty".
	 *
	 * Shared with `Rollback\SnapshotStore`, which writes the same sentinel into snapshots —
	 * they must agree or a captured absence cannot round-trip. Taken from one constant so they
	 * cannot drift; see `Support\Legacy` for why the value keeps the pre-rename spelling.
	 */
	private const ABSENT = Legacy::ABSENT_SENTINEL;

	/** Stable positive integer id for an option name (for queue mapping). */
	public static function option_id( string $name ): int {
		return (int) sprintf( '%u', crc32( $name ) );
	}

	/** @return array|null */
	public function export( string $name ): ?array {
		if ( ! OptionAllowlist::is_allowed( $name ) ) {
			return null;
		}

		$value   = get_option( $name, self::ABSENT );
		$existed = ( self::ABSENT !== $value );

		$creds = Credentials::get();

		return array(
			'format'      => self::PACKAGE_FORMAT,
			'type'        => 'option',
			'subtype'     => '',
			'action'      => 'update',
			'origin_id'   => self::option_id( $name ),
			'origin_site' => $creds['site_id'],
			'name'        => $name,
			'value'       => $existed ? $value : null,
			'existed'     => $existed,
		);
	}
}
