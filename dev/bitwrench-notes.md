# bitwrench notes

Friction hit while building triops against bitwrench, written down as it
happened. triops is a useful canary because it is a real app rather than a
component demo, it is small enough that any repro is short, and it exercises a
distribution path the library's own tests probably do not: vendored UMD, no npm,
no build step, no CDN, served by PHP.

Vendored version: **2.1.3** (`app/assets/bitwrench.umd.min.js`).
`status.php` reports it, so include that when filing upstream.

---

## Worth filing

### `makeTableFromArray` takes rows, not records

The name reads as "make a table from an array [of objects]", which is the common
shape when you have just parsed JSON. It actually wants an array of arrays.

```js
bw.makeTableFromArray({ data: [{ts: '1', ch: 'a'}] })
// TypeError: t[0].map is not a function

bw.makeTableFromArray({ data: [['1', 'a'], ['2', 'b']] })   // works
bw.makeDataTable({ data: [{ts: '1', ch: 'a'}] })            // records go here
```

Cost me a few minutes and a stack trace that pointed into the minified bundle.
Either a clearer error when `data[0]` is not an array, or a note in the
docstring, would have saved it. `makeDataTable` is the right function and is easy
to miss when `makeTableFromArray` sounds like the general case.

### No arbitrary-JSON renderer

Not a bug — a component library cannot know your shape — but it is the one thing
triops needed that BCCL does not cover, so every consumer displaying unknown JSON
will write it. `triops.jsonTaco()` in `app/assets/triops.js` is ~30 lines and
recursive. Might be worth having as `bw.makeJSONTree()` or similar.

`bw.inspect()` is a DOM inspector, which is a reasonable thing to reach for by
name and not what you want here.

---

## Observations, not complaints

### Server-rendered forms through TACO work fine

Worth stating because it was not obvious before trying it: a TACO with
`a: {method: 'post', action: '...'}` renders a real `<form>` with real inputs
that POST normally. So a PHP app can build forms declaratively and still handle
them server-side, with no fetch layer.

Attribute values get HTML-escaped — `action: './users.php'` emits
`action=".&#x2F;users.php"` — which browsers decode correctly, so it works, but
it looks alarming the first time you view source.

### The all-or-nothing posture is real, and correct

llms.txt says that reaching for `querySelector` or `innerHTML` means fighting the
library. That is accurate. triops uses TACO for everything dynamic and keeps
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
