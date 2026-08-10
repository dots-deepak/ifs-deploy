<?php
declare(strict_types=1);

namespace IfsDeploy\Admin;

use IfsDeploy\Client\CompareService;
use IfsDeploy\Client\DeployClient;
use IfsDeploy\Client\DeploymentService;
use IfsDeploy\Client\PreviewService;
use IfsDeploy\History\DeploymentRepository;
use IfsDeploy\Queue\QueueRepository;
use IfsDeploy\Support\Access;
use IfsDeploy\Support\ApiLog;
use IfsDeploy\Support\Config;
use IfsDeploy\Support\DebugLog;
use IfsDeploy\Support\IpAccess;

/**
 * AJAX endpoints backing the admin screens: deploy, ignore, rollback, and the
 * connection test.
 */
final class Ajax {

	public const NONCE = 'ifs_deploy_ajax';

	public function register(): void {
		add_action( 'wp_ajax_ifs_deploy_deploy', array( $this, 'deploy' ) );
		add_action( 'wp_ajax_ifs_deploy_deploy_posts', array( $this, 'deploy_posts' ) );
		add_action( 'wp_ajax_ifs_deploy_ignore', array( $this, 'ignore' ) );
		add_action( 'wp_ajax_ifs_deploy_rollback', array( $this, 'rollback' ) );
		add_action( 'wp_ajax_ifs_deploy_test_connection', array( $this, 'test_connection' ) );
		add_action( 'wp_ajax_ifs_deploy_sync_ids', array( $this, 'sync_ids' ) );
		add_action( 'wp_ajax_ifs_deploy_clear_history', array( $this, 'clear_history' ) );
		add_action( 'wp_ajax_ifs_deploy_preview', array( $this, 'preview' ) );
		add_action( 'wp_ajax_ifs_deploy_diagnostics', array( $this, 'diagnostics' ) );
		add_action( 'wp_ajax_ifs_deploy_clear_log', array( $this, 'clear_log' ) );
		add_action( 'wp_ajax_ifs_deploy_clear_api_log', array( $this, 'clear_api_log' ) );
		add_action( 'wp_ajax_ifs_deploy_mark_ip', array( $this, 'mark_ip' ) );
		add_action( 'wp_ajax_ifs_deploy_search_users', array( $this, 'search_users' ) );
		add_action( 'wp_ajax_ifs_deploy_rollback_preview', array( $this, 'rollback_preview' ) );
		add_action( 'wp_ajax_ifs_deploy_tab', array( $this, 'tab' ) );
	}

	/**
	 * One tab's content, for switching without a page load.
	 *
	 * Guarded at the screen level (CAP_ACCESS) and then AGAIN inside Tabs::render(),
	 * which checks the tab's own capability. That second check is the one that matters:
	 * hiding a tab from the tab bar is presentation, not access control, and this
	 * endpoint accepts any slug the client sends.
	 *
	 * A tab's own query args are forwarded so its render() sees them exactly as it would
	 * on a real page load — Pending Changes reads `user`, Settings reads `section`. They
	 * are written into $_GET rather than passed as arguments because that is where the
	 * page classes read them from, and changing six readers to take a parameter would be
	 * a far wider change than this endpoint warrants.
	 */
	public function tab(): void {
		$this->guard( Access::CAP_ACCESS );

		$slug = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( (string) $_POST['tab'] ) ) : '';

		if ( '' === $slug || ! isset( Tabs::all()[ $slug ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown section.', 'ifs-deploy' ) ), 400 );
		}

		$args = isset( $_POST['args'] ) && is_array( $_POST['args'] ) ? wp_unslash( $_POST['args'] ) : array();
		foreach ( $args as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$_GET[ sanitize_key( (string) $key ) ] = (string) $value;
			}
		}

		wp_send_json_success(
			array(
				'tab'  => $slug,
				'html' => Tabs::render( $slug ),
			)
		);
	}

