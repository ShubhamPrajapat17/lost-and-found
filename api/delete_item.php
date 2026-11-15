<?php
/**
 * api/delete_item.php
 * Delete ONE lost/found report.
 *
 * A user may delete their own reports (e.g. they made a mistake); an admin may
 * delete any. Claims and chat messages on the report are removed with it, and
 * so are its uploaded image and proof files.
 *
 * Body (JSON): { "item_id": 12, "csrf_token": "..." }
 */
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
requireLoginJson();

$input = json_decode(file_get_contents('php://input'), true) ?? [];
if (!verifyCsrf($input['csrf_token'] ?? '')) {
    jsonResponse(['success' => false, 'message' => 'Invalid session token.'], 403);
}

$itemId = (int) ($input['item_id'] ?? 0);
if (!$itemId) {
    jsonResponse(['success' => false, 'message' => 'Invalid request.'], 400);
}

$db = getDB();
$stmt = $db->prepare('SELECT id, user_id, title FROM items WHERE id = ?');
$stmt->execute([$itemId]);
$item = $stmt->fetch();

if (!$item) {
    jsonResponse(['success' => false, 'message' => 'This report was not found. It may already have been deleted.'], 404);
}
if (!isAdmin() && (int) $item['user_id'] !== (int) currentUser()['id']) {
    jsonResponse(['success' => false, 'message' => 'You can only delete your own reports.'], 403);
}

try {
    $deleted = deleteItemsByIds($db, [$itemId]);
} catch (Throwable $e) {
    error_log('Delete item failed: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Could not delete this report. Please try again.'], 500);
}

logActivity(currentUser()['id'], 'Deleted report #' . $itemId . ': ' . mb_substr($item['title'], 0, 80));
jsonResponse(['success' => true, 'deleted' => $deleted, 'message' => 'Report deleted.']);
