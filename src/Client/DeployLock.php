<?php
declare(strict_types=1);

namespace IfsDeploy\Client;

use IfsDeploy\Support\DebugLog;

/**
 * Stops two people pushing the same object at the same moment.
 *
 * ── WHY THIS IS NEEDED ON A TEAM ───────────────────────────────────────────────
 *
 * Nothing prevented it. Two users pushing "About Us" simultaneously produced two
 * deployments, two imports and two rollback snapshots for one logical change. Production
 * did not corrupt — the later write simply won — but two things went wrong quietly:
 *
 *  - Rolling back afterwards could restore the OTHER person's version, because there were
 *    now two restore points for the same object and the newest was not the one anybody
 *    meant to undo.
 *  - The queue row was marked deployed by whichever request finished last, stamping ITS
 *    hash as `deployed_hash`. If that was the earlier of the two payloads, the row either
 *    stayed pending forever or cleared while Production held something else.
 *
 * Neither shows up as an error. That is what makes a lock worth having rather than just
 * documenting the race.
 *
 * ── WHY IT LOCKS PER OBJECT, NOT GLOBALLY ──────────────────────────────────────
 *
 * A single global lock would serialise the whole team: one person pushing a large batch
 * would block everyone else for as long as it ran. Fifteen people editing fifteen different
 * pages is the normal case and must stay fully parallel. Only a genuine collision — the same
 * object, at the same time — is worth refusing.
 *
 * ── WHY TRANSIENTS AND NOT A TABLE ─────────────────────────────────────────────
 *
 * A lock is allowed to be imperfect in a way replay protection is not. If a lock is lost to
 * a cache eviction the worst case is the pre-existing race, which is where this started; if
 * a lock could never expire, one crashed request would block an object forever. A transient
 * gives automatic expiry, which is the property that matters most here.
 */
final class DeployLock {

	/**
	 * How long a lock survives without being released, in seconds.
	 *
	 * Sized against `Config::TIMEOUT_HEAVY` (180s), which is the longest a single import
	 * request can legitimately take, plus room for a slow batch. A crashed request therefore
	 * frees its objects within a few minutes rather than needing manual intervention.
	 */
	private const TTL = 300;

	/**
	 * Objects locked by THIS request, so `release_all()` cannot free someone else's.
	 *
	 * @var array<string,string> lock key => owner token
	 */
	private array $held = array();

	/** Identifies this request, so a lock is only released by the request that took it. */
	private string $token;

	public function __construct() {
		// uniqid alone is time-based and can repeat under load; the random suffix makes a
		// collision between two simultaneous pushes implausible.
		$this->token = uniqid( 'dp', true ) . '-' . wp_generate_password( 8, false );
	}

	/**
	 * Try to take locks for a set of objects.
	 *
	 * ALL OR NOTHING. A partial claim would push half a batch and report the rest as
	 * blocked, leaving Production in a state neither user intended — worse than refusing
	 * outright and saying which object is busy.
	 *
	 * @param array<int,array{type:string,id:int}> $objects
	 * @return array{ok:bool,blocked:array<int,array{type:string,id:int}>}
	 */
	public function acquire( array $objects ): array {
		$blocked = array();

		foreach ( $objects as $object ) {
			if ( ! $this->claim( (string) $object['type'], (int) $object['id'] ) ) {
				$blocked[] = $object;
			}
		}

		if ( empty( $blocked ) ) {
			return array( 'ok' => true, 'blocked' => array() );
		}

		// Give back everything taken on the way to the collision, or a refused push would
		// leave its objects locked for the full TTL.
		$this->release_all();

		return array( 'ok' => false, 'blocked' => $blocked );
	}

	/**
	 * Claim one object.
	 *
	 * `add_option()`-style semantics via a transient: read, then write. Not atomic, and
	 * deliberately accepted — see the class note. The window is microseconds against a push
	 * that takes seconds, so it closes the realistic collision (two people clicking within
	 * the same second) without pretending to be a distributed lock.
	 */
	private function claim( string $type, int $id ): bool {
		$key = self::key( $type, $id );

		if ( false !== get_transient( $key ) ) {
			return false;
		}

		set_transient( $key, $this->token, self::TTL );

		// Read back: if another request won the race between the check and the write, its
		// token is there now and this one must stand down.
		if ( (string) get_transient( $key ) !== $this->token ) {
			return false;
		}

		$this->held[ $key ] = $this->token;

		return true;
	}

	/**
	 * Release every lock this request holds.
	 *
	 * Only ever deletes a lock whose stored token is ours. Without that check a slow request
	 * whose lock had already expired and been retaken would free the NEW holder's lock on
	 * its way out.
	 */
	public function release_all(): void {
		foreach ( $this->held as $key => $token ) {
			if ( (string) get_transient( $key ) === $token ) {
				delete_transient( $key );
			}
		}

		$this->held = array();
	}

	/**
	 * Who is pushing this object, if anyone — for the message shown to the second user.
	 *
	 * Returns a display name rather than an id: "Priya is pushing this right now" is
	 * actionable, "locked by user 7" is not.
	 */
	public static function holder_name( string $type, int $id ): string {
		$owner = (int) get_transient( self::owner_key( $type, $id ) );

		if ( $owner <= 0 ) {
			return '';
		}

		$user = get_userdata( $owner );

		return $user ? (string) $user->display_name : '';
	}

	/**
	 * Record who holds a lock, alongside the lock itself.
	 *
	 * Stored separately from the token so the token stays opaque — it is the release
	 * credential, and putting a user id inside it would invite comparing on the wrong thing.
	 */
	public function note_owner( string $type, int $id, int $user_id ): void {
		if ( $user_id > 0 ) {
			set_transient( self::owner_key( $type, $id ), $user_id, self::TTL );
		}
	}

	/**
	 * Log a refused push once, with enough detail to explain itself.
	 *
	 * @param array<int,array{type:string,id:int,title?:string}> $blocked
	 */
	public static function note_conflict( array $blocked ): void {
		$first = $blocked[0] ?? array();
		$who   = self::holder_name( (string) ( $first['type'] ?? '' ), (int) ( $first['id'] ?? 0 ) );

		DebugLog::warning(
			'A push was refused because another user is already deploying one of the same objects.',
			array(
				'objects'  => (string) count( $blocked ),
				'first'    => (string) ( $first['title'] ?? $first['id'] ?? '' ),
				'held_by'  => '' !== $who ? $who : 'another user',
				'why'      => 'Two simultaneous pushes of one object create two rollback points and can stamp the wrong deployed hash on the queue row.',
			)
		);
	}

	private static function key( string $type, int $id ): string {
		return 'dp_lock_' . hash( 'sha256', $type . '|' . $id );
	}

	private static function owner_key( string $type, int $id ): string {
		return 'dp_lockowner_' . hash( 'sha256', $type . '|' . $id );
	}
}
