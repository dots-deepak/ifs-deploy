<?php
declare(strict_types=1);

namespace IfsDeploy\Support;

use WP_User;

/**
 * Role-based access control for IFS Deploy.
 *
 * Deliberately implemented with VIRTUAL capabilities granted through the
 * `user_has_cap` filter, driven by an option — not by calling
 * WP_Role::add_cap()/remove_cap(). Writing capabilities onto WordPress's role
 * objects mutates global state that survives deactivation, is shared with every
 * other plugin, and is easy to get wrong in a way that strands a site. A filter is
 * evaluated per request and disappears the moment the plugin does.
 *
 * Defaults are admin-only, exactly matching the previous `manage_options`-gated
 * behaviour: no one who could not see IFS Deploy before can see it now. Access is
 * something an administrator opts a role into.
 *
 * Administrators can never be locked out — anyone with `manage_options` is granted
 * every IFS Deploy capability regardless of what the option says.
 */
final class Access {

	/** See IFS Deploy at all (own changes only, unless CAP_VIEW_ALL). */
	public const CAP_ACCESS = 'ifs_deploy_access';

	/** See — and act on — changes made by other users. */
	public const CAP_VIEW_ALL = 'ifs_deploy_view_all';

	/** Push changes to Production. */
	public const CAP_DEPLOY = 'ifs_deploy_deploy';

	/** Roll a deployment back. */
	public const CAP_ROLLBACK = 'ifs_deploy_rollback';

	/** Connection + role settings, logs, diagnostics. Administrators only. */
	public const CAP_MANAGE = 'manage_options';

	/**
	 * Compare & Sync, Settings, and Logs & Diagnostics.
	 *
	 * ── WHY THESE THREE ARE SEPARATE FROM `manage_options` ─────────────────────────
	 *
	 * They were administrator-only, which on a site with several administrators means
	 * everyone. Between them they expose the shared secret, the API access log, the
	 * connection to the live site, and a screen that can overwrite Production wholesale —
	 * so a team can reasonably want them held to named people rather than to a role.
	 *
	 * ── AND WHY IT NARROWS RATHER THAN GRANTS ─────────────────────────────────────
	 *
	 * `manage_options` is still required on top of being named. The list can only ever take
	 * access away, never hand it out: otherwise putting a subscriber's id in `wp-config.php`
	 * would give them the screen that displays the shared secret, which is the opposite of
	 * what a restriction is for.
	 */
	public const CAP_RESTRICTED = 'ifs_deploy_restricted';

	/**
	 * Constant naming the users allowed on those screens. Comma-separated ids.
	 *
	 *     define( 'IFS_DEPLOY_ADMIN_USERS', '1,7' );
	 *
	 * ── WHY wp-config.php AND NOT A FILE IN THE PLUGIN ────────────────────────────
	 *
	 * A config file inside the plugin folder is destroyed every time the plugin is
	 * updated — the folder is replaced wholesale — so the restriction would silently lift
	 * on each release with nothing to say it had. `wp-config.php` survives updates, and
	 * sits outside the database, so restoring a backup or compromising an administrator
	 * account cannot rewrite the list.
	 */
	public const USERS_CONSTANT = 'IFS_DEPLOY_ADMIN_USERS';

	private const OPTION = 'ifs_deploy_roles';

	private const OPTION_USERS = 'ifs_deploy_users';

	/** @var array<string,array<string,bool>>|null */
	private static ?array $cache = null;

	/** @var array{allow:int[],block:int[],allow_caps:array<string,bool>}|null */
	private static ?array $users_cache = null;

	/**
	 * The grantable capabilities, with labels for the settings screen.
	 *
	 * CAP_MANAGE is absent on purpose: settings and diagnostics stay with
	 * administrators, so a lower role can never widen its own permissions.
	 *
	 * @return array<string,array{label:string,description:string}>
	 */
	public static function grantable(): array {
		return array(
			self::CAP_ACCESS   => array(
				'label'       => __( 'Access Copperleaf Deploy', 'ifs-deploy' ),
				'description' => __( 'See the Dashboard, their own pending changes, and their own deployment history.', 'ifs-deploy' ),
			),
			self::CAP_VIEW_ALL => array(
				'label'       => __( 'See all users’ changes', 'ifs-deploy' ),
				'description' => __( 'Without this, a user sees only the changes they made themselves.', 'ifs-deploy' ),
			),
			self::CAP_DEPLOY   => array(
				'label'       => __( 'Push to Production', 'ifs-deploy' ),
				'description' => __( 'Deploy pending changes. Limited to their own changes unless they can see all.', 'ifs-deploy' ),
			),
			self::CAP_ROLLBACK => array(
				'label'       => __( 'Roll back', 'ifs-deploy' ),
				'description' => __( 'Restore the previous version of a deployment on Production.', 'ifs-deploy' ),
			),
		);
	}

