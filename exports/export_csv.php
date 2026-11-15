<?php
/**
 * exports/export_csv.php
 * Server-side CSV export for admins.
 * Usage: exports/export_csv.php?type=items | export_csv.php?type=users
 */
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$type = $_GET['type'] ?? 'items';
$db = getDB();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $type . '_export_' . date('Ymd_His') . '.csv"');

$out = fopen('php://output', 'w');

if ($type === 'users') {
    fputcsv($out, ['ID', 'Full Name', 'Email', 'Phone', 'Role', 'Status', 'Joined']);
    $stmt = $db->query('SELECT * FROM users ORDER BY created_at DESC');
    foreach ($stmt as $row) {
        fputcsv($out, [$row['id'], $row['full_name'], $row['email'], $row['phone'], $row['role'], $row['status'], $row['created_at']]);
    }
} else {
    fputcsv($out, ['ID', 'Type', 'Category', 'Title', 'Description', 'Date', 'Time', 'Location', 'Status', 'Approved', 'Admin Claimed At', 'Reporter Claimed At', 'Reporter', 'Contact Email', 'Contact Phone', 'Created At']);
    $stmt = $db->query(
        'SELECT i.*, u.full_name AS reporter_name FROM items i JOIN users u ON u.id = i.user_id ORDER BY i.created_at DESC'
    );
    foreach ($stmt as $row) {
        fputcsv($out, [
            $row['id'], $row['type'], $row['category'], $row['title'], $row['description'],
            $row['item_date'], $row['item_time'], $row['location'], $row['status'],
            $row['is_approved'] ? 'Yes' : 'No', $row['admin_claimed_at'] ?? '', $row['owner_claimed_at'] ?? '',
            $row['reporter_name'], $row['contact_email'],
            $row['contact_phone'], $row['created_at'],
        ]);
    }
}

fclose($out);
logActivity(currentUser()['id'], "Exported $type data as CSV");
exit;
