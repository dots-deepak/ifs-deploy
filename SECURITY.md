# IFS Deploy — Security Audit

**Audit date:** 7 August 2026 · **Version audited:** 0.1.0 · **Auditor:** internal code review
**Verify with:** `security-test.php` (120) · `firewall-test.php` (66) · `ipaccess-test.php` (78) · `ipmonitor-test.php` (107) · `protocol-test.php` (72) · `hardening-test.php` (57) · `nonce-test.php` (37) · `concurrency-test.php` (36) · `php tests/run-all.php` (**1426**)

This is a record of what was actually found in this codebase and what was done about it.
Every finding below was traced to a specific line, and every fix has a regression test named
after it. Items that turned out **not** to be vulnerabilities are listed too, in §5 — a
security document that only lists problems is impossible to re-verify later.

---

## Summary

| Severity | Found | Fixed | Still open | Highest open item |
|---|---|---|---|---|
| **Critical** | 1 | **1 ✅** | **0** | — *nothing outstanding* |
| **High** | 4 | **4 ✅** | **0** | — *nothing outstanding* |
| **Medium** | 7 | **7 ✅** | **0** | — *nothing outstanding* |
| **Low** | 5 | **5 ✅** | **0** | — *nothing outstanding* |

**Implemented and tested:** C-1 · H-1 · **H-1a** · H-2 · H-2b · **H-3** · M-1 · M-2 ·
**M-3** · M-4 · M-5 · M-6 · M-6a · M-6b · **M-7** · L-1 · L-2 · L-3 · L-4 · L-5 — plus the
transport hardening (`redirection => 0`) that came out of chasing the duplicate-delivery
report, and the AJAX direction guard in §12.

**Nothing outstanding.** Every finding in this document is fixed and covered by a named
regression test.

> **H-3 / M-3 note for the upgrade — no coordinated release needed after all.** These two
> do change the wire format, which is why they were held back, but the sites NEGOTIATE
> instead of requiring a simultaneous update:
>
> - The verifier accepts **v2, then v1**, so an un-upgraded Staging site keeps deploying.
> - The client signs **v1 until the peer proves it speaks v2**, so an un-upgraded Production
>   site keeps receiving.
> - Proof is the peer's own **response signature** — which is sent even in reply to a
>   v1-signed request, and that is what makes the bootstrap work at all.
>
> Update either site first, in any order, with no configuration step. The pair upgrades
> itself on the first successful call after the second site is updated, and the ratchet is
> **one-way** so nothing on the wire can talk it back down. Full reasoning in §11.

> **H-2 note for the upgrade:** existing installs land in **`report` mode**, which sanitises
> nothing. Deploy once, then read Logs & Diagnostics — anything the filter *would* remove is
> listed there by page name. Switch to `filter` when that list looks right. A brand-new
> install activates straight into `filter`.

Nothing found is exploitable by an **unauthenticated** attacker. Every finding requires
either a valid HMAC signature (i.e. possession of the shared secret, or control of the
paired Staging site) or an authenticated admin session. That is the correct shape for this
plugin — but it is not a reason to leave them, because the whole point of the Production
side is to bound what a compromised Staging site can do to it.

> ### Round 2 — status
>
> **Done and tested:** H-2, H-2b, M-4, M-5, M-6, M-6a, M-6b, L-2, L-3, L-5. None of them
> touches the wire format, so none can break the live pair — that is why they came first.
>
> **Also done:** H-3 + M-3 (response signing + route binding). They were held for a
> coordinated release because they change the wire format; the negotiation described in §11
> removed that requirement, so either site can be updated first.
>
> The headline round-2 item was that **Production's responses were never authenticated
> (H-3)** — the signed channel was one-way, which I had not flagged in round 1. It is now
> two-way, and a peer that has ever signed is never again believed unsigned.

---

## 1 · Round 1 — CRITICAL

> **✅ Nothing outstanding at CRITICAL severity.** C-1 below is the only finding at this
> level and it is fixed, in the codebase, and covered by 25 assertions.
>
> Re-verified 10 August 2026:
>
> - No import or restore path calls `maybe_unserialize()` on payload data
>   (`grep -rn maybe_unserialize src/Import/ src/Rollback/SnapshotStore.php` → empty).
> - All four write paths route through `Support\SafeData` — `PostImporter`,
>   `MediaImporter`, `TermImporter`, `SnapshotStore`.
> - `php tests/security-test.php` → the *"object injection in imported meta"* block passes,
>   **including the assertion that the fixture is a live vector**, so the passing result
>   means the exploit is really blocked rather than merely absent.

### C-1 · PHP Object Injection via `maybe_unserialize()` on payload data — ✅ FIXED &amp; VERIFIED

