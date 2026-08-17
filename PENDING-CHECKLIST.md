# IFS Deploy — Pending Checklist

Living document. Updated as items are finished; **nothing is ticked here until it has been
verified**, and "the code is written" is not verification. Two ticks exist on purpose:

- **Code** — written, unit-tested, suite green.
- **Verified** — actually observed working on the live Staging/Production pair.

> **Why the split matters.** Every test here is unit-level: they stub WordPress rather than
> loading it, and nothing in them exercises a real signed round-trip between two installs.
> A green suite means the logic is what it claims to be — not that the deploy works.
>
> The media-trash gap below is the case in point. The suite was green the whole time it
> existed, because the tests stubbed the same hook the code listened to. What found it was
> someone saying how their site actually behaves.

Last updated: 14 August 2026 · Built and ready to test: **0.11.1** · DB v9 ·
1973 assertions green

---

## 🔴 Needs verifying on the live pair

### Who does this

**The site owner, not the developer.** Verification means installing the build on the real
Staging/Production pair and watching what happens — two WordPress installs, a signed
connection between them, real media, real user accounts. None of that is reachable from
where the code is written, so nobody else can tick these.

What *is* done for you: every item below is written, reviewed and covered by the suite, and
each one has an exact procedure and an exact expected result, so verifying is a checklist
rather than an investigation. **When something does not match, send the message it showed
and the Logs & Diagnostics entries** — the plugin now names its own failures, so the report
is usually enough to find the cause without a second round trip.

**The build is ready:** `ifs-deploy-0.11.1.zip`. Install it on **both** sites before
starting — several of these are decided on the receiving side.

### 🟠 If a media removal still does not reach Production

Two reported faults were traced to the receiving site failing to *find* the media, which
produces both symptoms at once: nothing is removed, **and** no restore point is recorded, so
no Rollback button appears. Four defects behind that are fixed in 0.11.1.

If it happens again, the cause is now recorded rather than guessed. On **Production**, open
IFS Deploy → Logs & Diagnostics and find `Media removal matched nothing on this site`. It
lists the origin id, the origin site, the source URL and filename that were searched for,
whether that id is occupied locally, and what the local file is actually called. Comparing
those four values against the media item on Production identifies which of the three match
strategies broke, which is what the single message could never tell anyone.

### The order matters

Each one is a prerequisite for trusting the next: if batching is broken, a cancel test
tells you nothing, and if pushing is broken, a rollback test cannot be interpreted.

- [x] Code · [ ] Verified — **1. Small batched push.** Select 2–3 items, Push Selected.
      *Expect:* the progress dialog shows a moving bar, "n of N items", a phase line, the
      item name, and a time estimate after the first batch. The items appear on Production
      and leave Pending Changes.
- [x] Code · [ ] Verified — **2. Cancel mid-push.** Start a push of ~10 items and press
      Cancel while it runs. *Expect:* anything already sent is removed from Production, and
      **every row is still listed in Pending Changes** — a cancel must not throw work away.
      Deployment History shows ONE entry marked *Cancelled*, not a deploy plus an undo.
- [x] Code · [ ] Verified — **3. Rollback of newly created content.** Push a brand-new page,
      then roll that deployment back. *Expect:* the page goes to **Trash** on Production
      (recoverable). Repeat with a new media item — that one is removed. Before this, new
      content had no Rollback button at all.
- [x] Code · [ ] Verified — **4. Large media push.** Push the biggest image you have.
      *Expect:* it arrives. The file is no longer read into PHP memory, so its size should
      no longer be the limit.
- [x] Code · [ ] Verified — **5. Deletion sync — all four paths.** They go through different
      hooks and only one of them was ever working:
  - [ ] Trash a page → push → *expect it trashed on Production*
  - [ ] Permanently delete a page → push → *expect it gone*
  - [ ] Move media to Trash → push → *expect it trashed on Production, not destroyed*
  - [ ] Permanently delete media → push → *expect it gone*

      If any of them reports *"never deployed here"* or *"use Sync IDs"*, that is the new
      honest failure rather than the old silent one — send the wording.
