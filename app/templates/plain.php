<?php
/**
 * TEMPLATE: a plain-text endpoint.
 *
 * Copy to app/yourname.php and edit. This is the whole thing — one require and
 * one t_text().
 *
 * Plain text, not JSON, because the audience is a microcontroller with a 2 KB
 * HTTP client and a serial console. Everything in triops that is not under
 * api/ works this way.
 */
require __DIR__ . '/../lib/triops.php';

$name = (string) ($_GET['name'] ?? 'world');

t_text("hello {$name}\n");
