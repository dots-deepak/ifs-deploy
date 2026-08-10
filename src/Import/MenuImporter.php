<?php
declare(strict_types=1);

namespace IfsDeploy\Import;

use WP_Error;

/**
 * Applies a menu package to Production.
 *
 * The menu term is matched by slug (created if missing). Items are rebuilt from
 * scratch each deploy (menu items aren't referenced by anything else), with:
 *   - parents remapped through an origin→new id map built as items are created,
 *   - object references resolved by ID parity first, then by slug fallback,
 *   - theme locations re-assigned to the resulting menu.
 */
final class MenuImporter {

	/**
	 * @return array{object_id:int,created:bool}|WP_Error
	 */
	public function import( array $package ) {
		require_once ABSPATH . 'wp-admin/includes/nav-menu.php';

		$menu = (array) ( $package['menu'] ?? array() );
		$name = (string) ( $menu['name'] ?? '' );
		$slug = (string) ( $menu['slug'] ?? '' );

		if ( '' === $name && '' === $slug ) {
			return new WP_Error( 'ifs_deploy_bad_menu', __( 'Malformed menu package.', 'ifs-deploy' ) );
		}

		if ( 'delete' === ( $package['action'] ?? 'update' ) ) {
			return $this->delete( $name, $slug );
		}

		$existing = $this->find_menu( $name, $slug );
		$created  = false;

		if ( $existing ) {
			$menu_id = (int) $existing->term_id;
		} else {
			$menu_id = wp_create_nav_menu( '' !== $name ? $name : $slug );
			if ( is_wp_error( $menu_id ) ) {
				return $menu_id;
			}
			$menu_id = (int) $menu_id;
			$created = true;
		}

		// Rebuild items for an exact match with Staging.
		$this->clear_items( $menu_id );

		$map = array(); // origin item id => new prod item id.
		foreach ( (array) ( $package['items'] ?? array() ) as $item ) {
			$item    = (array) $item;
			$new_id  = wp_update_nav_menu_item( $menu_id, 0, $this->item_args( $item, $map ) );
			if ( ! is_wp_error( $new_id ) ) {
				$map[ (int) ( $item['origin_id'] ?? 0 ) ] = (int) $new_id;
			}
		}

		$this->assign_locations( $menu_id, (array) ( $package['locations'] ?? array() ) );

		return array( 'object_id' => $menu_id, 'created' => $created );
	}

	/**
	 * @return array{object_id:int,created:bool}
	 */
	private function delete( string $name, string $slug ): array {
		$existing = $this->find_menu( $name, $slug );
		if ( $existing ) {
			wp_delete_nav_menu( $existing->term_id );
			return array( 'object_id' => (int) $existing->term_id, 'created' => false );
		}
		return array( 'object_id' => 0, 'created' => false );
	}

	private function find_menu( string $name, string $slug ) {
		$menu = '' !== $slug ? wp_get_nav_menu_object( $slug ) : false;
		if ( ! $menu && '' !== $name ) {
			$menu = wp_get_nav_menu_object( $name );
		}
		return $menu ?: null;
	}

	private function clear_items( int $menu_id ): void {
		$items = wp_get_nav_menu_items( $menu_id, array( 'update_post_term_cache' => false ) );
		if ( is_array( $items ) ) {
			foreach ( $items as $item ) {
				wp_delete_post( (int) $item->ID, true );
			}
		}
	}

	/**
	 * Build the wp_update_nav_menu_item() args for one item.
	 *
	 * @param array          $item Exported item.
	 * @param array<int,int> $map  origin item id => new prod item id.
	 */
	private function item_args( array $item, array $map ): array {
		$parent_origin = (int) ( $item['parent'] ?? 0 );

		return array(
			'menu-item-title'       => (string) ( $item['title'] ?? '' ),
			'menu-item-status'      => 'publish',
			'menu-item-type'        => (string) ( $item['type'] ?? 'custom' ),
			'menu-item-object'      => (string) ( $item['object'] ?? '' ),
			'menu-item-object-id'   => $this->resolve_object_id( $item ),
			'menu-item-url'         => (string) ( $item['url'] ?? '' ),
			'menu-item-parent-id'   => $map[ $parent_origin ] ?? 0,
			'menu-item-position'    => (int) ( $item['position'] ?? 0 ),
			'menu-item-target'      => (string) ( $item['target'] ?? '' ),
			'menu-item-classes'     => (string) ( $item['classes'] ?? '' ),
			'menu-item-xfn'         => (string) ( $item['xfn'] ?? '' ),
			'menu-item-description' => (string) ( $item['description'] ?? '' ),
			'menu-item-attr-title'  => (string) ( $item['attr_title'] ?? '' ),
		);
	}

	/**
	 * Resolve a menu item's referenced object id on Production: ID parity first,
	 * then slug fallback for posts/terms.
	 */
	private function resolve_object_id( array $item ): int {
		$type      = (string) ( $item['type'] ?? '' );
		$object    = (string) ( $item['object'] ?? '' );
		$object_id = (int) ( $item['object_id'] ?? 0 );
		$ref_slug  = (string) ( $item['ref_slug'] ?? '' );

		if ( 'post_type' === $type ) {
			$post = $object_id ? get_post( $object_id ) : null;
			if ( $post instanceof \WP_Post && $post->post_type === $object ) {
				return $object_id; // ID parity.
			}
			if ( '' !== $ref_slug ) {
				$found = get_page_by_path( $ref_slug, OBJECT, $object );
				if ( $found instanceof \WP_Post ) {
					return (int) $found->ID;
				}
			}
			return 0;
		}

		if ( 'taxonomy' === $type ) {
			if ( '' !== $ref_slug ) {
				$term = get_term_by( 'slug', $ref_slug, $object );
				if ( $term instanceof \WP_Term ) {
					return (int) $term->term_id;
				}
			}
			return $object_id;
		}

		// custom / post_type_archive — no object id needed.
		return 0;
	}

	private function assign_locations( int $menu_id, array $locations ): void {
		if ( empty( $locations ) ) {
			return;
		}

		$current = get_theme_mod( 'nav_menu_locations', array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}

		foreach ( $locations as $location ) {
			$current[ (string) $location ] = $menu_id;
		}

		set_theme_mod( 'nav_menu_locations', $current );
	}
}
