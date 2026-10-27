<?php
/**
 * includes/product_functions.php
 * Product & Batch Query Functions — Complete Version
 * All functions guarded with if(!function_exists()) to prevent
 * "Cannot redeclare" errors when included alongside purchase_functions.php
 */

if (session_status() === PHP_SESSION_NONE) session_start();

// ==========================================================================
// PDO HELPERS (guarded — purchase_functions.php has identical copies)
// ==========================================================================

if (!function_exists('_getPDO')) {
function _getPDO(): PDO {
    foreach (['pdo', 'conn', 'db', 'connection', 'PDO'] as $name) {
        if (!empty($GLOBALS[$name]) && $GLOBALS[$name] instanceof PDO) {
            return $GLOBALS[$name];
        }
    }
    if (function_exists('db')) {
        $wrapper = db();
        foreach (['pdo', 'conn', 'connection', 'dbh', '_pdo', '_conn'] as $prop) {
            if (isset($wrapper->$prop) && $wrapper->$prop instanceof PDO) {
                return $wrapper->$prop;
            }
        }
        if ($wrapper instanceof PDO) return $wrapper;
    }
    if (defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER') && defined('DB_PASS')) {
        static $staticPdo = null;
        if (!$staticPdo) {
            $staticPdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
        }
        return $staticPdo;
    }
    throw new RuntimeException("Cannot find PDO connection. Check config.php.");
}
}

if (!function_exists('_pdoRun')) {
function _pdoRun(string $sql, array $params = []): PDOStatement {
    try {
        $pdo = _getPDO();
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $val) {
            if (is_null($val)) {
                $stmt->bindValue($key, null, PDO::PARAM_NULL);
            } elseif (is_int($val)) {
                $stmt->bindValue($key, $val, PDO::PARAM_INT);
            } elseif (is_bool($val)) {
                $stmt->bindValue($key, (int)$val, PDO::PARAM_INT);
            } elseif (is_float($val)) {
                $stmt->bindValue($key, $val, PDO::PARAM_STR);
            } else {
                $stmt->bindValue($key, $val, PDO::PARAM_STR);
            }
        }
        $stmt->execute();
        return $stmt;
    } catch (\Throwable $e) {
        error_log("_pdoRun Error: " . $e->getMessage());
        error_log("SQL: " . $sql);
        error_log("Params: " . print_r($params, true));
        throw $e;
    }
}
}

