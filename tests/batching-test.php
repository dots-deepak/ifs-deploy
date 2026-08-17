<?php
declare(strict_types=1);

/*
 * A push split into batches, and cancelling one part-way.
 *
 * ── THE TRAP THIS EXISTS TO GUARD ──────────────────────────────────────────────
 *
 * Batching was deferred for a long time (§18) for one specific reason: snapshots hang off
 * a DEPLOYMENT ID, so if each batch created its own deployment record, ROLLBACK WOULD ONLY
 * EVER RESTORE THE LAST BATCH. Everything earlier would be unreachable — and a rollback
 * that silently restores a third of a push is far worse than no rollback at all.
 *
 * Everything below is about the properties that keep that from happening, plus the two
 * things cancelling has to get right: Production must be put back, and the user's work
 * must NOT be thrown away.
 */

$root = dirname( __DIR__ );
$pass = 0;
$fail = 0;

function ok( string $label, bool $condition ): bool {
	global $pass, $fail;

	if ( $condition ) {
		++$pass;
		echo "PASS  $label\n";

		return true;
	}

	++$fail;
	echo "FAIL  $label\n";

	return false;
}

/** Source with comments stripped, so the prose explaining a rule cannot satisfy it. */
function src( string $relative ): string {
	return (string) php_strip_whitespace( dirname( __DIR__ ) . '/' . $relative );
}

$import  = src( 'src/Import/ImportManager.php' );
$service = src( 'src/Client/DeploymentService.php' );
$cancel  = src( 'src/Rest/CancelEndpoint.php' );
$queue   = src( 'src/Queue/QueueRepository.php' );
$store   = src( 'src/Rollback/SnapshotStore.php' );
$ajax    = src( 'src/Admin/Ajax.php' );
$js      = (string) file_get_contents( $root . '/assets/js/admin.js' );

echo "=== ONE deployment record per push, however many requests ===\n";
//
// The whole reason batching was deferred. Snapshots attach to a deployment id.

ok( 'the record is looked up by uuid first', (bool) preg_match( '/get_by_uuid\(\s*\$deployment_uuid\s*\)/', $import ) );
ok( 'and only created when there is none', (bool) preg_match( '/null !== \$existing\s*\?\s*\(int\) \$existing->id\s*:\s*\$this->deployments->create\(/', $import ) );
// A retried batch must land in the same deployment rather than fragmenting the history.
ok( 'so a retried batch cannot fragment it', false !== strpos( $import, 'get_by_uuid' ) );

echo "\n=== the status describes the WHOLE push, not the last batch ===\n";
//
// Otherwise a final batch of one successful object reports `success` for a deployment
// whose earlier half failed.
ok( 'earlier results are read back', (bool) preg_match( '/json_decode\(\s*\(string\) \$existing->deployment_log/', $import ) );
ok( 'and merged before the verdict', (bool) preg_match( '/\$all\s*=\s*array_merge\(\s*\$previous,\s*\$results\s*\)/', $import ) );
ok( 'the verdict counts the merged set', (bool) preg_match( '/resolve_status\(\s*count\( \$all \),\s*\$succeeded\s*\)/', $import ) );
// Only THIS batch's results go back, or the sender re-marks the same queue rows every time.
ok( 'but only this batch is reported back', (bool) preg_match( "/'results'\s*=>\s*\\\$results/", $import ) );

echo "\n=== dependency order is applied across the WHOLE push ===\n";
//
// Sorting inside a batch only orders it against itself: a post could go in batch 1 and the
// media it references in batch 2, and the post would import first — the exact dependency
// the order exists to prevent (§12).
ok( 'the rank is a single shared definition', false !== strpos( $import, 'function rank(' ) );
ok( 'the planner sorts by it', false !== strpos( $service, 'ImportManager::rank(' ) );
ok( 'and splits only after sorting', (bool) preg_match( '/usort\(.*?array_chunk\(/s', $service ) );
// Media first: those batches are the slow ones, and finishing them early keeps the
// remaining estimate honest rather than optimistic.
ok( 'media still ranks first', (bool) preg_match( "/'media'\s*=>\s*0/", $import ) );
ok( 'and options last', (bool) preg_match( "/'option'\s*=>\s*4/", $import ) );

