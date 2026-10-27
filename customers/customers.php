<?php
/**
 * customers/customers.php — Customer Management
 * Phase 18: Full AJAX modernisation
 *
 * Features:
 *  - AJAX search (debounced, no page reload)
 *  - AJAX filters: All / With Pending / Clear Balance
 *  - AJAX pagination
 *  - Add Customer (modal, AJAX save)
 *  - Edit Customer (modal, AJAX save, phone cascade to credits)
 *  - Delete Customer (AJAX, blocked when active credits exist)
 *  - View Customer Detail (modal, AJAX, invoice table + progress bars)
 *  - Record Payment (from detail modal, AJAX)
 *  - Export single customer CSV
 *  - Export all customers CSV
 *  - Stat cards auto-refresh after mutations
 *
 * Tables: customers, customer_credits, credit_payments, sales
 */

require_once '../config/config.php';
require_once '../includes/sync_engine.php';

requireLogin();

// AUTO-CREATE customers TABLE IF NOT EXISTS
$db = db();
$db->query("CREATE TABLE IF NOT EXISTS customers (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(120) NOT NULL,
    phone         VARCHAR(30)  NOT NULL,
    address       TEXT         DEFAULT NULL,
    notes         TEXT         DEFAULT NULL,
    created_at    DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$page_title = 'Customers — Pharmacy';
$HANDLER    = BASE_URL . 'customers/ajax_handler.php';
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
.sc-label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--mu); }
.sc-val   { font-size:22px; font-weight:700; margin:4px 0 2px; }
.sc-sub   { font-size:11px; color:var(--mu); }

