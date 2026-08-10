<?php
declare(strict_types=1);

namespace IfsDeploy\Export;

use IfsDeploy\Auth\Credentials;

/**
 * Builds the deployment package for a single taxonomy term.
 *
 * Terms are matched across sites by slug + taxonomy (slugs are unique per
 * taxonomy), so no attachment/ID remapping is needed. Parent is carried as the
 * parent's slug and re-resolved on import.
 */
final class TermExporter {

	public const PACKAGE_FORMAT = 1;

	/** @return array|null */
	public function export( int $term_id, string $taxonomy ): ?array {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return null;
		}

		$creds  = Credentials::get();
		$parent = $term->parent ? get_term( $term->parent, $taxonomy ) : null;

		return array(
			'format'      => self::PACKAGE_FORMAT,
			'type'        => 'term',
			'subtype'     => $taxonomy,
			'action'      => 'update',
			'origin_id'   => $term_id,
			'origin_site' => $creds['site_id'],
			'term'        => array(
				'name'        => $term->name,
				'slug'        => $term->slug,
				'description' => $term->description,
				'parent_slug' => $parent instanceof \WP_Term ? $parent->slug : '',
				// Carried so the importer can resolve the parent by origin rather than by
				// slug. A renamed PARENT used to silently promote its children to the top
				// level, because its slug no longer existed on the other side.
				'parent_origin_id' => $parent instanceof \WP_Term ? (int) $parent->term_id : 0,
			),
			'meta'        => $this->meta( $term_id ),
		);
	}

	/**
	 * @return array<string,array<int,mixed>>
	 */
	private function meta( int $term_id ): array {
		$all = get_term_meta( $term_id );
		if ( ! is_array( $all ) ) {
			return array();
		}

		$out = array();
		foreach ( $all as $key => $values ) {
			$key = (string) $key;
			if ( 0 === strpos( $key, '_ifs_deploy_' ) ) {
				continue;
			}
			$out[ $key ] = array_map( 'maybe_unserialize', (array) $values );
		}

		return $out;
	}
}