echo "\n=== permission is settled once, on the whole selection ===\n";
//
// Deciding it per batch would let a push start, change Production, and THEN fail half way
// with a permission error.
ok( 'the plan is guarded', (bool) preg_match( '/function push_plan\(\).*?guard\(\s*Access::CAP_DEPLOY\s*\)/s', $ajax ) );
ok( 'each batch is guarded too', (bool) preg_match( '/function push_batch\(\).*?guard\(\s*Access::CAP_DEPLOY\s*\)/s', $ajax ) );
ok( 'and every batch re-checks ownership', (bool) preg_match( '/function push_batch\(\).*?\$this->queue_ids\(\)/s', $ajax ) );
// All three are Staging-only: a receiver must never be asked to send.
foreach ( array( 'push_plan', 'push_batch', 'push_cancel' ) as $method ) {
	ok( "$method refuses to run on Production", (bool) preg_match( '/function ' . $method . '\(\).*?require_staging\(\)/s', $ajax ) );
}

echo "\n=== cancelling puts Production back ===\n";

ok( 'cancel has its own endpoint', false !== strpos( $cancel, 'class CancelEndpoint' ) );
ok( 'it restores every revision', (bool) preg_match( '/foreach \( \$revisions as \$revision \).*?\$store->restore\(/s', $cancel ) );
// Not /rollback: that refuses a second attempt and KEEPS its revisions, because replaying a
// completed deployment could undo a later rollback. A cancel has neither property.
ok( 'and then removes them', false !== strpos( $cancel, 'delete_for_deployment' ) );
ok( 'so no Rollback button is left offering to re-apply it', false !== strpos( $store, 'function delete_for_deployment(' ) );

// ONE history line, not a deploy followed by an undo of it.
ok( 'the deployment is marked cancelled', false !== strpos( $cancel, 'STATUS_CANCELLED' ) );
ok( 'which is its own status', false !== strpos( src( 'src/History/DeploymentRepository.php' ), "STATUS_CANCELLED = 'cancelled'" ) );
ok( 'not reused from rollback', 0 === preg_match( '/STATUS_CANCELLED\s*=\s*.rolled_back./', src( 'src/History/DeploymentRepository.php' ) ) );

// Cancelling before the first batch lands is the best outcome, not an error to interpret.
ok( 'nothing-applied is a success, not a failure', (bool) preg_match( "/null === \\\$deployment.*?'ok' => true/s", $cancel ) );

echo "\n=== a batched push RECORDS ITSELF in Deployment History ===\n";
//
// ── THE BUG THIS EXISTS TO CATCH ───────────────────────────────────────────────────
//
// `send()` — the single-request path behind Compare & Sync's per-row Push — creates a
// deployment record. `deploy_batch()` never did. So every push started from Pending
// Changes, which is every push of media and very nearly every push of anything, completed
// and left NO trace in Deployment History.
//
// It took rollback with it: the History screen offers Rollback from a deployment row, and
// there was no row. A batched push could not be rolled back from this side at all.
/*
 * SCOPED TO deploy_batch()'s OWN BODY.
 *
 * `send()` further down the same file creates and updates a deployment record too, so an
 * unanchored search across the file passed whatever `deploy_batch()` did — which is exactly
 * how the missing record survived review in the first place. Every assertion below reads
 * only the slice between this method and the next one.
 */
// Bounded by the NEXT function declaration. `src()` runs php_strip_whitespace(), which
// collapses the formatting — so the boundary has to be a token, not indentation.
preg_match( '/function deploy_batch\(.*?(?=function [a-z_]+\()/s', $service, $slice );

$batch_body = (string) ( $slice[0] ?? '' );

