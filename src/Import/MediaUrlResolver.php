<?php
declare(strict_types=1);

namespace IfsDeploy\Import;

use IfsDeploy\Support\UrlRewriter;

/**
 * Maps Staging media URLs onto the file Production ACTUALLY stored.
 *
 * Why this exists: wp_upload_bits() will not overwrite an existing file. If
 * Production already holds an unrelated `sample.png`, the incoming one is saved as
 * `sample-1.png`. Rewriting only the domain then leaves post content pointing at
 * `…/uploads/2026/08/sample.png` on Production — a file that either does not exist
 * or, worse, is somebody else's image. The upload directory can differ too, since
 * the year/month folder is chosen at upload time.
 *
 * The mapping is recovered from `_ifs_deploy_source_url`, which MediaImporter
 * stamps on every attachment it creates: given a Staging URL, Production can find
 * its own attachment and ask WordPress for the real URL. That works for media
 * imported in this batch and in any earlier deploy.
 *
 * Sized variants are handled by splitting off the `-WxH` suffix, resolving the
 * original, and re-applying the suffix to Production's filename — so
 * `sample-300x200.png` becomes `sample-1-300x200.png`.
 */
final class MediaUrlResolver {

	/** File extensions treated as uploaded media. */
	private const EXTENSIONS = 'jpg|jpeg|jpe|png|gif|webp|avif|bmp|tif|tiff|svg|ico|pdf|doc|docx|xls|xlsx|ppt|pptx|zip|mp4|m4v|mov|webm|ogv|mp3|m4a|wav|ogg';

	/** @var array<string,int> source url => attachment id (0 records a miss) */
	private array $cache = array();

	/**
	 * Build the replacement map for every Staging media URL found in the given
	 * strings whose Production counterpart has a different URL.
	 *
	 * Entries are returned for both the plain and escaped-slash forms, since block
	 * markup stores `https:\/\/host\/…`.
	 *
	 * @param string[] $haystacks Strings to scan (content, excerpt, meta values).
	 *
	 * @return array<string,string> staging url => production url
	 */
	public function build_map( array $haystacks, string $origin_url ): array {
		if ( '' === $origin_url || empty( $haystacks ) ) {
			return array();
		}

		$found = $this->find_candidates( $haystacks, $origin_url );
		if ( empty( $found ) ) {
			return array();
		}

		$map = array();

		foreach ( $found as $staging_url ) {
			$production_url = $this->resolve( $staging_url );
			if ( '' === $production_url ) {
				continue;
			}

			// Where a plain domain swap already lands on the right file, leave it to
			// the ordinary rewrite and keep the map small.
			if ( $production_url === UrlRewriter::rewrite( $staging_url, $origin_url, home_url() ) ) {
				continue;
			}

			$map[ $staging_url ] = $production_url;

			$escaped_from = str_replace( '/', '\/', $staging_url );
			if ( $escaped_from !== $staging_url ) {
				$map[ $escaped_from ] = str_replace( '/', '\/', $production_url );
			}
		}

		return $map;
	}

	/**
	 * Apply a map to a single string.
	 *
	 * @param array<string,string> $map
	 */
	public function apply( string $content, array $map ): string {
		if ( empty( $map ) ) {
			return $content;
		}

		return str_replace( array_keys( $map ), array_values( $map ), $content );
	}

	/**
	 * Apply a map recursively through nested arrays (ACF repeaters, galleries).
	 * Values must already be unserialized.
	 *
	 * @param mixed                $value
	 * @param array<string,string> $map
	 *
	 * @return mixed
	 */
	public function apply_deep( $value, array $map ) {
		if ( empty( $map ) ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			return $this->apply( $value, $map );
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = $this->apply_deep( $item, $map );
			}
		}

