<?php
/**
 * User management.
 *
 * No roles: anyone logged in can see the data and manage accounts. A hardware
 * debug tool on a lab network does not need a permission matrix, and every role
 * check is somewhere for a bug to hide. Add roles when someone actually asks.
 */
require __DIR__ . '/lib/triops.php';

$me      = t_require_auth();
$notice  = '';
$problem = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    t_csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add') {
        $name = trim((string) ($_POST['username'] ?? ''));
        $pass = (string) ($_POST['password'] ?? '');
        if (strlen($pass) < 8) {
            $problem = 'Password must be at least 8 characters.';
        } elseif (!t_user_add($name, $pass)) {
            $problem = 'Could not add "' . $name . '" — check the name is not already taken.';
        } else {
            $notice = 'Added ' . $name . '.';
        }
    } elseif ($action === 'delete') {
        $name = (string) ($_POST['username'] ?? '');
        if ($name === $me) {
            $problem = 'You cannot delete the account you are logged in as.';
        } elseif (!t_user_delete($name)) {
            $problem = 'Could not delete "' . $name . '". triops keeps at least one account.';
        } else {
            $notice = 'Deleted ' . $name . '.';
        }
    } elseif ($action === 'password') {
        $name = (string) ($_POST['username'] ?? '');
        $pass = (string) ($_POST['password'] ?? '');
        if (!t_user_set_password($name, $pass)) {
            $problem = 'Could not change the password (8 characters minimum).';
        } else {
            $notice = 'Password changed for ' . $name . '.';
        }
    }
}

$users = t_users_load();

t_page_open('Users');
?>
<?php if ($notice !== ''): ?>
  <p class="bw_bccl_card" style="padding:0.7rem"><strong><?= t_e($notice) ?></strong></p>
<?php endif; ?>
<?php if ($problem !== ''): ?>
  <p class="bw_bccl_card" style="padding:0.7rem;color:#c0392b"><strong><?= t_e($problem) ?></strong></p>
<?php endif; ?>

<div class="bw_bccl_card" style="padding:1rem;margin-bottom:1.5rem;max-width:44rem">
  <h3>Accounts</h3>
  <table style="width:100%">
    <tbody>
    <?php foreach ($users as $name => $meta): ?>
      <tr>
        <td style="padding:0.4rem 0;font-family:monospace"><?= t_e((string) $name) ?>
          <?php if ($name === $me): ?><small style="opacity:0.6">(you)</small><?php endif; ?>
        </td>
        <td style="padding:0.4rem 0;opacity:0.7;font-size:0.85rem">
          added <?= t_e(date('Y-m-d', (int) ($meta['created'] ?? 0))) ?>
        </td>
        <td style="padding:0.4rem 0;text-align:right">
          <?php if ($name !== $me && count($users) > 1): ?>
          <form method="post" action="./users.php" style="display:inline"
                onsubmit="return confirm('Delete <?= t_e((string) $name) ?>?')">
            <input type="hidden" name="csrf" value="<?= t_e(t_csrf_token()) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="username" value="<?= t_e((string) $name) ?>">
            <button class="bw_bccl_btn bw_danger" type="submit">Delete</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="bw_bccl_card" style="padding:1rem;margin-bottom:1.5rem;max-width:44rem">
  <h3>Add an account</h3>
  <form method="post" action="./users.php">
    <input type="hidden" name="csrf" value="<?= t_e(t_csrf_token()) ?>">
    <input type="hidden" name="action" value="add">
    <p><label>Username<br><input class="bw_bccl_form_control" type="text" name="username" required></label></p>
    <p><label>Password <small>(8 characters or more)</small><br>
       <input class="bw_bccl_form_control" type="password" name="password" required></label></p>
    <p><button class="bw_bccl_btn bw_primary" type="submit">Add</button></p>
  </form>
</div>

<div class="bw_bccl_card" style="padding:1rem;max-width:44rem">
  <h3>Change your password</h3>
  <form method="post" action="./users.php">
    <input type="hidden" name="csrf" value="<?= t_e(t_csrf_token()) ?>">
    <input type="hidden" name="action" value="password">
    <input type="hidden" name="username" value="<?= t_e($me) ?>">
    <p><label>New password<br>
       <input class="bw_bccl_form_control" type="password" name="password" required></label></p>
    <p><button class="bw_bccl_btn bw_primary" type="submit">Change</button></p>
  </form>
</div>
<?php
t_page_close();
t_page_end();
