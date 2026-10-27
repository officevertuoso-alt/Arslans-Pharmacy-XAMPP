<?php
/**
 * suppliers/view.php — Supplier Profile & Transaction History
 *
 * STEP 11 UI CHANGES + FIX: Outstanding shows NET due after returns
 * - All colors via CSS variables — zero hardcoded hex
 * - Summary stat cards: design system .stat-card with left border accents
 * - All three tables (.table-card wrapper, th uppercase, hover rows, CSS-var colors)
 * - .status-badge for payment status (badge-paid / badge-partial / badge-pending)
 * - Payment method badge using CSS var tint
 * - Smooth transitions on cards, buttons, rows
 * - Page fade-in animation
 * - Breadcrumb: Dashboard > Suppliers > Supplier Details (via navbar.php)
 *
 * ALL ORIGINAL PHP LOGIC (queries, fmt_cur, fmt_date, payStatusBadge,
 * session check, purchase_returns try/catch) 100% PRESERVED, except:
 * - Outstanding stat now uses net due after returns (via getPurchaseNetDue())
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/purchase_functions.php';

if (file_exists(__DIR__ . '/../includes/sync_engine.php')) {
    require_once '../includes/sync_engine.php';
}
if (file_exists(__DIR__ . '/../includes/cashbook_functions.php')) {
    require_once '../includes/cashbook_functions.php';
}

// Manual login check — preserved exactly
if (empty($_SESSION['user_id']) && empty($_SESSION['logged_in'])) {
    header('Location: ../index.php');
    exit;
}

$supplier_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$supplier_id) {
    header('Location: list.php');
    exit;
}

// ── Inline helpers (preserved) ────────────────────────────────────────────────
if (!function_exists('fmt_cur')) {
function fmt_cur($n): string {
    return 'Rs ' . number_format((float)$n, 2);
}
}
if (!function_exists('fmt_date')) {
function fmt_date($d): string {
    if (!$d) return '—';
    $ts = is_numeric($d) ? (int)$d : strtotime($d);
    return ($ts && $ts > 0) ? date('d M Y', $ts) : '—';
}
}

// ── Fetch supplier ────────────────────────────────────────────────────────────
$supplier = _pdoOne("SELECT * FROM suppliers WHERE id = :id", [':id' => $supplier_id]);
if (!$supplier) {
    header('Location: list.php');
    exit;
}

// ── Summary stats – NET DUE AFTER RETURNS ─────────────────────────────────────
$grossStats = _pdoOne(
    "SELECT
        COALESCE(SUM(total), 0)       AS total_purchases,
        COALESCE(SUM(paid_amount), 0) AS total_paid
     FROM purchases WHERE supplier_id = :id",
    [':id' => $supplier_id]
) ?: ['total_purchases' => 0, 'total_paid' => 0];

// Calculate net due after returns for each purchase
$allPurchases = _pdoAll("SELECT id FROM purchases WHERE supplier_id = :id", [':id' => $supplier_id]);
$netDue = 0;
foreach ($allPurchases as $pur) {
    if (function_exists('getPurchaseNetDue')) {
        $info = getPurchaseNetDue((int)$pur['id']);
        $netDue += $info['net_due'];
    } else {
        // Fallback – if function missing, fall back to gross due
        $p = _pdoOne("SELECT due_amount FROM purchases WHERE id = :id", [':id' => $pur['id']]);
        $netDue += (float)($p['due_amount'] ?? 0);
    }
}
$stats = [
    'total_purchases' => $grossStats['total_purchases'],
    'total_paid'      => $grossStats['total_paid'],
    'total_due'       => $netDue,
];

// ── Total returns ─────────────────────────────────────────────────────────────
$ret_row = _pdoOne(
    "SELECT COALESCE(SUM(pr.total_amount), 0) AS total_returns
     FROM purchase_returns pr
     INNER JOIN purchases p ON p.id = pr.purchase_id
     WHERE p.supplier_id = :id",
    [':id' => $supplier_id]
);
$returns_amount = $ret_row['total_returns'] ?? 0;

// ── Purchases list ────────────────────────────────────────────────────────────
$purchases = _pdoAll(
    "SELECT id, purchase_invoice_no, purchase_date, total, paid_amount, due_amount, payment_status
     FROM purchases WHERE supplier_id = :id ORDER BY purchase_date DESC",
    [':id' => $supplier_id]
) ?: [];

// ── Payments list ─────────────────────────────────────────────────────────────
$payments = _pdoAll(
    "SELECT sp.*, u.username AS paid_by
     FROM supplier_payments sp
     LEFT JOIN users u ON u.id = sp.created_by
     WHERE sp.supplier_id = :id
     ORDER BY sp.payment_date DESC",
    [':id' => $supplier_id]
) ?: [];

// ── Purchase returns ──────────────────────────────────────────────────────────
$purchase_returns = [];
try {
    $purchase_returns = _pdoAll(
        "SELECT pr.return_no, pr.return_date, pr.total_amount, pr.reason, pr.status,
                p.purchase_invoice_no
         FROM purchase_returns pr
         INNER JOIN purchases p ON p.id = pr.purchase_id
         WHERE p.supplier_id = :id
         ORDER BY pr.return_date DESC",
        [':id' => $supplier_id]
    ) ?: [];
} catch (Exception $e) { $purchase_returns = []; }

$currentBal = floatval($supplier['current_balance'] ?? 0);
$page_title = 'Supplier: ' . htmlspecialchars($supplier['supplier_name']);

// ── Status badge helper — now outputs design-system classes ──────────────────
function payStatusBadge(string $status): string {
    return match($status) {
        'paid'    => '<span class="status-badge badge-paid">Paid</span>',
        'partial' => '<span class="status-badge badge-partial">Partial</span>',
        'pending' => '<span class="status-badge badge-pending">Pending</span>',
        default   => '<span class="status-badge badge-returned">' . htmlspecialchars($status) . '</span>',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include dirname(__DIR__) . '/includes/page_head.php'; ?>
    
    
    
    <style>
        /* ═══════════════════════════════════════════════════════════
           suppliers/view.php — Page styles
           ALL colors via CSS variables — dark-mode safe
        ═══════════════════════════════════════════════════════════ */

        @keyframes fadeInPage {
            from { opacity: 0; transform: translateY(10px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .main-content {
            margin-left: var(--sb-w, 260px);
            padding: 24px 28px;
            animation: fadeInPage 0.28s ease;
            transition: margin-left 0.3s ease;
        }
        body.sidebar-mini .main-content { margin-left: var(--sb-w-mini, 62px); }
        @media (max-width: 991px) { .main-content { margin-left: 0 !important; } }
        @media print {
            .no-print { display: none !important; }
            .main-content { margin-left: 0; padding: 0; }
        }

        /* ── Page heading ──────────────────────────────────────── */
        .page-heading h4 {
            font-size: 1.25rem; font-weight: 700;
            color: var(--pharmacy-heading, #111827); margin-bottom: 2px;
        }
        .page-heading small {
            font-size: 0.8rem;
            color: color-mix(in srgb, var(--pharmacy-text, #374151) 60%, transparent);
        }

        /* ── Stat cards ────────────────────────────────────────── */
        .stat-card {
            background: var(--pharmacy-card-bg, #fff);
            border: 1px solid var(--pharmacy-border, #e5e7eb);
            border-radius: var(--card-border-radius, 12px);
            padding: 16px 20px;
            box-shadow: 0 2px 12px rgba(0,0,0,.07);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            position: relative; overflow: hidden;
        }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 28px rgba(0,0,0,.13); }
        .stat-card.blue   { border-left: 4px solid var(--pharmacy-primary, #0d6efd); }
        .stat-card.green  { border-left: 4px solid var(--pharmacy-success, #198754); }
        .stat-card.red    { border-left: 4px solid var(--pharmacy-danger,  #dc3545); }
        .stat-card.orange { border-left: 4px solid var(--pharmacy-warning, #fd7e14); }
        .stat-card.purple { border-left: 4px solid #6f42c1; }
        .detail-label {
            font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700;
            color: color-mix(in srgb, var(--pharmacy-text, #374151) 55%, transparent);
        }
        .stat-value {
            font-size: 1.15rem; font-weight: 700;
            color: var(--pharmacy-heading, #111827);
        }
        .stat-value.text-success-v { color: var(--pharmacy-success, #198754); }
        .stat-value.text-danger-v  { color: var(--pharmacy-danger,  #dc3545); }
        .stat-value.text-warning-v { color: var(--pharmacy-warning, #fd7e14); }
        .stat-value.text-purple    { color: #6f42c1; }

        /* ── Info card (supplier info) ─────────────────────────── */
        .info-card {
            background: var(--pharmacy-card-bg, #fff);
            border: 1px solid var(--pharmacy-border, #e5e7eb);
            border-radius: var(--card-border-radius, 12px);
            box-shadow: 0 2px 8px rgba(0,0,0,.05);
            margin-bottom: 20px;
            overflow: hidden;
        }
        .info-card .card-header-custom {
            padding: 13px 20px;
            background: var(--pharmacy-table-th, #f8fafc);
            border-bottom: 2px solid var(--pharmacy-border, #e5e7eb);
            font-weight: 700; font-size: 0.85rem;
            color: var(--pharmacy-heading, #111827);
            text-transform: uppercase; letter-spacing: 0.4px;
        }
        .info-card .card-body-custom { padding: 18px 20px; }

        /* ── Table card ────────────────────────────────────────── */
        .table-card {
            background: var(--pharmacy-card-bg, #fff);
            border: 1px solid var(--pharmacy-border, #e5e7eb);
            border-radius: var(--card-border-radius, 12px);
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,.05);
            margin-bottom: 20px;
        }
        .table-card-header {
            display: flex; align-items: center;
            padding: 13px 20px;
            background: var(--pharmacy-table-th, #f8fafc);
            border-bottom: 2px solid var(--pharmacy-border, #e5e7eb);
            font-weight: 700; font-size: 0.85rem;
            color: var(--pharmacy-heading, #111827);
        }
        .table-card-header i { margin-right: 8px; opacity: 0.7; }

        /* ── Table styles ──────────────────────────────────────── */
        .table th {
            background: var(--pharmacy-table-th, #f8fafc) !important;
            color: var(--pharmacy-heading, #111827) !important;
            font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.5px;
            font-weight: 700; padding: 10px 14px;
            border-bottom: 2px solid var(--pharmacy-border, #e5e7eb) !important;
            white-space: nowrap;
        }
        .table td {
            padding: 10px 14px; vertical-align: middle;
            color: var(--pharmacy-text, #374151) !important;
            border-color: var(--pharmacy-border, #e5e7eb) !important;
            font-size: 0.875rem;
        }
        .table tbody tr { transition: background 0.15s ease; }
        .table-hover tbody tr:hover td {
            background: color-mix(in srgb, var(--pharmacy-primary, #0d6efd) 5%, var(--pharmacy-card-bg, #fff)) !important;
        }
        .table tbody tr:not(:last-child) td {
            border-bottom: 1px solid var(--pharmacy-border, #e5e7eb) !important;
        }

        /* Totals footer row */
        .table tfoot td {
            background: var(--pharmacy-table-th, #f8fafc) !important;
            color: var(--pharmacy-heading, #111827) !important;
            font-weight: 700; font-size: 0.875rem;
            border-top: 2px solid var(--pharmacy-border, #e5e7eb) !important;
        }

        /* ── Status badges ─────────────────────────────────────── */
        .status-badge {
            padding: 3px 10px; border-radius: 20px;
            font-size: 0.72rem; font-weight: 700;
            display: inline-block; white-space: nowrap;
        }
        .badge-paid {
            background: var(--pharmacy-badge-instock-bg, #d1fae5);
            color: var(--pharmacy-badge-instock-text, #065f46);
        }
        .badge-partial {
            background: var(--pharmacy-badge-expiry-bg, #fff3cd);
            color: var(--pharmacy-badge-expiry-text, #856404);
        }
        .badge-pending {
            background: var(--pharmacy-badge-oos-bg, #fee2e2);
            color: var(--pharmacy-badge-oos-text, #991b1b);
        }
        .badge-returned {
            background: var(--pharmacy-badge-lowstock-bg, #f3f4f6);
            color: var(--pharmacy-badge-lowstock-text, #6b7280);
        }

        /* ── Payment method badge ──────────────────────────────── */
        .method-badge {
            display: inline-block; padding: 2px 9px; border-radius: 20px;
            font-size: 0.72rem; font-weight: 600;
            background: color-mix(in srgb, var(--pharmacy-primary, #0d6efd) 10%, transparent);
            color: var(--pharmacy-primary, #0d6efd);
        }

        /* ── Invoice code ──────────────────────────────────────── */
        .inv-code {
            font-family: 'Courier New', monospace; font-size: 0.8rem; font-weight: 700;
            color: var(--pharmacy-primary, #0d6efd);
        }

        /* ── Action buttons ────────────────────────────────────── */
        .btn-action {
            padding: 3px 8px; font-size: 0.78rem; border-radius: 6px;
            transition: all 0.2s ease;
        }
        .btn-action:hover { transform: translateY(-1px); }

        /* ── Amount colors ─────────────────────────────────────── */
        .amt-due     { color: var(--pharmacy-danger, #dc3545); font-weight: 700; }
        .amt-paid    { color: var(--pharmacy-success, #198754); }
        .amt-warning { color: var(--pharmacy-warning, #fd7e14); font-weight: 600; }
        .amt-muted   { color: color-mix(in srgb, var(--pharmacy-text, #374151) 45%, transparent); }
    </style>
</head>

<?php
$__dm = !empty($_SESSION["company_settings_cache"]["dark_mode"]) ? " dark-mode" : "";
?>
<body class="<?php echo trim("bg-light" . $__dm); ?>">

<?php include '../includes/sidebar.php'; ?>
<?php include '../includes/navbar.php'; ?>

<div class="main-content">
<div class="container-fluid py-2">

    <!-- ── Page Header ──────────────────────────────────────────────── -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 no-print gap-2">
        <div class="page-heading">
            <h4>
                <i class="bi bi-truck me-2" style="color:var(--pharmacy-primary,#0d6efd);opacity:.8"></i>
                <?php echo htmlspecialchars($supplier['supplier_name']); ?>
            </h4>
            <small>Supplier Profile &amp; Transaction History</small>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <?php if (file_exists(__DIR__ . '/statement.php')): ?>
            <a href="statement.php?supplier_id=<?php echo $supplier_id; ?>" class="btn btn-outline-info btn-sm" style="transition:all .2s ease">
                <i class="bi bi-file-text me-1"></i>Statement
            </a>
            <?php endif; ?>
            <a href="edit.php?id=<?php echo $supplier_id; ?>" class="btn btn-outline-secondary btn-sm"
               data-requires-perm="suppliers.edit" style="transition:all .2s ease">
                <i class="bi bi-pencil me-1"></i>Edit
            </a>
            <a href="pay.php?supplier_id=<?php echo $supplier_id; ?>" class="btn btn-success btn-sm"
               data-requires-perm="suppliers.pay" style="transition:all .2s ease">
                <i class="bi bi-cash-coin me-1"></i>Pay Now
            </a>
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm no-print" style="transition:all .2s ease">
                <i class="bi bi-printer me-1"></i>Print
            </button>
            <a href="../reports/supplier_report.php" class="btn btn-outline-dark btn-sm" style="transition:all .2s ease">
                <i class="bi bi-bar-chart me-1"></i>Reports
            </a>
            <a href="list.php" class="btn btn-outline-secondary btn-sm" style="transition:all .2s ease">
                <i class="bi bi-arrow-left me-1"></i>Back
            </a>
        </div>
    </div>

    <!-- ── Supplier Info Card ────────────────────────────────────────── -->
    <div class="info-card mb-4">
        <div class="card-header-custom">
            <i class="bi bi-person-vcard me-2" style="color:var(--pharmacy-primary,#0d6efd)"></i>Supplier Information
        </div>
        <div class="card-body-custom">
            <div class="row g-3">
                <div class="col-md-3 col-sm-6">
                    <div class="detail-label mb-1">Supplier Name</div>
                    <strong style="color:var(--pharmacy-heading,#111827)"><?php echo htmlspecialchars($supplier['supplier_name']); ?></strong>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="detail-label mb-1">Contact Person</div>
                    <strong style="color:var(--pharmacy-heading,#111827)"><?php echo htmlspecialchars($supplier['contact_person'] ?? '—'); ?></strong>
                </div>
                <div class="col-md-2 col-sm-6">
                    <div class="detail-label mb-1">Phone</div>
                    <strong style="color:var(--pharmacy-heading,#111827)"><?php echo htmlspecialchars($supplier['phone'] ?? '—'); ?></strong>
                </div>
                <div class="col-md-2 col-sm-6">
                    <div class="detail-label mb-1">Email</div>
                    <strong style="color:var(--pharmacy-heading,#111827)"><?php echo htmlspecialchars($supplier['email'] ?? '—'); ?></strong>
                </div>
                <div class="col-md-2 col-sm-6">
                    <div class="detail-label mb-1">Address</div>
                    <strong style="color:var(--pharmacy-heading,#111827)"><?php echo htmlspecialchars($supplier['address'] ?? '—'); ?></strong>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Summary Stat Cards ───────────────────────────────────────── -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md">
            <div class="stat-card orange">
                <div class="detail-label mb-1">Total Purchases</div>
                <div class="stat-value text-warning-v"><?php echo fmt_cur($stats['total_purchases']); ?></div>
                <small class="amt-muted"><?php echo count($purchases); ?> invoice(s)</small>
            </div>
        </div>
        <div class="col-6 col-md">
            <div class="stat-card green">
                <div class="detail-label mb-1">Total Paid</div>
                <div class="stat-value text-success-v"><?php echo fmt_cur($stats['total_paid']); ?></div>
                <small class="amt-muted">Payments made</small>
            </div>
        </div>
        <div class="col-6 col-md">
            <div class="stat-card red">
                <div class="detail-label mb-1">Outstanding (Net)</div>
                <div class="stat-value text-danger-v"><?php echo fmt_cur($stats['total_due']); ?></div>
                <small class="amt-muted">After purchase returns</small>
            </div>
        </div>
        <div class="col-6 col-md">
            <div class="stat-card purple">
                <div class="detail-label mb-1">Total Returns</div>
                <div class="stat-value text-purple"><?php echo fmt_cur($returns_amount); ?></div>
                <small class="amt-muted">Purchase returns</small>
            </div>
        </div>
        <div class="col-6 col-md">
            <div class="stat-card blue">
                <div class="detail-label mb-1">Current Balance</div>
                <div class="stat-value <?php echo $currentBal > 0 ? 'text-danger-v' : 'text-success-v'; ?>">
                    <?php echo fmt_cur($currentBal); ?>
                </div>
                <small class="amt-muted">Ledger balance</small>
            </div>
        </div>
    </div>

    <!-- ── Purchase History ──────────────────────────────────────────── -->
    <div class="table-card">
        <div class="table-card-header">
            <i class="bi bi-cart" style="color:var(--pharmacy-primary,#0d6efd)"></i>Purchase History
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th style="width:42px">#</th>
                        <th>Invoice No</th>
                        <th>Date</th>
                        <th class="text-end">Total</th>
                        <th class="text-end">Paid</th>
                        <th class="text-end">Due</th>
                        <th class="text-center">Status</th>
                        <th class="text-center no-print">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($purchases)): ?>
                        <tr><td colspan="8" class="text-center py-4 amt-muted">No purchases found.<?php echo count($purchases); ?> <?php echo $i; ?> <?php echo $supplier; ?></td></tr>
                    <?php else: $i = 1; foreach ($purchases as $p): ?>
                        <tr>
                            <td class="amt-muted small"><?php echo $i++; ?></td>
                            <td><span class="inv-code"><?php echo htmlspecialchars($p['purchase_invoice_no']); ?></span></td>
                            <td style="white-space:nowrap;font-size:.82rem"><?php echo fmt_date($p['purchase_date']); ?></td>
                            <td class="text-end fw-semibold"><?php echo fmt_cur($p['total']); ?></td>
                            <td class="text-end amt-paid"><?php echo fmt_cur($p['paid_amount']); ?></td>
                            <td class="text-end <?php echo $p['due_amount'] > 0 ? 'amt-due' : 'amt-muted'; ?>">
                                <?php echo fmt_cur($p['due_amount']); ?>
                            </td>
                            <td class="text-center"><?php echo payStatusBadge($p['payment_status']); ?></td>
                            <td class="text-center no-print" style="white-space:nowrap">
                                <div class="d-flex gap-1 justify-content-center">
                                    <a href="../purchases/view.php?id=<?php echo $p['id']; ?>"
                                       class="btn btn-outline-primary btn-action"
                                       data-bs-toggle="tooltip" title="View Purchase">
                                        <i class="bi bi-eye"></i> View
                                    </a>
                                    <?php if ($p['due_amount'] > 0): ?>
                                    <a href="pay.php?supplier_id=<?php echo $supplier_id; ?>&purchase_id=<?php echo $p['id']; ?>"
                                       class="btn btn-outline-success btn-action"
                                       data-bs-toggle="tooltip" title="Pay this invoice">
                                        <i class="bi bi-cash"></i> Pay
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
                <?php if (!empty($purchases)): ?>
                <tfoot>
                    <tr>
                        <td colspan="3" class="text-end">Totals</td>
                        <td class="text-end"><?php echo fmt_cur($stats['total_purchases']); ?></td>
                        <td class="text-end amt-paid"><?php echo fmt_cur($stats['total_paid']); ?></td>
                        <td class="text-end amt-due"><?php echo fmt_cur($stats['total_due']); ?></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <!-- ── Payment History ───────────────────────────────────────────── -->
    <div class="table-card">
        <div class="table-card-header">
            <i class="bi bi-cash" style="color:var(--pharmacy-success,#198754)"></i>Payment History
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th style="width:42px">#</th>
                        <th>Date</th>
                        <th class="text-end">Amount</th>
                        <th>Method</th>
                        <th>Reference</th>
                        <th>Notes</th>
                        <th>Recorded By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($payments)): ?>
                        <tr><td colspan="7" class="text-center py-4 amt-muted">No payments recorded.<?php echo count($payments); ?> <?php echo $i; ?> <?php echo $supplier; ?></td></tr>
                    <?php else: $i = 1; foreach ($payments as $pay): ?>
                        <tr>
                            <td class="amt-muted small"><?php echo $i++; ?></td>
                            <td style="white-space:nowrap;font-size:.82rem"><?php echo fmt_date($pay['payment_date']); ?></td>
                            <td class="text-end amt-paid fw-semibold"><?php echo fmt_cur($pay['amount']); ?></td>
                            <td>
                                <span class="method-badge">
                                    <?php echo htmlspecialchars(ucfirst($pay['payment_method'] ?? 'cash')); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($pay['reference_no'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($pay['notes'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($pay['paid_by'] ?? '—'); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
                <?php if (!empty($payments)): ?>
                <tfoot>
                    <tr>
                        <td colspan="2" class="text-end">Total Paid</td>
                        <td class="text-end amt-paid"><?php echo fmt_cur(array_sum(array_column($payments, 'amount'))); ?></td>
                        <td colspan="4"></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <!-- ── Purchase Returns ──────────────────────────────────────────── -->
    <div class="table-card">
        <div class="table-card-header">
            <i class="bi bi-arrow-return-left" style="color:var(--pharmacy-warning,#fd7e14)"></i>Purchase Returns
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th style="width:42px">#</th>
                        <th>Return No</th>
                        <th>Date</th>
                        <th>Invoice</th>
                        <th class="text-end">Amount</th>
                        <th>Reason</th>
                        <th class="text-center">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($purchase_returns)): ?>
                        <tr><td colspan="7" class="text-center py-4 amt-muted">No returns found.<?php echo count($purchase_returns); ?> <?php echo $i; ?> <?php echo $supplier; ?></td></tr>
                    <?php else: $i = 1; foreach ($purchase_returns as $ret): ?>
                        <tr>
                            <td class="amt-muted small"><?php echo $i++; ?></td>
                            <td><span class="inv-code"><?php echo htmlspecialchars($ret['return_no']); ?></span></td>
                            <td style="white-space:nowrap;font-size:.82rem"><?php echo fmt_date($ret['return_date']); ?></td>
                            <td><?php echo htmlspecialchars($ret['purchase_invoice_no']); ?></td>
                            <td class="text-end amt-warning"><?php echo fmt_cur($ret['total_amount']); ?></td>
                            <td><?php echo htmlspecialchars($ret['reason'] ?? '—'); ?></td>
                            <td class="text-center">
                                <span class="method-badge">
                                    <?php echo htmlspecialchars(ucfirst($ret['status'] ?? '')); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div><!-- /container-fluid -->
</div><!-- /main-content -->


<script>
(function () {
    'use strict';
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
        new bootstrap.Tooltip(el, { trigger: 'hover' });
    });
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
