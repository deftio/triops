# triops todo — 0.2 plan

Nobody is on 0.1, so 0.2 is a clean break: no migration path, no deprecation
shims, no upgrade docs. 0.2 is intended to be a **long-lived** release for a
niche audience — favor doing less, well, over doing more.

Success metric: 25 GitHub stars.

## Hard constraints

Say no to anything that violates these:

- no composer, no framework at runtime — stay composer-*compatible*, never composer-*dependent*
- no runtime dependencies at all: no redis, no daemons, no cron, no build step
- file-per-URL routing; no front controller, no mod_rewrite requirement
- pure HTML UI, no JS framework; forms work with JS disabled
- PHP 8.0 floor
- a new endpoint is ~5 lines on top of the bootstrap. **If it ever isn't, the framework failed.**

## The install test

The deploy README must fit in four steps. Anything that adds a fifth has to argue for itself.

1. unzip into your webroot
2. load the page, create your admin user
3. point your device at `/api/ingest.php`
4. watch it arrive

Editing config is deliberately *not* a step. Defaults must work out of the box;
`config.php` is for changing things, not for starting.

---

## Layout

```
app/          ← the deployable unit. this is what the zip contains.
  README.md   ← deploy guide; renders when browsing app/ on GitHub
  .htaccess
  index.php
  config.sample.php
  config.php          (gitignored)
  lib/                (.htaccess deny + defined('TRIOPS') guard)
  templates/
  assets/
  api/
  data/               (gitignored, created on first run)
docs/         ← all markdown
pages/        ← GH Pages site: landing + pages/demo (JS-mocked)
dev/          ← scratch, ships in nothing
dist/         ← build output, gitignored
```

- [ ] restructure to the above; move root `assets/` into `app/assets/`
- [ ] `pages/` contains no prose that also lives in `docs/` — landing page links out to docs
- [ ] zip stages `app/` → `triops/` so it unpacks with a sensible directory name

## Core / mini framework

- [ ] `lib/triops.php` — one bootstrap; every page opens with `require '../lib/triops.php';`
- [ ] `config.php` (gitignored) + `config.sample.php` — kills the duplicated `$triopsConfig` arrays
- [ ] response helpers — `t_text()`, `t_json()`, `t_status()`; explicit `Content-Type` everywhere
- [ ] one layout — header / nav / footer / `render()`
- [ ] `defined('TRIOPS')` guard in every lib file (`.htaccess` is a no-op on nginx, so the guard is the real defense)
- [ ] `TRIOPS_VERSION` const in the bootstrap; CI asserts it matches the git tag
- [ ] timezone from config
- [ ] endpoint manifest array — index page renders its own list, so page and docs can't drift
- [ ] 404 page
- [ ] delete `version_compare` compat in `timestamp.php`; delete all `strftime()` (removed in 8.1)

## Store — replaces redis entirely

Redis usage in 0.1 was: set a scalar, `rpush`, `llen`, `lpop`, `lrange`. That's a
capped append-only list per channel plus two scalars. Build it.

- [ ] interface: `push()` / `last()` / `clear()` / `channels()`
- [ ] **sqlite driver (default)** — `entries(id, channel, ts, body)` + index on `(channel, id)`, `kv(k, v, expires_at)`
  - [ ] `PRAGMA journal_mode=WAL` — non-negotiable; view pages poll at 250ms while devices post,
        and rollback-journal mode will throw "database is locked" under exactly that pattern
  - [ ] `PRAGMA busy_timeout`, `synchronous=NORMAL`
  - [ ] random filename suffix at first run (`triops-a8f3d21b.sqlite`) — a sqlite file can't self-protect
        and is fully readable if the directory is ever served
- [ ] **ndjson driver (fallback, zero extensions)** — append-only, `fopen('a')` + `flock` + one `fwrite`.
      NOT read-modify-write; that rewrites the whole buffer per packet.
  - [ ] `<?php exit; ?>` as line 1, skipped on read, so contents don't leak if served
  - [ ] compact only when the file exceeds ~4x target length
  - [ ] side benefit worth preserving: `tail -f app/data/unregdev.ndjson` is a great debugging experience
