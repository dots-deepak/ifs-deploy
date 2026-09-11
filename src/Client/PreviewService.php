<?php
declare(strict_types=1);

namespace IfsDeploy\Client;

use IfsDeploy\Auth\Credentials;
use IfsDeploy\Export\MediaExporter;
use IfsDeploy\Export\PostExporter;
use IfsDeploy\Queue\QueueRepository;
use IfsDeploy\Rest\ObjectEndpoint;
use IfsDeploy\Support\Config;
use IfsDeploy\Support\PackageDiff;
use IfsDeploy\Support\UrlRewriter;

/**
 * Staging-side "what will this push change?" preview for a post.
 *
 * Fetches Production's CURRENT state for the object over the signed connection,
 * then diffs it against the package that would be sent. Strictly read-only on
 * both sides — nothing here writes, queues, or deploys.
 *
 * Two entry points, because the two screens identify objects differently:
 *   - preview()      — by queue row id (Pending Changes; also handles deletes).
 *   - preview_post() — by post id      (Compare & Sync, which has no queue row).
 * Both converge on the same fetch + diff, so the two screens can never disagree
 * about what a push would do.
 */
final class PreviewService {

	private QueueRepository $queue;
	private PostExporter $exporter;
	private MediaExporter $media_exporter;
	private DeployClient $client;

	public function __construct(
		?QueueRepository $queue = null,
		?PostExporter $exporter = null,
		?DeployClient $client = null,
		?MediaExporter $media_exporter = null
	) {
		$this->queue          = $queue ?? new QueueRepository();
		$this->exporter       = $exporter ?? new PostExporter();
		$this->client         = $client ?? new DeployClient();
		$this->media_exporter = $media_exporter ?? new MediaExporter();
	}

	/**
	 * Preview a queued change (Pending Changes screen).
	 *
	 * @return array See preview_post() for the shape.
	 */
	public function preview( int $queue_id ): array {
		$item = $this->queue->get( $queue_id );
		if ( null === $item ) {
			return $this->fail( __( 'That pending change no longer exists. Refresh the page.', 'ifs-deploy' ) );
		}

		$type = (string) $item->object_type;

		if ( ! in_array( $type, array( 'post', 'media' ), true ) ) {
			return $this->fail( __( 'Preview is available for pages, posts, custom post types and media. This item still deploys normally.', 'ifs-deploy' ) );
		}

		if ( 'delete' === (string) $item->action ) {
			return $this->preview_delete( $item );
		}

		$preview = 'media' === $type
			? $this->preview_media( (int) $item->object_id )
			: $this->preview_post( (int) $item->object_id );

		// The dialog just proved there is nothing to deploy, so act on it rather than
		// leaving the row in Pending Changes contradicting its own preview. Only when
		// the object exists on Production — "missing there" is a real change.
		if ( ! empty( $preview['ok'] ) && ! empty( $preview['found'] ) && empty( $preview['fields'] ) ) {
			$this->queue->mark_deployed( (int) $item->id );
			$preview['resolved'] = true;
		}

		return $preview;
	}

	/**
	 * Preview what pushing a single post would change on Production.
	 *
	 * @return array{
	 *   ok:bool, error?:string, title?:string, subtype?:string, action?:string,
	 *   found?:bool, prod_id?:int, strategy?:string, fields?:array
	 * }
	 */
	public function preview_post( int $post_id ): array {
		if ( ! Config::is_staging() ) {
			return $this->fail( __( 'Previews run from the Staging site.', 'ifs-deploy' ) );
		}

		$package = $this->exporter->export( $post_id );
		if ( null === $package ) {
			return $this->fail( __( 'This post no longer exists on Staging, so there is nothing to preview.', 'ifs-deploy' ) );
		}

		$remote = $this->fetch_remote( $this->probe_from_package( $package ) );
		if ( isset( $remote['error'] ) ) {
			return $this->fail( (string) $remote['error'] );
		}

		$prod_package = is_array( $remote['object'] ?? null ) ? $remote['object'] : null;

		// Production reported a match but could not export it (deleted in the
		// meantime). Treat it as absent so the panel does not claim to update an
		// object it has no "before" state for.
		$found = ( null !== $prod_package );

		// Environment URLs are not content differences — the deploy resolves them per
		// environment — so both sides are collapsed to a token before comparing.
		$environment_urls = array( home_url(), (string) ( $remote['site_url'] ?? '' ), Config::remote()['url'] );

		$package = $this->neutralize_urls( $package, $environment_urls );
		if ( null !== $prod_package ) {
			$prod_package = $this->neutralize_urls( $prod_package, $environment_urls );
		}

		// Parent is compared by slug, not by the ID inside the package (IDs differ
		// legitimately across sites). Supplied on both sides for PackageDiff.
		$package['parent_slug'] = ObjectEndpoint::parent_slug( $post_id );

		if ( null !== $prod_package ) {
			$prod_package['parent_slug'] = (string) ( $remote['parent_slug'] ?? '' );
		}

		return array(
			'ok'       => true,
			'title'    => (string) ( $package['object']['post_title'] ?? '' ),
			'subtype'  => (string) ( $package['subtype'] ?? '' ),
			'action'   => 'update',
			'found'    => $found,
			'prod_id'  => $found ? (int) ( $remote['prod_id'] ?? 0 ) : 0,
			'strategy' => $found ? (string) ( $remote['strategy'] ?? 'none' ) : 'none',
			'fields'   => PackageDiff::compare( $package, $prod_package ),
		);
	}

