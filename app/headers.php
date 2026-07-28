<?php
/**
 * Request headers exactly as the server received them, plain text.
 *
 * Use this when the address in ip.php is not what you expect, or when a CDN or
 * reverse proxy is between your device and triops — X-Forwarded-For and friends
 * show up here.
 */
require __DIR__ . '/lib/triops.php';

$out = [];
foreach (t_request_headers() as $name => $value) {
    $out[] = $name . ': ' . $value;
}

t_text(implode("\n", $out) . "\n");
