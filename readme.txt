=== Copperleaf Deploy ===
Contributors: SET_YOUR_WORDPRESS_ORG_USERNAME
Tags: deployment, staging, content, acf, rollback
Requires at least: 6.5
Tested up to: 6.5
Requires PHP: 8.0
Stable tag: 0.14.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Push content from a Staging site to Production over a signed connection. Review what changed, deploy what you choose, roll back if it was wrong.

== Description ==

Copperleaf Deploy moves **content** between two WordPress sites — pages, posts, custom post
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

1. On the **live** site, open Copperleaf Deploy → Settings, set the role to **Production**, and
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

= Can I limit Compare & Sync, Settings and Logs to certain people? =

Yes. Add this to `wp-config.php` on the site you want to restrict, listing the user IDs that
should keep access:

`define( 'IFS_DEPLOY_ADMIN_USERS', '1,7' );`

Those three screens then appear only for the users named. Every other administrator keeps
Overview, Pending Changes and Deployment History, and can still push and roll back their own
work — the restriction hides three screens, it does not remove anyone from the plugin.

It goes in `wp-config.php` rather than a file inside the plugin because the plugin folder is
replaced on every update, which would silently lift the restriction. Being outside the
database also means restoring a backup cannot rewrite the list.

The list only ever narrows: a user must still be an administrator *and* be named. Adding
somebody who is not an administrator gives them nothing.

If the constant is absent — or set to something with no usable IDs — every administrator
keeps the screens, exactly as before, and a note is written to the event log. That is
deliberate: the alternative would lock everyone out of the plugin's own Settings screen with
no way back through wp-admin.

To keep the list somewhere else, such as an mu-plugin, use the `ifs_deploy_admin_users`
filter instead.

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

= 0.14.0 =
Compare & Sync now reads in the order you need it, and the plugin can be kept out of the way
of people who should not be changing how deployment works.

* **Compare & Sync is reordered.** The groups run *Not on Production → Only on Production →
  Different → In sync*: first the groups where the two sites disagree about what **exists**,
  then the one where they disagree about **content**, then the one where they agree and
  there is nothing to do. The summary cards follow the same order.
* **The summary cards are now jump links.** Click one and the page scrolls to that group. A
  card is only clickable when its table is actually on the page, so a card reading 0 stays a
  plain box rather than a button that does nothing.
* **"In sync" no longer renders thousands of rows nobody reads.** It starts at 10 and
  **Show more** reveals 50 at a time. The rows are already in the page, so revealing them
  makes no request and does not re-run the comparison against Production.
* **Compare & Sync, Settings and Logs & Diagnostics are now restricted by default.** Add
  `define( 'IFS_DEPLOY_ADMIN_USERS', '1,7' );` to `wp-config.php` and only those user IDs
  can reach them — everyone else is refused, including by direct URL. **Leave the constant
  out and nobody can reach them**, on Staging or Production. Being an administrator is no
  longer enough on its own; that is the whole point of the setting. It lives in
  `wp-config.php` rather than in a settings screen precisely so it cannot be changed from
  inside WordPress.
* **The restriction explains itself rather than just hiding.** An administrator who cannot
  reach those screens is shown the exact line to add to `wp-config.php`, with their own user
  ID already filled in, on the plugin's screens and on the Plugins list. Nobody has to know
  the constant's name or read the documentation to get back in. If a list already exists,
  the suggested line **adds** them to it rather than replacing it.
* **Being restricted is not being removed from the plugin.** Overview, Pending Changes and
  Deployment History are unaffected, and pushing and rollback keep working exactly as
  before — so a site that updates without adding the constant does not lose deployment.
* **The plugin is now called Copperleaf Deploy.** This is a display change only — the
  name in the plugin list, the sidebar menu and the screen heading. Nothing underneath
  moved: the text domain, REST namespace, admin page slug, option keys and database tables
  are all unchanged, so an existing paired installation keeps working across the update and
  no reconnection is needed.
* **The sidebar menu is hidden on Production.** The live site is not where deployments are
  driven from, so the menu is out of the way there. Settings is still reachable through
  **Plugins → All Plugins → Copperleaf Deploy → Settings** for anyone allowed to use it. Nothing
  about the Staging workflow changes — an Editor still edits and pushes exactly as before.

= 0.13.0 =
New content can now arrive unpublished, so somebody on the live site decides when it goes
public.

