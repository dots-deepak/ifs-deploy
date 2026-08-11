<?php
declare(strict_types=1);

function __( $s, $d = '' ) { return $s; }
function add_filter( ...$a ) {}
function absint( $v ) { return abs( (int) $v ); }
function get_option( $n, $d = false ) { return $GLOBALS['dp_opt'][ $n ] ?? $d; }
function update_option( $n, $v, $a = null ) { $GLOBALS['dp_opt'][ $n ] = $v; return true; }
function get_current_user_id() { return 7; }
function current_user_can( $c ) { return ! empty( $GLOBALS['dp_current'][ $c ] ); }

class WP_User {
	public $roles = array(); public $ID = 0;
	public function __construct( array $roles ) { $this->roles = $roles; }
}

class FakeRole {
	public $capabilities = array();
	public function __construct( array $caps ) { $this->capabilities = $caps; }
}

class FakeRoles {
	public function get_names() {
		return array( 'administrator' => 'Administrator', 'editor' => 'Editor', 'author' => 'Author', 'subscriber' => 'Subscriber' );
	}
	public function get_role( $slug ) {
		$caps = array(
			'administrator' => array( 'manage_options' => true ),
			'editor'        => array( 'edit_others_posts' => true ),
			'author'        => array( 'publish_posts' => true ),
			'subscriber'    => array( 'read' => true ),
		);
		return isset( $caps[ $slug ] ) ? new FakeRole( $caps[ $slug ] ) : null;
	}
}
function wp_roles() { return new FakeRoles(); }

$GLOBALS['dp_opt'] = array();
require __DIR__ . '/../src/Support/Access.php';
use IfsDeploy\Support\Access;

