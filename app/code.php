<?php
/**
 * Responds with whatever HTTP status you ask for.  code.php?c=500
 *
 * Most device firmware is written against the happy path. This is how you find
 * out what yours does with a 401, a 429 or a 503 without having to break
 * something real first.
 */
require __DIR__ . '/lib/triops.php';

$code = (int) ($_GET['c'] ?? 200);
if ($code < 100 || $code > 599) {
    $code = 200;
}

t_text("status {$code}\n", $code);
