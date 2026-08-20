<?php
declare(strict_types=1);

/*
 * WHAT STATUS CONTENT GETS WHEN IT ARRIVES ON PRODUCTION.
 *
 * ── THE REQUIREMENT ────────────────────────────────────────────────────────────────
 *
 * A team can require that nothing publishes itself: new content arrives as a draft and a
 * person on the live site decides when it goes public. Two rules, and the second is the
 * one that is easy to get wrong:
 *
 *   1. NEW content gets the configured status, whatever Staging says.
 *   2. EXISTING content keeps the status it has — UNLESS somebody deliberately changed the
 *      status on Staging, which is a change like any other and must be applied.
 *
 * Rule 2 cannot be answered by comparing the two sites: once rule 1 has fired they are
 * SUPPOSED to differ, and "they differ" says nothing about who changed what. It is
 * answered by comparing against what Staging said LAST time.
 */

function __( $s, $d = '' ) { return $s; }
function esc_html( $s ) { return $s; }
function sanitize_key( $k ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $k ) ); }

$GLOBALS['opts'] = array();
function get_option( $n, $d = false ) { return $GLOBALS['opts'][ $n ] ?? $d; }
function update_option( $n, $v, $a = null ) { $GLOBALS['opts'][ $n ] = $v; return true; }

$GLOBALS['meta'] = array();
function get_post_meta( $id, $key = '', $single = false ) {
	$v = $GLOBALS['meta'][ (int) $id ][ $key ] ?? '';
	return $single ? $v : ( '' === $v ? array() : array( $v ) );
}
function update_post_meta( $id, $k, $v, $prev = '' ) { $GLOBALS['meta'][ (int) $id ][ $k ] = $v; return true; }

/** The statuses this site has. `workflow` stands in for one a plugin registered. */
$GLOBALS['stati'] = array(
	'publish'    => 'Published',
	'draft'      => 'Draft',
	'pending'    => 'Pending Review',
	'private'    => 'Private',
	'future'     => 'Scheduled',
	'trash'      => 'Trash',
	'inherit'    => 'Inherit',
	'auto-draft' => 'Auto Draft',
	'workflow'   => 'Awaiting Legal',
);

function get_post_stati( $args = array(), $output = 'names' ) {
	if ( 'objects' === $output ) {
		$out = array();
		foreach ( $GLOBALS['stati'] as $name => $label ) {
			$out[ $name ] = (object) array( 'name' => $name, 'label' => $label );
		}
		return $out;
	}
	return array_keys( $GLOBALS['stati'] );
}
function get_post_status_object( $status ) {
	$all = get_post_stati( array(), 'objects' );
	return $all[ $status ] ?? null;
}
function post_type_exists( $type ) { return in_array( $type, array( 'post', 'page', 'event' ), true ); }

// DebugLog writes a warning when the configured status cannot be used, so it needs enough
// of a WordPress to store one.
function current_time( $t = 'mysql', $g = 0 ) { return '2026-08-18 10:00:00'; }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function apply_filters( $tag, $value, ...$args ) { return $value; }

require __DIR__ . '/../src/Support/Logger.php';
require __DIR__ . '/../src/Support/Config.php';
require __DIR__ . '/../src/Support/DebugLog.php';
require __DIR__ . '/../src/Support/PublishPolicy.php';

use IfsDeploy\Support\PublishPolicy;

$pass = 0; $fail = 0;
function ok( string $n, bool $c ): void {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "PASS  $n\n"; } else { $fail++; echo "FAIL  $n\n"; }
}

echo "=== the dropdown is built from what this site actually has ===\n";

$choices = PublishPolicy::choices();

ok( 'same-as-source is always offered', array_key_exists( PublishPolicy::SOURCE, $choices ) );
ok( 'draft is offered',                 array_key_exists( 'draft', $choices ) );
ok( 'pending review is offered',        array_key_exists( 'pending', $choices ) );
ok( 'private is offered',               array_key_exists( 'private', $choices ) );
ok( 'and a status a plugin registered', array_key_exists( 'workflow', $choices ) );

/*
 * Four are withheld whatever WordPress reports, each for its own reason:
 *
 *   auto-draft — core deletes these on a schedule, so content would simply vanish.
 *   inherit    — belongs to attachments; a post given it is invisible everywhere.
 *   trash      — arriving in the bin is not "awaiting review"; the deploy looks failed.
 *   future     — means scheduled, and a post whose date is not in the future is
 *                re-published by core immediately, so choosing it silently does nothing.
 */
foreach ( array( 'auto-draft', 'inherit', 'trash', 'future' ) as $never ) {
	ok( "{$never} is never offered", ! array_key_exists( $never, $choices ) );
}

echo "\n=== the default reproduces Staging, exactly as before the setting existed ===\n";

$GLOBALS['opts'] = array();

ok( 'unset means same-as-source',     PublishPolicy::SOURCE === PublishPolicy::get() );
ok( 'and the policy is not active',   false === PublishPolicy::is_active() );
ok( 'so a new page keeps its status', 'publish' === PublishPolicy::status_for_new( 'publish', 'page' ) );

