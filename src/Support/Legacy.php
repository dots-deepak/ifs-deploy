<?php
declare(strict_types=1);

namespace IfsDeploy\Support;

/**
 * Every string this plugin still has to spell the OLD way, in one place.
 *
 * ── WHY THESE ARE NOT LEFTOVERS ────────────────────────────────────────────────
 *
 * The plugin was renamed to `ifs_deploy_` in August 2026, and 2799 references moved with
 * it. These did not, and each one has a specific reason — they are **stored data format**, not
 * names. They describe bytes that are already on disk, so changing the string does not rename
 * anything: it stops the code recognising what is there.
 *
 * They live here rather than scattered across six files for two reasons. Anyone auditing "why
 * does this still use the old name?" gets one file with the answer, instead of finding six and
 * having to judge each one. And `ABSENT_SENTINEL` was previously written out twice, in
 * `Export\OptionExporter` and `Rollback\SnapshotStore`, where the two literals HAD to match for
 * a captured absence to round-trip — a constraint enforced only by a test. One constant makes
 * divergence impossible instead of merely detectable.
 *
 * ── WHAT WOULD BREAK ───────────────────────────────────────────────────────────
 *
 * | Constant             | If it changed                                                     |
 * |----------------------|-------------------------------------------------------------------|
 * | `SIGNATURE_URL_TOKEN`| Every signature ever written is invalidated. It is substituted     |
 * |                      | into content *before* hashing, so it sits inside the `hash` column |
 * |                      | of every queue row and the `_ifs_deploy_src_sig` stamp on every    |
 * |                      | deployed object. Unchanged content would hash differently:         |
 * |                      | ~2000 objects reporting a difference that does not exist, and      |
 * |                      | `QueueVerifier` unable to clear a row that really is in sync.      |
 * | `ABSENT_SENTINEL`    | Rollback breaks for any snapshot taken before the rename. The      |
 * |                      | marker distinguishing "this option did not exist" from "it existed |
 * |                      | and was empty" is written INTO snapshots; unrecognised, a restore  |
 * |                      | writes the sentinel in as the option's value instead of deleting   |
 * |                      | the option.                                                       |
 * | `META_PREFIX`        | A pre-rename snapshot's bookkeeping keys stop being recognised as  |
 * |                      | ours, so a restore revives them and the next deploy pushes them to |
 * |                      | Production as though they were content.                           |
 * | `OPTION_PREFIX`      | `Support\LegacyRename` can no longer find the data it exists to    |
 * | `DB_VERSION_OPTION`  | migrate, so the migration silently becomes a no-op and every       |
 * |                      | failure it prevents comes back.                                   |
 * | `CRON_HOOK`          | The old daily event is left scheduled, firing a hook nothing       |
 * |                      | listens to, forever.                                              |
 *
 * Nothing here is ever displayed. The old spelling costs nothing and keeps everything already
 * stored readable.
 *
 * **Do not "finish the rename" by editing this file.** `legacy-rename-test.php` asserts each
 * value and asserts that its consumers reference the constant rather than re-introducing a
 * literal.
 */
final class Legacy {

	/** Option and table prefix before the rename. Used to FIND old data, never to write it. */
	public const OPTION_PREFIX = 'deploypress_';

	/** Post and term meta prefix before the rename. */
	public const META_PREFIX = '_deploypress_';

	/** The option whose presence means "this install predates the rename". */
	public const DB_VERSION_OPTION = 'deploypress_db_version';

	/** The daily log-purge event, as it was scheduled before the rename. */
	public const CRON_HOOK = 'deploypress_purge_logs';

	/**
	 * Stands in for a site's own home URL inside a content signature.
	 *
	 * Part of the hash input, therefore part of every signature on disk.
	 */
	public const SIGNATURE_URL_TOKEN = '__deploypress_site_url__';

	/**
	 * Marks "this option did not exist" in an exported package and in a rollback snapshot.
	 *
	 * Shared by `Export\OptionExporter` and `Rollback\SnapshotStore`; they must agree or a
	 * captured absence cannot round-trip.
	 */
	public const ABSENT_SENTINEL = '__deploypress_absent__';
}
