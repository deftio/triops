<?php
/**
 * The inbox: everything received, newest first.
 *
 * Polls api/read.php rather than rendering server-side, so the auto-refresh
 * does not reload the page under you while you are reading a payload.
 */
require __DIR__ . '/lib/triops.php';

t_require_auth();

$channel = t_channel($_GET['channel'] ?? null);

t_page_open('Received');
?>
<div id="controls"></div>
<div id="entries">Loading…</div>
<?php
t_page_close(true, [
    'channel'    => $channel,
    'channels'   => t_store()->channels(),
    'csrf'       => t_csrf_token(),
    'allowTaco'  => (bool) t_config('allow_taco_render', false),
]);
?>
<script>
(function () {
  var channel = TRIOPS_DATA.channel;
  var timer = null;

  // Which payloads the user has toggled to raw, keyed by arrival timestamp.
  // Survives the auto-refresh rebuilding the list underneath them.
  var expanded = new Set();

  function controls() {
    bw.DOM('#controls', {
      t: 'div',
      a: { style: 'display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;margin-bottom:1rem' },
      c: [
        { t: 'span', c: 'channel:' },
        {
          t: 'select',
          a: {
            class: 'bw_bccl_form_control',
            style: 'width:auto',
            onchange: function (e) { window.location.href = './view.php?channel=' + encodeURIComponent(e.target.value); }
          },
          c: (TRIOPS_DATA.channels.length ? TRIOPS_DATA.channels : [{ channel: channel, n: 0 }]).map(function (c) {
            return {
              t: 'option',
              a: c.channel === channel ? { value: c.channel, selected: 'selected' } : { value: c.channel },
              c: c.channel + ' (' + c.n + ')'
            };
          })
        },
        bw.makeButton({ text: 'Refresh now', size: 'sm', onclick: load }),
        {
          t: 'label',
          a: { style: 'display:flex;gap:0.3rem;align-items:center' },
          c: [
            { t: 'input', a: { type: 'checkbox', id: 'auto', onchange: toggleAuto } },
            { t: 'span', c: 'auto (2s)' }
          ]
        },
        bw.makeButton({
          text: 'Clear channel',
          size: 'sm',
          variant: 'danger',
          onclick: clearChannel
        })
      ]
    });
  }

  function toggleAuto(e) {
    if (timer) { clearInterval(timer); timer = null; }
    if (e.target.checked) timer = setInterval(load, 2000);
  }

  function clearChannel() {
    if (!window.confirm('Delete every entry in "' + channel + '"?')) return;
    fetch('./api/clear.php?channel=' + encodeURIComponent(channel), { method: 'POST' })
      .then(function () { load(); });
  }

  function load() {
    fetch('./api/read.php?channel=' + encodeURIComponent(channel) + '&n=100')
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.ok) { bw.DOM('#entries', bw.makeAlert ? bw.makeAlert({ text: res.error }) : { t: 'p', c: res.error }); return; }
        render(res.data.entries);
      })
      .catch(function (err) { bw.DOM('#entries', { t: 'p', c: 'Fetch failed: ' + err.message }); });
  }

  function render(entries) {
    if (!entries.length) {
      bw.DOM('#entries', bw.makeCard({
        title: 'Nothing yet',
        content: 'Post something to api/ingest.php?channel=' + channel + ' and it will appear here.'
      }));
      return;
    }

    bw.DOM('#entries', {
      t: 'div',
      c: entries.map(function (e) {
        var meta = [
          triops.fmtTime(e.ts) + ' (' + triops.fmtAgo(e.ts) + ')',
          e.method,
          e.ip,
          e.bytes + ' bytes',
          e.ctype || 'no content-type'
        ].join('  ·  ');

        var body = [{ t: 'div', a: { style: 'font-size:0.8rem;opacity:0.7;margin-bottom:0.4rem' }, c: meta }];

        if (e.query && Object.keys(e.query).length) {
          body.push({ t: 'div', a: { style: 'margin-bottom:0.4rem' }, c: [
            { t: 'strong', c: 'query: ' }, triops.jsonTaco(e.query)
          ]});
        }

        // Opt-in: let a device drive the display by posting a TACO.
        if (TRIOPS_DATA.allowTaco) {
          try {
            var maybe = JSON.parse(e.body);
            if (maybe && maybe.t) {
              body.push(bw.makeCard({
                title: 'device-rendered',
                content: triops.sanitizeTaco(maybe)
              }));
              return bw.makeCard({ content: { t: 'div', c: body } });
            }
          } catch (err) { /* not a TACO, fall through */ }
        }

        body.push(triops.payloadTaco(e.body, String(e.ts), expanded));
        return bw.makeCard({ content: { t: 'div', c: body } });
      })
    });
  }

  controls();
  load();
})();
</script>
<?php t_page_end(); ?>
