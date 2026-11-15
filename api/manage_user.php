<?php
/**
 * api/manage_user.php  (admin only)
 * Block / unblock / delete a user account.
 *
 * Body (JSON): { "user_id": 5, "action": "block"|"unblock"|"delete",
 *                "reason": "optional text, shown to the user when they try to log in",
 *                "csrf_token": "..." }
 *
 * Blocking takes effect immediately: the user is signed out on their next
 * request (see enforceActiveAccount() in includes/auth.php), cannot log in
 * again, and their listings disappear from the public pages. Unblocking
 * restores everything. Admin accounts can never be blocked or deleted here.
 */
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
requireAdminJson();

$input = json_decode(file_get_contents('php://input'), true) ?? [];
if (!verifyCsrf($input['csrf_token'] ?? '')) {
    jsonResponse(['success' => false, 'message' => 'Invalid session token.'], 403);
}

$userId = (int) ($input['user_id'] ?? 0);
$action = $input['action'] ?? '';
// Accept the old names too so nothing that still sends them breaks.
$action = ['suspend' => 'block', 'activate' => 'unblock'][$action] ?? $action;
$reason = trim((string) ($input['reason'] ?? ''));

if (!$userId || !in_array($action, ['block', 'unblock', 'delete'], true)) {
    jsonResponse(['success' => false, 'message' => 'Invalid request.'], 400);
}
if ($userId === (int) currentUser()['id']) {
    jsonResponse(['success' => false, 'message' => 'You cannot modify your own admin account here.'], 400);
}
if (mb_strlen($reason) > 255) {
    jsonResponse(['success' => false, 'message' => 'The reason must be 255 characters or fewer.'], 400);
}

$db = getDB();
$stmt = $db->prepare('SELECT id, full_name, email, role FROM users WHERE id = ?');
$stmt->execute([$userId]);
$target = $stmt->fetch();

if (!$target) {
    jsonResponse(['success' => false, 'message' => 'User not found.'], 404);
}
if ($target['role'] === 'admin') {
    jsonResponse(['success' => false, 'message' => 'Admin accounts cannot be blocked or deleted from here.'], 403);
}

$label = $target['full_name'] . ' (#' . $userId . ')';

if ($action === 'delete') {
    // Remove their uploaded files too; the database rows go via ON DELETE CASCADE.
    $ids = $db->prepare('SELECT id FROM items WHERE user_id = ?');
    $ids->execute([$userId]);
    deleteItemsByIds($db, array_column($ids->fetchAll(), 'id'));
    $db->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
    logActivity(currentUser()['id'], 'Deleted user ' . $label);
    jsonResponse(['success' => true, 'message' => 'User deleted.']);
}

if ($action === 'block') {
    $db->prepare('UPDATE users SET status = "suspended", blocked_reason = ?, blocked_at = NOW() WHERE id = ?')
       ->execute([$reason !== '' ? $reason : null, $userId]);
    logActivity(currentUser()['id'], 'Blocked user ' . $label . ($reason !== '' ? ' - ' . $reason : ''));
    jsonResponse(['success' => true, 'message' => 'User blocked. They are signed out and cannot log in.']);
}

$db->prepare('UPDATE users SET status = "active", blocked_reason = NULL, blocked_at = NULL WHERE id = ?')
   ->execute([$userId]);
logActivity(currentUser()['id'], 'Unblocked user ' . $label);
jsonResponse(['success' => true, 'message' => 'User unblocked.']);
