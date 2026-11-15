<?php
/**
 * api/item_details.php
 * Full detail for a single report, used by the "View" button in the admin
 * incoming-reports table.
 *
 * Readable by an admin or by the person who filed the report. Contact details
 * are returned in the clear for those two only — everyone else gets a 403
 * rather than a masked row, since this endpoint exists for moderation.
 */

require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
requireLoginJson();

$itemId = (int) ($_GET['id'] ?? 0);
if (!$itemId) {
    jsonResponse(['success' => false, 'message' => 'Invalid report id.'], 400);
}

try {
    $stmt = getDB()->prepare(
        "SELECT i.*,
                u.full_name  AS reporter_name,
                u.email      AS reporter_email,
                u.phone      AS reporter_phone,
                a.full_name  AS admin_claimer_name,
                (SELECT COUNT(*) FROM claims c WHERE c.item_id = i.id) AS claim_count
         FROM items i
         JOIN users u ON u.id = i.user_id
         LEFT JOIN users a ON a.id = i.admin_claimed_by
         WHERE i.id = ?"
    );
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();
} catch (Throwable $e) {
    error_log('Item details lookup failed: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Could not load this report.'], 500);
}

if (!$item) {
    jsonResponse(['success' => false, 'message' => 'Report not found.'], 404);
}
if (!isAdmin() && (int) $item['user_id'] !== (int) currentUser()['id']) {
    jsonResponse(['success' => false, 'message' => 'You do not have permission to view this report.'], 403);
}

$imageUrl = itemImageUrl($item);

jsonResponse([
    'success' => true,
    'item' => [
        'id' => (int) $item['id'],
        'title' => $item['title'],
        'type' => $item['type'],
        'category' => $item['category'],
        'description' => $item['description'],
        'item_date' => $item['item_date'],
        'item_time' => $item['item_time'],
        'location' => $item['location'],
        'image_url' => $imageUrl,
        'status' => $item['status'],
        'is_approved' => (int) $item['is_approved'] === 1,
        'secret_detail' => $item['secret_detail'],
        'contact_email' => $item['contact_email'],
        'contact_phone' => $item['contact_phone'],
        'reporter_name' => $item['reporter_name'],
        'reporter_email' => $item['reporter_email'],
        'reporter_phone' => $item['reporter_phone'],
        'admin_claimed_at' => $item['admin_claimed_at'],
        'admin_claimer_name' => $item['admin_claimer_name'],
        'owner_claimed_at' => $item['owner_claimed_at'],
        'claim_count' => (int) $item['claim_count'],
        'created_at' => $item['created_at'],
        'updated_at' => $item['updated_at'],
    ],
]);
