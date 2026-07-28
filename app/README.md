# triops — deploy

This directory is the whole application. Copy it to a web-served location and it
runs. Nothing else in the repository is needed at runtime.

## Install

```sh
cp -r app /var/www/html/triops
chmod u+w /var/www/html/triops/data
```

Open `http://your-host/triops/` and create your account.

**Requirements:** PHP 8.0 or newer, and a writable `data/` directory. That is
all — SQLite is used if present, a plain text file if not.

## What is here

```
index.php          home; renders itself from the manifest in lib/triops.php
view.php           the inbox
send.php           post a payload from a form
status.php         which store is live, is data/ writable, versions
users.php          add and remove accounts
login/logout/setup plain HTML, work without JavaScript
404.php

timestamp.php sum.php ip.php echo.php
headers.php delay.php code.php bytes.php     the plain-text primitives

api/               JSON: version, status, read, ingest, clear
lib/               bootstrap, store, auth, page shell — not web pages
templates/         copy one of these to add your own page
assets/            bitwrench (vendored, pinned), triops.js, logos
data/              sqlite db / ndjson files / users.php  — must be writable
config.sample.php  every setting, documented, all defaults
```

## Configuration

Optional. Every value already has a working default.

```sh
cp config.sample.php config.php
```

Then edit. `config.php` is gitignored and must never be committed.

## Security

Two directories must not be readable over HTTP: `data/` and `lib/`. The
`.htaccess` files here handle Apache.

**On nginx `.htaccess` does nothing** — you need location blocks, and
[docs/install.md](../docs/install.md) has them.

Independently of the web server: every file in `lib/` refuses to run unless the
bootstrap defined `TRIOPS`, and every file in `data/` is named `.php` with an
`exit` on line 1, so PHP terminates before printing anything. The extension is
the point — a `.json` file is served as static text and its guard never runs.

The SQLite database cannot protect itself that way, so its filename carries a
random suffix generated on first run. Better still, move the whole thing out of
the web root:

```php
<?php
// config.php
return ['data_dir' => '/var/lib/triops'];
```

triops is built for a bench or a lab network. It has no rate limiting and no
hardening against a hostile internet. Do not put it on a public address.

## Locked out?

```sh
rm data/users.php
```

Reload, and triops asks you to create a new account. Payload data is untouched.

## Adding a page

Copy a file out of `templates/`. A new endpoint is about five lines. See
[docs/hacking.md](../docs/hacking.md).
