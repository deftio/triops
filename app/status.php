<?php
/**
 * Self-diagnosis. Same data as api/status.php, rendered.
 *
 * When someone says "it does not work", this page is the first question and
 * usually the answer: wrong driver, unwritable data directory, missing
 * extension.
 */
require __DIR__ . '/lib/triops.php';

t_require_auth();

t_page_open('Status');
?>
<div id="stats"></div>
<div id="detail"></div>
<?php
t_page_close(true, ['status' => t_status_report()]);
?>
<script>
(function () {
  var s = TRIOPS_DATA.status;

  var total = s.channels.reduce(function (acc, c) { return acc + Number(c.n || 0); }, 0);

  bw.DOM('#stats', {
    t: 'div',
    a: { style: 'display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:1.5rem' },
    c: [
      bw.makeStatCard({ label: 'Store', value: s.store.driver }),
      bw.makeStatCard({ label: 'Entries', value: total }),
      bw.makeStatCard({ label: 'Channels', value: s.channels.length }),
      bw.makeStatCard({ label: 'Users', value: s.auth.users_configured })
    ]
  });

  function row(label, value, bad) {
    return {
      t: 'tr',
      c: [
        { t: 'td', a: { style: 'padding:0.3rem 0.9rem 0.3rem 0;opacity:0.75' }, c: label },
        { t: 'td', a: { style: 'font-family:monospace' + (bad ? ';color:#c0392b;font-weight:600' : '') }, c: String(value) }
      ]
    };
  }

  var checks = [
    row('triops', s.triops_version),
    row('api', s.api_version),
    row('php', s.php_version),
    row('bitwrench', s.bitwrench || 'not detected', !s.bitwrench),
    row('store driver', s.store.driver),
    row('store healthy', s.store.healthy ? 'yes' : 'no', !s.store.healthy),
    row('sqlite3 extension', s.store.sqlite_present ? 'present' : 'missing'),
    row('data dir', s.data_dir.path),
    row('data dir writable', s.data_dir.writable ? 'yes' : 'NO', !s.data_dir.writable),
    row('max entries / channel', s.store.max_entries),
    row('max payload bytes', s.store.max_payload),
    row('ingest key', s.auth.ingest_key_set ? 'set' : 'not set — anyone can post'),
    row('device TACO render', s.taco_render ? 'enabled' : 'disabled'),
    row('server time', s.server_time)
  ];
  // Why you are on the driver you are on. Silent fallback is the confusing kind:
  // everything works, slower and with a different retention shape, and nothing
  // says so.
  if (s.store.fallback_reason) {
    checks.push(row('sqlite not used', s.store.fallback_reason, s.store.sqlite_present));
  }
  if (s.store.error) checks.push(row('store error', s.store.error, true));

  var channelBlock = s.channels.length
    ? bw.makeDataTable({
        data: s.channels.map(function (c) {
          return {
            channel: c.channel,
            entries: c.n,
            last: c.last_ts ? triops.fmtAgo(c.last_ts) : '—'
          };
        })
      })
    : { t: 'p', a: { style: 'opacity:0.7' }, c: 'No channels yet.' };

  bw.DOM('#detail', {
    t: 'div',
    c: [
      bw.makeCard({ title: 'Environment', content: { t: 'table', c: { t: 'tbody', c: checks } } }),
      bw.makeCard({ title: 'Channels', content: channelBlock })
    ]
  });
})();
</script>
<?php t_page_end(); ?>
