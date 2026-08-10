<?php
declare(strict_types=1);

namespace IfsDeploy\Export;

use IfsDeploy\Auth\Credentials;

/**
 * Builds the deployment package for a media attachment.
 *
 * Carries the source file URL (so Production can download the binary), the
 * attachment's post fields + caption/description/alt, and its parent's origin
 * id. The file itself is fetched on the receiving side; attachment metadata
 * (sizes) is regenerated there rather than transferred.
 */
final class MediaExporter {

	public const PACKAGE_FORMAT = 1;

	/** Meta not transferred — regenerated or path/size specific on the target. */
	private const META_BLOCKLIST = array(
		'_wp_attached_file',
		'_wp_attachment_metadata',
		'_edit_lock',
		'_edit_last',
		'_ifs_deploy_origin_id',
		'_ifs_deploy_origin_site',
		'_ifs_deploy_source_url',
	);

	/** @return array|null */
	public function export( int $attachment_id ): ?array {
		$post = get_post( $attachment_id );
		if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type ) {
			return null;
		}

		$source_url = wp_get_attachment_url( $attachment_id );
		if ( ! $source_url ) {
			return null;
		}

		$creds = Credentials::get();

		return array(
			'format'      => self::PACKAGE_FORMAT,
			'type'        => 'media',
			'subtype'     => $post->post_mime_type,
			'action'      => 'update',
			'origin_id'   => $attachment_id,
			'origin_site' => $creds['site_id'],
			'source_url'  => $source_url,
			'filename'    => basename( (string) get_attached_file( $attachment_id ) ),
			'alt'         => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'attachment'  => array(
				'post_title'     => $post->post_title,
				'post_name'      => $post->post_name,
				'post_excerpt'   => $post->post_excerpt,   // caption.
				'post_content'   => $post->post_content,   // description.
				'post_mime_type' => $post->post_mime_type,
				'post_parent'    => (int) $post->post_parent,
				'menu_order'     => (int) $post->menu_order,
				'post_date'      => $post->post_date,
			),
			'meta'        => $this->meta( $attachment_id ),
		);
	}

	/**
	 * @return array<string,array<int,mixed>>
	 */
	private function meta( int $attachment_id ): array {
		$all = get_post_meta( $attachment_id );
		if ( ! is_array( $all ) ) {
			return array();
		}

		$out = array();
		foreach ( $all as $key => $values ) {
			$key = (string) $key;
			if ( in_array( $key, self::META_BLOCKLIST, true ) || '_wp_attachment_image_alt' === $key ) {
				continue;
			}
			$out[ $key ] = array_map( 'maybe_unserialize', (array) $values );
		}

		return $out;
	}
}
