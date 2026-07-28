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
  function payloadTaco(body, key, expanded) {
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
    baseStyles: baseStyles,
    jsonTaco: jsonTaco,
    payloadTaco: payloadTaco,
    sanitizeTaco: sanitizeTaco,
    fmtTime: fmtTime,
    fmtAgo: fmtAgo
  };
})();
