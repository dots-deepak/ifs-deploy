=== IFS Deploy ===
Contributors: SET_YOUR_WORDPRESS_ORG_USERNAME
Tags: deployment, staging, content, acf, rollback
Requires at least: 6.5
Tested up to: 6.5
Requires PHP: 8.0
Stable tag: 0.12.0
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

= 0.12.0 =
The Pending Changes list now says what you actually did, plugin archives stay out of it for
good, and the push dialog shows the progress it was already measuring.

* **The Action column names the real action.** It printed the two values the deploy uses
  internally, so trashing a file, restoring one and editing one all read "update". It now
  says Added, Updated, Published, Draft, Scheduled, Private, Restored, Trashed or Deleted.
* **Plugin and theme ZIPs are ignored on every path.** The rule was applied where files are
  uploaded but not where they are removed, so an archive correctly kept out of the list
  still appeared in it the moment it was moved to the Trash. Existing rows are cleared on
  upgrade.
* **Something you add and remove before pushing now leaves nothing behind.** Uploading an
  image and deleting it again left a pending deletion, which asked Production to remove a
  file it had never been given and failed. The two changes cancel out.
* **Restoring media from the Trash is tracked and syncs.** It relied on a hook that does not
  reliably fire for a restore, so a recovered file could sit on Staging with nothing to push.
* **The progress dialog no longer reads "0%%".** A percent sign was escaped for PHP in a
  string the browser formats, so the escape was printed.
* **The progress dialog is visible.** It closed and reloaded in the same instant the last
  batch returned, so on a push small enough to be one request the bar was only ever seen at
  zero. The finished state is now shown before the page refreshes, and the bar animates
  while a batch is in flight instead of looking stalled.
* **A push that could send nothing now says so.** If no item could be prepared, the push
  reported success having transmitted nothing — the page reloaded and Production was
  untouched, with no indication anything had gone wrong.

= 0.11.1 =
Fixes for media rollback and for removals that could not find their target.

* **Media changes now record a restore point when Production's copy is in the Trash.** The
  snapshot lookup searched only live attachments while the import beside it searched the
  Trash as well, so anything already trashed there was changed with no restore point — and
  the History screen showed no Rollback button, because as far as it could tell nothing had
  been overwritten.
* **A rollback that cannot restore anything now says so** instead of reporting success. The
  case is a permanently deleted item: the row and, for media, the file are both gone, so
  there is nothing to put back.
* **Removals now find media this site renamed on arrival.** A file that arrived as
  `photo.png` where an unrelated `photo.png` already existed is stored as `photo-1.png`, and
  the last-resort match compared that local name — so once Staging's own URL had changed as
  well, the file could not be found at all and the removal reported that it had never been
  deployed.
* **A removal that matches but cannot be applied is reported as a failure**, not a success,
  and a removal that matches nothing now records everything it searched for in Logs &
  Diagnostics so the cause can actually be identified.

= 0.11.0 =
Trashing media now trashes it on Production instead of destroying it, and a media item that
cannot keep its ID says so instead of quietly changing it.

* **Moving media to the Trash no longer destroys it on Production.** The removal was sent
  as "remove this" and the receiving site decided what that meant — and WordPress leaves
  media trash switched OFF unless a site turns it on, so most Production sites deleted the
  file outright while the deploy reported success. A trash on Staging is now a trash on
  Production whatever that site's setting is, and a permanent delete is permanent on both.
* **Emptying the Trash after pushing a trash now works.** Once removals genuinely trashed
  things on Production, the plugin could no longer find what it had trashed — so the
  follow-up permanent delete reported that the item had never been deployed. Both matchers
  now look in the Trash as well.
* **Re-saving a file you had deleted brings it back.** Pushing an update for media sitting
  in Production's Trash used to report success while the image stayed missing from every
  page using it.
* **Permanently deleting a page now removes it from Production**, instead of only moving it
  to the Trash there.
* **Media that cannot keep its ID is reported instead of silently renumbered.** A new image
  is created on Production with the same ID it has on Staging, which is what makes gallery
  fields, featured images and inline images keep working. When that ID is already taken —
  by anything, including a page — the file used to be uploaded under a different ID with no
  indication. The push now stops and names what is occupying the ID, before downloading
  anything.
* **And offers to fix it.** If the file is not yet used anywhere on Staging, you are offered
  a new ID that is free on both sites. If it IS used, the dialog says what is using it and
  declines — changing the ID of a file that is referenced would break those references
  silently, and WordPress provides no safe way to do it.

= 0.10.0 =
Deleting content now actually syncs, permission refusals say so, and the push dialog
describes what it is doing.

* **Deleting a page or media item now reaches Production.** Four separate faults were
  behind this. Moving something to the Trash quietly cancelled its own removal, because
  the save that trashing performs replaced the record a moment after it was written — so a
  deletion was pushed as an edit, and on a site where it could not be matched it created a
  trashed copy instead of removing anything. A removal also carried too little to identify
  what it referred to, so a page renamed before deletion could not be found. And when
  nothing matched, the push reported success anyway.
* **Media moved to the Trash was not tracked at all** on sites where "Delete" means "Move
  to Trash". It is now. (Pushing it still destroyed the file on Production rather than
  trashing it — see 0.11.0, which is the release that actually fixed that.)
* **Pushing changes you do not own explains itself.** Pressing Push All on a colleague's
  changes used to do nothing at all — no dialog, no message — and Push Selected reported
  "Nothing is selected" when several rows were selected. Both now say what the problem is.
  The same applies to Ignore. If a selection is part yours and part someone else's, the
  confirmation says how many will be left out **before** you confirm.
* **The push dialog now says what it is doing** — which stage it has reached, the item
  being sent, and an estimated time remaining measured from the work already done rather
  than guessed.
