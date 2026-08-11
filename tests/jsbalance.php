<?php
/**
 * Crude but string/comment-aware bracket balance check for a JS file.
 *
 * Not a parser — it cannot prove the file is valid. It does catch the mistake that
 * actually happens when hand-editing: an unclosed brace, paren or bracket.
 */
$path = $argv[1] ?? '';
$src  = (string) file_get_contents( $path );

$len   = strlen( $src );
$stack = array();
$line  = 1;

$in_line_comment  = false;
$in_block_comment = false;
$quote            = '';

$pairs = array( ')' => '(', '}' => '{', ']' => '[' );

for ( $i = 0; $i < $len; $i++ ) {
	$c    = $src[ $i ];
	$next = $src[ $i + 1 ] ?? '';

	if ( "\n" === $c ) {
		++$line;
		$in_line_comment = false;
		continue;
	}

	if ( $in_line_comment ) {
		continue;
	}

	if ( $in_block_comment ) {
		if ( '*' === $c && '/' === $next ) {
			$in_block_comment = false;
			++$i;
		}
		continue;
	}

	if ( '' !== $quote ) {
		if ( '\\' === $c ) {
			++$i;
			continue;
		}
		if ( $c === $quote ) {
			$quote = '';
		}
		continue;
	}

	if ( '/' === $c && '/' === $next ) {
		$in_line_comment = true;
		++$i;
		continue;
	}
	if ( '/' === $c && '*' === $next ) {
		$in_block_comment = true;
		++$i;
		continue;
	}
	if ( '"' === $c || "'" === $c || '`' === $c ) {
		$quote = $c;
		continue;
	}

	if ( in_array( $c, array( '(', '{', '[' ), true ) ) {
		$stack[] = array( $c, $line );
		continue;
	}

	if ( isset( $pairs[ $c ] ) ) {
		$top = array_pop( $stack );
		if ( null === $top ) {
			printf( "UNMATCHED closing %s on line %d\n", $c, $line );
			exit( 1 );
		}
		if ( $top[0] !== $pairs[ $c ] ) {
			printf( "MISMATCH: %s opened line %d, closed by %s on line %d\n", $top[0], $top[1], $c, $line );
			exit( 1 );
		}
	}
}

if ( ! empty( $stack ) ) {
	foreach ( $stack as $open ) {
		printf( "UNCLOSED %s opened on line %d\n", $open[0], $open[1] );
	}
	exit( 1 );
}

printf( "balanced: %d lines, all brackets matched\n", $line );
