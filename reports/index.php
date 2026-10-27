<?php
/**
 * reports/index.php
 * Reports Dashboard — Design System Enhanced
 */
require_once '../config/config.php';
require_once '../includes/auth.php';
requireLogin();

if (!canDo('reports', 'view')) {
    if (function_exists('setFlash')) setFlash('error', 'You do not have permission to view reports.');
    header('Location: ' . (defined('BASE_URL') ? BASE_URL : '../') . 'dashboard.php');
    exit;
}
$page_title = 'Reports Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include dirname(__DIR__) . '/includes/page_head.php'; ?>
    
    
    <style>
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
        @media (max-width: 991.98px) { .main-content { margin-left: 0 !important; } }

        /* ── Report Cards ── */
        .report-card {
            background    : var(--pharmacy-card-bg, #fff);
            border        : 1px solid var(--pharmacy-border, #e9ecef);
            border-radius : var(--card-border-radius, 12px);
            box-shadow    : 0 2px 12px rgba(0,0,0,0.07);
            padding       : 28px 20px 20px;
            text-align    : center;
            height        : 100%;
            transition    : transform 0.22s ease, box-shadow 0.22s ease;
            position      : relative;
            overflow      : hidden;
        }
        .report-card::before {
            content       : '';
            position      : absolute;
            top           : 0; left: 0; right: 0;
            height        : 4px;
            background    : var(--card-accent, var(--pharmacy-primary, #0d6efd));
            border-radius : var(--card-border-radius, 12px) var(--card-border-radius, 12px) 0 0;
        }
        .report-card:hover {
            transform  : translateY(-5px);
            box-shadow : 0 12px 32px rgba(0,0,0,0.13);
        }
        .report-card .report-icon {
            font-size     : 3rem;
            margin-bottom : 14px;
            line-height   : 1;
        }
        .report-card .report-title {
            font-size   : 1.05rem;
            font-weight : 700;
            color       : var(--pharmacy-heading, #212529);
            margin-bottom: 8px;
        }
        .report-card .report-desc {
            font-size : 0.8rem;
            color     : color-mix(in srgb, var(--pharmacy-text, #6c757d) 75%, transparent);
            min-height: 40px;
            line-height: 1.45;
        }
        .report-card .btn { transition: all 0.2s ease; }
        .report-card .btn:hover { transform: translateY(-1px); }

        /* ── Tip card ── */
        .tip-card {
            background    : var(--pharmacy-card-bg, #fff);
            border        : 1px solid var(--pharmacy-border, #e9ecef);
            border-radius : var(--card-border-radius, 12px);
            padding       : 18px 22px;
            box-shadow    : 0 1px 6px rgba(0,0,0,0.06);
        }

        /* ── Page header ── */
        h4 { color: var(--pharmacy-heading, #212529); font-weight: 700; }
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
        <div class="mb-4">
            <h4 class="mb-1"><i class="fas fa-chart-line me-2 text-primary"></i>Reports Dashboard</h4>
            <p class="text-muted small mb-0">Select a report to view, filter, or export data</p>
        </div>

        <div class="row g-4">

            <!-- Stock Report -->
            <div class="col-xl-3 col-md-4 col-sm-6">
                <div class="report-card" style="--card-accent:#198754;">
                    <div class="report-icon text-success"><i class="fas fa-boxes"></i></div>
                    <div class="report-title">Stock Report</div>
                    <div class="report-desc">Current inventory status, batch-wise stock levels</div>
                    <a href="stock_report.php" class="btn btn-sm btn-outline-success mt-3 px-4">
                        View Report <i class="fas fa-arrow-right ms-1"></i>
                    </a>
                </div>
            </div>

            <!-- Expiry Report -->
            <div class="col-xl-3 col-md-4 col-sm-6">
                <div class="report-card" style="--card-accent:#dc3545;">
                    <div class="report-icon text-danger"><i class="fas fa-calendar-times"></i></div>
                    <div class="report-title">Expiry Report</div>
                    <div class="report-desc">Expired & near-expiry products, batch-wise expiry alerts</div>
                    <a href="expiry_report.php" class="btn btn-sm btn-outline-danger mt-3 px-4">
                        View Report <i class="fas fa-arrow-right ms-1"></i>
                    </a>
                </div>
            </div>

            <!-- Sales Report -->
            <div class="col-xl-3 col-md-4 col-sm-6">
                <div class="report-card" style="--card-accent:#0d6efd;">
                    <div class="report-icon text-primary"><i class="fas fa-chart-line"></i></div>
                    <div class="report-title">Sales Report</div>
                    <div class="report-desc">Daily sales trend, payment methods, credit outstanding</div>
                    <a href="sales_report.php" class="btn btn-sm btn-outline-primary mt-3 px-4">
                        View Report <i class="fas fa-arrow-right ms-1"></i>
                    </a>
                </div>
            </div>

            <!-- Purchase Report -->
            <div class="col-xl-3 col-md-4 col-sm-6">
                <div class="report-card" style="--card-accent:#0dcaf0;">
                    <div class="report-icon text-info"><i class="fas fa-shopping-cart"></i></div>
                    <div class="report-title">Purchase Report</div>
                    <div class="report-desc">Supplier-wise purchases, payment status, returns</div>
                    <a href="purchase_report.php" class="btn btn-sm btn-outline-info mt-3 px-4">
                        View Report <i class="fas fa-arrow-right ms-1"></i>
                    </a>
                </div>
            </div>

            <!-- Profit & Loss -->
            <div class="col-xl-3 col-md-4 col-sm-6">
                <div class="report-card" style="--card-accent:#6f42c1;">
                    <div class="report-icon" style="color:#6f42c1;"><i class="fas fa-chart-pie"></i></div>
                    <div class="report-title">Profit &amp; Loss</div>
                    <div class="report-desc">Revenue, COGS, expenses, gross &amp; net profit, margins</div>
                    <a href="profit_loss_report.php" class="btn btn-sm mt-3 px-4" style="border-color:#6f42c1;color:#6f42c1;" onmouseover="this.style.background='#6f42c1';this.style.color='#fff';" onmouseout="this.style.background='';this.style.color='#6f42c1';">
                        View Report <i class="fas fa-arrow-right ms-1"></i>
                    </a>
                </div>
            </div>

            <!-- Customer Report -->
            <div class="col-xl-3 col-md-4 col-sm-6">
                <div class="report-card" style="--card-accent:#fd7e14;">
                    <div class="report-icon text-warning"><i class="fas fa-users"></i></div>
                    <div class="report-title">Customer Report</div>
                    <div class="report-desc">Customer-wise sales, credit given, outstanding balances</div>
                    <a href="customer_report.php" class="btn btn-sm btn-outline-warning mt-3 px-4">
                        View Report <i class="fas fa-arrow-right ms-1"></i>
                    </a>
                </div>
            </div>

            <!-- Supplier Report -->
            <div class="col-xl-3 col-md-4 col-sm-6">
                <div class="report-card" style="--card-accent:#20c997;">
                    <div class="report-icon" style="color:#20c997;"><i class="fas fa-building"></i></div>
                    <div class="report-title">Supplier Report</div>
                    <div class="report-desc">Supplier-wise purchase &amp; payment analysis, dues</div>
                    <a href="supplier_report.php" class="btn btn-sm mt-3 px-4" style="border-color:#20c997;color:#20c997;" onmouseover="this.style.background='#20c997';this.style.color='#fff';" onmouseout="this.style.background='';this.style.color='#20c997';">
                        View Report <i class="fas fa-arrow-right ms-1"></i>
                    </a>
                </div>
            </div>

            <!-- Cashbook -->
            <div class="col-xl-3 col-md-4 col-sm-6">
                <div class="report-card" style="--card-accent:#198754;">
                    <div class="report-icon text-success"><i class="fas fa-book-open"></i></div>
                    <div class="report-title">Cashbook Report</div>
                    <div class="report-desc">Daily cash in/out, opening &amp; closing balance, net flow by date range</div>
                    <a href="cashbook_report.php" class="btn btn-sm btn-outline-success mt-3 px-4">
                        View Report <i class="fas fa-arrow-right ms-1"></i>
                    </a>
                </div>
            </div>

        </div>

        <!-- Tip -->
        <div class="tip-card mt-4 d-flex align-items-start gap-3">
            <i class="fas fa-info-circle text-primary fa-lg mt-1"></i>
            <div class="small">
                <strong>Phase 22 — Standardized Reports:</strong> All reports now support
                <i class="fas fa-file-pdf text-danger"></i> <strong>PDF Export</strong>,
                <i class="fas fa-file-excel text-success"></i> <strong>Excel Export</strong>, and
                <i class="fas fa-print text-secondary"></i> <strong>Print</strong>.<br>
                Use date filters on each report page to refine data by date range, supplier, category, or payment method.
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
