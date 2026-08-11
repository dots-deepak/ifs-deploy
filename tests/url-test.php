<?php
declare(strict_types=1);

function untrailingslashit( $s ) { return rtrim( (string) $s, '/' ); }
function wp_parse_url( $url, $component = -1 ) { return -1 === $component ? parse_url( $url ) : parse_url( $url, $component ); }

require __DIR__ . '/../src/Support/UrlRewriter.php';
use IfsDeploy\Support\UrlRewriter;

// The real pair from the reported screenshot.
const DEV  = 'https://copperlfdev.wpenginepowered.com';
const STG  = 'https://copperlfstg.wpenginepowered.com';

$pass = 0; $fail = 0;

function eq( string $name, string $got, string $want ) {
	global $pass, $fail;
	if ( $got === $want ) { $pass++; printf( "PASS  %s\n", $name ); }
	else { $fail++; printf( "FAIL  %s\n        got:  %s\n        want: %s\n", $name, $got, $want ); }
}

echo "=== IMPORT rewrite (dev -> stg) ===\n";

// The reported case: hardcoded http:// while home_url() is https://.
eq(
	'http:// link is rewritten (was leaking to Production)',
	UrlRewriter::rewrite( '<a href="http://copperlfdev.wpenginepowered.com/">x</a>', DEV, STG ),
	'<a href="' . STG . '/">x</a>'
);
eq(
	'https:// link is rewritten',
	UrlRewriter::rewrite( '<a href="https://copperlfdev.wpenginepowered.com/about">x</a>', DEV, STG ),
	'<a href="' . STG . '/about">x</a>'
);
eq(
	'protocol-relative //host is rewritten, not corrupted',
	UrlRewriter::rewrite( '<img src="//copperlfdev.wpenginepowered.com/a.jpg">', DEV, STG ),
	'<img src="' . STG . '/a.jpg">'
);
eq(
	'escaped-slash block markup keeps escaped form',
	UrlRewriter::rewrite( '{"url":"https:\/\/copperlfdev.wpenginepowered.com\/a.jpg"}', DEV, STG ),
	'{"url":"https:\/\/copperlfstg.wpenginepowered.com\/a.jpg"}'
);
eq(
	'www. variant is rewritten',
	UrlRewriter::rewrite( '<a href="https://www.copperlfdev.wpenginepowered.com/x">y</a>', DEV, STG ),
	'<a href="' . STG . '/x">y</a>'
);
eq(
	'unrelated domain untouched',
	UrlRewriter::rewrite( '<a href="https://example.com/copperlfdev">y</a>', DEV, STG ),
	'<a href="https://example.com/copperlfdev">y</a>'
);
eq(
	'deploy-to-self is a no-op',
	UrlRewriter::rewrite( '<a href="http://copperlfdev.wpenginepowered.com/">x</a>', DEV, DEV ),
	'<a href="http://copperlfdev.wpenginepowered.com/">x</a>'
);

echo "\n=== COMPARISON neutralize (both sides, same url list) ===\n";
$urls = array( DEV, STG );

// Exactly the diff from the screenshot: dev on the left, stg on the right.
$left  = '<a href="http://copperlfdev.wpenginepowered.com/"><i>copperlfdev.wpenginepowered.com</i></a>';
$right = '<a href="http://copperlfstg.wpenginepowered.com/"><i>copperlfstg.wpenginepowered.com</i></a>';
eq(
	'reported diff line now matches on both sides',
	UrlRewriter::neutralize( $left, $urls ),
	UrlRewriter::neutralize( $right, $urls )
);

// Mixed schemes across the two sides must still match.
eq(
	'https on one side, http on the other, still matches',
	UrlRewriter::neutralize( '<a href="https://copperlfdev.wpenginepowered.com/a">x</a>', $urls ),
	UrlRewriter::neutralize( '<a href="http://copperlfstg.wpenginepowered.com/a">x</a>', $urls )
);

// A genuine difference must survive neutralization.
$a = UrlRewriter::neutralize( '<a href="http://copperlfdev.wpenginepowered.com/about">About</a>', $urls );
$b = UrlRewriter::neutralize( '<a href="http://copperlfstg.wpenginepowered.com/about">Contact</a>', $urls );
if ( $a !== $b ) { $pass++; echo "PASS  real text difference still detected\n"; }
else { $fail++; echo "FAIL  real text difference was masked!\n"; }

// A different PATH on the same host is a real difference.
$a = UrlRewriter::neutralize( '<a href="http://copperlfdev.wpenginepowered.com/about">x</a>', $urls );
$b = UrlRewriter::neutralize( '<a href="http://copperlfstg.wpenginepowered.com/services">x</a>', $urls );
if ( $a !== $b ) { $pass++; echo "PASS  differing link PATH still detected\n"; }
else { $fail++; echo "FAIL  differing link path was masked!\n"; }

// Third-party URLs are never neutralized.
eq(
	'external URL survives neutralization verbatim',
	UrlRewriter::neutralize( '<a href="https://google.com/">g</a>', $urls ),
	'<a href="https://google.com/">g</a>'
);

echo "\n=== subdirectory install (path preserved) ===\n";
eq(
	'subdirectory origin rewritten with path',
	UrlRewriter::rewrite( '<a href="http://old.test/blog/page">x</a>', 'https://old.test/blog', 'https://new.test/site' ),
	'<a href="https://new.test/site/page">x</a>'
);

echo "\n=== bare hostname as visible link text (the screenshot case) ===\n";
eq(
	'bare host in link text is neutralized',
	UrlRewriter::neutralize( '<i>copperlfdev.wpenginepowered.com</i>', $urls ),
	'<i>[site-url]</i>'
);

$a = UrlRewriter::neutralize( '<a href="http://copperlfdev.wpenginepowered.com/"><i>copperlfdev.wpenginepowered.com</i></a>', $urls );
$b = UrlRewriter::neutralize( '<a href="http://copperlfstg.wpenginepowered.com/"><i>copperlfstg.wpenginepowered.com</i></a>', $urls );
if ( $a === $b ) { $pass++; echo "PASS  full screenshot line (href + link text) now matches\n"; }
else { $fail++; printf( "FAIL  full screenshot line\n        A: %s\n        B: %s\n", $a, $b ); }

eq(
	'subdomain NOT clobbered by bare-host matching',
	UrlRewriter::neutralize( 'see sub.copperlfdev.wpenginepowered.com here', $urls ),
	'see sub.copperlfdev.wpenginepowered.com here'
);

eq(
	'bare host untouched by the IMPORT rewrite (origins only, by design)',
	UrlRewriter::rewrite( '<i>copperlfdev.wpenginepowered.com</i>', DEV, STG ),
	'<i>copperlfdev.wpenginepowered.com</i>'
);

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
