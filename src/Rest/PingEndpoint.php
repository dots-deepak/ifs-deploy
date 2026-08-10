<?php
declare(strict_types=1);

namespace IfsDeploy\Rest;

use IfsDeploy\Auth\Credentials;
use IfsDeploy\Auth\Protocol;
use IfsDeploy\Auth\Verifier;
use IfsDeploy\Support\Config;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST ifs-deploy/v1/ping — connection test and remote diagnostics.
 *
 * Returns the receiving site's identity plus enough environment detail to explain
 * the failures that are otherwise invisible from the sending side: a partially
 * uploaded plugin (missing classes), the wrong role, an unwritable uploads
 * directory, or an inability to reach back to Staging to fetch media files.
 */
final class PingEndpoint {

	/**
	 * Classes that must exist for the newer features to work. A missing entry means
	 * the plugin files on this site are older or were uploaded incompletely — the
	 * usual cause of an opaque HTTP 500 during import.
	 *
	 * @var array<string,string>
	 */
	private const FEATURE_CLASSES = array(
		'object_endpoint'    => \IfsDeploy\Rest\ObjectEndpoint::class,
		'url_rewriter'       => \IfsDeploy\Support\UrlRewriter::class,
		'media_url_resolver' => \IfsDeploy\Import\MediaUrlResolver::class,
		'package_diff'       => \IfsDeploy\Support\PackageDiff::class,
		'preview_service'    => \IfsDeploy\Client\PreviewService::class,
		'debug_log'          => \IfsDeploy\Support\DebugLog::class,
		'rollback_preview'   => \IfsDeploy\Rest\RollbackPreviewEndpoint::class,
		'signatures'         => \IfsDeploy\Rest\SignatureEndpoint::class,
	);

	public function register(): void {
		register_rest_route(
			RestController::NAMESPACE,
			'/ping',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( Verifier::class, 'verify_request' ),
			)
		);
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$creds  = Credentials::get();
		$params = (array) $request->get_json_params();

		$response = array(
			'ok'       => true,

			/*
			 * How the caller LEARNS this side speaks v2 (SECURITY.md H-3, M-3).
			 *
			 * Test Connection is the first thing anyone runs after updating, and it is the
			 * one route a Staging site calls without pushing anything — so it is the cheapest
			 * place to answer "can I start signing route-bound requests?". An older Staging
			 * ignores the field; an older Production omits it, which is read as v1.
			 */
			'protocol' => Protocol::CURRENT,

			'site_id'  => $creds['site_id'],
			'name'     => get_bloginfo( 'name' ),
			'version'  => IFS_DEPLOY_VERSION,
			'role'     => Config::role(),
			'php'      => PHP_VERSION,
			'wp'       => get_bloginfo( 'version' ),
			'home_url' => home_url(),
			'features' => $this->features(),
			'uploads'  => $this->uploads_status(),
		);

		// Optional reachability probe. Media import needs this site to fetch files
		// FROM the sender, and a staging site behind HTTP auth or an IP allowlist
		// silently breaks that — the failure looks like a download error with no
		// explanation. Asking the receiver to try the URL answers it directly.
		if ( ! empty( $params['probe_url'] ) ) {
			$response['probe'] = $this->probe( (string) $params['probe_url'] );
		}

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * @return array<string,bool>
	 */
	private function features(): array {
		$features = array();
		foreach ( self::FEATURE_CLASSES as $name => $class ) {
			$features[ $name ] = class_exists( $class );
		}

		return $features;
	}

	/**
	 * @return array{dir:string,writable:bool,error:string}
	 */
	private function uploads_status(): array {
		$uploads = wp_get_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return array(
				'dir'      => '',
				'writable' => false,
				'error'    => (string) $uploads['error'],
			);
		}

		$dir = (string) ( $uploads['basedir'] ?? '' );

		return array(
			'dir'      => $dir,
			'writable' => '' !== $dir && wp_is_writable( $dir ),
			'error'    => '',
		);
	}

	/**
	 * Can this site reach the given URL? HEAD only, short timeout, http(s) only.
	 *
	 * @return array{url:string,ok:bool,status:int,error:string}
	 */
	private function probe( string $url ): array {
		$url    = esc_url_raw( $url );
		$scheme = (string) wp_parse_url( $url, PHP_URL_SCHEME );

		if ( '' === $url || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return array(
				'url'    => $url,
				'ok'     => false,
				'status' => 0,
				'error'  => __( 'Not a valid http(s) URL.', 'ifs-deploy' ),
			);
		}

		$result = wp_remote_head(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 3,
			)
		);

		if ( is_wp_error( $result ) ) {
			return array(
				'url'    => $url,
				'ok'     => false,
				'status' => 0,
				'error'  => $result->get_error_message(),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $result );

		return array(
			'url'    => $url,
			'ok'     => ( $status >= 200 && $status < 400 ),
			'status' => $status,
			'error'  => '',
		);
	}
}
