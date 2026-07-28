<?php
/**
 * GET api/status.php
 *
 * Everything you would otherwise ask a support thread: which store driver is
 * live, whether the data directory is writable, what PHP is running, which
 * bitwrench is vendored, and how much is in each channel.
 *
 * Authenticated — it describes your configuration.
 */
require __DIR__ . '/../lib/triops.php';

t_api_require_auth();

t_ok(t_status_report());