ok( 'the deploy_batch body was isolated', '' !== $batch_body && false === strpos( $batch_body, 'function send(' ) );

ok( 'the batch opens a deployment record', false !== strpos( $batch_body, 'deployments->create(' ) );
ok( 'and updates it with the results', false !== strpos( $batch_body, 'deployments->update(' ) );

// ONE deployment per push, not one per batch — the property the whole file is about.
// Production groups by uuid in ImportManager::run(); this side has to agree, or a rollback
// would ask that side to undo a deployment it filed differently.
ok( 'it looks the record up by uuid first', false !== strpos( $batch_body, 'get_by_uuid( $uuid )' ) );
ok( 'creating one only when there is none', (bool) preg_match( '/null !== \$deployment\s*\?\s*\(int\) \$deployment->id\s*:\s*\$this->deployments->create\(/', $batch_body ) );

// Each batch returns only its own results, so writing them straight over the record would
// leave History describing the last batch and nothing else.
ok( 'later batches append rather than replace', false !== strpos( $batch_body, 'array_merge( $previous' ) );

// A transport failure has to be recorded too, or a push that never arrived looks like one
// that never happened.
ok( 'a failed batch is written to the record as well', (bool) preg_match( '/is_wp_error\( \$response \).*?deployments->update\(/s', $batch_body ) );

echo "\n=== and the status describes the WHOLE push ===\n";
//
// Recomputed over every batch's results, not taken from the last one: a push whose first
// batch failed and whose second succeeded has not succeeded, and can_rollback() reads this
// column to decide whether to offer the button.
require_once dirname( __DIR__ ) . '/src/History/DeploymentRepository.php';
require_once dirname( __DIR__ ) . '/src/Client/DeploymentService.php';

use IfsDeploy\Client\DeploymentService;
use IfsDeploy\History\DeploymentRepository;

$ok_result  = array( 'ok' => true );
$bad_result = array( 'ok' => false );

ok( 'all succeeded is a success', DeploymentRepository::STATUS_SUCCESS === DeploymentService::status_for( array( $ok_result, $ok_result ) ) );
ok( 'all failed is a failure', DeploymentRepository::STATUS_FAILED === DeploymentService::status_for( array( $bad_result, $bad_result ) ) );
ok( 'a mixture is PARTIAL', DeploymentRepository::STATUS_PARTIAL === DeploymentService::status_for( array( $ok_result, $bad_result ) ) );
ok( 'and order does not change that', DeploymentRepository::STATUS_PARTIAL === DeploymentService::status_for( array( $bad_result, $ok_result ) ) );
ok( 'nothing yet is still pending', DeploymentRepository::STATUS_PENDING === DeploymentService::status_for( array() ) );

/*
 * PARTIAL is deliberately not FAILED. Some objects reached Production and their snapshots
 * are real, so the deployment is still rollback-able — and HistoryPage::can_rollback()
 * refuses FAILED outright, so calling it that would strand the half that did land.
 */
$history = src( 'src/Admin/Pages/HistoryPage.php' );

// Checked against the EXCLUSION LIST specifically. `STATUS_PARTIAL` also appears in the
// status-badge map, so searching the whole file would pass whatever can_rollback() did.
preg_match( '/function can_rollback\(.*?in_array\(\s*\$status,\s*array\((.*?)\)/s', $history, $refused );

$refused_statuses = (string) ( $refused[1] ?? '' );

ok( 'the rollback refusal list was found', '' !== $refused_statuses );
ok( 'a partial push can still be rolled back', false === strpos( $refused_statuses, 'STATUS_PARTIAL' ) );
ok( 'while a failed one cannot', false !== strpos( $refused_statuses, 'STATUS_FAILED' ) );
ok( 'nor a cancelled one', false !== strpos( $refused_statuses, 'STATUS_CANCELLED' ) );

echo "\n=== Reset All Plugin Data clears state and NEVER content ===\n";
//
// The one thing this feature must not be mistaken for is something that deletes pages.
// "Reset all data" is a sentence people read as including their content, so the guarantee
// is asserted here rather than only promised in the copy.
$reset = src( 'src/Support/DataReset.php' );

