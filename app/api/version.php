<?php
/**
 * GET api/version.php
 *
 * Unauthenticated on purpose — this is how a client confirms it is talking to
 * triops at all, and how it feature-detects. api is an integer and only changes
 * on a breaking API change, so clients never have to parse a semver string.
 *
 *   {"ok":true,"api":1,"data":{"name":"triops","version":"0.2.0","api":1}}
 */
require __DIR__ . '/../lib/triops.php';

t_ok([
    'name'    => 'triops',
    'version' => TRIOPS_VERSION,
    'api'     => TRIOPS_API_VERSION,
]);
