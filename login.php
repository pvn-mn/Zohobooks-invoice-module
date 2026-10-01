<?php
/**
 * Single-user login (no registration). Username and password hash live in config.php.
 */
require_once __DIR__ . '/bootstrap.php';

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}
if (is_logged_in()) {
    header('Location: invoices.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $user = trim($_POST['username'] ?? '');
    $pass = (string)($_POST['password'] ?? '');
    if (!defined('APP_USERNAME') || !defined('APP_PASSWORD_HASH')) {
        $error = 'Login is not set up yet: add APP_USERNAME and APP_PASSWORD_HASH to config.php.';
    } elseif (hash_equals(APP_USERNAME, $user) && password_verify($pass, APP_PASSWORD_HASH)) {
        session_regenerate_id(true);
        $_SESSION['user'] = $user;
        header('Location: invoices.php');
        exit;
    } else {
        usleep(500000); // slow down guessing
        $error = 'Wrong username or password.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Log in</title>
<style>
  body { font: 15px/1.5 system-ui, sans-serif; background: #f3f4f6; margin: 0; display: grid; place-items: center; min-height: 100vh; }
  form { background: #fff; padding: 2rem; border-radius: 10px; box-shadow: 0 2px 12px rgba(0,0,0,.08); width: min(340px, 90vw); display: grid; gap: .9rem; }
  h1 { font-size: 1.25rem; margin: 0; }
  label { display: grid; gap: .25rem; font-size: .85rem; }
  input { font: inherit; padding: .5rem; border: 1px solid #ccc; border-radius: 6px; }
  button { font: inherit; padding: .6rem; border: 0; border-radius: 6px; background: #2563eb; color: #fff; cursor: pointer; }
  .err { background: #fee2e2; padding: .5rem .75rem; border-radius: 6px; font-size: .9rem; }
</style>
</head>
<body>
  <form method="post">
    <h1>Tax Invoices</h1>
    <?php if ($error): ?><div class="err"><?= h($error) ?></div><?php endif; ?>
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <label>Username<input name="username" autocomplete="username" required autofocus></label>
    <label>Password<input name="password" type="password" autocomplete="current-password" required></label>
    <button type="submit">Log in</button>
  </form>
</body>
</html>
