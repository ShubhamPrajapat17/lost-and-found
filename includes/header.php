<?php
/**
 * includes/header.php
 * Shared page header / navigation bar.
 * Expects optional $pageTitle to be set before include.
 */
require_once __DIR__ . '/auth.php';
$user = currentUser();
$pageTitle = $pageTitle ?? APP_NAME;
$theme = currentTheme();
$themeOptions = [
    'default' => ['🖥️', 'System default'],
    'light'   => ['☀️', 'Light'],
    'dark'    => ['🌙', 'Dark'],
];
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= h($theme) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="light dark">
<title><?= h($pageTitle) ?> · <?= h(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= h(asset('css/style.css')) ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🔎</text></svg>">
</head>
<body>
<header class="site-header">
  <div class="container nav-wrap">
    <a href="index.php" class="brand">🔎 <?= h(APP_NAME) ?></a>
    <button class="nav-toggle" id="navToggle" aria-label="Toggle navigation">☰</button>
    <nav class="site-nav" id="siteNav">
      <a href="index.php">Home</a>
      <a href="lost_items.php">Lost Items</a>
      <a href="found_items.php">Found Items</a>
      <a href="about.php">About / FAQ</a>
      <a href="contact.php">Contact</a>
      <div class="profile-menu" id="themeMenu">
        <button type="button" class="profile-trigger" id="themeTrigger" aria-haspopup="true" aria-expanded="false"
                aria-controls="themeDropdown" title="Change theme">
          <span id="themeIcon" aria-hidden="true"><?= h($themeOptions[$theme][0]) ?></span>
          <span class="profile-name">Theme</span>
          <span class="profile-caret" aria-hidden="true">▾</span>
        </button>
        <div class="profile-dropdown" id="themeDropdown" role="menu" hidden>
          <div class="profile-section">
            <span class="profile-section-label">Theme</span>
            <?php foreach ($themeOptions as $value => [$icon, $label]): ?>
              <button type="button" class="profile-item theme-option<?= $theme === $value ? ' active' : '' ?>"
                      role="menuitemradio" aria-checked="<?= $theme === $value ? 'true' : 'false' ?>"
                      data-theme-value="<?= h($value) ?>" data-theme-icon="<?= h($icon) ?>">
                <span aria-hidden="true"><?= $icon ?></span>
                <span><?= h($label) ?></span>
                <span class="profile-check" aria-hidden="true">✓</span>
              </button>
            <?php endforeach; ?>
            <p class="profile-hint" id="themeStatus" role="status"></p>
          </div>
        </div>
      </div>
      <?php if ($user): ?>
        <?php
          $dashboardUrl = $user['role'] === 'admin' ? 'admin_dashboard.php' : 'user_dashboard.php';
        ?>
        <a href="<?= $dashboardUrl ?>" class="nav-cta"><?= $user['role'] === 'admin' ? 'Admin Dashboard' : 'My Dashboard' ?></a>

        <div class="profile-menu" id="profileMenu">
          <button type="button" class="profile-trigger" id="profileTrigger"
                  aria-haspopup="true" aria-expanded="false" aria-controls="profileDropdown">
            <span class="profile-avatar" aria-hidden="true"><?= h(strtoupper(substr(trim($user['full_name']), 0, 1))) ?></span>
            <span class="profile-name"><?= h($user['full_name']) ?></span>
            <span class="profile-caret" aria-hidden="true">▾</span>
          </button>

          <div class="profile-dropdown" id="profileDropdown" role="menu" hidden>
            <div class="profile-head">
              <strong><?= h($user['full_name']) ?></strong>
              <span class="text-muted"><?= h($user['email']) ?></span>
              <span class="badge badge-<?= $user['role'] === 'admin' ? 'claimed' : 'active' ?>"><?= ucfirst($user['role']) ?></span>
            </div>

            <div class="profile-section">
              <a class="profile-item" role="menuitem" href="<?= $dashboardUrl ?>#settings">⚙️ <span>Profile settings</span></a>
              <a class="profile-item" role="menuitem" href="logout.php">🚪 <span>Logout</span></a>
            </div>
          </div>
        </div>
      <?php else: ?>
        <a href="login.php">Login</a>
        <a href="register.php" class="nav-cta">Sign Up</a>
      <?php endif; ?>
    </nav>
  </div>
</header>
<script>
  window.CSRF_TOKEN = <?= json_encode(csrfToken()) ?>;
  window.IS_LOGGED_IN = <?= json_encode(isLoggedIn()) ?>;
  window.IS_ADMIN = <?= json_encode(isAdmin()) ?>;
  window.THEME = <?= json_encode($theme) ?>;
</script>
<main>
