<?php
declare(strict_types=1);

namespace IfsDeploy\Support;

/**
 * The single list of post meta IFS Deploy ignores everywhere.
 *
 * "Ignored" means: not exported, not part of the content signature, and not part of a
 * snapshot comparison. That has to be one list, because the three used to disagree —
 * `_oembed_*` and `_transient_*` were excluded from the signature but still deployed,
 * so they could never be reported as a difference yet were copied to Production
 * anyway.
 *
 * What belongs here is meta that changes WITHOUT the content changing, or that means
 * something different on each site: editor locks, caches, ping timestamps, trash
 * bookkeeping. Deploying those achieves nothing, and — worse — because the queue keys
 * off the package hash, a key that changes on every save makes every save look like a
 * deployable change.
 *
 * Add project-specific keys with the `ifs_deploy_ignore_meta_key` filter rather than
 * editing this file:
 *
 *     add_filter( 'ifs_deploy_ignore_meta_key', function ( $ignored, $key ) {
 *         return $ignored || str_starts_with( $key, '_my_plugin_cache_' );
 *     }, 10, 2 );
 */
final class MetaBlocklist {

	/**
	 * Exact keys never exported, signed or compared.
	 *
	 * @var string[]
	 */
	private const KEYS = array(
		// Editor / revision bookkeeping.
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_wp_old_date',
		'_wp_desired_post_slug',

		// Pending ping flags — set on publish, cleared by cron.
		'_pingme',
		'_encloseme',

		// Trash bookkeeping: per-site, and meaningless on the target.
		'_wp_trash_meta_status',
		'_wp_trash_meta_time',
		'_wp_trash_meta_comments_status',

		// The featured image travels as a source URL, not as a local attachment id.
		'_thumbnail_id',

		// Yoast writes this every time it pings IndexNow, so it changes on every save
		// while the content is untouched. The exact symptom this list exists for.
		'_yoast_indexnow_last_ping',
	);

	/**
	 * Key prefixes never exported, signed or compared.
	 *
	 * @var string[]
	 */
	private const PREFIXES = array(
		// IFS Deploy's own identity stamps.
		'_ifs_deploy_',

		/*
		 * The SAME stamps under the pre-rename prefix.
		 *
		 * `Support\LegacyRename` moves the live ones, so this is not about them. It is about
		 * rollback snapshots: a snapshot captured before the rename holds the old-prefixed keys
		 * inside its stored payload, and restoring it writes them back. Without this line that
		 * revived bookkeeping key is not recognised as ours, so the next deploy would export it
		 * and push it to Production as though it were content.
		 */
		Legacy::META_PREFIX,

		// oEmbed HTML caches and their timestamps — regenerated per site on render.
		'_oembed_',

		// Anything a plugin parked in postmeta as a cache.
		'_transient_',
		'_site_transient_',
	);

	/**
	 * Should this meta key be left out of exports, signatures and comparisons?
	 */
	public static function is_ignored( string $key ): bool {
		$ignored = in_array( $key, self::KEYS, true );

		if ( ! $ignored ) {
			foreach ( self::PREFIXES as $prefix ) {
				if ( 0 === strpos( $key, $prefix ) ) {
					$ignored = true;
					break;
				}
			}
		}

		/**
		 * Filter whether a post meta key is ignored by IFS Deploy.
		 *
		 * Use this for plugin meta that changes on every save without the content
		 * changing (ping timestamps, generated CSS, counters), or that is meaningful
		 * only on one site.
		 *
		 * @param bool   $ignored
		 * @param string $key
		 */
		return (bool) apply_filters( 'ifs_deploy_ignore_meta_key', $ignored, $key );
	}

	/**
	 * Filter a whole meta map, dropping ignored keys.
	 *
	 * @param array<string,mixed> $meta
	 *
	 * @return array<string,mixed>
	 */
	public static function filter( array $meta ): array {
		$out = array();

		foreach ( $meta as $key => $value ) {
			$key = (string) $key;
			if ( self::is_ignored( $key ) ) {
				continue;
			}

			$out[ $key ] = $value;
		}

		return $out;
	}
}
