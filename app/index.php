<?php
/**
 * Home. Renders itself from the manifest in lib/triops.php, so this page and
 * docs/endpoints.md cannot drift from what actually ships.
 */
require __DIR__ . '/lib/triops.php';

t_require_auth();

t_page_open('');
?>
<img src="./assets/triops-logo.png" alt="triops" style="height:64px;margin:0.5rem 0 1rem">
<p style="max-width:44rem">
  Point a device at one of these and watch what arrives. The primitives return
  plain text so you can read them on a serial console; everything under
  <code>api/</code> returns JSON.
</p>
<div id="manifest"></div>
<?php
t_page_close(true, ['manifest' => t_manifest(), 'store' => t_store()->name()]);
?>
<script>
bw.DOM('#manifest', {
  t: 'div',
  c: Object.keys(TRIOPS_DATA.manifest).map(function (section) {
    return bw.makeCard({
      title: section,
      content: {
        t: 'ul',
        a: { style: 'margin:0;padding-left:1.1rem' },
        c: TRIOPS_DATA.manifest[section].map(function (row) {
          return {
            t: 'li',
            a: { style: 'margin:0.35rem 0' },
            c: [
              { t: 'a', a: { href: './' + row[0], style: 'font-family:monospace' }, c: row[0] },
              { t: 'span', a: { style: 'opacity:0.75' }, c: ' — ' + row[1] }
            ]
          };
        })
      }
    });
  })
});
</script>
<?php t_page_end(); ?>
