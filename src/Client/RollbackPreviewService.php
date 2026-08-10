<?php
declare(strict_types=1);

namespace IfsDeploy\Client;

use IfsDeploy\History\DeploymentRepository;
use IfsDeploy\Support\Access;

/**
 * Staging-side fetch of "what would a rollback change?".
 *
 * Both versions being compared — the object as it is on Production now, and the
 * snapshot taken before the deploy — exist only on Production, so this is a thin
 * wrapper around the /rollback-preview endpoint. Read-only from end to end.
 */
final class RollbackPreviewService {

	private DeploymentRepository $deployments;
	private DeployClient $client;

	public function __construct( ?DeploymentRepository $deployments = null, ?DeployClient $client = null ) {
		$this->deployments = $deployments ?? new DeploymentRepository();
		$this->client      = $client ?? new DeployClient();
	}

	/**
	 * @param int $deployment_id Local deployment row id.
	 * @param int $revision_id   Which object to diff; 0 for the first.
	 *
	 * @return array{ok:bool,error?:string,objects?:array,diff?:array|null}
	 */
	public function preview( int $deployment_id, int $revision_id = 0 ): array {
		$deployment = $this->deployments->get( $deployment_id );

		if ( null === $deployment ) {
			return $this->fail( __( 'That deployment no longer exists. Refresh the page.', 'ifs-deploy' ) );
		}

		// Mirrors the rollback rule itself: without "see all", only your own.
		if ( ! Access::sees_all() && (int) $deployment->deployed_by !== get_current_user_id() ) {
			return $this->fail( __( 'You can only roll back deployments you started.', 'ifs-deploy' ) );
		}

		$payload = array( 'deployment_uuid' => (string) $deployment->deployment_uuid );
		if ( $revision_id > 0 ) {
			$payload['revision_id'] = $revision_id;
		}

		$response = $this->client->post( 'rollback-preview', $payload );

		if ( is_wp_error( $response ) ) {
			return $this->fail( $response->get_error_message() );
		}

		if ( 200 !== $response['status'] || empty( $response['body']['ok'] ) ) {
			$error = (string) ( $response['body']['error'] ?? '' );

			if ( '' === $error ) {
				$error = sprintf(
					/* translators: %d: HTTP status code */
					__( 'Production returned HTTP %d while building the rollback preview. If it is running an older build, update the plugin there.', 'ifs-deploy' ),
					(int) $response['status']
				);
			}

			return $this->fail( $error );
		}

		$body = (array) $response['body'];

		return array(
			'ok'         => true,
			'deployment' => $deployment,
			'objects'    => is_array( $body['objects'] ?? null ) ? $body['objects'] : array(),
			'diff'       => is_array( $body['diff'] ?? null ) ? $body['diff'] : null,
		);
	}

	/**
	 * @return array{ok:bool,error:string}
	 */
	private function fail( string $message ): array {
		return array( 'ok' => false, 'error' => $message );
	}
}
