<?php
declare(strict_types=1);

namespace IfsDeploy\Admin\Pages;

use IfsDeploy\Admin\AdminMenu;
use IfsDeploy\Admin\Section;
use IfsDeploy\Admin\Tabs;
use IfsDeploy\History\DeploymentRepository;
use IfsDeploy\Queue\QueueRepository;
use IfsDeploy\Support\Access;
use IfsDeploy\Support\ApiLog;
use IfsDeploy\Support\Config;
use IfsDeploy\Support\ContentFirewall;
use IfsDeploy\Support\LogRetention;

/**
 * Dashboard: at-a-glance status and quick links.
 *
 * Everything shown here is scoped by Access::scope_user_id(), so a user without
 * "see all" gets their own counts rather than the site's.
 */
final class DashboardPage {

	public function render(): void {
		if ( ! current_user_can( Access::CAP_ACCESS ) ) {
			return;
		}

		$scope    = Access::scope_user_id();
		$sees_all = Access::sees_all();
		$pending  = count( ( new QueueRepository() )->get_by_status( QueueRepository::STATUS_PENDING, $scope ) );
		$last     = ( new DeploymentRepository() )->recent( 1, $scope );
		$last     = $last[0] ?? null;
		$remote   = Config::remote();

		Section::title(
			__( 'Overview', 'ifs-deploy' ),
			Config::is_production()
				? __( 'What this site receives, and where it comes from.', 'ifs-deploy' )
				: __( 'What is waiting to be deployed, and where it goes.', 'ifs-deploy' )
		);
		$this->setup_notice( $remote );
		$this->environments( $remote );

		echo '<div class="ifs-deploy-cards">';

		/*
		 * Both of these count things only the SENDING side has. The queue is filled by
		 * editing hooks on Staging and deployment rows are written by
		 * `Client\DeploymentService`, neither of which runs on the receiver — so on
		 * Production they are permanently "0" and "Never", which reads as something being
		 * broken rather than as not applicable.
		 */
		if ( Config::is_staging() ) {
			$this->card(
				$sees_all ? __( 'Pending Changes', 'ifs-deploy' ) : __( 'Your Pending Changes', 'ifs-deploy' ),
				(string) $pending,
				// Only worth saying when the number is NARROWED. Someone who sees everything
				// does not need to be told the count is not narrowed.
				$sees_all ? __( 'waiting to be pushed', 'ifs-deploy' ) : __( 'your changes only', 'ifs-deploy' )
			);

			$this->card( __( 'Last Deployment', 'ifs-deploy' ), $this->last_deployment_label( $last ) );
		} else {
			$this->receiver_cards();
		}

		echo '</div>';

		/*
		 * A flex row, so spacing comes from `gap` rather than the stray text nodes the
		 * old markup relied on between anchors. Each anchor carries data-tab, so the
		 * tab script switches in place; the href stays a real URL for no-JS and for
		 * middle-click, which is why these are anchors and not buttons.
		 *
		 * Built as a LIST rather than printed inline, because a shortcut must never point
		 * at a tab this site does not show — Pending and History are Staging-only, and a
		 * button leading to "there is nothing to show here" is worse than no button. The
		 * wrapper is skipped entirely when nothing qualifies, so Production does not get an
		 * empty row with its own spacing.
		 */
		$actions = array();

		if ( Tabs::applies_here( 'pending' ) ) {
			$actions[] = sprintf(
				'<a href="%1$s" class="button button-primary" data-tab="pending">%2$s</a>',
				esc_url( Tabs::url( 'pending' ) ),
				esc_html__( 'View Pending Changes', 'ifs-deploy' )
			);
		}

		if ( Tabs::applies_here( 'history' ) ) {
			$actions[] = sprintf(
				'<a href="%1$s" class="button" data-tab="history">%2$s</a>',
				esc_url( Tabs::url( 'history' ) ),
				esc_html__( 'Deployment History', 'ifs-deploy' )
			);
		}

		// Settings is administrator-only, so do not offer a link that would 403.
		if ( current_user_can( AdminMenu::CAPABILITY ) ) {
			$actions[] = sprintf(
				'<a href="%1$s" class="button" data-tab="settings">%2$s</a>',
				esc_url( Tabs::url( 'settings' ) ),
				esc_html__( 'Settings', 'ifs-deploy' )
			);
		}

		if ( ! empty( $actions ) ) {
			echo '<div class="dp-actions">' . implode( '', $actions ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
		}
	}

	/* ---------------------------------------------------------------------------
	 * The two environments, and which way content moves
	 * ------------------------------------------------------------------------- */

	/**
	 * Staging → Production, drawn as two cards with the direction between them.
	 *
	 * ── WHAT THIS IS FOR ───────────────────────────────────────────────────────
	 *
	 * The same plugin, the same screens, on two sites — and a "Role: Production" card was
	 * the only thing distinguishing them. On a team install that is not enough: people open
	 * the wrong site's admin, edit there, and wonder why nothing appears. So the pair is
	 * drawn as a pair, with THIS site marked and the direction stated once and visibly.
	 *
	 * ── ONE-WAY, DELIBERATELY ──────────────────────────────────────────────────
	 *
	 * Only PUSH exists. There is no pull, and nothing here hints at one — a disabled or
	 * "coming soon" control is a promise the code cannot keep, and someone would try it. The
	 * caption says "one-way" because that is the truth today; when a pull lands, this is the
	 * one place that has to change.
	 *
	 * ── NO NETWORK CALLS ───────────────────────────────────────────────────────
	 *
	 * Overview is not lazy — it renders on every visit to the plugin. Everything below comes
	 * from options and `home_url()`. "Connected" therefore means *configured*, not *reachable*;
	 * proving reachability is what Test Connection and Diagnostics are for, and doing it here
	 * would put a signed round trip on every page load.
	 *
	 * @param array{url:string,api_key:string,secret_key:string} $remote
	 */
	private function environments( array $remote ): void {
		$is_production = Config::is_production();
		$connected     = '' !== $remote['url'] && '' !== $remote['api_key'] && '' !== $remote['secret_key'];

		$staging_meta = $is_production
			// A receiver holds no record of its sender: the credentials live on the other
			// side. Saying so is better than inventing a URL or leaving it blank.
			? __( 'Configured on that site', 'ifs-deploy' )
			: $this->host( home_url() );

		$production_meta = $is_production
			? $this->host( home_url() )
			: ( '' !== $remote['url'] ? $this->host( $remote['url'] ) : __( 'Not connected', 'ifs-deploy' ) );

		echo '<div class="dp-env">';

		$this->environment_card(
			'staging',
			__( 'Staging', 'ifs-deploy' ),
			$staging_meta,
			__( 'draft content', 'ifs-deploy' ),
			! $is_production
		);

		// The direction, between the two cards it applies to. Muted rather than hidden when
		// there is nowhere to push: the relationship still exists, it is just not set up.
		printf(
			'<div class="dp-env-flow"><span class="dp-env-arrow%1$s">%2$s %3$s</span><span class="dp-env-flow-note">%4$s</span></div>',
			$is_production || $connected ? '' : ' is-idle',
			'<span aria-hidden="true">&rarr;</span>',
			esc_html__( 'Push', 'ifs-deploy' ),
			esc_html(
				$is_production || $connected
					? __( 'one-way', 'ifs-deploy' )
					: __( 'not connected', 'ifs-deploy' )
			)
		);

		$this->environment_card(
			'production',
			__( 'Production', 'ifs-deploy' ),
			$production_meta,
			__( 'live site', 'ifs-deploy' ),
			$is_production
		);

		echo '</div>';
	}

	/**
	 * One environment card.
	 *
	 * @param string $kind    `staging` or `production` — selects the icon and its tint.
	 * @param string $name    Environment name.
	 * @param string $meta    Host, or why there is no host to show.
	 * @param string $role    What the environment is for, in three words.
	 * @param bool   $is_self Whether this card is the site being looked at.
	 */
	private function environment_card( string $kind, string $name, string $meta, string $role, bool $is_self ): void {
		printf(
			'<div class="dp-env-card%1$s">'
				. '%2$s'
				. '<span class="dp-env-icon dp-env-icon-%3$s" aria-hidden="true">%4$s</span>'
				. '<span class="dp-env-eyebrow">%5$s</span>'
				. '<span class="dp-env-name">%6$s</span>'
				. '<span class="dp-env-meta">%7$s</span>'
				. '<span class="dp-env-role">%8$s</span>'
				. '</div>',
			$is_self ? ' is-self' : '',
			// The badge is the fastest read on the screen — before the name, before the URL.
			$is_self ? '<span class="dp-env-you">' . esc_html__( 'This site', 'ifs-deploy' ) . '</span>' : '',
			esc_attr( $kind ),
			self::icon( $kind ), // phpcs:ignore WordPress.Security.EscapeOutput -- fixed literal, see icon().
			esc_html__( 'Environment', 'ifs-deploy' ),
			esc_html( $name ),
			esc_html( $meta ),
			esc_html( $role )
		);
	}

	/**
	 * Inline SVG, bundled rather than fetched.
	 *
	 * Two reasons it is inline and not an icon font or a sprite: wordpress.org forbids
	 * loading assets from a third party, and `currentColor` lets one markup string take the
	 * tint from its container rather than needing a file per colour.
	 */
	private static function icon( string $kind ): string {
		$paths = 'production' === $kind
			// Layers — a stack of what has been published.
			? '<path d="M12 2 2 7l10 5 10-5-10-5Z"/><path d="m2 12 10 5 10-5"/><path d="m2 17 10 5 10-5"/>'
			// A screen — where the work is done before it goes anywhere.
			: '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8"/><path d="M12 17v4"/>';

		return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
			. ' stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" focusable="false">'
			. $paths
			. '</svg>';
	}

	/**
	 * The host of a URL, for display. Falls back to the whole URL rather than to nothing.
	 */
	private function host( string $url ): string {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );

		return '' !== $host ? $host : $url;
	}

