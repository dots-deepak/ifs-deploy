# Copperleaf Deploy — Checklist

**Last build:** `ifs-deploy-0.14.0.zip` · DB v10 · 2219 tests green · 11 September 2026
**The working tree and the build match.** Everything below is in that zip and testable now —
install it on **both** sites before you start.

Priority is **1–10**, where 10 must happen first.

---

## 🔴 Pending — needs testing on your live pair

- **(8) Cancel mid-push.** Start a push of ~10 items and press
  **Cancel** while it runs. Expect four things together:
  - **every row stays in Pending Changes** — a cancel must not throw work away,
  - anything already sent is reverted on Production,
  - Deployment History shows **one** row marked *Cancelled*, not a deploy plus an undo,
  - pushing those same rows again straight afterwards works normally.

  Press Cancel **while a batch is mid-flight**, not in the gap between two — that is the
  case that was broken, twice over. A batch already on the wire kept running and marked its
  rows deployed *after* the cancel had put them back; and the cancellation was written down
  only after the round trip to Production finished, so a batch completing inside that window
  read "not cancelled" and went live anyway. Both were fixed in 0.12.5, so the build you
  already have carries the fix — this is the last piece of that work still unconfirmed on a
  live pair.

- **(8) Status for new items.** On **Production**, set
  Settings → *Status for new items* → **Draft**. Then:
  - push a page that does **not** exist on Production yet — it should arrive as a Draft,
  - push an edit to a page already **published** on Production — it must **stay published**,
  - change a page's status on Staging deliberately and push — that change applies as normal.

- **(9) Restricted admin screens.** Put this in `wp-config.php` on **both** sites:

  ```php
  define( 'IFS_DEPLOY_ADMIN_USERS', '1,7' );
  ```

  **Add it before you update, on both sites.** Without it nobody can reach Compare & Sync,
  Settings or Logs & Diagnostics — that is deliberate, not a bug. The screens are now
  *deny by default*: being an administrator is no longer enough on its own, which is the
  whole point of the setting.

  Then check:
  - as a user **in** the list, all three screens still work,
  - as a user **not** in the list, all three are gone from the tabs and typing their URLs
    directly is refused,
  - that same user still has Overview, Pending Changes and History, and can still push and
    roll back — the restriction hides three screens, it does not remove them from the plugin,
  - with the constant **missing**, an administrator sees a notice giving the exact line to
    add with their own user ID already in it, both on the plugin screens and on
    **Plugins → All Plugins**.

  That notice is the only way back in, since Settings is itself one of the hidden screens —
  so it is worth confirming it actually appears on your Production site, where the sidebar
  menu is hidden too and the Plugins list is the only page you are sure to pass through.

- **(6) Compare & Sync reads top to bottom.** The four groups are now ordered *Not on
  Production → Only on Production → Different → In sync*: the groups where the two sites
  disagree about what **exists**, then the one where they disagree about **content**, then
  the one where they agree and there is nothing to do. The summary cards follow that same
  order.

- **(6) Clicking a summary card jumps to its table.** A card is clickable only when its
  table was actually rendered, so a card reading 0 stays a plain box rather than becoming a
  control that silently does nothing.

- **(5) In sync loads 10 rows, then 50 at a time.** On a site with hundreds of synced pages,
  check the screen opens quickly and that **Show more (N hidden)** reveals the rest in
  blocks of 50 — without reloading the page and without re-running the comparison against
  Production. *Only on Production* is deliberately not limited, by your call.

- **(5) The Copperleaf Deploy menu is hidden on Production.** On the Production site the sidebar
  entry is gone; Settings is reached through **Plugins → All Plugins → Copperleaf Deploy →
  Settings**. Check that an Editor on **Staging** can still edit a page and push it exactly
  as before — nothing about that workflow changed.

---

## 🟠 Blocked — waiting on your decision

