<?php
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
requireLoginJson();

$input = json_decode(file_get_contents('php://input'), true) ?? [];
if (!verifyCsrf($input['csrf_token'] ?? '')) {
    jsonResponse(['success' => false, 'message' => 'Invalid session token.'], 403);
}

$itemId = (int) ($input['item_id'] ?? 0);
$status = $input['status'] ?? '';
$allowed = ['pending_review', 'active', 'claimed', 'resolved', 'handed_over', 'rejected'];

if (!$itemId || !in_array($status, $allowed, true)) {
    jsonResponse(['success' => false, 'message' => 'Invalid request.'], 400);
}

$db = getDB();
$stmt = $db->prepare('SELECT * FROM items WHERE id = ?');
$stmt->execute([$itemId]);
$item = $stmt->fetch();

if (!$item) {
    jsonResponse(['success' => false, 'message' => 'Item not found.'], 404);
}
if (!isAdmin() && (int) $item['user_id'] !== currentUser()['id']) {
    jsonResponse(['success' => false, 'message' => 'You do not have permission to update this item.'], 403);
}
// Non-admins cannot set "rejected" (admin-only moderation status)
if (!isAdmin() && $status === 'rejected') {
    jsonResponse(['success' => false, 'message' => 'Only admins can reject items.'], 403);
}
// A reporter must claim the report before resolving it, and a report can only
// be claimed after an admin has claimed it first. Enforced server-side so the
// disabled button in the dashboard is not the only thing holding the line.
if (!isAdmin() && $status === 'resolved' && empty($item['owner_claimed_at'])) {
    $message = empty($item['admin_claimed_at'])
        ? 'An administrator has not claimed this report yet.'
        : 'Claim this report before marking it resolved.';
    jsonResponse(['success' => false, 'message' => $message], 409);
}

$update = $db->prepare('UPDATE items SET status = ? WHERE id = ?');
$update->execute([$status, $itemId]);

logActivity(currentUser()['id'], "Updated item #$itemId status to $status", 'item', $itemId);

jsonResponse(['success' => true]);
