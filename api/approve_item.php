<?php
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
requireAdminJson();

$input = json_decode(file_get_contents('php://input'), true) ?? [];
if (!verifyCsrf($input['csrf_token'] ?? '')) {
    jsonResponse(['success' => false, 'message' => 'Invalid session token.'], 403);
}

$itemId = (int) ($input['item_id'] ?? 0);
$action = $input['action'] ?? ''; // 'approve' | 'reject'

if (!$itemId || !in_array($action, ['approve', 'reject'], true)) {
    jsonResponse(['success' => false, 'message' => 'Invalid request.'], 400);
}

$db = getDB();
if ($action === 'approve') {
    $stmt = $db->prepare('UPDATE items SET is_approved = 1, status = "active" WHERE id = ?');
} else {
    $stmt = $db->prepare('UPDATE items SET is_approved = 0, status = "rejected" WHERE id = ?');
}
$stmt->execute([$itemId]);

logActivity(currentUser()['id'], ucfirst($action) . "d item #$itemId", 'item', $itemId);

jsonResponse(['success' => true]);
