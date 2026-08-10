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
				'label'       => __( 'Access IFS Deploy', 'ifs-deploy' ),
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
}