* **New setting: the status new content arrives with.** On the Production site, Settings now
  offers "Status for new items" — Draft, Pending Review, Private, or any other status
  registered on that site. Choose one and a page pushed for the first time waits there
  instead of going live the moment it lands. The default is unchanged: content arrives
  exactly as it is on Staging.
* **It applies to new content only.** A page that already exists on Production keeps the
  status it has. Pushing an edit to it does not republish it, and does not unpublish it
  either — so a page you deliberately published on the live site stays published, however
  many content edits follow.
* **Unless the status was deliberately changed on Staging.** That is a change like any other
  and is applied normally. The two cases are told apart by what Staging said last time, not
  by comparing the two sites — once the setting is in use they are meant to differ.
* **The setting lives on Production, where it belongs.** The live site decides its own
  publishing; a policy set on Staging would mean the sending site choosing when Production
  publishes. Staging shows a note saying where to find it.
* **Falls back to Draft** if the chosen status cannot be used for a particular post type,
  rather than falling back to publishing it.
* Line endings are now pinned to LF across the repository, which fixes a set of checks that
  passed or failed depending on how a file had last been saved.

= 0.12.5 =
Cancelling a push is now reliable, including when Production is slow.

* **A push that timed out could go live after being cancelled.** When Production takes
  longer than the sending site waits — a batch of media routinely does — the request fails
  on this side while Production carries on and applies everything in it. That case did two
  harmful things: it never told Production to undo what it had applied, and it recorded the
  push as *failed*, writing over the fact that it had been *cancelled*. So the push was
  cancelled, published anyway, and no longer said it had been stopped. This is the reason a
  cancelled push could still leave a change on the live site.
* **A cancelled push can no longer be re-recorded as anything else.** Once stopped, it stays
  stopped, so nothing later mistakes it for a push still in progress.
* **Cancelling now confirms the live site is clean instead of assuming it.** "Nothing left to
  undo" can also mean "the batch has not arrived yet", so the check is repeated until two
  passes in a row come back clean. If that cannot be confirmed, it says so rather than
  reporting success.
* **Cancelled items stay in Pending Changes.** After a cancel the list re-checks itself
  against Production, and a revert still in progress could make an item look already
  published — removing the very change the cancel had just rescued. Cancelled items are now
  left on the list so they can be pushed again.
* **The dialog says what it is doing while it cancels.** It kept showing the push progress
  bar, which read as the push carrying on. It now says it is undoing, and asks you to keep
  the tab open while it finishes.

= 0.12.4 =
Push several items straight from Compare & Sync, and a cancel that is now guaranteed to
leave nothing behind.

* **Compare & Sync can push several items at once.** Each list has tick boxes and a
  select-all, so a set of pages can be sent together instead of one row at a time — with the
  same progress window, item-by-item, and the same Cancel.
* **The confirmation says what will actually happen.** These lists are everything that
  *differs* between the two sites, which can include a page edited directly on Production —
  so the dialog now warns that those edits will be replaced. Creating pages that do not exist
  on Production yet is worded differently, because nothing is overwritten.
* **Cancelling a push now guarantees nothing is left applied.** The cancellation was recorded
  only after the round trip to Production had finished, and that request takes seconds — so a
  batch completing inside that window read the push as still running and went live anyway.
  Pressing Cancel could leave part of a push published. The cancellation is now written down
  before anything else happens, which closes the window entirely.
* **And it now confirms Production is clean rather than assuming it.** A batch that lands
  while the undo is in progress creates new changes behind it, so the undo is repeated until
  Production reports there was nothing left to reverse. If that cannot be confirmed, the
  result says so instead of reporting success.

= 0.12.3 =
Rolling back now follows the same permission rule as pushing.

* **A deployment can only be rolled back by the person who pushed it, or an administrator.**
  Pushing has always been the creator's own act — you can push your own changes, and only an
  administrator can push everyone's. Rolling back had no such rule: holding the rollback
  permission was enough to undo *anyone's* deployment, including one the same user would not
  have been allowed to push in the first place. A rollback restores older content over live
  pages, so if someone may not publish a colleague's work they should certainly not be able
  to unpublish it.
* **Deployment History says why a Rollback button is missing** — it names the person who
  pushed it, instead of showing an unexplained dash.
* **The rollback preview is covered by the same rule.** It shows the previous contents of the
  pages involved, so it is not something to render for a deployment you may not undo — and a
  dialog that fills with detail and only then refuses is worse than one that never opens.
