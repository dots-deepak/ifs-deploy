<?php
declare(strict_types=1);

namespace IfsDeploy\Admin\Pages;

use IfsDeploy\Admin\AdminMenu;
use IfsDeploy\Admin\Section;
use IfsDeploy\Admin\Tabs;
use IfsDeploy\Auth\Credentials;
use IfsDeploy\Auth\Protocol;
use IfsDeploy\Support\Access;
use IfsDeploy\Support\ApiLog;
use IfsDeploy\Support\ClientIp;
use IfsDeploy\Support\Config;
use IfsDeploy\Support\ContentFirewall;
use IfsDeploy\Support\DebugLog;
use IfsDeploy\Support\IpAccess;
use IfsDeploy\Support\LogRetention;

/**
 * Settings, split into two sections: Connection and Role Management.
 *
 * These are selected with `section=`, NOT `tab=`. `tab=` now belongs to the screen-level
 * tab bar (Admin\Screen), and reusing it here would make "Role Management" indis-
 * tinguishable from a request for a different top-level tab.
 *
 * Administrators only — a lower role must never be able to widen its own
 * permissions or read the shared secret.
 */
final class SettingsPage {

	private const SECTION_CONNECTION = 'connection';
	private const SECTION_ROLES      = 'roles';
	private const SECTION_LOGS       = 'logs';

	/**
	 * Id of the connection form.
	 *
	 * Its Save button lives outside the form and targets it with `form="…"`, so that the
	 * button can share a row with Regenerate Credentials — which posts a different action
	 * and therefore has to be its own form.
	 */
	private const CONNECTION_FORM_ID = 'dp-connection-form';

	public function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		// POST is handled by Admin\Screen before any output, so a successful save can
		// redirect and its notice can render above the tab bar. Nothing to do here.
		$section = $this->current_section();

		Section::title(
			__( 'Settings', 'ifs-deploy' ),
			__( 'Connect this site to its counterpart, and choose who may deploy.', 'ifs-deploy' )
		);

		$this->sections( $section );

