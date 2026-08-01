# Contributing to triops

Thanks for looking. triops is a small tool with a deliberate ceiling, so the most
useful thing to know before you start is what it is trying to be — and what it is
trying not to become.

## The one-paragraph version

triops is the receiving end of a device's HTTP connection during firmware
bring-up. It shows you the exact bytes a board sent, the headers that came with
them, and when they arrived, and it runs anywhere there is PHP and a writable
directory — shared hosting, a Raspberry Pi, a NAS. **It is not a telemetry
backend and is not trying to become one.** When you outgrow it, you are supposed
to outgrow it.

## Reporting a bug

The best bug reports for this project are boring and specific. Include:

- **PHP version** and how it is served — Apache, nginx + FPM, `php -S`, a NAS
  package, shared hosting.
- **What `status.php` says.** It reports the active storage driver, whether the
  data directory is writable, versions, and per-channel counts. If sqlite was
  not selected it also says why. Most "nothing appears in the inbox" reports are
  answered on that page. Redact the paths if you like.
- **What the device sent**, as exactly as you can. `echo.php` plays a whole
  request back at you, which is usually easier than describing it.
- What you expected instead.

If a payload arrived but looks wrong, say whether the raw view looks wrong too.
Raw is what triops actually stored; the parsed view is a rendering of it.

### Security issues

**Do not open a public issue.** Email **deftio@deftio.com**.

[`SECURITY.md`](./SECURITY.md) has the scope — what counts as a vulnerability in
a tool that is explicitly built for a bench rather than a public IP, and what is
documented behaviour.

## Before you write code

**Read [`dev/todo.md`](./dev/todo.md) first.** It has a *Deliberately NOT doing*
section, and it is not a wishlist that never got done — those things were
considered and cut on purpose:

roles and permissions, login throttling, password reset, device registration, a
redis driver, docker-compose, an OpenAPI spec, a router or front controller,
composer, and any runtime dependency at all.

A PR adding one of those will be declined however good the code is, and I would
rather you found that out here than after an evening's work. If you think one of
them has become necessary, **open an issue and argue for it** — the reasoning
is written down precisely so it can be argued with.

Things that are always welcome:

- bug fixes, especially ones with a smoke-test case
- a device or host triops does not work on, with enough detail to reproduce
- documentation that was wrong, unclear, or out of date
- worked examples for a board or language not in `docs/examples/`

## The rules that will get a PR rejected

Full conventions are in [`AGENTS.md`](./AGENTS.md) — that is the file to read
before changing anything, and it applies to humans as much as to coding agents.
The short version:

1. **No runtime dependency may be added to `app/`.** Not composer, not a
   package, not a service. This is the whole point of the project.
2. **PHP 8.0 syntax only.** CI runs 8.0, 8.2 and 8.4.
3. **No hand-written CSS, no `innerHTML`, no HTML strings in JavaScript.** The UI
   is built from bitwrench TACO objects.
4. **Plain-text endpoint output is frozen.** People script against
   `timestamp.php` and `sum.php`.
5. **Anything user-supplied that reaches the filesystem goes through
   `t_channel()`**, and anything stored goes through `t_redact()`.

## Running the checks

No composer, no PHPUnit, no build step. Everything is bash, curl, and `php -l`:

```sh
find app dev -name '*.php' -exec php -l {} \;   # lint — matters most
php dev/check-taco-tags.php                     # renderer matches the schema
node dev/check-taco-sanitizer.mjs               # sanitiser refuses hostile TACO
./dev/smoke.sh                                  # endpoint walk
STORE=ndjson ./dev/smoke.sh                     # again on the fallback driver
```

Run both smoke passes. The two storage drivers fail in different ways, and a
change that only works under sqlite silently breaks every host without the
extension — which is a lot of shared hosting.

To try it:

```sh
php -S 127.0.0.1:8777 -t app
```

## Opening a PR

- Branch from `master`.
- **Do not bump `TRIOPS_VERSION`.** On this repo the version bump *is* the
  release trigger — CI reads it, tags, and publishes. Version bumps and
  changelog sections are done at release time, not in feature PRs.
- Keep file moves in their own commit, separate from content changes. The diff
  is unreviewable otherwise, and this repo gets read as a worked bitwrench
  example.
- If you changed `app/assets/triops.js`, copy it to `pages/assets/`. CI diffs
  them.
- If you changed how `view.php` renders an entry, update
  `pages/demo/index.html` to match and re-record the GIF with
  `./dev/record-demo.sh`. Nothing checks the demo for you.
- Say what you tested on. "Works on my Apache box with PHP 8.2" is genuinely
  useful information for a tool whose main claim is that it runs anywhere.

Small PRs get reviewed quickly. Large ones that redesign something are better
started as an issue.

## License

BSD 2-clause. By contributing you agree your work is licensed the same way.