	/**
	 * Hook the capability filter. Called on every request from Plugin::boot().
	 */
	public static function register(): void {
		add_filter( 'user_has_cap', array( __CLASS__, 'grant' ), 10, 4 );
	}

	/**
	 * Grant virtual capabilities to the user being checked.
	 *
	 * Must never call current_user_can()/user_can() — that would re-enter this same
	 * filter and recurse. The administrator test therefore reads $allcaps directly.
	 *
	 * @param array<string,bool> $allcaps
	 * @param string[]           $caps
	 * @param array              $args
	 * @param WP_User|mixed      $user
	 *
	 * @return array<string,bool>
	 */
	public static function grant( $allcaps, $caps, $args, $user ) {
		if ( ! is_array( $allcaps ) ) {
			return $allcaps;
		}

		// Administrators always have everything, so they cannot lock themselves out
		// of the screen that hands out permissions.
		if ( ! empty( $allcaps[ self::CAP_MANAGE ] ) ) {
			foreach ( array_keys( self::grantable() ) as $cap ) {
				$allcaps[ $cap ] = true;
			}

			/*
			 * The three restricted screens are the ONE thing an administrator does not get
			 * automatically — that is the entire point of them.
			 *
			 * Granted on top of `manage_options`, never instead of it, so the list can only
			 * narrow. `$user` can be absent here (WordPress passes it, but the filter's
			 * contract does not guarantee an object), which is why the id is read defensively
			 * rather than assumed.
			 */
			$allcaps[ self::CAP_RESTRICTED ] = self::may_use_restricted_screens(
				$user instanceof WP_User ? (int) $user->ID : 0
			);

			return $allcaps;
		}

		if ( ! $user instanceof WP_User ) {
			return $allcaps;
		}

		$overrides = self::users();
		$user_id   = (int) $user->ID;

		// A blocked user gets nothing, whatever their role allows. Checked before the
		// role rules so "this role, except that person" works.
		if ( in_array( $user_id, $overrides['block'], true ) ) {
			foreach ( array_keys( self::grantable() ) as $cap ) {
				unset( $allcaps[ $cap ] );
			}

			return $allcaps;
		}

		$permissions = self::permissions();

		foreach ( (array) $user->roles as $role ) {
			$granted = $permissions[ (string) $role ] ?? array();

			foreach ( array_keys( self::grantable() ) as $cap ) {
				if ( ! empty( $granted[ $cap ] ) ) {
					$allcaps[ $cap ] = true;
				}
			}
		}

		// An individually allowed user ADDS to whatever their role already gave them,
		// so naming one person never takes anything away.
		if ( in_array( $user_id, $overrides['allow'], true ) ) {
			foreach ( array_keys( self::grantable() ) as $cap ) {
				if ( ! empty( $overrides['allow_caps'][ $cap ] ) ) {
					$allcaps[ $cap ] = true;
				}
			}
		}

		// Every other capability is meaningless without base access, so a role that
		// was given only "push" cannot reach anything.
		if ( empty( $allcaps[ self::CAP_ACCESS ] ) ) {
			foreach ( array_keys( self::grantable() ) as $cap ) {
				unset( $allcaps[ $cap ] );
			}
		}

		return $allcaps;
	}

	/**
	 * Stored role → capability map.
	 *
	 * @return array<string,array<string,bool>>
	 */
	public static function permissions(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$clean = array();
		foreach ( $stored as $role => $caps ) {
			if ( ! is_array( $caps ) ) {
				continue;
			}

			$clean[ (string) $role ] = array();
			foreach ( array_keys( self::grantable() ) as $cap ) {
				$clean[ (string) $role ][ $cap ] = ! empty( $caps[ $cap ] );
			}
		}

		self::$cache = $clean;

		return self::$cache;
	}

	/**
	 * Persist a submitted role → capability map.
	 *
	 * @param array<string,array<string,mixed>> $submitted Raw form input.
	 */
	public static function save( array $submitted ): void {
		$roles = array_keys( self::roles() );
		$clean = array();

		foreach ( $submitted as $role => $caps ) {
			$role = (string) $role;

			// Never store a role WordPress does not have, and never store the
			// administrator: it is granted everything unconditionally.
			if ( ! in_array( $role, $roles, true ) || self::is_admin_role( $role ) ) {
				continue;
			}

			$caps  = is_array( $caps ) ? $caps : array();
			$entry = array();
			foreach ( array_keys( self::grantable() ) as $cap ) {
				$entry[ $cap ] = ! empty( $caps[ $cap ] );
			}

			$clean[ $role ] = $entry;
		}

		update_option( self::OPTION, $clean, false );
		self::$cache = null;
	}

