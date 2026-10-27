<?php
/**
 * includes/functions.php
 * Pharmacy Management System — Core Helper Functions
 *
 * STEP 2 FIXES APPLIED:
 * 1. if(!function_exists()) guards on ALL functions — prevents Fatal "Cannot redeclare" errors
 * 2. getDashboardStats() — real queries, proper SESSION caching, calls getDashboardStatsLive() if available
 * 3. Centralized _getPDO(), _pdoOne(), _pdoAll(), _pdoRun() helpers with guards (no more triple-declaration)
 * 4. insertData() / updateData() global helpers confirmed present
 * 5. generatePurchaseInvoiceNo() REMOVED from here — lives in config/config.php only
 * 6. All stub functions (getTotalStockValue, getExpiredProductsList, etc.) replaced with real queries
 * 7. getRecentSales / getRecentPurchases / getMonthlySalesData / getTopSellingProducts — all real
 * 8. Added getDashboardStatsLive() as authoritative live-stats function
 * 9. Added today_expenses to stats
 * 10. Added getActivitySummary(), getReturnStatsByDate() with guards
 */

// ==========================================================================
// CACHE KEYS
// ==========================================================================
if (!defined('CACHE_KEY_DASHBOARD')) {
    define('CACHE_KEY_DASHBOARD', 'dashboard_stats');
}

// ==========================================================================
// CENTRALIZED PDO HELPERS
// Declared here with guards so NO file needs to redeclare them.
// purchase_functions.php, return_functions.php, product_functions.php
// all use if(!function_exists()) so these win if loaded first.
// ==========================================================================

if (!function_exists('_getPDO')) {
    function _getPDO(): PDO {
        static $_pdo = null;
        if ($_pdo !== null) return $_pdo;

        // Try db() wrapper first
        if (function_exists('db')) {
            $wrapper = db();
            if ($wrapper instanceof PDO) {
                $_pdo = $wrapper;
                return $_pdo;
            }
            // Check common property names
            foreach (['pdo', 'connection', 'conn', 'dbh', '_pdo', '_conn'] as $prop) {
                if (isset($wrapper->$prop) && $wrapper->$prop instanceof PDO) {
                    $_pdo = $wrapper->$prop;
                    return $_pdo;
                }
            }
            // Try getConnection() method
            if (method_exists($wrapper, 'getConnection')) {
                $conn = $wrapper->getConnection();
                if ($conn instanceof PDO) {
                    $_pdo = $conn;
                    return $_pdo;
                }
            }
        }

        // Try globals
        foreach (['pdo', 'conn', 'db', 'connection'] as $name) {
            if (!empty($GLOBALS[$name]) && $GLOBALS[$name] instanceof PDO) {
                $_pdo = $GLOBALS[$name];
                return $_pdo;
            }
        }

        // Build from constants (defined in database.php / config.php)
        $host   = defined('DB_HOST')    ? DB_HOST    : 'localhost';
        $dbname = defined('DB_NAME')    ? DB_NAME    : '';
        $user   = defined('DB_USER')    ? DB_USER    : 'root';
        $pass   = defined('DB_PASS')    ? DB_PASS    : '';
        $dsn    = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";

        $_pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        return $_pdo;
    }
}

if (!function_exists('_pdoRun')) {
    function _pdoRun(string $sql, array $params = []): PDOStatement {
        $pdo  = _getPDO();
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $val) {
            if (is_null($val)) {
                $stmt->bindValue($key, null, PDO::PARAM_NULL);
            } elseif (is_int($val) || is_bool($val)) {
                $stmt->bindValue($key, (int)$val, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($key, $val, PDO::PARAM_STR);
            }
        }
        $stmt->execute();
        return $stmt;
    }
}

if (!function_exists('_pdoOne')) {
    function _pdoOne(string $sql, array $params = []): ?array {
        $row = _pdoRun($sql, $params)->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }
}

