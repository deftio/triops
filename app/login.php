<?php
/**
 * Login. Plain HTML form on purpose — you should never be locked out of a
 * debug tool because a script failed to load.
 */
require __DIR__ . '/lib/triops.php';

if (!t_users_exist()) {
    header('Location: ./setup.php');
    exit;
}

$error = '';
$next  = (string) ($_GET['next'] ?? './index.php');
// Only ever redirect somewhere local.
if (!preg_match('#^[./][A-Za-z0-9_./?=&-]*$#', $next) || strpos($next, '//') !== false) {
    $next = './index.php';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $user = trim((string) ($_POST['username'] ?? ''));
    $pass = (string) ($_POST['password'] ?? '');

    if (t_user_verify($user, $pass)) {
        t_login($user);
        header('Location: ' . $next);
        exit;
    }
    $error = 'Wrong username or password.';
}

t_page_open('Log in', false);
?>
<div class="bw_bccl_card" style="max-width:26rem;margin:3rem auto;padding:1.5rem">
  <img src="./assets/triops-logo.png" alt="triops" style="height:56px;margin-bottom:1rem">
  <?php if ($error !== ''): ?>
    <p style="color:#c0392b"><strong><?= t_e($error) ?></strong></p>
  <?php endif; ?>
  <form method="post" action="./login.php?next=<?= t_e(urlencode($next)) ?>">
    <p><label>Username<br><input class="bw_bccl_form_control" type="text" name="username" autofocus required></label></p>
    <p><label>Password<br><input class="bw_bccl_form_control" type="password" name="password" required></label></p>
    <p><button class="bw_bccl_btn bw_primary" type="submit">Log in</button></p>
  </form>
  <p style="font-size:0.85rem;opacity:0.7">
    Locked out? Delete <code>app/data/users.json</code> and reload — triops will
    ask you to create a new account.
  </p>
</div>
<?php
t_page_close(false);
t_page_end();
