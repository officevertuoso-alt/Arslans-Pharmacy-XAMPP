<?php
/**
 * includes/return_functions.php
 * Returns Management Functions – FULLY SYNCHRONIZED (STEP 2 + STEP 3)
 *
 * FIXES APPLIED:
 * 1. getPurchaseForReturn() — removed p.status (column doesn't exist).
 *    Uses _pdoOne/_pdoAll (raw PDO) to bypass db() wrapper.
 * 2. getAllPurchaseReturns() — flexible signature (page as int OR filters array).
 * 3. getPurchaseReturnDetails() — safe JOIN via purchase_items, no direct product_id on purchase_returns.
 * 4. getSaleReturnSummary() / getPurchaseReturnSummary() — use _pdoOne (raw PDO).
 * 5. getReturnableItems() — both sale and purchase paths use raw PDO.
 * 6. All db() calls inside sale functions kept (sales table has status column).
 * 7. processPurchaseReturn() / processSaleReturn() — deprecated but kept safe.
 * 8. [FIX] getAllSaleReturns() — added s.customer_phone to SELECT so customer links work.
 * 9. [NEW] Added refreshProductStockAfterReturn() – updates products.stock_quantity after return.
 *10. [NEW] Modified deprecated processSaleReturn() / processPurchaseReturn() to refresh product stock.
 */

require_once __DIR__ . '/../config/database.php';

// Safe include for optional helpers
if (file_exists(__DIR__ . '/sync_engine.php')) {
    require_once __DIR__ . '/sync_engine.php';
}
if (file_exists(__DIR__ . '/cashbook_functions.php')) {
    require_once __DIR__ . '/cashbook_functions.php';
}
// Include purchase_functions for updateProductStockAndPrice (if available)
if (file_exists(__DIR__ . '/purchase_functions.php')) {
    require_once __DIR__ . '/purchase_functions.php';
}

