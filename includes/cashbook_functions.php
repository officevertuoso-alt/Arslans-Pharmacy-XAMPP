<?php
/**
 * Cashbook Functions Library
 * includes/cashbook_functions.php
 *
 * COMPLETE FIXED VERSION — All business rules implemented:
 *
 * CASH IN (credit) triggers:
 *  - Sale (non-credit)              → cash_in via entry_type='sale'
 *  - Credit payment received        → cash_in via entry_type='credit_received'
 *  - Purchase return (paid was cash)→ cash_in via entry_type='purchase_return'
 *  - Stock adjust increase (new stock added) → cash_out (money spent on stock)
 *
 * CASH OUT (debit) triggers:
 *  - Purchase (non-credit)          → cash_out via entry_type='purchase_payment'
 *  - Expense                        → cash_out via entry_type='expense'
 *  - Expired stock loss             → cash_out via entry_type='expired_loss'
 *  - Sale return (customer paid cash, now refunded) → cash_out via entry_type='sale_return'
 *  - Stock adjust reduced (damaged etc) → cash_out via entry_type='stock_adjustment_loss'
 *  - Stock adjust increased         → cash_out via entry_type='stock_adjustment_purchase'
 *  - Manual cash out                → cash_out via entry_type='cash_out'
 *  - Dues paid to supplier          → cash_out via entry_type='dues_paid'
 *
 * NO ENTRY (credit/pending scenarios):
 *  - Sale on credit (credit_given)  → no cash flow, entry_type='credit_given' debit=0 credit=0 (record only)
 *  - Purchase on credit             → no cash flow, entry_type='purchase_credit' (record only)
 *  - Sale return where customer had pending dues → deduct from dues, no cashbook entry
 *  - Purchase return where purchase was on credit → no cashbook entry
 *
 * FIX: can_print column error — ensureCashbookTable() now handles schema
 *       gracefully. The actual fix is in config.php permission loader but
 *       we add a safe fallback here too.
 */

// ─────────────────────────────────────────────
// SAFE PERMISSION HELPER — avoids can_print column error
// ─────────────────────────────────────────────

if (!function_exists('canView')) {
    function canView(string $module): bool {
        // Safe fallback: check session role without querying modules table
        $role = $_SESSION['user_role'] ?? '';
        if ($role === 'admin') return true;
        // Try DB query safely (won't crash if column missing)
        try {
            $db  = db();
            $pdo = $db->getConnection();
            // Check which columns exist to build safe query
            $cols = $pdo->query("SHOW COLUMNS FROM modules")->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('module_key', $cols) && in_array('is_active', $cols)) {
                $row = $db->getOne(
                    "SELECT id FROM modules WHERE module_key = :m AND is_active = 1",
                    [':m' => $module]
                );
                if (!$row) return false; // module disabled
            }
            // Check role_permissions table if it exists
            $tables = $pdo->query("SHOW TABLES LIKE 'role_permissions'")->fetchAll();
            if ($tables) {
                $perm = $db->getOne(
                    "SELECT can_view FROM role_permissions rp
                     JOIN modules m ON m.id = rp.module_id
                     WHERE m.module_key = :mod AND rp.role = :role",
                    [':mod' => $module, ':role' => $role]
                );
                if ($perm) return (bool)$perm['can_view'];
            }
        } catch (\Throwable $e) {
            // Fallback: allow admin/pharmacist
        }
        return in_array($role, ['admin', 'pharmacist'], true);
    }
}

// ─────────────────────────────────────────────
// TABLE EXISTENCE CHECK & SCHEMA UPGRADE
// ─────────────────────────────────────────────

/**
 * Ensure cashbook table exists with all required columns and ENUM values.
 * Safe to call multiple times — idempotent.
 */
