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
  '23.1',
  'sensor=temp&value=22.4&id=esp32-01',
];

const LIVE = [
  '{"device":"pico-w-02","readings":[1.2,3.4,5.6],"battery_v":3.71}',
  '{"device":"esp32-01","temp_c":22.6,"humidity":41,"uptime_s":1048,"wifi":{"rssi":-61,"ssid":"lab"}}',
];

async function post(body) {
  await fetch(`${BASE}/api/ingest.php?channel=lab`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body,
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
  for (const body of SEED) await post(body);

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

  // Payloads land live, picked up by the page's own auto-refresh.
  for (const body of LIVE) {
    await post(body);
    await sleep(2200);
  }

  // The beat that distinguishes triops from a generic request bin: the raw
  // bytes are always one click away.
  const rawBtn = page.getByRole('button', { name: /show raw/i }).first();
  if (await rawBtn.count()) {
    await rawBtn.scrollIntoViewIfNeeded();
    await sleep(700);
    await rawBtn.click();
    await sleep(2200);
  }

  await sleep(800);
  await context.close();
  await browser.close();
  rmSync(profile, { recursive: true, force: true });

  console.log(`recorded to ${OUT}`);
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
