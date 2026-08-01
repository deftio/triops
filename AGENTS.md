# Working on triops

Conventions for coding agents and new contributors. Read `llms.txt` first for
what triops is and how it is put together; this file is about changing it.

If you are a human opening your first PR, start with
[`CONTRIBUTING.md`](./CONTRIBUTING.md) — how to report a bug, what was cut on
purpose, and what will get a PR turned down. Then come back here for the
conventions.

## Before you change anything

triops is deliberately small and deliberately dependency-free. Most "obvious
improvements" have already been considered and rejected on purpose — the
reasoning is in `dev/todo.md` under **Deliberately NOT doing**. Check there
before adding roles, a router, composer, a cron job, or a driver.

## Checks

```sh
# lint everything (this is the check that matters most)
find app dev -name '*.php' -exec php -l {} \;

# the renderer's element allowlist still matches the published schema
php dev/check-taco-tags.php

# the sanitiser still refuses hostile TACO — 67 adversarial cases
node dev/check-taco-sanitizer.mjs

# run it
php -S 127.0.0.1:8777 -t app
# then open http://127.0.0.1:8777/

# smoke test — run it on both drivers, they fail differently
./dev/smoke.sh
STORE=ndjson ./dev/smoke.sh
```

CI runs all of these on PHP 8.0, 8.2 and 8.4, plus one job with the sqlite
extension deliberately absent so the ndjson fallback is exercised for real.

There is no PHPUnit and no composer. The smoke test is bash and curl on purpose:
adding a test framework would add the build step the project exists without.

`STORE` matters: `smoke.sh` writes `app/config.php` itself to redirect the data
directory, so setting the driver by writing that file first does nothing — it
gets clobbered. That bug shipped in CI and went unnoticed for a release.

### What is in `dev/`

Nothing here ships. It is excluded from the release zip.

```
smoke.sh                  endpoint walk, both drivers, bash + curl
check-taco-tags.php       renderer allowlist vs. published schema
check-taco-sanitizer.mjs  adversarial cases against sanitizeTaco()
record-demo.sh / .mjs     regenerate docs/triops-demo.gif
make-social-card.py       regenerate pages/assets/social-card.png
todo.md                   what shipped, what was cut, and why
bitwrench-notes.md        friction hit while using bitwrench, for filing upstream
socket.php                a toy; not part of the product
```

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
10. **Nothing user-supplied is stored without `t_redact()`.** Query parameters
    and request headers both go through it. A debugging tool that writes down a
    key someone pasted once to test a board has turned a temporary secret into
    permanent telemetry. The name survives, the value becomes `[redacted]`.
11. **Never assume a request body is text.** Devices send CBOR, protobuf,
    compressed frames, and buffers with bugs in them. `t_make_record()` base64s
    anything that is not valid UTF-8 and labels it with `body_encoding`;
    anything that reads a body must honour that field. Records written before
    0.2.1 have no such field and are `utf-8` by default.
12. **`json_encode()` returns `false`.** It does so on any input it cannot
    represent, and `false` concatenated or echoed is an empty string. That is
    how a packet got silently dropped under ndjson and how a channel became
    permanently unreadable under sqlite. Check the return value.
13. **The TACO allowlist and the schema are one list living in two files.**
    `TACO_TAGS` in `app/assets/triops.js` and the `t` enum in
    `docs/taco-wire.schema.json` must agree; `dev/check-taco-tags.php` fails CI
    if they do not. A renderer laxer than the schema it publishes is worse than
    shipping no schema.

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

Two invariants that are easy to break by accident:

- **`init()` must actually open the thing.** It is what the factory calls to
  decide whether a driver works before committing to it. A constructor that only
  remembers a path always succeeds, which makes automatic fallback a fiction —
  that was the bug in 0.2.0, where fallback fired for a missing extension and
  nothing else.
- **Never `flock` the ndjson data file.** Compaction ends in a `rename`, so a
  writer holding a lock on the data file holds a lock on an inode that is about
  to stop being the data file, and appends into it are written, flushed, and
  lost. The lock is a separate `ch-NAME.lock.php`, held across append *and*
  compaction, because a lock file is never renamed.

Every file the app writes into `data/` ends in `.php` and starts with
`<?php exit; ?>`. Not decoration: named anything else it is served as static
text by any host that ignores `.htaccess`, which is nginx, and `php -S`, and
plenty of shared hosting.

