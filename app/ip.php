<?php
/**
 * The client address as the server sees it, plain text.
 *
 * This is the peer address, so behind a proxy, load balancer or NAT it is the
 * proxy talking, not your device. headers.php shows the forwarding headers if
 * there are any.
 */
require __DIR__ . '/lib/triops.php';

t_text(t_client_ip());
