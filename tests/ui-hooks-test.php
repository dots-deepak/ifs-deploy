<?php
declare(strict_types=1);

/**
 * Guards the JS↔markup contract listed in DESIGN.md §11.3.
 *
 *     php tests/ui-hooks-test.php
 *
 * admin.js selects elements by id, class and data attribute. A UI refactor that
 * renames or drops one of those produces NO error — the feature just silently stops
 * working. This asserts every hook admin.js reads still exists in the PHP that renders
 * it (or in admin.js itself, for markup the script injects).
 *
 * Add a hook here whenever admin.js starts depending on a new one.
 */

$root = __DIR__ . '/..';

/** Everything admin.js reads from the DOM, and where it must be produced. */
$hooks = array(
	// id => rendered by PHP
	'#ifs-deploy-select-all'         => 'php',
	'#ifs-deploy-push-selected'      => 'php',
	'#ifs-deploy-push-all'           => 'php',
	'#ifs-deploy-ignore'             => 'php',
	'#ifs-deploy-sync-ids'           => 'php',
	'#ifs-deploy-clear-history'      => 'php',
	'#ifs-deploy-clear-log'          => 'php',
	'#ifs-deploy-run-diagnostics'    => 'php',
	'#ifs-deploy-test-connection'    => 'php',
	'#ifs-deploy-test-result'        => 'php',
	'#ifs-deploy-diagnostics-result' => 'php',
	'#ifs-deploy-notice'             => 'php',
	'#ifs-deploy-preview-modal'      => 'php',
	'#ifs-deploy-modal-title'        => 'php',
	'#ifs-deploy-confirm-modal'      => 'php',
	'#ifs-deploy-confirm-title'      => 'php',
	'#ifs-deploy-confirm-ok'         => 'php',

	// Classes rendered by PHP.
	'.ifs-deploy-item'               => 'php',
	'.ifs-deploy-preview-open'       => 'php',
	'.ifs-deploy-push-one'           => 'php',
	'.ifs-deploy-rollback'           => 'php',
	'.ifs-deploy-modal-dialog'       => 'php',
	'.ifs-deploy-modal-body'         => 'php',
	'.ifs-deploy-modal-close'        => 'php',
	'.ifs-deploy-user-picker'        => 'php',
	'.ifs-deploy-user-search'        => 'php',
	'.ifs-deploy-chips'              => 'php',
	'.ifs-deploy-staging-only'       => 'php',
	'.ifs-deploy-production-only'    => 'php',

	// Rendered by PHP for the rollback dialog body.
	'.ifs-deploy-rollback-object'    => 'php',
	'.ifs-deploy-rollback-objects'   => 'php',
	'.ifs-deploy-rollback-panel'     => 'php',

	// Data attributes PHP must emit.
	//
	// The two preview ids are asserted WITHOUT the `data-` prefix on purpose:
	// PreviewModal::button() emits `data-%1$s` and takes 'queue-id' or 'post-id' as an
	// argument, so the full attribute name never appears literally in the source.
	'data-ifs-deploy-close'          => 'php',
	'data-ifs-deploy-confirm-close'  => 'php',
	'queue-id'                        => 'php',
	'post-id'                         => 'php',
	'data-revision-id'                => 'php',
	'data-title'                      => 'php',
	'data-field'                      => 'php',

	// Injected by admin.js, so they only have to exist there — but they still need
	// styling, which is asserted separately below.
	'.ifs-deploy-chip-item'          => 'js',
	'.ifs-deploy-chip-remove'        => 'js',
	'.ifs-deploy-preview-loading'    => 'js',
	'.ifs-deploy-rollback-ask'       => 'js',
);

/**
 * Classes admin.js WRITES with addClass/toggleClass. These carry behaviour, so they
 * must have real styling or the UI misbehaves silently (a dialog that never narrows,
 * a destructive button that looks ordinary).
 */