foreach ( array( 'queue_table', 'deployments_table', 'revisions_table', 'api_log_table', 'api_addresses_table', 'nonces_table' ) as $table ) {
	ok( "it empties the {$table}", false !== strpos( $reset, $table . '()' ) );
}

// DELETE, not DROP. The plugin is still running and the very next page load writes to these.
ok( 'the tables survive, only their rows go', false === stripos( $reset, 'DROP TABLE' ) );

/*
 * The stamps are the half a reset is usually actually after: without removing them, every
 * object this site has deployed still says so, and the next push updates the far copy
 * instead of behaving like the first push it is meant to be.
 */
foreach ( array( '_ifs_deploy_origin_id', '_ifs_deploy_origin_site', '_ifs_deploy_source_url', '_ifs_deploy_src_sig' ) as $key ) {
	ok( "the {$key} stamp is removed", false !== strpos( $reset, $key ) );
}

ok( 'from posts', false !== strpos( $reset, 'wpdb->postmeta' ) );
ok( 'and from terms', false !== strpos( $reset, 'wpdb->termmeta' ) );

// NOTHING may touch the content tables themselves.
ok( 'it never deletes posts', false === strpos( $reset, 'wpdb->posts,' ) && false === strpos( $reset, 'wp_delete_post' ) );
ok( 'nor attachments', false === strpos( $reset, 'wp_delete_attachment' ) );
ok( 'nor terms', false === strpos( $reset, 'wp_delete_term' ) );
ok( 'nor options wholesale', 0 === preg_match( '/DELETE FROM \{\$wpdb->options\}(?!.*option_name LIKE)/s', $reset ) );

// The connection is kept unless asked for: re-pairing two sites by hand is a far bigger
// interruption than the reset is meant to be.
ok( 'the connection is kept by default', (bool) preg_match( '/if \( \$include_connection \)/', $reset ) );
ok( 'credentials only go when asked', (bool) preg_match( "/CONNECTION_OPTIONS = array\(\s*'ifs_deploy_credentials'/", $reset ) );

// Admin-only. Someone who may push content is not thereby someone who may erase every
// restore point on the site.
$ajax = src( 'src/Admin/Ajax.php' );
ok( 'only an administrator may run it', (bool) preg_match( '/function reset_data\(\).*?guard\( Access::CAP_MANAGE \)/s', $ajax ) );

// A reset that left no trace of itself would make the next report of "everything
// disappeared" impossible to explain.
ok( 'and it records that it happened', false !== strpos( $reset, 'All IFS Deploy data on this site was reset' ) );

echo "\n=== cancelling does NOT throw the work away ===\n";
//
// A cancel is a decision not to publish yet — not a decision to discard what was queued.
ok( 'rows go back to pending', false !== strpos( $queue, 'function restore_pending(' ) );
ok( 'with the status reset', (bool) preg_match( '/SET status = %s, deployed_hash = \'\'/', $queue ) );
ok( 'and the service calls it', false !== strpos( $service, 'restore_pending(' ) );

/*
 * deployed_hash is CLEARED rather than restored to its previous value.
 *
 * The column means "what Production last accepted", and after a revert we no longer know:
 * Production went back to a state this site never recorded. Leaving the just-deployed hash
 * would be an outright lie — the row would look already-pushed and could drop out of
 * Pending Changes on the next save, which is precisely what must not happen.
 */
ok( 'deployed_hash is cleared, not guessed at', (bool) preg_match( "/deployed_hash = ''/", $queue ) );

// Restored even when Production could not be reached: an unknown remote state must leave
// the row pending, never deployed.
ok( 'the rows are restored even if the undo failed', (bool) preg_match( '/\$restored = \$this->queue->restore_pending\(.*?is_wp_error\(\s*\$response\s*\)/s', $service ) );