// ─────────────────────────────────────────────────────────
// RAW PDO HELPERS
// ─────────────────────────────────────────────────────────
if (!function_exists('_getPDO')) {
    function _getPDO(): PDO {
        static $pdo = null;
        if ($pdo === null) {
            if (function_exists('db')) {
                $dbObj = db();
                if (is_object($dbObj) && property_exists($dbObj, 'pdo')) {
                    $pdo = $dbObj->pdo;
                    return $pdo;
                }
                if (is_object($dbObj) && method_exists($dbObj, 'getPDO')) {
                    $pdo = $dbObj->getPDO();
                    return $pdo;
                }
            }
            $host   = defined('DB_HOST') ? DB_HOST : 'localhost';
            $dbname = defined('DB_NAME') ? DB_NAME : '';
            $user   = defined('DB_USER') ? DB_USER : 'root';
            $pass   = defined('DB_PASS') ? DB_PASS : '';
            $dsn    = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return $pdo;
    }
}

if (!function_exists('_pdoOne')) {
    function _pdoOne(string $sql, array $params = []): ?array {
        $stmt = _getPDO()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }
}

if (!function_exists('_pdoAll')) {
    function _pdoAll(string $sql, array $params = []): array {
        $stmt = _getPDO()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('_pdoRun')) {
    function _pdoRun(string $sql, array $params = []): int {
        $stmt = _getPDO()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }
}

// ─────────────────────────────────────────────────────────
// STOCK REFRESH HELPER
// ─────────────────────────────────────────────────────────
if (!function_exists('refreshProductStockAfterReturn')) {
    /**
     * Refresh products.stock_quantity for given product IDs.
     * Uses updateProductStockAndPrice() from purchase_functions if available,
     * otherwise falls back to direct SQL.
     *
     * @param array $productIds Array of product IDs
     * @return void
     */
    function refreshProductStockAfterReturn(array $productIds): void {
        if (empty($productIds)) return;
        $productIds = array_unique(array_map('intval', $productIds));
        if (function_exists('updateProductStockAndPrice')) {
            foreach ($productIds as $pid) {
                updateProductStockAndPrice($pid);
            }
        } else {
            // Fallback: direct update (sum of non-expired batch quantities)
            $pdo = _getPDO();
            foreach ($productIds as $pid) {
                $pdo->prepare("
                    UPDATE products p
                    SET p.stock_quantity = (
                        SELECT COALESCE(SUM(b.remaining_quantity), 0)
                        FROM batches b
                        WHERE b.product_id = p.id
                          AND b.is_expired = 0
                          AND b.remaining_quantity > 0
                    )
                    WHERE p.id = :pid
                ")->execute([':pid' => $pid]);
            }
        }
    }
}

// ─────────────────────────────────────────────────────────
// 1. GET SALE FOR RETURN
// ─────────────────────────────────────────────────────────
function getSaleForReturn($identifier, $isId = true) {
    $db = db();

    if ($isId) {
        $sale = $db->getOne(
            "SELECT s.*, c.id as credit_id, c.total_amount as credit_total, c.status as credit_status
             FROM sales s
             LEFT JOIN customer_credits c ON c.sale_id = s.id
             WHERE s.id = :id AND s.payment_status IN ('paid', 'partial')
               AND s.status != 'voided'",
            [':id' => $identifier]
        );
    } else {
        $sale = $db->getOne(
            "SELECT s.*, c.id as credit_id, c.total_amount as credit_total, c.status as credit_status
             FROM sales s
             LEFT JOIN customer_credits c ON c.sale_id = s.id
             WHERE s.invoice_no = :inv AND s.payment_status IN ('paid', 'partial')
               AND s.status != 'voided'",
            [':inv' => $identifier]
        );
    }

    if (!$sale) return null;

    $items = $db->getAll(
        "SELECT
            si.id as sale_item_id,
            si.product_id,
            si.variant_id,
            si.batch_id,
            si.quantity as sold_quantity,
            si.unit_price,
            si.total as line_total,
            b.batch_number,
            b.expiry_date,
            b.remaining_quantity as batch_stock,
            p.product_name,
            p.generic_name,
            p.brand_name,
            v.variant_value,
            COALESCE((
                SELECT SUM(sr.quantity)
                FROM sale_returns sr
                WHERE sr.sale_item_id = si.id
            ), 0) as already_returned
        FROM sale_items si
        JOIN batches b ON si.batch_id = b.id
        JOIN products p ON si.product_id = p.id
        LEFT JOIN product_variants v ON si.variant_id = v.id
        WHERE si.sale_id = :sale_id
        ORDER BY si.id",
        [':sale_id' => $sale['id']]
    );

    foreach ($items as &$item) {
        $item['returnable_qty'] = max(0, (int)$item['sold_quantity'] - (int)$item['already_returned']);
        $item['max_returnable'] = $item['returnable_qty'];
        $item['line_total']     = (float)$item['line_total'];
        $item['unit_price']     = (float)$item['unit_price'];
    }
    unset($item);

    $sale['items']       = $items;
    $sale['grand_total'] = (float)($sale['grand_total'] ?? $sale['total'] ?? 0);
    $sale['paid_amount'] = (float)($sale['paid_amount'] ?? 0);
    $sale['net_amount']  = (float)($sale['net_amount']  ?? 0);

    return $sale;
}

// ─────────────────────────────────────────────────────────
// 2. GET PURCHASE FOR RETURN
// ─────────────────────────────────────────────────────────
function getPurchaseForReturn($identifier, $isId = true) {
    if ($isId) {
        $purchase = _pdoOne(
            "SELECT p.*, s.supplier_name,
                    COALESCE(s.current_balance, 0) AS current_balance,
                    COALESCE(p.due_amount, 0) AS supplier_due
             FROM purchases p
             JOIN suppliers s ON p.supplier_id = s.id
             WHERE p.id = :id",
            [':id' => (int)$identifier]
        );
    } else {
        $purchase = _pdoOne(
            "SELECT p.*, s.supplier_name,
                    COALESCE(s.current_balance, 0) AS current_balance,
                    COALESCE(p.due_amount, 0) AS supplier_due
             FROM purchases p
             JOIN suppliers s ON p.supplier_id = s.id
             WHERE p.purchase_invoice_no = :inv",
            [':inv' => $identifier]
        );
    }

    if (!$purchase) return null;

    $items = _pdoAll(
        "SELECT
            pi.id as purchase_item_id,
            pi.product_id,
            pi.batch_id,
            pi.quantity as purchased_quantity,
            pi.unit_price,
            pi.total as line_total,
            b.batch_number,
            b.expiry_date,
            b.remaining_quantity as batch_stock,
            p.product_name,
            p.generic_name,
            p.brand_name,
            COALESCE(p.units_per_pack, 1) as units_per_pack,
            COALESCE((
                SELECT SUM(pr.quantity)
                FROM purchase_returns pr
                WHERE pr.purchase_item_id = pi.id
            ), 0) as already_returned,
            COALESCE((
                SELECT SUM(si.quantity)
                FROM sale_items si
                WHERE si.batch_id = pi.batch_id
            ), 0) as pieces_sold_from_batch
        FROM purchase_items pi
        JOIN batches b ON pi.batch_id = b.id
        JOIN products p ON pi.product_id = p.id
        WHERE pi.purchase_id = :purchase_id
        ORDER BY pi.id",
        [':purchase_id' => $purchase['id']]
    );

    foreach ($items as &$item) {
        $unitsPerPack     = max(1, (int)($item['units_per_pack'] ?? 1));
        $purchasedPacks   = (int)$item['purchased_quantity'];
        $purchasedPieces  = $purchasedPacks * $unitsPerPack;
        $alreadyRetPieces = (int)$item['already_returned'];
        $piecesSold       = (int)($item['pieces_sold_from_batch'] ?? 0);
        $batchStockPacks  = (int)$item['batch_stock'];
        $batchStockPieces = $batchStockPacks * $unitsPerPack;
        $returnablePieces = max(0, $batchStockPieces);

        $item['units_per_pack']          = $unitsPerPack;
        $item['purchased_quantity']      = $purchasedPacks;
        $item['purchased_pieces']        = $purchasedPieces;
        $item['already_returned']        = $alreadyRetPieces;
        $item['already_returned_pieces'] = $alreadyRetPieces;
        $item['pieces_sold_from_batch']  = $piecesSold;
        $item['batch_stock_packs']       = $batchStockPacks;
        $item['batch_stock_pieces']      = $batchStockPieces;
        $item['returnable_pieces']       = $returnablePieces;
        $item['returnable_qty']          = $returnablePieces;
        $item['max_returnable']          = $returnablePieces;
        $item['line_total']              = (float)$item['line_total'];
        $item['unit_price']              = (float)$item['unit_price'];
        if (!array_key_exists('variant_id', $item)) $item['variant_id'] = null;
    }
    unset($item);

    $purchase['items']       = $items;
    $purchase['total']       = (float)$purchase['total'];
    $purchase['paid_amount'] = (float)$purchase['paid_amount'];
    $purchase['due_amount']  = (float)($purchase['due_amount'] ?? 0);

    return $purchase;
}

// ─────────────────────────────────────────────────────────
// 3. RETURNABLE ITEMS HELPER
// ─────────────────────────────────────────────────────────
function getReturnableItems($parentId, $type = 'sale') {
    if ($type === 'sale') {
        $items = _pdoAll(
            "SELECT
                si.id as item_id,
                si.product_id,
                si.batch_id,
                si.quantity as sold_qty,
                si.unit_price,
                p.product_name,
                b.batch_number,
                COALESCE(SUM(sr.quantity), 0) as returned_qty
            FROM sale_items si
            JOIN products p ON si.product_id = p.id
            JOIN batches b ON si.batch_id = b.id
            LEFT JOIN sale_returns sr ON sr.sale_item_id = si.id
            WHERE si.sale_id = :pid
            GROUP BY si.id, si.product_id, si.batch_id, si.quantity, si.unit_price,
                     p.product_name, b.batch_number",
            [':pid' => $parentId]
        );
        foreach ($items as &$item) {
            $item['qty_returnable'] = (int)$item['sold_qty'] - (int)$item['returned_qty'];
        }
    } else {
        $items = _pdoAll(
            "SELECT
                pi.id as item_id,
                pi.product_id,
                pi.batch_id,
                pi.quantity as purchased_qty,
                pi.unit_price,
                p.product_name,
                b.batch_number,
                b.remaining_quantity as batch_stock_pieces,
                COALESCE(p.units_per_pack, 1) as units_per_pack,
                COALESCE(SUM(pr.quantity), 0) as returned_qty,
                COALESCE((
                    SELECT SUM(si.quantity)
                    FROM sale_items si
                    WHERE si.batch_id = pi.batch_id
                ), 0) as pieces_sold
            FROM purchase_items pi
            JOIN products p ON pi.product_id = p.id
            JOIN batches b ON pi.batch_id = b.id
            LEFT JOIN purchase_returns pr ON pr.purchase_item_id = pi.id
            WHERE pi.purchase_id = :pid
            GROUP BY pi.id, pi.product_id, pi.batch_id, pi.quantity, pi.unit_price,
                     p.product_name, b.batch_number, b.remaining_quantity",
            [':pid' => $parentId]
        );
        foreach ($items as &$item) {
            $unitsPerPack  = max(1, (int)$item['units_per_pack']);
            $batchStockPcs = (int)$item['batch_stock_pieces'] * $unitsPerPack;
            $item['qty_returnable'] = max(0, $batchStockPcs);
        }
    }
    unset($item);

    return array_values(array_filter($items, fn($i) => $i['qty_returnable'] > 0));
}

// ─────────────────────────────────────────────────────────
// 4. SALE RETURN SUMMARY
// ─────────────────────────────────────────────────────────
function getSaleReturnSummary($sale_id) {
    $result = _pdoOne(
        "SELECT
            COALESCE(SUM(total_amount), 0) as total_returned,
            COUNT(DISTINCT id) as return_count
         FROM sale_returns
         WHERE sale_id = :sid",
        [':sid' => $sale_id]
    );
    $totalReturned = (float)($result['total_returned'] ?? 0);
    $returnCount   = (int)($result['return_count']   ?? 0);
    $sale          = _pdoOne("SELECT total FROM sales WHERE id = :sid", [':sid' => $sale_id]);
    $grandTotal    = $sale ? (float)($sale['total'] ?? 0) : 0;

    return [
        'total_returned_amount' => $totalReturned,
        'return_count'          => $returnCount,
        'net_amount'            => max(0, $grandTotal - $totalReturned),
    ];
}

// ─────────────────────────────────────────────────────────
// 5. PURCHASE RETURN SUMMARY
// ─────────────────────────────────────────────────────────
function getPurchaseReturnSummary($purchase_id) {
    $result = _pdoOne(
        "SELECT
            COALESCE(SUM(total_amount), 0) as total_returned,
            COUNT(DISTINCT id) as return_count
         FROM purchase_returns
         WHERE purchase_id = :pid",
        [':pid' => $purchase_id]
    );
    $totalReturned = (float)($result['total_returned'] ?? 0);
    $returnCount   = (int)($result['return_count']   ?? 0);
    $purchase      = _pdoOne("SELECT total FROM purchases WHERE id = :pid", [':pid' => $purchase_id]);
    $originalTotal = $purchase ? (float)$purchase['total'] : 0;

    return [
        'total_returned_amount' => $totalReturned,
        'return_count'          => $returnCount,
        'net_amount'            => max(0, $originalTotal - $totalReturned),
    ];
}

// ─────────────────────────────────────────────────────────
// 6. SALE RETURN DETAILS
// ─────────────────────────────────────────────────────────
function getSaleReturnDetails($return_id) {
    $return = _pdoOne(
        "SELECT sr.*,
                s.invoice_no, s.customer_name, s.customer_phone, s.sale_date,
                u.full_name as returned_by_name
         FROM sale_returns sr
         JOIN sales s       ON sr.sale_id     = s.id
         LEFT JOIN users u  ON sr.returned_by = u.id
         WHERE sr.id = :rid",
        [':rid' => $return_id]
    );
    if (!$return) return null;

    $items = _pdoAll(
        "SELECT sr.id, sr.quantity, sr.unit_price, sr.total_amount,
                COALESCE(p.product_name, 'Unknown Product') AS product_name,
                COALESCE(p.generic_name, '')               AS generic_name,
                b.batch_number
         FROM sale_returns sr
         LEFT JOIN batches b     ON b.id  = sr.batch_id
         LEFT JOIN sale_items si ON si.id = sr.sale_item_id
         LEFT JOIN products p   ON p.id  = COALESCE(si.product_id, b.product_id)
         WHERE sr.return_no = :rno
         ORDER BY sr.id",
        [':rno' => $return['return_no']]
    );
    $return['items'] = $items ?: [];

    if (!empty($return['items'])) {
        $return['total_amount'] = array_sum(array_column($return['items'], 'total_amount'));
    }

    $cashbook = _pdoOne(
        "SELECT id, debit, credit, payment_method
         FROM cashbook
         WHERE reference_id = :rid AND reference_type = 'sale_return'
         LIMIT 1",
        [':rid' => $return_id]
    );

    if (!$cashbook && !empty($return['return_no'])) {
        $cashbook = _pdoOne(
            "SELECT id, debit, credit, payment_method
             FROM cashbook
             WHERE description LIKE :rno
             LIMIT 1",
            [':rno' => '%' . $return['return_no'] . '%']
        );
    }

    $return['cashbook_entry_exists']   = ($cashbook !== null);
    $return['cashbook_amount']         = $cashbook ? (float)($cashbook['debit'] ?? 0) : 0;
    $return['cashbook_payment_method'] = $cashbook['payment_method'] ?? null;

    return $return;
}

// ─────────────────────────────────────────────────────────
// 7. PURCHASE RETURN DETAILS
// ─────────────────────────────────────────────────────────
function getPurchaseReturnDetails($return_id) {
    $return = _pdoOne(
        "SELECT pr.*,
                p.purchase_invoice_no, p.supplier_id,
                s.supplier_name,
                u.full_name as returned_by_name,
                prod.product_name, prod.generic_name,
                b.batch_number
         FROM purchase_returns pr
         JOIN purchases p   ON pr.purchase_id       = p.id
         JOIN suppliers s   ON p.supplier_id         = s.id
         JOIN purchase_items pi ON pr.purchase_item_id = pi.id
         JOIN products prod ON pi.product_id          = prod.id
         LEFT JOIN batches b ON pr.batch_id            = b.id
         LEFT JOIN users u  ON pr.returned_by          = u.id
         WHERE pr.id = :rid",
        [':rid' => $return_id]
    );
    if (!$return) return null;

    $cashbook = _pdoOne(
        "SELECT id, entry_type, debit, credit, payment_method
         FROM cashbook
         WHERE reference_id = :rid AND reference_type = 'purchase_return'",
        [':rid' => $return_id]
    );
    $return['cashbook_entry_exists']   = ($cashbook !== null);
    $return['cashbook_amount']         = $cashbook ? (float)($cashbook['credit'] ?? 0) : 0;
    $return['cashbook_payment_method'] = $cashbook['payment_method'] ?? null;

    return $return;
}

// ─────────────────────────────────────────────────────────
// 8. GET ALL SALE RETURNS
// ── FIX: Added s.customer_phone to SELECT so that sale_return_list.php
//         can render clickable customer links with phone number.
// ─────────────────────────────────────────────────────────
function getAllSaleReturns(array $filters = [], int $page = 1, int $perPage = 20): array {
    $where  = "1=1";
    $params = [];

    if (!empty($filters['from_date'])) {
        $where .= " AND DATE(sr.created_at) >= :from";
        $params[':from'] = $filters['from_date'];
    }
    if (!empty($filters['to_date'])) {
        $where .= " AND DATE(sr.created_at) <= :to";
        $params[':to'] = $filters['to_date'];
    }
    if (!empty($filters['customer'])) {
        $where .= " AND (s.customer_name LIKE :cust OR s.invoice_no LIKE :cust OR s.customer_phone LIKE :cust)";
        $params[':cust'] = '%' . $filters['customer'] . '%';
    }
    if (!empty($filters['refund_method'])) {
        $where .= " AND sr.refund_method = :rm";
        $params[':rm'] = $filters['refund_method'];
    }

    $offset = max(0, ($page - 1) * $perPage);

    $data = _pdoAll(
        "SELECT sr.*,
                s.invoice_no,
                s.customer_name,
                s.customer_phone,
                p.product_name,
                b.batch_number,
                u.full_name as returned_by_name,
                CASE WHEN cb.id IS NOT NULL THEN 1 ELSE 0 END as has_cashbook
         FROM sale_returns sr
         JOIN sales s              ON sr.sale_id      = s.id
         LEFT JOIN sale_items si   ON sr.sale_item_id = si.id
         LEFT JOIN products p      ON si.product_id   = p.id
         LEFT JOIN batches b       ON sr.batch_id     = b.id
         LEFT JOIN users u         ON sr.returned_by  = u.id
         LEFT JOIN cashbook cb     ON cb.reference_id = sr.id
                                  AND cb.reference_type = 'sale_return'
         WHERE {$where}
         ORDER BY sr.created_at DESC
         LIMIT {$offset}, {$perPage}",
        $params
    );

    $countRow = _pdoOne(
        "SELECT COUNT(*) as total FROM sale_returns sr
         JOIN sales s ON sr.sale_id = s.id
         WHERE {$where}",
        $params
    );
    $total = (int)($countRow['total'] ?? 0);

    return [
        'data'       => $data ?: [],
        'total'      => $total,
        'totalPages' => (int)ceil($total / max(1, $perPage)),
        'page'       => $page,
    ];
}

// ─────────────────────────────────────────────────────────
// 9. GET ALL PURCHASE RETURNS
// ─────────────────────────────────────────────────────────
function getAllPurchaseReturns(
    $filtersOrPage   = [],
    int $perPage     = 20,
    string $search   = '',
    string $fromDate = '',
    string $toDate   = '',
    string $refundMethod = ''
): array {

    if (is_int($filtersOrPage)) {
        $page    = max(1, $filtersOrPage);
        $filters = [
            'search'        => $search,
            'from_date'     => $fromDate,
            'to_date'       => $toDate,
            'refund_method' => $refundMethod,
        ];
    } else {
        $page    = max(1, (int)$perPage ?: 1);
        $perPage = 20;
        $filters = is_array($filtersOrPage) ? $filtersOrPage : [];
    }

    $where  = "1=1";
    $params = [];

    if (!empty($filters['from_date'])) {
        $where .= " AND DATE(pr.created_at) >= :from";
        $params[':from'] = $filters['from_date'];
    }
    if (!empty($filters['to_date'])) {
        $where .= " AND DATE(pr.created_at) <= :to";
        $params[':to'] = $filters['to_date'];
    }
    if (!empty($filters['supplier'])) {
        $where .= " AND (s.supplier_name LIKE :sup OR p.purchase_invoice_no LIKE :sup)";
        $params[':sup'] = '%' . $filters['supplier'] . '%';
    }
    if (!empty($filters['search'])) {
        $where .= " AND (pr.return_no LIKE :srch OR s.supplier_name LIKE :srch OR p.purchase_invoice_no LIKE :srch)";
        $params[':srch'] = '%' . $filters['search'] . '%';
    }
    if (!empty($filters['refund_method'])) {
        $where .= " AND pr.refund_method = :rm";
        $params[':rm'] = $filters['refund_method'];
    }

    $offset = max(0, ($page - 1) * $perPage);

    $data = _pdoAll(
        "SELECT pr.*,
                p.purchase_invoice_no, s.supplier_name,
                pi_prod.product_name,
                b.batch_number,
                u.full_name as returned_by,
                CASE WHEN cb.id IS NOT NULL THEN 1 ELSE 0 END AS has_cashbook
         FROM purchase_returns pr
         JOIN purchases p               ON pr.purchase_id       = p.id
         JOIN suppliers s               ON p.supplier_id        = s.id
         LEFT JOIN purchase_items pi2   ON pr.purchase_item_id  = pi2.id
         LEFT JOIN products pi_prod     ON pi2.product_id       = pi_prod.id
         LEFT JOIN batches b            ON pr.batch_id          = b.id
         LEFT JOIN users u              ON pr.returned_by       = u.id
         LEFT JOIN cashbook cb          ON cb.reference_id      = pr.id
                                       AND cb.reference_type    = 'purchase_return'
         WHERE {$where}
         ORDER BY pr.created_at DESC
         LIMIT {$offset}, {$perPage}",
        $params
    );

    $countRow = _pdoOne(
        "SELECT COUNT(*) as total FROM purchase_returns pr
         JOIN purchases p ON pr.purchase_id = p.id
         JOIN suppliers s ON p.supplier_id  = s.id
         WHERE {$where}",
        $params
    );
    $total = (int)($countRow['total'] ?? 0);

    return [
        'data'       => $data ?: [],
        'total'      => $total,
        'totalPages' => (int)ceil($total / max(1, $perPage)),
        'page'       => $page,
    ];
}

// ─────────────────────────────────────────────────────────
// 10. RETURN STATS BY DATE
// ─────────────────────────────────────────────────────────
function getReturnStatsByDate($date) {
    $saleStats = _pdoOne(
        "SELECT COALESCE(SUM(total_amount), 0) as total_amount, COUNT(*) as count
         FROM sale_returns WHERE DATE(created_at) = :date",
        [':date' => $date]
    );
    $purchaseStats = _pdoOne(
        "SELECT COALESCE(SUM(total_amount), 0) as total_amount, COUNT(*) as count
         FROM purchase_returns WHERE DATE(created_at) = :date",
        [':date' => $date]
    );
    return [
        'sale_returns' => [
            'total_amount' => (float)($saleStats['total_amount'] ?? 0),
            'count'        => (int)($saleStats['count'] ?? 0),
        ],
        'purchase_returns' => [
            'total_amount' => (float)($purchaseStats['total_amount'] ?? 0),
            'count'        => (int)($purchaseStats['count'] ?? 0),
        ],
        'combined_total' => (float)($saleStats['total_amount'] ?? 0) + (float)($purchaseStats['total_amount'] ?? 0),
    ];
}

// ─────────────────────────────────────────────────────────
// 11. LEGACY WRAPPERS (deprecated — kept for compatibility)
//     UPDATED: Now refresh product stock after commit.
// ─────────────────────────────────────────────────────────

/**
 * @deprecated Use ajax/process_return.php endpoint.
 */
function processSaleReturn($saleId, $items, $reason) {
    error_log("processSaleReturn() is deprecated. Use unified return processor.");
    $pdo = _getPDO();
    $pdo->beginTransaction();
    $affectedProductIds = [];
    try {
        $totalReturnAmount = 0;
        $returnNo = 'SR-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $returnId = null;
        foreach ($items as $item) {
            if ((int)$item['quantity'] <= 0) continue;
            $saleItem = _pdoOne(
                "SELECT * FROM sale_items WHERE id = :id AND sale_id = :sid",
                [':id' => $item['sale_item_id'], ':sid' => $saleId]
            );
            if (!$saleItem) throw new Exception("Sale item not found");
            if ((int)$item['quantity'] > (int)$saleItem['quantity'])
                throw new Exception("Return quantity exceeds sold quantity");

            $batchStmt = $pdo->prepare("SELECT remaining_quantity FROM batches WHERE id = :bid FOR UPDATE");
            $batchStmt->execute([':bid' => $saleItem['batch_id']]);
            $batch  = $batchStmt->fetch(PDO::FETCH_ASSOC);
            $newQty = (int)$batch['remaining_quantity'] + (int)$item['quantity'];
            $pdo->prepare("UPDATE batches SET remaining_quantity = :nq WHERE id = :bid")
                ->execute([':nq' => $newQty, ':bid' => $saleItem['batch_id']]);

            $returnAmount       = (int)$item['quantity'] * (float)$saleItem['unit_price'];
            $totalReturnAmount += $returnAmount;

            $pdo->prepare(
                "INSERT INTO sale_returns
                    (return_no, sale_id, sale_item_id, batch_id, quantity, unit_price, total_amount, reason, refund_method, returned_by)
                 VALUES (:rno, :sid, :siid, :bid, :qty, :up, :ta, :reason, 'cash', :uid)"
            )->execute([
                ':rno'    => $returnNo,
                ':sid'    => $saleId,
                ':siid'   => $saleItem['id'],
                ':bid'    => $saleItem['batch_id'],
                ':qty'    => $item['quantity'],
                ':up'     => $saleItem['unit_price'],
                ':ta'     => $returnAmount,
                ':reason' => $reason,
                ':uid'    => $_SESSION['user_id'] ?? 1,
            ]);
            $returnId = (int)$pdo->lastInsertId();

            $pdo->prepare(
                "INSERT INTO stock_movements (batch_id, movement_type, quantity, reference_id, reference_type, previous_quantity, new_quantity)
                 VALUES (:bid,'return_in',:qty,:rid,'sale_return',:prev,:new)"
            )->execute([
                ':bid'  => $saleItem['batch_id'],
                ':qty'  => $item['quantity'],
                ':rid'  => $returnId,
                ':prev' => $batch['remaining_quantity'],
                ':new'  => $newQty,
            ]);

            // Record product ID for stock refresh
            $affectedProductIds[] = (int)$saleItem['product_id'];
        }
        $pdo->prepare("UPDATE sales SET net_amount = net_amount - :amt WHERE id = :sid")
            ->execute([':amt' => $totalReturnAmount, ':sid' => $saleId]);
        $pdo->commit();

        // Refresh product stock for all affected products
        if (!empty($affectedProductIds)) {
            refreshProductStockAfterReturn($affectedProductIds);
        }

        if ($returnId && function_exists('syncAfterSaleReturn')) {
            syncAfterSaleReturn($returnId, $saleId, $totalReturnAmount, 'cash', $returnNo);
        }
        return ['success' => true, 'return_amount' => $totalReturnAmount];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Sale Return Error: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * @deprecated Use ajax/process_return.php endpoint.
 */
if (!function_exists('processPurchaseReturn')) {
function processPurchaseReturn($purchaseId, $items, $reason) {
    error_log("processPurchaseReturn() is deprecated. Use unified return processor.");
    $pdo = _getPDO();
    $pdo->beginTransaction();
    $affectedProductIds = [];
    try {
        $totalReturnAmount = 0;
        $returnNo = 'PR-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $returnId = null;
        $supplierIdVal = 0;

        foreach ($items as $item) {
            if ((int)$item['quantity'] <= 0) continue;
            $purchaseItem = _pdoOne(
                "SELECT pi.*, COALESCE(p.units_per_pack, 1) AS units_per_pack
                 FROM purchase_items pi
                 JOIN products p ON pi.product_id = p.id
                 WHERE pi.id = :id AND pi.purchase_id = :pid",
                [':id' => $item['purchase_item_id'], ':pid' => $purchaseId]
            );
            if (!$purchaseItem) throw new Exception("Purchase item not found");

            $unitsPerPack    = max(1, (int)$purchaseItem['units_per_pack']);
            $returnPieces    = (int)$item['quantity'];

            $batchStmt = $pdo->prepare("SELECT remaining_quantity FROM batches WHERE id = :bid FOR UPDATE");
            $batchStmt->execute([':bid' => $purchaseItem['batch_id']]);
            $batch = $batchStmt->fetch(PDO::FETCH_ASSOC);

            $batchRemainingPacks  = (int)$batch['remaining_quantity'];
            $batchRemainingPieces = $batchRemainingPacks * $unitsPerPack;

            if ($batchRemainingPieces < $returnPieces)
                throw new Exception("Insufficient stock in batch ({$batchRemainingPieces} pcs available)");

            $packsToDeduct = (int)ceil($returnPieces / $unitsPerPack);
            $newPacks      = max(0, $batchRemainingPacks - $packsToDeduct);

            $pdo->prepare("UPDATE batches SET remaining_quantity = :nq WHERE id = :bid")
                ->execute([':nq' => $newPacks, ':bid' => $purchaseItem['batch_id']]);

            $returnAmount       = $returnPieces * (float)$purchaseItem['unit_price'];
            $totalReturnAmount += $returnAmount;

            $pdo->prepare(
                "INSERT INTO purchase_returns
                    (return_no, purchase_id, purchase_item_id, batch_id, quantity, unit_price, total_amount, reason, refund_method, returned_by)
                 VALUES (:rno, :pid, :piid, :bid, :qty, :up, :ta, :reason, 'cash', :uid)"
            )->execute([
                ':rno'    => $returnNo,
                ':pid'    => $purchaseId,
                ':piid'   => $purchaseItem['id'],
                ':bid'    => $purchaseItem['batch_id'],
                ':qty'    => $returnPieces,
                ':up'     => $purchaseItem['unit_price'],
                ':ta'     => $returnAmount,
                ':reason' => $reason,
                ':uid'    => $_SESSION['user_id'] ?? 1,
            ]);
            $returnId = (int)$pdo->lastInsertId();

            $pdo->prepare(
                "INSERT INTO stock_movements (batch_id, movement_type, quantity, reference_id, reference_type, previous_quantity, new_quantity)
                 VALUES (:bid,'return_out',:qty,:rid,'purchase_return',:prev,:new)"
            )->execute([
                ':bid'  => $purchaseItem['batch_id'],
                ':qty'  => $packsToDeduct,
                ':rid'  => $returnId,
                ':prev' => $batchRemainingPacks,
                ':new'  => $newPacks,
            ]);

            if (!$supplierIdVal) {
                $pRow = _pdoOne("SELECT supplier_id FROM purchases WHERE id = :pid", [':pid' => $purchaseId]);
                $supplierIdVal = (int)($pRow['supplier_id'] ?? 0);
            }
            if ($supplierIdVal) {
                $pdo->prepare(
                    "UPDATE suppliers SET current_balance = GREATEST(0, current_balance - :amt) WHERE id = :sid"
                )->execute([':amt' => $returnAmount, ':sid' => $supplierIdVal]);
            }

            // Record product ID for stock refresh
            $affectedProductIds[] = (int)$purchaseItem['product_id'];
        }
        $pdo->commit();

        // Refresh product stock for all affected products
        if (!empty($affectedProductIds)) {
            refreshProductStockAfterReturn($affectedProductIds);
        }

        if ($returnId && $supplierIdVal && function_exists('syncAfterPurchaseReturn')) {
            syncAfterPurchaseReturn($returnId, $purchaseId, $supplierIdVal, $totalReturnAmount, $returnNo, 'cash');
        }
        return ['success' => true, 'return_amount' => $totalReturnAmount];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Purchase Return Error: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
}