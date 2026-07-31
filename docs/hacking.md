# Adding your own page

triops is file-per-URL. A file in `app/` is a page at that path — there is no
router, no front controller, and no rewrite rules to configure. Drop a file in,
it exists.

Every page starts with the same line:

```php
<?php require __DIR__ . '/lib/triops.php';
```

That gives you config, the store, auth, and the response helpers. Everything
below is what you do next.

Three templates live in `app/templates/`. Copy the one that matches and edit it.

---

## 1. A plain-text endpoint

The simplest thing triops does, and the most useful on a microcontroller. Copy
`templates/plain.php` to `app/hello.php`:

```php
<?php
require __DIR__ . '/lib/triops.php';

$name = (string) ($_GET['name'] ?? 'world');

t_text("hello {$name}\n");
```

That is the entire file. `hello.php?name=board` returns `hello board`.

`t_text()` sets `Content-Type: text/plain`, disables caching, and exits. Plain
text rather than JSON is a deliberate choice: a device with a 2 KB HTTP client
and a serial console should not have to parse anything to check connectivity.

**Everything at the top level works this way.** Only `api/` speaks JSON.

## 2. A JSON API endpoint

Copy `templates/api.php` to `app/api/summary.php`:

```php
<?php
require __DIR__ . '/../lib/triops.php';

t_api_require_auth();

$channel = t_channel($_GET['channel'] ?? null);
$entries = t_store()->last($channel, 100);

t_ok([
    'channel' => $channel,
    'count'   => count($entries),
    'bytes'   => array_sum(array_column($entries, 'bytes')),
]);
```

Rules that are not optional:

- **Answer through `t_ok()` or `t_err()`.** They set the envelope and the status
  code. Never emit a bare array, and never return 200 with an error inside — a
  device that only checks the status code will read that as success.
- **Use `t_api_require_auth()`, not `t_require_auth()`.** The API version returns
  401 JSON. The HTML version sends a 302 to a login page, which an API client
  cannot do anything sensible with.
- **Put user input through `t_channel()`** before it reaches the filesystem.

`api/` is a plain directory, so `api/v2/` is how you version without touching
any server configuration.

## 3. An HTML page

Copy `templates/page.php` to `app/mine.php`. This is where bitwrench comes in.

```php
<?php
require __DIR__ . '/lib/triops.php';

t_require_auth();

$entries = t_store()->last(t_channel(), 20);

t_page_open('My page');
?>
<p>Last <?= count($entries) ?> entries.</p>
<div id="mine"></div>
<?php
t_page_close(true, ['entries' => $entries]);
?>
<script>
bw.DOM('#mine', bw.makeCard({
  title: 'Payload sizes',
  content: bw.makeDataTable({
    data: TRIOPS_DATA.entries.map(function (e) {
      return { when: triops.fmtAgo(e.ts), from: e.ip, bytes: e.bytes };
    })
  })
}));
</script>
<?php t_page_end(); ?>
```

The shape is always the same:

1. `t_page_open($title)` — emits the head, loads bitwrench, opens the container
2. Your markup — keep it to placeholder elements
3. `t_page_close(true, $data)` — `$data` becomes `window.TRIOPS_DATA`
4. A `<script>` block that fills the placeholders
5. `t_page_end()`

Add your page to `t_manifest()` in `app/lib/triops.php` and it appears on the
home page automatically.

---

## The bitwrench part

