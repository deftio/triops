<?php
/**
 * triops page shell.
 *
 * The UI is bitwrench (https://github.com/deftio/bitwrench). PHP emits a thin
 * skeleton and hands data to the page as JSON; bitwrench builds the chrome and
 * every dynamic region from TACO objects. There is no hand-written CSS in
 * triops — bw.loadStyles() derives the whole palette, dark mode included, from
 * the two seed colors in config.
 *
 * Login and setup are the exception: they emit real HTML forms so you can still
 * get in when JavaScript fails. Being locked out of a debug tool by a script
 * error is a bad joke.
 */

defined('TRIOPS') or die('Direct access not permitted');

function t_page_open(string $title, bool $chrome = true): void
{
    header('Content-Type: text/html; charset=utf-8');
    $site = t_e((string) t_config('site_name', 'triops'));
    $full = $title === '' ? $site : t_e($title) . ' — ' . $site;
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $full ?></title>
<link rel="icon" type="image/x-icon" href="./assets/favicon.ico">
<script src="./assets/bitwrench.umd.min.js"></script>
<script src="./assets/triops.js"></script>
</head>
<body>
<div id="triops-nav"></div>
<main class="bw_container" id="triops-main">
<?php
    if ($chrome && $title !== '') {
        echo '<h1>' . t_e($title) . "</h1>\n";
    }
}

function t_page_close(bool $chrome = true, array $pageData = []): void
{
    $cfg = [
        'site'    => (string) t_config('site_name', 'triops'),
        'theme'   => (array) t_config('theme', []),
        'chrome'  => $chrome,
        'user'    => t_current_user(),
        'version' => TRIOPS_VERSION,
    ];
    ?>
</main>
<script>
triops.boot(<?= json_encode($cfg, JSON_UNESCAPED_SLASHES) ?>);
window.TRIOPS_DATA = <?= json_encode($pageData, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
</script>
<?php
}

/** Emit the closing tags after any page-specific script has run. */
function t_page_end(): void
{
    echo "</body>\n</html>\n";
}
