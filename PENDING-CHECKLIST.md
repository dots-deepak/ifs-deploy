# IFS Deploy — Checklist

**Last build:** `ifs-deploy-0.12.3.zip` · DB v10 · 2111 tests green · 18 August 2026
**Working tree is ahead of that build** — see the Pending item.

Priority is **1–10**, where 10 must happen first.

> Everything else on the live pair has been tested and confirmed working. **One item is
> left**, and it needs a build first — the fix for it is not in `ifs-deploy-0.12.3.zip`.

---

## 🔴 Pending — needs testing on your live pair

- **(8) Cancel mid-push.** Start a push of ~10 items and press **Cancel** while it runs.
  Expect four things together:
  - **every row stays in Pending Changes** — a cancel must not throw work away,
  - anything already sent is reverted on Production,
  - Deployment History shows **one** row marked *Cancelled*, not a deploy plus an undo,
  - pushing those same rows again straight afterwards works normally.

  Press Cancel **while a batch is mid-flight**, not in the gap between two — that is the
  case that was broken, twice over. A batch already on the wire kept running and marked its
  rows deployed *after* the cancel had put them back; and the cancellation was written down
  only after the round trip to Production finished, so a batch completing inside that window
  read "not cancelled" and went live anyway. Both fixed in the working tree; not yet in any
  build.

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
  and point `-c` at `tailwind.config.js`. Only needed when editing `admin.src.css` —
  `admin.css` is committed already built, so nothing else requires it.
- **Both sites must run the same version.** `/cancel` and `/id-space` are REST routes an
  older side answers 404 to, and the removal intent is read on the receiving side.
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
