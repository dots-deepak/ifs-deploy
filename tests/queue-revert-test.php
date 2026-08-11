<?php
declare(strict_types=1);

/*
 * Simulates the reported lifecycle against the real upsert() decision logic:
 * edit -> queued, deploy -> deployed, edit -> queued, undo -> must leave the queue.
 */

function current_time( $t ) { return '2026-08-05 12:00:00'; }
function get_current_user_id() { return 7; }

/** In-memory stand-in for the queue table. */
class FakeWpdb {
	public $rows = array();
	public $prefix = 'wp_';
	private $next_id = 1;

	public function insert( $table, $data, $format = null ) {
		// Apply the column DEFAULTs that MySQL would, so the fake table behaves like the
		// real one for rows that have never been deployed and carry no baseline.
		$data = array_merge( array( 'deployed_hash' => '', 'baseline_hash' => '' ), $data );
		$data['id'] = $this->next_id++;
		$this->rows[ $data['id'] ] = (object) $data;
		return 1;
	}
	public function update( $table, $data, $where, $f = null, $wf = null ) {
		$id = (int) $where['id'];
		if ( ! isset( $this->rows[ $id ] ) ) { return 0; }
		foreach ( $data as $k => $v ) { $this->rows[ $id ]->$k = $v; }
		return 1;
	}
	public function prepare( $sql, ...$args ) { return array( 'sql' => $sql, 'args' => $args ); }
	public function get_row( $q ) {
		/*
		 * find(): type, object_id — the SUBTYPE IS NOT PART OF IT.
		 *
		 * A mime type or post type can change while the object stays the same one, so
		 * matching on it gave a single object one row per value it had ever reported.
		 * Newest-id-first mirrors the real query, which has to converge on the most
		 * recent row while a table still holds pre-migration duplicates.
		 */
		list( $type, $object_id ) = $q['args'];
		foreach ( array_reverse( $this->rows, true ) as $row ) {
			if ( $row->object_type === $type && (int) $row->object_id === (int) $object_id ) {
				return $row;
			}
		}
		return null;
	}
	public function query( $q ) {
		// mark_deployed(): status, updated_at, id
		if ( is_array( $q ) ) {
			list( $status, $updated, $id ) = $q['args'];
			$row = $this->rows[ (int) $id ] ?? null;
			if ( $row ) { $row->status = $status; $row->deployed_hash = $row->object_hash; $row->updated_at = $updated; }
		}
		return 1;
	}
	public function get_results( $q ) { return array(); }
	public function get_col( $q ) { return array(); }
	public function get_charset_collate() { return ''; }
}

$GLOBALS['wpdb'] = new FakeWpdb();

require __DIR__ . '/../src/Support/Schema.php';
require __DIR__ . '/../src/Queue/QueueRepository.php';

use IfsDeploy\Queue\QueueRepository;

