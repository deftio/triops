<?php
/**
 * triops storage.
 *
 * 0.1 used redis for what turns out to be a very small surface: set a scalar,
 * rpush, llen, lpop, lrange. That is a capped append-only list per channel plus
 * a couple of scalars, so triops now just implements it and has no runtime
 * dependency at all.
 *
 * Two drivers:
 *   sqlite  (default) — one file, WAL, O(1) append, trimmed in the same transaction
 *   ndjson  (fallback) — append-only text, no extensions needed, and you can
 *                        tail -f it while a device posts
 *
 * Trimming happens on write. There is deliberately no timer and no cron: a
 * background job that quietly dies is a worse failure than one extra DELETE.
 */

defined('TRIOPS') or die('Direct access not permitted');

abstract class TriopsStore
{
    /** @var string */
    protected $dir;

    public function __construct(string $dir)
    {
        $this->dir = $dir;
    }

    abstract public function name(): string;
    abstract public function push(string $channel, array $record): void;
    abstract public function last(string $channel, int $n): array;
    abstract public function clear(string $channel): int;
    abstract public function channels(): array;
    abstract public function healthy(): bool;

    protected function maxEntries(): int
    {
        return max(1, (int) t_config('max_entries_per_channel', 512));
    }
}

// ---------------------------------------------------------------- sqlite

class TriopsSqliteStore extends TriopsStore
{
    /** @var SQLite3|null */
    private $db = null;

    public function name(): string
    {
        return 'sqlite';
    }

    /**
     * The database filename carries a random suffix generated on first run.
     * A sqlite file cannot protect itself the way the ndjson driver can, so if
     * the deny rules ever fail the name is at least not guessable.
     */
    private function path(): string
    {
        $marker = $this->dir . '/.dbname';
        if (is_readable($marker)) {
            $name = trim((string) file_get_contents($marker));
            if (preg_match('/^triops-[a-f0-9]{16}\.sqlite$/', $name)) {
                return $this->dir . '/' . $name;
            }
        }
        $name = 'triops-' . bin2hex(random_bytes(8)) . '.sqlite';
        file_put_contents($marker, $name, LOCK_EX);
        return $this->dir . '/' . $name;
    }

