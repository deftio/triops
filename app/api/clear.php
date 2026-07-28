<?php
/**
 * POST api/clear.php?channel=default
 *
 * Drops a channel so you are not reading yesterday's packet while chasing
 * today's bug. POST only — a GET that deletes data gets triggered by every
 * link prefetcher on earth.
 */
require __DIR__ . '/../lib/triops.php';

t_api_require_auth();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    t_err('Use POST to clear a channel.', 'method_not_allowed', 405);
}

$channel = t_channel($_GET['channel'] ?? null);

try {
    $removed = t_store()->clear($channel);
} catch (Throwable $e) {
    t_err('Store clear failed: ' . $e->getMessage(), 'store_error', 500);
}

t_ok(['channel' => $channel, 'removed' => $removed]);
