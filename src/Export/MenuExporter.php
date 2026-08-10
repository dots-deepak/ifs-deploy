<?php
declare(strict_types=1);

namespace IfsDeploy\Export;

use IfsDeploy\Auth\Credentials;

/**
 * Exports a whole navigation menu as one unit: the nav_menu term, all its items
 * (with each item's parent + referenced object carried as a stable slug for
 * cross-site resolution), and the theme locations the menu is assigned to.
 */
final class MenuExporter {

	public const PACKAGE_FORMAT = 1;

	/** @return array|null */
	public function export( int $menu_id ): ?array {
		$menu = wp_get_nav_menu_object( $menu_id );
		if ( ! $menu ) {
			return null;
		}

		$items = wp_get_nav_menu_items( $menu->term_id, array( 'update_post_term_cache' => false ) );
		$items = is_array( $items ) ? $items : array();

		$creds = Credentials::get();

		$locations = array();
		foreach ( (array) get_nav_menu_locations() as $location => $term_id ) {
			if ( (int) $term_id === (int) $menu->term_id ) {
				$locations[] = (string) $location;
			}
		}

		return array(
			'format'      => self::PACKAGE_FORMAT,
			'type'        => 'menu',
			'subtype'     => '',
			'action'      => 'update',
			'origin_id'   => (int) $menu->term_id,
			'origin_site' => $creds['site_id'],
			'menu'        => array(
				'name' => $menu->name,
				'slug' => $menu->slug,
			),
			'items'       => array_map( array( $this, 'export_item' ), $items ),
			'locations'   => $locations,
		);
	}

	/**
	 * @param \WP_Post $item A menu item as returned by wp_get_nav_menu_items().
	 */
	private function export_item( \WP_Post $item ): array {
		$ref_slug    = '';
		$ref_subtype = '';

		if ( 'post_type' === $item->type ) {
			$post = get_post( (int) $item->object_id );
			if ( $post instanceof \WP_Post ) {
				$ref_slug    = $post->post_name;
				$ref_subtype = $post->post_type;
			}
		} elseif ( 'taxonomy' === $item->type ) {
			$term = get_term( (int) $item->object_id, (string) $item->object );
			if ( $term instanceof \WP_Term ) {
				$ref_slug    = $term->slug;
				$ref_subtype = (string) $item->object;
			}
		}

		return array(
			'origin_id'   => (int) $item->ID,
			'parent'      => (int) $item->menu_item_parent,
			'title'       => (string) $item->title,
			'type'        => (string) $item->type,
			'object'      => (string) $item->object,
			'object_id'   => (int) $item->object_id,
			'ref_slug'    => $ref_slug,
			'ref_subtype' => $ref_subtype,
			'url'         => (string) $item->url,
			'target'      => (string) $item->target,
			'classes'     => is_array( $item->classes ) ? implode( ' ', $item->classes ) : (string) $item->classes,
			'xfn'         => (string) $item->xfn,
			'description' => (string) $item->description,
			'attr_title'  => (string) $item->attr_title,
			'position'    => (int) $item->menu_order,
		);
	}
}