- [ ] auto-detect driver; config can force one
- [ ] **trim on write, in the same transaction. No timer, no cron.**
- [ ] lazy expiry on `kv` — check `expires_at` on read
- [ ] cap payload size in config — a device in a retry loop can currently fill the disk
- [ ] no redis driver. Interface allows one; don't write it unless someone asks.

## User management — keep it small

~150 lines total. Users are humans; devices are not users.

- [ ] `users.json`, atomic write via temp+rename
- [ ] `password_hash()` / `password_verify()` — delete the md5 cookie scheme
- [ ] native sessions; `session_regenerate_id()` on login; HttpOnly + SameSite=Lax + Secure-when-HTTPS
- [ ] CSRF token on every state-changing form
- [ ] first-run setup — no users file means "create your first user", never a default credential.
      (Config-file users would force plaintext passwords in config, which is the 0.1 bug in a new hat.)
- [ ] UI: list / add / delete / change password
- [ ] recovery is "delete `users.json` and reload" — document it in `app/README.md`
- [ ] **no roles.** Logged-in vs. not is the only distinction.
- [ ] **no login throttle** — needs state, and auth must not depend on the store being healthy
- [ ] no email, no password reset flow, no audit log
- [ ] fix or drop `logVisits()` — currently writes to `""` because `$LOG_FILE` isn't in scope

## Endpoints

Framing: **httpbin for microcontrollers, plus a payload inbox.**

Two surfaces, and the line between them stays sharp:

- `/` — plain text, informal, no envelope, **stable forever**. A board with a 2KB HTTP
  client must not have to parse JSON to check connectivity.
- `/api/` — JSON, versioned, enveloped, for dashboards and client libraries.

Keep + port: `timestamp`, `sum`, `ip`.

- [ ] `echo.php` — return exactly what was sent, headers included
- [ ] `headers.php` — request headers as the server sees them
- [ ] `status.php` — driver, writable?, PHP version, triops version, counts (absorbs `dev/sqlitever.php`)
- [ ] `delay.php?ms=2000` — test client timeouts
- [ ] `code.php?c=500` — arbitrary HTTP status, test device error handling
- [ ] `bytes.php?n=4096` — N bytes, test buffer limits on constrained devices

## API

`app/api/` is the namespace. Directory-as-namespace; no router. Future versions are `api/v2/`.

- [ ] `api/version.php` → `{"name":"triops","version":"0.2.0","api":1}` — **unauthenticated**
- [ ] `api/status.php` — authenticated (leaks config)
- [ ] `api/read.php?channel=X&n=50` — replaces `sleevestreamapi.php`
- [ ] `api/ingest.php` — canonical JSON ingest
- [ ] `api/clear.php?channel=X`

Rules:

- [ ] one envelope always — `{"ok":true,"data":…}` / `{"ok":false,"error":"…","code":"…"}`
- [ ] real HTTP status codes, never 200-with-an-error-body
- [ ] integer `api` version separate from product version, so clients feature-detect without parsing semver
- [ ] `Content-Type: application/json` always
- [ ] document the plain-text endpoints' output format as a stability promise — people will script against them

## Templates

Three. Adding a page should be copy-paste. Pairs with `docs/hacking.md`.

- [ ] `templates/plain.php` — plain-text endpoint
- [ ] `templates/page.php` — HTML page, authenticated
- [ ] `templates/api.php` — JSON in / JSON out

## Security

