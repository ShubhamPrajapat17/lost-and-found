<?php
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
requireLoginJson();

$claimId = (int) ($_GET['claim_id'] ?? 0);
if (!$claimId) {
    jsonResponse(['success' => false, 'message' => 'Invalid claim.'], 400);
}

$db = getDB();
$stmt = $db->prepare(
    'SELECT c.*, i.user_id AS finder_id, i.title AS item_title FROM claims c JOIN items i ON i.id = c.item_id WHERE c.id = ?'
);
$stmt->execute([$claimId]);
$claim = $stmt->fetch();

if (!$claim) {
    jsonResponse(['success' => false, 'message' => 'Conversation not found.'], 404);
}

$uid = currentUser()['id'];
$isParticipant = $uid === (int) $claim['finder_id'] || $uid === (int) $claim['claimant_id'];
if (!$isParticipant && !isAdmin()) {
    jsonResponse(['success' => false, 'message' => 'You are not part of this conversation.'], 403);
}

if ($claim['status'] !== 'approved') {
    jsonResponse(['success' => false, 'message' => 'Chat unlocks once the claim has been approved.'], 403);
}

$msgStmt = $db->prepare(
    'SELECT ch.*, u.full_name AS sender_name FROM chats ch JOIN users u ON u.id = ch.sender_id WHERE claim_id = ? ORDER BY created_at ASC'
);
$msgStmt->execute([$claimId]);
$messages = $msgStmt->fetchAll();

jsonResponse([
    'success' => true,
    'item_title' => $claim['item_title'],
    'current_user_id' => $uid,
    'messages' => $messages,
]);