- **(3) `readme.txt` still says `Contributors: SET_YOUR_WORDPRESS_ORG_USERNAME`.** A
  wordpress.org-only field that means nothing on a client install, but it is a visible
  placeholder. Give me a username, or say the word and I will drop the line — this plugin is
  not being submitted to wordpress.org.

---

## ✅ Done — verified working on your live pair

**Media**
- Trashing media on Staging trashes it on Production (not destroyed).
- Permanent delete destroys it on both sides.
- Trashed items stay findable, so emptying the Trash afterwards works.
- Restoring from Trash syncs to Production.
- Large media pushes without hitting a memory limit.
- New media keeps its Staging ID, or refuses and names what is occupying it.
- "Generate new ID" offered only when nothing references the file yet.
- Plugin/theme ZIPs never appear in Pending Changes.
- Add-then-delete before pushing leaves no row behind.
- WPML duplicates merged into one row.
- Media tracking overall.

**Pushing**
- Small batched push with a working progress bar, phase, item name and estimate.
- Deployment History records the push, with a working Rollback button.
- Rollback of newly created pages and media.
- Editor permission refusals explain themselves.

**Elsewhere**
- Rollback permissions — only the person who pushed it, or an administrator, can undo it.
- Action column names the real action (Trashed / Restored / Updated / Published).
- Settings save uses the plugin's own toast.
- Duplicate Pending Changes entries.
- "View changes" for media.
- Object ID and file name on each row.

---

## ⚠️ Things to know

- **Rebuilding the CSS** needs the Tailwind standalone binary, which is not in this repo
  (38 MB, build tool, never shipped). Download `tailwindcss-windows-x64.exe` **v3.x** from
  https://github.com/tailwindlabs/tailwindcss/releases, keep it anywhere outside the repo,
  and point `-c` at `tailwind.config.js`. Without it, `npx tailwindcss@3.4.19 -c
  tailwind.config.js -i assets/css/src/admin.src.css -o assets/css/admin.css --minify`
  does the same job — that is how 0.14.0 was built. The run prints *"No utility classes were
  detected"*; that is **expected**, not a failure, because every utility here is applied
  through `@apply`. Only needed when editing `admin.src.css` —
  `admin.css` is committed already built, so nothing else requires it.
- **The plugin is now displayed as Copperleaf Deploy.** Name only — the folder is still
  `ifs-deploy`, and so are the text domain, REST routes, option keys and database tables.
  An existing pair keeps working across the update with no reconnection.
- **Both sites must run the same version.** `/cancel` and `/id-space` are REST routes an
  older side answers 404 to, and the removal intent is read on the receiving side.
- **No `IFS_DEPLOY_ADMIN_USERS` means nobody, not everybody.** The three screens fail
  *closed*. A restriction that has to be switched on is one that is off wherever the line
  was forgotten, lost in a wp-config rewrite, or restored from an older copy of the file —
  failing open at exactly the moment it was meant to apply.
- **`IFS_DEPLOY_ADMIN_USERS` is not a security boundary against a determined administrator.**
  Anyone who can edit `wp-config.php`, install a plugin, or run code on the server can undo
  it. It exists to keep deployment settings out of the way of colleagues who should not be
  changing them, not to contain someone working against you.
- **Old deployments will never appear in History.** They were never recorded — there is no
  data to recover. History starts from the first push after installing 0.12.1.
- **Reset All Plugin Data** (Settings → Log Retention) clears this site's pending changes,
  history, restore points, logs and deployment stamps. Run it on both sites for a clean
  pair. It never touches content, and it is admin-only.
- **Locks are per batch, not per push.** A conflict may surface part-way through a push
  rather than before anything is sent.
- **If a media removal ever fails again:** on **Production**, open Logs & Diagnostics and
  find `Media removal matched nothing on this site`. It records everything the matcher
  searched for — send me that line.
- **WPML still creates several attachment records per file.** The plugin merges them so you
  see one row, but the duplicates remain in the Media Library. Fixable in WPML → Settings →
  Media Translation.
