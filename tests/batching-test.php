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
 * SCOPED TO send_batch()'s OWN BODY.
 *
 * `send_batch()` is the shared tail of a push: lock, open the deployment record, transmit,
 * apply the results. Both entry points call it — `deploy_batch()` from queue rows and
 * `deploy_post_batch()` from Compare & Sync's post ids — so the deployment record, the
 * locking and both halves of the cancellation race are written once rather than twice.
 *
 * Bounded by the NEXT function declaration. `src()` runs php_strip_whitespace(), which
 * collapses the formatting — so the boundary has to be a token, not indentation.
 */
preg_match( '/function send_batch\(.*?(?=function [a-z_]+\()/s', $service, $slice );

$batch_body = (string) ( $slice[0] ?? '' );

ok( 'the send_batch body was isolated', '' !== $batch_body && false === strpos( $batch_body, 'function send(' ) );

// Both sources must go through it, or one of them would quietly lack the record and the
// cancellation checks.
ok( 'the queue path goes through it', (bool) preg_match( '/function deploy_batch\(.*?send_batch\( \$uuid, \$objects, \$mapped, \$user_id, \$failed \)/s', $service ) );
ok( 'and so does the Compare path', (bool) preg_match( '/function deploy_post_batch\(.*?send_batch\( \$uuid, \$objects, \$mapped, \$user_id, 0 \)/s', $service ) );

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

echo "\n=== a batch that outlives the cancel cannot un-restore the rows ===\n";
//
// ── THE BUG THIS EXISTS TO CATCH ───────────────────────────────────────────────────
//
// Reported as: cancelling a push REMOVED items from Pending Changes. It is a race between
// two HTTP requests, not a logic error, which is why the restore looked correct in
// isolation.
//
//   1. batch N is in flight; Production is applying it
//   2. the user presses Cancel
//   3. /cancel reverts what had landed, and restore_pending() puts every row back
//   4. batch N finishes — and apply_results() marks its rows DEPLOYED, undoing step 3
//   5. the page reloads and those rows are gone
//
// `push.cancelling` in the browser only stops the NEXT batch being queued. A request
// already on the wire is not stopped by anything the browser does, so the flag has to live
// where both processes can see it: the deployment record.
ok( 'the cancelled state is read back from the record', false !== strpos( $batch_body, 'is_cancelled(' ) );
ok( 'and it is a database read, not a variable', (bool) preg_match( '/is_cancelled\(\s*\$this->deployments->get_by_uuid\( \$uuid \)\s*\)/', $batch_body ) );

// Checked BOTH sides of the request. Before it, so a batch that has not left never does;
// after it, so one that already landed is undone.
ok( 'a batch is refused before sending if the push is already cancelled', (bool) preg_match( '/is_cancelled\( \$deployment \)/', $batch_body ) );
ok( 'and results are NOT applied when it was cancelled mid-flight', (bool) preg_match( '/is_cancelled\(.*?restore_pending\(.*?apply_results\(/s', $batch_body ) );

// Two things are needed, not one: the rows go back, AND Production is told to undo this
// batch too — it applied after the cancel had already swept the deployment.
ok( 'the late batch is handed to the shared revert', false !== strpos( $batch_body, 'revert_after_cancel(' ) );
ok( 'which re-cancels on Production', (bool) preg_match( "/function revert_after_cancel\(.*?post\(\s*'cancel'/s", $service ) );
ok( 'and puts the rows back', (bool) preg_match( '/function revert_after_cancel\(.*?restore_pending\(/s', $service ) );

/*
 * ── A TIMEOUT IS NOT A FAILURE TO DEPLOY ──────────────────────────────────────────
 *
 * When Production takes longer than this side waits — a batch of media downloads
 * routinely does — the request returns an error while Production carries on and applies
 * every object in it. The transport-error branch used to return without re-cancelling,
 * AND wrote STATUS_FAILED over STATUS_CANCELLED, erasing the flag that tells a later batch
 * not to apply itself. So the push was cancelled, Production published it anyway, and the
 * record no longer said it had been cancelled.
 *
 * The cancellation check therefore has to come BEFORE the error branch, not after it.
 */
$at_cancel_check = strpos( $batch_body, 'is_cancelled( $this->deployments->get_by_uuid( $uuid ) )' );
$at_error_branch = strpos( $batch_body, 'is_wp_error( $response )' );

