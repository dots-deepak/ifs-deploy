<?php
/**
 * Content firewall — SECURITY.md H-2 / H-2b.
 *
 * Driven against a REAL WordPress kses, not a stub. That is deliberate: the whole design
 * rests on what core's allowlist actually contains, and my first assumption about that was
 * wrong in both directions — block markup survives kses (I thought it would not), while
 * `img srcset`, `iframe`, `form`, inline `svg` and `<source>` do not (I had not checked).
 * A stub would have let those mistakes through.
 *
 * Skipped with a clear message if no WordPress can be found, rather than failing the suite
 * on a machine that has none.
 */

$root = dirname( __DIR__ );
$pass = 0;
$fail = 0;

function ok( string $label, bool $condition ): bool {
	global $pass, $fail;

	if ( $condition ) {
		++$pass;
		echo "  PASS  $label\n";

		return true;
	}

	++$fail;
	echo "  FAIL  $label\n";

	return false;
}

/* -----------------------------------------------------------------------------
 * Locate a WordPress to borrow kses from
 * -------------------------------------------------------------------------- */

/** scandir, not glob: the repo path contains "[22020]", which glob reads as a character class. */
function dp_find_wp( string $start ): string {
	$candidates = array( $start );

	// Walk up from the plugin, then try the usual XAMPP docroot's first few sites.
	$dir = $start;
	for ( $i = 0; $i < 5; $i++ ) {
		$dir          = dirname( $dir );
		$candidates[] = $dir;
	}

	foreach ( array( 'D:/xampp/htdocs', 'C:/xampp/htdocs' ) as $docroot ) {
		if ( ! is_dir( $docroot ) ) {
			continue;
		}

		foreach ( (array) scandir( $docroot ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				$candidates[] = $docroot . '/' . $entry;
			}
		}
	}

	foreach ( $candidates as $candidate ) {
		if ( is_readable( $candidate . '/wp-includes/kses.php' ) ) {
			return $candidate . '/';
		}
	}

	return '';
}

$wp = dp_find_wp( $root );

if ( '' === $wp ) {
	echo "  SKIP  no WordPress installation found to borrow kses from.\n";
	echo "        This suite needs core's real allowlist; a stub would defeat its purpose.\n";
	echo "\n0 passed, 0 failed\n";
	exit( 0 );
}

define( 'ABSPATH', $wp );
define( 'WPINC', 'wp-includes' );
$GLOBALS['wp_filter'] = array();

require_once ABSPATH . WPINC . '/plugin.php';
require_once ABSPATH . WPINC . '/compat.php';
require_once ABSPATH . WPINC . '/functions.php';
require_once ABSPATH . WPINC . '/formatting.php';
require_once ABSPATH . WPINC . '/kses.php';
require_once ABSPATH . WPINC . '/http.php';

/*
 * Core's option.php arrives with the requires above, so get_option() already exists and
 * cannot be redeclared. It needs a few tiny helpers and an object cache to reach its
 * default-return path, which is all this suite wants: the mode comes from the cache below,
 * and no database is touched.
 *
 * Stubbing the option layer is fine; stubbing KSES would have defeated the whole point.
 */
if ( ! function_exists( 'wp_installing' ) ) {
	function wp_installing( $is_installing = null ) {
		return false;
	}
}

// l10n.php is not among the requires, so the translation wrappers are missing.
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

$GLOBALS['dp_cache'] = array();

function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
	$found = array_key_exists( $group . '|' . $key, $GLOBALS['dp_cache'] );

	return $found ? $GLOBALS['dp_cache'][ $group . '|' . $key ] : false;
}
function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
	$GLOBALS['dp_cache'][ $group . '|' . $key ] = $data;

	return true;
}
function wp_cache_add( $key, $data, $group = '', $expire = 0 ) {
	return wp_cache_set( $key, $data, $group, $expire );
}
function wp_cache_delete( $key, $group = '' ) {
	unset( $GLOBALS['dp_cache'][ $group . '|' . $key ] );

	return true;
}
function wp_cache_get_multiple( $keys, $group = '', $force = false ) {
	$out = array();
	foreach ( (array) $keys as $key ) {
		$out[ $key ] = wp_cache_get( $key, $group );
	}

	return $out;
}
function wp_cache_set_multiple( $data, $group = '', $expire = 0 ) {
	foreach ( (array) $data as $key => $value ) {
		wp_cache_set( $key, $value, $group, $expire );
	}

	return true;
}

