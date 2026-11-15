<?php
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'Found Items';
include __DIR__ . '/includes/header.php';
?>
<div class="container section">
  <div class="section-title">
    <h2>🟢 Found Items</h2>
    <div class="flex">
      <button class="btn btn-secondary btn-sm" id="viewGridBtn">🖼️ Grid View</button>
      <?php if (isLoggedIn()): ?>
        <button class="btn btn-secondary btn-sm" id="viewListBtn">📋 My List View</button>
      <?php endif; ?>
      <a href="report_found.php" class="btn btn-primary btn-sm">+ Report Found Item</a>
    </div>
  </div>

  <div class="search-card" style="margin:0 0 24px;max-width:none;">
    <form id="filterForm" class="search-grid">
      <input type="text" name="keyword" placeholder="Search keyword...">
      <select name="category">
        <option value="">All Categories</option>
        <option value="Electronics">Electronics</option>
        <option value="Documents">Documents</option>
        <option value="Keys">Keys</option>
        <option value="Bags">Bags</option>
        <option value="Jewelry">Jewelry</option>
        <option value="Pets">Pets</option>
        <option value="Other">Other</option>
      </select>
      <input type="date" name="date_from" title="From">
      <input type="date" name="date_to" title="To">
      <button type="submit" class="btn btn-primary">Filter</button>
    </form>
    <input type="text" name="location" form="filterForm" placeholder="Location contains..." class="mt-1">
  </div>

  <!-- Grid view (public) -->
  <div id="gridView" class="item-grid"><p class="text-muted">Loading found items…</p></div>

  <!-- List / manage view (logged-in users, own items) -->
  <div id="listView" class="hidden">
    <div class="table-wrap">
      <div class="table-toolbar">
        <div class="flex">
          <input type="checkbox" id="selectAllLost">
          <label for="selectAllLost" style="margin:0;">Select All</label>
        </div>
        <button class="btn btn-danger btn-sm" id="bulkDeleteLostBtn">🗑️ Bulk Delete Selected</button>
      </div>
      <table>
        <thead>
          <tr><th></th><th>Title</th><th>Category</th><th>Date</th><th>Location</th><th>Status</th><th>Reported</th><th>Actions</th></tr>
        </thead>
        <tbody id="lostListBody"><tr><td colspan="8" class="text-muted">Loading…</td></tr></tbody>
      </table>
    </div>
  </div>
</div>

<!-- Claim Modal -->
<div class="modal-overlay" id="claimModal">
  <div class="modal-box">
    <button class="modal-close" data-close-modal>&times;</button>
    <h2>Claim This Item</h2>
    <p class="text-muted">Provide verification details. The finder / admin must approve your claim before contact details are exchanged.</p>
    <form id="claimForm">
      <input type="hidden" name="item_id" id="claimItemId">
      <div class="form-group">
        <label class="required">Your Full Name</label>
        <input type="text" name="claimant_name" required>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="required">Your Phone</label>
          <input type="tel" name="claimant_phone" required>
        </div>
        <div class="form-group">
          <label class="required">Your Email</label>
          <input type="email" name="claimant_email" required>
        </div>
      </div>
      <div class="form-group">
        <label>Proof Type</label>
        <select name="proof_type" id="proofType">
          <option value="secret_detail">Describe a secret/identifying detail</option>
          <option value="document">Upload invoice / proof photo</option>
        </select>
      </div>
      <div class="form-group" id="proofTextGroup">
        <label>Identifying Detail</label>
        <textarea name="proof_text" placeholder="Describe a detail that proves this item is yours"></textarea>
      </div>
      <div class="form-group hidden" id="proofFileGroup">
        <label>Upload Proof</label>
        <input type="file" name="proof_file" accept="image/*,.pdf">
      </div>
      <button type="submit" class="btn btn-primary btn-block">Submit Claim</button>
      <div id="claimResult" class="mt-1"></div>
    </form>
  </div>
</div>

<script>
window.ITEM_TYPE = 'found';
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
