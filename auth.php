<?php
require_once __DIR__ . '/config.php';

if (isset($_SESSION['user_id']) && $_SERVER['REQUEST_METHOD'] !== 'POST') redirect('dashboard.php');

$errors = [];
$mode   = $_GET['mode'] ?? 'login';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'logout') { $_SESSION = []; session_destroy(); redirect('auth.php'); }

    if (!csrf_verify($_POST['csrf_token'] ?? '')) $errors[] = 'Session expired.';

    if (empty($errors)) {
        if ($action === 'signup') {
            $name = trim($_POST['name'] ?? ''); $email = strtolower(trim($_POST['email'] ?? ''));
            $pass = $_POST['password'] ?? ''; $confirm = $_POST['confirm'] ?? '';
            if (!$name)                                 $errors[] = 'Name is required.';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email.';
            if (strlen($pass) < 6)                      $errors[] = 'Password must be at least 6 characters.';
            if ($pass !== $confirm)                     $errors[] = 'Passwords do not match.';
            if (empty($errors)) {
                try {
                    $db = get_db();
                    $chk = $db->prepare('SELECT id FROM users WHERE email=?'); $chk->execute([$email]);
                    if ($chk->fetch()) { $errors[] = 'Email already registered.'; }
                    else {
                        $hash = password_hash($pass, PASSWORD_BCRYPT);
                        $ins  = $db->prepare('INSERT INTO users (name,email,password) VALUES (?,?,?)');
                        $ins->execute([$name, $email, $hash]);
                        $uid = (int)$db->lastInsertId();
                        // Init streak row
                        $db->prepare('INSERT IGNORE INTO streaks (user_id) VALUES (?)')->execute([$uid]);
                        $_SESSION['user_id'] = $uid; $_SESSION['user_name'] = $name;
                        redirect('dashboard.php');
                    }
                } catch (PDOException $e) { $errors[] = 'Database error.'; }
            }
            $mode = 'signup';
        } elseif ($action === 'login') {
            $email = strtolower(trim($_POST['email'] ?? '')); $pass = $_POST['password'] ?? '';
            if (!$email) $errors[] = 'Email required.';
            if (!$pass)  $errors[] = 'Password required.';
            if (empty($errors)) {
                try {
                    $db = get_db();
                    $s = $db->prepare('SELECT id,name,password FROM users WHERE email=?'); $s->execute([$email]);
                    $u = $s->fetch();
                    if (!$u || !password_verify($pass, $u['password'])) { $errors[] = 'Invalid credentials.'; }
                    else {
                        $db->prepare('INSERT IGNORE INTO streaks (user_id) VALUES (?)')->execute([(int)$u['id']]);
                        $_SESSION['user_id'] = (int)$u['id']; $_SESSION['user_name'] = $u['name'];
                        redirect('dashboard.php');
                    }
                } catch (PDOException $e) { $errors[] = 'Database error.'; }
            }
            $mode = 'login';
        }
    }
}
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>FocusTrack — Sign In</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="app.css" />
</head>
<body class="auth-body">
  <div class="auth-canvas">
    <div class="auth-glow glow-a"></div>
    <div class="auth-glow glow-b"></div>
    <div class="auth-glow glow-c"></div>
  </div>

  <a href="index.html" class="auth-back">← Home</a>

  <div class="auth-shell">
    <div class="auth-brand-mark">
      <span class="logomark">◎</span>
      <span class="logoname">FocusTrack</span>
    </div>

    <div class="auth-glass">
      <div class="auth-tabs">
        <a href="auth.php?mode=login"  class="atab <?= $mode==='login'  ? 'atab--on':'' ?>">Sign in</a>
        <a href="auth.php?mode=signup" class="atab <?= $mode==='signup' ? 'atab--on':'' ?>">Create account</a>
      </div>

      <?php if (!empty($errors)): ?>
        <div class="auth-err">
          <?php foreach($errors as $e): ?><p><?= htmlspecialchars($e) ?></p><?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($mode === 'login'): ?>
      <form method="POST" action="auth.php" class="aform" novalidate>
        <input type="hidden" name="action"     value="login" />
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>" />
        <div class="afield">
          <label>Email</label>
          <input type="email" name="email" placeholder="you@example.com" value="<?= htmlspecialchars($_POST['email']??'') ?>" autocomplete="email" required />
        </div>
        <div class="afield">
          <label>Password</label>
          <input type="password" name="password" placeholder="••••••••" autocomplete="current-password" required />
        </div>
        <button type="submit" class="abtn">Sign in →</button>
      </form>
      <?php else: ?>
      <form method="POST" action="auth.php?mode=signup" class="aform" novalidate>
        <input type="hidden" name="action"     value="signup" />
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>" />
        <div class="afield">
          <label>Full name</label>
          <input type="text" name="name" placeholder="Your name" value="<?= htmlspecialchars($_POST['name']??'') ?>" autocomplete="name" required />
        </div>
        <div class="afield">
          <label>Email</label>
          <input type="email" name="email" placeholder="you@example.com" value="<?= htmlspecialchars($_POST['email']??'') ?>" autocomplete="email" required />
        </div>
        <div class="afield">
          <label>Password</label>
          <input type="password" name="password" placeholder="Min. 6 characters" autocomplete="new-password" required />
        </div>
        <div class="afield">
          <label>Confirm password</label>
          <input type="password" name="confirm" placeholder="Repeat password" autocomplete="new-password" required />
        </div>
        <button type="submit" class="abtn">Create account →</button>
      </form>
      <?php endif; ?>
      <p class="auth-sub">Free forever · No credit card · No spam</p>
    </div>
  </div>
</body>
</html>
