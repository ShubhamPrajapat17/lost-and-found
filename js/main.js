/* =====================================================================
   Lost & Found — main.js
   Handles navigation, AJAX search/filter, modals, dashboards, chat,
   claims, and admin moderation actions.
   ===================================================================== */

document.addEventListener('DOMContentLoaded', () => {
  initNavToggle();
  initModals();
  initQuickSearch();
  initItemListingPages();
  initDashboardTabs();
  initClaimForm();
  initChatWidgets();
  initBulkDelete();
  initAdminBulkDelete();
  initThemePreview();
  initProfileMenu();
  initIncomingReportsFilter();
  initTableFilter({ select: 'userStatusFilter', search: 'userSearch', tbody: 'usersBody', counter: 'usersVisibleCount', noMatch: 'usersNoMatch', attr: 'account' });
  initTableFilter({ select: 'supportStatusFilter', search: 'supportSearch', tbody: 'supportBody', counter: 'supportVisibleCount', noMatch: 'supportNoMatch', attr: 'status' });
  initThemeMenu();
});

/* ---------------------------- Utilities -------------------------------- */

async function apiFetch(url, options = {}) {
  const res = await fetch(url, options);
  let data;
  try {
    data = await res.json();
  } catch (e) {
    data = { success: false, message: 'Unexpected server response.' };
  }
  return data;
}

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str ?? '';
  return div.innerHTML;
}

/** Safely embed any string as a JS argument inside a double-quoted onclick="…" attribute. */
function jsArg(value) {
  return escapeHtml(JSON.stringify(String(value ?? ''))).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function badgeClass(status) {
  return 'badge badge-' + status;
}

function formatDate(dateStr) {
  const d = new Date(dateStr);
  if (isNaN(d)) return dateStr;
  return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

/* ---------------------------- Nav toggle -------------------------------- */

function initNavToggle() {
  const toggle = document.getElementById('navToggle');
  const nav = document.getElementById('siteNav');
  if (toggle && nav) {
    toggle.addEventListener('click', () => nav.classList.toggle('open'));
  }
}

/* ---------------------------- Modal handling ----------------------------- */

function initModals() {
  document.querySelectorAll('[data-close-modal]').forEach(btn => {
    btn.addEventListener('click', () => btn.closest('.modal-overlay').classList.remove('open'));
  });
  document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) overlay.classList.remove('open');
    });
  });

  const openLookupBtn = document.getElementById('openLookupModal');
  if (openLookupBtn) {
    openLookupBtn.addEventListener('click', () => {
      document.getElementById('lookupModal').classList.add('open');
    });
  }

  const lookupSubmit = document.getElementById('lookupSubmit');
  if (lookupSubmit) {
    lookupSubmit.addEventListener('click', async () => {
      const keyword = document.getElementById('lookupKeyword').value.trim();
      const resultBox = document.getElementById('lookupResult');
      if (!keyword) {
        resultBox.innerHTML = '<div class="alert alert-error">Please enter a keyword.</div>';
        return;
      }
      resultBox.innerHTML = '<p class="text-muted">Searching…</p>';
      const data = await apiFetch('api/lookup.php?keyword=' + encodeURIComponent(keyword));
      if (!data.success) {
        resultBox.innerHTML = `<div class="alert alert-error">${escapeHtml(data.message || 'Lookup failed.')}</div>`;
        return;
      }
      if (data.items.length === 0) {
        resultBox.innerHTML = '<p class="text-muted">No matching items found.</p>';
        return;
      }
      resultBox.innerHTML = data.items.map(item => `
        <div class="flex-between" style="padding:10px 0;border-bottom:1px solid var(--border);">
          <div>
            <strong>${escapeHtml(item.title)}</strong>
            <div class="text-muted" style="font-size:0.82rem;">${escapeHtml(item.category)} · ${formatDate(item.item_date)} · ${escapeHtml(item.location)}</div>
          </div>
          <span class="${badgeClass(item.type)}">${item.type}</span>
        </div>
      `).join('');
    });
  }
}

/* ---------------------------- Quick search on homepage -------------------- */

function initQuickSearch() {
  const form = document.getElementById('quickSearchForm');
  if (!form) return;

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    await runQuickSearch();
  });

  loadRecentItems();
}

