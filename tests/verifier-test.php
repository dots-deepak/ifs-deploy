<?php
declare(strict_types=1);

/*
 * The two decisions QueueVerifier makes, tested directly. Both are pure, so no
 * WordPress is needed — the plumbing around them (export → probe → HTTP → loop) is
 * straightforward, while these are the parts where a mistake either silently loses
 * queued work or leaves the list stale.
 */

require __DIR__ . '/../src/Support/SyncCheck.php';
require __DIR__ . '/../src/Client/QueueVerifier.php';

use IfsDeploy\Client\QueueVerifier;

$pass = 0;
$fail = 0;

function ok( string $name, bool $condition ): void {
	global $pass, $fail;
	if ( $condition ) {
		++$pass;
		echo "PASS  $name\n";
	} else {
		++$fail;
		echo "FAIL  $name\n";
	}
}

function row( string $type = 'post', string $action = 'update', int $object_id = 10 ): object {
	return (object) array( 'object_type' => $type, 'action' => $action, 'object_id' => $object_id );
}

echo "=== which rows can be verified at all ===\n";
ok( 'post update is verifiable', QueueVerifier::is_verifiable_row( row() ) );
ok( 'queued DELETE is not', ! QueueVerifier::is_verifiable_row( row( 'post', 'delete' ) ) );
ok( 'media is not', ! QueueVerifier::is_verifiable_row( row( 'media' ) ) );
ok( 'term is not', ! QueueVerifier::is_verifiable_row( row( 'term' ) ) );
ok( 'option is not', ! QueueVerifier::is_verifiable_row( row( 'option' ) ) );
ok( 'menu is not', ! QueueVerifier::is_verifiable_row( row( 'menu' ) ) );
ok( 'missing object id is not', ! QueueVerifier::is_verifiable_row( row( 'post', 'update', 0 ) ) );
ok( 'malformed row is not', ! QueueVerifier::is_verifiable_row( (object) array() ) );

echo "\n=== when a row may be cleared ===\n";
ok(
	'THE REPORTED CASE: signatures match -> clear',
	QueueVerifier::should_clear( 'same', array( 'found' => true, 'signature' => 'same', 'deployed_sig' => '' ) )
);
ok(
	'matches the deployed stamp -> clear',
	QueueVerifier::should_clear( 'stamped', array( 'found' => true, 'signature' => 'filtered-on-prod', 'deployed_sig' => 'stamped' ) )
);

echo "\n=== when it must NOT be cleared (fails closed) ===\n";
ok(
	'genuinely different -> keep',
	! QueueVerifier::should_clear( 'staging', array( 'found' => true, 'signature' => 'production', 'deployed_sig' => '' ) )
);
ok(
	'not on Production yet -> keep',
	! QueueVerifier::should_clear( 'x', array( 'found' => false, 'signature' => '', 'deployed_sig' => '' ) )
);
ok(
	'Production answered with nothing -> keep',
	! QueueVerifier::should_clear( 'x', array() )
);
ok(
	'found but no signatures at all -> keep',
	! QueueVerifier::should_clear( 'x', array( 'found' => true, 'signature' => '', 'deployed_sig' => '' ) )
);
ok(
	'no local signature -> keep',
	! QueueVerifier::should_clear( '', array( 'found' => true, 'signature' => 'anything', 'deployed_sig' => '' ) )
);
ok(
	'not found even though signatures match -> keep',
	! QueueVerifier::should_clear( 'same', array( 'found' => false, 'signature' => 'same', 'deployed_sig' => 'same' ) )
);

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
