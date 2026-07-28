# bitwrench notes

Friction hit while building triops against bitwrench, written down as it
happened. triops is a useful canary because it is a real app rather than a
component demo, it is small enough that any repro is short, and it exercises a
distribution path the library's own tests probably do not: vendored UMD, no npm,
no build step, no CDN, served by PHP.

Vendored version: **2.1.3** (`app/assets/bitwrench.umd.min.js`).
`status.php` reports it, so include that when filing upstream.

**Tally: no defects found.** Both things that initially looked like library
problems were triops failing to read the documentation. Filed one DX issue —
[bitwrench#92](https://github.com/deftio/bitwrench/issues/92) — about the
*failure mode* rather than the docs: unrecognised option keys are dropped
silently, so a wrong call either does nothing or throws from minified code.

---

## What we got wrong

### Assuming `makeButton` takes an `href`

triops shipped a landing page where every hero button was dead:

```js
bw.makeButton({ text: 'Try the demo', href: './demo/' })
```

`makeButton` takes `{text, variant, size, disabled, onclick, action, type,
className, style}` and renders a `<button>`. It never claimed to accept `href` —
we invented that parameter, and JavaScript ignores unknown object keys, as it
does everywhere.

What made it nasty is that it reviews clean: correct styling, valid markup, no
console error. It simply does not navigate, and nothing tells you.

Fix was an anchor carrying the button classes, which is more correct anyway
since these navigate between pages and should support middle-click and
open-in-new-tab. `bw.link` is not the substitute — it calls `preventDefault`
for SPA routing.

### Assuming `makeTableFromArray` takes records

Called it with an array of objects and got `TypeError: t[0].map is not a
function` out of the minified bundle.

The docs are explicit and carry a worked example:

```js
bw.makeTableFromArray({ data: [['Name','Age','Role'], ['Alice',30,'Engineer']] })
```

`makeTableFromArray` is documented as taking **2D arrays — CSV and spreadsheet
data** — with `headerRow` controlling whether row one is the header. The
cheatsheet lists its key prop as `data (2D array)`. `makeTable` and
`makeDataTable` are the ones that take records.

The name reads ambiguously in isolation, but "from array" is doing real work
here: it distinguishes this from `makeTable`, which takes objects. Documented,
exampled, and listed in two places. Nothing to file.

### Filed upstream

[bitwrench#92](https://github.com/deftio/bitwrench/issues/92) — warn on
unrecognised option keys in dev builds. Both mistakes below would have surfaced
immediately. Also mentions shipping an unminified UMD build, since debugging a
vendored no-build-step consumer means reading `t[0].map` with no source map.

### The actual pattern

Both failures were the same one, and bitwrench's own `llms.txt` warns about it
directly:

> Check BCCL first — if there is a built-in component for what you need, use it.

We had that text loaded and still guessed at signatures instead of opening
`docs/component-library.md`. bitwrench has deep docs; the cost of reading them
is about ninety seconds and would have prevented both.

Second lesson, from the button: **verify links by clicking them in a browser,
not by reading the code.** `dev/record-demo.sh` already has Playwright
installed, and pointing it at the page found the dead button in seconds.

---

## Observations, not complaints

### No arbitrary-JSON renderer, and reasonably so

Verified against the full component list: there is no `makeJSONTree`,
`makeTree`, `makeObjectView` or equivalent. `bw.inspect()` is a DOM inspector,
which is a plausible thing to reach for by name and not what you want.

Not a gap so much as a boundary — a component library cannot know your shape.
triops needed it because displaying *unknown* payloads is the entire product, so
`triops.jsonTaco()` in `app/assets/triops.js` is ~30 recursive lines.

If enough consumers end up writing that same function it might earn a place in
BCCL, but one data point is not evidence.

### Server-rendered forms through TACO work fine

Worth stating because it was not obvious before trying it: a TACO with
`a: {method: 'post', action: '...'}` renders a real `<form>` with real inputs
that POST normally. So a PHP app can build forms declaratively and still handle
them server-side, with no fetch layer.

Attribute values get HTML-escaped — `action: './users.php'` emits
`action=".&#x2F;users.php"` — which browsers decode correctly, so it works, but
it looks alarming the first time you view source.

### The all-or-nothing posture is real, and correct

llms.txt says that reaching for `querySelector` or `innerHTML` means fighting
the library. That is accurate. triops uses TACO for everything dynamic and keeps
exactly two pages (login, setup) as plain server-rendered HTML — deliberately, so
a script error cannot lock you out of a debug tool. That split has been stable
and has not caused friction.

### Palette generation removed real work

`loadStyles({primary, secondary})` plus `toggleThemeMode()` replaced what was
going to be a hand-written stylesheet with dark mode support. triops has zero CSS
files as a result. This is the single biggest reason vendoring bitwrench made
triops *smaller* rather than larger.

### 170 KB vendored

Irrelevant for a tool you open on a laptop on a LAN, and worth naming only
because it is the objection people will raise. Gzipped it is ~45 KB, and it
replaced bootstrap's two files.
