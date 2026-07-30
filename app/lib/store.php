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
    /**
     * Line 1 of every file this directory holds is a PHP exit guard, so even if
     * the directory ends up served the contents do not leak. Readers skip it.
     */
    protected const GUARD = '<?php exit; ?>';

    /** @var string */
    protected $dir;

    public function __construct(string $dir)
    {
        $this->dir = $dir;
    }

    abstract public function name(): string;

    /**
     * Open the store and make it ready to write, or throw.
     *
     * This exists so the factory can find out whether a driver actually works
     * before committing to it. A constructor that only remembers a path always
     * succeeds, which makes automatic fallback a fiction.
     */
    abstract public function init(): void;

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

    public function init(): void
    {
        $this->db();
    }

    /**
     * The database filename carries a random suffix generated on first run.
     * A sqlite file cannot protect itself the way the ndjson driver can — you
     * cannot prepend an exit guard to a binary file — so if the deny rules ever
     * fail the name is at least not guessable.
     *
     * Which is why the marker recording that name is itself guarded. 0.2.0 wrote
     * it to a bare `.dbname`, and a bare file is served as text by anything that
     * ignores .htaccess — handing out the one filename the random suffix exists
     * to hide.
     */
    private function path(): string
    {
        $marker = $this->dir . '/dbname.php';
        $legacy = $this->dir . '/.dbname';
        $name   = null;

        if (is_readable($marker)) {
            $name = $this->readName($marker, 1);
        } elseif (is_readable($legacy)) {
            $name = $this->readName($legacy, 0);
            if ($name !== null) {
                $this->writeMarker($marker, $name);
                @unlink($legacy);
            }
        }

        if ($name === null) {
            $name = 'triops-' . bin2hex(random_bytes(8)) . '.sqlite';
            $this->writeMarker($marker, $name);
        }

        return $this->dir . '/' . $name;
    }

    /** Read the database name from a marker file, or null if it does not hold one. */
    private function readName(string $file, int $line): ?string
    {
        $lines = explode("\n", (string) file_get_contents($file));
        $name  = trim($lines[$line] ?? '');
        return preg_match('/^triops-[a-f0-9]{16}\.sqlite$/', $name) === 1 ? $name : null;
    }

    private function writeMarker(string $file, string $name): void
    {
        file_put_contents($file, self::GUARD . "\n" . $name . "\n", LOCK_EX);
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
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            channel       TEXT NOT NULL,
            ts            REAL NOT NULL,
            ip            TEXT,
            method        TEXT,
            ctype         TEXT,
            bytes         INTEGER,
            query         TEXT,
            headers       TEXT,
            body          TEXT,
            body_encoding TEXT
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_entries_channel_id ON entries (channel, id)');

        // 0.2.0 databases predate the last two columns, and CREATE TABLE IF NOT
        // EXISTS will not add them to a table that already exists. Ask.
        $have = [];
        $res  = $db->query('PRAGMA table_info(entries)');
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $have[$row['name']] = true;
        }
        foreach (['headers', 'body_encoding'] as $col) {
            if (!isset($have[$col])) {
                $db->exec('ALTER TABLE entries ADD COLUMN ' . $col . ' TEXT');
            }
        }

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
            $st = $db->prepare('INSERT INTO entries (channel, ts, ip, method, ctype, bytes, query, headers, body, body_encoding)
                                VALUES (:ch, :ts, :ip, :m, :ct, :by, :q, :hd, :bd, :enc)');
            $st->bindValue(':ch', $channel, SQLITE3_TEXT);
            $st->bindValue(':ts', $record['ts'], SQLITE3_FLOAT);
            $st->bindValue(':ip', $record['ip'], SQLITE3_TEXT);
            $st->bindValue(':m', $record['method'], SQLITE3_TEXT);
            $st->bindValue(':ct', $record['ctype'], SQLITE3_TEXT);
            $st->bindValue(':by', $record['bytes'], SQLITE3_INTEGER);
            $st->bindValue(':q', (string) json_encode($record['query']), SQLITE3_TEXT);
            $st->bindValue(':hd', (string) json_encode($record['headers']), SQLITE3_TEXT);
            // Already base64 by the time it reaches here if it was not UTF-8,
            // so this column is always text that JSON can carry back out.
            $st->bindValue(':bd', $record['body'], SQLITE3_TEXT);
            $st->bindValue(':enc', $record['body_encoding'], SQLITE3_TEXT);
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
        $st = $this->db()->prepare('SELECT ts, ip, method, ctype, bytes, query, headers, body, body_encoding
                                    FROM entries WHERE channel = :ch
                                    ORDER BY id DESC LIMIT :n');
        $st->bindValue(':ch', $channel, SQLITE3_TEXT);
        $st->bindValue(':n', max(1, $n), SQLITE3_INTEGER);
        $res = $st->execute();

        $out = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $out[] = t_normalise_record($row);
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
    public function name(): string
    {
        return 'ndjson';
    }

    public function init(): void
    {
        if (!is_dir($this->dir)) {
            throw new RuntimeException('Data directory does not exist: ' . $this->dir);
        }
        if (!is_writable($this->dir)) {
            throw new RuntimeException('Data directory is not writable: ' . $this->dir);
        }
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

    /**
     * The lock is a separate file, and it is separate for a reason.
     *
     * Compaction ends in a rename, which replaces the inode. A writer holding
     * flock on the *data* file therefore holds a lock on a file that is about to
     * stop being the data file, and appends into an unlinked inode — the packet
     * is written, fsynced, and gone. A lock file is never renamed, so everyone
     * queues on the same object no matter how many times the data underneath it
     * is replaced.
     *
     */
    private function lockPath(string $channel): string
    {
        return $this->dir . '/ch-' . $channel . '.lock.php';
    }

    public function push(string $channel, array $record): void
    {
        // Encode before taking the lock, and refuse rather than write a blank
        // line. json_encode returns false on malformed UTF-8, and `false . "\n"`
        // is a newline: the packet silently vanishes and the device is told it
        // was stored. t_make_record base64s such bodies, so reaching this is a
        // bug — but it fails loudly now instead of eating data.
        $line = json_encode($record, JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            throw new RuntimeException('Record could not be encoded: ' . json_last_error_msg());
        }

        $file = $this->path($channel);
        $lock = fopen($this->lockPath($channel), 'c+');
        if ($lock === false) {
            throw new RuntimeException('Cannot open the lock file for channel ' . $channel . '.');
        }

        try {
            flock($lock, LOCK_EX);

            // A worker process handles many requests, and its stat cache can
            // outlive a rename from compaction or an unlink from clear(). A
            // stale "the file exists" skips the guard line, and an unguarded
            // data file is served as plain text anywhere .htaccess is ignored.
            clearstatcache(true, $file);

            $new = !file_exists($file);
            $fh  = fopen($file, 'a');
            if ($fh === false) {
                throw new RuntimeException('Cannot open ' . $file . ' for append.');
            }
            try {
                if ($new) {
                    fwrite($fh, self::GUARD . "\n");
                }
                // One append per packet. Never read-modify-write: rewriting the whole
                // buffer on every request is what makes the naive file approach fall over.
                fwrite($fh, $line . "\n");
                fflush($fh);
            } finally {
                fclose($fh);
            }

            // Counting means reading the file, which is what 0.2.0 did on every
            // append as well. The difference is that it now happens under the
            // lock, so the count cannot describe a file that is being replaced
            // underneath it. A counter cached in the lock file would make this
            // O(1), and was tried: under concurrent workers it drifts out of
            // step with the file it claims to describe, and a wrong count means
            // compaction that never fires or fires early. Correct and O(file) is
            // the better trade for a ring buffer that is 2048 lines at its
            // widest.
            if (count($this->readLines($file)) > $this->maxEntries() * 4) {
                $this->compact($file);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Rewrite down to the last max entries. The caller must hold the channel
     * lock; this reads and renames, and both halves have to be inside it.
     */
    private function compact(string $file): void
    {
        $keep = array_slice($this->readLines($file), -$this->maxEntries());
        // .php on the temporary file too — it is briefly a real file in a served
        // directory, and a crash between write and rename leaves it there.
        $tmp = $file . '.compacting.php';
        file_put_contents($tmp, self::GUARD . "\n" . implode("\n", $keep) . "\n");
        rename($tmp, $file);
        clearstatcache(true, $file);
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
                $out[] = t_normalise_record($rec);
            }
        }
        return $out;
    }

    public function clear(string $channel): int
    {
        $file = $this->path($channel);
        $lock = $this->lockPath($channel);
        $n    = count($this->readLines($file));
        if (file_exists($file)) {
            unlink($file);
        }
        // The counter describes a file that no longer exists.
        if (file_exists($lock)) {
            unlink($lock);
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

/** Why sqlite was not used, when it was wanted and did not work. Null if it was. */
function t_store_fallback_reason(?string $set = null): ?string
{
    static $reason = null;
    if ($set !== null && $reason === null) {
        $reason = $set;
    }
    return $reason;
}

function t_store(): TriopsStore
{
    static $store = null;
    if ($store !== null) {
        return $store;
    }

    $dir  = t_data_dir();
    $want = (string) t_config('store', 'auto');

    if ($want === 'sqlite' || ($want === 'auto' && class_exists('SQLite3'))) {
        try {
            $sqlite = new TriopsSqliteStore($dir);
            // Open the database *now*. Until 0.2.1 this was lazy, so the try
            // block wrapped a constructor that only remembered a path and could
            // not fail — fallback fired for a missing extension and nothing
            // else. A read-only data directory, a full disk or a corrupt file
            // surfaced later as a 500 from ingest, long after the fallback
            // decision had been made.
            $sqlite->init();
            return $store = $sqlite;
        } catch (Throwable $e) {
            // A missing extension is an Error, not an Exception, in PHP 7+.
            // Catching Exception here — as 0.1 did — misses it entirely and the
            // page fatals.
            if ($want === 'sqlite') {
                throw $e;
            }
            t_store_fallback_reason($e->getMessage());
        }
    } elseif ($want === 'auto') {
        t_store_fallback_reason('The SQLite3 extension is not loaded.');
    }

    $ndjson = new TriopsNdjsonStore($dir);
    try {
        $ndjson->init();
    } catch (Throwable $e) {
        // The floor. There is nothing left to fall back to, so record it and let
        // status.php explain rather than fataling every page in the app —
        // including the one you opened to find out what is wrong.
        t_store_fallback_reason($e->getMessage());
    }
    return $store = $ndjson;
}

/**
 * Normalised record for one inbound request.
 *
 * Bodies are stored as sent. Anything that is not valid UTF-8 — a CBOR frame, a
 * protobuf, a compressed packet, or simply a firmware bug — is base64'd and
 * labelled, because JSON cannot carry those bytes at all. Storing them raw meant
 * json_encode returned false, which under ndjson wrote a blank line and lost the
 * packet, and under sqlite made every later read of that channel return an empty
 * body. "Store the bytes the device sent" has to survive the bytes being ugly.
 */
function t_make_record(string $body): array
{
    // Embedded NULs are legal UTF-8 but survive round trips poorly, so they
    // count as binary here too.
    $text = ($body === '')
        || (preg_match('//u', $body) === 1 && strpos($body, "\0") === false);

    return [
        'ts'            => microtime(true),
        'ip'            => t_client_ip(),
        'method'        => (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        'ctype'         => (string) ($_SERVER['CONTENT_TYPE'] ?? ''),
        'bytes'         => strlen($body),
        'query'         => t_redact($_GET),
        'headers'       => t_redact(t_request_headers()),
        'body'          => $text ? $body : base64_encode($body),
        'body_encoding' => $text ? 'utf-8' : 'base64',
    ];
}

/**
 * Give every record the shape the current version promises, whichever driver and
 * whichever version wrote it. Records from 0.2.0 have no headers and no encoding.
 */
function t_normalise_record(array $rec): array
{
    if (!is_array($rec['query'] ?? null)) {
        $rec['query'] = json_decode((string) ($rec['query'] ?? ''), true) ?: [];
    }
    if (!is_array($rec['headers'] ?? null)) {
        $rec['headers'] = json_decode((string) ($rec['headers'] ?? ''), true) ?: [];
    }
    if (empty($rec['body_encoding'])) {
        $rec['body_encoding'] = 'utf-8';
    }
    return $rec;
}
