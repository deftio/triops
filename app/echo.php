<?php
/**
 * Returns your request back to you as plain text: method, path, headers,
 * query, and body.
 *
 * The first thing to reach for when a device "is sending data" and the server
 * disagrees. Shows exactly what arrived, including the Content-Type your HTTP
 * client picked without telling you.
 */
require __DIR__ . '/lib/triops.php';

$body  = t_request_body();
$lines = [];

$lines[] = ($_SERVER['REQUEST_METHOD'] ?? 'GET') . ' ' . ($_SERVER['REQUEST_URI'] ?? '/');
$lines[] = 'from: ' . t_client_ip();
$lines[] = '';

$lines[] = '--- headers ---';
foreach (t_request_headers() as $name => $value) {
    $lines[] = $name . ': ' . $value;
}

$lines[] = '';
$lines[] = '--- query (' . count($_GET) . ') ---';
foreach ($_GET as $k => $v) {
    $lines[] = $k . ' = ' . (is_array($v) ? json_encode($v) : $v);
}

$lines[] = '';
$lines[] = '--- body (' . strlen($body) . ' bytes) ---';
$lines[] = $body;

t_text(implode("\n", $lines) . "\n");