	/**
	 * The three numbers that mean something on a receiving site.
	 *
	 * Deliberately not the sender's numbers with zeroes in them. What a Production viewer
	 * actually needs to know at a glance is whether anything is arriving, whether anything is
	 * being turned away, and whether incoming content is being sanitised — the last one
	 * because `report` mode looks like protection and is not.
	 */
	private function receiver_cards(): void {
		$summary = ApiLog::summary();
		$days    = LogRetention::days();

		$window = $days > 0
			/* translators: %d: number of days log data is kept */
			? sprintf( _n( 'in the last day', 'in the last %d days', $days, 'ifs-deploy' ), $days )
			: __( 'all time', 'ifs-deploy' );

		$this->card( __( 'API Requests', 'ifs-deploy' ), (string) $summary['requests'], $window );

		$this->card(
			__( 'Refused', 'ifs-deploy' ),
			(string) $summary['failures'],
			$summary['failures'] > 0
				? __( 'see Logs & Diagnostics', 'ifs-deploy' )
				: __( 'nothing turned away', 'ifs-deploy' )
		);

		$mode = ContentFirewall::mode();

		$this->card(
			__( 'Imported Content', 'ifs-deploy' ),
			ContentFirewall::short_label( $mode ),
			ContentFirewall::MODE_REPORT === $mode
				? __( 'logging only — nothing removed', 'ifs-deploy' )
				: __( 'applies to every deploy', 'ifs-deploy' )
		);
	}

