<?php
/**
 * exports/export_pdf.php
 * Server-side PDF-style report for admins. Renders a clean, print-optimized
 * HTML page and auto-opens the browser print dialog so the admin can save
 * it as a PDF ("Save as PDF" destination). For fully server-rendered binary
 * PDFs, install a library such as mPDF or TCPDF via Composer and swap the
 * output step below.
 *
 * Usage: exports/export_pdf.php?type=items | export_pdf.php?type=users
 */
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$type = $_GET['type'] ?? 'items';
$db = getDB();

if ($type === 'users') {
    $title = 'Users Report';
    $headers = ['ID', 'Full Name', 'Email', 'Phone', 'Role', 'Status', 'Joined'];
    $rows = $db->query('SELECT id, full_name, email, phone, role, status, created_at FROM users ORDER BY created_at DESC')->fetchAll();
} else {
    $type = 'items';
    $title = 'Items Report';
    $headers = ['ID', 'Type', 'Category', 'Title', 'Date', 'Location', 'Status', 'Reporter'];
    $rows = $db->query(
        'SELECT i.id, i.type, i.category, i.title, i.item_date, i.location, i.status, u.full_name AS reporter_name
         FROM items i JOIN users u ON u.id = i.user_id ORDER BY i.created_at DESC'
    )->fetchAll();
}

logActivity(currentUser()['id'], "Exported $type data as PDF report");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= h($title) ?></title>
<style>
  body { font-family: Arial, sans-serif; padding: 30px; color: #1f2937; }
  h1 { font-size: 20px; margin-bottom: 4px; }
  .meta { color: #6b7280; font-size: 12px; margin-bottom: 20px; }
  table { width: 100%; border-collapse: collapse; font-size: 12px; }
  th, td { border: 1px solid #d1d5db; padding: 6px 8px; text-align: left; }
  th { background: #f3f4f6; }
  @media print { .no-print { display: none; } }
</style>
</head>
<body>
  <button class="no-print" onclick="window.print()" style="margin-bottom:16px;padding:8px 16px;">🖨️ Print / Save as PDF</button>
  <h1><?= h(APP_NAME) ?> — <?= h($title) ?></h1>
  <div class="meta">Generated on <?= date('F j, Y g:i A') ?></div>
  <table>
    <thead><tr><?php foreach ($headers as $hd): ?><th><?= h($hd) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
      <?php foreach ($rows as $row): ?>
        <tr><?php foreach ($row as $k => $v): if (is_int($k)) continue; ?><td><?= h((string) $v) ?></td><?php endforeach; ?></tr>
      <?php endforeach; ?>
      <?php if (empty($rows)): ?><tr><td colspan="<?= count($headers) ?>">No data available.</td></tr><?php endif; ?>
    </tbody>
  </table>
  <script>window.onload = () => setTimeout(() => window.print(), 300);</script>
</body>
</html>