    private function db(): SQLite3
    {
        if ($this->db !== null) {
            return $this->db;
        }

        $db = new SQLite3($this->path(), SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);

        // WAL matters here. The viewer polls while devices post, and in the
        // default rollback-journal mode that combination throws "database is
        // locked" almost immediately.
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA synchronous=NORMAL');
        $db->busyTimeout(5000);

        $db->exec('CREATE TABLE IF NOT EXISTS entries (
            id      INTEGER PRIMARY KEY AUTOINCREMENT,
            channel TEXT NOT NULL,
            ts      REAL NOT NULL,
            ip      TEXT,
            method  TEXT,
            ctype   TEXT,
            bytes   INTEGER,
            query   TEXT,
            body    TEXT
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_entries_channel_id ON entries (channel, id)');
        $db->exec('CREATE TABLE IF NOT EXISTS kv (
            k          TEXT PRIMARY KEY,
            v          TEXT,
            expires_at INTEGER
        )');

        return $this->db = $db;
    }

    public function push(string $channel, array $record): void
    {
        $db = $this->db();
        $db->exec('BEGIN IMMEDIATE');
        try {
            $st = $db->prepare('INSERT INTO entries (channel, ts, ip, method, ctype, bytes, query, body)
                                VALUES (:ch, :ts, :ip, :m, :ct, :by, :q, :bd)');
            $st->bindValue(':ch', $channel, SQLITE3_TEXT);
            $st->bindValue(':ts', $record['ts'], SQLITE3_FLOAT);
            $st->bindValue(':ip', $record['ip'], SQLITE3_TEXT);
            $st->bindValue(':m', $record['method'], SQLITE3_TEXT);
            $st->bindValue(':ct', $record['ctype'], SQLITE3_TEXT);
            $st->bindValue(':by', $record['bytes'], SQLITE3_INTEGER);
            $st->bindValue(':q', json_encode($record['query']), SQLITE3_TEXT);
            $st->bindValue(':bd', $record['body'], SQLITE3_TEXT);
            $st->execute();

            // Trim in the same transaction as the insert.
            $tr = $db->prepare('DELETE FROM entries WHERE channel = :ch AND id <= (
                                    SELECT id FROM entries WHERE channel = :ch2
                                    ORDER BY id DESC LIMIT 1 OFFSET :max
                                )');
            $tr->bindValue(':ch', $channel, SQLITE3_TEXT);
            $tr->bindValue(':ch2', $channel, SQLITE3_TEXT);
            $tr->bindValue(':max', $this->maxEntries(), SQLITE3_INTEGER);
            $tr->execute();

            $db->exec('COMMIT');
        } catch (Throwable $e) {
            $db->exec('ROLLBACK');
            throw $e;
        }
    }

    public function last(string $channel, int $n): array
    {
        $st = $this->db()->prepare('SELECT ts, ip, method, ctype, bytes, query, body
                                    FROM entries WHERE channel = :ch
                                    ORDER BY id DESC LIMIT :n');
        $st->bindValue(':ch', $channel, SQLITE3_TEXT);
        $st->bindValue(':n', max(1, $n), SQLITE3_INTEGER);
        $res = $st->execute();

        $out = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $row['query'] = json_decode((string) $row['query'], true) ?: [];
            $out[] = $row;
        }
        return $out;
    }

    public function clear(string $channel): int
    {
        $db = $this->db();
        $st = $db->prepare('DELETE FROM entries WHERE channel = :ch');
        $st->bindValue(':ch', $channel, SQLITE3_TEXT);
        $st->execute();
        return $db->changes();
    }

    public function channels(): array
    {
        $res = $this->db()->query('SELECT channel, COUNT(*) AS n, MAX(ts) AS last_ts
                                   FROM entries GROUP BY channel ORDER BY last_ts DESC');
        $out = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $out[] = $row;
        }
        return $out;
    }

    public function healthy(): bool
    {
        try {
            $this->db()->querySingle('SELECT 1');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

// ---------------------------------------------------------------- ndjson

class TriopsNdjsonStore extends TriopsStore
{
    /**
     * Line 1 of every data file is a PHP exit guard, so even if the directory
     * ends up served the contents do not leak. Readers skip it.
     */
    private const GUARD = '<?php exit; ?>';

    public function name(): string
    {
        return 'ndjson';
    }

    /**
     * The .php suffix is deliberate and load-bearing.
     *
     * A guard line only protects the file if the server executes it. Named
     * plain .ndjson these are served as static text and every payload is
     * readable by anyone who guesses the path — which is what happens on nginx,
     * or anywhere .htaccess is ignored. Named .php the interpreter runs the
     * exit on line 1 and returns nothing.
     *
     * It stays readable on disk: tail -f app/data/ch-lab.ndjson.php
     */
    private function path(string $channel): string
    {
        return $this->dir . '/ch-' . $channel . '.ndjson.php';
    }

    public function push(string $channel, array $record): void
    {
        $file = $this->path($channel);
        $new  = !file_exists($file);

        $fh = fopen($file, 'a');
        if ($fh === false) {
            throw new RuntimeException('Cannot open ' . $file . ' for append.');
        }
        try {
            flock($fh, LOCK_EX);
            if ($new) {
                fwrite($fh, self::GUARD . "\n");
            }
            // One append per packet. Never read-modify-write: rewriting the whole
            // buffer on every request is what makes the naive file approach fall over.
            fwrite($fh, json_encode($record, JSON_UNESCAPED_SLASHES) . "\n");
            fflush($fh);
            flock($fh, LOCK_UN);
        } finally {
            fclose($fh);
        }

        $this->compactIfNeeded($file);
    }

    /** Rewrite only once the file has drifted well past the target depth. */
    private function compactIfNeeded(string $file): void
    {
        $max   = $this->maxEntries();
        $lines = $this->readLines($file);
        if (count($lines) <= $max * 4) {
            return;
        }
        $keep = array_slice($lines, -$max);
        $tmp  = $file . '.tmp';
        file_put_contents($tmp, self::GUARD . "\n" . implode("\n", $keep) . "\n", LOCK_EX);
        rename($tmp, $file);
    }

    /** @return string[] data lines, guard removed */
    private function readLines(string $file): array
    {
        if (!is_readable($file)) {
            return [];
        }
        $raw = (string) file_get_contents($file);
        $all = explode("\n", trim($raw));
        if ($all && strpos($all[0], '<?php') === 0) {
            array_shift($all);
        }
        return array_values(array_filter($all, static function ($l) {
            return trim($l) !== '';
        }));
    }

    public function last(string $channel, int $n): array
    {
        $lines = $this->readLines($this->path($channel));
        $tail  = array_slice($lines, -max(1, $n));
        $out   = [];
        foreach (array_reverse($tail) as $line) {
            $rec = json_decode($line, true);
            if (is_array($rec)) {
                $out[] = $rec;
            }
        }
        return $out;
    }

    public function clear(string $channel): int
    {
        $file = $this->path($channel);
        $n    = count($this->readLines($file));
        if (file_exists($file)) {
            unlink($file);
        }
        return $n;
    }

    public function channels(): array
    {
        $out = [];
        foreach ((array) glob($this->dir . '/ch-*.ndjson.php') as $file) {
            $lines = $this->readLines($file);
            $lastTs = 0.0;
            if ($lines) {
                $rec = json_decode(end($lines), true);
                $lastTs = (float) ($rec['ts'] ?? 0);
            }
            $out[] = [
                'channel' => preg_replace('/^ch-|\.ndjson\.php$/', '', basename($file)),
                'n'       => count($lines),
                'last_ts' => $lastTs,
            ];
        }
        usort($out, static function ($a, $b) {
            return $b['last_ts'] <=> $a['last_ts'];
        });
        return $out;
    }

    public function healthy(): bool
    {
        return is_writable($this->dir);
    }
}

// ---------------------------------------------------------------- factory

function t_data_dir(): string
{
    $dir = (string) t_config('data_dir', __DIR__ . '/../data');
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

function t_store(): TriopsStore
{
    static $store = null;
    if ($store !== null) {
        return $store;
    }

    $dir  = t_data_dir();
    $want = (string) t_config('store', 'auto');

    // A missing extension is an Error, not an Exception, in PHP 7+. Catching
    // Exception here — as 0.1 did — misses it entirely and the page fatals.
    if ($want === 'sqlite' || ($want === 'auto' && class_exists('SQLite3'))) {
        try {
            return $store = new TriopsSqliteStore($dir);
        } catch (Throwable $e) {
            if ($want === 'sqlite') {
                throw $e;
            }
        }
    }

    return $store = new TriopsNdjsonStore($dir);
}

/** Normalised record for one inbound request. */
function t_make_record(string $body): array
{
    return [
        'ts'     => microtime(true),
        'ip'     => t_client_ip(),
        'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        'ctype'  => (string) ($_SERVER['CONTENT_TYPE'] ?? ''),
        'bytes'  => strlen($body),
        'query'  => $_GET,
        'body'   => $body,
    ];
}