* Being able to *see* everyone's changes still does not grant the ability to act on them.
  Seeing and publishing are separate permissions and stay separate.
* `SECURITY.md` rewritten as a short, readable summary of what was found and fixed.

= 0.12.2 =
Cancelling a push no longer loses the changes it was meant to keep.

* **Cancelling a push no longer removes items from Pending Changes.** Pressing Cancel stops
  the browser sending the NEXT batch, but a batch already on its way could not be stopped —
  the server went on applying it, and marked its items as pushed a moment after the cancel
  had put them back on the list. Those items then disappeared. Cancelling a push has never
  meant discarding the work, and now it does not behave as though it did.
* **A batch that arrives after a cancel is undone on Production too.** It landed after the
  rest of the push had already been reverted, so its changes were live with nothing left to
  say so.
* **A push cancelled before its first batch lands is recorded.** Previously it left no entry
  in Deployment History at all.
* **Cancelling no longer fails on a permission check.** It could stop with a message about
  rows belonging to someone else, before returning anything to the list — the exact outcome
  cancelling exists to prevent.
* **A spinner while a screen loads.** Switching tabs, and Compare & Sync in particular,
  fetches from Production and can take a few seconds; the screen simply dimmed and gave no
  sign it was working.
* Plugin author and URI headers now name IFS Copperleaf rather than placeholders.

= 0.12.1 =
Pushes are recorded in Deployment History again, and there is a way to clear the plugin's
data without uninstalling it.

* **A push now appears in Deployment History.** Pushing from Pending Changes recorded
  nothing at all — only the per-row Push on the Compare screen ever created a history entry.
  Since that is where almost everything is pushed from, the screen stayed empty however much
  was deployed. Rollback went with it: the Rollback button is offered from a history row, and
  there was no row, so a push made this way could never be undone from the Staging side.
  A push split into several batches is recorded as ONE deployment, not one per batch.
* **A push that partly failed says so.** The status is worked out across the whole push
  rather than taken from its last batch, so a deployment where some objects landed and others
  did not is reported as Partial — and stays rollback-able, because the restore points for
  the objects that did land are real.
* **Cancelling marks the deployment cancelled** on this side too, instead of leaving it
  offering a Rollback button that would re-apply exactly what the cancel had just undone.
* **New: Reset All Plugin Data**, under Settings → Log Retention. Clears every pending
  change, the deployment history, all restore points, both logs, and the deployment stamps
  on your content — which is what makes the next push behave like a first push, and what
  made repeat testing impossible before. No page, post, image, category or setting of your
  site is touched. It applies to this site only, and the connection is kept unless you ask
  for it to go. Administrators only.

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
  executables are not content, and Copperleaf Deploy deliberately never deploys code — so a
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

= 0.14.0 =
Reorders Compare & Sync, makes the summary cards jump to their tables, and loads the "In
sync" list 50 rows at a time instead of all at once. IMPORTANT: Compare & Sync, Settings and
Logs & Diagnostics are now restricted to user IDs named in a new wp-config.php constant,
and are hidden from everyone until you add it — on both sites. Add
define( 'IFS_DEPLOY_ADMIN_USERS', '1,7' ); before updating, or the plugin tells you how on
screen afterwards. Pending changes, pushing and rollback are unaffected. Also hides the
sidebar menu on Production. No database upgrade. Update both sites together.

= 0.13.0 =
Adds a Production-side setting for the status new content arrives with, so it can wait for
review instead of publishing itself. Existing content is unaffected. No database upgrade.
Update both sites together.

= 0.12.5 =
Fixes a cancelled push still going live when Production is slow, and keeps cancelled items in
Pending Changes. No database upgrade. Update both sites together.

= 0.12.4 =
Adds multi-select pushing on Compare & Sync, and fixes a case where cancelling a push could
still leave part of it published. No database upgrade. Update both sites together.

= 0.12.3 =
Rolling back now requires being the person who pushed it, or an administrator — matching how
pushing already worked. No database upgrade. Update both sites together.

= 0.12.2 =
Fixes cancelling a push removing items from Pending Changes. No database upgrade. Update
both sites together.

= 0.12.1 =
Pushes are recorded in Deployment History again, which also restores the Rollback button for
them. Adds Reset All Plugin Data for testing. No database upgrade. Update both sites
together.

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