async function runQuickSearch() {
  const form = document.getElementById('quickSearchForm');
  const params = new URLSearchParams();
  const keyword = document.getElementById('q_keyword').value.trim();
  const category = document.getElementById('q_category').value;
  const dateFrom = document.getElementById('q_date_from').value;
  const location = document.getElementById('q_location').value.trim();
  if (keyword) params.set('keyword', keyword);
  if (category) params.set('category', category);
  if (dateFrom) params.set('date_from', dateFrom);
  if (location) params.set('location', location);
  params.set('limit', '12');

  const resultBox = document.getElementById('quickResults');
  resultBox.innerHTML = '<p class="text-muted">Searching…</p>';
  const data = await apiFetch('api/search.php?' + params.toString());
  if (!data.success) {
    resultBox.innerHTML = `<div class="alert alert-error">${escapeHtml(data.message || 'Search failed.')}</div>`;
    return;
  }
  if (data.items.length === 0) {
    resultBox.innerHTML = '<p class="text-muted">No items matched your search.</p>';
    return;
  }
  resultBox.innerHTML = '<div class="item-grid">' + data.items.map(renderItemCard).join('') + '</div>';
}

async function loadRecentItems() {
  const container = document.getElementById('recentItems');
  if (!container) return;
  const data = await apiFetch('api/search.php?limit=6');
  if (!data.success || data.items.length === 0) {
    container.innerHTML = '<p class="text-muted">No listings yet. Be the first to report an item!</p>';
    return;
  }
  container.innerHTML = data.items.map(renderItemCard).join('');
}

/* ---------------------------- Item card rendering -------------------------- */

function renderItemCard(item) {
  const img = item.image_url
    ? `<img src="${escapeHtml(item.image_url)}" alt="${escapeHtml(item.title)}">`
    : `<div class="no-image">📷</div>`;

  const claimBtn = (item.type === 'found' && window.IS_LOGGED_IN && !item.is_owner && item.status === 'active')
    ? `<button class="btn btn-sm btn-primary" onclick="openClaimModal(${item.id})">Claim</button>`
    : '';

  // Owners can remove their own listing straight from the card.
  const deleteBtn = item.is_owner
    ? `<button class="btn btn-sm btn-danger" onclick="deleteItem(${item.id}, ${jsArg(item.title)})">🗑️ Delete</button>`
    : '';

  return `
    <div class="item-card">
      ${img}
      <div class="item-card-body">
        <div class="flex-between">
          <h3>${escapeHtml(item.title)}</h3>
          <span class="${badgeClass(item.type)}">${item.type}</span>
        </div>
        <div class="item-meta">${escapeHtml(item.category)} · ${formatDate(item.item_date)}</div>
        <div class="item-meta">📍 ${escapeHtml(item.location)}</div>
        <p style="font-size:0.88rem;flex:1;">${escapeHtml((item.description || '').slice(0, 100))}${item.description && item.description.length > 100 ? '…' : ''}</p>
        <div class="item-meta">✉️ ${escapeHtml(item.contact_email)} · 📞 ${escapeHtml(item.contact_phone)}</div>
      </div>
      <div class="item-card-footer">
        <span class="${badgeClass(item.status)}">${item.status.replace('_',' ')}</span>
        <span class="flex">${claimBtn}${deleteBtn}</span>
      </div>
    </div>`;
}

function openClaimModal(itemId) {
  const modal = document.getElementById('claimModal');
  if (!modal) return;
  document.getElementById('claimItemId').value = itemId;
  modal.classList.add('open');
}

/* ---------------------------- Lost/Found list pages ------------------------- */