if (!function_exists('_pdoOne')) {
function _pdoOne(string $sql, array $params = []): ?array {
    $row = _pdoRun($sql, $params)->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
}

if (!function_exists('_pdoAll')) {
function _pdoAll(string $sql, array $params = []): array {
    return _pdoRun($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
}
}

// Alias used in view.php inline queries
if (!function_exists('_pf_all')) {
function _pf_all(string $sql, array $params = []): array {
    return _pdoAll($sql, $params);
}
}
if (!function_exists('_pf_one')) {
function _pf_one(string $sql, array $params = []): ?array {
    return _pdoOne($sql, $params);
}
}

// ==========================================================================
// SCHEMA MIGRATION
// ==========================================================================

if (!function_exists('ensureBatchesHaveReorderLevel')) {
function ensureBatchesHaveReorderLevel(): void {
    try {
        $pdo = _getPDO();
        $cols = array_column(_pdoAll("SHOW COLUMNS FROM batches"), 'Field');
        if (!in_array('reorder_level', $cols, true)) {
            $pdo->exec("ALTER TABLE batches ADD COLUMN reorder_level INT NOT NULL DEFAULT 10");
        }
    } catch (\Throwable $e) {
        error_log("ensureBatchesHaveReorderLevel: " . $e->getMessage());
    }
}
}

// ==========================================================================
// SCHEMA MIGRATION — products & batches
// Adds any columns that may be missing from older installs.
// Safe to run on every page load: SHOW COLUMNS is cheap and cached by MySQL.
// ==========================================================================

if (!function_exists('ensureProductsSchema')) {
function ensureProductsSchema(): void {
    static $ran = false;
    if ($ran) return;
    $ran = true;
    try {
        $pdo = _getPDO();

        // ── products table ────────────────────────────────────────────────
        $productCols = array_column(_pdoAll("SHOW COLUMNS FROM products"), 'Field');

        $productMissing = [
            // column name          => ALTER TABLE statement
            'image_path'  => "ALTER TABLE products ADD COLUMN image_path VARCHAR(500) NULL DEFAULT NULL",
            'barcode'     => "ALTER TABLE products ADD COLUMN barcode VARCHAR(100) NULL DEFAULT NULL",
            'size_value'  => "ALTER TABLE products ADD COLUMN size_value VARCHAR(100) NULL DEFAULT NULL",
            'deleted_at'  => "ALTER TABLE products ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL",
            'updated_at'  => "ALTER TABLE products ADD COLUMN updated_at DATETIME NULL DEFAULT NULL",
        ];

        foreach ($productMissing as $col => $ddl) {
            if (!in_array($col, $productCols, true)) {
                $pdo->exec($ddl);
                error_log("[ensureProductsSchema] Added products.{$col}");
            }
        }

        // ── batches table ─────────────────────────────────────────────────
        $batchCols = array_column(_pdoAll("SHOW COLUMNS FROM batches"), 'Field');

        $batchMissing = [
            'units_per_pack'    => "ALTER TABLE batches ADD COLUMN units_per_pack INT NOT NULL DEFAULT 1",
            'quantity_of_packs' => "ALTER TABLE batches ADD COLUMN quantity_of_packs INT NOT NULL DEFAULT 0",
            'total_pieces'      => "ALTER TABLE batches ADD COLUMN total_pieces INT NOT NULL DEFAULT 0",
            'is_blocked'        => "ALTER TABLE batches ADD COLUMN is_blocked TINYINT(1) NOT NULL DEFAULT 0",
            'reorder_level'     => "ALTER TABLE batches ADD COLUMN reorder_level INT NOT NULL DEFAULT 10",
            'purchase_date'     => "ALTER TABLE batches ADD COLUMN purchase_date DATE NULL DEFAULT NULL",
        ];

        foreach ($batchMissing as $col => $ddl) {
            if (!in_array($col, $batchCols, true)) {
                $pdo->exec($ddl);
                error_log("[ensureProductsSchema] Added batches.{$col}");
            }
        }

    } catch (\Throwable $e) {
        error_log("ensureProductsSchema: " . $e->getMessage());
    }
}
}

if (!function_exists('markExpiredBatches')) {
function markExpiredBatches(): void {
    static $ran = false;
    if ($ran) return;
    $ran = true;
    try {
        _pdoRun("UPDATE batches SET is_expired = 1 WHERE expiry_date <= CURDATE() AND is_expired = 0");
    } catch (\Throwable $e) {
        error_log("markExpiredBatches: " . $e->getMessage());
    }
}
}

// ==========================================================================
// CORE: getFilteredProducts()
// Returns batch-level rows for the products list page.
// ==========================================================================

if (!function_exists('getFilteredProducts')) {
function getFilteredProducts(
    string $search       = '',
    string $category     = '',
    int    $page         = 1,
    int    $perPage      = 20,
    string $searchType   = 'all',
    string $stockFilter  = 'in_stock',
    string $expiryFilter = 'all',
    string $sortBy       = 'expiry',
    string $sortDir      = 'asc'
): array {

    markExpiredBatches();

    $pWhere = ["p.status = 1"];
    $params = [];

    if ($search !== '') {
        $like = "%{$search}%";
        switch ($searchType) {
            case 'name':
                $pWhere[] = "p.product_name LIKE :s1";
                $params[':s1'] = $like;
                break;
            case 'brand':
                $pWhere[] = "p.brand_name LIKE :s1";
                $params[':s1'] = $like;
                break;
            case 'generic':
                $pWhere[] = "p.generic_name LIKE :s1";
                $params[':s1'] = $like;
                break;
            case 'code':
                $pWhere[] = "p.product_code LIKE :s1";
                $params[':s1'] = $like;
                break;
            default:
                $pWhere[] = "(p.product_name LIKE :s1 OR p.brand_name LIKE :s2
                              OR p.generic_name LIKE :s3 OR p.product_code LIKE :s4
                              OR EXISTS (
                                  SELECT 1 FROM batches bsrch
                                  WHERE bsrch.product_id = p.id
                                    AND bsrch.batch_number LIKE :s5
                              ))";
                $params[':s1']=$like; $params[':s2']=$like;
                $params[':s3']=$like; $params[':s4']=$like; $params[':s5']=$like;
        }
    }

    if ($category !== '') {
        $pWhere[] = "p.category_id = :cat";
        $params[':cat'] = (int)$category;
    }

    // $pWhereSQL is built AFTER the switch so filters like 'out_of_stock'
    // can append extra WHERE conditions before the SQL is assembled.
    $effectiveFilter = $stockFilter;
    if ($stockFilter === 'all' && $expiryFilter === 'expired')     $effectiveFilter = 'expired';
    if ($stockFilter === 'all' && $expiryFilter === 'near_expiry') $effectiveFilter = 'near_expiry';

    $dir = strtoupper($sortDir) === 'DESC' ? 'DESC' : 'ASC';
    $outerOrder = match($sortBy) {
        'name'     => "p.product_name $dir",
        'brand'    => "p.brand_name $dir",
        'strength' => "p.strength $dir",
        'stock'    => "COALESCE(b.remaining_quantity, 0) $dir",
        'price'    => "COALESCE(b.selling_price, 0) $dir",
        'units'    => "p.units_per_pack $dir",
        'newest'   => "COALESCE(b.id, 0) DESC",
        default    => "COALESCE(b.expiry_date, '9999-12-31') $dir, COALESCE(b.id,0) ASC",
    };

    switch ($effectiveFilter) {
        case 'in_stock':
            // Show earliest-expiry active batch (FIFO) with quantity > 0.
            $batchSubquery = "
                (SELECT bx.id FROM batches bx
                 WHERE bx.product_id = p.id
                   AND bx.remaining_quantity > 0
                   AND COALESCE(bx.is_expired,0) = 0
                   AND (bx.expiry_date IS NULL OR bx.expiry_date > CURDATE())
                 ORDER BY bx.expiry_date ASC, bx.id ASC
                 LIMIT 1)
            ";
            $joinType = "INNER";
            break;

        case 'out_of_stock':
            // Products that have been purchased but have ZERO active stock remaining.
            // Only show products with at least one batch AND no active (non-expired, qty>0) batches.
            // For the displayed batch: prefer the most recently sold-out non-expired batch;
            // fall back to any sold-out batch (could be expired) if all batches are expired.
            $pWhere[] = "EXISTS (SELECT 1 FROM batches bany WHERE bany.product_id = p.id)";
            $pWhere[] = "NOT EXISTS (
                SELECT 1 FROM batches bact
                WHERE bact.product_id = p.id
                  AND bact.remaining_quantity > 0
                  AND COALESCE(bact.is_expired, 0) = 0
                  AND (bact.expiry_date IS NULL OR bact.expiry_date > CURDATE())
            )";
            // Show the most recently sold-out batch (qty=0, not yet expired preferred).
            // We use CASE to prefer non-expired batches first, then latest batch id.
            $batchSubquery = "
                (SELECT bx.id FROM batches bx
                 WHERE bx.product_id = p.id
                   AND bx.remaining_quantity = 0
                 ORDER BY
                   CASE WHEN COALESCE(bx.is_expired,0) = 0
                             AND (bx.expiry_date IS NULL OR bx.expiry_date > CURDATE())
                        THEN 0 ELSE 1 END ASC,
                   bx.id DESC
                 LIMIT 1)
            ";
            $joinType = "INNER";
            break;

        case 'expired':
            // Products that have at least one expired batch (regardless of qty).
            // Show the most recently expired batch (latest expiry_date DESC, then id DESC).
            // This ensures: if 5 batches exist and batch #3 expired most recently, show #3's
            // sale price and expiry date. Stock shown is that specific batch's remaining_quantity.
            $pWhere[] = "EXISTS (
                SELECT 1 FROM batches bx WHERE bx.product_id = p.id
                  AND (bx.expiry_date <= CURDATE() OR bx.is_expired = 1)
            )";
            $batchSubquery = "
                (SELECT bx.id FROM batches bx
                 WHERE bx.product_id = p.id
                   AND (bx.expiry_date <= CURDATE() OR bx.is_expired = 1)
                 ORDER BY
                   bx.expiry_date DESC,
                   bx.id DESC
                 LIMIT 1)
            ";
            $joinType = "INNER";
            break;
        case 'near_expiry':
            $batchSubquery = "
                (SELECT bx.id FROM batches bx
                 WHERE bx.product_id = p.id
                   AND bx.remaining_quantity > 0
                   AND COALESCE(bx.is_expired,0) = 0
                   AND bx.expiry_date BETWEEN CURDATE()
                       AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                 ORDER BY bx.expiry_date ASC, bx.id ASC
                 LIMIT 1)
            ";
            $joinType = "INNER";
            break;
        case 'low_stock':
            $batchSubquery = "
                (SELECT bx.id FROM batches bx
                 WHERE bx.product_id = p.id
                   AND bx.remaining_quantity > 0
                   AND COALESCE(bx.is_expired,0) = 0
                   AND (bx.expiry_date IS NULL OR bx.expiry_date > CURDATE())
                   AND bx.remaining_quantity <= bx.reorder_level
                 ORDER BY bx.expiry_date ASC, bx.id ASC
                 LIMIT 1)
            ";
            $joinType = "INNER";
            break;
        default: // 'all' filter
            // FIX: ALL products including those never purchased (no batches).
            // Priority order for which batch to show:
            //   1. Active in-stock batch (earliest expiry = FIFO)
            //   2. Near-expiry batch
            //   3. Expired batch (most recently expired)
            //   4. OOS batch (most recently sold out)
            //   5. NULL (product never purchased — still shown, batch cols = null)
            $batchSubquery = "
                (SELECT bx.id FROM batches bx
                 WHERE bx.product_id = p.id
                 ORDER BY
                   CASE
                     WHEN bx.remaining_quantity > 0
                          AND COALESCE(bx.is_expired,0) = 0
                          AND (bx.expiry_date IS NULL OR bx.expiry_date > CURDATE())
                     THEN 0
                     WHEN bx.remaining_quantity > 0
                          AND (bx.expiry_date <= CURDATE() OR bx.is_expired = 1)
                     THEN 1
                     WHEN bx.remaining_quantity = 0
                     THEN 2
                     ELSE 3
                   END ASC,
                   bx.expiry_date ASC,
                   bx.id ASC
                 LIMIT 1)
            ";
            $joinType = "LEFT";
            break;
    }

    // Build WHERE string AFTER switch so all filter-specific conditions are included.
    $pWhereSQL = implode(' AND ', $pWhere);

    $countSQL = "
        SELECT COUNT(*) AS cnt
        FROM products p
        {$joinType} JOIN batches b
              ON b.id = {$batchSubquery}
        WHERE {$pWhereSQL}
    ";
    $total  = (int)(_pdoOne($countSQL, $params)['cnt'] ?? 0);
    $pages  = max(1, (int)ceil($total / $perPage));
    $page   = max(1, min($page, $pages));
    $offset = ($page - 1) * $perPage;

    $dataSQL = "
        SELECT
            b.id                            AS batch_id,
            b.batch_number,
            b.remaining_quantity,
            b.remaining_quantity            AS current_stock,
            b.purchase_price                AS batch_purchase_price,
            b.selling_price,
            b.selling_price                 AS sale_price,
            b.units_per_pack                AS batch_units_per_pack,
            b.quantity_of_packs,
            b.total_pieces,
            COALESCE(b.reorder_level, 10)   AS reorder_level,
            b.expiry_date,
            b.purchase_date,
            COALESCE(b.is_expired, 0)       AS is_expired,
            COALESCE(b.is_blocked, 0)       AS is_blocked,
            s.supplier_name,
            p.id                            AS product_id,
            p.product_code,
            p.product_name,
            p.generic_name,
            p.brand_name,
            p.category_id,
            p.dosage_form,
            p.strength,
            p.size_value,
            p.unit_type,
            p.units_per_pack,
            p.image_path,
            p.barcode,
            CASE WHEN EXISTS (
                SELECT 1 FROM batches bact
                WHERE bact.product_id = p.id
                  AND bact.remaining_quantity > 0
                  AND COALESCE(bact.is_expired,0) = 0
                  AND (bact.expiry_date IS NULL OR bact.expiry_date > CURDATE())
            ) THEN 1 ELSE 0 END             AS has_active_batch,
            (SELECT COALESCE(SUM(bx.remaining_quantity), 0) FROM batches bx
             WHERE bx.product_id = p.id
               AND bx.remaining_quantity > 0
               AND COALESCE(bx.is_expired,0) = 0
               AND (bx.expiry_date IS NULL OR bx.expiry_date > CURDATE())
            )                               AS total_active_stock,
            (SELECT COUNT(*) FROM batches bx
             WHERE bx.product_id = p.id
               AND bx.remaining_quantity > 0
               AND COALESCE(bx.is_expired,0) = 0
               AND (bx.expiry_date IS NULL OR bx.expiry_date > CURDATE())
            )                               AS active_batch_count,
            (SELECT COUNT(*) FROM batches bx
             WHERE bx.product_id = p.id
               AND (bx.expiry_date <= CURDATE() OR bx.is_expired = 1)
            )                               AS expired_batch_count,
            (SELECT COUNT(*) FROM batches bx
             WHERE bx.product_id = p.id AND bx.remaining_quantity = 0
            )                               AS oos_batch_count,
            (SELECT COUNT(*) FROM batches bx
             WHERE bx.product_id = p.id
            )                               AS total_batch_count
        FROM products p
        {$joinType} JOIN batches b
              ON b.id = {$batchSubquery}
        LEFT  JOIN suppliers s ON s.id = b.supplier_id
        WHERE {$pWhereSQL}
        ORDER BY {$outerOrder}
        LIMIT :limit OFFSET :offset
    ";

    $dataParams            = $params;
    $dataParams[':limit']  = $perPage;
    $dataParams[':offset'] = $offset;

    $pdo  = _getPDO();
    $stmt = $pdo->prepare($dataSQL);
    foreach ($dataParams as $key => $val) {
        if ($key === ':limit' || $key === ':offset') {
            $stmt->bindValue($key, (int)$val, PDO::PARAM_INT);
        } elseif (is_null($val)) {
            $stmt->bindValue($key, null, PDO::PARAM_NULL);
        } elseif (is_int($val)) {
            $stmt->bindValue($key, $val, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($key, $val, PDO::PARAM_STR);
        }
    }
    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'data'       => $data,
        'total'      => $total,
        'totalPages' => $pages,
        'page'       => $page,
        'perPage'    => $perPage,
    ];
}
}