// A stored status that no longer exists — a workflow plugin switched off after it was
// chosen. Applying it would make content unreachable in wp-admin, so it falls back.
$GLOBALS['opts']['ifs_deploy_new_status'] = 'gone_away';
ok( 'an unregistered stored status falls back', PublishPolicy::SOURCE === PublishPolicy::get() );

echo "\n=== rule 1: NEW content gets the configured status ===\n";

PublishPolicy::set( 'draft' );

ok( 'the setting stores',                  'draft' === PublishPolicy::get() );
ok( 'and the policy is active',            true === PublishPolicy::is_active() );
ok( 'a published page arrives as a draft', 'draft' === PublishPolicy::status_for_new( 'publish', 'page' ) );
ok( 'a draft arrives as a draft too',      'draft' === PublishPolicy::status_for_new( 'draft', 'page' ) );
ok( 'and so does a custom post type',      'draft' === PublishPolicy::status_for_new( 'publish', 'event' ) );

PublishPolicy::set( 'pending' );
ok( 'any chosen status is used', 'pending' === PublishPolicy::status_for_new( 'publish', 'page' ) );

/*
 * FALLBACK IS DRAFT, NOT THE INCOMING STATUS.
 *
 * If the configured status cannot be used, the safe answer is the one the operator was
 * reaching for — "not visible yet" — never the one they were trying to avoid. Falling back
 * to the incoming status would publish the very thing the policy exists to hold back, and
 * would do it silently.
 */
PublishPolicy::set( 'workflow' );
ok( 'an unknown post type falls back to draft', 'draft' === PublishPolicy::status_for_new( 'publish', 'no_such_type' ) );

echo "\n=== rule 2: EXISTING content keeps its own status ===\n";

PublishPolicy::set( 'draft' );
$GLOBALS['meta'] = array();

/*
 * The scenario the requirement describes. A page arrived as a draft; somebody on the live
 * site published it. Staging still says publish, and has said so all along.
 */
update_post_meta( 55, PublishPolicy::LAST_SOURCE_STATUS_META, 'publish' );

ok(
	'an unchanged source status is NOT applied',
	false === PublishPolicy::respects_source_status( 55, 'publish' )
);

// And the other half: somebody unpublished it on Staging on purpose. That IS a change.
ok(
	'a changed source status IS applied',
	true === PublishPolicy::respects_source_status( 55, 'draft' )
);

// Nothing recorded — the object predates the policy, so there is no evidence either way.
// Applying the incoming status is the old behaviour and the safer reading of "unknown".
$GLOBALS['meta'] = array();
ok(
	'with nothing recorded, the source wins',
	true === PublishPolicy::respects_source_status( 55, 'publish' )
);

/*
 * With the policy OFF the two sites are meant to mirror each other, so this must not
 * change anything for anyone who has not opted in.
 */
PublishPolicy::set( PublishPolicy::SOURCE );
update_post_meta( 55, PublishPolicy::LAST_SOURCE_STATUS_META, 'publish' );

ok(
	'the rule does not apply when the policy is off',
	true === PublishPolicy::respects_source_status( 55, 'publish' )
);

echo "\n=== the importer applies both rules in the right places ===\n";
//
// Read from source: reaching these needs a whole WordPress. What matters is WHERE each
// rule is applied — the status is decided on insert, and only carried through on update.
$importer = (string) php_strip_whitespace( __DIR__ . '/../src/Import/PostImporter.php' );

ok( 'the insert branch asks the policy',          false !== strpos( $importer, 'PublishPolicy::status_for_new(' ) );
ok( 'the update branch asks the other rule',      false !== strpos( $importer, 'PublishPolicy::respects_source_status(' ) );
ok( 'and drops the field when it must not apply', false !== strpos( $importer, 'unset( $postarr[' ) );

// Recorded on EVERY import, whatever was actually applied — the next push compares
// against it, and it records the SOURCE's value, not this site's.
ok( 'what Staging said is recorded each time', false !== strpos( $importer, 'PublishPolicy::LAST_SOURCE_STATUS_META, $incoming_status' ) );

echo "\n=== it is a Production-side decision ===\n";
//
// A policy set on Staging would mean the SENDING site choosing when the live site
// publishes — the authority this exists to take away from it.
$settings = (string) php_strip_whitespace( __DIR__ . '/../src/Admin/Pages/SettingsPage.php' );
$exporter = (string) php_strip_whitespace( __DIR__ . '/../src/Export/PostExporter.php' );

ok( 'the setting is offered on Production only', (bool) preg_match( '/function publish_policy\(.*?Config::is_production\(\)/s', $settings ) );
ok( 'and Staging is told where it lives',        (bool) preg_match( '/function publish_policy\(.*?Set this on the Production site/s', $settings ) );
ok( 'nothing about it travels in a package',     false === strpos( $exporter, 'PublishPolicy' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