	/**
	 * Preview what pushing a media item would change on Production.
	 *
	 * Same shape and same rules as preview_post(), so `Admin\DiffRenderer` needs no
	 * special case — a media row's dialog is built by exactly the code that builds a
	 * page's. What differs is only the package: both sides are built by the same
	 * `MediaExporter`, so any difference reported is a real one rather than an artefact
	 * of two different serializations.
	 *
	 * No URL neutralising here. A media package's only URL is `source_url`, which is
	 * excluded from the comparison outright (it carries the domain, so it differs on
	 * every object), and attachment meta does not hold the environment links that ACF
	 * puts in a post's.
	 *
	 * @return array See preview_post() for the shape.
	 */
	public function preview_media( int $attachment_id ): array {
		if ( ! Config::is_staging() ) {
			return $this->fail( __( 'Previews run from the Staging site.', 'ifs-deploy' ) );
		}

		$package = $this->media_exporter->export( $attachment_id );
		if ( null === $package ) {
			return $this->fail( __( 'This media item no longer exists on Staging, so there is nothing to preview.', 'ifs-deploy' ) );
		}

		$remote = $this->fetch_remote( $this->probe_from_media_package( $package ) );
		if ( isset( $remote['error'] ) ) {
			return $this->fail( (string) $remote['error'] );
		}

		$prod_package = is_array( $remote['object'] ?? null ) ? $remote['object'] : null;
		$found        = ( null !== $prod_package );

		return array(
			'ok'       => true,
			'title'    => (string) ( $package['attachment']['post_title'] ?? '' ),
			'subtype'  => (string) ( $package['subtype'] ?? '' ),
			'action'   => 'update',
			'found'    => $found,
			'prod_id'  => $found ? (int) ( $remote['prod_id'] ?? 0 ) : 0,
			'strategy' => $found ? (string) ( $remote['strategy'] ?? 'none' ) : 'none',
			'fields'   => PackageDiff::compare( $package, $prod_package ),
		);
	}

	/**
	 * How Production is asked to find its copy of an attachment.
	 *
	 * Carries exactly what `MediaImporter::find_existing()` needs, and nothing else, so
	 * the preview resolves the same attachment a real push would update: the recorded
	 * source URL first, then the origin link, then id parity — and that last one only
	 * when the filename corroborates it.
	 */
	private function probe_from_media_package( array $package ): array {
		return array(
			'type'        => 'media',
			'origin_id'   => (int) ( $package['origin_id'] ?? 0 ),
			'origin_site' => (string) ( $package['origin_site'] ?? '' ),
			'source_url'  => (string) ( $package['source_url'] ?? '' ),
			'filename'    => (string) ( $package['filename'] ?? '' ),
		);
	}

	/**
	 * A queued delete has no field-level diff — the whole object goes to Trash.
	 *
	 * @param object $item Queue row.
	 */
	private function preview_delete( object $item ): array {
		if ( ! Config::is_staging() ) {
			return $this->fail( __( 'Previews run from the Staging site.', 'ifs-deploy' ) );
		}

		$remote = $this->fetch_remote( $this->probe_from_queue_item( $item ) );
		if ( isset( $remote['error'] ) ) {
			return $this->fail( (string) $remote['error'] );
		}

		return array(
			'ok'       => true,
			'title'    => (string) $item->object_title,
			'subtype'  => (string) $item->object_subtype,
			'action'   => 'delete',
			'found'    => ! empty( $remote['found'] ),
			'prod_id'  => (int) ( $remote['prod_id'] ?? 0 ),
			'strategy' => (string) ( $remote['strategy'] ?? 'none' ),
			'fields'   => array(),
		);
	}