| | |
|---|---|
| **Files** | `src/Import/PostImporter.php:355`, `src/Import/MediaImporter.php:292`, `src/Import/TermImporter.php:98`, `src/Rollback/SnapshotStore.php:201,332` |
| **CWE** | [CWE-502](https://cwe.mitre.org/data/definitions/502.html) Deserialization of Untrusted Data |
| **CVSS 3.1** | 8.8 High — `AV:N/AC:L/PR:L/UI:N/S:U/C:H/I:H/A:H` |
| **Impact** | Remote code execution on the Production site |
| **Test** | `security-test.php` → *"CRITICAL: object injection in imported meta"* |

**What was wrong.** The import endpoints receive a JSON body. JSON carries only scalars and
arrays — but a JSON *string* can contain PHP's serialized format, and the meta-writing paths
called `maybe_unserialize()` on those strings. `maybe_unserialize()` calls `unserialize()`
whenever `is_serialized()` says the string looks serialized, which **instantiates the named
class** and runs its `__wakeup()`, then later its `__destruct()`. Combined with any POP-chain
gadget from WordPress core or an active plugin, that is a remote code execution primitive.

```php
// BEFORE — src/Import/PostImporter.php
foreach ( (array) $values as $value ) {
    $value = maybe_unserialize( $value );   // ← instantiates whatever the payload names
    ...
    add_post_meta( $post_id, $key, wp_slash( $value ) );
}
```

**Proof of concept.** A signed `POST /wp-json/ifs-deploy/v1/import` with:

```json
{ "packages": [ { "type": "post", "meta": {
    "any_key": [ "O:9:\"DP_Gadget\":1:{s:3:\"cmd\";s:6:\"whoami\";}" ] } } ] }
```

`DP_Gadget::__wakeup()` executes on Production. Confirmed locally:

```
$ php tests/security-test.php
  PASS  the fixture instantiates via plain unserialize (vector is real)
  PASS  and its __wakeup() runs
```

**The fix.** A new `Support\SafeData` deserialises with `allowed_classes => false`, so
`unserialize()` returns `__PHP_Incomplete_Class` instead of building anything — no
constructor, no `__wakeup`, no `__destruct`. `decode()` then drops the placeholder so nothing
object-shaped reaches post meta. Arrays and scalars round-trip exactly as before.

```php
// AFTER
$value = SafeData::decode( $value );
```

**Note on scope.** `maybe_unserialize()` is *still* used on the **export** side
(`PostExporter`, `MediaExporter`, `TermExporter`, `ContentSignature`, `SnapshotPackage`) and
that is correct — those read values this site's own WordPress serialized. Swapping them would
be churn with no security benefit. The test asserts both halves of that distinction, so a
future importer that reaches for `maybe_unserialize` fails the build.

---

## 2 · Round 1 — HIGH

> **✅ Nothing outstanding at HIGH severity.** H-3 (§8) — response authentication — was the
> last one, and it is fixed; see §11 for the rollout.

### H-1 · No replay protection — the nonce was signed but never remembered — FIXED

| | |
|---|---|
| **File** | `src/Auth/Verifier.php` (whole `verify_request()` flow) |
| **CWE** | [CWE-294](https://cwe.mitre.org/data/definitions/294.html) Authentication Bypass by Capture-Replay |
| **CVSS 3.1** | 6.5 Medium — `AV:N/AC:H/PR:N/UI:N/S:U/C:N/I:H/A:L` |
| **Impact** | A captured `/import` or `/rollback` can be re-executed at will |
| **Test** | `security-test.php` → *"HIGH: replay protection"* |

**What was wrong.** The nonce was part of the signed canonical string from day one, but
nothing recorded it. The original code said so out loud:

```php
// BEFORE — src/Auth/Verifier.php docblock
 * Phase 1 checks API key, timestamp window, and signature. The replay/nonce
 * store lands in Phase 4 …
```

Nothing in a captured request changes when it is resent, so the signature stays valid for as
long as the timestamp sits inside `Config::TIMESTAMP_WINDOW`. Within that window an attacker
who observed one request (a TLS-terminating proxy, a shared log, a leaked `wp_remote_post`
capture) could replay it repeatedly — re-importing content, or re-running a rollback.

**The fix.** `Auth\NonceStore` records every accepted nonce for slightly more than twice the
timestamp window, and `verify_request()` rejects a repeat with **HTTP 409**.

Three details in the fix that matter more than the fix itself:

1. **The nonce check runs LAST**, after the signature. Recording nonces from unverified
   requests would let anyone fill the store for free — a cheap denial of service, and enough
   cache pressure to evict genuine entries. The test asserts this ordering.
2. **The entry lives for `2 × WINDOW + 60s`**, not `WINDOW`. The window is applied to
   `|now − timestamp|` in *both* directions, so a request may legitimately arrive with a
   timestamp up to `WINDOW` seconds in the future. An entry expiring after only `WINDOW`
   could lapse while that request was still replayable.
3. **An unusable nonce fails closed.** Empty or over-long nonces are reported as replays, so
   the request is refused rather than sailing through with no replay protection at all.

**The residual risk is now closed — see H-1a.** This first fix used transients, which left
two holes: the check was not atomic (two concurrent deliveries of the same request could both
read "not seen" before either wrote — exactly what a proxy retry produces), and a persistent
object cache could evict the key inside the window. The store is now a table with a `UNIQUE`
index, so the `INSERT` itself is the check and the database decides.

### H-1a · The replay store is now an atomic table — ✅ FIXED

| | |
|---|---|
| **Files** | `src/Auth/NonceStore.php`, `src/Support/Schema.php` (DB **v5**) |
| **Test** | `nonce-test.php` (37 assertions) |

`{prefix}ifs_deploy_nonces` with `UNIQUE KEY nonce_hash`. `NonceStore::claim()` is one
`INSERT`: it succeeds the first time and fails the second, so checking and recording are a
single operation with no window between them.

**The two ways this could have broken the plugin**, both handled in `claim()` and both
covered by the tests:

1. **A failed `INSERT` is not proof of a replay.** It is also what a missing table, a lost
   connection or a full disk look like. Believing it would refuse **every** request — a total
   outage caused by the security feature. So a failure is confirmed with a `SELECT` before it
   is believed, and anything else **fails open** to the old transient path. Degraded replay
   protection is recoverable; the pair going down is not.
2. **Not by parsing the error text.** Checking `$wpdb->last_error` for "Duplicate entry"
   would depend on a server-generated, possibly localised string. The confirming `SELECT` is
   locale-proof and costs one query on a path that is rare by definition. The test asserts
   this by stripping comments first — the docblock mentions `last_error` to explain why it is
   *not* used, and the naive check failed on its own documentation.

A `$wpdb` that is missing entirely also falls back rather than fatalling. The degraded state
is logged once an hour, naming the fix (deactivate and reactivate to recreate the table).

Rows carry their own `expires_at`, a few minutes out. They are swept opportunistically and by
the daily retention cron — but **never on the retention cutoff**, because deleting an
unexpired nonce would reopen the window it exists to close.

---

## 3 · Round 1 — MEDIUM

### M-1 · Credentials written to `debug.log` in clear — FIXED

| | |
|---|---|
| **File** | `src/Support/DebugLog.php:62` |
| **CWE** | [CWE-532](https://cwe.mitre.org/data/definitions/532.html) Insertion of Sensitive Information into Log File |
| **CVSS 3.1** | 5.5 Medium — `AV:L/AC:L/PR:L/UI:N/S:U/C:H/I:N/A:N` |
| **Test** | `security-test.php` → *"MEDIUM: credentials never reach the log"* |

`record()` redacted the context for the copy stored in the option, then passed the **raw**
context to the error-log mirror:

```php
// BEFORE
'context' => self::sanitize_context( $context ),   // redacted
...
Logger::debug( $message, $context );               // ← NOT redacted
```

With `WP_DEBUG_LOG` on, any secret in the context went to `wp-content/debug.log` in clear — a
file that is world-readable on a mis-permissioned host and routinely pasted into support
threads. Fixed by redacting once and using the redacted copy in both places.

### M-2 · Redaction matched key names only, not values — FIXED

| | |
|---|---|
| **File** | `src/Support/DebugLog.php` (`sanitize_context()`) |
| **CWE** | CWE-532 |
| **CVSS 3.1** | 4.9 Medium — `AV:N/AC:L/PR:H/UI:N/S:U/C:H/I:N/A:N` |

The filter tested the **key** against `secret|api_key|password|token|nonce|signature`, so a
credential under an innocuous key survived — a URL logged as `url` with a key in its query
string, or a Production response body logged as `detail` containing `{"secret_key":"dps_…"}`.
Both occur in real error paths in this plugin.

Now redaction also runs over **values**, keyed on the credential prefixes
`Credentials::regenerate()` actually issues (`dpk_`, `dps_`), plus any bare 64+ character hex
run. `auth` and `credential` were added to the key pattern.

### M-3 · Signature does not bind the request route — ✅ FIXED (protocol v2, see §11)

| | |
|---|---|
| **File** | `src/Auth/Signer.php:23` (`canonical()`) |
| **CWE** | [CWE-345](https://cwe.mitre.org/data/definitions/345.html) Insufficient Verification of Data Authenticity |
| **CVSS 3.1** | 3.7 Low — `AV:N/AC:H/PR:N/UI:N/S:U/C:N/I:L/A:N` |

The canonical string is `timestamp \n nonce \n sha256(body)`. The **route is not included**,
so a signature valid for `/index` is arithmetically valid for `/import` with the same body.
Exploitability is low — each endpoint validates its own body shape, and H-1's nonce store now
prevents reusing a captured signature at all — but binding the route is standard practice
(AWS SigV4 does).

**Fixed in protocol v2.** `Signer::canonical_v2()` is
`timestamp \n nonce \n route \n sha256(body)`, and the route is normalised to its last path
segment on both sides so the client's `import` and the server's `/ifs-deploy/v1/import`
reduce to the same string. A signature computed for `index` no longer verifies against
`import`.

The coordinated-release problem this originally had is solved by negotiation rather than by
scheduling — see §11. `protocol-test.php` asserts the cross-route case directly:
*"a cross-route signature fails"*.

---

## 4 · Round 1 — LOW

### L-1 · `uninstall.php` left data and a cron event behind — FIXED

`ifs_deploy_log_retention_days` was not deleted, the `ifs_deploy_purge_logs` cron event was
not cleared (WP-Cron would keep firing a hook nothing listens to), and replay nonces were
left in `wp_options`. All three now handled.

### L-2 · No size limit on media fetched during import — ✅ FIXED (with M-5)

`src/Import/MediaImporter.php:213` — `download_url()` imposes no maximum size, and
`file_get_contents( $tmp )` then loads the whole file into memory. A signed request naming a
very large file can exhaust PHP's memory limit and fail the request. Denial of service only,
requires a valid signature, and the blast radius is one request. §7 L-2a.

### L-3 · No rate limiting or bulk cap on `/import` — ✅ FIXED (with M-5 / M-6)

There is no cap on packages per request and no per-key rate limit. A holder of the secret can
submit arbitrarily large batches. Mitigated in practice by `Config::TIMEOUT_HEAVY` and PHP's
own limits, and an attacker with the secret can already write content — so a limit here is
resource hygiene, not an access control. §7 L-3a.

### L-4 · `Credentials::get()` regenerates on a corrupt option — FIXED (hardened)

If `ifs_deploy_credentials` were not an array, `get()` called `regenerate()` — a **write
during a read**, reachable from an unauthenticated REST request via
`secret_for_api_key()`. Effect was a silent, permanent break of the pairing rather than a
compromise. Left in place (it is also what bootstraps a fresh install) but now documented at
the call site as deliberate.

---

## 5 · Checked and found sound

These were audited and needed **no change**. Listed so the next audit does not have to
re-derive them — and so a regression is visible.

| Area | Finding | Evidence |
|---|---|---|
| **REST authorisation** | All **8** routes declare `permission_callback` → `Verifier::verify_request`. No `__return_true` anywhere. | `security-test.php` → *"every REST route is guarded"* |
| **Signature comparison** | `hash_equals()`, not `===`. No timing oracle on the signature. | *"signature comparison is timing-safe"* |
| **API key comparison** | Also `hash_equals()` — the key itself cannot be brute-forced byte by byte. | same |
| **Signed material** | Timestamp, nonce **and** `sha256(body)` are all bound. A tampered payload cannot reuse a signature. | same |
| **Key generation** | `random_bytes(16)` for the API key (128-bit) and `random_bytes(32)` for the secret (256-bit). No `rand`/`mt_rand`/`uniqid`. | *"credential generation"* |
| **Credential storage** | `update_option( …, false )` — `autoload = false`, so the secret is not loaded into every page request. | same |
| **Replay window** | `ctype_digit()` on the timestamp, then a hard ±`TIMESTAMP_WINDOW` bound. | `Verifier.php:32-39` |
| **Direction enforcement** | `/import` and `/rollback` refuse with **409** unless `Config::is_production()`. Production cannot be talked into pushing; Staging cannot be made to receive. | *"writes are refused in the wrong direction"* |
| **SQL injection** | Every query with a variable goes through `$wpdb->prepare()`. The only interpolations are identifiers (`$table`, `$queue`, `$charset_collate`) built from `$wpdb->prefix`, and `$placeholders` — a run of literal `%d` tokens from `array_fill( 0, count( $ids ), '%d' )`, where the values still pass through `prepare()`. | *"SQL is parameterised"* |
| **AJAX authorisation** | All **13** actions route through `Ajax::guard()`, which does `current_user_can()` **and** `check_ajax_referer()`. `guard()` **defaults to the admin capability**, so an action added without thinking about permissions fails closed rather than open. | *"every AJAX action is capability- and nonce-checked"* |
| **IDOR on the queue** | `Ajax::queue_ids()` narrows submitted ids against the database via `QueueRepository::ids_owned_by()` for users without *see all* — ownership is enforced server-side, not trusted from the page. | `src/Admin/Ajax.php` |
| **Privilege escalation** | Capabilities are virtual, via the `user_has_cap` filter — never written with `WP_Role::add_cap()`, so nothing persists if the plugin is removed. Administrator rows in the role matrix are rendered `disabled`, which is what stops an admin removing their own access to the screen. | `access-test.php` (33 assertions) |
| **SSRF** | **Not exploitable.** `download_url()` uses `wp_safe_remote_get()`, which sets `reject_unsafe_urls`, which runs `wp_http_validate_url()` — that rejects non-http(s) schemes and resolves the host, refusing private and loopback ranges (incl. `169.254.169.254` cloud metadata). Verified in `wp-includes/http.php:565-620`. My initial assessment called this a HIGH; it was wrong. |
| **Arbitrary file upload** | `wp_upload_bits()` calls `wp_check_filetype()` and refuses a disallowed extension unless the user has `unfiltered_upload`. A REST import runs with **no logged-in user**, so that capability is always false and `.php` is always refused. Verified in `wp-includes/functions.php:2901`. |
| **Path traversal** | Filenames go through `basename()` and then `wp_upload_bits()`, which applies `sanitize_file_name()` and `wp_unique_filename()`. No user-controlled path is concatenated anywhere. |
| **Option overwrite** | `Support\OptionAllowlist` is **default-deny** with a `DANGEROUS` blocklist the filter cannot override, and the importer **re-validates** before writing — so a malicious push cannot touch `siteurl`, `home`, `active_plugins`, `user_roles`, `admin_email`, salts or cron. | `OptionImporter.php:28` |
| **TLS** | No `sslverify` override anywhere, so WordPress's default (verify **on**) applies to every signed request. | *"hygiene"* |
| **Code-exec sinks** | No `eval`, `create_function`, `assert`, `extract`, or `call_user_func` on request data anywhere in `src/`. | grep, clean |
| **Direct file access** | `ifs-deploy.php` guards on `ABSPATH`; `uninstall.php` guards on `WP_UNINSTALL_PLUGIN`. | *"hygiene"* |
| **Debug leftovers** | No `var_dump`, `print_r`, `die()` or `dd()` in `src/`. | *"hygiene"* |
| **Diagnostics exposure** | Reports Production's name, URL, plugin version, role and uploads directory — to an administrator on the Staging site, who by definition owns both. No credentials, no paths beyond the uploads dir, no DB details. Not a finding. |

---

## 6 · Known accepted risks

**The secret is stored in plaintext in `wp_options`.** WordPress offers no key-management
facility, so this matches every other plugin that holds an API secret. It is `autoload =
false` and never rendered outside the administrator-only Settings screen. Anyone with
database read access already has the whole site.

*(The "imported content is not passed through kses" entry that was here has been promoted to
a finding — **H-2** in §8. My original claim that kses would corrupt block markup was
partly wrong; see the measurements there.)*

---

## 7 · Recommended hardening carried into round 2

Each of these is a real improvement that was **not** applied because it needs coordination
across two live sites or is out of proportion to the risk. Listed so they are decisions, not
oversights.

| ID | Recommendation | Why deferred |
|---|---|---|
| ✅ **H-1a** | Done. Replay store moved to `{prefix}ifs_deploy_nonces` with a `UNIQUE` index, so the `INSERT` itself is the atomic check. | Shipped as DB v5. A failed insert is confirmed with a SELECT before it is believed, and anything else FAILS OPEN to the old transient path. |
| ✅ **M-3a** | Done — `canonical_v2()` adds the route; the verifier accepts both forms and the client only signs the new one once the peer is proven. See §11. | Was: changes the wire format. Solved by negotiating instead of scheduling. |
| ✅ **L-2a** | Done, as part of M-5 — `MediaImporter` checks `Content-Length` from a HEAD request before fetching, then re-checks the actual size on disk. | Was: DoS only, needs a valid signature. This row said "deferred" for longer than the code did. |
| ◐ **L-3a** | **Half done, half declined.** The packages cap shipped with M-5 (200 per request, filterable, refuses rather than truncating), as did the body-size cap. The per-API-key **rate limit is deliberately not implemented** — see §12. | A rate limit here would be shared by the whole team, because a Staging site has ONE API key. |
| ⚠ | *This row used to read "Settings → Regenerate Credentials already does it". **It did not** — the handler was missing entirely. Promoted to a finding: **M-7** in §8.* | The recommendation stands; the mechanism had to be built first. |
| — | Add a `Content-Security-Policy` for the plugin's own admin screens. | wp-admin does not set one; doing it for one plugin's screens has limited value. |

---

## 8 · Round 2 — proposed fixes, awaiting review

Nothing in this section is implemented. Each item states the finding, the evidence, the
proposed change, and **what it would cost** — because several of these touch the wire format
between two live sites, and "nothing should break" is a hard constraint here.

---

### H-2 · Imported content bypasses `kses` — raw `<script>` can be stored — ✅ FIXED

| | |
|---|---|
| **Files** | `src/Import/PostImporter.php:62-63` (`post_content`, `post_excerpt`), `src/Import/OptionImporter.php:52` (`widget_*`) |
| **CWE** | [CWE-79](https://cwe.mitre.org/data/definitions/79.html) Stored XSS · [CWE-269](https://cwe.mitre.org/data/definitions/269.html) Improper Privilege Management |
| **CVSS 3.1** | 7.6 High — `AV:N/AC:L/PR:L/UI:R/S:C/C:H/I:H/A:N` |
| **Impact** | Anything that can sign a request can store executable HTML on Production, then served to every visitor and every logged-in admin |

**What is wrong.** WordPress applies `wp_filter_post_kses` to `post_content` on save for any
user lacking `unfiltered_html`. A REST import runs with **no logged-in user**, so that filter
never fires and the content is stored exactly as sent. Result: a compromised Staging site —
or anyone holding the secret — can store `<script>` on Production even though **no user on
Production has permission to do that**. That is the privilege-management half of the finding,
and it is the part that makes this a real issue rather than an accepted design trade.

**Correcting my round-1 assessment.** I previously wrote that this could not be fixed because
"running kses would corrupt legitimate block markup". I measured it instead of assuming, and
that was **partly wrong**. Against real WordPress (`wp_kses_post()`, core allowlist):

| Content | `wp_kses_post()` verdict |
|---|---|
| `<!-- wp:paragraph -->` block comments | ✅ **preserved** |
| Block attrs JSON `<!-- wp:heading {"level":3} -->` | ✅ **preserved** |
| Nested blocks (columns → column) | ✅ **preserved** |
| `data-*` attributes | ✅ **preserved** |
| Inline `style="color:#f00"` | ✅ **preserved** |
| Shortcodes | ✅ **preserved** |
| `<img srcset="…" sizes="…">` | ❌ **srcset and sizes STRIPPED** |
| `<iframe>` (YouTube, Maps, oEmbed HTML) | ❌ **removed entirely** |
| `<form>` / `<input>` | ❌ **removed** |
| Inline `<svg>` | ❌ **removed** |
| `<script>` | 🛡️ stripped |
| `onerror=` / `onclick=` | 🛡️ stripped |
| `href="javascript:…"` | 🛡️ neutralised |
| `style="background:url(javascript:…)"` | 🛡️ stripped |
| `<svg onload=…>` | 🛡️ removed |

Verified against core's own allowlist:

```
allowed img attributes: alt, align, border, height, hspace, loading, longdesc,
  vspace, src, usemap, width, aria-*, class, data-*, dir, hidden, id, lang, style,
  title, role, xml:lang
srcset allowed? NO   sizes allowed? NO
iframe allowed? NO   form allowed? NO   svg allowed? NO
```

So block markup is safe — but **blanket `wp_kses_post()` cannot be the default.** It would
strip `srcset`/`sizes` from *every* image on *every* deploy (silently degrading responsive
images site-wide) and delete every embed, form and inline SVG. That is exactly the kind of
silent content loss ruled out by "nothing should break".

**Proposed fix — a content firewall, not kses-as-shipped.**

Start from `wp_kses_allowed_html( 'post' )` and *extend* it with what is legitimate content in
a deployment but missing from core's editor-facing allowlist:

```php
// Support\ContentFirewall (proposed)
$allowed = wp_kses_allowed_html( 'post' );

$allowed['img'] += array( 'srcset' => true, 'sizes' => true, 'decoding' => true );

$allowed['iframe'] = array(
    'src' => true, 'width' => true, 'height' => true, 'title' => true,
    'class' => true, 'style' => true, 'loading' => true,
    'allow' => true, 'allowfullscreen' => true, 'frameborder' => true,
    'referrerpolicy' => true, 'sandbox' => true,
);

// Forms and inline SVG, with NO event-handler attributes — kses drops any attribute
// not named here, which is what removes every on* handler for free.
$allowed['form']   = array( 'action' => true, 'method' => true, 'class' => true, 'id' => true );
$allowed['input']  = array( 'type' => true, 'name' => true, 'value' => true, /* … */ );
$allowed['svg']    = array( 'viewbox' => true, 'xmlns' => true, 'class' => true, /* … */ );
$allowed['path']   = array( 'd' => true, 'fill' => true, /* … */ );
```

Then `wp_kses( $content, $allowed )`. Because the allowlist names every permitted attribute,
kses removes **all** `on*` handlers automatically, and still runs `wp_kses_bad_protocol()` on
`href`/`src` (killing `javascript:`) and `safecss_filter_attr()` on `style`.

Three things this design must also do:

1. **Never silently lose content.** Compare before and after; when they differ, record it in
   the deployment log so History shows *"content was sanitised on Production"* with the
   object name. A lossy deploy the user cannot see is worse than no filter.
2. **Restrict `iframe` `src` to an allowlist of hosts** (YouTube, Vimeo, Google Maps, plus a
   `ifs_deploy_allowed_iframe_hosts` filter). An unrestricted iframe is still an injection
   surface; a host-restricted one is an embed.
3. **Cover `widget_*` options too.** This is the gap that makes content-only sanitisation
   insufficient — see H-2b.

**Rollout, so nothing breaks.** Production-side setting, three states:

| Mode | Behaviour |
|---|---|
| `report` | Sanitise nothing; log what *would* have been stripped. **Default on upgrade** for existing installs. |
| `filter` | Apply the firewall and log every change. **Default for new installs.** |
| `off` | Trust the peer completely; current behaviour. Requires ticking an explicit "I trust this Staging site" box. |

Starting existing installs in `report` means the first deploy after upgrading changes nothing
but tells you exactly what `filter` mode would do. That is the only rollout I would be
comfortable with on a live pair.

**Why the setting must live on Production, not Staging.** The payload could declare "the
author had `unfiltered_html`" and Production could honour it — that would faithfully reproduce
WordPress's own rule. But it is worthless as a *security* control, because a compromised
Staging site simply sets the flag to true. The decision has to belong to the side being
protected.

---

### H-2b · Allowlisted `widget_*` options carry arbitrary HTML by design — ✅ FIXED

| | |
|---|---|
| **File** | `src/Support/OptionAllowlist.php:70` (`SAFE_PREFIXES` includes `widget_`) |
| **CVSS 3.1** | 7.1 High — same vector as H-2 |

`widget_custom_html` and `widget_text` **exist to hold raw HTML**. They are deployable, and
`OptionImporter` writes them with no filtering. So even with H-2 fixed, a signed request can
inject script through a widget instead of through a page — meaning **H-2 alone does not close
the XSS path**, and shipping it while leaving this open would be a false sense of security.

**Proposed:** run the same firewall over the HTML-bearing keys of `widget_*` values in the
importer, under the same three-mode setting. Structure and non-HTML fields untouched.

---

### H-3 · Production's responses are never authenticated — ✅ FIXED (protocol v2, see §11)

| | |
|---|---|
| **File** | `src/Client/DeployClient.php:66-90` — the response is parsed with no verification |
| **CWE** | [CWE-345](https://cwe.mitre.org/data/definitions/345.html) Insufficient Verification of Data Authenticity |
| **CVSS 3.1** | 6.8 Medium — `AV:N/AC:H/PR:N/UI:R/S:U/C:L/I:H/A:N` |

Requests **to** Production are signed. Responses **from** Production are not: Staging parses
whatever comes back. This was not in round 1 and it should have been — "secure the API
communication" is only half done when only one direction is authenticated.

What a forged response buys an on-path attacker:

- **A forged `index`** → Compare & Sync shows fabricated state. Pages that differ appear
  in sync (so a real change is never pushed), or vice versa.
- **A forged `object` / `rollback-preview`** → the diff shown *before* the user confirms a
  rollback is attacker-controlled. The confirmation dialog exists precisely so the user can
  see what will change; forging it defeats it.
- **A forged `ping`** → Diagnostics reports a healthy peer that is not there.

Over HTTPS with a valid certificate this needs a CA-level compromise. Over plain `http://` —
which the plugin currently accepts, see §8 M-4 — it is trivial.

**Fixed.** Production signs its response with the shared secret in
`X-IFS-Deploy-Response-Signature`, over `timestamp \n nonce \n sha256(normalised body)`,
reusing the **request's** nonce so the reply is bound to the one request that asked for it.
`DeployClient` verifies it with `hash_equals()` **before anything reads the body**, so a
forged reply never reaches the caller.

Two details that are load-bearing rather than incidental:

- **What is signed is the normalised body, not the raw bytes.** The server cannot see its
  own final output from a `rest_post_dispatch` filter, and `?_pretty` changes those bytes
  anyway. Both sides therefore sign `wp_json_encode()` of the decoded data: the server
  encodes the array it is returning, the client decodes what arrived and re-encodes it the
  same way. Deterministic for what these endpoints carry, and immune to whitespace or
  escaping differences in transit.
- **A signature is required on a 200 only.** A response signature is keyed to a *verified*
  request, so a 401/403/429 from the verifier itself cannot carry one. Enforcing presence
  there would replace "Authentication failed" with a confusing downgrade error at the exact
  moment the owner needs the real one — right after a credential rotation. What that
  concedes, stated plainly: an on-path attacker can turn a genuine 200 into a forged error,
  making a deploy that worked look like it failed. Visible and recoverable. It cannot make
  Staging trust wrong **content**, which is the point of H-3 — every reply whose body is
  read as truth (`index`, `object`, `signatures`, `rollback-preview`) is a 200.

Rollout, and the downgrade rule that goes with it, in §11.

---

### M-4 · `http://` is accepted for the Production URL — ✅ FIXED

| | |
|---|---|
| **File** | `src/Support/Config.php:65` — `esc_url_raw()` permits `http` |
| **CWE** | [CWE-319](https://cwe.mitre.org/data/definitions/319.html) Cleartext Transmission |
| **CVSS 3.1** | 5.9 Medium — `AV:N/AC:H/PR:N/UI:N/S:U/C:H/I:L/A:N` |

```php
'url' => untrailingslashit( esc_url_raw( $url ) ),   // http:// passes
```

The HMAC protects **integrity, not confidentiality**. Over `http://`:

- all deployed content travels in cleartext;
- the **API key travels in a request header** in cleartext (the secret does not, so an
  observer still cannot forge — but see H-1's replay window and H-3's response forgery);
- H-3 becomes trivially exploitable.

**Proposed:** reject non-`https` in `set_remote()` with a clear validation error; warn on the
Settings screen and in Diagnostics if an existing configuration uses `http`. Allow an explicit
`ifs_deploy_allow_insecure_transport` filter for local development only (`.test`/`.local`), so a
dev environment is not blocked.

---

### M-5 · No payload size or batch cap — ✅ FIXED

| | |
|---|---|
| **Files** | `src/Rest/ImportEndpoint.php` (no cap on `packages`), `src/Import/MediaImporter.php:213` (no size cap — L-2 restated) |
| **CVSS 3.1** | 4.3 Medium — availability only, requires a signature |

Nothing bounds packages per request or total body size. Combined with `TIMEOUT_HEAVY = 180`,
a single signed request can hold a worker for three minutes and exhaust memory.

**Proposed:** cap packages per request (default 200, filterable), reject bodies over a
configurable size before parsing, and check `Content-Length` before `download_url()` fetches a
media file — streaming to disk rather than `file_get_contents()`.

---

### M-6a · API access monitoring — IMPLEMENTED

Built ahead of the rest of round 2, because monitoring is what tells you whether any of the
other findings are being probed. `Logs & Diagnostics → API access` on a Production site now
records every signed request: source address, endpoint, outcome, HTTP status, user agent,
plus a per-address summary flagging **new** and **suspicious** addresses.

| | |
|---|---|
| **Added** | `src/Support/ClientIp.php`, `src/Support/ApiLog.php`, DB **v4** table `{prefix}ifs_deploy_api_log` |
| **Wired at** | `src/Auth/Verifier.php` — `verify_request()` wraps `check()` so no return path can escape the log |
| **Test** | `ipmonitor-test.php` (107 assertions) |

**The decision that makes or breaks this feature: which address to believe.**

`REMOTE_ADDR` is the only value the web server observed. Every `X-Forwarded-For`,
`X-Real-IP` and `CF-Connecting-IP` is *a request header* — anyone can send any value. A
monitor that trusts them by default is **worse than no monitor**:

- the attacker writes whichever address they like into the security log, so the log lies;
- and any per-address counting is bypassed by rotating the header, so the control stops
  working while still appearing to.

So `REMOTE_ADDR` is the default and the only trusted source. This matches core, which says
so explicitly in `wp-includes/comment.php`:

> We use `REMOTE_ADDR` here directly. If you are behind a proxy, you should ensure that it
> is properly set, such as in wp-config.php, for your environment.

**The opposite mistake is equally real.** Behind a CDN or managed-host load balancer,
`REMOTE_ADDR` *is* the proxy — every request appears to come from one address and the
monitor is useless. So a forwarded header **can** be trusted, but only from a fixed list
and only when the owner explicitly selects it (Settings → Log Retention → *Source address
from*). Both values are always stored, so a mismatch shows on the Logs screen either way,
and the screen warns in both directions: when a header is trusted, and when every request
has arrived from a single address (the signature of an unconfigured proxy).

**Guard rails, so the log cannot become the vulnerability.**

| Risk | Handling |
|---|---|
| One DB row per attempt lets the attacker size the table | Repeat failures from the same address/route/outcome inside 5 minutes **update** the existing row instead of inserting. Successes are never collapsed. |
| A sustained attack floods the 200-entry event log | The "repeated rejections" alert is rate-limited to once per hour per address by a transient marker. |
| Unbounded growth of personal data | Purged by `LogRetention` on the same setting and schedule as everything else. |
| Attacker-controlled strings rendered in the admin | Addresses validated with `filter_var( …, FILTER_VALIDATE_IP )`; user agent `sanitize_text_field` + capped at 255; the endpoint slug taken from `$request->get_route()`, never from input, and reduced to `[a-z0-9-_]`. |
| Logging on the side that has nothing to log | `ApiLog::record()` is a no-op unless the site is the Production receiver. |

**GDPR.** IP addresses are personal data. Retention already bounds them, and an
*anonymise* toggle stores them masked via core's `wp_privacy_anonymize_ip()` — still enough
to attribute a burst to one network, which is the whole reason to keep any of it.

**Known limit, stated rather than hidden:** because "new address" is derived from the log
itself, an address whose rows have all aged out reads as new again. That is the honest
answer given the data retained, and it errs toward telling you.

This delivers most of **M-6**'s value (the missing signal). The remaining half — returning
429 and refusing the request after a threshold — is still proposed below.

#### A duplicate delivery is not a failure, and is not logged by default

**Reported by the team:** the access log filled with *"Duplicate suppressed"* rows, and
people reading it assumed something was wrong or under attack. Two separate problems sat
behind that, and only one of them was cosmetic.

**What a duplicate actually is.** The same HTTP request delivered twice — a host or proxy
retry, or a followed redirect before that was fixed. By the time it is refused it has
already passed the signature, key, timestamp and address checks; the *only* thing that
rejected it was the replay store, and it rejected it correctly, so nothing ran twice. Two
genuinely separate calls carry two separate nonces and both are accepted, so an identical
nonce arriving twice can only be one request arriving twice.

**The real bug it was hiding.** `duplicate` was counted as a FAILURE by
`recent_failures()`, `by_ip()` and `summary()`. Consequences:

- the API access summary reported failures a perfectly healthy pair was not having;
- and `maybe_flag_suspicious()` fires at **5 failures in an hour**, so a site behind a
  retrying proxy wrote ERROR-level *"Repeated rejected API requests"* entries **about its
  own paired Staging server**. A security alert that fires on normal operation trains people
  to ignore security alerts, which is a worse outcome than not having the alert.

`ApiLog::is_failure()` now exempts `DUPLICATE` along with `OK`, and all three queries use
`outcome NOT IN ( %s, %s )`. A **deliberate** replay is a different outcome —
`ifs_deploy_replay`, recorded when the repeat arrives outside `Verifier::DUPLICATE_WINDOW`
— and still counts in full. So this exempts transport noise without exempting the attack it
resembles.

**Why dropping the rows is safe, stated as a claim that can be checked:**

| | |
|---|---|
| The refusal is unchanged | Still **409**, still nothing applied twice. Only whether it earns a table row changed. |
| The attack case is untouched | A repeat outside the 30s window is `ifs_deploy_replay` and is **always** logged, whatever this is set to. |
| The information is not lost | `Verifier::note_duplicate()` still writes one `info` entry per hour naming the endpoint, the seconds apart, the likely cause and what to check. |
| Lockout was already correct | `Auth\Lockout::COUNTED` never included `duplicate` — a proxy retry has never counted toward a cool-off. |
| A first sighting is never dropped | `is_new_ip()` is answered *from this table*, so an address that only ever produced duplicates would otherwise never appear at all. A new address is recorded even when the outcome is a duplicate. |
| The omission is disclosed | The Logs screen says duplicates are not listed and names the setting that shows them. A log that quietly omits rows is worse than a noisy one. |
| It is reversible | *Settings → Log Retention → Duplicate deliveries* turns the rows back on for chasing a transport problem. |

Default **off**. The one thing this does not do is fix the cause: if duplicates keep
appearing, the host or proxy is genuinely delivering requests twice, and the hourly `info`
entry says so with what to check.

### M-6b · Address allow / block lists — IMPLEMENTED

`Support\IpAccess`, enforced as the **first** step of `Verifier::check()` — before any
cryptography, so a refused address costs one option read and a byte comparison rather than
an HMAC. Single addresses and CIDR ranges, IPv4 and IPv6. Test: `ipaccess-test.php` (58).

**Order, and why it is not negotiable**

| | |
|---|---|
| On the block list | refused — **blocking always wins**, even over the allow list, so there is never a question of which rule applied |
| Allow list **empty** | allowed — an empty list means *no restriction*, not *deny everything* |
| Allow list set | allowed only on a match |

That second row is the most important line in the feature. Reading an empty list as
"deny all" would mean **installing the plugin breaks every deploy**.

**This is the one setting that can lock a working pair out of itself.** Get the address
wrong and Production refuses its own Staging site: deploys fail 403 and nothing on the
Staging screen explains why. Four things exist purely to stop that happening quietly:

1. **The addresses actually observed are listed for copying**, taken from the access log
   (M-6a), so the admin never has to guess. An address with requests and no rejections is
   almost certainly the Staging site, and the screen says so.
2. **Invalid entries are reported, never dropped silently** — a typo in an allow list is
   exactly how this goes wrong, so `save()` returns what it refused and the notice names it
   ahead of any "saved" message.
3. **Every refusal is logged**, because the check sits inside `check()` and so passes
   through the access-log wrapper. If deploys stop, the reason is on the Logs screen.
4. **The rules touch the API only, never wp-admin.** A mistake here can break deploys; it
   can never lock anyone out of the screen needed to fix it.

**Two interactions worth knowing**

- **A proxy makes this worse.** Behind a CDN, `REMOTE_ADDR` is the CDN — allow-listing the
  real Staging address then refuses everything. Matching uses `ClientIp::for_matching()`,
  which honours the trusted-header setting, so the two features must be configured
  together. The UI states this next to the field.
- **Anonymised logging would have silently disabled both lists.** With masking on, the
  recorded address is `203.0.113.0`, so an entry of `203.0.113.9` could never match.
  Matching therefore uses the **unmasked** address (`raw`), while storage stays masked.
  That interaction is asserted in the tests, because it is invisible until it fails.

**Deliberate design choices**

- **Both refusals return the same message and status.** Telling a caller which list caught
  it — or that an allow list exists at all — is free reconnaissance. The error *codes*
  differ so the log keeps the distinction.
- **Matching compares packed bytes** via `inet_pton`, not strings: `2001:db8::1` and
  `2001:0db8:0000:…:0001` are the same address but different strings.
- **`/0` is refused on input.** In a block list it would refuse every request to the site;
  in an allow list it is a no-op that looks like a restriction.
- **Entries are capped at 200 and re-validated on read**, so a hand-edited option cannot
  put an unparseable entry into a matcher that runs on every request.

**Honest limit:** a block list is a **speed bump, not a wall**. An attacker on a rotating
address or behind a large NAT reappears immediately. It is worth having to silence one
noisy source cheaply; the signature remains the actual access control.

### Retention options narrowed to 7 / 14 / 30 / 90 days

"180 days", "1 year" and **"Keep forever"** are gone. The access log holds IP addresses —
personal data — so an unbounded retention is a liability rather than a feature, and 90 days
is already far longer than a deployment failure stays worth investigating. `days()` only
accepts a listed value, so a stored `0` from an earlier build **falls back to 30** rather
than silently disabling the purge.

### M-6 · No lockout after repeated signature failures — ✅ FIXED

| | |
|---|---|
| **File** | `src/Auth/Verifier.php` |
| **CVSS 3.1** | 3.7 Low/Medium |

A wrong signature costs the attacker nothing and is not recorded. Brute-forcing HMAC-SHA256 is
not feasible, so this is not a key-recovery risk — but the *absence of a signal* is the real
gap: sustained failures are the only evidence of an attack on this endpoint, and nothing
surfaces them.

**Proposed:** count failures per source IP in a short-lived transient; after a threshold,
return 429 for a cool-off period, and write one `DebugLog::warning` per lockout so it appears
on the Logs screen. Rate-limit the *logging* too, so the log cannot be flooded.

---

### M-7 · The documented way to rotate a leaked secret did not exist — ✅ FIXED

| | |
|---|---|
| **File** | `src/Admin/Pages/SettingsPage.php` — `maybe_handle_post()` had no `regenerate` branch |
| **CWE** | [CWE-320](https://cwe.mitre.org/data/definitions/320.html) Key Management Errors |
| **CVSS 3.1** | 4.0 Medium — no direct exploit; it removes the REMEDY for one |
| **Impact** | A leaked or shared secret could not be rotated from the admin UI at all |
| **Test** | `render-test.php` → *"the regenerate action is handled"* |

**What was wrong.** The Connection screen has a *Regenerate Credentials* button. It had its
own form, its own confirm dialog and a valid `ifs_deploy_regenerate` nonce field — and
nothing on the server listened for it. `maybe_handle_post()` matched `save_settings`,
`save_roles` and `save_logs`, then returned `''`. So the page reloaded, the keys were
unchanged, and **no error was shown**: the most reassuring possible presentation of a
control that does nothing.

`Credentials::regenerate()` existed and worked; it was simply never called from the admin.
The only caller was `Credentials::get()`, as a repair path for a corrupt option.

**Why this is a security finding and not a UI bug.** Rotation is the entire remedy for an
exposed shared secret. Every other control here reduces what an attacker can do *with* the
secret; this is the one that takes the secret away. Without it the answer to "our key
leaked" was to edit `wp_options` by hand.

It is also the finding that says the most about this document: **§7 asserted the mechanism
worked.** I wrote that from reading the button, not the handler. See §10 correction 5.

**The fix.** A `regenerate` branch behind `check_admin_referer( 'ifs_deploy_regenerate' )`
that calls `Credentials::regenerate()`, records a **warning**-level log entry naming who did
it, and returns a notice saying the old keys are dead and the new ones must be copied into
Staging. Warning rather than info deliberately: from that moment every deploy fails
signature verification until the peer is updated, and an unexplained outage is worse than a
noisy log.

`ifs_deploy_peer_protocol` is deliberately **not** cleared. That option records what the
site this one pushes *to* supports; a receiver's own identity and a sender's memory of its
peer are separate facts, and resetting a proven capability here would be a downgrade for no
reason. `Config::set_remote()` handles the case that does warrant it.

---

### L-5 · Signature failures are indistinguishable from key mismatches — ✅ FIXED

`Verifier` returns `ifs_deploy_bad_key` (403) for an unknown API key and
`ifs_deploy_bad_signature` (403) for a bad signature. That tells an attacker whether the key
they hold is the right one — a small oracle. **Proposed:** return one generic 403 to the
client, and keep the distinction in the log where the site owner can still debug it.

---

## 8.1 · Proposed order of work

Grouped so the two wire-format changes ship together in a single coordinated release — that
is the only way to avoid an outage on a live pair.

| # | Item | Wire change? | Risk to existing flow |
|---|---|---|---|
| 1 | ✅ **M-4** enforce HTTPS | no | Done — existing http configs keep working, warned |
| 2 | ✅ **M-5** size/batch caps | no | Done — refuses rather than truncating |
| 3 | ✅ **M-6** failure lockout + logging | no | Done — transport noise excluded from the count |
| 4 | ✅ **L-5** generic 403 | no | Done — specific code still reaches the log |
| 5 | ✅ **H-2 + H-2b** content firewall | no | Done — `report` mode on upgrade, so the first deploy after updating changes nothing |
| 6 | ✅ **H-1a** nonce table (DB v5) | no | Done — additive `dbDelta`, fails open if the table is unusable |
| 7 | ✅ **H-3 + M-3** response signing + route binding | **YES** | Done — negotiated, so either site can be updated first (§11) |

Items 1–6 were independent and safe, which is why they came first. Item 7 did need the
version-tolerant verifier this table called for — and once that existed, the "both sites in
the same window" requirement went away with it.

---

---

## 9 · Verification

```bash
php tests/security-test.php     # 120 assertions, this document's findings
php tests/protocol-test.php     #  72 assertions, H-3 + M-3 and the rollout
php tests/run-all.php           # 1426 assertions, whole plugin
```

Manual checks that automation cannot cover:

1. **Replay** — capture a signed `/import` with a proxy, resend it. Expect **409
   `ifs_deploy_replay`**; before the fix it returned 200 and re-imported.
2. **Object injection** — send the C-1 proof-of-concept payload. Expect the meta value to be
   absent or scalar, and no gadget side effect.
3. **Tampering** — alter one byte of the body, keep the headers. Expect **403**
   (`ifs_deploy_unauthorized` to the caller, `ifs_deploy_bad_signature` in the log).
4. **Expiry** — send with a timestamp 10 minutes old. Expect **401 `ifs_deploy_expired`**.
5. **Direction** — send `/import` to a site set to Staging. Expect **409**.
6. **Log hygiene** — enable `WP_DEBUG_LOG`, force a connection failure, then
   `grep -E 'dpk_|dps_' wp-content/debug.log`. Expect **no matches**.
7. **Unauthenticated access** — call each of the 8 routes with no headers. Expect **401
   `ifs_deploy_missing_auth`** every time.
8. **Response forgery (H-3)** — with a proxy, alter one byte of a `/index` reply's body
   while leaving `X-IFS-Deploy-Response-Signature` intact. Expect Staging to refuse it
   with `ifs_deploy_bad_response_signature` and show nothing from that reply.
9. **Downgrade (H-3)** — once the pair has upgraded itself, strip that header from a 200.
   Expect `ifs_deploy_response_unsigned`, **not** silent acceptance. Then confirm a 403
   with no signature still surfaces its real reason.
10. **Route binding (M-3)** — replay a captured `/index` signature against `/import` with
    the same body and a fresh nonce. Expect **403**; under v1 the signature was valid.
11. **Mixed versions** — update one site only, deploy, then update the other. Expect no
    failure at any point, and one `info` entry noting the legacy signature while it lasts.

### Hardening guide for site owners

- Serve **both** sites over HTTPS. The signature protects integrity, not confidentiality —
  the content itself travels in the body.
- Keep the Production site's Settings screen limited to administrators (the default).
- **Regenerate credentials** if the Staging site is ever shared with a third party, and after
  any contractor offboards. Staging holds a secret that can write to Production.
- Do not leave `WP_DEBUG_LOG` on in production, and keep `wp-content/debug.log`
  non-web-readable.
- Set the log retention period (Settings → Log Retention) so diagnostic data does not
  accumulate indefinitely.

### WordPress.org requirements

| Requirement | Status |
|---|---|
| No remote code execution | ✅ C-1 fixed |
| All input sanitised, all output escaped | ✅ |
| All SQL prepared | ✅ |
| Nonces + capability checks on every action | ✅ 13/13 AJAX, 8/8 REST |
| No assets loaded from third-party services | ✅ Inter bundled locally (`DESIGN.md` §1.3) |
| Bundled third-party assets carry their licence | ✅ `assets/fonts/Inter-LICENSE.txt` (OFL 1.1) |
| No obfuscated or minified-only code | ✅ `admin.css` is generated; source is `assets/css/src/` |
| Direct file access blocked | ✅ |
| Clean uninstall | ✅ L-1 fixed |
| No calling home without consent | ✅ only ever contacts the URL the admin configured |

---

---

## 9.5 · Multi-user concurrency — ✅ FIXED

Not security findings, but the same shape of problem: three races that a fifteen-person team
hits weekly, none of which produced an error. Raised when asked whether several users can push
at once. **They can — nothing blocked it, and that was the issue.** Test:
`concurrency-test.php` (36 assertions).

### C-1 · Two people pushing the same object

`src/Client/DeployLock.php`, taken in `DeploymentService::dispatch()`.

Two simultaneous pushes of one page produced two deployments, two imports and **two rollback
snapshots for one logical change**. Production did not corrupt — the later write won — but:

- rolling back afterwards could restore the *other* person's version, because there were now
  two restore points and the newest was not the one anyone meant to undo;
- the queue row was marked deployed by whichever request finished last, stamping **its** hash
  as `deployed_hash`. If that was the earlier payload, the row either stayed pending forever
  or cleared while Production held something else.

**Per object, not global.** A global lock would serialise the whole team behind one person's
batch; fifteen people on fifteen pages is the normal case and stays fully parallel. Only a
genuine collision is refused, and the message names the object and the person holding it —
"deployment failed" would send someone hunting a fault that does not exist.

**All or nothing**: a partial claim would push half a batch, so locks taken on the way to a
collision are given back. Released in a `finally` so a fatal mid-deploy cannot hold an object,
with a 300s expiry as the backstop (sized above `TIMEOUT_HEAVY`). A lock is only ever released
by the request whose token is stored in it.

*Transients, not a table, deliberately:* a lost lock degrades to the pre-existing race, but a
lock that could never expire would block an object forever. Automatic expiry is the property
that matters most here — the opposite trade to H-1a.

### C-2 · "Push All" published other people's work

`Ajax::queue_ids()` returned **every** id for anyone with *see all*, so one administrator
click deployed every colleague's pending row — including work someone was halfway through. On
a team that is the likeliest way to publish something nobody intended, and it looked like an
ordinary action right until it happened.

Ownership narrowing now applies to administrators too. The wide behaviour is still available
via an explicit *"Include changes made by other users"* checkbox, only rendered for users who
could act on others anyway; without *see all* the server narrows regardless of what the page
sends.

**The dialog had to be fixed with it.** It counted the rows on screen, so an administrator
would have seen "12 changes will be pushed" and had 3 pushed. Rows now carry `data-mine`, and
the browser narrows the count to match — a dialog that promises something different from what
happens is worse than no dialog.

### C-3 · The same new image downloaded twice

`MediaImporter::import()` — `find_existing()` and `sideload()` are two steps. Two concurrent
imports of pages sharing one new image both found nothing and both uploaded it, leaving
`image.jpg` and `image-1.jpg` with content pointing at whichever won. Two people pushing
different pages that share an image is an ordinary Tuesday, not a rare race.

The download is now claimed on the **source URL** — what identifies the file, and what a
second request is about to fetch. A second import waits briefly and reuses the result rather
than failing the object or duplicating it; if the first request died without producing an
attachment, the second proceeds. Claim released immediately after the download, with a 60s
expiry so a crash cannot block one file.

### Still open, by choice

The queue keeps **one row per object, owned by the last editor**. If Aman edits a page and
Priya then edits it, the row becomes Priya's and disappears from Aman's Pending Changes — and
Priya's push carries Aman's edits, because they are in the same page. That is inherent to
one-row-per-object; fixing it means tracking multiple contributors per row (a DB migration and
a Pending Changes UI change). Deliberately deferred, not overlooked.

---

## 10 · Notes on the audit itself

Five corrections I made to my own findings while working, recorded because they change how
much the rest of this document should be trusted:

1. **I initially rated SSRF in the media importer HIGH.** It is not exploitable —
   `download_url()` goes through `wp_safe_remote_get()` → `wp_http_validate_url()`, which
   already blocks private and loopback targets. I only established this by reading
   `wp-includes/http.php`, not by assuming. Reported in §6 rather than as a finding.
2. **My first object-injection test passed for the wrong reason.** The fixture used
   `O:10:"DP_Gadget"` where the class name is 9 characters, so `unserialize()` bailed before
   instantiating anything and the "safe" assertion was meaningless. The test now asserts
   *first* that the fixture is a live vector (`plain unserialize DOES instantiate it`) before
   asserting the fix stops it. Any security test that cannot demonstrate the vulnerability it
   guards is not evidence of anything.

3. **I wrote off the kses problem as unfixable, on an assumption.** Round 1 said running kses
   "would corrupt legitimate block markup", filed it under accepted risk, and moved on. I had
   not tested it. Measured against real WordPress, block markup, block attribute JSON, nested
   blocks, `data-*`, inline `style` and shortcodes all survive `wp_kses_post()` untouched —
   what actually breaks is `srcset`/`sizes`, `iframe`, `form` and inline `svg`. The finding
   was real and the reasoning for dismissing it was not, which is why H-2 now proposes an
   extended allowlist rather than either extreme.

   The general lesson, and the reason this section exists: **an accepted risk justified by an
   untested assumption is not an accepted risk, it is an unexamined one.**

4. **My first H-3 bootstrap could be forged, and I caught it after writing the code.** The
   client needs some signal that the peer speaks v2. My first version accepted two: a
   response signature, or `protocol: 2` in the ping body. The second is **unsigned**, and
   the capability ratchet is **permanent** — so an on-path attacker who injected that one
   field in front of a Production site still running v1 would make the client sign v2
   forever against a peer that cannot verify it. Every deploy would fail a signature check
   until an administrator intervened. A denial of service handed over by the anti-downgrade
   control itself.

   The fix was to sign replies to **v1-signed requests too**, so the bootstrap signal is the
   signature and nothing else is believed. `ping` still reports `protocol` for diagnostics;
   it is no longer acted on. `protocol-test.php` asserts exactly that: *"a body claiming
   protocol 2 proves nothing"*.

   The lesson is narrower than the others and worth keeping separate: **a capability upgrade
   is a security decision, so the evidence for it has to be authenticated to the same
   standard as the thing it unlocks.** An unauthenticated bootstrap for an authenticated
   channel is just the channel's weakest link with extra steps.

5. **I documented a mechanism I had not checked existed.** §7 recommended rotating the
   shared secret and stated that *"Settings → Regenerate Credentials already does it"*. It
   did not — there was no handler, and the button had been silently doing nothing for as
   long as it had existed. I wrote that line from reading the FORM, which had a button, a
   confirm dialog and a correct nonce field. Everything about it looked finished.

   Now **M-7**. The lesson is specific and it is the same shape as correction 2: I asserted
   a control worked because the part of it I looked at was present. A recommendation that
   points at an existing feature has to be traced to the code that executes, or the audit
   is repeating the reader's own assumption back at them — with the authority of a document
   that says it verified things.

   What made this one findable at all: someone asked "what is left?", and answering it by
   grepping rather than by re-reading this file is what surfaced the gap.

**Not covered by this audit:** the JavaScript in `assets/js/admin.js` beyond its nonce and
capability contracts; the interaction of imported content with arbitrary third-party plugins;
and infrastructure (server config, file permissions, WAF rules).

---

## 11 · Protocol v2 — how H-3 and M-3 shipped without a coordinated release

Both fixes change the **wire format**: requests gain the route in their signed material
(M-3), replies gain a signature (H-3). §8.1 listed them as needing "both sites updated in
the same window", which for a live pair means a planned outage. That requirement turned out
to be avoidable, and this section is the record of how — because the negotiation is now part
of the security surface and has to be reviewable.

### The rule on each side

| Side | Rule | Why |
|---|---|---|
| **Verifier** (receiver) | Try v2, then v1. Both through `hash_equals()`. | An un-upgraded Staging site keeps deploying. Trying two is not a timing oracle: the work is constant and the outcome is the same 403. |
| **Client** (sender) | Sign v1 **until the peer is proven** to speak v2, then v2 for good. | An un-upgraded Production site keeps receiving. Signing v2 unconditionally would break the first request against it. |
| **Server replies** | Sign every authenticated reply — **including replies to v1-signed requests**. | This is the bootstrap. A newly updated Staging site still signs v1, so if v1 callers got no signed reply there would be no proof and the pair would sit on v1 forever. |
| **Proof** | The response signature, and nothing else. | It is the only signal an attacker cannot fabricate. See §10 correction 4. |
| **Ratchet** | `Protocol::remember_peer_v2()` only ever writes v2. There is no downgrade path. | "Accept v1 if v2 fails" and "accept an unsigned reply" are each a downgrade. Once a peer has signed, both are refused. |

The net effect: **update either site first, in any order, with no configuration step.** The
pair upgrades itself on the first successful call after the second site is updated. While the
versions are mixed, one `info` entry per hour notes the legacy signature — deliberately not a
warning, because it is not an error.

### The one escape hatch, and where it can be reached from

`Protocol::forget_peer()` resets to v1. That is the whole anti-downgrade control's weak
point, so where it can be called from *is* its security. It is reachable from exactly one
place: the Settings save handler, behind `check_admin_referer()`, and only when the
connection details actually **changed** — re-saving identical values does not reset it. A
changed connection may be a different Production site, which is the one legitimate reason to
forget a capability claim.

`protocol-test.php` walks the whole `src/` tree with comments stripped and asserts that only
two files call it: its own definition, and that handler. If a third ever appears, the suite
fails — which is the point, because that is not a bug anyone would notice by reading a diff.

### What is on the wire

```
v1 request   sha256_hmac( timestamp \n nonce \n sha256(body), secret )
v2 request   sha256_hmac( timestamp \n nonce \n route \n sha256(body), secret )
reply        sha256_hmac( timestamp \n nonce \n sha256(wp_json_encode(data)), secret )
```

`route` is the last path segment with anything outside `[a-z0-9-_]` stripped, so the client's
`import` and the server's `/ifs-deploy/v1/import` both reduce to `import`. The reply reuses
the **request's** nonce and timestamp, binding it to the one request that asked for it.

Headers: `X-IFS-Deploy-Response-Signature` carries the reply signature.
`X-IFS-Deploy-Protocol` advertises what a side speaks — **diagnostics only**, never used to
decide what to accept. The signature is the proof; a header that changed behaviour would be a
downgrade lever.

### Coverage

`protocol-test.php` (72 assertions) covers all four states a real pair passes through — both
old, old→new, new→old, both new — plus: a cross-route signature is refused; a v1 caller still
receives a signable reply; a rejected request gets no reply signature; a tampered reply body
is refused; an unsigned **200** is refused once the peer has ever signed; an unsigned **401 /
403 / 429 / 409** still surfaces its real reason; and an unsigned body claiming `protocol: 2`
does not ratchet anything.

---

## 12 · Declined: a per-API-key rate limit (L-3a)

Recorded as a decision with the numbers behind it, because "we did not add a rate limit" reads
as an oversight otherwise. Asked directly by the site owner, whose team is ~15 editors working
on a site of ~2000 pages and posts.

### Editing makes no API requests at all

`Detection\ChangeTracker` is registered behind `Config::is_staging()` in `Plugin.php` and is
entirely local: `save_post` fires, a row goes into `{prefix}ifs_deploy_queue`, done. Fifteen
people editing two thousand pages all day produce **zero** requests to Production. The network
only happens when someone opens a screen that verifies against Production, or clicks Push.

### Every request site in the plugin

There are eight, and each is **one** request per user action — nothing loops per object:

| Route | Triggered by | Requests | Batching |
|---|---|---|---|
| `signatures` | Pending Changes render | 1 | up to 50 objects per call (`QueueVerifier::MAX_ROWS`) |
| `index` | Compare & Sync render | 1 | the whole production index |
| `import` | Push | 1 | up to 200 objects per call (`ifs_deploy_max_objects_per_import`) |
| `object` | Preview | 1 | per preview |
| `rollback-preview` · `rollback` | Rollback | 1 each | — |
| `link` | Sync IDs | 1 | all links in one call |
| `ping` | Test Connection · Diagnostics | 1 | — |

A heavy day for that team lands around **450 requests** in total. A full-site push of 2000
objects is ~10 sequential `import` calls (the cap refuses rather than truncating, so they are
ten deliberate pushes), spread over the time ten 180-second imports take.

### Why a limit would still hurt

**A Staging site has exactly one API key.** So a per-API-key limit is a limit on the *entire
team combined*, never per person. Three concrete failures:

1. **Simultaneous reloads.** Fifteen people opening Pending Changes after a standup is fifteen
   requests in a few seconds. Any per-minute limit low enough to be meaningful refuses some of
   them.
2. **Duplicate delivery.** This pair is already known to double-deliver occasionally (§8 H-3
   notes, `Verifier::note_duplicate`). Every one of those would count twice against the limit.
3. **The failure direction is wrong.** A refused `import` presents as a failed deploy. A
   refused `index` makes Compare & Sync show nothing — which reads as *"everything is in
   sync"* when it is not. A security control whose failure mode is a false all-clear is worse
   than its absence.

### And what it would actually buy

Very little that is not already covered:

| Concern | Already handled by |
|---|---|
| Unauthenticated flooding | `Auth\Lockout` — 12 failures in 15 minutes earns a 900s cool-off. This is the case *anyone* can attempt. |
| One request holding a worker | Batch cap of 200 + body size cap (M-5) + `TIMEOUT_HEAVY` |
| A huge media file | `Content-Length` HEAD check, then the real size on disk (M-5 / L-2a) |
| Repeated identical delivery | The replay nonce store (H-1a) |

What remains is only: *a Staging site holding a valid secret exhausts Production's resources.*
That same site can already overwrite every page on the production site through `/import`. Rate
limiting does not prevent that, it paces it — so the trade is a real cost to fifteen people's
daily work against a marginal gain in a scenario where the attacker has already won.

**Declined.** If pressure of this kind ever appears in the API access log, the right control is
not a rate limit but a **concurrency guard** — refuse a second `import` from the same key while
one is still running. That bound is independent of team size, because fifteen people do not
push simultaneously, and if they did they *should* be serialised.

---

## 13 · AJAX direction guard

Not a finding — no report, no exploit, and it was unreachable from the screens. Recorded
because it closes an inconsistency worth not having.

The REST layer enforces direction: `/import` and `/rollback` answer **409** unless the
receiving site is set to Production. The AJAX layer had no equivalent, so `ifs_deploy_deploy`
and `ifs_deploy_deploy_posts` checked only capability and nonce. A Production administrator
POSTing either one directly would open a deployment record and attempt a push.

Three things already made it harmless — the queue on a receiver is always empty because
`ChangeTracker` never runs there, Pending Changes is now Staging-only, and Production has no
remote configured to push to. `Ajax::require_staging()` stops relying on all three staying
true, and answers **409** to match how the REST side reports the same rule.
