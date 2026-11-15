<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();
$user = currentUser();
$db = getDB();

// Handle profile update
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
        } else {
            if ($newPassword !== '') {
                if (passwordPolicyMessage($newPassword) !== '') {
                    $profileMsg = '<div class="alert alert-error">' . h(passwordPolicyMessage($newPassword)) . '</div>';
                } elseif ($newPassword !== $confirmPassword) {
                    $profileMsg = '<div class="alert alert-error">The new passwords do not match.</div>';
                } else {
                    $db->prepare('UPDATE users SET full_name=?, phone=?, theme=?, password_hash=? WHERE id=?')
                       ->execute([$fullName, $phone, $theme, password_hash($newPassword, PASSWORD_BCRYPT), $user['id']]);
                }
            } else {
                $db->prepare('UPDATE users SET full_name=?, phone=?, theme=? WHERE id=?')
                   ->execute([$fullName, $phone, $theme, $user['id']]);
            }
            if (!$profileMsg) {
                $_SESSION['user']['full_name'] = $fullName;
                $_SESSION['user']['phone'] = $phone;
                $_SESSION['user']['theme'] = $theme;
                rememberThemeCookie($theme);
                $user = currentUser();
                $profileMsg = '<div class="alert alert-success">Profile updated successfully.</div>';
            }
        }
    }
}

// My reports (lost + found)
$reportsStmt = $db->prepare('SELECT * FROM items WHERE user_id = ? ORDER BY created_at DESC');
$reportsStmt->execute([$user['id']]);
$myReports = $reportsStmt->fetchAll();

// My claims
$claimsStmt = $db->prepare(
    'SELECT c.*, i.title AS item_title, i.type AS item_type FROM claims c JOIN items i ON i.id = c.item_id WHERE c.claimant_id = ? ORDER BY c.created_at DESC'
);
$claimsStmt->execute([$user['id']]);
$myClaims = $claimsStmt->fetchAll();

// Claims received on my found items (as finder, to verify)
$receivedStmt = $db->prepare(
    'SELECT c.*, i.title AS item_title FROM claims c JOIN items i ON i.id = c.item_id WHERE i.user_id = ? ORDER BY c.created_at DESC'
);
$receivedStmt->execute([$user['id']]);
$receivedClaims = $receivedStmt->fetchAll();