// ==========================================================================
// CORE: getProductStatusCounts()
// ==========================================================================

if (!function_exists('getProductStatusCounts')) {
function getProductStatusCounts(): array {
    markExpiredBatches();
    try {
        $row = _pdoOne("
            SELECT
                SUM(CASE WHEN EXISTS (
                    SELECT 1 FROM batches bx WHERE bx.product_id = p.id
                      AND bx.remaining_quantity > 0
                      AND COALESCE(bx.is_expired,0) = 0
                      AND (bx.expiry_date IS NULL OR bx.expiry_date > CURDATE())
                ) THEN 1 ELSE 0 END) AS in_stock,
                SUM(CASE WHEN
                    EXISTS (
                        SELECT 1 FROM batches bx WHERE bx.product_id = p.id
                    )
                    AND NOT EXISTS (
                        SELECT 1 FROM batches bx WHERE bx.product_id = p.id
                          AND bx.remaining_quantity > 0
                          AND COALESCE(bx.is_expired,0) = 0
                          AND (bx.expiry_date IS NULL OR bx.expiry_date > CURDATE())
                    )
                THEN 1 ELSE 0 END) AS out_of_stock,
                SUM(CASE WHEN EXISTS (
                    SELECT 1 FROM batches bx WHERE bx.product_id = p.id
                      AND (bx.expiry_date <= CURDATE() OR bx.is_expired = 1)
                ) THEN 1 ELSE 0 END) AS expired,
                SUM(CASE WHEN EXISTS (
                    SELECT 1 FROM batches bx WHERE bx.product_id = p.id
                      AND bx.remaining_quantity > 0
                      AND COALESCE(bx.is_expired,0) = 0
                      AND bx.expiry_date BETWEEN CURDATE()
                          AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                ) THEN 1 ELSE 0 END) AS near_expiry,
                SUM(CASE WHEN EXISTS (
                    SELECT 1 FROM batches bx WHERE bx.product_id = p.id
                      AND bx.remaining_quantity > 0
                      AND COALESCE(bx.is_expired,0) = 0
                      AND (bx.expiry_date IS NULL OR bx.expiry_date > CURDATE())
                      AND bx.remaining_quantity <= bx.reorder_level
                ) THEN 1 ELSE 0 END) AS low_stock,
                COUNT(*) AS total_all
            FROM products p
            WHERE p.status = 1
        ");
        $total = (int)($row['total_all'] ?? 0);
        return [
            'in_stock'     => (int)($row['in_stock']     ?? 0),
            'out_of_stock' => (int)($row['out_of_stock'] ?? 0),
            'expired'      => (int)($row['expired']      ?? 0),
            'near_expiry'  => (int)($row['near_expiry']  ?? 0),
            'low_stock'    => (int)($row['low_stock']    ?? 0),
            'all'          => $total,
            'total'        => $total,
        ];
    } catch (\Throwable $e) {
        error_log("getProductStatusCounts: " . $e->getMessage());
        return ['in_stock'=>0,'out_of_stock'=>0,'expired'=>0,'near_expiry'=>0,
                'low_stock'=>0,'all'=>0,'total'=>0];
    }
}
}

// ==========================================================================
// CORE: getProductPillCounts()
// Returns all 6 status counts in ONE query, respecting the active
// search/category filters — replaces the old 6-call _pillCount() pattern.
// ==========================================================================

if (!function_exists('getProductPillCounts')) {
function getProductPillCounts(string $search = '', string $category = '', string $searchType = 'all'): array {
    $pdo    = _getPDO();
    $where  = ["p.status = 1"];
    $params = [];

    if ($search !== '') {
        $like = "%{$search}%";
        if ($searchType === 'name') {
            $where[] = "p.product_name LIKE :s1";
            $params[':s1'] = $like;
        } elseif ($searchType === 'brand') {
            $where[] = "p.brand_name LIKE :s1";
            $params[':s1'] = $like;
        } else {
            $where[] = "(p.product_name LIKE :s1 OR p.brand_name LIKE :s2 OR p.generic_name LIKE :s3 OR p.product_code LIKE :s4)";
            $params[':s1'] = $like;
            $params[':s2'] = $like;
            $params[':s3'] = $like;
            $params[':s4'] = $like;
        }
    }

    if ($category !== '') {
        $where[] = "p.category_id = :cat";
        $params[':cat'] = (int)$category;
    }

    $sql = "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN EXISTS(
            SELECT 1 FROM batches b WHERE b.product_id = p.id
              AND b.remaining_quantity > 0
              AND COALESCE(b.is_expired, 0) = 0
              AND (b.expiry_date IS NULL OR b.expiry_date > CURDATE())
        ) THEN 1 ELSE 0 END) AS in_stock,
        SUM(CASE WHEN EXISTS(
            SELECT 1 FROM batches b WHERE b.product_id = p.id
        ) AND NOT EXISTS(
            SELECT 1 FROM batches b WHERE b.product_id = p.id
              AND b.remaining_quantity > 0
              AND COALESCE(b.is_expired, 0) = 0
              AND (b.expiry_date IS NULL OR b.expiry_date > CURDATE())
        ) THEN 1 ELSE 0 END) AS out_of_stock,
        SUM(CASE WHEN EXISTS(
            SELECT 1 FROM batches b WHERE b.product_id = p.id
              AND (b.expiry_date <= CURDATE() OR b.is_expired = 1)
        ) THEN 1 ELSE 0 END) AS expired,
        SUM(CASE WHEN EXISTS(
            SELECT 1 FROM batches b WHERE b.product_id = p.id
              AND b.expiry_date > CURDATE()
              AND b.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
              AND b.remaining_quantity > 0
        ) THEN 1 ELSE 0 END) AS near_expiry,
        SUM(CASE WHEN (
            SELECT COALESCE(SUM(b.remaining_quantity), 0) FROM batches b WHERE b.product_id = p.id
              AND COALESCE(b.is_expired, 0) = 0
              AND (b.expiry_date IS NULL OR b.expiry_date > CURDATE())
        ) BETWEEN 1 AND 10 THEN 1 ELSE 0 END) AS low_stock
        FROM products p WHERE " . implode(' AND ', $where);

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: ['total' => 0, 'in_stock' => 0, 'out_of_stock' => 0, 'expired' => 0, 'near_expiry' => 0, 'low_stock' => 0];
    } catch (\Throwable $e) {
        error_log("getProductPillCounts: " . $e->getMessage());
        return ['total' => 0, 'in_stock' => 0, 'out_of_stock' => 0, 'expired' => 0, 'near_expiry' => 0, 'low_stock' => 0];
    }
}
}

