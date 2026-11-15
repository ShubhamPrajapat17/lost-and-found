<?php
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    redirect(isAdmin() ? 'admin_dashboard.php' : 'user_dashboard.php');
}

$error = '';
// Set by enforceActiveAccount() when a signed-in user was blocked mid-session.
$notice = $_SESSION['login_notice'] ?? '';
unset($_SESSION['login_notice']);
$roleTab = ($_GET['role'] ?? '') === 'admin' ? 'admin' : 'user';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid session token. Please refresh and try again.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'user';
        $roleTab = $role;

        if ($email === '' || $password === '') {
            $error = 'Please fill in all fields.';
        } else {
            $result = attemptLogin($email, $password, $role);
            if ($result['success']) {
                redirect($role === 'admin' ? 'admin_dashboard.php' : 'user_dashboard.php');
            } else {
                $error = $result['message'];
            }
        }
    }
}

$pageTitle = 'Login';
include __DIR__ . '/includes/header.php';
?>
<div class="container">
  <div class="card auth-card">
    <div class="chip-group mb-1" role="tablist">
      <button type="button" class="chip <?= $roleTab === 'user' ? 'active' : '' ?>" data-role-tab="user">User Login</button>
      <button type="button" class="chip <?= $roleTab === 'admin' ? 'active' : '' ?>" data-role-tab="admin">Admin Login</button>
    </div>
    <h2 id="loginHeading"><?= $roleTab === 'admin' ? 'Admin Login' : 'User Login' ?></h2>

    <?php if ($notice): ?><div class="alert alert-error"><?= h($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

    <form method="POST" action="login.php">
      <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
      <input type="hidden" name="role" id="roleField" value="<?= h($roleTab) ?>">
      <div class="form-group">
        <label class="required">Email Address</label>
        <input type="email" name="email" required autofocus>
      </div>
      <div class="form-group">
        <label class="required">Password</label>
        <input type="password" name="password" required>
      </div>
      <button type="submit" class="btn btn-primary btn-block">Login</button>
    </form>
    <p class="mt-1 text-muted">Don't have an account? <a href="register.php">Sign up here</a>.</p>
    <p class="text-muted">Trouble logging in or blocked by mistake? <a href="contact.php?category=account">Contact the admin</a>.</p>
    <p class="text-muted" style="font-size:0.8rem;">Demo admin: admin@lostfound.com / Admin@123</p>
  </div>
</div>

<script>
document.querySelectorAll('[data-role-tab]').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('[data-role-tab]').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    const role = btn.dataset.roleTab;
    document.getElementById('roleField').value = role;
    document.getElementById('loginHeading').textContent = role === 'admin' ? 'Admin Login' : 'User Login';
  });
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
