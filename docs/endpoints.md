# Endpoint reference

Two surfaces, and the difference is deliberate.

**Plain text** — everything at the top level. No envelope, no JSON, no HTML. A
device with a 2 KB HTTP client and a serial console should not have to parse
anything to answer a simple question. **These output formats are a stability
promise**: people script against them, so they do not change without a major
version.

**JSON** — everything under `api/`, documented in [api.md](./api.md).

---

## Primitives

### `timestamp.php`

Server time to the microsecond.

```
$ curl http://host/triops/timestamp.php
2026-07-27 14:31:07:117700
```

Useful for spotting a caching layer that is handing your device a stale
response, and for checking clock skew against a device's own RTC.

### `sum.php`

Adds every numeric query parameter.

```
$ curl 'http://host/triops/sum.php?a=1&b=2.5'
3.5
```

Pointless in itself. Its job is to prove your client built the query string it
thinks it did, and that something in the middle is not caching.
Non-numeric values are ignored.

### `ip.php`

The peer address as the server sees it.

```
$ curl http://host/triops/ip.php
192.168.1.55
```

Behind a proxy or NAT this is the proxy, not your device. `headers.php` shows
the forwarding headers if there are any.

### `echo.php`

Your entire request, played back: method, path, headers, query, and body.

```
$ curl -X POST 'http://host/triops/echo.php?q=1' -H 'X-Board: esp32' -d 'hi'
POST /triops/echo.php?q=1
from: 192.168.1.55

--- headers ---
Content-Length: 2
Content-Type: application/x-www-form-urlencoded
Host: host
X-Board: esp32

--- query (1) ---
q = 1

--- body (2 bytes) ---
hi
```

**The first thing to reach for when a device "is sending data" and the server
disagrees.** It shows the `Content-Type` your HTTP client picked without telling
you, which is the single most common cause of "works with curl, not from the
board".

### `headers.php`

Just the request headers, one per line.

```
$ curl http://host/triops/headers.php
Accept: */*
Host: host
User-Agent: curl/8.7.1
```

Reach for this when `ip.php` is not what you expect, or when a CDN or reverse
proxy sits between the device and triops.

### `delay.php`

Answers after a wait. `?ms=` milliseconds, capped at 30000.

```
$ time curl 'http://host/triops/delay.php?ms=2000'
waited 2000ms
real 0m2.01s
```

Embedded HTTP clients handle timeouts badly and you cannot test that against a
server that always answers instantly.

### `code.php`

Answers with the status code you ask for. `?c=` between 100 and 599; anything
else returns 200.

```
$ curl -i 'http://host/triops/code.php?c=503'
HTTP/1.1 503 Service Unavailable
status 503
```

Most firmware is written against the happy path. This is how you find out what
yours does with a 401, a 429 or a 503 without breaking something real first.

### `bytes.php`

Returns exactly N bytes. `?n=` up to 1048576.

```
$ curl 'http://host/triops/bytes.php?n=16'
0123456789abcdef
```

The payload is a repeating printable pattern, so truncation is obvious on a
serial console. Walk it up until your client breaks and you have found your real
receive buffer limit:

```
bytes.php?n=512     ok
bytes.php?n=1024    ok
bytes.php?n=2048    truncated  <- limit is between 1024 and 2048
```

---

## Inbox

These require a login.

### `view.php`

Everything received, newest first. Channel selector, optional 2-second
auto-refresh, a clear button, and per-entry metadata: arrival time, peer
address, method, content type, byte count.

Each entry also carries the request headers, collapsed behind a toggle —
`Content-Length`, `Content-Encoding`, `Transfer-Encoding`, `User-Agent` and
whatever your firmware set. A good share of embedded HTTP failures are header
failures rather than body failures. Anything that looks like a credential is
redacted before it is stored; see `redact_keys` in `config.sample.php`.

Payloads that parse as JSON are shown as a browsable tree with a toggle to the
exact bytes. **The raw view is not optional** — when a board emits malformed
JSON or stray whitespace, a prettified view hides the precise bug you are
chasing.

A body that is not valid UTF-8 — CBOR, protobuf, a compressed frame, or a
buffer bug — is shown as a hex dump with an ASCII gutter rather than being
mangled into text or dropped.

### `send.php`

Post a payload from a form, as if you were a device. Useful before the hardware
exists, and for proving triops works before you start blaming your firmware.

### `status.php`

Active store driver, data directory writability, PHP and bitwrench versions, and
entry counts per channel. The rendered version of
[`api/status.php`](./api.md#get-apistatusphp).

### `users.php`

Add, remove, and change passwords. No roles — anyone logged in can do all of it.

### `login.php` / `logout.php` / `setup.php`

`setup.php` runs once, on first load, and creates the first account. triops
ships with no credentials at all.

These pages are plain HTML and work with JavaScript disabled, so a script error
can never lock you out.

---

## Adding your own

See [hacking.md](./hacking.md). Add it to `t_manifest()` in `app/lib/triops.php`
and it appears on the home page automatically — the home page renders itself
from that manifest, which is why it cannot drift from what actually ships.
