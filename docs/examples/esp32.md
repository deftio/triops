# ESP32 / Arduino

Tested against the ESP32 Arduino core. The ESP8266 equivalents are the same with
`ESP8266WiFi.h` and `ESP8266HTTPClient.h`.

## Minimal post

```cpp
#include <WiFi.h>
#include <HTTPClient.h>

const char* SSID   = "your-network";
const char* PASS   = "your-password";
const char* TRIOPS = "http://192.168.1.40/triops";

void setup() {
  Serial.begin(115200);
  WiFi.begin(SSID, PASS);
  while (WiFi.status() != WL_CONNECTED) { delay(500); Serial.print("."); }
  Serial.println(WiFi.localIP());
}

void loop() {
  HTTPClient http;
  http.begin(String(TRIOPS) + "/api/ingest.php?channel=lab");
  http.addHeader("Content-Type", "application/json");

  String body = "{\"temp_c\":" + String(readTemp(), 1) +
                ",\"uptime_s\":" + String(millis() / 1000) + "}";

  int code = http.POST(body);
  Serial.printf("POST -> %d %s\n", code, http.getString().c_str());
  http.end();

  delay(5000);
}
```

Open the **View** page and the payloads appear as you go.

## Checking connectivity before you post anything

When the post is not working, back off to the primitives. They return plain
text, so you can print them straight to the serial console with no parsing.

```cpp
void checkTriops() {
  HTTPClient http;

  // 1. Can we reach it at all, and is the clock sane?
  http.begin(String(TRIOPS) + "/timestamp.php");
  Serial.printf("time: %d %s\n", http.GET(), http.getString().c_str());
  http.end();

  // 2. Does our query string survive the trip?
  http.begin(String(TRIOPS) + "/sum.php?a=1&b=2");
  Serial.printf("sum:  %s (expect 3)\n", http.getString().c_str());
  http.end();

  // 3. What does the server think our address is?
  http.begin(String(TRIOPS) + "/ip.php");
  Serial.printf("ip:   %s\n", http.getString().c_str());
  http.end();
}
```

If `timestamp.php` works and your POST does not, the network is fine and the
problem is in the request you are building. Point it at `echo.php` instead of
`api/ingest.php` and read back exactly what you sent:

```cpp
http.begin(String(TRIOPS) + "/echo.php");
http.addHeader("Content-Type", "application/json");
http.POST("{\"probe\":1}");
Serial.println(http.getString());   // headers, query and body as received
```

## Finding your receive buffer limit

`HTTPClient::getString()` will quietly truncate on a constrained device. Walk it
up until it does:

```cpp
void findBufferLimit() {
  int sizes[] = {256, 512, 1024, 2048, 4096, 8192};
  for (int i = 0; i < 6; i++) {
    HTTPClient http;
    http.begin(String(TRIOPS) + "/bytes.php?n=" + String(sizes[i]));
    http.GET();
    int got = http.getString().length();
    Serial.printf("asked %5d, got %5d %s\n",
                  sizes[i], got, got == sizes[i] ? "ok" : "TRUNCATED");
    http.end();
  }
}
```

## Testing timeout and error handling

```cpp
// Does your timeout actually fire?
http.setTimeout(1000);
http.begin(String(TRIOPS) + "/delay.php?ms=5000");
int code = http.GET();          // expect -11 (read timeout)

// What does your retry logic do with a 503?
http.begin(String(TRIOPS) + "/code.php?c=503");
code = http.GET();              // expect 503
```

Most firmware handles the happy path and falls over on these. Better to find out
against triops than in the field.

## With an ingest key

```cpp
http.begin(String(TRIOPS) + "/api/ingest.php?channel=lab");
http.addHeader("Content-Type", "application/json");
http.addHeader("X-Triops-Key", "YOUR_KEY");
http.POST(body);
```

## Notes

- **Send a Content-Type.** The Arduino core sends nothing by default. triops
  stores the payload regardless, but you will see `no content-type` in the
  viewer, and whatever you build next probably will not be as forgiving.
- **Keep `channel` short and alphanumeric.** Anything outside `[A-Za-z0-9_-]` is
  stripped, so `esp32/01` and `esp3201` are the same channel.
- **Do not post faster than you can read.** The default ring buffer is 512
  entries per channel; at 2 Hz that is four minutes of history.
