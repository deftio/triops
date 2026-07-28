<?php
/**
 * Server time to microseconds, as plain text.
 *
 * Output format is a stability promise — people script against it. Do not
 * change it without bumping the major version.
 *
 *   2026-07-27 14:31:07:117700
 */
require __DIR__ . '/lib/triops.php';

$now = DateTime::createFromFormat('U.u', sprintf('%.6F', microtime(true)));
$now->setTimezone(new DateTimeZone(date_default_timezone_get()));

t_text($now->format('Y-m-d H:i:s:u'));