	/**
	 * What a rollback would restore. Read-only: it renders the summary the
	 * confirmation dialog shows before anything is undone.
	 */
	public function rollback_preview(): void {
		$this->guard( Access::CAP_ROLLBACK );

		$deployment_id = isset( $_POST['deployment_id'] ) ? absint( wp_unslash( $_POST['deployment_id'] ) ) : 0;
		if ( ! $deployment_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid deployment.', 'ifs-deploy' ) ) );
		}

		$revision_id = isset( $_POST['revision_id'] ) ? absint( wp_unslash( $_POST['revision_id'] ) ) : 0;
		$summary     = new RollbackSummary();

		// A revision id means the dialog is already open and the user clicked another
		// object, so only the detail panel is rebuilt.
		if ( isset( $_POST['panel_only'] ) && $revision_id > 0 ) {
			$panel = $summary->render_object( $deployment_id, $revision_id );

			wp_send_json_success( array( 'html' => $panel['html'] ) );
		}

		$result = $summary->render( $deployment_id, $revision_id );

		wp_send_json_success(
			array(
				'html'       => $result['html'],
				// The dialog keeps Confirm disabled when there is nothing to restore.
				'restorable' => (int) $result['restorable'],
			)
		);
	}

	/**
	 * Signed round trip to Production, reporting what it says about itself.
	 */
	public function diagnostics(): void {
		$this->guard();

		wp_send_json_success( array( 'html' => ( new Diagnostics() )->run() ) );
	}

	public function clear_log(): void {
		$this->guard();

		DebugLog::clear();

		wp_send_json_success( array( 'message' => __( 'Event log cleared.', 'ifs-deploy' ) ) );
	}

