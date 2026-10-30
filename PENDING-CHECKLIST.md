# Copperleaf Deploy — Checklist

**Last build:** `ifs-deploy-0.14.0.zip` · DB v10 · 2219 tests green · 11 September 2026
**The working tree and the build match, and every feature in it is verified on your live
pair.** One documentation decision is open; nothing else is.

Priority is **1–10**, where 10 must happen first.

---

## 🔴 Pending — needs testing on your live pair

**Nothing outstanding.** Every feature built so far has been verified on your pair against
`ifs-deploy-0.14.0.zip`, including the three that had been carried for several builds —
cancel mid-push, the restricted screens, and the default status for new items.

---

## 🟠 Blocked — waiting on your decision

- **(3) `readme.txt` still says `Contributors: SET_YOUR_WORDPRESS_ORG_USERNAME`.** A
  wordpress.org-only field that means nothing on a client install, but it is a visible
  placeholder. Give me a username, or say the word and I will drop the line — this plugin is
  not being submitted to wordpress.org.

---

## ✅ Done — verified working on your live pair

**Access and visibility** *(0.14.0)*
- Compare & Sync, Settings and Logs & Diagnostics restricted to the user IDs named in
  `IFS_DEPLOY_ADMIN_USERS`, and hidden from everyone when the constant is absent.
- The recovery notice appears for a locked-out administrator, giving the exact line to add
  with their own user ID in it — on the plugin screens and on Plugins → All Plugins.
- A restricted user keeps Overview, Pending Changes, History, pushing and rollback.
- The sidebar menu is hidden on Production; Settings reached via Plugins → All Plugins.
- The Staging workflow is unchanged — an Editor still edits and pushes as before.

**Compare & Sync** *(0.14.0)*
- Groups ordered Not on Production → Only on Production → Different → In sync, with the
  summary cards in the same order.
- Clicking a summary card jumps to its table; a card reading 0 stays a plain box.
- "In sync" renders 10 rows, then 50 per click, with no reload and no re-comparison.

**Pushing** *(earlier builds, confirmed on 0.14.0)*
- Cancel mid-push: rows stay in Pending Changes, anything already sent is reverted, History
  shows one Cancelled row, and re-pushing straight afterwards works. Confirmed with a batch
  cancelled **in flight**, which is the case that was broken three times over.
- Status for new items: new pages arrive at the chosen status, pages already published on
  Production stay published, and a deliberate status change on Staging still applies.

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
