<?php
/**
 * triops configuration.
 *
 * You do not need this file to run triops — every value below is the default.
 * Copy it to config.php only when you want to change something:
 *
 *     cp config.sample.php config.php
 *
 * config.php is gitignored. Never commit it.
 */

return [
    // Shown in the browser title and the nav bar.
    'site_name' => 'triops',

    // Any value from https://www.php.net/manual/en/timezones.php
    'timezone' => 'UTC',

    // 'auto' picks sqlite when the SQLite3 extension is present, else ndjson.
    // Force 'ndjson' if you want to watch payloads land with:  tail -f app/data/ch-*.ndjson
    'store' => 'auto',

    // Where payloads, users and the database live. Must be writable by the web server.
    // Move this outside your web root if you can — see docs/install.md.
    'data_dir' => __DIR__ . '/data',

    // Reject request bodies larger than this. A device stuck in a retry loop
    // can otherwise fill your disk.
    'max_payload_bytes' => 65536,

    // Ring buffer depth. Older entries are dropped as new ones arrive.
    //
    // The sqlite driver trims to exactly this on every write. The ndjson driver
    // only rewrites its file once it passes 4x this number, then cuts back down,
    // so it holds somewhere between N and 4N. Compacting an append-only file on
    // every request would defeat the point of it being append-only.
    'max_entries_per_channel' => 512,

    // Channel used when a request does not name one.
    'default_channel' => 'default',

    // Leave empty and anyone who can reach the server can post.
    // Set it and ingest requires  ?key=...  or  X-Triops-Key: ...
    'ingest_key' => '',

    // Devices can post a bitwrench TACO ({t,a,c,o}) and have triops render it
    // as live UI. Off by default: rendering attacker-supplied attributes is XSS.
    // Event handlers and the options block are stripped even when this is on.
    'allow_taco_render' => false,

    // Seed colors. bitwrench derives the full palette, including dark mode.
    'theme' => [
        'primary'   => '#006666',
        'secondary' => '#cc6633',
    ],
];
