/**
 * Record the README demo.
 *
 * Drives a real triops instance in a real browser and records the viewport,
 * so the GIF can never show a UI that does not exist. Payloads are posted from
 * here on a timer while the page's auto-refresh picks them up, which is the
 * thing worth showing: data arriving live.
 *
 *   ./dev/record-demo.sh
 *
 * Playwright records webm; the shell wrapper converts it to a GIF with ffmpeg.
 */
import { chromium } from 'playwright';
import { request as httpRequest } from 'node:http';
import { mkdtempSync, rmSync, mkdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const BASE = process.env.BASE || 'http://127.0.0.1:8790';
const OUT = process.env.OUT || new URL('../dist/demo', import.meta.url).pathname;

// 2:1 is close to how GitHub renders a README image, and keeps the GIF small.
const VIEWPORT = { width: 1000, height: 620 };

const USER = 'demo';
const PASS = 'demodemo123';

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/**
 * Payloads shaped like things a board actually sends.
 *
 * Order matters: these arrive newest-first on screen, so the last one posted is
 * the one at the top when the raw toggle is clicked. That should be the richest
 * payload — a nested object is what makes the tree view worth showing.
 */
const SEED = [
  { body: '23.1', type: 'text/plain' },
  { body: 'sensor=temp&value=22.4&id=esp32-01', type: 'application/x-www-form-urlencoded' },
];

/**
 * A binary frame: sync bytes, a device id, a reading, a checksum. Not valid
 * UTF-8, which is the point — JSON cannot carry these bytes, so triops stores
 * them base64 and the viewer hex-dumps them. Worth showing because it is the
 * case a generic request bin gets wrong.
 */
const BINARY = new Uint8Array([
  0xAA, 0x55, 0x01, 0x10, 0x65, 0x73, 0x70, 0x33, 0x32, 0x2D,
  0x30, 0x31, 0x00, 0x0E, 0x01, 0x60, 0xFF, 0xD2, 0x3C, 0x91,
]);

const LIVE = [
  // Truncated mid-object. A prettified view would hide exactly this.
  { body: '{"device":"esp32-01","temp_c":22.9,"uptime_s":1058,', type: 'application/json' },
  { body: '{"device":"esp32-01","temp_c":22.6,"humidity":41,"uptime_s":1048,"wifi":{"rssi":-61,"ssid":"lab"}}',
    type: 'application/json' },
];

/**
 * Posted with node's http module rather than fetch.
 *
 * fetch adds accept, accept-language and sec-fetch-* on its own, and since the
 * inbox now shows headers those would be in the GIF — making a device post look
 * like a browser request. http.request sends only what it is given, which is
 * what a board with a 2 KB HTTP client actually puts on the wire.
 */
function post(sample) {
  const url = new URL(`${BASE}/api/ingest.php?channel=lab`);
  const body = typeof sample.body === 'string' ? Buffer.from(sample.body) : Buffer.from(sample.body);

  return new Promise((resolve, reject) => {
    const req = httpRequest({
      hostname: url.hostname,
      port: url.port,
      path: url.pathname + url.search,
      method: 'POST',
      headers: {
        'Host': url.host,
        'User-Agent': 'ESP32HTTPClient',
        'Content-Type': sample.type || 'application/json',
        'Content-Length': body.length,
        'X-Device-Id': 'esp32-01',
        'Connection': 'close',
      },
    }, (res) => { res.resume(); res.on('end', resolve); });
    req.on('error', reject);
    req.end(body);
  });
}

async function main() {
  mkdirSync(OUT, { recursive: true });
  const profile = mkdtempSync(join(tmpdir(), 'triops-rec-'));

  const browser = await chromium.launch();

  // --- setup, in a context with no recording attached.
  //
  // Playwright starts the video the moment a context is created, so signing up
  // has to happen in a throwaway context or it lands in the GIF. The session
  // carries over via storageState.
  const setup = await browser.newContext({ viewport: VIEWPORT });
  const setupPage = await setup.newPage();

  await setupPage.goto(`${BASE}/setup.php`);
  if (setupPage.url().includes('setup.php')) {
    await setupPage.fill('input[name=username]', USER);
    await setupPage.fill('input[name=password]', PASS);
    await setupPage.fill('input[name=password2]', PASS);
    await setupPage.click('button[type=submit]');
  } else {
    await setupPage.goto(`${BASE}/login.php`);
    await setupPage.fill('input[name=username]', USER);
    await setupPage.fill('input[name=password]', PASS);
    await setupPage.click('button[type=submit]');
  }
  await setupPage.waitForLoadState('networkidle');

  const storageState = await setup.storageState();
  await setup.close();

  // A couple already on screen, so the GIF opens on something rather than an
  // empty page.
  for (const sample of SEED) await post(sample);

  // --- the take
  const context = await browser.newContext({
    viewport: VIEWPORT,
    deviceScaleFactor: 2,
    storageState,
    recordVideo: { dir: OUT, size: VIEWPORT },
  });
  const page = await context.newPage();

  await page.goto(`${BASE}/view.php?channel=lab`);
  await page.waitForSelector('.bw_bccl_card', { timeout: 10000 });
  await sleep(1200);

  // Turn on auto-refresh so arriving payloads appear without interaction.
  const auto = page.locator('#auto');
  if (await auto.count()) {
    await auto.check();
    await sleep(600);
  }

  // Payloads land live, picked up by the page's own auto-refresh. Truncated
  // first, then the rich one, so the tree view is the top card for the beats
  // that follow.
  for (const sample of LIVE) {
    await post(sample);
    await sleep(2300);
  }

  // Half of embedded HTTP bugs are header bugs. The inbox records them, and the
  // panel is collapsed until you want it.
  const headers = page.locator('details summary').first();
  if (await headers.count()) {
    await headers.scrollIntoViewIfNeeded();
    await sleep(600);
    await headers.click();
    await sleep(2400);
    await headers.click();
    await sleep(400);
  }

  // The beat that distinguishes triops from a generic request bin: the raw
  // bytes are always one click away.
  const rawBtn = page.getByRole('button', { name: /show raw/i }).first();
  if (await rawBtn.count()) {
    await rawBtn.scrollIntoViewIfNeeded();
    await sleep(700);
    await rawBtn.click();
    await sleep(2000);
  }

  // Close on the binary frame. It arrives last so it lands at the top of the
  // list and is fully visible: bytes JSON cannot carry, hex-dumped rather than
  // mangled into text or quietly dropped. This is the frame that says what
  // triops is for.
  await post({ body: BINARY, type: 'application/octet-stream' });
  await sleep(2400);
  await page.evaluate(() => window.scrollTo({ top: 0 }));
  await sleep(2600);
  await context.close();
  await browser.close();
  rmSync(profile, { recursive: true, force: true });

  console.log(`recorded to ${OUT}`);
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