/* Table card */
.tcard { background:var(--card); border-radius:14px; box-shadow:0 1px 4px rgba(0,0,0,.07); overflow:hidden; }
.tcard thead th { background:#f9fafb; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; padding:10px 14px; border-bottom:1px solid var(--bo); white-space:nowrap; }
.tcard tbody td { padding:10px 14px; vertical-align:middle; border-bottom:1px solid #f3f4f6; }
.tcard tbody tr:last-child td { border-bottom:none; }
.tcard tbody tr:hover { background:#f9fafb; }
.tcard tbody tr.has-pending { background:#fff8f8; }
.tcard tbody tr.has-pending:hover { background:#fff0f0; }
.tcard-header { background:#f9fafb; }

/* Badges */
.pending-badge { display:inline-block; background:#fef2f2; color:#dc2626; border:1px solid #fecaca; border-radius:20px; padding:3px 10px; font-size:11px; font-weight:700; }
.clear-badge   { display:inline-block; background:#f0fdf4; color:#16a34a; border:1px solid #bbf7d0; border-radius:20px; padding:3px 10px; font-size:11px; font-weight:600; }

/* Filter bar */
.filter-bar { background:var(--card); border-radius:12px; padding:12px 16px; box-shadow:0 1px 4px rgba(0,0,0,.06); margin-bottom:16px; }
.filter-btn { border-radius:20px; font-size:12px; font-weight:600; padding:4px 14px; border:1px solid var(--bo); background:#fff; color:var(--mu); cursor:pointer; transition:all .15s; }
.filter-btn:hover, .filter-btn.active { background:var(--pri); color:#fff; border-color:var(--pri); }
.filter-btn.active-red { background:var(--dan); color:#fff; border-color:var(--dan); }
.filter-btn.active-green { background:var(--suc); color:#fff; border-color:var(--suc); }

/* Invoice table inside detail modal */
.inv-table { width:100%; border-collapse:collapse; font-size:12px; }
.inv-table th { background:#f3f4f6; padding:6px 10px; text-align:left; font-weight:700; font-size:11px; text-transform:uppercase; color:var(--mu); }
.inv-table td { padding:7px 10px; border-bottom:1px solid #f3f4f6; }
.inv-table tr:last-child td { border-bottom:none; }
.ppb  { background:#f3f4f6; border-radius:99px; overflow:hidden; height:5px; margin-bottom:2px; }
.ppf  { height:100%; border-radius:99px; background:linear-gradient(90deg,#1a56db,#3b82f6); }

/* Loading overlay */
#listLoading { display:none; position:absolute; inset:0; background:rgba(255,255,255,.6); z-index:10; align-items:center; justify-content:center; border-radius:14px; }

.toast-container { position:fixed; bottom:20px; right:20px; z-index:9999; }
</style>
</head>
<?php $__dm = !empty($_SESSION["company_settings_cache"]["dark_mode"]) ? " dark-mode" : ""; ?>
<body class="<?= trim("bg-light" . $__dm) ?>">
<?php include '../includes/sidebar.php'; ?>
<?php include '../includes/navbar.php'; ?>

<div class="main-content">
<div class="container-fluid">

<!-- Header -->
<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-1 fw-bold"><i class="fas fa-users me-2 text-primary"></i>Customers</h4>
        <p class="text-muted mb-0" style="font-size:12px">Customer database with credit &amp; pending dues tracking</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>credit/list.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-file-invoice-dollar me-1"></i>Credit List
        </a>
        <button class="btn btn-primary btn-sm" data-requires-perm="customers.add" onclick="openCustomerModal(0)">
            <i class="fas fa-user-plus me-1"></i>Add Customer
        </button>
        <a href="<?= $HANDLER ?>?action=export_all" class="btn btn-outline-success btn-sm" data-requires-perm="credit.export">
            <i class="fas fa-file-csv me-1"></i>Export All CSV
        </a>
    </div>
</div>

<!-- Stat Cards -->
<div class="row g-3 mb-4" id="statCards">
    <div class="col-6 col-md-4">
        <div class="sc sc-blue">
            <div class="sc-label">Total Customers</div>
            <div class="sc-val" id="statTotal">—</div>
            <div class="sc-sub">registered</div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="sc sc-red">
            <div class="sc-label">With Pending</div>
            <div class="sc-val" id="statPending">—</div>
            <div class="sc-sub">have outstanding dues</div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="sc sc-green">
            <div class="sc-label">Grand Pending</div>
            <div class="sc-val" id="statGrand">—</div>
            <div class="sc-sub">total outstanding balance</div>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<div class="filter-bar d-flex align-items-center gap-3 flex-wrap">
    <div class="d-flex gap-2 align-items-center flex-wrap">
        <span style="font-size:12px;font-weight:600;color:var(--mu)">Filter:</span>
        <button class="filter-btn active" data-filter="">All Customers</button>
        <button class="filter-btn" data-filter="pending">
            <i class="fas fa-exclamation-circle me-1 text-danger"></i>With Pending
        </button>
        <button class="filter-btn" data-filter="clear">
            <i class="fas fa-check-circle me-1 text-success"></i>Clear Balance
        </button>
    </div>
    <div class="ms-auto d-flex gap-2" style="min-width:260px">
        <div class="input-group input-group-sm">
            <span class="input-group-text"><i class="fas fa-search text-muted"></i></span>
            <input type="text" id="searchInput" class="form-control" placeholder="Search name or phone…">
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

<!-- Add / Edit Customer Modal -->
<div class="modal fade" id="customerModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h6 class="modal-title fw-bold" id="customerModalTitle">
                    <i class="fas fa-user-plus me-2"></i>Add Customer
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="custId">
                <div class="mb-3">
                    <label class="form-label form-label-sm fw-semibold">Full Name <span class="text-danger">*</span></label>
                    <input type="text" id="custName" class="form-control form-control-sm" placeholder="Customer name" autocomplete="off">
                </div>
                <div class="mb-3">
                    <label class="form-label form-label-sm fw-semibold">Phone <span class="text-danger">*</span></label>
                    <input type="text" id="custPhone" class="form-control form-control-sm" placeholder="03xx-xxxxxxx" autocomplete="off">
                </div>
                <div class="mb-3">
                    <label class="form-label form-label-sm fw-semibold">Address</label>
                    <textarea id="custAddress" class="form-control form-control-sm" rows="2" placeholder="Optional address"></textarea>
                </div>
                <div class="mb-2">
                    <label class="form-label form-label-sm fw-semibold">Notes</label>
                    <textarea id="custNotes" class="form-control form-control-sm" rows="2" placeholder="Optional notes"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" id="custSaveBtn" onclick="saveCustomer()">
                    <i class="fas fa-save me-1"></i>Save
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Customer Detail Modal -->
<div class="modal fade" id="detailModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h6 class="modal-title fw-bold" id="detailModalTitle">
                    <i class="fas fa-user me-2"></i>Customer Details
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detailModalBody">
                <div class="text-center py-4"><div class="spinner-border text-primary"></div></div>
            </div>
            <div class="modal-footer" id="detailModalFooter" style="display:none">
                <a id="custExportBtn" href="#" class="btn btn-outline-success btn-sm" data-requires-perm="credit.export">
                    <i class="fas fa-file-csv me-1"></i>Export CSV
                </a>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Pay Credit Modal -->
<div class="modal fade" id="payModal2" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h6 class="modal-title fw-bold"><i class="fas fa-money-bill-wave me-2"></i>Record Payment</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="pay2CreditId">
                <input type="hidden" id="pay2CustomerId">
                <div class="mb-2 p-2 rounded" style="background:#f0fdf4">
                    <div class="fw-semibold" id="pay2Invoice"></div>
                    <div class="small text-muted">Remaining: <span id="pay2Remaining" class="text-danger fw-bold"></span></div>
                </div>
                <div class="mb-2">
                    <label class="form-label form-label-sm fw-semibold">Amount (Rs) <span class="text-danger">*</span></label>
                    <input type="number" id="pay2Amount" class="form-control form-control-sm" step="0.01" min="0.01">
                </div>
                <div class="mb-2">
                    <label class="form-label form-label-sm fw-semibold">Method</label>
                    <select id="pay2Method" class="form-select form-select-sm">
                        <option value="cash">Cash</option>
                        <option value="card">Card</option>
                        <option value="online">Online</option>
                    </select>
                </div>
                <div class="mb-1">
                    <label class="form-label form-label-sm fw-semibold">Note</label>
                    <input type="text" id="pay2Note" class="form-control form-control-sm" placeholder="Optional">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success btn-sm" id="pay2Btn" onclick="submitPay2()">
                    <i class="fas fa-check me-1"></i>Record Payment
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirm Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h6 class="modal-title fw-bold"><i class="fas fa-trash me-2"></i>Delete Customer</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <p class="mb-1">Are you sure you want to delete</p>
                <p class="fw-bold mb-1" id="deleteCustomerName"></p>
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

<div class="toast-container" id="toastContainer"></div>

<script>
var HANDLER    = '<?= $HANDLER ?>';
var CSRF_TOKEN = '<?= $csrfToken ?>';
var BASE_URL   = '<?= BASE_URL ?>';

var state       = { page: 1, search: '', filter: '' };
var searchTimer = null;

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
        return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[m];
    }) : '';
}

function loadList(page) {
    state.page = page || 1;
    $('#listLoading').show();
    $.get(HANDLER, { action:'get_list', page:state.page, search:state.search, filter:state.filter }, function (html) {
        $('#listContainer').html(html);
        $('#listLoading').hide();
        bindRowButtons();
    }).fail(function () {
        $('#listLoading').hide();
        toast('Failed to load customers', 'danger');
    });
}

function loadSummary() {
    $.get(HANDLER, { action:'get_summary' }, function (r) {
        if (!r.success) return;
        $('#statTotal').text(r.total_customers);
        $('#statPending').text(r.with_pending);
        $('#statGrand').text('Rs ' + parseFloat(r.grand_pending).toLocaleString('en', { minimumFractionDigits:2, maximumFractionDigits:2 }));
    }, 'json');
}

function bindRowButtons() {
    $('#listContainer').off('click', '.btn-view').on('click', '.btn-view', function () {
        viewCustomer($(this).data('id'));
    });
    $('#listContainer').off('click', '.btn-edit').on('click', '.btn-edit', function () {
        var d = $(this).data('cust');
        if (typeof d === 'string') { try { d = JSON.parse(d); } catch(e){} }
        openCustomerModal(d.id, d);
    });
    $('#listContainer').off('click', '.btn-export-one').on('click', '.btn-export-one', function () {
        window.location.href = HANDLER + '?action=export_customer&phone=' + $(this).data('phone');
    });
    $('#listContainer').off('click', '.btn-delete').on('click', '.btn-delete', function () {
        var pending = parseFloat($(this).data('pending'));
        if (pending > 0.005) {
            toast('Cannot delete: customer has active credit balance of Rs ' + pending.toFixed(2), 'danger');
            return;
        }
        openDeleteModal($(this).data('id'), $(this).data('name'));
    });
    $('#listContainer').off('click', '.page-nav').on('click', '.page-nav', function (e) {
        e.preventDefault();
        loadList($(this).data('page'));
    });
}

/* Filter buttons */
$('.filter-btn').on('click', function () {
    $('.filter-btn').removeClass('active active-red active-green');
    var f = $(this).data('filter');
    state.filter = f;
    if      (f === 'pending') $(this).addClass('active-red');
    else if (f === 'clear')   $(this).addClass('active-green');
    else                      $(this).addClass('active');
    loadList(1);
});

/* Search */
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

/* Add / Edit */
function openCustomerModal(id, data) {
    $('#custId').val(id || 0);
    $('#custName').val(data    ? data.name     : '');
    $('#custPhone').val(data   ? data.phone    : '');
    $('#custAddress').val(data ? (data.address || '') : '');
    $('#custNotes').val(data   ? (data.notes   || '') : '');
    $('#customerModalTitle').html(id
        ? '<i class="fas fa-edit me-2"></i>Edit Customer'
        : '<i class="fas fa-user-plus me-2"></i>Add Customer');
    $('#custSaveBtn').prop('disabled', false).html('<i class="fas fa-save me-1"></i>Save');
    new bootstrap.Modal(document.getElementById('customerModal')).show();
    setTimeout(function () { $('#custName').trigger('focus'); }, 400);
}

function saveCustomer() {
    var id    = $('#custId').val();
    var name  = $('#custName').val().trim();
    var phone = $('#custPhone').val().trim();
    if (!name)  { toast('Name is required',  'danger'); return; }
    if (!phone) { toast('Phone is required', 'danger'); return; }
    $('#custSaveBtn').prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Saving…');
    $.post(HANDLER + '?action=save_customer', {
        csrf_token: CSRF_TOKEN, id: id, name: name, phone: phone,
        address: $('#custAddress').val().trim(),
        notes:   $('#custNotes').val().trim()
    }, function (r) {
        $('#custSaveBtn').prop('disabled', false).html('<i class="fas fa-save me-1"></i>Save');
        if (r.success) {
            toast(r.message);
            bootstrap.Modal.getInstance(document.getElementById('customerModal')).hide();
            loadList(state.page);
            loadSummary();
        } else {
            toast(r.message || 'Failed', 'danger');
        }
    }, 'json').fail(function () {
        $('#custSaveBtn').prop('disabled', false).html('<i class="fas fa-save me-1"></i>Save');
        toast('Server error', 'danger');
    });
}

/* Delete */
var pendingDeleteId = null;
function openDeleteModal(id, name) {
    pendingDeleteId = id;
    $('#deleteCustomerName').text(name);
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
$('#confirmDeleteBtn').on('click', function () {
    if (!pendingDeleteId) return;
    var btn = $(this);
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
    $.post(HANDLER + '?action=delete_customer', { csrf_token: CSRF_TOKEN, id: pendingDeleteId }, function (r) {
        btn.prop('disabled', false).html('<i class="fas fa-trash me-1"></i>Delete');
        bootstrap.Modal.getInstance(document.getElementById('deleteModal')).hide();
        if (r.success) { toast(r.message); loadList(state.page); loadSummary(); }
        else { toast(r.message || 'Delete failed', 'danger'); }
    }, 'json').fail(function () {
        btn.prop('disabled', false).html('<i class="fas fa-trash me-1"></i>Delete');
        toast('Server error', 'danger');
    });
});

/* View Detail */
function viewCustomer(id) {
    $('#detailModalBody').html('<div class="text-center py-4"><div class="spinner-border text-primary"></div></div>');
    $('#detailModalFooter').hide();
    new bootstrap.Modal(document.getElementById('detailModal')).show();

    $.get(HANDLER, { action:'get_customer', id:id }, function (r) {
        if (!r.success) { $('#detailModalBody').html('<div class="alert alert-danger">' + esc(r.message) + '</div>'); return; }
        var c = r.customer;
        $('#detailModalTitle').html('<i class="fas fa-user me-2"></i>' + esc(c.name) + ' <small class="text-white-50">— ' + esc(c.phone) + '</small>');
        $('#custExportBtn').attr('href', HANDLER + '?action=export_customer&phone=' + encodeURIComponent(c.phone));
        $('#detailModalFooter').show();

        var pending = parseFloat(r.total_pending) || 0;
        var html = '<div class="row g-2 mb-3">'
                 + '<div class="col-6 col-md-3"><div class="sc sc-blue p-2"><div class="sc-label">Name</div><div style="font-weight:700">' + esc(c.name) + '</div></div></div>'
                 + '<div class="col-6 col-md-3"><div class="sc sc-blue p-2"><div class="sc-label">Phone</div><div style="font-weight:700">' + esc(c.phone) + '</div></div></div>'
                 + '<div class="col-6 col-md-3"><div class="sc sc-blue p-2"><div class="sc-label">Address</div><div style="font-size:12px">' + esc(c.address || '—') + '</div></div></div>'
                 + '<div class="col-6 col-md-3"><div class="sc ' + (pending > 0 ? 'sc-red' : 'sc-green') + ' p-2"><div class="sc-label">Total Pending</div><div style="font-weight:700;font-size:18px">Rs ' + pending.toFixed(2) + '</div></div></div>'
                 + '</div>';

        if (!r.credits || r.credits.length === 0) {
            html += '<div class="text-center text-muted py-3"><i class="fas fa-inbox fa-2x mb-2 d-block opacity-30"></i>No credit records</div>';
        } else {
            html += '<h6 class="fw-bold mb-2"><i class="fas fa-file-invoice-dollar me-2 text-primary"></i>Invoice-wise Credit Records</h6>';
            html += '<div class="table-responsive"><table class="inv-table"><thead><tr><th>Invoice</th><th>Total</th><th>Paid</th><th>Remaining</th><th>Due Date</th><th>Progress</th><th>Status</th><th>Action</th></tr></thead><tbody>';
            r.credits.forEach(function (cr) {
                var total = parseFloat(cr.display_total) || 0;
                var paid  = parseFloat(cr.paid)          || 0;
                var rem   = parseFloat(cr.remaining)     || 0;
                var pct   = total > 0 ? Math.min(100, (paid / total) * 100) : 0;
                var isP   = rem < 0.01;
                var isA   = cr.status === 'active';
                var badge = isP
                    ? '<span style="background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;border-radius:20px;padding:2px 8px;font-size:10px;font-weight:600">Paid</span>'
                    : '<span style="background:#fef2f2;color:#dc2626;border:1px solid #fecaca;border-radius:20px;padding:2px 8px;font-size:10px;font-weight:600">' + (isA ? 'Active' : 'Closed') + '</span>';
                var invL = cr.invoice_no
                    ? '<a href="' + BASE_URL + 'sales/view.php?id=' + cr.sale_id_fk + '" target="_blank" style="color:#1a56db;font-weight:600">' + esc(cr.invoice_no) + '</a>'
                    : '<span style="color:var(--mu)">Manual</span>';
                var payB = (!isP && isA)
                    ? '<button class="btn btn-outline-success btn-sm" style="font-size:10px;padding:2px 7px" onclick="openPay2(' + cr.id + ',' + JSON.stringify(cr.invoice_no || 'Manual') + ',' + rem + ',' + id + ')"><i class="fas fa-money-bill-wave"></i> Pay</button>'
                    : '';
                html += '<tr>'
                      + '<td>' + invL + '<br><small style="color:var(--mu)">' + esc((cr.sale_date || cr.created_at || '').substring(0, 10)) + '</small></td>'
                      + '<td>Rs ' + total.toFixed(2) + '</td>'
                      + '<td class="text-success">Rs ' + paid.toFixed(2) + '</td>'
                      + '<td class="' + (rem > 0.005 ? 'text-danger fw-bold' : 'text-success') + '">Rs ' + rem.toFixed(2) + '</td>'
                      + '<td>' + esc(cr.due_date || '—') + '</td>'
                      + '<td style="min-width:80px"><div class="ppb"><div class="ppf" style="width:' + pct.toFixed(0) + '%"></div></div><small style="color:var(--mu)">' + pct.toFixed(0) + '% paid</small></td>'
                      + '<td>' + badge + '</td>'
                      + '<td>' + payB + '</td>'
                      + '</tr>';
            });
            html += '</tbody></table></div>';
        }
        $('#detailModalBody').html(html);
    }, 'json').fail(function () {
        $('#detailModalBody').html('<div class="alert alert-danger">Failed to load data</div>');
    });
}

/* Pay */
function openPay2(creditId, invoiceLabel, remaining, customerId) {
    $('#pay2CreditId').val(creditId);
    $('#pay2CustomerId').val(customerId);
    $('#pay2Invoice').text(invoiceLabel);
    $('#pay2Remaining').text('Rs ' + parseFloat(remaining).toFixed(2));
    $('#pay2Amount').val('').attr('max', remaining);
    $('#pay2Method').val('cash');
    $('#pay2Note').val('');
    $('#pay2Btn').prop('disabled', false).html('<i class="fas fa-check me-1"></i>Record Payment');
    new bootstrap.Modal(document.getElementById('payModal2')).show();
}
function submitPay2() {
    var id  = $('#pay2CreditId').val();
    var cid = $('#pay2CustomerId').val();
    var amt = parseFloat($('#pay2Amount').val());
    var rem = parseFloat($('#pay2Remaining').text().replace('Rs ', ''));
    if (isNaN(amt) || amt <= 0) { toast('Enter a valid amount', 'danger'); return; }
    if (amt > rem + 0.01)       { toast('Amount exceeds remaining balance', 'danger'); return; }
    $('#pay2Btn').prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Saving…');
    $.post(HANDLER + '?action=pay_credit', {
        csrf_token: CSRF_TOKEN, credit_id: id, amount: amt,
        payment_method: $('#pay2Method').val(), note: $('#pay2Note').val().trim()
    }, function (r) {
        $('#pay2Btn').prop('disabled', false).html('<i class="fas fa-check me-1"></i>Record Payment');
        if (r.success) {
            bootstrap.Modal.getInstance(document.getElementById('payModal2')).hide();
            toast(r.message);
            if (cid) setTimeout(function () { viewCustomer(parseInt(cid)); }, 300);
            loadList(state.page);
            loadSummary();
        } else {
            toast(r.message || 'Failed', 'danger');
        }
    }, 'json').fail(function () {
        $('#pay2Btn').prop('disabled', false).html('<i class="fas fa-check me-1"></i>Record Payment');
        toast('Server error', 'danger');
    });
}

/* Enter key in modal */
$('#customerModal').on('keydown', function (e) {
    if (e.key === 'Enter' && !$(e.target).is('textarea')) saveCustomer();
});

$(document).ready(function () {
    loadSummary();
    loadList(1);
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