ok( 'cancellation is checked before a transport error is concluded', false !== $at_cancel_check && false !== $at_error_branch && $at_cancel_check < $at_error_branch );
ok( 'and a timeout is reported as such to the revert', false !== strpos( $batch_body, 'revert_after_cancel( $uuid, $mapped, is_wp_error( $response ) )' ) );

/*
 * CANCELLED IS FINAL, enforced in the repository rather than at each call site — there are
 * several, and the next one added would not know to check.
 */
$repo = src( 'src/History/DeploymentRepository.php' );

ok( 'a cancelled deployment cannot be written over', (bool) preg_match( '/function update\(.*?STATUS_CANCELLED === \$this->status_of\( \$id \).*?return;/s', $repo ) );
ok( 'nor re-statused by anything but another cancel', (bool) preg_match( '/function set_status\(.*?STATUS_CANCELLED !== \$status && self::STATUS_CANCELLED === \$this->status_of\( \$id \)/s', $repo ) );
// `$cancel` is the Production-side endpoint: it reports "nothing to undo" as a SUCCESS,
// which is what makes calling it a second time safe.
ok( 'which is safe because /cancel is idempotent', false !== strpos( $cancel, 'nothing_applied' ) );

/*
 * ── CANCELLING IS A SEQUENCE, AND THE ORDER IS THE BUG ─────────────────────────────
 *
 * Reported as: cancel karne ke baad bhi kuch changes deploy ho jate hain.
 *
 * A push is not one request. Pressing Cancel stops the browser QUEUEING further batches;
 * it cannot stop one already on the wire, and it cannot stop Production finishing the one
 * it is processing. `deploy_batch()` reads the deployment record to decide whether to
 * apply a batch's results — so the moment that flag is written decides the outcome.
 *
 * It used to be written AFTER the `/cancel` HTTP call returned. That call takes seconds,
 * and a batch finishing inside that window read "not cancelled", applied its objects to
 * Production and marked its rows deployed. The operator pressed Cancel and watched part of
 * the push go live regardless.
 *
 * Ordering the local write FIRST closes the window completely: it is a database write, so
 * it lands before any concurrent batch can look at it.
 */
$cancellation = src( 'src/Client/PushCancellation.php' );

preg_match( '/function run\( string \$uuid.*?(?=function [a-z_]+\()/s', $cancellation, $run_slice );

$run_body = (string) ( $run_slice[0] ?? '' );

ok( 'the cancel sequence has its own class', false !== strpos( $cancellation, 'class PushCancellation' ) );
ok( 'and the run body was isolated', '' !== $run_body );

// THE ORDER. Marking cancelled must come before the queue restore, and both before any
// network call — the sweep is where the network happens.
$at_mark    = strpos( $run_body, 'mark_cancelled(' );
$at_restore = strpos( $run_body, 'restore_pending(' );
$at_sweep   = strpos( $run_body, 'sweep(' );

ok( 'it records the cancellation first', false !== $at_mark && false !== $at_restore && $at_mark < $at_restore );
ok( 'and before it touches Production', false !== $at_sweep && $at_mark < $at_sweep );
ok( 'the local write never waits on the network', false === strpos( $run_body, "post( 'cancel'" ) );

// The flag must exist from the MOMENT Cancel is pressed, not only once some batch has
// created a record — cancelling before the first batch lands is exactly when none exists.
ok( 'cancel records the state even with no record yet', (bool) preg_match( '/get_by_uuid\( \$uuid \).*?deployments->create\(\s*\$uuid.*?STATUS_CANCELLED/s', $cancellation ) );
ok( 'and it still restores the queue rows', false !== strpos( $run_body, 'restore_pending( $queue_ids )' ) );

/*
 * ── AND ONE SWEEP CANNOT BE ENOUGH ─────────────────────────────────────────────────
 *
 * A batch that lands DURING the revert creates fresh snapshots on Production after the
 * sweep has already passed over them. So the revert is repeated until Production reports
 * it found nothing left to undo — that answer is the confirmation, and without it the
 * cancel is only probably complete.
 */