function initItemListingPages() {
  const gridView = document.getElementById('gridView');
  const listView = document.getElementById('listView');
  if (!gridView) return; // not on lost_items.php / found_items.php

  const type = window.ITEM_TYPE;
  const filterForm = document.getElementById('filterForm');
  const gridBtn = document.getElementById('viewGridBtn');
  const listBtn = document.getElementById('viewListBtn');
  const selectAllBox = document.getElementById('selectAllLost');

  async function loadGrid() {
    const params = new URLSearchParams(new FormData(filterForm));
    params.set('type', type);
    params.set('limit', '48');
    gridView.innerHTML = '<p class="text-muted">Loading…</p>';
    const data = await apiFetch('api/search.php?' + params.toString());
    if (!data.success) { gridView.innerHTML = `<div class="alert alert-error">${escapeHtml(data.message)}</div>`; return; }
    gridView.innerHTML = data.items.length
      ? data.items.map(renderItemCard).join('')
      : '<div class="empty-state">No items found matching your filters.</div>';
  }

  async function loadMyList() {
    const tbody = document.getElementById('lostListBody');
    const params = new URLSearchParams({ type, mine: '1', limit: '100' });
    tbody.innerHTML = '<tr><td colspan="8" class="text-muted">Loading…</td></tr>';
    const data = await apiFetch('api/search.php?' + params.toString());
    if (!data.success) { tbody.innerHTML = `<tr><td colspan="8">${escapeHtml(data.message)}</td></tr>`; return; }
    if (selectAllBox) selectAllBox.checked = false;
    if (data.items.length === 0) {
      tbody.innerHTML = '<tr><td colspan="8" class="text-muted">You have not reported any items of this type yet.</td></tr>';
      return;
    }
    tbody.innerHTML = data.items.map(item => `
      <tr>
        <td><input type="checkbox" class="row-checkbox" value="${item.id}"></td>
        <td>${escapeHtml(item.title)}</td>
        <td>${escapeHtml(item.category)}</td>
        <td>${formatDate(item.item_date)}</td>
        <td>${escapeHtml(item.location)}</td>
        <td><span class="${badgeClass(item.status)}">${item.status.replace('_',' ')}</span></td>
        <td>${formatDate(item.created_at)}</td>
        <td><button class="btn btn-sm btn-danger" onclick="deleteItem(${item.id}, ${jsArg(item.title)})">🗑️ Delete</button></td>
      </tr>
    `).join('');
  }

  // Lets deleteItem() / bulk delete refresh both views without a page reload
  // (a reload would drop the user back onto the grid view).
  window.refreshItemLists = () => Promise.all([loadGrid(), listView.classList.contains('hidden') ? null : loadMyList()]);

  filterForm.addEventListener('submit', (e) => { e.preventDefault(); loadGrid(); });

  if (gridBtn) gridBtn.addEventListener('click', () => {
    gridView.classList.remove('hidden');
    listView.classList.add('hidden');
    gridBtn.classList.replace('btn-secondary','btn-primary');
    if (listBtn) listBtn.classList.replace('btn-primary','btn-secondary');
  });
  if (listBtn) listBtn.addEventListener('click', () => {
    listView.classList.remove('hidden');
    gridView.classList.add('hidden');
    listBtn.classList.replace('btn-secondary','btn-primary');
    gridBtn.classList.replace('btn-primary','btn-secondary');
    loadMyList();
  });

  const selectAll = document.getElementById('selectAllLost');
  if (selectAll) {
    selectAll.addEventListener('change', () => {
      document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = selectAll.checked);
    });
  }

  loadGrid();
}

/* ---------------------------- Bulk delete (user list views) ---------------- */

function initBulkDelete() {
  const btn = document.getElementById('bulkDeleteLostBtn');
  if (!btn) return;
  btn.addEventListener('click', async () => {
    const ids = Array.from(document.querySelectorAll('.row-checkbox:checked')).map(cb => parseInt(cb.value, 10));
    if (ids.length === 0) { alert('Please select at least one item.'); return; }
    if (!confirm(`Delete ${ids.length} selected item(s)? This cannot be undone.`)) return;
    const data = await apiFetch('api/bulk_delete.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ids, csrf_token: window.CSRF_TOKEN }),
    });
    alert(data.message || (data.success ? `Deleted ${data.deleted} item(s).` : 'Failed to delete.'));
    if (data.success) afterItemsChanged();
  });
}

function initAdminBulkDelete() {
  const btn = document.getElementById('adminBulkDeleteBtn');
  if (!btn) return;
  const selectAll = document.getElementById('selectAllItems');
  if (selectAll) {
    selectAll.addEventListener('change', () => {
      document.querySelectorAll('.item-checkbox').forEach(cb => cb.checked = selectAll.checked);
    });
  }
  btn.addEventListener('click', async () => {
    const ids = Array.from(document.querySelectorAll('.item-checkbox:checked')).map(cb => parseInt(cb.value, 10));
    if (ids.length === 0) { alert('Please select at least one item.'); return; }
    if (!confirm(`Delete ${ids.length} selected item(s)? This cannot be undone.`)) return;
    const data = await apiFetch('api/bulk_delete.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ids, csrf_token: window.CSRF_TOKEN }),
    });
    alert(data.message || (data.success ? `Deleted ${data.deleted} item(s).` : 'Failed to delete.'));
    if (data.success) location.reload();
  });
}

/* ---------------------------- Dashboard tab switching ----------------------- */

