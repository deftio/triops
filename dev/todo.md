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

## Before tagging v0.2.0

- [x] **GIF recorded** — `./dev/record-demo.sh` drives the real app in a real
      browser with Playwright and converts with ffmpeg, so it can never show a
      UI that does not exist. Re-run it after any UI change.
- [ ] set the GitHub repo description and topics:
      `esp32` `iot` `embedded` `debugging` `php` `self-hosted` `webhook`
- [ ] social preview image so shared links do not look dead
- [x] GitHub Pages — `pages/` is committed static files served from the branch
      at `/triops/pages/`. No workflow, no settings change.
- [ ] read `docs/` end to end once more with fresh eyes
- [ ] push to master — CI reads TRIOPS_VERSION, tags v0.2.0 and publishes.
      No tag to push by hand; bump the const + changelog section is the release.

## After tagging

- [ ] post it: r/esp32, r/embedded, r/selfhosted, Show HN, Hackaday tip line
- [ ] link triops from the bitwrench docs as a worked example
- [x] nothing to file upstream — both apparent bitwrench issues were triops not
      reading the docs. See `dev/bitwrench-notes.md`.

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
