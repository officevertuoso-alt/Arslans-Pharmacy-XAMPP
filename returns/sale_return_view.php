<?php
/**
 * returns/sale_return_view.php
 *
 * Sale Return Detail View
 * Enhanced with design system: CSS variables, stat cards, table-card, status badges, smooth transitions
 */

require_once '../config/config.php';
require_once '../includes/return_functions.php';
if (file_exists('../includes/cashbook_functions.php')) {
    require_once '../includes/cashbook_functions.php';
}

requireLogin();

if (!defined('APP_NAME')) {
    define('APP_NAME', defined('SITE_NAME') ? SITE_NAME : 'Pharmacy');
}

$return_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$return_id) {
    setFlash('error', 'Invalid return ID.', 'danger');
    redirect('returns/sale_return_list.php');
}

$return = getSaleReturnDetails($return_id);

if (!$return) {
    setFlash('error', 'Return record not found.', 'danger');
    redirect('returns/sale_return_list.php');
}

$items           = $return['items'] ?? [];
$cashbook_ok     = !empty($return['cashbook_entry_exists']);
$cashbook_amt    = $return['cashbook_amount'] ?? 0;
$cashbook_method = $return['cashbook_payment_method'] ?? '';

$page_title = 'Sale Return ' . ($return['return_no'] ?? '');

