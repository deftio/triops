# triops todo

0.2 is built. This file is now the record of what shipped, what was cut, and
what is next.

## Hard constraints (still binding)

- no composer, no framework, no runtime dependency of any kind
- file-per-URL routing; no front controller, no mod_rewrite requirement
- runs on a plain shared host; sqlite optional, nothing else needed
- pure HTML UI, no JS framework, login and setup work with JS disabled
- PHP 8.0 floor
- **a new endpoint is ~5 lines on top of the bootstrap.** If it stops being
  that, fix `lib/` rather than writing more scaffolding.

## The install test

Four steps, and none of them is "edit a config file". Anything that adds a fifth
has to argue for itself.

1. unzip into your webroot
2. load the page, create your account
3. point your device at `api/ingest.php`
4. watch it arrive

---

## Shipped in 0.2

- [x] restructure to `app/` / `docs/` / `pages/` / `dev/`; release zip is exactly `app/`
- [x] `lib/triops.php` bootstrap, `config.sample.php` with working defaults
- [x] store: sqlite (WAL, trim inside the insert transaction) + ndjson fallback
      (append-only, `flock`, compacts at 4x). No cron, no timer.
- [x] redis removed entirely
- [x] users.json + `password_hash` + sessions + CSRF + first-run setup; no roles
- [x] primitives: timestamp, sum, ip, echo, headers, delay, code, bytes
- [x] api: version, status, read, ingest, clear — one envelope, real status codes
- [x] UI on bitwrench 2.1.3, vendored and pinned; zero hand-written CSS; dark mode
- [x] `triops.jsonTaco()` — arbitrary JSON to a browsable tree, raw always one click away
- [x] optional device-posted TACO rendering, off by default, handlers stripped
- [x] three templates; `t_manifest()` so the home page cannot drift from reality
- [x] docs: install, endpoints, api, hacking (+bitwrench primer), troubleshooting, examples
- [x] `llms.txt` and `AGENTS.md`
- [x] landing page + JS-mocked demo; `dev/build-site.sh` copies shared assets
- [x] CI: `php -l` on 8.0/8.2/8.4, smoke test on both drivers, one job with sqlite absent
- [x] release is version-driven: CI reads `TRIOPS_VERSION`, creates the tag,
      publishes zip + SHA256SUMS with that version's changelog section as the body
- [x] dependabot: security-only for the dev npm tooling, grouped monthly for actions
- [x] all Kinisi branding gone; dead pages and dev toys deleted
- [x] `socket.php` moved to `dev/`

### Security fixed

- [x] traversal on read and arbitrary write from posted JSON — all filesystem
      input goes through `t_channel()`
- [x] committed credentials and the unsalted md5 cookie gone
- [x] **data files named `.php` so their `exit` guard is executed, not served.**
      Named `.json` the guard is inert and bcrypt hashes leak to anyone who
      guesses the path on a host that ignores `.htaccess`. `dev/smoke.sh`
      asserts this with a `.json` control.
- [x] request bodies capped by `max_payload_bytes`
- [x] `Throwable` not `Exception` around storage init

---

## Shipping checklist

- [x] **GIF recorded** — `./dev/record-demo.sh` drives the real app in a real
      browser with Playwright and converts with ffmpeg, so it can never show a
      UI that does not exist. Re-run it after any UI change. Re-recorded for
      0.2.1: it now shows the headers panel and a binary payload hex-dumped,
      and closes on that frame.
- [x] GitHub repo description and topics set. `iot-framework`, `login` and
      `login-system` removed — the first fights the positioning and the other
      two describe scaffolding rather than the point.
- [x] GitHub Pages — `pages/` is committed static files served from the branch
      at `/triops/pages/`. No workflow, no settings change.
- [x] `docs/` read end to end. Everything 0.2.1 changed is described where it is
      described: the record shape in `api.md`, the inbox in `endpoints.md`, the
      helper signatures in `hacking.md`, the sanitiser in `llms.txt`.
- [x] v0.2.0 and v0.2.1 tagged and published by CI from `TRIOPS_VERSION`.
- [x] social preview image — `pages/assets/social-card.png`, 1280×640, wired as
      `og:image` on all three site pages. Regenerate with `dev/make-social-card.py`.