		return $value;
	}

	/**
	 * Absolute Staging upload URLs appearing in the given strings.
	 *
	 * Discovery runs against an unescaped copy so one pattern covers both plain and
	 * block-markup forms; the escaped variants are re-derived when the map is built.
	 *
	 * @param string[] $haystacks
	 *
	 * @return string[] Unique URLs, longest first.
	 */
	private function find_candidates( array $haystacks, string $origin_url ): array {
		$plain = str_replace( '\/', '/', implode( "\n", $haystacks ) );
		if ( '' === trim( $plain ) ) {
			return array();
		}

		// Plain-form origins only — the haystack has already been unescaped.
		$origins = array_filter(
			UrlRewriter::variants( $origin_url ),
			static fn( string $variant ): bool => false === strpos( $variant, '\/' )
		);

		if ( empty( $origins ) ) {
			return array();
		}

		$alternatives = implode( '|', array_map( static fn( string $o ): string => preg_quote( $o, '#' ), $origins ) );
		$pattern      = '#(?:' . $alternatives . ')/[^\s"\'<>()\\\\]+?\.(?:' . self::EXTENSIONS . ')#i';

		if ( ! preg_match_all( $pattern, $plain, $matches ) ) {
			return array();
		}

		$urls = array_unique( $matches[0] );

		// Longest first: a sized URL contains its own original as a prefix, and
		// replacing the shorter string first would corrupt the longer one.
		usort( $urls, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );

		return $urls;
	}

	/**
	 * Production's URL for a Staging media URL, or '' when it is unknown here.
	 */
	private function resolve( string $staging_url ): string {
		list( $stem, $size, $extension ) = $this->split( $staging_url );

		foreach ( $this->lookup_candidates( $stem, $size, $extension ) as $candidate ) {
			$attachment_id = $this->find_by_source( $candidate );
			if ( ! $attachment_id ) {
				continue;
			}

			$production_url = (string) wp_get_attachment_url( $attachment_id );
			if ( '' === $production_url ) {
				return '';
			}

			return '' !== $size ? $this->with_size( $production_url, $size ) : $production_url;
		}

		return '';
	}

	/**
	 * The URLs that might have been recorded as this file's source, most likely
	 * first. `_ifs_deploy_source_url` holds whatever wp_get_attachment_url()
	 * returned on Staging, which is the -scaled file for large images — hence the
	 * scaled variants.
	 *
	 * @return string[]
	 */
	private function lookup_candidates( string $stem, string $size, string $extension ): array {
		$unscaled = (string) preg_replace( '#-scaled$#', '', $stem );

		$candidates = array(
			$stem . $extension,                 // original, any size suffix removed
			$stem . $size . $extension,         // exactly as written in the content
			$unscaled . '-scaled' . $extension, // Staging stored the scaled file
			$unscaled . $extension,             // content referenced the scaled file
		);

		return array_values( array_unique( array_filter( $candidates ) ) );
	}

	/**
	 * Split a URL into [stem, size suffix, extension].
	 *
	 * @return array{0:string,1:string,2:string}
	 */
	private function split( string $url ): array {
		if ( preg_match( '#^(.*)(-\d+x\d+)(\.[A-Za-z0-9]{1,5})$#', $url, $matches ) ) {
			return array( $matches[1], $matches[2], $matches[3] );
		}

		if ( preg_match( '#^(.*)(\.[A-Za-z0-9]{1,5})$#', $url, $matches ) ) {
			return array( $matches[1], '', $matches[2] );
		}

		return array( $url, '', '' );
	}

	/**
	 * Re-apply a `-WxH` suffix to a resolved production URL.
	 */
	private function with_size( string $url, string $size ): string {
		if ( ! preg_match( '#^(.*)(\.[A-Za-z0-9]{1,5})$#', $url, $matches ) ) {
			return $url;
		}

		return $matches[1] . $size . $matches[2];
	}

	/**
	 * Attachment stamped with this source URL, or 0. Cached: one post can reference
	 * the same image many times over.
	 */
	private function find_by_source( string $source_url ): int {
		if ( array_key_exists( $source_url, $this->cache ) ) {
			return $this->cache[ $source_url ];
		}

		$query = new \WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array(
						'key'   => MediaImporter::SOURCE_URL_META,
						'value' => $source_url,
					),
				),
			)
		);

		$this->cache[ $source_url ] = $query->posts ? (int) $query->posts[0] : 0;

		return $this->cache[ $source_url ];
	}
}