$written_classes = array(
	'ifs-deploy-modal-open',
	'ifs-deploy-modal-sm',
	'ifs-deploy-danger',
	'is-active',
);

/** Concatenated class names Tailwind's scanner cannot see — must stay unpurged. */
$dynamic_classes = array(
	'ifs-deploy-status-success',
	'ifs-deploy-status-failed',
	'ifs-deploy-status-partial',
	'ifs-deploy-status-rolledback',
	'ifs-deploy-chip-added',
	'ifs-deploy-chip-changed',
	'ifs-deploy-chip-removed',
	'ifs-deploy-chip-kept',
);

/** Concatenate every PHP file under src/. scandir, not glob — see tests/README.md. */
function php_source( string $dir ): string {
	$out = '';
	$it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );

	foreach ( $it as $file ) {
		if ( $file->isFile() && 'php' === $file->getExtension() ) {
			$out .= (string) file_get_contents( $file->getPathname() ) . "\n";
		}
	}

	return $out;
}

require __DIR__ . '/lib-css.php';

$php = php_source( $root . '/src' );
$js  = (string) file_get_contents( $root . '/assets/js/admin.js' );

/*
 * NORMALISED, not raw.
 *
 * The CSS assertions below are written against the minified build — `.dp-tab:focus{…}` with
 * no spaces. `admin.css` is generated, and its source header documents two commands: `--watch`
 * (expanded) and `--minify` (collapsed). Whichever ran last is what is on disk, so matching
 * raw text made these assertions pass or fail on the BUILD FLAG rather than on the CSS. Eleven
 * of them went red the first time the file was rebuilt in watch mode, with nothing wrong.
 *
 * Normalising once here means every regex below keeps working in both forms, unchanged.
 */
$css = dp_css_normalise( (string) file_get_contents( $root . '/assets/css/admin.css' ) );

$pass = 0;
$fail = 0;

function ok( string $name, bool $condition ): void {
	global $pass, $fail;
	if ( $condition ) {
		++$pass;
		echo "  PASS  $name\n";
	} else {
		++$fail;
		echo "  FAIL  $name\n";
	}
}

echo "=== hooks admin.js reads are still produced ===\n";
foreach ( $hooks as $hook => $where ) {
	// Strip the CSS sigil: the markup contains the bare name.
	$needle   = ltrim( $hook, '#.' );
	$haystack = ( 'js' === $where ) ? $js : $php;

	ok( "$hook produced in $where", false !== strpos( $haystack, $needle ) );
}

echo "=== classes admin.js writes have styling ===\n";
foreach ( $written_classes as $class ) {
	ok( ".$class styled", false !== strpos( $css, $class ) );
}

echo "=== dynamically-built classes survived purging ===\n";
foreach ( $dynamic_classes as $class ) {
	ok( ".$class present in built CSS", false !== strpos( $css, $class ) );
}

echo "=== Compare & Sync reads live state, never a cache ===\n";
//
// A stale comparison is the worst failure this screen has: it reports "In sync" for
// something that differs (or the reverse), and Refresh keeps reporting it because the
// second query is served from the same cache as the first. On a host with a PERSISTENT
// object cache that survives across requests, so the only escape was flushing by hand.
$index_src = (string) file_get_contents( $root . '/src/Support/SiteIndex.php' );

ok( 'the index query bypasses the query cache', false !== strpos( $index_src, "'cache_results'          => false" ) );
ok( 'it does not prime the meta cache from the query', false !== strpos( $index_src, "'update_post_meta_cache' => false" ) );

// Bypassing the query cache is not enough on its own: ContentSignature calls get_post()
// and get_post_meta(), which READ the cache. Stale entries have to be deleted.
ok( 'stale post rows are deleted', (bool) preg_match( "/wp_cache_delete_multiple\(\s*\\\$ids,\s*'posts'\s*\)/", $index_src ) );
ok( 'stale meta is deleted', (bool) preg_match( "/wp_cache_delete_multiple\(\s*\\\$ids,\s*'post_meta'\s*\)/", $index_src ) );

