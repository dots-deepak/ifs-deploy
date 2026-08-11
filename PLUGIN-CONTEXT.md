# IFS Deploy — Complete Context & Handoff

> Purpose of this file: give a fresh chat/developer the *full* context of what
> IFS Deploy is, how it's built, every decision made so far, what works, and
> what's deferred — so work can continue without re-reading the whole history.

---

## 1. What it is

IFS Deploy is a WordPress plugin that deploys **content** (not database dumps)
from a **Staging** site to a **Production** site over a secure, signed REST
connection. Think "git push, but for WordPress content."

Core promises:
- Track content changes on Staging automatically.
- Review pending changes and push selected items to Production.
- Never overwrite Production via raw SQL — only WordPress APIs.
- Every deploy is reversible (pre-deploy snapshots + one-click rollback).
- Never touch transactional data (users, orders) or file/server config.

The same codebase installs on **both** sites. A site's **role** (staging vs
production) is a runtime setting, not a separate build.

- **Staging** = source. Runs change-detection hooks, pushes content.
- **Production** = destination. Exposes REST endpoints, receives + applies content.

**Environment (current):**

- Repo / working dir: **`d:\Projects\Wordpress\IFS [22020]\deploypress`** — still the old
  folder name, deliberately: it is a git checkout and renaming it would break the clone. The
  **release zip writes `ifs-deploy/` as the internal folder** regardless (§29), which is what
  WordPress takes the slug from. Git branch: `ifs-deploy` (main branch: `main`).
- The path contains `[22020]`, which **`glob()` reads as a character class** and silently
  matches nothing. Every tool and test in this repo uses `scandir()` instead. Tailwind's own
  content scanner is unaffected because its patterns are relative.
- PHP for linting and the test suites: `D:\xampp\php\php.exe` (8.2.12). *Laragon and
  its bundled PHP/Node/WordPress copies were removed from the D: drive mid-project —
  older notes referencing `D:\laragon\...` are dead paths.* No Node is currently
  installed, so `admin.js` is checked with a bracket-balance script instead of
  `node --check`.
- Deployed to the real sites over SFTP (`.vscode/sftp.json`, which holds **live WP Engine
  passwords**). That file was **tracked** for a long time despite `.gitignore` listing
  `.vscode/` — a gitignore entry has no effect on an already-committed file. It is now
  untracked (`git rm --cached`), **but it remains in at least 5 commits of history, so the two
  passwords are spent and still need rotating.** Untracking only stops future commits.
- Live pair under test: **Staging = `copperlfstg.wpenginepowered.com`**,
  **"Production" = `copperlfdev.wpenginepowered.com`** (the dev environment is
  standing in as the destination).

Note the SFTP workflow's sharpest edge: `uploadOnSave` only fires on editor saves, so
**newly added files are easy to miss**. A missing class is a fatal on the far side —
which is why Diagnostics checks Production for each expected class by name (§20).

---

## 2. Tech / conventions

