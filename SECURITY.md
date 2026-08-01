# Security policy

## Reporting a vulnerability

**Do not open a public issue.** Email **deftio@deftio.com** with enough detail to
reproduce it.

This is a one-person project, not a team with a rota — expect an
acknowledgement within a few days rather than a few hours, and please give it a
reasonable window before disclosing publicly. If a fix is warranted it ships as
a normal release, credited in `CHANGELOG.md` unless you would rather not be.

## Supported versions

The latest release only. There are no backports: triops is a folder of PHP files
you replace wholesale, and upgrading is unzipping over the top while keeping your
`config.php` and `data/`.

| Version | Supported |
|---|---|
| latest `0.2.x` | yes |
| anything older | no — upgrade first, then report if it persists |

## What triops is, before you decide something is a bug

triops is a **bench tool**, built for a lab network, a machine behind a VPN, or a
laptop on the same subnet as a dev board. The README says this plainly and so
does the install guide. It has no rate limiting, no audit log, no TLS, and no
hardening against a hostile internet, and none of that is an oversight.

So: **"I put it on a public IP and it was attacked" is documented behaviour, not
a vulnerability.** What follows is the line I actually draw.

### In scope — please report these

- Anything that escapes `data_dir`, or that turns a posted value into an
  arbitrary filesystem path. Everything user-supplied that reaches the
  filesystem goes through `t_channel()`; a way around it is a real bug.
- Anything that lets an **unauthenticated** request read a stored payload, a
  password hash, or the contents of the data directory.
- Anything that executes attacker-supplied input — including a payload that
  gets past `sanitizeTaco()` and executes in a viewer's browser when
  `allow_taco_render` is enabled. That is the one place a device's bytes become
  UI rather than escaped text, and it is the place I most want to hear about.
- A credential that survives into storage despite matching `redact_keys`, or a
  common credential name that should be in that list and is not.
- A data file that is served as text rather than executed — the `.php` naming
  and the `<?php exit; ?>` guard on line 1 are what protect those, and a way
  around either matters.
- Session or CSRF handling that lets one user act as another.

### Out of scope

- Exposure to a hostile network, per the above.
- Password brute-forcing. There is deliberately no login throttling; see
  *Deliberately NOT doing* in [`dev/todo.md`](./dev/todo.md).
- The SQLite database being readable when the web server is misconfigured. It is
  binary and cannot carry an exit guard, which is why its filename is randomised
  and why [`docs/install.md`](./docs/install.md) tells you to write the nginx
  location block or move `data_dir` outside the web root.
- Anything requiring an already-authenticated session. There are no roles —
  logged-in versus not is the only distinction, by design, and every logged-in
  user can see everything.
- Missing security headers, TLS, or cookie flags on a plain-HTTP bench tool you
  are expected to run behind something that terminates TLS if it is exposed.

## Hardening a deployment

[`docs/install.md`](./docs/install.md) covers the two that matter most: getting
the nginx `location` block right (nginx ignores `.htaccess` entirely, so nothing
in those files applies), and moving `data_dir` outside the web root, which is
better on any host that allows it because then no amount of misconfiguration can
expose your payloads.

Set `ingest_key` if the network is not yours alone, and send it as the
`X-Triops-Key` header rather than `?key=` — a URL ends up in access logs, proxy
logs and shell history. Either way the value is redacted before the request is
stored.