// Order matters and is easy to get wrong: update_post_cache() uses wp_cache_ADD_multiple,
// and *add* will not overwrite an existing key. Priming without deleting first leaves the
// stale rows exactly where they were.
$delete_at = strpos( $index_src, "wp_cache_delete_multiple( \$ids, 'posts' )" );
$prime_at  = strpos( $index_src, 'update_post_cache( $posts )' );
ok( 'the post cache is deleted BEFORE it is primed', false !== $delete_at && false !== $prime_at && $delete_at < $prime_at );

// Meta is re-primed in one call for every id, not left to N separate get_post_meta()
// queries — the difference is 1 query versus up to 2000.
ok( 'meta is re-primed in a single query', false !== strpos( $index_src, "update_meta_cache( 'post', \$ids )" ) );

// And the remote leg: no intermediary may replay a stored index response.
$client_src = (string) file_get_contents( $root . '/src/Client/DeployClient.php' );
ok( 'signed requests send no-cache', false !== strpos( $client_src, "'Cache-Control'          => 'no-cache, no-store, must-revalidate'" ) );
ok( 'and the legacy Pragma header too', false !== strpos( $client_src, "'Pragma'                 => 'no-cache'" ) );

// Those headers must NOT be part of the signed material, or adding them would have
// broken verification on the other site.
$signer_src = (string) file_get_contents( $root . '/src/Auth/Signer.php' );
ok( 'Cache-Control is not signed', false === strpos( $signer_src, 'Cache-Control' ) );

echo "=== Inter is bundled, not fetched from a CDN ===\n";
//
// wordpress.org's plugin guidelines forbid loading assets from third-party services, and
// serving fonts from Google's CDN sends visitor IPs to a third party (a GDPR problem on
// client sites). So the font must be a local file and the CSS must not reference a remote
// host — this asserts both, because a "quick fix" @import is exactly how that regresses.
$font = $root . '/assets/fonts/inter-latin-variable.woff2';

ok( 'the woff2 is committed', is_readable( $font ) );
ok( 'it is a real woff2 (wOF2 magic)', 'wOF2' === (string) file_get_contents( $font, false, null, 0, 4 ) );
// Variable Latin subset is ~48 KB. Well over 400 KB means someone swapped in the full
// family or a static set, which undoes the "lightweight" requirement.
ok( 'it stays small (< 400 KB)', filesize( $font ) < 400 * 1024 );

ok( 'the licence ships beside it', is_readable( $root . '/assets/fonts/Inter-LICENSE.txt' ) );

ok( 'CSS declares @font-face', false !== strpos( $css, '@font-face' ) );
ok( 'CSS points at the local file', false !== strpos( $css, 'fonts/inter-latin-variable.woff2' ) );
ok( 'CSS uses font-display: swap', false !== strpos( $css, 'font-display:swap' ) || false !== strpos( $css, 'font-display: swap' ) );
ok( 'no google fonts reference', false === stripos( $css, 'fonts.googleapis' ) && false === stripos( $css, 'fonts.gstatic' ) );
ok( 'no remote @import', ! preg_match( '/@import\s+url\(\s*[\x27"]?https?:/i', $css ) );

// Scoped to our screens: on `body` it would restyle the admin menu and every other
// plugin, which is the global bleed the whole layer is designed to avoid.
ok( 'the family is scoped to .ifs-deploy', (bool) preg_match( '/\.ifs-deploy\{[^}]*font-family:Inter/', $css ) );
ok( 'body is not restyled', ! preg_match( '/(^|\})body\{[^}]*font-family/', $css ) );

