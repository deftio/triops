<?php
/**
 * Responds after a delay.  delay.php?ms=2000
 *
 * Embedded HTTP clients handle timeouts badly and you cannot test that against
 * a server that always answers instantly. Capped at 30s so a stray request
 * cannot pin a worker forever.
 */
require __DIR__ . '/lib/triops.php';

$ms = (int) ($_GET['ms'] ?? 1000);
$ms = max(0, min(30000, $ms));

usleep($ms * 1000);

t_text("waited {$ms}ms\n");