- [x] Code · [ ] Verified — **6. Editor permissions.** Log in as the Editor, select an
      administrator's rows, and try **Push Selected**, **Push All** and **Ignore**.
      *Expect:* a clear permission message every time — never silence, never "Nothing is
      selected". Then select a mix of your own and someone else's: the confirm dialog should
      say how many will be excluded **before** you confirm.
- [x] Code · [ ] Verified — **7. Settings save.** Save anything on the Settings screen.
      *Expect:* the plugin's own toast, not the grey WordPress notice.
- [x] Code · [ ] Verified — **8. WPML media.** Edit one image's title or alt text.
      *Expect:* exactly **one** pending entry, not one per language.
- [x] Code · [ ] Verified — **9. Media trash reaches Production's trash.** Move an image to
      Trash on Staging and push it. *Expect:* it is in Production's **Media → Trash**, not
      gone. Then permanently delete it on Staging and push again — *expect it destroyed on
      Production*, and **not** reported as "nothing matched".
- [x] Code · [ ] Verified — **10. Media ID conflict.** Upload a new image on Staging whose
      ID is already taken on Production, and push it. *Expect:* the push stops with the
      **Media ID mismatch** dialog naming the ID and what is occupying it — no file is
      uploaded. If the image is not used anywhere on Staging yet, the dialog offers
      **Generate new ID**; take it, push again, and the IDs should now match on both sites.
      Then repeat with an image that IS used on a page: *expect the dialog to refuse and
      name the page* rather than offering the button.

### ⚠️ Media IDs — what was actually wrong

**The IDs were never un-synced.** `MediaImporter` has always asked for the Staging ID via
`import_id`, so ID 1 becomes ID 1 on Production whenever ID 1 is free there. What it did
when the ID was *taken* was the bug: it let WordPress allocate a fresh one and said nothing.
So the mismatch was real, occasional, and completely invisible — and it is not cosmetic,
because this importer copies meta verbatim and ACF fields, galleries, `wp-image-N` classes
and Gutenberg block attributes all store the bare number.

Note that `get_post()` checks *every* post type: a **page** at ID 1 on Production is enough
to push an image to a different ID.

Three things changed, and the third is the one that needed a decision:

1. **New media whose ID is taken is refused**, before the file is downloaded, naming what
   occupies the ID. Only media that has never reached Production can be refused — anything
   already there is matched by source URL or origin stamp long before the check.
2. **Staging is offered the fix**, because Production cannot make the ID free. The dialog
   asks first and offers the button only when the file is referenced nowhere.
3. **Renumbering is refused whenever anything at all uses the file.** WordPress has no API
   for changing a post ID, and the references live in post content, postmeta, options, term
   relationships and every page builder's own storage — a list that can never be complete,
   with a silently broken image as the failure mode. A file you just uploaded and have not
   placed yet is the case this serves, and it is the common one.

### ⚠️ Deletion sync — what was actually wrong

Four separate problems, found in this order. The first two are why deletions did not
reflect; the third is why nobody could tell; the fourth is why media was destroyed instead
of trashed.

**1. Trashing overwrote its own delete row.** Core's `wp_trash_post()` runs:

```php
do_action( 'wp_trash_post', $id );                 // → on_delete, row action 'delete'
wp_update_post( [ 'post_status' => 'trash' ] );    // → save_post, row action 'update'
```

`is_trackable()` did not exclude `trash`, so the save that trashing itself performs
replaced the delete row a moment after it was written. The queue then held an **update**
carrying a package whose status happened to be `trash`. Pushing it took the update path:
where the object matched it was trashed and looked like it had worked, and where it did
**not** match, the importer did what an update does with an object it cannot find — it
**inserted** one. Deleting a page on Staging could create a trashed copy of it on
Production.

**2. The delete package could not identify the object.** It carried only the title and
post type. `PostImporter::locate()` tries origin stamp → id parity → slug; with no slug the
third strategy could never run and the second had nothing but the title to corroborate
itself with. A page renamed before deletion, or one on a site that was cloned rather than
deployed to, could not be found at all. The slug and publish date now travel with it —
including the `_wp_desired_post_slug` handling, because `wp_trash_post()` renames the slug
to `{slug}__trashed` and sending *that* would be worse than sending nothing.