if (!function_exists('_pdoAll')) {
    function _pdoAll(string $sql, array $params = []): array {
        return _pdoRun($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ==========================================================================
// insertData() / updateData() — global wrappers (used by sync_engine etc.)
// ==========================================================================

if (!function_exists('insertData')) {
    function insertData(string $table, array $data): int|false {
        if (empty($data)) return false;
        $cols   = implode(', ', array_keys($data));
        $phs    = implode(', ', array_map(fn($k) => ":$k", array_keys($data)));
        $params = [];
        foreach ($data as $k => $v) {
            $params[":$k"] = $v;
        }
        try {
            _pdoRun("INSERT INTO `{$table}` ({$cols}) VALUES ({$phs})", $params);
            return (int)_getPDO()->lastInsertId() ?: false;
        } catch (\Throwable $e) {
            error_log("[insertData] {$table}: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('updateData')) {
    function updateData(string $table, array $data, string $where, array $whereParams = []): bool {
        if (empty($data)) return false;
        $sets   = implode(', ', array_map(fn($k) => "`{$k}` = :upd_{$k}", array_keys($data)));
        $params = $whereParams;
        foreach ($data as $k => $v) {
            $params[":upd_{$k}"] = $v;
        }
        try {
            _pdoRun("UPDATE `{$table}` SET {$sets} WHERE {$where}", $params);
            return true;
        } catch (\Throwable $e) {
            error_log("[updateData] {$table}: " . $e->getMessage());
            return false;
        }
    }
}

// ==========================================================================
// FORMAT CURRENCY
// ==========================================================================
if (!function_exists('formatCurrency')) {
    function formatCurrency($amount) {
        $symbol = defined('CURRENCY_SYMBOL') ? CURRENCY_SYMBOL : 'Rs';
        return $symbol . ' ' . number_format((float)$amount, 2);
    }
}

// ==========================================================================
// getDashboardStatsLive() — Always fresh from DB, no cache
// This is the AUTHORITATIVE stats function used by AJAX refresh endpoint
// ==========================================================================
if (!function_exists('getDashboardStatsLive')) {
    function getDashboardStatsLive(): array {
        $today        = date('Y-m-d');
        $lowThreshold = defined('LOW_STOCK_THRESHOLD') ? LOW_STOCK_THRESHOLD : 10;

        // ── Query 1: Sales — today + month + total + today count ──────────
        $salesRow = _pdoOne("
            SELECT
                COALESCE(SUM(CASE WHEN DATE(sale_date) = CURDATE() AND COALESCE(status,'active') != 'voided'
                    THEN net_amount END), 0) AS today_sale,
                COALESCE(SUM(CASE WHEN MONTH(sale_date) = MONTH(CURDATE())
                    AND YEAR(sale_date) = YEAR(CURDATE())
                    AND COALESCE(status,'active') != 'voided'
                    THEN net_amount END), 0) AS month_sale,
                COALESCE(SUM(CASE WHEN COALESCE(status,'active') != 'voided'
                    THEN net_amount END), 0) AS total_sale,
                COUNT(CASE WHEN DATE(sale_date) = CURDATE()
                    AND COALESCE(status,'active') != 'voided' THEN 1 END) AS today_sale_count,
                COALESCE(SUM(CASE WHEN DATE(sale_date) = CURDATE()
                    AND COALESCE(is_credit,0) = 1
                    AND COALESCE(status,'active') != 'voided'
                    THEN (total - paid_amount) END), 0) AS today_credit_given,
                COALESCE(SUM(CASE WHEN DATE(sale_date) = CURDATE()
                    AND COALESCE(status,'active') != 'voided'
                    THEN net_amount END), 0) AS today_sale_net
            FROM sales
        ") ?? [];

        $todaySale          = (float)($salesRow['today_sale']        ?? 0);
        $todayCreditGiven   = (float)($salesRow['today_credit_given'] ?? 0);

        // ── Query 2: Purchases + returns + expenses + cashbook + COGS ─────
        // [PERF-02] PHASE 27: Replaced 8-correlated-subquery FROM DUAL with
        // sp_dashboard_txn_summary — 6 indexed range scans, no DATE() wrapping.
        $pdo = db()->getConnection();
        $stmt27 = $pdo->prepare("CALL sp_dashboard_txn_summary(
            :d,
            @today_purchase, @today_sale_returns, @today_purchase_returns,
            @today_expenses, @cashbook_in, @cashbook_out,
            @today_cogs, @today_credit_collected
        )");
        $stmt27->execute([':d' => $today]);
        $stmt27->closeCursor();
        $txnRow = $pdo->query("SELECT
            @today_purchase AS today_purchase,
            @today_sale_returns AS today_sale_returns,
            @today_purchase_returns AS today_purchase_returns,
            @today_expenses AS today_expenses,
            @cashbook_in AS cashbook_in,
            @cashbook_out AS cashbook_out,
            @today_cogs AS today_cogs,
            @today_credit_collected AS today_credit_collected")->fetch(PDO::FETCH_ASSOC) ?? [];

        $todayPurchase        = (float)($txnRow['today_purchase']         ?? 0);
        $todaySaleReturns     = (float)($txnRow['today_sale_returns']     ?? 0);
        $todayPurchaseReturns = (float)($txnRow['today_purchase_returns'] ?? 0);
        $todayExpenses        = (float)($txnRow['today_expenses']         ?? 0);
        $cashbookIn           = (float)($txnRow['cashbook_in']            ?? 0);
        $cashbookOut          = (float)($txnRow['cashbook_out']           ?? 0);
        $todayCOGS            = (float)($txnRow['today_cogs']             ?? 0);
        $todayCreditCollected = (float)($txnRow['today_credit_collected'] ?? 0);
        $todayProfit    = round($todaySale - $todayCOGS, 2);
        $todayNetProfit = round($todayProfit - $todayExpenses, 2);

        // ── Query 3: Products + batches stock summary ─────────────────────
        $stockRow = _pdoOne("
            SELECT
                COUNT(DISTINCT CASE WHEN b.remaining_quantity > 0
                    AND COALESCE(b.is_expired, 0) = 0 THEN b.product_id END)    AS total_products,
                COALESCE(SUM(CASE WHEN COALESCE(b.is_expired,0) = 0
                    THEN b.remaining_quantity * b.purchase_price END), 0)        AS total_stock_value,
                COALESCE(SUM(CASE WHEN COALESCE(b.is_expired,0) = 0
                    THEN b.remaining_quantity * b.selling_price END), 0)         AS total_retail_value,
                COUNT(CASE WHEN b.expiry_date <= CURDATE()
                    AND b.remaining_quantity > 0 THEN 1 END)                     AS expired_batches,
                COUNT(CASE WHEN b.expiry_date > CURDATE()
                    AND b.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                    AND b.remaining_quantity > 0 THEN 1 END)                     AS near_expiry_count,
                COUNT(CASE WHEN b.remaining_quantity > 0
                    AND b.remaining_quantity <= :low
                    AND COALESCE(b.is_expired, 0) = 0 THEN 1 END)               AS low_stock_count
            FROM batches b
        ", [':low' => $lowThreshold]) ?? [];

        $totalProducts   = (int)($stockRow['total_products']    ?? 0);
        $totalStockValue = (float)($stockRow['total_stock_value'] ?? 0);
        $totalRetailValue= (float)($stockRow['total_retail_value']?? 0);
        $expiredCount    = (int)($stockRow['expired_batches']   ?? 0);
        $nearExpiryCount = (int)($stockRow['near_expiry_count'] ?? 0);
        $lowStockCount   = (int)($stockRow['low_stock_count']   ?? 0);

        // ── Query 4: Credit summary ─────────────────────────────────────────
        // [PERF-03] PHASE 27: Pre-aggregated JOIN replaces correlated subquery per row.
        $creditRow = _pdoOne(
            "SELECT COALESCE(SUM(cc.total_amount) - COALESCE(SUM(cp_agg.paid), 0), 0) AS total_credit_pending
             FROM customer_credits cc
             LEFT JOIN (
                 SELECT credit_id, SUM(amount) AS paid
                 FROM   credit_payments
                 GROUP  BY credit_id
             ) cp_agg ON cp_agg.credit_id = cc.id
             WHERE cc.status IN ('active', 'overdue')"
        ) ?? [];

        $totalCreditPending = (float)($creditRow['total_credit_pending'] ?? 0);

        // ── Query 5: Total medicines (parent products only) ───────────────
        $totalMedicines = (int)(_pdoOne(
            "SELECT COUNT(*) AS total FROM products
             WHERE status = 1 AND COALESCE(parent_id, 0) = 0"
        )['total'] ?? 0);

        // ── Assemble return — all keys preserved exactly ──────────────────
        return [
            'total_medicines'          => $totalMedicines,
            'total_products'           => $totalProducts,
            'today_sale'               => $todaySale,
            'today_purchase'           => $todayPurchase,
            'today_purchase_total'     => $todayPurchase,
            'today_profit'             => $todayProfit,
            'today_net_profit'         => $todayNetProfit,
            'today_expenses'           => $todayExpenses,
            'today_credit_given'       => $todayCreditGiven,
            'today_credit_collected'   => $todayCreditCollected,
            'total_credit_pending'     => $totalCreditPending,
            'today_sale_returns'       => $todaySaleReturns,
            'today_purchase_returns'   => $todayPurchaseReturns,
            'cashbook_today_in'        => $cashbookIn,
            'cashbook_today_out'       => $cashbookOut,
            'net_cash_today'           => round($cashbookIn - $cashbookOut, 2),
            'total_stock_value'        => $totalStockValue,
            'total_retail_value'       => $totalRetailValue,
            'low_stock_count'          => $lowStockCount,
            'low_stock_alert'          => $lowStockCount,
            'near_expiry_count'        => $nearExpiryCount,
            'expired_medicines'        => $expiredCount,
            'short_stock'              => $expiredCount,  // alias
            'generated_at'             => date('Y-m-d H:i:s'),
        ];
    }
}

// ==========================================================================
// getDashboardStats() — SESSION-cached wrapper around getDashboardStatsLive()
// Pages call this; AJAX refresh endpoint calls getDashboardStatsLive() directly
// ==========================================================================
if (!function_exists('getDashboardStats')) {
    function getDashboardStats(bool $forceRefresh = false): array {
        $cacheKey = CACHE_KEY_DASHBOARD;
        $ttl      = defined('CACHE_TTL') ? (int)CACHE_TTL : 60;

        if (
            !$forceRefresh &&
            isset($_SESSION[$cacheKey], $_SESSION[$cacheKey . '_exp']) &&
            time() < $_SESSION[$cacheKey . '_exp']
        ) {
            return $_SESSION[$cacheKey];
        }

        $stats = getDashboardStatsLive();

        $_SESSION[$cacheKey]         = $stats;
        $_SESSION[$cacheKey . '_exp'] = time() + $ttl;

        return $stats;
    }
}

// ==========================================================================
// NEAR EXPIRY PRODUCTS
// ==========================================================================
if (!function_exists('getNearExpiryProducts')) {
    function getNearExpiryProducts(int $limit = 10): array {
        $rows = _pdoAll(
            "SELECT
                b.id AS batch_id,
                b.batch_number,
                p.id AS product_id,
                p.product_name,
                p.generic_name,
                p.brand_name,
                p.strength,
                p.dosage_form,
                b.expiry_date,
                b.remaining_quantity,
                DATEDIFF(b.expiry_date, CURDATE()) AS days_left
             FROM batches b
             JOIN products p ON b.product_id = p.id
             WHERE b.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
               AND b.remaining_quantity > 0
               AND b.is_expired = 0
             ORDER BY b.expiry_date ASC
             LIMIT :lim",
            [':lim' => $limit]
        );
        return $rows;
    }
}

// ==========================================================================
// LOW / SHORT STOCK PRODUCTS
// ==========================================================================
if (!function_exists('getNearShortStockProducts')) {
    function getNearShortStockProducts(int $limit = 10): array {
        $threshold = defined('LOW_STOCK_THRESHOLD') ? (int)LOW_STOCK_THRESHOLD : 10;
        return _pdoAll(
            "SELECT
                p.id AS product_id,
                p.product_name,
                p.generic_name,
                p.brand_name,
                p.strength,
                p.dosage_form,
                COALESCE(MIN(b.reorder_level), :threshold) AS reorder_level,
                COALESCE(SUM(b.remaining_quantity), 0) AS total_stock,
                COUNT(DISTINCT b.id) AS batch_count,
                MIN(b.expiry_date) AS nearest_expiry
             FROM products p
             LEFT JOIN batches b ON b.product_id = p.id
               AND b.remaining_quantity > 0
               AND (b.expiry_date IS NULL OR b.expiry_date > CURDATE())
               AND b.is_expired = 0
             WHERE p.status = 1
             GROUP BY p.id
             HAVING total_stock <= :threshold2 AND total_stock > 0
             ORDER BY total_stock ASC
             LIMIT :lim",
            [':threshold' => $threshold, ':threshold2' => $threshold, ':lim' => $limit]
        );
    }
}

// ==========================================================================
// RECENT SALES
// ==========================================================================
if (!function_exists('getRecentSales')) {
    function getRecentSales(int $limit = 10): array {
        return _pdoAll(
            "SELECT
                s.id,
                s.invoice_no,
                s.customer_name,
                s.sale_date,
                s.net_amount AS total,
                s.total AS grand_total,
                s.payment_method,
                s.payment_status,
                COUNT(si.id) AS items_count
             FROM sales s
             LEFT JOIN sale_items si ON s.id = si.sale_id
             WHERE COALESCE(s.status,'active') != 'voided'
             GROUP BY s.id
             ORDER BY s.sale_date DESC, s.id DESC
             LIMIT :lim",
            [':lim' => $limit]
        );
    }
}

// ==========================================================================
// RECENT PURCHASES
// ==========================================================================
if (!function_exists('getRecentPurchases')) {
    function getRecentPurchases(int $limit = 10): array {
        return _pdoAll(
            "SELECT
                p.id,
                p.purchase_invoice_no,
                s.supplier_name,
                p.purchase_date,
                p.total,
                p.payment_status,
                COUNT(pi.id) AS items_count
             FROM purchases p
             JOIN suppliers s ON p.supplier_id = s.id
             LEFT JOIN purchase_items pi ON p.id = pi.purchase_id
             GROUP BY p.id
             ORDER BY p.purchase_date DESC, p.id DESC
             LIMIT :lim",
            [':lim' => $limit]
        );
    }
}

// ==========================================================================
// MONTHLY SALES DATA (last 6 months, for chart)
// ==========================================================================
if (!function_exists('getMonthlySalesData')) {
    function getMonthlySalesData(): array {
        return _pdoAll(
            "SELECT
                DATE_FORMAT(sale_date, '%Y-%m') AS month,
                DATE_FORMAT(sale_date, '%b %Y') AS month_label,
                COUNT(*) AS invoice_count,
                COALESCE(SUM(net_amount), 0) AS total_amount
             FROM sales
             WHERE sale_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
               AND COALESCE(status,'active') != 'voided'
             GROUP BY DATE_FORMAT(sale_date, '%Y-%m')
             ORDER BY month ASC"
        );
    }
}

// ==========================================================================
// TOP SELLING PRODUCTS (last 30 days)
// ==========================================================================
if (!function_exists('getTopSellingProducts')) {
    function getTopSellingProducts(int $limit = 5): array {
        return _pdoAll(
            "SELECT
                p.id AS product_id,
                p.product_name,
                p.generic_name,
                p.brand_name,
                COALESCE(SUM(si.quantity), 0) AS total_quantity_sold,
                COALESCE(SUM(si.total), 0) AS total_amount
             FROM sale_items si
             JOIN products p ON si.product_id = p.id
             JOIN sales s ON si.sale_id = s.id
             WHERE s.sale_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
               AND COALESCE(s.status,'active') != 'voided'
             GROUP BY p.id
             ORDER BY total_quantity_sold DESC
             LIMIT :lim",
            [':lim' => $limit]
        );
    }
}

// ==========================================================================
// RECENT ACTIVITIES
// ==========================================================================
if (!function_exists('getRecentActivities')) {
    function getRecentActivities(int $limit = 10): array {
        return _pdoAll(
            "SELECT
                al.*,
                u.username,
                u.full_name
             FROM activity_logs al
             LEFT JOIN users u ON al.user_id = u.id
             ORDER BY al.created_at DESC
             LIMIT :lim",
            [':lim' => $limit]
        );
    }
}

// ==========================================================================
// TOTAL STOCK VALUE (cost basis)
// ==========================================================================
if (!function_exists('getTotalStockValue')) {
    function getTotalStockValue(): float {
        $row = _pdoOne(
            "SELECT COALESCE(SUM(remaining_quantity * purchase_price), 0) AS total_value
             FROM batches WHERE remaining_quantity > 0 AND is_expired = 0"
        );
        return (float)($row['total_value'] ?? 0);
    }
}

// ==========================================================================
// EXPIRED PRODUCTS LIST
// ==========================================================================
if (!function_exists('getExpiredProductsList')) {
    function getExpiredProductsList(int $limit = 10): array {
        return _pdoAll(
            "SELECT
                b.id AS batch_id,
                b.batch_number,
                p.product_name,
                p.generic_name,
                b.expiry_date,
                b.remaining_quantity,
                DATEDIFF(CURDATE(), b.expiry_date) AS days_expired
             FROM batches b
             JOIN products p ON b.product_id = p.id
             WHERE b.expiry_date < CURDATE() AND b.remaining_quantity > 0
             ORDER BY b.expiry_date ASC
             LIMIT :lim",
            [':lim' => $limit]
        );
    }
}

// ==========================================================================
// TODAY'S SALES SUMMARY
// ==========================================================================
if (!function_exists('getTodaySalesSummary')) {
    function getTodaySalesSummary(): array {
        $today = date('Y-m-d');

        $sales = _pdoOne(
            "SELECT
                COUNT(*) AS total_invoices,
                COALESCE(SUM(net_amount), 0) AS total_amount,
                COALESCE(SUM(CASE WHEN payment_method='cash'   THEN net_amount ELSE 0 END), 0) AS cash_amount,
                COALESCE(SUM(CASE WHEN payment_method='card'   THEN net_amount ELSE 0 END), 0) AS card_amount,
                COALESCE(SUM(CASE WHEN payment_method='online' THEN net_amount ELSE 0 END), 0) AS online_amount,
                COALESCE(SUM(CASE WHEN payment_method='credit' AND COALESCE(is_credit,0)=1 THEN total - paid_amount ELSE 0 END), 0) AS credit_amount,
                COUNT(CASE WHEN COALESCE(is_credit,0)=1 THEN 1 END) AS credit_sale_count
             FROM sales
             WHERE DATE(sale_date) = :today AND COALESCE(status,'active') != 'voided'",
            [':today' => $today]
        );

        $returns = _pdoOne(
            "SELECT COALESCE(SUM(total_amount), 0) AS total_returns_today
             FROM sale_returns WHERE DATE(created_at) = :today",
            [':today' => $today]
        );

        $creditCollected = _pdoOne(
            "SELECT COALESCE(SUM(amount), 0) AS credit_collected_today
             FROM credit_payments WHERE DATE(payment_date) = :today",
            [':today' => $today]
        );

        return [
            'total_invoices'         => (int)($sales['total_invoices']      ?? 0),
            'total_amount'           => (float)($sales['total_amount']       ?? 0),
            'cash_amount'            => (float)($sales['cash_amount']        ?? 0),
            'card_amount'            => (float)($sales['card_amount']        ?? 0),
            'online_amount'          => (float)($sales['online_amount']      ?? 0),
            'credit_amount'          => (float)($sales['credit_amount']      ?? 0),
            'credit_sale_count'      => (int)($sales['credit_sale_count']    ?? 0),
            'total_returns_today'    => (float)($returns['total_returns_today'] ?? 0),
            'credit_collected_today' => (float)($creditCollected['credit_collected_today'] ?? 0),
        ];
    }
}

// ==========================================================================
// SUPPLIER PAYMENT SUMMARY
// ==========================================================================
if (!function_exists('getSupplierPaymentSummary')) {
    function getSupplierPaymentSummary(): array {
        $row = _pdoOne(
            "SELECT COUNT(*) AS total_suppliers, COALESCE(SUM(current_balance), 0) AS total_due
             FROM suppliers WHERE current_balance > 0"
        );
        return [
            'total_suppliers' => (int)($row['total_suppliers'] ?? 0),
            'total_due'       => (float)($row['total_due']     ?? 0),
        ];
    }
}

// ==========================================================================
// ACTIVITY SUMMARY FOR A DATE
// ==========================================================================
if (!function_exists('getActivitySummary')) {
    function getActivitySummary(string $date): array {
        $d = date('Y-m-d', strtotime($date));

        $sales = _pdoOne(
            "SELECT COUNT(*) AS count, COALESCE(SUM(net_amount),0) AS total FROM sales
             WHERE DATE(sale_date)=:d AND COALESCE(status,'active')!='voided'",
            [':d' => $d]
        );
        $purchases = _pdoOne(
            "SELECT COUNT(*) AS count, COALESCE(SUM(total),0) AS total FROM purchases
             WHERE DATE(purchase_date)=:d",
            [':d' => $d]
        );
        $saleReturns = _pdoOne(
            "SELECT COUNT(*) AS count, COALESCE(SUM(total_amount),0) AS total FROM sale_returns
             WHERE DATE(created_at)=:d",
            [':d' => $d]
        );
        $purchaseReturns = _pdoOne(
            "SELECT COUNT(*) AS count, COALESCE(SUM(total_amount),0) AS total FROM purchase_returns
             WHERE DATE(created_at)=:d",
            [':d' => $d]
        );
        $creditsGiven = _pdoOne(
            "SELECT COUNT(*) AS count, COALESCE(SUM(total - paid_amount),0) AS total
             FROM sales WHERE DATE(sale_date)=:d AND COALESCE(is_credit,0)=1
             AND COALESCE(status,'active')!='voided'",
            [':d' => $d]
        );
        $expenses = _pdoOne(
            "SELECT COUNT(*) AS count, COALESCE(SUM(amount),0) AS total FROM expenses
             WHERE DATE(expense_date)=:d",
            [':d' => $d]
        );

        return [
            'sales'            => ['count' => (int)($sales['count'] ?? 0),           'total' => (float)($sales['total'] ?? 0)],
            'purchases'        => ['count' => (int)($purchases['count'] ?? 0),        'total' => (float)($purchases['total'] ?? 0)],
            'sale_returns'     => ['count' => (int)($saleReturns['count'] ?? 0),      'total' => (float)($saleReturns['total'] ?? 0)],
            'purchase_returns' => ['count' => (int)($purchaseReturns['count'] ?? 0),  'total' => (float)($purchaseReturns['total'] ?? 0)],
            'credits_given'    => ['count' => (int)($creditsGiven['count'] ?? 0),     'total' => (float)($creditsGiven['total'] ?? 0)],
            'expenses'         => ['count' => (int)($expenses['count'] ?? 0),         'total' => (float)($expenses['total'] ?? 0)],
        ];
    }
}

// ==========================================================================
// RETURN STATS BY DATE
// ==========================================================================
if (!function_exists('getReturnStatsByDate')) {
    function getReturnStatsByDate(string $date): array {
        $d = date('Y-m-d', strtotime($date));

        $saleStats = _pdoOne(
            "SELECT COALESCE(SUM(total_amount),0) AS total_amount, COUNT(*) AS count
             FROM sale_returns WHERE DATE(created_at)=:d",
            [':d' => $d]
        );
        $purchaseStats = _pdoOne(
            "SELECT COALESCE(SUM(total_amount),0) AS total_amount, COUNT(*) AS count
             FROM purchase_returns WHERE DATE(created_at)=:d",
            [':d' => $d]
        );

        return [
            'sale_returns'     => ['total_amount' => (float)($saleStats['total_amount']    ?? 0), 'count' => (int)($saleStats['count']    ?? 0)],
            'purchase_returns' => ['total_amount' => (float)($purchaseStats['total_amount'] ?? 0), 'count' => (int)($purchaseStats['count'] ?? 0)],
            'combined_total'   => (float)($saleStats['total_amount'] ?? 0) + (float)($purchaseStats['total_amount'] ?? 0),
        ];
    }
}