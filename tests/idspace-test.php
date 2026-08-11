<?php
declare(strict_types=1);

/*
 * Four review fixes that share one root cause: something was identified by a value
 * that was not unique enough, or not reported at all.
 *
 *  1. QUEUE STATUS MAPPING keyed a deployment's results by object id alone. Post ids,
 *     term ids, attachment ids and option ids all live in the same integer space, so a
 *     batch holding post 42 and term 42 cross-assigned their outcomes.
 *
 *  2. OPTION IDS came from crc32 — 32 bits, and a collision does not error, it MERGES:
 *     the queue's UNIQUE key is (type, subtype, object_id) and for options the subtype
 *     is always '', so two names sharing an id share a row and one silently stops being
 *     tracked.
 *
 *  3. THE COMPARE INDEX stopped at its limit and said nothing, so Compare & Sync drew
 *     confident conclusions from a partial list.
 *
 *  4. A ROLLED-BACK DEPLOYMENT was never marked as such on Production, so its snapshots
 *     stayed eligible and a second rollback replayed them.
 */

function __( $s, $d = '' ) { return $s; }
function esc_url_raw( $u ) { return $u; }

require __DIR__ . '/../src/Client/DeploymentService.php';
require __DIR__ . '/../src/Export/OptionExporter.php';

use IfsDeploy\Client\DeploymentService;
use IfsDeploy\Export\OptionExporter;

$pass = 0; $fail = 0;
function ok( string $n, bool $c ): void {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "PASS  $n\n"; } else { $fail++; echo "FAIL  $n\n"; }
}

$root = __DIR__ . '/..';

echo "=== THE CROSS-ASSIGNMENT BUG: one id, several object types ===\n";
//
// The consequence was not a visible error. The term's failure marked the POST's queue
// row failed, and the post's success marked the term deployed — stamping a
// deployed_hash for content Production never accepted, which drops the row out of
// Pending Changes permanently.

ok( 'a post and a term with the same id key differently', DeploymentService::map_key( 'post', 42 ) !== DeploymentService::map_key( 'term', 42 ) );
ok( 'media too', DeploymentService::map_key( 'media', 42 ) !== DeploymentService::map_key( 'post', 42 ) );
ok( 'options too', DeploymentService::map_key( 'option', 42 ) !== DeploymentService::map_key( 'menu', 42 ) );
ok( 'the same type and id still key the same', DeploymentService::map_key( 'post', 42 ) === DeploymentService::map_key( 'post', 42 ) );
ok( 'different ids of one type still differ', DeploymentService::map_key( 'post', 42 ) !== DeploymentService::map_key( 'post', 43 ) );

// A result carrying no type at all comes from a Production too old to send one, and
// ImportManager's own default for that case is 'post'. The two must agree or every
// queue row on a mixed-version pair would be left unresolved.
ok( 'an absent type falls back to post', DeploymentService::map_key( '', 42 ) === DeploymentService::map_key( 'post', 42 ) );

// The whole batch, the way it actually collides.
$mapped = array(
	DeploymentService::map_key( 'post', 42 ) => 100,
	DeploymentService::map_key( 'term', 42 ) => 200,
);
ok( 'both rows survive in one map', 2 === count( $mapped ) );
ok( "the post's result finds the post's row", 100 === $mapped[ DeploymentService::map_key( 'post', 42 ) ] );
ok( "the term's result finds the term's row", 200 === $mapped[ DeploymentService::map_key( 'term', 42 ) ] );

$service = (string) php_strip_whitespace( $root . '/src/Client/DeploymentService.php' );
ok( 'finalize() reads the type back off the result', false !== strpos( $service, "\$result['type'] ?? 'post'" ) );
ok( 'and no bare object-id key remains', 0 === preg_match( '/\$mapped\[\s*\(int\)\s*\$item->object_id\s*\]/', $service ) );

echo "\n=== one row per object at READ time as well, and it self-heals ===\n";
//
// Writing is already safe (find() keys on type + object_id), but a row left by an older
// build, or reaching the table another way, must still never be listed twice. With
// several people working at once, a list that shows the same object twice makes "what am
// I about to push?" unanswerable.
$repo = (string) php_strip_whitespace( $root . '/src/Queue/QueueRepository.php' );

ok( 'the listing query keeps only the newest row per object', (bool) preg_match( '/SELECT MAX\(d\.id\).*?d\.object_type = q\.object_type.*?d\.object_id = q\.object_id/s', $repo ) );
// Status is deliberately NOT in the subquery: the invariant is one row per object
// outright, which is also what the UNIQUE key enforces across every status.
ok( 'and does not scope that per status', 0 === preg_match( '/SELECT MAX\(d\.id\).*?d\.status = q\.status/s', $repo ) );
ok( 'both the all-users and per-user queries carry it', 2 === preg_match_all( '/SELECT MAX\(d\.id\)/', $repo ) );
ok( 'and duplicates are physically removed, not just hidden', false !== strpos( $repo, 'function collapse_duplicates()' ) );