**3. A delete that matched nothing reported SUCCESS.** It returned quietly, so
"Deployment complete" appeared while the page stayed live. It now says which of the two
situations it is — never deployed here, or needs linking with Sync IDs.

**4. Media moved to Trash was not tracked at all.** Found once the site's `MEDIA_TRASH`
setting came up. `wp_delete_attachment()` begins:

```php
if ( ! $force_delete && MEDIA_TRASH && EMPTY_TRASH_DAYS ) {
    return wp_trash_post( $post_id );
}
do_action( 'delete_attachment', $post_id );
```

So on a site where removing media means moving it to Trash, the hook this plugin listened
to **never fired**. No queue row, nothing to push, and nothing on screen to suggest
anything was missing. `wp_trash_post` is now watched as well — it fires for every post
type, so the observer checks the type itself — and the same guard as posts stops the save
that trashing performs from overwriting the row it just wrote.

That also removed the limitation noted here before: a **trashed** attachment still exists,
so its source URL and filename now travel with the removal, and the far side can match on
the recorded source URL rather than depending on an origin stamp. A permanently deleted one
is gone and still relies on the stamp — reported honestly either way.

**5. And then Production destroyed it anyway.** The removal was sent as "remove this" and
the receiving site decided what that meant — `wp_delete_attachment( $id, false )`, which
trashes where `MEDIA_TRASH` is on and deletes where it is not. The reasoning was that a
deploy should not impose one site's settings on the other. It was wrong, because:

```php
// wp-includes/default-constants.php
if ( ! defined( 'MEDIA_TRASH' ) ) {
    define( 'MEDIA_TRASH', false );
}
```

**`MEDIA_TRASH` defaults to false.** Staging had it switched on, Production had not, so
`force = false` fell straight through to a permanent delete — a file trashed on Staging in
the expectation of being able to restore it was destroyed on the live site, and the deploy
reported success. Recoverability is a property of the action the operator took, not of the
receiver's `wp-config.php`, so the intent now travels with the package: trash means
`wp_trash_post()` there regardless of that setting, and a permanent delete forces past the
trash for the same reason in reverse.

Fixing that exposed a sixth fault immediately. `post_status => 'inherit'` is what a *live*
attachment has, so once this plugin started genuinely trashing things on Production, it went
blind to its own handiwork: the follow-up permanent delete reported "nothing matched, it was
probably never deployed here" about a file it had trashed minutes earlier, and re-uploading
created a duplicate at a fresh ID. Both matchers now look in the trash as well — and both
have to name `'trash'` explicitly, because WP_Query's `'any'` is built by *excluding* every
status flagged `exclude_from_search`, which is exactly what trash is.

---

## 🟡 Half-built

*(Nothing at present.)*

---

## 🔵 Blocking the verification above

- [x] ~~Build 0.10.0 / 0.11.0~~ — superseded by 0.11.1, which is the one to test.
- [x] **Build the release zip** — `ifs-deploy-0.11.1.zip`, 99 files, 418 KB. DB stays at 9,
      so nothing migrates and it can be installed straight over the top.
- [ ] **Install it on BOTH sites.** Two reasons now: `/cancel` (new in 0.9.0) and
      `/id-space` (new in this build) are REST routes, so an older Production answers 404
      to them — the cancel test and the "Generate new ID" button would both fail for that
      reason rather than a real one. The removal intent is also read on the receiving side,
      so an un-updated Production keeps destroying media that Staging only trashed.

---

## 🟢 Decisions for the owner

- [ ] **`tools/tailwindcss.exe` (38 MB)** — copied into the repo so the CSS can be rebuilt
      without depending on the `deploypress` folder. Excluded from the release zip by
      `.distignore`, but heavy to commit to git. Keep or remove?
- [ ] **Nothing is committed to git.** Every change is in the working tree.
- [ ] **`Author` and `Plugin URI` headers** are still placeholders. The build warns about
      them and continues, deliberately — they break nothing on a client install.

