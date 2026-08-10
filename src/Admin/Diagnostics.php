<?php
declare(strict_types=1);

namespace IfsDeploy\Admin;

use IfsDeploy\Client\DeployClient;
use IfsDeploy\Support\Config;
use IfsDeploy\Support\DebugLog;

/**
 * Asks Production about itself and renders the answer as a checklist.
 *
 * Aimed squarely at the failures that are invisible from the sending side:
 *
 *  - **Missing feature classes** — the plugin was uploaded incompletely, so a class
 *    is absent and the import dies with an empty HTTP 500. This is easy to hit with
 *    an SFTP-on-save workflow, where a newly ADDED file never gets sent.
 *  - **Wrong role** — Production still set to "Staging", so every endpoint answers
 *    409 and refuses the work.
 *  - **Unwritable uploads directory** — media import cannot store the file.
 *  - **Production cannot reach Staging** — media import downloads the file FROM
 *    Staging, so HTTP auth or an IP allowlist there breaks it in a way no
 *    Production-side check would reveal.
 */
final class Diagnostics {

	/**
	 * Human labels for the feature flags PingEndpoint reports.
	 *
	 * @var array<string,string>
	 */
	private const FEATURE_LABELS = array(
		'object_endpoint'    => 'Preview endpoint (/object)',
		'url_rewriter'       => 'URL rewriter',
		'media_url_resolver' => 'Renamed-media resolver',
		'package_diff'       => 'Field-level diff',
		'preview_service'    => 'Preview service',
		'debug_log'          => 'Event log',
		'rollback_preview'   => 'Rollback preview endpoint',
		'signatures'         => 'Signature endpoint (Pending Changes verification)',
	);

	private DeployClient $client;

	public function __construct( ?DeployClient $client = null ) {
		$this->client = $client ?? new DeployClient();
	}

	/**
	 * Run the checks and return rendered HTML.
	 */
	public function run(): string {
		$response = $this->client->post( 'ping', array( 'probe_url' => $this->probe_url() ) );

		if ( is_wp_error( $response ) ) {
			DebugLog::error( 'Diagnostics could not reach Production', array( 'error' => $response->get_error_message() ) );

			return $this->notice(
				sprintf(
					/* translators: %s: error message */
					__( 'Could not reach Production: %s', 'ifs-deploy' ),
					$response->get_error_message()
				),
				'error'
			);
		}

		$status = (int) $response['status'];
		$body   = (array) $response['body'];

		if ( 200 !== $status || empty( $body['ok'] ) ) {
			$detail = (string) ( $body['error'] ?? ( $response['raw'] ?? '' ) );

			DebugLog::error( 'Diagnostics got a bad response from Production', array( 'status' => $status, 'detail' => $detail ) );

			return $this->notice(
				sprintf(
					/* translators: 1: HTTP status, 2: detail */
					__( 'Production answered HTTP %1$d: %2$s', 'ifs-deploy' ),
					$status,
					$detail
				),
				'error'
			);
		}

		DebugLog::info( 'Diagnostics completed', array( 'remote_role' => (string) ( $body['role'] ?? '' ) ) );

		return $this->render( $body );
	}

	/**
	 * A real uploaded file URL to hand Production, so the reachability probe tests
	 * the exact thing media import does. Falls back to the site URL when the media
	 * library is empty.
	 */
	private function probe_url(): string {
		$attachments = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				'posts_per_page'   => 1,
				'orderby'          => 'ID',
				'order'            => 'DESC',
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);

		if ( ! empty( $attachments ) ) {
			$url = wp_get_attachment_url( (int) $attachments[0] );
			if ( $url ) {
				return (string) $url;
			}
		}