echo "=== no focus shadow on buttons or tabs ===\n";
//
// Requested explicitly. Two ways this regresses, so both are guarded:
//
//  1. Someone re-adds `tw-shadow-focus` to a button or tab rule.
//  2. Someone DELETES our `box-shadow: none` thinking it is redundant — at which point
//     core's `.wp-core-ui .button:focus { box-shadow: 0 0 0 1px #4f94d4, … }` shows
//     through and the ring comes back BLUE, which is worse than what we removed.
preg_match_all( '/[^{}]*(button|dp-tab|dp-subnav)[^{}]*:focus[^{}]*\{[^}]*\}/', $css, $focus_rules );

$with_shadow = array_values(
	array_filter(
		$focus_rules[0],
		static fn( string $rule ): bool => (bool) preg_match( '/box-shadow:(?!none)/', $rule )
	)
);

ok( 'focus rules for buttons/tabs exist at all', ! empty( $focus_rules[0] ) );
ok( 'none of them applies a focus shadow', empty( $with_shadow ) );
foreach ( $with_shadow as $rule ) {
	echo '        ' . substr( trim( $rule ), 0, 120 ) . "\n";
}

// The explicit suppressions that keep core's blue ring out.
ok( 'button focus shadow is suppressed', (bool) preg_match( '/\.ifs-deploy \.button[^{}]*:focus[^{}]*\{[^}]*box-shadow:none/', $css ) );
ok( 'tab focus shadow is suppressed', (bool) preg_match( '/\.ifs-deploy \.dp-tab[^{}]*:focus[^{}]*\{[^}]*box-shadow:none/', $css ) );

// Removing the ring must not leave keyboard users with nothing: the button's own border
// and the tab's own underline take over. Both are existing shapes recoloured, not new
// ones, so they cost mouse users nothing.
ok( 'buttons still mark keyboard focus (border)', (bool) preg_match( '/\.ifs-deploy \.button:focus-visible\{[^}]*border-color/', $css ) );
ok( 'tabs still mark keyboard focus (underline)', (bool) preg_match( '/\.ifs-deploy \.dp-tab:focus-visible:after\{[^}]*background-color/', $css ) );

// Text inputs are NOT in scope — a field has no other signal that keystrokes land in it.
ok( 'text inputs keep their focus ring', (bool) preg_match( '/\.ifs-deploy \.dp-input:focus[^{}]*\{[^}]*box-shadow:(?!none)/', $css ) );

echo "=== table surface: zebra and hover are distinguishable ===\n";
//
// The stripe and the hover tint must be DIFFERENT colours. If they collapse to one, the
// row under the pointer stops standing out and the table reads as static.
preg_match( '/nth-child\(odd\)[^{]*\{([^}]*)\}/', $css, $zebra );
preg_match( '/tbody tr:hover>\*[^{]*\{([^}]*)\}/', $css, $hover );

ok( 'a zebra rule exists', ! empty( $zebra[1] ) );
ok( 'a hover rule exists', ! empty( $hover[1] ) );
ok(
	'zebra and hover are different colours',
	! empty( $zebra[1] ) && ! empty( $hover[1] ) && $zebra[1] !== $hover[1]
);

// Row borders were dropped in favour of the stripe; both together was too much.
ok( 'status pills are bordered (legible on the stripe)', (bool) preg_match( '/\.ifs-deploy \.ifs-deploy-status(?![-a-z])[^{]*\{[^}]*border-width/', $css ) );

// The light frame around the table.
preg_match( '/[^{}]*\.wp-list-table\{([^}]*)\}/', $css, $table );
$table_rule = $table[1] ?? '';

ok( 'the table has a border', false !== strpos( $table_rule, 'border-width:1px' ) );
ok( 'the border is rounded', false !== strpos( $table_rule, 'border-radius' ) );

// `border-collapse: separate` is load-bearing, not stylistic: a COLLAPSED table ignores
// border-radius on its corner cells, so the frame would render with square corners
// poking past the rounded border. `overflow: hidden` is not reliably honoured on a
// <table>, so the usual clipping trick cannot rescue it either.
ok( 'border-collapse is separate', false !== strpos( $table_rule, 'border-collapse:separate' ) );
ok( 'with zero spacing', false !== strpos( $table_rule, 'border-spacing:0' ) );

