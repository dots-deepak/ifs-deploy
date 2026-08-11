<?php
/**
 * The pre-rename → `ifs_deploy_` data migration.
 *
 * This is the highest-risk code in the plugin, because the thing it protects is not a feature
 * — it is the identity of every object ever deployed. If the origin-id meta is not
 * carried over, Production stops recognising its own pages as copies of Staging pages, and the
 * next deploy creates a DUPLICATE of each one instead of updating it. On the site this was
 * written for that is roughly 2000 duplicates.
 *
 * So the assertions here are mostly about the awkward cases rather than the happy path:
 * a fresh install must not be touched, a half-finished run must be completable, a collision
 * must not throw away the newer value, and the caches must be invalidated or the site keeps
 * reading what it read before.
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
 * Stubs
 * -------------------------------------------------------------------------- */

// PHP falls back to the global namespace for CONSTANTS, so these resolve unqualified in a
// real install and are simply absent from this harness.
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['dp_options']    = array();
$GLOBALS['dp_cron']       = array();
$GLOBALS['dp_flushed']    = 0;
$GLOBALS['dp_log']        = array();

function __( string $t, string $d = '' ): string {
	return $t;
}
function get_option( string $n, $default = false ) {
	return array_key_exists( $n, $GLOBALS['dp_options'] ) ? $GLOBALS['dp_options'][ $n ] : $default;
}
function update_option( string $n, $v, $a = null ): bool {
	$GLOBALS['dp_options'][ $n ] = $v;

	return true;
}
function delete_option( string $n ): bool {
	unset( $GLOBALS['dp_options'][ $n ] );

	return true;
}
function wp_cache_flush(): bool {
	++$GLOBALS['dp_flushed'];

	return true;
}
function current_time( string $type = 'mysql', $gmt = 0 ) {
	return 'timestamp' === $type ? time() : gmdate( 'Y-m-d H:i:s' );
}
function wp_json_encode( $d, int $f = 0 ) {
	return json_encode( $d, $f );
}
function wp_next_scheduled( string $hook ) {
	return $GLOBALS['dp_cron'][ $hook ] ?? false;
}
function wp_schedule_event( int $when, string $r, string $hook ): bool {
	$GLOBALS['dp_cron'][ $hook ] = $when;

	return true;
}
function wp_clear_scheduled_hook( string $hook ): void {
	unset( $GLOBALS['dp_cron'][ $hook ] );
}
function apply_filters( string $h, $v, ...$args ) {
	return $v;
}

/**
 * A $wpdb that models the two things this migration can trip over: `option_name` is UNIQUE,
 * and a table cannot be renamed onto one that already exists.
 */
class DP_Legacy_WPDB {
	public string $prefix   = 'wp_';
	public string $options  = 'wp_options';
	public string $postmeta = 'wp_postmeta';
	public string $termmeta = 'wp_termmeta';

	/** table name => true */
	public array $tables = array();

	/** option_name => option_id */
	public array $option_rows = array();

	/** meta_key => row count, per table */
	public array $meta = array( 'wp_postmeta' => array(), 'wp_termmeta' => array() );

	public array $queries = array();

	private string $sql  = '';
	private array $args = array();

	public function prepare( string $sql, ...$args ): string {
		$this->sql  = $sql;
		$this->args = $args;

		return $sql;
	}

	public function get_var( $query ) {
		$sql = (string) $query;

		if ( false !== strpos( $sql, 'SHOW TABLES LIKE' ) ) {
			$name = (string) ( $this->args[0] ?? '' );

			return isset( $this->tables[ $name ] ) ? $name : null;
		}

		if ( false !== strpos( $sql, 'SELECT option_id' ) ) {
			$name = (string) ( $this->args[0] ?? '' );

			return $this->option_rows[ $name ] ?? null;
		}

		return null;
	}

