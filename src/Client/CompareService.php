<?php
declare(strict_types=1);

namespace IfsDeploy\Client;

use IfsDeploy\Auth\Credentials;
use IfsDeploy\Support\SiteIndex;
use IfsDeploy\Support\SyncCheck;

/**
 * Staging-side comparison + sync against Production.
 *
 * Fetches the Production index, matches each local object to its Production
 * counterpart (origin link → same id → slug+type), and reports whether content
 * is in sync, different, or missing. "Sync IDs" stamps origin linkage on the
 * matched-but-unlinked Production objects.
 */
final class CompareService {

	public const STATUS_IN_SYNC   = 'in_sync';
	public const STATUS_DIFFERENT = 'different';
	public const STATUS_MISSING   = 'missing';     // exists on Staging, not on Production.
	public const STATUS_PROD_ONLY = 'prod_only';   // exists on Production, not on Staging.

	private DeployClient $client;

	public function __construct( ?DeployClient $client = null ) {
		$this->client = $client ?? new DeployClient();
	}

	/**
	 * Build the full comparison.
	 *
	 * @return array{ok:bool,error?:string,rows?:array,prod_only?:array,summary?:array}
	 */
	public function compare(): array {
		// Freshness is handled in two places, neither of them here: DeployClient sends
		// no-cache request headers, and SiteIndex::build() reads Production's rows from
		// the database rather than its object cache.
		$response = $this->client->post( 'index', array() );
		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'error' => $response->get_error_message() );
		}
		if ( 200 !== $response['status'] || empty( $response['body']['ok'] ) ) {
			return array(
				'ok'    => false,
				'error' => (string) ( $response['body']['error'] ?? __( 'Could not fetch the Production index.', 'ifs-deploy' ) ),
			);
		}

		$remote       = is_array( $response['body']['index'] ?? null ) ? $response['body']['index'] : array();
		$local_report = SiteIndex::report();
		$local        = $local_report['index'];
		$staging_sid  = (string) Credentials::get()['site_id'];

		$maps    = $this->index_maps( $remote, $staging_sid );
		$matched = array();
		$rows    = array();

		foreach ( $local as $item ) {
			$match = $this->match_remote( $item, $staging_sid, $maps );

			if ( null === $match ) {
				$rows[] = $this->row( $item, null, 'none', self::STATUS_MISSING );
				continue;
			}

			$matched[ (int) $match['item']['id'] ] = true;
			$status = $this->resolve_status( (string) $item['signature'], $match['item'] );
			$rows[] = $this->row( $item, $match['item'], $match['type'], $status );
		}

		// Anything on Production not matched to a Staging object.
		$prod_only = array();
		foreach ( $remote as $r ) {
			if ( empty( $matched[ (int) $r['id'] ] ) ) {
				$prod_only[] = $r;
			}
		}

		return array(
			'ok'        => true,
			'rows'      => $rows,
			'prod_only' => $prod_only,
			'summary'   => $this->summarize( $rows, $prod_only ),
			'truncated' => $this->truncation_warning(
				(bool) $local_report['truncated'],
				! empty( $response['body']['truncated'] ),
				(int) $local_report['limit']
			),
		);
	}

	/**
	 * Say so when either side's index stopped short, or '' when neither did.
	 *
	 * This comparison is only as complete as the two lists behind it, and the failure
	 * is not a blank screen — it is a confident, wrong verdict. Anything past the cut
	 * on Staging is reported as "Not on Production", and anything past the cut on
	 * Production as "Only on Production", because in both cases the counterpart is
	 * simply missing from the data. Acting on the first creates a duplicate of a page
	 * that already exists.
	 *
	 * Naming which side ran over matters: the fix is the same filter, but it has to be
	 * applied on that site.
	 */
	private function truncation_warning( bool $local, bool $remote, int $limit ): string {
		if ( ! $local && ! $remote ) {
			return '';
		}

		if ( $local && $remote ) {
			$where = __( 'Both this site and Production have', 'ifs-deploy' );
		} elseif ( $local ) {
			$where = __( 'This site has', 'ifs-deploy' );
		} else {
			$where = __( 'Production has', 'ifs-deploy' );
		}

		return sprintf(
			/* translators: 1: which site(s) ran over the limit, 2: the limit */
			__( '%1$s more than %2$d objects, so this comparison covers only the first %2$d and the rest are not reported at all. Anything beyond the limit will show incorrectly as "Not on Production" or "Only on Production" — do not push from this screen until the limit is raised with the ifs_deploy_index_limit filter.', 'ifs-deploy' ),
			$where,
			$limit
		);
	}

	/**
	 * Link matched-but-unlinked Production objects to their Staging origins.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function sync_ids(): array {
		$comparison = $this->compare();
		if ( empty( $comparison['ok'] ) ) {
			return array( 'ok' => false, 'message' => (string) ( $comparison['error'] ?? __( 'Comparison failed.', 'ifs-deploy' ) ) );
		}

		$staging_sid = (string) Credentials::get()['site_id'];
		$links       = array();

		foreach ( $comparison['rows'] as $row ) {
			// Already linked via origin meta, or no match → nothing to do.
			if ( 'origin' === $row['match_type'] || ! $row['remote_id'] ) {
				continue;
			}
			$links[] = array(
				'prod_id'     => (int) $row['remote_id'],
				'origin_id'   => (int) $row['id'],
				'origin_site' => $staging_sid,
			);
		}

		if ( empty( $links ) ) {
			return array( 'ok' => true, 'message' => __( 'Nothing to sync — all matched objects are already linked.', 'ifs-deploy' ) );
		}

		$response = $this->client->post( 'link', array( 'links' => $links ) );
		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'message' => $response->get_error_message() );
		}

		$linked = (int) ( $response['body']['linked'] ?? 0 );

		return array(
			'ok'      => $linked > 0,
			'message' => sprintf(
				/* translators: %d: number of linked objects */
				_n( 'Linked %d object on Production.', 'Linked %d objects on Production.', $linked, 'ifs-deploy' ),
				$linked
			),
		);
	}

	/**
	 * Build fast lookup maps from the remote index.
	 *
	 * @param array $remote Remote index items.
	 *
	 * @return array{origin:array,id:array,slug:array}
	 */
	private function index_maps( array $remote, string $staging_sid ): array {
		$by_origin = array();
		$by_id     = array();
		$by_slug   = array();

		foreach ( $remote as $r ) {
			$by_id[ (int) $r['id'] ] = $r;
			$by_slug[ $r['type'] . '|' . $r['slug'] ] = $r;

			// Only index origin links that point back at THIS staging site.
			if ( (string) $r['origin_site'] === $staging_sid && (int) $r['origin_id'] > 0 ) {
				$by_origin[ (int) $r['origin_id'] ] = $r;
			}
		}

		return array(
			'origin' => $by_origin,
			'id'     => $by_id,
			'slug'   => $by_slug,
		);
	}

	/**
	 * Decide whether a matched object is in sync.
	 *
	 * In sync when EITHER:
	 *   - Staging's current signature equals the signature Staging stamped on the
	 *     production post at the last deploy (precise — survives production-side
	 *     content filtering and production-only meta), OR
	 *   - both sides' freshly-computed signatures match (covers cloned/legacy
	 *     content that was never deployed-with-stamp but is genuinely identical).
	 *
	 * Either check only ever turns a false "different" into "in sync"; if the
	 * content truly differs, both fail.
	 */
	private function resolve_status( string $local_sig, array $remote ): string {
		$in_sync = SyncCheck::in_sync(
			$local_sig,
			(string) ( $remote['deployed_sig'] ?? '' ),
			(string) ( $remote['signature'] ?? '' )
		);

		return $in_sync ? self::STATUS_IN_SYNC : self::STATUS_DIFFERENT;
	}

	/**
	 * @return array{item:array,type:string}|null
	 */
	private function match_remote( array $local, string $staging_sid, array $maps ): ?array {
		$id   = (int) $local['id'];
		$type = (string) $local['type'];
		$slug = (string) $local['slug'];

		if ( isset( $maps['origin'][ $id ] ) ) {
			return array( 'item' => $maps['origin'][ $id ], 'type' => 'origin' );
		}
		if ( isset( $maps['id'][ $id ] ) && $maps['id'][ $id ]['type'] === $type ) {
			return array( 'item' => $maps['id'][ $id ], 'type' => 'id' );
		}
		if ( '' !== $slug && isset( $maps['slug'][ $type . '|' . $slug ] ) ) {
			return array( 'item' => $maps['slug'][ $type . '|' . $slug ], 'type' => 'slug' );
		}

		return null;
	}

	private function row( array $local, ?array $remote, string $match_type, string $status ): array {
		return array(
			'id'           => (int) $local['id'],
			'title'        => (string) $local['title'],
			'type'         => (string) $local['type'],
			'status'       => $status,
			'match_type'   => $match_type,
			'remote_id'    => $remote ? (int) $remote['id'] : 0,
			'remote_title' => $remote ? (string) $remote['title'] : '',
		);
	}

	private function summarize( array $rows, array $prod_only ): array {
		$counts = array(
			self::STATUS_IN_SYNC   => 0,
			self::STATUS_DIFFERENT => 0,
			self::STATUS_MISSING   => 0,
			self::STATUS_PROD_ONLY => count( $prod_only ),
		);
		foreach ( $rows as $row ) {
			if ( isset( $counts[ $row['status'] ] ) ) {
				++$counts[ $row['status'] ];
			}
		}
		return $counts;
	}
}
