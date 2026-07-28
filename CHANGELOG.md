# Changelog

Format follows [Keep a Changelog](https://keepachangelog.com/). This project
uses [semantic versioning](https://semver.org/).

## [0.2.0] — 2026-07-27

A rewrite. 0.1 was never really deployed, so 0.2 is a clean break: no migration
path and no compatibility shims.

The headline is that **triops now has no runtime dependencies at all.** 0.1
wanted redis, php-redis, and a systemd restart before it would do anything. It
turned out to be using five redis operations — set a scalar, `rpush`, `llen`,
`lpop`, `lrange` — which is a capped list plus two scalars. triops implements
that itself now, so installation went from four commands to unzipping a folder.

### Added

- **Storage layer** with two drivers and no dependencies. SQLite by default
  (WAL mode, trimmed inside the insert transaction) and an append-only NDJSON
  fallback needing no extensions, which you can `tail -f` while a device posts.
  Trimming happens on write — no cron, no timer, nothing to quietly die.
- **New debug primitives:** `echo.php` (plays your whole request back),
  `headers.php`, `delay.php` (test client timeouts), `code.php` (test error
  paths), `bytes.php` (find receive buffer limits). All plain text.
- **JSON API** under `api/`: `version`, `status`, `read`, `ingest`, `clear`.
  Consistent envelope, real status codes, and an integer API version separate
  from the product version so clients feature-detect without parsing semver.
- **User accounts** — `password_hash`, native sessions, CSRF tokens on every
  form, and a first-run setup page. No default credentials ship.
- **`status.php`** — active driver, data directory writability, PHP and
  bitwrench versions, per-channel counts. The first place to look when something
  is wrong.
- **Optional `ingest_key`** for gating device posts.
- **Optional device-rendered UI** — a device can post a bitwrench TACO and have
  triops render it. Off by default; handlers are stripped when on.
- **Templates** in `app/templates/` for the three kinds of page.
- **Docs**: install, endpoints, api, hacking (with a bitwrench primer),
  troubleshooting, and worked examples for curl, ESP32/Arduino, Python and
  MicroPython.
- **`llms.txt` and `AGENTS.md`** so coding agents can work on this correctly.
- **CI** — `php -l` across 8.0/8.2/8.4 plus a 34-check bash smoke test.
- **`dev/smoke.sh`** — bash and curl, no test framework.

### Changed

- **Layout.** Everything deployable is now `app/`, which is exactly what the
  release zip contains. `docs/` is markdown, `pages/` is the site, `dev/` ships
  in nothing.
- **The UI is [bitwrench](https://github.com/deftio/bitwrench) 2.1.3**, vendored
  and pinned so it works on a network with no internet. Bootstrap is gone, and
  so is all hand-written CSS — the palette, including dark mode, is derived from
  two seed colors in config.
- **Configuration** is one optional `config.php` with a fully documented sample.
  Defaults work with no edits at all.
- Payload rendering shows a browsable tree for JSON **with the raw bytes always
  one click away**. A prettified view hides exactly the malformed-payload bugs
  you are hunting.

### Fixed

- `pages/index.php` began `<? php` (with a space), so the entire block was
  emitted as text and `level_one_access` was never defined.
- `logVisits()` wrote to `""` — `$LOG_FILE` was a file-scope variable read
  inside a function without `global`. Visit logging is gone.
- `strftime()` was called throughout; it was removed in PHP 8.1.
- `raw_get` was always empty — `parse_url()` was being handed a bare query
  string.
- `ltrim($s, "rawpost_data=")` stripped characters rather than a prefix.
- Three pages loaded `./bitwrench.min.js`, which was in `../assets/`.
- `rawview.php` had a duplicated `</script></body>` tail and its auth include
  commented out.
- Redis failures were caught as `Exception`; a missing extension raises `Error`
  in PHP 7+, so the page fatalled instead.

### Security

- **Path traversal on read.** `sleevestreamapi.php` put `$_GET["device_id"]`
  straight into a file path.
- **Arbitrary file write.** `submitpacket.php` built a filename from
  `meta.device_id` inside the *posted JSON*. Everything reaching the filesystem
  now goes through `t_channel()`, which strips all but `[A-Za-z0-9_-]`.
- **Credentials committed to git.** `admin/triops` and `demo/device` were in the
  repository, authenticated with an unsalted `md5(user%pass)` cookie that never
  expired. Both gone; accounts are created on first run.
- **Payload files were web-readable.** `pages/data/lastpacket.json` could be
  fetched by anyone who guessed the path. Data files are now named `.php` so the
  `exit` on their first line is executed rather than served — the extension is
  what protects them, since a `.json` file is static content and its guard never
  runs. `dev/smoke.sh` asserts this, with a `.json` control demonstrating the
  difference.
- Request bodies are capped by `max_payload_bytes`; 0.1 read them unbounded.

### Removed

- redis, and the `bootstrap.min.css` / `bootstrap.min.js` pair.
- `device.php` and `regdevice.php`, both of which rendered "Protected Page".
- `sleevestreamapi.php`, `rawsend.php`, `submitpacket.php`, `rawview.php`,
  `rawview-graph.php`, `testrawsend.php` — replaced by `api/` and `view.php`.
- All remaining "Kinisi" branding.
- `dev/undo.html`, `dev/questions.html`, `dev/clock.html`, `dev/sqlitever.php`.
- `access/socket.php` moved to `dev/`: it needs `ext-sockets`, cannot run on
  shared hosting, and shares no code with the web application.

## [0.1] — 2020

Initial release.
