<?php
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
requireLoginJson();

$input = json_decode(file_get_contents('php://input'), true) ?? [];
if (!verifyCsrf($input['csrf_token'] ?? '')) {
    jsonResponse(['success' => false, 'message' => 'Invalid session token.'], 403);
}

$claimId = (int) ($input['claim_id'] ?? 0);
$action = $input['action'] ?? ''; // 'approve' | 'reject'

if (!$claimId || !in_array($action, ['approve', 'reject'], true)) {
    jsonResponse(['success' => false, 'message' => 'Invalid request.'], 400);
}

$db = getDB();
$stmt = $db->prepare(
    'SELECT c.*, i.user_id AS finder_id, i.id AS item_id FROM claims c JOIN items i ON i.id = c.item_id WHERE c.id = ?'
);
$stmt->execute([$claimId]);
$claim = $stmt->fetch();

if (!$claim) {
    jsonResponse(['success' => false, 'message' => 'Claim not found.'], 404);
}
// Only the item's finder or an admin may review the claim
if (!isAdmin() && (int) $claim['finder_id'] !== currentUser()['id']) {
    jsonResponse(['success' => false, 'message' => 'You do not have permission to review this claim.'], 403);
}

if ($claim['status'] !== 'pending') {
    jsonResponse(['success' => false, 'message' => 'This claim has already been reviewed.'], 409);
}

$newClaimStatus = $action === 'approve' ? 'approved' : 'rejected';
$db->prepare('UPDATE claims SET status = ?, reviewed_by = ? WHERE id = ?')
   ->execute([$newClaimStatus, currentUser()['id'], $claimId]);

if ($action === 'approve') {
    $db->prepare('UPDATE items SET status = "resolved" WHERE id = ?')->execute([$claim['item_id']]);
} else {
    // Re-open the item only if nobody else still has a claim waiting.
    $db->prepare(
        'UPDATE items SET status = "active"
         WHERE id = ? AND status = "claimed"
           AND NOT EXISTS (SELECT 1 FROM claims WHERE item_id = ? AND status = "pending")'
    )->execute([$claim['item_id'], $claim['item_id']]);
}

logActivity(currentUser()['id'], ucfirst($action) . "d claim #$claimId", 'claim', $claimId);

jsonResponse(['success' => true]);
