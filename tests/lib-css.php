<?php
/**
 * Compare CSS without depending on how it was built.
 *
 * ── WHY THIS EXISTS ────────────────────────────────────────────────────────────
 *
 * `assets/css/admin.css` is generated, and its own source header documents TWO commands:
 *
 *     tools/tailwindcss.exe … --watch     → expanded, one declaration per line
 *     tools/tailwindcss.exe … --minify    → collapsed, no whitespace
 *
 * Both are legitimate, and whichever ran last is what sits in the file. Assertions written
 * against the minified form — `.dp-env-card{border:1px solid #eaeaea}` — therefore pass or
 * fail on the build FLAG rather than on the CSS, and a whole suite went red the first time
 * the file was rebuilt in watch mode. The styling was never wrong.
 *
 * So both sides of every comparison are normalised to one canonical shape first. What is
 * being asserted is that a rule exists and says what it should, which is true in either form.
 *
 * NOT a general CSS parser, and not trying to be: whitespace around structural punctuation is
 * the only difference between the two outputs.
 */

/**
 * Canonical form: no whitespace runs, and none adjacent to punctuation.
 *
 * `border: 1px solid #EAEAEA;` becomes `border:1px solid #EAEAEA;`.
 *
 * Case is deliberately LEFT ALONE. Lowercasing would handle the minifier rewriting hex
 * colours, but it would also turn `font-family:Inter` into `font-family:inter` and quietly
 * break every assertion that names a value by its real spelling. Case-insensitivity belongs
 * in the comparison instead — see `dp_css_has()`.
 */
function dp_css_normalise( string $css ): string {
	// Every whitespace run becomes one space, so the two layouts converge.
	$css = (string) preg_replace( '/\s+/', ' ', $css );

	// Then drop the spaces that only exist for readability. Combinators (`~ > +`), block and
	// declaration punctuation, and the separators inside a value.
	$css = (string) preg_replace( '/\s*([{};:,()~>+\/])\s*/', '$1', $css );

	return trim( $css );
}

/**
 * Does this CSS contain that rule or declaration, whichever way it was built?
 *
 * The NEEDLE is normalised too, so it can be written in either form — which means an
 * assertion can be pasted straight from the source file or from the built file and still
 * mean the same thing.
 *
 * Case-insensitive, because the minifier lowercases hex colours: `#EAEAEA` as authored comes
 * out as `#eaeaea`. Nothing in CSS distinguishes two rules by case alone, so there is no
 * precision lost here.
 */
function dp_css_has( string $css, string $needle ): bool {
	return false !== stripos( dp_css_normalise( $css ), dp_css_normalise( $needle ) );
}

/**
 * The body of one rule, or '' when there is no such selector.
 *
 * Matched against the normalised text, so the returned body is normalised as well — callers
 * should test it with `dp_css_has()` rather than a bare `strpos()`.
 *
 * @param string $selector Exactly as authored, e.g. `.ifs-deploy .dp-env-card`.
 */
function dp_css_rule( string $css, string $selector ): string {
	$pattern = '/(?:^|[};])' . preg_quote( dp_css_normalise( $selector ), '/' ) . '\{([^}]*)\}/i';

	return preg_match( $pattern, dp_css_normalise( $css ), $m ) ? $m[1] : '';
}