// All four corner cells rounded, or the zebra tint squares off whichever corner it lands
// in. The last row is the one that shows this.
foreach ( array( 'border-top-left-radius', 'border-top-right-radius', 'border-bottom-left-radius', 'border-bottom-right-radius' ) as $corner ) {
	ok( "corner cells set $corner", (bool) preg_match( '/wp-list-table (thead|tbody)[^{]*\{[^}]*' . $corner . '/', $css ) );
}

// One border source. The scroll wrapper must NOT also draw one, or a wrapped table
// (the role matrix) shows a doubled frame.
ok(
	'the scroll wrapper draws no border of its own',
	(bool) preg_match( '/\.ifs-deploy \.dp-table-wrap\{([^}]*)\}/', $css, $wrap ) && false === strpos( $wrap[1], 'border-width' )
);

echo "=== tabs render content only; Screen owns the chrome ===\n";
//
// Every tab's content is injected into one panel that is swapped over AJAX. Anything a
// page class emits ONCE-per-screen would be duplicated or orphaned by that swap, so the
// wrapper, the notice slot and the dialog shells must live in Screen and nowhere else.
$pages = array( 'Dashboard', 'PendingChanges', 'Compare', 'History', 'Settings', 'Logs' );
foreach ( $pages as $page ) {
	$src = (string) file_get_contents( $root . "/src/Admin/Pages/{$page}Page.php" );

	ok( "{$page}Page titles itself with Section", false !== strpos( $src, 'Section::title' ) );
	ok( "{$page}Page imports Section", false !== strpos( $src, 'use IfsDeploy\Admin\Section;' ) );

	// The wrapper belongs to Screen. A page emitting its own would nest a .wrap inside
	// the panel on every AJAX load.
	ok( "{$page}Page emits no page wrapper", false === strpos( $src, 'wrap ifs-deploy' ) );

	// One notice target per screen, or admin.js writes into whichever id it finds first.
	ok( "{$page}Page emits no notice slot", false === strpos( $src, 'id="ifs-deploy-notice"' ) );

	// Dialog shells are rendered once by Screen; a per-tab copy would leave a stale
	// dialog in the DOM after a swap, or two elements sharing one id.
	ok( "{$page}Page renders no dialog shell", false === strpos( $src, 'Modal::render()' ) );
}

$screen = (string) file_get_contents( $root . '/src/Admin/Screen.php' );
ok( 'Screen emits the wrap class', false !== strpos( $screen, 'wrap ifs-deploy' ) );
ok( 'Screen emits wp-header-end', false !== strpos( $screen, 'wp-header-end' ) );
ok( 'Screen emits the notice slot', false !== strpos( $screen, 'id="ifs-deploy-notice"' ) );
ok( 'Screen renders the preview dialog', false !== strpos( $screen, 'PreviewModal::render()' ) );
ok( 'Screen renders the confirm dialog', false !== strpos( $screen, 'ConfirmModal::render()' ) );
ok( 'Screen renders the panel the script targets', false !== strpos( $screen, 'id="dp-tab-panel"' ) );

echo "=== tab switching contract ===\n";
$tabs  = (string) file_get_contents( $root . '/src/Admin/Tabs.php' );
$ajax  = (string) file_get_contents( $root . '/src/Admin/Ajax.php' );
// Comments stripped: these assertions count CALLS, and the docblock explaining why the
// menu is registered-then-removed naturally mentions add_menu_page() by name.
$menu  = (string) php_strip_whitespace( $root . '/src/Admin/AdminMenu.php' );

