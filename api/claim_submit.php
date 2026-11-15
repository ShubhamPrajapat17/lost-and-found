<?php
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
requireLoginJson();

if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
    jsonResponse(['success' => false, 'message' => 'Invalid session token.'], 403);
}

$itemId = (int) ($_POST['item_id'] ?? 0);
$name = trim($_POST['claimant_name'] ?? '');
$phone = trim($_POST['claimant_phone'] ?? '');
$email = trim($_POST['claimant_email'] ?? '');
$proofType = ($_POST['proof_type'] ?? 'secret_detail') === 'document' ? 'document' : 'secret_detail';
$proofText = trim($_POST['proof_text'] ?? '');

if (!$itemId || $name === '' || $phone === '' || $email === '') {
    jsonResponse(['success' => false, 'message' => 'Please fill in all required fields.'], 400);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['success' => false, 'message' => 'Please enter a valid email address.'], 400);
}

if ($proofType === 'secret_detail' && $proofText === '') {
    jsonResponse(['success' => false, 'message' => 'Please describe a detail that proves this item is yours.'], 400);
}
if ($proofType === 'document' && empty($_FILES['proof_file']['name'])) {
    jsonResponse(['success' => false, 'message' => 'Please upload a proof document or photo.'], 400);
}

$db = getDB();
$stmt = $db->prepare('SELECT * FROM items WHERE id = ? AND type = "found"');
$stmt->execute([$itemId]);
$item = $stmt->fetch();

if (!$item) {
    jsonResponse(['success' => false, 'message' => 'Found item not found.'], 404);
}
if ((int) $item['user_id'] === currentUser()['id']) {
    jsonResponse(['success' => false, 'message' => 'You cannot claim an item you reported yourself.'], 400);
}
// Only listings that are live can be claimed: not pending review, rejected or already resolved.
if ((int) $item['is_approved'] !== 1 || !in_array($item['status'], ['active', 'claimed'], true)) {
    jsonResponse(['success' => false, 'message' => 'This item is not open for claims.'], 409);
}
// One open claim per person per item.
$dup = $db->prepare('SELECT COUNT(*) FROM claims WHERE item_id = ? AND claimant_id = ? AND status IN ("pending", "approved")');
$dup->execute([$itemId, currentUser()['id']]);
if ((int) $dup->fetchColumn() > 0) {
    jsonResponse(['success' => false, 'message' => 'You have already submitted a claim for this item.'], 409);
}

$proofFile = null;
try {
    if ($proofType === 'document' && !empty($_FILES['proof_file']['name'])) {
        $uploaded = uploadFile($_FILES['proof_file'], ALLOWED_DOC_TYPES);
        $proofFile = $uploaded['path'] ?? $uploaded['url'];
    }

    $insert = $db->prepare(
        'INSERT INTO claims (item_id, claimant_id, claimant_name, claimant_phone, claimant_email, proof_type, proof_file, proof_text, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, "pending")'
    );
    $insert->execute([
        $itemId, currentUser()['id'], $name, $phone, $email, $proofType, $proofFile, $proofText ?: null,
    ]);
    $claimId = (int) $db->lastInsertId();

    $db->prepare('UPDATE items SET status = "claimed" WHERE id = ? AND status = "active"')->execute([$itemId]);

    logActivity(currentUser()['id'], "Submitted a claim on item #$itemId", 'claim', $claimId);

    jsonResponse(['success' => true, 'claim_id' => $claimId, 'message' => 'Your claim has been submitted for verification.']);
} catch (Throwable $e) {
    error_log($e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Failed to submit claim: ' . $e->getMessage()], 500);
}
