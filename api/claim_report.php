<?php
/**
 * api/claim_report.php
 * The two-step claim handshake on an approved report.
 *
 *   as=admin  An administrator takes charge of the report. Only allowed once
 *             the report is approved.
 *   as=owner  The reporter claims their own report. Only allowed once an
 *             admin has already claimed it.
 *
 * Marking a report resolved is gated on the owner claim — see
 * api/update_status.php. The rules are enforced here as well as in the UI,
 * so a disabled button is not the only thing standing in the way.
 */

require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
requireLoginJson();

$input = json_decode(file_get_contents('php://input'), true) ?? [];
if (!verifyCsrf($input['csrf_token'] ?? '')) {
    jsonResponse(['success' => false, 'message' => 'Invalid session token.'], 403);
}

$itemId = (int) ($input['item_id'] ?? 0);
$as = $input['as'] ?? '';

if (!$itemId || !in_array($as, ['admin', 'owner'], true)) {
    jsonResponse(['success' => false, 'message' => 'Invalid request.'], 400);
}

$db = getDB();
$stmt = $db->prepare('SELECT * FROM items WHERE id = ?');
$stmt->execute([$itemId]);
$item = $stmt->fetch();

if (!$item) {
    jsonResponse(['success' => false, 'message' => 'Report not found.'], 404);
}
if ((int) $item['is_approved'] !== 1) {
    jsonResponse(['success' => false, 'message' => 'This report has not been approved yet.'], 409);
}
if (in_array($item['status'], ['rejected', 'resolved', 'handed_over'], true)) {
    jsonResponse(['success' => false, 'message' => 'This report is already closed.'], 409);
}

$userId = (int) currentUser()['id'];

if ($as === 'admin') {
    if (!isAdmin()) {
        jsonResponse(['success' => false, 'message' => 'Administrator access required.'], 403);
    }
    if (!empty($item['admin_claimed_at'])) {
        jsonResponse(['success' => false, 'message' => 'This report has already been claimed by an admin.'], 409);
    }

    $db->prepare('UPDATE items SET admin_claimed_by = ?, admin_claimed_at = NOW() WHERE id = ? AND admin_claimed_at IS NULL')
       ->execute([$userId, $itemId]);

    logActivity($userId, "Claimed report #$itemId as admin", 'item', $itemId);
    jsonResponse([
        'success' => true,
        'message' => 'Report claimed. The reporter can now claim it from their dashboard.',
        'admin_claimed' => true,
        'owner_claimed' => !empty($item['owner_claimed_at']),
    ]);
}

// as=owner
if ((int) $item['user_id'] !== $userId) {
    jsonResponse(['success' => false, 'message' => 'Only the person who filed this report can claim it.'], 403);
}
if (empty($item['admin_claimed_at'])) {
    jsonResponse(['success' => false, 'message' => 'An administrator has not claimed this report yet.'], 409);
}
if (!empty($item['owner_claimed_at'])) {
    jsonResponse(['success' => false, 'message' => 'You have already claimed this report.'], 409);
}

$db->prepare('UPDATE items SET owner_claimed_at = NOW() WHERE id = ? AND owner_claimed_at IS NULL AND admin_claimed_at IS NOT NULL')
   ->execute([$itemId]);

logActivity($userId, "Claimed own report #$itemId", 'item', $itemId);
jsonResponse([
    'success' => true,
    'message' => 'Report claimed. You can now mark it resolved.',
    'admin_claimed' => true,
    'owner_claimed' => true,
]);
