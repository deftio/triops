<?php
/**
 * triops bootstrap.
 *
 * Every page starts with exactly one line:
 *
 *     <?php require __DIR__ . '/lib/triops.php';
 *
 * That gives you config, the store, auth, and the response/UI helpers.
 * See docs/hacking.md.
 */

declare(strict_types=1);

define('TRIOPS', true);
define('TRIOPS_VERSION', '0.2.2');

// Bumped only on a breaking change to /api. Clients feature-detect on this
// integer instead of parsing the product version.
define('TRIOPS_API_VERSION', 1);

// ---------------------------------------------------------------- config

function t_config(?string $key = null, $default = null)
{
    static $config = null;

    if ($config === null) {
        $config = require __DIR__ . '/../config.sample.php';
        $local  = __DIR__ . '/../config.php';
        if (is_readable($local)) {
            $override = require $local;
            if (is_array($override)) {
                $config = array_replace($config, $override);
            }
        }
    }

    if ($key === null) {
        return $config;
    }
    return $config[$key] ?? $default;
}

date_default_timezone_set((string) t_config('timezone', 'UTC'));

require __DIR__ . '/store.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/ui.php';

// ---------------------------------------------------------------- responses

/** Plain text, no HTML. The debug primitives use this and their output is stable. */
function t_text(string $body, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $body;
    exit;
}

/** Raw bytes with an explicit content type. */
function t_raw(string $body, string $contentType, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: ' . $contentType);
    header('Cache-Control: no-store');
    echo $body;
    exit;
}

/** Every /api response goes through t_ok or t_err so the envelope never varies. */
function t_ok($data = null, int $status = 200): void
{
    t_json(['ok' => true, 'api' => TRIOPS_API_VERSION, 'data' => $data], $status);
}

function t_err(string $message, string $code = 'error', int $status = 400): void
{
    t_json(['ok' => false, 'api' => TRIOPS_API_VERSION, 'error' => $message, 'code' => $code], $status);
}

function t_json(array $payload, int $status = 200): void
{
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    // json_encode returns false on anything it cannot represent, and `echo false`
    // is an empty string — which shipped a 200 with a JSON content type and no
    // body, the least debuggable failure available. Say what happened instead.
    if ($json === false) {
        $status = 500;
        $json   = (string) json_encode([
            'ok'    => false,
            'api'   => TRIOPS_API_VERSION,
            'error' => 'Response could not be encoded: ' . json_last_error_msg(),
            'code'  => 'encode_failed',
        ]);
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo $json;
    exit;
}

// ---------------------------------------------------------------- request

/** Body of the request, refused if it exceeds max_payload_bytes. */
function t_request_body(): string
{
    $max  = (int) t_config('max_payload_bytes', 65536);
    $body = file_get_contents('php://input', false, null, 0, $max + 1);
    if ($body === false) {
        return '';
    }
    if (strlen($body) > $max) {
        t_err("Payload exceeds max_payload_bytes ({$max}).", 'payload_too_large', 413);
    }
    return $body;
}

function t_client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

/**
 * Request headers as an ordinary map.
 *
 * getallheaders() only exists under some SAPIs, so fall back to reconstructing
 * from $_SERVER. HTTP_X_FORWARDED_FOR becomes X-Forwarded-For.
 *
 * @return array<string,string>
 */
function t_request_headers(): array
{
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers) && $headers !== []) {
            return $headers;
        }
    }

    $out = [];
    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') !== 0) {
            continue;
        }
        $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
        $out[$name] = (string) $value;
    }
    foreach (['CONTENT_TYPE' => 'Content-Type', 'CONTENT_LENGTH' => 'Content-Length'] as $k => $name) {
        if (isset($_SERVER[$k]) && $_SERVER[$k] !== '') {
            $out[$name] = (string) $_SERVER[$k];
        }
    }
    ksort($out);
    return $out;
}

/**
 * Replace the value of anything that looks like a credential with a marker.
 *
 * triops is a debugging tool, and a debugging tool must not quietly turn a
 * temporary secret into permanent telemetry. `?key=...` was being stored in the
 * record, shown in the inbox, and handed back by api/read.php — so a key pasted
 * once to test a board outlived the test by however long the channel does.
 *
 * The value goes, the key stays: "an ingest key was supplied and it was wrong"
 * is exactly what you need to see when a device cannot authenticate.
 *
 * Matching ignores case, dashes and underscores, so api_key, API-KEY and apikey
 * are all one name.
 *
 * @param array<string,mixed> $map
 * @return array<string,mixed>
 */