	public function query( $query ) {
		$sql             = (string) $query;
		$this->queries[] = $sql;

		// RENAME TABLE `old` TO `new`
		if ( preg_match( '/RENAME TABLE `([^`]+)` TO `([^`]+)`/', $sql, $m ) ) {
			unset( $this->tables[ $m[1] ] );
			$this->tables[ $m[2] ] = true;

			return 1;
		}

		/*
		 * UPDATE <table> SET meta_key = REPLACE( meta_key, %s, %s ) WHERE meta_key LIKE %s
		 *
		 * The two prefixes are taken from the PREPARED ARGS rather than restated here, so the
		 * stub performs whatever substitution the migration actually asked for. Restating them
		 * would make the stub agree with a broken migration.
		 */
		if ( preg_match( '/UPDATE (\S+) SET meta_key = REPLACE/', $sql, $m ) ) {
			$table   = $m[1];
			$from    = (string) ( $this->args[0] ?? '' );
			$to      = (string) ( $this->args[1] ?? '' );
			$renamed = 0;

			foreach ( $this->meta[ $table ] ?? array() as $key => $rows ) {
				if ( '' === $from || 0 !== strpos( $key, $from ) ) {
					continue;
				}

				$new = str_replace( $from, $to, $key );

				$this->meta[ $table ][ $new ] = ( $this->meta[ $table ][ $new ] ?? 0 ) + $rows;
				unset( $this->meta[ $table ][ $key ] );

				$renamed += $rows;
			}

			return $renamed;
		}

		return 0;
	}

	/**
	 * Renaming an option row moves its VALUE too.
	 *
	 * The first version of this stub kept `option_rows` (the table) and `$dp_options` (what
	 * `get_option()` reads) as two unrelated stores, so the migration appeared to rename rows
	 * while every later `get_option()` still returned the old name — and the capability
	 * assertions failed against correct code. In WordPress there is one store: renaming the
	 * row IS moving the value, once the cache is invalidated.
	 */
	public function update( $table, $data, $where, $f = null, $wf = null ) {
		$old = (string) ( $where['option_name'] ?? '' );
		$new = (string) ( $data['option_name'] ?? '' );

		if ( '' === $old || ! isset( $this->option_rows[ $old ] ) ) {
			return 0;
		}

		// The UNIQUE index. A migration that renamed onto a live key would abort here.
		if ( isset( $this->option_rows[ $new ] ) ) {
			throw new RuntimeException( "UNIQUE violation on option_name: $new" );
		}

		$this->option_rows[ $new ] = $this->option_rows[ $old ];
		unset( $this->option_rows[ $old ] );

		if ( array_key_exists( $old, $GLOBALS['dp_options'] ) ) {
			$GLOBALS['dp_options'][ $new ] = $GLOBALS['dp_options'][ $old ];
			unset( $GLOBALS['dp_options'][ $old ] );
		}

		return 1;
	}

	public function delete( $table, $where, $f = null ) {
		$name = (string) ( $where['option_name'] ?? '' );

		if ( ! isset( $this->option_rows[ $name ] ) ) {
			return 0;
		}

		unset( $this->option_rows[ $name ], $GLOBALS['dp_options'][ $name ] );

		return 1;
	}

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}
}

