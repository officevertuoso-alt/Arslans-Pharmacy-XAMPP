<?php
/**
 * === FILE: pharmacy_software/dashboard.php ===
 *
 * Dashboard – Phase 7: Standardised dashboard cards via renderDashboardCard().
 * Uses design-system CSS variables, AJAX auto-refresh, and caching.
 */

// ── ISS-028 FIX: Removed inline ?ajax=refresh_stats handler — use ajax/get_dashboard_stats.php ──
if (isset($_GET['ajax']) && $_GET['ajax'] === 'refresh_stats') {
    $qs = http_build_query(array_merge($_GET, ['ajax' => null]));
    header('Location: ' . (defined('BASE_URL') ? BASE_URL : '') . 'ajax/get_dashboard_stats.php' . ($qs ? '?' . $qs : ''));
    exit;
}

require_once 'config/config.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'includes/product_functions.php';
require_once 'includes/cashbook_functions.php';
require_once 'includes/security_helpers.php';
require_once 'includes/sync_engine.php';
require_once 'includes/dashboard_card.php';   // Phase 7: reusable card component

$page_title = 'Dashboard';

// ── Stats with caching ───────────────────────────────────────────────────────
$cacheKey = 'dashboard_stats';
$cacheTTL = 8;
$stats    = function_exists('getDashboardStatsLive') ? getDashboardStatsLive() : getDashboardStats();

$_SESSION[$cacheKey]          = $stats;
$_SESSION[$cacheKey . '_exp'] = time() + $cacheTTL;

if (isset($_SESSION[$cacheKey . '_exp'])) {
    $cacheAge = time() - $_SESSION[$cacheKey . '_exp'] + $cacheTTL;
    $cacheAge = max(0, $cacheAge);
} else {
    $cacheAge = 0;
}

// ── Table data — ISS-021 FIX: 60s session cache ───────────────────────────
$_tablesCacheKey = 'dashboard_tables_cache';
$_tablesCacheExp = $_SESSION[$_tablesCacheKey . '_exp'] ?? 0;
if (!empty($_SESSION[$_tablesCacheKey]) && time() < $_tablesCacheExp) {
    extract($_SESSION[$_tablesCacheKey]);
} else {
    $nearExpiryProducts     = getNearExpiryProducts(8);
    $nearShortStockProducts = getNearShortStockProducts(8);
    $recentSales            = getRecentSales(6);
    $recentPurchases        = getRecentPurchases(6);
    $topProducts            = getTopSellingProducts(5);
    $expiredProducts        = getExpiredProductsList(5);
    $recentActivities       = getRecentActivities(5);
    $totalStockValue        = getTotalStockValue();

    $_SESSION[$_tablesCacheKey] = compact(
        'nearExpiryProducts', 'nearShortStockProducts', 'recentSales',
        'recentPurchases', 'topProducts', 'expiredProducts',
        'recentActivities', 'totalStockValue'
    );
    $_SESSION[$_tablesCacheKey . '_exp'] = time() + 60;
}

// ── DB extras ────────────────────────────────────────────────────────────────
$db = db();

$expiredLossRow   = $db->getOne(
    "SELECT COALESCE(SUM(b.remaining_quantity * b.purchase_price), 0) AS expired_loss
     FROM batches b WHERE b.expiry_date < CURDATE() AND b.remaining_quantity > 0", []
);
$expiredStockLoss = $expiredLossRow ? (float)$expiredLossRow['expired_loss'] : 0;

$cashbookToday = ['opening' => 0, 'total_in' => 0, 'total_out' => 0, 'closing' => 0, 'net_flow' => 0];
try {
    $cb = getDailySummary(date('Y-m-d'));
    if ($cb) $cashbookToday = $cb;
} catch (Exception $e) {}

