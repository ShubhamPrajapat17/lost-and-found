<?php
/**
 * api/support_admin.php  (admin only)
 * Work with the messages users send from contact.php.
 *
 *   GET  ?id=12                      -> one message (+ who sent it)
 *   POST {action:"update", id, status, reply, csrf_token}
 *   POST {action:"delete", id, csrf_token}
 */
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
requireAdminJson();

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = (int) ($_GET['id'] ?? 0);
    $stmt = $db->prepare('SELECT * FROM contact_messages WHERE id = ?');
    $stmt->execute([$id]);
    $m = $stmt->fetch();
    if (!$m) {
        jsonResponse(['success' => false, 'message' => 'Message not found.'], 404);
    }

    // Who is this? Match by account id, or by email for visitors who weren't
    // logged in (e.g. a blocked user appealing).
    $sender = null;
    if ($m['user_id']) {
        $q = $db->prepare('SELECT id, full_name, email, role, status, blocked_reason FROM users WHERE id = ?');
        $q->execute([$m['user_id']]);
    } else {
        $q = $db->prepare('SELECT id, full_name, email, role, status, blocked_reason FROM users WHERE email = ? LIMIT 1');
        $q->execute([$m['email']]);
    }
    $sender = $q->fetch() ?: null;

    $cats = contactCategories();
    jsonResponse(['success' => true, 'message' => [
        'id' => (int) $m['id'],
        'name' => $m['name'],
        'email' => $m['email'],
        'category' => $cats[$m['category']] ?? 'Other',
        'subject' => $m['subject'],
        'body' => $m['message'],
        'status' => $m['status'],
        'admin_reply' => $m['admin_reply'],
        'replied_at' => $m['replied_at'],
        'created_at' => $m['created_at'],
        'logged_in' => $m['user_id'] !== null,
        'account' => $sender ? [
            'id' => (int) $sender['id'],
            'name' => $sender['full_name'],
            'role' => $sender['role'],
            'blocked' => $sender['status'] !== 'active',
            'blocked_reason' => $sender['blocked_reason'],
        ] : null,
    ]]);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
if (!verifyCsrf($input['csrf_token'] ?? '')) {
    jsonResponse(['success' => false, 'message' => 'Invalid session token.'], 403);
}

$id = (int) ($input['id'] ?? 0);
$action = $input['action'] ?? '';
$exists = $db->prepare('SELECT id, subject FROM contact_messages WHERE id = ?');
$exists->execute([$id]);
$row = $exists->fetch();
if (!$row) {
    jsonResponse(['success' => false, 'message' => 'Message not found.'], 404);
}

if ($action === 'delete') {
    $db->prepare('DELETE FROM contact_messages WHERE id = ?')->execute([$id]);
    logActivity(currentUser()['id'], "Deleted support message #$id");
    jsonResponse(['success' => true, 'message' => 'Message deleted.']);
}

if ($action === 'update') {
    $status = $input['status'] ?? '';
    $reply = trim((string) ($input['reply'] ?? ''));
    if (!in_array($status, ['new', 'in_progress', 'resolved'], true)) {
        jsonResponse(['success' => false, 'message' => 'Invalid status.'], 400);
    }
    if (mb_strlen($reply) > 3000) {
        jsonResponse(['success' => false, 'message' => 'The reply is too long (3000 characters at most).'], 400);
    }

    if ($reply !== '') {
        $db->prepare(
            'UPDATE contact_messages SET status = ?, admin_reply = ?, replied_by = ?, replied_at = NOW() WHERE id = ?'
        )->execute([$status, $reply, currentUser()['id'], $id]);
    } else {
        // Status change only; keep any earlier reply as it was.
        $db->prepare('UPDATE contact_messages SET status = ? WHERE id = ?')->execute([$status, $id]);
    }
    logActivity(currentUser()['id'], "Updated support message #$id to " . supportStatusLabel($status));
    jsonResponse(['success' => true, 'message' => 'Saved.']);
}

jsonResponse(['success' => false, 'message' => 'Invalid request.'], 400);
