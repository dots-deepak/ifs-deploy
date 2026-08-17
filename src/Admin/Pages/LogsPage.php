<?php
declare(strict_types=1);

namespace IfsDeploy\Admin\Pages;

use IfsDeploy\Admin\AdminMenu;
use IfsDeploy\Admin\Section;
use IfsDeploy\Admin\Tabs;
use IfsDeploy\Auth\Credentials;
use IfsDeploy\History\DeploymentRepository;
use IfsDeploy\Support\ApiLog;
use IfsDeploy\Support\ClientIp;
use IfsDeploy\Support\Config;
use IfsDeploy\Support\DebugLog;
use IfsDeploy\Support\IpAccess;

/**
 * Logs & Diagnostics: why did the last deployment fail?
 *
 * Three things, in the order you need them when something breaks:
 *   1. Failures from recent deployments, with the per-object error text. These were
 *      always recorded in the deployment log but never shown, which is why a failed
 *      push only ever said "Deployment failed."
 *   2. Diagnostics — an on-demand signed round trip that reports Production's role,
 *      plugin state and uploads directory, and can ask Production to try fetching a
 *      Staging URL (the media pipeline depends on that direction working).
 *   3. The raw event log for this site.
 *
 * Both sites keep their own log. An import error is recorded on PRODUCTION, so when
 * the cause is not obvious here, open this screen there too.
 */
final class LogsPage {

	/** Deployments scanned for failures. */
	private const SCAN_LIMIT = 20;

	public function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		Section::title(
			__( 'Logs & Diagnostics', 'ifs-deploy' ),
			__( 'Why a deployment failed, and whether the other side is healthy.', 'ifs-deploy' )
		);

