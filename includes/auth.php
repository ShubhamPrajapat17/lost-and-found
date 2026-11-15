<?php
/**
 * includes/auth.php
 * Authentication helpers: session guards and role checks.
 */

require_once __DIR__ . '/functions.php';

function currentUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

function isLoggedIn(): bool
{
    return isset($_SESSION['user']);
}

function isAdmin(): bool
{
    return isLoggedIn() && $_SESSION['user']['role'] === 'admin';
}

/**
 * Re-check the logged-in account against the database on every request.
 *
 * Without this, blocking a user only stopped them from *logging in again* -
 * anyone already signed in kept full access until their session expired.
 * Now a blocked (or deleted) account is signed out on its very next click,
 * and sees a notice explaining why on the login page.
 */
function enforceActiveAccount(): void
{
    static $checked = false;
    if ($checked || !isset($_SESSION['user']['id'])) {
        return;
    }
    $checked = true;

    try {
        $stmt = getDB()->prepare('SELECT status, blocked_reason, role FROM users WHERE id = ?');
        $stmt->execute([(int) $_SESSION['user']['id']]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        error_log('Account status check failed: ' . $e->getMessage());
        return; // fail open on a DB hiccup rather than log everyone out
    }

    if (!$row) {
        unset($_SESSION['user']);
        $_SESSION['login_notice'] = 'Your account no longer exists.';
        return;
    }
    if ($row['status'] !== 'active') {
        unset($_SESSION['user']);
        $_SESSION['login_notice'] = blockedAccountMessage($row['blocked_reason'] ?? null);
        return;
    }
    // Keep the role in step with the database (e.g. an admin was demoted).
    $_SESSION['user']['role'] = $row['role'];
}

function blockedAccountMessage(?string $reason): string
{
    $msg = 'This account has been blocked by an administrator.';
    if ($reason !== null && trim($reason) !== '') {
        $msg .= ' Reason: ' . rtrim(trim($reason), '.!?') . '.';
    }
    return $msg . ' If you think this is a mistake, please use the Contact page to reach the admin.';
}

enforceActiveAccount();

/**
 * The theme to render the current page with.
 * Logged-in visitors get their saved profile preference; guests fall back to
 * the cookie written when a preference was last saved on this device.
 */
function currentTheme(): string
{
    $user = currentUser();

    // Sessions created before the theme column existed have no theme key.
    // Backfill it once rather than falling back for the rest of the session.
    if ($user && !array_key_exists('theme', $user)) {
        try {
            $stmt = getDB()->prepare('SELECT theme FROM users WHERE id = ?');
            $stmt->execute([$user['id']]);
            $_SESSION['user']['theme'] = normalizeTheme($stmt->fetchColumn() ?: null);
        } catch (Throwable $e) {
            $_SESSION['user']['theme'] = 'default';
        }
        $user = currentUser();
    }

    if ($user && !empty($user['theme'])) {
        return normalizeTheme($user['theme']);
    }
    return normalizeTheme($_COOKIE['lf_theme'] ?? null);
}

/** Persist the theme for one year on this device so guest pages match too. */
function rememberThemeCookie(string $theme): void
{
    if (headers_sent()) {
        return;
    }
    setcookie('lf_theme', normalizeTheme($theme), [
        'expires' => time() + 31536000,
        'path' => '/',
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
}

/**
 * Guard for JSON endpoints. Unlike requireLogin() this returns a JSON 401
 * instead of redirecting to an HTML login page, which would otherwise make
 * the caller's JSON.parse() fail with a confusing "Unexpected server response".
 */
function requireLoginJson(): void
{
    if (!isLoggedIn()) {
        jsonResponse(['success' => false, 'message' => 'Please log in to continue.'], 401);
    }
}

function requireAdminJson(): void
{
    requireLoginJson();
    if (!isAdmin()) {
        jsonResponse(['success' => false, 'message' => 'Administrator access required.'], 403);
    }
}

/** Require the visitor to be logged in, otherwise redirect to login page. */
function requireLogin(): void
{
    if (!isLoggedIn()) {
        redirect('login.php');
    }
}

/** Require the visitor to be an admin, otherwise redirect away. */
function requireAdmin(): void
{
    requireLogin();
    if (!isAdmin()) {
        redirect('user_dashboard.php');
    }
}

/** Attempt to log a user in. Returns ['success' => bool, 'message' => string]. */
function attemptLogin(string $email, string $password, string $expectedRole): array
{
    $stmt = getDB()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return ['success' => false, 'message' => 'Invalid email or password.'];
    }
    if ($user['status'] !== 'active') {
        return ['success' => false, 'message' => blockedAccountMessage($user['blocked_reason'] ?? null)];
    }
    if ($user['role'] !== $expectedRole) {
        return ['success' => false, 'message' => 'Please use the correct login form for your account type.'];
    }

    // New session id on every login (prevents session fixation).
    session_regenerate_id(true);

    // If this account has no saved theme yet but the visitor already picked one
    // on this device before logging in, keep their choice.
    $theme = normalizeTheme($user['theme'] ?? 'default');
    $cookieTheme = normalizeTheme($_COOKIE['lf_theme'] ?? null);
    if ($theme === 'default' && $cookieTheme !== 'default') {
        $theme = $cookieTheme;
        try {
            getDB()->prepare('UPDATE users SET theme = ? WHERE id = ?')->execute([$theme, $user['id']]);
        } catch (Throwable $e) {
            error_log('Could not save theme at login: ' . $e->getMessage());
        }
    }

    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'full_name' => $user['full_name'],
        'email' => $user['email'],
        'phone' => $user['phone'],
        'role' => $user['role'],
        'profile_image' => $user['profile_image'],
        'theme' => $theme,
    ];
    rememberThemeCookie($theme);
    logActivity((int) $user['id'], 'Logged in');
    return ['success' => true];
}
