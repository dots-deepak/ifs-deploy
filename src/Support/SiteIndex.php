<?php
declare(strict_types=1);

namespace IfsDeploy\Support;

use IfsDeploy\Import\PostImporter;

/**
 * Builds a lightweight index of this site's deployable posts/pages, used for the
 * Compare screen. Each entry carries a content signature plus any stored origin
 * linkage so the two sites can be matched and diffed.
 */
final class SiteIndex {

	/**
	 * The index, plus whether the site has more objects than it covers.
	 *
	 * Truncation used to be invisible. The query simply stopped at the limit and
	 * returned a list that looked complete, so Compare & Sync drew its conclusions
	 * from a partial picture and stated them with total confidence: every Staging
	 * page past the cut reported "Not on Production" (its counterpart was merely
	 * absent from the answer), and every Production page past the cut reported "Only
	 * on Production". Both invite exactly the wrong action — pushing a duplicate of a
	 * page that already exists.
	 *
	 * One extra row is fetched rather than counted, so knowing costs no second query.
	 *
	 * @return array{index:array<int,array{
	 *   id:int, type:string, slug:string, title:string, status:string,
	 *   modified:string, signature:string, deployed_sig:string,
	 *   origin_id:int, origin_site:string
	 * }>, truncated:bool, limit:int}
	 */
	public static function report(): array {
		$post_types = Config::tracked_post_types();

		/**
		 * Filter the maximum number of objects scanned per site for comparison.
		 *
		 * @param int $limit
		 */
		$limit = (int) apply_filters( 'ifs_deploy_index_limit', 2000 );

		// 0 or -1 means "no limit", which get_posts() understands and which can never
		// be truncated; anything else fetches one extra row as the overflow probe.
		$unlimited = $limit <= 0;

		$posts = get_posts(
			array(
				'post_type'        => $post_types,
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'   => $unlimited ? -1 : $limit + 1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => false,

				/*
				 * Read from the DATABASE, not the object cache.
				 *
				 * This index is the input to Compare & Sync on both sites, and a stale
				 * signature there is the worst possible failure: the screen reports
				 * "In sync" for something that differs, or the reverse, and clicking
				 * Refresh changes nothing because the second query is served from the
				 * same cache as the first. On a host with a PERSISTENT object cache
				 * (Memcached/Redis) that survives across requests, so the only way out
				 * was flushing the cache by hand.
				 *
				 * Cost is one uncached query on a screen that already makes a remote
				 * round trip taking seconds. There are exactly two callers — this screen
				 * and the REST index that serves it — so nothing hot is affected.
				 */
				'cache_results'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$truncated = ! $unlimited && count( $posts ) > $limit;

		if ( $truncated ) {
			// Drop the probe row so the index itself still honours the documented limit.
			$posts = array_slice( $posts, 0, $limit );
		}

		self::refresh_caches( $posts );

		$index = array();
		foreach ( $posts as $post ) {
			$index[] = array(
				'id'          => (int) $post->ID,
				'type'        => (string) $post->post_type,
				'slug'        => (string) $post->post_name,
				'title'       => (string) $post->post_title,
				'status'      => (string) $post->post_status,
				'modified'    => (string) $post->post_modified,
				'signature'    => (string) ( ContentSignature::for_post( (int) $post->ID ) ?? '' ),
				'deployed_sig' => (string) get_post_meta( (int) $post->ID, PostImporter::SRC_SIG_META, true ),
				'origin_id'    => (int) get_post_meta( (int) $post->ID, PostImporter::ORIGIN_ID_META, true ),
				'origin_site'  => (string) get_post_meta( (int) $post->ID, PostImporter::ORIGIN_SITE_META, true ),
			);
		}

		return array(
			'index'     => $index,
			'truncated' => $truncated,
			'limit'     => $limit,
		);
	}

	/**
	 * Replace any cached copies of these posts with the rows just read, and force their
	 * meta to be re-read from the database.
	 *
	 * `cache_results => false` stops the query POPULATING the cache, but it does not stop
	 * everything downstream from READING it — `ContentSignature::for_post()` calls
	 * `get_post()`, and `get_post_meta()` reads the meta cache. Without this step a stale
	 * entry would still win.
	 *
	 * Deleting before priming is not optional: `update_post_cache()` uses
	 * `wp_cache_add_multiple()`, and *add* does not overwrite an existing key. Calling it
	 * on its own would leave stale rows exactly where they are.
	 *
	 * Meta is re-primed with one `update_meta_cache()` call for every id at once, rather
	 * than letting each `get_post_meta()` fire its own query — the difference is 1 query
	 * versus up to 2000.
	 *
	 * @param \WP_Post[] $posts Rows freshly read from the database.
	 */
	private static function refresh_caches( array $posts ): void {
		if ( empty( $posts ) ) {
			return;
		}

		$ids = array();
		foreach ( $posts as $post ) {
			$ids[] = (int) $post->ID;
		}

		// Post rows: drop, then prime with what we just read.
		wp_cache_delete_multiple( $ids, 'posts' );
		update_post_cache( $posts );

		// Meta: drop, then re-read in a single query. This is where the signature stamps
		// live (`_ifs_deploy_src_sig`, origin id/site), which are written during import
		// on the receiving site — the values most likely to be stale here.
		wp_cache_delete_multiple( $ids, 'post_meta' );
		update_meta_cache( 'post', $ids );
	}
}