echo "\n=== the UNIQUE key is verified, not assumed ===\n";
//
// THE REPORTED CASE: eight queue rows for ONE attachment, all created in the same
// second. That is impossible while the UNIQUE key exists — so on that site it did not.
// dbDelta declares it in CREATE TABLE but is unreliable at ADDING an index to a table
// that already exists, and it reports nothing when it fails.
$schema_src = (string) php_strip_whitespace( $root . '/src/Support/Schema.php' );

ok( 'the index is checked explicitly', false !== strpos( $schema_src, 'ensure_object_identity_index' ) );
ok( 'by asking the table, not by trusting dbDelta', false !== strpos( $schema_src, 'SHOW INDEX FROM' ) );
ok( 'and rebuilt when missing', false !== strpos( $schema_src, 'ADD UNIQUE KEY object_identity' ) );
// MySQL refuses to add a unique index while duplicates exist, which is why a missing key
// is self-perpetuating: the duplicates it allowed then block its own repair.
$collapse_at = strpos( $schema_src, 'collapse_duplicate_rows()' );
$alter_at    = strpos( $schema_src, 'ADD UNIQUE KEY object_identity' );
ok( 'duplicates are cleared BEFORE the key is added', false !== $collapse_at && false !== $alter_at && $collapse_at < $alter_at );
ok( 'a database that refuses it is logged, not fataled', (bool) preg_match( '/false === \$added.*?DebugLog::error/s', $schema_src ) );
ok( 'and the check runs on every install/upgrade', (bool) preg_match( '/function install\(\).*?ensure_object_identity_index\(\)/s', $schema_src ) );

$pending_page = (string) php_strip_whitespace( $root . '/src/Admin/Pages/PendingChangesPage.php' );
ok( 'the screen repairs the table when it renders', false !== strpos( $pending_page, 'collapse_duplicates()' ) );
// Before verification, or a stale duplicate would be probed against Production and
// offered for pushing alongside the row that supersedes it.
$collapse_at = strpos( $pending_page, 'collapse_duplicates()' );
$verify_at   = strpos( $pending_page, '$this->verify(' );
ok( 'and repairs BEFORE anything reads the rows', false !== $collapse_at && false !== $verify_at && $collapse_at < $verify_at );
ok( 'rows never vanish without a word', false !== strpos( $pending_page, 'was merged into it' ) );

// Identical titles are not a duplicate. WordPress names an attachment after its file, so
// uploading test.png repeatedly yields several attachments all called "test" — eight of
// those read as one item tracked eight times unless the object id is on screen.
ok( 'each row shows the object id', false !== strpos( $pending_page, '(int) $item->object_id' ) );
ok( 'rendered in the Object cell', (bool) preg_match( '/<strong>%2\$s<\/strong><span class="dp-id">#%10\$d<\/span>/', $pending_page ) );

echo "\n=== option ids are wide enough to stop merging rows ===\n";

$id = OptionExporter::option_id( 'options_hero_title' );

ok( 'an option id is a positive integer', $id > 0 );
ok( 'it is stable for the same name', $id === OptionExporter::option_id( 'options_hero_title' ) );
ok( 'and different for a different name', $id !== OptionExporter::option_id( 'options_hero_subtitle' ) );
ok( 'it fits bigint(20) unsigned', $id <= PHP_INT_MAX );

// 60 bits, so the birthday bound moves from ~77,000 names to ~1.5 billion. Asserted as
// a floor rather than an exact width, because what matters is that it is far past what
// crc32 could offer.
ok( 'it is far wider than crc32 could produce', OptionExporter::option_id( str_repeat( 'z', 40 ) ) > 0xFFFFFFFF );

// A known crc32 collision pair. Under the old derivation these two names shared one
// queue row; whichever was saved second overwrote the first's title and hash, and the
// first stopped being deployable with no sign that anything had happened.
$a = 'plumless';
$b = 'buckeroo';
ok( 'the fixture really is a crc32 collision', crc32( $a ) === crc32( $b ) );
ok( 'THE REPORTED BUG: they no longer share an id', OptionExporter::option_id( $a ) !== OptionExporter::option_id( $b ) );

$exporter = (string) php_strip_whitespace( $root . '/src/Export/OptionExporter.php' );
ok( 'crc32 is gone from the exporter', false === strpos( $exporter, 'crc32' ) );

// One definition, not two. A second copy decides where snapshots are filed, so drift
// would leave revisions under an id that prune() and every lookup no longer find.
$snapshots = (string) php_strip_whitespace( $root . '/src/Rollback/SnapshotStore.php' );
ok( 'SnapshotStore no longer has its own copy', false === strpos( $snapshots, 'crc32' ) );
ok( 'it delegates to the one definition', false !== strpos( $snapshots, 'OptionExporter::option_id( $name )' ) );

