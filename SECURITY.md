# IFS Deploy — Security

**Audited:** internal code review · **Verify with:** `php tests/run-all.php` (2065 assertions)

Every issue found in this plugin has been fixed, and each fix has a regression test named
after it. Nothing is outstanding.

**Nothing found was exploitable by a stranger.** Every issue needed either the shared secret
(so: control of the paired Staging site) or an already-logged-in administrator. That is the
expected shape for a deploy plugin — but they were still worth fixing, because the whole
point of the Production side is to limit what a compromised Staging site can do to it.

---

## 🔴 Pending

**Nothing.** All 17 findings are fixed and tested.

One known limitation, deferred on purpose rather than overlooked:

- **A page edited by two people belongs to whoever saved last.** The queue keeps one row per
  object. If Aman edits a page and Priya then edits it, the row becomes Priya's and leaves
  Aman's Pending Changes — and Priya's push carries Aman's edits too, because they are the
  same page. Not a security hole, but it can surprise a team. Fixing it means tracking
  several contributors per row: a database migration and a Pending Changes redesign.

---

## 🟠 Blocked — deliberate decisions, not oversights

- **The shared secret is stored in plain text** in `wp_options`. WordPress offers no
  key-management facility, so this matches every plugin that holds an API secret. It is
  `autoload = false`, so it is not loaded on every page, and it is never shown outside the
  administrator-only Settings screen. Anyone who can read the database already has the whole
  site. **Accepted.**

- **No per-API-key rate limit.** A Staging site has exactly *one* API key, so such a limit
  would apply to the entire team combined, never per person — fifteen people opening Pending
  Changes after a standup would trip it. Worse, the failure direction is wrong: a refused
  index request makes Compare & Sync show nothing, which reads as *"everything is in sync"*
  when it is not. A control whose failure mode is a false all-clear is worse than none.

  What it would guard against is already covered: unauthenticated flooding by the lockout,
  oversized requests by the batch and body caps, huge files by the size check, repeat
  delivery by the replay store. What remains is a Staging site with a valid secret using up
  Production's resources — and that site can already overwrite every page through a normal
  push. **Declined.** If it ever becomes a real problem the right control is a concurrency
  guard (refuse a second push from the same key while one is running), not a rate limit.

- **No Content-Security-Policy on the plugin's admin screens.** wp-admin sets none, so doing
  it for one plugin's screens buys little. **Declined.**

---

## ✅ Done — fixed and tested

The short codes are how the code refers back here — searching the source for `H-2` finds
every line written because of it.

### The serious ones

- **`C-1` Remote code execution through a pushed payload.** Imported data was passed to
  `maybe_unserialize()`, so a crafted push could build PHP objects and reach any `__destruct`
  or `__wakeup` in WordPress or any active plugin. Payloads are now decoded as data only.
- **`H-1` `H-1a` Signed requests could be replayed.** The nonce was signed but never
  remembered, so a captured request could be sent again. Nonces are now stored in a dedicated
  table whose `UNIQUE` index makes the insert itself the check.
- **`H-2` `H-2b` Pushed content bypassed WordPress's HTML filter.** A deploy has no logged-in
  user, so the filter that normally strips `<script>` never ran and whatever was sent was
  stored as-is. Now filtered, with the mode configurable.
- **`H-3` Production's replies were never authenticated.** The signed channel was one-way, so
  anything in the middle could feed Staging a forged comparison or a forged rollback preview
  — the very screen you read *before* confirming a rollback. Replies are now signed too, and
  a site that has ever signed is never again believed unsigned.
- **`M-3` A signature did not cover which route it was for.** A signature captured for one
  endpoint could be replayed against another. The route is now part of what is signed.

### Credentials and access

- **`M-1` `M-2`** Credentials were written to `debug.log` in clear text; redaction now covers
  values, not just key names.
- **`M-7`** The documented way to rotate a leaked secret did not exist — the button was
  there, the handler was not. It exists now.
- **`L-5`** Signature failures now report a different cause from key mismatches, so a
  misconfigured pair is distinguishable from an attack.
- **`M-6`** Repeated signature failures now earn a lockout: 12 failures in 15 minutes, then a
  15-minute cool-off.
- **`M-6a` `M-6b`** API access monitoring, plus address allow and block lists.

### Limits and hygiene

- **`M-4`** `http://` is no longer accepted for the Production URL.
- **`M-5` `L-2` `L-3`** Caps on request body size, on objects per push, and on the size of a
  media file fetched during an import — checked before the download, then again on disk.
- **`L-1`** `uninstall.php` left data and a scheduled task behind; it now removes everything.
- **`L-4`** A corrupt credentials option silently regenerated the keys, which broke the
  pairing without saying so.
- **Direction guard.** The AJAX layer now enforces the Staging/Production split the REST
  layer already did, and answers the same 409.

### Team safety

- Two people pushing the same object at once produced two deployments and two rollback
  points for one change; objects are now claimed for the duration of a push.
- **"Push All" used to publish other people's work.** Anyone who could *see* the whole team's
  queue could push it. Seeing and publishing are different powers: the default is now your
  own rows, and including others has to be asked for and is administrators only.
- The same new image pushed by two people at once downloaded twice, leaving a duplicate.

---

## Checked and found sound

Audited, needed no change. Listed so a later regression is visible.

| Area | Result |
|---|---|
| REST authorisation | All routes declare a permission callback. No open endpoints. |
| Signature & key comparison | Timing-safe (`hash_equals`), so neither can be guessed byte by byte. |
| Key generation | Cryptographically random — 128-bit key, 256-bit secret. |
| SQL injection | Every query with a variable is prepared. |
| AJAX authorisation | Every action checks capability **and** nonce, and fails closed by default. |
| Queue tampering | Submitted row ids are re-checked against the database, never trusted from the page. |
| Privilege escalation | Capabilities are virtual and leave nothing behind if the plugin is removed. |
| Server-side request forgery | Not exploitable — WordPress refuses private and loopback addresses, including cloud metadata. |
| Arbitrary file upload | `.php` is always refused: an import runs with no logged-in user, so the capability that would allow it is never present. |
| Path traversal | No user-controlled path is ever concatenated. |
| Option overwrite | Default-deny allowlist with a blocklist that cannot be overridden, re-validated on import — `siteurl`, `active_plugins`, user roles, salts and cron are unreachable. |
| TLS | Certificate verification is never disabled. |
| Code-execution sinks | None on request data. |
| Diagnostics | Show nothing beyond what the administrator already owns. |

---

## Two things to know when upgrading

**The two sites negotiate; they do not need updating at the same moment.** Response signing
and route binding change what goes over the wire, but each side accepts the older form until
the other proves it speaks the newer one — and the proof is the reply's own signature.
Update either site first, in any order, with no configuration step. The upgrade is one-way,
so nothing on the wire can talk a pair back down to the weaker form.

**Content filtering starts in report mode on an existing install.** It sanitises nothing at
first. Push once, then read Logs & Diagnostics: anything the filter *would* have removed is
listed there by page name. Switch to filtering when that list looks right. A brand-new
install starts with filtering already on.

---

## Hardening this pair further

- Keep both sites on the same version.
- Rotate the shared secret if it is ever pasted somewhere it should not be — Settings →
  Regenerate Credentials, then re-pair.
- Restrict which addresses may reach Production's API, under Settings → API access
  monitoring.
- Keep the event log's retention short enough that it is actually read.