	/**
	 * Say so when a Staging site has nowhere to push to — otherwise the first
	 * failure is the only clue.
	 *
	 * @param array{url:string,api_key:string,secret_key:string} $remote
	 */
	private function setup_notice( array $remote ): void {
		if ( ! Config::is_staging() ) {
			return;
		}

		if ( '' !== $remote['url'] && '' !== $remote['api_key'] && '' !== $remote['secret_key'] ) {
			return;
		}

		$message = __( 'This Staging site is not connected to a Production site yet, so nothing can be pushed.', 'ifs-deploy' );

		if ( current_user_can( AdminMenu::CAPABILITY ) ) {
			printf(
				'<div class="notice notice-warning"><p>%1$s <a href="%2$s" data-tab="settings">%3$s</a></p></div>',
				esc_html( $message ),
				esc_url( Tabs::url( 'settings' ) ),
				esc_html__( 'Open Settings', 'ifs-deploy' )
			);

			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html( $message . ' ' . __( 'Ask an administrator to finish the setup.', 'ifs-deploy' ) )
		);
	}

	/**
	 * Date + outcome of the most recent deployment, or a placeholder.
	 *
	 * @param object|null $deployment
	 */
	private function last_deployment_label( ?object $deployment ): string {
		if ( null === $deployment ) {
			return __( 'None yet', 'ifs-deploy' );
		}

		$statuses = array(
			DeploymentRepository::STATUS_SUCCESS     => __( 'Success', 'ifs-deploy' ),
			DeploymentRepository::STATUS_PARTIAL     => __( 'Partial', 'ifs-deploy' ),
			DeploymentRepository::STATUS_FAILED      => __( 'Failed', 'ifs-deploy' ),
			DeploymentRepository::STATUS_PENDING     => __( 'Pending', 'ifs-deploy' ),
			DeploymentRepository::STATUS_ROLLED_BACK => __( 'Rolled back', 'ifs-deploy' ),
		);

		$status = (string) $deployment->deployment_status;
		$label  = $statuses[ $status ] ?? ucfirst( $status );
		$date   = mysql2date( (string) get_option( 'date_format' ), (string) $deployment->deployed_at );

		/* translators: 1: outcome, e.g. Success, 2: date */
		return sprintf( __( '%1$s — %2$s', 'ifs-deploy' ), $label, $date );
	}

	/**
	 * @param string $hint Optional line under the value. Says what the number MEANS —
	 *                     "12 requests" is not information until you know over what window.
	 */
	private function card( string $label, string $value, string $hint = '' ): void {
		printf(
			'<div class="ifs-deploy-card"><span class="ifs-deploy-card-label">%1$s</span><span class="ifs-deploy-card-value">%2$s</span>%3$s</div>',
			esc_html( $label ),
			esc_html( $value ),
			'' !== $hint ? '<span class="ifs-deploy-card-hint">' . esc_html( $hint ) . '</span>' : ''
		);
	}
}