$pass = 0; $fail = 0;
function ok( string $n, bool $c ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS  $n\n"; } else { $fail++; echo "FAIL  $n\n"; } }

function user( int $id, array $roles ): WP_User { $u = new WP_User( $roles ); $u->ID = $id; return $u; }
function caps_for( array $base, WP_User $user ): array {
	$r = new ReflectionClass( Access::class );
	// grant() is public static
	return Access::grant( $base, array(), array(), $user );
}

echo "=== default state: nothing granted to anyone ===\n";
$GLOBALS['dp_opt'] = array();

$admin = caps_for( array( 'manage_options' => true ), new WP_User( array( 'administrator' ) ) );
ok( 'administrator gets access',    ! empty( $admin[ Access::CAP_ACCESS ] ) );
ok( 'administrator gets view_all',  ! empty( $admin[ Access::CAP_VIEW_ALL ] ) );
ok( 'administrator gets deploy',    ! empty( $admin[ Access::CAP_DEPLOY ] ) );
ok( 'administrator gets rollback',  ! empty( $admin[ Access::CAP_ROLLBACK ] ) );

$editor = caps_for( array( 'edit_others_posts' => true ), new WP_User( array( 'editor' ) ) );
ok( 'editor gets NOTHING by default', empty( $editor[ Access::CAP_ACCESS ] ) && empty( $editor[ Access::CAP_DEPLOY ] ) );

echo "\n=== grant editor access only ===\n";
Access::save( array( 'editor' => array( Access::CAP_ACCESS => '1' ) ) );
$editor = caps_for( array( 'edit_others_posts' => true ), new WP_User( array( 'editor' ) ) );
ok( 'editor has access',        ! empty( $editor[ Access::CAP_ACCESS ] ) );
ok( 'editor cannot see all',    empty( $editor[ Access::CAP_VIEW_ALL ] ) );
ok( 'editor cannot deploy',     empty( $editor[ Access::CAP_DEPLOY ] ) );
ok( 'editor cannot roll back',  empty( $editor[ Access::CAP_ROLLBACK ] ) );

echo "\n=== access is a prerequisite ===\n";
Access::save( array( 'author' => array( Access::CAP_DEPLOY => '1', Access::CAP_VIEW_ALL => '1' ) ) );
$author = caps_for( array( 'publish_posts' => true ), new WP_User( array( 'author' ) ) );
ok( 'deploy without access grants nothing',   empty( $author[ Access::CAP_DEPLOY ] ) );
ok( 'view_all without access grants nothing', empty( $author[ Access::CAP_VIEW_ALL ] ) );

echo "\n=== full grant ===\n";
Access::save( array( 'editor' => array( Access::CAP_ACCESS => '1', Access::CAP_VIEW_ALL => '1', Access::CAP_DEPLOY => '1', Access::CAP_ROLLBACK => '1' ) ) );
$editor = caps_for( array( 'edit_others_posts' => true ), new WP_User( array( 'editor' ) ) );
ok( 'editor has all four', ! empty( $editor[ Access::CAP_ACCESS ] ) && ! empty( $editor[ Access::CAP_VIEW_ALL ] )
	&& ! empty( $editor[ Access::CAP_DEPLOY ] ) && ! empty( $editor[ Access::CAP_ROLLBACK ] ) );

echo "\n=== save() hardening ===\n";
Access::save( array( 'administrator' => array( Access::CAP_ACCESS => '' ), 'not_a_role' => array( Access::CAP_ACCESS => '1' ) ) );
$stored = $GLOBALS['dp_opt']['ifs_deploy_roles'];
ok( 'administrator is never stored',  ! array_key_exists( 'administrator', $stored ) );
ok( 'unknown role is rejected',       ! array_key_exists( 'not_a_role', $stored ) );

// Even with the option cleared for administrator, they still get everything.
$admin = caps_for( array( 'manage_options' => true ), new WP_User( array( 'administrator' ) ) );
ok( 'administrator cannot be locked out', ! empty( $admin[ Access::CAP_ACCESS ] ) );

echo "\n=== multi-role users take the union ===\n";
Access::save( array( 'editor' => array( Access::CAP_ACCESS => '1' ), 'author' => array( Access::CAP_ACCESS => '1', Access::CAP_DEPLOY => '1' ) ) );
$both = caps_for( array( 'read' => true ), new WP_User( array( 'editor', 'author' ) ) );
ok( 'union of both roles', ! empty( $both[ Access::CAP_ACCESS ] ) && ! empty( $both[ Access::CAP_DEPLOY ] ) );

echo "\n=== non-user input is passed through untouched ===\n";
$out = Access::grant( array( 'read' => true ), array(), array(), null );
ok( 'null user unchanged', $out === array( 'read' => true ) );
$out = Access::grant( 'not-an-array', array(), array(), null );
ok( 'non-array allcaps unchanged', 'not-an-array' === $out );

echo "\n=== scope ===\n";
$GLOBALS['dp_current'] = array( Access::CAP_VIEW_ALL => true );
ok( 'sees_all -> scope null', null === Access::scope_user_id() );
$GLOBALS['dp_current'] = array();
ok( 'no view_all -> scope own id', 7 === Access::scope_user_id() );

echo "\n=== per-user OVERRIDES ===\n";

// Case 1: "give only one editor access" — Editor role has nothing.
$GLOBALS['dp_opt'] = array();
Access::save( array() );                                   // no role permissions at all
Access::save_users( array( 42 ), array(), array( Access::CAP_ACCESS => '1', Access::CAP_DEPLOY => '1' ) );

$chosen = caps_for( array( 'edit_others_posts' => true ), user( 42, array( 'editor' ) ) );
$other  = caps_for( array( 'edit_others_posts' => true ), user( 43, array( 'editor' ) ) );
ok( 'named editor gets access',            ! empty( $chosen[ Access::CAP_ACCESS ] ) );
ok( 'named editor gets the ticked deploy', ! empty( $chosen[ Access::CAP_DEPLOY ] ) );
ok( 'named editor does NOT get view_all',  empty( $chosen[ Access::CAP_VIEW_ALL ] ) );
ok( 'other editors still get nothing',     empty( $other[ Access::CAP_ACCESS ] ) );

// Case 2: "all Editors, except one" — role grants it, one user blocked.
$GLOBALS['dp_opt'] = array();
Access::save( array( 'editor' => array( Access::CAP_ACCESS => '1', Access::CAP_DEPLOY => '1' ) ) );
Access::save_users( array(), array( 43 ), array() );

$allowed = caps_for( array( 'edit_others_posts' => true ), user( 42, array( 'editor' ) ) );
$blocked = caps_for( array( 'edit_others_posts' => true ), user( 43, array( 'editor' ) ) );
ok( 'editors in general keep access',   ! empty( $allowed[ Access::CAP_ACCESS ] ) );
ok( 'blocked editor loses access',      empty( $blocked[ Access::CAP_ACCESS ] ) );
ok( 'blocked editor loses deploy too',  empty( $blocked[ Access::CAP_DEPLOY ] ) );

echo "\n=== precedence and hardening ===\n";

// Block must win over an individual allow.
$GLOBALS['dp_opt'] = array();
Access::save( array() );
Access::save_users( array( 44 ), array( 44 ), array( Access::CAP_ACCESS => '1' ) );
$both = caps_for( array( 'read' => true ), user( 44, array( 'subscriber' ) ) );
ok( 'block beats allow for the same user', empty( $both[ Access::CAP_ACCESS ] ) );
$stored = $GLOBALS['dp_opt']['ifs_deploy_users'];
ok( 'contradiction is not even stored',    ! in_array( 44, $stored['allow'], true ) );

// An administrator can never be blocked out.
$GLOBALS['dp_opt'] = array();
Access::save_users( array(), array( 1 ), array() );
$admin = caps_for( array( 'manage_options' => true ), user( 1, array( 'administrator' ) ) );
ok( 'administrator survives being blocked', ! empty( $admin[ Access::CAP_ACCESS ] ) );

// Allow adds to the role rather than replacing it.
$GLOBALS['dp_opt'] = array();
Access::save( array( 'editor' => array( Access::CAP_ACCESS => '1' ) ) );
Access::save_users( array( 42 ), array(), array( Access::CAP_ROLLBACK => '1' ) );
$sum = caps_for( array( 'edit_others_posts' => true ), user( 42, array( 'editor' ) ) );
ok( 'role access + individual rollback both present', ! empty( $sum[ Access::CAP_ACCESS ] ) && ! empty( $sum[ Access::CAP_ROLLBACK ] ) );

// Empty tick list implies base access, so a named user is never a no-op.
$GLOBALS['dp_opt'] = array();
Access::save( array() );
Access::save_users( array( 42 ), array(), array() );
$implied = caps_for( array( 'read' => true ), user( 42, array( 'subscriber' ) ) );
ok( 'allow with nothing ticked still grants access', ! empty( $implied[ Access::CAP_ACCESS ] ) );

// Garbage ids are discarded.
$GLOBALS['dp_opt'] = array();
Access::save_users( array( 0, -5, 'abc', 42, 42 ), array(), array() );
$stored = $GLOBALS['dp_opt']['ifs_deploy_users'];
ok( 'ids are cleaned and de-duplicated', array( 42 ) === $stored['allow'] );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