	/**
	 * Per-user overrides that sit on top of the role rules.
	 *
	 * `block` wins over everything except `manage_options` — that is what makes
	 * "every Editor except this one" expressible. `allow` adds `allow_caps` to
	 * whatever the user's role already granted, so naming someone never removes
	 * anything.
	 *
	 * @return array{allow:int[],block:int[],allow_caps:array<string,bool>}
	 */
	public static function users(): array {
		if ( null !== self::$users_cache ) {
			return self::$users_cache;
		}

		$stored = get_option( self::OPTION_USERS, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$allow_caps = array();
		foreach ( array_keys( self::grantable() ) as $cap ) {
			$allow_caps[ $cap ] = ! empty( $stored['allow_caps'][ $cap ] );
		}

		// A user granted access individually with nothing ticked would be pointless,
		// so base access is implied.
		if ( ! array_filter( $allow_caps ) ) {
			$allow_caps[ self::CAP_ACCESS ] = true;
		}

		self::$users_cache = array(
			'allow'      => self::clean_ids( $stored['allow'] ?? array() ),
			'block'      => self::clean_ids( $stored['block'] ?? array() ),
			'allow_caps' => $allow_caps,
		);

		return self::$users_cache;
	}

	/**
	 * Persist the per-user overrides.
	 *
	 * @param int[]                $allow
	 * @param int[]                $block
	 * @param array<string,mixed>  $allow_caps
	 */
	public static function save_users( array $allow, array $block, array $allow_caps ): void {
		$allow = self::clean_ids( $allow );
		$block = self::clean_ids( $block );

		// Blocking wins at evaluation time; dropping the id from `allow` as well keeps
		// the stored state from contradicting itself on screen.
		$allow = array_values( array_diff( $allow, $block ) );

		$caps = array();
		foreach ( array_keys( self::grantable() ) as $cap ) {
			$caps[ $cap ] = ! empty( $allow_caps[ $cap ] );
		}

		update_option(
			self::OPTION_USERS,
			array(
				'allow'      => $allow,
				'block'      => $block,
				'allow_caps' => $caps,
			),
			false
		);

		self::$users_cache = null;
	}

	/**
	 * Positive, unique user ids.
	 *
	 * Deliberately not absint(): that maps -5 to 5, quietly turning a nonsense id
	 * into a valid id for a *different* user. Non-numeric and non-positive values are
	 * dropped instead.
	 *
	 * @param mixed $ids
	 *
	 * @return int[]
	 */
	private static function clean_ids( $ids ): array {
		$out = array();

		foreach ( (array) $ids as $id ) {
			if ( ! is_numeric( $id ) ) {
				continue;
			}

			$id = (int) $id;
			if ( $id > 0 ) {
				$out[] = $id;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * All WordPress roles, slug => display name.
	 *
	 * @return array<string,string>
	 */
	public static function roles(): array {
		$roles = wp_roles();

		return is_object( $roles ) ? (array) $roles->get_names() : array();
	}

	/**
	 * Roles that hold `manage_options` get everything implicitly, and are shown as
	 * such rather than as editable rows.
	 */
	public static function is_admin_role( string $role ): bool {
		$roles = wp_roles();
		$object = is_object( $roles ) ? $roles->get_role( $role ) : null;

		return ( null !== $object && ! empty( $object->capabilities[ self::CAP_MANAGE ] ) );
	}

	/**
	 * Is this user allowed on Compare & Sync, Settings and Logs & Diagnostics?
	 *
	 * Only ever called for somebody who already holds `manage_options`, so a `true` here
	 * widens nothing — it decides whether an administrator keeps access they would
	 * otherwise have had.
	 *
	 * ── NO LIST MEANS NOBODY ───────────────────────────────────────────────────────
	 *
	 * Deny by default. An administrator reaches these screens only by being named in
	 * `wp-config.php`; an undefined, empty or unparseable constant lets nobody in.
	 *
	 * The opposite default — "not set" meaning "everyone" — is the more forgiving one and
	 * was what this shipped with first. It is wrong for what these screens do. They hold
	 * the connection credentials, the API log and the button that pushes to the live site,
	 * and a restriction that has to be switched ON is a restriction that is OFF on every
	 * site where someone forgot, lost the line in a wp-config rewrite, or restored an older
	 * copy of the file. Failing open there means failing open silently, at exactly the
	 * moment the protection was supposed to apply.
	 *
	 * ── AND WHY THAT IS NOT A LOCKOUT ──────────────────────────────────────────────
	 *
	 * Settings is one of the screens being hidden, so nobody can grant themselves access
	 * from inside wp-admin — by design, and the reason the list lives in a file the
	 * database cannot reach. The way back in is always the same one line in
	 * `wp-config.php`, and `Admin\RestrictionNotice` prints it, with the reader's own user
	 * id already filled in, on the screens they can still reach. A hidden tab with no
	 * explanation would be indistinguishable from a broken plugin; this one explains
	 * itself.
	 */
	public static function may_use_restricted_screens( int $user_id ): bool {
		$allowed = self::restricted_users();

		if ( empty( $allowed ) ) {
			return false;
		}

		return $user_id > 0 && in_array( $user_id, $allowed, true );
	}

	/**
	 * The user ids named in `wp-config.php`, or an empty list when unset.
	 *
	 * @return int[]
	 */
	public static function restricted_users(): array {
		$raw = defined( self::USERS_CONSTANT ) ? constant( self::USERS_CONSTANT ) : '';

		// Accepts a comma-separated string — the documented form — or an array, because
		// somebody will eventually write one and being strict about it helps nobody.
		$parts = is_array( $raw ) ? $raw : explode( ',', (string) $raw );

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $parts ) ) ) );

		/**
		 * Filter the users allowed on the restricted screens.
		 *
		 * The escape hatch for moving the list out of `wp-config.php` — into an mu-plugin,
		 * say — without editing this plugin. Returning an empty array allows NOBODY onto
		 * the restricted screens, the same as leaving the constant undefined.
		 *
		 * @param int[] $ids Ids parsed from the constant.
		 */
		$ids = (array) apply_filters( 'ifs_deploy_admin_users', $ids );

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		if ( empty( $ids ) && defined( self::USERS_CONSTANT ) && '' !== (string) constant( self::USERS_CONSTANT ) ) {
			DebugLog::warning(
				'IFS_DEPLOY_ADMIN_USERS is set but names no usable user ids, so nobody can reach Compare & Sync, Settings or Logs & Diagnostics',
				array(
					'value' => (string) constant( self::USERS_CONSTANT ),
					'fix'   => 'Use numeric user ids separated by commas, e.g. 1,7',
				)
			);
		}

		return $ids;
	}

