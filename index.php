<?php
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'Home';
include __DIR__ . '/includes/header.php';
?>

<section class="hero">
  <div class="container">
    <h1>Find What's Lost. Return What's Found.</h1>
    <p>Easily search and report lost or found items in your area. Every listing is carefully reviewed before being published, while your personal information stays private until a claim is verified.</p>
    <div class="hero-actions">
      <a href="report_lost.php" class="btn btn-primary">📝 Report a Lost Item</a>
      <a href="report_found.php" class="btn btn-secondary">📦 Report a Found Item</a>
    </div>
  </div>
</section>

<div class="container">
  <div class="search-card">
    <form id="quickSearchForm" class="search-grid">
      <input type="text" name="keyword" id="q_keyword" placeholder="Search by keyword, e.g. 'black wallet'">
      <select name="category" id="q_category">
        <option value="">All Categories</option>
        <option value="Electronics">Electronics</option>
        <option value="Documents">Documents</option>
        <option value="Keys">Keys</option>
        <option value="Bags">Bags</option>
        <option value="Jewelry">Jewelry</option>
        <option value="Pets">Pets</option>
        <option value="Other">Other</option>
      </select>
      <input type="date" name="date_from" id="q_date_from" title="From date">
      <input type="text" name="location" id="q_location" placeholder="Location / area">
      <button type="submit" class="btn btn-primary">Search</button>
    </form>
    <div class="mt-1" style="text-align:center;">
      <button type="button" class="btn btn-secondary btn-sm" id="openLookupModal">🔍 Quick Single Item Lookup</button>
    </div>
    <div id="quickResults" class="mt-2"></div>
  </div>
</div>

<section class="section">
  <div class="container">
    <div class="section-title"><h2>Recently Reported</h2><a href="lost_items.php">View all lost items →</a></div>
    <div id="recentItems" class="item-grid">
      <p class="text-muted">Loading recent listings…</p>
    </div>
  </div>
</section>

<section class="section section-alt">
  <div class="container">
    <div class="section-title"><h2>How It Works</h2></div>
    <div class="item-grid">
      <div class="card"><h3>1. Report</h3><p class="text-muted">Submit a detailed lost or found report with photo, date, time and specific location.</p></div>
      <div class="card"><h3>2. Review</h3><p class="text-muted">Our admin team reviews every submission before it goes public, keeping listings trustworthy.</p></div>
      <div class="card"><h3>3. Claim & Verify</h3><p class="text-muted">Claimants submit verification proof. Your contact details stay hidden until a claim is approved.</p></div>
      <div class="card"><h3>4. Reunite</h3><p class="text-muted">Chat securely in-app to arrange the handover — no personal number shared until you're ready.</p></div>
    </div>
  </div>
</section>

<!-- Quick Single Item Lookup Modal -->
<div class="modal-overlay" id="lookupModal">
  <div class="modal-box">
    <button class="modal-close" data-close-modal>&times;</button>
    <h2>Quick Item Lookup</h2>
    <p class="text-muted">Enter an item title or reference keyword to quickly check if it has been reported.</p>
    <div class="form-group">
      <label>Item keyword</label>
      <input type="text" id="lookupKeyword" placeholder="e.g. iPhone 13, blue backpack, car keys">
    </div>
    <button class="btn btn-primary btn-block" id="lookupSubmit">Look Up Item</button>
    <div id="lookupResult" class="mt-2"></div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