The sqlite database is the one exception — you cannot prepend an exit guard to a
binary file. That is why its filename carries a random suffix, and why the marker
recording that name (`dbname.php`) is itself guarded. Move `data_dir` outside the
web root on any host that allows it; `docs/install.md` covers it.

## Commits

**Manu does all commits and pushes himself.** Do not run `git commit` or
`git push` — finish the work, verify it, leave the tree dirty, and say what is
ready. The commit is his sign-off, and on this repo a version bump is what
publishes a release, so there is a human between "done" and "public".

Keep file moves in their own commit, separate from content changes. The diff is
unreviewable otherwise, and this repo gets read as a worked bitwrench example.

## Releasing

`TRIOPS_VERSION` in `app/lib/triops.php` is the single source of truth, and the
release is driven from it — there is no tag to remember to push.

To cut a release:

1. bump `TRIOPS_VERSION`
2. bump `version` in `pages/assets/version.js` to match
3. add a matching `## [x.y.z]` section to `CHANGELOG.md`, and a compare link at
   the foot of that file
4. update the example output in `docs/api.md` and `app/api/version.php`
5. push to master

Steps 2 and 4 are checked by CI, so forgetting them fails the build rather than
shipping documentation that disagrees with the product. Step 3 is checked by the
release job.

`CHANGELOG.md` **is** the release notes — the section you write is published
verbatim as the body of the GitHub release. Write it for someone deciding
whether to upgrade.

CI reads the version, sees no `vx.y.z` tag yet, runs lint and the smoke test,
builds the zip, creates the tag, and publishes the release with that changelog
section as the body. Pushes where the version has not changed do nothing.

The changelog section is also the guard: bump the version without writing the
notes and the build fails rather than publishing an empty release.

`TRIOPS_API_VERSION` is a separate integer that changes only on a breaking
`/api` change.

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

Two things in `record-demo.mjs` look incidental and are not. Payloads are posted
with node's `http` module rather than `fetch`, because `fetch` adds
`accept-language` and `sec-fetch-*` of its own — and now that the inbox shows
headers, that made a device post look like a browser request on screen. And the
`WIDTH`/`FPS` defaults are the ones the committed GIF was made with; change them
and the next re-record silently produces a different-sized image.

The palette is 64 colours with dithering off. Dithering exists to fake shades
that are not in the palette, and on flat UI colour it only adds noise the encoder
then has to store — it doubled the file for no visible gain.

Playwright lives in `dev/package.json` and is dev-only. Nothing in `app/` has a
dependency of any kind, and `dev/` is excluded from the release zip.

## The Docker runner

`docker/` holds a Dockerfile and its own README. It exists for people who have
Docker and no PHP — increasingly common, since macOS stopped shipping it — and
it is **not** a deployment artifact and **not** a published image. `php -S` is a
development server, the README says so, and nothing in a registry can go stale
if there is nothing in a registry.

It is not in the release zip: the zip is `cp -r app`, so only `app/` ships. The
root `.dockerignore` keeps `dev/node_modules` out of the build context, which
would otherwise send hundreds of megabytes of Playwright to the daemon.

If you change how `app/` is laid out, check the `COPY` line still makes sense.
Nothing tests this image in CI, on purpose — it would mean a Docker build on
every push for a convenience script.

## The site

`pages/` is a directory of static files that GitHub serves directly from the
branch — no build step, no staging script, no deploy workflow. It carries its
own copies of `bitwrench.umd.min.js` and `triops.js` rather than referencing
`app/assets/`, because a served directory has to be self-contained.

That means two copies. Fine — they are pinned files that change rarely, and CI
diffs them. If you change `app/assets/triops.js`, copy it to `pages/assets/`.
Bump `pages/assets/version.js` when you bump `TRIOPS_VERSION`; CI checks that
too.

`pages/demo/index.html` is a hand-maintained mock — GitHub Pages cannot run PHP,
so the payloads are canned and the login accepts anything. It loads the real
`triops.js` and mirrors the real pages, and its own comment promises as much, so
**anything you change in how `view.php` renders an entry has to be changed here
too.** Nothing checks this for you. It drifted once already: 0.2.1 added the
headers panel and the demo went a release without it.
