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

  /** Page chrome. Called once per page by t_page_close(). */
  function boot(config) {
    cfg = config || {};
    bw.loadStyles(cfg.theme || { primary: '#006666', secondary: '#cc6633' });

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
          a: { style: 'text-align:right;padding:0.35rem 1rem;font-size:0.85rem;opacity:0.7' },
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
   */
  function payloadTaco(body) {
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

    return {
      t: 'div',
      o: {
        state: { raw: false },
        render: function (el, state) {
          bw.DOM(el, {
            t: 'div',
            c: [
              bw.makeButton({
                text: state.raw ? 'show parsed' : 'show raw',
                size: 'sm',
                variant: 'secondary',
                onclick: function () { state.raw = !state.raw; bw.refresh(el); }
              }),
              state.raw ? rawBlock : { t: 'div', a: { style: 'margin-top:0.5rem' }, c: jsonTaco(parsed) }
            ]
          });
        }
      }
    };
  }

  /**
   * A device can post a bitwrench TACO and have triops render it as live UI.
   * Off unless allow_taco_render is set, and even then handlers are stripped:
   * rendering attacker-supplied attributes is XSS with extra steps.
   */
  function sanitizeTaco(node) {
    if (node === null || typeof node !== 'object') return node;
    if (Object.prototype.toString.call(node) === '[object Array]') return node.map(sanitizeTaco);

    var out = {};
    if (node.t) out.t = String(node.t);
    if (node.a && typeof node.a === 'object') {
      out.a = {};
      Object.keys(node.a).forEach(function (k) {
        var lower = k.toLowerCase();
        // No event handlers, no javascript: urls, no inline script vectors.
        if (lower.indexOf('on') === 0) return;
        if (lower === 'href' || lower === 'src') {
          if (/^\s*javascript:/i.test(String(node.a[k]))) return;
        }
        out.a[k] = node.a[k];
      });
    }
    if (typeof node.c !== 'undefined') out.c = sanitizeTaco(node.c);
    // o carries functions and lifecycle hooks. Never accept it from the wire.
    return out;
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
    jsonTaco: jsonTaco,
    payloadTaco: payloadTaco,
    sanitizeTaco: sanitizeTaco,
    fmtTime: fmtTime,
    fmtAgo: fmtAgo
  };
})();
