<?php
/**
 * Notifications Component for Navbar
 * BUG #7 FIX: Replaced 4 separate COUNT queries with 1 optimized combined query.
 * Added session cache with 60-second TTL to prevent repeated DB hits on every page load.
 */

/**
 * Returns aggregated notification counts from a single optimized DB query.
 * Results are cached in $_SESSION for 60 seconds to reduce DB load.
 *
 * @return array { expired_count, near_expiry_count, low_stock_count, out_of_stock_count }
 */
function _getNotificationStats(): array {
    // Session cache with 60-second TTL
    $cacheKey = '_notif_stats';
    $ttlKey   = '_notif_stats_ts';
    if (isset($_SESSION[$cacheKey]) && isset($_SESSION[$ttlKey])
        && (time() - $_SESSION[$ttlKey]) < 60) {
        return $_SESSION[$cacheKey];
    }

    try {
        $pdo  = db()->getConnection();
        $stmt = $pdo->query(
            "SELECT
                SUM(CASE WHEN expiry_date < CURDATE()
                         AND remaining_quantity > 0 THEN 1 ELSE 0 END)          AS expired_count,
                SUM(CASE WHEN expiry_date BETWEEN CURDATE()
                         AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                         AND remaining_quantity > 0 THEN 1 ELSE 0 END)          AS near_expiry_count,
                COUNT(DISTINCT CASE WHEN remaining_quantity <= 10
                                    AND remaining_quantity > 0
                                    AND expiry_date > CURDATE()
                               THEN product_id END)                              AS low_stock_count,
                COUNT(DISTINCT CASE WHEN remaining_quantity = 0
                               THEN product_id END)                              AS out_of_stock_count
             FROM batches"
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        error_log('[notifications] DB error: ' . $e->getMessage());
        $row = [];
    }

    $stats = [
        'expired_count'      => (int)($row['expired_count']      ?? 0),
        'near_expiry_count'  => (int)($row['near_expiry_count']  ?? 0),
        'low_stock_count'    => (int)($row['low_stock_count']    ?? 0),
        'out_of_stock_count' => (int)($row['out_of_stock_count'] ?? 0),
    ];

    // Store in session cache
    $_SESSION[$cacheKey] = $stats;
    $_SESSION[$ttlKey]   = time();

    return $stats;
}

function getNotificationCount(): int {
    $stats = _getNotificationStats();
    return $stats['expired_count']
         + $stats['near_expiry_count']
         + $stats['low_stock_count'];
}

function renderNotifications(): string {
    $db    = db();
    $stats = _getNotificationStats();

    $notifications = [];

    if ($stats['expired_count'] > 0) {
        $notifications[] = [
            'type'    => 'danger',
            'title'   => 'Expired Products',
            'message' => $stats['expired_count'] . ' product(s) have expired.',
            'icon'    => 'fas fa-calendar-times',
            'link'    => BASE_URL . 'reports/expiry_report.php?status=expired',
        ];
    }

    if ($stats['near_expiry_count'] > 0) {
        $notifications[] = [
            'type'    => 'warning',
            'title'   => 'Near Expiry Products',
            'message' => $stats['near_expiry_count'] . ' product(s) will expire within 30 days.',
            'icon'    => 'fas fa-hourglass-half',
            'link'    => BASE_URL . 'reports/expiry_report.php?status=near',
        ];
    }

    if ($stats['low_stock_count'] > 0) {
        $notifications[] = [
            'type'    => 'warning',
            'title'   => 'Low Stock Alert',
            'message' => $stats['low_stock_count'] . ' product(s) are running low on stock.',
            'icon'    => 'fas fa-exclamation-triangle',
            'link'    => BASE_URL . 'reports/stock_report.php?low_stock=1',
        ];
    }

    if ($stats['out_of_stock_count'] > 0) {
        $notifications[] = [
            'type'    => 'danger',
            'title'   => 'Out of Stock',
            'message' => $stats['out_of_stock_count'] . ' product(s) are out of stock.',
            'icon'    => 'fas fa-times-circle',
            'link'    => BASE_URL . 'reports/stock_report.php',
        ];
    }

    // Today's sales (single lightweight query — not in batch stats)
    try {
        $pdo  = db()->getConnection();
        $stmt = $pdo->query("SELECT COUNT(*) as cnt, COALESCE(SUM(total), 0) as total FROM sales WHERE DATE(sale_date) = CURDATE()");
        $todaySales = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['cnt' => 0, 'total' => 0];
    } catch (\Throwable $e) {
        $todaySales = ['cnt' => 0, 'total' => 0];
    }

    if ((int)($todaySales['cnt'] ?? 0) > 0) {
        $notifications[] = [
            'type'    => 'success',
            'title'   => "Today's Sales",
            'message' => $todaySales['cnt'] . ' invoice(s) | Total: ' . CURRENCY . ' ' . number_format($todaySales['total'], 2),
            'icon'    => 'fas fa-chart-line',
            'link'    => BASE_URL . 'sales/list.php',
        ];
    }

    $totalCount = count($notifications);

    // ── Build HTML ───────────────────────────────────────────────────────────
    $html  = '<div class="dropdown me-3">';
    $html .= '<a href="#" class="text-dark position-relative" data-bs-toggle="dropdown">';
    $html .= '<i class="fas fa-bell fa-lg"></i>';

    if ($totalCount > 0) {
        $html .= '<span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">';
        $html .= ($totalCount > 9 ? '9+' : $totalCount);
        $html .= '</span>';
    }

    $html .= '</a>';
    $html .= '<ul class="dropdown-menu dropdown-menu-end shadow" style="width: 350px;">';
    $html .= '<li><h6 class="dropdown-header"><i class="fas fa-bell me-2"></i>Notifications</h6></li>';

    if ($totalCount > 0) {
        foreach ($notifications as $notif) {
            $bgClass = ($notif['type'] === 'danger')  ? 'text-danger'
                     : ($notif['type'] === 'warning' ? 'text-warning' : 'text-success');
            $html .= '<li>';
            $html .= '<a class="dropdown-item" href="' . $notif['link'] . '">';
            $html .= '<div class="d-flex align-items-start">';
            $html .= '<div class="flex-shrink-0"><i class="' . $notif['icon'] . ' ' . $bgClass . ' me-3"></i></div>';
            $html .= '<div class="flex-grow-1">';
            $html .= '<strong>' . htmlspecialchars($notif['title']) . '</strong><br>';
            $html .= '<small class="text-muted">' . htmlspecialchars($notif['message']) . '</small>';
            $html .= '</div>';
            $html .= '</div>';
            $html .= '</a>';
            $html .= '</li>';
        }
        $html .= '<li><hr class="dropdown-divider"></li>';
        $html .= '<li><a class="dropdown-item text-center text-primary" href="' . BASE_URL . 'logs/activity.php">';
        $html .= '<i class="fas fa-history me-1"></i> View All Activities';
        $html .= '</a></li>';
    } else {
        $html .= '<li><div class="text-center py-4 text-muted">';
        $html .= '<i class="fas fa-bell-slash fa-2x mb-2"></i><br>';
        $html .= 'No new notifications';
        $html .= '</div></li>';
    }

    $html .= '</ul>';
    $html .= '</div>';

    return $html;
}
?>
