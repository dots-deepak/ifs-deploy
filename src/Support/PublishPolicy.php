<?php
declare(strict_types=1);

namespace IfsDeploy\Support;

/**
 * What status content gets when it ARRIVES on this site for the first time.
 *
 * ── WHAT THIS IS FOR ───────────────────────────────────────────────────────────────
 *
 * By default a deploy reproduces Staging exactly, so a published page arrives published
 * and is live the moment it lands. Some teams want the opposite: everything arrives as a
 * draft, and a person on the live site decides when it goes public. That is an editorial
 * policy, not a transport setting.
 *
 * ── WHY IT LIVES ON PRODUCTION, NOT STAGING ────────────────────────────────────────
 *
 * The whole point is that the live site controls its own publishing. A policy configured
 * on Staging would travel inside the package, which means the sending site would be
 * telling the receiving site when to publish — exactly the authority this is meant to take
 * away from it. It is also the same rule the rest of the plugin follows: what happens to
 * Production is decided by Production (`ContentFirewall` is the other example).
 *
 * So the Settings screen only offers this when the site is set to Production, and says so
 * on Staging rather than presenting a control that would do nothing.
 *
 * ── FIRST ARRIVAL ONLY ─────────────────────────────────────────────────────────────
 *
 * Applied when `PostImporter` INSERTS, never when it updates. "New" is decided by the same
 * matcher every other decision uses — origin stamp, then id parity, then slug — so an
 * object that exists here under any of those is an update and keeps whatever status it has.
 *
 * The second half of that promise needs `respects_source_status()`: once this policy is in
 * use, Production's status and Staging's can legitimately differ for ever, and a later
 * content edit must not quietly drag Production back to whatever Staging happens to say.
 */
final class PublishPolicy {

	public const OPTION = 'ifs_deploy_new_status';

	/** Reproduce Staging exactly — the historic behaviour, and the default. */
	public const SOURCE = 'source';

	/**
	 * Statuses that must never be offered, whatever WordPress reports.
	 *
	 *   auto-draft — a placeholder core deletes on a schedule, so content would vanish.
	 *   inherit    — belongs to attachments; a post given it is invisible everywhere.
	 *   trash      — arriving in the bin is not "awaiting review", and the deploy would
	 *                look like it had failed.
	 *   future     — means "scheduled", and a post whose date is not in the future is
	 *                immediately re-published by core, so it silently does nothing.
	 */
	private const NEVER = array( 'auto-draft', 'inherit', 'trash', 'future' );

	/**
	 * The dropdown: "same as source", then every status this site actually has.
	 *
	 * Read from WordPress rather than hard-coded, so a workflow plugin that registers its
	 * own status appears here without this file knowing about it.
	 *
	 * @return array<string,string> value => label
	 */
	public static function choices(): array {
		$choices = array(
			self::SOURCE => __( 'Same as Staging — publish immediately if it is published there', 'ifs-deploy' ),
		);

		foreach ( get_post_stati( array(), 'objects' ) as $status ) {
			$name = (string) ( $status->name ?? '' );

			if ( '' === $name || in_array( $name, self::NEVER, true ) ) {
				continue;
			}

			$choices[ $name ] = (string) ( $status->label ?? $name );
		}

		return $choices;
	}

	/**
	 * The configured status, or SOURCE when it is unset or no longer registered.
	 *
	 * Falling back rather than trusting the stored value matters: a workflow plugin can be
	 * deactivated after its status was chosen here, and applying a status WordPress no
	 * longer knows makes content unreachable in wp-admin.
	 */
	public static function get(): string {
		$stored = (string) get_option( self::OPTION, self::SOURCE );

		return array_key_exists( $stored, self::choices() ) ? $stored : self::SOURCE;
	}

	public static function set( string $status ): string {
		$status = array_key_exists( $status, self::choices() ) ? $status : self::SOURCE;

		update_option( self::OPTION, $status );

		return $status;
	}

