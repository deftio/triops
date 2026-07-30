# Changelog

Format follows [Keep a Changelog](https://keepachangelog.com/). This project
uses [semantic versioning](https://semver.org/).

## [0.2.1] — 2026-07-28

Correctness and trust boundaries. Nothing here changes the install, the API
envelope or the API integer — it changes what triops does with bytes and
credentials it did not choose. Upgrade in place: existing data is migrated on
first run.

### Fixed

- **Binary request bodies no longer vanish.** JSON cannot carry arbitrary bytes,
  and triops was putting raw bodies straight into it. Under the ndjson driver
  `json_encode` returned `false`, `false . "\n"` wrote a blank line, and the
  packet was silently discarded — while the device was told `"stored": true`.
  Under sqlite the row was written but every subsequent read of that channel came
  back with an empty body, so **one malformed packet made the channel unreadable
  until it was cleared.** Bodies that are not valid UTF-8 are now base64'd and
  labelled with a new `body_encoding` field (`utf-8` or `base64`), and the inbox
  shows them as a hex dump with an ASCII gutter. Entries written by 0.2.0 have no
  such field and are read as `utf-8`.
- **`t_json()` can no longer emit an empty 200.** An unencodable response used to
  send a JSON content type with a zero-length body; it now returns a `500` with
  code `encode_failed` saying what could not be encoded.
- **Credentials are no longer written down.** `?key=...` was stored in the record,
  shown in the inbox and returned by `api/read.php`, so a key pasted once to test
  a board outlived the test by however long the channel did. Query parameters and
  request headers matching the new `redact_keys` config have their value replaced
  with `[redacted]` — the name is kept, because "a key was sent and it was wrong"
  is what you need to see when a device cannot authenticate. Covers `key`,
  `token`, `password`, `secret`, `authorization`, `cookie`, `x-triops-key` and
  more, matched ignoring case, dashes and underscores.
- **Device-posted TACO is checked against an allowlist, not filtered.** The
  sanitiser stripped `on*` handlers and `javascript:` URLs and passed everything
  else through — which stops none of the tags that never needed a handler:
  `iframe`, `object`, `embed`, a `script` with a `src`, `srcdoc`, `formaction`.
  It now enforces the element and attribute sets published in
  `docs/taco-wire.schema.json`, restricts URLs to relative/`http`/`https`/
  `mailto`, and applies depth, node-count and text-length limits. A document that
  cannot be rendered safely is shown as a raw payload instead of partially
  rendered. `dev/check-taco-tags.php` diffs the renderer against the schema and
  CI fails if they drift. Still off by default.
- **ndjson compaction is no longer racy.** Appends were locked; compaction read
  the file and renamed a replacement over it with no lock at all, so a packet
  landing in between went into an inode that was about to stop being the data
  file. Append and compaction now share a per-channel lock file — separate from
  the data file, because a lock on a file that is about to be renamed protects
  nothing, and a writer queued on the old inode would append into a file that is
  no longer the file.
- **sqlite is verified before it is chosen.** The fallback caught failures from a
  constructor that only stored a path and could not fail, so it fired for a
  missing extension and nothing else; a read-only data directory or a corrupt
  database surfaced later as a 500. The connection is now opened and the schema
  created during driver selection, and `status.php` reports which driver won and
  why the other did not.
- **The sqlite filename marker is guarded.** 0.2.0 wrote it to a bare `.dbname`,
  served as plain text by anything that ignores `.htaccess` — handing out the one
  filename the random suffix exists to hide. It is now `dbname.php` behind an exit
  guard, and an existing `.dbname` is migrated and removed on first run.
- **Recovery instructions named the wrong file.** The lockout note on the login
  page, and `llms.txt`, said `data/users.json`; the file is `data/users.php`.
  Wrong in the one place it is read when you are already locked out.
- **CI was testing sqlite twice.** `smoke.sh` writes `app/config.php` to redirect
  the data directory, clobbering the `store => ndjson` the ndjson job wrote into
  the same file. The driver is passed as `STORE` now.
- **The smoke test left server processes behind.** Killing the `php -S` parent
  leaves its forked workers alive and still bound to the port, where they serve
  the next run from a config file that has since been rewritten. Runs now stop
  the whole process group.

### Added

- **Request headers are stored and shown.** `Content-Length`, `Content-Encoding`,
  `Transfer-Encoding`, `User-Agent` and any custom device headers, in a collapsed
  block per entry, with the same redaction as query parameters. A good half of
  embedded HTTP failures are header failures — a wrong `Content-Length`, a
  chunked encoding the server will not take — and until now `echo.php` could see
  them but the inbox could not.
- `redact_keys` config setting.
- `store.fallback_reason` in `api/status.php` and on the status page.
- Concurrency coverage in `dev/smoke.sh`: forty simultaneous posts, and a second
  burst against a deliberately tightened ring so compaction runs mid-flight. The
  smoke server now runs with `PHP_CLI_SERVER_WORKERS`, without which the built-in
  server handles one request at a time and nothing runs concurrently at all.
  This guards against gross failure under load — dropped packets, an ignored
  ring, a lost newest write. It does not reliably reproduce the compaction race
  itself, which needs a write to land in a very narrow window; that fix rests on
  the reasoning rather than on this test.
- Smoke coverage asserting that every generated data file is unreadable over
  HTTP — the real ndjson file, the lock file, `users.php`, the database name
  marker — rather than only a synthetic canary.
- Three worked deployment profiles in `docs/install.md`: laptop during bring-up,
  Raspberry Pi or LAN box, shared hosting or NAS.

### Changed

- Dependency wording is now specific: no framework, no Composer packages, no
  database server, no build system, no third-party service. "No dependencies"
  was never quite the claim, and the precise version is the more interesting one.
- Docs lead with `X-Triops-Key` rather than `?key=`. Both still work; a URL ends
  up in access logs, proxy logs and shell history, and a header does not.

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
- An expanded raw payload view was closed by the 2-second auto-refresh, which
  rebuilt every card underneath you — exactly when you are most likely to have
  auto-refresh switched on.

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
