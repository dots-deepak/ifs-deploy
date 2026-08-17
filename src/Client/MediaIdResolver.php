<?php
declare(strict_types=1);

namespace IfsDeploy\Client;

use IfsDeploy\Queue\QueueRepository;
use IfsDeploy\Support\DebugLog;
use IfsDeploy\Support\MediaReferences;

/**
 * Resolves a media ID conflict by moving the attachment on THIS (Staging) site to an id
 * that is free on both sites — and refuses, loudly, whenever that is not provably safe.
 *
 * ── THE PROBLEM THIS SOLVES ────────────────────────────────────────────────────────
 *
 * `MediaImporter` gives a new attachment the same id it has on Staging, because this
 * plugin copies meta verbatim and ACF fields, galleries, `wp-image-N` classes and
 * Gutenberg block attributes all store the bare number. When Production already has an
 * object at that id, the import refuses rather than silently landing the file somewhere
 * else. Production cannot fix that from its side: the id belongs to a real object there.
 *
 * Staging can — but only while the attachment is not referenced yet.
 *
 * ── WHY THE ZERO-REFERENCE RULE IS NOT NEGOTIABLE ─────────────────────────────────
 *
 * WordPress has no API for changing a post's id, and nothing keeps references to it in one
 * place. Rewriting them would mean editing post content, postmeta, options, term
 * relationships, and whatever any page builder or third-party plugin stores — in one
 * unsynchronised pass, with a silently broken image as the failure mode. The list can
 * never be complete, because any plugin may store an id in a shape nothing here knows to
 * look for.
 *
 * So this refuses whenever `MediaReferences` finds anything at all, and names what it
 * found. The case it does serve is the common one: an image uploaded to the Media Library
 * and not yet placed on a page.
 *
 * ── WHY A DIRECT UPDATE, RATHER THAN RE-CREATING THE ATTACHMENT ───────────────────
 *
 * Deleting and re-inserting would destroy the file. `wp_delete_attachment()` removes it
 * from disk, and `wp_delete_post()` delegates to it for attachments, so "delete the row
 * but keep the file" is not something the API offers either. Re-uploading instead would
 * change the filename whenever the name is taken, regenerate every size, and lose the
 * original's dates.
 *
 * For a row nothing points at, moving the id is genuinely just the row and its meta. It is
 * done in one transaction, and every id-keyed table is updated — not only the two obvious
 * ones — so nothing is left pointing at a number that no longer exists.
 */
final class MediaIdResolver {

	private DeployClient $client;

	public function __construct( ?DeployClient $client = null ) {
		$this->client = $client ?? new DeployClient();
	}

	/**
	 * What would happen if this attachment were renumbered? Nothing is changed.
	 *
	 * @return array{ok:bool,message:string,can_renumber?:bool,new_id?:int,blockers?:array}
	 */
	public function inspect( int $attachment_id ): array {
		$post = get_post( $attachment_id );

		if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type ) {
			return array( 'ok' => false, 'message' => __( 'That media item no longer exists on this site.', 'ifs-deploy' ) );
		}

		$blockers = MediaReferences::find( $attachment_id, 6 );

		if ( array() !== $blockers ) {
			return array(
				'ok'           => true,
				'can_renumber' => false,
				'blockers'     => $blockers,
				'message'      => sprintf(
					/* translators: %s: comma-separated list of things using the file */
					__( 'This file is already in use by %s, so its ID cannot be changed without breaking those references. Free it up first, or resolve the conflict on Production instead.', 'ifs-deploy' ),
					implode( ', ', array_map( static fn( array $b ): string => $b['label'], $blockers ) )
				),
			);
		}

		$new_id = $this->free_id();

		if ( is_wp_error( $new_id ) ) {
			return array( 'ok' => false, 'message' => $new_id->get_error_message() );
		}

