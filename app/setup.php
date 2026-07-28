<?php
/**
 * First-run setup: create the first account.
 *
 * triops ships with no credentials. Shipping a default password is how 0.1
 * ended up with admin/triops committed to git, and a config-file user would
 * force a plaintext password into config.php — same problem, new hat.
 *
 * Plain HTML form, no JavaScript required.
 */
require __DIR__ . '/lib/triops.php';

// Once an account exists this page is closed for good.
if (t_users_exist()) {
    header('Location: ./login.php');
    exit;
}

$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $user = (string) ($_POST['username'] ?? '');
    $pass = (string) ($_POST['password'] ?? '');
    $again = (string) ($_POST['password2'] ?? '');

    if (trim($user) === '') {
        $error = 'Pick a username.';
    } elseif (strlen($pass) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($pass !== $again) {
        $error = 'Those passwords do not match.';
    } elseif (!is_writable(t_data_dir())) {
        $error = 'The data directory is not writable: ' . t_e(t_data_dir());
    } elseif (!t_user_add($user, $pass)) {
        $error = 'Could not create the account. Check that the data directory is writable.';
    } else {
        t_login(trim($user));
        header('Location: ./index.php');
        exit;
    }
}

t_page_open('Set up triops', false);
?>
<div class="bw_bccl_card" style="max-width:32rem;margin:3rem auto;padding:1.5rem">
  <img src="./assets/triops-logo.png" alt="triops" style="height:56px;margin-bottom:1rem">
  <h2>Create your account</h2>
  <p>triops has no users yet. This first account is yours; you can add more later.</p>
  <?php if ($error !== ''): ?>
    <p style="color:#c0392b"><strong><?= t_e($error) ?></strong></p>
  <?php endif; ?>
  <form method="post" action="./setup.php">
    <p><label>Username<br><input class="bw_bccl_form_control" type="text" name="username"
       value="<?= t_e((string) ($_POST['username'] ?? '')) ?>" autofocus required></label></p>
    <p><label>Password <small>(8 characters or more)</small><br>
       <input class="bw_bccl_form_control" type="password" name="password" required></label></p>
    <p><label>Password again<br>
       <input class="bw_bccl_form_control" type="password" name="password2" required></label></p>
    <p><button class="bw_bccl_btn bw_primary" type="submit">Create account</button></p>
  </form>
</div>
<?php
t_page_close(false);
t_page_end();