// The capability check that matters is the one in Tabs::render(): the AJAX endpoint
// accepts any slug the client sends, so hiding a tab from the bar is not a control.
ok( 'Tabs::render re-checks capability', false !== strpos( $tabs, 'available()' ) && false !== strpos( $tabs, 'permission' ) );
ok( 'AJAX tab action registered', false !== strpos( $ajax, 'wp_ajax_ifs_deploy_tab' ) );
ok( 'AJAX tab action goes through the guard', (bool) preg_match( '/function tab\(\).*?\$this->guard\(/s', $ajax ) );
ok( 'AJAX tab responds with html', (bool) preg_match( '/function tab\(\).*?Tabs::render/s', $ajax ) );

// Exactly one page is registered now; a stray add_submenu_page would resurrect a
// screen that no longer renders its own chrome.
ok( 'one admin page registered', 1 === substr_count( $menu, 'add_menu_page(' ) );
ok( 'no submenu pages registered', false === strpos( $menu, 'add_submenu_page(' ) );
ok( 'legacy slugs are redirected', false !== strpos( $menu, 'redirect_legacy_slugs' ) );

/*
 * ── HIDDEN ON PRODUCTION, BUT STILL REACHABLE ─────────────────────────────────────
 *
 * `add_menu_page()` registers the sidebar entry AND the page route. Simply not calling it
 * would un-register the route, so `?page=ifs-deploy` would answer "Sorry, you are not
 * allowed to access this page" — on the one site where the Plugins-screen link is the only
 * way in. `remove_menu_page()` drops the entry and leaves the route, which is the whole
 * trick.
 */
ok( 'the menu is registered, then removed', false !== strpos( $menu, 'remove_menu_page(' ) );
ok( 'and removed LATE, after it exists', false !== strpos( $menu, chr(39) . 'maybe_hide_menu' . chr(39) . ' ), 999' ) );
ok( 'hiding is decided by the site role', (bool) preg_match( '/maybe_hide_menu.*?Config::is_production\(\)/s', $menu ) );
ok( 'and is filterable either way', false !== strpos( $menu, 'ifs_deploy_hide_admin_menu' ) );

// The way back in. Registered with plugin_basename() rather than a hardcoded
// `ifs-deploy/ifs-deploy.php`: the folder name is not guaranteed, and a wrong basename
// fails silently — no error, just no link, on the site that needs it most.
ok( 'a Plugins-screen link is added', false !== strpos( $menu, 'plugin_action_links_' ) );
ok( 'keyed on the real basename', false !== strpos( $menu, 'plugin_basename( IFS_DEPLOY_FILE )' ) );
ok( 'pointing at Settings', (bool) preg_match( "/action_links.*?Tabs::url\( 'settings' \)/s", $menu ) );

// Not shown to somebody who would only be refused on arrival.
ok( 'and hidden from users who cannot use it', (bool) preg_match( '/action_links.*?current_user_can\( Access::CAP_RESTRICTED \)/s', $menu ) );

// Settings' inner sections must NOT use `tab`, which now selects the top-level tab.
$settings = (string) file_get_contents( $root . '/src/Admin/Pages/SettingsPage.php' );
ok( 'Settings sections use section=, not tab=', false === strpos( $settings, "_GET['tab']" ) );
ok( 'Settings reads section=', false !== strpos( $settings, "_GET['section']" ) );

// Screen must consume handle_post()'s return value; discarding it swallows the notice.
ok( 'Screen surfaces the settings save notice', (bool) preg_match( '/=\s*SettingsPage::handle_post\(\)/', $screen ) );

echo "=== every handler survives a panel swap ===\n";
//
// A direct binding (`$( '#id' ).on( ... )`) dies the moment the panel is replaced,
// which is silent: the button simply stops working. Every binding must be delegated to
// document, or be re-run by initPanel().
if ( preg_match_all( "/^\t*\\\$\( '[#.][^']+' \)\.on\( /m", $js, $direct ) ) {
	foreach ( $direct[0] as $line ) {
		ok( 'direct binding (dies on swap): ' . trim( $line ), false );
	}
} else {
	ok( 'no direct element bindings in admin.js', true );
}