		$this->environment();
		$this->diagnostics();
		$this->api_access();
		$this->recent_failures();
		$this->event_log();

	}

	/**
	 * This site at a glance — the things that silently break a deploy.
	 */
	private function environment(): void {
		$remote = Config::remote();
		$creds  = Credentials::get();

		Section::heading( __( 'This site', 'ifs-deploy' ) );
		echo '<table class="widefat striped ifs-deploy-kv"><tbody>';

		$this->row( __( 'Role', 'ifs-deploy' ), Config::is_production() ? __( 'Production (receives)', 'ifs-deploy' ) : __( 'Staging (sends)', 'ifs-deploy' ) );
		$this->row( __( 'Site URL', 'ifs-deploy' ), home_url() );
		$this->row( __( 'Plugin version', 'ifs-deploy' ), IFS_DEPLOY_VERSION );
		$this->row( __( 'PHP', 'ifs-deploy' ), PHP_VERSION );
		$this->row( __( 'Site ID', 'ifs-deploy' ), $creds['site_id'] );

		if ( Config::is_staging() ) {
			$this->row(
				__( 'Production URL', 'ifs-deploy' ),
				'' !== $remote['url'] ? $remote['url'] : __( 'Not configured', 'ifs-deploy' ),
				'' === $remote['url']
			);
			$this->row(
				__( 'Production credentials', 'ifs-deploy' ),
				( '' !== $remote['api_key'] && '' !== $remote['secret_key'] )
					? __( 'Set', 'ifs-deploy' )
					: __( 'Missing — set them under IFS Deploy → Settings', 'ifs-deploy' ),
				'' === $remote['api_key'] || '' === $remote['secret_key']
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * The button that answers "is the other side actually healthy?".
	 */
	private function diagnostics(): void {
		Section::heading( __( 'Diagnostics', 'ifs-deploy' ) );

		if ( ! Config::is_staging() ) {
			echo '<p class="description">' . esc_html__( 'Diagnostics run from the Staging site, which is the side that initiates requests.', 'ifs-deploy' ) . '</p>';
			return;
		}

		echo '<p class="description">' . esc_html__( 'Contacts Production over the signed connection and reports its role, plugin state and uploads directory. It also asks Production to fetch a media file from this site — media deployment depends on that direction working, and a Staging site behind HTTP authentication or an IP allowlist will fail there.', 'ifs-deploy' ) . '</p>';

		echo '<p><button type="button" class="button button-primary" id="ifs-deploy-run-diagnostics">' . esc_html__( 'Run Diagnostics', 'ifs-deploy' ) . '</button></p>';
		echo '<div id="ifs-deploy-diagnostics-result"></div>';
	}

	/* ---------------------------------------------------------------------------
	 * API access monitoring
	 * ------------------------------------------------------------------------- */

	/**
	 * Who has been calling the signed API, and how it went.
	 *
	 * Only meaningful on the RECEIVING side. A Staging site makes the requests, so there
	 * is nothing inbound to monitor there and the section says so rather than showing an
	 * empty table that looks like a fault.
	 */
	private function api_access(): void {
		Section::heading( __( 'API access', 'ifs-deploy' ) );

		if ( ! Config::is_production() ) {
			echo '<p class="dp-help">' . esc_html__( 'This site is the Staging source, so it makes API requests rather than receiving them. Open this screen on the Production site to see who has been calling it.', 'ifs-deploy' ) . '</p>';
			return;
		}

		$summary = ApiLog::summary();

		echo '<div class="ifs-deploy-cards">';
		$this->card( __( 'Requests logged', 'ifs-deploy' ), (string) $summary['requests'] );
		$this->card( __( 'Rejected', 'ifs-deploy' ), (string) $summary['failures'] );
		$this->card( __( 'Addresses seen', 'ifs-deploy' ), (string) $summary['addresses'] );
		$this->card( __( 'New in 24h', 'ifs-deploy' ), (string) $summary['new_today'] );
		echo '</div>';

		$this->trusted_header_notice();
		$this->ip_summary_table();
		$this->api_request_table();
	}

	/**
	 * Say plainly whether the recorded addresses can be believed.
	 *
	 * Behind a CDN or load balancer, `REMOTE_ADDR` is the proxy — so every request looks
	 * like it came from one address and the monitor is worthless without saying why. The
	 * opposite mistake is worse: trusting a forwarded header that nothing sets means the
	 * caller chooses what appears in this log. Both cases get a warning.
	 */
	private function trusted_header_notice(): void {
		$header = ClientIp::trusted_header();
		$rows   = ApiLog::by_ip( 200 );

		if ( '' !== $header ) {
			printf(
				'<div class="notice notice-info"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: HTTP header name */
						__( 'Addresses are taken from the %s header, which you configured as trusted. That header must be set by your proxy or CDN — if nothing overwrites it, a caller can put any address in this log.', 'ifs-deploy' ),
						str_replace( 'HTTP_', '', $header )
					)
				)
			);

			return;
		}

		// One distinct address across many requests is the classic signature of sitting
		// behind a proxy without having said so.
		$distinct = count( $rows );
		$total    = 0;
		foreach ( $rows as $row ) {
			$total += (int) $row->hits;
		}

		if ( 1 === $distinct && $total >= 10 ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'Every request so far came from the same address. That usually means this site sits behind a proxy or CDN, and the connecting address is the proxy rather than the caller. Set a trusted header under Settings → Log Retention to see real addresses.', 'ifs-deploy' )
			);
		}
	}

	/**
	 * One row per address — the view that answers "is anyone probing us?".
	 */
	private function ip_summary_table(): void {
		$rows = ApiLog::by_ip( 25 );

		Section::heading( __( 'By address', 'ifs-deploy' ) );

		if ( empty( $rows ) ) {
			echo '<p class="dp-help">' . esc_html__( 'No API requests have reached this site yet.', 'ifs-deploy' ) . '</p>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Address', 'ifs-deploy' ) . '</th>';
		echo '<th>' . esc_html__( 'Requests', 'ifs-deploy' ) . '</th>';
		echo '<th>' . esc_html__( 'Rejected', 'ifs-deploy' ) . '</th>';
		echo '<th>' . esc_html__( 'First seen', 'ifs-deploy' ) . '</th>';
		echo '<th>' . esc_html__( 'Last seen', 'ifs-deploy' ) . '</th>';
		echo '<th>' . esc_html__( 'Rule', 'ifs-deploy' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'ifs-deploy' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$ip       = (string) $row->ip;
			$failures = (int) $row->failures;
			$status   = IpAccess::status( $ip );

			printf(
				'<tr><td><code>%1$s</code>%2$s</td><td>%3$d</td><td>%4$s</td><td>%5$s</td><td>%6$s</td><td>%7$s</td><td>%8$s</td></tr>',
				esc_html( $ip ),
				$failures >= 5 ? ' ' . $this->badge( __( 'Suspicious', 'ifs-deploy' ), 'failed' ) : '',
				(int) $row->hits,
				$failures > 0 ? $this->badge( (string) $failures, 'partial' ) : '<span class="description">&mdash;</span>',
				esc_html( (string) $row->first_seen ),
				esc_html( (string) $row->last_seen ),
				'' !== $status ? $this->rule_badge( $status ) : '<span class="description">&mdash;</span>',
				$this->ip_actions( $ip, $status )
			);
		}

		echo '</tbody></table>';

		echo '<p class="dp-help">' . esc_html__( 'Allowing an address restricts the API to the allow list — every address not on it is refused. Blocking takes effect immediately and always wins over allowing.', 'ifs-deploy' ) . '</p>';
		echo '<p class="dp-help">' . esc_html__( 'This list is kept until you remove an address yourself — clearing the requests below does not touch it. Forget deletes an address and its requests; it will be reported as new the next time it calls.', 'ifs-deploy' ) . '</p>';
	}

	/**
	 * One-click allow / block for an address already in the log.
	 *
	 * The reason these live here and not only in Settings: the addresses worth listing are
	 * the ones you have just seen, and copying an IPv6 address into a textarea by hand is
	 * how the typo gets made — the typo that makes Production refuse its own Staging site.
	 */
	private function ip_actions( string $ip, string $status ): string {
		if ( '' === $ip ) {
			return '<span class="description">&mdash;</span>';
		}

		$buttons = array();

		if ( IpAccess::RESULT_BLOCKED === $status ) {
			$buttons[] = array( 'unblock', __( 'Unblock', 'ifs-deploy' ), '' );
		} else {
			// Allow and block are mutually exclusive in practice, so only the useful pair
			// is offered for any given row rather than all four every time.
			$buttons[] = IpAccess::RESULT_ALLOWED === $status
				? array( 'unallow', __( 'Remove from allow list', 'ifs-deploy' ), '' )
				: array( 'allow', __( 'Allow', 'ifs-deploy' ), '' );

			$buttons[] = array( 'block', __( 'Block', 'ifs-deploy' ), 'ifs-deploy-danger' );
		}

		$html = '<div class="ifs-deploy-row-actions">';

		foreach ( $buttons as list( $mark, $label, $extra ) ) {
			$html .= sprintf(
				'<button type="button" class="button button-small ifs-deploy-mark-ip %1$s" data-ip="%2$s" data-mark="%3$s">%4$s</button>',
				esc_attr( $extra ),
				esc_attr( $ip ),
				esc_attr( $mark ),
				esc_html( $label )
			);
		}

		/*
		 * Forget the address entirely.
		 *
		 * The roster is not aged out — it is the record of which machines call this site,
		 * and Clear deliberately leaves it alone — so removing one has to be a deliberate
		 * act. Kept visually apart from Allow/Block: those change a RULE, this deletes
		 * history, and the two should not read as variations of one another.
		 */
		$html .= sprintf(
			'<button type="button" class="button button-small ifs-deploy-forget-ip" data-ip="%1$s">%2$s</button>',
			esc_attr( $ip ),
			esc_html__( 'Forget', 'ifs-deploy' )
		);

		return $html . '</div>';
	}

	/**
	 * How an address stands against the current rules.
	 */
	private function rule_badge( string $status ): string {
		$map = array(
			IpAccess::RESULT_ALLOWED     => array( __( 'Allowed', 'ifs-deploy' ), 'success' ),
			IpAccess::RESULT_BLOCKED     => array( __( 'Blocked', 'ifs-deploy' ), 'failed' ),
			IpAccess::RESULT_NOT_ALLOWED => array( __( 'Not allowed', 'ifs-deploy' ), 'partial' ),
		);

		$badge = $map[ $status ] ?? null;

		return null === $badge ? '' : $this->badge( $badge[0], $badge[1] );
	}

	/**
	 * The per-request tail, for reading the detail once an address looks wrong.
	 */
	private function api_request_table(): void {
		$rows = ApiLog::recent( 100 );

		Section::heading(
			__( 'Recent requests', 'ifs-deploy' ),
			'<button type="button" class="button button-small" id="ifs-deploy-clear-api-log"' . ( empty( $rows ) ? ' disabled' : '' ) . '>' . esc_html__( 'Clear', 'ifs-deploy' ) . '</button>'
		);

		if ( empty( $rows ) ) {
			return;
		}

		// Says what this list IS, because it is no longer everything. A routine accepted
		// request is counted against its address above and left out here — otherwise a
		// working pair wrote several rows per deploy for ever, and a list where every entry
		// reads "fine" is where failures go to hide.
		echo '<p class="dp-help">' . esc_html__( 'Newest first. Only first sightings of an address and rejected requests are listed — accepted requests are counted against their address above rather than listed here. Repeated rejections from one address within five minutes are collapsed into a single row, so a flood cannot fill this table.', 'ifs-deploy' ) . '</p>';

		// Says what is NOT here. A log that quietly omits rows is worse than a noisy one, so
		// the omission is stated on the screen and the setting that controls it is named.
		if ( ! ApiLog::logs_duplicates() ) {
			echo '<p class="dp-help mb-20">' . esc_html__( 'Duplicate deliveries — the same request arriving twice, refused so nothing runs twice — are not listed. A deliberate replay still is. Settings → Log Retention → Duplicate deliveries turns them on.', 'ifs-deploy' ) . '</p>';
		}

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'When', 'ifs-deploy' ) . '</th>';
		echo '<th>' . esc_html__( 'Address', 'ifs-deploy' ) . '</th>';
		echo '<th>' . esc_html__( 'Endpoint', 'ifs-deploy' ) . '</th>';
		echo '<th>' . esc_html__( 'Result', 'ifs-deploy' ) . '</th>';
		echo '<th>' . esc_html__( 'User agent', 'ifs-deploy' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$outcome = (string) $row->outcome;
			$ok      = ApiLog::OK === $outcome;

			// A duplicate delivery is the protection WORKING on ordinary transport noise,
			// not an attack. Red made every proxy retry look like an intrusion.
			$duplicate = ApiLog::DUPLICATE === $outcome;

			// An unexpected forwarded header is worth seeing even when it is not trusted —
			// it is either a proxy nobody configured, or someone trying to forge an address.
			$forwarded = (string) $row->forwarded;
			$mismatch  = '' !== $forwarded && $forwarded !== (string) $row->remote_addr;

			printf(
				'<tr><td>%1$s</td><td><code>%2$s</code>%3$s%4$s</td><td>%5$s</td><td>%6$s</td><td class="ifs-deploy-log-message">%7$s</td></tr>',
				esc_html( (string) $row->created_at ),
				esc_html( (string) $row->ip ),
				$row->is_new_ip ? ' ' . $this->badge( __( 'New', 'ifs-deploy' ), 'partial' ) : '',
				$mismatch ? '<span class="dp-id" title="' . esc_attr__( 'A forwarded-for header was present and differs from the connecting address.', 'ifs-deploy' ) . '">' . esc_html( $forwarded ) . '</span>' : '',
				'<code>' . esc_html( (string) $row->route ) . '</code>',
				$ok
					? $this->badge( __( 'Accepted', 'ifs-deploy' ), 'success' )
					: $this->badge(
						$this->outcome_label( $outcome ) . ( $duplicate ? '' : ' (' . (int) $row->status . ')' ),
						$duplicate ? 'rolledback' : 'failed'
					),
				esc_html( '' !== (string) $row->user_agent ? (string) $row->user_agent : __( '(none sent)', 'ifs-deploy' ) )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * Turn a WP_Error code into something a site owner can act on.
	 */
	private function outcome_label( string $outcome ): string {
		$map = array(
			'ifs_deploy_missing_auth'  => __( 'No credentials', 'ifs-deploy' ),
			'ifs_deploy_bad_timestamp' => __( 'Bad timestamp', 'ifs-deploy' ),
			'ifs_deploy_expired'       => __( 'Expired', 'ifs-deploy' ),
			'ifs_deploy_bad_key'       => __( 'Unknown key', 'ifs-deploy' ),
			'ifs_deploy_bad_signature' => __( 'Bad signature', 'ifs-deploy' ),
			'ifs_deploy_replay'        => __( 'Replay refused', 'ifs-deploy' ),
			// Neutral wording on purpose: nothing was rejected that should have run, and
			// nothing ran twice. The first delivery did the work.
			ApiLog::DUPLICATE           => __( 'Duplicate suppressed', 'ifs-deploy' ),
			'ifs_deploy_ip_blocked'    => __( 'Address blocked', 'ifs-deploy' ),
			'ifs_deploy_ip_not_allowed' => __( 'Not on allow list', 'ifs-deploy' ),
			'ifs_deploy_locked_out'    => __( 'Locked out', 'ifs-deploy' ),
			// Never appears: the log records the SPECIFIC reason and only the reply to the
			// caller is genericised (L-5). Listed so it reads properly if it ever does.
			'ifs_deploy_unauthorized'  => __( 'Authentication failed', 'ifs-deploy' ),
		);

		return (string) ( $map[ $outcome ] ?? $outcome );
	}

	private function badge( string $label, string $tone ): string {
		return sprintf(
			'<span class="ifs-deploy-status ifs-deploy-status-%1$s">%2$s</span>',
			esc_attr( $tone ),
			esc_html( $label )
		);
	}

	private function card( string $label, string $value ): void {
		printf(
			'<div class="ifs-deploy-card"><span class="ifs-deploy-card-label">%1$s</span><span class="ifs-deploy-card-value">%2$s</span></div>',
			esc_html( $label ),
			esc_html( $value )
		);
	}

	/**
	 * Per-object errors from recent deployments — the detail the History screen
	 * summarises as a red cross and nothing more.
	 */
	private function recent_failures(): void {
		/*
		 * Staging only. Deployment rows are written by `Client\DeploymentService`, which
		 * never runs on the receiver, so this section on Production was a heading followed
		 * by "No failures" — permanently, whatever happened. An empty panel that can never
		 * fill is not reassurance, it is a question the reader now has to answer.
		 *
		 * Production's own import errors are in the Event log below, which is where they
		 * have always been recorded.
		 */
		if ( ! Config::is_staging() ) {
			return;
		}

		$deployments = ( new DeploymentRepository() )->recent( self::SCAN_LIMIT );

		Section::heading( __( 'Recent deployment failures', 'ifs-deploy' ) );

		$rows = array();
		foreach ( $deployments as $deployment ) {
			$log = json_decode( (string) $deployment->deployment_log, true );
			$log = is_array( $log ) ? $log : array();

			// A transport-level failure is stored as a single {error: ...} entry.
			if ( isset( $log['error'] ) ) {
				$rows[] = array(
					'uuid'  => (string) $deployment->deployment_uuid,
					'time'  => (string) $deployment->deployed_at,
					'title' => __( '(whole deployment)', 'ifs-deploy' ),
					'type'  => '—',
					'error' => (string) $log['error'],
				);
				continue;
			}

			foreach ( $log as $entry ) {
				if ( ! is_array( $entry ) || ! empty( $entry['ok'] ) ) {
					continue;
				}

				$rows[] = array(
					'uuid'  => (string) $deployment->deployment_uuid,
					'time'  => (string) $deployment->deployed_at,
					'title' => (string) ( $entry['title'] ?? '' ),
					'type'  => (string) ( $entry['type'] ?? '' ),
					'error' => (string) ( $entry['error'] ?? __( 'No error message was recorded.', 'ifs-deploy' ) ),
				);
			}
		}

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No failures in the recent deployments on this site.', 'ifs-deploy' ) . '</p>';
			echo '<p class="description">' . esc_html__( 'Import errors are recorded on the Production site. If a push failed but nothing appears here, open this screen on Production.', 'ifs-deploy' ) . '</p>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'When', 'ifs-deploy' ) . '</th>';
		echo '<th>' . esc_html__( 'Deployment', 'ifs-deploy' ) . '</th>';
		echo '<th>' . esc_html__( 'Object', 'ifs-deploy' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'ifs-deploy' ) . '</th>';
		echo '<th>' . esc_html__( 'Error', 'ifs-deploy' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			printf(
				'<tr><td>%1$s</td><td><code>%2$s</code></td><td><strong>%3$s</strong></td><td>%4$s</td><td class="ifs-deploy-log-message">%5$s</td></tr>',
				esc_html( $row['time'] ),
				esc_html( substr( $row['uuid'], 0, 8 ) ),
				esc_html( '' !== $row['title'] ? $row['title'] : __( '(no title)', 'ifs-deploy' ) ),
				esc_html( $row['type'] ),
				esc_html( $row['error'] )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * The raw event log for this site.
	 *
	 * ── WHY PRODUCTION SEES A FILTERED VIEW ────────────────────────────────────
	 *
	 * On a shared install the routine `info` entries — a deploy arriving, a first sighting
	 * of an allow-listed address, a note that the peer still signs the old way, a transport
	 * duplicate being explained — are the bulk of the table and none of them need anyone to
	 * do anything. Teammates read a long log as a list of problems.
	 *
	 * But the log cannot simply be hidden here, and this is the part worth being explicit
	 * about: **each site keeps its own log, and Production's contains events Staging never
	 * sees.** The content firewall's `report` entries (the whole point of that mode is to
	 * read them on the receiver), unexpected-address warnings, the repeated-rejection alert,
	 * lockouts and import failures all exist only here. Hiding the section would make the
	 * monitoring unobservable on the one site that does the monitoring.
	 *
	 * So Production defaults to **warnings and errors only** — everything that asks for a
	 * decision, nothing that does not — with the full log one link away. Staging is
	 * unchanged: it is the working surface, and its log is what you read while debugging a
	 * push.
	 */
	private function event_log(): void {
		$all = DebugLog::all();

		// A full-page link rather than a data-tab one on purpose: the AJAX tab loader only
		// forwards a fixed set of query args, so a filter arg would be silently dropped.
		$show_all = Config::is_staging() || ( isset( $_GET['dp_log'] ) && 'all' === $_GET['dp_log'] ); // phpcs:ignore WordPress.Security.NonceVerification -- read-only display filter.

		$entries = $show_all ? $all : array_values(
			array_filter(
				$all,
				static fn( $entry ): bool => is_array( $entry )
					&& in_array( (string) ( $entry['level'] ?? '' ), array( DebugLog::LEVEL_ERROR, DebugLog::LEVEL_WARNING ), true )
			)
		);

		$hidden = count( $all ) - count( $entries );

		Section::heading(
			__( 'Event log', 'ifs-deploy' ),
			'<span class="ifs-deploy-count">' . (int) count( $entries ) . '</span>'
		);

		echo '<p>';
		printf(
			'<button type="button" class="button" id="ifs-deploy-clear-log"%s>%s</button>',
			empty( $all ) ? ' disabled' : '',
			esc_html__( 'Clear Log', 'ifs-deploy' )
		);
		echo ' <span class="description">' . esc_html__( 'Newest first. Records deployment attempts, transport errors, import failures and refused writes. Credentials are redacted.', 'ifs-deploy' ) . '</span>';
		echo '</p>';

		/*
		 * Detailed logging, off by default.
		 *
		 * These entries are what identified WPML as the reason one image edit produced
		 * eight pending changes, so they earn their place — but on a multilingual site
		 * they are written once per language per media save, and the log holds 200
		 * entries. Left on, routine noise EVICTS the failure someone is hunting for.
		 *
		 * Rendered as a plain checkbox that posts on change: it is a single boolean with
		 * no form around it, so a Save button would be ceremony.
		 */
		printf(
			'<p><label><input type="checkbox" id="ifs-deploy-verbose-log"%1$s /> %2$s</label>'
				. ' <span class="description">%3$s</span></p>',
			checked( DebugLog::verbose(), true, false ),
			esc_html__( 'Detailed logging', 'ifs-deploy' ),
			esc_html__( 'Records every change detected, with the file and the plugin responsible. Useful for tracing unexpected entries — switch it off again afterwards, or it fills the log.', 'ifs-deploy' )
		);

		// State the filter and how to defeat it. A log that quietly omits entries is worse
		// than a long one, so the count of what is hidden is named rather than implied.
		if ( ! $show_all ) {
			echo '<p class="description">';
			printf(
				/* translators: %d: number of routine entries not shown */
				esc_html( _n( 'Showing warnings and errors only. %d routine entry is hidden.', 'Showing warnings and errors only. %d routine entries are hidden.', $hidden, 'ifs-deploy' ) ),
				(int) $hidden
			);

			if ( $hidden > 0 ) {
				printf(
					' <a href="%1$s">%2$s</a>',
					esc_url( add_query_arg( 'dp_log', 'all', Tabs::url( 'logs' ) ) ),
					esc_html__( 'Show everything', 'ifs-deploy' )
				);
			}

			echo '</p>';
		}

		if ( empty( $entries ) ) {
			echo '<p>' . esc_html(
				empty( $all )
					? __( 'Nothing logged yet on this site.', 'ifs-deploy' )
					: __( 'Nothing on this site needs attention.', 'ifs-deploy' )
			) . '</p>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'When', 'ifs-deploy' ) . '</th>';
		echo '<th>' . esc_html__( 'Level', 'ifs-deploy' ) . '</th>';
		echo '<th>' . esc_html__( 'Role', 'ifs-deploy' ) . '</th>';
		echo '<th>' . esc_html__( 'Event', 'ifs-deploy' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td class="ifs-deploy-log-message">%4$s%5$s</td></tr>',
				esc_html( (string) ( $entry['time'] ?? '' ) ),
				$this->level_badge( (string) ( $entry['level'] ?? '' ) ),
				esc_html( (string) ( $entry['role'] ?? '' ) ),
				esc_html( (string) ( $entry['message'] ?? '' ) ),
				$this->context( (array) ( $entry['context'] ?? array() ) )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * @param array<string,string> $context
	 */
	private function context( array $context ): string {
		if ( empty( $context ) ) {
			return '';
		}

		$lines = array();
		foreach ( $context as $key => $value ) {
			$lines[] = esc_html( (string) $key . ': ' . (string) $value );
		}

		return '<pre class="ifs-deploy-log-context">' . implode( "\n", $lines ) . '</pre>';
	}

	private function level_badge( string $level ): string {
		$map = array(
			DebugLog::LEVEL_ERROR   => array( __( 'Error', 'ifs-deploy' ), 'failed' ),
			DebugLog::LEVEL_WARNING => array( __( 'Warning', 'ifs-deploy' ), 'partial' ),
			DebugLog::LEVEL_INFO    => array( __( 'Info', 'ifs-deploy' ), '' ),
			// Only present while "Detailed logging" is on, so it is worth labelling as
			// something the reader switched on rather than as ordinary Info.
			DebugLog::LEVEL_DEBUG   => array( __( 'Detail', 'ifs-deploy' ), '' ),
		);

		$label = $map[ $level ] ?? array( $level, '' );

		return sprintf(
			'<span class="ifs-deploy-status ifs-deploy-status-%1$s">%2$s</span>',
			esc_attr( $label[1] ),
			esc_html( $label[0] )
		);
	}

	private function row( string $label, string $value, bool $problem = false ): void {
		printf(
			'<tr><th scope="row">%1$s</th><td%2$s>%3$s</td></tr>',
			esc_html( $label ),
			$problem ? ' class="ifs-deploy-kv-problem"' : '',
			esc_html( '' !== $value ? $value : '—' )
		);
	}
}