function t_redact(array $map): array
{
    static $deny = null;
    if ($deny === null) {
        $deny = [];
        foreach ((array) t_config('redact_keys', []) as $name) {
            $deny[t_redact_key((string) $name)] = true;
        }
    }

    $out = [];
    foreach ($map as $key => $value) {
        if (isset($deny[t_redact_key((string) $key)])) {
            $out[$key] = '[redacted]';
        } elseif (is_array($value)) {
            $out[$key] = t_redact($value);
        } else {
            $out[$key] = $value;
        }
    }
    return $out;
}

function t_redact_key(string $name): string
{
    return str_replace(['-', '_'], '', strtolower($name));
}

/**
 * Channel names reach the filesystem, so nothing but [A-Za-z0-9_-] survives.
 * This is the only thing standing between a posted device_id and ../../.
 */
function t_channel(?string $raw = null): string
{
    if ($raw === null || $raw === '') {
        $raw = (string) t_config('default_channel', 'default');
    }
    $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $raw);
    $safe = substr((string) $safe, 0, 64);
    return $safe === '' ? 'default' : $safe;
}

/** Constant-time check of the optional shared ingest key. */
function t_check_ingest_key(): void
{
    $expected = (string) t_config('ingest_key', '');
    if ($expected === '') {
        return;
    }
    $given = (string) ($_GET['key'] ?? $_SERVER['HTTP_X_TRIOPS_KEY'] ?? '');
    if (!hash_equals($expected, $given)) {
        t_err('Missing or invalid ingest key.', 'unauthorized', 401);
    }
}

// ---------------------------------------------------------------- misc

function t_e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Self-diagnosis, shared by status.php and api/status.php so the page and the
 * API can never disagree.
 */
function t_status_report(): array
{
    $dir   = t_data_dir();
    $store = t_store();

    $channels = [];
    $healthy  = false;
    $error    = null;
    try {
        $healthy  = $store->healthy();
        $channels = $store->channels();
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }

    $bw = __DIR__ . '/../assets/bitwrench.umd.min.js';
    $bwVersion = null;
    if (is_readable($bw)) {
        $head = (string) file_get_contents($bw, false, null, 0, 200);
        if (preg_match('/bitwrench v([0-9][^\s|]*)/', $head, $m)) {
            $bwVersion = $m[1];
        }
    }

    return [
        'triops_version' => TRIOPS_VERSION,
        'api_version'    => TRIOPS_API_VERSION,
        'php_version'    => PHP_VERSION,
        'bitwrench'      => $bwVersion,
        'store'          => [
            'driver'          => $store->name(),
            'healthy'         => $healthy,
            'error'           => $error,
            'fallback_reason' => t_store_fallback_reason(),
            'sqlite_present'  => class_exists('SQLite3'),
            'max_entries'     => (int) t_config('max_entries_per_channel', 512),
            'max_payload'     => (int) t_config('max_payload_bytes', 65536),
        ],
        'data_dir' => [
            'path'     => $dir,
            'exists'   => is_dir($dir),
            'writable' => is_writable($dir),
        ],
        'auth' => [
            'users_configured' => count(t_users_load()),
            'ingest_key_set'   => t_config('ingest_key', '') !== '',
        ],
        'taco_render' => (bool) t_config('allow_taco_render', false),
        'channels'    => $channels,
        'server_time' => date('Y-m-d H:i:s'),
    ];
}

/** The endpoint manifest. index.php renders itself from this, so the page cannot drift from reality. */
function t_manifest(): array
{
    return [
        'Primitives' => [
            ['timestamp.php', 'Server time to microseconds, as plain text.'],
            ['sum.php?a=1&b=2', 'Adds every numeric GET parameter. Confirms query strings survive the trip.'],
            ['ip.php', 'The client address as the server sees it.'],
            ['echo.php', 'Returns your request back to you: method, headers, query, body.'],
            ['headers.php', 'Just the request headers. Useful behind a proxy or NAT.'],
            ['delay.php?ms=2000', 'Responds after a delay. Tests client timeouts.'],
            ['code.php?c=500', 'Responds with the status code you ask for. Tests error handling.'],
            ['bytes.php?n=4096', 'Returns exactly N bytes. Tests buffer limits on constrained devices.'],
        ],
        'Inbox' => [
            ['send.php', 'Post a payload from a web form, as if you were a device.'],
            ['view.php', 'Everything received, newest first, with a raw view.'],
            ['status.php', 'Which store is active, whether it is writable, versions, counts.'],
        ],
        'API' => [
            ['api/version.php', 'Name, version, api integer. No auth.'],
            ['api/status.php', 'Machine-readable status.'],
            ['api/read.php?channel=default&n=50', 'Last N entries as JSON.'],
            ['api/ingest.php', 'POST a payload. The canonical device endpoint.'],
            ['api/clear.php?channel=default', 'Drop a channel.'],
        ],
    ];
}
