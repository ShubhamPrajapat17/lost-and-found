<?php
require_once __DIR__ . '/includes/auth.php';
requireAdmin();
$db = getDB();
$admin = currentUser();

// ---- Admin profile update (name, phone, theme, password) ----
$profileMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'profile') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $profileMsg = '<div class="alert alert-error">Invalid session token.</div>';
    } else {
        $fullName = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_new_password'] ?? '';
        $theme = normalizeTheme($_POST['theme'] ?? 'default');

        if ($fullName === '' || $phone === '') {
            $profileMsg = '<div class="alert alert-error">Name and phone are required.</div>';
        } elseif ($newPassword !== '' && passwordPolicyMessage($newPassword) !== '') {
            $profileMsg = '<div class="alert alert-error">' . h(passwordPolicyMessage($newPassword)) . '</div>';
        } elseif ($newPassword !== '' && $newPassword !== $confirmPassword) {
            $profileMsg = '<div class="alert alert-error">The new passwords do not match.</div>';
        } else {
            if ($newPassword !== '') {
                $db->prepare('UPDATE users SET full_name=?, phone=?, theme=?, password_hash=? WHERE id=?')
                   ->execute([$fullName, $phone, $theme, password_hash($newPassword, PASSWORD_BCRYPT), $admin['id']]);
            } else {
                $db->prepare('UPDATE users SET full_name=?, phone=?, theme=? WHERE id=?')
                   ->execute([$fullName, $phone, $theme, $admin['id']]);
            }
            $_SESSION['user']['full_name'] = $fullName;
            $_SESSION['user']['phone'] = $phone;
            $_SESSION['user']['theme'] = $theme;
            rememberThemeCookie($theme);
            $admin = currentUser();
            $profileMsg = '<div class="alert alert-success">Profile updated successfully.</div>';
        }
    }
}

// ---- Metrics ----
$totalReported = $db->query('SELECT COUNT(*) c FROM items')->fetch()['c'];
$resolved = $db->query('SELECT COUNT(*) c FROM items WHERE status IN ("resolved","handed_over")')->fetch()['c'];
$activeLost = $db->query('SELECT COUNT(*) c FROM items WHERE type="lost" AND status="active"')->fetch()['c'];
$pendingClaims = $db->query('SELECT COUNT(*) c FROM claims WHERE status="pending"')->fetch()['c'];
$pendingReview = $db->query('SELECT COUNT(*) c FROM items WHERE is_approved=0 AND status="pending_review"')->fetch()['c'];
$totalUsers = $db->query('SELECT COUNT(*) c FROM users WHERE role="user"')->fetch()['c'];

// ---- Monthly trend (last 6 months) ----
$trendStmt = $db->query(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') ym, type, COUNT(*) c
     FROM items
     WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
     GROUP BY ym, type ORDER BY ym ASC"
);
$trendRaw = $trendStmt->fetchAll();
$months = [];
$lostSeries = [];
$foundSeries = [];
$trendMap = [];
foreach ($trendRaw as $row) {
    $trendMap[$row['ym']][$row['type']] = (int) $row['c'];
}
for ($i = 5; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime("-$i months"));
    $months[] = date('M Y', strtotime($ym . '-01'));
    $lostSeries[] = $trendMap[$ym]['lost'] ?? 0;
    $foundSeries[] = $trendMap[$ym]['found'] ?? 0;
}

// ---- Incoming reports ----
// Every report is listed here, not only the ones awaiting review, so nothing
// that comes in can slip past the admin view. Pending items sort to the top;
// the table itself is filterable by status.
$incomingItems = $db->query(
    "SELECT i.*, u.full_name AS reporter_name, a.full_name AS admin_claimer_name
     FROM items i
     JOIN users u ON u.id = i.user_id
     LEFT JOIN users a ON a.id = i.admin_claimed_by
     ORDER BY (i.status = 'pending_review') DESC, i.created_at DESC
     LIMIT 500"
)->fetchAll();
$totalIncoming = count($incomingItems);

