<?php
/**
 * Adds every numeric GET parameter and returns the total as plain text.
 *
 *   sum.php?a=1&b=2  ->  3
 *
 * Pointless on its own; useful for proving your client actually built the
 * query string it thinks it did, and that nothing is serving you a cached
 * response from ten minutes ago.
 */
require __DIR__ . '/lib/triops.php';

$sum = 0;
foreach ($_GET as $value) {
    if (is_numeric($value)) {
        $sum += $value + 0;
    }
}

t_text((string) $sum);
