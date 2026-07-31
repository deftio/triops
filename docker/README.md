# Running triops in Docker

**This is a local runner, not a way to deploy triops.**

triops is a folder of PHP files that runs on any shared host with no Docker, no
build step and no services. That is the point of the project, and this directory
does not change it. It exists for one situation:

> There is a board on your desk and no PHP on your machine.

Which is now the default on macOS — Apple stopped shipping PHP — and always was
on Windows. If you already have PHP, you do not want this. Run
`php -S 0.0.0.0:8080 -t app` instead and skip the 93 MB.

## Use it

Two commands, from the repository root:

```sh
docker build -t triops -f docker/Dockerfile .
docker run --rm -p 8080:8080 -v triops-data:/triops/data triops
```

Then open <http://localhost:8080/> and create an account when it asks.

To point real hardware at it, use your machine's LAN address rather than
localhost — the board is not on your loopback:

```
http://192.168.1.40:8080/api/ingest.php?channel=lab
```

`ipconfig getifaddr en0` on macOS, `hostname -I` on Linux.

## Throwing it away

The data directory is a Docker volume, and accounts live in `data/users.php`
alongside the payloads. So one command resets everything — stored packets and
the login you created:

```sh
docker volume rm triops-data
```

Next run starts at the setup page again. That is the intended workflow: try
something, wipe it, try something else.

Leave the `-v` off entirely and there is nothing to clean up at all — `--rm`
plus no volume means the container's data disappears when you stop it.

## What you get

The image is `php:8.2-cli-alpine` plus `app/`. Nothing else, and no extensions
are installed:

- **SQLite is already in the base image**, so triops uses its sqlite driver
  rather than the ndjson fallback. You get an exact ring buffer instead of one
  that holds between N and 4N entries.
- **`PHP_CLI_SERVER_WORKERS=4`** is set. Without it PHP's built-in server
  handles one request at a time, and a device posting while the viewer polls
  would queue behind it. Four is plenty for a bench.

Everything else behaves exactly as a normal install: the same endpoints, the
same API, the same `status.php`. There is no Docker-specific configuration and
no compose file.

## Why this is not a deployment

`php -S` is a development server. PHP's own documentation says not to serve
production traffic from it, and that has not stopped being true because it is in
a container. It has no process supervision, no TLS, and no request queueing
beyond the four workers above.

To actually run triops somewhere it needs to stay up, copy `app/` onto a real
web server — Apache, nginx with FPM, a Raspberry Pi, or the shared hosting you
already pay for. [`docs/install.md`](../docs/install.md) covers all three, plus
moving the data directory out of the web root, which matters more than anything
in this file.

There is deliberately **no published image**. A registry tag is a thing that
goes stale — someone pulls `latest` a year from now and gets a version with
bugs that were fixed twice over. Eight lines you build yourself cannot rot.

## Configuration

The container reads the same `app/config.php` as any other install, which is not
copied into the image (see `.dockerignore`). If you want to change settings, the
simplest route is to mount one:

```sh
docker run --rm -p 8080:8080 \
  -v triops-data:/triops/data \
  -v "$PWD/my-config.php:/triops/config.php:ro" \
  triops
```

Start from [`app/config.sample.php`](../app/config.sample.php) — every value in
it is the default, so you only need the lines you are changing.

## Watching the data

With a named volume, the ndjson files and the SQLite database live inside
Docker. To tail a channel as packets arrive:

```sh
docker exec -it <container> sh -c 'tail -f /triops/data/ch-*.ndjson.php'
```

That only applies if triops picked the ndjson driver, which it will not here
because the base image has SQLite. If you specifically want tail-able files, set
`'store' => 'ndjson'` in a mounted config.

If you would rather have the data on your host — to inspect it, or to keep it
under version control while testing — swap the volume for a bind mount:

```sh
mkdir -p ./triops-data
docker run --rm -p 8080:8080 -v "$PWD/triops-data:/triops/data" triops
```

On Linux the container writes as root, so those files end up root-owned and
`rm -rf` will want `sudo`. Add `--user "$(id -u):$(id -g)"` to avoid that. The
named volume in the main example exists precisely to sidestep this.