function refundBadge($method) {
    $map = [
        'cash'        => ['bg' => '#dcfce7', 'color' => '#166534'],
        'card'        => ['bg' => '#dbeafe', 'color' => '#1e40af'],
        'online'      => ['bg' => '#cffafe', 'color' => '#164e63'],
        'bank'        => ['bg' => '#dbeafe', 'color' => '#1e40af'],
        'credit_note' => ['bg' => '#fef3c7', 'color' => '#92400e'],
        'credit'      => ['bg' => '#fef3c7', 'color' => '#92400e'],
    ];
    $style = $map[strtolower(trim($method ?? ''))] ?? ['bg' => '#f1f5f9', 'color' => '#475569'];
    $label = ucfirst(str_replace('_', ' ', $method ?? 'N/A'));
    return '<span class="status-badge" style="background:' . $style['bg'] . ';color:' . $style['color'] . ';">' . htmlspecialchars($label) . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include dirname(__DIR__) . '/includes/page_head.php'; ?>
    <style>
        /* ── Page fade-in ── */
        @keyframes fadeInPage {
            from { opacity: 0; transform: translateY(10px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .main-content {
            margin-left : var(--sb-w, 260px);
            padding     : 24px;
            min-height  : 100vh;
            animation   : fadeInPage 0.28s ease;
            background  : var(--pharmacy-bg, #f0f2f5);
            transition  : margin-left 0.3s ease;
        }
        body.sb-col-body .main-content { margin-left: var(--sb-w-mini, 62px); }
        @media (max-width: 991.98px) { .main-content { margin-left: 0 !important; } } }

        /* ── Cards ── */
        .card-custom {
            background    : var(--pharmacy-card-bg, #fff);
            border        : 1px solid var(--pharmacy-border, #e9ecef);
            border-radius : var(--card-border-radius, 12px);
            box-shadow    : 0 2px 12px rgba(0,0,0,0.07);
            margin-bottom : 20px;
            transition    : box-shadow 0.2s ease;
        }
        .card-custom:hover { box-shadow: 0 6px 24px rgba(0,0,0,0.11); }

        /* ── Stat Cards ── */
        .stat-card {
            background    : var(--pharmacy-card-bg, #fff);
            border        : 1px solid var(--pharmacy-border, #e9ecef);
            border-left   : 4px solid var(--pharmacy-primary, #0d6efd);
            border-radius : var(--card-border-radius, 12px);
            box-shadow    : 0 2px 12px rgba(0,0,0,0.07);
            padding       : 20px 24px;
            transition    : transform 0.2s ease, box-shadow 0.2s ease;
            height        : 100%;
        }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 28px rgba(0,0,0,0.13); }
        .stat-card .stat-value {
            font-size   : 1.6rem;
            font-weight : 700;
            color       : var(--pharmacy-heading, #212529);
            line-height : 1.2;
            word-break  : break-all;
        }
        .stat-card .stat-label {
            font-size      : 0.72rem;
            color          : color-mix(in srgb, var(--pharmacy-text, #495057) 65%, transparent);
            text-transform : uppercase;
            letter-spacing : 0.5px;
            font-weight    : 600;
        }
        .stat-card .stat-meta { font-size: 0.82rem; color: var(--pharmacy-text, #6c757d); margin-top: 4px; }
        .stat-card .stat-icon { font-size: 1.8rem; opacity: 0.13; }
        .stat-card.accent-danger  { border-left-color: #dc3545; }
        .stat-card.accent-success { border-left-color: #198754; }
        .stat-card.accent-info    { border-left-color: #0dcaf0; }
        .stat-card.accent-warning { border-left-color: #ffc107; }

        /* ── Card headers ── */
        .card-header-custom {
            background    : var(--pharmacy-table-th, #f8f9fa);
            border-bottom : 2px solid var(--pharmacy-border, #e9ecef);
            padding       : 14px 20px;
            border-radius : var(--card-border-radius, 12px) var(--card-border-radius, 12px) 0 0;
            font-weight   : 600;
            color         : var(--pharmacy-heading, #212529);
            display       : flex;
            align-items   : center;
            gap           : 8px;
        }

        /* ── Icon box ── */
        .icon-box {
            width           : 30px;
            height          : 30px;
            border-radius   : 8px;
            display         : inline-flex;
            align-items     : center;
            justify-content : center;
            font-size       : 0.8rem;
            flex-shrink     : 0;
        }

        /* ── Table card ── */
        .table-card {
            border-radius : var(--card-border-radius, 12px);
            overflow      : hidden;
        }
        .table th {
            background     : var(--pharmacy-table-th, #f8f9fa) !important;
            color          : var(--pharmacy-heading, #212529) !important;
            font-size      : 0.75rem;
            text-transform : uppercase;
            letter-spacing : 0.4px;
            font-weight    : 700;
            padding        : 12px 16px;
            border-bottom  : 2px solid var(--pharmacy-border, #e9ecef) !important;
        }
        .table td {
            padding        : 11px 16px;
            vertical-align : middle;
            color          : var(--pharmacy-text, #495057) !important;
            border-color   : var(--pharmacy-border, #e9ecef) !important;
        }
        .table tbody tr { transition: background 0.15s ease; }
        .table-hover tbody tr:hover td {
            background: color-mix(in srgb, var(--pharmacy-primary, #0d6efd) 6%, var(--pharmacy-card-bg, #fff)) !important;
        }
        .table tbody tr:not(:last-child) td {
            border-bottom: 1px solid var(--pharmacy-border, #e9ecef) !important;
        }
        .tfoot-total td {
            background     : var(--pharmacy-table-th, #f8f9fa) !important;
            border-top     : 2px solid var(--pharmacy-border, #e9ecef) !important;
        }

        /* ── Status badges ── */
        .status-badge {
            padding       : 3px 10px;
            border-radius : 20px;
            font-size     : 0.72rem;
            font-weight   : 600;
            display       : inline-block;
        }
        .badge-synced  { background: #d1fae5; color: #065f46; }
        .badge-missing { background: #fee2e2; color: #991b1b; }

        /* ── Detail labels ── */
        .detail-label {
            font-size      : 0.7rem;
            text-transform : uppercase;
            color          : color-mix(in srgb, var(--pharmacy-text, #6c757d) 65%, transparent);
            letter-spacing : 0.06em;
            font-weight    : 600;
            margin-bottom  : 3px;
        }

        /* ── Cashbook strip ── */
        .cashbook-yes {
            background    : #d1fae5;
            border-left   : 4px solid #198754;
            border-radius : 10px;
            padding       : 16px 18px;
        }
        .cashbook-no {
            background    : #fee2e2;
            border-left   : 4px solid #dc3545;
            border-radius : 10px;
            padding       : 16px 18px;
        }

        /* ── Customer link ── */
        .customer-link { color: var(--pharmacy-primary, #1a56db); transition: color 0.15s; }
        .customer-link:hover { text-decoration: underline !important; }

        /* ── Buttons ── */
        .btn { transition: all 0.2s ease; }
        .btn:hover { transform: translateY(-1px); }
        .btn:active { transform: translateY(0); }

        /* ── Page header ── */
        .page-header h4 { color: var(--pharmacy-heading, #212529); font-weight: 700; }

        /* ── Print ── */
        @media print {
            .no-print, #mainSidebar, nav.navbar { display: none !important; }
            .main-content { margin-left: 0 !important; padding: 20px !important; }
            .card-custom { box-shadow: none !important; border: 1px solid #dee2e6 !important; }
            .stat-card   { box-shadow: none !important; border: 1px solid #dee2e6 !important; }
        }
    </style>
</head>
<?php
$__dm = !empty($_SESSION["company_settings_cache"]["dark_mode"]) ? " dark-mode" : "";
?>
<body class="<?php echo trim("bg-light" . $__dm); ?>">

<?php include '../includes/sidebar.php'; ?>
<?php include '../includes/navbar.php'; ?>

<div class="main-content">
    <div class="container-fluid">

        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-start mb-4 no-print page-header">
            <div>
                <h4 class="mb-1">
                    <i class="fas fa-undo-alt me-2 text-warning"></i>
                    Sale Return
                    <span class="fw-normal text-muted">&mdash; <?= htmlspecialchars($return['return_no'] ?? '—') ?></span>
                </h4>
                <p class="text-muted mb-0 small">Return details &amp; cashbook sync status</p>
            </div>
            <div class="d-flex gap-2 no-print">
                <button class="btn btn-outline-secondary" onclick="window.print()">
                    <i class="fas fa-print me-2"></i>Print
                </button>
                <a href="sale_return_list.php" class="btn btn-outline-primary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Returns
                </a>
            </div>
        </div>

        <?php echo renderFlashAll(); ?>

        <!-- Summary Stat Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-4 col-sm-6">
                <div class="stat-card accent-warning">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-label">Return No</div>
                            <div class="stat-value" style="font-size:1.2rem;"><?= htmlspecialchars($return['return_no'] ?? '—') ?></div>
                            <div class="stat-meta">
                                <i class="fas fa-calendar-alt me-1"></i>
                                <?= formatDate($return['created_at'] ?? '') ?>
                            </div>
                        </div>
                        <i class="fas fa-undo-alt stat-icon text-warning"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-4 col-sm-6">
                <div class="stat-card accent-danger">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-label">Total Refund</div>
                            <div class="stat-value"><?= formatCurrency($return['total_amount'] ?? 0) ?></div>
                            <div class="stat-meta">
                                <?= !empty($return['refund_method'])
                                    ? '<i class="fas fa-money-bill-wave me-1"></i>' . ucfirst(str_replace('_',' ',$return['refund_method']))
                                    : '—' ?>
                            </div>
                        </div>
                        <i class="fas fa-money-bill-wave stat-icon text-danger"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-4 col-sm-6">
                <div class="stat-card <?= $cashbook_ok ? 'accent-success' : 'accent-danger'; ?>">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-label">Cashbook Status</div>
                            <div class="stat-value" style="font-size:1.25rem;">
                                <?php if ($cashbook_ok): ?>
                                    <i class="fas fa-check-circle me-1 text-success"></i>Synced
                                <?php else: ?>
                                    <i class="fas fa-exclamation-triangle me-1" style="color:#dc3545;"></i>Missing
                                <?php endif; ?>
                            </div>
                            <div class="stat-meta">
                                <?php if ($cashbook_ok): ?>
                                    <?= formatCurrency($cashbook_amt) ?> recorded
                                <?php else: ?>
                                    No cashbook entry found
                                <?php endif; ?>
                            </div>
                        </div>
                        <i class="fas fa-book-open stat-icon <?= $cashbook_ok ? 'text-success' : ''; ?>" style="<?= !$cashbook_ok ? 'color:#dc3545;' : '' ?>"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Detail Row: Return Info + Original Sale -->
        <div class="row g-4 mb-0">

            <!-- Return Information -->
            <div class="col-lg-6">
                <div class="card-custom">
                    <div class="card-header-custom">
                        <span class="icon-box bg-warning bg-opacity-15 text-warning">
                            <i class="fas fa-undo-alt"></i>
                        </span>
                        Return Information
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-6">
                                <div class="detail-label">Return No</div>
                                <strong style="color:var(--pharmacy-heading,#212529);"><?= htmlspecialchars($return['return_no'] ?? '—') ?></strong>
                            </div>
                            <div class="col-6">
                                <div class="detail-label">Return Date</div>
                                <strong><?= formatDate($return['created_at'] ?? '') ?></strong>
                            </div>
                            <div class="col-6">
                                <div class="detail-label">Refund Method</div>
                                <?= !empty($return['refund_method'])
                                    ? refundBadge($return['refund_method'])
                                    : '<span class="text-muted">—</span>' ?>
                            </div>
                            <div class="col-6">
                                <div class="detail-label">Total Refund</div>
                                <strong class="fs-5" style="color:#dc3545;">
                                    <?= formatCurrency($return['total_amount'] ?? 0) ?>
                                </strong>
                            </div>
                            <?php if (!empty($return['reason'])): ?>
                            <div class="col-12">
                                <div class="detail-label">Reason</div>
                                <span style="color:var(--pharmacy-text,#495057);">
                                    <i class="fas fa-comment-alt me-1 text-muted"></i>
                                    <?= htmlspecialchars($return['reason']) ?>
                                </span>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($return['returned_by'])): ?>
                            <div class="col-6">
                                <div class="detail-label">Processed By</div>
                                <span>
                                    <i class="fas fa-user me-1 text-muted"></i>
                                    <?= htmlspecialchars($return['returned_by']) ?>
                                </span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Original Sale -->
            <div class="col-lg-6">
                <div class="card-custom">
                    <div class="card-header-custom">
                        <span class="icon-box bg-primary bg-opacity-10 text-primary">
                            <i class="fas fa-file-invoice"></i>
                        </span>
                        Original Sale
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-6">
                                <div class="detail-label">Invoice No</div>
                                <?php if (!empty($return['invoice_no']) && $return['invoice_no'] !== '—'): ?>
                                    <a href="../sales/view.php?id=<?= $return['sale_id'] ?>"
                                       class="customer-link fw-semibold text-decoration-none">
                                        <?= htmlspecialchars($return['invoice_no']) ?>
                                        <i class="fas fa-external-link-alt ms-1" style="font-size:.7rem;"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </div>
                            <div class="col-6">
                                <div class="detail-label">Sale Date</div>
                                <strong><?= formatDate($return['sale_date'] ?? '') ?></strong>
                            </div>
                            <div class="col-6">
                                <div class="detail-label">Customer</div>
                                <?php if (!empty($return['customer_phone'])): ?>
                                    <a href="../sales/customer_view.php?phone=<?= urlencode($return['customer_phone']) ?>"
                                       class="customer-link d-block fw-semibold text-decoration-none">
                                        <i class="fas fa-user-circle me-1 text-primary" style="font-size:12px;"></i>
                                        <?= htmlspecialchars($return['customer_name'] ?? '—') ?>
                                    </a>
                                <?php else: ?>
                                    <strong><?= htmlspecialchars($return['customer_name'] ?? '—') ?></strong>
                                <?php endif; ?>
                            </div>
                            <div class="col-6">
                                <div class="detail-label">Phone</div>
                                <?php if (!empty($return['customer_phone'])): ?>
                                    <a href="../sales/customer_view.php?phone=<?= urlencode($return['customer_phone']) ?>"
                                       class="text-muted text-decoration-none customer-link" style="font-size:.9rem;">
                                        <i class="fas fa-phone me-1" style="font-size:.75rem;"></i>
                                        <?= htmlspecialchars($return['customer_phone']) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- Returned Items Table -->
        <div class="card-custom">
            <div class="card-header-custom">
                <span class="icon-box bg-success bg-opacity-10 text-success">
                    <i class="fas fa-box-open"></i>
                </span>
                Returned Items
                <span class="ms-auto badge rounded-pill" style="background:var(--pharmacy-primary,#0d6efd);font-size:0.72rem;">
                    <?= count($items) ?> item<?= count($items) !== 1 ? 's' : '' ?>
                </span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="ps-4">#</th>
                                <th>Product</th>
                                <th>Batch No</th>
                                <th class="text-center">Qty Returned</th>
                                <th class="text-end">Unit Price</th>
                                <th class="text-end pe-4">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($items)): ?>
                                <tr>
                                    <td colspan="6">
                                        <div class="text-center py-5 text-muted">
                                            <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                                            No items found for this return.
                                        </div>
                                    </td>
                                </tr>
                            <?php else:
                                $i = 1;
                                foreach ($items as $item): ?>
                                <tr>
                                    <td class="ps-4 text-muted small"><?= $i++ ?></td>
                                    <td>
                                        <strong class="d-block"><?= htmlspecialchars($item['product_name'] ?? '—') ?></strong>
                                        <?php if (!empty($item['generic_name'])): ?>
                                            <small class="text-muted"><?= htmlspecialchars($item['generic_name']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($item['batch_number'])): ?>
                                            <code class="small text-secondary"><?= htmlspecialchars($item['batch_number']) ?></code>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="status-badge" style="background:#fef3c7;color:#92400e;">
                                            <?= (int)($item['quantity'] ?? 0) ?> pcs
                                        </span>
                                    </td>
                                    <td class="text-end"><?= formatCurrency($item['unit_price'] ?? 0) ?></td>
                                    <td class="text-end pe-4 fw-bold" style="color:#dc3545;">
                                        <?= formatCurrency($item['total_amount'] ?? 0) ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <tr class="tfoot-total">
                                    <td colspan="5" class="text-end fw-bold py-3 pe-3">
                                        <i class="fas fa-calculator me-2 text-muted"></i>Total Refund Amount
                                    </td>
                                    <td class="text-end pe-4 fw-bold fs-5 py-3" style="color:#dc3545;">
                                        <?= formatCurrency($return['total_amount'] ?? 0) ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Cashbook Entry Status -->
        <div class="card-custom">
            <div class="card-header-custom">
                <span class="icon-box bg-secondary bg-opacity-10 text-secondary">
                    <i class="fas fa-book-open"></i>
                </span>
                Cashbook Entry Status
            </div>
            <div class="card-body">
                <?php if ($cashbook_ok): ?>
                    <div class="cashbook-yes d-flex flex-wrap justify-content-between align-items-center gap-3">
                        <div class="d-flex align-items-center gap-2">
                            <span class="status-badge badge-synced">
                                <i class="fas fa-check-circle me-1"></i>Synced
                            </span>
                            <small class="text-muted">Cashbook debit entry has been recorded for this return.</small>
                        </div>
                        <div class="text-end">
                            <div class="detail-label">Cash Out (Debit)</div>
                            <strong class="fs-5" style="color:#dc3545;"><?= formatCurrency($cashbook_amt) ?></strong>
                            <?php if ($cashbook_method): ?>
                                <span class="ms-2"><?= refundBadge($cashbook_method) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="cashbook-no d-flex align-items-center gap-3">
                        <span class="status-badge badge-missing">
                            <i class="fas fa-exclamation-triangle me-1"></i>Not Synced
                        </span>
                        <span class="text-muted small">
                            No cashbook entry found for this return. Go to
                            <a href="sale_return_list.php" class="customer-link">Sale Returns List</a>
                            to fix the missing entry.
                        </span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Action Buttons (bottom) -->
        <div class="d-flex gap-2 justify-content-end mb-4 no-print">
            <button class="btn btn-outline-secondary" onclick="window.print()">
                <i class="fas fa-print me-2"></i>Print
            </button>
            <a href="sale_return.php?invoice_no=<?= urlencode($return['invoice_no'] ?? '') ?>" class="btn btn-outline-warning">
                <i class="fas fa-undo-alt me-2"></i>Process Another Return
            </a>
            <a href="sale_return_list.php" class="btn btn-primary">
                <i class="fas fa-arrow-left me-2"></i>Back to Returns
            </a>
        </div>

        <!-- Print Footer -->
        <div class="d-none d-print-block mt-4 pt-3 text-center text-muted small" style="border-top:1px solid #dee2e6;">
            <p class="mb-0 fw-semibold"><?= htmlspecialchars(APP_NAME) ?> &mdash; Sale Return Receipt</p>
            <p class="mb-0">
                Return No: <strong><?= htmlspecialchars($return['return_no'] ?? '') ?></strong>
                &nbsp;|&nbsp; Printed: <?= date('d M Y H:i') ?>
            </p>
        </div>

    </div>
</div>

<script>
// Init tooltips
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el, { trigger: 'hover' }));
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