echo "\n=== the progress the user sees is real ===\n";

ok( 'the browser drives the batches', false !== strpos( $js, 'function pushNext(' ) );
ok( 'and counts what actually completed', false !== strpos( $js, 'push.done += batch.length' ) );
// Nothing animates toward a number it has not been told.
ok( 'the percentage is computed from that', (bool) preg_match( '/Math\.round\(\s*\(\s*push\.done \/ push\.total\s*\)\s*\* 100\s*\)/', $js ) );

// A failing batch STOPS the push: continuing would pile more changes onto a Production
// that has already rejected one, and bury the message saying why.
ok( 'a failing batch stops the push', (bool) preg_match( '/if \( ! res \|\| ! res\.success \) \{.*?pushClose\(\);/s', $js ) );
// Marked before the request, so no further batch is sent while the undo runs.
ok( 'cancelling stops further batches immediately', (bool) preg_match( '/push\.cancelling = true;.*?ifs_deploy_push_cancel/s', $js ) );
ok( 'and pushNext refuses to run once cancelling', (bool) preg_match( '/function pushNext\(\)\s*\{\s*if \( ! push \|\| push\.cancelling \)/', $js ) );

// Every id, not just the ones still queued — a cancel has to put back the rows that
// completed batches already marked deployed.
ok( 'the cancel carries every id from the plan', false !== strpos( $js, 'all: ids' ) );

echo "\n=== the dialog names the work BEFORE doing it ===\n";
//
// The description cannot come from a batch's RESPONSE: by then the batch is finished, so
// the dialog would only ever name things it had already done — no use during the long
// batch, which is the one people are watching. So the plan carries each item's type and
// title up front.
ok( 'the plan carries each type', (bool) preg_match( "/'type'\s*=>\s*\(string\) \\\$row->object_type/", $service ) );
ok( 'and each title', (bool) preg_match( "/'title'\s*=>\s*\(string\) \\\$row->object_title/", $service ) );
ok( 'the phase is set before the request', (bool) preg_match( '/push\.phase = pushPhase\(.*?\$\.post\(/s', $js ) );

foreach ( array( 'media', 'term', 'post', 'menu', 'option' ) as $type ) {
	ok( "$type has a phase label", (bool) preg_match( '/\b' . $type . ':\s*IfsDeploy\.i18n\.pushPhase/i', $js ) );
}

// A batch is ONE request, so its items are in flight together. Naming a single one would
// claim a precision the design does not have.
ok( 'a multi-item batch says "and N more"', false !== strpos( $js, 'pushItemMore' ) );

echo "\n=== the estimate is measured, not invented ===\n";
//
// Types are wildly unequal — a media item downloads a file and regenerates every image
// size; an option is one row. A flat average would be wrong in the one direction that
// matters, because the plan puts media FIRST: an average taken during the slow part would
// go on being applied to the fast remainder and promise far more time than is left.
ok( 'time is measured per type', (bool) preg_match( '/push\.spent\[\s*batch\[0\]\.type\s*\]/', $js ) );
ok( 'from the real elapsed time', (bool) preg_match( '/spent\.ms \+= \( new Date\(\) \)\.getTime\(\) - startedAt/', $js ) );
ok( 'and the remainder is priced by its own type', (bool) preg_match( '/seen && seen\.items \? seen\.ms \/ seen\.items : overall/', $js ) );

// Nothing to base a number on before the first batch finishes, and inventing one is what
// makes a progress dialog untrustworthy.
ok( 'no estimate before a batch completes', (bool) preg_match( "/if \( ! totalItems \) \{\s*return '';/", $js ) );
ok( 'seconds and minutes are worded differently', false !== strpos( $js, 'pushEtaMinutes' ) && false !== strpos( $js, 'pushEtaSeconds' ) );

// While cancelling, the phase and item describe work that is no longer happening.
ok( 'cancelling replaces them rather than sitting under them', (bool) preg_match( '/push\.cancelling \? \'\' : push\.phase/', $js ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