The UI is [bitwrench](https://github.com/deftio/bitwrench), vendored in
`app/assets/`. You do not need to learn it to add a plain-text endpoint or an
API route. For HTML pages, this is the ten-minute version.

### UI is objects, not strings

Every element is a plain JavaScript object called a **TACO**:

```js
{ t: 'div', a: { class: 'note' }, c: 'Hello' }
//  ^tag      ^attributes           ^content
```

`c` takes a string, another TACO, or an array of them. Nest them, build them
with `.map()`, branch with a ternary — it is just data.

```js
{ t: 'ul', c: items.map(function (i) { return { t: 'li', c: i.name }; }) }
```

### Rendering

```js
bw.DOM('#target', taco);   // replace the contents of #target
bw.create(taco);           // build a detached element
bw.html(taco);             // render to an HTML string
```

### Use the built-in components

bitwrench ships 47 of them. Check before hand-building anything:

```js
bw.makeCard({ title: 'Status', content: 'All good' })
bw.makeDataTable({ data: rows })           // array of objects, sortable
bw.makeButton({ text: 'Go', onclick: fn })
bw.makeStatCard({ label: 'Packets', value: 42 })
bw.makeBarChart({ data: [{label: 'a', value: 3}] })
bw.makeAlert({ text: 'Careful' })
```

`makeDataTable` takes an array of objects. `makeTableFromArray` takes an array
of *arrays* — the names are easy to mix up.

### Do not write CSS

`bw.loadStyles()` has already run before your script, deriving a full palette
from the two seed colors in `config.php` — including dark mode, which the nav
toggles. There is no stylesheet in triops and there should not be one. If you
need a custom rule:

```js
bw.injectCSS(bw.css({ '.my-thing': { background: palette.surfaceAlt } }));
```

For a one-off, an inline style on the TACO node is fine.

### Events go in attributes

```js
{ t: 'button', a: { onclick: save }, c: 'Save' }   // yes
```

Not `addEventListener` in a lifecycle hook — handlers in attributes survive a
re-render, listeners attached in `mounted` do not.

### State, when you need it

```js
bw.DOM('#counter', {
  t: 'div',
  o: {
    state: { n: 0 },
    render: function (el, state) {
      bw.DOM(el, {
        t: 'div',
        c: [
          { t: 'span', c: 'count: ' + state.n },
          bw.makeButton({ text: '+1', onclick: function () { state.n++; bw.refresh(el); } })
        ]
      });
    }
  }
});
```

There is no virtual DOM and no automatic re-rendering. You change state and call
`bw.refresh(el)`. That is the whole model.

### triops helpers

`app/assets/triops.js` adds the few things a general UI library cannot know
about:

```js
triops.jsonTaco(value)                        // any JSON -> a browsable tree
triops.payloadTaco(body, key, open, enc)      // the above, plus a raw/parsed toggle
triops.binaryTaco(base64)                     // hex dump with an ASCII gutter
triops.sanitizeTaco(node)                     // wire-safe TACO, or null
triops.fmtTime(ts)                            // 14:31:07.117
triops.fmtAgo(ts)                             // "3m ago"
```

`jsonTaco` is the heart of what triops does: you do not know what shape a device
will send, so the renderer cannot assume one.

`payloadTaco` takes the entry's `body_encoding` as its fourth argument and hands
a `base64` body to `binaryTaco`. `key` and `open` keep a raw view open across the
2-second auto-refresh. `sanitizeTaco` returns `null` when a device-supplied tree
cannot be rendered safely — treat that as "show the raw payload instead".

### Learning more

- [bitwrench llms.txt](https://raw.githubusercontent.com/deftio/bitwrench/main/llms.txt) — the whole library, condensed
- [thinking-in-bitwrench.md](https://github.com/deftio/bitwrench/blob/main/docs/thinking-in-bitwrench.md) — the progressive walkthrough
- [component-cheatsheet.md](https://github.com/deftio/bitwrench/blob/main/docs/component-cheatsheet.md) — copy-paste patterns

---

## Storage

Do not touch SQLite or the filesystem from a page. Go through the store:

```php
$store = t_store();

$store->push('lab', t_make_record($body));  // append
$store->last('lab', 50);                    // newest first
$store->clear('lab');
$store->channels();                         // [{channel, n, last_ts}, ...]
```

If you need a new operation, add it to the abstract `TriopsStore` in
`lib/store.php` and implement it in **both** drivers. A feature that only works
under sqlite silently breaks every host without the extension.

## Testing what you added

```sh
php -l app/mine.php          # syntax
./dev/smoke.sh               # everything still works
php -S 127.0.0.1:8777 -t app # look at it
```

## If it took more than five lines of scaffolding

That is a bug in `lib/`, not a reason to write more scaffolding. The whole point
of the bootstrap is that a new endpoint is trivial; if yours was not, something
is missing and should be added there instead.