function initDashboardTabs() {
  const tabs = document.querySelectorAll('.dash-tab');
  if (tabs.length === 0) return;
  tabs.forEach(tab => {
    tab.addEventListener('click', (e) => {
      e.preventDefault();
      const target = tab.dataset.tab;
      tabs.forEach(t => t.classList.remove('active'));
      tab.classList.add('active');
      document.querySelectorAll('.dash-panel').forEach(p => p.classList.add('hidden'));
      const panel = document.getElementById('panel-' + target);
      if (panel) panel.classList.remove('hidden');
      history.replaceState(null, '', '#' + target);
    });
  });
  if (location.hash) {
    const match = document.querySelector(`.dash-tab[data-tab="${location.hash.slice(1)}"]`);
    if (match) match.click();
  }
}

/* ---------------------------- Item status / moderation actions -------------- */

async function markResolved(itemId) {
  if (!confirm('Mark this item as resolved?')) return;
  const data = await apiFetch('api/update_status.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ item_id: itemId, status: 'resolved', csrf_token: window.CSRF_TOKEN }),
  });
  alert(data.message || (data.success ? 'Updated.' : 'Failed.'));
  if (data.success) location.reload();
}

/**
 * The two-step claim handshake on an approved report.
 * `as` is 'admin' (admin dashboard) or 'owner' (the reporter's dashboard).
 * An owner claim is only accepted once an admin has claimed the report, and
 * "Mark Resolved" only unlocks once the owner claim has gone through.
 */
async function claimReport(itemId, as) {
  const label = as === 'admin'
    ? 'Claim this report so the reporter can claim it in turn?'
    : 'Claim this report? You will then be able to mark it resolved.';
  if (!confirm(label)) return;

  const data = await apiFetch('api/claim_report.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ item_id: itemId, as, csrf_token: window.CSRF_TOKEN }),
  });
  alert(data.message || (data.success ? 'Report claimed.' : 'Failed to claim report.'));
  if (data.success) location.reload();
}

async function approveItem(itemId, action) {
  const data = await apiFetch('api/approve_item.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ item_id: itemId, action, csrf_token: window.CSRF_TOKEN }),
  });
  alert(data.message || (data.success ? `Item ${action}d.` : 'Failed.'));
  if (data.success) location.reload();
}

/** Delete ONE report (own report for users, any report for admins). */
async function deleteItem(itemId, title) {
  const name = title ? `"${title}"` : 'this report';
  if (!confirm(`Delete ${name}?\n\nThis also removes any claims and chat messages on it and cannot be undone.`)) return;
  const data = await apiFetch('api/delete_item.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ item_id: itemId, csrf_token: window.CSRF_TOKEN }),
  });
  alert(data.message || (data.success ? 'Report deleted.' : 'Could not delete this report.'));
  if (data.success) afterItemsChanged();
}

/** After something was deleted: refresh lists in place if we can, else reload. */
function afterItemsChanged() {
  if (typeof window.refreshItemLists === 'function') window.refreshItemLists();
  else location.reload();
}

async function manageUser(userId, action, reason) {
  if (action === 'delete' && !confirm('Permanently delete this user and all their data? This cannot be undone.\n\nTip: use Block instead if you only want to stop them using the site.')) return;
  const data = await apiFetch('api/manage_user.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ user_id: userId, action, reason: reason || '', csrf_token: window.CSRF_TOKEN }),
  });
  alert(data.message || (data.success ? 'Updated.' : 'Failed.'));
  if (data.success) location.reload();
}

/** Block a user, asking the admin for a reason (shown to the user at login). */
function blockUser(userId, name) {
  const reason = prompt(
    `Block ${name || 'this user'}?\n\nThey will be signed out straight away and won't be able to log in. ` +
    `Their listings are hidden. You can unblock them later.\n\nReason (shown to the user, optional):`, '');
  if (reason === null) return; // cancelled
  manageUser(userId, 'block', reason.trim());
}

async function reviewClaim(claimId, action) {
  if (!confirm(`${action === 'approve' ? 'Approve' : 'Reject'} this claim?`)) return;
  const data = await apiFetch('api/claim_review.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ claim_id: claimId, action, csrf_token: window.CSRF_TOKEN }),
  });
  alert(data.message || (data.success ? 'Claim updated.' : 'Failed.'));
  if (data.success) location.reload();
}

/* ------------------- Admin: incoming reports status filter ------------------ */

/**
 * The admin queue lists every incoming report, not just the pending ones, so
 * this narrows the table client-side without another round trip.
 */
