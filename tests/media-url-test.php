<?php
declare(strict_types=1);

function untrailingslashit( $s ) { return rtrim( (string) $s, '/' ); }
function wp_parse_url( $u, $c = -1 ) { return -1 === $c ? parse_url( $u ) : parse_url( $u, $c ); }
function home_url() { return $GLOBALS['dp_home']; }

// Fake attachment store: source_url => production url
$GLOBALS['dp_attachments'] = array();
$GLOBALS['dp_home'] = 'https://prod.test';

function wp_get_attachment_url( $id ) { return $GLOBALS['dp_by_id'][ $id ] ?? ''; }

// Stand-in WP_Query that resolves our meta lookup.
class WP_Query {
	public $posts = array();
	public function __construct( $args ) {
		$wanted = $args['meta_query'][0]['value'] ?? '';
		foreach ( $GLOBALS['dp_attachments'] as $source => $info ) {
			if ( $source === $wanted ) { $this->posts = array( $info['id'] ); return; }
		}
	}
}

require __DIR__ . '/../src/Support/MediaIdentity.php';
require __DIR__ . '/../src/Support/UrlRewriter.php';
require __DIR__ . '/../src/Import/MediaImporter.php';
require __DIR__ . '/../src/Import/MediaUrlResolver.php';

use IfsDeploy\Import\MediaUrlResolver;

const STG = 'https://stg.test';

function register_attachment( int $id, string $source_url, string $production_url ): void {
	$GLOBALS['dp_attachments'][ $source_url ] = array( 'id' => $id );
	$GLOBALS['dp_by_id'][ $id ] = $production_url;
}

$pass = 0; $fail = 0;
function eq( string $name, $got, $want ) {
	global $pass, $fail;
	if ( $got === $want ) { $pass++; printf( "PASS  %s\n", $name ); }
	else { $fail++; printf( "FAIL  %s\n        got:  %s\n        want: %s\n", $name, var_export( $got, true ), var_export( $want, true ) ); }
}

// The reported case: sample.png became sample-1.png on Production.
register_attachment( 55, STG . '/wp-content/uploads/2026/08/sample.png', 'https://prod.test/wp-content/uploads/2026/08/sample-1.png' );

$r = new MediaUrlResolver();

echo "=== base file ===\n";
$content = '<img src="' . STG . '/wp-content/uploads/2026/08/sample.png" alt="">';
$map = $r->build_map( array( $content ), STG );
eq(
	'sample.png is remapped to sample-1.png',
	$r->apply( $content, $map ),
	'<img src="https://prod.test/wp-content/uploads/2026/08/sample-1.png" alt="">'
);

echo "\n=== sized variants ===\n";
$sized = '<img src="' . STG . '/wp-content/uploads/2026/08/sample-300x200.png">';
eq(
	'sample-300x200.png -> sample-1-300x200.png',
	$r->apply( $sized, $r->build_map( array( $sized ), STG ) ),
	'<img src="https://prod.test/wp-content/uploads/2026/08/sample-1-300x200.png">'
);

$srcset = 'srcset="' . STG . '/wp-content/uploads/2026/08/sample.png 1x, ' . STG . '/wp-content/uploads/2026/08/sample-600x400.png 2x"';
eq(
	'srcset: both base and sized remapped',
	$r->apply( $srcset, $r->build_map( array( $srcset ), STG ) ),
	'srcset="https://prod.test/wp-content/uploads/2026/08/sample-1.png 1x, https://prod.test/wp-content/uploads/2026/08/sample-1-600x400.png 2x"'
);

echo "\n=== block markup (escaped slashes) ===\n";
$block = '<!-- wp:image {"url":"https:\/\/stg.test\/wp-content\/uploads\/2026\/08\/sample.png","id":55} -->';
eq(
	'escaped-slash URL remapped, escaping preserved',
	$r->apply( $block, $r->build_map( array( $block ), STG ) ),
	'<!-- wp:image {"url":"https:\/\/prod.test\/wp-content\/uploads\/2026\/08\/sample-1.png","id":55} -->'
);

echo "\n=== different month folder on Production ===\n";
register_attachment( 56, STG . '/wp-content/uploads/2026/08/photo.jpg', 'https://prod.test/wp-content/uploads/2026/09/photo.jpg' );
$moved = '<img src="' . STG . '/wp-content/uploads/2026/08/photo-150x150.jpg">';
eq(
	'folder difference is followed too',
	$r->apply( $moved, $r->build_map( array( $moved ), STG ) ),
	'<img src="https://prod.test/wp-content/uploads/2026/09/photo-150x150.jpg">'
);

echo "\n=== -scaled originals ===\n";
register_attachment( 57, STG . '/wp-content/uploads/2026/08/big-scaled.jpg', 'https://prod.test/wp-content/uploads/2026/08/big-1-scaled.jpg' );
$scaled = '<img src="' . STG . '/wp-content/uploads/2026/08/big-scaled.jpg">';
eq(
	'scaled file remapped',
	$r->apply( $scaled, $r->build_map( array( $scaled ), STG ) ),
	'<img src="https://prod.test/wp-content/uploads/2026/08/big-1-scaled.jpg">'
);
$scaled_sized = '<img src="' . STG . '/wp-content/uploads/2026/08/big-scaled-768x512.jpg">';
eq(
	'sized variant of a scaled file remapped',
	$r->apply( $scaled_sized, $r->build_map( array( $scaled_sized ), STG ) ),
	'<img src="https://prod.test/wp-content/uploads/2026/08/big-1-scaled-768x512.jpg">'
);

echo "\n=== no-op cases ===\n";
$same = '<img src="' . STG . '/wp-content/uploads/2026/08/unknown.png">';
eq( 'unknown media is left to the plain domain rewrite', $r->build_map( array( $same ), STG ), array() );

register_attachment( 58, STG . '/wp-content/uploads/2026/08/kept.png', 'https://prod.test/wp-content/uploads/2026/08/kept.png' );
$kept = '<img src="' . STG . '/wp-content/uploads/2026/08/kept.png">';
eq( 'same filename produces no map entry', $r->build_map( array( $kept ), STG ), array() );

$external = '<img src="https://cdn.example.com/2026/08/sample.png">';
eq( 'external media untouched', $r->build_map( array( $external ), STG ), array() );

echo "\n=== nested meta (ACF gallery / WYSIWYG) ===\n";
$meta = array( 'rows' => array( array( 'body' => '<img src="' . STG . '/wp-content/uploads/2026/08/sample.png">' ) ) );
$map  = $r->build_map( array( wp_json_encode_stub( $meta ) ), STG );
$out  = $r->apply_deep( $meta, $map );
eq(
	'nested ACF value remapped',
	$out['rows'][0]['body'],
	'<img src="https://prod.test/wp-content/uploads/2026/08/sample-1.png">'
);

function wp_json_encode_stub( $v ) { return json_encode( $v ); }

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
