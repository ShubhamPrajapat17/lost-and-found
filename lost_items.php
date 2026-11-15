<?php
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'Lost Items';
include __DIR__ . '/includes/header.php';
?>
<div class="container section">
  <div class="section-title">
    <h2>🔴 Lost Items</h2>
    <div class="flex">
      <button class="btn btn-secondary btn-sm" id="viewGridBtn">🖼️ Grid View</button>
      <?php if (isLoggedIn()): ?>
        <button class="btn btn-secondary btn-sm" id="viewListBtn">📋 My List View</button>
      <?php endif; ?>
      <a href="report_lost.php" class="btn btn-primary btn-sm">+ Report Lost Item</a>
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
  <div id="gridView" class="item-grid"><p class="text-muted">Loading lost items…</p></div>

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

<script>
window.ITEM_TYPE = 'lost';
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