function initIncomingReportsFilter() {
  const select = document.getElementById('incomingStatusFilter');
  const search = document.getElementById('incomingSearch');
  const tbody = document.getElementById('incomingReportsBody');
  const counter = document.getElementById('incomingVisibleCount');
  if (!tbody || (!select && !search)) return;

  function apply() {
    const status = select ? select.value : '';
    const term = search ? search.value.trim().toLowerCase() : '';
    let visible = 0;

    tbody.querySelectorAll('tr[data-status]').forEach(row => {
      const statusOk = !status || row.dataset.status === status;
      const textOk = !term || row.innerText.toLowerCase().includes(term);
      const show = statusOk && textOk;
      row.classList.toggle('hidden', !show);
      if (show) visible++;
    });

    const emptyRow = document.getElementById('incomingNoMatch');
    if (emptyRow) emptyRow.classList.toggle('hidden', visible > 0);
    if (counter) counter.textContent = visible;
  }

  if (select) select.addEventListener('change', apply);
  if (search) search.addEventListener('input', apply);
  apply();
}

/* ---------------------------- Theme preference ------------------------------ */

/**
 * Live-preview the theme while the dropdown is being changed. The value is
 * still saved with the rest of the profile form; this only avoids a round
 * trip before you can see what you picked.
 */
function initThemePreview() {
  const select = document.getElementById('themeSelect');
  if (!select) return;
  const original = document.documentElement.getAttribute('data-theme');
  select.addEventListener('change', () => {
    document.documentElement.setAttribute('data-theme', select.value || 'default');
    document.dispatchEvent(new Event('themechange'));
  });
  const form = select.closest('form');
  if (form) {
    form.addEventListener('reset', () => {
      document.documentElement.setAttribute('data-theme', original);
    });
  }
}

/* ------------------- Admin: view a single incoming report ------------------- */

function detailRow(label, value) {
  if (value === null || value === undefined || value === '') return '';
  return `<div class="detail-row"><dt>${escapeHtml(label)}</dt><dd>${value}</dd></div>`;
}

/** Open the read-only detail view for one report from the incoming table. */
async function viewReport(itemId) {
  const modal = document.getElementById('reportModal');
  const body = document.getElementById('reportModalBody');
  const heading = document.getElementById('reportModalTitle');
  if (!modal || !body) return;

  heading.textContent = 'Report';
  body.innerHTML = '<p class="text-muted">Loading…</p>';
  modal.classList.add('open');

  const data = await apiFetch('api/item_details.php?id=' + encodeURIComponent(itemId));
  if (!data.success) {
    body.innerHTML = `<div class="alert alert-error">${escapeHtml(data.message || 'Could not load this report.')}</div>`;
    return;
  }

  const it = data.item;
  heading.textContent = it.title;

  const image = it.image_url
    ? `<img src="${escapeHtml(it.image_url)}" alt="${escapeHtml(it.title)}" class="detail-image">`
    : '';

  // The claim handshake, spelled out rather than left as two timestamps.
  let claimState;
  if (it.owner_claimed_at) {
    claimState = `Reporter claimed on ${formatDate(it.owner_claimed_at)} — this report can now be marked resolved.`;
  } else if (it.admin_claimed_at) {
    claimState = `Claimed by ${escapeHtml(it.admin_claimer_name || 'an admin')} on ${formatDate(it.admin_claimed_at)}. Waiting for the reporter to claim it.`;
  } else if (it.is_approved) {
    claimState = 'Not claimed yet. An admin must claim it before the reporter can.';
  } else {
    claimState = 'Not approved yet, so it cannot be claimed.';
  }

  body.innerHTML = `
    ${image}
    <div class="flex mb-1" style="flex-wrap:wrap;">
      <span class="${badgeClass(it.type)}">${escapeHtml(it.type)}</span>
      <span class="${badgeClass(it.status)}">${escapeHtml(it.status.replace(/_/g, ' '))}</span>
      <span class="badge ${it.is_approved ? 'badge-approved' : 'badge-pending'}">${it.is_approved ? 'Approved' : 'Not approved'}</span>
    </div>
    <dl class="detail-list">
      ${detailRow('Category', escapeHtml(it.category))}
      ${detailRow('Description', escapeHtml(it.description))}
      ${detailRow('Date & time', `${formatDate(it.item_date)} at ${escapeHtml(it.item_time || '')}`)}
      ${detailRow('Location', escapeHtml(it.location))}
      ${detailRow('Reporter', `${escapeHtml(it.reporter_name)} &middot; ${escapeHtml(it.reporter_email)} &middot; ${escapeHtml(it.reporter_phone)}`)}
      ${detailRow('Contact on listing', `${escapeHtml(it.contact_email)} &middot; ${escapeHtml(it.contact_phone)}`)}
      ${detailRow('Verification secret', it.secret_detail ? escapeHtml(it.secret_detail) : '')}
      ${detailRow('Claim status', claimState)}
      ${detailRow('Claims submitted', String(it.claim_count))}
      ${detailRow('Submitted', formatDate(it.created_at))}
    </dl>`;
}