	/**
	 * Can the current user see and act on everyone's changes?
	 */
	public static function sees_all(): bool {
		return current_user_can( self::CAP_VIEW_ALL );
	}

	/**
	 * The user id to scope queries to, or null for "everyone".
	 *
	 * This is the single place the own-vs-all rule is decided, so no screen can
	 * accidentally show more than it should.
	 */
	public static function scope_user_id(): ?int {
		return self::sees_all() ? null : get_current_user_id();
	}

	/**
	 * May the current user ACT on something another user owns?
	 *
	 * ── ONE RULE, WHEREVER SOMETHING IS OWNED ──────────────────────────────────────
	 *
	 * Yours, or you are an administrator. That is the whole rule, and it is the same
	 * one `Ajax::queue_ids()` enforces for pushing — written here so pushing and rolling
	 * back cannot drift apart, which is exactly what had happened: a pending change could
	 * only be pushed by the person who made it, and then ANY user with the rollback
	 * capability could undo the deployment that resulted.
	 *
	 * ── WHY NOT `CAP_VIEW_ALL` ─────────────────────────────────────────────────────
	 *
	 * Because seeing and acting are different powers, and conflating them is the mistake
	 * this rule was rewritten to remove once already. An editor may legitimately need to
	 * review the whole team's work without being able to undo a colleague's deployment —
	 * which restores older content over live pages and is among the least reversible
	 * things this plugin can do.
	 *
	 * The exemption is `manage_options`, a real administrator, and it exists because the
	 * alternative strands work: someone leaves, and a deployment of theirs that turned out
	 * wrong could never be undone by anyone.
	 *
	 * A logged-out request owns nothing — `get_current_user_id()` returns 0, and an owner
	 * id of 0 must never match it.
	 *
	 * @param int $owner_id The user who owns the thing being acted on.
	 */
	public static function may_act_on( int $owner_id ): bool {
		if ( current_user_can( self::CAP_MANAGE ) ) {
			return true;
		}

		$user = get_current_user_id();

		return $user > 0 && $user === $owner_id;
	}
}