		if ( self::SECTION_ROLES === $section ) {
			$this->roles_tab();
		} elseif ( self::SECTION_LOGS === $section ) {
			$this->logs_tab();
		} else {
			$this->connection_tab();
		}
	}

	/* ---------------------------------------------------------------------------
	 * Log Retention section
	 * ------------------------------------------------------------------------- */

	/**
	 * How long log data is kept before it is purged automatically.
	 */
	private function logs_tab(): void {
		$days    = LogRetention::days();
		$entries = count( DebugLog::all() );

		echo '<form method="post" class="dp-form">';
		wp_nonce_field( 'ifs_deploy_save_logs' );
		echo '<input type="hidden" name="ifs_deploy_action" value="save_logs" />';

		Section::heading( __( 'Retention period', 'ifs-deploy' ) );

		$options = '';
		foreach ( LogRetention::choices() as $value => $label ) {
			$options .= sprintf(
				'<option value="%1$d"%2$s>%3$s</option>',
				(int) $value,
				selected( $days, (int) $value, false ),
				esc_html( $label )
			);
		}

		$this->field(
			'log_retention_days',
			__( 'Keep log data for', 'ifs-deploy' ),
			sprintf(
				'<select id="log_retention_days" name="log_retention_days">%s</select>',
				$options // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts above.
			),
			__( 'Anything older is deleted by a daily background task. Saving also purges immediately, so a shorter window takes effect right away.', 'ifs-deploy' )
		);

		echo '<div class="dp-note">';
		echo '<p class="dp-help"><strong>' . esc_html__( 'What gets purged', 'ifs-deploy' ) . '</strong></p>';
		echo '<ul class="dp-list">';
		printf(
			'<li>%s</li>',
			esc_html(
				sprintf(
					/* translators: %d: number of entries currently stored */
					_n(
						'The event log on this site (%d entry stored).',
						'The event log on this site (%d entries stored).',
						$entries,
						'ifs-deploy'
					),
					$entries
				)
			)
		);
		printf( '<li>%s</li>', esc_html__( 'Deployment history rows, which carry the per-object result of every push and are the part that actually grows without limit.', 'ifs-deploy' ) );
		echo '</ul>';

		// Worth stating plainly: people reasonably fear that pruning history removes the
		// ability to undo a deployment.
		printf(
			'<p class="dp-help">%s</p>',
			esc_html__( 'Rollback restore points are NOT affected. They live on the Production site and are already capped at 3 per page or post, so anything old enough to be purged here could not have been rolled back anyway.', 'ifs-deploy' )
		);
		echo '</div>';

		$next = wp_next_scheduled( LogRetention::CRON_HOOK );

		/* --- IP monitoring ---------------------------------------------------- */

		Section::heading( __( 'API access monitoring', 'ifs-deploy' ) );

		echo '<p class="dp-help">' . esc_html__( 'When this site acts as Production it records every signed API request — the source address, endpoint, result and user agent — under Logs & Diagnostics → API access.', 'ifs-deploy' ) . '</p>';

		$headers = '';
		foreach ( ClientIp::trustable_headers() as $value => $label ) {
			$headers .= sprintf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( (string) $value ),
				selected( ClientIp::trusted_header(), (string) $value, false ),
				esc_html( $label )
			);
		}

		$this->field(
			'trusted_ip_header',
			__( 'Source address from', 'ifs-deploy' ),
			sprintf(
				'<select id="trusted_ip_header" name="trusted_ip_header">%s</select>',
				$headers // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts above.
			),
			__( 'Leave as "None" unless this site sits behind a proxy, load balancer or CDN.', 'ifs-deploy' )
		);

		// This is the one setting on the screen that can make a security feature lie, so it
		// gets an explicit explanation rather than a one-line hint.
		echo '<div class="dp-note">';
		echo '<p class="dp-help"><strong>' . esc_html__( 'Why this matters', 'ifs-deploy' ) . '</strong></p>';
		echo '<ul class="dp-list">';
		printf( '<li>%s</li>', esc_html__( 'Forwarded-for headers are just request headers — anyone can send any value in one. Trusting one that your proxy does not overwrite lets a caller choose the address that appears in your security log, and slips past any per-address monitoring.', 'ifs-deploy' ) );
		printf( '<li>%s</li>', esc_html__( 'The opposite is also a problem: behind a CDN, the connecting address is the CDN, so every request looks like it came from one place. That is when you need this setting.', 'ifs-deploy' ) );
		printf( '<li>%s</li>', esc_html__( 'Both values are always recorded, so a mismatch is visible on the Logs screen either way.', 'ifs-deploy' ) );
		echo '</ul></div>';

		echo '<div class="dp-field"><span class="dp-field-label">' . esc_html__( 'Privacy', 'ifs-deploy' ) . '</span>';
		echo '<div class="dp-field-control">';
		echo $this->toggle( // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in toggle().
			'anonymise_ips',
			ClientIp::anonymises(),
			false,
			__( 'Store addresses anonymised', 'ifs-deploy' ),
			true
		);
		echo '<p class="dp-help">' . esc_html__( 'IP addresses are personal data under the GDPR. Anonymising masks the last part of each address, which still identifies one source hammering the endpoint while storing less about it. The retention period above applies either way.', 'ifs-deploy' ) . '</p>';
		echo '</div></div>';

		/*
		 * Duplicate deliveries. Off by default, and the copy has to say WHY that is safe —
		 * "we hide some rejected requests from your security log" needs a reason, not a
		 * checkbox.
		 */
		echo '<div class="dp-field"><span class="dp-field-label">' . esc_html__( 'Duplicate deliveries', 'ifs-deploy' ) . '</span>';
		echo '<div class="dp-field-control">';
		echo $this->toggle( // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in toggle().
			'log_duplicates',
			ApiLog::logs_duplicates(),
			false,
			__( 'List them as separate requests', 'ifs-deploy' ),
			true
		);
		echo '<p class="dp-help">' . esc_html__( 'Some hosts, proxies and load balancers deliver the same request twice. The second copy is refused so nothing is applied twice — but it is the same event, not a new one, and a table full of "Duplicate suppressed" is how a security log stops being read. Off by default: the cause is still reported once an hour in the event log, with what to check. Turn this on only while chasing a transport problem.', 'ifs-deploy' ) . '</p>';
		echo '<p class="dp-help">' . esc_html__( 'A deliberately replayed request is a different result — "Replay refused" — and is always listed, whatever this is set to.', 'ifs-deploy' ) . '</p>';
		echo '</div></div>';

		$this->content_firewall();
		$this->ip_rules();

		echo '<div class="dp-form-actions">';
		submit_button( __( 'Save Log Settings', 'ifs-deploy' ), 'primary', 'submit', false );

		if ( $next ) {
			printf(
				'<span class="dp-help">%s</span>',
				esc_html(
					sprintf(
						/* translators: %s: human-readable time until the next run, e.g. "3 hours" */
						__( 'Next automatic purge in %s.', 'ifs-deploy' ),
						human_time_diff( (int) $next )
					)
				)
			);
		} else {
			printf( '<span class="dp-help">%s</span>', esc_html__( 'The daily purge is not scheduled. Deactivating and reactivating the plugin restores it.', 'ifs-deploy' ) );
		}

		echo '</div>';
		echo '</form>';

		$this->reset_data();
	}

	/**
	 * Start again from nothing, for testing.
	 *
	 * ── WHY THIS IS HERE AND WHY IT LOOKS LIKE THIS ────────────────────────────────
	 *
	 * The plugin remembers what it has deployed — that is the point of it — and there was
	 * no way to make it forget short of uninstalling. Deactivating does not do it: the
	 * tables survive, and so do the origin stamps, which live on the content itself. So a
	 * second run of the same test never reproduced the first, because by then every object
	 * was already known.
	 *
	 * Rendered outside the settings form on purpose. It is not a setting and must not be
	 * saved along with one — and putting a destructive button inside a form whose primary
	 * action is "Save" is how it eventually gets pressed by accident.
	 *
	 * The list is exhaustive and specific. "Reset all data" is a sentence people will read
	 * as including their pages, and the one thing this must never be mistaken for is
	 * something that touches content.
	 */
	private function reset_data(): void {
		if ( ! current_user_can( Access::CAP_MANAGE ) ) {
			return;
		}

		Section::heading( __( 'Reset plugin data', 'ifs-deploy' ) );

		echo '<div class="dp-note dp-note-danger">';
		echo '<p class="dp-help"><strong>' . esc_html__( 'This removes, on this site only:', 'ifs-deploy' ) . '</strong></p>';
		echo '<ul class="dp-list">';
		printf( '<li>%s</li>', esc_html__( 'Every pending change, including ones never pushed.', 'ifs-deploy' ) );
		printf( '<li>%s</li>', esc_html__( 'The whole deployment history, and every rollback restore point it refers to.', 'ifs-deploy' ) );
		printf( '<li>%s</li>', esc_html__( 'The event log and the API access log.', 'ifs-deploy' ) );
		printf( '<li>%s</li>', esc_html__( 'The deployment stamps on your content — the marks that record which objects have been deployed before. Removing them is what makes the next push behave like a first push.', 'ifs-deploy' ) );
		echo '</ul>';

		// The reassurance has to be as prominent as the warning, or the button does not get
		// used at all — which is its own failure, since the alternative people reach for is
		// deleting rows by hand.
		printf(
			'<p class="dp-help"><strong>%s</strong> %s</p>',
			esc_html__( 'No content is touched.', 'ifs-deploy' ),
			esc_html__( 'Not one page, post, image, category or setting of your site is deleted or altered — only this plugin\'s own records, and its bookkeeping meta on your content.', 'ifs-deploy' )
		);

		printf(
			'<p class="dp-help">%s</p>',
			esc_html__( 'It applies to THIS site only. The site at the other end keeps its own history, restore points and stamps — reset it there as well if you want both sides clean.', 'ifs-deploy' )
		);
		echo '</div>';

		echo '<div class="dp-field"><span class="dp-field-label">' . esc_html__( 'Connection', 'ifs-deploy' ) . '</span>';
		echo '<div class="dp-field-control">';
		echo $this->toggle( // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in toggle().
			'reset_include_connection',
			false,
			false,
			__( 'Also forget the credentials and the paired site', 'ifs-deploy' ),
			true
		);
		echo '<p class="dp-help">' . esc_html__( 'Off by default. Leaving it off keeps the two sites paired so you can carry straight on testing; turning it on means regenerating credentials and entering them again on both sides.', 'ifs-deploy' ) . '</p>';
		echo '</div></div>';

		echo '<div class="dp-form-actions">';
		printf(
			'<button type="button" class="button ifs-deploy-danger" id="ifs-deploy-reset-data">%s</button>',
			esc_html__( 'Reset All Plugin Data', 'ifs-deploy' )
		);
		echo '</div>';
	}

	/**
	 * What imported content is allowed to contain (SECURITY.md H-2 / H-2b).
	 *
	 * Only meaningful on the receiving side, and the copy says so rather than offering a
	 * setting that quietly does nothing.
	 */
	private function content_firewall(): void {
		Section::heading( __( 'Imported content', 'ifs-deploy' ) );

		if ( ! Config::is_production() ) {
			echo '<p class="dp-help">' . esc_html__( 'This site is the Staging source, so nothing is imported into it. Set this on the Production site.', 'ifs-deploy' ) . '</p>';
			return;
		}

		$mode = ContentFirewall::mode();

		echo '<p class="dp-help">' . esc_html__( 'WordPress strips scripts from content saved by a user without the unfiltered_html capability. A deployment has no logged-in user, so that filter never runs and whatever is sent is stored as-is — which means anything holding the shared secret can store executable HTML here, even though no user on this site is allowed to.', 'ifs-deploy' ) . '</p>';

		$options = '';
		foreach ( ContentFirewall::modes() as $value => $label ) {
			$options .= sprintf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $mode, $value, false ),
				esc_html( $label )
			);
		}

		$this->field(
			'content_firewall',
			__( 'Imported HTML', 'ifs-deploy' ),
			sprintf(
				'<select id="content_firewall" name="content_firewall">%s</select>',
				$options // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts above.
			),
			__( 'Applies to page and post content, and to the HTML inside deployed widgets.', 'ifs-deploy' )
		);

		// Report mode is the default on upgrade precisely so nobody has to take this on
		// trust — the log tells you what would change before anything does.
		if ( ContentFirewall::MODE_REPORT === $mode ) {
			printf(
				'<div class="notice notice-info"><p>%s</p></div>',
				esc_html__( 'Report mode: nothing is being removed. Deploy as usual, then check Logs & Diagnostics — anything that would be stripped is listed there by page name. If the list is empty or only mentions things you do not want on the site, switch to Filter.', 'ifs-deploy' )
			);
		}

		if ( ContentFirewall::MODE_OFF === $mode ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'Off: imported content is stored exactly as sent, including any script. Treat the shared secret as equivalent to an administrator account on this site.', 'ifs-deploy' )
			);
		}

		echo '<div class="dp-note">';
		echo '<p class="dp-help"><strong>' . esc_html__( 'What Filter mode keeps and removes', 'ifs-deploy' ) . '</strong></p>';
		echo '<ul class="dp-list">';
		// Measured against real WordPress, not assumed — an earlier version of this note
		// claimed block markup would break, and that was wrong.
		printf( '<li><strong>%s</strong> %s</li>', esc_html__( 'Kept:', 'ifs-deploy' ), esc_html__( 'block markup and block attributes, nested blocks, shortcodes, data-* attributes, inline styles, responsive image srcset and sizes, video sources, forms, inline SVG, and embeds from the usual video and map providers.', 'ifs-deploy' ) );
		printf( '<li><strong>%s</strong> %s</li>', esc_html__( 'Removed:', 'ifs-deploy' ), esc_html__( 'script and style tags, every on* event handler, javascript: URLs, iframes pointing at any other host, and the SVG constructs that can smuggle markup back in.', 'ifs-deploy' ) );
		echo '</ul>';
		printf(
			'<p class="dp-help">%s</p>',
			esc_html__( 'Both lists are extendable with the ifs_deploy_allowed_html and ifs_deploy_allowed_iframe_hosts filters.', 'ifs-deploy' )
		);
		echo '</div>';
	}

	/**
	 * Allow and block lists for the API.
	 *
	 * The allow list is the one setting on this screen that can lock a working pair out of
	 * itself, so the UI is built around not letting that happen quietly: the addresses
	 * actually observed are listed for copying, the current effect is stated in words, and
	 * the warning is next to the field rather than buried in help text.
	 */
	private function ip_rules(): void {
		Section::heading( __( 'Address rules', 'ifs-deploy' ) );

		echo '<p class="dp-help">' . esc_html__( 'Applies to the signed API only, on the site acting as Production. It never affects who can log in to wp-admin — so a mistake here cannot lock you out of this screen.', 'ifs-deploy' ) . '</p>';

		$allow = IpAccess::allow_list();
		$block = IpAccess::block_list();

		// State the effect in words. "Three entries" does not tell you whether deploys are
		// currently restricted; this does.
		printf(
			'<div class="notice notice-%1$s"><p>%2$s</p></div>',
			IpAccess::is_configured() ? 'warning' : 'info',
			esc_html(
				IpAccess::is_configured()
					? sprintf(
						/* translators: %d: number of allow-list entries */
						_n(
							'Only %d listed address may call the API right now. Anything else is refused, including your Staging site if its address is not on the list.',
							'Only the %d listed addresses may call the API right now. Anything else is refused, including your Staging site if its address is not on the list.',
							count( $allow ),
							'ifs-deploy'
						),
						count( $allow )
					)
					: __( 'No allow list is set, so any address may call the API — the signature is what protects it. Add entries only if you know the fixed address your Staging site sends from.', 'ifs-deploy' )
			)
		);

		$this->field(
			'ip_allow',
			__( 'Allowed addresses', 'ifs-deploy' ),
			sprintf(
				'<textarea id="ip_allow" name="ip_allow" rows="4" class="dp-input dp-input-mono" spellcheck="false" placeholder="%1$s">%2$s</textarea>',
				esc_attr__( "203.0.113.9\n198.51.100.0/24", 'ifs-deploy' ),
				esc_textarea( implode( "\n", $allow ) )
			),
			__( 'One per line. A single address, or a CIDR range such as 198.51.100.0/24. Leave empty for no restriction.', 'ifs-deploy' )
		);

		$this->field(
			'ip_block',
			__( 'Blocked addresses', 'ifs-deploy' ),
			sprintf(
				'<textarea id="ip_block" name="ip_block" rows="4" class="dp-input dp-input-mono" spellcheck="false" placeholder="%1$s">%2$s</textarea>',
				esc_attr__( '192.0.2.44', 'ifs-deploy' ),
				esc_textarea( implode( "\n", $block ) )
			),
			__( 'Refused before anything else is checked. Blocking always wins over the allow list.', 'ifs-deploy' )
		);

		$this->observed_addresses();

		echo '<div class="dp-note">';
		echo '<p class="dp-help"><strong>' . esc_html__( 'Before you fill in the allow list', 'ifs-deploy' ) . '</strong></p>';
		echo '<ul class="dp-list">';
		printf( '<li>%s</li>', esc_html__( 'Copy an address from the list below rather than guessing. A wrong entry makes Production refuse its own Staging site, and every deploy then fails with a permission error.', 'ifs-deploy' ) );
		printf( '<li>%s</li>', esc_html__( 'Matching uses the same address the log records, so it obeys the "Source address from" setting above. Behind a CDN that is the CDN\'s address, not your Staging site\'s — configure that setting first or the allow list will match the wrong thing.', 'ifs-deploy' ) );
		printf( '<li>%s</li>', esc_html__( 'Many hosts do not give a Staging site a fixed outbound address. If yours does not, leave the allow list empty; the signature is the real access control.', 'ifs-deploy' ) );
		printf( '<li>%s</li>', esc_html__( 'A block list is a speed bump, not a wall — an attacker on a changing address simply reappears. It is for silencing one noisy source.', 'ifs-deploy' ) );
		echo '</ul>';

		// If they get it wrong anyway, say where the evidence will be.
		printf(
			'<p class="dp-help">%s</p>',
			esc_html__( 'Every refusal is recorded under Logs & Diagnostics → API access, so if deploys stop working you can see there whether an address rule is the reason.', 'ifs-deploy' )
		);
		echo '</div>';
	}

	/**
	 * Addresses that have actually called this site, so the admin copies rather than guesses.
	 */
	private function observed_addresses(): void {
		if ( ! Config::is_production() ) {
			echo '<div class="dp-field"><span class="dp-field-label">' . esc_html__( 'Observed', 'ifs-deploy' ) . '</span>';
			echo '<div class="dp-field-control"><p class="dp-help">' . esc_html__( 'This site is the Staging source, so nothing calls its API. Set address rules on the Production site instead.', 'ifs-deploy' ) . '</p></div></div>';
			return;
		}

		$rows = ApiLog::by_ip( 10 );

		echo '<div class="dp-field"><span class="dp-field-label">' . esc_html__( 'Observed', 'ifs-deploy' ) . '</span>';
		echo '<div class="dp-field-control">';

		if ( empty( $rows ) ) {
			echo '<p class="dp-help">' . esc_html__( 'No API requests have reached this site yet. Run a deploy first, then come back — the address your Staging site actually used will be listed here.', 'ifs-deploy' ) . '</p>';
			echo '</div></div>';
			return;
		}

		echo '<ul class="dp-list dp-observed">';
		foreach ( $rows as $row ) {
			$ip       = (string) $row->ip;
			$status   = IpAccess::status( $ip );
			$failures = (int) $row->failures;

			printf(
				'<li><code>%1$s</code> <span class="dp-help">%2$s</span>%3$s</li>',
				esc_html( $ip ),
				esc_html(
					sprintf(
						/* translators: 1: total requests, 2: rejected requests */
						_n( '%1$d request, %2$d rejected', '%1$d requests, %2$d rejected', (int) $row->hits, 'ifs-deploy' ),
						(int) $row->hits,
						$failures
					)
				),
				'' !== $status ? ' ' . $this->rule_badge( $status ) : ''
			);
		}
		echo '</ul>';

		echo '<p class="dp-help">' . esc_html__( 'Newest activity first. An address with requests and no rejections is almost certainly your Staging site.', 'ifs-deploy' ) . '</p>';
		echo '</div></div>';
	}

	/**
	 * How an observed address stands against the current rules.
	 */
	private function rule_badge( string $status ): string {
		$map = array(
			IpAccess::RESULT_ALLOWED     => array( __( 'Allowed', 'ifs-deploy' ), 'success' ),
			IpAccess::RESULT_BLOCKED     => array( __( 'Blocked', 'ifs-deploy' ), 'failed' ),
			IpAccess::RESULT_NOT_ALLOWED => array( __( 'Not on allow list', 'ifs-deploy' ), 'partial' ),
		);

		$badge = $map[ $status ] ?? null;

		if ( null === $badge ) {
			return '';
		}

		return sprintf(
			'<span class="ifs-deploy-status ifs-deploy-status-%1$s">%2$s</span>',
			esc_attr( $badge[1] ),
			esc_html( $badge[0] )
		);
	}

	/**
	 * Sections this site actually has, in display order.
	 *
	 * Role Management is Staging-only. Every capability it grants — queue access, pushing,
	 * rollback — is exercised from the SENDING side; on the receiver the plugin has no
	 * user-initiated action to delegate, so the matrix is three columns of settings that
	 * change nothing. Hiding it is a display decision: the stored roles are untouched, and
	 * `Support\Access` keeps enforcing them on both sites exactly as before.
	 *
	 * @return array<string,string> slug => label
	 */
	private function available_sections(): array {
		$sections = array(
			self::SECTION_CONNECTION => __( 'Connection Settings', 'ifs-deploy' ),
		);

		if ( Config::is_staging() ) {
			$sections[ self::SECTION_ROLES ] = __( 'Role Management', 'ifs-deploy' );
		}

		$sections[ self::SECTION_LOGS ] = __( 'Log Retention', 'ifs-deploy' );

		return $sections;
	}

	private function current_section(): string {
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( (string) $_GET['section'] ) ) : self::SECTION_CONNECTION;

		// Resolved against what this site OFFERS, so an old link to `section=roles` on a
		// Production site lands on Connection Settings rather than rendering a section that
		// is not in the subnav — which would look like a bug from either direction.
		return isset( $this->available_sections()[ $section ] ) ? $section : self::SECTION_CONNECTION;
	}

	public static function section_url( string $section ): string {
		return add_query_arg( 'section', $section, Tabs::url( 'settings' ) );
	}

	private function sections( string $current ): void {
		$sections = $this->available_sections();

		echo '<div class="dp-subnav" role="tablist">';
		foreach ( $sections as $slug => $label ) {
			printf(
				'<a href="%1$s" class="dp-subnav-item%2$s" role="tab" aria-selected="%3$s" data-section="%4$s">%5$s</a>',
				esc_url( self::section_url( $slug ) ),
				$slug === $current ? ' is-active' : '',
				$slug === $current ? 'true' : 'false',
				esc_attr( $slug ),
				esc_html( $label )
			);
		}
		echo '</div>';
	}

	/* ---------------------------------------------------------------------------
	 * Connection tab — unchanged behaviour, just moved under a tab.
	 * ------------------------------------------------------------------------- */

	private function connection_tab(): void {
		$role   = Config::role();
		$creds  = Credentials::get();
		$remote = Config::remote();

		printf( '<form method="post" class="dp-form" id="%s">', esc_attr( self::CONNECTION_FORM_ID ) );
		wp_nonce_field( 'ifs_deploy_save_settings' );
		echo '<input type="hidden" name="ifs_deploy_action" value="save_settings" />';

		Section::heading( __( 'Direction', 'ifs-deploy' ) );

		// Two mutually exclusive, consequential choices — so each one states its
		// consequence rather than relying on a bare radio label.
		echo '<div class="dp-choices">';
		$this->choice(
			'role',
			Config::ROLE_STAGING,
			$role === Config::ROLE_STAGING,
			__( 'Staging', 'ifs-deploy' ),
			__( 'The source. Content is edited here and pushed out.', 'ifs-deploy' )
		);
		$this->choice(
			'role',
			Config::ROLE_PRODUCTION,
			$role === Config::ROLE_PRODUCTION,
			__( 'Production', 'ifs-deploy' ),
			__( 'The destination. It receives content and never sends any.', 'ifs-deploy' )
		);
		echo '</div>';

		// The remote connection only applies to Staging. Rendered already-hidden when
		// the current role is Production so there is no flash on load; admin.js keeps it
		// in sync as the radio changes.
		$hide_staging = Config::is_production() ? ' style="display:none;"' : '';
		$hide_prod    = Config::is_production() ? '' : ' style="display:none;"';

		echo '<div class="ifs-deploy-staging-only"' . $hide_staging . '>';

		Section::heading( __( 'Production connection', 'ifs-deploy' ) );

		/*
		 * An already-stored http:// URL is reported, never rewritten or disabled.
		 * Silently breaking a working pair on upgrade is the one thing worse than the
		 * warning itself, so the fix stays the owner's deliberate action.
		 */
		if ( Config::remote_is_insecure() ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p></div>',
				esc_html__( 'This connection is not encrypted.', 'ifs-deploy' ),
				esc_html__( 'The Production URL uses http, so every deployed page and the API key itself travel in the clear, and an attacker on the network can alter what Production appears to reply. Change it to https and save. Deploys keep working in the meantime.', 'ifs-deploy' )
			);
		}

		$this->field(
			'remote_url',
			__( 'Production URL', 'ifs-deploy' ),
			sprintf(
				'<input type="url" id="remote_url" name="remote_url" value="%s" class="dp-input" placeholder="https://www.example.com" />',
				esc_attr( $remote['url'] )
			),
			__( 'The base URL of the Production site this Staging site pushes to.', 'ifs-deploy' )
		);

		$this->field(
			'remote_api_key',
			__( 'Production API Key', 'ifs-deploy' ),
			sprintf(
				'<input type="text" id="remote_api_key" name="remote_api_key" value="%s" class="dp-input dp-input-mono" spellcheck="false" autocomplete="off" />',
				esc_attr( $remote['api_key'] )
			),
			__( 'Copy these from the Production site, under Settings → This site\'s credentials.', 'ifs-deploy' )
		);

		$this->field(
			'remote_secret_key',
			__( 'Production Secret Key', 'ifs-deploy' ),
			sprintf(
				'<input type="text" id="remote_secret_key" name="remote_secret_key" value="%s" class="dp-input dp-input-mono" spellcheck="false" autocomplete="off" />',
				esc_attr( $remote['secret_key'] )
			)
		);

		echo '</div>';

		// On Production there is nothing to configure, so say that instead of leaving
		// the tab looking empty.
		echo '<div class="ifs-deploy-production-only"' . $hide_prod . '>';
		printf(
			'<div class="notice notice-info"><p>%s</p></div>',
			esc_html__( 'This site is a Production destination. It needs no remote details — just share the credentials below with your Staging site.', 'ifs-deploy' )
		);
		echo '</div>';

		// The form closes HERE, before the credentials block, so that block is not inside
		// it — Regenerate is a separate form and forms cannot nest. Save Settings still
		// submits this one from outside it via the HTML5 `form` attribute below.
		echo '</form>';

		// This site's own credentials — only meaningful while this site IS the
		// destination. On Staging you paste the OTHER site's keys in above; your own are
		// noise, and showing them invites pasting the wrong pair.
		echo '<div class="ifs-deploy-production-only"' . $hide_prod . '>';

		Section::heading( __( 'This site\'s credentials', 'ifs-deploy' ) );

		echo '<p class="dp-help">' . esc_html__( 'Paste these into the Staging site\'s connection settings. Click a value to select it.', 'ifs-deploy' ) . '</p>';

		echo '<div class="dp-form">';
		$this->readonly_field( __( 'Site ID', 'ifs-deploy' ), $creds['site_id'] );
		$this->readonly_field( __( 'API Key', 'ifs-deploy' ), $creds['api_key'] );
		$this->readonly_field( __( 'Secret Key', 'ifs-deploy' ), $creds['secret_key'] );
		echo '</div>';

		echo '</div>';

		/*
		 * One actions row for the whole tab, placed after the credentials block so it
		 * lands in the right place in BOTH roles without being rendered twice:
		 *
		 *   Staging     → [ Save Settings ] [ Test Connection ]
		 *   Production  → credentials, then [ Save Settings ] [ Regenerate Credentials ]
		 *
		 * Save Settings sits outside its form and submits it by id. That is what lets the
		 * two buttons share a row at all: they post different actions, so they must stay
		 * in different forms, and `form=` is the only way to place a submit button
		 * anywhere on the page.
		 */
		echo '<div class="dp-form-actions">';

		submit_button(
			__( 'Save Settings', 'ifs-deploy' ),
			'primary',
			'submit',
			false,
			array( 'form' => self::CONNECTION_FORM_ID )
		);

		// Regenerate is a form of its own, side by side with the button above.
		printf(
			'<form method="post" class="dp-inline-form ifs-deploy-production-only" onsubmit="return confirm(\'%s\');"%s>',
			esc_js( __( 'Regenerate credentials? Any Staging site using the old keys will stop connecting until updated.', 'ifs-deploy' ) ),
			$hide_prod // phpcs:ignore WordPress.Security.EscapeOutput -- fixed literal.
		);
		wp_nonce_field( 'ifs_deploy_regenerate' );
		echo '<input type="hidden" name="ifs_deploy_action" value="regenerate" />';
		submit_button( __( 'Regenerate Credentials', 'ifs-deploy' ), 'secondary', 'regenerate', false );
		echo '</form>';

		// Test Connection is a Staging-only action.
		echo '<span class="ifs-deploy-staging-only"' . $hide_staging . '>';
		echo '<button type="button" class="button" id="ifs-deploy-test-connection">' . esc_html__( 'Test Connection', 'ifs-deploy' ) . '</button>';
		echo '</span>';

		echo '<span id="ifs-deploy-test-result" class="dp-inline-result"></span>';
		echo '</div>';

		// Sits under the row so it explains Regenerate without widening it.
		echo '<p class="dp-help ifs-deploy-production-only"' . $hide_prod . '>';
		echo esc_html__( 'Regenerating invalidates the current keys immediately, and any Staging site using them stops connecting until you update it.', 'ifs-deploy' );
		echo '</p>';
	}

	/**
	 * One label + control + help row.
	 *
	 * @param string $for     Id of the control, so the label is clickable.
	 * @param string $control Pre-escaped control markup.
	 */
	private function field( string $for, string $label, string $control, string $help = '' ): void {
		echo '<div class="dp-field">';
		printf( '<label class="dp-field-label" for="%1$s">%2$s</label>', esc_attr( $for ), esc_html( $label ) );

		echo '<div class="dp-field-control">';
		echo $control; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by the caller.

		if ( '' !== $help ) {
			printf( '<p class="dp-help">%s</p>', esc_html( $help ) );
		}

		echo '</div></div>';
	}

	/**
	 * A radio rendered as a selectable card stating what the choice means.
	 */
	private function choice( string $name, string $value, bool $selected, string $label, string $description ): void {
		printf(
			'<label class="dp-choice%1$s">'
				. '<input type="radio" name="%2$s" value="%3$s"%4$s />'
				. '<span class="dp-choice-body">'
					. '<span class="dp-choice-label">%5$s</span>'
					. '<span class="dp-choice-description">%6$s</span>'
				. '</span>'
				. '</label>',
			$selected ? ' is-selected' : '',
			esc_attr( $name ),
			esc_attr( $value ),
			checked( $selected, true, false ),
			esc_html( $label ),
			esc_html( $description )
		);
	}

	/**
	 * A credential the user copies out. Read-only rather than disabled, so the value is
	 * still selectable; `onfocus` selects it in one click.
	 */
	private function readonly_field( string $label, string $value ): void {
		printf(
			'<div class="dp-field"><span class="dp-field-label">%1$s</span>'
				. '<div class="dp-field-control">'
				. '<input type="text" readonly class="dp-input dp-input-mono is-readonly" onfocus="this.select()" value="%2$s" />'
				. '</div></div>',
			esc_html( $label ),
			esc_attr( $value )
		);
	}

	/* ---------------------------------------------------------------------------
	 * Role Management tab
	 * ------------------------------------------------------------------------- */

	private function roles_tab(): void {
		$permissions = Access::permissions();
		$grantable   = Access::grantable();

		echo '<form method="post" class="dp-form">';
		wp_nonce_field( 'ifs_deploy_save_roles' );
		echo '<input type="hidden" name="ifs_deploy_action" value="save_roles" />';

		Section::heading( __( 'By role', 'ifs-deploy' ) );

		echo '<p class="dp-help">' . esc_html__( 'Nothing is granted by default — a role sees IFS Deploy only once you allow it here.', 'ifs-deploy' ) . '</p>';

		echo '<div class="dp-table-wrap">';
		echo '<table class="dp-table ifs-deploy-roles">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Role', 'ifs-deploy' ) . '</th>';
		foreach ( $grantable as $meta ) {
			printf(
				'<th scope="col">%1$s<span class="dp-th-hint">%2$s</span></th>',
				esc_html( $meta['label'] ),
				esc_html( $meta['description'] )
			);
		}
		echo '</tr></thead><tbody>';

		foreach ( Access::roles() as $slug => $name ) {
			$is_admin = Access::is_admin_role( (string) $slug );

			echo '<tr>';
			printf(
				'<th scope="row"><span class="dp-role-name">%1$s</span>%2$s</th>',
				esc_html( $name ),
				$is_admin ? '<span class="dp-th-hint">' . esc_html__( 'Always has full access', 'ifs-deploy' ) . '</span>' : ''
			);

			foreach ( $grantable as $cap => $meta ) {
				$is_on = $is_admin || ! empty( $permissions[ (string) $slug ][ $cap ] );

				// Administrators are granted everything unconditionally, so their
				// switches show on but cannot be edited — that is what stops an
				// administrator locking themselves out of this very screen.
				printf(
					'<td>%s</td>',
					$this->toggle(
						sprintf( 'roles[%s][%s]', (string) $slug, $cap ),
						$is_on,
						$is_admin,
						/* translators: 1: permission name, 2: role name */
						sprintf( __( '%1$s for %2$s', 'ifs-deploy' ), $meta['label'], $name )
					)
				);
			}

			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';

		echo '<p class="dp-help">' . esc_html__( 'Connection settings, Compare & Sync, logs and diagnostics stay with administrators and cannot be delegated.', 'ifs-deploy' ) . '</p>';

		$this->user_overrides( $grantable );

		echo '<div class="dp-form-actions">';
		submit_button( __( 'Save Role Settings', 'ifs-deploy' ), 'primary', 'submit', false );
		echo '</div>';
		echo '</form>';
	}

	/**
	 * Per-user exceptions to the role rules.
	 *
	 * Covers the two cases the role matrix alone cannot: granting one person access
	 * when their whole role has none, and denying one person when their role has it.
	 *
	 * @param array<string,array{label:string,description:string}> $grantable
	 */
	private function user_overrides( array $grantable ): void {
		$users = Access::users();

		Section::heading( __( 'Specific users', 'ifs-deploy' ) );

		echo '<p class="dp-help">' . esc_html__( 'Exceptions to the rules above. Search by name or email address.', 'ifs-deploy' ) . '</p>';

		// --- Allow ---------------------------------------------------------------
		echo '<div class="dp-field">';
		echo '<span class="dp-field-label">' . esc_html__( 'Give access to', 'ifs-deploy' ) . '</span>';
		echo '<div class="dp-field-control">';
		$this->user_picker( 'dp_users_allow', $users['allow'], __( 'Search users to give access…', 'ifs-deploy' ) );
		echo '<p class="dp-help">' . esc_html__( 'These users get the permissions switched on below, on top of anything their role already allows.', 'ifs-deploy' ) . '</p>';

		echo '<fieldset class="ifs-deploy-user-caps dp-toggle-list">';
		foreach ( $grantable as $cap => $meta ) {
			echo $this->toggle( // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in toggle().
				sprintf( 'dp_users_allow_caps[%s]', $cap ),
				! empty( $users['allow_caps'][ $cap ] ),
				false,
				$meta['label'],
				true
			);
		}
		echo '</fieldset>';
		echo '</div></div>';

		// --- Block ---------------------------------------------------------------
		echo '<div class="dp-field">';
		echo '<span class="dp-field-label">' . esc_html__( 'Block', 'ifs-deploy' ) . '</span>';
		echo '<div class="dp-field-control">';
		$this->user_picker( 'dp_users_block', $users['block'], __( 'Search users to block…', 'ifs-deploy' ) );
		echo '<p class="dp-help">' . esc_html__( 'These users get no IFS Deploy access at all, even if their role allows it. Blocking wins over everything else — except administrators, who always keep full access.', 'ifs-deploy' ) . '</p>';
		echo '</div></div>';
	}

	/**
	 * A searchable multi-select: a text input backed by autocomplete, plus one chip
	 * per chosen user carrying a hidden field.
	 *
	 * Rendered server-side so the current selection survives with JavaScript off;
	 * only adding and removing needs the script.
	 *
	 * @param int[] $selected
	 */
	private function user_picker( string $field, array $selected, string $placeholder ): void {
		printf(
			'<div class="ifs-deploy-user-picker" data-field="%s">',
			esc_attr( $field )
		);

		printf(
			'<input type="text" class="dp-input ifs-deploy-user-search" placeholder="%s" autocomplete="off" />',
			esc_attr( $placeholder )
		);

		echo '<ul class="ifs-deploy-chips">';
		foreach ( $selected as $user_id ) {
			echo $this->chip( $field, (int) $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in chip().
		}
		echo '</ul>';

		echo '</div>';
	}

	/**
	 * One selected-user chip.
	 */
	private function chip( string $field, int $user_id ): string {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			/* translators: %d: user ID */
			$label = sprintf( __( 'Deleted user (#%d)', 'ifs-deploy' ), $user_id );
		} else {
			$label = sprintf( '%s (%s)', $user->display_name, $user->user_email );
		}

		return sprintf(
			'<li class="ifs-deploy-chip-item">'
				. '<input type="hidden" name="%1$s[]" value="%2$d" />'
				. '<span>%3$s</span>'
				. '<button type="button" class="ifs-deploy-chip-remove" aria-label="%4$s">&times;</button>'
				. '</li>',
			esc_attr( $field ),
			$user_id,
			esc_html( $label ),
			esc_attr__( 'Remove', 'ifs-deploy' )
		);
	}

	/* ------------------------------------------------------------------------- */

	/**
	 * Handle a settings form submission.
	 *
	 * Called by `Admin\Screen` on a REAL page load, before anything renders — a form
	 * POST is ordinary navigation, not an XHR, so this can never run in the AJAX path
	 * that swaps tabs.
	 *
	 * Returns the confirmation notice as HTML instead of echoing it, so the caller can
	 * place it inside the page wrapper rather than above it.
	 */
	public static function handle_post(): string {
		return ( new self() )->maybe_handle_post();
	}

	/**
	 * @return string Notice HTML, or '' when there was nothing to handle.
	 */
	private function maybe_handle_post(): string {
		$action = isset( $_POST['ifs_deploy_action'] ) ? sanitize_key( wp_unslash( $_POST['ifs_deploy_action'] ) ) : '';
		if ( '' === $action ) {
			return '';
		}

		if ( 'save_settings' === $action ) {
			check_admin_referer( 'ifs_deploy_save_settings' );

			$role = isset( $_POST['role'] ) ? sanitize_text_field( wp_unslash( $_POST['role'] ) ) : Config::ROLE_STAGING;
			Config::set_role( $role );

			// Only write the remote details when the form actually submitted them.
			// They are hidden for the Production role, and blindly saving would wipe
			// a working configuration the moment someone flipped the role.
			if ( isset( $_POST['remote_url'] ) || isset( $_POST['remote_api_key'] ) || isset( $_POST['remote_secret_key'] ) ) {
				$submitted = isset( $_POST['remote_url'] ) ? esc_url_raw( wp_unslash( $_POST['remote_url'] ) ) : '';
				$check     = Config::validate_remote_url( $submitted );

				/*
				 * A rejected URL stops the WHOLE remote block from being written — the
				 * keys are not saved either.
				 *
				 * Storing the keys against a URL we refused would leave a half-configured
				 * connection that looks saved and cannot work. Nothing is changed, the old
				 * configuration keeps working, and the reason is stated.
				 */
				if ( ! $check['ok'] ) {
					return $this->notice(
						sprintf(
							/* translators: %s: reason the URL was refused */
							__( 'Site role saved, but the Production connection was NOT changed: %s', 'ifs-deploy' ),
							$check['error']
						)
					);
				}

				$before = Config::remote();

				Config::set_remote(
					$check['url'],
					isset( $_POST['remote_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['remote_api_key'] ) ) : '',
					isset( $_POST['remote_secret_key'] ) ? sanitize_text_field( wp_unslash( $_POST['remote_secret_key'] ) ) : ''
				);

				/*
				 * A CHANGED connection may be a different Production site, so forget what the
				 * old one was known to support (SECURITY.md H-3).
				 *
				 * `Protocol::peer()` is a one-way ratchet on purpose: nothing on the wire may
				 * talk this site back down to v1. Re-pointing at another server is the one
				 * legitimate reason to clear it, and this is an authenticated administrator
				 * acting deliberately — not an attacker stripping a header. Left set, the first
				 * request to an older Production would be signed v2 and come back as a signature
				 * failure with nothing on screen to explain it.
				 *
				 * Only on an actual change — re-saving the same values must not reset it.
				 */
				$after = Config::remote();

				if ( $before !== $after ) {
					Protocol::forget_peer();
				}
			}

			return $this->notice( __( 'Settings saved.', 'ifs-deploy' ) );
		}

		/*
		 * REGENERATE CREDENTIALS.
		 *
		 * The button, its confirm dialog and its nonce field all existed; the handler did not.
		 * So the one documented way to rotate a leaked shared secret silently did nothing —
		 * the page reloaded, the keys were unchanged, and nothing said so. The audit itself
		 * asserted this worked (SECURITY.md M-7), which made it wrong as well as missing.
		 *
		 * Rotation is the whole remedy if a secret is exposed, so it has to actually rotate.
		 */
		if ( 'regenerate' === $action ) {
			check_admin_referer( 'ifs_deploy_regenerate' );

			Credentials::regenerate();

			/*
			 * Recorded because it is a security-relevant administrative action, and because
			 * it explains an outage that is otherwise baffling: every deploy starts failing
			 * signature verification the moment this runs, until the new keys are pasted into
			 * Staging. WARNING rather than info for exactly that reason.
			 *
			 * No keys in the context. DebugLog redacts `dpk_`/`dps_` values anyway, but the
			 * right place to read a secret is the field on this screen, not a log.
			 */
			DebugLog::warning(
				'API credentials were regenerated on this site. Any Staging site still holding the old keys will be refused until it is updated.',
				array(
					'by'   => (string) wp_get_current_user()->user_login,
					'next' => 'Copy the new API key and secret into the Staging site under IFS Deploy → Settings → Connection Settings.',
				)
			);

			/*
			 * Deliberately NOT touching `ifs_deploy_peer_protocol`.
			 *
			 * That option records what the site this one PUSHES TO can do, and it is keyed to
			 * nothing in these credentials — a receiver's own identity and a sender's memory of
			 * its peer are separate facts. Clearing it here would reset a proven capability for
			 * no reason. `Config::set_remote()` handles the case that genuinely warrants it.
			 */

			return $this->notice(
				__( 'New credentials issued. The previous API key and secret no longer work — copy the new ones below into the Staging site to restore the connection.', 'ifs-deploy' )
			);
		}

		if ( 'save_roles' === $action ) {
			check_admin_referer( 'ifs_deploy_save_roles' );

			$submitted = isset( $_POST['roles'] ) ? wp_unslash( $_POST['roles'] ) : array();
			Access::save( is_array( $submitted ) ? $submitted : array() );

			// Empty pickers submit nothing at all, so absent means "cleared".
			Access::save_users(
				isset( $_POST['dp_users_allow'] ) ? (array) wp_unslash( $_POST['dp_users_allow'] ) : array(),
				isset( $_POST['dp_users_block'] ) ? (array) wp_unslash( $_POST['dp_users_block'] ) : array(),
				isset( $_POST['dp_users_allow_caps'] ) ? (array) wp_unslash( $_POST['dp_users_allow_caps'] ) : array()
			);

			return $this->notice( __( 'Role settings saved.', 'ifs-deploy' ) );
		}

		if ( 'save_logs' === $action ) {
			check_admin_referer( 'ifs_deploy_save_logs' );

			$days = LogRetention::set_days(
				isset( $_POST['log_retention_days'] ) ? (int) wp_unslash( $_POST['log_retention_days'] ) : LogRetention::DEFAULT_DAYS
			);

			// set_trusted_header() ignores anything outside its fixed list, so an arbitrary
			// header name can never be pointed at.
			ClientIp::set_trusted_header(
				isset( $_POST['trusted_ip_header'] ) ? sanitize_key( wp_unslash( (string) $_POST['trusted_ip_header'] ) ) : ''
			);

			update_option( ClientIp::OPTION_ANONYMISE, ! empty( $_POST['anonymise_ips'] ) );

			// An unchecked box posts nothing at all, so absent means off — which is the
			// default and the safe direction here.
			ApiLog::set_logs_duplicates( ! empty( $_POST['log_duplicates'] ) );

			if ( isset( $_POST['content_firewall'] ) ) {
				ContentFirewall::set_mode( sanitize_key( wp_unslash( (string) $_POST['content_firewall'] ) ) );
			}

			// Address rules. `save()` validates each entry and reports back anything it
			// could not parse — a silently dropped allow-list entry is how a pair locks
			// itself out without the admin ever seeing why.
			$rules = array();
			foreach ( array( 'ip_allow' => IpAccess::OPTION_ALLOW, 'ip_block' => IpAccess::OPTION_BLOCK ) as $field => $option ) {
				$raw     = isset( $_POST[ $field ] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST[ $field ] ) ) : '';
				$rules[] = IpAccess::save( $option, $raw );
			}

			$rejected = array_merge( ...array_column( $rules, 'rejected' ) );

			// A rejected address rule is reported first and on its own. It is the one thing
			// on this screen that silently breaks deploys, so it must not be buried behind
			// "Settings saved."
			if ( ! empty( $rejected ) ) {
				return $this->notice(
					sprintf(
						/* translators: %s: comma-separated list of rejected entries */
						__( 'Log settings saved, but these address entries were not understood and have NOT been stored: %s. Use a single address (203.0.113.9) or a CIDR range (198.51.100.0/24).', 'ifs-deploy' ),
						implode( ', ', array_slice( $rejected, 0, 10 ) )
					)
				);
			}

			// Apply it straight away rather than waiting up to a day for the cron run —
			// someone who just shortened the window expects to see the effect.
			$removed = LogRetention::purge();
			$total   = array_sum( $removed );

			if ( $total > 0 ) {
				return $this->notice(
					sprintf(
						/* translators: 1: event log entries removed, 2: deployment records removed */
						__( 'Log settings saved. Purged %1$d log entries and %2$d deployment records.', 'ifs-deploy' ),
						$removed['events'],
						$removed['deployments']
					)
				);
			}

			return $this->notice(
				0 === $days
					? __( 'Log settings saved. Nothing will be purged automatically.', 'ifs-deploy' )
					: __( 'Log settings saved. Nothing was old enough to purge.', 'ifs-deploy' )
			);
		}

		return '';
	}

	/**
	 * A checkbox drawn as a switch.
	 *
	 * The input keeps its name, value and checked state exactly as before — this is
	 * presentation only, so what the form posts is unchanged.
	 *
	 * @param string $label Accessible name. Rendered visibly when $visible_label is true,
	 *                      otherwise attached with aria-label (the role matrix labels its
	 *                      columns in the header instead).
	 */
	private function toggle( string $name, bool $checked, bool $disabled, string $label, bool $visible_label = false ): string {
		return sprintf(
			'<label class="dp-toggle%1$s">'
				. '<input type="checkbox" name="%2$s" value="1"%3$s%4$s%5$s />'
				. '<span class="dp-toggle-track" aria-hidden="true"><span class="dp-toggle-knob"></span></span>'
				. '%6$s'
				. '</label>',
			$disabled ? ' is-disabled' : '',
			esc_attr( $name ),
			checked( $checked, true, false ),
			$disabled ? ' disabled' : '',
			$visible_label ? '' : sprintf( ' aria-label="%s"', esc_attr( $label ) ),
			$visible_label ? '<span class="dp-toggle-label">' . esc_html( $label ) . '</span>' : ''
		);
	}

	/**
	 * The result of a save, handed to the SAME toast every other action uses.
	 *
	 * ── WHY THIS IS NOT A wp-admin NOTICE ANY MORE ─────────────────────────────────
	 *
	 * Saving here posts a real form, so the outcome used to be a `notice notice-success`
	 * printed into the page — which meant one part of the plugin reported itself in
	 * WordPress's voice while every other action reported itself in the plugin's. Two
	 * visual languages for the same kind of statement, and the wp-admin one is the easier
	 * of the two to scroll past.
	 *
	 * It cannot be localised into the script instead: `Assets::enqueue()` runs on
	 * `admin_enqueue_scripts`, which is long finished by the time this save happens
	 * during render. So the message is emitted as a marker element and `admin.js` turns
	 * it into a toast on load — which also works for the AJAX tab loader, where there is
	 * no page load at all.
	 *
	 * @param string $message What happened.
	 * @param bool   $is_error Failures use the same channel; only the styling differs.
	 */
	private function notice( string $message, bool $is_error = false ): string {
		return sprintf(
			'<div class="ifs-deploy-flash" data-error="%1$d" hidden>%2$s</div>',
			$is_error ? 1 : 0,
			esc_html( $message )
		);
	}
}