// Changing the derivation moves every existing row, so they have to be re-filed or the
// observer can never find them again and inserts a duplicate beside each one.
$schema = (string) php_strip_whitespace( $root . '/src/Support/Schema.php' );
ok( 'existing option rows are re-keyed by a migration', false !== strpos( $schema, 'rekey_option_rows' ) );
ok( 'the migration is version-gated', (bool) preg_match( "/version_compare\(\s*\\\$from,\s*'6',\s*'<'\s*\)/", $schema ) );
ok( 'and skipped on a fresh install', (bool) preg_match( "/'0'\s*!==\s*\\\$from/", $schema ) );
ok( 'it re-derives from the option NAME', false !== strpos( $schema, 'OptionExporter::option_id( $name )' ) );

$boot = (string) file_get_contents( $root . '/ifs-deploy.php' );
preg_match( "/IFS_DEPLOY_DB_VERSION',\s*'(\d+)'/", $boot, $version );
ok( 'the schema version was bumped for it', (int) ( $version[1] ?? 0 ) >= 6 );

echo "\n=== a truncated index says so ===\n";
//
// Silence here is worse than a blank screen: everything past the cut on Staging reads
// as "Not on Production" and everything past the cut on Production as "Only on
// Production", because in both cases the counterpart is simply absent from the data.
// Acting on the first creates a duplicate of a page that already exists.

$index = (string) php_strip_whitespace( $root . '/src/Support/SiteIndex.php' );

ok( 'the index reports whether it is complete', false !== strpos( $index, "'truncated'" ) );
ok( 'overflow is detected by fetching one extra row', false !== strpos( $index, '$limit + 1' ) );
ok( 'and the extra row is dropped again', false !== strpos( $index, 'array_slice( $posts, 0, $limit )' ) );
// A filter returning 0 or -1 means "no limit", which can never be truncated — and must
// not have 1 added to it, since get_posts() reads -1 as unlimited and 0 as the default.
ok( 'an unlimited setting is handled separately', false !== strpos( $index, '$unlimited = $limit <= 0' ) );

$endpoint = (string) php_strip_whitespace( $root . '/src/Rest/IndexEndpoint.php' );
ok( 'Production passes truncation across the wire', false !== strpos( $endpoint, "'truncated'" ) );

$compare = (string) php_strip_whitespace( $root . '/src/Client/CompareService.php' );
ok( 'Compare checks BOTH sides', false !== strpos( $compare, 'truncation_warning' ) );
ok( 'reading the remote flag from the response', false !== strpos( $compare, "\$response['body']['truncated']" ) );
// An older Production sends no flag, which reads as false — the previous behaviour.
ok( 'an absent remote flag is not treated as truncated', false !== strpos( $compare, '! empty(' ) );

$page = (string) php_strip_whitespace( $root . '/src/Admin/Pages/ComparePage.php' );
ok( 'the screen renders the warning', false !== strpos( $page, "\$comparison['truncated']" ) );
ok( 'as a warning notice', false !== strpos( $page, 'notice notice-warning' ) );
// Before the summary cards, which would otherwise read as authoritative counts.
$warn_at    = strpos( $page, "\$comparison['truncated']" );
$summary_at = strpos( $page, 'summary(' );
ok( 'and before the summary it qualifies', false !== $warn_at && false !== $summary_at && $warn_at < $summary_at );

echo "\n=== a deployment can only be rolled back once ===\n";
//
// Replaying is not the no-op it looks like. Roll back deploy B and then deploy A and
// the object correctly sits at A's "before" state; replaying B afterwards silently
// drags it forward again, undoing the rollback of A. Staging hides the button once a
// deployment reads "Rolled Back", but that guard vanishes when history is cleared and
// was never binding on a receiver that trusts whatever asks.

$rollback = (string) php_strip_whitespace( $root . '/src/Rest/RollbackEndpoint.php' );

ok( 'Production refuses an already-rolled-back deployment', false !== strpos( $rollback, 'STATUS_ROLLED_BACK === (string) $deployment->deployment_status' ) );
ok( 'with 409, so it is distinct from restoring zero objects', (bool) preg_match( '/already been rolled back.*?409/s', $rollback ) );
ok( 'and it records the rollback for next time', false !== strpos( $rollback, 'set_status( (int) $deployment->id, DeploymentRepository::STATUS_ROLLED_BACK )' ) );
// A run that restored nothing changed no state, so marking it would strand the
// deployment with no way to retry.
ok( 'only when something was actually restored', (bool) preg_match( '/if\s*\(\s*\$restored > 0\s*\)\s*\{\s*\$deployments->set_status/', $rollback ) );
// The guard has to precede the work, or the snapshots are replayed before it is read.
$guard_at   = strpos( $rollback, 'already been rolled back' );
$restore_at = strpos( $rollback, '$store->restore(' );
ok( 'the refusal comes before any restoring', false !== $guard_at && false !== $restore_at && $guard_at < $restore_at );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