spl_autoload_register(
	static function ( string $class ) use ( $root ): void {
		if ( 0 !== strpos( $class, 'IfsDeploy' . chr( 92 ) ) ) {
			return;
		}

		$path = $root . '/src/' . str_replace( chr( 92 ), '/', substr( $class, strlen( 'IfsDeploy' ) + 1 ) ) . '.php';

		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

use IfsDeploy\Support\Legacy;
use IfsDeploy\Support\LegacyRename;

/*
 * EVERY old-name string below comes from `Support\Legacy`, never from a literal.
 *
 * Two reasons. A fixture built from the same constant the migration reads cannot drift out of
 * step with it — hard-coding the old prefix here would let the test keep passing against a
 * migration that had been pointed somewhere else. And it keeps the old spelling in one file,
 * so "why does the codebase still say that?" has a single answer.
 *
 * The exception is the block at the very bottom that PINS the constant values. Those must
 * spell the strings out: a comparison of a constant against itself proves nothing, and what
 * needs proving is that the bytes still match what is on disk.
 */
const OLD      = Legacy::OPTION_PREFIX;
const OLD_META = Legacy::META_PREFIX;
const OLD_CRON = Legacy::CRON_HOOK;

const DONE   = 'ifs_deploy_legacy_migrated';
const TABLES = array( 'queue', 'deployments', 'revisions', 'api_log', 'nonces' );

/** A $wpdb and option set that looks like a configured pre-rename install. */
function legacy_install(): DP_Legacy_WPDB {
	$db = new DP_Legacy_WPDB();

	foreach ( TABLES as $i => $suffix ) {
		$db->tables[ 'wp_' . OLD . $suffix ] = true;
	}

	$id = 100;
	foreach ( array( 'db_version', 'role', 'credentials', 'remote', 'ip_allow', 'content_firewall' ) as $suffix ) {
		$db->option_rows[ OLD . $suffix ] = ++$id;
		$GLOBALS['dp_options'][ OLD . $suffix ] = 'value-of-' . $suffix;
	}

	/*
	 * The two option VALUES that carry capability names as array keys. Renaming the option row
	 * does nothing for these, and the failure is invisible from the admin side.
	 */
	$db->option_rows[ OLD . 'roles' ] = ++$id;
	$db->option_rows[ OLD . 'users' ] = ++$id;

	$GLOBALS['dp_options'][ OLD . 'roles' ] = array(
		'editor' => array(
			OLD . 'access'   => true,
			OLD . 'deploy'   => true,
			OLD . 'rollback' => false,
			'some_other_cap' => true,
		),
	);
	$GLOBALS['dp_options'][ OLD . 'users' ] = array(
		'allow'      => array( 7, 9 ),
		'block'      => array( 12 ),
		'allow_caps' => array( OLD . 'access' => true, OLD . 'view_all' => false ),
	);

	// The identity meta, on a site with real content.
	$db->meta['wp_postmeta'] = array(
		OLD_META . 'origin_id'   => 2000,
		OLD_META . 'origin_site' => 2000,
		OLD_META . 'src_sig'     => 1800,
		'_thumbnail_id'          => 1500,
	);
	$db->meta['wp_termmeta'] = array(
		OLD_META . 'origin_term_id' => 40,
		'other_plugin_key'          => 12,
	);

	$GLOBALS['dp_options']  = $GLOBALS['dp_options'] ?? array();
	$GLOBALS['dp_cron']     = array( OLD_CRON => time() + 3600 );
	$GLOBALS['dp_flushed']  = 0;
	$GLOBALS['wpdb']        = $db;

	return $db;
}

/* -----------------------------------------------------------------------------
 * The happy path
 * -------------------------------------------------------------------------- */

echo "=== a configured pre-rename install is migrated ===\n";

$GLOBALS['dp_options'] = array();
$db                    = legacy_install();

LegacyRename::run();

foreach ( TABLES as $suffix ) {
	ok( "table $suffix was renamed", isset( $db->tables[ 'wp_ifs_deploy_' . $suffix ] ) );
	ok( "and the old $suffix name is gone", ! isset( $db->tables[ 'wp_' . OLD . $suffix ] ) );
}

// Credentials are the ones that break the PAIR: a missing option regenerates the secret and
// the other site is refused on every request from then on.
ok( 'credentials moved', isset( $db->option_rows['ifs_deploy_credentials'] ) );
ok( 'role moved', isset( $db->option_rows['ifs_deploy_role'] ) );
ok( 'the remote connection moved', isset( $db->option_rows['ifs_deploy_remote'] ) );
ok( 'the address allow list moved', isset( $db->option_rows['ifs_deploy_ip_allow'] ) );
ok( 'and no old option row survives', array() === array_filter( array_keys( $db->option_rows ), static fn( $k ) => 0 === strpos( $k, OLD ) ) );

echo "=== content identity survives, which is the whole point ===\n";

// THE assertion. Without this every one of those 2000 posts is unrecognisable to the next
// deploy, and each one gets a duplicate rather than an update.
ok( 'origin id meta was rewritten', 2000 === ( $db->meta['wp_postmeta']['_ifs_deploy_origin_id'] ?? 0 ) );
ok( 'origin site meta was rewritten', 2000 === ( $db->meta['wp_postmeta']['_ifs_deploy_origin_site'] ?? 0 ) );
ok( 'the source signature moved too', 1800 === ( $db->meta['wp_postmeta']['_ifs_deploy_src_sig'] ?? 0 ) );
ok( 'no old post meta key remains', ! isset( $db->meta['wp_postmeta'][ OLD_META . 'origin_id' ] ) );

// Terms have their own identity meta and their own table.
ok( 'term identity meta was rewritten', 40 === ( $db->meta['wp_termmeta']['_ifs_deploy_origin_term_id'] ?? 0 ) );

// Another plugin's meta must be untouched — the rename is scoped to our own prefix.
ok( 'core meta is untouched', 1500 === ( $db->meta['wp_postmeta']['_thumbnail_id'] ?? 0 ) );
ok( "another plugin's term meta is untouched", 12 === ( $db->meta['wp_termmeta']['other_plugin_key'] ?? 0 ) );

echo "=== capability names INSIDE the option values ===\n";

/*
 * The step a table rename does not cover, and the one that fails silently.
 *
 * `Support\Access` capabilities are named after the plugin, and they are stored as array KEYS
 * inside two option values. Renaming the option row leaves those keys saying
 * the old spelling, while `Access::permissions()` reads the new one — false for
 * every one. Administrators keep access because that is hard-coded, so the owner sees nothing
 * wrong while every delegated role is locked out of the plugin.
 */
$roles = $GLOBALS['dp_options']['ifs_deploy_roles'] ?? array();

ok( 'the role matrix survived', isset( $roles['editor'] ) );
ok( 'and its caps were rekeyed', ! empty( $roles['editor']['ifs_deploy_deploy'] ) );
ok( 'no old cap key remains', ! array_key_exists( OLD . 'deploy', (array) ( $roles['editor'] ?? array() ) ) );
// A false stays false — rekeying must not grant anything that was not granted.
ok( 'a denied capability stays denied', isset( $roles['editor']['ifs_deploy_rollback'] ) && false === $roles['editor']['ifs_deploy_rollback'] );
// Anything that is not one of ours is left exactly as it was.
ok( "another plugin's key is untouched", ! empty( $roles['editor']['some_other_cap'] ) );

$users = $GLOBALS['dp_options']['ifs_deploy_users'] ?? array();

ok( 'per-user caps were rekeyed', ! empty( $users['allow_caps']['ifs_deploy_access'] ) );
ok( 'and the old key is gone', ! array_key_exists( OLD . 'access', (array) ( $users['allow_caps'] ?? array() ) ) );
// ids are not capability names and must survive verbatim.
ok( 'the allow list of user ids survives', array( 7, 9 ) === ( $users['allow'] ?? array() ) );
ok( 'and the block list too', array( 12 ) === ( $users['block'] ?? array() ) );

echo "=== caches, cron and the marker ===\n";

// Renaming rows under a warm cache means the site keeps reading the OLD values, which looks
// exactly like the migration never running.
ok( 'the object cache was flushed', $GLOBALS['dp_flushed'] >= 1 );

ok( 'the old cron event was cleared', ! isset( $GLOBALS['dp_cron'][ OLD_CRON ] ) );
ok( 'and the new one was scheduled', isset( $GLOBALS['dp_cron']['ifs_deploy_purge_logs'] ) );

ok( 'the migration is marked done', '' !== (string) get_option( DONE, '' ) );
ok( 'and the marker is not the "nothing to do" value', 'not-needed' !== get_option( DONE ) );

// A site owner should not have to infer that their credentials and content identity moved.
$log = $GLOBALS['dp_options']['ifs_deploy_debug_log'] ?? array();
ok( 'the migration is logged', ! empty( $log ) );
ok( 'at warning level, because the pair is now mismatched', 'warning' === ( $log[0]['level'] ?? '' ) );
ok( 'and it says the other site must be updated too', false !== strpos( wp_json_encode( $log[0] ?? array() ), 'paired site must be updated' ) );

/* -----------------------------------------------------------------------------
 * The cases that make it safe to ship
 * -------------------------------------------------------------------------- */

echo "=== running twice changes nothing ===\n";

$before_flushes = $GLOBALS['dp_flushed'];
$before_queries = count( $db->queries );

LegacyRename::run();

ok( 'no further queries', $before_queries === count( $db->queries ) );
ok( 'no second cache flush', $before_flushes === $GLOBALS['dp_flushed'] );

echo "=== a fresh install is not touched ===\n";

$GLOBALS['dp_options'] = array();
$GLOBALS['dp_cron']    = array();
$GLOBALS['dp_flushed'] = 0;

$fresh           = new DP_Legacy_WPDB();
$GLOBALS['wpdb'] = $fresh;

LegacyRename::run();

// No pre-rename db-version option means there is nothing from before the rename.
ok( 'nothing was queried', array() === $fresh->queries );
ok( 'no cache flush', 0 === $GLOBALS['dp_flushed'] );
ok( 'nothing was logged', empty( $GLOBALS['dp_options']['ifs_deploy_debug_log'] ) );
// Marked anyway, so the check never runs again — the fast path is one autoloaded read.
ok( 'but it is marked as considered', 'not-needed' === get_option( DONE ) );

echo "=== a half-finished run completes without throwing ===\n";

/*
 * The failure this models is real: a request times out or a fatal lands mid-migration, and the
 * marker was never written. The second attempt sees some names already moved.
 *
 * `$wpdb->update()` in the stub THROWS on a UNIQUE violation, exactly as MySQL would abort the
 * statement. So if the migration renamed blindly, this block fails loudly rather than subtly.
 */
$GLOBALS['dp_options'] = array();
$GLOBALS['dp_cron']    = array();
$GLOBALS['dp_flushed'] = 0;

$partial                                     = new DP_Legacy_WPDB();
$partial->tables['wp_ifs_deploy_queue']      = true;  // already moved
$partial->tables[ 'wp_' . OLD . 'queue' ]     = true;  // leftover
$partial->tables[ 'wp_' . OLD . 'revisions' ] = true;  // not moved yet

$partial->option_rows[ OLD . 'db_version' ]  = 1;
$partial->option_rows[ OLD . 'role' ]        = 2;
$partial->option_rows['ifs_deploy_role']         = 3;  // already moved — a collision
$partial->option_rows[ OLD . 'credentials' ] = 4;

$GLOBALS['dp_options'][ OLD . 'db_version' ] = '5';
$GLOBALS['wpdb']                                = $partial;

$threw = '';
try {
	LegacyRename::run();
} catch ( Throwable $e ) {
	$threw = get_class( $e ) . ': ' . $e->getMessage();
}

ok( 'it does not throw on a collision', '' === $threw ) or print( "        $threw\n" );
ok( 'the un-moved table is now moved', isset( $partial->tables['wp_ifs_deploy_revisions'] ) );
// The already-moved table is left as it is rather than renamed over.
ok( 'the already-moved table is intact', isset( $partial->tables['wp_ifs_deploy_queue'] ) );
ok( 'and its leftover is not renamed over it', isset( $partial->tables[ 'wp_' . OLD . 'queue' ] ) );

// On a collision the NEWER value wins and the stale row goes. Renaming over it would have
// aborted the statement and left everything after it unmigrated.
ok( 'the newer option value is kept', 3 === $partial->option_rows['ifs_deploy_role'] );
ok( 'the stale duplicate is removed', ! isset( $partial->option_rows[ OLD . 'role' ] ) );
ok( 'and the remaining option still moved', isset( $partial->option_rows['ifs_deploy_credentials'] ) );

echo "=== it runs before anything reads an option ===\n";

/*
 * Ordering is the one thing a unit test cannot demonstrate by running it: `Schema` would
 * create empty tables beside the real ones, and after that a rename is impossible. Asserted
 * against the source instead.
 *
 * COMMENTS STRIPPED, and that is not incidental. All three of these assertions failed on the
 * first run because the docblocks EXPLAIN the ordering — `Plugin.php` names
 * `Schema::maybe_upgrade()` in the comment above the call, so the raw text found it first and
 * the comparison inverted. `php_strip_whitespace()` leaves only what executes.
 */
$plugin_src = php_strip_whitespace( $root . '/src/Plugin.php' );

ok( 'boot() migrates first', strpos( $plugin_src, 'LegacyRename::run()' ) < strpos( $plugin_src, 'Schema::maybe_upgrade()' ) );

$activator_src = php_strip_whitespace( $root . '/src/Support/Activator.php' );

// The likelier path of the two: renaming the plugin folder makes WordPress deactivate it, so
// the owner switches it back on and activation runs.
ok( 'activation migrates first too', strpos( $activator_src, 'LegacyRename::run()' ) < strpos( $activator_src, 'Schema::install()' ) );

$uninstall_src = (string) file_get_contents( $root . '/uninstall.php' );
ok( 'uninstall removes the marker', false !== strpos( $uninstall_src, 'ifs_deploy_legacy_migrated' ) );
// Via the constant, and via a bare `require` — uninstall.php runs with no autoloader.
ok( 'and the pre-rename cron event', false !== strpos( $uninstall_src, 'Legacy::CRON_HOOK' ) );
ok( 'requiring Legacy directly, since there is no autoloader', false !== strpos( $uninstall_src, "require_once __DIR__ . '/src/Support/Legacy.php'" ) );

$uninstall_src = (string) file_get_contents( $root . '/uninstall.php' );

// Comments stripped again: the class docblock DISCUSSES the LIKE sweep it does not do, so the
// raw source contains the exact string this asserts is absent.
$legacy_code = php_strip_whitespace( $root . '/src/Support/LegacyRename.php' );
$legacy_src  = (string) file_get_contents( $root . '/src/Support/LegacyRename.php' );

// A `LIKE` sweep over the old prefix would also rename another plugin's option that happens
// to share it. The 15 keys are listed explicitly for that reason.
ok( 'options are listed, not pattern-matched', false === strpos( $legacy_code, 'LIKE ' . chr( 39 ) . Legacy::OPTION_PREFIX ) );

/*
 * An explicit list is safer than a sweep, but it can go stale — and if it does, the option
 * added later is the one that silently fails to migrate. So the migration's list is compared
 * against `uninstall.php`, which is the other place that has to know every option this plugin
 * owns. If a future option is added to one and not the other, this fails.
 */
/*
 * Line endings normalised first. `uninstall.php` is CRLF, and with `/m` the `$` anchor sits
 * *after* the `\r`, so the un-normalised pattern matched nothing — and `array_diff` against an
 * empty list would have passed vacuously. A comparison that cannot fail is not a comparison.
 */
$lf = static fn( string $text ): string => str_replace( "\r\n", "\n", $text );

preg_match_all( "/^\t'ifs_deploy_([a-z_]+)',$/m", $lf( $uninstall_src ), $u );
preg_match_all( "/^\t\t'([a-z_]+)',$/m", $lf( $legacy_src ), $l );

// The marker itself is excluded: it is written BY the migration, so there is no old-named row
// of it to move.
$uninstalled = array_diff( $u[1], array( 'legacy_migrated' ) );
$migrated    = $l[1];

sort( $uninstalled );
sort( $migrated );

ok( 'the migration covers every option uninstall knows about', array_values( $uninstalled ) === array_values( $migrated ) )
	or printf( "        migration: %s\n        uninstall: %s\n", implode( ',', $migrated ), implode( ',', $uninstalled ) );
// `_` is a LIKE wildcard, so an unescaped old-prefix pattern would also match a similarly
// named key belonging to someone else.
ok( 'the meta pattern is LIKE-escaped', false !== strpos( $legacy_code, 'esc_like( $old )' ) );

echo "=== three literals that must NOT be renamed ===\n";

/*
 * These read like leftovers from the old plugin name. They are stored data format.
 *
 * Each one is already baked into data on disk — inside signatures, inside snapshots — so
 * changing the string does not rename anything, it invalidates what is stored. The failures
 * are quiet and expensive, which is exactly why they get assertions rather than only comments.
 */
/*
 * Asserted on `Support\Legacy` now, and on its CONSUMERS referencing the constant rather than
 * writing the literal again. That is a stronger guard than checking for the string in five
 * files: it also catches someone "helpfully" inlining the value back, which is how the two
 * copies of the absence sentinel came to exist in the first place.
 */
$legacy_class = (string) file_get_contents( $root . '/src/Support/Legacy.php' );

ok( 'Legacy holds the signature URL token', false !== strpos( $legacy_class, "SIGNATURE_URL_TOKEN = '__deploypress_site_url__'" ) );
ok( 'Legacy holds the absence sentinel', false !== strpos( $legacy_class, "ABSENT_SENTINEL = '__deploypress_absent__'" ) );
ok( 'Legacy holds the meta prefix', false !== strpos( $legacy_class, "META_PREFIX = '_deploypress_'" ) );
ok( 'Legacy holds the option prefix', false !== strpos( $legacy_class, "OPTION_PREFIX = 'deploypress_'" ) );
ok( 'Legacy holds the legacy db-version option', false !== strpos( $legacy_class, "DB_VERSION_OPTION = 'deploypress_db_version'" ) );
ok( 'Legacy holds the legacy cron hook', false !== strpos( $legacy_class, "CRON_HOOK = 'deploypress_purge_logs'" ) );
ok( 'and it explains why none of them may change', false !== stripos( $legacy_class, 'DO NOT' ) );

// Every consumer takes the value from the constant. A literal here is the drift this prevents.
$consumers = array(
	'src/Support/ContentSignature.php' => 'Legacy::SIGNATURE_URL_TOKEN',
	'src/Export/OptionExporter.php'    => 'Legacy::ABSENT_SENTINEL',
	'src/Rollback/SnapshotStore.php'   => 'Legacy::ABSENT_SENTINEL',
	'src/Support/MetaBlocklist.php'    => 'Legacy::META_PREFIX',
	'src/Support/LegacyRename.php'     => 'Legacy::OPTION_PREFIX',
	'uninstall.php'                    => 'Legacy::CRON_HOOK',
);

foreach ( $consumers as $file => $constant ) {
	$code = php_strip_whitespace( $root . '/' . $file );

	ok( basename( $file ) . " uses $constant", false !== strpos( $code, $constant ) );
	// The old spelling must appear NOWHERE in executing code outside Legacy itself.
	ok( basename( $file ) . ' writes no literal of its own', false === strpos( $code, 'deploypress' ) );
}

$signature_src = (string) file_get_contents( $root . '/src/Support/ContentSignature.php' );

/*
 * The URL token is substituted into content BEFORE hashing, so it is part of every signature
 * ever written: the `hash` column of every queue row and the `_ifs_deploy_src_sig` stamp on
 * every deployed object. Change it and unchanged content hashes differently — on a 2000-object
 * site, 2000 items reporting a difference that does not exist, and QueueVerifier unable to
 * clear a single row that really is in sync.
 */

$exporter_src = (string) file_get_contents( $root . '/src/Export/OptionExporter.php' );
$snapshot_src = (string) file_get_contents( $root . '/src/Rollback/SnapshotStore.php' );

/*
 * The absence sentinel distinguishes "this option did not exist" from "it existed and was
 * empty", and it is written INTO rollback snapshots. Renaming it means restoring an older
 * snapshot no longer recognises the marker — so instead of deleting an option that was absent,
 * it writes the sentinel string in as the value.
 */
// Both sides must use the SAME string, or a captured absence never round-trips.
ok( 'neither was renamed', false === strpos( $exporter_src . $snapshot_src, '__ifs_deploy_absent__' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