/* ---------------------------- Header profile menu --------------------------- */

/**
 * The profile menu in the site header: opens on click, closes on outside
 * click or Escape, and switches the theme in one step. The theme is applied
 * to <html> straight away and saved in the background, so the page never has
 * to reload just to change appearance.
 */
function initProfileMenu() {
  const wrap = document.getElementById('profileMenu');
  const trigger = document.getElementById('profileTrigger');
  const dropdown = document.getElementById('profileDropdown');
  if (!wrap || !trigger || !dropdown) return;

  function setOpen(open) {
    dropdown.hidden = !open;
    wrap.classList.toggle('open', open);
    trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  trigger.addEventListener('click', (e) => {
    e.stopPropagation();
    setOpen(dropdown.hidden);
  });
  dropdown.addEventListener('click', (e) => e.stopPropagation());
  document.addEventListener('click', () => setOpen(false));
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !dropdown.hidden) {
      setOpen(false);
      trigger.focus();
    }
  });
}

/* ---------------------------- Claim submission form ------------------------- */

function initClaimForm() {
  const form = document.getElementById('claimForm');
  if (!form) return;

  const proofType = document.getElementById('proofType');
  const textGroup = document.getElementById('proofTextGroup');
  const fileGroup = document.getElementById('proofFileGroup');
  if (proofType) {
    proofType.addEventListener('change', () => {
      const isDoc = proofType.value === 'document';
      textGroup.classList.toggle('hidden', isDoc);
      fileGroup.classList.toggle('hidden', !isDoc);
    });
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!window.IS_LOGGED_IN) {
      window.location.href = 'login.php';
      return;
    }
    const resultBox = document.getElementById('claimResult');
    const formData = new FormData(form);
    formData.set('csrf_token', window.CSRF_TOKEN);
    resultBox.innerHTML = '<p class="text-muted">Submitting…</p>';
    try {
      const res = await fetch('api/claim_submit.php', { method: 'POST', body: formData });
      const data = await res.json();
      resultBox.innerHTML = data.success
        ? `<div class="alert alert-success">${escapeHtml(data.message)}</div>`
        : `<div class="alert alert-error">${escapeHtml(data.message || 'Submission failed.')}</div>`;
      if (data.success) setTimeout(() => location.reload(), 1500);
    } catch (err) {
      resultBox.innerHTML = '<div class="alert alert-error">Network error. Please try again.</div>';
    }
  });
}

/* ---------------------------- Secure in-app chat ----------------------------- */

function initChatWidgets() {
  const sendBtn = document.getElementById('chatSendBtn');
  const input = document.getElementById('chatInput');
  if (!sendBtn) return;

  sendBtn.addEventListener('click', sendChatMessage);
  input.addEventListener('keydown', (e) => { if (e.key === 'Enter') sendChatMessage(); });
}

async function openChat(claimId) {
  window.CURRENT_CLAIM_ID = claimId;
  const modal = document.getElementById('chatModal');
  modal.classList.add('open');
  await refreshChat();
}

async function refreshChat() {
  const claimId = window.CURRENT_CLAIM_ID;
  if (!claimId) return;
  const data = await apiFetch('api/get_messages.php?claim_id=' + claimId);
  const box = document.getElementById('chatMessages');
  if (!data.success) {
    box.innerHTML = `<p class="text-muted">${escapeHtml(data.message || 'Unable to load chat.')}</p>`;
    return;
  }
  document.getElementById('chatItemTitle').textContent = 'Chat — ' + data.item_title;
  box.innerHTML = data.messages.map(m => `
    <div class="chat-bubble ${m.sender_id == data.current_user_id ? 'me' : 'them'}">
      ${escapeHtml(m.message)}
      <span class="chat-time">${escapeHtml(m.sender_name)} · ${new Date(m.created_at).toLocaleTimeString()}</span>
    </div>
  `).join('') || '<p class="text-muted">No messages yet. Say hello!</p>';
  box.scrollTop = box.scrollHeight;
}

