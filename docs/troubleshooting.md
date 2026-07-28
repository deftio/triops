# Troubleshooting

Start with `status.php`. It reports the active store driver, whether the data
directory is writable, and the versions in play. Most problems are visible there
before you read any further.

## My device posts but nothing appears

Work down the stack. Each step rules out everything below it.

**1. Is anything reaching the server at all?**

```sh
curl -X POST 'http://your-host/triops/api/ingest.php?channel=test' -d 'hello'
```

If that appears on the View page, triops is fine and the problem is on the
device. If it does not, the problem is triops or the web server.

**2. Is the device reaching the right URL?**

Point it at `echo.php` instead of `api/ingest.php`. That endpoint replays your
whole request without storing anything. If the device gets a response, the
network path works and you can read exactly what it sent. If it times out, this
is a network or DNS problem and has nothing to do with triops.

**3. Are you looking at the right channel?**

`api/ingest.php` without a `?channel=` writes to `default`. The View page has a
channel selector; check it.

**4. Is the data directory writable?**

`status.php` says so directly. If not, ingest returns a `store_error` and the
response body says which path failed.

## It works with curl but not from the board

This is the most common report, and it is nearly always one of three things.

**Content-Type.** Many embedded HTTP clients send nothing, or send
`application/x-www-form-urlencoded` regardless of what you passed. triops stores
the raw bytes either way, so check `echo.php` to see what your client actually
set. If your payload looks right in `echo.php` but your own backend rejects it
later, this is why.

**Chunked transfer encoding.** Some clients stream the body without a
`Content-Length`. PHP handles it, but a misconfigured proxy in between may not.
`headers.php` shows you what arrived.

**Truncation.** Small receive buffers cut the body silently. Walk `bytes.php?n=`
up until your client breaks:

```
bytes.php?n=512     ok
bytes.php?n=1024    ok
bytes.php?n=2048    truncated  <- your real limit is between 1024 and 2048
```

The View page shows the byte count of every entry. If it is consistently short
and round, you have found a buffer.

## Everything returns 500

Turn on error display temporarily and look at what PHP says:

```sh
php -S 127.0.0.1:8000 -t app
```

Then load the page over that instead of your web server. The development server
prints errors to the terminal.

The usual causes are a `data/` directory that does not exist and cannot be
created, or a PHP older than 8.0.

## "database is locked"

You should never see this — triops opens SQLite in WAL mode specifically so that
the auto-refreshing viewer can poll while devices are posting. If it appears
anyway, your filesystem probably does not support WAL. Network filesystems
frequently do not.

Force the other driver:

```php
<?php
// app/config.php
return ['store' => 'ndjson'];
```

## I am locked out

Delete the users file and reload the page. triops will ask you to create a new
account.

```sh
rm app/data/users.php
```

Note the `.php` extension — that file is named so the exit guard on its first
line is executed rather than served. It is still plain text on disk.

## Payloads disappear after a while

By design. Each channel is a ring buffer holding `max_entries_per_channel`
entries, 512 by default. Raise it in config if you need more history, but triops
is a bench tool, not a datastore — if you need retention, forward the payloads
somewhere that does retention.

Note the two drivers differ here: sqlite trims to exactly the limit on every
write, while ndjson lets the file grow to 4× before compacting, so it holds
somewhere between N and 4N. That is deliberate; rewriting an append-only file on
every request would defeat the point of it being append-only.

## The UI is unstyled

bitwrench did not load. Check that `app/assets/bitwrench.umd.min.js` exists and
is being served — the browser console will say if it 404ed.

Login and setup are plain HTML on purpose and stay usable without it, so you can
always get in and reach `status.php`.

## Nothing renders at all, blank page

Almost always a PHP fatal before any output. Check your web server's error log,
or run it under `php -S` as above to see the message.

## Devices post fine, but the View page is empty

If `api/read.php?channel=X` returns entries and the page shows none, that is a
JavaScript problem, not a storage problem. Open the browser console. Then please
[file it](https://github.com/deftio/triops/issues) with what the console said.
