<?php
/**
 * api/set_theme.php
 * Saves the theme picked from the profile menu in the site header.
 *
 * Writes users.theme, refreshes the session copy so the next page render picks
 * it up, and re-stamps the lf_theme cookie so signed-out pages on this device
 * match too. The profile Settings form saves the same column; this endpoint
 * just avoids a full page submit for a one-click change.
 *
 * Note: no output may be sent before rememberThemeCookie() runs, since
 * setcookie() needs the headers to still be open.
 */

require_once __DIR__ . '/../includes/auth.php';
requireLoginJson();

$input = json_decode(file_get_contents('php://input'), true) ?? [];
if (!verifyCsrf($input['csrf_token'] ?? '')) {
    header('Content-Type: application/json');
    jsonResponse(['success' => false, 'message' => 'Invalid session token.'], 403);
}

$requested = $input['theme'] ?? '';
if (!in_array($requested, allowedThemes(), true)) {
    header('Content-Type: application/json');
    jsonResponse(['success' => false, 'message' => 'Unknown theme.'], 400);
}

$userId = (int) currentUser()['id'];

try {
    getDB()->prepare('UPDATE users SET theme = ? WHERE id = ?')->execute([$requested, $userId]);
} catch (Throwable $e) {
    error_log('Failed to save theme: ' . $e->getMessage());
    header('Content-Type: application/json');
    jsonResponse(['success' => false, 'message' => 'Could not save your theme preference.'], 500);
}

$_SESSION['user']['theme'] = $requested;
rememberThemeCookie($requested);

header('Content-Type: application/json');
jsonResponse(['success' => true, 'theme' => $requested]);