- **PHP 8.0+** target. IMPORTANT: do **not** use `new` in constructor/param
  default values (that's 8.1+). Pattern used everywhere: nullable params +
  `$this->x = $x ?? new X();` in the body.
- **OOP, namespaced `IfsDeploy\`, PSR-4** mapping `IfsDeploy\ => src/`.
- **Hand-rolled autoloader** in `ifs-deploy.php` (an `spl_autoload_register`).
  `composer.json` declares the same PSR-4 map for tooling, but **no
  `composer install` is required on the server** and there is no `vendor/`.
- WordPress coding-style-ish (tabs, `esc_*`, nonces, capability checks).
- **86 classes in `src/`**, all lint-clean.
- **34 test suites + 6 repo-wide checks, 1653 assertions**, runnable without WordPress. See §24.

### Naming — one plugin, six spellings

Renamed twice: display name `Stagio → IFS Deploy`, then internals `deploypress → ifs_deploy_`
(2799 references, 126 files). One spelling cannot serve every context, so:

| Context | Form | Why not just `ifs-deploy` |
|---|---|---|
| PHP namespace, JS object | `IfsDeploy` | a hyphen is not a valid identifier |
| PHP constants | `IFS_DEPLOY_` | same |
| Options, tables, post meta, hooks, filters, nonces | `ifs_deploy_` | `wp_ajax_ifs-deploy_x` matches no hook; a hyphen in an unquoted SQL identifier is a syntax error |
| Text domain, REST namespace, page slug, CSS classes | `ifs-deploy` | slugs are hyphenated |
| HTTP headers | `X-IFS-Deploy-` | header casing |
| Prose, labels | `IFS Deploy` | it is a name |

The `dp-` CSS prefix and `dp_` transient prefix are **deliberately unchanged** — opaque
prefixes nobody reads as a brand.

**`Support\Legacy` holds the six strings that still spell the old name**, because they are
*stored data format*, not names: the option/meta/table prefixes the migration searches for, the
signature URL token baked into every hash on disk, and the "option was absent" sentinel written
into snapshots. Its docblock has a table of exactly what breaks if each one changes.
`Support\LegacyRename` migrates a pre-rename install (tables, options, post + term meta, **and
the capability names stored inside two option values**) — see §29.
- **Admin CSS is now BUILT** with the Tailwind v3 standalone binary (no Node). Edit
  `assets/css/src/admin.src.css`, never `assets/css/admin.css`. See `DESIGN.md`.

---

## 3. How to set it up (admin flow)

Install + activate on both sites, then **IFS Deploy → Settings → Connection
Settings**:

- **Production site:** Role = Production. Leave the remote fields blank. Copy
  the API Key + Secret Key shown under "This Site's Credentials."
- **Staging site:** Role = Staging. Paste the Production URL + the API/Secret
  keys copied from Production. Click **Test Connection**.

The Settings screen is role-aware: selecting "Production" hides the remote
fields (JS toggle + server-side initial state), since Production needs no remote
details.

Admin menu: **Dashboard, Pending Changes, Compare & Sync, Deployment History,
Settings, Logs & Diagnostics.**

("Connection Settings" was renamed to **Settings** once it grew a second tab — see
§21. Messages that used to point users at "Connection Settings" now say
"IFS Deploy → Settings".)

---

## 4. Architecture / data flow

```
STAGING                                        PRODUCTION
-------                                        ----------
edit content
  -> ChangeTracker hooks fire
  -> Observer builds a package, hashes it
  -> QueueRepository upsert (dedup by hash)     REST ifs-deploy/v1/*
Pending Changes / Compare screens               (permission_callback = Verifier)
  -> DeploymentService.deploy()                    -> /import  -> ImportManager
     builds packages per queue item                    -> snapshot current state
     signs (Signer) + POSTs (DeployClient) -------->    -> dispatch by package type
                                                        -> PostImporter / TermImporter /
                                                           OptionImporter / MediaImporter /
                                                           MenuImporter  (WP APIs only)
                                                     -> /rollback -> SnapshotStore.restore
                                                     -> /index    -> SiteIndex (for Compare)
                                                     -> /link     -> stamp origin meta
```

### Package format (JSON, per object)
Every deployable object becomes an array with at least:
`format, type (post|term|option|media|menu), subtype, action (update|delete),
origin_id, origin_site` plus type-specific payload. It's `wp_json_encode`d and
sent in a signed request body.

---

## 5. Security model

**`SECURITY.md` is the authority** — it records every finding, its CVSS, the fix, and the
named regression test. Summary: Critical 1/1 · High 4/4 · Medium 7/7 · Low 5/5, **nothing
outstanding**. What follows is the shape; go there for the reasoning.

### The signed channel (protocol v2)

- **Requests.** `Signer::canonical_v2()` = `timestamp \n nonce \n route \n sha256(body)`,
  HMAC-SHA256 with the shared secret. Binding the **route** (M-3) means a signature valid for
  `/index` is no longer arithmetically valid for `/import`.
- **Responses too** (H-3). Production signs its reply over
  `timestamp \n nonce \n sha256(wp_json_encode(data))`, reusing the *request's* nonce so the
  reply is bound to the one request that asked for it. Staging verifies **before anything reads
  the body**. Without this, an on-path attacker could forge an `index` (Compare shows fabricated
  state) or a `rollback-preview` (the diff you approve a rollback from).
- **Six headers**, all `X-IFS-Deploy-*`: Key, Timestamp, Nonce, Signature, Protocol,
  Response-Signature.
- **Replay protection** (H-1/H-1a). `Auth\NonceStore` claims each nonce with a single `INSERT`
  against a `UNIQUE` index — atomic, so two concurrent deliveries cannot both pass. Fails
  **open** to a transient store if the table is unusable, because a failed insert also looks
  like a missing table and refusing everything would be a self-inflicted outage.
- **Version negotiation** (`Auth\Protocol`). The verifier accepts v2 then v1; the client signs
  v1 until the peer *proves* v2 by signing a reply. The ratchet is **one-way**, so nothing on
  the wire can talk a proven pair back down. This is why the two sites did not need a
  simultaneous update for H-3/M-3 — see `SECURITY.md` §11.

### On the receiving side

- **Content firewall** (H-2). Imported `post_content`/`post_excerpt` and `widget_*` options pass
  through `wp_kses` with an extended allow-list. A REST import has no logged-in user, so core's
  `wp_filter_post_kses` never fires — meaning anything holding the secret could otherwise store
  `<script>` on the live site *even though no user there is allowed to*. Three modes:
  `report` (default on upgrade — logs, changes nothing), `filter` (default for new installs),
  `off`.
- **Object injection** (C-1). `Support\SafeData` deserialises with `allowed_classes => false`;
  every importer and the snapshot store use it. Export deliberately still uses
  `maybe_unserialize()` — that reads values this site's own WordPress wrote.
- **Lockout** (M-6). 12 counted auth failures in 15 minutes → 900s cool-off. `duplicate` and
  `replay` are excluded: a proxy retry is transport noise, not an attack.
- **Address rules** (M-6b) and **API access logging** (M-6a) — see §20.
- **Generic 403** (L-5). `bad_key` and `bad_signature` both reply
  `ifs_deploy_unauthorized`; the specific code is already in the access log, so the distinction
  is kept where it helps the owner and removed where it helps an attacker.
- **Direction** enforced on both layers: `/import` and `/rollback` answer 409 unless the
  receiver is Production, and `Ajax::require_staging()` refuses `deploy`/`deploy_posts` on a
  receiver.

### Credentials

`Auth\Credentials` stores Site ID + API key (`dpk_…`) + secret (`dps_…`) in
`ifs_deploy_credentials`, `autoload = false`. **Settings → Regenerate Credentials** rotates
them (M-7 — the button existed for a long time with no handler, so the documented remedy for a
leaked secret silently did nothing).

### Admin surface

Every AJAX action goes through `Ajax::guard()` — capability **and** `check_ajax_referer` — and
`guard()` **defaults to `manage_options`**, so an action added without thinking about
permissions fails closed. Role model in §21.

### Transport

`redirection => 0` on every request. WordPress re-sends method, body **and headers** on a
301/302, so one open redirect would forward the API key and the whole payload to a third party.
A redirect now surfaces as `ifs_deploy_redirected` with the fix (correct the Production URL).

---

## 6. Database (5 tables, `Support\Schema` via dbDelta)

- `wp_ifs_deploy_queue` — pending changes. Columns: id, object_type,
  object_subtype, object_id, object_title, action, object_hash,
  **deployed_hash**, status, **user_id**, created_at, updated_at. **Unique key** on
  (object_type, object_subtype, object_id) enforces one row per object (smart-queue
  dedup). `user_id` = who last touched it (§21); `deployed_hash` = what was last
  successfully pushed, which is how "undo your edit" clears the row (§23).
- `wp_ifs_deploy_deployments` — history. id, deployment_uuid, deployed_by,
  deployed_at, deployment_status, deployment_log (JSON of per-object results).
- `wp_ifs_deploy_revisions` — snapshots for rollback. id, deployment_id,
  object_type, object_id, snapshot (JSON `longtext`), created_at.
- `wp_ifs_deploy_api_log` — who called the signed API on the receiving side. ip, remote_addr,
  forwarded, route, method, outcome, status, user_agent, is_new_ip, created_at. §20.
- `wp_ifs_deploy_nonces` — the replay store. `nonce_hash char(64)` with a **UNIQUE** key (the
  index *is* the check) plus `expires_at` for the sweep. §5.

`Schema::maybe_upgrade()` runs on `plugins_loaded` gated by `ifs_deploy_db_version`
(constant `IFS_DEPLOY_DB_VERSION`, **now `6`**). Schema history: **1** initial tables ·
**2** queue `user_id` · **3** queue `deployed_hash` plus a `Schema::migrate()` backfill ·
**4** `api_log` · **5** `nonces` · **6** no schema change — re-keys existing option queue
rows after `OptionExporter::option_id()` stopped using crc32 (§26) · **7** queue
`baseline_hash`, plus collapsing duplicate rows now that a row's identity is
(object_type, object_id) rather than including the subtype (§26b).

**Options** (the authoritative list is `uninstall.php`, and `contracts-test.php` fails if any
option the code writes is missing from it): `db_version, role, credentials, remote, debug_log,
roles, users, log_retention_days, trusted_ip_header, anonymise_ips, ip_allow, ip_block,
content_firewall, peer_protocol, log_duplicates, legacy_migrated` — all `ifs_deploy_`-prefixed,
all removed on uninstall.

**Post/term meta** written on the receiver: `_ifs_deploy_origin_id`, `_origin_site`,
`_origin_term_id`, `_source_url`, `_src_sig`. `origin_id` is how Production recognises an object
as the copy of a Staging one — lose it and the next deploy creates a duplicate instead of
updating (§9).

---

## 7. Change detection (Staging only — `Detection\ChangeTracker`)

Registered only when `Config::is_staging()`. Observers build a package, hash it
(`Queue\Hasher::hash` = `md5(wp_json_encode(package))`), and upsert into the
queue. Upsert **skips** when hash unchanged and status still pending (this is
the "edit Homepage 20× → 1 row" behavior) **and** when the new hash matches
`deployed_hash`, which is what makes undoing an edit drop the row back out of
Pending Changes (§23). Because the hash covers the package, meta on the shared
blocklist can never make a save look deployable (§23).

- **PostObserver** — `save_post`(99), `acf/save_post`(20), `wp_trash_post`,
  `before_delete_post`. Skips autosaves/revisions/auto-draft.
- **TermObserver** — `created_term`, `edited_term`, `delete_term`.
- **OptionObserver** — `added_option`, `updated_option`, `deleted_option`
  (only allowlisted options; see §11).
- **AttachmentObserver** — `add_attachment`, `edit_attachment`,
  `delete_attachment`.
- **MenuObserver** — `wp_update_nav_menu` (covers create + any item change).

Queue row key conventions:
- post/media: object_id = post/attachment ID, subtype = post_type / mime.
- term: object_id = term_id, subtype = taxonomy.
- option: object_id = `crc32(name)`, subtype = '', **name stored in
  object_title**.
- menu: object_id = nav_menu term id, subtype = ''.

---

## 8. Content coverage (what deploys today)

- **All public post types + all CPTs + reusable/global blocks (`wp_block`)** —
  `Config::tracked_post_types()` = public types + wp_block, minus a safety
  exclusion (attachment, revision, nav_menu_item, FSE system types,
  `shop_order`/`shop_order_refund`). Filter: `ifs_deploy_tracked_post_types`.
- **Any post meta / ACF fields** — copied **verbatim from postmeta** (see §10).
- **Taxonomies / categories / tags / custom** — `Config::tracked_taxonomies()`
  = public taxonomies minus nav_menu/link_category/post_format/FSE. Matched by
  slug across sites.
- **Options** — theme options, ACF options pages, widgets (header/footer for
  classic themes), safe plugin settings — via allowlist (§11).
- **Media** — files uploaded to Production, links rewritten (§12).
- **Menus** — whole menu as one unit (§13).

Cross-site object identity, in priority order (`PostImporter::find_target`):
1. **origin meta** — `_ifs_deploy_origin_id` + `_ifs_deploy_origin_site`
   stamped on the prod object after any deploy (most precise).
2. **same ID** — a post with the same ID + type (works on cloned sites).
3. **slug + post_type**.
Filter: `ifs_deploy_match_strategies`. Whatever matches gets stamped, so future
deploys are precise. If nothing matches → insert (with ID parity, see §9).

---

## 9. ID parity (keystone feature)

New content created on Staging is created on Production with the **same ID**,
via `wp_insert_post([... 'import_id' => origin_id])` and
`wp_insert_attachment([... 'import_id' => origin_id])`. `import_id` is honored
only when that ID is free on Production; on collision WordPress assigns a new ID
(so it never overwrites). This is what makes menu → page refs, ACF relations,
inline media, and reusable-block refs line up across sites without deep
remapping.

---

## 10. ACF / shortcode handling (IMPORTANT correctness decision)

ACF data is **NOT** exported via `get_fields()`. `get_fields()` formats values
and runs `do_shortcode()` on WYSIWYG/text fields, which would deploy *rendered
HTML* instead of the literal `[shortcode]`. Instead:
- Export copies **raw `postmeta` verbatim** (`get_post_meta`), which never
  renders. ACF stores everything in postmeta, so ACF (incl. repeaters/flex/
  groups) round-trips losslessly, shortcodes preserved.
- Import does **NOT** call `update_field()`; it writes raw meta verbatim.
- Requires the same ACF field groups on both sites (a stated assumption).
- The package still has an `'acf' => null` key for format stability.

---

## 11. Options safety — `Support\OptionAllowlist`

**Default-DENY.** An option is deployable only if it matches a safe pattern (or
the filter allows it) AND is not on the dangerous blocklist. Re-checked on the
**import side** too (`OptionImporter`), so Production never writes a protected
option regardless of the request.

- Safe prefixes: `options_`, `_options_` (ACF), `theme_mods_`, `widget_`.
- Safe exact: `blogname`, `blogdescription`, `sidebars_widgets`,
  `page_on_front`, `page_for_posts`, `show_on_front`, `sticky_posts`.
- Always refused: `siteurl, home, template, stylesheet, active_plugins,
  permalink_structure, category_base, tag_base, rewrite_rules, upload_path,
  upload_url_path, db_version, *secret/salt/key*, cron, admin_email,
  mailserver_*, users_can_register, user_roles`, etc. Transients skipped.
- Filter to widen (e.g. Yoast globals): `ifs_deploy_option_is_allowed`.

This honors the user's explicit rule: track all *safe DB* content, never touch
anything file/.htaccess/permalink/server related.

---

## 12. Media pipeline

- **MediaExporter** — source_url, filename, alt, attachment post fields
  (title/name/caption/description/mime/parent), meta minus regenerated keys
  (`_wp_attached_file`, `_wp_attachment_metadata` are NOT transferred).
- **MediaImporter** — downloads the file (`download_url` + `wp_upload_bits`),
  creates the attachment with `import_id` (ID parity), regenerates size
  metadata (`wp_generate_attachment_metadata`), stamps origin + source URL.
- **Media matching order is load-bearing** (`find_existing()`): recorded
  `_ifs_deploy_source_url` → origin link → ID parity **only when corroborated**.
  It originally tested ID parity first and unconditionally, and `import()` treats a
  match as "already present → keep the existing file". Because cloned environments
  share attachment ids, uploading a NEW image on Staging and pushing it matched
  whatever unrelated attachment occupied that id, skipped the download entirely, and
  still reported success — the file simply never arrived. Parity now also requires a
  matching filename (case-insensitive) and no origin stamp pointing at a different
  site or object. Deliberately conservative: a false match is silent, a false miss
  only costs a redundant upload. Both branches log, because "matched" and "uploaded"
  are indistinguishable from outside. Covered by `media-match-test.php` (10 cases).
- **URL rewrite**: `PostExporter` sends `origin_url` (staging home_url);
  `PostImporter` replaces Staging URLs → Production URLs in post_content/excerpt
  **and in meta at any depth**, via `Support\UrlRewriter` (which handles http/https,
  `www.`, protocol-relative and escaped-slash forms — see §19).
- **Renamed files** (`Import\MediaUrlResolver`): `wp_upload_bits()` never
  overwrites, so if Production already holds an unrelated `sample.png` the incoming
  file is stored as `sample-1.png`. A domain-only rewrite then leaves content
  pointing at a file that does not exist — or at somebody else's image. The resolver
  scans content + meta for Staging upload URLs, finds each attachment by
  `_ifs_deploy_source_url`, and rewrites to the URL Production actually has. It
  splits off any `-WxH` suffix and re-applies it, so `sample-300x200.png` →
  `sample-1-300x200.png`, and it follows a changed year/month folder too. Media from
  earlier deploys resolves as well, because the lookup is by stored meta rather than
  a batch-scoped map.
- **Uploads keep Staging's year/month folder**: `wp_upload_bits()` is passed the
  attachment's own `post_date`. Otherwise a file uploaded in August but deployed in
  September lands in `2026/09` — a second, needless way for the URL to diverge.
- **Import order is dependency order**, not the order the sender queued things
  (`ImportManager::TYPE_ORDER`): media → terms → posts → menus → options. Media
  first is load-bearing for the rename fix — `PostImporter` can only look up an
  attachment that already exists.
- Featured image handling in `PostImporter::apply_featured_image()` shares the
  `_ifs_deploy_source_url` dedup key with the media pipeline, so no duplicate
  upload.
- Media requires Staging to be reachable from Production (to fetch the file).
- Media rollback snapshots the attachment's DB record, **not** the binary file.

---

## 13. Menus

A whole menu deploys as one unit (`type = 'menu'`).
- **MenuExporter** — the nav_menu term + all items (each item carries
  `ref_slug`/`ref_subtype` for its target) + theme locations pointing at it.
- **MenuImporter** — matches the menu by slug (creates if missing), **rebuilds
  items from scratch** (delete + recreate — items aren't referenced
  externally), remaps parents via an origin→new id map, resolves each item's
  target by ID parity then slug fallback (posts) / slug (terms), then
  re-assigns theme locations.
- Rollback snapshots the entire menu package and re-imports it
  (`SnapshotStore::capture_menu` / `restore_menu`).
- **Menu deletes are NOT auto-propagated** (safe side) — observer only tracks
  create/change.

---

## 14. Deployment, history & rollback

- **DeploymentService** (Staging) — `deploy(queue_ids)`, `deploy_posts(post_ids)`
  (used by Compare's per-row Push), and `rollback(deployment_id)`. Builds
  packages per queue item (`build_package` dispatches by object_type; deletes
  via `build_delete_package`, which sets origin_site = this site's id so deletes
  can be matched). Sends a batch, then records history + updates queue statuses.
- **ImportManager** (Production) — creates a deployment record, then per object:
  snapshots current prod state, dispatches import by `package['type']`, records
  per-object result (ok/title/origin_id/object_id/created/revision_id). Status:
  success / partial / failed.
- **SnapshotStore** — `capture` (post), `capture_term`, `capture_option`,
  `capture_menu`; `restore` dispatches by object_type to
  restore_post/term/option/menu. Prunes to `Config::MAX_REVISIONS` (3) **per
  object**.
- **DeploymentRepository** — history CRUD, `set_status`, `clear()`.
- **Deletes** handled safely: posts → `wp_trash_post` (reversible), terms →
  delete by slug/name, options → `delete_option` (allowlist re-checked), media
  → `wp_delete_attachment`.

---

## 15. Admin screens

- **Dashboard** — role, pending count (scoped per §21), **last deployment**,
  production URL (Staging only), quick links, and a warning when a Staging site has
  no connection configured. Renders **once** — it used to render twice, because the
  `add_submenu_page()` that relabels the first entry passed a second callback onto
  the same page hook.
- **Pending Changes** — queued items with a human "Type" label (post type /
  "Taxonomy: x" / "Option / setting" / "Media: mime" / "Navigation menu"), a
  **Changed By** column, a **user filter** for those who can see everyone's changes,
  and a **View changes** preview per post row (§19). Push Selected / Push All /
  Ignore / Refresh (AJAX), with pushes now behind a confirmation (§22). The list is
  verified against Production before rendering so it only shows real differences
  (§23).
- **Compare & Sync** (Staging) — fetches Production `/index`, matches local→
  remote, groups results into **separate tables**: "Different — needs deploy"
  (with Push button), "Not on Production" (Create button), "In sync", and
  "Only on Production". "Sync IDs" links matched-but-unlinked prod objects via
  `/link`. NOTE: Compare currently covers **posts/pages only**; terms/options/
  media/menus surface in Pending Changes, not Compare.
- **Deployment History** — shows object names per deployment, up to 50 rows,
  Rollback button **only when rollback is possible** (succeeded + has a
  snapshot revision), "Rolled Back" status after rollback, **Clear History**
  button. Retention note: last 3 restore points **per page/post**.
- **Settings** — two tabs (§21): **Connection Settings** (role toggle with
  role-aware field hiding, this site's credentials + Regenerate, remote config +
  Test Connection) and **Role Management** (role × capability matrix plus per-user
  allow/block pickers). Administrator-only.
- **Logs & Diagnostics** — event log, recent per-object deployment failures, and a
  signed health check of Production (§20). Administrator-only.

Assets: `assets/js/admin.js` (jQuery), `assets/css/admin.css`. Localized via
`Assets` with nonce + i18n strings.

---

## 16. Compare "in sync" logic (subtle — read before touching)

`Support\ContentSignature::for_post()` builds a **site-independent** content
hash from the same content that deploys: core fields + post meta (incl. ACF) +
taxonomy slugs + featured image filename — excluding site-specific/volatile keys
(origin stamps, edit locks, `_oembed_*`, `_transient_*`, `_ifs_deploy_src_sig`).

**URLs are neutralized before hashing** (`neutralize_urls()`): both the site's own
`home_url()` and the configured counterpart URL collapse to a fixed token, in
plain and escaped-slash form. This is load-bearing, not cosmetic — the import
rewrites Staging URLs to the Production domain, so hashing raw content made every
post containing an internal link or inline image report as **"Different" forever**
while the field-level preview (which rewrites first) correctly found nothing to
deploy. The counterpart URL is included so content where an editor pasted the
*live* URL into Staging also matches, since the import leaves such URLs alone and
only Production would otherwise neutralize them. Verified against genuinely
different content to confirm the token cannot mask a real edit.

At deploy time, Staging's signature is **stamped on the prod post**
(`_ifs_deploy_src_sig` via `PostImporter`). `SiteIndex` returns both the
recomputed `signature` and the stamped `deployed_sig`. `CompareService`
considers an object **in sync** if EITHER:
1. staging current signature == prod's stamped `deployed_sig` (precise —
   survives prod-side kses filtering / prod-only meta), OR
2. staging current signature == prod's recomputed signature (covers
   cloned/identical content never deployed-with-stamp).

Why both: comparing two *independently* recomputed hashes falsely diverges after
a push (kses rewriting, extra prod meta); the stamp fixes that. But the stamp is
absent on legacy/cloned content, so recomputed-match is the fallback. Neither
can produce a false "in sync."

---

## 17. File map (src/)

86 classes. Newer additions marked ★.

```
Plugin.php                     bootstrap; wires services by role
Support/  Activator, Schema, Config, Logger, ContentSignature, SiteIndex,
          OptionAllowlist,
        ★ Access          role + per-user capabilities (§21)
        ★ DebugLog        persisted ring-buffer log (§20)
        ★ MetaBlocklist   the one list of ignored meta (§23)
        ★ MediaIdentity   rename-proof attachment filename (§23)
        ★ PackageDiff     field-level diff engine (§19)
        ★ SyncCheck       the single "in sync?" rule (§23)
        ★ UrlRewriter     rewrite / neutralize environment URLs (§19)
        ★ SafeData        unserialize with allowed_classes => false (C-1, §5)
        ★ Json            encode that reports failure instead of casting it away (§26)
        ★ ContentFirewall kses allow-list for imported content (H-2, §5)
        ★ ClientIp        which address to believe, and when (M-6a, §20)
        ★ ApiLog          who called the signed API (M-6a, §20)
        ★ IpAccess        allow / block lists with CIDR (M-6b, §20)
        ★ LogRetention    the daily purge + retention setting (§20)
        ★ Legacy          the six strings that keep the pre-rename spelling (§2)
        ★ LegacyRename    one-time data migration for a pre-rename install (§29)
Auth/     Credentials, Signer, Verifier,
        ★ Protocol        which signing version the peer speaks; one-way ratchet (§5)
        ★ NonceStore      atomic replay claim, fails open (§5)
        ★ Lockout         cool-off after repeated auth failures (§5)
Detection/ChangeTracker, PostObserver, TermObserver, OptionObserver,
          AttachmentObserver, MenuObserver
Queue/    QueueRepository, Hasher
Export/   PostExporter, TermExporter, OptionExporter, MediaExporter, MenuExporter
Import/   ImportManager (dispatch + type ordering), PostImporter, TermImporter,
          OptionImporter, MediaImporter, MenuImporter,
        ★ MediaUrlResolver  maps Staging media URLs to renamed prod files (§12)
Rollback/ SnapshotStore,
        ★ SnapshotPackage   snapshot → package shape, for the rollback diff (§22)
History/  DeploymentRepository
Rest/     RestController, PingEndpoint, ImportEndpoint, RollbackEndpoint,
          IndexEndpoint, LinkEndpoint,
        ★ ObjectEndpoint          read-only, deploy preview (§19)
        ★ RollbackPreviewEndpoint read-only, rollback diff (§22)
        ★ SignatureEndpoint       read-only, queue verification (§23)
Client/   DeployClient (signed HTTP), DeploymentService, CompareService,
        ★ DeployLock      per-object locks so two people cannot push one object at once
        ★ PreviewService          deploy preview (§19)
        ★ RollbackPreviewService  rollback preview (§22)
        ★ QueueVerifier           drops already-in-sync rows (§23)
Admin/    AdminMenu, Assets, Ajax,
          Pages/{Dashboard,PendingChanges,Compare,History,Settings}Page,
        ★ Pages/LogsPage     logs + diagnostics screen (§20)
        ★ Diagnostics        the health-check report (§20)
        ★ DiffRenderer       renders PackageDiff output (§19)
        ★ PreviewModal       shared preview dialog shell (§19)
        ★ ConfirmModal       shared confirm dialog shell (§22)
        ★ RollbackSummary    rollback dialog body (§22)
```
Root: `ifs-deploy.php`, `uninstall.php`, `composer.json`, `readme.txt`,
`SPRINT-PLAN.txt`, this file, and **`tests/`** (§24 — dev tooling, exclude from
builds).

REST namespace: `ifs-deploy/v1`. Endpoints: `ping, import, rollback, index,
link, object, rollback-preview, signatures`. The last three are read-only: `object`
powers the deploy preview diff (§19), `rollback-preview` the rollback diff (§22), and
`signatures` the Pending Changes verification (§23). `ping` also reports Production's
diagnostics (§20).

---

## 18. Deferred / not done (honest status)

- **Preview Changes for terms, options and menus** — the diff (§19) covers post types
  AND media (§26b); term/option/menu rows still show "—" in the Preview column.
- **Compare & Sync for non-posts** — terms/options/media/menus aren't in the
  Compare diff yet.
- ~~Persisted replay/nonce store~~ — **done**, DB v5 `nonces` table with a UNIQUE index (§5).
- **Redirection & other custom-DB-table plugins** — store data in their own
  tables, not posts/meta/options; need per-plugin connector/drivers.
- **Yoast global settings** — per-page SEO already deploys (postmeta); global
  options need allowlist entries via the filter.
- **Deep ACF/relationship remapping** beyond ID parity (mostly unnecessary now
  that IDs match, but non-cloned sites with divergent IDs could still need it).
- **Background/batched media** for large libraries (currently synchronous). Related:
  a large push can still exceed **Production's own PHP execution limit** — the client
  timeout was raised to 180s (§20) but that cannot help past the server's cap.
  Splitting one push into several requests needs Production to reuse a single
  deployment record per UUID, or rollback would only restore the last chunk.
- **Code/theme/file deploys** — intentionally out of scope (use Git).
- ~~`.gitignore`~~ — **done**, and extended (§1). Note it never untracked `sftp.json`; that
  needed `git rm --cached`, and the passwords are still in history.
- ~~`readme.txt` is stale~~ — **done**, rewritten for release with Installation, FAQ and a
  Changelog; every claim checked against the code.
- **Plugin name for wordpress.org** — decided for this build, still open for the public
  directory (§25, §30).
- **A per-API-key rate limit** — declined with the numbers written down, because one API key is
  shared by the whole team; see `SECURITY.md` §12.
- **Multi-contributor queue ownership** — the queue is one row per object owned by the last
  editor, so a second editor's push carries the first's changes. Fixing it needs a DB migration
  and a Pending Changes UI change; deferred by choice (`SECURITY.md` §9.5).

---

## 19. Preview Changes (before/after diff in Pending Changes)

A **View changes** button opens a **modal dialog** (no page reload) on **both**
screens. **Left = Staging after this push (green), Right = Production right now
(red).**

- **Pending Changes** — on every post-type row. Non-post rows show "—" and deploy
  as before. Also previews queued *deletes* ("will be moved to Trash").
- **Compare & Sync** — on every row in "Different — needs deploy" and "Not on
  Production", beside the existing push button, so a difference can be reviewed
  before deciding to push. The "In sync" table has no actions and no preview.

Flow: `ifs_deploy_preview` AJAX → `Client\PreviewService` → signed POST to
Production's new **`/object`** endpoint → `Support\PackageDiff` →
`Admin\DiffRenderer` → HTML injected into the dialog body.

Two entry points, because the screens identify objects differently, converging on
one fetch + diff so they can never disagree about what a push would do:
`PreviewService::preview( queue_id )` (Pending Changes; dispatches deletes) and
`PreviewService::preview_post( post_id )` (Compare, which has no queue row). The
AJAX action accepts `queue_id` **or** `post_id`; `admin.js` sends whichever
`data-` attribute the button carries and caches per `q<id>`/`p<id>`.

`Admin\PreviewModal` owns the shared dialog shell (`render()`) and trigger button
(`button()`), so both pages emit identical markup.

**Row actions are small text buttons** (`button-small`). An icon-only version with
hover tooltips was built and rejected — it did not look right in this table — so
if you are tempted again, note that it also required special-casing `admin.js`'s
`request()`, which swaps a button's *text* to "Working…" and would wipe a glyph.
On Compare & Sync the review + push pair is stretched to a shared width
(`.ifs-deploy-row-actions`), because the column is too narrow for them to sit side
by side and content-sized buttons of differing widths read as a glitch.

The dialog markup is rendered once per page by `PreviewModal::render()` (so it is
translated and escaped in PHP, not assembled in JS) and reused for every row. It
closes on the X, the Close
button, a backdrop click, or Escape; focus moves to the close button on open and
returns to the trigger on close; `body.ifs-deploy-modal-open` locks page scroll.
The dialog body is the single vertical scroll container — per-field diffs only
scroll horizontally, so the user never gets two competing scrollbars.

Design decisions that matter:

- **`/object` is read-only** and resolves the target with `PostImporter::locate()`
  — the *same* matcher the import uses — so the panel describes the object the
  deploy would really overwrite. `locate()` is `find_target()` plus the strategy
  name; `find_target()` now delegates to it (unchanged signature/behavior).
- **Both sides build the package with the same `PostExporter`**, so both share one
  shape and one meta blocklist. Any difference is a real difference, not a
  serialization artefact.
- **Environment URLs are excluded from comparison, not predicted.**
  `Support\UrlRewriter` has two operations and both sides of every comparison run
  through the same one:
  - `rewrite()` — real URL rewriting, used by `PostImporter` on import.
  - `neutralize()` — collapse every environment URL to `[site-url]`, used by the
    preview *and* by `ContentSignature`, so Compare's verdict and the dialog can
    never disagree about whether a URL counts as a change.

  Both build on `variants()`, which is the load-bearing part: a site URL appears in
  content in far more forms than `home_url()` returns — `http://` pasted into an
  `https://` site, `www.`/bare, protocol-relative `//host`, and escaped
  `https:\/\/host` in block markup. Matching only the exact `home_url()` string
  missed all of them, which is why hardcoded `http://` staging links **leaked into
  Production** and why the diff flagged them on every push. `variants()` returns
  longest-first because `//host` is a substring of `https://host`; replacing the
  short form first would corrupt the long one into `https:[token]`.

  `neutralize()` goes one step further than `rewrite()` and also collapses **bare
  hostnames**, because a link whose visible text is the domain differs per
  environment just as much as its href. Bare hosts are matched with word boundaries
  so `example.com` never matches inside `sub.example.com`. `rewrite()` deliberately
  does *not* do this — rewriting domain names in prose during a real deploy is a
  bigger behavioural change than the leak fix called for.

  **Meta is covered too, at any depth** (`rewrite_deep()` / `neutralize_deep()`).
  This is where it matters most in practice: ACF WYSIWYG fields, repeaters and
  flexible content all live in postmeta and routinely hold absolute internal links,
  so a content-only rule left exactly the URLs an editor sees being flagged on every
  push. It also closed a second bug — `PostImporter` copied meta verbatim, so
  pushing a page **overwrote Production's ACF links with Staging URLs**; it now
  rewrites them like it does post_content.

  Deep values must be UNSERIALIZED before rewriting (both helpers assume this, and
  the importer unserializes first). A string replace over a serialized blob would
  leave its length prefixes wrong and corrupt the value.
- **Labels describe what the import actually does, not merely how the sides
  differ.** The importer never deletes meta absent from a package, so
  production-only meta is `kept`, not `removed`. Same for a taxonomy missing from
  the package and for a removed featured image. A taxonomy *present* in the
  package does replace its terms, so there removals are labelled `removed`.
- **`post_parent` is compared by SLUG, `post_date_gmt` not at all.** Raw parent IDs
  differ legitimately across sites, so comparing them would flag identical
  hierarchy; the slug is site-independent and is the same basis
  `ContentSignature` uses — which matters, because otherwise re-parenting a page
  reads as "Different" on Compare but "no differences" in the dialog. Both sides'
  slugs are supplied under a top-level `parent_slug` key (Production via
  `/object`, Staging locally) rather than by adding a key to the package format,
  which would change every hash and re-queue the whole site once.
  `post_date_gmt` is excluded outright — it is recomputed on import, so it would
  report a difference no push can resolve.
- **Rendering uses core `wp_text_diff()`** (the post-revision differ), so word-level
  highlighting matches WordPress's own revision screen. Its `table.diff` styling
  lives in `wp-admin/css/revisions.css`, which is always present because the
  `wp-admin` style bundle depends on it. A plain two-column table is the fallback
  for the whitespace-only cases it reports as identical.
- **Column order is Staging-left / Production-right, which fights core and needs
  three corrections.** Column order follows argument order, so `diff_table()` calls
  `wp_text_diff( $staging, $production )`. But core hard-codes its left column as
  "deleted" and its right as "added", giving the *incoming* Staging text red +
  `dashicons-minus` + screen-reader text "Deleted:". `admin.css` re-orients all
  three, scoped to `.ifs-deploy-diff-body` so core's revision screens are
  untouched: colours swapped (core's own values), glyph `content` swapped (plus
  `\f132` / minus `\f460`) along with core's plus-glyph vertical nudge, and the
  inverted per-cell screen-reader text `display:none`'d — it cannot be corrected
  from CSS, and removing it from the accessibility tree beats announcing the
  opposite of the truth (the visible legend states the orientation and is real
  text, so it is announced). **If the argument order changes, all of that must
  change with it.** Verified against core's actual rendered markup, not assumed.
- Underscore-prefixed meta (ACF `field_*` key mirrors, plugin internals) is shown
  but collapsed under "technical fields".
- The diff is fetched **on demand**, cached per row in JS, and does **not** reuse
  `admin.js`'s `request()` helper — that reloads the page on success, which would
  tear down the dialog the moment it opened.
- **Assets are cache-busted by file mtime** (`Assets::asset_version()`), not by
  `IFS_DEPLOY_VERSION` alone. This matters: editing `admin.js` without bumping the
  version constant leaves browsers running the *old* script behind *new* markup,
  which presents exactly as "the button does nothing". Do not revert this to a
  static version string during development.

Files added: `Rest/ObjectEndpoint.php`, `Support/PackageDiff.php`,
`Support/UrlRewriter.php`, `Client/PreviewService.php`, `Admin/DiffRenderer.php`,
`Admin/PreviewModal.php`.
Touched: `RestController` (route), `Ajax` (action), `PendingChangesPage` (Preview
column — the table is now 8 columns after Changed By was added in §21), `ComparePage`
(preview button + dialog), `Assets` (i18n + mtime cache-buster), `PostImporter`
(locate/UrlRewriter delegation), `admin.js`, `admin.css`.

NOTE: `/object` is new, so **Production must also be running the updated plugin**
or previews fail on both screens.

---

## 20. Logs & Diagnostics (debugging a failed deploy)

Admin screen **IFS Deploy → Logs & Diagnostics**, present on both sites.

Why it exists: a failed push reported only "Deployment failed." even though the
cause was usually already known. Three separate things hid it:

1. **Per-object errors were recorded but never rendered.** `ImportManager` puts an
   `error` on each failed object and it is stored in `deployment_log`; the History
   screen showed only a red cross. `DeploymentService::failure_message()` now
   surfaces the first couple in the notice, and the Logs screen lists them all.
2. **`DeployClient` discarded a non-JSON body.** A PHP fatal on the far side arrives
   as an HTML or empty 500, `json_decode` gave null, and the body became `array()` —
   losing the only copy of the message. It now keeps a stripped, truncated excerpt
   as `raw` and logs it.
3. **An uncaught `Error` on Production aborted the whole REST request.** A missing
   class after a partial upload took out the entire batch with an empty 500.
   `ImportManager::import_one()` now catches `\Throwable` per object and records
   `Class: message (file:line)`, so the batch continues and the cause is named.

`Support\DebugLog` is a capped (200-entry) ring buffer in the
`ifs_deploy_debug_log` option — no schema migration, autoload off, credentials
redacted by key name. Each site keeps its own, and **import errors land on
Production**, so that is where to look when Staging shows nothing.

`Admin\Diagnostics` (the "Run Diagnostics" button, Staging only) does one signed
ping and checks what the sender cannot otherwise see:

- **Role is Production** — if it is still "Staging" every endpoint answers 409.
- **Plugin files complete** — `PingEndpoint` reports `class_exists()` for the newer
  classes, so an incomplete upload is named outright. This matters with an
  SFTP-on-save workflow, where a newly *added* file is easy to miss.
- **Uploads directory writable** — media import cannot store the file otherwise.
- **Production can fetch media from this site** — ping takes an optional
  `probe_url` and does a `wp_remote_head()` on it. Media import downloads files
  *from* Staging, so HTTP auth or an IP allowlist there breaks it in a way no
  Production-side check reveals. Diagnostics passes a real attachment URL.

The probe makes Production fetch a caller-supplied URL. That is not an escalation —
the caller already holds full write access — and it is limited to http(s), HEAD, a
15s timeout, and is logged.

**Two failures it caught immediately, both since fixed:**

- `array_walk_recursive(): Argument #1 could not be passed by reference` in
  `PostImporter::package_strings()` — it takes its array **by reference**, so
  `(array) ( $package['meta'] ?? array() )` is a fatal `Error`, not a notice. Replaced
  with a small recursive collector. Because media imports before posts, this
  presented as "media reached Production but the post did not". Don't hand a cast or
  `??` expression to a by-ref parameter; a repo-wide scan found no others.
- `cURL error 28: Operation timed out after 30001ms` — the flat 30s HTTP timeout is
  not enough for an import that downloads every media file and regenerates its image
  sizes. `DeployClient` now allows 180s for `import`/`rollback` (30s elsewhere),
  filterable via `ifs_deploy_request_timeout`. A timeout is reported honestly:
  Production keeps working after the client gives up, so part of the batch may
  already be live and a blind retry is the wrong reflex. Note Production's own PHP
  execution limit applies independently — beyond it, the answer is fewer items per
  push (batching the request is not implemented).

Files added: `Support/DebugLog.php`, `Admin/Diagnostics.php`,
`Admin/Pages/LogsPage.php`. Touched: `AdminMenu` (submenu), `Ajax`
(`ifs_deploy_diagnostics`, `ifs_deploy_clear_log`), `DeployClient` (raw body +
logging), `DeploymentService` (`failure_message()`, package-build and dispatch
logging), `ImportManager` (Throwable catch + logging), `PingEndpoint` (diagnostics
payload), `uninstall.php` (drops the new option), `admin.js`, `admin.css`.

---

## 21. Role-based access (`Support\Access`)

**Capabilities are VIRTUAL**, granted through the `user_has_cap` filter from the
`ifs_deploy_roles` option — deliberately *not* via `WP_Role::add_cap()`. Writing
caps onto WordPress's role objects mutates global state that outlives deactivation,
is shared with every other plugin, and can strand a site. A filter is evaluated per
request and vanishes with the plugin.

Grantable: `ifs_deploy_access` (see IFS Deploy, own changes only),
`ifs_deploy_view_all` (see everyone's), `ifs_deploy_deploy`,
`ifs_deploy_rollback`. `CAP_MANAGE` is `manage_options` and is **not** grantable, so
a delegated role can never widen its own permissions.

Invariants, all covered by `access-test.php` (20 cases):

- **Defaults grant nothing.** Only administrators have access out of the box, which
  reproduces the old `manage_options` gate exactly — nobody gains visibility on
  upgrade.
- **Administrators can never be locked out.** Anyone with `manage_options` is granted
  every capability regardless of the option, so the screen that hands out
  permissions cannot be used to lose it. The administrator row is shown ticked and
  disabled, and `save()` refuses to store it.
- **`access` is a prerequisite.** A role given only "deploy" gets nothing — otherwise
  a half-configured role could act without being able to see what it was acting on.
- `grant()` must never call `current_user_can()`; that re-enters the same filter and
  recurses. The administrator test reads `$allcaps` directly.

**Per-user overrides** (`ifs_deploy_users` option) sit on top of the role rules, for
the two cases a role matrix cannot express:

- **Give access to** — named users gain the ticked capabilities *in addition to*
  whatever their role already allows, so naming someone never removes anything.
  ("Only one Editor may use IfsDeploy.")
- **Block** — named users get nothing at all, whatever their role says. Evaluated
  **before** the role rules, which is what makes "all Editors except this one" work.

Resolution order: `manage_options` (everything) → block (nothing) → role union →
individual allow → the "access is a prerequisite" sweep. Block beats allow, and
`save_users()` also drops a blocked id from the allow list so the stored state cannot
contradict the screen. Ids are validated with an explicit positive-integer check
rather than `absint()`, which would map `-5` to `5` and quietly grant a *different*
user.

The pickers are jQuery UI autocomplete (registered by core — no select2 dependency)
against `ifs_deploy_search_users`, which searches `user_login`, `user_email`,
`user_nicename` and `display_name`, and is administrator-gated because it returns
email addresses. Chips are rendered server-side as hidden inputs, so an existing
selection survives with JavaScript off; only add/remove needs the script. The
`.ui-autocomplete` menu is styled in `admin.css` unscoped, since jQuery UI appends it
to `<body>` and core ships no admin theme for it.

**Scoping** flows from one place, `Access::scope_user_id()` (null = everyone), used
by Pending Changes, History and the Dashboard count. Ownership comes from the new
`user_id` column on the queue (**DB version 2**, added by dbDelta; pre-existing rows
hold 0 and display as "Unknown"). `QueueRepository::upsert()` captures
`get_current_user_id()` itself — one row per object means this records the *last*
person to touch it, which is the right answer for "whose pending change is this?".

Requests are re-checked server-side: `Ajax::queue_ids()` narrows submitted ids via
`ids_owned_by()`, so a user without `view_all` cannot act on someone else's row by
posting its id. `Ajax::guard()` **defaults to `manage_options`**, so an action added
without thinking about permissions fails closed.

Compare & Sync stays administrator-only: it works on posts rather than queue rows,
so there is no ownership to scope it by.

Settings is now tabbed (`&tab=connection|roles`), both administrator-only. While
rewriting its save handler, a long-standing bug went with it: it used to call
`Config::set_remote()` unconditionally, so switching a site to the Production role —
which hides those fields — silently wiped the URL and keys. It now writes them only
when the form actually submitted them.

Files added: `Support/Access.php`. Touched: `Schema` (+`user_id`, DB version 2),
`ifs-deploy.php` (DB version), `QueueRepository` (attribution, scoped queries,
`users_with_status()`, `ids_owned_by()`), `DeploymentRepository::recent()` (user
filter), `Plugin` (registers the filter), `AdminMenu` (per-screen caps), `Ajax`
(per-action caps), `PendingChangesPage` (Changed By column + user filter),
`HistoryPage`, `DashboardPage`, `SettingsPage` (tabs + role matrix),
`uninstall.php`, `admin.css`.

---

## 22. Confirmation dialogs (`Admin\ConfirmModal`, `Admin\RollbackSummary`)

One shared dialog for every destructive or outward-facing action, replacing
`window.confirm()` and — more importantly — adding a confirmation where there was
none: **Push Selected, Push All and Compare's per-row push all fired immediately.**

- **Push to Live** — "Are you sure you want to push updates to live?" plus a count
  line, on all three push paths (single, selected, all).
- **Rollback** — the change summary is **fetched and displayed first**, and Confirm
  stays `disabled` until it is on screen. It also stays disabled when nothing is
  restorable, so the dialog cannot green-light a no-op.
- **Clear History** — routed through the same dialog for consistency.

### Rollback preview: current version vs the version being restored

The rollback dialog shows a **field-level diff of the live object against its
snapshot**, one object at a time, with the affected objects listed as buttons above
the panel — click a name to switch. Confirm stays disabled until a preview is on
screen, and stays disabled when nothing is restorable.

Both versions exist only on **Production** (the live object and the snapshot row), so
this needs a round trip: **`POST /rollback-preview`**
(`Rest\RollbackPreviewEndpoint`, read-only). It always returns the object list plus
the diff for ONE revision — the requested one, or the first — which keeps the payload
small when a deployment touched many objects. Staging fetches via
`Client\RollbackPreviewService` and renders with `Admin\RollbackSummary`, which reuses
`DiffRenderer::render_fields()` so the rollback and deploy diffs cannot drift apart.
Switching objects refetches only the panel (`panel_only` on the AJAX action).

Two subtleties that make the comparison honest:

- **`Rollback\SnapshotPackage`** reshapes a snapshot into the package shape
  `PackageDiff` expects. Snapshots are written for *restoring*, not comparing: meta is
  raw `get_post_meta()` (serialized), taxonomies are term IDs, the thumbnail is an
  attachment ID. It unserializes, resolves IDs to slugs/filenames, and filters through
  `Support\MetaBlocklist` (§23) so IFS Deploy's own bookkeeping and other volatile
  keys are not reported as changes. Empty taxonomies are preserved deliberately —
  `restore_post()` iterates the snapshot's taxonomies, so one recorded as empty really
  does get cleared, and dropping the key would mislabel that as "kept".
- **`PackageDiff::compare()` takes a `$full_replace` flag.** A deploy never deletes
  what it does not mention, so target-only data is `kept`. A rollback is a full
  rewrite — `restore_post()` clears meta, terms and the thumbnail before writing the
  snapshot back — so there the same data is genuinely `removed`. Defaults to false, so
  the deploy path is untouched; verified both ways, including that the flag does not
  leak between calls.

Orientation matches the deploy preview: left/green is the version being applied (the
snapshot), right/red is the version it replaces (what is live). It also refuses to
preview someone else's deployment unless the user has `view_all`.

Two mechanical notes worth keeping:

- The confirm dialog's close controls use `data-ifs-deploy-confirm-close`, distinct
  from the preview dialog's `data-ifs-deploy-close`, so the two cannot close each
  other. Escape cancels both.
- `openConfirm()` falls back to `window.confirm()` when the dialog markup is absent,
  so an action is never silently unavailable on a screen that forgot to render the
  shell. Escape and Cancel always cancel; nothing confirms implicitly.

`ConfirmModal::render()` is printed by Pending Changes, Compare & Sync and History.

---

## 23. Meta noise and the "undo leaves it queued" fix

### One shared meta blocklist (`Support\MetaBlocklist`)

`PostExporter`, `ContentSignature` and `Rollback\SnapshotPackage` all filter meta
through this one list now. They used to keep separate lists that **disagreed**:
`_oembed_*`, `_transient_*`, `_pingme` and `_encloseme` were excluded from the
signature but still deployed, so they could never be reported as a difference yet were
copied to Production anyway.

What belongs in it: meta that changes *without the content changing*, or that means
something different per site — editor locks, caches, ping timestamps, trash
bookkeeping. This matters more than it looks: because the queue keys off the package
hash, **one key that changes on every save makes every save look deployable**.
`_yoast_indexnow_last_ping` is exactly that (Yoast rewrites it on each IndexNow ping)
and was the reported symptom.

Project-specific keys go through the **`ifs_deploy_ignore_meta_key`** filter rather
than edits to the file:

    add_filter( 'ifs_deploy_ignore_meta_key', function ( $ignored, $key ) {
        return $ignored || str_starts_with( $key, '_elementor_css' );
    }, 10, 2 );

Note what is deliberately NOT ignored: `_yoast_wpseo_*` (real SEO content),
`_wp_page_template`, and underscore-prefixed ACF field-key refs — all genuinely
deployable.

### Reverting an edit clears the pending row (DB version 3)

`upsert()` could only compare the new hash against the previous **pending** hash, so
edit → undo left the row queued forever: A→B queued hash(B), B→A stored hash(A), still
pending. It had no idea what Production actually holds.

The queue now carries **`deployed_hash`** — the hash of what was last successfully
sent. `QueueRepository::mark_deployed()` records it (replacing a bare
`set_status( DEPLOYED )` in `DeploymentService::finalize()`), and `upsert()` drops the
row back to `deployed` when the new hash matches it.

Same change fixes a second bug: a save that changed nothing used to **re-queue an
already-deployed object**, because the old skip test also required
`status === pending`.

Rows deployed before the column existed are backfilled by `Schema::migrate()`
(`deployed_hash = object_hash WHERE status = 'deployed'`), so the behaviour works for
existing history rather than only after each object's next deploy. `upsert()` reads the
column with `?? ''` so it is safe even if a row predates it.

Objects never deployed by IFS Deploy keep an empty `deployed_hash` and behave as
before — Staging cannot know Production's state for them without asking, which is what
Compare & Sync and the preview dialog are for.

Covered by `metablock-test.php` (33 cases) and `queue-revert-test.php` (18 cases,
walking the full edit → deploy → edit → undo lifecycle against the real upsert logic).

### Pending Changes only lists real differences (`Client\QueueVerifier`)

`deployed_hash` is free and local, but it can only help objects deployed *since* that
column existed — a row already sitting in the queue has no deployed hash to compare
against, so the reported row stayed listed while its own preview said "no deployable
differences". The list contradicting its own dialog is the bug.

Production is the authority, so `PendingChangesPage::render()` now verifies **before**
listing: one signed call to **`POST /signatures`** (`Rest\SignatureEndpoint`) covering
only the queued objects, then rows that match are resolved via `mark_deployed()` and a
dismissible notice explains the disappearance.

Why a new endpoint rather than `/index`: `/index` signs every post on the site (up to
2000) and is far too heavy to call while rendering a screen. `/signatures` costs what
the queue costs.

Guards, because this runs on a page load:

- Staging only, and only when the connection is configured.
- Capped at **50 rows** per run.
- **10s timeout** (`DeployClient::QUICK_ROUTES`) — a slow Production must not hold the
  page open for the normal 30s.
- Wrapped in `catch ( \Throwable )`: verification is a convenience, and no failure in it
  is worth taking the screen down for. An unreachable Production just means the list
  renders unverified.

The two decisions are pure `public static` methods so they can be tested without
WordPress (`QueueRepository`, `PostExporter` and `DeployClient` are all `final`, so the
collaborators cannot be stubbed by subclassing):

- `is_verifiable_row()` — posts only, and never a queued **delete**: that still has work
  to do even when the content matches.
- `should_clear()` — **fails closed.** Requires `found` *and* a signature match; a
  missing object, an empty answer or an inconclusive one keeps the row. Wrongly clearing
  silently loses someone's queued work, which is far worse than showing one stale row.

`Support\SyncCheck` now holds the in-sync rule, shared with Compare & Sync so the two
screens can never disagree about the same object.

`PreviewService::preview()` also self-heals: when the dialog proves there is nothing to
deploy it resolves the row there and then, and the message says so.

Covered by `verifier-test.php` (16 cases) and `syncheck-test.php` (7 cases).

### Featured images survive Production renaming them (`Support\MediaIdentity`)

All three comparison paths used to describe the featured image by its **local**
filename. `wp_upload_bits()` never overwrites, so an incoming `hero.png` becomes
`hero-1.png` when Production already holds an unrelated `hero.png` — and the two sides
then differ on **every** push, forever, over a suffix Production added itself. The
featured image was set correctly all along; only the comparison was wrong, which is why
it read as "the path didn't change on live".

`MediaIdentity::stable_filename()` compares by the ORIGINAL filename instead, recovered
from the `_ifs_deploy_source_url` stamp both importers write. It falls back to the
local filename when there is no stamp, which is what keeps it precise rather than a
guess: an unrelated `hero-1.png` uploaded by hand on Production has no stamp, so it
still compares as different — because it really is a different image. Requires a
parsed **host** as well as a path, since `parse_url()` reports a bare string like
`not-a-url` as a path and would otherwise be trusted.

Used by `PostExporter`, `ContentSignature` and `Rollback\SnapshotPackage` — the same
three seams as `MetaBlocklist`, so both fixes reach Pending Changes, the deploy preview,
Compare & Sync and the rollback preview together. `MediaIdentity::SOURCE_URL_META` is
now the canonical definition of that key; both importers alias it rather than repeating
the string.

Covered by `media-identity-test.php` (9 cases).

### Meta writes are slashed (long-standing data loss, now fixed)

`add_post_meta()`/`add_term_meta()` **unslash** what they are given, so passing raw
values stripped every backslash — silently corrupting ACF WYSIWYG content, regex,
escaped JSON and Windows paths on every deploy *and* every rollback. All five write
sites now pass `wp_slash()`: `PostImporter::apply_meta()`,
`TermImporter::apply_meta()`, `MediaImporter::apply_fields()` and both meta loops in
`SnapshotStore`. For values without backslashes `wp_slash()` is a no-op, so this cannot
change well-behaved data.

### Rollback preserves IFS Deploy's own stamps

`SnapshotStore::replace_meta()` used to delete ALL meta and write the snapshot's back.
The first deploy of a page snapshots it *before* the origin link is written, so rolling
that deploy back deleted `_ifs_deploy_origin_id` — after which the next deploy could no
longer identify the page and fell back to id/slug guessing, or created a duplicate.
`_ifs_deploy_*` keys are now excluded from both the delete and the restore: they
describe the deployment relationship, not the content.

---

## 24. Test suites

Live in **`tests/`**. **34 suites + 6 repo-wide checks, 1653 assertions, all passing.** Plain
PHP — no WordPress, no PHPUnit, no `composer install`; each suite stubs the handful of WordPress
functions it touches.

Two non-suite helpers sit alongside them, both named so `run-all.php` cannot mistake them for
suites: `lib-css.php` (read built CSS without depending on the build flag) and
`lib-media-importer.php` (a namespaced stand-in for `Import\MediaImporter`, so `postmatch` can
ask whether the featured image is routed through the media pipeline without performing a real
download). `tests/stubs/wp-admin/includes/` holds three empty files that only exist to satisfy
the unconditional `require_once ABSPATH . …` in the sideload fallback.

```bash
php tests/run-all.php            # everything, exits non-zero on failure
php tests/access-test.php        # one suite
```

| Suite | Cases | Covers |
|---|---|---|
| `render` | 320 | renders every tab **under both roles**, real markup, fails on any PHP notice |
| `ui-hooks` | 148 | the JS↔markup↔CSS contract (`DESIGN.md` §11.3) |
| `security` | 139 | every finding in `SECURITY.md` has a named block here |
| `ipmonitor` | 107 | address resolution, access-log guard rails, duplicate suppression |
| `legacy-rename` | 80 | the pre-rename data migration — the highest-risk code in the plugin |
| `ipaccess` | 78 | allow/block lists, CIDR maths, lock-yourself-out safety |
| `protocol` | 72 | H-3/M-3 wire format and the four states a mixed-version pair passes through |
| `firewall` | 66 | what the content allow-list keeps and removes |
| `hardening` | 57 | M-4/M-5 transport and payload limits |
| `retention` | 45 | log purging, and that nonces are *not* swept on the retention cutoff |
| `termmatch` | 40 | taxonomy term matching |
| `nonce` | 37 | the replay store's atomic claim and its fail-open path |
| `idspace` | 42 | the four §26 identity fixes: queue-status keying, option ids, index truncation, rollback replay |
| `access` | 33 | capability matrix, per-user allow/block, lockout safety (§21) |
| `metablock` | 33 | ignored vs deployable meta, the filter (§23) |
| `encoding` | 33 | what happens when `wp_json_encode()` fails, at all six call sites (§26) |
| `postmatch` | 30 | ID-parity corroboration, `post_date_gmt`, featured-image parity (§26) |
| `contracts` | 28 | **cross-file agreements** — see below |
| `metadelete` | 22 | meta removed on Staging is removed on Production, and what cannot be (§26b) |
| `mediadiff` | 28 | the media "View changes" diff, and what it must never report (§26b) |
| `queue-revert` | 39 | edit → deploy → edit → undo (§23), undo without ever deploying, one row per object (§26b) |
| `url` · `rollback-diff` | 17 each | URL variants (§19); snapshot → package semantics (§22) |
| `verifier` | 16 | which queue rows may be cleared; fails closed (§23) |
| `meta` | 14 | deep meta rewriting, serialization round-trip (§19) |
| `media-url` · `media-match` · `media-identity` | 11 / 10 / 9 | the media pipeline (§12, §23) |
| `byref` | 9 | the `array_walk_recursive` fatal and its fix (§20) |
| `signature` · `syncheck` | 7 each | signature stability (§16); the shared in-sync rule (§23) |
| `concurrency` | 36 | multi-user deploys of the same object (`SECURITY.md` §9.5) |
| `diff` | 13 scenarios | field-level diff output (§19) |

### `contracts-test.php` — the one that catches what nothing else can

Every assertion in it spans **two files that must agree on a string**, where drift produces no
error and no failing unit test:

| Pair | Symptom if it drifts |
|---|---|
| AJAX action names: PHP registers ↔ JS posts | the button silently does nothing |
| i18n keys: PHP sends ↔ JS reads | `undefined` in a confirm dialog — a destructive action with no description |
| JS object handle; enqueue ↔ localize handle | all JS silently dead |
| CSS scope class: PHP emits ↔ stylesheet | the screen renders completely unstyled |
| nonces: created ↔ verified | a form that accepts a forged POST, or one that can never submit |
| tables/options: code ↔ `uninstall.php` | data left behind forever |
| REST namespace + the six headers, declared once | 404 on every deploy |

This existed as a manual grep pass during the rename. It is a file now so the next change does
not need the same pass.

### Repo-wide checks

- `psr4.php` — every class sits where the hand-rolled autoloader will look.
- `unused-imports.php` — leftover `use` statements.
- `missing-imports.php` — the **inverse**, and the one that caught a real shipped bug: inside a
  namespace an unimported `ContentFirewall::mode()` resolves to the *current* namespace and
  fatals when that line runs. Invisible to `php -l`, to PSR-4 and to unused-imports. It shipped
  on a Production-only settings path, so every test that rendered the screen as Staging passed
  while the real thing was a 500.
- `contrast.php` — every palette pair meets WCAG AA (`DESIGN.md` §3).
- `dead-code.php` — classes, methods and constants nothing reaches. Heuristic, so it reports
  rather than failing; hook callbacks and REST handlers have no in-repo caller by design.
- `jsbalance.php` — string/comment-aware bracket balance for `admin.js`, standing in for
  `node --check` now that Node is gone. Not a parser.

### Lessons that are now conventions

- **A test that cannot demonstrate the bug it guards is not evidence.** The first
  object-injection fixture used `O:10:"DP_Gadget"` — the class name is 9 characters, so
  `unserialize()` bailed before instantiating and the "safe" assertion meant nothing. Suites now
  assert the fixture is a *live vector* first.
- **Strip comments before asserting on source.** Three assertions failed because the docblock
  above the code explains the very thing being searched for. Use `php_strip_whitespace()`.
- **Normalise line endings before a `/m` regex.** The repo is mixed CRLF/LF; `$` sits after the
  `\r`, so an un-normalised pattern matches nothing — and comparing against an empty list
  passes vacuously.
- **Never assert on built CSS as raw text.** `admin.css` is generated, and its source header
  documents both `--watch` (expanded) and `--minify` (collapsed). Eleven assertions went red on
  a rebuild with nothing wrong. Use `tests/lib-css.php` (`dp_css_has`, `dp_css_rule`).
- **Never use `glob()`.** The repo path contains `[22020]`, parsed as a character class →
  zero files, silently.
- `QueueRepository`, `PostExporter` and `DeployClient` are `final`, so collaborators cannot be
  stubbed by subclassing. Where a decision needed testing it was extracted into a pure
  `public static` method (e.g. `QueueVerifier::should_clear()`).

**Gap worth knowing:** these are unit-level. Nothing exercises a real signed round-trip between
two WordPress installs, media downloads, or the admin screens end to end — those have only been
tested by hand on the live pair.

---
## 25. Plugin name — decided for this build, open for wordpress.org

**This build ships as `IFS Deploy` / `ifs-deploy`.** The public-directory name is still open; the
research that led here:

- The slug `ifs-deploy` is free, but the name reads as *code* deployment
  (WP Pusher / DeployHQ territory) when this plugin deliberately does not touch code.
- **[migro.dev](https://migro.dev/) is a direct competitor** — "content deployment and
  promotion for modern WordPress workflows", migrating individual posts with Yoast,
  ACF and taxonomy metadata. So `Migro` and every near-miss (`Migrio`, `Migrato`) are
  out: guideline 17 forbids leading with another brand, and it would market for them.

Verified free at the time of checking: `stagio`, `portro`, `publo`, `shifto`,
`vecto`, `stage2live`, `go-live-kit`, `stage-to-live`, `push-to-live`, `content-pilot`.

### Two names, decided separately

| | |
|---|---|
| **This build** | **IFS Deploy** — the display name, for the IFS Copperleaf project. Shipped. |
| **wordpress.org** | **Still open.** `stagio` and `portro` were the front-runners and were free when checked. |

They are separate decisions on purpose. **IFS Deploy is not a candidate for the public
directory**: `IFS` is the trading name of a large ERP vendor (ifs.com), and guideline 17 is
about exactly that — leading with a brand you do not own. Fine for a build that only ever runs
on the client's own two sites; a rejection risk on submission.

### What the rename touched

Done in two passes, and the second one is the reason `Support\LegacyRename` exists.

**Pass 1 — display only.** Plugin header, menu label, screen titles, `readme.txt` and the prose
in every docblock: 53 occurrences, 29 files. Internal identifiers untouched, verified by
counting them before and after (1844 both times). Zero risk, no migration.

**Pass 2 — internals as well.** 2799 replacements across 126 files, in a deliberate order,
because one spelling cannot serve every context:

| Context | Form | Why not just `ifs-deploy` |
|---|---|---|
| PHP namespace, JS object | `IfsDeploy` | a hyphen is not a valid identifier |
| PHP constants | `IFS_DEPLOY_` | same |
| Options, tables, post meta, hooks, filters | `ifs_deploy_` | `wp_ajax_ifs-deploy_x` matches no hook; a hyphen in an unquoted SQL identifier is a syntax error |
| Text domain, REST namespace, page slug, CSS | `ifs-deploy` | slugs are hyphenated |
| HTTP headers | `X-IFS-Deploy-` | header casing |
| Prose and labels | `IFS Deploy` | it is a name |

The `dp-` CSS prefix and the `dp_` transient prefix were **left alone**: they are opaque
three-letter prefixes nobody reads as a brand, and renaming them would touch several hundred
call sites across PHP, CSS and JS for no visible gain.

### The part that is not a find-and-replace

Renaming the identifiers renames where the DATA lives. An existing install has options,
tables and post meta under the old names, so the code alone would come up as a fresh install:
credentials regenerated, pairing lost, rollback points unreachable, and — worst — every page
missing its `_ifs_deploy_origin_id`, so the next deploy would create a duplicate of it rather
than updating it.

`Support\LegacyRename` migrates all three, once, on both sites. See its class docblock for the
ordering and why the object cache has to be flushed. **A pair must be updated in the same
window**: the REST namespace and the six `X-IFS-Deploy-*` headers are wire format, and the
protocol negotiation in SECURITY.md §11 does not help — that covers signature format, not the
URL path or header names.

---

## 26. Open review findings (from the full code review)

Fixed since the review: meta slashing, the Settings remote-wipe, import ordering,
`_oembed_`/`_transient_` inconsistency, missing per-object error reporting, the
30s timeout.

**All eight remaining findings are now FIXED**, with regression coverage (§24:
`postmatch`, `encoding`, `idspace`). None of this was touched by the two security rounds,
deliberately: these are *feature-correctness* bugs, not vulnerabilities, and mixing the two
would have made either audit impossible to review.

Each suite was run against the pre-fix code first and shown to fail — `postmatch` alone
went 16-red — because a test that cannot demonstrate the bug it guards is not evidence
(§24).

### 1. `match_by_id` could overwrite unrelated content — fixed

`PostImporter::match_by_id()` matched on ID + post_type alone, so on sites that were NOT
cloned, Staging post 412 silently overwrote whatever unrelated page Production kept at 412.
No error, no duplicate, no warning, and the pre-deploy snapshot made it look ordinary.

`locate()` now corroborates parity through **`id_parity_is_same_post()`** — the post-side
counterpart of the media fix in §12 — which refuses on two kinds of evidence:

1. the target is **stamped** as somebody else's (different origin site, or a different
   origin id on this one); that is proof, so it always refuses;
2. **nothing** about the target agrees: not the slug, not the title, not the publish date.

Any *one* of those three matching is enough, and that is deliberate. Requiring the slug
alone would break the ordinary case of renaming a slug on Staging — parity would refuse,
the slug strategy would miss, and the deploy would insert a **duplicate of the very page it
meant to update**. Every refusal is logged.

Note what is *not* changed: `map_parent()` still uses raw parity, because the package
carries no parent slug and the failure there (a wrong parent) is visible and reversible
rather than silent data loss.

### 2. Queue-status mapping collided across types — fixed

`DeploymentService` keyed `$mapped` by object id alone, and post/term/attachment/option ids
share one integer space. A batch holding post 42 and term 42 cross-assigned their outcomes:
the term's failure marked the *post's* row failed, and the post's success marked the term
deployed — stamping a `deployed_hash` for content Production never accepted, which drops the
row out of Pending Changes for good. Now keyed via **`DeploymentService::map_key()`**
(`type|id`, public + static so the pairing is testable without a database). `finalize()`
reads `$result['type'] ?? 'post'`, matching `ImportManager`'s own default so a Production
too old to send a type behaves exactly as before.

### 3. `post_date_gmt` was exported but dropped on import — fixed

The importer now passes it through. Core was re-deriving it from `post_date` with
`get_gmt_from_date()` using the **receiving** site's timezone, so every deployed date
shifted by the offset between the two sites — visible only in feeds, REST output and
scheduling, since `post_date` itself was copied verbatim. An empty value (what an older
Staging sends) keeps the old behaviour.

### 4. `wp_json_encode()` failure was never checked — fixed

New **`Support\Json::encode()`** returns `?string` instead of `false`, so each caller has to
decide what its own failure looks like — and every one of those decisions now fails closed:

| Call site | Was | Now |
|---|---|---|
| `Queue\Hasher` | `md5('')` — the same hash for every unencodable object, so a real edit read as "nothing changed" and two unrelated objects read as identical | falls back to `md5(serialize())`, which keeps varying |
| `Rollback\SnapshotStore` | empty snapshot → History offered a Rollback button that restored nothing | no revision row, logged; History correctly shows no rollback available |
| `Client\DeployClient` | empty body → a well-formed POST Production accepts, reporting success over zero objects | `WP_Error`, nothing sent |
| `Support\ContentSignature` | `md5('')` → two unrelated pages compare as "in sync" | `null`, which every caller already treats as unknown |
| `History\DeploymentRepository` | invalid JSON → History renders a deployment with no per-object errors | `?? '[]'` |
| `Support\PackageDiff` | already handled | routed through `Json` too, so there is one seam |

`wp_json_encode()` is more robust than it looks — core retries through
`_wp_json_sanity_check()`, which strips invalid UTF-8 — so the reachable vectors are depth
beyond 512 (nested ACF flexible content gets there), INF/NAN and recursion. `encoding-test`
asserts its fixtures are live vectors before asserting anything about the handling.

### 5. `SiteIndex` truncated at 2000 with no signal — fixed

`build()` became **`report()`**, returning `{index, truncated, limit}`. Overflow is detected
by fetching one extra row, so knowing costs no second query. `/index` passes `truncated`
across the wire, `CompareService` checks **both** sides and `ComparePage` renders a warning
*above* the summary cards. This mattered because silence produced a confident wrong verdict,
not a blank screen: everything past the cut on Staging read as "Not on Production" and
everything past the cut on Production as "Only on Production" — and acting on the first
creates a duplicate of a page that already exists.

### 6. Production never marked a rolled-back deployment — fixed

`RollbackEndpoint` now refuses an already-rolled-back deployment with **409** (distinct from
a rollback that restored zero objects) and records `STATUS_ROLLED_BACK` — but only when
something was actually restored, since a run that restored nothing changed no state and
marking it would strand the deployment. Replaying is not the no-op it looks like: roll back
deploy B then deploy A and the object correctly sits at A's "before" state; replaying B
afterwards silently drags it forward again. Staging already hid the button, but that guard
disappears when history is cleared and was never binding on a receiver.

### 7. `crc32` option ids could collide — fixed

The queue's UNIQUE key is `(type, subtype, object_id)` and an option's subtype is always
`''`, so a collision did not error — it **merged**: the second option overwrote the first
row's title and hash, and the first silently stopped being tracked. `OptionExporter::option_id()`
now takes the **top 60 bits of an md5**, moving the birthday bound from ~77,000 names to
~1.5 billion while still fitting `bigint(20) unsigned`. `SnapshotStore` had a *second copy*
of the crc32 expression and now delegates, because drift there would file revisions under an
id `prune()` and every lookup no longer find.

Changing the derivation moves where the data lives, so **DB version 6** re-keys existing
option rows (`Schema::rekey_option_rows()`). Row by row in PHP, not one bulk `UPDATE`: the
UNIQUE key means a row moving onto an id a not-yet-migrated row still occupies would abort
the statement partway and leave the table half-converted.

### 8. Featured images lost ID parity — fixed

`media_sideload_image()` cannot pass `import_id`, so the image landed on a fresh Production
id — and since meta is copied verbatim, every raw ACF image field pointing at it broke, or
worse pointed at an unrelated attachment holding that id. It only worked when the image also
happened to travel as its own queued media object.

`PostExporter` now sends the source attachment `id`, and `apply_featured_image()` routes
through **`MediaImporter`**, picking up the size cap, the concurrent-download claim, the
year/month folder and the origin stamps as well. The sideload path remains as the fallback
for packages from an older Staging.

One consequence worth knowing: adding a key changes the package hash, so **each post with a
featured image queues once more on its next save** even if nothing changed. It settles by
itself — `ContentSignature` is unaffected (it reports filename and alt only), so
`QueueVerifier` finds the object already in sync and clears the row, and `PackageDiff`
compares images by filename and alt so the dialog shows nothing either.

---

## 26b. Three field-reported bugs (fixed)

Reported from the live pair after §26 landed. All three were about the queue and the
importer telling the truth about *removals* and *identity*.

### 1. Deleting a Yoast meta description did not sync

Setting a value deployed; clearing it did not. Yoast **deletes**
`_yoast_wpseo_metadesc` when the field is emptied, so the key vanished from the package
— and `PostImporter::apply_meta()` only ever wrote the keys it was given. The old
description stayed live for ever, with both screens reporting the object as in sync.

`PostImporter::remove_meta_deleted_on_source()` now deletes deployable meta the package
no longer carries. **The package is the complete deployable meta set** (`PostExporter`
exports all of `get_post_meta()` minus `Support\MetaBlocklist`), so a key on Production
and not in the package is either deleted on Staging or is meta this plugin should never
have touched — and the blocklist is exactly what decides that. Editor locks,
`_thumbnail_id`, oEmbed/transient caches and `_ifs_deploy_*` are never exported, so they
can never be "missing from a package" and are structurally out of reach.

Two escape hatches: `ifs_deploy_ignore_meta_key` protects a Production-only plugin's meta
(one list decides both what deploys and what is left alone), and
`ifs_deploy_delete_missing_meta` turns the whole behaviour off.

**This reverses a documented §19 decision**, so `PackageDiff` moved with it: meta absent
from a package is now `removed`, not `kept`. Taxonomies and the featured image keep the
`$full_replace` distinction, because the importer still only replaces those when the
package mentions them. Covered by `metadelete-test.php` (22 cases).

### 2. Reverting an edit left the row in Pending Changes

§23 solved this with `deployed_hash`, but only for objects **already deployed by IFS
Deploy**. The reported flow is the other one: edit something, decide against pushing it,
put it back. Nothing was ever deployed, so there was no `deployed_hash` — and the row
only ever remembered the state it was changed *to*.

The queue now also carries **`baseline_hash`** (DB v7): the state an object held before
its pending change began. Editing back to it resolves the row to the new status
`unchanged` — deliberately not `deployed`, which would assert something false and would
let `Schema`'s v3 backfill stamp a `deployed_hash` for content Production has never seen.

It is populated two ways:

- **Free, for every object type.** A row leaving a settled state supplies its own
  baseline: whatever it held while settled *is* the state to return to.
- **`PostObserver::on_pre_update()`**, hooked to `pre_post_update`, for a post with no
  row at all. That hook is the last moment the previous state is still readable — it
  fires before the row is written and before any meta box, ACF or block-editor meta
  write. Cost is one export on the first edit of an object and none after, because a post
  that already has a row is skipped. A baseline equal to the hash being stored is
  discarded, so a "before" captured too late can never resolve a real change.

Gap worth knowing: terms, options, media and menus have no pre-edit hook, so the *first*
change to one of those still relies on the free path (which needs an existing settled
row) or on `QueueVerifier`.

### 3. One media update produced several tracked items — and the real cause

Reported as eight rows for one attachment. **The diagnosis is worth recording, because
the obvious reading was wrong.** All eight showed `media` + `image/png`, and the queue
has `UNIQUE KEY object_identity (object_type, object_subtype, object_id)` — so those
eight rows are arithmetically impossible while that key exists. Confirmed with the user
that only ONE image existed, which leaves exactly one explanation:

**The UNIQUE key was not on the table.** `dbDelta()` declares it in `CREATE TABLE` but is
unreliable at *adding* an index to a table that already exists, and it reports nothing
when it fails. Without it, `upsert()`'s read-then-write is not atomic — two concurrent
saves of one object both find nothing and both insert. Eight rows in the same second is
the signature of exactly that. It is also self-perpetuating: MySQL refuses to add a
unique index while duplicates exist, so the rows it allowed then block its own repair.

Four layers now, so correctness never depends on any single one:

1. **`Schema::ensure_object_identity_index()`** asks the table (`SHOW INDEX`) rather than
   trusting dbDelta, clears duplicates, then re-adds the key. Runs on activation and on
   every schema upgrade. A database that still refuses it is logged, not fataled.
2. **Identity no longer includes the subtype.** `QueueRepository::find()` keys on
   `(object_type, object_id)`. The subtype is a mime type for media and a post type for
   posts — both *content*, both able to change while the object stays the same one, so
   each distinct value used to get a row the others never deduped against. Object ids are
   unique per type in WordPress, so nothing is lost.
3. **`get_by_status()` dedupes on read**, keeping the highest id per object. Status is
   deliberately *not* in that subquery: the invariant is one row per object outright,
   which is what the UNIQUE key enforces too.
4. **`collapse_duplicates()`** physically removes duplicates when Pending Changes renders
   — hiding is not fixing, since a hidden row still counts in `users_with_status()`. It
   runs *before* verification, or a stale duplicate would be probed against Production
   and offered for pushing alongside the row superseding it.

Separately, the list was **confusing even when correct**: WordPress names an attachment
after its file, so uploading `test.png` repeatedly gives every copy the title `test`, and
the Object column showed nothing else. Pending Changes now prints the object id
(`test #1240`), as Compare & Sync always has.

**And that id column immediately paid for itself.** The next report showed eight rows
with ids 63817–63824 — **consecutive**, same second, seven titled `sstest` and one
`1223`. Consecutive ids mean eight attachments genuinely exist: the queue was tracking
eight real objects, and something else on the site was creating the other seven. Nothing
inside the queue can fix that, so three things were added instead:

- **Generated sizes are never tracked** (`AttachmentObserver::is_generated_size()`). A
  `-300x200` derivative is output, not content — §12 already withholds
  `_wp_attachment_metadata` so Production regenerates its own sizes. Plugins that
  register derivatives as real attachments would otherwise give each one a pending
  change. Precision matters here: a genuine upload called `banner-1920x1080.jpg` matches
  the same pattern, so it is only skipped when the **un-suffixed file exists as an
  attachment in its own right** — which is what makes it a derivative *of* something.
- **`ifs_deploy_track_attachment`** excludes anything else a given site produces.
- **Every media queue write is logged** with the FILE, the hook (`current_filter()`) and
  the first non-plugin stack frame. That is what settles a live case: several distinct
  files means several uploads, `-WxH` names mean generated sizes, and the same path twice
  means duplicate rows for one file. The backtrace uses
  `DEBUG_BACKTRACE_IGNORE_ARGS` — argument values can hold post content and credentials
  and must never reach a log.

Media rows also show their file name now, since `sstest.png`, `sstest-1.png`,
`sstest-2.png` explains at a glance what several same-titled rows actually are.

**And that file name settled it.** The next report showed seven rows, ids 63818–63824,
every one with `_wp_attached_file` = `sstest-1.png`. Not generated sizes (no `-WxH`), not
separate uploads (those would be `sstest-2.png`, `sstest-3.png`): **seven attachment
records for ONE file.**

That makes merging them correct rather than merely tidy. `MediaImporter::find_existing()`
resolves an incoming attachment by its recorded **source URL**, and records sharing a file
share a source URL — so pushing all seven produces exactly ONE attachment on Production,
each overwriting the last. Seven rows described an outcome that could not happen.

- **`AttachmentObserver::canonical_for_file()`** redirects every duplicate to the LOWEST
  id. Deterministic on purpose: whichever record is saved, in whatever order, all of them
  converge on the same single row — verified in both directions. The lowest id is also the
  original record; the rest are the artefacts.
- **`QueueRepository::collapse_media_by_file()`** does the same for rows already written,
  because those six duplicates may never be saved again and nothing else would ever clear
  them. Meta for every row is primed in one query rather than one per row.
- An attachment whose file is **unknown** is left strictly alone — with nothing to group
  on, "duplicate" cannot be established, and this must never guess.

Worth stating plainly in the handover: this treats the SYMPTOM. Something on that site is
creating seven attachment records per file, which pollutes the Media Library too. The
`triggered` field in the log names the caller.

Covered by `queue-revert-test.php` (39), `idspace-test.php` and `mediatrack-test.php`
(20, including the reported case in both save orders).

### 4. Media rows now have a "View changes" preview

Previously the Preview column showed "—" for media, so after editing an image there was
no way to see what had actually changed. Media rows now open the same dialog a page does,
built by the same `Admin\DiffRenderer` — no special case in the renderer at all.

- `PackageDiff::compare()` **dispatches on the package type**. A media package has no
  `post_content`, no taxonomies and no featured image, so running it through the post
  shape would report its real differences as nothing at all.
- Compared: title, caption, description, slug, file type, order, alt text and attachment
  meta.
- **Not** compared, because each would flag every object on every push: `source_url`
  (carries the domain), `post_date` (upload time), `post_parent` (a per-site id).
- The **filename is** compared, but `Rest\ObjectEndpoint` substitutes
  `MediaIdentity::stable_filename()` into Production's reply first, recovering the
  original name from the source-URL stamp. Otherwise a `-1` suffix Production added
  itself (`wp_upload_bits()` never overwrites) would read as a difference for ever — the
  same trap §23 fixed for featured images. Safe to substitute because that package is
  only ever diffed; a real import builds its own on the sending side.
- Production resolves the attachment with `MediaImporter::find_existing()` — the same
  matcher the import uses, so the panel describes the attachment a push would really
  update.

Covered by `mediadiff-test.php` (28 cases). Terms, options and menus still show "—".

---

## 27. History (what happened, in order)

Phases 1–22 built the plugin: MVP, HMAC auth, smart queue, snapshots + rollback, Compare & Sync,
ID parity, media, menus, Preview Changes, `UrlRewriter`, Logs & Diagnostics, role-based access,
confirmation dialogs, `MetaBlocklist` / `deployed_hash` / `QueueVerifier`. Those are documented in
their own sections above.

What happened after that, and why:

23. **UI redesign** — Tailwind v3 standalone (no Node), six screens folded into one AJAX-switched
    tab shell, a bespoke palette with a WCAG contrast test. See `DESIGN.md`.
24. **Security audit round 1** → `SECURITY.md`. Found C-1 (object injection via
    `maybe_unserialize` on payload data — RCE), H-1 (no replay store), and the M/L set.
25. **Security audit round 2** — prompted by "secure the API communication *both ways*". Added
    H-2 (content firewall), H-3 (response signing), M-3 (route binding), M-4/M-5 (transport and
    payload limits), M-6/M-6a/M-6b (lockout, API access log, address rules), L-5.
26. **Multi-user concurrency** — a 10–15 person team meant two people could push the same object
    at once. Per-object locks, and a "Push All" fix where the confirm dialog promised 12 and the
    server pushed 3.
27. **Protocol v2 rollout** — H-3 and M-3 change the wire format, so they were expected to need a
    coordinated release. Solved by negotiation instead (verifier accepts v2-then-v1, client signs
    v1 until the peer proves v2, one-way ratchet). Either site can be updated first.
28. **Duplicate-delivery noise** — the access log filled with "Duplicate suppressed". Root cause
    was transport double-delivery, not an attack; the real bug was that it counted as a *failure*,
    inflating the summary and firing the suspicious-address alert about the site's own peer.
29. **Production UI slimming** — hid Pending Changes / Compare & Sync / Deployment History and
    Settings → Role Management on a receiver, because all read Staging-only data. Overview
    redesigned as an environment pair with a "This site" marker.
30. **`missing-imports.php`** — written after "Log Retention tab shows *Request failed*" turned out
    to be an unimported class fataling on a Production-only path.
31. **Two renames.** Display `Stagio → IFS Deploy`, then internals `deploypress → ifs_deploy_`
    (§2). The second needed `Support\LegacyRename` (§29).
32. **Release prep** — dead-code sweep, `contracts-test.php`, `readme.txt` rewrite,
    `build-release.php` + `.distignore` (§29).

### Corrections worth carrying forward

Recorded because they change how much of the older analysis to trust:

- **SSRF in the media importer was rated HIGH, then found not exploitable.** `download_url()`
  goes through `wp_safe_remote_get()` → `wp_http_validate_url()`, which already blocks private
  and loopback targets. Established by reading core, not by assuming.
- **"kses would corrupt block markup" was wrong.** Measured against real WordPress: block
  comments, block attribute JSON, nested blocks, `data-*`, inline `style` and shortcodes all
  survive. What actually breaks is `srcset`/`sizes`, `iframe`, `form` and inline `svg` — which is
  why H-2 ships an *extended* allow-list rather than either extreme.
- **§7 of `SECURITY.md` claimed "Regenerate Credentials already does it".** It did not — no
  handler existed. Written from reading the form, which had a button, a dialog and a correct
  nonce field. Became M-7.
- **A signature-token impact was overstated.** Renaming it was described as breaking ~2000
  objects; tracing `SyncCheck::in_sync()` showed the fresh-recompute branch still matches. The
  token is still frozen, but for the snapshot sentinel's sake, not that reason.

---

## 28. Release build

```bash
php build-release.php        # → ifs-deploy-0.1.0.zip (98 files, ~552 KB)
```

**The trap it exists to avoid:** the checkout is still named `deploypress`, and WordPress takes
the plugin slug from the folder *inside* the zip. Zipping by hand produces a package that
installs as `deploypress` with a text domain that no longer matches — and the mismatch is
silent: everything works except translated strings. The script writes `ifs-deploy/` regardless.

It **refuses to build** when something is actually broken: version disagreeing across the plugin
header / `IFS_DEPLOY_VERSION` / readme `Stable tag`; text domain not equal to the slug;
`admin.css` older than `admin.src.css` (editing the source without re-running Tailwind is a
silent no-op); `.distignore` excluding a required file or letting a dev file through.

It **warns and continues** for things that are merely incomplete — `Author`, `Plugin URI`,
`Contributors`. The split is deliberate: refusing there would block a perfectly good internal
release.

`.distignore` is the single exclusion list (also honoured by `wp dist-archive`). Excluded:
`tests/`, `tools/` (39 MB Tailwind binary), `DESIGN.md`, **`PLUGIN-CONTEXT.md`** (this file —
internal notes, competitor research, must not be published), `SPRINT-PLAN.txt`, `composer.json`,
`tailwind.config.js`. **`SECURITY.md` does ship** — `readme.txt` points users at it, and
everything in it is derivable from the PHP source, which ships anyway.

---

## 29. Upgrading a pre-rename install (`Support\LegacyRename`)

Renaming the internals renamed *where the data lives*. Without this migration an existing install
boots as if it had never been configured:

| Missing | Effect |
|---|---|
| `ifs_deploy_credentials` | `Credentials::get()` regenerates → the shared secret changes → the peer is refused on every request |
| `ifs_deploy_role` | defaults to Staging; a Production site then answers 409 to every import |
| `{prefix}ifs_deploy_revisions` | `Schema::install()` creates an empty one → every rollback point is on disk but unreachable |
| `_ifs_deploy_origin_id` on every post | Production stops recognising its own pages → **the next deploy duplicates all ~2000 of them** |
| capability keys *inside* `ifs_deploy_roles` / `_users` | every delegated role loses access. Administrators keep theirs (hard-coded), so the owner sees nothing wrong while the team is locked out |

`run()` fires **before `Schema::maybe_upgrade()`** from both `Plugin::boot()` and
`Activator::activate()` — the schema check reads the new db-version option, finds nothing, and
would install a fresh schema *beside* the real tables, after which a rename is impossible.

Properties: idempotent (marker option, then one autoloaded read); collision-safe (if both names
exist the **new** value wins — renaming onto a live key would trip the UNIQUE index on
`option_name` and abort everything after it); flushes the object cache (renaming rows under a
warm cache looks exactly like the migration not running); scoped to an explicit 15-key list, not
a `LIKE` sweep that would rename another plugin's options.

**Uninstalling does not remove post meta.** So even a delete-and-reinstall leaves
`_deploypress_origin_id` on every post — the migration is required either way.

**Both sites must be updated in the same window.** The REST namespace and the six
`X-IFS-Deploy-*` headers are wire format; the v1/v2 negotiation covers the signature *format*,
not the URL path or header names, because the request never reaches the verifier.

Once the migration has run on both sites, `Support\Legacy`, `Support\LegacyRename` and
`tests/legacy-rename-test.php` can be **deleted outright** — that is the intended endgame and
the only way the last 13 old-name strings go away.

---

## 30. How to continue in a new chat

Point the session at this file and the plugin dir. State of play: **1545 assertions green**,
`SECURITY.md` has nothing outstanding, **§26 is fully closed**.

Suggested order:

1. **End-to-end smoke test on the live pair.** The suites are unit-level — nothing exercises a
   real signed round-trip, media download, or the admin screens end to end. Do this before
   anything else, especially the migration (§29). It matters more than usual now: §26 touched
   the import matcher, the media path and the schema, and none of that has run against real
   WordPress yet.
2. **Re-run the Tailwind build.** `assets/css/admin.css` is STALE relative to
   `assets/css/src/admin.src.css` in this checkout — the source declares a card shadow and the
   whole `.ifs-deploy .dp-env-card.is-self` rule, and neither is in the built file (`is-self`
   appears zero times). That is the silent no-op DESIGN.md warns about, and mtime does not
   catch it because both files carry their checkout time. Two assertions in `render-test.php`
   are commented out waiting on it, with the exact lines to restore.
3. **Rotate the two WP Engine passwords** — still live in git history (§1).
4. **Set `Author`, `Plugin URI`, `Contributors`, `Tested up to`.** The build warns about the
   first three.
5. **Delete the legacy migration** once §29 has run on both sites.
6. Extend Compare & Sync and the preview to terms / options / media / menus.

### If the plugin goes to wordpress.org

`IFS Deploy` is **not** a viable public name: `IFS` is the trading name of a large ERP vendor,
and guideline 17 is about exactly that. Fine for a build that only runs on the client's own two
sites; a rejection risk on submission. `stagio` and `portro` were free when checked (§25).

Decide then whether the .org release keeps `ifs_deploy_` internals under a different display
name (cheap, and what this build does) or renames them too — a DB migration plus a coordinated
release, because the slug is fixed once approved.
