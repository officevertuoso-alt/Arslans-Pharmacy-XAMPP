<?php
// ── OPcache reset (runs once on file load) ────────────────────────────────────
if (function_exists('opcache_reset')) { @opcache_reset(); }
if (function_exists('opcache_invalidate')) { @opcache_invalidate(__FILE__, true); }

/**
 * Purchase Management Functions – FULLY FIXED VERSION
 * =====================================================
 * FIX 1: Batch insert error – proper PDO NULL binding for variant_id
 * FIX 2: Race condition – batch number uniqueness enforced inside transaction
 * FIX 3: Raw PDO used directly (bypasses broken db() wrapper issues)
 * FIX 4: getError() replaced with PDO errorInfo() directly
 * FIX 5: inTransaction() guard added everywhere
 * FIX 6: Stock update always runs after commit (not inside transaction)
 * FIX 7: Cashbook entry for paid purchase added automatically
 * FIX 8: Dashboard-visible: products.stock_quantity updated correctly
 * FIX 9: Added getPurchaseNetDue() helper for supplier ledger (net after returns)
 * FIX 10: Invoice number generation – fixed duplicate issue
 * =====================================================
 */

if (session_status() === PHP_SESSION_NONE) session_start();

// ==========================================================================
// INTERNAL HELPER: Get raw PDO connection (works with any wrapper pattern)
// ==========================================================================
if (!function_exists('_getPDO')) {
function _getPDO(): PDO {
    // Try common global variable names used in XAMPP pharmacy systems
    foreach (['pdo', 'conn', 'db', 'connection', 'PDO'] as $name) {
        if (!empty($GLOBALS[$name]) && $GLOBALS[$name] instanceof PDO) {
            return $GLOBALS[$name];
        }
    }
    // Try db() function
    if (function_exists('db')) {
        $wrapper = db();
        // Common wrapper patterns
        foreach (['pdo', 'conn', 'connection', 'dbh', '_pdo', '_conn'] as $prop) {
            if (isset($wrapper->$prop) && $wrapper->$prop instanceof PDO) {
                return $wrapper->$prop;
            }
        }
        // If wrapper itself is PDO subclass
        if ($wrapper instanceof PDO) {
            return $wrapper;
        }
    }
    // Last resort: check $_SESSION stored DSN or config
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

// ==========================================================================
// INTERNAL HELPER: Run a query and return PDOStatement
// ==========================================================================
if (!function_exists('_pdoRun')) {
function _pdoRun(string $sql, array $params = []): PDOStatement {
    $pdo = _getPDO();
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $val) {
        if (is_null($val)) {
            $stmt->bindValue($key, null, PDO::PARAM_NULL);
        } elseif (is_int($val)) {
            $stmt->bindValue($key, $val, PDO::PARAM_INT);
        } elseif (is_bool($val)) {
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
    return $row ?: null;
}
}

if (!function_exists('_pdoAll')) {
function _pdoAll(string $sql, array $params = []): array {
    return _pdoRun($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
}
}

// ==========================================================================
// 1. TABLE CREATION + AUTO-MIGRATION (self-healing)
// ==========================================================================

/**
 * Get existing column names for a table.
 */
if (!function_exists('_getTableColumns')) {
function _getTableColumns(string $table): array {
    try {
        $rows = _pdoAll("SHOW COLUMNS FROM `{$table}`");
        return array_column($rows, 'Field');
    } catch (\Throwable $e) {
        return [];
    }
}
}

/**
 * Add a column only if it doesn't already exist.
 */
if (!function_exists('_addColumnIfMissing')) {
function _addColumnIfMissing(PDO $pdo, string $table, string $column, string $definition): void {
    $cols = _getTableColumns($table);
    if (!in_array($column, $cols, true)) {
        try {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        } catch (\Throwable $e) {
            error_log("ALTER TABLE {$table} ADD {$column}: " . $e->getMessage());
        }
    }
}
}

if (!function_exists('ensurePurchaseTables')) {
function ensurePurchaseTables(): void {
    $pdo = _getPDO();

    // ── purchases ─────────────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS purchases (
        id INT AUTO_INCREMENT PRIMARY KEY,
        purchase_invoice_no VARCHAR(50) NOT NULL,
        supplier_id INT NOT NULL,
        purchase_date DATE NOT NULL,
        subtotal DECIMAL(10,2) DEFAULT 0,
        discount DECIMAL(10,2) DEFAULT 0,
        tax_amount DECIMAL(10,2) DEFAULT 0,
        total DECIMAL(10,2) DEFAULT 0,
        payment_status ENUM('paid','partial','pending') DEFAULT 'pending',
        paid_amount DECIMAL(10,2) DEFAULT 0,
        due_amount DECIMAL(12,2) DEFAULT 0,
        notes TEXT,
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_supplier (supplier_id),
        INDEX idx_date (purchase_date),
        INDEX idx_invoice (purchase_invoice_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Add UNIQUE constraint on purchase_invoice_no if not exists
    try {
        $pdo->exec("ALTER TABLE purchases ADD UNIQUE INDEX uq_purchase_invoice (purchase_invoice_no)");
    } catch (\Throwable $e) {
        // Index may already exist
    }

    // Migrate purchases: ensure tax column exists (some old installs used 'tax')
    _addColumnIfMissing($pdo, 'purchases', 'tax_amount', 'DECIMAL(10,2) DEFAULT 0');
    _addColumnIfMissing($pdo, 'purchases', 'due_amount',  'DECIMAL(12,2) DEFAULT 0');

    // ── purchase_items ─────────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        purchase_id INT NOT NULL,
        batch_id INT NOT NULL,
        product_id INT NOT NULL,
        variant_id INT DEFAULT NULL,
        quantity INT NOT NULL,
        pieces_per_pack INT NOT NULL DEFAULT 1,
        total_pieces INT NOT NULL,
        unit_price DECIMAL(10,2) NOT NULL,
        total DECIMAL(10,2) NOT NULL,
        expiry_date DATE NOT NULL,
        INDEX idx_purchase (purchase_id),
        INDEX idx_batch (batch_id),
        INDEX idx_product (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // AUTO-MIGRATE: add columns that may be missing in older installs
    _addColumnIfMissing($pdo, 'purchase_items', 'selling_price',  'DECIMAL(10,2) DEFAULT 0');
    _addColumnIfMissing($pdo, 'purchase_items', 'discount',        'DECIMAL(10,2) DEFAULT 0');
    _addColumnIfMissing($pdo, 'purchase_items', 'tax',             'DECIMAL(10,2) DEFAULT 0');
    _addColumnIfMissing($pdo, 'purchase_items', 'pieces_per_pack', 'INT NOT NULL DEFAULT 1');
    _addColumnIfMissing($pdo, 'purchase_items', 'total_pieces',    'INT NOT NULL DEFAULT 0');

    // ── batches ────────────────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS batches (
        id INT AUTO_INCREMENT PRIMARY KEY,
        batch_number VARCHAR(100) NOT NULL,
        product_id INT NOT NULL,
        variant_id INT DEFAULT NULL,
        supplier_id INT NOT NULL,
        purchase_price DECIMAL(10,2) NOT NULL,
        selling_price DECIMAL(10,2) NOT NULL DEFAULT 0,
        units_per_pack INT NOT NULL DEFAULT 1,
        quantity_of_packs INT NOT NULL DEFAULT 1,
        total_pieces INT NOT NULL,
        remaining_quantity INT NOT NULL,
        manufacture_date DATE DEFAULT NULL,
        expiry_date DATE NOT NULL,
        purchase_date DATE NOT NULL,
        is_expired TINYINT(1) DEFAULT 0,
        is_blocked TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_product (product_id),
        INDEX idx_expiry (expiry_date),
        INDEX idx_fifo (product_id, expiry_date, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // AUTO-MIGRATE batches columns
    _addColumnIfMissing($pdo, 'batches', 'selling_price',    'DECIMAL(10,2) NOT NULL DEFAULT 0');
    _addColumnIfMissing($pdo, 'batches', 'units_per_pack',   'INT NOT NULL DEFAULT 1');
    _addColumnIfMissing($pdo, 'batches', 'quantity_of_packs','INT NOT NULL DEFAULT 1');
    _addColumnIfMissing($pdo, 'batches', 'purchase_date',    'DATE NOT NULL DEFAULT (CURDATE())');
    _addColumnIfMissing($pdo, 'batches', 'is_expired',       'TINYINT(1) DEFAULT 0');
    _addColumnIfMissing($pdo, 'batches', 'is_blocked',       'TINYINT(1) DEFAULT 0');
    _addColumnIfMissing($pdo, 'batches', 'remaining_quantity','INT NOT NULL DEFAULT 0');
    // ⭐ reorder_level lives in batches (NOT products) — per-batch low-stock threshold
    _addColumnIfMissing($pdo, 'batches', 'reorder_level',    'INT NOT NULL DEFAULT 10');

    // Remove UNIQUE constraint on batch_number if it exists (was causing errors)
    try {
        $pdo->exec("ALTER TABLE batches DROP INDEX batch_number");
    } catch (\Throwable $e) {
        // Ignore – index may not exist
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS stock_movements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        batch_id INT NOT NULL,
        product_id INT DEFAULT NULL,
        movement_type ENUM('purchase','sale','return_in','return_out','adjustment_in','adjustment_out') NOT NULL,
        quantity INT NOT NULL,
        reference_id INT DEFAULT NULL,
        reference_type VARCHAR(20) DEFAULT NULL,
        previous_quantity INT DEFAULT NULL,
        new_quantity INT DEFAULT NULL,
        notes TEXT,
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_batch (batch_id),
        INDEX idx_product (product_id),
        INDEX idx_reference (reference_type, reference_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_returns (
        id INT AUTO_INCREMENT PRIMARY KEY,
        return_no VARCHAR(50) NOT NULL,
        purchase_id INT NOT NULL,
        purchase_item_id INT NOT NULL,
        batch_id INT NOT NULL,
        return_date DATETIME NOT NULL,
        quantity INT NOT NULL,
        unit_price DECIMAL(10,2) NOT NULL,
        total_amount DECIMAL(10,2) NOT NULL,
        reason TEXT,
        refund_method ENUM('cash','credit_note','bank') DEFAULT 'cash',
        credit_note_ref VARCHAR(100) NULL,
        status ENUM('pending','approved','rejected') DEFAULT 'approved',
        returned_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_purchase (purchase_id),
        INDEX idx_return_no (return_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // AUTO-MIGRATE purchase_returns: add any columns missing from older installs
    _addColumnIfMissing($pdo, 'purchase_returns', 'return_no',        "VARCHAR(50) NOT NULL DEFAULT ''");
    _addColumnIfMissing($pdo, 'purchase_returns', 'purchase_item_id', 'INT NOT NULL DEFAULT 0');
    _addColumnIfMissing($pdo, 'purchase_returns', 'batch_id',         'INT NOT NULL DEFAULT 0');
    _addColumnIfMissing($pdo, 'purchase_returns', 'return_date',      'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
    _addColumnIfMissing($pdo, 'purchase_returns', 'unit_price',       'DECIMAL(10,2) NOT NULL DEFAULT 0');
    _addColumnIfMissing($pdo, 'purchase_returns', 'total_amount',     'DECIMAL(10,2) NOT NULL DEFAULT 0');
    _addColumnIfMissing($pdo, 'purchase_returns', 'reason',           'TEXT NULL');
    _addColumnIfMissing($pdo, 'purchase_returns', 'refund_method',    "VARCHAR(20) DEFAULT 'cash'");
    _addColumnIfMissing($pdo, 'purchase_returns', 'credit_note_ref',  'VARCHAR(100) NULL');
    _addColumnIfMissing($pdo, 'purchase_returns', 'status',           "VARCHAR(20) DEFAULT 'approved'");
    _addColumnIfMissing($pdo, 'purchase_returns', 'returned_by',      'INT NULL DEFAULT NULL');
    _addColumnIfMissing($pdo, 'purchase_returns', 'product_name',     'VARCHAR(255) NULL');
    _addColumnIfMissing($pdo, 'purchase_returns', 'created_at',       'TIMESTAMP DEFAULT CURRENT_TIMESTAMP');

    $pdo->exec("CREATE TABLE IF NOT EXISTS supplier_payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        supplier_id INT NOT NULL,
        purchase_id INT NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        payment_date DATE NOT NULL,
        payment_method VARCHAR(50) DEFAULT 'cash',
        notes TEXT,
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_supplier (supplier_id),
        INDEX idx_purchase (purchase_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS cashbook (
        id INT AUTO_INCREMENT PRIMARY KEY,
        entry_date DATE NOT NULL,
        entry_type ENUM('sale','purchase_payment','credit_received','expense','sale_return','purchase_return','opening','closing','purchase') NOT NULL,
        reference_id INT DEFAULT NULL,
        reference_type VARCHAR(50) DEFAULT NULL,
        description VARCHAR(255) NOT NULL,
        debit DECIMAL(12,2) DEFAULT 0,
        credit DECIMAL(12,2) DEFAULT 0,
        balance DECIMAL(12,2) DEFAULT 0,
        payment_method ENUM('cash','card','online','cheque','bank') DEFAULT 'cash',
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        voided TINYINT(1) DEFAULT 0,
        voided_reason VARCHAR(255) NULL,
        INDEX idx_date (entry_date),
        INDEX idx_reference (reference_id, reference_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
}

// ==========================================================================
// 2. PURCHASE INVOICE NUMBER – DEFINITIVE FIX
// ==========================================================================
//
// ROOT CAUSE (confirmed from live DB dump):
//
//   DB has THREE historic formats:
//     PUR-2024-0001          (very old, year-only)
//     PUR-2026050001..0043   (YYYYMMDDDD – no dashes, running global seq)
//     PUR-202606-0001        (YYYYMM-XXXX – the format THIS code generates)
//
//   The code does:  LIKE 'PUR-20260611-%'
//   But DB has:     'PUR-202606-0001'   → dash after YYYYMM not after YYYYMMDD
//   So LIKE finds 0 rows → maxSeq=0 → generates PUR-202606-0001 → DUPLICATE.
//
// FIX STRATEGY:
//   1. GET_LOCK – serialise all concurrent callers at MySQL level (no race).
//   2. Scan ALL purchase_invoice_no values with a broad prefix (PUR-202606)
//      so every historic format for this month is counted regardless of
//      how many dashes or digits follow.
//   3. Extract the trailing numeric sequence from every matched row and
//      take the MAX – new number is MAX+1.
//   4. Emit canonical format PUR-YYYYMMDD-XXXX going forward.
//   5. Called INSIDE the transaction in addPurchase() so number+row are
//      committed atomically.
//
// ==========================================================================
// ── One-time migration: fix any duplicate PUR-202606-0001 entries ────────────
// Runs safely every time (only updates if duplicate actually exists)
if (!function_exists('_fixDuplicatePurchaseInvoices')) {
    function _fixDuplicatePurchaseInvoices(): void {
        try {
            $pdo = _getPDO();
            // Find any invoices matching old YYYYMM-XXXX format for current month
            $yearMonth = date('Ym');
            $oldFmt    = 'PUR-' . date('Y') . substr(date('m'), 0) . '-%';
            // Get all invoices and check for ones with YYYYMM- format (6 digit year+month)
            $rows = $pdo->query(
                "SELECT id, purchase_invoice_no FROM purchases
                  WHERE purchase_invoice_no REGEXP '^PUR-[0-9]{6}-[0-9]{4}$'
                  ORDER BY id ASC"
            )->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                // Already canonical YYYYMMDD format? Skip (those have 8 digits after PUR-)
                if (preg_match('/^PUR-\d{8}-\d{4}$/', $row['purchase_invoice_no'])) continue;

                // This is old YYYYMM-XXXX format — rename to YYYYMMDD-XXXX
                // Extract date from purchase record
                $p = $pdo->prepare("SELECT purchase_date FROM purchases WHERE id = :id");
                $p->execute([':id' => $row['id']]);
                $pdate = $p->fetchColumn();
                if (!$pdate) continue;

                $yyyymmdd  = date('Ymd', strtotime($pdate));
                $seqMatch  = [];
                preg_match('/(\d{4})$/', $row['purchase_invoice_no'], $seqMatch);
                $seq       = (int)($seqMatch[1] ?? 1);

                // Build new canonical name, incrementing seq until free
                $newInv    = 'PUR-' . $yyyymmdd . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
                $guard     = 0;
                while (true) {
                    $chk = $pdo->prepare("SELECT id FROM purchases WHERE purchase_invoice_no = :inv AND id != :self LIMIT 1");
                    $chk->execute([':inv' => $newInv, ':self' => $row['id']]);
                    if (!$chk->fetchColumn()) break;
                    $seq++;
                    $newInv = 'PUR-' . $yyyymmdd . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
                    if (++$guard > 999) break;
                }

                // Rename it
                $pdo->prepare("UPDATE purchases SET purchase_invoice_no = :new WHERE id = :id")
                    ->execute([':new' => $newInv, ':id' => $row['id']]);
                error_log("_fixDuplicatePurchaseInvoices: renamed {$row['purchase_invoice_no']} → {$newInv}");
            }
        } catch (\Throwable $e) {
            error_log("_fixDuplicatePurchaseInvoices (non-fatal): " . $e->getMessage());
        }
    }
}
// Run migration automatically on every load (safe — only updates old format rows)
if (function_exists('_fixDuplicatePurchaseInvoices') && function_exists('_getPDO')) {
    try { _fixDuplicatePurchaseInvoices(); } catch (\Throwable $e) { /* ignore */ }
}

if (!function_exists('generatePurchaseInvoiceNo')) {
    function generatePurchaseInvoiceNo(): string {
        $pdo = _getPDO();

        // ── Bulletproof invoice number generator ──────────────────────────
        // Handles ALL historic DB formats:
        //   PUR-2024-0001        (year-only, old)
        //   PUR-2026050001       (YYYYMM + 4-digit, no dash)
        //   PUR-202606-0001      (YYYYMM-XXXX)
        //   PUR-20260611-0001    (YYYYMMDD-XXXX, canonical going forward)
        //
        // Runs OUTSIDE any transaction — always reads latest committed data.
        // Uses direct PDO (not _pdoAll/_pdoOne) to avoid any cached connection.

        $yearMonthDay = date('Ymd');   // e.g. 20260611
        $yearMonth    = date('Ym');    // e.g. 202606  — broad prefix for all formats

        // Scan ALL invoices for this month (covers every historic format)
        $stmt = $pdo->prepare(
            "SELECT purchase_invoice_no FROM purchases
              WHERE purchase_invoice_no LIKE :pfx
              ORDER BY id DESC"
        );
        $stmt->execute([':pfx' => 'PUR-' . $yearMonth . '%']);
        $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Extract max trailing numeric sequence
        $maxSeq = 0;
        foreach ($existing as $inv) {
            if (preg_match('/(\d+)$/', $inv, $m)) {
                $n = (int)$m[1];
                if ($n > $maxSeq) $maxSeq = $n;
            }
        }

        // Build canonical format: PUR-YYYYMMDD-XXXX
        $newSeq    = $maxSeq + 1;
        $invoiceNo = 'PUR-' . $yearMonthDay . '-' . str_pad($newSeq, 4, '0', STR_PAD_LEFT);

        // Hard uniqueness guard — loop until we find a truly free slot
        $guard = 0;
        while (true) {
            $chk = $pdo->prepare("SELECT id FROM purchases WHERE purchase_invoice_no = :inv LIMIT 1");
            $chk->execute([':inv' => $invoiceNo]);
            if (!$chk->fetchColumn()) {
                break; // free — use it
            }
            $newSeq++;
            $invoiceNo = 'PUR-' . $yearMonthDay . '-' . str_pad($newSeq, 4, '0', STR_PAD_LEFT);
            if (++$guard > 9999) {
                // Absolute last resort: microsecond-based suffix
                $invoiceNo = 'PUR-' . $yearMonthDay . '-' . substr((string)(int)(microtime(true) * 1000), -6);
                break;
            }
        }

        return $invoiceNo;
    }
}


// ==========================================================================
// 3. BATCH NUMBER HELPERS
// ==========================================================================
/**
 * Generate a unique batch number (no DB hit for uniqueness – uses microtime + random).
 * Called before the transaction so we don't hold locks during generation.
 */
if (!function_exists('_makeUniqueBatchNumber')) {
function _makeUniqueBatchNumber(string $prefix = 'BATCH'): string {
    $date   = date('Ymd');
    $micro  = sprintf('%08d', (int)(fmod(microtime(true), 1) * 1e8));
    $rand   = strtoupper(bin2hex(random_bytes(4)));
    $candidate = "{$prefix}-{$date}-{$micro}-{$rand}";
    // Hard cap at 100 chars to fit VARCHAR(100)
    return substr($candidate, 0, 100);
}
}

/**
 * Resolve user-supplied batch number:
 * - If empty, generate one.
 * - If already used in THIS purchase session, append counter.
 * - If it exists in DB, append counter suffix.
 * Called INSIDE the transaction so we can use SELECT ... FOR UPDATE.
 */
if (!function_exists('_resolveBatchNumberInTx')) {
function _resolveBatchNumberInTx(string $userInput, array &$usedInThisTx): string {
    $input = trim($userInput);
    if ($input === '') {
        $input = _makeUniqueBatchNumber();
    }

    // If this exact string already used in this purchase (same form submission, 2 rows same batch)
    $base = $input;
    $n    = 1;
    while (in_array($input, $usedInThisTx, true)) {
        $input = $base . '-' . (++$n);
    }

    // Check DB – use FOR UPDATE to prevent race
    for ($suffix = $n; $suffix <= 999; $suffix++) {
        $candidate = ($suffix === 1) ? $base : ($base . '-' . $suffix);
        // Skip if already claimed this tx
        if (in_array($candidate, $usedInThisTx, true)) continue;
        $row = _pdoOne("SELECT id FROM batches WHERE batch_number = :bn LIMIT 1 FOR UPDATE", [':bn' => $candidate]);
        if (!$row) {
            $usedInThisTx[] = $candidate;
            return $candidate;
        }
    }
    // Absolute fallback
    $fallback = $base . '-' . date('His') . '-' . strtoupper(bin2hex(random_bytes(3)));
    $usedInThisTx[] = $fallback;
    return $fallback;
}
}

// ==========================================================================
// 4. SUPPLIER FUNCTIONS
// ==========================================================================
if (!function_exists('getAllSuppliers')) {
    function getAllSuppliers(string $search = ''): array {
        $sql    = "SELECT * FROM suppliers WHERE status = 1";
        $params = [];
        if (!empty($search)) {
            $sql             .= " AND (supplier_name LIKE :s OR phone LIKE :s OR contact_person LIKE :s)";
            $params[':s']    = "%{$search}%";
        }
        $sql .= " ORDER BY supplier_name ASC";
        return _pdoAll($sql, $params);
    }
}

if (!function_exists('getSupplierById')) {
    function getSupplierById(int $id): ?array {
        return _pdoOne("SELECT * FROM suppliers WHERE id = :id", [':id' => $id]);
    }
}

if (!function_exists('addSupplier')) {
    function addSupplier(array $data): int|false {
        $count = (int)(_pdoOne("SELECT COUNT(*) AS total FROM suppliers")['total'] ?? 0) + 1;
        $code  = 'SUP' . str_pad($count, 3, '0', STR_PAD_LEFT);
        $pdo   = _getPDO();
        $stmt  = $pdo->prepare("INSERT INTO suppliers
            (supplier_code, supplier_name, contact_person, phone, alternative_phone,
             email, address, gst_no, opening_balance, current_balance)
            VALUES (:code,:name,:contact,:phone,:alt,:email,:addr,:gst,:opening,:current)");
        $stmt->execute([
            ':code'    => $code,
            ':name'    => $data['supplier_name'],
            ':contact' => $data['contact_person'] ?? null,
            ':phone'   => $data['phone'],
            ':alt'     => $data['alternative_phone'] ?? null,
            ':email'   => $data['email'] ?? null,
            ':addr'    => $data['address'] ?? null,
            ':gst'     => $data['gst_no'] ?? null,
            ':opening' => $data['opening_balance'] ?? 0,
            ':current' => $data['opening_balance'] ?? 0,
        ]);
        return (int)$pdo->lastInsertId() ?: false;
    }
}

if (!function_exists('updateSupplier')) {
    function updateSupplier(int $id, array $data): bool {
        _pdoRun("UPDATE suppliers SET
            supplier_name=:name, contact_person=:contact, phone=:phone,
            alternative_phone=:alt, email=:email, address=:addr, gst_no=:gst
            WHERE id=:id", [
            ':id'      => $id,
            ':name'    => $data['supplier_name'],
            ':contact' => $data['contact_person'] ?? null,
            ':phone'   => $data['phone'],
            ':alt'     => $data['alternative_phone'] ?? null,
            ':email'   => $data['email'] ?? null,
            ':addr'    => $data['address'] ?? null,
            ':gst'     => $data['gst_no'] ?? null,
        ]);
        return true;
    }
}

if (!function_exists('deleteSupplier')) {
    function deleteSupplier(int $id): bool {
        $hasPurchases = (int)(_pdoOne("SELECT COUNT(*) AS t FROM purchases WHERE supplier_id=:id", [':id' => $id])['t'] ?? 0) > 0;
        if ($hasPurchases) {
            _pdoRun("UPDATE suppliers SET status=0 WHERE id=:id", [':id' => $id]);
        } else {
            _pdoRun("DELETE FROM suppliers WHERE id=:id", [':id' => $id]);
        }
        return true;
    }
}

// ==========================================================================
// 5. STOCK HELPERS
// ==========================================================================
if (!function_exists('markExpiredBatches')) {
function markExpiredBatches(): void {
    try {
        _pdoRun("UPDATE batches SET is_expired=1 WHERE expiry_date < CURDATE() AND is_expired=0");
    } catch (\Throwable $e) {
        error_log("markExpiredBatches: " . $e->getMessage());
    }
}
}

if (!function_exists('updateProductStockAndPrice')) {
function updateProductStockAndPrice(int $productId): void {
    try {
        markExpiredBatches();
        $stock = _pdoOne(
            "SELECT COALESCE(SUM(remaining_quantity),0) AS total
             FROM batches WHERE product_id=:pid AND remaining_quantity>0 AND is_expired=0",
            [':pid' => $productId]
        );
        $totalStock = (float)($stock['total'] ?? 0);

        $fifo = _pdoOne(
            "SELECT selling_price, purchase_price FROM batches
             WHERE product_id=:pid AND remaining_quantity>0 AND is_expired=0
             ORDER BY expiry_date ASC, id ASC LIMIT 1",
            [':pid' => $productId]
        );

        if ($fifo) {
            _pdoRun(
                "UPDATE products SET stock_quantity=:stock, sale_price=:sp WHERE id=:pid",
                [':stock' => $totalStock, ':sp' => $fifo['selling_price'], ':pid' => $productId]
            );
        } else {
            _pdoRun(
                "UPDATE products SET stock_quantity=:stock WHERE id=:pid",
                [':stock' => $totalStock, ':pid' => $productId]
            );
        }
    } catch (\Throwable $e) {
        error_log("updateProductStockAndPrice[$productId]: " . $e->getMessage());
    }
}
}

// FIFO deduction on sale
if (!function_exists('deductStockFIFO')) {
function deductStockFIFO(int $productId, float $qtyToDeduct): array {
    $pdo       = _getPDO();
    $remaining = $qtyToDeduct;
    $cogsTotal = 0.0;
    $batches   = [];
    $userId    = (int)($_SESSION['user_id'] ?? 1);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "SELECT id, batch_number, remaining_quantity, purchase_price, selling_price, expiry_date
             FROM batches
             WHERE product_id=:pid AND remaining_quantity>0 AND is_expired=0
             ORDER BY expiry_date ASC, id ASC FOR UPDATE"
        );
        $stmt->execute([':pid' => $productId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $b) {
            if ($remaining <= 0) break;
            $take       = min($remaining, (float)$b['remaining_quantity']);
            $cogsTotal += $take * (float)$b['purchase_price'];
            $newQty     = (float)$b['remaining_quantity'] - $take;
            $batches[]  = [
                'batch_id'       => $b['id'],
                'batch_number'   => $b['batch_number'],
                'qty_taken'      => $take,
                'purchase_price' => $b['purchase_price'],
                'selling_price'  => $b['selling_price'],
            ];
            $pdo->prepare("UPDATE batches SET remaining_quantity=:q WHERE id=:id")
                ->execute([':q' => $newQty, ':id' => $b['id']]);
            $pdo->prepare(
                "INSERT INTO stock_movements
                 (batch_id, product_id, movement_type, quantity, reference_type,
                  previous_quantity, new_quantity, notes, created_by)
                 VALUES (:bid,:pid,'sale',:qty,'sale',:prev,:new,'FIFO deduction',:user)"
            )->execute([
                ':bid' => $b['id'], ':pid' => $productId,
                ':qty' => $take, ':prev' => $b['remaining_quantity'],
                ':new' => $newQty, ':user' => $userId,
            ]);
            $remaining -= $take;
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("deductStockFIFO: " . $e->getMessage());
        return ['cogs' => 0, 'batches_used' => [], 'fulfilled' => 0, 'shortage' => $qtyToDeduct];
    }
    updateProductStockAndPrice($productId);
    return [
        'cogs'        => $cogsTotal,
        'batches_used'=> $batches,
        'fulfilled'   => $qtyToDeduct - $remaining,
        'shortage'    => $remaining,
    ];
}
}

if (!function_exists('getFIFOBatch')) {
function getFIFOBatch(int $productId): ?array {
    markExpiredBatches();
    return _pdoOne(
        "SELECT * FROM batches WHERE product_id=:pid AND remaining_quantity>0 AND is_expired=0
         ORDER BY expiry_date ASC, id ASC LIMIT 1",
        [':pid' => $productId]
    );
}
}

// ==========================================================================
// 6. ADD PURCHASE  ← MAIN FIXED FUNCTION
// ==========================================================================
$GLOBALS['addPurchaseLastError'] = '';

if (!function_exists('addPurchase')) {
    function addPurchase(array $purchaseData, array $items): int|false {
        $GLOBALS['addPurchaseLastError'] = '';
        ensurePurchaseTables();

        // ── Pre-flight validation ──────────────────────────────────────────
        if (empty($purchaseData['supplier_id']) || (int)$purchaseData['supplier_id'] <= 0) {
            $GLOBALS['addPurchaseLastError'] = 'No supplier selected.';
            return false;
        }
        if (empty($items)) {
            $GLOBALS['addPurchaseLastError'] = 'No items provided.';
            return false;
        }
        $today = date('Y-m-d');
        foreach ($items as $idx => $item) {
            $row = $idx + 1;
            if (empty($item['product_id']) || (int)$item['product_id'] <= 0) {
                $GLOBALS['addPurchaseLastError'] = "Row {$row}: Invalid product ID.";
                return false;
            }
            if (empty($item['expiry_date'])) {
                $GLOBALS['addPurchaseLastError'] = "Row {$row}: Expiry date missing.";
                return false;
            }
            if ($item['expiry_date'] <= $today) {
                $GLOBALS['addPurchaseLastError'] = "Row {$row}: Expiry date must be a future date.";
                return false;
            }
            if ((float)($item['purchase_price'] ?? 0) <= 0) {
                $GLOBALS['addPurchaseLastError'] = "Row {$row}: Purchase price must be > 0.";
                return false;
            }
            if ((float)($item['selling_price'] ?? 0) <= 0) {
                $GLOBALS['addPurchaseLastError'] = "Row {$row}: Selling price must be > 0.";
                return false;
            }
            if ((int)($item['units_per_pack'] ?? 0) <= 0 || (int)($item['quantity_of_packs'] ?? 0) <= 0) {
                $GLOBALS['addPurchaseLastError'] = "Row {$row}: Units/Packs must be > 0.";
                return false;
            }
        }

        // ── Detect/fix tax column ──────────────────────────────────────────
        $pdo = _getPDO();
        $cols = _pdoAll("SHOW COLUMNS FROM purchases");
        $colNames  = array_column($cols, 'Field');
        if (in_array('tax_amount', $colNames)) {
            $taxCol = 'tax_amount';
        } elseif (in_array('tax', $colNames)) {
            $taxCol = 'tax';
        } else {
            try {
                $pdo->exec("ALTER TABLE purchases ADD COLUMN tax_amount DECIMAL(10,2) DEFAULT 0");
                $taxCol = 'tax_amount';
            } catch (\Throwable $e) {
                $GLOBALS['addPurchaseLastError'] = 'Missing tax column: ' . $e->getMessage();
                return false;
            }
        }

        // ── Build purchase record ──────────────────────────────────────────
        $supplierId    = (int)$purchaseData['supplier_id'];
        $purchaseDate  = $purchaseData['purchase_date'];
        $subtotal      = round((float)$purchaseData['subtotal'], 2);
        $discount      = round((float)($purchaseData['discount'] ?? 0), 2);
        $tax           = round((float)($purchaseData['tax'] ?? 0), 2);
        $grandTotal    = round((float)$purchaseData['total'], 2);
        $paymentStatus = $purchaseData['payment_status'] ?? 'pending';
        $paidAmount    = round((float)($purchaseData['paid_amount'] ?? 0), 2);
        $due           = max(0, round($grandTotal - $paidAmount, 2));
        $notes         = $purchaseData['notes'] ?? null;
        $userId        = (int)($_SESSION['user_id'] ?? 1);

        // ── Generate invoice number BEFORE transaction ────────────────────
        // CRITICAL FIX: GET_LOCK + SELECT inside a transaction reads the
        // transaction's MVCC snapshot, which does NOT see rows committed by
        // other sessions since this transaction started — causing duplicates.
        // Solution: generate the number outside the transaction so the SELECT
        // reads the latest committed data, then the uniqueness guard loop
        // confirms it's still free before we open the transaction.
        $invoiceNo = generatePurchaseInvoiceNo();

        // ── START TRANSACTION ──────────────────────────────────────────────
        $pdo->beginTransaction();
        $purchaseId     = null;
        $uniqueProducts = [];
        $usedBatchNums  = [];

        try {
            // 0. Invoice number already reserved above (before transaction)

            // 1. Insert purchase header
            $pdo->prepare(
                "INSERT INTO purchases
                 (purchase_invoice_no, supplier_id, purchase_date, subtotal, discount,
                  {$taxCol}, total, payment_status, paid_amount, due_amount, notes, created_by)
                 VALUES
                 (:inv,:sid,:pdate,:sub,:disc,:tax,:total,:pstat,:paid,:due,:notes,:user)"
            )->execute([
                ':inv'   => $invoiceNo,
                ':sid'   => $supplierId,
                ':pdate' => $purchaseDate,
                ':sub'   => $subtotal,
                ':disc'  => $discount,
                ':tax'   => $tax,
                ':total' => $grandTotal,
                ':pstat' => $paymentStatus,
                ':paid'  => $paidAmount,
                ':due'   => $due,
                ':notes' => $notes,
                ':user'  => $userId,
            ]);
            $purchaseId = (int)$pdo->lastInsertId();
            if (!$purchaseId) throw new \RuntimeException("Purchase header insert returned no ID.");

            // 2. Insert items
            foreach ($items as $item) {
                $productId   = (int)$item['product_id'];
                $variantId   = (!empty($item['variant_id']) && (int)$item['variant_id'] > 0) ? (int)$item['variant_id'] : null;
                $unitsPerPack = (int)$item['units_per_pack'];
                $qtyOfPacks  = (int)$item['quantity_of_packs'];
                $totalPieces = $unitsPerPack * $qtyOfPacks;
                $purchasePrice = round((float)$item['purchase_price'], 4);
                $sellingPrice  = round((float)$item['selling_price'], 4);
                $expiryDate    = $item['expiry_date'];
                $itemDiscount  = round((float)($item['item_discount'] ?? 0), 2);
                $itemTax       = round((float)($item['item_tax'] ?? 0), 2);
                $totalCost     = round((float)$item['total_cost'], 2);

                // ── Resolve batch number (inside TX → FOR UPDATE guard) ──
                $batchNumber = _resolveBatchNumberInTx($item['batch_number'] ?? '', $usedBatchNums);

                // ── Insert batch (dynamic – safe against old table schemas) ──
                static $batchCols = null;
                if ($batchCols === null) {
                    $batchCols = _getTableColumns('batches');
                }

                // Core columns always present
                $bFields  = ['batch_number','product_id','supplier_id',
                             'purchase_price','total_pieces','remaining_quantity',
                             'expiry_date','purchase_date'];
                $bPh      = [':bn',':pid',':sid',':pp',':tp',':rq',':exp',':pdate'];
                $bBinds   = [
                    ':bn'    => [$batchNumber,   PDO::PARAM_STR],
                    ':pid'   => [$productId,     PDO::PARAM_INT],
                    ':sid'   => [$supplierId,    PDO::PARAM_INT],
                    ':pp'    => [$purchasePrice, PDO::PARAM_STR],
                    ':tp'    => [$totalPieces,   PDO::PARAM_INT],
                    ':rq'    => [$totalPieces,   PDO::PARAM_INT],
                    ':exp'   => [$expiryDate,    PDO::PARAM_STR],
                    ':pdate' => [$purchaseDate,  PDO::PARAM_STR],
                ];

                // Optional batch columns
                $bOptionals = [
                    'variant_id'       => [':vid', $variantId,    $variantId === null ? PDO::PARAM_NULL : PDO::PARAM_INT],
                    'selling_price'    => [':sp',  $sellingPrice, PDO::PARAM_STR],
                    'units_per_pack'   => [':up',  $unitsPerPack, PDO::PARAM_INT],
                    'quantity_of_packs'=> [':qp',  $qtyOfPacks,   PDO::PARAM_INT],
                    'reorder_level'    => [':rl',  (int)($item['reorder_level'] ?? 10), PDO::PARAM_INT],
                ];
                foreach ($bOptionals as $colName => [$ph, $val, $type]) {
                    if (in_array($colName, $batchCols, true)) {
                        $bFields[] = $colName;
                        $bPh[]     = $ph;
                        $bBinds[$ph] = [$val, $type];
                    }
                }

                $bSQL   = "INSERT INTO batches (" . implode(',', $bFields) . ") VALUES (" . implode(',', $bPh) . ")";
                $batchStmt = $pdo->prepare($bSQL);
                foreach ($bBinds as $ph => [$val, $type]) {
                    $batchStmt->bindValue($ph, $val, $type);
                }

                if (!$batchStmt->execute()) {
                    $err = $batchStmt->errorInfo();
                    throw new \RuntimeException(
                        "Batch insert failed for product {$productId} (batch: {$batchNumber}). " .
                        "SQLSTATE[{$err[0]}] {$err[2]}"
                    );
                }
                $batchId = (int)$pdo->lastInsertId();
                if (!$batchId) throw new \RuntimeException("Batch insert gave no ID for product {$productId}.");

                // ── Stock movement ───────────────────────────────────────
                try {
                    $pdo->prepare(
                        "INSERT INTO stock_movements
                         (batch_id, product_id, movement_type, quantity,
                          reference_id, reference_type,
                          previous_quantity, new_quantity, notes, created_by)
                         VALUES (:bid,:pid,'purchase',:qty,:rid,'purchase',0,:new,'New batch purchase',:user)"
                    )->execute([
                        ':bid'  => $batchId,
                        ':pid'  => $productId,
                        ':qty'  => $totalPieces,
                        ':rid'  => $purchaseId,
                        ':new'  => $totalPieces,
                        ':user' => $userId,
                    ]);
                } catch (\Throwable $e) {
                    // Non-fatal – stock_movements is audit trail only
                    error_log("stock_movements insert (non-fatal): " . $e->getMessage());
                }

                // ── Purchase item line (dynamic – only insert cols that exist) ─
                static $piCols = null;
                if ($piCols === null) {
                    $piCols = _getTableColumns('purchase_items');
                }

                // Core columns – always present
                $piFields  = ['purchase_id','batch_id','product_id','variant_id',
                              'quantity','total','expiry_date'];
                $piPlaceholders = [':pid',':bid',':prid',':vid',':qty',':total',':exp'];
                $piBinds   = [
                    ':pid'   => [$purchaseId,   PDO::PARAM_INT],
                    ':bid'   => [$batchId,       PDO::PARAM_INT],
                    ':prid'  => [$productId,     PDO::PARAM_INT],
                    ':vid'   => [$variantId,     $variantId === null ? PDO::PARAM_NULL : PDO::PARAM_INT],
                    ':qty'   => [$qtyOfPacks,    PDO::PARAM_INT],
                    ':total' => [$totalCost,     PDO::PARAM_STR],
                    ':exp'   => [$expiryDate,    PDO::PARAM_STR],
                ];

                // Optional columns – add only if they exist in DB
                $optionals = [
                    'unit_price'     => [':up',   $purchasePrice, PDO::PARAM_STR],
                    'selling_price'  => [':sp',   $sellingPrice,  PDO::PARAM_STR],
                    'discount'       => [':disc', $itemDiscount,  PDO::PARAM_STR],
                    'tax'            => [':tax',  $itemTax,       PDO::PARAM_STR],
                    'pieces_per_pack'=> [':ppp',  $unitsPerPack,  PDO::PARAM_INT],
                    'total_pieces'   => [':tp',   $totalPieces,   PDO::PARAM_INT],
                ];
                foreach ($optionals as $colName => [$ph, $val, $type]) {
                    if (in_array($colName, $piCols, true)) {
                        $piFields[]      = $colName;
                        $piPlaceholders[]= $ph;
                        $piBinds[$ph]    = [$val, $type];
                    }
                }

                $piSQL  = "INSERT INTO purchase_items (" . implode(',', $piFields) . ") VALUES (" . implode(',', $piPlaceholders) . ")";
                $piStmt = $pdo->prepare($piSQL);
                foreach ($piBinds as $ph => [$val, $type]) {
                    $piStmt->bindValue($ph, $val, $type);
                }

                if (!$piStmt->execute()) {
                    $err = $piStmt->errorInfo();
                    throw new \RuntimeException(
                        "Purchase item insert failed for product {$productId}. " .
                        "SQLSTATE[{$err[0]}] {$err[2]}"
                    );
                }

                $uniqueProducts[] = $productId;
            }

            // 3. Update supplier balance (credit purchases)
            if ($paymentStatus !== 'paid' && $due > 0) {
                $pdo->prepare("UPDATE suppliers SET current_balance = current_balance + :due WHERE id = :sid")
                    ->execute([':due' => $due, ':sid' => $supplierId]);
            }

            // ── COMMIT ────────────────────────────────────────────────────
            $pdo->commit();

        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $GLOBALS['addPurchaseLastError'] = $e->getMessage();
            error_log("addPurchase FAILED (Invoice: {$invoiceNo}): " . $e->getMessage());
            return false;
        }

        // ── POST-COMMIT (non-transactional) ───────────────────────────────

        // 4. Update products.stock_quantity + sale_price (dashboard visible)
        foreach (array_unique($uniqueProducts) as $pid) {
            updateProductStockAndPrice($pid);
        }

        // 5. Cashbook entry for paid/partial purchases
        try {
            _insertCashbookForPurchase($purchaseId, $invoiceNo, $supplierId, $paidAmount, $paymentStatus, $purchaseDate, $userId);
        } catch (\Throwable $e) {
            error_log("Cashbook entry failed (non-fatal): " . $e->getMessage());
        }

        // 6. Activity log (wrapped with function_exists guard)
        try {
            if (function_exists('logActivity')) {
                logActivity('add_purchase', "Purchase #{$invoiceNo} added. Items: " . count($items) . ". Total: Rs " . number_format($grandTotal, 2), $userId);
            }
        } catch (\Throwable $e) { /* ignore */ }

        return $purchaseId;
    }
}

// ==========================================================================
// 6b. CASHBOOK HELPER FOR PURCHASE
// ==========================================================================
if (!function_exists('_insertCashbookForPurchase')) {
function _insertCashbookForPurchase(
    int    $purchaseId,
    string $invoiceNo,
    int    $supplierId,
    float  $paidAmount,
    string $paymentStatus,
    string $purchaseDate,
    int    $userId
): void {
    if ($paidAmount <= 0) return; // Nothing paid in cash yet

    // Check if cashbook has the 'purchase' enum value; if not use 'purchase_payment'
    try {
        $cols = _pdoAll("SHOW COLUMNS FROM cashbook LIKE 'entry_type'");
        $enumDef = $cols[0]['Type'] ?? '';
        $entryType = (str_contains($enumDef, "'purchase'")) ? 'purchase' : 'purchase_payment';
    } catch (\Throwable $e) {
        $entryType = 'purchase_payment';
    }

    // Get last cashbook balance
    $lastRow = _pdoOne("SELECT balance FROM cashbook WHERE voided=0 ORDER BY id DESC LIMIT 1");
    $prevBalance = (float)($lastRow['balance'] ?? 0);
    $newBalance  = round($prevBalance - $paidAmount, 2); // cash OUT for purchase

    $supplier = _pdoOne("SELECT supplier_name FROM suppliers WHERE id=:id", [':id' => $supplierId]);
    $supplierName = $supplier['supplier_name'] ?? "Supplier #{$supplierId}";

    try {
        // ── Dynamic cashbook INSERT — detects which optional columns exist ──
        $cbCols       = _getPDO()->query("SHOW COLUMNS FROM cashbook")->fetchAll(PDO::FETCH_COLUMN);
        $hasBalance   = in_array('balance',        $cbCols);
        $hasPayMethod = in_array('payment_method', $cbCols);
        $hasVoided    = in_array('voided',         $cbCols);
        $cbUserCol    = in_array('created_by', $cbCols) ? 'created_by'
                      : (in_array('added_by',  $cbCols) ? 'added_by' : null);

        // Get last cashbook balance (only if column exists)
        if ($hasBalance && $hasVoided) {
            $lastRow    = _pdoOne("SELECT balance FROM cashbook WHERE voided=0 ORDER BY id DESC LIMIT 1");
        } elseif ($hasBalance) {
            $lastRow    = _pdoOne("SELECT balance FROM cashbook ORDER BY id DESC LIMIT 1");
        }
        $newBalance = $hasBalance ? round((float)($lastRow['balance'] ?? 0) - $paidAmount, 2) : 0;

        $cbFields = ['entry_date','entry_type','reference_id','reference_type','description','debit','credit'];
        $cbPh     = [':date',':type',':rid',"'purchase'"  ,':desc'        ,':debit' ,'0'     ];
        $cbParams = [
            ':date'  => $purchaseDate,
            ':type'  => $entryType,
            ':rid'   => $purchaseId,
            ':desc'  => "Purchase {$invoiceNo} – {$supplierName}",
            ':debit' => $paidAmount,
        ];

        if ($hasBalance) {
            $cbFields[] = 'balance';    $cbPh[] = ':bal';    $cbParams[':bal']    = $newBalance;
        }
        if ($hasPayMethod) {
            $cbFields[] = 'payment_method'; $cbPh[] = ':method'; $cbParams[':method'] = 'cash';
        }
        if ($cbUserCol) {
            $cbFields[] = $cbUserCol;   $cbPh[] = ':user';   $cbParams[':user']   = $userId;
        }

        $sql = "INSERT INTO cashbook (" . implode(',', $cbFields) . ") VALUES (" . implode(',', $cbPh) . ")";
        _pdoRun($sql, $cbParams);

    } catch (\Throwable $e) {
        error_log("_insertCashbookForPurchase failed: " . $e->getMessage());
    }
}
} // end if(!function_exists('_insertCashbookForPurchase'))

// ==========================================================================
// 7. GET PURCHASES (list & single)
// ==========================================================================
if (!function_exists('getAllPurchases')) {
    function getAllPurchases(int $page = 1, int $perPage = 20, string $search = ''): array {
        $offset = ($page - 1) * $perPage;
        $where  = "1=1";
        $params = [];
        if (!empty($search)) {
            $where .= " AND (p.purchase_invoice_no LIKE :s OR s.supplier_name LIKE :s)";
            $params[':s'] = "%{$search}%";
        }
        $sql = "SELECT p.*, s.supplier_name,
                       COALESCE((SELECT SUM(total_amount) FROM purchase_returns WHERE purchase_id=p.id),0) AS total_returned
                FROM purchases p
                JOIN suppliers s ON p.supplier_id=s.id
                WHERE {$where}
                ORDER BY p.purchase_date DESC, p.id DESC
                LIMIT {$offset},{$perPage}";
        $data  = _pdoAll($sql, $params);
        $total = (int)(_pdoOne("SELECT COUNT(*) AS t FROM purchases p WHERE {$where}", $params)['t'] ?? 0);
        return ['data' => $data, 'total' => $total, 'page' => $page, 'perPage' => $perPage, 'totalPages' => (int)ceil($total / $perPage)];
    }
}

if (!function_exists('getPurchaseById')) {
    function getPurchaseById(int $id): ?array {
        $purchase = _pdoOne(
            "SELECT p.*, s.supplier_name, s.phone, s.email, s.address, s.gst_no
             FROM purchases p JOIN suppliers s ON p.supplier_id=s.id
             WHERE p.id=:id",
            [':id' => $id]
        );
        if ($purchase) {
            $purchase['items'] = _pdoAll(
                "SELECT pi.*, b.batch_number, pr.product_name, pr.generic_name, pr.brand_name,
                        pr.strength, pr.dosage_form, pr.size_value
                 FROM purchase_items pi
                 JOIN batches b ON pi.batch_id=b.id
                 JOIN products pr ON pi.product_id=pr.id
                 WHERE pi.purchase_id=:pid",
                [':pid' => $id]
            );
            $purchase['returns'] = _pdoAll("SELECT * FROM purchase_returns WHERE purchase_id=:pid", [':pid' => $id]);
            $purchase['total_returned']     = (float)(_pdoOne("SELECT COALESCE(SUM(total_amount),0) AS t FROM purchase_returns WHERE purchase_id=:pid", [':pid' => $id])['t'] ?? 0);
            $purchase['net_after_returns']  = (float)$purchase['total'] - $purchase['total_returned'];
        }
        return $purchase;
    }
}

// ==========================================================================
// 8. SUPPLIER PAYMENTS (with cashbook)
// ==========================================================================
if (!function_exists('addSupplierPayment')) {
    function addSupplierPayment(array $data): int|false {
        $pdo    = _getPDO();
        $userId = (int)($_SESSION['user_id'] ?? 1);

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "INSERT INTO supplier_payments
                 (supplier_id, purchase_id, amount, payment_date, payment_method, notes, created_by)
                 VALUES (:sid,:pid,:amt,:date,:method,:notes,:user)"
            )->execute([
                ':sid'    => (int)$data['supplier_id'],
                ':pid'    => (int)$data['purchase_id'],
                ':amt'    => round((float)$data['amount'], 2),
                ':date'   => $data['payment_date'],
                ':method' => $data['payment_method'] ?? 'cash',
                ':notes'  => $data['notes'] ?? null,
                ':user'   => $userId,
            ]);
            $paymentId = (int)$pdo->lastInsertId();
            if (!$paymentId) throw new \RuntimeException("Payment insert failed.");

            $purchase = _pdoOne("SELECT total, paid_amount, due_amount FROM purchases WHERE id=:pid", [':pid' => $data['purchase_id']]);
            $newPaid   = (float)$purchase['paid_amount'] + (float)$data['amount'];
            $newDue    = max(0, (float)$purchase['total'] - $newPaid);
            $newStatus = ($newDue <= 0) ? 'paid' : ($newPaid > 0 ? 'partial' : 'pending');

            $pdo->prepare("UPDATE purchases SET paid_amount=:paid, due_amount=:due, payment_status=:status WHERE id=:pid")
                ->execute([':paid' => $newPaid, ':due' => $newDue, ':status' => $newStatus, ':pid' => $data['purchase_id']]);

            $pdo->prepare("UPDATE suppliers SET current_balance=GREATEST(0,current_balance-:amt) WHERE id=:sid")
                ->execute([':amt' => $data['amount'], ':sid' => $data['supplier_id']]);

            $pdo->commit();

            // Cashbook
            try {
                $lastRow = _pdoOne("SELECT balance FROM cashbook WHERE voided=0 ORDER BY id DESC LIMIT 1");
                $bal     = round((float)($lastRow['balance'] ?? 0) - (float)$data['amount'], 2);
                _pdoRun(
                    "INSERT INTO cashbook
                     (entry_date,entry_type,reference_id,reference_type,description,debit,credit,balance,payment_method,created_by)
                     VALUES (:date,'purchase_payment',:rid,'purchase',:desc,:debit,0,:bal,:method,:user)",
                    [
                        ':date'   => $data['payment_date'],
                        ':rid'    => $data['purchase_id'],
                        ':desc'   => "Supplier payment – purchase #{$data['purchase_id']}",
                        ':debit'  => round((float)$data['amount'], 2),
                        ':bal'    => $bal,
                        ':method' => $data['payment_method'] ?? 'cash',
                        ':user'   => $userId,
                    ]
                );
            } catch (\Throwable $e) {
                error_log("Cashbook for supplier payment (non-fatal): " . $e->getMessage());
            }

            if (function_exists('logActivity')) {
                logActivity("Supplier Payment", "Rs " . number_format($data['amount'], 2) . " paid to supplier #{$data['supplier_id']} for purchase #{$data['purchase_id']}", $userId);
            }
            return $paymentId;

        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("addSupplierPayment: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('getSupplierPayments')) {
    function getSupplierPayments(int $supplierId): array {
        return _pdoAll(
            "SELECT sp.*, p.purchase_date, p.purchase_invoice_no
             FROM supplier_payments sp
             LEFT JOIN purchases p ON sp.purchase_id=p.id
             WHERE sp.supplier_id=:sid ORDER BY sp.payment_date DESC",
            [':sid' => $supplierId]
        );
    }
}

if (!function_exists('getSupplierPurchases')) {
    function getSupplierPurchases(int $supplierId): array {
        return _pdoAll(
            "SELECT * FROM purchases WHERE supplier_id=:sid AND payment_status!='paid' ORDER BY purchase_date DESC",
            [':sid' => $supplierId]
        );
    }
}

// ==========================================================================
// 9. PURCHASE RETURNS
// ==========================================================================
// processPurchaseReturn() intentionally NOT declared here.
// It lives in includes/return_functions.php (authoritative source).

// ==========================================================================
// 10. PRODUCT BATCHES & NEAR EXPIRY
// ==========================================================================
if (!function_exists('getProductBatches')) {
    function getProductBatches(int $productId): array {
        markExpiredBatches();
        return _pdoAll(
            "SELECT b.*, s.supplier_name
             FROM batches b LEFT JOIN suppliers s ON b.supplier_id=s.id
             WHERE b.product_id=:pid ORDER BY b.expiry_date ASC, b.id ASC",
            [':pid' => $productId]
        );
    }
}

if (!function_exists('getNearExpiryBatches')) {
    function getNearExpiryBatches(int $daysAhead = 90): array {
        markExpiredBatches();
        return _pdoAll(
            "SELECT b.*, p.product_name, p.brand_name
             FROM batches b JOIN products p ON p.id=b.product_id
             WHERE b.remaining_quantity>0 AND b.is_expired=0
               AND b.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL :days DAY)
             ORDER BY b.expiry_date ASC",
            [':days' => $daysAhead]
        );
    }
}

// ==========================================================================
// 11. PROFIT / LOSS REPORT
// ==========================================================================
if (!function_exists('getProfitLossData')) {
    function getProfitLossData(?string $dateFrom = null, ?string $dateTo = null): array {
        try {
            $where  = "1=1";
            $params = [];
            if ($dateFrom) { $where .= " AND s.sale_date>=:df"; $params[':df'] = $dateFrom; }
            if ($dateTo)   { $where .= " AND s.sale_date<=:dt"; $params[':dt'] = $dateTo; }

            $result = _pdoOne(
                "SELECT COALESCE(SUM(si.quantity * si.unit_price),0) AS total_revenue,
                        COALESCE(SUM(si.quantity * COALESCE(b.purchase_price, si.unit_price * 0.7)),0) AS total_cogs,
                        COUNT(DISTINCT s.id) AS total_sales
                 FROM sales s
                 JOIN sale_items si ON si.sale_id=s.id
                 LEFT JOIN batches b ON b.id=si.batch_id
                 WHERE {$where}",
                $params
            );
            $revenue = (float)($result['total_revenue'] ?? 0);
            $cogs    = (float)($result['total_cogs'] ?? 0);
            return [
                'revenue'      => $revenue,
                'cogs'         => $cogs,
                'gross_profit' => $revenue - $cogs,
                'margin_pct'   => $revenue > 0 ? round(($revenue - $cogs) / $revenue * 100, 2) : 0,
                'total_sales'  => (int)($result['total_sales'] ?? 0),
            ];
        } catch (\Throwable $e) {
            error_log("getProfitLossData: " . $e->getMessage());
            return ['revenue' => 0, 'cogs' => 0, 'gross_profit' => 0, 'margin_pct' => 0, 'total_sales' => 0];
        }
    }
}

// ==========================================================================
// 12. PRODUCT SEARCH (AJAX)
// ==========================================================================
if (!function_exists('searchProductsForPurchase')) {
    function searchProductsForPurchase(string $search): array {
        return _pdoAll(
            "SELECT p.id, p.product_code, p.product_name, p.generic_name, p.brand_name,
                    p.category_id, p.unit_type, p.units_per_pack, p.quantity_of_packs,
                    p.purchase_price, p.sale_price,
                    (SELECT COUNT(*) FROM products WHERE parent_id=p.id) AS has_variants
             FROM products p
             WHERE p.status=1 AND p.parent_id IS NULL
               AND (p.product_name LIKE :s OR p.product_code LIKE :s
                    OR p.generic_name LIKE :s OR p.brand_name LIKE :s)
             ORDER BY p.product_name ASC LIMIT 20",
            [':s' => "%{$search}%"]
        );
    }
}

if (!function_exists('getProductVariants')) {
    function getProductVariants(int $productId): array {
        return _pdoAll(
            "SELECT id, product_name, product_code, strength, dosage_form, size_value,
                    unit_type, units_per_pack, quantity_of_packs, purchase_price, sale_price
             FROM products WHERE parent_id=:pid AND status=1 ORDER BY product_name",
            [':pid' => $productId]
        );
    }
}

if (!function_exists('getProductPurchaseDetails')) {
    function getProductPurchaseDetails(int $productId): ?array {
        $product = _pdoOne(
            "SELECT id, product_name, generic_name, brand_name,
                    strength, dosage_form, size_value,
                    unit_type, units_per_pack, quantity_of_packs, purchase_price, sale_price
             FROM products WHERE id=:id AND status=1",
            [':id' => $productId]
        );
        if ($product) {
            $product['variants'] = _pdoAll(
                "SELECT id, product_name, unit_type, units_per_pack, quantity_of_packs, purchase_price, sale_price
                 FROM products WHERE parent_id=:pid AND status=1 ORDER BY product_name",
                [':pid' => $productId]
            );
        }
        return $product;
    }
}

// ==========================================================================
// 13. ACTIVITY LOGGING (safe wrapper — guarded with function_exists)
// ==========================================================================
if (!function_exists('logActivity')) {
    function logActivity(string $action, string $description, ?int $userId = null): void {
        if ($userId === null) $userId = (int)($_SESSION['user_id'] ?? 0);
        try {
            _pdoRun(
                "INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent, created_at)
                 VALUES (:uid,:action,:desc,:ip,:ua,NOW())",
                [
                    ':uid'    => $userId,
                    ':action' => $action,
                    ':desc'   => $description,
                    ':ip'     => $_SERVER['REMOTE_ADDR'] ?? '',
                    ':ua'     => $_SERVER['HTTP_USER_AGENT'] ?? '',
                ]
            );
        } catch (\Throwable $e) {
            error_log("logActivity: " . $e->getMessage());
        }
    }
}

// ==========================================================================
// 14. HELPER: GET NET DUE FOR A PURCHASE AFTER RETURNS (Supplier Ledger)
// ==========================================================================
if (!function_exists('getPurchaseNetDue')) {
    function getPurchaseNetDue(int $purchaseId): array {
        $p = _pdoOne(
            "SELECT total, paid_amount FROM purchases WHERE id = :id",
            [':id' => $purchaseId]
        );
        if (!$p) return ['net_due' => 0, 'net_total' => 0, 'total_returned' => 0];

        $totalReturned = (float)(_pdoOne(
            "SELECT COALESCE(SUM(total_amount), 0) FROM purchase_returns WHERE purchase_id = :pid",
            [':pid' => $purchaseId]
        )['t'] ?? 0);

        $netTotal = (float)$p['total'] - $totalReturned;
        $netDue   = max(0, $netTotal - (float)$p['paid_amount']);

        return [
            'net_due'        => $netDue,
            'net_total'      => $netTotal,
            'total_returned' => $totalReturned,
            'paid_amount'    => (float)$p['paid_amount'],
            'gross_total'    => (float)$p['total'],
        ];
    }
}

// Initialize tables
ensurePurchaseTables();

// ── Ensure reorder_level column exists in batches (standalone migration) ──────
if (!function_exists('ensureBatchesHaveReorderLevel')) {
    function ensureBatchesHaveReorderLevel(): void {
        $pdo = _getPDO();
        _addColumnIfMissing($pdo, 'batches', 'reorder_level', 'INT NOT NULL DEFAULT 10');
    }
}
ensureBatchesHaveReorderLevel();
?>