ok( 'the revert repeats until nothing is left', false !== strpos( $cancellation, 'MAX_SWEEPS' ) );
/*
 * AN EMPTY PASS IS EVIDENCE, NOT A CONCLUSION.
 *
 * "Nothing to revert" has two opposite meanings: everything has been undone, or the batch
 * has not landed YET. The second is common — cancelling quickly reaches Production before
 * its import has registered anything, and a single empty pass then reported "nothing had
 * reached Production" moments before the whole push went live.
 */
ok( 'an empty pass only counts toward the verdict', (bool) preg_match( '/\$clean = \( 0 === \$this_pass \) \? \$clean \+ 1 : 0;/', $cancellation ) );
ok( 'and two consecutive ones are required', (bool) preg_match( '/\$clean >= self::CLEAN_PASSES/', $cancellation ) );
ok( 'with a pause between, so Production can finish', false !== strpos( $cancellation, 'sleep( self::PAUSE_SECONDS )' ) );

/*
 * And a cancel must not be undone by the very next page load: the verifier resolves pending
 * rows that match Production, which after a cancel is exactly the state a half-finished
 * revert leaves behind. Those rows are exempt for a short grace period.
 */
$verifier = src( 'src/Client/QueueVerifier.php' );

ok( 'restored rows are marked protected', false !== strpos( $queue, 'function is_protected(' ) );
ok( 'restore_pending marks them', (bool) preg_match( '/function restore_pending\(.*?self::protect\( \$ids \)/s', $queue ) );
ok( 'and the verifier leaves them alone', false !== strpos( $verifier, 'QueueRepository::is_protected(' ) );
ok( 'the exemption expires on its own', false !== strpos( $queue, 'PROTECTED_FOR' ) );

/*
 * Running out of passes is a FAILURE, not a success with a caveat. The one thing a cancel
 * must never do is report Production clean when it has not been shown to be.
 */
ok( 'exhausting the passes is reported as a failure', (bool) preg_match( "/MAX_SWEEPS.*?'ok'\s*=>\s*false/s", $cancellation ) );

/*
 * And the cancel must never be REFUSED for ownership: aborting there would return before
 * restore_pending() ran, stranding the already-pushed rows as deployed — the exact outcome
 * the cancel exists to prevent, reached via a permission message.
 */
$ajax_src = src( 'src/Admin/Ajax.php' );
ok( 'the cancel narrows silently rather than refusing', (bool) preg_match( '/function push_cancel\(.*?queue_ids\( false \)/s', $ajax_src ) );
ok( 'while an ordinary push still refuses', (bool) preg_match( '/function queue_ids\( bool \$refuse = true \)/', $ajax_src ) );

echo "\n=== Compare & Sync groups, in the order a reader needs them ===\n";
//
// Missing, then Production-only, then Different, then In sync: from the groups where the
// two sites disagree about what EXISTS, through the one where they disagree about content,
// down to the one where they agree and there is nothing to do.
$compare_src = src( 'src/Admin/Pages/ComparePage.php' );

preg_match_all( "/->objects_table\(\s*__\( '([^']+)'|->prod_only_table\(/", $compare_src, $order );

$tables = array();
foreach ( $order[0] as $k => $whole ) {
	$tables[] = '' !== $order[1][ $k ] ? $order[1][ $k ] : 'Only on Production';
}

ok( 'four groups are rendered', 4 === count( $tables ) );
ok( '1st is Not on Production',  'Not on Production' === ( $tables[0] ?? '' ) );
ok( '2nd is Only on Production', 'Only on Production' === ( $tables[1] ?? '' ) );
ok( '3rd is Different',          0 === strpos( (string) ( $tables[2] ?? '' ), 'Different' ) );
ok( '4th is In sync',            'In sync' === ( $tables[3] ?? '' ) );

/*
 * THE CARDS MUST MATCH THE TABLES.
 *
 * They are links INTO those tables now, so a summary that lists groups in one order and
 * scrolls to them in another is worse than one that does not scroll at all.
 */
preg_match( '/function summary\(.*?(?=function [a-z_]+\()/s', $compare_src, $summary_slice );

$summary_body = (string) ( $summary_slice[0] ?? '' );

$card_order = array();
if ( preg_match_all( "/->card\(\s*__\( '([^']+)'/", $summary_body, $cards ) ) {
	$card_order = $cards[1];
}