$yRow          = $db->getOne(
    "SELECT COALESCE(SUM(net_amount),0) AS total FROM sales
     WHERE DATE(sale_date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND status != 'voided'", []
);
$yesterdaySale = $yRow ? (float)$yRow['total'] : 0;
$todaySale     = (float)($stats['today_sale'] ?? 0);
$salesVsYest   = $yesterdaySale > 0
    ? round((($todaySale - $yesterdaySale) / $yesterdaySale) * 100, 1)
    : ($todaySale > 0 ? 100 : 0);

$todayProfit = ($stats['today_sale'] ?? 0) - ($stats['today_purchase_total'] ?? 0);
$netCash     = (float)($stats['net_cash_today'] ?? 0);

function time_elapsed_string($datetime) {
    $now  = new DateTime();
    $ago  = new DateTime($datetime);
    $diff = $now->diff($ago);
    $str  = ['y' => 'year', 'm' => 'month', 'd' => 'day', 'h' => 'hour', 'i' => 'minute', 's' => 'second'];
    foreach ($str as $k => &$v) {
        if ($diff->$k) $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        else unset($str[$k]);
    }
    $str = array_slice($str, 0, 1);
    return $str ? implode(', ', $str) . ' ago' : 'just now';
}

include 'includes/header.php';
?>

<style>
/* ── Dashboard-specific styles (all CSS-var based, no hardcoded colours) ── */

/* Quick action buttons */
.quick-action-btn {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    padding: 18px 10px; border-radius: 14px; border: 1px solid var(--pharmacy-border, #e9ecef);
    transition: all 0.2s ease; text-decoration: none; color: var(--pharmacy-text, #212529);
    background: var(--pharmacy-card-bg, #fff);
    box-shadow: 0 2px 8px rgba(0,0,0,0.06); min-height: 90px;
}
.quick-action-btn:hover {
    transform: translateY(-4px); box-shadow: 0 8px 24px rgba(0,0,0,.12);
    color: var(--pharmacy-text, #212529);
    border-color: var(--pharmacy-primary);
}
.quick-action-btn .qa-icon { font-size: 26px; margin-bottom: 7px; }
.quick-action-btn .qa-label { font-size: 12px; font-weight: 600; text-align: center; }

/* Dash card (table/list cards — distinct from stat cards) */
.dash-card { border-radius: var(--card-border-radius, 12px); border: 1px solid var(--pharmacy-border, #e9ecef); box-shadow: 0 2px 10px rgba(0,0,0,.05); background: var(--pharmacy-card-bg, #fff); margin-bottom: 0; }
.dash-card .card-header { background: var(--pharmacy-table-th, #f8f9fa) !important; border-bottom: 2px solid var(--pharmacy-border, #e9ecef) !important; padding: 13px 18px; font-weight: 600; border-radius: var(--card-border-radius, 12px) var(--card-border-radius, 12px) 0 0; color: var(--pharmacy-heading, #212529); }

/* Cashbook mini tiles */
.cash-mini { border-radius: 10px; padding: 12px 14px; color: #fff; }

/* Live dot */
.live-dot { width: 9px; height: 9px; border-radius: 50%; background: #28a745; display: inline-block; animation: dashPulse 1.5s infinite; }
@keyframes dashPulse { 0%,100%{opacity:1;transform:scale(1);} 50%{opacity:.5;transform:scale(1.4);} }

/* Notification items */
.notif-item { display: flex; align-items: flex-start; gap: 10px; padding: 10px 15px; border-bottom: 1px solid var(--pharmacy-border, #f0f2f5); text-decoration: none; color: var(--pharmacy-text, inherit); transition: background 0.15s; }
.notif-item:hover { background: color-mix(in srgb, var(--pharmacy-primary) 6%, var(--pharmacy-card-bg, #fff)); }
.notif-item:last-child { border-bottom: none; }
.notif-icon { width: 34px; height: 34px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 15px; }

/* Returns rows */
.return-row { display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; border-bottom: 1px solid var(--pharmacy-border, #f0f2f5); }
.return-row:last-child { border-bottom: none; }

/* Period pills */
.period-btn { font-size: 12px; padding: 5px 14px; border-radius: 20px; font-weight: 600; border: 1px solid var(--pharmacy-border, #dee2e6); background: var(--pharmacy-card-bg, #fff); color: var(--pharmacy-text, #212529); cursor: pointer; transition: all 0.15s; }
.period-btn.active, .period-btn:hover { background: var(--pharmacy-primary); color: #fff; border-color: var(--pharmacy-primary); }

/* Alert items in near-expiry / low-stock */
.alert-item { padding: 10px 14px; border-left: 3px solid; margin-bottom: 8px; background: var(--pharmacy-card-bg, #f8f9fa); border-radius: 8px; }

/* Refreshing indicator */
#stats-refreshing { transition: opacity 0.3s ease; }

/* Fade-in animation */
@keyframes fadeInUp { from{opacity:0;transform:translateY(18px);}to{opacity:1;transform:translateY(0);} }
.afu { animation: fadeInUp 0.45s ease forwards; }

@media(max-width: 768px) { .stat-value { font-size: 1.3rem !important; } }

/* ── Phase 8: Chart containers ── */
.chart-card-body { padding: 14px 16px 10px; }
.chart-wrap { position: relative; width: 100%; height: 220px; }
.chart-wrap--doughnut { height: 200px; }
@media(max-width: 576px) {
    .chart-wrap { height: 180px; }
    .chart-wrap--doughnut { height: 160px; }
}
</style>

<?php include 'includes/sidebar.php'; ?>
<?php include 'includes/navbar.php'; ?>

<div class="main-content">
<div class="container-fluid py-3">

    <!-- ── Page Header ────────────────────────────────────────────────── -->
    <div class="d-flex justify-content-between align-items-center mb-3 afu">
        <div>
            <h4 class="mb-1" style="color:var(--pharmacy-heading,#212529); font-weight:700;">
                Welcome back, <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'User'); ?>!
            </h4>
            <p class="text-muted mb-0 small">
                <span class="live-dot me-1"></span>
                <span id="last-updated-label">Stats updated <?php echo $cacheAge < 5 ? 'just now' : $cacheAge . 's ago'; ?></span>
                <span id="stats-refreshing" class="ms-2 text-muted small" style="opacity:0;">
                    <i class="fas fa-circle-notch fa-spin me-1" style="font-size:10px;"></i>Refreshing…
                </span>
            </p>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-sm btn-outline-success rounded-pill" id="refreshStatsBtn">
                <i class="fas fa-sync-alt me-1" id="refresh-icon"></i> Refresh Stats
            </button>
        </div>
    </div>

    <!-- ── Quick Actions ──────────────────────────────────────────────── -->
    <div class="row g-2 mb-4 afu">
        <div class="col-6 col-sm-3">
            <a href="<?php echo BASE_URL; ?>sales/pos.php" class="quick-action-btn">
                <div class="qa-icon text-success"><i class="fas fa-cash-register"></i></div>
                <div class="qa-label">New Sale</div>
            </a>
        </div>
        <div class="col-6 col-sm-3">
            <a href="<?php echo BASE_URL; ?>purchases/add.php" class="quick-action-btn">
                <div class="qa-icon text-warning"><i class="fas fa-shopping-cart"></i></div>
                <div class="qa-label">Add Purchase</div>
            </a>
        </div>
        <div class="col-6 col-sm-3">
            <a href="<?php echo BASE_URL; ?>products/add.php" class="quick-action-btn">
                <div class="qa-icon" style="color:var(--pharmacy-primary);"><i class="fas fa-pills"></i></div>
                <div class="qa-label">Add Product</div>
            </a>
        </div>
        <div class="col-6 col-sm-3">
            <a href="<?php echo BASE_URL; ?>reports/index.php" class="quick-action-btn">
                <div class="qa-icon text-info"><i class="fas fa-chart-bar"></i></div>
                <div class="qa-label">View Reports</div>
            </a>
        </div>
    </div>

    <!-- ── Row 1: Today's Sales · Today's Purchases · Today's Profit ── -->
    <div class="row g-3 mb-3">
        <div class="col-md-4 col-sm-6">
            <?php renderDashboardCard([
                'label'      => "Today's Sales",
                'value'      => 'Rs ' . number_format($stats['today_sale'] ?? 0, 2),
                'icon'       => 'fas fa-rupee-sign',
                'accent'     => 'success',
                'icon_bg'    => '#28a745',
                'id'         => 'sc-today-sale',
                'href'       => BASE_URL . 'sales/list.php',
                'extra_class'=> 'afu',
                'trend'      => '<i class="fas fa-arrow-' . ($salesVsYest >= 0 ? 'up' : 'down') . '"></i> '
                                . abs($salesVsYest) . '% vs yesterday',
                'trend_color'=> $salesVsYest >= 0 ? 'text-success' : 'text-danger',
            ]); ?>
        </div>
        <div class="col-md-4 col-sm-6">
            <?php renderDashboardCard([
                'label'      => "Today's Purchases",
                'value'      => 'Rs ' . number_format($stats['today_purchase_total'] ?? 0, 2),
                'icon'       => 'fas fa-shopping-cart',
                'accent'     => 'purple',
                'id'         => 'sc-today-purchase',
                'href'       => BASE_URL . 'purchases/list.php',
                'extra_class'=> 'afu',
                'trend'      => '<i class="fas fa-box"></i> Stock added today',
                'trend_color'=> 'text-muted',
            ]); ?>
        </div>
        <div class="col-md-4 col-sm-6">
            <?php renderDashboardCard([
                'label'       => "Today's Profit / Loss",
                'value'       => 'Rs ' . number_format(abs($todayProfit), 2),
                'icon'        => 'fas ' . ($todayProfit >= 0 ? 'fa-arrow-trend-up' : 'fa-arrow-trend-down'),
                'accent'      => $todayProfit >= 0 ? 'teal' : 'danger',
                'icon_bg'     => $todayProfit >= 0 ? '#20c997' : '#dc3545',
                'value_color' => $todayProfit >= 0 ? '#20c997' : '#dc3545',
                'id'          => 'sc-today-profit',
                'href'        => BASE_URL . 'reports/profit_loss_report.php?from_date=' . date('Y-m-d') . '&to_date=' . date('Y-m-d'),
                'extra_class' => 'afu',
                'trend'       => $todayProfit >= 0 ? '▲ Net Profit today' : '▼ Net Loss today',
                'trend_color' => $todayProfit >= 0 ? 'text-success' : 'text-danger',
            ]); ?>
        </div>
    </div>

    <!-- ── Row 2: Credit Pending · Credit Given · Credit Collected ───── -->
    <div class="row g-3 mb-3">
        <div class="col-md-4 col-sm-6">
            <?php renderDashboardCard([
                'label'       => 'Credit Pending',
                'value'       => 'Rs ' . number_format($stats['total_credit_pending'] ?? 0, 2),
                'icon'        => 'fas fa-hand-holding-usd',
                'accent'      => 'danger',
                'value_color' => '#dc3545',
                'id'          => 'sc-credit-pending',
                'extra_class' => 'afu',
                'trend'       => '<i class="fas fa-clock"></i> Total outstanding',
                'trend_color' => 'text-danger',
            ]); ?>
        </div>
        <div class="col-md-4 col-sm-6">
            <?php renderDashboardCard([
                'label'       => 'Today Credit Given',
                'value'       => 'Rs ' . number_format($stats['today_credit_given'] ?? 0, 2),
                'icon'        => 'fas fa-credit-card',
                'accent'      => 'warning',
                'value_color' => '#ffc107',
                'id'          => 'sc-credit-given',
                'extra_class' => 'afu',
                'trend'       => '<i class="fas fa-arrow-up"></i> Credit sales today',
                'trend_color' => 'text-warning',
            ]); ?>
        </div>
        <div class="col-md-4 col-sm-6">
            <?php renderDashboardCard([
                'label'       => 'Credit Collected',
                'value'       => 'Rs ' . number_format($stats['today_credit_collected'] ?? 0, 2),
                'icon'        => 'fas fa-money-bill-wave',
                'accent'      => 'success',
                'value_color' => '',
                'id'          => 'sc-credit-collected',
                'extra_class' => 'afu',
                'trend'       => '<i class="fas fa-check"></i> Recovered today',
                'trend_color' => 'text-success',
            ]); ?>
        </div>
    </div>

    <!-- ── Row 3: Sale Returns · Purchase Returns · Expenses · Net Cash ─ -->
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-sm-6">
            <?php renderDashboardCard([
                'label'       => 'Sale Returns',
                'value'       => 'Rs ' . number_format($stats['today_sale_returns'] ?? 0, 2),
                'icon'        => 'fas fa-undo-alt',
                'accent'      => 'orange',
                'value_color' => '#fd7e14',
                'id'          => 'sc-sale-returns',
                'href'        => BASE_URL . 'returns/sale_return_list.php?from_date=' . date('Y-m-d') . '&to_date=' . date('Y-m-d'),
                'extra_class' => 'afu',
                'trend'       => '<i class="fas fa-rotate-left"></i> Today\'s returns',
                'trend_color' => '',
            ]); ?>
        </div>
        <div class="col-md-3 col-sm-6">
            <?php renderDashboardCard([
                'label'       => 'Purchase Returns',
                'value'       => 'Rs ' . number_format($stats['today_purchase_returns'] ?? 0, 2),
                'icon'        => 'fas fa-truck-arrow-right',
                'accent'      => 'purple',
                'value_color' => '#6f42c1',
                'id'          => 'sc-purchase-returns',
                'href'        => BASE_URL . 'returns/purchase_return_list.php?from_date=' . date('Y-m-d') . '&to_date=' . date('Y-m-d'),
                'extra_class' => 'afu',
                'trend'       => '<i class="fas fa-rotate-right"></i> Returned to supplier',
                'trend_color' => '',
            ]); ?>
        </div>
        <div class="col-md-3 col-sm-6">
            <?php renderDashboardCard([
                'label'       => "Today's Expenses",
                'value'       => 'Rs ' . number_format($stats['today_expenses'] ?? 0, 2),
                'icon'        => 'fas fa-file-invoice-dollar',
                'accent'      => 'danger',
                'value_color' => '#dc3545',
                'id'          => 'sc-today-expenses',
                'href'        => BASE_URL . 'expenses/index.php',
                'extra_class' => 'afu',
                'trend'       => '<i class="fas fa-arrow-down"></i> Cash out — expenses',
                'trend_color' => 'text-danger',
            ]); ?>
        </div>
        <div class="col-md-3 col-sm-6">
            <?php renderDashboardCard([
                'label'       => 'Net Cash Today',
                'value'       => 'Rs ' . number_format($netCash, 2),
                'icon'        => 'fas fa-wallet',
                'accent'      => $netCash >= 0 ? 'dark' : 'danger',
                'icon_bg'     => $netCash >= 0 ? 'var(--pharmacy-accent,#343a40)' : '#dc3545',
                'value_color' => $netCash < 0 ? '#dc3545' : '',
                'id'          => 'sc-net-cash',
                'href'        => BASE_URL . 'cashbook/index.php',
                'extra_class' => 'afu',
                'trend'       => '<i class="fas fa-book-open"></i> From cashbook',
                'trend_color' => 'text-muted',
            ]); ?>
        </div>
    </div>

    <!-- ── Expired Stock Loss ─────────────────────────────────────────── -->
    <div class="row g-3 mb-4">
        <div class="col-md-4 col-sm-6">
            <?php renderDashboardCard([
                'label'       => 'Expired Stock Loss',
                'value'       => 'Rs ' . number_format($expiredStockLoss, 2),
                'icon'        => 'fas fa-skull-crossbones',
                'accent'      => 'danger',
                'icon_bg'     => '#6d1a1a',
                'value_color' => '#dc3545',
                'label_color' => '#6d1a1a',
                'id'          => 'sc-expired-loss',
                'href'        => BASE_URL . 'reports/expiry_report.php?filter=current',
                'extra_class' => 'afu',
                'trend'       => '<i class="fas fa-ban"></i> Current expired stock value'
                                 . ' <a href="' . BASE_URL . 'reports/expiry_report.php?filter=current"'
                                 . ' class="ms-2 badge bg-danger text-white" style="font-size:10px;text-decoration:none;"'
                                 . ' onclick="event.stopPropagation();">View &amp; Log</a>',
                'trend_color' => 'text-danger',
            ]); ?>
        </div>
    </div>

    <!-- ── Returns Widget + Cashbook Today + Notifications ───────────── -->
    <div class="row g-4 mb-4">

        <!-- Returns Mini Widget -->
        <div class="col-md-4">
            <div class="dash-card h-100">
                <div class="card-header"><i class="fas fa-exchange-alt me-2 text-warning"></i> Today's Returns</div>
                <div class="card-body p-0">
                    <div class="return-row">
                        <div>
                            <div class="fw-semibold text-warning"><i class="fas fa-undo-alt me-1"></i> Sale Returns</div>
                            <small class="text-muted">Customer returns today</small>
                        </div>
                        <div class="text-end">
                            <div class="fw-bold text-warning" id="rw-sale-returns">Rs <?php echo number_format($stats['today_sale_returns'] ?? 0, 2); ?></div>
                            <a href="<?php echo BASE_URL; ?>returns/sale_return_list.php?from_date=<?php echo date('Y-m-d'); ?>&to_date=<?php echo date('Y-m-d'); ?>"
                               class="badge bg-warning text-dark" style="font-size:10px;">View All</a>
                        </div>
                    </div>
                    <div class="return-row">
                        <div>
                            <div class="fw-semibold" style="color:#6f42c1;"><i class="fas fa-truck-arrow-right me-1"></i> Purchase Returns</div>
                            <small class="text-muted">Supplier returns today</small>
                        </div>
                        <div class="text-end">
                            <div class="fw-bold" style="color:#6f42c1;" id="rw-purchase-returns">Rs <?php echo number_format($stats['today_purchase_returns'] ?? 0, 2); ?></div>
                            <a href="<?php echo BASE_URL; ?>returns/purchase_return_list.php?from_date=<?php echo date('Y-m-d'); ?>&to_date=<?php echo date('Y-m-d'); ?>"
                               class="badge text-white" style="background:#6f42c1;font-size:10px;">View All</a>
                        </div>
                    </div>
                    <div class="return-row" style="background:color-mix(in srgb, var(--pharmacy-primary) 5%, var(--pharmacy-card-bg,#fff));">
                        <div class="fw-semibold" style="color:var(--pharmacy-heading,#212529);">Combined Returns</div>
                        <div class="fw-bold text-danger">
                            Rs <?php echo number_format(($stats['today_sale_returns'] ?? 0) + ($stats['today_purchase_returns'] ?? 0), 2); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Cashbook Today Widget -->
        <div class="col-md-4">
            <div class="dash-card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-book-open me-2 text-success"></i> Cashbook Today</span>
                    <a href="<?php echo BASE_URL; ?>cashbook/index.php?date=<?php echo date('Y-m-d'); ?>" class="btn btn-sm btn-link p-0">Full View</a>
                </div>
                <div class="card-body">
                    <div class="row g-2 mb-2">
                        <div class="col-6"><div class="cash-mini bg-secondary bg-opacity-75"><div class="small opacity-75">Opening</div><div class="fw-bold">Rs <?php echo number_format($cashbookToday['opening'] ?? 0, 0); ?></div></div></div>
                        <div class="col-6"><div class="cash-mini bg-success"><div class="small opacity-75">Cash In</div><div class="fw-bold" id="cb-in">Rs <?php echo number_format($stats['cashbook_today_in'] ?? 0, 0); ?></div></div></div>
                        <div class="col-6"><div class="cash-mini bg-danger"><div class="small opacity-75">Cash Out</div><div class="fw-bold" id="cb-out">Rs <?php echo number_format($stats['cashbook_today_out'] ?? 0, 0); ?></div></div></div>
                        <div class="col-6"><div class="cash-mini <?php echo ($cashbookToday['closing'] ?? 0) >= 0 ? 'bg-primary' : 'bg-danger'; ?>"><div class="small opacity-75">Closing</div><div class="fw-bold">Rs <?php echo number_format($cashbookToday['closing'] ?? 0, 0); ?></div></div></div>
                    </div>
                    <div class="text-center mt-2">
                        <small class="text-muted">Net Flow: <strong class="<?php echo $netCash >= 0 ? 'text-success' : 'text-danger'; ?>" id="cb-net">
                            <?php echo $netCash >= 0 ? '+' : ''; ?>Rs <?php echo number_format($netCash, 2); ?>
                        </strong></small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Alerts & Notifications -->
        <div class="col-md-4">
            <div class="dash-card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-bell me-2 text-warning"></i> Alerts</span>
                    <button class="btn btn-sm btn-outline-secondary" id="refreshNotifBtn"><i class="fas fa-sync-alt"></i></button>
                </div>
                <div class="card-body p-0" style="max-height:230px; overflow-y:auto;">
                    <div id="notif-container">
                        <div class="text-center py-4 text-muted">
                            <div class="spinner-border spinner-border-sm me-2"></div> Loading…
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Near Expiry & Low Stock ────────────────────────────────────── -->
    <div class="row g-4 mb-4">
        <div class="col-md-6">
            <div class="dash-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-hourglass-half text-warning me-2"></i> Near Expiry Products</span>
                    <a href="<?php echo BASE_URL; ?>reports/expiry_report.php?status=near" class="btn btn-sm btn-link">View All</a>
                </div>
                <div class="card-body p-0">
                    <?php if (count($nearExpiryProducts) > 0): ?>
                    <div style="max-height:280px;overflow-y:auto;">
                        <?php foreach ($nearExpiryProducts as $p): ?>
                        <div class="alert-item" style="border-left-color:<?php echo $p['days_left'] <= 7 ? '#dc3545' : ($p['days_left'] <= 15 ? '#ffc107' : '#17a2b8'); ?>;">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <strong style="color:var(--pharmacy-heading,#212529);"><?php echo htmlspecialchars($p['product_name']); ?></strong>
                                    <?php if ($p['generic_name']): ?><br><small class="text-muted"><?php echo htmlspecialchars($p['generic_name']); ?></small><?php endif; ?>
                                </div>
                                <div class="text-end">
                                    <span class="status-badge <?php echo $p['days_left'] <= 7 ? 'badge-expired' : ($p['days_left'] <= 15 ? 'badge-expiring' : 'badge-instock'); ?>">
                                        <?php echo $p['days_left']; ?>d left
                                    </span>
                                    <div><small class="text-muted">Stock: <?php echo $p['remaining_quantity']; ?> pcs</small></div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-4 text-muted"><i class="fas fa-check-circle fa-2x mb-2 text-success d-block"></i>No near expiry products</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="dash-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-exclamation-triangle text-warning me-2"></i> Low Stock Alert</span>
                    <a href="<?php echo BASE_URL; ?>reports/stock_report.php?low_stock=1" class="btn btn-sm btn-link">View All</a>
                </div>
                <div class="card-body p-0">
                    <?php if (count($nearShortStockProducts) > 0): ?>
                    <div style="max-height:280px;overflow-y:auto;">
                        <?php foreach ($nearShortStockProducts as $p): ?>
                        <div class="alert-item" style="border-left-color:#ffc107;">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <strong style="color:var(--pharmacy-heading,#212529);"><?php echo htmlspecialchars($p['product_name']); ?></strong>
                                    <?php if ($p['generic_name']): ?><br><small class="text-muted"><?php echo htmlspecialchars($p['generic_name']); ?></small><?php endif; ?>
                                </div>
                                <div class="text-end">
                                    <span class="status-badge badge-lowstock"><?php echo $p['total_stock']; ?> pcs</span>
                                    <div><small class="text-muted">Reorder: <?php echo $p['reorder_level']; ?></small></div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-4 text-muted"><i class="fas fa-check-circle fa-2x mb-2 text-success d-block"></i>All products sufficient</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Phase 8: Dashboard Charts ─────────────────────────────────── -->

    <!-- Period selector — shared across all trend charts -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h6 class="mb-0 fw-bold" style="color:var(--pharmacy-heading,#212529);">
            <i class="fas fa-chart-area me-2" style="color:var(--pharmacy-primary);"></i>Trend Charts
        </h6>
        <div id="periodSelector" class="d-flex gap-1 flex-wrap">
            <button class="period-btn active" data-period="7days">7 Days</button>
            <button class="period-btn" data-period="30days">30 Days</button>
            <button class="period-btn" data-period="6months">6 Mo</button>
            <button class="period-btn" data-period="12months">12 Mo</button>
        </div>
    </div>

    <!-- Chart loading / error overlay (shared) -->
    <div id="chart-loading" class="text-center py-3 text-muted" style="display:none;">
        <div class="spinner-border spinner-border-sm me-2"></div> Loading charts…
    </div>
    <div id="chart-error" class="alert alert-danger py-2 small" style="display:none;">
        <i class="fas fa-exclamation-circle me-1"></i>
        <span id="chart-error-msg">Failed to load chart data.</span>
        <button class="btn btn-sm btn-outline-danger ms-2" id="chartRetryBtn">Retry</button>
    </div>

    <!-- Row A: Sales Trend + Purchase Trend -->
    <div class="row g-4 mb-4">
        <div class="col-md-6">
            <div class="dash-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-chart-line me-2 text-success"></i> Sales Trend</span>
                    <a href="<?php echo BASE_URL; ?>reports/sales_report.php" class="btn btn-sm btn-link p-0">Full Report</a>
                </div>
                <div class="card-body chart-card-body">
                    <div class="chart-wrap"><canvas id="salesTrendChart"></canvas></div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="dash-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-chart-line me-2 text-danger"></i> Purchase Trend</span>
                    <a href="<?php echo BASE_URL; ?>reports/purchase_report.php" class="btn btn-sm btn-link p-0">Full Report</a>
                </div>
                <div class="card-body chart-card-body">
                    <div class="chart-wrap"><canvas id="purchaseTrendChart"></canvas></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Row B: Revenue Trend + Top Products Chart -->
    <div class="row g-4 mb-4">
        <div class="col-md-7">
            <div class="dash-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-chart-area me-2 text-info"></i> Revenue Trend <small class="text-muted fw-normal ms-1">(Sales − Purchases)</small></span>
                    <a href="<?php echo BASE_URL; ?>reports/profit_loss_report.php" class="btn btn-sm btn-link p-0">P&amp;L Report</a>
                </div>
                <div class="card-body chart-card-body">
                    <div class="chart-wrap"><canvas id="revenueTrendChart"></canvas></div>
                </div>
            </div>
        </div>
        <div class="col-md-5">
            <div class="dash-card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-trophy me-2 text-warning"></i> Top Products</span>
                    <a href="<?php echo BASE_URL; ?>reports/sales_report.php" class="btn btn-sm btn-link p-0">View All</a>
                </div>
                <div class="card-body chart-card-body">
                    <div class="chart-wrap chart-wrap--doughnut"><canvas id="topProductsChart"></canvas></div>
                    <div id="top-products-legend" class="mt-2" style="font-size:11px;max-height:100px;overflow-y:auto;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Recent Sales & Recent Purchases ───────────────────────────── -->
    <div class="row g-4 mb-4">
        <div class="col-md-6">
            <div class="dash-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-receipt me-2 text-success"></i> Recent Sales</span>
                    <a href="<?php echo BASE_URL; ?>sales/list.php" class="btn btn-sm btn-link">View All</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-card">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr><th>Invoice</th><th>Customer</th><th>Amount</th><th>Status</th></tr>
                                </thead>
                                <tbody>
                                    <?php if (count($recentSales) > 0): foreach ($recentSales as $s): ?>
                                    <tr>
                                        <td>
                                            <a href="<?php echo BASE_URL; ?>sales/view.php?id=<?php echo $s['id']; ?>" class="fw-semibold text-decoration-none"><?php echo htmlspecialchars($s['invoice_no']); ?></a>
                                            <?php if (!empty($s['is_credit']) && $s['is_credit']): ?>
                                            <span class="status-badge badge-expiring ms-1">CREDIT</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($s['customer_name']); ?></td>
                                        <td class="fw-bold">Rs <?php echo number_format($s['total'], 2); ?></td>
                                        <td>
                                            <?php
                                            $pm = strtolower($s['payment_method'] ?? 'cash');
                                            $pmBadge = ['cash' => 'badge-instock', 'card' => 'badge-instock', 'online' => 'badge-instock', 'credit' => 'badge-expiring'][$pm] ?? 'badge-oos';
                                            ?>
                                            <span class="status-badge <?php echo $pmBadge; ?>"><?php echo strtoupper($pm); ?></span>
                                        </td>
                                    </tr>
                                    <?php endforeach; else: ?>
                                    <tr><td colspan="4" class="text-center text-muted py-3">No recent sales</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="dash-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-truck me-2 text-info"></i> Recent Purchases</span>
                    <a href="<?php echo BASE_URL; ?>purchases/list.php" class="btn btn-sm btn-link">View All</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-card">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr><th>Invoice</th><th>Supplier</th><th>Amount</th><th>Status</th></tr>
                                </thead>
                                <tbody>
                                    <?php if (count($recentPurchases) > 0): foreach ($recentPurchases as $p):
                                        $pTotal  = (float)($p['total'] ?? 0);
                                        $pPaid   = (float)($p['paid_amount'] ?? $pTotal);
                                        $pDue    = $pTotal - $pPaid;
                                        $pStatus = $p['payment_status'] ?? ($pDue > 0 ? 'pending' : 'paid');
                                        $psBadge = ['paid' => 'badge-instock', 'partial' => 'badge-expiring', 'pending' => 'badge-oos'][$pStatus] ?? 'badge-oos';
                                    ?>
                                    <tr>
                                        <td><a href="<?php echo BASE_URL; ?>purchases/view.php?id=<?php echo $p['id']; ?>" class="fw-semibold text-decoration-none"><?php echo htmlspecialchars($p['purchase_invoice_no']); ?></a></td>
                                        <td><?php echo htmlspecialchars($p['supplier_name']); ?></td>
                                        <td>
                                            <span class="fw-bold">Rs <?php echo number_format($pTotal, 0); ?></span>
                                            <?php if ($pDue > 0): ?><br><small class="text-danger">Due: Rs <?php echo number_format($pDue, 0); ?></small><?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $psBadge; ?>"><?php echo strtoupper($pStatus); ?></span>
                                            <?php if ($pStatus === 'pending' || $pStatus === 'partial'): ?>
                                            <br><span class="status-badge badge-oos" style="font-size:0.65rem;">Pending Payment</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; else: ?>
                                    <tr><td colspan="4" class="text-center text-muted py-3">No recent purchases</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Expired Products + Recent Activities ───────────────────────── -->
    <div class="row g-4 mb-4">
        <div class="col-md-6">
            <div class="dash-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-calendar-times text-danger me-2"></i> Expired Products</span>
                    <a href="<?php echo BASE_URL; ?>reports/expiry_report.php?status=expired" class="btn btn-sm btn-link">View All</a>
                </div>
                <div class="card-body p-0">
                    <?php if (count($expiredProducts) > 0): ?>
                    <div class="table-card">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr><th>Product</th><th>Batch</th><th>Expiry</th><th>Stock</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($expiredProducts as $p): ?>
                                <tr>
                                    <td style="color:var(--pharmacy-heading,#212529);"><?php echo htmlspecialchars($p['product_name']); ?></td>
                                    <td><?php echo htmlspecialchars($p['batch_number']); ?></td>
                                    <td><span class="status-badge badge-expired"><?php echo date('d M Y', strtotime($p['expiry_date'])); ?></span></td>
                                    <td><?php echo $p['remaining_quantity']; ?> pcs</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-4 text-muted"><i class="fas fa-check-circle fa-2x mb-2 text-success d-block"></i>No expired products</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="dash-card">
                <div class="card-header"><i class="fas fa-history me-2 text-secondary"></i> Recent Activities</div>
                <div class="card-body p-0">
                    <div style="max-height:300px;overflow-y:auto;">
                        <?php if (count($recentActivities) > 0): foreach ($recentActivities as $a): ?>
                        <div class="d-flex align-items-start p-3 border-bottom" style="border-color:var(--pharmacy-border,#dee2e6)!important;">
                            <div class="flex-shrink-0">
                                <i class="fas fa-circle mt-1" style="font-size:9px; color:<?php echo strpos($a['action'],'Login')!==false?'#28a745':(strpos($a['action'],'Sale')!==false?'var(--pharmacy-primary)':'#6c757d'); ?>;"></i>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <div class="fw-bold small" style="color:var(--pharmacy-heading,#212529);"><?php echo htmlspecialchars($a['action']); ?></div>
                                <div class="small text-muted">
                                    <?php echo htmlspecialchars($a['description'] ?? ''); ?><br>
                                    <i class="fas fa-user"></i> <?php echo htmlspecialchars($a['full_name'] ?? $a['username'] ?? 'System'); ?>
                                    &nbsp;•&nbsp;
                                    <i class="fas fa-clock"></i> <?php echo time_elapsed_string($a['created_at']); ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; else: ?>
                        <div class="text-center py-4 text-muted"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No recent activities</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div><!-- /container-fluid -->
</div><!-- /main-content -->

<?php if (isset($_SESSION['security_setup_required']) && $_SESSION['security_setup_required'] === true):
    $secUser        = getOne('SELECT security_question FROM users WHERE id = :id', [':id' => (int)$_SESSION['user_id']]);
    $presetQuestion = $secUser['security_question'] ?? '';
    $hasPreset      = ($presetQuestion !== '' && $presetQuestion !== null);
?>
<div class="modal fade" id="securitySetupModal" tabindex="-1"
     data-bs-backdrop="static" data-bs-keyboard="false" aria-labelledby="secSetupLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-460">
        <div class="modal-content">
            <div class="modal-header modal-header-info border-0 px-4 py-3">
                <div class="d-flex align-items-center gap-2">
                    <div style="width:34px;height:34px;background:rgba(255,255,255,.2);border-radius:8px;display:flex;align-items:center;justify-content:center;">
                        <i class="bi bi-shield-lock-fill" style="color:#fff;font-size:15px;"></i>
                    </div>
                    <div>
                        <h5 class="modal-title mb-0" id="secSetupLabel">Security Setup</h5>
                        <div style="color:rgba(255,255,255,.75);font-size:11px;">Password recovery question</div>
                    </div>
                </div>
            </div>
            <div class="modal-body px-4 py-4">
                <?php if ($hasPreset): ?>
                <div class="alert alert-info py-2 mb-3" style="font-size:12px;border-radius:8px;">
                    <i class="bi bi-info-circle-fill me-1"></i>
                    Your administrator has already set a security question for you.
                    You can <strong>confirm it as-is</strong> or <strong>update</strong> the answer below.
                </div>
                <?php else: ?>
                <p style="font-size:13px;color:#6b7280;margin-bottom:16px;">Please set a security question — it will be used to recover your password if forgotten.</p>
                <?php endif; ?>
                <form id="securitySetupForm" autocomplete="off">
                    <input type="hidden" name="user_id" value="<?php echo (int)$_SESSION['user_id']; ?>">
                    <div class="mb-3">
                        <label class="form-label fw-semibold" style="font-size:13px;">Security Question</label>
                        <?php echo renderSecurityQuestionDropdown($presetQuestion, 'question', 'form-select form-select-sm', !$hasPreset); ?>
                        <?php if ($hasPreset): ?><div class="form-text" style="font-size:11px;"><i class="bi bi-lock-fill text-primary me-1"></i>Pre-filled by admin — you may change it.</div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" style="font-size:13px;">
                            <?php echo $hasPreset ? 'Your Answer <span style="color:#6b7280;font-weight:400;">(update or re-enter to confirm)</span>' : 'Your Answer'; ?>
                        </label>
                        <input type="text" name="answer" id="secAnswerInput" class="form-control form-control-sm"
                               placeholder="<?php echo $hasPreset ? 'Enter your answer to confirm or change it' : 'Your answer (case-sensitive)'; ?>"
                               <?php echo $hasPreset ? '' : 'required'; ?> minlength="2" autocomplete="off">
                        <div class="form-text" style="font-size:11px;">Answer is case-sensitive and stored securely (hashed).</div>
                    </div>
                    <div id="setupMessage" class="mb-2"></div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary fw-semibold flex-fill" id="btnSecSave"
                                style="background:linear-gradient(135deg,#3b82f6,#6366f1);border:none;border-radius:9px;padding:10px;">
                            <i class="bi bi-shield-check me-1"></i>Save &amp; Continue
                        </button>
                        <?php if ($hasPreset): ?>
                        <button type="button" class="btn btn-outline-secondary fw-semibold" id="btnSecSkip"
                                style="border-radius:9px;padding:10px 16px;" title="Keep existing security question and proceed">
                            <i class="bi bi-skip-forward-fill me-1"></i>Skip
                        </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var modalEl   = document.getElementById('securitySetupModal');
    var bsModal   = new bootstrap.Modal(modalEl, { backdrop: 'static', keyboard: false });
    bsModal.show();
    var form      = document.getElementById('securitySetupForm');
    var msgDiv    = document.getElementById('setupMessage');
    var submitBtn = document.getElementById('btnSecSave');
    var skipBtn   = document.getElementById('btnSecSkip');
    var AJAX_URL  = '<?php echo rtrim(BASE_URL, "/"); ?>/ajax/complete_security_setup.php';
    var SKIP_URL  = '<?php echo rtrim(BASE_URL, "/"); ?>/ajax/skip_security_setup.php';
    form.addEventListener('submit', function (e) {
        e.preventDefault(); e.stopPropagation();
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span>Saving...';
        msgDiv.innerHTML = '';
        fetch(AJAX_URL, { method: 'POST', body: new FormData(form), headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (data) {
            if (data.success) {
                msgDiv.innerHTML = '<div class="alert alert-success py-2 mb-0"><i class="bi bi-check-circle me-1"></i>' + data.message + '</div>';
                setTimeout(function () { window.location.href = data.redirect || window.location.href; }, 1200);
            } else {
                msgDiv.innerHTML = '<div class="alert alert-danger py-2 mb-0"><i class="bi bi-exclamation-circle me-1"></i>' + (data.message || 'Unknown error.') + '</div>';
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="bi bi-shield-check me-1"></i>Save &amp; Continue';
            }
        })
        .catch(function (err) {
            msgDiv.innerHTML = '<div class="alert alert-danger py-2 mb-0"><i class="bi bi-exclamation-circle me-1"></i>Could not reach server (' + err.message + ').</div>';
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="bi bi-shield-check me-1"></i>Save &amp; Continue';
        });
    });
    if (skipBtn) {
        skipBtn.addEventListener('click', function () {
            skipBtn.disabled = true;
            skipBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>';
            fetch(SKIP_URL, { method: 'POST', body: new URLSearchParams({ user_id: '<?php echo (int)$_SESSION['user_id']; ?>' }), headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) { window.location.href = data.redirect || window.location.href; }
                else { msgDiv.innerHTML = '<div class="alert alert-warning py-2 mb-0">' + (data.message || 'Could not skip.') + '</div>'; skipBtn.disabled = false; skipBtn.innerHTML = '<i class="bi bi-skip-forward-fill me-1"></i>Skip'; }
            })
            .catch(function () { skipBtn.disabled = false; skipBtn.innerHTML = '<i class="bi bi-skip-forward-fill me-1"></i>Skip'; });
        });
    }
}());
</script>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
// ────────────────────────────────────────────────────────────────────────────
// Phase 8 – Dashboard Charts
// Charts: Sales Trend | Purchase Trend | Revenue Trend | Top Products
// Theme: reads CSS variables at runtime → works with all themes (light/dark)
// Responsive: all canvases live inside a .chart-wrap div; Chart.js
//   responsive:true + maintainAspectRatio:false fills the container exactly.
// ────────────────────────────────────────────────────────────────────────────

// ── Stats refresh (unchanged) ───────────────────────────────────────────────
let secondsSinceUpdate = 0;
const ajaxUrl = '<?php echo BASE_URL; ?>ajax/get_dashboard_stats.php';

function formatRs(val) {
    return 'Rs ' + Number(val).toLocaleString('en-PK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function animateChanged($el, newText, isPositive) {
    const oldText = $el.text();
    $el.text(newText);
    if (oldText !== newText) {
        $el.css({ 'background': isPositive !== false ? '#d1fae5' : '#fee2e2', 'border-radius': '4px', 'transition': 'background 0.3s' });
        setTimeout(function () { $el.css('background', ''); }, 1200);
    }
}

function applyStats(s) {
    animateChanged($('#sc-today-sale'),       formatRs(s.today_sale ?? 0), true);
    animateChanged($('#sc-today-purchase'),   formatRs(s.today_purchase_total ?? 0), false);
    animateChanged($('#sc-credit-pending'),   formatRs(s.total_credit_pending ?? 0), null);
    animateChanged($('#sc-credit-given'),     formatRs(s.today_credit_given ?? 0), null);
    animateChanged($('#sc-credit-collected'), formatRs(s.today_credit_collected ?? 0), true);
    animateChanged($('#sc-sale-returns'),     formatRs(s.today_sale_returns ?? 0), false);
    animateChanged($('#sc-purchase-returns'), formatRs(s.today_purchase_returns ?? 0), false);
    animateChanged($('#sc-net-cash'),         formatRs(s.net_cash_today ?? 0), true);
    if (s.today_expenses !== undefined)     animateChanged($('#sc-today-expenses'), formatRs(s.today_expenses ?? 0), false);
    if (s.expired_stock_loss !== undefined) animateChanged($('#sc-expired-loss'), formatRs(s.expired_stock_loss ?? 0), false);

    const profit     = (s.today_sale ?? 0) - (s.today_purchase_total ?? 0);
    const profitText = 'Rs ' + Math.abs(profit).toLocaleString('en-PK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    animateChanged($('#sc-today-profit'), profitText, profit >= 0);
    $('#sc-today-profit').css('color', profit >= 0 ? '#20c997' : '#dc3545');

    animateChanged($('#rw-sale-returns'),     formatRs(s.today_sale_returns ?? 0), false);
    animateChanged($('#rw-purchase-returns'), formatRs(s.today_purchase_returns ?? 0), false);

    $('#cb-in').text('Rs '  + Number(s.cashbook_today_in  ?? 0).toLocaleString('en-PK', { minimumFractionDigits: 0 }));
    $('#cb-out').text('Rs ' + Number(s.cashbook_today_out ?? 0).toLocaleString('en-PK', { minimumFractionDigits: 0 }));
    const net = s.net_cash_today ?? 0;
    $('#cb-net').text((net >= 0 ? '+' : '') + 'Rs ' + Math.abs(net).toLocaleString('en-PK', { minimumFractionDigits: 2 }))
               .removeClass('text-success text-danger').addClass(net >= 0 ? 'text-success' : 'text-danger');
}

function showRefreshing(on) { $('#stats-refreshing').css('opacity', on ? '1' : '0'); }

function refreshStats(showSpinner) {
    if (showSpinner) $('#refresh-icon').addClass('fa-spin');
    showRefreshing(true);
    $.getJSON(ajaxUrl)
        .done(function (data) {
            if (data && !data.error) {
                applyStats(data);
                secondsSinceUpdate = 0;
                $('#last-updated-label').text('Stats updated just now');
            }
        })
        .always(function () {
            $('#refresh-icon').removeClass('fa-spin');
            showRefreshing(false);
        });
}

setInterval(function () {
    secondsSinceUpdate++;
    if (secondsSinceUpdate < 60) {
        $('#last-updated-label').text('Stats updated ' + secondsSinceUpdate + 's ago');
    } else {
        $('#last-updated-label').text('Stats updated ' + Math.floor(secondsSinceUpdate / 60) + 'm ago');
    }
}, 1000);

setInterval(function () { refreshStats(false); }, 8000);
$('#refreshStatsBtn').on('click', function () { refreshStats(true); });

// ── Notifications ────────────────────────────────────────────────────────────
const notifColorMap = {
    expired:    { bg: '#dc3545', icon: 'bi bi-slash-circle-fill' },
    credit_due: { bg: '#0dcaf0', icon: 'bi bi-cash-coin' },
    expiry:     { bg: '#fd7e14', icon: 'bi bi-calendar-x-fill' },
    low_stock:  { bg: '#ffc107', icon: 'bi bi-boxes' }
};
function loadNotifications() {
    const $c = $('#notif-container');
    $c.html('<div class="text-center py-3 text-muted"><div class="spinner-border spinner-border-sm me-2"></div> Loading…</div>');
    $.getJSON('<?php echo BASE_URL; ?>ajax/get_notifications.php').done(function (data) {
        if (!data.success || data.total_count === 0) {
            $c.html('<div class="text-center py-3 text-muted"><i class="fas fa-check-circle fa-2x mb-2 text-success d-block"></i>All clear!</div>');
            if ($('#notif-badge').length) $('#notif-badge').hide();
            return;
        }
        if ($('#notif-badge').length) $('#notif-badge').text(data.total_count).show();
        let html = '';
        data.notifications.forEach(function (n) {
            const st = notifColorMap[n.type] || { bg: '#6c757d', icon: 'bi bi-bell-fill' };
            html += `<a href="${n.link}" class="notif-item"><div class="notif-icon" style="background:${st.bg}22;color:${st.bg};"><i class="${st.icon}"></i></div><div style="font-size:12px;line-height:1.4;">${n.message}</div></a>`;
        });
        $c.html(html);
    }).fail(function () {
        $c.html('<div class="text-center py-3 text-danger small">Failed to load</div>');
    });
}
$('#refreshNotifBtn').on('click', loadNotifications);
loadNotifications();

// ── Phase 8: Chart Engine ────────────────────────────────────────────────────

/**
 * Read a CSS variable from :root at runtime.
 * This is the key to theme compatibility — no hardcoded hex values.
 */
function cssVar(name, fallback) {
    const val = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    return val || fallback;
}

/**
 * Build a Chart.js theme-aware options object shared by all charts.
 * Re-called on each render so picks up the current theme's variables.
 */
function makeChartTheme() {
    const textColor    = cssVar('--pharmacy-text',    '#212529');
    const borderColor  = cssVar('--pharmacy-border',  '#e9ecef');
    const cardBg       = cssVar('--pharmacy-card-bg', '#ffffff');
    return {
        gridColor:  borderColor,
        tickColor:  textColor,
        legendColor: textColor,
        tooltipBg:  cardBg,
        tooltipText: textColor,
    };
}

// Chart instances (kept so we can update data without full re-init)
let chartSales    = null;
let chartPurchase = null;
let chartRevenue  = null;
let chartTopProd  = null;

/**
 * Shared base Chart.js options for line charts.
 * All colors come from CSS vars via makeChartTheme().
 */
function lineChartOptions(theme, yLabel, clickUrl) {
    return {
        responsive: true,
        maintainAspectRatio: false,   // fills .chart-wrap height exactly
        animation: { duration: 400 },
        plugins: {
            legend: {
                display: false,  // each chart has its own title in the card-header
            },
            tooltip: {
                backgroundColor: theme.tooltipBg,
                titleColor:      theme.tooltipText,
                bodyColor:       theme.tooltipText,
                borderColor:     theme.gridColor,
                borderWidth:     1,
                callbacks: {
                    label: function(c) {
                        return ' Rs ' + Number(c.raw).toLocaleString('en-PK', { minimumFractionDigits: 0 });
                    }
                }
            }
        },
        scales: {
            x: {
                grid:  { color: theme.gridColor },
                ticks: { color: theme.tickColor, maxRotation: 45, font: { size: 11 } }
            },
            y: {
                beginAtZero: true,
                grid:  { color: theme.gridColor },
                ticks: {
                    color: theme.tickColor,
                    font:  { size: 11 },
                    callback: function(v) {
                        if (v >= 1000000) return 'Rs ' + (v/1000000).toFixed(1) + 'M';
                        if (v >= 1000)    return 'Rs ' + (v/1000).toFixed(0) + 'k';
                        return 'Rs ' + v;
                    }
                }
            }
        },
        onClick: function(event, elements) {
            if (clickUrl && elements && elements.length > 0) {
                window.location.href = clickUrl;
            }
        }
    };
}

/**
 * Create or update the Sales Trend chart.
 */
function renderSalesChart(data, theme) {
    const ctx = document.getElementById('salesTrendChart').getContext('2d');
    const primary = cssVar('--pharmacy-primary', '#3b82f6');
    const ds = {
        label: 'Sales',
        data:  data.sales_data,
        borderColor:     primary,
        backgroundColor: primary + '22',
        borderWidth: 2,
        fill: true,
        tension: 0.4,
        pointRadius: 3,
        pointHoverRadius: 5,
    };

    if (chartSales) {
        chartSales.data.labels      = data.labels;
        chartSales.data.datasets[0] = ds;
        chartSales.options = lineChartOptions(theme, 'Sales', '<?php echo BASE_URL; ?>reports/sales_report.php');
        chartSales.update('none');
    } else {
        chartSales = new Chart(ctx, {
            type: 'line',
            data: { labels: data.labels, datasets: [ds] },
            options: lineChartOptions(theme, 'Sales', '<?php echo BASE_URL; ?>reports/sales_report.php')
        });
    }
}

/**
 * Create or update the Purchase Trend chart.
 */
function renderPurchaseChart(data, theme) {
    const ctx = document.getElementById('purchaseTrendChart').getContext('2d');
    const ds = {
        label: 'Purchases',
        data:  data.purchase_data,
        borderColor:     '#dc3545',
        backgroundColor: 'rgba(220,53,69,0.12)',
        borderWidth: 2,
        fill: true,
        tension: 0.4,
        pointRadius: 3,
        pointHoverRadius: 5,
    };

    if (chartPurchase) {
        chartPurchase.data.labels      = data.labels;
        chartPurchase.data.datasets[0] = ds;
        chartPurchase.options = lineChartOptions(theme, 'Purchases', '<?php echo BASE_URL; ?>reports/purchase_report.php');
        chartPurchase.update('none');
    } else {
        chartPurchase = new Chart(ctx, {
            type: 'line',
            data: { labels: data.labels, datasets: [ds] },
            options: lineChartOptions(theme, 'Purchases', '<?php echo BASE_URL; ?>reports/purchase_report.php')
        });
    }
}

/**
 * Create or update the Revenue Trend chart (net profit = sales − purchases).
 * Uses a bar chart; bars are green for profit, red for loss.
 */
function renderRevenueChart(data, theme) {
    const ctx = document.getElementById('revenueTrendChart').getContext('2d');
    const barColors = data.revenue_data.map(function(v) {
        return v >= 0 ? 'rgba(32,201,151,0.75)' : 'rgba(220,53,69,0.75)';
    });
    const borderColors = data.revenue_data.map(function(v) {
        return v >= 0 ? '#20c997' : '#dc3545';
    });

    const ds = {
        label: 'Net Revenue',
        data:  data.revenue_data,
        backgroundColor: barColors,
        borderColor:     borderColors,
        borderWidth: 1.5,
        borderRadius: 4,
    };

    const opts = lineChartOptions(theme, 'Revenue', '<?php echo BASE_URL; ?>reports/profit_loss_report.php');
    // Bar chart: switch to bar type, remove fill/tension
    opts.plugins.tooltip.callbacks.label = function(c) {
        const sign = c.raw >= 0 ? '+' : '';
        return sign + ' Rs ' + Number(c.raw).toLocaleString('en-PK', { minimumFractionDigits: 0 });
    };

    if (chartRevenue) {
        chartRevenue.data.labels      = data.labels;
        chartRevenue.data.datasets[0] = ds;
        chartRevenue.options = opts;
        chartRevenue.update('none');
    } else {
        chartRevenue = new Chart(ctx, {
            type: 'bar',
            data: { labels: data.labels, datasets: [ds] },
            options: opts
        });
    }
}

/**
 * Doughnut palette — 8 distinct colours that work on both light & dark themes.
 */
const DOUGHNUT_PALETTE = [
    '#3b82f6','#10b981','#f59e0b','#ef4444',
    '#8b5cf6','#06b6d4','#f97316','#84cc16'
];

/**
 * Create or update the Top Products doughnut chart.
 */
function renderTopProductsChart(data, theme) {
    const tp  = data.top_products;
    const ctx = document.getElementById('topProductsChart').getContext('2d');

    if (!tp || tp.labels.length === 0) {
        // Show a "No data" placeholder
        document.getElementById('topProductsChart').style.display = 'none';
        document.getElementById('top-products-legend').innerHTML =
            '<div class="text-center text-muted py-3"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No sales data for this period</div>';
        return;
    }
    document.getElementById('topProductsChart').style.display = '';

    const ds = {
        label: 'Qty Sold',
        data:  tp.quantities,
        backgroundColor: DOUGHNUT_PALETTE,
        borderWidth: 2,
        borderColor: cssVar('--pharmacy-card-bg', '#ffffff'),
        hoverOffset: 8,
    };

    const opts = {
        responsive: true,
        maintainAspectRatio: false,
        animation: { duration: 400 },
        cutout: '60%',
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: theme.tooltipBg,
                titleColor:      theme.tooltipText,
                bodyColor:       theme.tooltipText,
                borderColor:     theme.gridColor,
                borderWidth:     1,
                callbacks: {
                    label: function(c) {
                        const amt = tp.amounts[c.dataIndex];
                        return ' ' + c.label + ': ' + c.raw + ' pcs (Rs ' + Number(amt).toLocaleString('en-PK', { minimumFractionDigits: 0 }) + ')';
                    }
                }
            }
        }
    };

    if (chartTopProd) {
        chartTopProd.data.labels      = tp.labels;
        chartTopProd.data.datasets[0] = ds;
        chartTopProd.options = opts;
        chartTopProd.update('none');
    } else {
        chartTopProd = new Chart(ctx, {
            type: 'doughnut',
            data: { labels: tp.labels, datasets: [ds] },
            options: opts
        });
    }

    // Custom legend below the chart
    let legendHtml = '<div class="d-flex flex-wrap gap-1">';
    tp.labels.forEach(function(label, i) {
        const color = DOUGHNUT_PALETTE[i % DOUGHNUT_PALETTE.length];
        legendHtml += `<span style="color:${theme.tickColor};white-space:nowrap;">
            <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:${color};margin-right:3px;"></span>
            ${label}
        </span>`;
    });
    legendHtml += '</div>';
    document.getElementById('top-products-legend').innerHTML = legendHtml;
}

/**
 * Master chart loader — fetches data, renders all 4 charts.
 */
function loadChartData(period) {
    $('#chart-loading').show();
    $('#chart-error').hide();

    $.getJSON('<?php echo BASE_URL; ?>ajax/get_sales_chart_data.php', { period: period })
    .done(function(data) {
        if (!data || !data.success) {
            $('#chart-error-msg').text(data && data.message ? data.message : 'Server returned an error.');
            $('#chart-error').show();
            return;
        }

        // Read theme at render time for correct light/dark colours
        const theme = makeChartTheme();
        renderSalesChart(data, theme);
        renderPurchaseChart(data, theme);
        renderRevenueChart(data, theme);
        renderTopProductsChart(data, theme);
    })
    .fail(function(xhr, status, err) {
        $('#chart-error-msg').text('Network error: ' + (err || status));
        $('#chart-error').show();
    })
    .always(function() {
        $('#chart-loading').hide();
    });
}

// Period pill click
$('#periodSelector').on('click', '.period-btn', function() {
    $('#periodSelector .period-btn').removeClass('active');
    $(this).addClass('active');
    loadChartData($(this).data('period'));
});

// Retry button
$('#chartRetryBtn').on('click', function() {
    const activePeriod = $('#periodSelector .period-btn.active').data('period') || '7days';
    loadChartData(activePeriod);
});

// Initial load
loadChartData('7days');
</script>

<?php include __DIR__ . '/includes/first_login_modal.php'; ?>
<?php include 'includes/footer.php'; ?>