function ensureCashbookTable(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $db  = db();
    $pdo = $db->getConnection();

    $stmt = $pdo->query("SHOW TABLES LIKE 'cashbook'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("
            CREATE TABLE `cashbook` (
                `id`                int(11)       NOT NULL AUTO_INCREMENT,
                `entry_date`        date          NOT NULL,
                `entry_type`        enum(
                    'sale',
                    'purchase_payment',
                    'purchase_credit',
                    'credit_received',
                    'credit_given',
                    'expense',
                    'sale_return',
                    'purchase_return',
                    'expired_loss',
                    'stock_adjustment_loss',
                    'stock_adjustment_purchase',
                    'dues_paid',
                    'cash_in',
                    'cash_out',
                    'opening',
                    'closing',
                    'adjustment'
                ) NOT NULL,
                `reference_id`      int(11)       DEFAULT NULL,
                `reference_type`    varchar(50)   DEFAULT NULL,
                `description`       varchar(500)  NOT NULL DEFAULT '',
                `debit`             decimal(12,2) NOT NULL DEFAULT 0.00,
                `credit`            decimal(12,2) NOT NULL DEFAULT 0.00,
                `balance`           decimal(12,2) NOT NULL DEFAULT 0.00,
                `payment_method`    enum('cash','card','online','cheque','bank','credit_note','adjustment') DEFAULT 'cash',
                `created_by`        int(11)       NOT NULL DEFAULT 0,
                `created_at`        timestamp     NOT NULL DEFAULT current_timestamp(),
                `voided`            tinyint(1)    NOT NULL DEFAULT 0,
                `voided_reason`     varchar(255)  DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_date`      (`entry_date`),
                KEY `idx_type`      (`entry_type`),
                KEY `idx_ref`       (`reference_id`, `reference_type`),
                KEY `idx_voided`    (`voided`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        return;
    }

    // Table exists — upgrade columns if needed
    try {
        // Add voided columns if missing
        $cols = array_column(
            $pdo->query("SHOW COLUMNS FROM cashbook")->fetchAll(PDO::FETCH_ASSOC),
            'Field'
        );
        if (!in_array('voided', $cols)) {
            $pdo->exec("ALTER TABLE cashbook ADD COLUMN voided TINYINT(1) NOT NULL DEFAULT 0");
        }
        if (!in_array('voided_reason', $cols)) {
            $pdo->exec("ALTER TABLE cashbook ADD COLUMN voided_reason VARCHAR(255) DEFAULT NULL");
        }

        // Upgrade entry_type ENUM to include all new types
        $enumRow = $pdo->query("SHOW COLUMNS FROM cashbook WHERE Field = 'entry_type'")->fetch(PDO::FETCH_ASSOC);
        $needsNewTypes = $enumRow && (
            strpos($enumRow['Type'], 'stock_adjustment_loss') === false
            || strpos($enumRow['Type'], 'dues_paid') === false
            || strpos($enumRow['Type'], 'adjustment') === false
        );
        if ($needsNewTypes) {
            $pdo->exec("ALTER TABLE cashbook MODIFY COLUMN entry_type ENUM(
                'sale','purchase_payment','purchase_credit','credit_received','credit_given',
                'expense','sale_return','purchase_return','expired_loss',
                'stock_adjustment_loss','stock_adjustment_purchase',
                'dues_paid','cash_in','cash_out','opening','closing','adjustment'
            ) NOT NULL");
        }

        // Upgrade payment_method ENUM if needed
        $pmRow = $pdo->query("SHOW COLUMNS FROM cashbook WHERE Field = 'payment_method'")->fetch(PDO::FETCH_ASSOC);
        if ($pmRow && strpos($pmRow['Type'], 'adjustment') === false) {
            $pdo->exec("ALTER TABLE cashbook MODIFY COLUMN payment_method
                ENUM('cash','card','online','cheque','bank','credit_note','adjustment') DEFAULT 'cash'");
        }
    } catch (\Throwable $e) {
        error_log("[Cashbook] Schema upgrade failed: " . $e->getMessage());
    }
}

// ─────────────────────────────────────────────
// CORE: ADD ENTRY
// ─────────────────────────────────────────────

/**
 * Add a cashbook entry with duplicate prevention and running-balance update.
 *
 * Business rules encoded here:
 *  entry_type          | debit (out) | credit (in)
 *  --------------------|-------------|------------
 *  sale                |     0       |  amount paid
 *  credit_given        |     0       |     0       (no cash – record only)
 *  credit_received     |     0       |  amount received
 *  purchase_payment    |  amount paid|     0
 *  purchase_credit     |     0       |     0       (no cash – record only)
 *  dues_paid           |  amount paid|     0       (paying supplier pending dues)
 *  expense             |  amount     |     0
 *  sale_return         |  refund amt |     0       (cash paid back to customer)
 *  purchase_return     |     0       |  refund amt (cash received from supplier)
 *  expired_loss        |  loss value |     0
 *  stock_adjustment_loss    | value  |     0       (damaged/reduced stock)
 *  stock_adjustment_purchase| value  |     0       (new stock added = money spent)
 *  cash_in             |     0       |  amount
 *  cash_out            |  amount     |     0
 *  opening             |     0       |  amount
 *  adjustment          |  per direction             |
 *
 * @param string      $entryType
 * @param int|null    $referenceId
 * @param string|null $referenceType
 * @param string      $description
 * @param float       $debit          Cash OUT
 * @param float       $credit         Cash IN
 * @param string      $paymentMethod
 * @param int         $userId
 * @param string|null $entryDate      Y-m-d, default today
 * @param bool        $preventDuplicate
 * @return int|false  New or existing entry ID
 */
function addCashbookEntry(
    string  $entryType,
    ?int    $referenceId,
    ?string $referenceType,
    string  $description,
    float   $debit = 0.00,
    float   $credit = 0.00,
    string  $paymentMethod = 'cash',
    int     $userId = 0,
    ?string $entryDate = null,
    bool    $preventDuplicate = true
): int|false {
    ensureCashbookTable();

    $entryDate = $entryDate ?? date('Y-m-d');
    $userId    = $userId ?: (int)($_SESSION['user_id'] ?? 0);
    $debit     = round(max(0, $debit), 2);
    $credit    = round(max(0, $credit), 2);

    // Duplicate guard
    if ($preventDuplicate && $referenceId !== null && $referenceType !== null) {
        $existing = getOne(
            "SELECT id FROM cashbook
             WHERE reference_id   = :ref_id
               AND reference_type = :ref_type
               AND entry_type     = :etype
               AND voided         = 0",
            [':ref_id' => $referenceId, ':ref_type' => $referenceType, ':etype' => $entryType]
        );
        if ($existing) return (int)$existing['id'];
    }

    $db  = db();
    $sql = "INSERT INTO cashbook
                (entry_date, entry_type, reference_id, reference_type, description,
                 debit, credit, payment_method, created_by)
            VALUES
                (:entry_date, :entry_type, :ref_id, :ref_type, :description,
                 :debit, :credit, :payment_method, :created_by)";

    $stmt = $db->prepare($sql);
    $ok   = $stmt->execute([
        ':entry_date'     => $entryDate,
        ':entry_type'     => $entryType,
        ':ref_id'         => $referenceId,
        ':ref_type'       => $referenceType,
        ':description'    => $description,
        ':debit'          => $debit,
        ':credit'         => $credit,
        ':payment_method' => $paymentMethod,
        ':created_by'     => $userId,
    ]);

    if (!$ok) return false;

    $newId = (int)$db->lastInsertId();
    _recalculateRunningBalance($entryDate);
    return $newId;
}

// ─────────────────────────────────────────────
// BUSINESS-LOGIC ENTRY POINTS
// Called from sales.php, purchases.php, returns, expenses, stock_adjust, etc.
// ─────────────────────────────────────────────

/**
 * Record a SALE.
 * - Non-credit → cash_in (credit column)
 * - Credit sale → credit_given, debit=0 credit=0 (no cash movement, record only)
 */
function recordSaleCashbook(
    int    $saleId,
    float  $amount,
    bool   $isCredit,
    string $paymentMethod = 'cash',
    string $description   = '',
    string $date          = ''
): int|false {
    if ($isCredit) {
        // Credit sale: no cash changes, just record
        return addCashbookEntry(
            'credit_given', $saleId, 'sale',
            $description ?: "Credit Sale #$saleId (raqam baad mein milegi)",
            0, 0, 'credit_note',
            0, $date ?: date('Y-m-d')
        );
    }
    // Cash/card/online sale → money received
    return addCashbookEntry(
        'sale', $saleId, 'sale',
        $description ?: "Sale #$saleId — Cash Received",
        0, $amount, $paymentMethod,
        0, $date ?: date('Y-m-d')
    );
}

/**
 * Record CREDIT PAYMENT RECEIVED (customer paying their dues).
 * Always cash_in.
 */
function recordCreditReceivedCashbook(
    int    $paymentId,
    float  $amount,
    string $paymentMethod = 'cash',
    string $description   = '',
    string $date          = ''
): int|false {
    return addCashbookEntry(
        'credit_received', $paymentId, 'credit_payment',
        $description ?: "Credit Payment Received — Payment #$paymentId",
        0, $amount, $paymentMethod,
        0, $date ?: date('Y-m-d')
    );
}

/**
 * Record a PURCHASE.
 * - Paid (cash/card/bank) → cash_out (debit)
 * - Pending/credit → purchase_credit, debit=0 credit=0 (no cash, record only)
 */
function recordPurchaseCashbook(
    int    $purchaseId,
    float  $amountPaid,
    string $paymentStatus,   // 'paid','partial','pending','credit'
    string $paymentMethod = 'cash',
    string $description   = '',
    string $date          = ''
): int|false {
    if (in_array($paymentStatus, ['pending', 'credit'], true)) {
        return addCashbookEntry(
            'purchase_credit', $purchaseId, 'purchase',
            $description ?: "Credit Purchase #$purchaseId (bعد mein dena hai)",
            0, 0, 'credit_note',
            0, $date ?: date('Y-m-d')
        );
    }
    return addCashbookEntry(
        'purchase_payment', $purchaseId, 'purchase',
        $description ?: "Purchase #$purchaseId — Cash Paid",
        $amountPaid, 0, $paymentMethod,
        0, $date ?: date('Y-m-d')
    );
}

/**
 * Record SUPPLIER DUES PAID (pending purchase later paid).
 * cash_out → dues_paid.
 */
function recordDuesPaidCashbook(
    int    $paymentId,
    int    $purchaseId,
    float  $amount,
    string $paymentMethod = 'cash',
    string $description   = '',
    string $date          = ''
): int|false {
    return addCashbookEntry(
        'dues_paid', $paymentId, 'supplier_payment',
        $description ?: "Supplier Dues Paid — Purchase #$purchaseId",
        $amount, 0, $paymentMethod,
        0, $date ?: date('Y-m-d')
    );
}

/**
 * Record an EXPENSE. Always cash_out.
 */
function recordExpenseCashbook(
    int    $expenseId,
    float  $amount,
    string $category,
    string $paymentMethod = 'cash',
    string $date          = ''
): int|false {
    return addCashbookEntry(
        'expense', $expenseId, 'expense',
        "Expense — $category",
        $amount, 0, $paymentMethod,
        0, $date ?: date('Y-m-d')
    );
}

/**
 * Record SALE RETURN.
 *
 * Rules:
 * - Customer had paid cash originally → refund cash back → cash_out (sale_return)
 * - Customer had credit sale (dues pending) → deduct from customer dues, NO cashbook entry
 * - Customer returns item but it was already paid → cash_out
 *
 * @param int    $returnId
 * @param float  $refundAmount
 * @param bool   $wasCredit      true if original sale was on credit (dues pending)
 * @param bool   $customerPaid   if wasCredit=false, customer is getting cash back
 * @param string $refundMethod   'cash','bank','none'
 * @param string $description
 * @param string $date
 */
function recordSaleReturnCashbook(
    int    $returnId,
    float  $refundAmount,
    bool   $wasCredit,
    string $refundMethod  = 'cash',
    string $description   = '',
    string $date          = ''
): int|false {
    // If original sale was on credit and customer had pending dues
    // → adjust dues only, no cash movement
    if ($wasCredit) {
        return false; // Handled by credit system — no cashbook entry
    }
    // Paid sale returned → refund cash out
    if ($refundAmount > 0 && $refundMethod !== 'none') {
        return addCashbookEntry(
            'sale_return', $returnId, 'sale_return',
            $description ?: "Sale Return #$returnId — Refund Paid to Customer",
            $refundAmount, 0, $refundMethod,
            0, $date ?: date('Y-m-d')
        );
    }
    return false;
}

/**
 * Record PURCHASE RETURN.
 *
 * Rules:
 * - Purchase was paid → supplier returns money → cash_in (purchase_return)
 * - Purchase was on credit/pending → no money was given → no cashbook entry
 *
 * @param int    $returnId
 * @param float  $refundAmount
 * @param bool   $wasCreditPurchase   true if original purchase was credit/pending
 * @param string $refundMethod
 * @param string $description
 * @param string $date
 */
function recordPurchaseReturnCashbook(
    int    $returnId,
    float  $refundAmount,
    bool   $wasCreditPurchase,
    string $refundMethod = 'cash',
    string $description  = '',
    string $date         = ''
): int|false {
    // Credit purchase returned → no cash was given, no cash received back
    if ($wasCreditPurchase) {
        return false;
    }
    // Paid purchase returned → we get money back
    if ($refundAmount > 0 && $refundMethod !== 'none') {
        return addCashbookEntry(
            'purchase_return', $returnId, 'purchase_return',
            $description ?: "Purchase Return #$returnId — Refund Received from Supplier",
            0, $refundAmount, $refundMethod,
            0, $date ?: date('Y-m-d')
        );
    }
    return false;
}

/**
 * Record EXPIRED STOCK LOSS. Always cash_out.
 */
function recordExpiredStockLoss(
    float  $lossAmount,
    string $description,
    ?int   $userId  = null,
    string $date    = ''
): int|false {
    ensureCashbookTable();
    $userId = $userId ?: (int)($_SESSION['user_id'] ?? 1);
    if ($lossAmount <= 0) return false;

    return addCashbookEntry(
        'expired_loss', null, 'expired_loss',
        $description ?: "Expired Stock Loss — Rs " . number_format($lossAmount, 2),
        $lossAmount, 0, 'adjustment',
        $userId, $date ?: date('Y-m-d'),
        false  // allow multiple expired loss entries
    );
}

/**
 * Record STOCK ADJUSTMENT.
 *
 * Rules:
 * - Reduce stock (damaged/expired/shrinkage) → cash_out (stock_adjustment_loss)
 *   because stock value is lost
 * - Increase stock (new stock added via adjustment) → cash_out (stock_adjustment_purchase)
 *   because money was spent to acquire this stock (even if entered late)
 *
 * @param int    $adjustmentId
 * @param string $direction      'increase' | 'decrease'
 * @param float  $stockValue     Value of stock adjusted (qty × purchase_price)
 * @param string $reason         e.g. 'damaged', 'found', 'correction'
 * @param string $description
 * @param string $date
 */
function recordStockAdjustmentCashbook(
    int    $adjustmentId,
    string $direction,
    float  $stockValue,
    string $reason      = '',
    string $description = '',
    string $date        = ''
): int|false {
    if ($stockValue <= 0) return false;
    $date = $date ?: date('Y-m-d');

    if ($direction === 'decrease') {
        // Stock reduced (damaged, lost, expired via manual adjust)
        return addCashbookEntry(
            'stock_adjustment_loss', $adjustmentId, 'stock_adjustment',
            $description ?: "Stock Adjustment (Decrease) — $reason — Rs " . number_format($stockValue, 2),
            $stockValue, 0, 'adjustment',
            0, $date, false
        );
    } else {
        // Stock increased via adjustment → cash out (new stock = money spent)
        return addCashbookEntry(
            'stock_adjustment_purchase', $adjustmentId, 'stock_adjustment',
            $description ?: "Stock Adjustment (Increase) — $reason — Rs " . number_format($stockValue, 2),
            $stockValue, 0, 'adjustment',
            0, $date, false
        );
    }
}

// ─────────────────────────────────────────────
// BALANCE & SUMMARY
// ─────────────────────────────────────────────

/**
 * Recalculate running balance for all non-voided entries of a given date.
 */
function _recalculateRunningBalance(string $date): void {
    $db = db();

    // Opening = last recorded balance before this date
    $prev = getOne(
        "SELECT balance FROM cashbook
         WHERE entry_date < :date AND voided = 0
         ORDER BY entry_date DESC, created_at DESC, id DESC LIMIT 1",
        [':date' => $date]
    );
    $running = $prev ? (float)$prev['balance'] : 0.0;

    $entries = getAll(
        "SELECT id, credit, debit FROM cashbook
         WHERE entry_date = :date AND voided = 0
         ORDER BY created_at ASC, id ASC",
        [':date' => $date]
    );

    foreach ($entries as $e) {
        $running += (float)$e['credit'];
        $running -= (float)$e['debit'];
        $running  = round($running, 2);
        $db->run(
            "UPDATE cashbook SET balance = :bal WHERE id = :id",
            [':bal' => $running, ':id' => $e['id']]
        );
    }
}

/**
 * Compute correct opening balance for a date (recursive, walks back through history).
 */
function computeOpeningBalance(string $date): float {
    $db = db();
    $prevRow = $db->getOne(
        "SELECT MAX(entry_date) AS prev_date FROM cashbook WHERE entry_date < :date AND voided = 0",
        [':date' => $date]
    );
    if (!$prevRow || empty($prevRow['prev_date'])) return 0.0;
    $prev = $prevRow['prev_date'];

    $totals = $db->getOne(
        "SELECT
            COALESCE(SUM(credit), 0) AS total_in,
            COALESCE(SUM(debit),  0) AS total_out
         FROM cashbook
         WHERE entry_date = :d AND voided = 0",
        [':d' => $prev]
    );
    $prevIn  = (float)($totals['total_in']  ?? 0);
    $prevOut = (float)($totals['total_out'] ?? 0);
    return computeOpeningBalance($prev) + $prevIn - $prevOut;
}

// Alias for backward compat
function getOpeningBalance(string $date): float {
    ensureCashbookTable();
    return computeOpeningBalance($date);
}

/**
 * Get daily summary (totals + by-type breakdown).
 */
function getDailySummary(string $date): array {
    ensureCashbookTable();
    $opening  = computeOpeningBalance($date);
    $agg = getOne(
        "SELECT COALESCE(SUM(credit),0) AS total_in, COALESCE(SUM(debit),0) AS total_out
         FROM cashbook WHERE entry_date = :date AND voided = 0",
        [':date' => $date]
    );
    $totalIn  = $agg ? (float)$agg['total_in']  : 0.0;
    $totalOut = $agg ? (float)$agg['total_out'] : 0.0;
    $closing  = round($opening + $totalIn - $totalOut, 2);

    $types = getAll(
        "SELECT entry_type,
                COALESCE(SUM(credit),0) AS credit_sum,
                COALESCE(SUM(debit),0)  AS debit_sum
         FROM cashbook
         WHERE entry_date = :date AND voided = 0
         GROUP BY entry_type",
        [':date' => $date]
    );
    $byType = [];
    foreach ($types as $t) {
        $byType[$t['entry_type']] = [
            'credit' => (float)$t['credit_sum'],
            'debit'  => (float)$t['debit_sum'],
            'net'    => (float)$t['credit_sum'] - (float)$t['debit_sum']
        ];
    }

    return [
        'date'      => $date,
        'opening'   => $opening,
        'total_in'  => $totalIn,
        'total_out' => $totalOut,
        'closing'   => $closing,
        'net_flow'  => round($totalIn - $totalOut, 2),
        'by_type'   => $byType,
    ];
}

/**
 * Get date-range cashbook report (daily summaries).
 */
function getCashbookReport(string $fromDate, string $toDate): array {
    ensureCashbookTable();
    $dates = getAll(
        "SELECT DISTINCT entry_date FROM cashbook
         WHERE entry_date BETWEEN :from AND :to AND voided = 0
         ORDER BY entry_date ASC",
        [':from' => $fromDate, ':to' => $toDate]
    );
    $report = [];
    foreach ($dates as $d) {
        $report[] = getDailySummary($d['entry_date']);
    }
    return $report;
}

/**
 * Get all entries for a date with labels, badges, desc links.
 */
function getCashbookByType(string $date, ?string $entryType = null): array {
    ensureCashbookTable();
    $sql    = "SELECT c.*, u.full_name AS created_by_name
               FROM cashbook c
               LEFT JOIN users u ON u.id = c.created_by
               WHERE c.entry_date = :date AND c.voided = 0";
    $params = [':date' => $date];
    if ($entryType) {
        $sql .= " AND c.entry_type = :type";
        $params[':type'] = $entryType;
    }
    $sql .= " ORDER BY c.created_at ASC, c.id ASC";
    $entries = getAll($sql, $params);
    foreach ($entries as &$e) {
        $e['type_label']    = getCashbookEntryLabel($e['entry_type']);
        $e['badge_class']   = getCashbookBadgeClass($e['entry_type']);
        $e['reference_link']= getReferenceLink($e['entry_type'], $e['reference_id'], $e['reference_type']);
    }
    return $entries;
}

/**
 * Full daily cashbook with opening/closing synthetic rows.
 */
function getDailyCashbook(string $date): array {
    ensureCashbookTable();
    $opening = computeOpeningBalance($date);
    $entries = getCashbookByType($date, null);

    $result   = [];
    $result[] = [
        'id'             => null,
        'entry_type'     => 'opening',
        'type_label'     => 'Opening Balance',
        'description'    => 'Carry forward from previous day',
        'debit'          => 0.00,
        'credit'         => 0.00,
        'balance'        => $opening,
        'created_at'     => $date . ' 00:00:00',
        'payment_method' => '',
        'reference_link' => '',
        'badge_class'    => 'bg-secondary',
    ];

    foreach ($entries as $e) {
        $result[] = $e;
    }

    $lastBalance = $opening;
    foreach ($entries as $e) {
        $lastBalance += (float)$e['credit'];
        $lastBalance -= (float)$e['debit'];
        $lastBalance = round($lastBalance, 2);
    }

    $result[] = [
        'id'             => null,
        'entry_type'     => 'closing',
        'type_label'     => 'Closing Balance',
        'description'    => 'End of day balance',
        'debit'          => 0.00,
        'credit'         => 0.00,
        'balance'        => $lastBalance,
        'created_at'     => $date . ' 23:59:59',
        'payment_method' => '',
        'reference_link' => '',
        'badge_class'    => 'bg-primary',
    ];

    return $result;
}

/**
 * Get today's cash position (cash method only).
 */
function getTodayCashPosition(?string $date = null): array {
    $date = $date ?? date('Y-m-d');
    ensureCashbookTable();
    $row = getOne(
        "SELECT COALESCE(SUM(credit),0) AS cash_in, COALESCE(SUM(debit),0) AS cash_out
         FROM cashbook
         WHERE entry_date = :date AND payment_method = 'cash' AND voided = 0",
        [':date' => $date]
    );
    $in  = $row ? (float)$row['cash_in']  : 0.0;
    $out = $row ? (float)$row['cash_out'] : 0.0;
    return ['cash_in' => $in, 'cash_out' => $out, 'net_cash' => round($in - $out, 2)];
}

/**
 * Dashboard summary.
 */
function getCashbookSummaryForDashboard(string $date): array {
    ensureCashbookTable();
    $summary = getOne(
        "SELECT
            COALESCE(SUM(CASE WHEN entry_type = 'sale'              THEN credit ELSE 0 END), 0) AS sale_credit,
            COALESCE(SUM(CASE WHEN entry_type = 'credit_received'   THEN credit ELSE 0 END), 0) AS credit_received,
            COALESCE(SUM(CASE WHEN entry_type = 'purchase_return'   THEN credit ELSE 0 END), 0) AS purchase_return_credit,
            COALESCE(SUM(CASE WHEN entry_type = 'cash_in'           THEN credit ELSE 0 END), 0) AS manual_cash_in,
            COALESCE(SUM(CASE WHEN entry_type = 'purchase_payment'  THEN debit  ELSE 0 END), 0) AS purchase_payment_debit,
            COALESCE(SUM(CASE WHEN entry_type = 'dues_paid'         THEN debit  ELSE 0 END), 0) AS dues_paid_debit,
            COALESCE(SUM(CASE WHEN entry_type = 'expense'           THEN debit  ELSE 0 END), 0) AS expense_debit,
            COALESCE(SUM(CASE WHEN entry_type = 'sale_return'       THEN debit  ELSE 0 END), 0) AS sale_return_debit,
            COALESCE(SUM(CASE WHEN entry_type = 'expired_loss'      THEN debit  ELSE 0 END), 0) AS expired_loss_debit,
            COALESCE(SUM(CASE WHEN entry_type IN ('stock_adjustment_loss','stock_adjustment_purchase') THEN debit ELSE 0 END), 0) AS stock_adj_debit,
            COALESCE(SUM(CASE WHEN entry_type = 'cash_out'          THEN debit  ELSE 0 END), 0) AS manual_cash_out,
            COALESCE(SUM(credit), 0) AS total_credit,
            COALESCE(SUM(debit),  0) AS total_debit
         FROM cashbook
         WHERE entry_date = :date AND voided = 0",
        [':date' => $date]
    );
    if (!$summary) {
        $summary = array_fill_keys([
            'sale_credit','credit_received','purchase_return_credit','manual_cash_in',
            'purchase_payment_debit','dues_paid_debit','expense_debit','sale_return_debit',
            'expired_loss_debit','stock_adj_debit','manual_cash_out','total_credit','total_debit'
        ], 0);
    }
    $opening = computeOpeningBalance($date);
    $closing = $opening + (float)$summary['total_credit'] - (float)$summary['total_debit'];

    return [
        'date'                    => $date,
        'opening_balance'         => $opening,
        'closing_balance'         => round($closing, 2),
        'cash_in_today'           => (float)$summary['total_credit'],
        'cash_out_today'          => (float)$summary['total_debit'],
        'sale_collections'        => (float)$summary['sale_credit'],
        'credit_received'         => (float)$summary['credit_received'],
        'purchase_return'         => (float)$summary['purchase_return_credit'],
        'manual_cash_in'          => (float)$summary['manual_cash_in'],
        'purchase_payments'       => (float)$summary['purchase_payment_debit'],
        'dues_paid'               => (float)$summary['dues_paid_debit'],
        'expenses'                => (float)$summary['expense_debit'],
        'sale_refunds'            => (float)$summary['sale_return_debit'],
        'expired_loss'            => (float)$summary['expired_loss_debit'],
        'stock_adjustments'       => (float)$summary['stock_adj_debit'],
        'manual_cash_out'         => (float)$summary['manual_cash_out'],
    ];
}

/**
 * Void a cashbook entry.
 */
function voidCashbookEntry(int $entryId, string $reason): bool {
    $db    = db();
    $entry = getOne("SELECT entry_date, voided FROM cashbook WHERE id = :id", [':id' => $entryId]);
    if (!$entry || $entry['voided'] == 1) return false;

    $db->beginTransaction();
    try {
        $db->run(
            "UPDATE cashbook SET voided = 1, voided_reason = :reason WHERE id = :id",
            [':reason' => $reason, ':id' => $entryId]
        );
        _recalculateRunningBalance($entry['entry_date']);
        $db->commit();
        return true;
    } catch (\Exception $e) {
        $db->rollback();
        error_log("[Cashbook] Void failed: " . $e->getMessage());
        return false;
    }
}

// ─────────────────────────────────────────────
// LABEL / BADGE HELPERS
// ─────────────────────────────────────────────

function getCashbookEntryLabel(string $type): string {
    $labels = [
        'sale'                       => 'Sale — Cash Received',
        'credit_given'               => 'Credit Sale (No Cash)',
        'credit_received'            => 'Credit Payment Received',
        'purchase_payment'           => 'Purchase — Cash Paid',
        'purchase_credit'            => 'Credit Purchase (No Cash)',
        'dues_paid'                  => 'Supplier Dues Paid',
        'expense'                    => 'Expense',
        'sale_return'                => 'Sale Return — Refund Paid',
        'purchase_return'            => 'Purchase Return — Refund Received',
        'expired_loss'               => 'Expired Stock Loss',
        'stock_adjustment_loss'      => 'Stock Adjustment (Loss/Damage)',
        'stock_adjustment_purchase'  => 'Stock Adjustment (Added)',
        'cash_in'                    => 'Manual Cash In',
        'cash_out'                   => 'Manual Cash Out',
        'opening'                    => 'Opening Balance',
        'closing'                    => 'Closing Balance',
        'adjustment'                 => 'Adjustment',
    ];
    return $labels[$type] ?? ucfirst(str_replace('_', ' ', $type));
}

function getCashbookBadgeClass(string $type): string {
    $classes = [
        'sale'                       => 'bg-success',
        'credit_given'               => 'bg-warning text-dark',
        'credit_received'            => 'bg-info text-dark',
        'purchase_payment'           => 'bg-primary',
        'purchase_credit'            => 'bg-secondary text-white',
        'dues_paid'                  => 'bg-danger',
        'expense'                    => 'bg-danger',
        'sale_return'                => 'bg-warning text-dark',
        'purchase_return'            => 'bg-success',
        'expired_loss'               => 'bg-danger',
        'stock_adjustment_loss'      => 'bg-danger',
        'stock_adjustment_purchase'  => 'bg-warning text-dark',
        'cash_in'                    => 'bg-success',
        'cash_out'                   => 'bg-danger',
        'opening'                    => 'bg-secondary',
        'closing'                    => 'bg-primary',
        'adjustment'                 => 'bg-secondary',
    ];
    return $classes[$type] ?? 'bg-secondary';
}

function getReferenceLink(string $entryType, ?int $referenceId, ?string $referenceType): string {
    if (!$referenceId) return '';
    try {
        switch ($entryType) {
            case 'sale':
            case 'credit_given':
                $s = getOne("SELECT invoice_no FROM sales WHERE id = :id", [':id' => $referenceId]);
                if ($s) return '<a href="../sales/view.php?id=' . $referenceId . '">' . htmlspecialchars($s['invoice_no']) . '</a>';
                break;
            case 'purchase_payment':
            case 'purchase_credit':
                $p = getOne("SELECT purchase_invoice_no FROM purchases WHERE id = :id", [':id' => $referenceId]);
                if ($p) return '<a href="../purchases/view.php?id=' . $referenceId . '">' . htmlspecialchars($p['purchase_invoice_no']) . '</a>';
                break;
            case 'credit_received':
                $cp = getOne(
                    "SELECT c.sale_id FROM credit_payments cp
                     JOIN customer_credits c ON c.id = cp.credit_id
                     WHERE cp.id = :id",
                    [':id' => $referenceId]
                );
                if ($cp) return '<a href="../sales/view.php?id=' . $cp['sale_id'] . '">View Credit</a>';
                break;
            case 'dues_paid':
                $sp = getOne("SELECT purchase_id FROM supplier_payments WHERE id = :id", [':id' => $referenceId]);
                if ($sp) return '<a href="../purchases/view.php?id=' . $sp['purchase_id'] . '">View Purchase</a>';
                break;
            case 'sale_return':
                return '<a href="../returns/sale_return_view.php?id=' . $referenceId . '">View Return</a>';
            case 'purchase_return':
                return '<a href="../returns/purchase_return_view.php?id=' . $referenceId . '">View Return</a>';
            case 'expense':
                $ex = getOne("SELECT category FROM expenses WHERE id = :id", [':id' => $referenceId]);
                if ($ex) return '<a href="../expenses/index.php?id=' . $referenceId . '">' . htmlspecialchars($ex['category']) . '</a>';
                break;
            case 'stock_adjustment_loss':
            case 'stock_adjustment_purchase':
                return '<a href="../stock/adjustments.php?id=' . $referenceId . '">View Adjustment</a>';
        }
    } catch (\Throwable $e) {
        // Silently ignore broken reference links
    }
    return '';
}
