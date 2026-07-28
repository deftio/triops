<?php
// Stops directory listing from being useful on hosts that ignore .htaccess.
http_response_code(403);
header('Content-Type: text/plain; charset=utf-8');
echo "Not available.\n";
