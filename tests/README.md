# IFS Deploy tests

Plain-PHP suites. **No WordPress, no PHPUnit, no `composer install`** — each suite
stubs the handful of WordPress functions it touches and runs straight from the CLI.

```bash
php tests/run-all.php            # everything, exits non-zero on failure
php tests/access-test.php        # one suite
```

The PHP used during development was `D:\xampp\php\php.exe` (8.2.12).

## What is covered

| Suite | Covers |
|---|---|
| `access-test` | role + per-user capability matrix, administrator lockout safety |
| `metablock-test` | which post meta is ignored vs deployable, and the filter |
| `queue-revert-test` | edit → deploy → edit → undo queue lifecycle |
| `url-test` | URL variants; `rewrite()` vs `neutralize()` |
| `rollback-diff-test` | snapshot → package reshaping, `full_replace` semantics |
| `verifier-test` | which queue rows may be auto-cleared (fails closed) |
| `meta-test` | deep meta rewriting, serialization round-trip |
| `media-url-test` | remapping URLs when Production renamed a file |
| `media-match-test` | attachment matching order |
| `media-identity-test` | rename-proof featured-image comparison |
| `byref-test` | the `array_walk_recursive` by-reference fatal and its fix |
| `signature-test` | content signatures stay stable across domains |
| `syncheck-test` | the shared "is it in sync?" rule |
| `diff-test` | field-level diff output (prints a report, not assertions) |
| `ui-hooks-test` | the JS↔markup contract: ids/classes `admin.js` targets, that the tab shell's chrome lives only in `Screen`, and that no JS binding is left un-delegated |
| `render-test` | **renders every tab for real** behind WordPress stubs; catches undefined methods, PHP notices and unbalanced `<div>`s, and checks a delegated role's tab bar |

Repo-wide checks, worth re-running after any refactor:

- **`psr4.php`** — every class sits where the hand-rolled autoloader will look for it.
  A mismatch is invisible until runtime, when it becomes a fatal "class not found".
- **`unused-imports.php`** — leftover `use` statements.
- **`jsbalance.php`** — string- and comment-aware bracket balance for `admin.js`,
  standing in for `node --check` (no Node installed). Not a parser: it catches an
  unclosed brace, not every syntax error.

## Known gaps

These are **unit-level**. Nothing here exercises a real signed round-trip between two
WordPress installs, or media downloads — those have only been tested by hand against the
live staging/production pair.

`render-test` covers the admin screens only as far as PHP goes: it proves each tab
renders without error and emits well-formed markup on a fresh, unconfigured install. It
does **not** run JavaScript, so the tab switching itself, dialog behaviour and the
autocomplete pickers still need a browser. `ui-hooks-test` compensates by asserting the
contract statically — but "the selector exists" is not "the click works".

## Gotchas if you extend these

- **Do not use `glob()`** in this project's tooling. The repo path contains `[22020]`,
  which `glob()` parses as a character class, so it silently returns nothing. Use
  `scandir()` or `RecursiveDirectoryIterator`.
- `QueueRepository`, `PostExporter` and `DeployClient` are `final`, so collaborators
  cannot be stubbed by subclassing. Where a decision needed testing it was extracted
  into a pure `public static` method instead (see `QueueVerifier::should_clear()`).
- Suites load the plugin via `__DIR__ . '/../src/…'`, so they keep working if the repo
  moves.
- Exclude this directory from any wordpress.org build (`.distignore`) and from SFTP
  upload — it is developer tooling, not part of the plugin.
