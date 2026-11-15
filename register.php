<?php
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    redirect(isAdmin() ? 'admin_dashboard.php' : 'user_dashboard.php');
}

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid session token. Please refresh and try again.';
    } else {
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if ($fullName === '' || $email === '' || $phone === '' || $password === '') {
            $error = 'Please fill in all required fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (passwordPolicyMessage($password) !== '') {
            $error = passwordPolicyMessage($password);
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $db = getDB();
            $check = $db->prepare('SELECT id FROM users WHERE email = ?');
            $check->execute([$email]);
            if ($check->fetch()) {
                $error = 'An account with this email already exists.';
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO users (full_name, email, phone, password_hash, role) VALUES (?, ?, ?, ?, ?)'
                );
                $stmt->execute([$fullName, $email, $phone, password_hash($password, PASSWORD_BCRYPT), 'user']);
                logActivity((int) $db->lastInsertId(), 'New user registered');
                $success = true;
            }
        }
    }
}

$pageTitle = 'Sign Up';
include __DIR__ . '/includes/header.php';
?>
<div class="container">
  <div class="card auth-card">
    <h2>Create Your Account</h2>
    <?php if ($success): ?>
      <div class="alert alert-success">Account created successfully! You can now <a href="login.php">log in</a>.</div>
    <?php else: ?>
      <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
      <form method="POST" action="register.php">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <div class="form-group">
          <label class="required">Full Name</label>
          <input type="text" name="full_name" required value="<?= h($_POST['full_name'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="required">Email Address</label>
          <input type="email" name="email" required value="<?= h($_POST['email'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="required">Phone Number</label>
          <input type="tel" name="phone" required value="<?= h($_POST['phone'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="required" for="password">Password</label>
          <input type="password" id="password" name="password" required minlength="8" maxlength="72"
                 autocomplete="new-password" data-password-policy>
        </div>
        <div class="form-group">
          <label class="required" for="confirm_password">Confirm Password</label>
          <input type="password" id="confirm_password" name="confirm_password" required maxlength="72"
                 autocomplete="off" data-confirm-for="password">
        </div>
        <button type="submit" class="btn btn-primary btn-block">Create Account</button>
      </form>
      <p class="mt-1 text-muted">Already have an account? <a href="login.php">Log in</a>.</p>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