// ==========================================================================
// CORE: getProductById()
// ==========================================================================

if (!function_exists('getProductById')) {
function getProductById(int $id): ?array {
    if ($id <= 0) return null;
    markExpiredBatches();

    $product = _pdoOne("
        SELECT p.*,
               c.category_name
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        WHERE p.id = :id AND p.status = 1
    ", [':id' => $id]);

    if (!$product) return null;

    $product['active_batches'] = _pdoAll("
        SELECT b.*, s.supplier_name
        FROM batches b
        LEFT JOIN suppliers s ON s.id = b.supplier_id
        WHERE b.product_id = :pid
          AND COALESCE(b.is_expired, 0) = 0
          AND (b.expiry_date IS NULL OR b.expiry_date > CURDATE())
        ORDER BY b.expiry_date ASC, b.id ASC
    ", [':pid' => $id]);

    $product['expired_batches'] = _pdoAll("
        SELECT b.*, s.supplier_name
        FROM batches b
        LEFT JOIN suppliers s ON s.id = b.supplier_id
        WHERE b.product_id = :pid
          AND (b.expiry_date <= CURDATE() OR b.is_expired = 1)
        ORDER BY b.expiry_date DESC
    ", [':pid' => $id]);

    $stockRow = _pdoOne("
        SELECT COALESCE(SUM(remaining_quantity), 0) AS total_stock
        FROM batches
        WHERE product_id = :pid
          AND COALESCE(is_expired, 0) = 0
          AND (expiry_date IS NULL OR expiry_date > CURDATE())
    ", [':pid' => $id]);
    $product['total_stock'] = (int)($stockRow['total_stock'] ?? 0);

    $product['stock_movements'] = _pdoAll("
        SELECT sm.*, b.batch_number
        FROM stock_movements sm
        INNER JOIN batches b ON b.id = sm.batch_id
        WHERE b.product_id = :pid
        ORDER BY sm.created_at DESC
        LIMIT 50
    ", [':pid' => $id]);

    $product['purchase_history'] = _pdoAll("
        SELECT pi.*, pu.purchase_invoice_no, pu.purchase_date,
               s.supplier_name, b.batch_number, b.expiry_date, b.remaining_quantity
        FROM purchase_items pi
        INNER JOIN purchases pu ON pu.id = pi.purchase_id
        LEFT JOIN batches b ON b.id = pi.batch_id
        LEFT JOIN suppliers s ON s.id = pu.supplier_id
        WHERE pi.product_id = :pid
        ORDER BY pu.purchase_date DESC
        LIMIT 50
    ", [':pid' => $id]);

    return $product;
}
}

// ==========================================================================
// getProductBatches()
// ==========================================================================

if (!function_exists('getProductBatches')) {
function getProductBatches(int $productId): array {
    return _pdoAll("
        SELECT b.*, s.supplier_name
        FROM batches b
        LEFT JOIN suppliers s ON s.id = b.supplier_id
        WHERE b.product_id = :pid
        ORDER BY b.expiry_date ASC, b.id ASC
    ", [':pid' => $productId]);
}
}

// ==========================================================================
// getBatchSummary()
// ==========================================================================

if (!function_exists('getBatchSummary')) {
function getBatchSummary(int $productId): array {
    try {
        $row = _pdoOne("
            SELECT
                SUM(CASE WHEN remaining_quantity > 0
                          AND COALESCE(is_expired,0) = 0
                          AND (expiry_date IS NULL OR expiry_date > CURDATE())
                     THEN 1 ELSE 0 END) AS active,
                SUM(CASE WHEN expiry_date <= CURDATE() OR is_expired = 1
                     THEN 1 ELSE 0 END) AS expired,
                SUM(CASE WHEN remaining_quantity = 0
                     THEN 1 ELSE 0 END) AS out_of_stock,
                COUNT(*) AS total
            FROM batches
            WHERE product_id = :pid
        ", [':pid' => $productId]);
        return [
            'active'       => (int)($row['active']       ?? 0),
            'expired'      => (int)($row['expired']      ?? 0),
            'out_of_stock' => (int)($row['out_of_stock'] ?? 0),
            'total'        => (int)($row['total']        ?? 0),
        ];
    } catch (\Throwable $e) {
        return ['active' => 0, 'expired' => 0, 'out_of_stock' => 0, 'total' => 0];
    }
}
}

// ==========================================================================
// updateBatchSalePrice()
// ==========================================================================

if (!function_exists('updateBatchSalePrice')) {
function updateBatchSalePrice(int $batchId, float $newPrice): bool {
    if ($batchId <= 0 || $newPrice <= 0) return false;
    try {
        _pdoRun("UPDATE batches SET selling_price = :p WHERE id = :id",
                [':p' => $newPrice, ':id' => $batchId]);

        $batch = _pdoOne("SELECT product_id FROM batches WHERE id = :id", [':id' => $batchId]);
        if ($batch) {
            if (function_exists('updateProductStockAndPrice')) {
                updateProductStockAndPrice((int)$batch['product_id']);
            } else {
                $fifo = _pdoOne("
                    SELECT selling_price FROM batches
                    WHERE product_id = :pid
                      AND remaining_quantity > 0
                      AND COALESCE(is_expired,0) = 0
                      AND (expiry_date IS NULL OR expiry_date > CURDATE())
                    ORDER BY expiry_date ASC, id ASC LIMIT 1
                ", [':pid' => (int)$batch['product_id']]);
                if ($fifo) {
                    _pdoRun("UPDATE products SET sale_price = :p WHERE id = :id",
                            [':p' => $fifo['selling_price'], ':id' => (int)$batch['product_id']]);
                }
            }
        }
        return true;
    } catch (\Throwable $e) {
        error_log("updateBatchSalePrice: " . $e->getMessage());
        return false;
    }
}
}

// ==========================================================================
// updateBatchReorderLevel()
// ==========================================================================

if (!function_exists('updateBatchReorderLevel')) {
function updateBatchReorderLevel(int $batchId, int $reorderLevel): bool {
    if ($batchId <= 0 || $reorderLevel < 1) return false;
    try {
        _pdoRun("UPDATE batches SET reorder_level = :r WHERE id = :id",
                [':r' => $reorderLevel, ':id' => $batchId]);
        return true;
    } catch (\Throwable $e) {
        error_log("updateBatchReorderLevel: " . $e->getMessage());
        return false;
    }
}
}

// ==========================================================================
// FORMAT HELPERS
// ==========================================================================

if (!function_exists('_smartPieceLabel')) {
function _smartPieceLabel(string $dosageForm, string $unitType): string {
    $df = strtolower(trim($dosageForm));
    $ut = strtolower(trim($unitType));
    if ($df === 'tablet' || $df === 'tablets')    return 'Tablet';
    if ($df === 'capsule' || $df === 'capsules')  return 'Capsule';
    if ($df === 'syrup')                           return 'Syrup';
    if ($df === 'injection' || $ut === 'ampoule' || $ut === 'vial') return 'Unit';
    if ($ut === 'sachet')  return 'Sachet';
    if ($ut === 'tube')    return 'Tube';
    if ($ut === 'bottle')  return 'Bottle';
    return 'Pc';
}
}

if (!function_exists('_smartPackLabel')) {
function _smartPackLabel(string $unitType): string {
    return match(strtolower(trim($unitType))) {
        'strip'   => 'Strip',
        'bottle'  => 'Bottle',
        'box'     => 'Box',
        'tube'    => 'Tube',
        'sachet'  => 'Sachet',
        'vial'    => 'Vial',
        'ampoule' => 'Ampoule',
        default   => 'Pack',
    };
}
}

if (!function_exists('formatStockDisplay')) {
function formatStockDisplay(int $remaining, int $unitsPerPack, string $unitType, string $dosageForm = ''): string {
    if ($remaining <= 0) {
        return '<span class="text-danger fw-bold">Out of Stock</span>';
    }

    $unitsPerPack = max(1, $unitsPerPack);
    $packs        = intdiv($remaining, $unitsPerPack);
    $leftover     = $remaining % $unitsPerPack;
    $pieceLabel   = _smartPieceLabel($dosageForm, $unitType);
    $packLabel    = _smartPackLabel($unitType);

    if ($unitsPerPack === 1) {
        return "<span class=\"text-success fw-semibold\">{$remaining} {$packLabel}(s)</span>"
             . "<br><small class=\"text-muted\">({$remaining} {$pieceLabel}s)</small>";
    }

    $parts = [];
    if ($packs > 0)    $parts[] = "{$packs} {$packLabel}(s)";
    if ($leftover > 0) $parts[] = "{$leftover} {$pieceLabel}(s)";
    $row1 = implode(' + ', $parts);
    $row2 = "({$remaining} {$pieceLabel}s)";

    return "<span class=\"text-success fw-semibold\">{$row1}</span>"
         . "<br><small class=\"text-muted\" style=\"font-size:11px;\">{$row2}</small>";
}
}

if (!function_exists('formatUnitsPerPackDisplay')) {
function formatUnitsPerPackDisplay(int $units, string $unitType, string $dosageForm = '', string $packType = ''): string {
    $units     = max(1, $units);
    $df        = strtolower(trim($dosageForm));
    $ut        = strtolower(trim($unitType));
    $pieceLabel = _smartPieceLabel($dosageForm, $unitType);
    $packLabel  = _smartPackLabel($unitType);

    if ($units === 1 && ($ut === 'bottle' || $ut === 'tube' || $ut === 'vial' || $ut === 'ampoule')) {
        return "1 {$packLabel}";
    }
    if ($ut === 'strip' && ($df === 'tablet' || $df === 'tablets')) {
        return "{$units} Tablets/Strip";
    }
    if ($ut === 'strip' && ($df === 'capsule' || $df === 'capsules')) {
        return "{$units} Capsules/Strip";
    }
    if ($ut === 'bottle' && $df === 'syrup') {
        return "{$units} Syrup/Bottle";
    }
    if ($ut === 'pcs' || $ut === 'piece' || $ut === 'pieces') {
        return $units === 1 ? "1 Pc/Pack" : "{$units} Pcs/Pack";
    }
    return "{$units} {$pieceLabel}s/{$packLabel}";
}
}

if (!function_exists('getExpiryDisplay')) {
function getExpiryDisplay(?string $expiryDate): string {
    if (empty($expiryDate) || $expiryDate === '0000-00-00') {
        return '<span class="badge bg-secondary">No Expiry</span>';
    }
    try {
        $now    = new DateTime('today');
        $expiry = new DateTime($expiryDate);
        $diff   = $now->diff($expiry);

        if ($expiry < $now) {
            return '<span class="badge bg-danger">Expired</span>';
        }

        $totalDays = (int)$diff->days;

        if ($totalDays <= 30) {
            return "<span class=\"badge bg-warning text-dark\">{$totalDays} Days Left</span>";
        }

        $years  = (int)$diff->y;
        $months = (int)$diff->m;
        $days   = (int)$diff->d;

        if ($years >= 1) {
            return sprintf('%02dY-%02dM-%02dD', $years, $months, $days);
        }
        return sprintf('%02dM-%02dD', $months, $days);

    } catch (\Throwable $e) {
        return '<span class="badge bg-secondary">—</span>';
    }
}
}

if (!function_exists('formatExpiryDateForDisplay')) {
function formatExpiryDateForDisplay(?string $expiryDate): string {
    return getExpiryDisplay($expiryDate);
}
}

if (!function_exists('formatDate')) {
function formatDate(?string $date, string $format = 'd M Y'): string {
    if (empty($date) || $date === '0000-00-00') return '—';
    try {
        return (new DateTime($date))->format($format);
    } catch (\Throwable $e) {
        return htmlspecialchars($date);
    }
}
}

if (!function_exists('formatCurrency')) {
function formatCurrency($amount, string $prefix = 'Rs. '): string {
    return $prefix . number_format((float)$amount, 2);
}
}

// ==========================================================================
// updateProductStockAndPrice()
// ==========================================================================

if (!function_exists('updateProductStockAndPrice')) {
function updateProductStockAndPrice(int $productId): void {
    if ($productId <= 0) return;
    try {
        $fifo = _pdoOne("
            SELECT selling_price
            FROM batches
            WHERE product_id = :pid
              AND remaining_quantity > 0
              AND COALESCE(is_expired, 0) = 0
              AND (expiry_date IS NULL OR expiry_date > CURDATE())
            ORDER BY expiry_date ASC, id ASC
            LIMIT 1
        ", [':pid' => $productId]);

        if ($fifo) {
            _pdoRun("UPDATE products SET sale_price = :p WHERE id = :id",
                    [':p' => $fifo['selling_price'], ':id' => $productId]);
        }
    } catch (\Throwable $e) {
        error_log("updateProductStockAndPrice: " . $e->getMessage());
    }
}
}

// ==========================================================================
// CSRF helper
// ==========================================================================

if (!function_exists('verifyCSRFToken')) {
function verifyCSRFToken(string $token): bool {
    if (session_status() === PHP_SESSION_NONE) session_start();
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
}

if (!function_exists('generateCSRFToken')) {
function generateCSRFToken(): string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
}

// ==========================================================================
// logActivity()
// ==========================================================================

if (!function_exists('logActivity')) {
function logActivity(string $action, string $description = '', ?int $userId = null): void {
    try {
        $uid = $userId ?? ($_SESSION['user_id'] ?? null);
        _pdoRun(
            "INSERT INTO activity_logs (user_id, action, description, created_at)
             VALUES (:uid, :act, :desc, NOW())",
            [':uid' => $uid, ':act' => $action, ':desc' => $description]
        );
    } catch (\Throwable $e) {
        // activity_logs table may not exist — silently ignore
    }
}
}

// Run schema migrations on include
ensureBatchesHaveReorderLevel();
ensureProductsSchema();

// ==========================================================================
// DOSAGE FORMS
// ==========================================================================

if (!function_exists('getAllDosageForms')) {
function getAllDosageForms(): array {
    $standard = [
        'Tablet','Capsule','Syrup','Suspension','Solution','Drops',
        'Injection','Cream','Ointment','Gel','Lotion','Powder',
        'Inhaler','Patch','Suppository','Sachet','Spray','Foam',
        'Ampoule','Vial',
    ];

    try {
        $custom = _pdoAll(
            "SELECT DISTINCT dosage_form FROM custom_dosage_forms
             WHERE dosage_form IS NOT NULL AND dosage_form != ''
             ORDER BY dosage_form ASC"
        );
        foreach ($custom as $row) {
            $val = trim($row['dosage_form']);
            if ($val !== '' && !in_array($val, $standard, true)) {
                $standard[] = $val;
            }
        }
    } catch (\Throwable $e) {}

    try {
        $fromProducts = _pdoAll(
            "SELECT DISTINCT dosage_form FROM products
             WHERE dosage_form IS NOT NULL AND dosage_form != ''
             ORDER BY dosage_form ASC"
        );
        foreach ($fromProducts as $row) {
            $val = trim($row['dosage_form']);
            if ($val !== '' && !in_array($val, $standard, true)) {
                $standard[] = $val;
            }
        }
    } catch (\Throwable $e) {}

    sort($standard);
    return array_values(array_unique($standard));
}
}

if (!function_exists('saveDosageForm')) {
function saveDosageForm(string $dosageForm): void {
    $dosageForm = trim($dosageForm);
    if ($dosageForm === '') return;
    try {
        _pdoRun(
            "INSERT IGNORE INTO custom_dosage_forms (dosage_form, created_at)
             VALUES (:df, NOW())",
            [':df' => $dosageForm]
        );
    } catch (\Throwable $e) {}
}
}

// ==========================================================================
// SUPPLIERS
// ==========================================================================

if (!function_exists('getAllSuppliers')) {
function getAllSuppliers(): array {
    try {
        return _pdoAll(
            "SELECT id, supplier_name, phone, email, contact_person
             FROM suppliers
             WHERE status = 1 OR status IS NULL
             ORDER BY supplier_name ASC"
        );
    } catch (\Throwable $e) {
        error_log("getAllSuppliers: " . $e->getMessage());
        return [];
    }
}
}

if (!function_exists('addSupplier')) {
function addSupplier(array $data): int {
    try {
        _pdoRun(
            "INSERT INTO suppliers
                (supplier_name, phone, email, contact_person, address, status, created_at)
             VALUES
                (:name, :phone, :email, :contact, :address, 1, NOW())",
            [
                ':name'    => trim($data['supplier_name']  ?? ''),
                ':phone'   => trim($data['phone']          ?? ''),
                ':email'   => trim($data['email']          ?? ''),
                ':contact' => trim($data['contact_person'] ?? ''),
                ':address' => trim($data['address']        ?? ''),
            ]
        );
        return (int)_getPDO()->lastInsertId();
    } catch (\Throwable $e) {
        error_log("addSupplier: " . $e->getMessage());
        return 0;
    }
}
}

// ==========================================================================
// CATEGORIES
// ==========================================================================

if (!function_exists('getCategories')) {
function getCategories(): array {
    try {
        return _pdoAll("SELECT id, category_name as name FROM categories ORDER BY id ASC");
    } catch (\Throwable $e) {
        return [
            ['id' => 1, 'name' => 'Medicine'],
            ['id' => 2, 'name' => 'Non-Medicine'],
        ];
    }
}
}

// ==========================================================================
// PRODUCT SAVE — FIXED VERSION
// ==========================================================================

if (!function_exists('saveProduct')) {
function saveProduct(array $data): int {
    try {
        error_log("=== saveProduct called ===");
        error_log("Data: " . print_r($data, true));
        
        // Validate required fields
        if (empty(trim($data['product_name'] ?? ''))) {
            error_log("ERROR: Product name is missing");
            return 0;
        }
        
        if (empty(trim($data['brand_name'] ?? ''))) {
            error_log("ERROR: Brand name is missing");
            return 0;
        }
        
        $category_id = (int)($data['category_id'] ?? 1);
        
        // Verify category exists
        $catCheck = _pdoOne("SELECT id FROM categories WHERE id = :id", [':id' => $category_id]);
        if (!$catCheck) {
            error_log("ERROR: Category ID $category_id does not exist");
            return 0;
        }
        
        // For medicine, validate generic name and dosage form
        if ($category_id === 1) {
            if (empty(trim($data['generic_name'] ?? ''))) {
                error_log("ERROR: Generic name required for medicine");
                return 0;
            }
            if (empty(trim($data['dosage_form'] ?? ''))) {
                error_log("ERROR: Dosage form required for medicine");
                return 0;
            }
        }
        
        // Auto-generate product code if not provided
        if (empty($data['product_code'])) {
            $prefix = ($category_id === 1) ? 'MED' : 'NMED';
            $last = _pdoOne(
                "SELECT product_code FROM products 
                 WHERE product_code LIKE :pfx 
                 ORDER BY id DESC LIMIT 1",
                [':pfx' => $prefix . '%']
            );
            
            $num = 1;
            if ($last && !empty($last['product_code'])) {
                $num = (int)preg_replace('/[^0-9]/', '', $last['product_code']) + 1;
            }
            $data['product_code'] = $prefix . str_pad($num, 4, '0', STR_PAD_LEFT);
            error_log("Generated product code: " . $data['product_code']);
        }
        
        // Prepare data for insert
        $productData = [
            ':product_code'   => $data['product_code'],
            ':product_name'   => trim($data['product_name']),
            ':category_id'    => $category_id,
            ':brand_name'     => trim($data['brand_name']),
            ':generic_name'   => trim($data['generic_name'] ?? ''),
            ':strength'       => trim($data['strength'] ?? ''),
            ':dosage_form'    => trim($data['dosage_form'] ?? ''),
            ':size_value'     => trim($data['size_value'] ?? ''),
            ':unit_type'      => trim($data['unit_type'] ?? 'Pcs'),
            ':units_per_pack' => (int)($data['units_per_pack'] ?? 1),
            ':barcode'        => trim($data['barcode'] ?? ''),
            ':image_path'     => trim($data['image_path'] ?? ''),
            ':purchase_price' => 0.00,
            ':sale_price'     => 0.00,
            ':status'         => 1,
        ];
        
        // Insert query
        $sql = "INSERT INTO products (
            product_code, product_name, category_id, brand_name, 
            generic_name, strength, dosage_form, size_value,
            unit_type, units_per_pack, barcode, image_path,
            purchase_price, sale_price, status, created_at
        ) VALUES (
            :product_code, :product_name, :category_id, :brand_name,
            :generic_name, :strength, :dosage_form, :size_value,
            :unit_type, :units_per_pack, :barcode, :image_path,
            :purchase_price, :sale_price, :status, NOW()
        )";
        
        error_log("SQL: " . $sql);
        
        // Execute and get PDO connection
        $pdo = _getPDO();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($productData);
        
        // Get last insert ID from the same connection
        $newId = (int)$pdo->lastInsertId();
        
        error_log("Last insert ID from PDO: " . $newId);
        
        if ($newId > 0) {
            error_log("Product saved successfully with ID: " . $newId);
            
            if ($category_id === 1 && !empty($productData[':dosage_form'])) {
                saveDosageForm($productData[':dosage_form']);
            }
            
            return $newId;
        }
        
        // Try an alternative method to get the ID
        $checkSql = "SELECT id FROM products WHERE product_code = :code ORDER BY id DESC LIMIT 1";
        $lastProduct = _pdoOne($checkSql, [':code' => $data['product_code']]);
        if ($lastProduct && isset($lastProduct['id'])) {
            error_log("Found product via product_code lookup: " . $lastProduct['id']);
            return (int)$lastProduct['id'];
        }
        
        error_log("Failed to get lastInsertId");
        return 0;
        
    } catch (\Throwable $e) {
        error_log("saveProduct Error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        return 0;
    }
}
}

// ==========================================================================
// updateProduct()
// ==========================================================================

if (!function_exists('updateProduct')) {
function updateProduct(int $id, array $data): bool {
    try {
        _pdoRun(
            "UPDATE products SET
                product_name  = :name,
                category_id   = :cat,
                brand_name    = :brand,
                generic_name  = :generic,
                strength      = :strength,
                dosage_form   = :dosage,
                size_value    = :size,
                unit_type     = :unit_type,
                units_per_pack= :upp,
                barcode       = :barcode,
                updated_at    = NOW()
             WHERE id = :id",
            [
                ':name'      => trim($data['product_name']  ?? ''),
                ':cat'       => (int)($data['category_id']  ?? 1),
                ':brand'     => trim($data['brand_name']    ?? ''),
                ':generic'   => trim($data['generic_name']  ?? ''),
                ':strength'  => trim($data['strength']      ?? ''),
                ':dosage'    => trim($data['dosage_form']   ?? ''),
                ':size'      => trim($data['size_value']    ?? ''),
                ':unit_type' => trim($data['unit_type']     ?? 'Pcs'),
                ':upp'       => (int)($data['units_per_pack'] ?? 1),
                ':barcode'   => trim($data['barcode']       ?? ''),
                ':id'        => $id,
            ]
        );
        return true;
    } catch (\Throwable $e) {
        error_log("updateProduct: " . $e->getMessage());
        return false;
    }
}
}

// ==========================================================================
// deleteProduct()
// ==========================================================================

if (!function_exists('deleteProduct')) {
function deleteProduct(int $id): bool {
    try {
        _pdoRun(
            "UPDATE products SET deleted_at = NOW(), status = 0 WHERE id = :id",
            [':id' => $id]
        );
        return true;
    } catch (\Throwable $e) {
        error_log("deleteProduct: " . $e->getMessage());
        return false;
    }
}
}

// ==========================================================================
// restoreProduct()
// ==========================================================================

if (!function_exists('restoreProduct')) {
function restoreProduct(int $id): bool {
    try {
        _pdoRun(
            "UPDATE products SET status = 1, deleted_at = NULL, updated_at = NOW() WHERE id = :id AND status = 0",
            [':id' => $id]
        );
        return true;
    } catch (\Throwable $e) {
        error_log("restoreProduct: " . $e->getMessage());
        return false;
    }
}
}

// ==========================================================================
// isProductCodeUnique()
// ==========================================================================

if (!function_exists('isProductCodeUnique')) {
function isProductCodeUnique(string $code, int $excludeId = 0): bool {
    $row = _pdoOne(
        "SELECT id FROM products WHERE product_code = :code AND id != :eid AND status = 1",
        [':code' => $code, ':eid' => $excludeId]
    );
    return $row === null;
}
}

// ==========================================================================
// generateProductCode()
// ==========================================================================

if (!function_exists('generateProductCode')) {
function generateProductCode(int $categoryId): string {
    $prefix = ($categoryId === 1) ? 'MED' : 'NMED';
    $last   = _pdoOne(
        "SELECT product_code FROM products
         WHERE product_code LIKE :pfx
         ORDER BY id DESC LIMIT 1",
        [':pfx' => $prefix . '%']
    );
    $num = 1;
    if ($last && !empty($last['product_code'])) {
        $num = (int)preg_replace('/\D/', '', $last['product_code']) + 1;
    }
    return $prefix . str_pad($num, 4, '0', STR_PAD_LEFT);
}
}

// ==========================================================================
// NEAR EXPIRY batches
// ==========================================================================

if (!function_exists('getNearExpiryBatches')) {
function getNearExpiryBatches(int $days = 30): array {
    try {
        return _pdoAll(
            "SELECT b.*, p.product_name, p.product_code, p.unit_type,
                    p.dosage_form, p.units_per_pack, s.supplier_name
             FROM batches b
             INNER JOIN products p ON p.id = b.product_id
             LEFT  JOIN suppliers s ON s.id = b.supplier_id
             WHERE b.remaining_quantity > 0
               AND COALESCE(b.is_expired,0) = 0
               AND b.expiry_date BETWEEN CURDATE()
                   AND DATE_ADD(CURDATE(), INTERVAL :days DAY)
               AND p.status = 1
             ORDER BY b.expiry_date ASC",
            [':days' => $days]
        );
    } catch (\Throwable $e) {
        error_log("getNearExpiryBatches: " . $e->getMessage());
        return [];
    }
}
}

// ==========================================================================
// addProduct() — alias
// ==========================================================================

if (!function_exists('addProduct')) {
function addProduct(array $data): int {
    return saveProduct($data);
}
}

// ==========================================================================
// getExportProducts()
// ==========================================================================

if (!function_exists('getExportProducts')) {
function getExportProducts(
    string $search       = '',
    string $category     = '',
    string $searchType   = 'all',
    string $stockFilter  = 'in_stock',
    string $expiryFilter = 'all',
    string $sortBy       = 'expiry',
    string $sortDir      = 'asc',
    array  $selectedIds  = []
): array {
    
    markExpiredBatches();

    $pWhere = ["p.status = 1"];
    $params = [];

    if ($search !== '') {
        $like = "%{$search}%";
        switch ($searchType) {
            case 'name':    $pWhere[] = "p.product_name LIKE :s1"; $params[':s1']=$like; break;
            case 'brand':   $pWhere[] = "p.brand_name LIKE :s1";   $params[':s1']=$like; break;
            case 'generic': $pWhere[] = "p.generic_name LIKE :s1"; $params[':s1']=$like; break;
            case 'code':    $pWhere[] = "p.product_code LIKE :s1"; $params[':s1']=$like; break;
            default:
                $pWhere[] = "(p.product_name LIKE :s1 OR p.brand_name LIKE :s2
                              OR p.generic_name LIKE :s3 OR p.product_code LIKE :s4
                              OR EXISTS (SELECT 1 FROM batches bsrch
                                         WHERE bsrch.product_id = p.id AND bsrch.batch_number LIKE :s5))";
                $params[':s1']=$like; $params[':s2']=$like;
                $params[':s3']=$like; $params[':s4']=$like; $params[':s5']=$like;
        }
    }

    if ($category !== '') {
        $pWhere[] = "p.category_id = :cat";
        $params[':cat'] = (int)$category;
    }

    if (!empty($selectedIds)) {
        $placeholders = implode(',', array_map('intval', $selectedIds));
        $pWhere[] = "p.id IN ({$placeholders})";
    }

    $pWhereSQL = implode(' AND ', $pWhere);

    $effectiveFilter = $stockFilter;
    if ($stockFilter === 'all' && $expiryFilter === 'expired')     $effectiveFilter = 'expired';
    if ($stockFilter === 'all' && $expiryFilter === 'near_expiry') $effectiveFilter = 'near_expiry';

    $dir = strtoupper($sortDir) === 'DESC' ? 'DESC' : 'ASC';
    $outerOrder = match($sortBy) {
        'name'     => "p.product_name $dir",
        'brand'    => "p.brand_name $dir",
        'strength' => "p.strength $dir",
        'stock'    => "COALESCE(b.remaining_quantity, 0) $dir",
        'price'    => "COALESCE(b.selling_price, 0) $dir",
        'units'    => "p.units_per_pack $dir",
        'newest'   => "COALESCE(b.id, 0) DESC",
        default    => "COALESCE(b.expiry_date, '9999-12-31') $dir, COALESCE(b.id,0) ASC",
    };

    switch ($effectiveFilter) {
        case 'out_of_stock':
            $batchSubquery = "(SELECT bx.id FROM batches bx WHERE bx.product_id = p.id ORDER BY bx.id DESC LIMIT 1)";
            $joinType = "LEFT";
            $pWhere[] = "EXISTS (SELECT 1 FROM batches bany WHERE bany.product_id = p.id)";
            $pWhere[] = "NOT EXISTS (SELECT 1 FROM batches bact WHERE bact.product_id = p.id AND bact.remaining_quantity > 0 AND COALESCE(bact.is_expired,0) = 0 AND (bact.expiry_date IS NULL OR bact.expiry_date > CURDATE()))";
            break;
        case 'expired':
            $batchSubquery = "(SELECT bx.id FROM batches bx WHERE bx.product_id = p.id
                               AND (bx.expiry_date <= CURDATE() OR bx.is_expired = 1)
                               ORDER BY bx.expiry_date DESC, bx.id DESC LIMIT 1)";
            $joinType = "INNER"; break;
        case 'near_expiry':
            $batchSubquery = "(SELECT bx.id FROM batches bx WHERE bx.product_id = p.id
                               AND bx.remaining_quantity > 0
                               AND COALESCE(bx.is_expired,0) = 0
                               AND bx.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                               ORDER BY bx.expiry_date ASC, bx.id ASC LIMIT 1)";
            $joinType = "INNER"; break;
        case 'low_stock':
            $batchSubquery = "(SELECT bx.id FROM batches bx WHERE bx.product_id = p.id
                               AND bx.remaining_quantity > 0
                               AND COALESCE(bx.is_expired,0) = 0
                               AND (bx.expiry_date IS NULL OR bx.expiry_date > CURDATE())
                               AND bx.remaining_quantity <= bx.reorder_level
                               ORDER BY bx.expiry_date ASC, bx.id ASC LIMIT 1)";
            $joinType = "INNER"; break;
        case 'in_stock':
            $batchSubquery = "(SELECT bx.id FROM batches bx WHERE bx.product_id = p.id
                               AND bx.remaining_quantity > 0
                               AND COALESCE(bx.is_expired,0) = 0
                               AND (bx.expiry_date IS NULL OR bx.expiry_date > CURDATE())
                               ORDER BY bx.expiry_date ASC, bx.id ASC LIMIT 1)";
            $joinType = "INNER"; break;
        default:
            $batchSubquery = "(SELECT bx.id FROM batches bx WHERE bx.product_id = p.id
                               ORDER BY (bx.remaining_quantity > 0 AND COALESCE(bx.is_expired,0) = 0
                                         AND (bx.expiry_date IS NULL OR bx.expiry_date > CURDATE())) DESC,
                               bx.expiry_date ASC, bx.id ASC LIMIT 1)";
            $joinType = "LEFT"; break;
    }

    $sql = "
        SELECT
            p.product_code,
            p.product_name,
            p.generic_name,
            p.brand_name,
            c.category_name as category,
            p.dosage_form,
            p.strength,
            p.size_value,
            p.unit_type,
            p.units_per_pack,
            p.barcode,
            b.batch_number,
            b.remaining_quantity                    AS stock_pieces,
            b.selling_price                         AS sale_price_unit,
            (b.selling_price * p.units_per_pack)    AS sale_price_pack,
            b.purchase_price,
            b.expiry_date,
            b.purchase_date,
            COALESCE(b.reorder_level, 10)           AS reorder_level,
            COALESCE(b.is_expired, 0)               AS is_expired,
            s.supplier_name,
            (SELECT COUNT(*) FROM batches bx WHERE bx.product_id = p.id
               AND bx.remaining_quantity > 0
               AND COALESCE(bx.is_expired,0) = 0
               AND (bx.expiry_date IS NULL OR bx.expiry_date > CURDATE())
            ) AS active_batch_count,
            (SELECT COUNT(*) FROM batches bx WHERE bx.product_id = p.id
               AND (bx.expiry_date <= CURDATE() OR bx.is_expired = 1)
            ) AS expired_batch_count,
            (SELECT COUNT(*) FROM batches bx WHERE bx.product_id = p.id
            ) AS total_batch_count
        FROM products p
        {$joinType} JOIN batches b ON b.id = {$batchSubquery}
        LEFT JOIN categories c ON c.id = p.category_id
        LEFT JOIN suppliers s ON s.id = b.supplier_id
        WHERE {$pWhereSQL}
        ORDER BY {$outerOrder}
    ";

    try {
        return _pdoAll($sql, $params);
    } catch (\Throwable $e) {
        error_log("getExportProducts: " . $e->getMessage());
        return [];
    }
}
}

// ==========================================================================
// STRENGTHS
// ==========================================================================

if (!function_exists('getAllStrengths')) {
function getAllStrengths(): array {
    $standard = ['5mg','10mg','20mg','25mg','40mg','50mg','100mg','200mg','250mg','500mg','1g','2g','5ml','10ml'];
    $result   = [];
    foreach ($standard as $s) $result[] = ['strength' => $s];

    try {
        $custom = _pdoAll("SELECT DISTINCT strength FROM custom_strengths WHERE strength IS NOT NULL AND strength != '' ORDER BY strength ASC");
        foreach ($custom as $row) {
            $val = trim($row['strength']);
            if ($val !== '' && !in_array(['strength'=>$val], $result, true)) {
                $result[] = ['strength' => $val];
            }
        }
    } catch (\Throwable $e) { }

    try {
        $fromProd = _pdoAll("SELECT DISTINCT strength FROM products WHERE strength IS NOT NULL AND strength != '' ORDER BY strength ASC");
        foreach ($fromProd as $row) {
            $val = trim($row['strength']);
            if ($val !== '') {
                $already = false;
                foreach ($result as $r) { if ($r['strength'] === $val) { $already = true; break; } }
                if (!$already) $result[] = ['strength' => $val];
            }
        }
    } catch (\Throwable $e) { }

    return $result;
}
}

if (!function_exists('saveCustomStrength')) {
function saveCustomStrength(string $strength, string $unit = ''): void {
    $strength = trim($strength);
    if ($strength === '') return;
    try {
        _pdoRun(
            "CREATE TABLE IF NOT EXISTS custom_strengths (
                id INT AUTO_INCREMENT PRIMARY KEY,
                strength VARCHAR(100) NOT NULL,
                unit VARCHAR(50) DEFAULT '',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_strength (strength)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        _pdoRun(
            "INSERT IGNORE INTO custom_strengths (strength, unit, created_at) VALUES (:s, :u, NOW())",
            [':s' => $strength, ':u' => $unit]
        );
    } catch (\Throwable $e) { }
}
}

if (!function_exists('saveCustomDosageForm')) {
function saveCustomDosageForm(string $dosageForm): void {
    saveDosageForm($dosageForm);
}
}

if (!function_exists('getAllDosageFormsFlat')) {
function getAllDosageFormsFlat(): array {
    return getAllDosageForms();
}
}
// ==========================================================================
// HELPER: getStockForDisplay()
// Returns the correct stock number to show in the table depending on filter.
// ==========================================================================
if (!function_exists('getStockForDisplay')) {
function getStockForDisplay(array $b, string $stockFilter): int {
    $currentStock      = (int)($b['current_stock']     ?? $b['remaining_quantity'] ?? 0);
    $totalActiveStock  = (int)($b['total_active_stock'] ?? 0);

    return match($stockFilter) {
        // In-stock / near-expiry / low-stock: show combined active stock across ALL batches (FIFO total)
        'in_stock', 'near_expiry', 'low_stock'
            => $totalActiveStock > 0 ? $totalActiveStock : $currentStock,

        // Out-of-stock: by definition active stock = 0; show 0
        'out_of_stock'
            => 0,

        // Expired: show the remaining_quantity of the specific expired batch displayed
        'expired'
            => $currentStock,

        // All products: prefer total active; if zero (never purchased or all expired) show batch qty
        default
            => $totalActiveStock > 0 ? $totalActiveStock : $currentStock,
    };
}
}

// ==========================================================================
// HELPER: getStatusBadgeForFilter()
// Returns correct [badgeClass, badgeLabel, badgeIcon] depending on the
// active filter so badge always matches what the row actually represents.
// ==========================================================================
if (!function_exists('getStatusBadgeForFilter')) {
function getStatusBadgeForFilter(array $b, string $stockFilter): array {
    $currentStock  = (int)($b['current_stock'] ?? $b['remaining_quantity'] ?? 0);
    $expiryDate    = $b['expiry_date'] ?? null;
    $reorderLevel  = (int)($b['reorder_level'] ?? 10);
    $totalActive   = (int)($b['total_active_stock'] ?? 0);

    $isExpired  = $expiryDate && (new DateTime('today') > new DateTime($expiryDate));
    $isOOS      = ($totalActive <= 0 && $stockFilter !== 'expired');
    $isLowStock = (!$isExpired && $totalActive > 0 && $totalActive <= $reorderLevel);

    return match(true) {
        $stockFilter === 'expired'      => ['badge-expired',  'Expired',      'fa-skull-crossbones'],
        $stockFilter === 'out_of_stock' => ['badge-oos',      'Out of Stock', 'fa-times-circle'],
        $stockFilter === 'near_expiry'  => ['badge-expiring', 'Near Expiry',  'fa-clock'],
        $stockFilter === 'low_stock'    => ['badge-lowstock', 'Low Stock',    'fa-exclamation-triangle'],
        $isExpired                      => ['badge-expired',  'Expired',      'fa-skull-crossbones'],
        $isOOS                          => ['badge-oos',      'Out of Stock', 'fa-times-circle'],
        $isLowStock                     => ['badge-lowstock', 'Low Stock',    'fa-exclamation-triangle'],
        default                         => ['badge-instock',  'In Stock',     'fa-check-circle'],
    };
}
}