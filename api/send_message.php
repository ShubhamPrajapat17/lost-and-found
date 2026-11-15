<?php
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
requireLoginJson();

$input = json_decode(file_get_contents('php://input'), true) ?? [];
if (!verifyCsrf($input['csrf_token'] ?? '')) {
    jsonResponse(['success' => false, 'message' => 'Invalid session token.'], 403);
}

$claimId = (int) ($input['claim_id'] ?? 0);
$message = trim($input['message'] ?? '');

if (!$claimId || $message === '') {
    jsonResponse(['success' => false, 'message' => 'Message cannot be empty.'], 400);
}

$db = getDB();
$stmt = $db->prepare(
    'SELECT c.*, i.user_id AS finder_id FROM claims c JOIN items i ON i.id = c.item_id WHERE c.id = ?'
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

$insert = $db->prepare('INSERT INTO chats (claim_id, sender_id, message) VALUES (?, ?, ?)');
$insert->execute([$claimId, $uid, substr($message, 0, 2000)]);

jsonResponse(['success' => true, 'message_id' => (int) $db->lastInsertId()]);
