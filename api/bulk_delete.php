<?php
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
requireLoginJson();

$input = json_decode(file_get_contents('php://input'), true) ?? [];
if (!verifyCsrf($input['csrf_token'] ?? '')) {
    jsonResponse(['success' => false, 'message' => 'Invalid session token.'], 403);
}

$ids = array_filter(array_map('intval', $input['ids'] ?? []));
if (empty($ids)) {
    jsonResponse(['success' => false, 'message' => 'No items selected.'], 400);
}

$db = getDB();
$placeholders = implode(',', array_fill(0, count($ids), '?'));

// Non-admins may only delete their own items
if (!isAdmin()) {
    $sql = "SELECT id FROM items WHERE id IN ($placeholders) AND user_id = ?";
    $stmt = $db->prepare($sql);
    $stmt->execute([...$ids, currentUser()['id']]);
    $allowedIds = array_column($stmt->fetchAll(), 'id');
} else {
    $allowedIds = $ids;
}

if (empty($allowedIds)) {
    jsonResponse(['success' => false, 'message' => 'You do not have permission to delete the selected items.'], 403);
}

$deleted = deleteItemsByIds($db, $allowedIds);

logActivity(currentUser()['id'], 'Bulk deleted ' . $deleted . ' item(s)');

jsonResponse(['success' => true, 'deleted' => $deleted, 'skipped' => count($ids) - count($allowedIds)]);
