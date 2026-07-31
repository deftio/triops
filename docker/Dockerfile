# A quick-and-dirty local runner. NOT a deployment artifact.
#
# triops is a folder of PHP files that runs on any shared host with no Docker,
# no build step and no services — that is the whole point of it, and this image
# does not change it. This exists for exactly one case: there is a board on your
# desk and no PHP on your machine, which is now the default on macOS and always
# was on Windows.
#
#   docker build -t triops -f docker/Dockerfile .
#   docker run --rm -p 8080:8080 -v triops-data:/triops/data triops
#
# Then point the board at  http://<your-lan-ip>:8080/api/ingest.php
# and open http://localhost:8080/ to watch it arrive.
#
# Throw it all away — payloads and the account you created — with:
#
#   docker volume rm triops-data
#
# Users live in data/users.php, so that one command resets the login and the
# stored packets together. Omit the -v entirely and everything vanishes when the
# container stops.
#
# Why this is not a deployment: `php -S` is a development server and PHP's own
# documentation says not to use it in production. It is single-threaded unless
# told otherwise, which is what PHP_CLI_SERVER_WORKERS below is for — without it
# a device posting and a browser polling queue behind each other. For anything
# that has to stay up, put app/ on a real web server. See docs/install.md.

FROM php:8.2-cli-alpine

# app/ is the entire deployable unit; nothing else in the repo is needed at
# runtime. The build context is the repo root, hence -f docker/Dockerfile .
COPY app/ /triops/

# Declared so a plain `docker run` keeps payloads out of the container's
# writable layer, and so `-v triops-data:/triops/data` has something to attach
# to. The .htaccess and exit-guard index.php shipped in app/data/ are copied in
# when the volume is first created.
VOLUME /triops/data

WORKDIR /triops
EXPOSE 8080

# The lesson from dev/smoke.sh: the built-in server handles one request at a
# time without this, so a device posting while the viewer polls will stall.
ENV PHP_CLI_SERVER_WORKERS=4

CMD ["php", "-S", "0.0.0.0:8080", "-t", "/triops"]