ok( 'admin.js initialises tabs', false !== strpos( $js, 'initTabs()' ) );
ok( 'admin.js re-runs element initialisers after a swap', false !== strpos( $js, "trigger( 'ifs-deploy:panel' )" ) );
ok( 'admin.js listens for that event', false !== strpos( $js, "on( 'ifs-deploy:panel', initPanel )" ) );
ok( 'admin.js switches on [data-tab]', false !== strpos( $js, "'[data-tab]'" ) );
ok( 'admin.js handles back/forward', false !== strpos( $js, "'popstate'" ) );
// Modified clicks must stay real navigations, or middle-click stops opening a new tab.
ok( 'admin.js leaves modified clicks alone', false !== strpos( $js, 'e.metaKey' ) );

echo "\n=== toasts: JS builds them, the stylesheet dresses them ===\n";
//
// Results used to be a wp-admin notice near the top of the panel, so a push made from
// halfway down a long table reported itself off-screen — and several of these actions
// reload, which destroyed the notice a moment after it appeared. Every class below is
// created in JS, so nothing in PHP would catch a rename: this is the only thing standing
// between a working toast and an unstyled one.
foreach ( array( 'ifs-deploy-toasts', 'ifs-deploy-toast', 'ifs-deploy-toast-text', 'ifs-deploy-toast-close' ) as $class ) {
	ok( "JS creates .$class", false !== strpos( $js, $class ) );
	ok( "and the stylesheet defines .$class", dp_css_has( $css, '.' . $class ) );
}

foreach ( array( 'is-success', 'is-error', 'is-visible' ) as $state ) {
	ok( "the .$state state is styled", dp_css_has( $css, '.ifs-deploy-toast.' . $state ) );
}

// Fixed to the VIEWPORT — the whole point. A toast that scrolls with the document has the
// same problem the notice had.
ok( 'the container is fixed', dp_css_has( dp_css_rule( $css, '.ifs-deploy-toasts' ), 'position:fixed' ) );
// Below .ifs-deploy-modal (9999): a toast must never cover a dialog being read.
ok( 'and sits below the dialog layer', dp_css_has( dp_css_rule( $css, '.ifs-deploy-toasts' ), 'z-index:9990' ) );
// The container spans a corner of the screen; without this it would swallow clicks on
// whatever is behind it.
ok( 'the container does not eat clicks', dp_css_has( dp_css_rule( $css, '.ifs-deploy-toasts' ), 'pointer-events:none' ) );
ok( 'while each toast still takes them', dp_css_has( dp_css_rule( $css, '.ifs-deploy-toast' ), 'pointer-events:auto' ) );

// Errors must NOT auto-dismiss: a success that vanishes has been read or does not matter,
// an error that vanishes takes the only account of what went wrong with it.
ok( 'only successes are auto-dismissed', (bool) preg_match( '/if\s*\(\s*!\s*isError\s*\)\s*\{\s*window\.setTimeout/', $js ) );
ok( 'errors are announced assertively', false !== strpos( $js, "isError ? 'assertive' : 'polite'" ) );
ok( 'and marked up as alerts', false !== strpos( $js, "isError ? 'alert' : 'status'" ) );

// Server messages can quote a filename, a post title, or a raw response body from the
// other site — none of which may be interpreted as markup.
ok( 'toast text is escaped, not injected', (bool) preg_match( '/ifs-deploy-toast-text.*?\.text\(\s*message\s*\)/s', $js ) );

// The reload would otherwise tear the toast down a moment after it appeared.
ok( 'messages survive a page reload', false !== strpos( $js, 'sessionStorage' ) );
ok( 'and are shown once the new page is up', false !== strpos( $js, 'drainToasts()' ) );

