<?php
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');

$keyword = trim($_GET['keyword'] ?? '');
if ($keyword === '') {
    jsonResponse(['success' => false, 'message' => 'Please enter a keyword.'], 400);
}

// Same fixes as api/search.php: single-quoted SQL literals (ANSI_QUOTES safe),
// escaped LIKE wildcards, and JSON rather than an empty 500 body on failure.
try {
    $stmt = getDB()->prepare(
        "SELECT i.id, i.type, i.category, i.title, i.item_date, i.location, i.status
         FROM items i
         JOIN users u ON u.id = i.user_id
         WHERE i.is_approved = 1 AND i.status <> 'rejected' AND u.status = 'active'
           AND i.title LIKE ? ESCAPE '!'
         ORDER BY i.created_at DESC LIMIT 10"
    );
    $stmt->execute(['%' . escapeLike($keyword) . '%']);
    $items = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('Lookup failed: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Lookup is temporarily unavailable. Please try again.'], 500);
}

jsonResponse(['success' => true, 'items' => $items]);