- [ ] upload that same file as the **GitHub repository** social preview.
      **Web UI only** — Settings → General → Social preview; `gh` cannot do it.

## When you want people to see it

- [ ] post it: Show HN first, then a personal social post, then the Arduino
      Forum Projects Showcase. r/selfhosted only if the account history makes it
      natural. Lead with the deployment model — a folder of PHP files on shared
      hosting — not with a feature list.
- [ ] link triops from the bitwrench docs as a worked example
- [x] nothing to file upstream — both apparent bitwrench issues were triops not
      reading the docs. See `dev/bitwrench-notes.md`.

## Shipped in 0.2.1

Correctness and trust boundaries. See CHANGELOG for the detail.

- [x] binary-safe bodies — `body_encoding`, base64 for non-UTF-8, hex dump in the
      inbox. ndjson was silently dropping these; sqlite was making the whole
      channel unreadable.
- [x] `t_json()` never emits an empty 200 again
- [x] credentials redacted out of stored query params and headers (`redact_keys`)
- [x] request headers stored and shown, with the same redaction
- [x] TACO sanitiser is an allowlist enforcing the published schema; drift
      checked in CI by `dev/check-taco-tags.php`
- [x] ndjson append + compaction under one per-channel lock file, separate from
      the data file because compaction renames over it
- [x] sqlite opened and verified during driver selection; `fallback_reason` on
      the status page
- [x] `.dbname` → guarded `dbname.php`, migrated on first run
- [x] smoke test asserts every real data file is unserveable, not just a canary
- [x] smoke test covers concurrent ingestion, with `PHP_CLI_SERVER_WORKERS` so
      it is actually concurrent
- [x] `users.json` → `users.php` in the recovery instructions
- [x] dependency claim reworded to what is actually true
- [x] three deployment profiles in `docs/install.md`

## Deliberately parked

Considered for 0.2.1 and cut, because triops is a good mini tool and should stop
there. Each of these makes it less distinctive, not more.

- **configurable ingest responses** (status, delay, truncation) — firmware
  developers do want to test retries and timeouts, but `code.php`, `delay.php`
  and `bytes.php` already cover the response side, and connection-close cannot be
  done portably under FPM anyway.
- **the TACO console** — a page rendering a live device-driven UI over long
  polling. Genuinely interesting, and a memorable demo, but it is a second
  identity for a tool whose first one is finally clear. The wire schema is
  written down; if it ever happens, snapshots and long polling, not patches and
  websockets. Notes: `session_write_close()` before any wait loop or the poll
  holds the session lock and freezes every other tab, and cap the wait near 10s
  because each one occupies an Apache worker.
- MQTT, dashboards, device registries, retention policies, more storage engines.

## Known rough edges

- ndjson retains between N and 4N entries where sqlite retains exactly N.
  Deliberate — compacting an append-only file per request defeats the point —
  but the config name reads like a hard cap. Documented in `config.sample.php`.
- The demo's canned data lives in `pages/demo/index.html` and is hand-maintained.
  If the viewer's rendering changes materially, it needs a look.

## Deliberately NOT doing

Cut on purpose. Do not let these creep back.

- roles / permissions — logged-in vs. not is enough
- login throttling, password reset, email, audit log
- device registration — `ingest_key` covers it; revisit only if asked
- a redis driver
- docker-compose — dropping redis removed its reason to exist
- more than 3 templates
- `build.json` / commit-SHA stamping
- markdown link checking in CI
- OpenAPI spec
- a router, a front controller, composer, any runtime dependency

## Resolved decisions

- PHP floor **8.0** — 7.4 has been EOL four years; 8.0 costs nothing over 8.2
  and covers older shared hosts
- app dir is `app/` — `src/` implies a build step that does not exist
- humans in `users.json`, everything else in one `triops.sqlite`; auth must keep
  working when the database does not
- the real boundary is humans vs. machines, not admin vs. viewer
- store: sqlite default, ndjson fallback, no redis
- `dist/` gitignored; releases are GitHub Release assets
- bitwrench stays, vendored not CDN — a lab LAN may have no internet
- `socket.php` lives in `dev/`, not `tools/`. `tools/` promises documented and
  tested; it needs `ext-sockets`, cannot run on shared hosting, and has bugs.
  Promotion path: fix them, document it, add it to CI lint.
