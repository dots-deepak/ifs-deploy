<?php
/**
 * Concurrent pushes from a multi-user team.
 *
 * A fifteen-person team turns three theoretical races into weekly ones. Each block below
 * names the failure it prevents, because none of them announced itself — no error appeared in
 * any of the three, which is precisely why they were worth fixing rather than documenting.
 */

$root = dirname( __DIR__ );
$pass = 0;
$fail = 0;

function ok( string $label, bool $condition ): bool {
	global $pass, $fail;

	if ( $condition ) {
		++$pass;
		echo "  PASS  $label\n";

		return true;
	}

	++$fail;
	echo "  FAIL  $label\n";

	return false;
}

/* -----------------------------------------------------------------------------
 * Stubs
 * -------------------------------------------------------------------------- */

$GLOBALS['dp_transients'] = array();
$GLOBALS['dp_users']      = array(
	7  => 'Priya',
	11 => 'Aman',
);

function __( string $t, string $d = '' ): string {
	return $t;
}
function _n( string $s, string $p, int $n, string $d = '' ): string {
	return 1 === $n ? $s : $p;
}
function get_transient( string $k ) {
	if ( ! isset( $GLOBALS['dp_transients'][ $k ] ) ) {
		return false;
	}

	[ $value, $expires ] = $GLOBALS['dp_transients'][ $k ];

	if ( $expires < time() ) {
		unset( $GLOBALS['dp_transients'][ $k ] );

		return false;
	}

	return $value;
}
function set_transient( string $k, $v, int $ttl = 0 ): bool {
	$GLOBALS['dp_transients'][ $k ] = array( $v, time() + $ttl );

	return true;
}
function delete_transient( string $k ): bool {
	unset( $GLOBALS['dp_transients'][ $k ] );

	return true;
}
function get_userdata( int $id ) {
	return isset( $GLOBALS['dp_users'][ $id ] )
		? (object) array( 'ID' => $id, 'display_name' => $GLOBALS['dp_users'][ $id ] )
		: false;
}
function wp_generate_password( int $len = 12, bool $special = true, bool $extra = false ): string {
	// Random enough that two DeployLock instances never collide on a token.
	return substr( str_shuffle( str_repeat( 'abcdef0123456789', 4 ) ), 0, $len );
}
function get_option( string $n, $d = false ) {
	return $GLOBALS['dp_options'][ $n ] ?? $d;
}
function update_option( string $n, $v, $a = null ): bool {
	$GLOBALS['dp_options'][ $n ] = $v;

	return true;
}
function current_time( string $type = 'mysql', $gmt = 0 ) {
	return 'timestamp' === $type ? time() : gmdate( 'Y-m-d H:i:s' );
}
function apply_filters( string $h, $v ) {
	return $v;
}
function wp_json_encode( $d, int $f = 0 ) {
	return json_encode( $d, $f );
}

