<?php
declare(strict_types=1);

function __( $s, $d = '' ) { return $s; }
$GLOBALS['dp_filters'] = array();

function add_filter( $tag, $cb = null, $p = 10, $a = 1 ) {
	if ( null !== $cb ) {
		$GLOBALS['dp_filters'][ $tag ][] = $cb;
	}
	return true;
}

function remove_restricted_filter() { unset( $GLOBALS['dp_filters']['ifs_deploy_admin_users'] ); }

// `restricted_users()` offers a filter so the list can be moved to an mu-plugin without
// editing the plugin — which is also how these tests drive it, since a constant cannot be
// defined twice in one process.
function apply_filters( $tag, $value, ...$args ) {
	foreach ( $GLOBALS['dp_filters'][ $tag ] ?? array() as $cb ) {
		$value = $cb( $value, ...$args );
	}
	return $value;
}
function absint( $v ) { return abs( (int) $v ); }
function get_option( $n, $d = false ) { return $GLOBALS['dp_opt'][ $n ] ?? $d; }
function update_option( $n, $v, $a = null ) { $GLOBALS['dp_opt'][ $n ] = $v; return true; }
// Variable, so "logged out" and "somebody else" are both reachable. Defaults to 7, which
// is what every test written before this expected.
function get_current_user_id() { return $GLOBALS['dp_user'] ?? 7; }
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

echo "\n=== acting on something SOMEBODY ELSE owns ===\n";
//
// ── THE GAP THIS CLOSES ────────────────────────────────────────────────────────────
//
// Pushing was always the creator's own act: submitted queue rows are narrowed to the ones
// this user made, and only an administrator may opt into the rest. Rolling back had no such
// rule — holding the rollback capability was enough, so any user who could roll back
// anything could roll back EVERYTHING, including a colleague's deployment they were never
// allowed to push in the first place.
//
// One rule now, so the two cannot drift apart again.
$GLOBALS['dp_user'] = 7;

$GLOBALS['dp_current'] = array();
ok( 'my own is mine to act on',            true === Access::may_act_on( 7 ) );
ok( 'someone else\'s is not',              false === Access::may_act_on( 8 ) );

// The exemption is a REAL administrator. It exists because the alternative strands work:
// someone leaves, and a bad deployment of theirs could never be undone by anyone.
$GLOBALS['dp_current'] = array( Access::CAP_MANAGE => true );
ok( 'an administrator may act on anyone\'s', true === Access::may_act_on( 8 ) );

/*
 * NOT `view_all`. Seeing and acting are different powers, and conflating them is the exact
 * mistake this rule was rewritten to remove once already for pushing. An editor may need to
 * review the whole team's work without being able to undo a colleague's deployment — which
 * restores older content over live pages.
 */
$GLOBALS['dp_current'] = array( Access::CAP_VIEW_ALL => true );
ok( 'seeing everything does NOT grant acting on it', false === Access::may_act_on( 8 ) );

// A logged-out request owns nothing, and an owner id of 0 must never match it.
$GLOBALS['dp_current'] = array();
$GLOBALS['dp_user']    = 0;
ok( 'logged out owns nothing',             false === Access::may_act_on( 0 ) );
ok( 'and cannot act on a real user\'s',    false === Access::may_act_on( 7 ) );

$GLOBALS['dp_user'] = 7;

echo "\n=== and the rule is actually applied to rollback ===\n";
//
// Read from source: reaching these needs the whole AJAX stack. What matters is that both
// rollback entry points consult the rule, and that the check is against the DATABASE row
// rather than anything the page supplied.
$ajax_src = (string) php_strip_whitespace( __DIR__ . '/../src/Admin/Ajax.php' );

ok( 'there is a single ownership guard', false !== strpos( $ajax_src, 'function require_own_deployment(' ) );
ok( 'it reads the deployment from the database', (bool) preg_match( '/require_own_deployment\(.*?DeploymentRepository\(\) \)->get\( \$deployment_id \)/s', $ajax_src ) );
ok( 'and decides with the shared rule', (bool) preg_match( '/require_own_deployment\(.*?Access::may_act_on\( \(int\) \$deployment->deployed_by \)/s', $ajax_src ) );

foreach ( array( 'rollback', 'rollback_preview' ) as $method ) {
	if ( ! preg_match( '/function ' . preg_quote( $method, '/' ) . '\(\): void \{(.*?)(?=function [a-z_]+\()/s', $ajax_src, $body ) ) {
		ok( "{$method}() body was located", false );
		continue;
	}

	ok( "{$method}() checks ownership", false !== strpos( $body[1], 'require_own_deployment(' ) );
}

// The preview is guarded too: it renders the previous contents of someone else's pages,
// which is a disclosure in its own right, and a dialog that fills with detail and only then
// refuses at Confirm is worse than one that never opens.
$history_src = (string) php_strip_whitespace( __DIR__ . '/../src/Admin/Pages/HistoryPage.php' );

ok( 'the History screen hides the button too', false !== strpos( $history_src, 'Access::may_act_on( (int) $deployment->deployed_by )' ) );