// ---- Claim requests ----
$claimRequests = $db->query(
    'SELECT c.*, i.title AS item_title, u.full_name AS finder_name FROM claims c
     JOIN items i ON i.id=c.item_id JOIN users u ON u.id=i.user_id
     ORDER BY c.created_at DESC LIMIT 50'
)->fetchAll();

// ---- Items management ----
$allItems = $db->query(
    'SELECT i.*, u.full_name AS reporter_name, u.email AS reporter_email, u.phone AS reporter_phone
     FROM items i JOIN users u ON u.id=i.user_id ORDER BY i.created_at DESC LIMIT 200'
)->fetchAll();

// ---- Users management ----
// Report / claim counts help spot fake or abusive accounts at a glance.
$allUsers = $db->query(
    'SELECT u.*,
            (SELECT COUNT(*) FROM items i WHERE i.user_id = u.id) AS report_count,
            (SELECT COUNT(*) FROM claims c WHERE c.claimant_id = u.id) AS claim_count,
            (SELECT COUNT(*) FROM contact_messages m WHERE m.user_id = u.id) AS message_count
     FROM users u ORDER BY (u.status <> "active") DESC, u.created_at DESC LIMIT 500'
)->fetchAll();
$blockedCount = count(array_filter($allUsers, fn($u) => $u['status'] !== 'active'));

// ---- Support messages (from contact.php) ----
$supportMessages = $db->query(
    'SELECT m.*, u.status AS account_status
     FROM contact_messages m LEFT JOIN users u ON u.id = m.user_id
     ORDER BY (m.status = "new") DESC, (m.status = "in_progress") DESC, m.created_at DESC LIMIT 300'
)->fetchAll();
$newSupport = (int) $db->query('SELECT COUNT(*) FROM contact_messages WHERE status = "new"')->fetchColumn();
$openSupport = (int) $db->query('SELECT COUNT(*) FROM contact_messages WHERE status <> "resolved"')->fetchColumn();
$supportCategories = contactCategories();

// ---- Recent activity ----
$activity = $db->query(
    'SELECT a.*, u.full_name FROM activity_log a LEFT JOIN users u ON u.id=a.user_id
     ORDER BY a.created_at DESC LIMIT 20'
)->fetchAll();

$pageTitle = 'Admin Dashboard';
include __DIR__ . '/includes/header.php';
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>

