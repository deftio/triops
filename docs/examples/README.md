# Examples

Working snippets for talking to triops. Replace `192.168.1.40/triops` with wherever
you installed it.

- [ESP32 / Arduino](./esp32.md)
- [Python and MicroPython](./python.md)

## curl

Everything below assumes `TRIOPS=http://192.168.1.40/triops`.

**Is it alive?**

```sh
curl $TRIOPS/api/version.php
```

**Post a payload:**

```sh
curl -X POST "$TRIOPS/api/ingest.php?channel=lab" \
     -H 'Content-Type: application/json' \
     -d '{"device":"esp32-01","temp_c":22.4}'
```

**Post a file:**

```sh
curl -X POST "$TRIOPS/api/ingest.php?channel=lab" --data-binary @reading.json
```

**Post from a pipe, every 5 seconds:**

```sh
while true; do
  echo "{\"uptime\":$(cut -d' ' -f1 /proc/uptime)}" \
    | curl -s -X POST "$TRIOPS/api/ingest.php?channel=host" --data-binary @-
  sleep 5
done
```

**Read it back** (needs a session cookie, so log in first):

```sh
curl -c jar -X POST "$TRIOPS/login.php" -d 'username=me&password=secret123'
curl -b jar "$TRIOPS/api/read.php?channel=lab&n=5"
```

**With an ingest key** configured:

```sh
curl -X POST "$TRIOPS/api/ingest.php?channel=lab&key=YOUR_KEY" -d 'hello'
# or
curl -X POST "$TRIOPS/api/ingest.php?channel=lab" -H 'X-Triops-Key: YOUR_KEY' -d 'hello'
```

## Finding your client's limits

The primitives are plain text, so a `curl` loop is enough to characterise any
HTTP client:

```sh
# where does the receive buffer give out?
for n in 256 512 1024 2048 4096 8192; do
  got=$(curl -s "$TRIOPS/bytes.php?n=$n" | wc -c)
  echo "asked $n, got $got"
done

# does the client honour its own timeout?
curl --max-time 1 "$TRIOPS/delay.php?ms=3000"

# what does it do with a 503?
curl -i "$TRIOPS/code.php?c=503"
```

Run the same three against your device's HTTP stack and you will know more about
it than the datasheet says.