		return home_url( '/' );
	}

	/**
	 * @param array $body Ping response.
	 */
	private function render( array $body ): string {
		$html = '<table class="widefat striped ifs-deploy-kv"><tbody>';

		$html .= $this->row( __( 'Production site', 'ifs-deploy' ), (string) ( $body['name'] ?? '' ) . ' — ' . (string) ( $body['home_url'] ?? '' ) );
		$html .= $this->row( __( 'Plugin version', 'ifs-deploy' ), (string) ( $body['version'] ?? '' ) );
		$html .= $this->row( __( 'PHP / WordPress', 'ifs-deploy' ), (string) ( $body['php'] ?? '' ) . ' / ' . (string) ( $body['wp'] ?? '' ) );

		// Role.
		$role    = (string) ( $body['role'] ?? '' );
		$is_prod = ( Config::ROLE_PRODUCTION === $role );
		$html   .= $this->check(
			__( 'Role is Production', 'ifs-deploy' ),
			$is_prod,
			$is_prod
				? __( 'Correct — it will accept deployments.', 'ifs-deploy' )
				: __( 'It is set to Staging, so it refuses every deployment with HTTP 409. Change its role under IFS Deploy → Settings on that site.', 'ifs-deploy' )
		);

		// Feature classes.
		$features = (array) ( $body['features'] ?? array() );
		$missing  = array();
		foreach ( $features as $key => $present ) {
			if ( ! $present ) {
				$missing[] = self::FEATURE_LABELS[ $key ] ?? (string) $key;
			}
		}

		if ( empty( $features ) ) {
			$html .= $this->check(
				__( 'Plugin files complete', 'ifs-deploy' ),
				false,
				__( 'Production did not report its feature list, so it is running an older build. Upload the whole plugin folder there.', 'ifs-deploy' )
			);
		} else {
			$html .= $this->check(
				__( 'Plugin files complete', 'ifs-deploy' ),
				empty( $missing ),
				empty( $missing )
					? __( 'All expected classes are present.', 'ifs-deploy' )
					: sprintf(
						/* translators: %s: comma-separated list of missing components */
						__( 'Missing on Production: %s. A missing class aborts the import with an empty HTTP 500 — upload the whole plugin folder, including newly added files.', 'ifs-deploy' ),
						implode( ', ', $missing )
					)
			);
		}

		// Uploads directory.
		$uploads = (array) ( $body['uploads'] ?? array() );
		if ( ! empty( $uploads ) ) {
			$writable = ! empty( $uploads['writable'] );
			$html    .= $this->check(
				__( 'Uploads directory writable', 'ifs-deploy' ),
				$writable,
				$writable
					? (string) ( $uploads['dir'] ?? '' )
					: sprintf(
						/* translators: %s: error detail */
						__( 'Media cannot be saved on Production. %s', 'ifs-deploy' ),
						(string) ( $uploads['error'] ?? (string) ( $uploads['dir'] ?? '' ) )
					)
			);
		}

		// Reachability of this site FROM Production.
		$probe = (array) ( $body['probe'] ?? array() );
		if ( ! empty( $probe ) ) {
			$reachable = ! empty( $probe['ok'] );
			$html     .= $this->check(
				__( 'Production can fetch media from this site', 'ifs-deploy' ),
				$reachable,
				$reachable
					? sprintf(
						/* translators: 1: HTTP status, 2: probed URL */
						__( 'HTTP %1$d for %2$s', 'ifs-deploy' ),
						(int) ( $probe['status'] ?? 0 ),
						(string) ( $probe['url'] ?? '' )
					)
					: sprintf(
						/* translators: 1: probed URL, 2: status or error */
						__( 'Production could not fetch %1$s (%2$s). Media deployment downloads files from this site, so password protection, HTTP authentication or an IP allowlist here will break it.', 'ifs-deploy' ),
						(string) ( $probe['url'] ?? '' ),
						'' !== (string) ( $probe['error'] ?? '' ) ? (string) $probe['error'] : 'HTTP ' . (int) ( $probe['status'] ?? 0 )
					)
			);
		}

		$html .= '</tbody></table>';

		return $html;
	}

	private function check( string $label, bool $ok, string $detail ): string {
		return sprintf(
			'<tr><th scope="row">%1$s</th><td><span class="ifs-deploy-status ifs-deploy-status-%2$s">%3$s</span> <span class="description">%4$s</span></td></tr>',
			esc_html( $label ),
			$ok ? 'success' : 'failed',
			$ok ? esc_html__( 'Pass', 'ifs-deploy' ) : esc_html__( 'Problem', 'ifs-deploy' ),
			esc_html( $detail )
		);
	}

	private function row( string $label, string $value ): string {
		return sprintf(
			'<tr><th scope="row">%1$s</th><td>%2$s</td></tr>',
			esc_html( $label ),
			esc_html( '' !== trim( $value, ' —' ) ? $value : '—' )
		);
	}

	private function notice( string $message, string $tone ): string {
		return sprintf(
			'<div class="notice notice-%1$s"><p>%2$s</p></div>',
			esc_attr( $tone ),
			esc_html( $message )
		);
	}
}
