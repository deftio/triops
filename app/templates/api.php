<?php
/**
 * TEMPLATE: a JSON API endpoint.
 *
 * Copy to app/api/yourname.php and edit.
 *
 * Always answer through t_ok() or t_err() so the envelope never varies, and let
 * them set the status code — a 200 carrying an error body is invisible to a
 * device that only checks the status.
 */
require __DIR__ . '/../lib/triops.php';

// Drop this line if the endpoint should be public. t_api_require_auth() returns
// 401 JSON; t_require_auth() redirects to an HTML login and is wrong here.
t_api_require_auth();

$channel = t_channel($_GET['channel'] ?? null);
$n       = max(1, min(500, (int) ($_GET['n'] ?? 10)));

try {
    $entries = t_store()->last($channel, $n);
} catch (Throwable $e) {
    t_err('Store read failed: ' . $e->getMessage(), 'store_error', 500);
}

t_ok([
    'channel' => $channel,
    'count'   => count($entries),
    'newest'  => $entries[0]['ts'] ?? null,
]);