spl_autoload_register(
	static function ( string $class ) use ( $root ): void {
		if ( 0 !== strpos( $class, 'IfsDeploy' . chr( 92 ) ) ) {
			return;
		}

		$relative = substr( $class, strlen( 'IfsDeploy' ) + 1 );
		$path     = $root . '/src/' . str_replace( chr( 92 ), '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

use IfsDeploy\Client\DeployLock;

/** One page, as the lock sees it. */
$about = array( array( 'type' => 'post', 'id' => 42, 'title' => 'About Us' ) );
$team  = array( array( 'type' => 'post', 'id' => 43, 'title' => 'Our Team' ) );

/* -----------------------------------------------------------------------------
 * The collision
 * -------------------------------------------------------------------------- */

echo "=== two people pushing the SAME page ===\n";

$GLOBALS['dp_transients'] = array();

$priya = new DeployLock();
$aman  = new DeployLock();

$first = $priya->acquire( $about );
ok( 'the first push takes the lock', true === $first['ok'] );

$priya->note_owner( 'post', 42, 7 );

$second = $aman->acquire( $about );

// THE assertion. Without this, both pushes went through: two deployments, two rollback
// snapshots for one change, and the queue row stamped by whichever finished last.
ok( 'the second push is refused', false === $second['ok'] );
ok( 'and it names the object', 42 === (int) ( $second['blocked'][0]['id'] ?? 0 ) );

// "Deployment failed" would send someone hunting for a fault that does not exist.
ok( 'the holder is identifiable by name', 'Priya' === DeployLock::holder_name( 'post', 42 ) );

echo "=== different pages stay fully parallel ===\n";

// A global lock would serialise the whole team behind one person's batch. Fifteen people on
// fifteen pages is the normal case and must not be slowed at all.
$third = $aman->acquire( $team );
ok( 'a different page is not blocked', true === $third['ok'] );

echo "=== a released lock frees the object ===\n";

$priya->release_all();

$retry = ( new DeployLock() )->acquire( $about );
ok( 'the page is pushable again', true === $retry['ok'] );

echo "=== a lock is only released by its owner ===\n";

$GLOBALS['dp_transients'] = array();

$owner  = new DeployLock();
$other  = new DeployLock();

$owner->acquire( $about );

// A slow request whose lock had expired and been retaken must not free the NEW holder on its
// way out. release_all() only deletes a lock whose stored token is its own.
$other->release_all();

ok( 'another request cannot release it', false === ( new DeployLock() )->acquire( $about )['ok'] );

$owner->release_all();
ok( 'but the owner can', true === ( new DeployLock() )->acquire( $about )['ok'] );

echo "=== a partial claim is rolled back ===\n";

$GLOBALS['dp_transients'] = array();

$batch = array(
	array( 'type' => 'post', 'id' => 50, 'title' => 'One' ),
	array( 'type' => 'post', 'id' => 51, 'title' => 'Two' ),
	array( 'type' => 'post', 'id' => 52, 'title' => 'Three' ),
);

// Someone else already holds the middle object.
$blocker = new DeployLock();
$blocker->acquire( array( $batch[1] ) );

$attempt = new DeployLock();
$result  = $attempt->acquire( $batch );

// All or nothing: pushing half a batch and reporting the rest as blocked would leave
// Production in a state neither user intended.
ok( 'the whole batch is refused', false === $result['ok'] );

// And the objects it DID take on the way to the collision must be given back, or a refused
// push would hold them for the full five-minute TTL.
ok( 'object 50 was released', false === get_transient( 'dp_lock_' . hash( 'sha256', 'post|50' ) ) );
ok( 'object 52 was never taken', false === get_transient( 'dp_lock_' . hash( 'sha256', 'post|52' ) ) );
ok( 'the blocker keeps its own lock', false !== get_transient( 'dp_lock_' . hash( 'sha256', 'post|51' ) ) );

echo "=== locks expire, so a crash cannot block forever ===\n";

$lock_src = (string) file_get_contents( $root . '/src/Client/DeployLock.php' );

ok( 'a TTL is set', false !== strpos( $lock_src, 'private const TTL' ) );
// Sized against TIMEOUT_HEAVY (180s) — the longest a legitimate import can take — plus room
// for a slow batch, so a crashed request frees its objects in minutes not never.
ok( 'and it outlives the heaviest import', (bool) preg_match( '/TTL\s*=\s*(300|[3-9]\d\d)/', $lock_src ) );

$service = (string) file_get_contents( $root . '/src/Client/DeploymentService.php' );

// `finally`, so a fatal mid-deploy still releases. The TTL is the backstop, not the mechanism.
ok( 'the release runs in a finally block', (bool) preg_match( '/finally\s*\{\s*(\/\/[^\n]*\n\s*)*\$lock->release_all\(\)/s', $service ) );

// Claimed BEFORE the deployment row exists, so a refusal leaves no history to explain.
$lock_at = strpos( $service, '$lock->acquire(' );
$row_at  = strpos( $service, '$this->deployments->create(' );
ok( 'objects are claimed before a deployment row is created', false !== $lock_at && $lock_at < $row_at );

/* -----------------------------------------------------------------------------
 * Push All scope
 * -------------------------------------------------------------------------- */

echo "=== Push All no longer publishes other people's work ===\n";

$ajax = (string) file_get_contents( $root . '/src/Admin/Ajax.php' );

// It used to return every id for anyone with "see all", so one administrator click deployed
// every colleague's pending row — including half-finished work.
ok( 'the wide scope requires an explicit opt-in', false !== strpos( $ajax, "! empty( \$_POST['include_others'] )" ) );
// A user without "see all" can never widen their scope, whatever the page sends.
ok( 'and the opt-in still needs the capability', (bool) preg_match( "/include_others'\s*\]\s*\)\s*&&\s*Access::sees_all\(\)/", $ajax ) );
// Narrowing now applies to administrators too, by default.
ok( 'ownership narrowing is the default for everyone', (bool) preg_match( '/if \( \$wants_others \) \{\s*return \$ids;\s*\}\s*return \( new QueueRepository\(\) \)->ids_owned_by/s', $ajax ) );

$pending = (string) file_get_contents( $root . '/src/Admin/Pages/PendingChangesPage.php' );

ok( 'the checkbox is rendered', false !== strpos( $pending, 'ifs-deploy-include-others' ) );
// Only shown to users who could act on others in the first place.
ok( 'only for users who can see all changes', (bool) preg_match( '/\$sees_all && current_user_can\( Access::CAP_DEPLOY \)/', $pending ) );
ok( 'and it defaults to off', false === strpos( $pending, 'id="ifs-deploy-include-others" checked' ) );

$js = (string) file_get_contents( $root . '/assets/js/admin.js' );

ok( 'the flag is sent on push', 2 === substr_count( $js, 'include_others: includeOthers()' ) - substr_count( $js, "ifs_deploy_ignore', { queue_ids: ids, include_others" ) );
ok( 'and on ignore', false !== strpos( $js, "ifs_deploy_ignore', { queue_ids: ids, include_others: includeOthers()" ) );
// One helper, so the three actions cannot drift apart on what the flag means.
ok( 'read through a single helper', 1 === substr_count( $js, 'function includeOthers()' ) );

// The dialog must not promise more than the server will do. Before this, an administrator
// saw "12 changes will be pushed" and 3 were pushed.
ok( 'the row carries its ownership', false !== strpos( $pending, 'data-mine=' ) );
ok( 'and the browser narrows the count to match', false !== strpos( $js, 'function scoped(' ) );
ok( 'both selected and all go through it', 2 === substr_count( $js, 'scoped( $( ' ) );

/* -----------------------------------------------------------------------------
 * Media race
 * -------------------------------------------------------------------------- */

echo "=== the same new image is downloaded once, not twice ===\n";

$media = (string) file_get_contents( $root . '/src/Import/MediaImporter.php' );

// find_existing() and sideload() are two steps. Two concurrent imports of pages sharing one
// new image both found nothing and both uploaded it — leaving image.jpg and image-1.jpg.
ok( 'the download is claimed first', false !== strpos( $media, "\$claim = 'dp_media_' . hash( 'sha256', \$source_url )" ) );
// Keyed on the source URL: that is what identifies the file, and what a second request
// would be about to fetch.
ok( 'keyed on the source url', false !== strpos( $media, "hash( 'sha256', \$source_url )" ) );

$claim_at = strpos( $media, '$claim = ' );
$side_at  = strpos( $media, '$attachment_id = $this->sideload(' );
ok( 'the claim is taken before the download', false !== $claim_at && $claim_at < $side_at );

// A second import waits and REUSES the result rather than failing the object or duplicating.
ok( 'a waiting import reuses the result', false !== strpos( $media, 'reused it instead of downloading again' ) );
ok( 'the wait is bounded', (bool) preg_match( '/\$waited < \d+;/', $media ) );
// If the other request died without producing an attachment, this one must proceed.
ok( 'it proceeds if the other request died', false !== strpos( $media, 'The other request finished or died' ) );
// Released immediately after the download, not left to expire.
ok( 'the claim is released after the download', (bool) preg_match( '/sideload\([^;]*\);\s*\n\s*delete_transient\( \$claim \);/s', $media ) );
// Short TTL so a crash cannot block one file indefinitely.
ok( 'and expires on its own', false !== strpos( $media, 'set_transient( $claim, 1, 60 )' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