/** Enough of $wpdb for get_option() to find nothing and fall back to its default. */
class DP_FW_WPDB {
	public string $options = 'wp_options';
	public $suppress_errors = false;

	public function get_row( $q, $o = null ) {
		return null;
	}
	public function get_results( $q, $o = null ) {
		return array();
	}
	public function get_var( $q ) {
		return null;
	}
	public function prepare( string $q, ...$a ): string {
		return $q;
	}
	public function suppress_errors( $s = true ) {
		return false;
	}
}

$GLOBALS['wpdb'] = new DP_FW_WPDB();

/**
 * Set the firewall's mode by priming the option cache.
 *
 * `alloptions` is primed too: `wp_load_alloptions()` returns straight from the cache when
 * that key is present, so `get_option()` never reaches a query — and therefore never needs
 * a real `$wpdb`.
 */
function dp_set_mode( string $mode ): void {
	$GLOBALS['dp_cache']['options|alloptions'] = array( 'ifs_deploy_content_firewall' => $mode );
	$GLOBALS['dp_cache']['options|notoptions'] = array();

	wp_cache_set( 'ifs_deploy_content_firewall', $mode, 'options' );
}

dp_set_mode( 'filter' );

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return 'https://prod.example.com' . $path;
	}
}

spl_autoload_register(
	static function ( string $class ) use ( $root ): void {
		if ( 0 !== strpos( $class, 'IfsDeploy' . chr( 92 ) ) ) {
			return;
		}

		$relative = substr( $class, strlen( 'IfsDeploy' ) + 1 );
		$path     = $root . '/src/' . str_replace( chr( 92 ), '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

$FW = 'IfsDeploy' . chr( 92 ) . 'Support' . chr( 92 ) . 'ContentFirewall';

/* -----------------------------------------------------------------------------
 * Content that MUST survive
 * -------------------------------------------------------------------------- */

echo "=== legitimate content is not damaged ===\n";

$survive = array(
	'block comments'      => "<!-- wp:paragraph -->\n<p>Hi</p>\n<!-- /wp:paragraph -->",
	'block attribute JSON' => '<!-- wp:heading {"level":3,"textColor":"vivid-red"} --><h3 class="has-vivid-red-color">Hi</h3><!-- /wp:heading -->',
	'nested blocks'       => '<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"><p>a</p></div><!-- /wp:column --></div><!-- /wp:columns -->',
	'img srcset + sizes'  => '<img src="/a.jpg" alt="a" srcset="/a.jpg 1x, /b.jpg 2x" sizes="100vw" class="wp-image-9" />',
	'video with source'   => '<video controls width="640"><source src="/v.mp4" type="video/mp4" /></video>',
	'youtube embed'       => '<iframe src="https://www.youtube.com/embed/x" width="560" height="315" allowfullscreen></iframe>',
	'vimeo subdomain'     => '<iframe src="https://player.vimeo.com/video/1" width="640" height="360"></iframe>',
	'own-host iframe'     => '<iframe src="https://prod.example.com/embed" width="400" height="300"></iframe>',
	'form and inputs'     => '<form action="/subscribe" method="post"><label for="e">Email</label><input type="email" name="e" required /><button type="submit">Go</button></form>',
	'inline svg'          => '<svg viewBox="0 0 24 24" class="i" aria-hidden="true"><path d="M4 4h16v16H4z" fill="currentColor" /></svg>',
	'data-* attributes'   => '<div class="s" data-slide="3" data-aos="fade-up">y</div>',
	'inline style'        => '<p style="color:#f00;font-size:20px">s</p>',
	'shortcode'           => '[contact-form-7 id="99" title="Contact"]',
	'plain text'          => 'Just words, an & ampersand, and 3 < 4.',
);

foreach ( $survive as $label => $html ) {
	// apply(), not filter(): the plain-text and entity-normalisation guards live there, and
	// "does not damage legitimate content" is exactly what they exist for.
	$result = $FW::apply( $html );
	$out    = (string) $result['html'];

	ok( "$label survives untouched", $out === $html ) or printf( "        got: %s\n", str_replace( "\n", '\n', $out ) );
}

/* -----------------------------------------------------------------------------
 * Content that MUST be neutralised
 * -------------------------------------------------------------------------- */

echo "=== executable markup is removed ===\n";

// One regex for "anything that could still run". Applied to the OUTPUT of every case, so a
// new bypass shows up even if its specific case was never written.
$danger = '#<\s*script|\son[a-z]+\s*=|javascript\s*:|xlink:href|srcdoc|<\s*foreignobject|<\s*animate|<\s*object|<\s*embed|<\s*applet|<\s*style\b|<\s*use\b#i';

$block = array(
	'script tag'              => '<p>ok</p><script>alert(1)</script>',
	'img onerror'             => '<img src="x" onerror="alert(1)">',
	'body onload style'       => '<div onmouseover="alert(1)">x</div>',
	'javascript: href'        => '<a href="javascript:alert(1)">c</a>',
	'javascript: form action' => '<form action="javascript:alert(1)"><input name="a" /></form>',
	'javascript: formaction'  => '<button formaction="javascript:alert(1)">x</button>',
	'style tag'              => '<style>body{display:none}</style>',
	'object tag'             => '<object data="evil.swf" type="application/x-shockwave-flash"></object>',
	'embed tag'              => '<embed src="evil.swf" />',
	'svg onload'             => '<svg onload="alert(1)"><path d="M0 0" /></svg>',
	'svg use + xlink:href'   => '<svg><use xlink:href="data:text/html,x" /></svg>',
	'svg nested script'      => '<svg><script>alert(1)</script><path d="M0 0" /></svg>',
	'svg foreignObject'      => '<svg><foreignObject><script>alert(1)</script></foreignObject></svg>',
	'svg animate rewrite'    => '<svg><rect /><animate attributeName="href" to="javascript:alert(1)" /></svg>',
	'iframe srcdoc'          => '<iframe srcdoc="&lt;script&gt;alert(1)&lt;/script&gt;"></iframe>',
	'style url(javascript:)' => '<p style="background:url(javascript:alert(1))">x</p>',
);

foreach ( $block as $label => $html ) {
	$out = $FW::filter( $html );

	ok( "$label is neutralised", ! preg_match( $danger, $out ) ) or printf( "        left: %s\n", str_replace( "\n", '\n', $out ) );
}

echo "=== iframes are restricted by HOST, not just protocol ===\n";

// kses checks the protocol of `src` and nothing else, so without the host check every
// https iframe survives — a tidier injection surface, but an injection surface.
$hosts = array(
	'https://evil.example/x'          => false,
	'https://evil-vimeo.com/x'        => false, // lookalike: must NOT match vimeo.com
	'https://vimeo.com.evil.test/x'   => false, // suffix trick
	'https://www.youtube.com/embed/x' => true,
	'https://youtu.be/x'              => true,
	'https://player.vimeo.com/video/1' => true,
	'https://prod.example.com/embed'  => true,  // the site's own host
);

foreach ( $hosts as $src => $expected ) {
	$out  = $FW::filter( '<iframe src="' . $src . '" width="1" height="1"></iframe>' );
	$kept = false !== stripos( $out, '<iframe' );

	ok( ( $expected ? 'allows ' : 'refuses ' ) . $src, $kept === $expected );
}

// An iframe with no src has nothing to embed, so nothing worth keeping.
ok( 'an iframe with no src is dropped', false === stripos( $FW::filter( '<iframe width="1"></iframe>' ), '<iframe' ) );

/* -----------------------------------------------------------------------------
 * Modes
 * -------------------------------------------------------------------------- */

echo "=== the three modes ===\n";

$dirty = '<p>keep</p><script>alert(1)</script>';

// `filter()` always filters — it is the primitive. `apply()` is what respects the mode.
ok( 'filter() always filters', false === strpos( $FW::filter( $dirty ), '<script' ) );

$firewall_src = (string) file_get_contents( $root . '/src/Support/ContentFirewall.php' );

// REPORT is the default when the option is unset, which is the state every EXISTING install
// upgrades into. This is the single most important line in the feature: upgrading must not
// silently rewrite anyone's pages.
ok( 'report is the default when unset', (bool) preg_match( '/get_option\(\s*self::OPTION,\s*self::MODE_REPORT\s*\)/', $firewall_src ) );
ok( 'report mode returns the ORIGINAL html', (bool) preg_match( "/MODE_FILTER === self::mode\(\) \? \\\$clean : \\\$html/", $firewall_src ) );
ok( 'off mode short-circuits entirely', (bool) preg_match( '/MODE_OFF === self::mode\(\)\s*\)\s*\{\s*return \$unchanged/', $firewall_src ) );

// A fresh install gets the secure default without anyone finding the setting.
$activator = (string) file_get_contents( $root . '/src/Support/Activator.php' );
ok( 'a fresh install activates in filter mode', false !== strpos( $activator, 'ContentFirewall::MODE_FILTER' ) );
ok( 'and only when the option was never set', (bool) preg_match( '/false === get_option\(\s*ContentFirewall::OPTION,\s*false\s*\)/', $activator ) );

/* -----------------------------------------------------------------------------
 * Reporting
 * -------------------------------------------------------------------------- */

echo "=== changes are reported, never silent ===\n";

// A lossy deploy the user cannot see is worse than no filter at all.
$post = (string) file_get_contents( $root . '/src/Import/PostImporter.php' );

ok( 'post content goes through the firewall', false !== strpos( $post, 'ContentFirewall::apply(' ) );
// Order matters: rewriting first means the firewall judges the markup that will be STORED.
$rewrite_at = strpos( $post, '$this->rewrite_urls( $this->media_urls->apply' );
$fw_at      = strpos( $post, 'ContentFirewall::apply(' );
ok( 'it runs AFTER the URL rewrites', false !== $rewrite_at && $rewrite_at < $fw_at );
ok( 'and names the object in the log', false !== strpos( $post, 'report_firewall(' ) );
// Reported in report mode too, or the mode would be pointless.
ok( 'report mode still logs what would change', false !== strpos( $post, 'would be removed if the content firewall were switched on' ) );

echo "=== H-2b: widget options are covered too ===\n";

// widget_custom_html exists TO hold raw HTML and is on the deployable allowlist, so
// filtering post_content alone leaves the injection path wide open.
$option = (string) file_get_contents( $root . '/src/Import/OptionImporter.php' );

ok( 'widget options are filtered', false !== strpos( $option, "0 !== strpos( \$name, 'widget_' )" ) );
ok( 'recursively, since widgets are arrays', false !== strpos( $option, 'clean_deep(' ) );
// Plain text must pass untouched — a widget title is a string too, and kses would eat a
// legitimate bare `<` or `&`.
ok( 'strings with no markup are left alone', false !== strpos( $option, "false === strpos( \$value, '<' )" ) );
// theme_mods_/options_ hold colours and ids; an HTML filter over them would corrupt values
// that were never markup.
ok( 'non-widget options are untouched', false === strpos( $option, "'theme_mods_'" ) );
ok( 'and the change is logged', false !== strpos( $option, 'DebugLog::warning' ) );

echo "=== the allowlist cannot be widened by accident ===\n";

$allowed = $FW::allowed();

// The three that make inline SVG dangerous, all absent by design. `xlink:href` in
// particular is NOT in wp_kses_uri_attributes(), so kses would not protocol-check it.
ok( 'script is not allowed', ! isset( $allowed['script'] ) );
ok( 'use is not allowed', ! isset( $allowed['use'] ) );
ok( 'foreignObject is not allowed', ! isset( $allowed['foreignobject'] ) && ! isset( $allowed['foreignObject'] ) );
ok( 'animate is not allowed', ! isset( $allowed['animate'] ) );
// Explicitly removed rather than left to a kses quirk about `data` vs `data-*`.
ok( 'object is explicitly removed', ! isset( $allowed['object'] ) );
ok( 'embed is not allowed', ! isset( $allowed['embed'] ) );

// No tag may list an event handler.
$handlers = array();
foreach ( $allowed as $tag => $attrs ) {
	foreach ( array_keys( (array) $attrs ) as $attr ) {
		if ( preg_match( '/^on[a-z]+$/i', (string) $attr ) ) {
			$handlers[] = $tag . '.' . $attr;
		}
	}
}
ok( 'no tag permits an on* handler', empty( $handlers ) );
foreach ( $handlers as $h ) {
	echo "        $h\n";
}

// And the additions that fix real content loss are present.
ok( 'img srcset was added back', isset( $allowed['img']['srcset'] ) );
ok( 'img sizes was added back', isset( $allowed['img']['sizes'] ) );
ok( 'source was added back', isset( $allowed['source']['src'] ) );
ok( 'iframe was added', isset( $allowed['iframe']['src'] ) );
ok( 'form was added', isset( $allowed['form']['action'] ) );
ok( 'svg path was added', isset( $allowed['path']['d'] ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
