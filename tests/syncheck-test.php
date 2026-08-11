<?php
declare(strict_types=1);
require __DIR__ . '/../src/Support/SyncCheck.php';
use IfsDeploy\Support\SyncCheck;

$pass = 0; $fail = 0;
function ok( string $n, bool $c ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS  $n\n"; } else { $fail++; echo "FAIL  $n\n"; } }

$A = 'aaa'; $B = 'bbb';

echo "=== in sync ===\n";
ok( 'local matches the stamp Staging left on Production', SyncCheck::in_sync( $A, $A, $B ) );
ok( 'local matches Production recomputed',                SyncCheck::in_sync( $A, '', $A ) );
ok( 'both match',                                         SyncCheck::in_sync( $A, $A, $A ) );

echo "\n=== NOT in sync (must keep the row queued) ===\n";
ok( 'neither matches',            ! SyncCheck::in_sync( $A, $B, $B ) );
ok( 'no local signature at all',  ! SyncCheck::in_sync( '', $A, $A ) );
ok( 'both remote sides empty',    ! SyncCheck::in_sync( $A, '', '' ) );
ok( 'empty stamp is not a match', ! SyncCheck::in_sync( $A, '', $B ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