$pageTitle = 'My Dashboard';
include __DIR__ . '/includes/header.php';
?>
<div class="container dashboard">
  <aside class="sidebar">
    <h4>My Account</h4>
    <nav>
      <a href="#reports" class="dash-tab active" data-tab="reports">📄 My Reports</a>
      <a href="#claims" class="dash-tab" data-tab="claims">🤝 My Claims</a>
      <a href="#received" class="dash-tab" data-tab="received">📥 Claims on My Items</a>
      <a href="#settings" class="dash-tab" data-tab="settings">⚙️ Settings</a>
    </nav>
    <h4 class="mt-2">Quick Actions</h4>
    <nav>
      <a href="report_lost.php">➕ Report Lost</a>
      <a href="report_found.php">➕ Report Found</a>
    </nav>
    <h4 class="mt-2">Need help?</h4>
    <nav>
      <a href="contact.php">💬 Contact Admin</a>
    </nav>
  </aside>

  <div class="dashboard-main">
    <!-- My Reports -->
    <section id="panel-reports" class="dash-panel">
      <div class="section-title"><h2>My Reports</h2></div>
      <div class="table-wrap">
        <div class="table-toolbar">
          <span class="text-muted"><?= count($myReports) ?> report(s)</span>
          <div class="flex">
            <button class="btn btn-secondary btn-sm" onclick="exportTableToCSV('myReportsTable','my_reports.csv')">⬇️ CSV</button>
            <button class="btn btn-secondary btn-sm" onclick="exportTableToExcel('myReportsTable','my_reports.xlsx')">⬇️ Excel</button>
            <button class="btn btn-secondary btn-sm" onclick="exportTableToPDF('myReportsTable','My Reports')">⬇️ PDF</button>
          </div>
        </div>
        <table id="myReportsTable">
          <thead><tr><th>Title</th><th>Type</th><th>Category</th><th>Date</th><th>Status</th><th>Approved</th><th>Claim</th><th class="no-export">Actions</th></tr></thead>
          <tbody>
          <?php if (empty($myReports)): ?>
            <tr><td colspan="8" class="text-muted">You haven't submitted any reports yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($myReports as $r): ?>
            <tr>
              <td><?= h($r['title']) ?></td>
              <td><span class="badge badge-<?= h($r['type']) ?>"><?= ucfirst($r['type']) ?></span></td>
              <td><?= h($r['category']) ?></td>
              <td><?= formatDate($r['item_date']) ?></td>
              <td><span class="badge badge-<?= h($r['status']) ?>"><?= ucwords(str_replace('_',' ',$r['status'])) ?></span></td>
              <td><?= $r['is_approved'] ? '✅' : '⏳ Pending' ?></td>
              <td>
                <?php if (!empty($r['owner_claimed_at'])): ?>
                  <span class="badge badge-approved">Claimed by you</span>
                <?php elseif (!empty($r['admin_claimed_at'])): ?>
                  <span class="badge badge-claimed">Ready to claim</span>
                <?php else: ?>
                  <span class="badge badge-pending">Awaiting admin</span>
                <?php endif; ?>
              </td>
              <td class="no-export actions-cell">
                <?php
                  $approved     = (int) $r['is_approved'] === 1;
                  $adminClaimed = !empty($r['admin_claimed_at']);
                  $ownerClaimed = !empty($r['owner_claimed_at']);
                  $closed       = in_array($r['status'], ['resolved', 'handed_over', 'rejected'], true);
                ?>
                <?php if ($closed): ?>
                  <span class="text-muted">&mdash;</span>
                <?php elseif (!$approved): ?>
                  <span class="text-muted">Awaiting admin approval</span>
                <?php else: ?>
                  <?php
                    // Claim unlocks only after an admin has claimed the report.
                    $claimDisabled = $ownerClaimed || !$adminClaimed;
                    if ($ownerClaimed) {
                        $claimHint = 'You claimed this report on ' . formatDateTime($r['owner_claimed_at']) . '.';
                    } elseif (!$adminClaimed) {
                        $claimHint = 'An administrator has not claimed this report yet.';
                    } else {
                        $claimHint = 'Claim this report to unlock Mark Resolved.';
                    }
                    $resolveHint = $ownerClaimed
                        ? 'Mark this report resolved.'
                        : 'Claim the report first to unlock this.';
                  ?>
                  <button class="btn btn-sm btn-primary"
                          onclick="claimReport(<?= (int) $r['id'] ?>, 'owner')"
                          title="<?= h($claimHint) ?>"
                          <?= $claimDisabled ? 'disabled' : '' ?>>
                    <?= $ownerClaimed ? '✓ Claimed' : 'Claim' ?>
                  </button>
                  <button class="btn btn-sm btn-secondary"
                          onclick="markResolved(<?= (int) $r['id'] ?>)"
                          title="<?= h($resolveHint) ?>"
                          <?= $ownerClaimed ? '' : 'disabled' ?>>
                    Mark Resolved
                  </button>
                <?php endif; ?>
                <button class="btn btn-sm btn-danger"
                        onclick="deleteItem(<?= (int) $r['id'] ?>, <?= h(json_encode($r['title'])) ?>)"
                        title="Delete this report (e.g. if you made a mistake)">🗑️ Delete</button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- My Claims -->
    <section id="panel-claims" class="dash-panel hidden">
      <div class="section-title"><h2>My Claims (Track Status)</h2></div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Item</th><th>Proof Type</th><th>Status</th><th>Submitted</th><th>Chat</th></tr></thead>
          <tbody>
          <?php if (empty($myClaims)): ?>
            <tr><td colspan="5" class="text-muted">You haven't claimed any items yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($myClaims as $c): ?>
            <tr>
              <td><?= h($c['item_title']) ?></td>
              <td><?= $c['proof_type'] === 'document' ? 'Document' : 'Secret Detail' ?></td>
              <td><span class="badge badge-<?= h($c['status']) ?>"><?= ucfirst($c['status']) ?></span></td>
              <td><?= formatDateTime($c['created_at']) ?></td>
              <td>
                <?php if ($c['status'] === 'approved'): ?>
                  <button class="btn btn-sm btn-primary" onclick="openChat(<?= (int)$c['id'] ?>)">💬 Chat</button>
                <?php else: ?>
                  <span class="text-muted">Locked</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Claims received on my found items -->
    <section id="panel-received" class="dash-panel hidden">
      <div class="section-title"><h2>Claims on My Found Items</h2></div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Item</th><th>Claimant</th><th>Proof</th><th>Status</th><th>Actions</th></tr></thead>
          <tbody>
          <?php if (empty($receivedClaims)): ?>
            <tr><td colspan="5" class="text-muted">No claims have been submitted on your items yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($receivedClaims as $c): ?>
            <tr>
              <td><?= h($c['item_title']) ?></td>
              <td><?= h($c['claimant_name']) ?></td>
              <td><?= $c['proof_type'] === 'document'
                    ? ($c['proof_file'] ? '<a href="' . h($c['proof_file']) . '" target="_blank">View File</a>' : 'Document')
                    : h($c['proof_text']) ?></td>
              <td><span class="badge badge-<?= h($c['status']) ?>"><?= ucfirst($c['status']) ?></span></td>
              <td>
                <?php if ($c['status'] === 'pending'): ?>
                  <button class="btn btn-sm btn-success" onclick="reviewClaim(<?= (int)$c['id'] ?>,'approve')">Approve</button>
                  <button class="btn btn-sm btn-danger" onclick="reviewClaim(<?= (int)$c['id'] ?>,'reject')">Reject</button>
                <?php elseif ($c['status'] === 'approved'): ?>
                  <button class="btn btn-sm btn-primary" onclick="openChat(<?= (int)$c['id'] ?>)">💬 Chat</button>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Settings -->
    <section id="panel-settings" class="dash-panel hidden">
      <div class="section-title"><h2>Profile Settings</h2></div>
      <div class="card" style="max-width:520px;">
        <?= $profileMsg ?>
        <form method="POST" action="user_dashboard.php#settings">
          <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
          <input type="hidden" name="form" value="profile">
          <div class="form-group">
            <label class="required">Full Name</label>
            <input type="text" name="full_name" value="<?= h($user['full_name']) ?>" required>
          </div>
          <div class="form-group">
            <label>Email Address</label>
            <input type="email" value="<?= h($user['email']) ?>" disabled>
            <div class="form-hint">Email cannot be changed. Contact admin if needed.</div>
          </div>
          <div class="form-group">
            <label class="required">Phone Number</label>
            <input type="tel" name="phone" value="<?= h($user['phone']) ?>" required>
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
                <option value="<?= h($value) ?>" <?= normalizeTheme($user['theme'] ?? 'default') === $value ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-hint">Previewed instantly; saved when you submit this form.</div>
          </div>
          <button type="submit" class="btn btn-primary btn-block">Save Changes</button>
        </form>
      </div>
    </section>
  </div>
</div>

<!-- Chat Modal -->
<div class="modal-overlay" id="chatModal">
  <div class="modal-box" style="max-width:520px;">
    <button class="modal-close" data-close-modal>&times;</button>
    <h2 id="chatItemTitle">Chat</h2>
    <div class="chat-box">
      <div class="chat-messages" id="chatMessages"></div>
      <div class="chat-input-row">
        <input type="text" id="chatInput" placeholder="Type a message...">
        <button class="btn btn-primary" id="chatSendBtn">Send</button>
      </div>
    </div>
  </div>
</div>

<script>window.CURRENT_CLAIM_ID = null;</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