- [ ] credentials out of git (`admin/triops` and `demo/device` are currently committed)
- [ ] `data/` must not be web-readable — `pages/data/lastpacket.json` is directly fetchable today
- [ ] path traversal on read — `sleevestreamapi.php` puts `$_GET["device_id"]` straight into a file path
- [ ] arbitrary file write — `submitpacket.php` builds a filename from `meta.device_id` in the *posted JSON*
- [ ] `rawview.php` auth include is commented out
- [ ] catch `Throwable`, not `Exception`, around storage init — a missing extension is an `Error` in PHP 7+
- [ ] optional shared `ingest_key` in config — 90% of device registration's value for 5% of the work,
      and forward-compatible with per-device tokens later (`hash_equals` against one key → against a lookup)
- [ ] README: not hardened for the open internet; run on a LAN or behind a VPN

## Cleanup / branding

- [ ] remove **all** Kinisi branding — `pages/index.php`, `pages/rawview.php`, and `sleevestreamapi.php` (→ `api/read.php`)
- [ ] fix `pages/index.php` — `<? php` with a space emits the whole block as text
- [ ] delete stubs `device.php`, `regdevice.php` (both render "Protected Page")
- [ ] delete `dev/undo.html`, `dev/questions.html`, `dev/clock.html`, `dev/sqlitever.php`
- [ ] merge `rawsend.php` + `submitpacket.php` (~80% duplicate)
- [ ] fix `rawsend.php` `raw_get` — `parse_url()` on a bare query string always yields empty
- [ ] fix `testrawsend.php` — `ltrim($s, "rawpost_data=")` strips characters, not a prefix
- [ ] fix duplicated `</script></body>` tail in `rawview.php`
- [ ] drop bootstrap entirely (`bootstrap.min.css` + `bootstrap.min.js`) — bitwrench `loadStyles()` replaces it
- [ ] upgrade bitwrench to 2.x, **vendored** in `app/assets/`, not CDN — a lab LAN may have no internet,
      and "unzip and go" must not depend on jsdelivr being reachable. Pin the version.
- [ ] rewrite the 0.1 bitwrench calls — the old API is gone in 2.x:
      `bw.htmlJSON` / `bw.getURLParam` / `bw.URLParamParse` / `bw.URLParamPack` no longer exist;
      `bw.DOM(sel, taco)` now mounts a TACO, not an HTML string.
      Use `bw.makeTable` for the payload list, `URLSearchParams` for the refresh param.
- [ ] no hand-written `triops.css` — use `bw.loadStyles({primary, secondary})` + `bw.css()` per the llms.txt
- [ ] `bw.toggleThemeMode()` gives dark mode for free; wire it into the nav
- [ ] `rawview-graph.php` has never actually graphed anything — bitwrench ships charts, so make it real
- [ ] move `access/socket.php` → `dev/socket.php` (see Resolved for why not `tools/`)
- [ ] update LICENSE year; add `.editorconfig`

## Docs — treat as the product, not the appendix

For a 1500-line tool the docs *are* the value. Task-oriented, not reference-oriented.
Everything copy-pasteable.

- [ ] README — one-sentence pitch, GIF, 4-step install, endpoint table, honest requirements.
      Delete the `devpost`/`devread`/`devregister` claims, the PHP 5.x claim, the redis instructions.
- [ ] `docs/install.md`
- [ ] `docs/endpoints.md`
- [ ] `docs/api.md` — table, not OpenAPI
- [ ] `docs/hacking.md` — "add your own page in 5 lines". The real framework doc.
- [ ] `docs/troubleshooting.md` — on-brand for a debug tool, and underrated.
      "posts but nothing shows up", "connection refused", "works with curl but not from the board"
      (usually Content-Type or chunked encoding)
- [ ] `docs/examples/` — curl, ESP32/Arduino `HTTPClient`, Python, MicroPython
- [ ] limitations section — states what it deliberately isn't; builds trust faster than features
- [ ] CHANGELOG.md (Keep a Changelog)

## Site

- [ ] `pages/` published via GitHub Actions (`upload-pages-artifact` — the legacy branch setting
      only allows `/` or `/docs`, Actions allows any directory)
