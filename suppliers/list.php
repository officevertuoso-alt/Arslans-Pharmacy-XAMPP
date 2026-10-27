<?php
/**
 * suppliers/list.php — Supplier Management
 * Phase 19: Full AJAX Modernisation
 *
 * Features:
 *  - AJAX search (debounced, no page reload)
 *  - AJAX filters: All / With Due / Clear Balance
 *  - AJAX pagination
 *  - Add Supplier (modal, AJAX save)
 *  - Edit Supplier (modal, AJAX save)
 *  - Delete Supplier (AJAX, blocked when pending dues exist)
 *  - View Supplier Detail (modal, AJAX, recent purchases)
 *  - Stat cards auto-refresh after mutations
 */

require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/purchase_functions.php';

requireLogin();
if (!canView('suppliers')) {
    setFlash('error', 'Permission denied.', 'danger');
    header('Location: ' . BASE_URL . 'dashboard.php');
    exit;
}

$page_title = 'Suppliers — Pharmacy';
$HANDLER    = BASE_URL . 'suppliers/ajax_handler.php';
$csrfToken  = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php include dirname(__DIR__) . '/includes/page_head.php'; ?>
<style>
:root {
    --bg:#f0f3f8; --card:#fff; --pri:#1a56db; --dan:#e02424;
    --suc:#057a55; --warn:#d97706; --mu:#6b7280; --bo:#e5e7eb; --tx:#111827;
}
body { background:var(--bg); font-family:'Segoe UI',system-ui,sans-serif; font-size:13px; color:var(--tx); }
.main-content { margin-left:var(--sb-w,260px); transition:margin-left .25s ease; padding:20px 24px; }
body.sb-col-body .main-content { margin-left:var(--sb-w-mini,62px); }
@media(max-width:991.98px){ .main-content { margin-left:0!important; } }

/* Stat cards */
.sc { background:var(--card); border-radius:14px; padding:16px 20px; box-shadow:0 1px 4px rgba(0,0,0,.07); border-left:4px solid transparent; transition:transform .15s; }
.sc:hover { transform:translateY(-2px); }
.sc-blue  { border-left-color:var(--pri); }
.sc-red   { border-left-color:var(--dan); }
.sc-green { border-left-color:var(--suc); }
.sc-orange{ border-left-color:var(--warn); }
.sc-label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--mu); }
.sc-val   { font-size:22px; font-weight:700; margin:4px 0 2px; }
.sc-sub   { font-size:11px; color:var(--mu); }

