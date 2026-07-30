# API reference

Everything under `api/` returns JSON with a consistent envelope. Everything
outside `api/` returns plain text — see [endpoints.md](./endpoints.md).

## Envelope

Success:

```json
{ "ok": true, "api": 1, "data": { } }
```

Failure:

```json
{ "ok": false, "api": 1, "error": "human readable", "code": "machine_readable" }
```

The HTTP status code is always meaningful. A failure never arrives as a 200 with
an error inside, because a constrained client that checks only the status would
read that as success.

## Versioning

`api` is an integer, separate from the triops version. It changes only when
something breaks compatibility, so a client can feature-detect without parsing
a semver string:

```c
if (api >= 1) { /* ingest.php exists and takes ?channel= */ }
```

A future incompatible API would live at `api/v2/` — a directory, since triops
routes by file path.

## Authentication

Session cookie, established by logging in through the web UI.

`api/version.php` is the exception and is deliberately public: it is how a client
confirms it is talking to triops at all.

Unauthenticated requests to the others return `401` with code `unauthorized`, or
`503` with `setup_required` when no account has been created yet. They never
redirect — an API client handed a 302 to a login form cannot do anything useful
with it.

Device ingest is separate. If `ingest_key` is set in config, `api/ingest.php`
requires it as an `X-Triops-Key` header, compared with `hash_equals`. Devices do
not have accounts.

`?key=` is still accepted for clients that cannot set a header, but prefer the
header: a query string ends up in access logs, proxy logs and shell history,
none of which triops controls. Neither form is written into the stored record.

---

## GET `api/version.php`

Public. Name, version, and API integer.

```sh
curl http://host/triops/api/version.php
```

```json
{ "ok": true, "api": 1,
  "data": { "name": "triops", "version": "0.2.1", "api": 1 } }
```

---

## POST `api/ingest.php`

The canonical device endpoint. Accepts any body — JSON, form encoding, CSV, a
bare number. The bytes are stored as received and parsed only when displayed, so
a malformed payload is still visible rather than silently dropped.

GET is accepted too, since some minimal clients cannot POST.

| Parameter | Default | Meaning |
|---|---|---|
| `channel` | `default` | Where to file it. Stripped to `[A-Za-z0-9_-]` |
| `key` | — | Required only if `ingest_key` is configured, and only if you cannot send `X-Triops-Key` |

```sh
curl -X POST 'http://host/triops/api/ingest.php?channel=lab' \
     -H 'Content-Type: application/json' \
     -d '{"temp_c":22.4}'
```

```json
{ "ok": true, "api": 1,
  "data": { "channel": "lab", "bytes": 15, "stored": true } }
```

Errors: `413 payload_too_large` past `max_payload_bytes`, `401 unauthorized` on
a bad key, `500 store_error` if the write fails.

### Posting UI instead of data

