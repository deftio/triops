# Working on triops

Conventions for coding agents and new contributors. Read `llms.txt` first for
what triops is and how it is put together; this file is about changing it.

## Before you change anything

triops is deliberately small and deliberately dependency-free. Most "obvious
improvements" have already been considered and rejected on purpose — the
reasoning is in `dev/todo.md` under **Deliberately NOT doing**. Check there
before adding roles, a router, composer, a cron job, or a driver.

## Checks

```sh
# lint everything (this is the check that matters most)
find app dev -name '*.php' -exec php -l {} \;

# run it
php -S 127.0.0.1:8777 -t app
# then open http://127.0.0.1:8777/

# smoke test
./dev/smoke.sh
```

There is no PHPUnit and no composer. The smoke test is bash and curl on purpose:
adding a test framework would add the build step the project exists without.

## Rules

1. **No runtime dependency may be added to `app/`.** Not composer, not a
   package, not a service. The only vendored asset is bitwrench, pinned.
2. **PHP 8.0 syntax only.** No enums, no `readonly`, no `never`, no pure
   intersection types. CI runs 8.0, 8.2 and 8.4.
3. **No hand-written CSS.** bitwrench derives the palette. If you need a style,
   use `bw.css()` with palette values, or an inline style on a TACO node.
4. **No `innerHTML`, no HTML strings in JavaScript.** Build TACO objects and let
   bitwrench render them. See the bitwrench llms.txt.
5. **Plain-text endpoint output is frozen.** People script against
   `timestamp.php` and `sum.php`. Changing their format is a breaking change.
6. **Everything user-supplied that touches the filesystem goes through
   `t_channel()`.** That function is the only thing standing between a posted
   `device_id` and `../../`.
7. **API responses go through `t_ok()` / `t_err()`.** Never emit a bare array,
   never return 200 with an error body.
8. **`t_csrf_check()` at the top of every POST handler** that changes state.
9. **Catch `Throwable`, not `Exception`.** A missing extension is an `Error` in
   PHP 7+; catching `Exception` misses it and the page fatals. This was a real
   bug in 0.1.

## Adding an endpoint

Copy the matching template and edit it:

- `app/templates/plain.php` → plain-text endpoint
- `app/templates/api.php` → JSON endpoint under `app/api/`
- `app/templates/page.php` → HTML page

Then add it to `t_manifest()` in `app/lib/triops.php` so it appears on the home
page. The manifest is the single source of truth — the home page renders itself
from it, which is why the page cannot drift from what actually ships. Update
`docs/endpoints.md` too.

If your endpoint took more than about five lines of scaffolding, that is a bug
in `lib/`, not a reason to write more scaffolding.

## Storage

Do not talk to SQLite or the filesystem directly from a page. Go through
`t_store()`. If you genuinely need a new operation, add it to the abstract
`TriopsStore` and implement it in **both** drivers — a feature that only works
under sqlite silently breaks every host without the extension.

## Commits

Keep file moves in their own commit, separate from content changes. The diff is
unreviewable otherwise, and this repo gets read as a worked bitwrench example.

## Versioning

`TRIOPS_VERSION` in `app/lib/triops.php` is the single source of truth. CI
asserts the git tag matches it. `TRIOPS_API_VERSION` is a separate integer that
changes only on a breaking `/api` change.

## Found a bitwrench problem?

triops is a real consumer of [bitwrench](https://github.com/deftio/bitwrench),
so it surfaces real bugs. Note them in `dev/bitwrench-notes.md` as you hit them —
friction is vivid while you are in it and forgotten by evening — and file
upstream. `status.php` reports the vendored bitwrench version, so include that.

## Re-recording the README demo

`docs/triops-demo.gif` is generated, not hand-captured:

```sh
cd dev && npm install && npx playwright install chromium   # one time
./dev/record-demo.sh                                       # -> docs/triops-demo.gif
```

It starts a throwaway triops, drives it in a real browser, and converts the
recording with ffmpeg — so the GIF cannot show a UI that does not exist. Re-run
it after any change to `view.php` or the payload rendering.

Playwright lives in `dev/package.json` and is dev-only. Nothing in `app/` has a
dependency of any kind, and `dev/` is excluded from the release zip.
