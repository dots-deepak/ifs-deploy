<?php
declare(strict_types=1);

namespace IfsDeploy\Import;

use WP_Error;
use IfsDeploy\Support\DebugLog;
use IfsDeploy\Support\SafeData;

/**
 * Applies a term package to Production via WordPress term APIs only.
 *
 * ── WHY MATCHING IS NOT JUST "BY SLUG" ─────────────────────────────────────────
 *
 * It used to be. That produced a reported bug: renaming a category on Staging —
 * "Uncategorized" → "Others" — created a SECOND category on Production instead of
 * renaming the existing one. Renaming a term can change its slug, `get_term_by( 'slug' )`
 * then finds nothing, and the importer takes the only other branch it had: insert.
 *
 * So the term now carries its origin, exactly as posts do, and matching walks four steps
 * from most to least reliable. Whichever one hits, the origin is stamped afterwards — so a
 * term deployed by an older build heals itself on its next deploy and is matched exactly
 * from then on.
 */
final class TermImporter {

	/**
	 * Origin stamps, mirroring PostImporter's post-meta equivalents.
	 *
	 * Prefixed `_ifs_deploy_` so `apply_meta()` and `TermExporter::meta()` both skip them
	 * — the stamp is ours, and must not be shipped back and forth as if it were content.
	 */
	public const ORIGIN_ID_META   = '_ifs_deploy_origin_term_id';
	public const ORIGIN_SITE_META = '_ifs_deploy_origin_site';

	/**
	 * @return array{object_id:int,created:bool}|WP_Error
	 */
	public function import( array $package ) {
		$taxonomy = (string) ( $package['subtype'] ?? '' );
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error( 'ifs_deploy_bad_taxonomy', __( 'Unknown taxonomy on Production.', 'ifs-deploy' ) );
		}

		$term = (array) ( $package['term'] ?? array() );
		$slug = (string) ( $term['slug'] ?? '' );
		$name = (string) ( $term['name'] ?? $slug );
		if ( '' === $slug ) {
			return new WP_Error( 'ifs_deploy_bad_term', __( 'Malformed term package.', 'ifs-deploy' ) );
		}

		$origin_id   = (int) ( $package['origin_id'] ?? 0 );
		$origin_site = (string) ( $package['origin_site'] ?? '' );

		if ( 'delete' === ( $package['action'] ?? 'update' ) ) {
			return $this->delete( $taxonomy, $slug, $name, $origin_id, $origin_site );
		}

		$args = array(
			'slug'        => $slug,
			'description' => (string) ( $term['description'] ?? '' ),
			'parent'      => $this->resolve_parent(
				$taxonomy,
				(string) ( $term['parent_slug'] ?? '' ),
				(int) ( $term['parent_origin_id'] ?? 0 ),
				$origin_site
			),
		);

		$existing = $this->find_existing( $taxonomy, $slug, $name, $origin_id, $origin_site );
		$created  = false;

		if ( $existing instanceof \WP_Term ) {
			// `name` is passed on UPDATE as well as insert — that is the whole point of the
			// fix. A rename must land on the existing term.
			$result = wp_update_term( $existing->term_id, $taxonomy, array_merge( $args, array( 'name' => $name ) ) );
		} else {
			$result  = wp_insert_term( $name, $taxonomy, $args );
			$created = true;
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$term_id = (int) $result['term_id'];

		$this->apply_meta( $term_id, (array) ( $package['meta'] ?? array() ) );
		$this->stamp_origin( $term_id, $origin_id, $origin_site );

		return array(
			'object_id' => $term_id,
			'created'   => $created,
		);
	}

	/**
	 * Find the Production term this package refers to.
	 *
	 * Ordered most to least reliable, and the order matters:
	 *
	 *  1. **Origin stamp** — exact. Survives a change of name, slug, description and
	 *     parent all at once. Every import writes it, so this is the path taken from the
	 *     second deploy of a term onwards.
	 *  2. **Slug** — unique per taxonomy, so a hit here is unambiguous. This is what the
	 *     importer used to do, and it still covers the common case.
	 *  3. **Name** — catches a slug edit where the name stayed the same.
	 *  4. **Same term id** — the last resort, and the one that fixes the reported bug: a
	 *     rename that changed BOTH name and slug leaves nothing else to match on. Guarded
	 *     (see `match_by_id()`) and logged, because it is a heuristic rather than a fact.
	 */
	private function find_existing( string $taxonomy, string $slug, string $name, int $origin_id, string $origin_site ) {
		$by_origin = $this->match_by_origin( $taxonomy, $origin_id, $origin_site );
		if ( $by_origin instanceof \WP_Term ) {
			return $by_origin;
		}

		$by_slug = get_term_by( 'slug', $slug, $taxonomy );
		if ( $by_slug instanceof \WP_Term ) {
			return $by_slug;
		}

		$by_name = get_term_by( 'name', $name, $taxonomy );
		if ( $by_name instanceof \WP_Term ) {
			return $by_name;
		}

		return $this->match_by_id( $taxonomy, $origin_id, $slug, $name );
	}

	/**
	 * The exact match: a Production term already stamped with this origin.
	 */
	private function match_by_origin( string $taxonomy, int $origin_id, string $origin_site ) {
		if ( $origin_id <= 0 || '' === $origin_site ) {
			return null;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 1,
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					'relation' => 'AND',
					array(
						'key'   => self::ORIGIN_ID_META,
						'value' => (string) $origin_id,
					),
					array(
						'key'   => self::ORIGIN_SITE_META,
						'value' => $origin_site,
					),
				),
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return null;
		}