echo "\n=== the three restricted screens ===\n";
//
// ── WHAT THIS IS ───────────────────────────────────────────────────────────────────
//
// Compare & Sync, Settings and Logs & Diagnostics were administrator-only, which on a site
// with several administrators means everyone. Between them they expose the shared secret,
// the API access log, and a screen that can overwrite Production wholesale — so a team can
// hold them to named people, listed in wp-config.php.
//
// The constant cannot be defined twice in one process, so the list is driven here through
// the filter the plugin offers for exactly that purpose.
$GLOBALS['dp_allowed'] = array();

add_filter( 'ifs_deploy_admin_users', static function ( $ids ) { return $GLOBALS['dp_allowed']; } );

echo "\n--- nothing configured: NOBODY is allowed ---\n";
//
// DENY BY DEFAULT. No list means no one, not everyone. A restriction that has to be switched
// ON is a restriction that is OFF wherever somebody forgot, lost the line in a wp-config
// rewrite, or restored an older copy of the file — failing open at exactly the moment it was
// meant to apply. These screens hold the shared secret, the API log and a button that
// overwrites Production, so the safe direction is closed.
//
// Settings is itself one of the hidden screens, so this cannot be undone from inside
// wp-admin — the point of putting the list in a file the database cannot reach. What stops
// it being a dead end is Admin RestrictionNotice, which prints the exact line to add with
// the reader's own user id already in it. Pinned in tests/render-test.php.
$GLOBALS['dp_allowed'] = array();

ok( 'an unset list allows nobody',     false === Access::may_use_restricted_screens( 7 ) );
ok( 'and a user id of 0 least of all', false === Access::may_use_restricted_screens( 0 ) );

$admin = caps_for( array( 'manage_options' => true ), user( 7, array( 'administrator' ) ) );
ok( 'so not even an administrator gets the screens', empty( $admin[ Access::CAP_RESTRICTED ] ) );

// Shut out of the three screens is NOT shut out of the plugin, or every site without the
// constant would lose pushing the moment it updated.
ok( 'but the administrator keeps plugin access', ! empty( $admin[ Access::CAP_ACCESS ] ) );
ok( 'and keeps pushing',                         ! empty( $admin[ Access::CAP_DEPLOY ] ) );
ok( 'and keeps rollback',                        ! empty( $admin[ Access::CAP_ROLLBACK ] ) );

echo "\n--- a list of named users ---\n";
$GLOBALS['dp_allowed'] = array( 1, 7 );

ok( 'a named user is allowed',         true === Access::may_use_restricted_screens( 7 ) );
ok( 'and the other named one too',     true === Access::may_use_restricted_screens( 1 ) );
ok( 'an unnamed administrator is not', false === Access::may_use_restricted_screens( 9 ) );
ok( 'nor is a logged-out request',     false === Access::may_use_restricted_screens( 0 ) );

$named   = caps_for( array( 'manage_options' => true ), user( 7, array( 'administrator' ) ) );
$unnamed = caps_for( array( 'manage_options' => true ), user( 9, array( 'administrator' ) ) );

ok( 'the named administrator gets the capability', ! empty( $named[ Access::CAP_RESTRICTED ] ) );
ok( 'the unnamed one does not',                    empty( $unnamed[ Access::CAP_RESTRICTED ] ) );

/*
 * AND THE REST OF THE PLUGIN IS UNTOUCHED.
 *
 * The restriction hides three screens; it does not remove somebody from the plugin. An
 * administrator who is not on the list still has Overview, Pending Changes and Deployment
 * History, and can still push and roll back their own work.
 */
ok( 'an unnamed administrator keeps access', ! empty( $unnamed[ Access::CAP_ACCESS ] ) );
ok( 'keeps push',                            ! empty( $unnamed[ Access::CAP_DEPLOY ] ) );
ok( 'and keeps rollback',                    ! empty( $unnamed[ Access::CAP_ROLLBACK ] ) );

echo "\n--- the list narrows, it never grants ---\n";
//
// Being named is necessary, not sufficient. Otherwise putting a subscriber's id in
// wp-config.php would hand them the screen that displays the shared secret.
$GLOBALS['dp_allowed'] = array( 42 );
$subscriber = caps_for( array( 'read' => true ), user( 42, array( 'subscriber' ) ) );

ok( 'a named NON-administrator gets nothing', empty( $subscriber[ Access::CAP_RESTRICTED ] ) );
ok( 'and no plugin access either',            empty( $subscriber[ Access::CAP_ACCESS ] ) );

echo "\n--- a list that resolves to nothing allows nobody either ---\n";
//
// A mistyped constant must not be a way IN. define( 'IFS_DEPLOY_ADMIN_USERS', 'admin' ) names
// no id, and treating that as "unrestricted" would turn every typo into a silent removal of
// the restriction. It closes, and the event log records why.
$GLOBALS['dp_allowed'] = array( 0, 'abc', '' );

ok( 'garbage allows nobody', false === Access::may_use_restricted_screens( 9 ) );
ok( 'and the ids parse to an empty list', array() === Access::restricted_users() );

remove_restricted_filter();

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