	/**
	 * Clear the API access log.
	 *
	 * Separate from clear_log() on purpose: the event log and the access log answer
	 * different questions, and wiping the security record while tidying up diagnostics
	 * would be a surprising side effect.
	 */
	public function clear_api_log(): void {
		$this->guard();

		$removed = ApiLog::clear();

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: number of rows removed */
					_n( 'API access log cleared (%d entry).', 'API access log cleared (%d entries).', $removed, 'ifs-deploy' ),
					$removed
				),
			)
		);
	}

	/**
	 * Add or remove an address from the allow or block list, from the Logs screen.
	 *
	 * Exists because the addresses worth listing are the ones you have just seen in the
	 * log, and retyping an IPv6 address into a textarea is how a typo gets made — the typo
	 * that makes Production refuse its own Staging site.
	 */
	public function mark_ip(): void {
		$this->guard();

		$ip     = isset( $_POST['ip'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['ip'] ) ) : '';
		$action = isset( $_POST['mark'] ) ? sanitize_key( wp_unslash( (string) $_POST['mark'] ) ) : '';

		// Re-validated here rather than trusted from the page: this writes a value that is
		// then matched on every API request.
		if ( '' === IpAccess::normalize( $ip ) ) {
			wp_send_json_error( array( 'message' => __( 'That is not a valid address.', 'ifs-deploy' ) ), 400 );
		}

		$map = array(
			'allow'   => array( IpAccess::OPTION_ALLOW, true ),
			'unallow' => array( IpAccess::OPTION_ALLOW, false ),
			'block'   => array( IpAccess::OPTION_BLOCK, true ),
			'unblock' => array( IpAccess::OPTION_BLOCK, false ),
		);

		if ( ! isset( $map[ $action ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown action.', 'ifs-deploy' ) ), 400 );
		}

		list( $option, $add ) = $map[ $action ];

		$result = $add ? IpAccess::add( $option, $ip ) : IpAccess::remove( $option, $ip );

		if ( ! $result['changed'] ) {
			wp_send_json_error( array( 'message' => $result['message'] ), 400 );
		}

		/*
		 * Allowing or blocking an address IS the acknowledgement of the warning that told
		 * you about it, so the warning goes. Otherwise the Logs screen keeps showing a
		 * standing "new address" warning for an address the admin has just approved —
		 * which is how a log becomes something people stop reading.
		 */
		if ( $add ) {
			DebugLog::forget( 'new address: ' . $ip, DebugLog::LEVEL_WARNING );
		}

		// Worth an audit entry: this changes who may reach the API, and the change itself
		// is a security event.
		DebugLog::warning(
			$result['message'],
			array(
				'ip'   => $ip,
				'by'   => (string) get_current_user_id(),
				'list' => IpAccess::OPTION_ALLOW === $option ? 'allow' : 'block',
			)
		);

		wp_send_json_success( array( 'message' => $result['message'] ) );
	}

	/**
	 * User search for the role-management pickers. Administrators only — it exposes
	 * email addresses.
	 */
	public function search_users(): void {
		$this->guard();

		$term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['term'] ) ) : '';
		if ( strlen( $term ) < 2 ) {
			wp_send_json_success( array( 'users' => array() ) );
		}

		$query = new \WP_User_Query(
			array(
				'search'         => '*' . $term . '*',
				'search_columns' => array( 'user_login', 'user_email', 'user_nicename', 'display_name' ),
				'number'         => 20,
				'orderby'        => 'display_name',
				'order'          => 'ASC',
				'fields'         => array( 'ID', 'display_name', 'user_email' ),
			)
		);

		$users = array();
		foreach ( (array) $query->get_results() as $user ) {
			$users[] = array(
				'id'    => (int) $user->ID,
				'name'  => (string) $user->display_name,
				'email' => (string) $user->user_email,
			);
		}

		wp_send_json_success( array( 'users' => $users ) );
	}

	/**
	 * Field-level before/after preview. Read-only: it never deploys, queues, or
	 * changes status.
	 *
	 * Accepts either a queue row id (Pending Changes) or a post id (Compare & Sync,
	 * where rows are not backed by a queue entry).
	 */
	public function preview(): void {
		$this->guard( Access::CAP_ACCESS );

		$queue_id = isset( $_POST['queue_id'] ) ? absint( wp_unslash( $_POST['queue_id'] ) ) : 0;
		$post_id  = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

		$service = new PreviewService();

		if ( $queue_id ) {
			$preview = $service->preview( $queue_id );
		} elseif ( $post_id ) {
			$preview = $service->preview_post( $post_id );
		} else {
			// Rendered as a normal preview failure so the message lands inside the
			// dialog, styled like every other one, instead of as a bare AJAX error.
			$preview = array( 'ok' => false, 'error' => __( 'Invalid item.', 'ifs-deploy' ) );
		}

		// Rendered server-side: a preview is HTML by nature, and building it here
		// keeps every value escaped at the point it is written.
		wp_send_json_success( array( 'html' => ( new DiffRenderer() )->render( $preview ) ) );
	}

	public function deploy(): void {
		$this->guard( Access::CAP_DEPLOY );
		$this->require_staging();

		$ids = $this->queue_ids();
		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No items selected.', 'ifs-deploy' ) ) );
		}

		$result = ( new DeploymentService() )->deploy( $ids, get_current_user_id() );

		if ( ! empty( $result['ok'] ) ) {
			wp_send_json_success( array( 'message' => $result['message'] ) );
		}
		wp_send_json_error( array( 'message' => $result['message'] ) );
	}

	public function deploy_posts(): void {
		$this->guard();
		$this->require_staging();

		$raw = isset( $_POST['post_ids'] ) ? wp_unslash( $_POST['post_ids'] ) : array();
		$raw = is_array( $raw ) ? $raw : array( $raw );
		$ids = array_values( array_filter( array_map( 'absint', $raw ) ) );

		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No objects selected.', 'ifs-deploy' ) ) );
		}

		$result = ( new DeploymentService() )->deploy_posts( $ids, get_current_user_id() );

		if ( ! empty( $result['ok'] ) ) {
			wp_send_json_success( array( 'message' => $result['message'] ) );
		}
		wp_send_json_error( array( 'message' => $result['message'] ) );
	}

	public function sync_ids(): void {
		$this->guard();

		$result = ( new CompareService() )->sync_ids();

		if ( ! empty( $result['ok'] ) ) {
			wp_send_json_success( array( 'message' => $result['message'] ) );
		}
		wp_send_json_error( array( 'message' => $result['message'] ) );
	}

	public function clear_history(): void {
		$this->guard();

		( new DeploymentRepository() )->clear();

		wp_send_json_success( array( 'message' => __( 'Deployment history cleared.', 'ifs-deploy' ) ) );
	}

	public function ignore(): void {
		$this->guard( Access::CAP_DEPLOY );

		$ids   = $this->queue_ids();
		$queue = new QueueRepository();
		foreach ( $ids as $id ) {
			$queue->set_status( $id, QueueRepository::STATUS_IGNORED );
		}

		wp_send_json_success( array( 'message' => __( 'Items ignored.', 'ifs-deploy' ) ) );
	}

	public function rollback(): void {
		$this->guard( Access::CAP_ROLLBACK );

		$deployment_id = isset( $_POST['deployment_id'] ) ? absint( wp_unslash( $_POST['deployment_id'] ) ) : 0;
		if ( ! $deployment_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid deployment.', 'ifs-deploy' ) ) );
		}

		$result = ( new DeploymentService() )->rollback( $deployment_id );

		if ( ! empty( $result['ok'] ) ) {
			wp_send_json_success( array( 'message' => $result['message'] ) );
		}
		wp_send_json_error( array( 'message' => $result['message'] ) );
	}

	public function test_connection(): void {
		$this->guard();

		$response = ( new DeployClient() )->ping();

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}

		if ( 200 === $response['status'] && ! empty( $response['body']['ok'] ) ) {
			wp_send_json_success(
				array(
					'message' => sprintf(
						/* translators: %s: remote site name */
						__( 'Connected to "%s".', 'ifs-deploy' ),
						(string) ( $response['body']['name'] ?? __( 'Production', 'ifs-deploy' ) )
					),
				)
			);
		}

		$message = (string) ( $response['body']['error'] ?? __( 'Connection failed.', 'ifs-deploy' ) );
		wp_send_json_error(
			array(
				/* translators: 1: HTTP status, 2: error message */
				'message' => sprintf( __( 'Connection failed (HTTP %1$d): %2$s', 'ifs-deploy' ), $response['status'], $message ),
			)
		);
	}

	/**
	 * Verify nonce + capability for every AJAX action.
	 *
	 * Defaults to the administrator capability, so any action that does not name a
	 * lower one stays administrator-only — a new action added without thinking about
	 * permissions fails closed rather than open.
	 */
	private function guard( string $capability = AdminMenu::CAPABILITY ): void {
		if ( ! current_user_can( $capability ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ifs-deploy' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
	}

	/**
	 * Refuse an action that only the SENDING side may take.
	 *
	 * The REST layer already enforces direction — `/import` and `/rollback` answer 409 unless
	 * the receiving site is set to Production — but the AJAX side had no equivalent, so a
	 * Production admin POSTing `ifs_deploy_deploy` would still open a deployment record and
	 * try to push. Unreachable from the screens now that Pending Changes is Staging-only, and
	 * the queue on a receiver is always empty because `ChangeTracker` never runs there, so
	 * nothing legitimate is affected. This closes the direct-POST path rather than relying on
	 * both of those to stay true.
	 *
	 * 409, matching the REST layer, so the two sides of the same rule report it the same way.
	 */
	private function require_staging(): void {
		if ( Config::is_staging() ) {
			return;
		}

		wp_send_json_error(
			array( 'message' => __( 'This site is set to Production, so it receives deployments rather than sending them. Push from the Staging site.', 'ifs-deploy' ) ),
			409
		);
	}

	/**
	 * Queue ids from the request, narrowed to what this user may act on.
	 *
	 * A user without "see all" can only touch their own rows, enforced here against
	 * the database rather than trusted from the page they were served.
	 *
	 * @return int[]
	 */
	private function queue_ids(): array {
		$raw = isset( $_POST['queue_ids'] ) ? wp_unslash( $_POST['queue_ids'] ) : array();
		$raw = is_array( $raw ) ? $raw : array( $raw );
		$ids = array_values( array_filter( array_map( 'absint', $raw ) ) );

		if ( empty( $ids ) ) {
			return $ids;
		}

		/*
		 * Ownership narrowing now applies to ADMINISTRATORS TOO, unless they opt in.
		 *
		 * It previously returned everything for anyone with "see all", which made an
		 * administrator's single "Push All" click deploy every colleague's pending row —
		 * including work someone was halfway through. On a fifteen-person team that is the
		 * most likely way to push something nobody meant to publish, and it looked like a
		 * normal action right up until it happened.
		 *
		 * `include_others` is sent only when the user has ticked the box next to the button,
		 * so the wide behaviour is still available — it just has to be asked for. Someone
		 * without "see all" can never widen their scope, whatever they send.
		 */
		$wants_others = ! empty( $_POST['include_others'] ) && Access::sees_all();

		if ( $wants_others ) {
			return $ids;
		}

		return ( new QueueRepository() )->ids_owned_by( $ids, get_current_user_id() );
	}
}
