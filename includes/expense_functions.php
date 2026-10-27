<?php
/**
 * Expense Functions Library – Enhanced with Full Sync Integration
 * 
 * - addExpense() now calls syncAfterExpense() via sync_engine
 * - Cashbook entry created with ensureCashbookEntry (prevents duplicates)
 * - getExpenseSummaryToday() for dashboard
 * - getExpensesByDateRange() with cashbook sync status
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/sync_engine.php';
require_once __DIR__ . '/cashbook_functions.php';

/**
 * Add a new expense and create corresponding cashbook debit entry (via sync engine)
 *
 * @param array $data  Keys: date, category, description, amount, payment_method, receipt_note, created_by
 * @return int|false   Inserted expense ID or false on failure
 */
function addExpense(array $data)
{
    $db = db();
    $db->beginTransaction();

    try {
        // Insert into expenses table
        $sql = "INSERT INTO expenses
                    (expense_date, category, description, amount, payment_method, receipt_note, created_by, created_at)
                VALUES
                    (:expense_date, :category, :description, :amount, :payment_method, :receipt_note, :created_by, NOW())";

        $db->query($sql);
        $db->bind(':expense_date', $data['expense_date']);
        $db->bind(':category', $data['category']);
        $db->bind(':description', $data['description']);
        $db->bind(':amount', (float)$data['amount']);
        $db->bind(':payment_method', $data['payment_method']);
        $db->bind(':receipt_note', $data['receipt_note'] ?? null);
        $db->bind(':created_by', $data['created_by']);

        if (!$db->execute()) {
            throw new Exception("Failed to insert expense");
        }

        $expenseId = $db->lastInsertId();

        // ─── DIRECT CASHBOOK INSERT (no helper deps) ─────────────────────
        try {
            $pdo = $db->getConnection();
            $chk = $pdo->prepare(
                "SELECT id FROM cashbook WHERE reference_id = ? AND reference_type = 'expense' AND voided = 0 LIMIT 1"
            );
            $chk->execute([$expenseId]);
            if (!$chk->fetch(PDO::FETCH_ASSOC)) {
                $balRow = $pdo->query("SELECT balance FROM cashbook WHERE voided = 0 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                $prevBalance = $balRow ? (float)$balRow['balance'] : 0.0;
                $newBalance  = round($prevBalance - (float)$data['amount'], 2);
                $ins = $pdo->prepare(
                    "INSERT INTO cashbook (entry_date, entry_type, reference_id, reference_type, description, debit, credit, balance, payment_method, voided, created_by, created_at)
                     VALUES (?, 'expense', ?, 'expense', ?, ?, 0, ?, ?, 0, ?, NOW())"
                );
                $ins->execute([
                    $data['expense_date'],
                    $expenseId,
                    'Expense — ' . $data['category'] . ': ' . $data['description'],
                    round((float)$data['amount'], 2),
                    $newBalance,
                    $data['payment_method'],
                    $data['created_by'] ?? ($_SESSION['user_id'] ?? 1),
                ]);
            }
        } catch (Exception $cbEx) {
            error_log('[addExpense cashbook] ' . $cbEx->getMessage());
        }

        $db->commit();

        logActivity('ADD_EXPENSE', 'New expense added — ID: ' . $expenseId .
                    ' | Category: ' . $data['category'] .
                    ' | Amount: ' . $data['amount']);

        return $expenseId;

    } catch (Exception $e) {
        $db->rollback();
        error_log('addExpense() Error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Update an existing expense and sync the cashbook entry
 *
 * @param int   $id
 * @param array $data  Keys: date, category, description, amount, payment_method, receipt_note
 * @return bool
 */
function updateExpense(int $id, array $data): bool
{
    $db = db();
    $db->beginTransaction();

    try {
        // Update expense row
        $db->query("UPDATE expenses
                    SET expense_date   = :expense_date,
                        category       = :category,
                        description    = :description,
                        amount         = :amount,
                        payment_method = :payment_method,
                        receipt_note   = :receipt_note,
                        
                    WHERE id = :id");
        $db->bind(':expense_date', $data['expense_date']);
        $db->bind(':category', $data['category']);
        $db->bind(':description', $data['description']);
        $db->bind(':amount', (float)$data['amount']);
        $db->bind(':payment_method', $data['payment_method']);
        $db->bind(':receipt_note', $data['receipt_note'] ?? null);
        $db->bind(':id', $id);
        $db->execute();

        // Update cashbook entry via ensureCashbookEntry (will update if exists, or create new)
        // First, get existing cashbook entry to update, else create new
        $existing = $db->getOne(
            "SELECT id FROM cashbook WHERE reference_id = :rid AND reference_type = 'expense'",
            [':rid' => $id]
        );
        if ($existing) {
            // Update existing cashbook entry
            $db->query("UPDATE cashbook
                        SET entry_date     = :entry_date,
                            description    = :description,
                            debit          = :debit,
                            payment_method = :payment_method
                        WHERE id = :id");
            $db->bind(':entry_date', $data['expense_date']);
            $db->bind(':description', 'Expense — ' . $data['category'] . ': ' . $data['description']);
            $db->bind(':debit', (float)$data['amount']);
            $db->bind(':payment_method', $data['payment_method']);
            $db->bind(':id', $existing['id']);
            $db->execute();
        } else {
            // Create new cashbook entry
            ensureCashbookEntry(
                entry_type: 'expense',
                reference_id: $id,
                reference_type: 'expense',
                description: 'Expense — ' . $data['category'] . ': ' . $data['description'],
                debit: (float)$data['amount'],
                credit: 0,
                payment_method: $data['payment_method'],
                entry_date: $data['expense_date']
            );
        }

        // Clear caches
        clearAllCaches();

        $db->commit();

        logActivity('UPDATE_EXPENSE', 'Expense updated — ID: ' . $id .
                    ' | Category: ' . $data['category'] .
                    ' | Amount: ' . $data['amount']);

        return true;

    } catch (Exception $e) {
        $db->rollback();
        error_log('updateExpense() Error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Soft-delete an expense and remove its cashbook entry
 *
 * @param int $id
 * @return bool
 */
function deleteExpense(int $id): bool
{
    $db = db();
    $db->beginTransaction();

    try {
        // Soft-delete expense
        $db->query("DELETE FROM expenses WHERE id = :id");
        $db->bind(':id', $id);
        $db->execute();

        if ($db->rowCount() === 0) {
            $db->rollback();
            return false;
        }

        // Void cashbook entry (soft delete) – set voided=1
        $db->query("UPDATE cashbook SET voided = 1, voided_reason = 'Expense deleted' 
                    WHERE reference_id = :rid AND reference_type = 'expense' AND voided = 0");
        $db->bind(':rid', $id);
        $db->execute();

        // Recalculate daily balance for affected date? Not necessary immediately, but we can clear caches
        clearAllCaches();

        $db->commit();

        logActivity('DELETE_EXPENSE', 'Expense soft-deleted — ID: ' . $id);

        return true;

    } catch (Exception $e) {
        $db->rollback();
        error_log('deleteExpense() Error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Retrieve expenses with optional filters, including cashbook sync status
 *
 * @param array $filters  Keys (all optional): from_date, to_date, category, search, limit, offset
 * @return array
 */
function getExpenses(array $filters = []): array
{
    $where = ["1=1"];
    $params = [];

    if (!empty($filters['from_date'])) {
        $where[] = "e.expense_date >= :from_date";
        $params[':from_date'] = $filters['from_date'];
    }
    if (!empty($filters['to_date'])) {
        $where[] = "e.expense_date <= :to_date";
        $params[':to_date'] = $filters['to_date'];
    }
    if (!empty($filters['category'])) {
        $where[] = "e.category = :category";
        $params[':category'] = $filters['category'];
    }
    if (!empty($filters['search'])) {
        $where[] = "(e.description LIKE :search OR e.receipt_note LIKE :search)";
        $params[':search'] = '%' . $filters['search'] . '%';
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);

    $limit  = isset($filters['limit'])  ? (int) $filters['limit']  : 500;
    $offset = isset($filters['offset']) ? (int) $filters['offset'] : 0;

    $sql = "SELECT e.*,
                   u.full_name AS created_by_name,
                   (SELECT COUNT(*) FROM cashbook WHERE reference_id = e.id AND reference_type = 'expense' AND voided = 0) AS has_cashbook
            FROM   expenses e
            LEFT JOIN users u ON u.id = e.created_by
            {$whereClause}
            ORDER BY e.expense_date DESC, e.id DESC
            LIMIT {$limit} OFFSET {$offset}";

    $db = db();
    return $db->getAll($sql, $params);
}

/**
 * Get expense summary for today (total + category breakdown)
 *
 * @return array
 */
function getExpenseSummaryToday(): array
{
    $db = db();
    $today = date('Y-m-d');
    $total = $db->getOne(
        "SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE expense_date = :today",
        [':today' => $today]
    )['total'] ?? 0;

    $byCategory = $db->getAll(
        "SELECT category, COALESCE(SUM(amount), 0) as total, COUNT(*) as count
         FROM expenses WHERE expense_date = :today
         GROUP BY category",
        [':today' => $today]
    );

    // Cashbook synced count for today's expenses
    $syncedCount = $db->getOne(
        "SELECT COUNT(*) as synced
         FROM expenses e
         JOIN cashbook c ON c.reference_id = e.id AND c.reference_type = 'expense' AND c.voided = 0
         WHERE e.expense_date = :today",
        [':today' => $today]
    )['synced'] ?? 0;

    return [
        'total' => (float)$total,
        'by_category' => $byCategory,
        'total_count' => array_sum(array_column($byCategory, 'count')),
        'synced_count' => (int)$syncedCount
    ];
}

/**
 * Get expenses by date range with cashbook sync status (for export/reports)
 *
 * @param string $fromDate
 * @param string $toDate
 * @param string|null $category
 * @return array
 */
function getExpensesByDateRange(string $fromDate, string $toDate, ?string $category = null): array
{
    $db = db();
    $params = [':from' => $fromDate, ':to' => $toDate];
    $categoryFilter = '';
    if ($category) {
        $categoryFilter = " AND e.category = :cat";
        $params[':cat'] = $category;
    }
    $sql = "SELECT e.*,
                   (SELECT COUNT(*) FROM cashbook WHERE reference_id = e.id AND reference_type = 'expense' AND voided = 0) AS has_cashbook
            FROM expenses e
            WHERE e.expense_date BETWEEN :from AND :to
             
              $categoryFilter
            ORDER BY e.expense_date DESC, e.id DESC";
    return $db->getAll($sql, $params);
}

/**
 * Return the list of expense categories
 *
 * @return array  [['value' => ..., 'label' => ...], ...]
 */
function getExpenseCategories(): array
{
    return [
        ['value' => 'Rent',        'label' => 'Rent / Kiraya'],
        ['value' => 'Utilities',   'label' => 'Utilities (Bijli, Gas, Paani)'],
        ['value' => 'Salary',      'label' => 'Salary / تنخواہ'],
        ['value' => 'Marketing',   'label' => 'Marketing / Advertising'],
        ['value' => 'Maintenance', 'label' => 'Maintenance / Repairs'],
        ['value' => 'Other',       'label' => 'Other / Aur'],
    ];
}

/**
 * Get expense summary grouped by category for a date range
 *
 * @param string $fromDate  Y-m-d
 * @param string $toDate    Y-m-d
 * @return array  [['category' => ..., 'total' => ...], ...]
 */
function getExpenseSummary(string $fromDate, string $toDate): array
{
    $db = db();
    $sql = "SELECT   category,
                     SUM(amount)  AS total,
                     COUNT(*)     AS count
            FROM     expenses
            WHERE 1=1
              AND    expense_date BETWEEN :from_date AND :to_date
            GROUP BY category
            ORDER BY total DESC";
    return $db->getAll($sql, [':from_date' => $fromDate, ':to_date' => $toDate]);
}

/**
 * Get monthly expense totals for the current year (or given year)
 *
 * @param int|null $year  Defaults to current year
 * @return array  [['month' => '2025-01', 'total' => ...], ...]
 */
function getMonthlyExpenseTotals(?int $year = null): array
{
    $year = $year ?? (int) date('Y');
    $db = db();
    $sql = "SELECT   DATE_FORMAT(expense_date, '%Y-%m') AS month,
                     SUM(amount)                AS total
            FROM     expenses
            WHERE 1=1
              AND    YEAR(expense_date) = :year
            GROUP BY DATE_FORMAT(expense_date, '%Y-%m')
            ORDER BY month ASC";
    return $db->getAll($sql, [':year' => $year]);
}
?>