<?php
/**
 * POST api/ingest.php[?channel=NAME]
 *
 * The canonical device endpoint. Accepts any body — JSON, form encoding, CSV,
 * a bare sensor reading. triops stores the bytes as received and parses only
 * when displaying, so a malformed payload is still visible rather than lost.
 *
 * Optionally gated by ingest_key in config, supplied as ?key= or X-Triops-Key.
 * GET is accepted too: some minimal clients cannot POST.
 */
require __DIR__ . '/../lib/triops.php';

t_check_ingest_key();

$channel = t_channel($_GET['channel'] ?? null);
$body    = t_request_body();

try {
    t_store()->push($channel, t_make_record($body));
} catch (Throwable $e) {
    t_err('Store write failed: ' . $e->getMessage(), 'store_error', 500);
}

t_ok([
    'channel' => $channel,
    'bytes'   => strlen($body),
    'stored'  => true,
]);
