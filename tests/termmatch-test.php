<?php
/**
 * Term identity across sites.
 *
 * The reported bug: renaming a category on Staging ("Uncategorized" → "Others") created a
 * SECOND category on Production instead of renaming the existing one. Matching was by slug
 * alone, a rename changed the slug, the lookup missed, and the only other branch was
 * insert.
 *
 * These tests drive `TermImporter` against a fake term store so each matching step can be
 * isolated — including the exact reported scenario, where BOTH name and slug changed.
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
 * A minimal term store
 * -------------------------------------------------------------------------- */

class WP_Term {
	public int $term_id = 0;
	public string $name = '';
	public string $slug = '';
	public string $taxonomy = '';
	public int $parent = 0;

	public function __construct( array $data ) {
		foreach ( $data as $key => $value ) {
			if ( property_exists( $this, $key ) ) {
				$this->$key = $value;
			}
		}
	}
}

class WP_Error {
	private string $code;
	private string $message;

	public function __construct( string $code = '', string $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code(): string {
		return $this->code;
	}
	public function get_error_message(): string {
		return $this->message;
	}
}

/** term_id => WP_Term */
$GLOBALS['dp_terms']     = array();
$GLOBALS['dp_term_meta'] = array();
$GLOBALS['dp_next_id']   = 100;
$GLOBALS['dp_inserted']  = array();
$GLOBALS['dp_updated']   = array();

function dp_seed_term( int $id, string $name, string $slug, string $taxonomy = 'category', int $parent = 0 ): void {
	$GLOBALS['dp_terms'][ $id ] = new WP_Term(
		array(
			'term_id'  => $id,
			'name'     => $name,
			'slug'     => $slug,
			'taxonomy' => $taxonomy,
			'parent'   => $parent,
		)
	);
}

function dp_reset(): void {
	$GLOBALS['dp_terms']     = array();
	$GLOBALS['dp_term_meta'] = array();
	$GLOBALS['dp_inserted']  = array();
	$GLOBALS['dp_updated']   = array();
	$GLOBALS['dp_next_id']   = 100;
}

function taxonomy_exists( string $t ): bool {
	return in_array( $t, array( 'category', 'post_tag' ), true );
}
function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}
function __( string $t, string $d = '' ): string {
	return $t;
}
function apply_filters( string $hook, $value, ...$args ) {
	return isset( $GLOBALS['dp_filters'][ $hook ] ) ? $GLOBALS['dp_filters'][ $hook ] : $value;
}
function wp_slash( $v ) {
	return $v;
}
function is_serialized( $data, $strict = true ): bool {
	return is_string( $data ) && (bool) preg_match( '/^[aOsbdi]:/', trim( $data ) );
}
function current_time( string $type = 'mysql', $gmt = 0 ) {
	return 'timestamp' === $type ? time() : gmdate( 'Y-m-d H:i:s' );
}
function get_option( string $n, $default = false ) {
	return $GLOBALS['dp_options'][ $n ] ?? $default;
}
function update_option( string $n, $v, $a = null ): bool {
	$GLOBALS['dp_options'][ $n ] = $v;

	return true;
}
function wp_json_encode( $d, int $f = 0 ) {
	return json_encode( $d, $f );
}

function get_term( $id, string $taxonomy = '' ) {
	$term = $GLOBALS['dp_terms'][ (int) $id ] ?? null;

	if ( null === $term ) {
		return null;
	}

	return ( '' === $taxonomy || $term->taxonomy === $taxonomy ) ? $term : null;
}

function get_term_by( string $field, $value, string $taxonomy = '' ) {
	foreach ( $GLOBALS['dp_terms'] as $term ) {
		if ( '' !== $taxonomy && $term->taxonomy !== $taxonomy ) {
			continue;
		}

		if ( 'slug' === $field && $term->slug === $value ) {
			return $term;
		}

		if ( 'name' === $field && $term->name === $value ) {
			return $term;
		}
	}

	return false;
}

/** Supports only what match_by_origin() asks for: taxonomy + an AND meta_query. */
function get_terms( array $args ) {
	$taxonomy = (string) ( $args['taxonomy'] ?? '' );
	$query    = (array) ( $args['meta_query'] ?? array() );
	$clauses  = array();

	foreach ( $query as $key => $clause ) {
		if ( is_array( $clause ) && isset( $clause['key'] ) ) {
			$clauses[ (string) $clause['key'] ] = (string) $clause['value'];
		}
	}

	$out = array();
	foreach ( $GLOBALS['dp_terms'] as $term ) {
		if ( $term->taxonomy !== $taxonomy ) {
			continue;
		}

		$match = true;
		foreach ( $clauses as $meta_key => $meta_value ) {
			if ( (string) get_term_meta( $term->term_id, $meta_key, true ) !== $meta_value ) {
				$match = false;
				break;
			}
		}

		if ( $match ) {
			$out[] = $term;
		}
	}

	return $out;
}

