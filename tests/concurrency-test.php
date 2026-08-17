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

/*
 * THE EXEMPTION IS ADMINISTRATOR, NOT "see all".
 *
 * It used to key off `view_all`, so anyone who could SEE everyone's changes could also
 * push them. Those are different powers: an editor may legitimately need to review the
 * whole team's queue without being able to publish a colleague's half-finished page.
 *
 * `manage_options` is the exemption, and it exists because the alternative strands work —
 * someone goes on leave and their approved changes can be pushed by nobody. Even then it
 * has to be asked for, so an administrator's "Push All" still defaults to their own rows.
 */
ok( 'the exemption is manage_options', (bool) preg_match( '/current_user_can\(\s*Access::CAP_MANAGE\s*\)/', $ajax ) );
ok( 'and view_all no longer grants it', 0 === preg_match( "/include_others'\s*\]\s*\)\s*&&\s*Access::sees_all\(\)/", $ajax ) );
ok( 'ownership narrowing is the default for everyone', (bool) preg_match( '/\$is_admin && ! empty\( \$_POST\[.include_others.\] \)\s*\)\s*\{\s*return \$ids;/s', $ajax ) );

// And a refusal SAYS SO. Selecting a colleague's row used to end at "No items selected.",
// which is not what happened and sends the user hunting for a fault in their own selection.
ok( 'a refused row is reported, not silently dropped', false !== strpos( $ajax, 'You do not have permission to push these changes.' ) );
ok( 'and the message counts what was refused', (bool) preg_match( '/\$refused = count\( \$ids \) - count\( \$owned \);/', $ajax ) );
// An administrator gets a different message: theirs is a missing tick, not a missing right.
ok( 'an administrator is told about the checkbox instead', (bool) preg_match( '/\$is_admin\s*\?\s*sprintf/', $ajax ) );

$pending = (string) file_get_contents( $root . '/src/Admin/Pages/PendingChangesPage.php' );

ok( 'the checkbox is rendered', false !== strpos( $pending, 'ifs-deploy-include-others' ) );
// Only for administrators now — the server enforces the same rule either way.
ok( 'only for administrators', (bool) preg_match( '/current_user_can\( Access::CAP_MANAGE \) && current_user_can\( Access::CAP_DEPLOY \)/', $pending ) );
ok( 'and it defaults to off', false === strpos( $pending, 'id="ifs-deploy-include-others" checked' ) );

$js = (string) file_get_contents( $root . '/assets/js/admin.js' );

/*
 * Push Selected and Push All both hand the flag to the batched pusher.
 *
 * It has to travel with EVERY batch, not just the first: each batch is its own request,
 * and `Ajax::queue_ids()` re-checks ownership on all of them — so a batch that arrived
 * without the flag would have its rows narrowed away mid-push, silently deploying less
 * than the plan promised.
 */
ok( 'both push buttons pass the flag to the planner', 2 === substr_count( $js, 'pushStart( set.ids, includeOthers() )' ) );
ok( 'the plan request carries it', (bool) preg_match( "/action: 'ifs_deploy_push_plan'.*?include_others: includeOthers/s", $js ) );
ok( 'and so does every batch', (bool) preg_match( "/action: 'ifs_deploy_push_batch'.*?include_others: push\.includeOthers/s", $js ) );
ok( 'and the cancel', (bool) preg_match( "/action: 'ifs_deploy_push_cancel'.*?include_others: push\.includeOthers/s", $js ) );
ok( 'and on ignore', false !== strpos( $js, "ifs_deploy_ignore', { queue_ids: set.ids, include_others: includeOthers()" ) );
// One helper, so the three actions cannot drift apart on what the flag means.
ok( 'read through a single helper', 1 === substr_count( $js, 'function includeOthers()' ) );

// The dialog must not promise more than the server will do. Before this, an administrator
// saw "12 changes will be pushed" and 3 were pushed.
ok( 'the row carries its ownership', false !== strpos( $pending, 'data-mine=' ) );
ok( 'and the browser narrows the count to match', false !== strpos( $js, 'function scoped(' ) );
// Both buttons read the same helper, which returns the narrowed ids AND how many were
// removed — the count is what lets an empty result be explained instead of ignored.
// Three: Push Selected, Push All, and Ignore. Ignore narrows by ownership too —
// `Ajax::ignore()` runs the same check — so it had the same silent failure, and
// dismissing a colleague's change is no more yours to do than publishing it.
ok( 'selected, all AND ignore go through it', 3 === substr_count( $js, 'pushable( $( ' ) );
ok( 'the helper reports what it removed', false !== strpos( $js, "refused: \$items.length - ids.length" ) );

/*
 * AN EMPTY PUSH IS EXPLAINED, NOT SWALLOWED.
 *
 * Push All answered an empty set with a bare `return`: an Editor pressing it on an
 * administrator's changes got no dialog, no message, and no sign the click had registered.
 * Push Selected was barely better, saying "Nothing is selected" to someone who had
 * selected several rows.
 */
ok( 'an empty push is explained', false !== strpos( $js, 'function explainEmptyPush(' ) );
ok( 'and no push handler returns silently', 0 === preg_match( '/var ids = allIds\(\);\s*if \( ! ids\.length \) \{\s*return;/', $js ) );
// Nothing pending and none-of-it-is-yours are different situations; only the second is a
// permission problem.
ok( 'a permission refusal is named as one', false !== strpos( $js, 'IfsDeploy.i18n.pushNotYours' ) );
ok( 'and that string exists', false !== strpos( (string) file_get_contents( $root . '/src/Admin/Assets.php' ), "'pushNotYours'" ) );
// A partial refusal is stated BEFORE confirming — pushing 3 of 8 and reporting success is
// how someone concludes their colleague's work went out with theirs.
ok( 'a partial refusal is shown in the dialog', (bool) preg_match( '/function pushConfirmBody\( count, refused \)/', $js ) );

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
