# Installing triops

triops is PHP files in a directory. There is nothing to build, nothing to
compile, and no package manager involved.

**Requirements:** PHP 8.0 or newer, and a directory the web server can write to.
That is the whole list. SQLite is used if it happens to be there and a plain
text file if it is not.

## The short version

1. Download [the latest release](https://github.com/deftio/triops/releases/latest/download/triops.zip)
2. Unzip it into a web-served directory
3. Open it in a browser and create your account
4. Point a device at `api/ingest.php`

```sh
cd /var/www/html
curl -LO https://github.com/deftio/triops/releases/latest/download/triops.zip
unzip triops.zip          # creates triops/
chmod u+w triops/data
```

Then open `http://your-host/triops/`.

## From a git clone

The deployable unit is the `app/` directory — nothing else in the repo is needed
at runtime.

```sh
git clone https://github.com/deftio/triops.git
cp -r triops/app /var/www/html/triops
chmod u+w /var/www/html/triops/data
```

## Apache

Works as shipped. `app/.htaccess` sets the 404 page and denies config, and
`app/data/.htaccess` and `app/lib/.htaccess` deny those directories.

Confirm `AllowOverride` is not `None` for your document root, or none of those
files do anything:

```apache
<Directory /var/www/html>
    AllowOverride All
</Directory>
```

## nginx

**nginx ignores `.htaccess` entirely.** Nothing in those files applies, so you
have to write the equivalent yourself:

```nginx
location /triops/ {
    try_files $uri $uri/ =404;

    location ~ ^/triops/(data|lib)/ {
        deny all;
        return 403;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

triops does not rely on those blocks alone. Every file in `lib/` refuses to run
unless the bootstrap defined `TRIOPS`, and every data file is named `.php` with
an `exit` on line 1 so PHP terminates before printing anything. The `deny` rules
are the belt; those are the braces.

The exception is the SQLite database, which cannot guard itself — it has to be a
real database file. Its filename carries a random suffix generated on first run
so it is not guessable, but if you are on nginx, get the location block right.

## Shared hosting

Upload `app/` via SFTP or the file manager, rename it to something sensible, and
make `data/` writable (0755 is usually enough; some hosts need 0775).

If your host runs an old PHP, look for a version selector in the control panel —
cPanel, Plesk and DirectAdmin all have one. triops needs 8.0 or newer.

If `data/` cannot be made writable, triops will tell you so on the status page
rather than failing mysteriously.

## Moving the data directory out of the web root

Better on any host that allows it, because then no amount of misconfiguration
can expose your payloads:

```php
<?php
// app/config.php
return [
    'data_dir' => '/var/lib/triops',
];
```

```sh
sudo mkdir -p /var/lib/triops
sudo chown www-data:www-data /var/lib/triops
```

## Configuration

Every setting has a working default, so `config.php` is optional. Copy the
sample when you want to change something:

```sh
cp app/config.sample.php app/config.php
```

`config.sample.php` documents every option inline. The ones people actually
change:

| Setting | Why |
|---|---|
| `data_dir` | Move storage out of the web root |
| `store` | Force `ndjson` so you can `tail -f` payloads as they land |
| `ingest_key` | Require a shared secret on `api/ingest.php` |
| `max_entries_per_channel` | Keep more or less history |
| `max_payload_bytes` | Accept larger payloads |
| `theme` | Two seed colors; bitwrench derives the rest |

`config.php` is gitignored. Do not commit it.

## Verifying

Open `status.php`. It reports the active store driver, whether the data
directory is writable, the PHP and bitwrench versions, and the entry count per
channel. If something is wrong, it is almost always on that page.

From a shell:

```sh
curl http://your-host/triops/api/version.php
curl -X POST 'http://your-host/triops/api/ingest.php?channel=test' -d 'hello'
```

## Upgrading

Replace the `app/` directory but keep your `config.php` and `data/`. There is no
migration step — 0.2 is the first release with this layout.

## Uninstalling

Delete the directory.
