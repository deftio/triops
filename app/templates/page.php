<?php
/**
 * TEMPLATE: an HTML page.
 *
 * Copy to app/yourname.php and edit. Add it to t_manifest() in lib/triops.php
 * if you want it listed on the home page.
 *
 * The UI is bitwrench. PHP fetches data and hands it over as JSON; the page
 * script builds the DOM from TACO objects — plain JS objects of the form
 * {t: tag, a: attributes, c: content}. There is no hand-written CSS anywhere in
 * triops: bw.loadStyles() has already derived the palette, including dark mode,
 * from the two seed colors in config.
 *
 * Full guide: docs/hacking.md
 * bitwrench:  https://github.com/deftio/bitwrench
 */
require __DIR__ . '/../lib/triops.php';

// Redirects to login (or setup on a fresh install). Drop it for a public page.
t_require_auth();

$channel = t_channel($_GET['channel'] ?? null);
$entries = t_store()->last($channel, 20);

// Anything printed between t_page_open() and t_page_close() lands inside the
// page container. Keep it to placeholder elements — let bitwrench fill them.
t_page_open('My page');
?>
<p>Last <?= count($entries) ?> entries on <code><?= t_e($channel) ?></code>.</p>
<div id="mine"></div>
<?php
// The second argument becomes window.TRIOPS_DATA in the browser.
t_page_close(true, ['entries' => $entries, 'channel' => $channel]);
?>
<script>
bw.DOM('#mine', bw.makeCard({
  title: 'Payload sizes',
  content: TRIOPS_DATA.entries.length
    ? bw.makeDataTable({
        data: TRIOPS_DATA.entries.map(function (e) {
          return { when: triops.fmtAgo(e.ts), from: e.ip, bytes: e.bytes };
        })
      })
    : { t: 'p', c: 'Nothing received yet.' }
}));
</script>
<?php t_page_end(); ?>
