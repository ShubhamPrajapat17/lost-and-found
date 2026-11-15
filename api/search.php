<?php
/**
 * api/search.php
 * JSON search / filter endpoint behind the home page quick search, the
 * lost & found grids, and the logged-in "My List" view.
 *
 * Fixed here:
 *  - `mine=1` used requireLogin(), which sent a 302 to an HTML login page.
 *    The caller then tried JSON.parse() on HTML and reported "Unexpected
 *    server response", so the list view search never worked when signed out
 *    or after the session expired. Now returns a proper JSON 401.
 *  - Keyword / location terms were interpolated into LIKE without escaping
 *    the % and _ wildcards, so "50%" or "a_b" matched unrelated rows.
 *  - Unvalidated date_from / date_to values were passed straight to MySQL.
 *  - String literals were double-quoted, which breaks under the ANSI_QUOTES
 *    sql_mode (MySQL then reads "rejected" as a column name and errors).
 *  - An uncaught PDOException produced an empty 500 body (display_errors is
 *    off), again surfacing as "Unexpected server response" with no clue why.
 */

require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');

$type = $_GET['type'] ?? '';
$type = in_array($type, ['lost', 'found'], true) ? $type : null;
$keyword = trim($_GET['keyword'] ?? '');
$category = trim($_GET['category'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$location = trim($_GET['location'] ?? '');
$mine = ($_GET['mine'] ?? '') === '1';
$limit = min(100, max(1, (int) ($_GET['limit'] ?? 24)));

$allowedCategories = ['Electronics', 'Documents', 'Keys', 'Bags', 'Jewelry', 'Pets', 'Other'];

// Reject malformed dates up front rather than handing them to MySQL.
if ($dateFrom !== '' && !isValidDate($dateFrom)) {
    jsonResponse(['success' => false, 'message' => 'Invalid "from" date. Use YYYY-MM-DD.'], 400);
}
if ($dateTo !== '' && !isValidDate($dateTo)) {
    jsonResponse(['success' => false, 'message' => 'Invalid "to" date. Use YYYY-MM-DD.'], 400);
}
if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    jsonResponse(['success' => false, 'message' => 'The "from" date must not be after the "to" date.'], 400);
}
if ($category !== '' && !in_array($category, $allowedCategories, true)) {
    jsonResponse(['success' => false, 'message' => 'Unknown category.'], 400);
}

$sql = 'SELECT i.*, u.full_name AS reporter_name
        FROM items i
        JOIN users u ON u.id = i.user_id
        WHERE 1 = 1';
$params = [];

if ($mine) {
    // JSON guard: never redirect an AJAX caller to the HTML login page.
    requireLoginJson();
    $sql .= ' AND i.user_id = ?';
    $params[] = currentUser()['id'];
} else {
    // Public browsing only shows approved, non-rejected listings, and nothing
    // from accounts an admin has blocked.
    $sql .= " AND i.is_approved = 1 AND i.status <> 'rejected' AND u.status = 'active'";
}

if ($type !== null) {
    $sql .= ' AND i.type = ?';
    $params[] = $type;
}
if ($keyword !== '') {
    $sql .= " AND (i.title LIKE ? ESCAPE '!' OR i.description LIKE ? ESCAPE '!')";
    $params[] = '%' . escapeLike($keyword) . '%';
    $params[] = '%' . escapeLike($keyword) . '%';
}
if ($category !== '') {
    $sql .= ' AND i.category = ?';
    $params[] = $category;
}
if ($dateFrom !== '') {
    $sql .= ' AND i.item_date >= ?';
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $sql .= ' AND i.item_date <= ?';
    $params[] = $dateTo;
}
if ($location !== '') {
    $sql .= " AND i.location LIKE ? ESCAPE '!'";
    $params[] = '%' . escapeLike($location) . '%';
}

$sql .= ' ORDER BY i.created_at DESC LIMIT ' . $limit; // $limit is an int, clamped above

try {
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('Search failed: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Search is temporarily unavailable. Please try again.'], 500);
}

$currentUser = currentUser();
$loggedInId = $currentUser['id'] ?? null;
$viewerIsAdmin = isAdmin();

$result = array_map(function (array $item) use ($loggedInId, $viewerIsAdmin) {
    $isOwner = $loggedInId !== null && (int) $item['user_id'] === (int) $loggedInId;
    $canSeeContact = $isOwner || $viewerIsAdmin;

    return [
        'id' => (int) $item['id'],
        'type' => $item['type'],
        'category' => $item['category'],
        'title' => $item['title'],
        'description' => $item['description'],
        'item_date' => $item['item_date'],
        'item_time' => $item['item_time'],
        'location' => $item['location'],
        'image_url' => itemImageUrl($item),
        'status' => $item['status'],
        'is_approved' => (int) $item['is_approved'] === 1,
        'admin_claimed' => !empty($item['admin_claimed_at']),
        'owner_claimed' => !empty($item['owner_claimed_at']),
        'reporter_name' => $item['reporter_name'],
        'contact_email' => $canSeeContact ? $item['contact_email'] : maskEmail($item['contact_email']),
        'contact_phone' => $canSeeContact ? $item['contact_phone'] : maskPhone($item['contact_phone']),
        'is_owner' => $isOwner,
        'created_at' => $item['created_at'],
    ];
}, $items);

jsonResponse(['success' => true, 'count' => count($result), 'items' => $result]);
