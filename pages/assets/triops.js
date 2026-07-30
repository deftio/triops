/**
 * triops UI helpers, built on bitwrench.
 *
 * Everything here returns TACO objects ({t, a, c, o}) — plain JavaScript
 * objects that bitwrench renders. Nothing here writes HTML strings or touches
 * innerHTML. If you are adding a page, see docs/hacking.md.
 */
var triops = (function () {
  'use strict';

  var cfg = {};

  /**
   * One content frame for the whole product.
   *
   * bitwrench's navbar wraps its own contents in .bw_container, so overriding
   * that single class is what makes the nav and the body line up instead of the
   * nav running full-bleed over narrower content. Everything — app pages, the
   * landing page, the demo — uses .bw_container and therefore the same edges.
   *
   * 8% each side, which holds across every ordinary desktop width; the cap only
   * engages past ~1830px so an ultrawide does not get one enormous column.
   * Readability is handled separately by .triops-prose rather than by squeezing
   * the whole frame, so cards and tables get the room and text does not.
   */
  function baseStyles(theme) {
    bw.loadStyles(theme || { primary: '#006666', secondary: '#cc6633' });
    bw.injectCSS(bw.css({
      '.bw_container': {
        width: '84%',
        'max-width': '96rem',
        'margin-left': 'auto',
        'margin-right': 'auto',
        'box-sizing': 'border-box'
      },
      // The navbar carries its own horizontal padding, so its inner
      // .bw_container was sizing against a narrower parent and landing ~20px
      // inside the body edge. Zero it and both compute 84% of the same width.
      '.bw_bccl_navbar': { 'padding-left': '0', 'padding-right': '0' },

      // Prose wants a shorter measure than the frame does.
      '.triops-prose': { 'max-width': '42rem' },
      '@media (max-width: 640px)': {
        '.bw_container': { width: '92%' }
      }
    }));
  }

  /** Page chrome. Called once per page by t_page_close(). */
  function boot(config) {
    cfg = config || {};
    baseStyles(cfg.theme);

    if (!cfg.chrome) return;

    var items = [
      { text: 'Home', href: './index.php' },
      { text: 'View', href: './view.php' },
      { text: 'Send', href: './send.php' },
      { text: 'Status', href: './status.php' }
    ];
    if (cfg.user) {
      items.push({ text: 'Users', href: './users.php' });
      items.push({ text: 'Log out (' + cfg.user + ')', href: './logout.php' });
    }

    bw.DOM('#triops-nav', {
      t: 'div',
      c: [
        bw.makeNavbar({ brand: cfg.site || 'triops', brandHref: './index.php', items: items }),
        {
          t: 'div',
          a: { class: 'bw_container', style: 'text-align:right;padding:0.4rem 0;font-size:0.85rem;opacity:0.7' },
          c: [
            { t: 'a', a: { href: '#', onclick: toggleTheme }, c: 'light / dark' },
            { t: 'span', c: '  ·  triops ' + (cfg.version || '') }
          ]
        }
      ]
    });
  }

  function toggleTheme(e) {
    if (e && e.preventDefault) e.preventDefault();
    bw.toggleThemeMode();
    return false;
  }

  /**
   * Any JSON value -> a browsable TACO tree.
   *
   * bitwrench ships 47 components but no arbitrary-JSON viewer, because a
   * component library cannot know your shape. This is the ~30 lines that
   * bridges the gap, and it is the heart of what triops does: you do not know
   * what the device will send, so the renderer cannot assume.
   */
  function jsonTaco(value, depth) {
    depth = depth || 0;

    if (value === null) return { t: 'span', a: { style: 'opacity:0.55' }, c: 'null' };

    var type = Object.prototype.toString.call(value);

    if (type === '[object Array]') {
      if (value.length === 0) return { t: 'span', a: { style: 'opacity:0.55' }, c: '[]' };
      return {
        t: 'ol',
        a: { style: 'margin:0 0 0 1.1rem;padding:0' },
        c: value.map(function (v) {
          return { t: 'li', c: jsonTaco(v, depth + 1) };
        })
      };
    }

    if (type === '[object Object]') {
      var keys = Object.keys(value);
      if (keys.length === 0) return { t: 'span', a: { style: 'opacity:0.55' }, c: '{}' };
      return {
        t: 'ul',
        a: { style: 'list-style:none;margin:0 0 0 ' + (depth ? '1.1rem' : '0') + ';padding:0' },
        c: keys.map(function (k) {
          return {
            t: 'li',
            a: { style: 'margin:0.15rem 0' },
            c: [
              { t: 'strong', a: { style: 'font-family:monospace' }, c: k + ': ' },
              jsonTaco(value[k], depth + 1)
            ]
          };
        })
      };
    }

    if (typeof value === 'number' || typeof value === 'boolean') {
      return { t: 'span', a: { style: 'font-family:monospace' }, c: String(value) };
    }

    return { t: 'span', a: { style: 'font-family:monospace' }, c: String(value) };
  }

  /**
   * Render a device payload: parsed tree when it is JSON, raw text when it is
   * not, and always a toggle to the exact bytes received.
   *
   * The raw view is not optional. When a board emits malformed JSON or stray
   * whitespace, a prettified view hides the precise bug you are chasing.
   *
   * `key` and `expanded` keep a toggled-open payload open across re-renders.
   * Without them the 2s auto-refresh rebuilds every card and snaps your raw
   * view shut while you are still reading it — which is precisely when you are
   * most likely to have auto-refresh on.
   */
  function payloadTaco(body, key, expanded, encoding) {
    if (encoding === 'base64') return binaryTaco(body);

    var parsed = null;
    try { parsed = JSON.parse(body); } catch (err) { parsed = null; }

    var rawBlock = {
      t: 'pre',
      a: {
        style: 'white-space:pre-wrap;word-break:break-all;margin:0.5rem 0 0;' +
               'padding:0.6rem;border-radius:4px;font-size:0.85rem;overflow-x:auto',
        class: 'bw_bccl_card'
      },
      c: body === '' ? '(empty body)' : body
    };

    if (parsed === null) return rawBlock;

    var startOpen = !!(expanded && key != null && expanded.has(key));

    return {
      t: 'div',
      o: {
        state: { raw: startOpen },
        render: function (el, state) {
          bw.DOM(el, {
            t: 'div',
            c: [
              bw.makeButton({
                text: state.raw ? 'show parsed' : 'show raw',
                size: 'sm',
                variant: 'secondary',
                onclick: function () {
                  state.raw = !state.raw;
                  // Remember across re-renders so auto-refresh does not close it.
                  if (expanded && key != null) {
                    if (state.raw) { expanded.add(key); } else { expanded.delete(key); }
                  }
                  bw.refresh(el);
                }
              }),
              state.raw ? rawBlock : { t: 'div', a: { style: 'margin-top:0.5rem' }, c: jsonTaco(parsed) }
            ]
          });
        }
      }
    };
  }

  /**
   * The wire-safe element set.
   *
   * Kept as one space-separated string because CI diffs it against the `t` enum
   * in docs/taco-wire.schema.json. A renderer that is laxer than the schema it
   * publishes is worse than shipping no schema at all, and two hand-maintained
   * lists drift the moment nobody is checking.
   */
  var TACO_TAGS = 'a abbr article aside b blockquote br button caption code dd ' +
    'del div dl dt em figcaption figure footer h1 h2 h3 h4 h5 h6 header hr i ' +
    'img ins label li main mark nav ol p pre section small span strong sub sup ' +
    'table tbody td tfoot th thead time tr ul';

  var TACO_TAG_SET = {};
  TACO_TAGS.split(' ').forEach(function (tag) { TACO_TAG_SET[tag] = true; });

  // A device that can post can post anything, including a tree deep enough to
  // blow the stack or wide enough to hang the tab.
  var TACO_MAX_DEPTH = 32;
  var TACO_MAX_NODES = 2000;
  var TACO_MAX_TEXT = 20000;

  /**
   * A device can post a bitwrench TACO and have triops render it as live UI.
   * Off unless allow_taco_render is set.
   *
   * This is an allowlist, and it has to be. Until 0.2.1 it stripped on* handlers
   * and javascript: URLs and passed everything else through, which stops none of
   * the tags that never needed a handler in the first place — iframe, object,
   * embed, a script with a src — nor srcdoc, nor formaction. Anything not named
   * in TACO_TAGS is dropped rather than rendered.
   *
   * Returns null when the document cannot be represented safely. Callers treat
   * that as "not renderable" and show the raw payload, which is the honest
   * outcome: you still get to see exactly what the device sent.
   */
  function sanitizeTaco(node) {
    return sanitizeNode(node, 0, { nodes: TACO_MAX_NODES });
  }

  function sanitizeNode(node, depth, budget) {
    if (depth > TACO_MAX_DEPTH || budget.nodes <= 0) return null;
    if (node === null || typeof node === 'undefined') return null;

    if (typeof node === 'string') {
      return node.length > TACO_MAX_TEXT ? node.slice(0, TACO_MAX_TEXT) + '…' : node;
    }
    if (typeof node === 'number' || typeof node === 'boolean') return String(node);
    if (typeof node !== 'object') return null;

    if (Object.prototype.toString.call(node) === '[object Array]') {
      var list = [];
      for (var i = 0; i < node.length; i++) {
        var child = sanitizeNode(node[i], depth + 1, budget);
        if (child !== null) list.push(child);
      }
      return list;
    }

    var tag = String(node.t || '').toLowerCase();
    if (TACO_TAG_SET[tag] !== true) return null;
    budget.nodes--;

    var out = { t: tag };

    if (node.a && typeof node.a === 'object' &&
        Object.prototype.toString.call(node.a) !== '[object Array]') {
      out.a = {};
      Object.keys(node.a).forEach(function (name) {
        var value = node.a[name];
        // The schema allows scalars only. An object here is either a mistake or
        // an attempt to smuggle something past String().
        if (typeof value !== 'string' && typeof value !== 'number' && typeof value !== 'boolean') return;
        if (!safeAttrName(name)) return;

        var lower = name.toLowerCase();
        if ((lower === 'href' || lower === 'src') && !safeUrl(String(value))) return;

        out.a[name] = value;
      });
    }

    if (typeof node.c !== 'undefined') {
      var content = sanitizeNode(node.c, depth + 1, budget);
      if (content !== null) out.c = content;
    }

    // o carries functions and lifecycle hooks. Never accept it from the wire.
    return out;
  }

  function safeAttrName(name) {
    var lower = String(name).toLowerCase();
    if (lower.indexOf('on') === 0) return false;
    // style is not a script vector in a current browser, but it is a defacement
    // vector: a fixed, full-viewport, high-z-index node covering the page it was
    // supposed to be a card inside. srcdoc and formaction are script vectors.
    if (lower === 'style' || lower === 'srcdoc' || lower === 'formaction') return false;
    return /^[A-Za-z_:][-A-Za-z0-9_:.]*$/.test(String(name));
  }

  /** Allowlist, not a denylist: relative, http, https, mailto. Nothing else. */
  function safeUrl(url) {
    if (/^\s/.test(url)) return false;
    if (/^(https?:\/\/|mailto:)/i.test(url)) return true;
    // No scheme at all is a relative URL. "anything:" we did not name is out,
    // which covers javascript:, data:, vbscript:, blob: and whatever is next.
    return !/^[A-Za-z][A-Za-z0-9+.\-]*:/.test(url);
  }

  /**
   * Bodies that are not valid UTF-8 arrive base64'd, and a hex dump is the only
   * honest way to show them: a CBOR frame, a protobuf, a compressed packet, or a
   * length prefix where you expected a brace. This is the case you opened triops
   * to look at, so it gets offsets and an ASCII gutter rather than an apology.
   */
  function binaryTaco(b64) {
    var bytes;
    try {
      bytes = atob(b64);
    } catch (err) {
      return { t: 'pre', c: '(body could not be decoded)' };
    }

    var limit = Math.min(bytes.length, 1024);
    var rows = [];

    for (var i = 0; i < limit; i += 16) {
      var hex = '';
      var txt = '';
      for (var j = 0; j < 16; j++) {
        if (i + j >= limit) { hex += '   '; continue; }
        var c = bytes.charCodeAt(i + j) & 0xff;
        hex += (c < 16 ? '0' : '') + c.toString(16) + ' ';
        txt += (c >= 32 && c < 127) ? bytes.charAt(i + j) : '.';
      }
      rows.push(('0000' + i.toString(16)).slice(-4) + '  ' + hex + ' |' + txt + '|');
    }

    if (bytes.length > limit) {
      rows.push('… ' + (bytes.length - limit) + ' more bytes');
    }

    return {
      t: 'div',
      c: [
        {
          t: 'div',
          a: { style: 'font-size:0.8rem;opacity:0.7;margin:0.5rem 0 0.2rem' },
          c: 'binary — ' + bytes.length + ' bytes, not valid UTF-8'
        },
        {
          t: 'pre',
          a: {
            style: 'white-space:pre;margin:0;padding:0.6rem;border-radius:4px;' +
                   'font-size:0.8rem;overflow-x:auto',
            class: 'bw_bccl_card'
          },
          c: rows.join('\n')
        }
      ]
    };
  }

  function fmtTime(ts) {
    var d = new Date(ts * 1000);
    return d.toLocaleTimeString() + '.' + String(d.getMilliseconds()).padStart(3, '0');
  }

  function fmtAgo(ts) {
    var s = Math.max(0, Math.floor(Date.now() / 1000 - ts));
    if (s < 60) return s + 's ago';
    if (s < 3600) return Math.floor(s / 60) + 'm ago';
    if (s < 86400) return Math.floor(s / 3600) + 'h ago';
    return Math.floor(s / 86400) + 'd ago';
  }

  return {
    boot: boot,
    baseStyles: baseStyles,
    jsonTaco: jsonTaco,
    payloadTaco: payloadTaco,
    binaryTaco: binaryTaco,
    sanitizeTaco: sanitizeTaco,
    tacoTags: TACO_TAGS,
    fmtTime: fmtTime,
    fmtAgo: fmtAgo
  };
})();