	/**
	 * Ask Production for its current state of this object.
	 *
	 * @param array $probe Lookup payload.
	 *
	 * @return array Remote payload, or array{error:string}.
	 */
	private function fetch_remote( array $probe ): array {
		$response = $this->client->post( 'object', $probe );

		if ( is_wp_error( $response ) ) {
			return array( 'error' => $response->get_error_message() );
		}

		if ( 200 !== $response['status'] || empty( $response['body']['ok'] ) ) {
			$error = (string) ( $response['body']['error'] ?? '' );

			if ( '' === $error ) {
				/* translators: %d: HTTP status code */
				$error = sprintf( __( 'Production returned HTTP %d. Check Copperleaf Deploy → Settings.', 'ifs-deploy' ), (int) $response['status'] );
			}

			return array( 'error' => $error );
		}

		return (array) $response['body'];
	}

	/**
	 * The lookup Production uses to find its counterpart object. Mirrors exactly
	 * what a real deploy would send, so the preview resolves the same target the
	 * push would overwrite.
	 */
	private function probe_from_package( array $package ): array {
		$fields = (array) ( $package['object'] ?? array() );

		return array(
			'origin_id'   => (int) ( $package['origin_id'] ?? 0 ),
			'origin_site' => (string) ( $package['origin_site'] ?? '' ),
			'post_type'   => (string) ( $fields['post_type'] ?? 'post' ),
			'slug'        => (string) ( $fields['post_name'] ?? '' ),
		);
	}

	/**
	 * Probe for a delete row, which has no exportable package. The post may be
	 * trashed on Staging but still readable, which gives us its slug for the
	 * slug-match fallback.
	 *
	 * @param object $item Queue row.
	 */
	private function probe_from_queue_item( object $item ): array {
		$post = get_post( (int) $item->object_id );

		// A deleted attachment is looked up the way attachments are looked up. Its file
		// is usually gone by now, so only the origin link can resolve it — which is
		// exactly what MediaImporter tries first after the source URL.
		if ( 'media' === (string) $item->object_type ) {
			return array(
				'type'        => 'media',
				'origin_id'   => (int) $item->object_id,
				'origin_site' => (string) Credentials::get()['site_id'],
				'source_url'  => '',
				'filename'    => '',
			);
		}

		return array(
			'origin_id'   => (int) $item->object_id,
			'origin_site' => (string) Credentials::get()['site_id'],
			'post_type'   => (string) $item->object_subtype,
			'slug'        => ( $post instanceof \WP_Post ) ? (string) $post->post_name : '',
		);
	}

	/**
	 * Collapse every environment URL in a package's content fields to a token.
	 *
	 * Applied to BOTH sides with the same URL list, so a link that merely points at
	 * a different environment stops registering as a difference. This replaced an
	 * earlier approach that rewrote only the Staging side into Production URLs and
	 * hoped the result matched byte for byte: it could not cope with URLs whose
	 * scheme or `www.` differed from `home_url()`, so those lines were flagged on
	 * every single push.
	 *
	 * Covers post_content, post_excerpt AND post meta at any depth — the same places
	 * the importer now rewrites. Meta matters most in practice: ACF WYSIWYG fields,
	 * repeaters and flexible content all live there and routinely hold absolute
	 * internal links, so excluding meta left exactly the URLs an editor sees flagged
	 * on every push.
	 *
	 * @param string[] $environment_urls
	 */
	private function neutralize_urls( array $package, array $environment_urls ): array {
		foreach ( array( 'post_content', 'post_excerpt' ) as $field ) {
			if ( isset( $package['object'][ $field ] ) ) {
				$package['object'][ $field ] = UrlRewriter::neutralize(
					(string) $package['object'][ $field ],
					$environment_urls
				);
			}
		}

		if ( isset( $package['meta'] ) && is_array( $package['meta'] ) ) {
			$package['meta'] = (array) UrlRewriter::neutralize_deep( $package['meta'], $environment_urls );
		}

		return $package;
	}

	/**
	 * @return array{ok:bool,error:string}
	 */
	private function fail( string $message ): array {
		return array( 'ok' => false, 'error' => $message );
	}
}