echo "\n=== every action reports itself the same way ===\n";
//
// Rollback used to reload from its own handler, with its own delay, while request() showed
// a NON-persisted toast — so the one action people most want confirmation of was the one
// whose confirmation the reload destroyed. One list now decides which actions reload, and
// they all take the same path through it.
ok( 'the reloading actions are one list', false !== strpos( $js, 'var RELOAD_ACTIONS' ) );

foreach (
	array(
		'ifs_deploy_deploy',
		'ifs_deploy_deploy_posts',
		'ifs_deploy_ignore',
		'ifs_deploy_rollback',
		'ifs_deploy_sync_ids',
		'ifs_deploy_clear_history',
		'ifs_deploy_clear_log',
	) as $action
) {
	ok( "$action is in it", (bool) preg_match( '/RELOAD_ACTIONS = \[[^\]]*' . preg_quote( $action, '/' ) . '/s', $js ) );
}

// Exactly one reload in the whole file, inside reloadWith(). A second one anywhere is how
// these behaviours drifted apart before — and the batched push, which needs the same
// ending, calls the helper instead of repeating it.
ok( 'only one place reloads', 1 === substr_count( $js, 'window.location.reload()' ) );
ok( 'it lives in one helper', false !== strpos( $js, 'function reloadWith(' ) );
// Stash the message BEFORE rebuilding the page, or the reload destroys the very
// confirmation it was shown for. Keeping the two steps in one function is what stops them
// being separated again.
ok( 'and it persists the toast first', (bool) preg_match( '/function reloadWith\([^)]*\)\s*\{\s*notify\(\s*message,\s*isError,\s*true\s*\);.*?window\.location\.reload/s', $js ) );
ok( 'the batched push reuses it', 3 <= substr_count( $js, 'reloadWith(' ) );

echo "\n=== a server-rendered result uses the same toast ===\n";
//
// Saving settings posts a real form, so its outcome is decided during render — long after
// admin_enqueue_scripts, which is why it cannot simply be localised into the script. It
// used to be printed as a wp-admin notice instead, so one part of the plugin reported
// itself in WordPress's voice while every other action reported itself in the plugin's.
// Comments stripped: the docblock explaining this very change names the string it is
// looking for, and would satisfy the assertion on its own.
$settings = (string) php_strip_whitespace( $root . '/src/Admin/Pages/SettingsPage.php' );

ok( 'the save emits a marker, not a wp-admin notice', false !== strpos( $settings, 'ifs-deploy-flash' ) );
ok( 'and no success notice is printed there', false === strpos( $settings, 'notice notice-success' ) );

/*
 * The notices that REMAIN on that screen are deliberate, and the distinction is the point.
 *
 * "Your settings were saved" is the result of an ACTION and belongs in the toast, with
 * every other action's result. "The address rules currently restrict the API" describes a
 * standing STATE of the screen — it is true until the setting changes, and a message that
 * fades after six seconds would be exactly the wrong shape for it.
 */
ok( 'standing state notices are left inline', false !== strpos( $settings, 'notice notice-' ) );
ok( 'JS turns markers into toasts', false !== strpos( $js, 'function drainFlashes(' ) );
// Removed once shown, or switching away and back replays a message about something that
// happened two screens ago.
ok( 'and removes them once shown', (bool) preg_match( '/function drainFlashes\(\).*?\$flash\.remove\(\)/s', $js ) );
// On load AND after a panel swap: the AJAX tab loader replaces the markup with no page
// load happening at all.
ok( 'drained on load', (bool) preg_match( '/drainToasts\(\);\s*(\/\/[^\n]*\n\s*)*drainFlashes\(\);/', $js ) );
ok( 'and after a panel swap', (bool) preg_match( '/function initPanel\(\).*?drainFlashes\(\)/s', $js ) );

// Nothing may hardcode a user-facing string: it cannot be translated, and it is the one
// kind of message that escapes the i18n contract contracts-test.php enforces.
ok( 'no hardcoded message text is left', 0 === preg_match( "/notify\(\s*'/", $js ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
