<?php
/**
 * triops auth — deliberately small.
 *
 * Users are humans who log in to look at data. Devices are not users; they post
 * to /api/ingest.php and are gated (optionally) by a shared ingest_key.
 *
 * There are no roles. Anyone logged in can see everything and manage users.
 * A hardware debug tool on a lab network does not need a permission matrix, and
 * every role check is a place for a bug to hide.
 *
 * Users live in a JSON file rather than the store on purpose: it stays editable
 * with vi when you lock yourself out, and auth keeps working even if the
 * database is corrupt or the sqlite extension vanishes in a host upgrade.
 */

defined('TRIOPS') or die('Direct access not permitted');

const TRIOPS_USERS_GUARD = '<?php exit; ?>';

/**
 * Note the .php extension. It is load-bearing.
 *
 * The first line of this file is an exit guard, but a guard only fires if the
 * server *executes* the file. Named users.json it would be served as static
 * text and every bcrypt hash would be readable by anyone who guessed the path —
 * which is exactly what happens on nginx, or on any host that ignores
 * .htaccess. Named .php it is handed to the interpreter, which exits
 * immediately and returns nothing.
 */
function t_users_file(): string
{
    return t_data_dir() . '/users.php';
}

function t_users_load(): array
{
    $file = t_users_file();
    if (!is_readable($file)) {
        return [];
    }
    $raw = (string) file_get_contents($file);
    if (strpos($raw, '<?php') === 0) {
        $raw = (string) substr($raw, strpos($raw, "\n") ?: 0);
    }
    $data = json_decode(trim($raw), true);
    return is_array($data) ? $data : [];
}

function t_users_save(array $users): bool
{
    $file = t_users_file();
    $tmp  = $file . '.tmp';
    $body = TRIOPS_USERS_GUARD . "\n" . json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if (file_put_contents($tmp, $body, LOCK_EX) === false) {
        return false;
    }
    @chmod($tmp, 0600);
    return rename($tmp, $file);
}

function t_users_exist(): bool
{
    return t_users_load() !== [];
}

function t_user_add(string $name, string $password): bool
{
    $name = trim($name);
    if ($name === '' || strlen($password) < 8) {
        return false;
    }
    $users = t_users_load();
    if (isset($users[$name])) {
        return false;
    }
    $users[$name] = [
        'hash'    => password_hash($password, PASSWORD_DEFAULT),
        'created' => time(),
    ];
    return t_users_save($users);
}

function t_user_delete(string $name): bool
{
    $users = t_users_load();
    if (!isset($users[$name])) {
        return false;
    }
    // Never remove the last account — that locks everyone out of the UI that
    // would let them fix it.
    if (count($users) <= 1) {
        return false;
    }
    unset($users[$name]);
    return t_users_save($users);
}

function t_user_set_password(string $name, string $password): bool
{
    if (strlen($password) < 8) {
        return false;
    }
    $users = t_users_load();
    if (!isset($users[$name])) {
        return false;
    }
    $users[$name]['hash'] = password_hash($password, PASSWORD_DEFAULT);
    return t_users_save($users);
}

function t_user_verify(string $name, string $password): bool
{
    $users = t_users_load();
    if (!isset($users[$name]['hash'])) {
        // Spend the time anyway so a missing user and a wrong password are
        // not trivially distinguishable by response time.
        password_verify($password, '$2y$10$usesomesillystringfoduBaAdWjZkPFV0uWCQFxLDPnbYAqNC');
        return false;
    }
    return password_verify($password, (string) $users[$name]['hash']);
}

// ---------------------------------------------------------------- session

function t_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $https,
    ]);
    session_name('triops_session');
    session_start();
}

function t_login(string $name): void
{
    t_session_start();
    session_regenerate_id(true);
    $_SESSION['user'] = $name;
}

function t_logout(): void
{
    t_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function t_current_user(): ?string
{
    t_session_start();
    $name = $_SESSION['user'] ?? null;
    if ($name === null) {
        return null;
    }
    // A user deleted mid-session should stop being logged in.
    $users = t_users_load();
    return isset($users[$name]) ? (string) $name : null;
}

/** Guard for any page that shows data. Sends you to setup on a fresh install. */
function t_require_auth(): string
{
    if (!t_users_exist()) {
        header('Location: ./setup.php');
        exit;
    }
    $user = t_current_user();
    if ($user === null) {
        $to = urlencode((string) ($_SERVER['REQUEST_URI'] ?? './index.php'));
        header('Location: ./login.php?next=' . $to);
        exit;
    }
    return $user;
}

/**
 * Guard for /api pages.
 *
 * Never redirects: an API client handed a 302 to an HTML login form has no idea
 * what happened, and the relative path would be wrong from api/ anyway.
 */
function t_api_require_auth(): string
{
    if (!t_users_exist()) {
        t_err('No users configured. Open the web UI to create the first account.', 'setup_required', 503);
    }
    $user = t_current_user();
    if ($user === null) {
        t_err('Authentication required.', 'unauthorized', 401);
    }
    return $user;
}

// ---------------------------------------------------------------- csrf

function t_csrf_token(): string
{
    t_session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf'];
}

/** Call at the top of every POST handler. Without it, a user-management UI is a liability. */
function t_csrf_check(): void
{
    t_session_start();
    $given    = (string) ($_POST['csrf'] ?? '');
    $expected = (string) ($_SESSION['csrf'] ?? '');
    if ($expected === '' || !hash_equals($expected, $given)) {
        t_text("Bad or missing CSRF token.\n", 403);
    }
}