async function sendChatMessage() {
  const input = document.getElementById('chatInput');
  const message = input.value.trim();
  const claimId = window.CURRENT_CLAIM_ID;
  if (!message || !claimId) return;
  input.value = '';
  await apiFetch('api/send_message.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ claim_id: claimId, message, csrf_token: window.CSRF_TOKEN }),
  });
  await refreshChat();
}


/* ------------------- Admin: generic table filter (users, support) ----------- */

/**
 * Narrow a table client-side by a select (matched against a data-<attr> on
 * each row) and/or a free-text box. Same idea as the incoming-reports filter.
 */
function initTableFilter(cfg) {
  const select = document.getElementById(cfg.select);
  const search = document.getElementById(cfg.search);
  const tbody = document.getElementById(cfg.tbody);
  const counter = document.getElementById(cfg.counter);
  const noMatch = document.getElementById(cfg.noMatch);
  if (!tbody || (!select && !search)) return;

  function apply() {
    const wanted = select ? select.value : '';
    const term = search ? search.value.trim().toLowerCase() : '';
    let visible = 0;
    tbody.querySelectorAll(`tr[data-${cfg.attr}]`).forEach(row => {
      const ok = (!wanted || row.dataset[cfg.attr] === wanted)
              && (!term || row.innerText.toLowerCase().includes(term));
      row.classList.toggle('hidden', !ok);
      if (ok) visible++;
    });
    if (noMatch) noMatch.classList.toggle('hidden', visible > 0);
    if (counter) counter.textContent = visible;
  }
  if (select) select.addEventListener('change', apply);
  if (search) search.addEventListener('input', apply);
  apply();
}

/* ------------------------- Admin: support messages -------------------------- */

async function openSupport(id) {
  const modal = document.getElementById('supportModal');
  const body = document.getElementById('supportModalBody');
  const heading = document.getElementById('supportModalTitle');
  if (!modal || !body) return;

  heading.textContent = 'Message';
  body.innerHTML = '<p class="text-muted">Loading…</p>';
  modal.classList.add('open');

  const data = await apiFetch('api/support_admin.php?id=' + encodeURIComponent(id));
  if (!data.success) {
    body.innerHTML = `<div class="alert alert-error">${escapeHtml(data.message || 'Could not load this message.')}</div>`;
    return;
  }
  const m = data.message;
  heading.textContent = m.subject;

  // Who sent it, and what we know about their account.
  let account = '<span class="text-muted">No account found for this email.</span>';
  let blockBtn = '';
  if (m.account) {
    account = `${escapeHtml(m.account.name)} <span class="badge ${m.account.blocked ? 'badge-rejected' : 'badge-active'}">${m.account.blocked ? 'Blocked' : 'Active'}</span>`;
    if (m.account.blocked && m.account.blocked_reason) {
      account += `<div class="text-muted" style="font-size:.82rem;">Block reason: ${escapeHtml(m.account.blocked_reason)}</div>`;
    }
    if (m.account.role !== 'admin') {
      blockBtn = m.account.blocked
        ? `<button type="button" class="btn btn-success btn-sm" onclick="manageUser(${m.account.id}, 'unblock')">✅ Unblock this user</button>`
        : `<button type="button" class="btn btn-secondary btn-sm" onclick="blockUser(${m.account.id}, ${jsArg(m.account.name)})">🚫 Block this user</button>`;
    }
  }

  const guestNote = m.logged_in ? '' :
    `<div class="alert alert-info">This person was not logged in, so they can't see your reply on the site. Email them at <a href="mailto:${escapeHtml(m.email)}?subject=${encodeURIComponent('Re: ' + m.subject)}">${escapeHtml(m.email)}</a> as well.</div>`;

  body.innerHTML = `
    <dl class="detail-list">
      ${detailRow('From', `${escapeHtml(m.name)} &middot; <a href="mailto:${escapeHtml(m.email)}">${escapeHtml(m.email)}</a>`)}
      ${detailRow('Topic', escapeHtml(m.category))}
      ${detailRow('Received', escapeHtml(new Date(m.created_at.replace(' ', 'T')).toLocaleString()))}
      ${detailRow('Account', account)}
    </dl>
    <div class="support-body support-box">${escapeHtml(m.body)}</div>
    ${guestNote}
    <div class="form-group">
      <label for="supportReply">Your reply (shown to the user on the Contact page)</label>
      <textarea id="supportReply" rows="5" maxlength="3000" placeholder="Write what you did or what the user should do next…">${escapeHtml(m.admin_reply || '')}</textarea>
    </div>
    <div class="form-group">
      <label for="supportStatus">Status</label>
      <select id="supportStatus">
        <option value="new" ${m.status === 'new' ? 'selected' : ''}>New</option>
        <option value="in_progress" ${m.status === 'in_progress' ? 'selected' : ''}>In progress</option>
        <option value="resolved" ${m.status === 'resolved' ? 'selected' : ''}>Resolved</option>
      </select>
    </div>
    <div class="flex" style="flex-wrap:wrap;">
      <button type="button" class="btn btn-primary" onclick="saveSupport(${m.id})">Save</button>
      <button type="button" class="btn btn-success" onclick="saveSupport(${m.id}, 'resolved')">Save &amp; mark resolved</button>
      ${blockBtn}
      <button type="button" class="btn btn-danger btn-sm" style="margin-left:auto;" onclick="deleteSupport(${m.id})">Delete</button>
    </div>
    <div id="supportResult" class="mt-1"></div>`;
}