/* Table card */
.tcard { background:var(--card); border-radius:14px; box-shadow:0 1px 4px rgba(0,0,0,.07); overflow:hidden; }
.tcard thead th { background:#f9fafb; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; padding:10px 14px; border-bottom:1px solid var(--bo); white-space:nowrap; }
.tcard tbody td { padding:10px 14px; vertical-align:middle; border-bottom:1px solid #f3f4f6; }
.tcard tbody tr:last-child td { border-bottom:none; }
.tcard tbody tr:hover { background:#f9fafb; }
.tcard tbody tr.has-due { background:#fff8f8; }
.tcard tbody tr.has-due:hover { background:#fff0f0; }
.tcard-header { background:#f9fafb; }

/* Badges */
.due-badge   { background:#fee2e2; color:#991b1b; border-radius:20px; padding:3px 10px; font-size:.75rem; font-weight:600; white-space:nowrap; }
.clear-badge { background:#d1fae5; color:#065f46; border-radius:20px; padding:3px 10px; font-size:.75rem; font-weight:600; white-space:nowrap; }
.supplier-name { color:var(--pri); }
.supplier-name:hover { color:#1e40af; }

/* Filter bar */
.filter-bar { background:var(--card); border-radius:14px; padding:12px 16px; box-shadow:0 1px 4px rgba(0,0,0,.07); margin-bottom:16px; }
.filter-btn { border:1px solid var(--bo); background:#fff; color:var(--mu); padding:5px 14px; border-radius:20px; font-size:12px; font-weight:600; cursor:pointer; transition:all .15s; }
.filter-btn.active         { background:var(--pri); color:#fff; border-color:var(--pri); }
.filter-btn.active-red     { background:var(--dan); color:#fff; border-color:var(--dan); }
.filter-btn.active-green   { background:var(--suc); color:#fff; border-color:var(--suc); }
.filter-btn:hover:not(.active):not(.active-red):not(.active-green) { background:#f3f4f6; }

/* Loading overlay */
#listLoading { position:absolute; inset:0; background:rgba(255,255,255,.7); z-index:10; align-items:center; justify-content:center; border-radius:14px; }

/* Detail modal */
.stat-mini { background:#f9fafb; border-radius:10px; padding:12px; text-align:center; }
.stat-mini .val { font-size:18px; font-weight:700; }
.stat-mini .lbl { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--mu); }
</style>
</head>
<?php $__dm = !empty($_SESSION['company_settings_cache']['dark_mode']) ? ' dark-mode' : ''; ?>
<body class="<?= trim('bg-light' . $__dm) ?>">
<?php include '../includes/sidebar.php'; ?>
<?php include '../includes/navbar.php'; ?>

<div class="main-content">
<div class="container-fluid">

<!-- Header -->
<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-1 fw-bold"><i class="fas fa-truck me-2 text-primary"></i>Suppliers</h4>
        <p class="text-muted mb-0" style="font-size:12px">Supplier database with purchase &amp; dues tracking</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (canAdd('suppliers')): ?>
        <button class="btn btn-primary btn-sm" data-requires-perm="suppliers.add" onclick="openSupplierModal(0)">
            <i class="fas fa-plus-circle me-1"></i>Add Supplier
        </button>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>purchases/list.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-shopping-cart me-1"></i>Purchases
        </a>
    </div>
</div>

<!-- Stat Cards -->
<div class="row g-3 mb-4" id="statCards">
    <div class="col-6 col-md-3">
        <div class="sc sc-blue">
            <div class="sc-label">Total Suppliers</div>
            <div class="sc-val" id="statTotal">—</div>
            <div class="sc-sub">registered</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="sc sc-red">
            <div class="sc-label">With Due</div>
            <div class="sc-val" id="statWithDue">—</div>
            <div class="sc-sub">have outstanding dues</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="sc sc-green">
            <div class="sc-label">Total Purchases</div>
            <div class="sc-val" id="statPurchases">—</div>
            <div class="sc-sub">all time</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="sc sc-orange">
            <div class="sc-label">Grand Due</div>
            <div class="sc-val" id="statDue">—</div>
            <div class="sc-sub">total outstanding</div>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<div class="filter-bar d-flex align-items-center gap-3 flex-wrap">
    <div class="d-flex gap-2 align-items-center flex-wrap">
        <span style="font-size:12px;font-weight:600;color:var(--mu)">Filter:</span>
        <button class="filter-btn active" data-filter="">All Suppliers</button>
        <button class="filter-btn" data-filter="with_due">
            <i class="fas fa-exclamation-circle me-1 text-danger"></i>With Due
        </button>
        <button class="filter-btn" data-filter="clear">
            <i class="fas fa-check-circle me-1 text-success"></i>Clear Balance
        </button>
    </div>
    <div class="ms-auto d-flex gap-2" style="min-width:260px">
        <div class="input-group input-group-sm">
            <span class="input-group-text"><i class="fas fa-search text-muted"></i></span>
            <input type="text" id="searchInput" class="form-control" placeholder="Search name, phone, email…">
            <button class="btn btn-outline-secondary" id="clearSearch" style="display:none" title="Clear">
                <i class="fas fa-times"></i>
            </button>
        </div>
    </div>
</div>

<!-- Table -->
<div class="tcard position-relative" id="tableWrapper">
    <div id="listLoading" style="display:flex;">
        <div class="spinner-border text-primary"></div>
    </div>
    <div id="listContainer"></div>
</div>

</div>
</div>

<!-- ═══════════════════════════════════════════════════════
     ADD / EDIT SUPPLIER MODAL
════════════════════════════════════════════════════════ -->
<div class="modal fade" id="supplierModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h6 class="modal-title fw-bold" id="supplierModalTitle">
                    <i class="fas fa-plus-circle me-2"></i>Add Supplier
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="suppId">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label form-label-sm fw-semibold">Supplier Name <span class="text-danger">*</span></label>
                        <input type="text" id="suppName" class="form-control form-control-sm" placeholder="Company / supplier name" autocomplete="off">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label form-label-sm fw-semibold">Contact Person</label>
                        <input type="text" id="suppContact" class="form-control form-control-sm" placeholder="Representative name" autocomplete="off">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label form-label-sm fw-semibold">Phone <span class="text-danger">*</span></label>
                        <input type="text" id="suppPhone" class="form-control form-control-sm" placeholder="03xx-xxxxxxx" autocomplete="off">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label form-label-sm fw-semibold">Alternative Phone</label>
                        <input type="text" id="suppAltPhone" class="form-control form-control-sm" placeholder="Optional" autocomplete="off">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label form-label-sm fw-semibold">Email</label>
                        <input type="email" id="suppEmail" class="form-control form-control-sm" placeholder="Optional" autocomplete="off">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label form-label-sm fw-semibold">GST Number</label>
                        <input type="text" id="suppGst" class="form-control form-control-sm" placeholder="Optional" autocomplete="off">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label form-label-sm fw-semibold">Address</label>
                        <textarea id="suppAddress" class="form-control form-control-sm" rows="2" placeholder="Optional address"></textarea>
                    </div>
                    <div class="col-md-6" id="openingBalanceRow">
                        <label class="form-label form-label-sm fw-semibold">Opening Balance (Rs)</label>
                        <input type="number" id="suppOpening" class="form-control form-control-sm" placeholder="0.00" step="0.01" min="0">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" id="suppSaveBtn" onclick="saveSupplier()">
                    <i class="fas fa-save me-1"></i>Save
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     SUPPLIER DETAIL MODAL
════════════════════════════════════════════════════════ -->
<div class="modal fade" id="detailModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h6 class="modal-title fw-bold" id="detailModalTitle">
                    <i class="fas fa-truck me-2"></i>Supplier Details
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detailModalBody">
                <div class="text-center py-4"><div class="spinner-border text-primary"></div></div>
            </div>
            <div class="modal-footer" id="detailModalFooter" style="display:none">
                <a id="suppViewBtn" href="#" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-external-link-alt me-1"></i>Full Profile
                </a>
                <a id="suppStatementBtn" href="#" class="btn btn-outline-info btn-sm">
                    <i class="fas fa-file-alt me-1"></i>Statement
                </a>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     DELETE CONFIRM MODAL
════════════════════════════════════════════════════════ -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h6 class="modal-title fw-bold"><i class="fas fa-trash me-2"></i>Delete Supplier</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <p class="mb-1">Are you sure you want to delete</p>
                <p class="fw-bold mb-1" id="deleteSupplierName"></p>
                <p class="text-muted small mb-0">This cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger btn-sm" id="confirmDeleteBtn">
                    <i class="fas fa-trash me-1"></i>Delete
                </button>
            </div>
        </div>
    </div>
</div>

<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toastContainer" style="z-index:9999"></div>

<?php require_once '../includes/footer.php'; ?>
<script>
var HANDLER    = '<?= $HANDLER ?>';
var CSRF_TOKEN = '<?= $csrfToken ?>';
var BASE_URL   = '<?= BASE_URL ?>';

var state       = { page: 1, search: '', filter: '' };
var searchTimer = null;

/* ── Toast ────────────────────────────────────────────── */
function toast(msg, type) {
    type = type || 'success';
    var bg = type === 'success' ? 'bg-success' : (type === 'warning' ? 'bg-warning text-dark' : 'bg-danger');
    var html = '<div class="toast align-items-center text-white ' + bg + ' border-0 mb-2">'
             + '<div class="d-flex"><div class="toast-body">' + msg + '</div>'
             + '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>'
             + '</div></div>';
    $('#toastContainer').append(html);
    var el = $('#toastContainer .toast').last()[0];
    new bootstrap.Toast(el, { delay: 3500 }).show();
    setTimeout(function () { $(el).remove(); }, 4000);
}

function esc(t) {
    return t ? String(t).replace(/[&<>"']/g, function (m) {
        return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'": '&#39;' }[m];
    }) : '';
}

function fmtMoney(v) {
    return 'Rs ' + parseFloat(v || 0).toLocaleString('en', { minimumFractionDigits:2, maximumFractionDigits:2 });
}

/* ── Load list ────────────────────────────────────────── */
function loadList(page) {
    state.page = page || 1;
    $('#listLoading').show();
    $.get(HANDLER, { action:'get_list', page:state.page, search:state.search, filter:state.filter }, function (html) {
        $('#listContainer').html(html);
        $('#listLoading').hide();
        bindRowButtons();
    }).fail(function () {
        $('#listLoading').hide();
        toast('Failed to load suppliers', 'danger');
    });
}

/* ── Load summary stat cards ──────────────────────────── */
function loadSummary() {
    $.get(HANDLER, { action:'get_summary' }, function (r) {
        if (!r.success) return;
        $('#statTotal').text(r.total_suppliers);
        $('#statWithDue').text(r.with_due);
        $('#statPurchases').text(fmtMoney(r.grand_purchases));
        $('#statDue').text(fmtMoney(r.grand_due));
    }, 'json');
}

/* ── Bind row action buttons ──────────────────────────── */
function bindRowButtons() {
    $('#listContainer').off('click', '.btn-view-supp').on('click', '.btn-view-supp', function () {
        viewSupplier($(this).data('id'));
    });
    $('#listContainer').off('click', '.btn-edit-supp').on('click', '.btn-edit-supp', function () {
        var d = $(this).data('supp');
        if (typeof d === 'string') { try { d = JSON.parse(d); } catch(e){} }
        openSupplierModal(d.id, d);
    });
    $('#listContainer').off('click', '.btn-delete-supp').on('click', '.btn-delete-supp', function () {
        var due = parseFloat($(this).data('due'));
        if (due > 0.005) {
            toast('Cannot delete: supplier has pending dues of ' + fmtMoney(due) + '. Clear all dues first.', 'danger');
            return;
        }
        openDeleteModal($(this).data('id'), $(this).data('name'));
    });
    $('#listContainer').off('click', '.page-nav').on('click', '.page-nav', function (e) {
        e.preventDefault();
        loadList($(this).data('page'));
    });
}

/* ── Filter buttons ───────────────────────────────────── */
$('.filter-btn').on('click', function () {
    $('.filter-btn').removeClass('active active-red active-green');
    var f = $(this).data('filter');
    state.filter = f;
    if      (f === 'with_due') $(this).addClass('active-red');
    else if (f === 'clear')    $(this).addClass('active-green');
    else                       $(this).addClass('active');
    loadList(1);
});

/* ── Search ───────────────────────────────────────────── */
$('#searchInput').on('input', function () {
    var v = $(this).val().trim();
    state.search = v;
    $('#clearSearch').toggle(v.length > 0);
    clearTimeout(searchTimer);
    searchTimer = setTimeout(function () { loadList(1); }, 350);
});
$('#clearSearch').on('click', function () {
    $('#searchInput').val('');
    state.search = '';
    $(this).hide();
    loadList(1);
});

/* ── Add / Edit Supplier Modal ────────────────────────── */
function openSupplierModal(id, data) {
    $('#suppId').val(id || 0);
    $('#suppName').val(data         ? data.supplier_name     : '');
    $('#suppContact').val(data      ? (data.contact_person    || '') : '');
    $('#suppPhone').val(data        ? data.phone              : '');
    $('#suppAltPhone').val(data     ? (data.alternative_phone || '') : '');
    $('#suppEmail').val(data        ? (data.email             || '') : '');
    $('#suppGst').val(data          ? (data.gst_no            || '') : '');
    $('#suppAddress').val(data      ? (data.address           || '') : '');
    $('#suppOpening').val('');

    // Show opening balance only for new suppliers
    $('#openingBalanceRow').toggle(!id);

    $('#supplierModalTitle').html(id
        ? '<i class="fas fa-edit me-2"></i>Edit Supplier'
        : '<i class="fas fa-plus-circle me-2"></i>Add Supplier');
    $('#suppSaveBtn').prop('disabled', false).html('<i class="fas fa-save me-1"></i>Save');
    new bootstrap.Modal(document.getElementById('supplierModal')).show();
    setTimeout(function () { $('#suppName').trigger('focus'); }, 400);
}

function saveSupplier() {
    var id   = $('#suppId').val();
    var name = $('#suppName').val().trim();
    var phone = $('#suppPhone').val().trim();
    if (!name)  { toast('Supplier name is required', 'danger'); return; }
    if (!phone) { toast('Phone number is required',  'danger'); return; }

    $('#suppSaveBtn').prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Saving…');
    $.post(HANDLER + '?action=save_supplier', {
        csrf_token:        CSRF_TOKEN,
        id:                id,
        supplier_name:     name,
        contact_person:    $('#suppContact').val().trim(),
        phone:             phone,
        alternative_phone: $('#suppAltPhone').val().trim(),
        email:             $('#suppEmail').val().trim(),
        gst_no:            $('#suppGst').val().trim(),
        address:           $('#suppAddress').val().trim(),
        opening_balance:   $('#suppOpening').val() || 0
    }, function (r) {
        $('#suppSaveBtn').prop('disabled', false).html('<i class="fas fa-save me-1"></i>Save');
        if (r.success) {
            toast(r.message);
            bootstrap.Modal.getInstance(document.getElementById('supplierModal')).hide();
            loadList(state.page);
            loadSummary();
        } else {
            toast(r.message || 'Failed', 'danger');
        }
    }, 'json').fail(function () {
        $('#suppSaveBtn').prop('disabled', false).html('<i class="fas fa-save me-1"></i>Save');
        toast('Server error', 'danger');
    });
}

/* ── Delete ───────────────────────────────────────────── */
var pendingDeleteId = null;
function openDeleteModal(id, name) {
    pendingDeleteId = id;
    $('#deleteSupplierName').text(name);
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
$('#confirmDeleteBtn').on('click', function () {
    if (!pendingDeleteId) return;
    var btn = $(this);
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
    $.post(HANDLER + '?action=delete_supplier', { csrf_token: CSRF_TOKEN, id: pendingDeleteId }, function (r) {
        btn.prop('disabled', false).html('<i class="fas fa-trash me-1"></i>Delete');
        bootstrap.Modal.getInstance(document.getElementById('deleteModal')).hide();
        if (r.success) { toast(r.message); loadList(state.page); loadSummary(); }
        else { toast(r.message || 'Delete failed', 'danger'); }
    }, 'json').fail(function () {
        btn.prop('disabled', false).html('<i class="fas fa-trash me-1"></i>Delete');
        toast('Server error', 'danger');
    });
});

/* ── View Detail ──────────────────────────────────────── */
function viewSupplier(id) {
    $('#detailModalBody').html('<div class="text-center py-4"><div class="spinner-border text-primary"></div></div>');
    $('#detailModalFooter').hide();
    new bootstrap.Modal(document.getElementById('detailModal')).show();

    $.get(HANDLER, { action:'get_supplier', id:id }, function (r) {
        if (!r.success) {
            $('#detailModalBody').html('<div class="alert alert-danger">' + esc(r.message) + '</div>');
            return;
        }
        var s = r.supplier;
        $('#detailModalTitle').html('<i class="fas fa-truck me-2"></i>' + esc(s.supplier_name) +
            ' <small class="text-white-50">— ' + esc(s.phone) + '</small>');
        $('#suppViewBtn').attr('href', BASE_URL + 'suppliers/view.php?id=' + s.id);
        $('#suppStatementBtn').attr('href', BASE_URL + 'suppliers/statement.php?id=' + s.id);
        $('#detailModalFooter').show();

        var hasDue = parseFloat(s.total_due) > 0.005;

        var html = '<div class="row g-2 mb-3">'
                 + '<div class="col-6 col-md-3"><div class="stat-mini"><div class="lbl">Total Purchases</div><div class="val text-primary">' + fmtMoney(s.total_purchases) + '</div></div></div>'
                 + '<div class="col-6 col-md-3"><div class="stat-mini"><div class="lbl">Total Paid</div><div class="val text-success">' + fmtMoney(s.total_paid) + '</div></div></div>'
                 + '<div class="col-6 col-md-3"><div class="stat-mini"><div class="lbl">Total Due</div><div class="val ' + (hasDue ? 'text-danger' : 'text-muted') + '">' + (hasDue ? fmtMoney(s.total_due) : 'Clear') + '</div></div></div>'
                 + '<div class="col-6 col-md-3"><div class="stat-mini"><div class="lbl">Orders</div><div class="val">' + (parseInt(s.purchase_count) || 0) + '</div></div></div>'
                 + '</div>';

        // Info row
        html += '<div class="row g-2 mb-3">';
        if (s.contact_person) html += '<div class="col-md-4"><small class="text-muted d-block">Contact Person</small><strong>' + esc(s.contact_person) + '</strong></div>';
        if (s.email)          html += '<div class="col-md-4"><small class="text-muted d-block">Email</small><strong>' + esc(s.email) + '</strong></div>';
        if (s.address)        html += '<div class="col-md-4"><small class="text-muted d-block">Address</small><span>' + esc(s.address) + '</span></div>';
        html += '</div>';

        // Recent purchases
        html += '<h6 class="fw-bold mt-2 mb-2" style="font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:var(--mu)">Recent Purchases</h6>';
        if (!r.purchases || r.purchases.length === 0) {
            html += '<div class="text-muted text-center py-3"><i class="fas fa-inbox fa-2x mb-2 d-block opacity-30"></i>No purchases on record</div>';
        } else {
            html += '<div class="table-responsive"><table class="table table-sm table-hover mb-0">'
                  + '<thead><tr><th>Invoice</th><th>Date</th><th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Due</th><th>Status</th></tr></thead><tbody>';
            r.purchases.forEach(function (p) {
                var statusBadge = p.payment_status === 'paid'
                    ? '<span class="badge bg-success">Paid</span>'
                    : (p.payment_status === 'partial' ? '<span class="badge bg-warning text-dark">Partial</span>' : '<span class="badge bg-danger">Unpaid</span>');
                html += '<tr>'
                      + '<td><a href="' + BASE_URL + 'purchases/view.php?id=' + p.id + '" class="text-decoration-none">' + esc(p.invoice_no || 'PUR-' + p.id) + '</a></td>'
                      + '<td><small>' + esc(p.purchase_date || '') + '</small></td>'
                      + '<td class="text-end"><small>' + fmtMoney(p.total_amount) + '</small></td>'
                      + '<td class="text-end"><small class="text-success">' + fmtMoney(p.paid_amount) + '</small></td>'
                      + '<td class="text-end"><small class="text-danger">' + fmtMoney(p.due_amount) + '</small></td>'
                      + '<td>' + statusBadge + '</td>'
                      + '</tr>';
            });
            html += '</tbody></table></div>';
        }

        $('#detailModalBody').html(html);
    }, 'json').fail(function () {
        $('#detailModalBody').html('<div class="alert alert-danger">Failed to load supplier details.</div>');
    });
}

/* ── Init ─────────────────────────────────────────────── */
$(function () {
    loadSummary();
    loadList(1);
});
</script>
</body>
</html>