ok( 'the cards follow the same order', array( 'Not on Production', 'Only on Production', 'Different', 'In sync' ) === $card_order );

echo "\n=== a card scrolls to its own table ===\n";

ok( 'each group carries an anchor',   false !== strpos( $compare_src, 'ifs-deploy-group-' ) );
ok( 'and the card names that anchor', false !== strpos( $compare_src, 'data-scroll-to' ) );

/*
 * A card is a BUTTON only when its table was rendered. Three of the four groups are skipped
 * entirely when empty, so a card showing 0 would otherwise be a control that silently does
 * nothing — which reads as broken rather than as empty.
 */
ok( 'an empty group gets a plain card',  false !== strpos( $compare_src, "if ( '' === \$group || ! \$has_rows ) {" ) );
ok( 'and a populated one gets a button', false !== strpos( $compare_src, '<button type="button" class="ifs-deploy-card is-linked"' ) );

// Scrolling alone leaves a keyboard user behind: the page moves and their next Tab carries
// on from the card, not from the table they asked for.
ok( 'the target is focused as well as scrolled to', false !== strpos( $js, 'target.focus( { preventScroll: true } )' ) );
ok( 'and reduced motion is honoured',               false !== strpos( $js, 'prefers-reduced-motion' ) );

echo "\n=== the In sync group holds its rows back ===\n";
//
// On a healthy site this is nearly every page, and thousands of table rows the reader never
// looks at still cost a full layout and paint.
ok( 'only the first rows are rendered', (bool) preg_match( '/INITIAL_ROWS = 10/', $compare_src ) );
ok( 'and more arrive 50 at a time',     (bool) preg_match( '/REVEAL_BATCH = 50/', $compare_src ) );
ok( 'In sync is the group limited',     (bool) preg_match( "/'in_sync',\s*self::INITIAL_ROWS/s", $compare_src ) );

/*
 * ── WHY A <template> AND NOT A FETCH, OR CSS ──────────────────────────────────────
 *
 * The comparison is deliberately UNCACHED, so a "show more" request would re-run the whole
 * thing — signing another call to Production for up to 2000 posts to reveal fifty rows. And
 * a `display:none` row is still parsed and still laid out, so hiding buys nothing.
 *
 * <template> content is parsed but never rendered until it is moved into the document: the
 * rows are already here, and cost nothing until asked for.
 */
ok( 'the overflow sits in a template', false !== strpos( $compare_src, '<template class="ifs-deploy-more-rows">' ) );
ok( 'revealed by moving nodes, not fetching', false !== strpos( $js, 'template.content.firstElementChild' ) );
preg_match( "/'click', '\.ifs-deploy-show-more'.*?
		\} \);/s", $js, $reveal_handler );

$reveal_body = (string) ( $reveal_handler[0] ?? '' );

ok( 'the handler was found to check',     '' !== $reveal_body );
ok( 'no request is made to reveal them',  false === strpos( $reveal_body, '.post(' ) && false === strpos( $reveal_body, '.ajax(' ) );

// One insertion, not one per row — appending individually re-lays out the table each time.
ok( 'the batch is inserted in one go', false !== strpos( $js, 'createDocumentFragment()' ) );
ok( 'and the button goes when nothing is left', false !== strpos( $js, "$btn.closest( '.ifs-deploy-more' ).remove()" ) );

echo "\n=== Compare & Sync can push several rows at once ===\n";
//
// Compare is NOT the queue. Its rows are objects whose content differs from Production's,
// which includes things nobody edited on Staging — so most of them have no queue row, and
// the queue planner cannot be reused.
$compare_page = src( 'src/Admin/Pages/ComparePage.php' );

ok( 'rows carry a checkbox', false !== strpos( $compare_page, 'ifs-deploy-compare-item' ) );
ok( 'with a select-all', false !== strpos( $compare_page, 'ifs-deploy-compare-all' ) );
ok( 'and a bulk push button', false !== strpos( $compare_page, 'ifs-deploy-compare-push' ) );

/*
 * PER GROUP, not per screen. "Different" overwrites content on Production and "Not on
 * Production" creates content that is not there — two different actions, so one shared
 * selection could not be confirmed honestly.
 */
