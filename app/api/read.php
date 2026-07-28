<?php
/**
 * GET api/read.php?channel=default&n=50
 *
 * Last N entries, newest first. Each entry carries the raw body plus what the
 * server observed about the request: time, peer address, method, content type,
 * size, and query parameters.
 */
require __DIR__ . '/../lib/triops.php';

t_api_require_auth();

$channel = t_channel($_GET['channel'] ?? null);
$n       = (int) ($_GET['n'] ?? 50);
$n       = max(1, min(500, $n));

try {
    $entries = t_store()->last($channel, $n);
} catch (Throwable $e) {
    t_err('Store read failed: ' . $e->getMessage(), 'store_error', 500);
}

t_ok([
    'channel' => $channel,
    'count'   => count($entries),
    'entries' => $entries,
]);