<div class="container dashboard">
  <aside class="sidebar">
    <h4>Admin Panel</h4>
    <nav>
      <a href="#overview" class="dash-tab active" data-tab="overview">📊 Overview</a>
      <a href="#approval" class="dash-tab" data-tab="approval">🕵️ Incoming Reports <?= $pendingReview ? "($pendingReview pending)" : '' ?></a>
      <a href="#claims" class="dash-tab" data-tab="claims">🤝 Claim Requests <?= $pendingClaims ? "($pendingClaims)" : '' ?></a>
      <a href="#items" class="dash-tab" data-tab="items">📦 Manage Items</a>
      <a href="#users" class="dash-tab" data-tab="users">👥 Manage Users <?= $blockedCount ? "($blockedCount blocked)" : '' ?></a>
      <a href="#support" class="dash-tab" data-tab="support">📨 Support Messages <?= $newSupport ? "($newSupport new)" : '' ?></a>
      <a href="#profile" class="dash-tab" data-tab="profile">👤 My Profile</a>
      <a href="#settings" class="dash-tab" data-tab="settings">⚙️ System Settings</a>
    </nav>
  </aside>

  <div class="dashboard-main">
    <!-- Overview -->
    <section id="panel-overview" class="dash-panel">
      <div class="section-title"><h2>Overview</h2></div>
      <div class="metrics-grid">
        <div class="metric-card"><div class="metric-value"><?= $totalReported ?></div><div class="metric-label">Total Reported</div></div>
        <div class="metric-card"><div class="metric-value"><?= $resolved ?></div><div class="metric-label">Resolved</div></div>
        <div class="metric-card"><div class="metric-value"><?= $activeLost ?></div><div class="metric-label">Active Lost Items</div></div>
        <div class="metric-card"><div class="metric-value"><?= $pendingClaims ?></div><div class="metric-label">Pending Claims</div></div>
        <div class="metric-card"><div class="metric-value"><?= $pendingReview ?></div><div class="metric-label">Awaiting Review</div></div>
        <div class="metric-card"><div class="metric-value"><?= $totalUsers ?></div><div class="metric-label">Registered Users</div></div>
        <div class="metric-card"><div class="metric-value"><?= $openSupport ?></div><div class="metric-label">Open Support Messages</div></div>
      </div>

      <div class="card mb-1">
        <h3>Activity Trends (Last 6 Months)</h3>
        <canvas id="trendChart" height="90"></canvas>
      </div>

      <div class="card">
        <h3>Recent Activity Stream</h3>
        <table>
          <thead><tr><th>User</th><th>Action</th><th>When</th></tr></thead>
          <tbody>
          <?php foreach ($activity as $a): ?>
            <tr><td><?= h($a['full_name'] ?? 'System') ?></td><td><?= h($a['action']) ?></td><td><?= formatDateTime($a['created_at']) ?></td></tr>
          <?php endforeach; ?>
          <?php if (empty($activity)): ?><tr><td colspan="3" class="text-muted">No recent activity.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Incoming Reports (every report, filterable) -->
    <section id="panel-approval" class="dash-panel hidden">
      <div class="section-title">
        <h2>Incoming Reports</h2>
        <span class="text-muted">Showing <span id="incomingVisibleCount"><?= $totalIncoming ?></span> of <?= $totalIncoming ?></span>
      </div>
      <div class="table-wrap">
        <div class="table-toolbar">
          <div class="flex">
            <select id="incomingStatusFilter" style="width:auto;">
              <option value="">All statuses</option>
              <option value="pending_review">Pending review</option>
              <option value="active">Active</option>
              <option value="claimed">Claimed</option>
              <option value="resolved">Resolved</option>
              <option value="handed_over">Handed over</option>
              <option value="rejected">Rejected</option>
            </select>
            <input type="text" id="incomingSearch" placeholder="Filter by title, reporter…" style="width:auto;">
          </div>
          <button class="btn btn-secondary btn-sm" onclick="exportTableToCSV('incomingReportsTable','incoming_reports.csv')">⬇️ CSV</button>
        </div>
        <table id="incomingReportsTable">
          <thead>
            <tr>
              <th>Title</th><th>Type</th><th>Category</th><th>Reporter</th>
              <th>Date</th><th>Status</th><th>Claim</th><th class="no-export">Actions</th>
            </tr>
          </thead>
          <tbody id="incomingReportsBody">
          <?php foreach ($incomingItems as $i): ?>
            <?php
              $approved     = (int) $i['is_approved'] === 1;
              $adminClaimed = !empty($i['admin_claimed_at']);
              $ownerClaimed = !empty($i['owner_claimed_at']);
              $closed       = in_array($i['status'], ['resolved', 'handed_over', 'rejected'], true);
              // The admin claim is what unlocks the reporter's own claim.
              if ($ownerClaimed) {
                  $claimHint = 'Reporter claimed this on ' . formatDateTime($i['owner_claimed_at']) . '.';
              } elseif ($adminClaimed) {
                  $claimHint = 'Claimed by ' . ($i['admin_claimer_name'] ?? 'an admin')
                             . ' on ' . formatDateTime($i['admin_claimed_at'])
                             . '. The reporter can now claim it.';
              } elseif (!$approved) {
                  $claimHint = 'Approve this report before claiming it.';
              } else {
                  $claimHint = 'Claim this report to let the reporter claim it in turn.';
              }
            ?>
            <tr data-status="<?= h($i['status']) ?>">
              <td><?= h($i['title']) ?></td>
              <td><span class="badge badge-<?= h($i['type']) ?>"><?= ucfirst($i['type']) ?></span></td>
              <td><?= h($i['category']) ?></td>
              <td><?= h($i['reporter_name']) ?></td>
              <td><?= formatDate($i['item_date']) ?></td>
              <td><span class="badge badge-<?= h($i['status']) ?>"><?= ucwords(str_replace('_', ' ', $i['status'])) ?></span></td>
              <td>
                <?php if ($ownerClaimed): ?>
                  <span class="badge badge-approved">Reporter claimed</span>
                <?php elseif ($adminClaimed): ?>
                  <span class="badge badge-claimed">Admin claimed</span>
                <?php else: ?>
                  <span class="text-muted">&mdash;</span>
                <?php endif; ?>
              </td>
              <td class="no-export">
                <button class="btn btn-sm btn-secondary" onclick="viewReport(<?= (int) $i['id'] ?>)">👁️ View</button>
                <?php if ($i['status'] === 'pending_review'): ?>
                  <button class="btn btn-sm btn-success" onclick="approveItem(<?= (int) $i['id'] ?>,'approve')">Approve</button>
                  <button class="btn btn-sm btn-danger" onclick="approveItem(<?= (int) $i['id'] ?>,'reject')">Reject</button>
                <?php elseif (!$closed): ?>
                  <button class="btn btn-sm btn-primary"
                          onclick="claimReport(<?= (int) $i['id'] ?>, 'admin')"
                          title="<?= h($claimHint) ?>"
                          <?= (!$approved || $adminClaimed) ? 'disabled' : '' ?>>
                    <?= $adminClaimed ? '✓ Claimed' : 'Claim' ?>
                  </button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <tr id="incomingNoMatch" class="hidden"><td colspan="8" class="text-muted">No reports match this filter.</td></tr>
          <?php if ($totalIncoming === 0): ?><tr><td colspan="8" class="text-muted">No reports have been submitted yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Claim Requests -->
    <section id="panel-claims" class="dash-panel hidden">
      <div class="section-title"><h2>Claim Requests</h2></div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Item</th><th>Finder</th><th>Claimant</th><th>Proof</th><th>Status</th><th>Actions</th></tr></thead>
          <tbody>
          <?php foreach ($claimRequests as $c): ?>
            <tr>
              <td><?= h($c['item_title']) ?></td>
              <td><?= h($c['finder_name']) ?></td>
              <td><?= h($c['claimant_name']) ?> (<?= h($c['claimant_email']) ?>)</td>
              <td><?= $c['proof_type'] === 'document'
                    ? ($c['proof_file'] ? '<a href="' . h($c['proof_file']) . '" target="_blank">View File</a>' : 'Document')
                    : h($c['proof_text']) ?></td>
              <td><span class="badge badge-<?= h($c['status']) ?>"><?= ucfirst($c['status']) ?></span></td>
              <td>
                <?php if ($c['status'] === 'pending'): ?>
                  <button class="btn btn-sm btn-success" onclick="reviewClaim(<?= (int)$c['id'] ?>,'approve')">Approve</button>
                  <button class="btn btn-sm btn-danger" onclick="reviewClaim(<?= (int)$c['id'] ?>,'reject')">Reject</button>
                <?php else: ?><span class="text-muted">—</span><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($claimRequests)): ?><tr><td colspan="6" class="text-muted">No claims submitted yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Manage Items -->
    <section id="panel-items" class="dash-panel hidden">
      <div class="section-title"><h2>Manage Items</h2></div>
      <div class="table-wrap">
        <div class="table-toolbar">
          <div class="flex"><input type="checkbox" id="selectAllItems"><label style="margin:0;">Select All</label></div>
          <div class="flex">
            <button class="btn btn-danger btn-sm" id="adminBulkDeleteBtn">🗑️ Bulk Delete</button>
            <button class="btn btn-secondary btn-sm" onclick="exportTableToCSV('adminItemsTable','items_export.csv')">⬇️ CSV</button>
            <button class="btn btn-secondary btn-sm" onclick="exportTableToExcel('adminItemsTable','items_export.xlsx')">⬇️ Excel</button>
            <button class="btn btn-secondary btn-sm" onclick="exportTableToPDF('adminItemsTable','Items Report')">⬇️ PDF</button>
          </div>
        </div>
        <table id="adminItemsTable">
          <thead><tr><th></th><th>Title</th><th>Type</th><th>Category</th><th>Reporter</th><th>Contact</th><th>Status</th><th>Approved</th><th class="no-export">Actions</th></tr></thead>
          <tbody>
          <?php foreach ($allItems as $i): ?>
            <tr>
              <td class="no-export"><input type="checkbox" class="item-checkbox" value="<?= (int)$i['id'] ?>"></td>
              <td><?= h($i['title']) ?></td>
              <td><span class="badge badge-<?= h($i['type']) ?>"><?= ucfirst($i['type']) ?></span></td>
              <td><?= h($i['category']) ?></td>
              <td><?= h($i['reporter_name']) ?></td>
              <td><?= h($i['reporter_email']) ?> / <?= h($i['reporter_phone']) ?></td>
              <td><span class="badge badge-<?= h($i['status']) ?>"><?= ucwords(str_replace('_',' ',$i['status'])) ?></span></td>
              <td><?= $i['is_approved'] ? '✅' : '⏳' ?></td>
              <td class="no-export"><button class="btn btn-sm btn-danger" onclick="deleteItem(<?= (int)$i['id'] ?>, <?= h(json_encode($i['title'])) ?>)">🗑️ Delete</button></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Manage Users -->
    <section id="panel-users" class="dash-panel hidden">
      <div class="section-title">
        <h2>Manage Users</h2>
        <span class="text-muted">Showing <span id="usersVisibleCount"><?= count($allUsers) ?></span> of <?= count($allUsers) ?></span>
      </div>
      <div class="alert alert-info">
        <strong>Blocking a user</strong> signs them out immediately, stops them logging in, and hides their listings from the public pages.
        Nothing is deleted &mdash; you can unblock them at any time. Use <em>Delete</em> only to erase an account and all its data permanently.
      </div>
      <div class="table-wrap">
        <div class="table-toolbar">
          <div class="flex">
            <select id="userStatusFilter" style="width:auto;">
              <option value="">All accounts</option>
              <option value="active">Active</option>
              <option value="blocked">Blocked</option>
            </select>
            <input type="text" id="userSearch" placeholder="Search name, email, phone…" style="width:auto;">
          </div>
          <div class="flex">
            <button class="btn btn-secondary btn-sm" onclick="exportTableToCSV('adminUsersTable','users_export.csv')">⬇️ CSV</button>
            <button class="btn btn-secondary btn-sm" onclick="exportTableToExcel('adminUsersTable','users_export.xlsx')">⬇️ Excel</button>
          </div>
        </div>
        <table id="adminUsersTable">
          <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Role</th><th>Reports</th><th>Claims</th><th>Status</th><th>Joined</th><th class="no-export">Actions</th></tr></thead>
          <tbody id="usersBody">
          <?php foreach ($allUsers as $u): ?>
            <?php $isBlocked = $u['status'] !== 'active'; ?>
            <tr data-account="<?= $isBlocked ? 'blocked' : 'active' ?>">
              <td><?= h($u['full_name']) ?></td>
              <td><?= h($u['email']) ?></td>
              <td><?= h($u['phone']) ?></td>
              <td><?= ucfirst($u['role']) ?></td>
              <td><?= (int) $u['report_count'] ?></td>
              <td><?= (int) $u['claim_count'] ?></td>
              <td>
                <span class="badge badge-<?= $isBlocked ? 'rejected' : 'active' ?>"><?= h(accountStatusLabel($u['status'])) ?></span>
                <?php if ($isBlocked && !empty($u['blocked_reason'])): ?>
                  <div class="text-muted" style="font-size:.78rem;max-width:220px;">“<?= h($u['blocked_reason']) ?>”</div>
                <?php endif; ?>
              </td>
              <td><?= formatDate($u['created_at']) ?></td>
              <td class="no-export">
                <?php if ($u['role'] !== 'admin'): ?>
                  <?php if (!$isBlocked): ?>
                    <button class="btn btn-sm btn-danger" onclick="blockUser(<?= (int)$u['id'] ?>, <?= h(json_encode($u['full_name'])) ?>)">🚫 Block</button>
                  <?php else: ?>
                    <button class="btn btn-sm btn-success" onclick="manageUser(<?= (int)$u['id'] ?>,'unblock')">✅ Unblock</button>
                  <?php endif; ?>
                  <button class="btn btn-sm btn-secondary" onclick="manageUser(<?= (int)$u['id'] ?>,'delete')">Delete</button>
                <?php else: ?><span class="text-muted">—</span><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <tr id="usersNoMatch" class="hidden"><td colspan="9" class="text-muted">No users match this filter.</td></tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Support Messages -->
    <section id="panel-support" class="dash-panel hidden">
      <div class="section-title">
        <h2>Support Messages</h2>
        <span class="text-muted">Showing <span id="supportVisibleCount"><?= count($supportMessages) ?></span> of <?= count($supportMessages) ?></span>
      </div>
      <div class="table-wrap">
        <div class="table-toolbar">
          <div class="flex">
            <select id="supportStatusFilter" style="width:auto;">
              <option value="">All statuses</option>
              <option value="new">New</option>
              <option value="in_progress">In progress</option>
              <option value="resolved">Resolved</option>
            </select>
            <input type="text" id="supportSearch" placeholder="Search subject, name, email…" style="width:auto;">
          </div>
        </div>
        <table id="supportTable">
          <thead><tr><th>Received</th><th>From</th><th>Topic</th><th>Subject</th><th>Status</th><th class="no-export">Actions</th></tr></thead>
          <tbody id="supportBody">
          <?php foreach ($supportMessages as $m): ?>
            <tr data-status="<?= h($m['status']) ?>">
              <td><?= formatDateTime($m['created_at']) ?></td>
              <td>
                <?= h($m['name']) ?>
                <div class="text-muted" style="font-size:.8rem;"><?= h($m['email']) ?><?= $m['user_id'] ? '' : ' · guest' ?></div>
                <?php if ($m['account_status'] !== null && $m['account_status'] !== 'active'): ?><span class="badge badge-rejected">Blocked user</span><?php endif; ?>
              </td>
              <td><?= h($supportCategories[$m['category']] ?? 'Other') ?></td>
              <td><?= h($m['subject']) ?></td>
              <td><span class="badge badge-support-<?= h($m['status']) ?>"><?= h(supportStatusLabel($m['status'])) ?></span></td>
              <td class="no-export">
                <button class="btn btn-sm btn-primary" onclick="openSupport(<?= (int)$m['id'] ?>)">Open / Reply</button>
              </td>
            </tr>
          <?php endforeach; ?>
          <tr id="supportNoMatch" class="hidden"><td colspan="6" class="text-muted">No messages match this filter.</td></tr>
          <?php if (empty($supportMessages)): ?><tr><td colspan="6" class="text-muted">No messages yet. They appear here when users use the Contact page.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Admin Profile -->
    <section id="panel-profile" class="dash-panel hidden">
      <div class="section-title"><h2>My Profile</h2></div>
      <div class="card" style="max-width:520px;">
        <?= $profileMsg ?>
        <form method="POST" action="admin_dashboard.php#profile">
          <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
          <input type="hidden" name="form" value="profile">
          <div class="form-group">
            <label class="required">Full Name</label>
            <input type="text" name="full_name" value="<?= h($admin['full_name']) ?>" required>
          </div>
          <div class="form-group">
            <label>Email Address</label>
            <input type="email" value="<?= h($admin['email']) ?>" disabled>
            <div class="form-hint">Admin email changes must be made directly in the database.</div>
          </div>
          <div class="form-group">
            <label class="required">Phone Number</label>
            <input type="tel" name="phone" value="<?= h($admin['phone']) ?>" required>
          </div>
          <div class="form-group">
            <label for="new_password">New Password (leave blank to keep current)</label>
            <input type="password" id="new_password" name="new_password" minlength="8" maxlength="72"
                   autocomplete="new-password" data-password-policy>
          </div>
          <div class="form-group">
            <label for="confirm_new_password">Confirm New Password</label>
            <input type="password" id="confirm_new_password" name="confirm_new_password" maxlength="72"
                   autocomplete="off" data-confirm-for="new_password">
          </div>
          <div class="form-group">
            <label>Theme</label>
            <select name="theme" id="themeSelect">
              <?php foreach (['default' => 'Default (match my device)', 'light' => 'Light', 'dark' => 'Dark'] as $value => $label): ?>
                <option value="<?= h($value) ?>" <?= normalizeTheme($admin['theme'] ?? 'default') === $value ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-hint">Previewed instantly; saved when you submit this form.</div>
          </div>
          <button type="submit" class="btn btn-primary btn-block">Save Changes</button>
        </form>
      </div>
    </section>

    <!-- System Settings -->
    <section id="panel-settings" class="dash-panel hidden">
      <div class="section-title"><h2>System Settings</h2></div>
      <div class="card">
        <p>Storage driver, upload limits, and cloud credentials are configured in <code>config.php</code>.</p>
        <ul>
          <li>Current storage driver: <strong><?= h(STORAGE_DRIVER) ?></strong></li>
          <li>Max upload size: <strong><?= MAX_UPLOAD_SIZE / 1024 / 1024 ?> MB</strong></li>
          <li>App URL: <strong><?= h(APP_URL) ?></strong></li>
        </ul>
        <p class="text-muted">To enable Cloudinary or AWS S3, set the relevant environment variables or edit config.php directly.</p>
        <h3 class="mt-2">Server-Side Reports</h3>
        <p class="text-muted">Generate reports directly from the server (useful for scheduled exports or large datasets):</p>
        <div class="flex">
          <a class="btn btn-secondary btn-sm" href="exports/export_csv.php?type=items">⬇️ Items CSV</a>
          <a class="btn btn-secondary btn-sm" href="exports/export_csv.php?type=users">⬇️ Users CSV</a>
          <a class="btn btn-secondary btn-sm" href="exports/export_pdf.php?type=items" target="_blank">🖨️ Items PDF</a>
          <a class="btn btn-secondary btn-sm" href="exports/export_pdf.php?type=users" target="_blank">🖨️ Users PDF</a>
        </div>
      </div>
    </section>
  </div>