		return array(
			'ok'           => true,
			'can_renumber' => true,
			'new_id'       => $new_id,
			'blockers'     => array(),
			'message'      => sprintf(
				/* translators: 1: current attachment id, 2: proposed new id */
				__( 'Nothing on this site uses this file yet, so it can be moved from ID %1$d to ID %2$d — which is free on both sites.', 'ifs-deploy' ),
				$attachment_id,
				$new_id
			),
		);
	}

	/**
	 * Move the attachment to an id free on both sites.
	 *
	 * @return array{ok:bool,message:string,new_id?:int}
	 */
	public function renumber( int $attachment_id ): array {
		$check = $this->inspect( $attachment_id );

		if ( empty( $check['ok'] ) || empty( $check['can_renumber'] ) ) {
			return array( 'ok' => false, 'message' => (string) $check['message'] );
		}

		$new_id = (int) $check['new_id'];

		// Re-checked immediately before the write, because inspect() ran over the network
		// and something local could have taken the id since.
		if ( get_post( $new_id ) instanceof \WP_Post ) {
			return array( 'ok' => false, 'message' => __( 'That ID was taken while we were checking. Try again.', 'ifs-deploy' ) );
		}

		$moved = $this->move( $attachment_id, $new_id );

		if ( ! $moved ) {
			return array( 'ok' => false, 'message' => __( 'The ID could not be changed. Nothing was altered — see Logs & Diagnostics.', 'ifs-deploy' ) );
		}

		DebugLog::info(
			'Media renumbered to resolve an ID conflict with Production',
			array( 'from' => $attachment_id, 'to' => $new_id )
		);

		return array(
			'ok'      => true,
			'new_id'  => $new_id,
			'message' => sprintf(
				/* translators: 1: old id, 2: new id */
				__( 'Media ID changed from %1$d to %2$d. Push it again — it will now land on the same ID on Production.', 'ifs-deploy' ),
				$attachment_id,
				$new_id
			),
		);
	}

	/**
	 * An id free on BOTH sites: one above the highest either of them has used.
	 *
	 * Production's figure comes from `IdSpaceEndpoint`, which reads AUTO_INCREMENT as well
	 * as MAX(ID) so a recently emptied trash cannot hand back a number MySQL is about to
	 * allocate anyway.
	 *
	 * @return int|\WP_Error
	 */
	private function free_id() {
		$response = $this->client->post( 'id-space', array() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = (array) ( $response['body'] ?? array() );

		if ( empty( $body['ok'] ) ) {
			return new \WP_Error(
				'ifs_deploy_id_space_failed',
				(string) ( $body['error'] ?? __( 'Production did not report its ID range, so a safe new ID cannot be chosen.', 'ifs-deploy' ) )
			);
		}

		$remote = (int) ( $body['max_id'] ?? 0 );

		if ( $remote <= 0 ) {
			return new \WP_Error(
				'ifs_deploy_id_space_empty',
				__( 'Production reported no ID range. Update the plugin on Production and try again.', 'ifs-deploy' )
			);
		}

		return max( self::local_max_id(), $remote ) + 1;
	}

	/**
	 * The highest post id in use here, counting the one MySQL will allocate next.
	 */
	public static function local_max_id(): int {
		global $wpdb;

		$max = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$status = $wpdb->get_row( "SHOW TABLE STATUS LIKE '{$wpdb->posts}'", ARRAY_A );

		$next = is_array( $status ) ? (int) ( $status['Auto_increment'] ?? 0 ) : 0;

		return max( $max, $next > 0 ? $next - 1 : 0 );
	}

	/**
	 * Move the row and everything keyed to it.
	 *
	 * Every table WordPress cores keys on a post id is updated, including the two that a
	 * zero-reference attachment is not supposed to have rows in. `MediaReferences` already
	 * refuses when it finds terms, so `term_relationships` should always be a no-op here —
	 * it is updated anyway, because a scan that missed something must not turn into
	 * orphaned rows, and an UPDATE that affects nothing costs nothing.
	 *
	 * `post_parent` is included for the same reason: attachments are usually leaves, but if
	 * anything is parented to this one, leaving it pointing at a vanished id would detach
	 * it silently.
	 */
	private function move( int $from, int $to ): bool {
		global $wpdb;

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$ok = false !== $wpdb->update( $wpdb->posts, array( 'ID' => $to ), array( 'ID' => $from ), array( '%d' ), array( '%d' ) ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			&& false !== $wpdb->update( $wpdb->postmeta, array( 'post_id' => $to ), array( 'post_id' => $from ), array( '%d' ), array( '%d' ) ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			&& false !== $wpdb->update( $wpdb->posts, array( 'post_parent' => $to ), array( 'post_parent' => $from ), array( '%d' ), array( '%d' ) ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			&& false !== $wpdb->update( $wpdb->term_relationships, array( 'object_id' => $to ), array( 'object_id' => $from ), array( '%d' ), array( '%d' ) ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			&& false !== $wpdb->update( $wpdb->comments, array( 'comment_post_ID' => $to ), array( 'comment_post_ID' => $from ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( ! $ok ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			return false;
		}

		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		/*
		 * The PENDING CHANGE has to follow the file.
		 *
		 * Its queue row still names the old id, and that id no longer exists — so the push
		 * that prompted all this would build a package for a missing attachment and fail
		 * with something far less clear than the conflict it replaced.
		 */
		( new QueueRepository() )->repoint( 'media', $from, $to );

		clean_post_cache( $from );
		clean_post_cache( $to );

		return true;
	}
}
