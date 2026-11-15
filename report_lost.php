<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid session token. Please refresh and try again.';
    } else {
        $title = trim($_POST['title'] ?? '');
        $category = $_POST['category'] ?? 'Other';
        $description = trim($_POST['description'] ?? '');
        $itemDate = $_POST['item_date'] ?? '';
        $itemTime = $_POST['item_time'] ?? '';
        $location = trim($_POST['location'] ?? '');
        $email = trim($_POST['contact_email'] ?? '');
        $phone = trim($_POST['contact_phone'] ?? '');

        $allowedCategories = ['Electronics', 'Documents', 'Keys', 'Bags', 'Jewelry', 'Pets', 'Other'];
        if (!in_array($category, $allowedCategories, true)) {
            $category = 'Other';
        }

        if ($title === '' || $description === '' || $itemDate === '' || $itemTime === '' || $location === '' || $email === '' || $phone === '') {
            $error = 'Please fill in all required fields — date, time, location, email and phone are mandatory.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid contact email address.';
        } else {
            $imagePath = null;
            $imageUrl = null;
            try {
                if (!empty($_FILES['image']['name'])) {
                    $uploaded = uploadFile($_FILES['image'], ALLOWED_IMAGE_TYPES);
                    $imagePath = $uploaded['path'];
                    $imageUrl = $uploaded['url'];
                }

                $db = getDB();
                $stmt = $db->prepare(
                    'INSERT INTO items (user_id, type, category, title, description, item_date, item_time, location, image_path, image_url, contact_email, contact_phone, status)
                     VALUES (?, "lost", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "pending_review")'
                );
                $stmt->execute([
                    currentUser()['id'], $category, $title, $description, $itemDate, $itemTime,
                    $location, $imagePath, $imageUrl, $email, $phone,
                ]);
                logActivity(currentUser()['id'], 'Reported a lost item: ' . $title, 'item', (int) $db->lastInsertId());
                $success = true;
            } catch (Throwable $e) {
                error_log($e->getMessage());
                $error = 'Something went wrong while submitting your report: ' . $e->getMessage();
            }
        }
    }
}

$pageTitle = 'Report Lost Item';
include __DIR__ . '/includes/header.php';
?>
<div class="container section">
  <div class="card" style="max-width:760px;margin:0 auto;">
    <h2>📝 Report a Lost Item</h2>
    <p class="text-muted">Fill in as much detail as possible to help others identify your item. Your report will be reviewed by an admin before it appears publicly.</p>

    <?php if ($success): ?>
      <div class="alert alert-success">Your lost item report has been submitted and is pending admin review. You can track its status from <a href="user_dashboard.php">My Dashboard</a>.</div>
    <?php else: ?>
      <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
      <form method="POST" action="report_lost.php" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">

        <div class="form-group">
          <label class="required">Item Title</label>
          <input type="text" name="title" required placeholder="e.g. Black leather wallet">
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="required">Category</label>
            <select name="category" required>
              <option value="Electronics">Electronics</option>
              <option value="Documents">Documents</option>
              <option value="Keys">Keys</option>
              <option value="Bags">Bags</option>
              <option value="Jewelry">Jewelry</option>
              <option value="Pets">Pets</option>
              <option value="Other">Other</option>
            </select>
          </div>
          <div class="form-group">
            <label>Upload Image</label>
            <input type="file" name="image" accept="image/*">
          </div>
        </div>

        <div class="form-group">
          <label class="required">Full Description</label>
          <textarea name="description" required placeholder="Describe the item in detail: color, brand, distinguishing marks, contents, etc."></textarea>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="required">Date Lost</label>
            <input type="date" name="item_date" required max="<?= date('Y-m-d') ?>">
          </div>
          <div class="form-group">
            <label class="required">Time Lost</label>
            <input type="time" name="item_time" required>
          </div>
        </div>

        <div class="form-group">
          <label class="required">Specific Location</label>
          <input type="text" name="location" required placeholder="e.g. Near City Mall main entrance, 5th Street">
          <div class="form-hint">Please give a specific street/landmark name rather than GPS coordinates.</div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="required">Contact Email</label>
            <input type="email" name="contact_email" required value="<?= h(currentUser()['email']) ?>">
          </div>
          <div class="form-group">
            <label class="required">Contact Phone</label>
            <input type="tel" name="contact_phone" required value="<?= h(currentUser()['phone']) ?>">
          </div>
        </div>

        <button type="submit" class="btn btn-primary btn-block">Submit Lost Item Report</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
