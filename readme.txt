=== IFS Deploy ===
Contributors: SET_YOUR_WORDPRESS_ORG_USERNAME
Tags: deployment, staging, content, acf, rollback
Requires at least: 6.5
Tested up to: 6.5
Requires PHP: 8.0
Stable tag: 0.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Push content from a Staging site to Production over a signed connection. Review what changed, deploy what you choose, roll back if it was wrong.

== Description ==

IFS Deploy moves **content** between two WordPress sites — pages, posts, custom post
types, ACF fields, taxonomy terms, menus, allow-listed options and media. It never
touches code, themes or plugin files, and it never writes raw SQL: everything goes
through the WordPress APIs, so hooks and caches behave exactly as they would if a
human had clicked Update.

Content moves **one way only**, Staging to Production. There is no pull.

= What it does =

* **Automatic change tracking.** Editing a page on Staging queues it. Editing it again
  updates the same queue entry instead of adding another, and editing it back to the
  state already deployed removes it from the queue.
* **Deploy what you choose.** Push one object, a selection, or everything pending.
* **See the change first.** A field-level before/after diff, including ACF, rendered
  from Production's actual current content rather than from a guess.
* **Compare & Sync.** Lists what differs between the two sites, and can link an
  existing Production page to its Staging original so later deploys update it instead
  of creating a duplicate.
* **Rollback.** A snapshot is taken on Production immediately before anything is
  overwritten — the latest 3 per object — with a preview of what a rollback would
  restore before you confirm it.
* **Built for a team.** Per-role permissions plus per-user allow/block, so an Editor can
  queue changes without being able to deploy them. Concurrent deploys of the same object
  are serialised rather than racing.

= Security =

The connection is the security boundary, so it is treated as one:

* Every request is signed with HMAC-SHA256 over its timestamp, nonce, route and body.
* Every reply is signed too, so Staging cannot be fed a forged "nothing changed".
* Replayed requests are refused by a nonce store, not by a timestamp window alone.
* Imported content passes through a `kses` allow-list, so a compromised sending site
  cannot store executable HTML on the live site.
* Repeated authentication failures earn a cool-off. Optional IP allow/block lists.
* Every API request is logged on the receiving side: address, endpoint, outcome, agent.
* IP addresses can be stored anonymised, and log retention is configurable
  (7, 14, 30 or 90 days) for GDPR.

`SECURITY.md` in the plugin folder documents every finding from the internal audit,
what was done about it, and what was deliberately accepted.

== Installation ==

Install and activate on **both** sites, then:

1. On the **live** site, open IFS Deploy → Settings, set the role to **Production**, and
   copy the API key and secret it generates.
2. On the **staging** site, set the role to **Staging** and paste the Production URL,
   API key and secret.
3. Click **Test Connection**. It reports the other side's role, plugin version and
   whether it can fetch media back from Staging — media deployment depends on that
   direction working.

Both sites must run the same plugin version. The signed request format is shared
between them, so a mismatched pair cannot deploy.

== Frequently Asked Questions ==

= Does it deploy themes, plugins or code? =

No. Content only. Use version control for code.

= Can I pull content from Production back to Staging? =

Not in this version. Deployment is one-way.

= What happens to content that exists on Production but not on Staging? =

Nothing. A deploy never deletes what it does not mention, so Production-only pages are
left alone. Compare & Sync lists them so you know they are there.

= Will it overwrite a page someone is editing on Production? =

It will overwrite the stored content, which is what a deploy is for — but the previous
version is snapshotted first and can be rolled back.

= Do both sites need to be updated at the same time? =

Yes. The signed request format is shared, so update the pair together.

= Is the shared secret stored in plain text? =

Yes, in `wp_options`, with autoload off — the same as every plugin that holds an API
secret, because WordPress offers no key store. Anyone with database read access already
has the whole site. Rotate it from Settings → Regenerate Credentials.

== Screenshots ==

1. Overview — which site you are on, and which way content moves.
2. Pending Changes — what is queued, with a before/after preview.
3. Compare & Sync — what differs between the two sites.
4. Deployment History — every push, with per-object results and rollback.
5. Logs & Diagnostics — API access on the receiving side, and the event log.

== Changelog ==

= 0.5.0 =
Recognises WPML media translations directly, and adds a logging switch.

* **WPML media translations are recognised as such.** 0.4.0 merged them because they
  share a file; this asks WPML itself which record is the original. That is more precise
  in two ways: it identifies the real original rather than assuming the oldest record is
  it, and it still works in the WPML setups that copy the file as well. Any translation
  plugin offering the same hook is covered too.