- [ ] landing page: pitch, GIF, install snippet, links to docs
- [ ] `pages/demo/` — GH Pages **cannot run PHP**, so login and packet view are JS-mocked
      against canned JSON. Label clearly as a demo.
- [ ] publish workflow copies `app/assets/triops.css` into the site so the demo can't drift from the real UI
- [ ] build this **last**

## CI / release

- [ ] `php -l` over every file in `app/` — highest-value check here; would have caught the `<? php` bug
- [ ] matrix 8.0 / 8.2 / 8.4
- [ ] smoke test — `php -S localhost:8000 -t app/`, curl each endpoint, assert status + body. Bash + curl, no PHPUnit.
- [ ] CI asserts git tag matches `TRIOPS_VERSION`
- [ ] release workflow: on tag, zip `app/` → `dist/triops-v0.2.0.zip`, generate `SHA256SUMS`, attach to GitHub Release
- [ ] `dist/` gitignored — the zip's audience is people who never clone; committing it serves nobody
      and adds undeltable binaries to history forever
- [ ] link `releases/latest/download/triops.zip` from docs so they never need a version bump
- [ ] tag `v0.2.0`, semver from here

## Launch / discoverability

Repos are not discovered by existing. Cheap, high-leverage, easy to forget:

- [ ] **an animated GIF** of a board posting and the payload appearing — highest-ROI artifact in the project,
      worth more than the demo site
- [ ] GitHub repo description + topics: `esp32` `iot` `embedded` `debugging` `php` `self-hosted` `webhook`
- [ ] social preview image (og:image) so shared links don't look dead
- [ ] "why not httpbin / webhook.site / RequestBin" section — self-hosted, LAN-capable, owns its data,
      and both halves in one place. Positioning helps people self-select.
- [ ] post once it's genuinely good: r/esp32, r/embedded, r/selfhosted, Show HN, Hackaday tip line
- [ ] CI badge + a tagged release — visible proof it's alive

## Deliberately NOT doing

Cut on purpose. Don't let these creep back.

- roles / permissions — logged-in vs. not is enough
- login throttling, password reset, email, audit log
- device registration (add/list/rotate/del) — `ingest_key` covers it; revisit only if asked
- redis driver
- docker-compose — dropping redis removed its reason to exist
- more than 3 templates
- `build.json` / commit-SHA stamping
- markdown link checking in CI
- OpenAPI spec
- a router, a front controller, composer, any runtime dependency

## Resolved

- PHP floor: **8.0** (7.4 has been EOL ~4 years; 8.0 costs nothing over 8.2 and covers older shared hosts)
- app dir named `app/` — `src/` implies a build step that doesn't exist
- humans in `users.json`, devices + entries + kv in one `triops.sqlite` (not separate .sqlite files —
  no cross-table transactions, more handles, separate WALs)
- the real boundary is humans vs. machines, not admin vs. viewer
- store default sqlite, fallback ndjson, no redis
- `dist/` gitignored, releases via GitHub Releases

- bitwrench **stays**, upgraded to 2.x and vendored — but **only on the ~4 HTML UI pages**
  (index, rawview, admin, testrawsend). The plain-text debug endpoints and everything under
  `/api/` emit no HTML and load no JS, so the "2KB HTTP client never parses JSON" promise is untouched.
  bitwrench 2.x is zero-dep, no build step, and ships an ESP32/Pico W tutorial — same audience as triops.
- `access/socket.php` → **`dev/`**, not `tools/`. It needs `ext-sockets` (not compiled into many PHP
  builds), can't run on shared hosting at all (long-running CLI process), and has real bugs — the
  `socket_set_option` calls sit inside the `socket_create` *failure* branch so they never run on success,
  and the startup banner echoes `$adresss`. `tools/` promises documented + tested + supported; nothing
  in 0.2 backs that. `dev/` is already "scratch, ships in nothing," which is the honest label.
  Promotion path: fix those bugs, write a README paragraph, add it to `php -l` in CI → earns `tools/` in 0.3.

## Open

- none