With `allow_taco_render` enabled in config, a device can post a
[bitwrench TACO](https://github.com/deftio/bitwrench) — `{t, a, c}` — and triops
renders it as live UI instead of showing it as a payload. A microcontroller
drawing its own status panel:

```json
{"t":"div","c":[
  {"t":"h3","c":"pump-03"},
  {"t":"ul","c":[
    {"t":"li","c":"pressure: 2.4 bar"},
    {"t":"li","c":"runtime: 41h"}
  ]}
]}
```

Off by default, because rendering markup from an untrusted source is XSS with
extra steps.

What triops accepts is specified as JSON Schema in
[`taco-wire.schema.json`](./taco-wire.schema.json) — a deliberately smaller
subset than a full TACO. Structure only, never behaviour: no `o` block (state,
lifecycle, handles), no `on*` attributes, no `script`/`iframe`/`style`/`form`
tags, and no `javascript:` or `data:` URLs.

That subset is not arbitrary. Everything excluded is executable code, which is
also the part that cannot be serialised — so **the schema-able subset and the
trust-boundary subset are the same subset**. UI as data travels; UI as behaviour
does not.

Being a schema rather than prose, it is machine-checkable at the boundary and
can be handed to a constrained decoder, so a model generating a panel is
structurally incapable of emitting a handler. This is the practical difference
between UI-as-data and UI-as-syntax: you cannot write this schema for JSX,
because JSX is a program.

**Enforcement note:** the runtime guard is `triops.sanitizeTaco()` in
`app/assets/triops.js`. It is an allowlist — an element not named in the schema
is dropped, an attribute not permitted by the schema is dropped, and a URL
scheme outside `http`, `https`, `mailto` or relative is dropped. A document that
cannot be represented safely is not rendered at all; you see the raw payload
instead, which is the honest outcome.

triops does not run a JSON Schema validator at runtime — that would mean a
dependency, and `app/` has none. The schema is the specification and the
sanitiser is the enforcement, so `dev/check-taco-tags.php` diffs the element
allowlist in the two files and CI fails if they disagree. A renderer laxer than
its own published schema is worse than shipping no schema.

Depth, node-count and text-length limits are also enforced, because a device
that can post can post a tree deep enough to blow the stack.

---

## GET `api/read.php`

Last N entries, newest first.

| Parameter | Default | Meaning |
|---|---|---|
| `channel` | `default` | Which channel |
| `n` | `50` | How many, clamped to 500 |

```sh
curl -b cookies.txt 'http://host/triops/api/read.php?channel=lab&n=2'
```

```json
{ "ok": true, "api": 1, "data": {
  "channel": "lab", "count": 1,
  "entries": [{
    "ts": 1785199996.169,
    "ip": "192.168.1.55",
    "method": "POST",
    "ctype": "application/json",
    "bytes": 15,
    "query": { "channel": "lab" },
    "headers": {
      "Host": "host",
      "Content-Type": "application/json",
      "Content-Length": "15",
      "User-Agent": "ESP32HTTPClient",
      "X-Triops-Key": "[redacted]"
    },
    "body": "{\"temp_c\":22.4}",
    "body_encoding": "utf-8"
  }]
}}
```

`ts` is a Unix timestamp with fractional seconds. `bytes` is always the length of
what the device actually sent, before any encoding applied below.

### `body` and `body_encoding`

`body_encoding` is `utf-8` or `base64`, and you have to look at it before using
`body`.

JSON cannot carry arbitrary bytes. A device sending CBOR, protobuf, a compressed
payload, a length-prefixed frame, or simply a buffer with a bug in it produces
something no JSON encoder will accept — so triops base64s those bodies and says
so. Valid UTF-8 is stored and returned as-is.

```
body_encoding == "utf-8"    body is the text, unchanged
body_encoding == "base64"   base64-decode body to get the bytes
```

Entries stored before 0.2.1 have no `body_encoding`; treat a missing field as
`utf-8`.

### `query` and `headers`

Both are recorded as the server saw them, with one exception: anything whose name
matches `redact_keys` in config has its **value** replaced with `[redacted]`. The
name is kept, because "a key was sent and it was the wrong one" is exactly what
you need to see when a device cannot authenticate.

By default that covers `key`, `token`, `password`, `secret`, `authorization`,
`cookie`, `x-triops-key` and similar. Matching ignores case, dashes and
underscores, so one entry covers `api_key`, `API-KEY` and `apikey`.

This is why the docs use the `X-Triops-Key` header rather than `?key=` — not
because the header is more secret in transit, but because a URL is the thing that
ends up in logs, proxies and shell history. Neither form is stored.

Entries stored before 0.2.1 have no `headers`; treat a missing field as `{}`.

---

## GET `api/status.php`

What is actually running. This is the first thing to check when something is
wrong, and the same data `status.php` renders.

```json
{ "ok": true, "api": 1, "data": {
  "triops_version": "0.2.1",
  "api_version": 1,
  "php_version": "8.4.6",
  "bitwrench": "2.1.3",
  "store": {
    "driver": "sqlite", "healthy": true, "error": null,
    "sqlite_present": true, "max_entries": 512, "max_payload": 65536
  },
  "data_dir": { "path": "/var/www/triops/data", "exists": true, "writable": true },
  "auth": { "users_configured": 1, "ingest_key_set": false },
  "taco_render": false,
  "channels": [{ "channel": "lab", "n": 12, "last_ts": 1785199996.169 }],
  "server_time": "2026-07-27 14:31:07"
}}
```

---

## POST `api/clear.php`

Empties a channel. POST only — a GET that deletes data gets fired by every link
prefetcher in existence.

```sh
curl -b cookies.txt -X POST 'http://host/triops/api/clear.php?channel=lab'
```

```json
{ "ok": true, "api": 1, "data": { "channel": "lab", "removed": 12 } }
```

Returns `405 method_not_allowed` on GET.

---

## Error codes

| Code | Status | Meaning |
|---|---|---|
| `unauthorized` | 401 | Not logged in, or bad ingest key |
| `setup_required` | 503 | No account exists yet |
| `method_not_allowed` | 405 | Wrong HTTP verb |
| `payload_too_large` | 413 | Body exceeded `max_payload_bytes` |
| `store_error` | 500 | The storage layer failed; `error` says why |

## Adding your own

See [hacking.md](./hacking.md). Copy `app/templates/api.php`, and answer through
`t_ok()` / `t_err()` so the envelope stays uniform.