* **New "Detailed logging" switch** under Logs & Diagnostics, off by default. When on,
  every detected change is recorded with the file, the status, the related records and
  the plugin or theme responsible — identified by file and line. It is off by default
  because those entries are written per event and the log keeps a fixed number of them:
  left on, routine detail pushes out the errors worth reading. Errors and warnings are
  always recorded either way.
* **Fixed missing admin styling.** The shipped stylesheet had fallen behind its source,
  so the "this site" marker on the Overview screen had no border and the summary cards
  had no shadow.

= 0.4.0 =
Duplicated media records now count as one pending change.

* **A file with more than one media record is tracked once.** WordPress can end up
  holding several media records that point at the same file — WPML's media duplication is
  one cause — and each was appearing as its own pending change. That was misleading as
  well as untidy: deploying all of them produces a single file on Production regardless.
  The original is kept and the rest are merged into it, both as changes happen and for
  entries already in the list.
* **Generated image sizes are no longer tracked as separate media items.** The original
  file is what deploys; Production regenerates its own sizes. Some plugins register those
  derivatives as real attachments, and each was appearing as its own pending change.
* Attachments can be excluded from tracking with the `ifs_deploy_track_attachment`
  filter.

= 0.3.0 =
Fixes duplicate entries in Pending Changes, and adds a change preview for media.

* **Media entries now show their file name**, so several attachments that share a title —
  which happens whenever the same file is uploaded more than once — can be told apart.

* **The same item can no longer appear more than once in Pending Changes.** On some
  sites the queue table was missing the database key that prevents it, so two saves of
  one object at the same moment each added a row — which is how a single image came to
  be listed eight times. The key is now checked and rebuilt on activation, existing
  duplicates are merged, and the list is de-duplicated as it is drawn.
* **Each entry now shows its object ID.** WordPress names an uploaded file's attachment
  after the file, so several copies of `photo.png` are all titled "photo". Without the ID
  they were impossible to tell apart.
* **"View changes" now works for media.** Editing an image's title, caption, description,
  slug, alt text or custom fields shows a before/after just like a page does. The file's
  address and upload date are excluded, since those differ between sites by nature.

= 0.2.0 =
Correctness release. Both sites must be updated together, and the database upgrade runs
automatically on each (schema versions 6 and 7).

* **Removing a value now syncs.** Clearing a field on Staging — a Yoast SEO meta
  description is the common case — removes it on Production too. Previously only added
  and edited values travelled, so a deleted description stayed live. Protected keys
  (editor locks, caches, featured-image links) are never touched; use the
  `ifs_deploy_delete_missing_meta` filter to switch the behaviour off.
* **Undoing an edit clears it from Pending Changes**, even for content that has never
  been deployed.
* **One queue entry per object.** Updating a media item's title or alt text no longer
  produces several tracked entries for the same file.
* **Safer matching on Production.** A deploy will no longer overwrite an unrelated
  Production post that merely shares an ID with the Staging one.
* Publish dates no longer drift when the two sites use different timezones.
* Featured images keep their ID across sites, so ACF image fields pointing at them
  resolve.
* Compare & Sync warns when either site has more content than the comparison covers,
  instead of reporting the remainder as missing.
* A deployment can only be rolled back once; a second attempt is refused rather than
  silently re-applying the snapshot.
* Deployment results are matched to the right queue entry when different object types
  share an ID.
* Rollback points, request bodies and content hashes are no longer written empty if a
  value cannot be encoded — the operation fails and says so instead.

= 0.1.0 =
* First release.
* Signed REST connection in both directions, with replay protection and route-bound
  request signatures.
* Change tracking for posts, pages, custom post types, ACF, terms, menus, allow-listed
  options and media.
* Selective deploy, field-level before/after preview, Compare & Sync with ID linking.
* Pre-deploy snapshots with rollback preview; 3 restore points per object.
* Role and per-user permissions; concurrent deploys of one object are serialised.
* Content allow-list filtering on import, failure lockout, IP allow/block lists,
  API access logging with configurable retention and optional IP anonymisation.

== Upgrade Notice ==

= 0.5.0 =
Recognises WPML media translations directly, adds a logging switch, and restores missing
admin styling. Includes everything since 0.2.0, so update both sites together; the
database upgrade runs itself.

= 0.3.0 =
Fixes duplicate Pending Changes entries and adds a change preview for media. Includes
everything in 0.2.0, so update both sites together; the database upgrade runs itself.

= 0.2.0 =
Correctness fixes to deleting, reverting and matching content. Update both sites
together; the database upgrade runs itself.

= 0.1.0 =
First release. Install on both sites and pair them from Settings.
