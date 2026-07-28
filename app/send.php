<?php
/**
 * Post a payload from the browser, as if you were a device.
 *
 * Useful before the hardware exists, and for proving triops works before you
 * start blaming your firmware. 0.1 did this by curling itself; this posts
 * straight to the store, which is one moving part instead of three.
 */
require __DIR__ . '/lib/triops.php';

t_require_auth();

$result  = '';
$channel = t_channel($_POST['channel'] ?? $_GET['channel'] ?? null);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    t_csrf_check();
    $payload = (string) ($_POST['payload'] ?? '');

    $max = (int) t_config('max_payload_bytes', 65536);
    if (strlen($payload) > $max) {
        $result = "Too big: {$max} bytes max.";
    } else {
        $record           = t_make_record($payload);
        $record['method'] = 'POST (send.php)';
        try {
            t_store()->push($channel, $record);
            $result = 'Stored ' . strlen($payload) . ' bytes in "' . $channel . '".';
        } catch (Throwable $e) {
            $result = 'Store write failed: ' . $e->getMessage();
        }
    }
}

t_page_open('Send test data');
?>
<p class="triops-prose">
  Anything goes — JSON, a bare number, malformed text. triops stores the bytes
  as received, so this is also how you check what the viewer does with a payload
  your parser would reject.
</p>

<?php if ($result !== ''): ?>
  <p class="bw_bccl_card" style="padding:0.75rem"><strong><?= t_e($result) ?></strong>
  &nbsp;<a href="./view.php?channel=<?= t_e(urlencode($channel)) ?>">View it →</a></p>
<?php endif; ?>

<form method="post" action="./send.php" class="bw_bccl_card" style="padding:1rem;max-width:42rem">
  <input type="hidden" name="csrf" value="<?= t_e(t_csrf_token()) ?>">
  <p><label>Channel<br>
    <input class="bw_bccl_form_control" type="text" name="channel" value="<?= t_e($channel) ?>"></label></p>
  <p><label>Payload<br>
    <textarea class="bw_bccl_form_control" name="payload" rows="12"
      placeholder='{"device":"esp32-01","temp_c":22.4,"uptime_s":1043}'><?= t_e((string) ($_POST['payload'] ?? '')) ?></textarea>
  </label></p>
  <p><button class="bw_bccl_btn bw_primary" type="submit">Send</button></p>
</form>
<?php
t_page_close();
t_page_end();