	/** Is a status being forced on new content at all? */
	public static function is_active(): bool {
		return self::SOURCE !== self::get();
	}

	/**
	 * The status a NEWLY ARRIVING object should be given.
	 *
	 * @param string $incoming  What Staging says the status is.
	 * @param string $post_type The type being created, for the fallback check.
	 *
	 * @return string The status to store.
	 */
	public static function status_for_new( string $incoming, string $post_type ): string {
		if ( ! self::is_active() ) {
			return $incoming;
		}

		$wanted = self::get();

		/*
		 * FALL BACK TO DRAFT RATHER THAN TO THE INCOMING STATUS.
		 *
		 * If the chosen status cannot be used for this post type, the safe answer is the
		 * one the operator was reaching for — "not visible yet" — not the one they were
		 * trying to avoid. Falling back to `$incoming` would publish the very thing the
		 * policy exists to hold back, and it would do it silently.
		 */
		if ( ! self::supports( $wanted, $post_type ) ) {
			DebugLog::warning(
				'The configured status for new content cannot be used for this post type; using Draft instead',
				array( 'wanted' => $wanted, 'post_type' => $post_type )
			);

			return 'draft';
		}

		return $wanted;
	}

	/**
	 * Should the status Staging sent be applied to something that ALREADY exists here?
	 *
	 * ── THE PROBLEM THIS SOLVES ────────────────────────────────────────────────────
	 *
	 * Once new content arrives as a draft and somebody here publishes it, the two sites
	 * disagree about its status on purpose — Production says `publish`, Staging still says
	 * `publish` too, or `draft`, or anything else. An ordinary content edit on Staging then
	 * carries a status field along with it, and applying that field would undo the decision
	 * made here. Edit a typo on Staging and the page you had deliberately unpublished goes
	 * live again.
	 *
	 * ── HOW "CHANGED ON STAGING" IS ESTABLISHED ────────────────────────────────────
	 *
	 * Not by comparing against Production's own status — that tells you the two differ, not
	 * who changed. Every import records what Staging said at the time, so the comparison is
	 * against THAT:
	 *
	 *   incoming === last recorded  → Staging has not touched the status. Keep ours.
	 *   incoming !== last recorded  → somebody changed it there deliberately. Apply it.
	 *
	 * Which is exactly the two rules asked for, and it needs no extra field on the wire.
	 *
	 * Only consulted while the policy is active. With "Same as Staging" the sites are meant
	 * to mirror each other, and this returns true so the behaviour is unchanged from before
	 * the setting existed.
	 *
	 * @param int    $post_id  The object on this site.
	 * @param string $incoming The status Staging is sending now.
	 */
	public static function respects_source_status( int $post_id, string $incoming ): bool {
		if ( ! self::is_active() ) {
			return true;
		}

		$last = (string) get_post_meta( $post_id, self::LAST_SOURCE_STATUS_META, true );

		// Nothing recorded — this object predates the policy, so there is no evidence
		// either way. Applying the incoming status is the old behaviour and the safer
		// reading of "we do not know".
		if ( '' === $last ) {
			return true;
		}

		return $last !== $incoming;
	}

	/**
	 * Meta recording what Staging last said this object's status was.
	 *
	 * Deliberately separate from the object's actual status here: the two are allowed to
	 * differ, and it is the DIFFERENCE BETWEEN CONSECUTIVE SOURCE VALUES that says whether
	 * anyone changed anything.
	 */
	public const LAST_SOURCE_STATUS_META = '_ifs_deploy_src_status';

	/**
	 * Can this post type actually hold this status?
	 *
	 * WordPress registers statuses globally rather than per type, so there is no direct
	 * answer to ask for. What can be checked is that the status is registered at all and
	 * that the post type exists — which is what catches the real cases: a status belonging
	 * to a workflow plugin that has since been switched off.
	 */
	private static function supports( string $status, string $post_type ): bool {
		if ( '' === $post_type || ! post_type_exists( $post_type ) ) {
			return false;
		}

		return null !== get_post_status_object( $status );
	}
}