---

## ⚠️ Standing constraints

- **Both sites must be updated together.** `/cancel` is a new REST route; the older side
  would answer 404. The REST namespace and the `X-IFS-Deploy-*` headers are wire format and
  the protocol negotiation does not cover them.
- **Locks are now per batch, not per push.** Two people still cannot have the same object in
  flight at once. What changed is that a conflict may be discovered part-way through a push
  rather than before anything is sent.
- **Something on Staging creates several attachment records per file** (WPML media
  duplication). The plugin merges them, so it no longer shows as duplicate pending changes —
  but the duplicates are still in the Media Library. Fixable in WPML → Settings → Media
  Translation, or leave it.

---

## ✅ Done and verified by the owner

- [x] Duplicate Pending Changes entries (the missing UNIQUE key) — confirmed fixed on the
      live site.
- [x] Editing one image producing several entries — confirmed; the WPML cause was found from
      the logs and the entries are merged.
- [x] "View changes" for media.
- [x] Object id and file name shown on each row.

---

## ✅ Done and shipped in 0.10.0

Everything from the original seven-item report is here plus the batching work. All of it is
code-green and **none of it is confirmed on a real site yet** — the 🔴 list above is how
each one gets confirmed.

**From the seven-item report:**

- [x] **Large media upload failure** — the file is no longer read into PHP memory.
- [x] **Deletion not syncing** — four separate causes, see the section above.
- [x] **Missing permission error** — a refusal now says so instead of narrowing silently.
- [x] **Rollback missing for media** — and for any newly created object; it was never
      media-specific.
- [x] **Popup not visible enough** — larger, heavier, tinted, and errors stay until
      dismissed.
- [x] **Role-based access & user-specific push** — the creator or an administrator, and the
      exemption is `manage_options` rather than "can see all changes".
- [x] **Error messages for all failures** — including the ones that used to pass as
      successes.

**Added after that:**

- [x] Batched pushes with a real progress bar, phase, current item and a measured estimate.
- [x] Cancel with auto-revert, keeping the rows in Pending Changes.
- [x] One deployment record per push, so rollback covers all of it rather than the last
      batch.

**Fixed from your testing:**

- [x] Editor pressing **Push All** silently doing nothing.
- [x] Editor pressing **Push Selected** being told *"Nothing is selected"* when rows were
      selected.
- [x] Same for **Ignore**, which narrows by ownership identically.
- [x] A partial refusal (some rows yours, some not) is now stated **in the confirm dialog**,
      before anything is pushed.
- [x] **Settings save** uses the plugin's toast rather than a wp-admin notice.
- [x] **Progress dialog finished** — phase ("Uploading media…"), the item being pushed, and
      a time estimate measured per object type rather than averaged flat. Media is the slow
      type and the plan puts it first, so a flat average would have kept promising the slow
      rate for the fast remainder.
- [x] **Deletion sync** — trashing no longer overwrites its own delete row, and the delete
      package now carries the slug and date so the object can actually be found.
- [x] **Media moved to Trash is tracked**, on sites where `MEDIA_TRASH` means "delete"
      trashes rather than removes. It was not tracked at all before.

**Added after 0.10.0 was built:**

- [x] **A trash on Staging is a trash on Production**, whatever `MEDIA_TRASH` says there —
      it defaults to `false`, which is why pushed removals were destroying files.
- [x] **A permanent delete forces past the receiving site's trash**, so the two sites cannot
      disagree in the other direction either.
- [x] **Trashed objects stay findable** by both matchers, so emptying the trash afterwards
      is not reported as "never deployed here".
- [x] **An update restores a file this plugin had trashed**, instead of reporting success
      while the image stays missing.
- [x] **Permanently deleting a page destroys it on Production**, rather than only trashing
      it there — the post-side twin of the media bug.
- [x] **A new attachment whose ID is taken on Production is refused, not silently
      renumbered** — with the ID and the occupant named, before the file is downloaded.
- [x] **Staging offers to move the file to an ID free on both sites**, but only when a
      reference scan proves nothing uses it yet; otherwise it names what does.
