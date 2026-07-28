<?php
/**
 * Returns exactly N bytes.  bytes.php?n=4096
 *
 * Constrained devices have small receive buffers and truncate quietly. Walk
 * this up until your client breaks and you have found your real limit.
 *
 * The payload is a repeating printable pattern so truncation is obvious on a
 * serial console. Capped at 1 MiB.
 */
require __DIR__ . '/lib/triops.php';

$n = (int) ($_GET['n'] ?? 1024);
$n = max(0, min(1048576, $n));

$pattern = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
$body    = $n > 0 ? substr(str_repeat($pattern, (int) ceil($n / strlen($pattern))), 0, $n) : '';

header('Content-Length: ' . $n);
t_text($body);