</div>

<!-- Support message modal (filled by openSupport() in js/main.js) -->
<div class="modal-overlay" id="supportModal">
  <div class="modal-box" style="max-width:680px;">
    <button class="modal-close" data-close-modal>&times;</button>
    <h2 id="supportModalTitle">Message</h2>
    <div id="supportModalBody"><p class="text-muted">Loading…</p></div>
  </div>
</div>

<!-- Report detail modal (filled by viewReport() in js/main.js) -->
<div class="modal-overlay" id="reportModal">
  <div class="modal-box" style="max-width:680px;">
    <button class="modal-close" data-close-modal>&times;</button>
    <h2 id="reportModalTitle">Report</h2>
    <div id="reportModalBody"><p class="text-muted">Loading…</p></div>
  </div>
</div>

<script>
const monthLabels = <?= json_encode($months) ?>;
const lostData = <?= json_encode($lostSeries) ?>;
const foundData = <?= json_encode($foundSeries) ?>;
window.addEventListener('DOMContentLoaded', () => {
  const ctx = document.getElementById('trendChart');
  if (ctx && window.Chart) {
    // Chart.js doesn't read CSS, so feed it the current theme's colours.
    const css = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    const themeChart = (chart) => {
      chart.options.color = css('--muted');
      chart.options.scales.x.ticks.color = css('--muted');
      chart.options.scales.y.ticks.color = css('--muted');
      chart.options.scales.x.grid.color = css('--border');
      chart.options.scales.y.grid.color = css('--border');
      chart.update('none');
    };
    const trendChart = new Chart(ctx, {
      type: 'line',
      data: {
        labels: monthLabels,
        datasets: [
          { label: 'Lost Reports', data: lostData, borderColor: '#dc2626', backgroundColor: 'rgba(220,38,38,0.1)', tension: 0.3 },
          { label: 'Found Reports', data: foundData, borderColor: '#16a34a', backgroundColor: 'rgba(22,163,74,0.1)', tension: 0.3 },
        ]
      },
      options: {
        responsive: true,
        plugins: { legend: { position: 'bottom' } },
        scales: { x: { ticks: {}, grid: {} }, y: { beginAtZero: true, ticks: { precision: 0 }, grid: {} } }
      }
    });
    themeChart(trendChart);
    // Re-colour when the theme (or the OS light/dark setting) changes.
    document.addEventListener('themechange', () => themeChart(trendChart));
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => setTimeout(() => themeChart(trendChart), 50));
  }
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
