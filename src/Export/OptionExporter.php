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

	/**
	 * Stable positive integer id for an option name (for queue mapping).
	 *
	 * An option has no numeric id of its own, but the queue's identity is
	 * `(object_type, object_subtype, object_id)` with a UNIQUE key over it, and for
	 * options the subtype is always ''. So this value alone decides which row an
	 * option occupies — and a collision does not error, it MERGES: the second option
	 * overwrites the first row's title and hash, the first silently stops being
	 * tracked, and its pending change is gone.
	 *
	 * This was `crc32()`, whose 32 bits give a coin-flip chance of collision at only
	 * ~77,000 distinct names — not reachable on one site, but well within reach of the
	 * birthday bound for a value nothing else guards. The top 60 bits of an md5 push
	 * that out past a billion names while still fitting the `bigint(20) unsigned`
	 * column and PHP's signed 64-bit int.
	 *
	 * CHANGING THIS CHANGES WHERE DATA LIVES. Existing queue rows and snapshot
	 * revisions are filed under the old value, which is why `Schema` migrates them at
	 * DB version 6 rather than leaving them orphaned.
	 */
	public static function option_id( string $name ): int {
		return (int) hexdec( substr( md5( $name ), 0, 15 ) );
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