function get_term_meta( int $id, string $key = '', bool $single = false ) {
	$all = $GLOBALS['dp_term_meta'][ $id ] ?? array();

	if ( '' === $key ) {
		return $all;
	}

	$values = $all[ $key ] ?? array();

	return $single ? ( $values[0] ?? '' ) : $values;
}
function update_term_meta( int $id, string $key, $value ): bool {
	$GLOBALS['dp_term_meta'][ $id ][ $key ] = array( $value );

	return true;
}
function add_term_meta( int $id, string $key, $value ): bool {
	$GLOBALS['dp_term_meta'][ $id ][ $key ][] = $value;

	return true;
}
function delete_term_meta( int $id, string $key ): bool {
	unset( $GLOBALS['dp_term_meta'][ $id ][ $key ] );

	return true;
}

function wp_insert_term( string $name, string $taxonomy, array $args = array() ) {
	$id = ++$GLOBALS['dp_next_id'];

	dp_seed_term( $id, $name, (string) ( $args['slug'] ?? $name ), $taxonomy, (int) ( $args['parent'] ?? 0 ) );
	$GLOBALS['dp_inserted'][] = array( 'id' => $id, 'name' => $name, 'slug' => $args['slug'] ?? '' );

	return array( 'term_id' => $id );
}

function wp_update_term( int $id, string $taxonomy, array $args = array() ) {
	if ( ! isset( $GLOBALS['dp_terms'][ $id ] ) ) {
		return new WP_Error( 'invalid_term', 'no such term' );
	}

	$term = $GLOBALS['dp_terms'][ $id ];

	foreach ( array( 'name', 'slug' ) as $key ) {
		if ( isset( $args[ $key ] ) ) {
			$term->$key = (string) $args[ $key ];
		}
	}
	if ( isset( $args['parent'] ) ) {
		$term->parent = (int) $args['parent'];
	}

	$GLOBALS['dp_updated'][] = array( 'id' => $id, 'name' => $term->name, 'slug' => $term->slug );

	return array( 'term_id' => $id );
}

function wp_delete_term( int $id, string $taxonomy ): bool {
	unset( $GLOBALS['dp_terms'][ $id ] );

	return true;
}