		$term = $terms[0];

		return $term instanceof \WP_Term ? $term : null;
	}

	/**
	 * Same id, same taxonomy — used only when nothing better matched.
	 *
	 * This is a HEURISTIC, and the guard below is what keeps it from being dangerous. It
	 * assumes the two sites are clones of one another, which is the model this plugin is
	 * built around: term 5 on Staging and term 5 on Production are the same category
	 * because one site was copied from the other.
	 *
	 * The guard: refuse the match if the Production term is already stamped for a
	 * DIFFERENT origin. Such a term demonstrably belongs to another object, so claiming it
	 * would overwrite unrelated content — the failure mode that makes id-matching
	 * dangerous in the first place.
	 *
	 * It is also logged, because a rename is the only legitimate reason to reach this far
	 * and an unexpected one is worth seeing. `ifs_deploy_term_match_by_id` disables it
	 * for anyone whose two sites are NOT clones and whose term ids are unrelated.
	 */
	private function match_by_id( string $taxonomy, int $origin_id, string $slug, string $name ) {
		if ( $origin_id <= 0 ) {
			return null;
		}

		/**
		 * Filter whether a term may be matched by raw id as a last resort.
		 *
		 * @param bool   $allowed
		 * @param string $taxonomy
		 * @param int    $origin_id
		 */
		if ( ! (bool) apply_filters( 'ifs_deploy_term_match_by_id', true, $taxonomy, $origin_id ) ) {
			return null;
		}

		$term = get_term( $origin_id, $taxonomy );

		if ( ! $term instanceof \WP_Term ) {
			return null;
		}

		$stamped = (int) get_term_meta( $origin_id, self::ORIGIN_ID_META, true );

		if ( $stamped > 0 && $stamped !== $origin_id ) {
			DebugLog::warning(
				'Refused to match a term by id: it is already linked to a different origin.',
				array(
					'taxonomy'  => $taxonomy,
					'origin_id' => (string) $origin_id,
					'stamped'   => (string) $stamped,
				)
			);

			return null;
		}

		DebugLog::info(
			'Matched a term by id after name and slug both changed.',
			array(
				'taxonomy'   => $taxonomy,
				'term_id'    => (string) $origin_id,
				'was'        => $term->name . ' (' . $term->slug . ')',
				'becomes'    => $name . ' (' . $slug . ')',
			)
		);

		return $term;
	}

	/**
	 * Record which Staging term this Production term came from.
	 *
	 * Written on EVERY import, not just on creation, so a term deployed by an older build
	 * — which has no stamp — gains one the first time it is matched by slug, name or id.
	 * From then on it is matched exactly and none of the heuristics run again.
	 */
	private function stamp_origin( int $term_id, int $origin_id, string $origin_site ): void {
		if ( $origin_id <= 0 || '' === $origin_site ) {
			return;
		}

		update_term_meta( $term_id, self::ORIGIN_ID_META, $origin_id );
		update_term_meta( $term_id, self::ORIGIN_SITE_META, $origin_site );
	}

	private function delete( string $taxonomy, string $slug, string $name = '', int $origin_id = 0, string $origin_site = '' ) {
		// Origin first here too: a term renamed and THEN deleted has neither its old slug
		// nor its old name to match on.
		$existing = $this->match_by_origin( $taxonomy, $origin_id, $origin_site );

		if ( ! ( $existing instanceof \WP_Term ) && '' !== $slug ) {
			$existing = get_term_by( 'slug', $slug, $taxonomy );
		}

		// The slug is gone once the term is deleted on Staging, so fall back to
		// matching by name.
		if ( ! ( $existing instanceof \WP_Term ) && '' !== $name ) {
			$existing = get_term_by( 'name', $name, $taxonomy );
		}

		if ( $existing instanceof \WP_Term ) {
			wp_delete_term( $existing->term_id, $taxonomy );
			return array( 'object_id' => (int) $existing->term_id, 'created' => false );
		}
		return array( 'object_id' => 0, 'created' => false );
	}

	/**
	 * Resolve the parent term, by origin before slug.
	 *
	 * A renamed PARENT breaks hierarchy the same way a renamed child broke identity: the
	 * parent slug in the package no longer exists on Production, `resolve_parent` returns
	 * 0, and the child is silently promoted to the top level.
	 */
	private function resolve_parent( string $taxonomy, string $parent_slug, int $parent_origin_id, string $origin_site ): int {
		$by_origin = $this->match_by_origin( $taxonomy, $parent_origin_id, $origin_site );

		if ( $by_origin instanceof \WP_Term ) {
			return (int) $by_origin->term_id;
		}

		if ( '' === $parent_slug ) {
			return 0;
		}

		$parent = get_term_by( 'slug', $parent_slug, $taxonomy );

		return $parent instanceof \WP_Term ? (int) $parent->term_id : 0;
	}

	private function apply_meta( int $term_id, array $meta ): void {
		foreach ( $meta as $key => $values ) {
			$key = (string) $key;
			if ( 0 === strpos( $key, '_ifs_deploy_' ) ) {
				continue;
			}
			delete_term_meta( $term_id, $key );
			foreach ( (array) $values as $value ) {
				// Slashed on purpose: add_term_meta() unslashes, so raw input would lose
				// every backslash in the value.
				add_term_meta( $term_id, $key, wp_slash( SafeData::decode( $value ) ) );
			}
		}
	}
}
