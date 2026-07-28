# Python and MicroPython

## CPython

```python
import requests

TRIOPS = "http://192.168.1.40/triops"

r = requests.post(
    f"{TRIOPS}/api/ingest.php",
    params={"channel": "lab"},
    json={"device": "rpi-01", "temp_c": 22.4},
    timeout=5,
)
print(r.status_code, r.json())
# 200 {'ok': True, 'api': 1, 'data': {'channel': 'lab', 'bytes': 38, 'stored': True}}
```

### A small logger

```python
import time, requests

TRIOPS  = "http://192.168.1.40/triops"
CHANNEL = "sensors"

def send(payload):
    try:
        r = requests.post(f"{TRIOPS}/api/ingest.php",
                          params={"channel": CHANNEL},
                          json=payload, timeout=5)
        return r.json().get("ok", False)
    except requests.RequestException as e:
        print("send failed:", e)
        return False

while True:
    send({"ts": time.time(), "temp_c": read_sensor()})
    time.sleep(5)
```

### Reading back

Reads need a session, so log in first:

```python
s = requests.Session()
s.post(f"{TRIOPS}/login.php", data={"username": "me", "password": "secret123"})

r = s.get(f"{TRIOPS}/api/read.php", params={"channel": "lab", "n": 10})
for e in r.json()["data"]["entries"]:
    print(time.strftime("%H:%M:%S", time.localtime(e["ts"])), e["bytes"], e["body"][:60])
```

### Checking a client's behaviour

The primitives make a decent test harness for whatever HTTP library you are
evaluating:

```python
# where does it truncate?
for n in (256, 512, 1024, 4096, 65536):
    got = len(requests.get(f"{TRIOPS}/bytes.php", params={"n": n}).content)
    print(f"asked {n:6d}  got {got:6d}  {'ok' if got == n else 'TRUNCATED'}")

# does the timeout fire?
try:
    requests.get(f"{TRIOPS}/delay.php", params={"ms": 3000}, timeout=1)
except requests.Timeout:
    print("timeout fired correctly")

# what comes back on a 503?
print(requests.get(f"{TRIOPS}/code.php", params={"c": 503}).status_code)
```

## MicroPython

`urequests` is much thinner than `requests` — no connection pooling, no retries,
and you must close responses or you will leak sockets.

```python
import urequests, ujson, time

TRIOPS = "http://192.168.1.40/triops"

def send(payload):
    r = None
    try:
        r = urequests.post(
            TRIOPS + "/api/ingest.php?channel=lab",
            data=ujson.dumps(payload),
            headers={"Content-Type": "application/json"},
        )
        return r.status_code == 200
    except Exception as e:
        print("send failed:", e)
        return False
    finally:
        if r:
            r.close()      # urequests leaks sockets otherwise

while True:
    send({"temp_c": 22.4, "uptime_s": time.ticks_ms() // 1000})
    time.sleep(5)
```

### Connectivity check

Plain text, so no JSON parsing on a device that may not have the memory for it:

```python
for path in ("/timestamp.php", "/sum.php?a=1&b=2", "/ip.php"):
    r = urequests.get(TRIOPS + path)
    print(path, "->", r.text)
    r.close()
```

If `timestamp.php` answers and your POST does not, the network is fine and the
request is wrong. Point it at `/echo.php` and read back what actually arrived.

### Notes

- **Set Content-Type explicitly.** `urequests` does not infer one from `data=`.
- **Use `data=ujson.dumps(...)`, not `json=`.** Some builds lack the `json`
  keyword entirely.
- **Close every response.** The socket is not garbage collected promptly and you
  will run out.
- **Watch your payload size.** `max_payload_bytes` defaults to 64 KB, which is
  far more than a constrained device should be sending anyway.