* **Saving settings uses the plugin's own notification**, like every other action, instead
  of the standard WordPress notice.

= 0.9.0 =
Large pushes now show real progress and can be cancelled, media rollback works, and
several failures that were reported as successes now say what went wrong.

* **Pushes are sent in batches, with a real progress bar.** A push used to be one long
  request that could exceed the server's time limit on a large set — and there was nothing
  to report from inside it. It is now split into batches, so the bar reflects what has
  actually completed rather than an estimate.
* **A push can be cancelled.** Anything already sent is undone on Production, and your
  changes stay in Pending Changes until you push them again or remove them yourself. A
  cancelled push shows as one entry in Deployment History, not as a deploy plus an undo.
* **Rollback now works for newly added content, including media.** Previously a rollback
  could only restore a previous version, so anything the deploy had CREATED had nothing to
  go back to and no Rollback button. Undoing a creation now removes it — a page goes to
  Trash, where it can still be recovered.
* **Large media files no longer fail.** The file was being loaded into memory in full
  before being saved; it is now moved into place directly, so size no longer costs memory.
* **A delete that matches nothing on Production is reported instead of passing silently.**
  Deleting a page or media item on staging and pushing it could report success while the
  item stayed live. The message now says whether it was never deployed there, or whether
  the two copies need linking with Compare & Sync.
* **Pushing someone else's changes gives a proper message.** Only the person who made a
  change — or an administrator — can push it. Selecting a colleague's item used to end at
  "No items selected", which was neither true nor helpful.
* **Notifications are more prominent**, and an error now stays on screen until dismissed.

= 0.8.0 =
Only real people's changes are tracked, and every action reports itself the same way.

* **Changes with no logged-in user are no longer tracked.** Entries showing "Changed By:
  Unknown" came from background work — cron, WP-CLI, an async cleanup — rather than from
  someone on the team, and could not be reviewed in any useful sense. Applies to every
  content type. A site that does edit content by script can turn tracking back on with the
  `ifs_deploy_track_without_user` filter, and every skip is recorded when Detailed logging
  is on, so nothing goes missing unnoticed.
* **Deleting a plugin or theme archive no longer creates a pending change.** Uploading one
  was already ignored; deleting one was not, so a ZIP could still appear in the list as
  something to delete from Production — which had never been given it in the first place.
* **Existing entries of both kinds are removed on upgrade.** Only pending ones; anything
  already deployed or ignored is left as the record it is.
* **Every action now reports itself the same way.** Rollback, Push, Ignore, Sync IDs, Clear
  History and Clear Log each used to refresh the screen on their own schedule, which meant
  some results were wiped from view a moment after appearing — rollback among them. All of
  them now show the same message, in the same place, and it survives the refresh.

= 0.7.0 =
A much quieter API log, a callers list that survives clearing, and a warning that no
longer cries wolf.

* **Accepted API requests are no longer listed individually.** Every step of a deploy is
  an API call, so a working pair was writing several "accepted" rows per push, for ever.
  Only first sightings of an address and rejected requests are listed now — a list where
  every entry reads "fine" is where real failures go to hide. Nothing is lost: accepted
  requests are still counted against their address.
* **The list of calling addresses is kept separately and survives Clear.** Clearing the
  request list no longer wipes the record of which machines push to this site. Each
  address can be removed individually with **Forget**, which also deletes its requests.
* **Fixed a warning on every deploy.** Pushing a page could log "Removed disallowed
  markup" while removing nothing at all: the content filter rebuilds every tag it keeps,
  and the resulting harmless change in formatting was being reported as a removal.
  Anything genuinely removed is now named exactly — the attribute or tag, rather than
  "disallowed markup".

= 0.6.0 =
Plugin and theme archives are no longer tracked, and results are shown as toasts.

* **Uploading a plugin or theme ZIP no longer creates a pending change.** Archives and
  executables are not content, and IFS Deploy deliberately never deploys code — so a
  deploy must not be able to carry a plugin installer to the live site. Images, PDFs,
  video and audio are unaffected. A site that genuinely publishes a downloadable archive
  can allow it again with the `ifs_deploy_track_attachment` filter.
* **Success and error messages are now toasts.** They used to be a standard admin notice
  at the top of the panel, which meant an action taken from halfway down a long list
  reported itself off-screen — and the actions that reload the page destroyed the notice a
  moment after it appeared. Messages now appear against the corner of the screen wherever
  you are scrolled to, and survive the reload.
* Errors stay until dismissed; only success messages fade on their own. Escape clears
  them, and each carries the right screen-reader role so failures are announced rather
  than missed.

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

= 0.12.0 =
The Action column now names the real action, plugin ZIPs stay out of Pending Changes, and
the push progress dialog works. Includes a database upgrade, which runs itself. Update both
sites together.

= 0.11.1 =
Fixes media rollback not being recorded, and removals that could not find their target.
**Install on Production too** — every fix in this release runs on the receiving side.

= 0.11.0 =
Trashing media no longer destroys it on Production, and media that cannot keep its ID says
so instead of changing it quietly. **Both sites must be updated** — Production is the side
that decides how a removal is applied, and the new ID check needs a route it does not yet
have. No database upgrade.

= 0.10.0 =
Deleting content now syncs to Production, permission refusals explain themselves, and the
push dialog reports its own progress. Includes everything since 0.2.0, so update both sites
together; the database upgrade runs itself.

= 0.3.0 =
Fixes duplicate Pending Changes entries and adds a change preview for media. Includes
everything in 0.2.0, so update both sites together; the database upgrade runs itself.

= 0.2.0 =
Correctness fixes to deleting, reverting and matching content. Update both sites
together; the database upgrade runs itself.

= 0.1.0 =
First release. Install on both sites and pair them from Settings.
