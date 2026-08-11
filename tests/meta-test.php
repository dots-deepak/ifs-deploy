<?php
declare(strict_types=1);

function untrailingslashit( $s ) { return rtrim( (string) $s, '/' ); }
function wp_parse_url( $u, $c = -1 ) { return -1 === $c ? parse_url( $u ) : parse_url( $u, $c ); }

require __DIR__ . '/../src/Support/UrlRewriter.php';
use IfsDeploy\Support\UrlRewriter;

const STG = 'https://copperlfstg.wpenginepowered.com';
const DEV = 'https://copperlfdev.wpenginepowered.com';

$pass = 0; $fail = 0;
function ok( string $name, bool $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS  $name\n"; } else { $fail++; echo "FAIL  $name\n"; }
}

// The screenshot's line, but living inside an ACF WYSIWYG field.
$acf_stg = 'CNAIM is a framework. IFS embeds <a href="' . STG . '/solutions/copperleaf-cnaim/"><strong>CNAIM</strong></a> into its platform.';
$acf_dev = 'CNAIM is a framework. IFS embeds <a href="' . DEV . '/solutions/copperleaf-cnaim/"><strong>CNAIM</strong></a> into its platform.';

$urls = array( STG, DEV );

echo "=== flat meta string (ACF WYSIWYG) ===\n";
ok(
	'ACF field with env URL now matches across sites',
	UrlRewriter::neutralize_deep( $acf_stg, $urls ) === UrlRewriter::neutralize_deep( $acf_dev, $urls )
);

echo "\n=== nested meta (ACF repeater / flexible content) ===\n";
$rep_stg = array( array( 'heading' => 'A', 'body' => $acf_stg, 'cards' => array( array( 'link' => STG . '/x' ) ) ) );
$rep_dev = array( array( 'heading' => 'A', 'body' => $acf_dev, 'cards' => array( array( 'link' => DEV . '/x' ) ) ) );
ok(
	'3-level nested repeater matches across sites',
	UrlRewriter::neutralize_deep( $rep_stg, $urls ) === UrlRewriter::neutralize_deep( $rep_dev, $urls )
);

$rep_diff = array( array( 'heading' => 'B', 'body' => $acf_dev, 'cards' => array( array( 'link' => DEV . '/x' ) ) ) );
ok(
	'real difference inside a repeater is still detected',
	UrlRewriter::neutralize_deep( $rep_stg, $urls ) !== UrlRewriter::neutralize_deep( $rep_diff, $urls )
);

echo "\n=== structure and types preserved ===\n";
$mixed = array( 'n' => 42, 'f' => 1.5, 'b' => true, 'nil' => null, 's' => STG . '/a', 'deep' => array( 'k' => STG ) );
$out   = UrlRewriter::neutralize_deep( $mixed, $urls );
ok( 'int preserved',    42 === $out['n'] );
ok( 'float preserved',  1.5 === $out['f'] );
ok( 'bool preserved',   true === $out['b'] );
ok( 'null preserved',   null === $out['nil'] );
ok( 'keys preserved',   array_keys( $mixed ) === array_keys( $out ) );
ok( 'nested string neutralized', '[site-url]' === $out['deep']['k'] );

echo "\n=== IMPORT rewrite through meta (stg -> dev) ===\n";
$rewritten = UrlRewriter::rewrite_deep( $rep_stg, STG, DEV );
ok(
	'nested ACF URLs rewritten to the target environment',
	$rewritten[0]['cards'][0]['link'] === DEV . '/x'
);
ok(
	'nested WYSIWYG body rewritten to the target environment',
	false !== strpos( (string) $rewritten[0]['body'], DEV . '/solutions/copperleaf-cnaim/' )
	&& false === strpos( (string) $rewritten[0]['body'], 'copperlfstg' )
);
ok( 'non-URL data untouched by rewrite', 'A' === $rewritten[0]['heading'] );

echo "\n=== serialization round-trip (length prefixes stay valid) ===\n";
$serialized   = serialize( $rep_stg );
$round        = UrlRewriter::rewrite_deep( unserialize( $serialized ), STG, DEV );
$reserialized = serialize( $round );
ok( 're-serialized value unserializes cleanly', false !== unserialize( $reserialized ) );
ok( 'round-tripped value equals direct rewrite', unserialize( $reserialized ) == $rewritten );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
