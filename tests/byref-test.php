<?php
declare(strict_types=1);

echo "=== 1. Reproduce the reported fatal ===\n";
$package = array( 'meta' => array( 'a' => array( 'x' ) ) );
$strings = array();
try {
	// The old code: an expression, not a variable, passed to a by-ref parameter.
	array_walk_recursive(
		(array) ( $package['meta'] ?? array() ),
		static function ( $v ) use ( &$strings ): void { $strings[] = $v; }
	);
	echo "  no error (unexpected on this PHP)\n";
} catch ( \Throwable $e ) {
	printf( "  REPRODUCED: %s: %s\n", get_class( $e ), $e->getMessage() );
}

echo "\n=== 2. The replacement: recursive collector ===\n";

final class Collector {
	/** @return string[] */
	public function package_strings( array $package ): array {
		$strings = array(
			(string) ( $package['object']['post_content'] ?? '' ),
			(string) ( $package['object']['post_excerpt'] ?? '' ),
		);
		$this->collect_strings( $package['meta'] ?? array(), $strings );
		return $strings;
	}

	private function collect_strings( $value, array &$into ): void {
		if ( is_string( $value ) ) { $into[] = $value; return; }
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) { $this->collect_strings( $item, $into ); }
		}
	}
}

$c = new Collector();
$pass = 0; $fail = 0;
function ok( string $n, bool $c ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS  $n\n"; } else { $fail++; echo "  FAIL  $n\n"; } }

// Realistic package: nested ACF repeater, mixed types, missing keys.
$package = array(
	'object' => array( 'post_content' => 'CONTENT', 'post_excerpt' => 'EXCERPT' ),
	'meta'   => array(
		'flat'    => array( 'FLAT' ),
		'rows'    => array( array( array( 'body' => 'DEEP', 'n' => 42, 'b' => true, 'nil' => null ) ) ),
		'numeric' => array( 7 ),
	),
);
$out = $c->package_strings( $package );
ok( 'content collected',            in_array( 'CONTENT', $out, true ) );
ok( 'excerpt collected',            in_array( 'EXCERPT', $out, true ) );
ok( 'flat meta collected',          in_array( 'FLAT', $out, true ) );
ok( 'deeply nested collected',      in_array( 'DEEP', $out, true ) );
ok( 'non-strings skipped',          ! in_array( 42, $out, true ) && ! in_array( true, $out, true ) );

// The shapes that used to crash.
try { $c->package_strings( array() );                       ok( 'empty package',        true ); } catch ( \Throwable $e ) { ok( 'empty package: ' . $e->getMessage(), false ); }
try { $c->package_strings( array( 'meta' => array() ) );    ok( 'empty meta',           true ); } catch ( \Throwable $e ) { ok( 'empty meta: ' . $e->getMessage(), false ); }
try { $c->package_strings( array( 'meta' => 'not-array' ) ); ok( 'meta not an array',   true ); } catch ( \Throwable $e ) { ok( 'meta scalar: ' . $e->getMessage(), false ); }
try { $c->package_strings( array( 'meta' => null ) );       ok( 'meta null',            true ); } catch ( \Throwable $e ) { ok( 'meta null: ' . $e->getMessage(), false ); }

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