ok( 'selection is scoped to its group', false !== strpos( $compare_page, 'data-group' ) );
ok( 'the two groups are named', false !== strpos( $compare_page, "'different'" ) && false !== strpos( $compare_page, "'missing'" ) );

// Only the actionable tables get checkboxes — "In sync" has nothing to push.
ok( 'checkboxes are gated on the action column', (bool) preg_match( '/if \( \$with_action \) \{\s*printf\(/s', $compare_page ) );

echo "\n=== and it goes through the same batched pusher ===\n";

ok( 'there is a plan endpoint for posts', (bool) preg_match( '/function compare_plan\(\).*?plan_posts\(/s', $ajax ) );
ok( 'and a batch endpoint', (bool) preg_match( '/function compare_batch\(\).*?deploy_post_batch\(/s', $ajax ) );

/*
 * Held to the RESTRICTED-SCREEN capability, not merely to `manage_options`.
 *
 * Compare & Sync is one of the three screens a site can limit to named administrators, and
 * hiding a screen means nothing if its AJAX actions stay open — `admin-ajax.php` does not
 * care which tab you can see. No ownership narrowing on top, because Compare is not a list
 * of anyone's pending work: it is the state of the two sites.
 */
foreach ( array( 'compare_plan', 'compare_batch' ) as $method ) {
	ok( "{$method} needs the restricted-screen capability", (bool) preg_match( '/function ' . $method . '\(\): void \{\s*\$this->guard\( Access::CAP_RESTRICTED \);/', $ajax ) );
	ok( "{$method} refuses to run on Production", (bool) preg_match( '/function ' . $method . '\(\).*?require_staging\(\)/s', $ajax ) );
}

/*
 * The ids are POST ids, so they must never be read as queue row ids — and this has to be
 * checked inside post_ids()'s OWN body. `queue_ids()` sits in the same file and does narrow
 * by ownership, so an unanchored search would find its call and pass regardless.
 */
preg_match( '/function post_ids\(\): array \{.*?(?=function [a-z_]+\()/s', $ajax, $post_ids_slice );

$post_ids_body = (string) ( $post_ids_slice[0] ?? '' );

ok( 'the post_ids body was isolated', '' !== $post_ids_body );
ok( 'it reads post ids', false !== strpos( $post_ids_body, "\$_POST['post_ids']" ) );
ok( 'and does NOT narrow by ownership', false === strpos( $post_ids_body, 'ids_owned_by' ) );

echo "\n=== a Compare push must never restore queue rows by post id ===\n";
//
// `restore_pending()` resets queue rows BY ROW ID. Sending post ids to it on a cancel would
// flip whichever unrelated rows happened to carry those numbers back to pending — silently
// resurrecting changes nobody asked for.
ok( 'the sources declare whether they restore', (bool) preg_match( '/compare: \{.*?restores: false/s', $js ) );
ok( 'the queue source does', (bool) preg_match( '/queue: \{.*?restores: true/s', $js ) );
ok( 'and the cancel honours it', false !== strpos( $js, 'push.source.restores ? push.all : []' ) );

// Compare sends post ids under their own name, so a mix-up cannot happen server-side either.
ok( 'the compare source names its id field', (bool) preg_match( "/compare: \{.*?idField: 'post_ids'/s", $js ) );

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
// Reset lives on Settings, so it is held to the same capability that screen is.
ok( 'only a permitted administrator may run it', (bool) preg_match( '/function reset_data\(\).*?guard\( Access::CAP_RESTRICTED \)/s', $ajax ) );

// A reset that left no trace of itself would make the next report of "everything
// disappeared" impossible to explain.
ok( 'and it records that it happened', false !== strpos( $reset, 'All Copperleaf Deploy data on this site was reset' ) );

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

/*
 * Restored even when Production could not be reached: an unknown remote state must leave
 * the row pending, never deployed.
 *
 * The restore now happens BEFORE the sweep that talks to Production, so the guarantee is
 * structural rather than a matter of which branch runs — there is no path through run()
 * that reaches the network without having put the rows back first.
 */
ok(
	'the rows are restored before Production is contacted at all',
	false !== $at_restore && false !== $at_sweep && $at_restore < $at_sweep
);
ok(
	'and a transport failure still reports the rows are safe',
	false !== strpos( $cancellation, 'your changes are still listed here, but Production could not be reached' )
);

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