$pass = 0; $fail = 0;
function ok( string $n, bool $c ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS  $n\n"; } else { $fail++; echo "FAIL  $n\n"; } }

$q = new QueueRepository();
$HASH_A = md5( 'original content' );
$HASH_B = md5( 'edited content' );

function row() { return reset( $GLOBALS['wpdb']->rows ) ?: null; }
function status() { $r = row(); return $r ? $r->status : '(none)'; }

echo "=== 1. first edit queues the change ===\n";
$queued = $q->upsert( 'post', 'page', 42, 'Summit', 'update', $HASH_B );
ok( 'upsert reported a change', true === $queued );
ok( 'status is pending',        'pending' === status() );

echo "\n=== 2. saving again with no change is skipped ===\n";
ok( 'no change reported', false === $q->upsert( 'post', 'page', 42, 'Summit', 'update', $HASH_B ) );
ok( 'still pending',      'pending' === status() );

echo "\n=== 3. deploy records the deployed hash ===\n";
$q->mark_deployed( (int) row()->id );
ok( 'status is deployed',        'deployed' === status() );
ok( 'deployed_hash captured',    $HASH_B === row()->deployed_hash );

echo "\n=== 4. a no-op save after deploying must NOT re-queue ===\n";
ok( 'no change reported', false === $q->upsert( 'post', 'page', 42, 'Summit', 'update', $HASH_B ) );
ok( 'still deployed',     'deployed' === status() );

echo "\n=== 5. a real edit re-queues it ===\n";
ok( 'change reported', true === $q->upsert( 'post', 'page', 42, 'Summit', 'update', $HASH_A ) );
ok( 'pending again',   'pending' === status() );

echo "\n=== 6. THE REPORTED CASE: undo the edit -> leaves Pending Changes ===\n";
ok( 'no change reported', false === $q->upsert( 'post', 'page', 42, 'Summit', 'update', $HASH_B ) );
ok( 'back to deployed',   'deployed' === status() );
ok( 'deployed_hash intact', $HASH_B === row()->deployed_hash );

echo "\n=== 7. editing again after the undo still works ===\n";
ok( 'change reported', true === $q->upsert( 'post', 'page', 42, 'Summit', 'update', $HASH_A ) );
ok( 'pending again',   'pending' === status() );

echo "\n=== 8. never-deployed objects are unaffected ===\n";
$q->upsert( 'post', 'page', 99, 'Fresh', 'update', md5( 'v1' ) );
$fresh = $GLOBALS['wpdb']->rows[2];
ok( 'queued as pending',      'pending' === $fresh->status );
ok( 'no deployed hash yet',   '' === (string) $fresh->deployed_hash );
ok( 'edit still re-queues',   true === $q->upsert( 'post', 'page', 99, 'Fresh', 'update', md5( 'v2' ) ) );

echo "\n=== 9. THE SECOND REPORT: undo WITHOUT ever deploying ===\n";
//
// The case `deployed_hash` cannot answer. Nothing was ever pushed, so there is no
// "what Production has" to compare against — the row only ever remembered the state it
// was changed TO, and the change therefore sat in Pending Changes for ever. The caller
// supplies the pre-edit hash (PostObserver captures it on `pre_post_update`) and the
// row carries it from then on.
$V1 = md5( 'draft v1' );
$V2 = md5( 'draft v2' );

$q->upsert( 'post', 'page', 500, 'Careers', 'update', $V2, $V1 );
$careers = $GLOBALS['wpdb']->rows[3];

ok( 'queued as pending',            'pending' === $careers->status );
ok( 'the baseline was recorded',    $V1 === (string) $careers->baseline_hash );
ok( 'and it was never deployed',    '' === (string) $careers->deployed_hash );

ok( 'undoing the edit reports no change', false === $q->upsert( 'post', 'page', 500, 'Careers', 'update', $V1 ) );
ok( 'THE FIX: the row leaves Pending Changes', 'unchanged' === $careers->status );
// "unchanged", not "deployed": nothing was ever sent, and saying otherwise would let
// Schema's v3 backfill stamp a deployed_hash for content Production has never seen.
ok( 'and it is NOT recorded as deployed', 'deployed' !== $careers->status );
ok( 'no deployed hash was invented',      '' === (string) $careers->deployed_hash );

echo "\n=== 10. and editing it again still queues ===\n";
ok( 'change reported', true === $q->upsert( 'post', 'page', 500, 'Careers', 'update', $V2 ) );
ok( 'pending again',   'pending' === $careers->status );
// A row leaving a settled state supplies its own baseline for free — the state it held
// while settled IS the state to return to. This is what makes the fix work for terms,
// options, media and menus too, none of which have a pre-edit hook.
ok( 'the baseline came from the settled row', $V1 === (string) $careers->baseline_hash );
ok( 'so undoing works a second time', false === $q->upsert( 'post', 'page', 500, 'Careers', 'update', $V1 ) );
ok( 'and it left the list again',     'unchanged' === $careers->status );

echo "\n=== 11. a baseline never overrides a real difference ===\n";
$q->upsert( 'post', 'page', 600, 'Pricing', 'update', md5( 'b' ), md5( 'a' ) );
$pricing = $GLOBALS['wpdb']->rows[4];
ok( 'a third, different state stays pending', true === $q->upsert( 'post', 'page', 600, 'Pricing', 'update', md5( 'c' ) ) );
ok( 'still pending', 'pending' === $pricing->status );
ok( 'the original baseline survives the update', md5( 'a' ) === (string) $pricing->baseline_hash );
ok( 'and returning to it still resolves', false === $q->upsert( 'post', 'page', 600, 'Pricing', 'update', md5( 'a' ) ) );
ok( 'resolved', 'unchanged' === $pricing->status );

echo "\n=== 12. ONE ROW PER OBJECT, whatever the subtype says ===\n";
//
// The media report: updating an attachment's details produced several tracked items for
// one file. The subtype (a mime type) was part of the row's identity, so every value it
// ever reported got its own row that the others never deduped against.
$before = count( $GLOBALS['wpdb']->rows );

$q->upsert( 'media', 'image/jpeg', 700, 'Hero', 'update', md5( 'm1' ) );
$q->upsert( 'media', 'image/webp', 700, 'Hero', 'update', md5( 'm2' ) );
$q->upsert( 'media', '', 700, 'Hero', 'update', md5( 'm3' ) );

ok( 'three saves of one attachment made ONE row', 1 === count( $GLOBALS['wpdb']->rows ) - $before );

$media = $GLOBALS['wpdb']->rows[ array_key_last( $GLOBALS['wpdb']->rows ) ];
ok( 'it holds the latest hash',    md5( 'm3' ) === (string) $media->object_hash );
ok( 'and the latest subtype',      '' === (string) $media->object_subtype );

// Different object types sharing an id still get their own rows: identity is
// (type, object_id), and ids are only unique WITHIN a type.
$q->upsert( 'term', 'category', 700, 'News', 'update', md5( 't1' ) );
ok( 'a term with the same id is a separate row', 2 === count( $GLOBALS['wpdb']->rows ) - $before );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
