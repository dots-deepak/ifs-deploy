<?php
/**
 * WCAG 2.1 contrast checker for the DESIGN.md palette.
 *
 *     php tests/contrast.php
 *
 * Every colour pair the design system relies on is asserted here, so an
 * inaccessible token cannot quietly ship. Thresholds:
 *   4.5:1  normal text (AA)
 *   3.0:1  large text (>=18.66px bold or >=24px) and non-text UI (AA)
 */

/** Relative luminance per WCAG 2.1. */
function luminance( string $hex ): float {
	$hex = ltrim( $hex, '#' );
	$rgb = array(
		hexdec( substr( $hex, 0, 2 ) ) / 255,
		hexdec( substr( $hex, 2, 2 ) ) / 255,
		hexdec( substr( $hex, 4, 2 ) ) / 255,
	);

	foreach ( $rgb as $i => $c ) {
		$rgb[ $i ] = ( $c <= 0.03928 ) ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
	}

	return 0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2];
}

function ratio( string $fg, string $bg ): float {
	$a = luminance( $fg );
	$b = luminance( $bg );
	$light = max( $a, $b );
	$dark  = min( $a, $b );

	return ( $light + 0.05 ) / ( $dark + 0.05 );
}

const WHITE = '#FFFFFF';

/** [ label, foreground, background, minimum required ] */
$checks = array(
	// Text on white surfaces.
	array( 'grey-900 on white  (title, inverted btn text)', '#191B1C', WHITE, 4.5 ),
	array( 'grey-800 on white  (body text, headings)', '#383B3D', WHITE, 4.5 ),
	array( 'grey-600 on white  (secondary text, placeholders)', '#5A6063', WHITE, 4.5 ),

	// Text on the zebra stripe.
	array( 'grey-800 on grey-25 (body on zebra)', '#383B3D', '#F7F8F9', 4.5 ),
	array( 'grey-600 on grey-25 (secondary on zebra)', '#5A6063', '#F7F8F9', 4.5 ),

	// Text on the page background.
	array( 'grey-800 on grey-50 (body on page bg)', '#383B3D', '#EDEFF0', 4.5 ),
	array( 'grey-600 on grey-50 (secondary on page bg)', '#5A6063', '#EDEFF0', 4.5 ),

	// Primary button: white text on near-black.
	array( 'white on grey-900   (primary button)', WHITE, '#191B1C', 4.5 ),

	// WCAG 1.4.11: a border that DEFINES a control needs 3:1. grey-200 is far too
	// light for that, so control borders use grey-500 and grey-200 is demoted to
	// decorative dividers only (which carry no meaning and are exempt).
	array( 'grey-500 control border on white', '#7B8285', WHITE, 3.0 ),

	/*
	 * Toggle switch (P2). The track IS the control, so both states need 3:1 against the
	 * panel, and the knob needs 3:1 against the track or the switch reads as a bare pill
	 * with no visible position. grey-400 was tried for the off state and fails at 2.56:1.
	 *
	 * ON is success-text green, not grey-900. Two near-black pills differing only in
	 * lightness are hard to tell apart at 36×20px; a hue change carries the state on its
	 * own. Both required ratios still have to hold, which is what these two pin.
	 */
	array( 'toggle track OFF on white', '#7B8285', WHITE, 3.0 ),
	array( 'toggle track ON  on white', '#2F6B4F', WHITE, 3.0 ),
	array( 'toggle knob on track OFF', WHITE, '#7B8285', 3.0 ),
	array( 'toggle knob on track ON', WHITE, '#2F6B4F', 3.0 ),

	// Table header text sits on the grey-50 header band.
	array( 'grey-800 header text on grey-50', '#383B3D', '#EDEFF0', 4.5 ),
	array( 'grey-600 column hint on grey-50', '#5A6063', '#EDEFF0', 4.5 ),

	// Semantic badges: text on its own tinted background.
	array( 'success text on success bg', '#2F6B4F', '#EAF2ED', 4.5 ),
	array( 'danger  text on danger bg', '#9B3A38', '#FBEDEC', 4.5 ),
	array( 'warning text on warning bg', '#7A5F31', '#FAF3E6', 4.5 ),

	// Semantic text directly on white (notices, inline errors).
	array( 'success text on white', '#2F6B4F', WHITE, 4.5 ),
	array( 'danger  text on white', '#9B3A38', WHITE, 4.5 ),
	array( 'warning text on white', '#7A5F31', WHITE, 4.5 ),

	// Diff surface: body text sits on the pale washes.
	array( 'grey-800 on diff-add wash', '#383B3D', '#F0F6F2', 4.5 ),
	array( 'grey-800 on diff-remove wash', '#383B3D', '#FBF0EF', 4.5 ),
);

$fail = 0;

printf( "%-52s %8s %8s  %s\n", 'PAIR', 'RATIO', 'NEEDS', '' );
echo str_repeat( '-', 82 ) . "\n";

foreach ( $checks as list( $label, $fg, $bg, $min ) ) {
	$r  = ratio( $fg, $bg );
	$ok = $r >= $min;
	if ( ! $ok ) {
		++$fail;
	}

	printf(
		"%-52s %7.2f:1 %7.1f:1  %s\n",
		$label,
		$r,
		$min,
		$ok ? 'PASS' : 'FAIL'
	);
}

echo str_repeat( '-', 82 ) . "\n";
printf( "%d checks, %d failing\n", count( $checks ), $fail );

exit( $fail > 0 ? 1 : 0 );