async function saveSupport(id, forceStatus) {
  const reply = document.getElementById('supportReply').value;
  const status = forceStatus || document.getElementById('supportStatus').value;
  const out = document.getElementById('supportResult');
  out.innerHTML = '<p class="text-muted">Saving…</p>';
  const data = await apiFetch('api/support_admin.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'update', id, status, reply, csrf_token: window.CSRF_TOKEN }),
  });
  if (data.success) {
    location.hash = 'support';
    location.reload();
  } else {
    out.innerHTML = `<div class="alert alert-error">${escapeHtml(data.message || 'Could not save.')}</div>`;
  }
}

async function deleteSupport(id) {
  if (!confirm('Delete this message permanently?')) return;
  const data = await apiFetch('api/support_admin.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'delete', id, csrf_token: window.CSRF_TOKEN }),
  });
  if (data.success) {
    location.hash = 'support';
    location.reload();
  } else {
    alert(data.message || 'Could not delete.');
  }
}

/* ------------------------------ Theme dropdown -------------------------------- */

/**
 * The single Theme dropdown in the header (System default / Light / Dark),
 * shown to everyone: visitors, users and admins.
 *  - Logged in: the choice is saved to the account (api/set_theme.php).
 *  - Logged out: it is saved in the lf_theme cookie on this device.
 * The page changes instantly; a failed save rolls back so the UI never claims
 * a preference that wasn't stored.
 */
function initThemeMenu() {
  const wrap = document.getElementById('themeMenu');
  const trigger = document.getElementById('themeTrigger');
  const dropdown = document.getElementById('themeDropdown');
  if (!wrap || !trigger || !dropdown) return;
  const status = document.getElementById('themeStatus');
  const icon = document.getElementById('themeIcon');
  const options = Array.from(dropdown.querySelectorAll('.theme-option'));

  function setOpen(open) {
    dropdown.hidden = !open;
    wrap.classList.toggle('open', open);
    trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
  }
  function show(value) {
    document.documentElement.setAttribute('data-theme', value);
    window.THEME = value;
    options.forEach(b => {
      const on = b.dataset.themeValue === value;
      b.classList.toggle('active', on);
      b.setAttribute('aria-checked', on ? 'true' : 'false');
      if (on && icon) icon.textContent = b.dataset.themeIcon;
    });
    const select = document.getElementById('themeSelect'); // Settings form, if on this page
    if (select) select.value = value;
    document.dispatchEvent(new Event('themechange'));
  }

  trigger.addEventListener('click', (e) => { e.stopPropagation(); setOpen(dropdown.hidden); });
  dropdown.addEventListener('click', (e) => e.stopPropagation());
  document.addEventListener('click', () => setOpen(false));
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !dropdown.hidden) { setOpen(false); trigger.focus(); }
  });

  options.forEach(btn => {
    btn.addEventListener('click', async () => {
      const value = btn.dataset.themeValue;
      const previous = document.documentElement.getAttribute('data-theme') || 'default';
      if (value === previous) { setOpen(false); return; }
      show(value);
      setOpen(false);

      if (!window.IS_LOGGED_IN) {
        document.cookie = `lf_theme=${value}; path=/; max-age=31536000; SameSite=Lax`;
        return;
      }
      if (status) status.textContent = 'Saving…';
      const data = await apiFetch('api/set_theme.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ theme: value, csrf_token: window.CSRF_TOKEN }),
      });
      if (!data.success) {
        show(previous); // roll back
        alert(data.message || 'Could not save your theme.');
      }
      if (status) status.textContent = '';
    });
  });
}