spl_autoload_register(
	static function ( string $class ) use ( $root ): void {
		if ( 0 !== strpos( $class, 'IfsDeploy\\' ) ) {
			return;
		}

		$path = $root . '/src/' . str_replace( '\\', '/', substr( $class, strlen( 'IfsDeploy\\' ) ) ) . '.php';

		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

use IfsDeploy\Import\TermImporter;

/** Build a term package as TermExporter would. */
function dp_package( int $origin_id, string $name, string $slug, array $extra = array() ): array {
	return array_merge(
		array(
			'format'      => 1,
			'type'        => 'term',
			'subtype'     => 'category',
			'action'      => 'update',
			'origin_id'   => $origin_id,
			'origin_site' => 'staging-site-uuid',
			'term'        => array(
				'name'             => $name,
				'slug'             => $slug,
				'description'      => '',
				'parent_slug'      => '',
				'parent_origin_id' => 0,
			),
			'meta'        => array(),
		),
		$extra
	);
}

$importer = new TermImporter();

/* -----------------------------------------------------------------------------
 * The reported bug
 * -------------------------------------------------------------------------- */

echo "=== THE BUG: rename changing both name AND slug ===\n";

dp_reset();
// Production has the WordPress default, as a clone of Staging would. No origin stamp,
// because it predates this fix — which is exactly the state a real site upgrades from.
dp_seed_term( 1, 'Uncategorized', 'uncategorized' );

// Staging term 1 renamed to "Others"; WordPress regenerated the slug too.
$result = $importer->import( dp_package( 1, 'Others', 'others' ) );

ok( 'the import succeeded', is_array( $result ) );
ok( 'NO new term was created', array() === $GLOBALS['dp_inserted'] );
ok( 'the existing term was updated', 1 === count( $GLOBALS['dp_updated'] ) );
ok( 'it is the same term id', 1 === (int) ( $result['object_id'] ?? 0 ) );
ok( 'created is reported false', false === ( $result['created'] ?? null ) );
ok( 'the name changed to Others', 'Others' === $GLOBALS['dp_terms'][1]->name );
ok( 'the slug changed to others', 'others' === $GLOBALS['dp_terms'][1]->slug );
ok( 'only one category exists', 1 === count( $GLOBALS['dp_terms'] ) );

// And the stamp is now written, so every later deploy is an exact match rather than a
// heuristic — this is what makes the fix self-healing on existing installs.
ok( 'the origin id was stamped', 1 === (int) get_term_meta( 1, TermImporter::ORIGIN_ID_META, true ) );
ok( 'the origin site was stamped', 'staging-site-uuid' === get_term_meta( 1, TermImporter::ORIGIN_SITE_META, true ) );

echo "=== a second rename now matches by stamp, not by heuristic ===\n";

// Nothing about the term matches the package any more — different name, different slug,
// and even a different id would be fine. Only the stamp connects them.
$GLOBALS['dp_updated'] = array();
$result                = $importer->import( dp_package( 1, 'News', 'news' ) );

ok( 'still no new term', array() === $GLOBALS['dp_inserted'] );
ok( 'the same term was updated again', 1 === (int) ( $result['object_id'] ?? 0 ) );
ok( 'the name is now News', 'News' === $GLOBALS['dp_terms'][1]->name );

// Prove it was the STAMP that matched: move the term to an id the package does not name,
// and give it a name and slug that do not match either.
dp_reset();
dp_seed_term( 77, 'Totally Different', 'totally-different' );
update_term_meta( 77, TermImporter::ORIGIN_ID_META, 5 );
update_term_meta( 77, TermImporter::ORIGIN_SITE_META, 'staging-site-uuid' );

$result = $importer->import( dp_package( 5, 'Renamed Again', 'renamed-again' ) );

ok( 'the stamp matched across a different id', 77 === (int) ( $result['object_id'] ?? 0 ) );
ok( 'and nothing was inserted', array() === $GLOBALS['dp_inserted'] );

/* -----------------------------------------------------------------------------
 * The other matching steps
 * -------------------------------------------------------------------------- */

echo "=== slug matching still works (the common case) ===\n";

dp_reset();
dp_seed_term( 3, 'Old Name', 'shared-slug' );

$result = $importer->import( dp_package( 3, 'New Name', 'shared-slug' ) );

ok( 'matched by slug', 3 === (int) ( $result['object_id'] ?? 0 ) );
ok( 'nothing inserted', array() === $GLOBALS['dp_inserted'] );
ok( 'the name was updated', 'New Name' === $GLOBALS['dp_terms'][3]->name );

echo "=== name matching catches a slug-only edit ===\n";

dp_reset();
// Slug edited on Staging, name untouched, and the ids differ so id-matching cannot help.
dp_seed_term( 9, 'Travel', 'travel' );

$result = $importer->import( dp_package( 42, 'Travel', 'travel-guides' ) );

ok( 'matched by name', 9 === (int) ( $result['object_id'] ?? 0 ) );
ok( 'nothing inserted', array() === $GLOBALS['dp_inserted'] );
ok( 'the slug was updated', 'travel-guides' === $GLOBALS['dp_terms'][9]->slug );

echo "=== a genuinely new term is still created ===\n";

dp_reset();
dp_seed_term( 1, 'Uncategorized', 'uncategorized' );

$result = $importer->import( dp_package( 55, 'Brand New', 'brand-new' ) );

// Nothing matched: no stamp, no slug, no name, and id 55 does not exist. Insert is correct
// here — the fix must not turn every new term into an accidental overwrite.
ok( 'a new term was inserted', 1 === count( $GLOBALS['dp_inserted'] ) );
ok( 'created is reported true', true === ( $result['created'] ?? null ) );
ok( 'the original term is untouched', 'Uncategorized' === $GLOBALS['dp_terms'][1]->name );
ok( 'the new term is stamped too', 55 === (int) get_term_meta( (int) $result['object_id'], TermImporter::ORIGIN_ID_META, true ) );

/* -----------------------------------------------------------------------------
 * The guard on id matching
 * -------------------------------------------------------------------------- */

echo "=== id matching refuses a term claimed by another origin ===\n";

dp_reset();
// Production term 7 already belongs to Staging term 999. Package for Staging term 7 must
// NOT claim it — that is the overwrite-unrelated-content failure mode.
dp_seed_term( 7, 'Belongs To Someone Else', 'someone-else' );
update_term_meta( 7, TermImporter::ORIGIN_ID_META, 999 );
update_term_meta( 7, TermImporter::ORIGIN_SITE_META, 'staging-site-uuid' );

$result = $importer->import( dp_package( 7, 'Trying To Take Over', 'takeover' ) );

ok( 'the claimed term was NOT hijacked', 'Belongs To Someone Else' === $GLOBALS['dp_terms'][7]->name );
ok( 'a separate term was created instead', 1 === count( $GLOBALS['dp_inserted'] ) );

echo "=== id matching can be switched off ===\n";

dp_reset();
dp_seed_term( 1, 'Uncategorized', 'uncategorized' );

// For two sites that are NOT clones, term ids are unrelated and the heuristic is wrong.
$GLOBALS['dp_filters']['ifs_deploy_term_match_by_id'] = false;
$result = $importer->import( dp_package( 1, 'Others', 'others' ) );

ok( 'with the filter off, nothing is hijacked', 'Uncategorized' === $GLOBALS['dp_terms'][1]->name );
ok( 'a new term is created instead', 1 === count( $GLOBALS['dp_inserted'] ) );

unset( $GLOBALS['dp_filters']['ifs_deploy_term_match_by_id'] );

echo "=== a different taxonomy is never matched ===\n";

dp_reset();
dp_seed_term( 1, 'Uncategorized', 'uncategorized', 'post_tag' );

$result = $importer->import( dp_package( 1, 'Others', 'others' ) );

ok( 'the post_tag term is untouched', 'Uncategorized' === $GLOBALS['dp_terms'][1]->name );
ok( 'a category was created', 1 === count( $GLOBALS['dp_inserted'] ) );

/* -----------------------------------------------------------------------------
 * Parents and deletes
 * -------------------------------------------------------------------------- */

echo "=== a renamed PARENT no longer orphans its children ===\n";

dp_reset();
// Production parent, stamped from an earlier deploy, but since renamed on Staging — so its
// slug in the package no longer matches anything here.
dp_seed_term( 10, 'Old Parent', 'old-parent' );
update_term_meta( 10, TermImporter::ORIGIN_ID_META, 10 );
update_term_meta( 10, TermImporter::ORIGIN_SITE_META, 'staging-site-uuid' );

$package = dp_package( 20, 'Child', 'child' );
$package['term']['parent_slug']      = 'renamed-parent';
$package['term']['parent_origin_id'] = 10;

$result = $importer->import( $package );
$child  = $GLOBALS['dp_terms'][ (int) $result['object_id'] ];

// Without parent-by-origin the lookup returns 0 and the child is silently promoted to the
// top level — a quiet hierarchy change nobody would notice until the menu broke.
ok( 'the parent resolved by origin', 10 === $child->parent );

echo "=== delete finds a renamed term ===\n";

dp_reset();
dp_seed_term( 30, 'Renamed On Production', 'renamed-on-production' );
update_term_meta( 30, TermImporter::ORIGIN_ID_META, 30 );
update_term_meta( 30, TermImporter::ORIGIN_SITE_META, 'staging-site-uuid' );

$package           = dp_package( 30, 'Whatever It Was Called', 'whatever' );
$package['action'] = 'delete';

$result = $importer->import( $package );

ok( 'the term was deleted by origin', 30 === (int) ( $result['object_id'] ?? 0 ) );
ok( 'and is gone', ! isset( $GLOBALS['dp_terms'][30] ) );

/* -----------------------------------------------------------------------------
 * Contract
 * -------------------------------------------------------------------------- */

echo "=== the exporter carries what the importer needs ===\n";

$exporter = (string) file_get_contents( $root . '/src/Export/TermExporter.php' );

ok( 'origin_id is exported', false !== strpos( $exporter, "'origin_id'   => \$term_id" ) );
ok( 'origin_site is exported', false !== strpos( $exporter, "'origin_site' =>" ) );
ok( 'parent_origin_id is exported', false !== strpos( $exporter, "'parent_origin_id'" ) );

// The stamps are `_ifs_deploy_` prefixed so neither side treats them as content — the
// exporter skips them, and apply_meta() does not delete them.
$importer_src = (string) file_get_contents( $root . '/src/Import/TermImporter.php' );

ok( 'the stamp keys are internally prefixed', false !== strpos( $importer_src, "'_ifs_deploy_origin_term_id'" ) );
ok( 'the exporter skips internal meta', false !== strpos( $exporter, "0 === strpos( \$key, '_ifs_deploy_' )" ) );
ok( 'apply_meta skips internal meta', false !== strpos( $importer_src, "0 === strpos( \$key, '_ifs_deploy_' )" ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
