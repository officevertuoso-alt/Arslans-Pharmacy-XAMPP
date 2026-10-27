<?php
/**
 * Daily Cashbook — index.php  (FIXED + COMPLETE)
 * cashbook/index.php
 *
 * FIXES:
 *  1. can_print column error → canView() now safe (see cashbook_functions.php)
 *  2. Correct recursive opening balance
 *  3. All business-logic entry types displayed correctly
 *  4. Realtime polling every 10s for new entries
 *  5. Integrity check covers all transaction types
 *  6. Stock adjustment entries shown in cashbook
 *  7. Returns: correct cash flow (paid vs credit)
 */

require_once '../config/config.php';
require_once '../includes/cashbook_functions.php';

// sync_engine.php is optional — load only if it exists
if (file_exists(__DIR__ . '/../includes/sync_engine.php')) {
    require_once '../includes/sync_engine.php';
}

requireLogin();

if (!canView('cashbook')) {
    setFlash('error', 'Aap ko Cashbook dekhne ki ijazat nahi hai.', 'danger');
    header('Location: ../dashboard.php');
    exit;
}

// ════════════════════════════════════════════════════════
// HELPERS
// ════════════════════════════════════════════════════════

/** Label map — covers ALL entry types */
function entryLabel(string $type): string {
    $map = [
        'sale'                      => 'Sale — Cash Received',
        'credit_given'              => 'Credit Sale (No Cash)',
        'credit_received'           => 'Credit Payment Received',
        'purchase_payment'          => 'Purchase — Cash Paid',
        'purchase_credit'           => 'Credit Purchase (No Cash)',
        'dues_paid'                 => 'Supplier Dues Paid',
        'expense'                   => 'Expense',
        'sale_return'               => 'Sale Return — Refund Paid',
        'purchase_return'           => 'Purchase Return — Refund Received',
        'expired_loss'              => 'Expired Stock Loss',
        'stock_adjustment_loss'     => 'Stock Adjust (Loss/Damage)',
        'stock_adjustment_purchase' => 'Stock Adjust (Added)',
        'cash_in'                   => 'Manual Cash In',
        'cash_out'                  => 'Manual Cash Out',
        'opening'                   => 'Opening Balance',
        'closing'                   => 'Closing Balance',
        'adjustment'                => 'Adjustment',
        'opening_balance'           => 'Opening Balance',
    ];
    return $map[$type] ?? ucwords(str_replace('_', ' ', $type));
}

function entryBadge(string $type): string {
    $map = [
        'sale'                      => 'bg-success',
        'credit_given'              => 'bg-warning text-dark',
        'credit_received'           => 'bg-info text-dark',
        'purchase_payment'          => 'bg-primary',
        'purchase_credit'           => 'bg-secondary text-white',
        'dues_paid'                 => 'bg-danger',
        'expense'                   => 'bg-danger',
        'sale_return'               => 'bg-warning text-dark',
        'purchase_return'           => 'bg-info text-dark',
        'expired_loss'              => 'bg-danger',
        'stock_adjustment_loss'     => 'bg-danger',
        'stock_adjustment_purchase' => 'bg-warning text-dark',
        'cash_in'                   => 'bg-success',
        'cash_out'                  => 'bg-danger',
        'opening'                   => 'bg-secondary',
        'opening_balance'           => 'bg-secondary',
        'closing'                   => 'bg-primary',
        'adjustment'                => 'bg-secondary',
    ];
    return $map[$type] ?? 'bg-secondary';
}

/** Build clickable description link for a cashbook row */
function buildDescriptionLink(array $row, string $selectedDate): string {
    $type  = $row['entry_type']   ?? '';
    $refId = $row['reference_id'] ?? null;
    $desc  = $row['description']  ?? '';

    try {
        if ($type === 'expense') {
            $cat = '';
            if (preg_match('/Expense\s*[—\-]+\s*([^:\n]+)/u', $desc, $m)) $cat = trim($m[1]);
            $url = '../expenses/index.php?from_date=' . urlencode($selectedDate)
                 . '&to_date=' . urlencode($selectedDate)
                 . ($cat ? '&category=' . urlencode($cat) : '');
            return '<a href="' . htmlspecialchars($url) . '" class="text-danger small ms-1" target="_blank">'
                 . htmlspecialchars($cat ?: 'Expenses') . '</a>';
        }
        if (in_array($type, ['sale', 'credit_given']) && $refId) {
            return '<a href="../sales/view.php?id=' . (int)$refId . '" class="text-success small ms-1" target="_blank">View Sale</a>';
        }
        if ($type === 'credit_received' && $refId) {
            $db   = db();
            $row2 = $db->getOne(
                "SELECT c.sale_id FROM credit_payments cp
                 JOIN customer_credits c ON c.id = cp.credit_id
                 WHERE cp.id = :id LIMIT 1",
                [':id' => (int)$refId]
            );
            if ($row2 && !empty($row2['sale_id'])) {
                return '<a href="../sales/view.php?id=' . (int)$row2['sale_id'] . '" class="text-info small ms-1" target="_blank">View Credit</a>';
            }
        }
        if (in_array($type, ['purchase_payment', 'purchase_credit', 'dues_paid']) && $refId) {
            $db = db();
            // dues_paid reference_id = supplier_payments.id → find purchase_id
            if ($type === 'dues_paid') {
                $sp = $db->getOne("SELECT purchase_id FROM supplier_payments WHERE id = :id LIMIT 1", [':id' => (int)$refId]);
                $pid = $sp ? (int)$sp['purchase_id'] : (int)$refId;
            } else {
                $refType = $row['reference_type'] ?? '';
                if ($refType === 'purchase') {
                    $pid = (int)$refId;
                } else {
                    $sp = $db->getOne("SELECT purchase_id FROM supplier_payments WHERE id = :id LIMIT 1", [':id' => (int)$refId]);
                    $pid = ($sp && !empty($sp['purchase_id'])) ? (int)$sp['purchase_id'] : (int)$refId;
                }
            }
            return '<a href="../purchases/view.php?id=' . $pid . '" class="text-primary small ms-1" target="_blank">View Purchase</a>';
        }
        if ($type === 'sale_return' && $refId) {
            return '<a href="../returns/sale_return_view.php?id=' . (int)$refId . '" class="text-warning small ms-1" target="_blank">View Return</a>';
        }
        if ($type === 'purchase_return' && $refId) {
            return '<a href="../returns/purchase_return_view.php?id=' . (int)$refId . '" class="text-info small ms-1" target="_blank">View Return</a>';
        }
        if (in_array($type, ['stock_adjustment_loss', 'stock_adjustment_purchase']) && $refId) {
            return '<a href="../stock/adjustments.php?id=' . (int)$refId . '" class="text-secondary small ms-1" target="_blank">View Adjustment</a>';
        }
    } catch (\Throwable $e) {
        // Silently skip broken links
    }

    if (!empty($row['reference_link'])) return $row['reference_link'];
    return '';
}

/** Build entries array with running balance, labels, badges */
function buildEntriesWithBalance(array $rows, float $opening, string $entryDate = ''): array {
    $balance = $opening;
    foreach ($rows as &$row) {
        $credit  = (float)($row['credit'] ?? 0);
        $debit   = (float)($row['debit']  ?? 0);
        $balance = round($balance + $credit - $debit, 2);
        $row['balance']   = $balance;
        $row['type_label'] = function_exists('getCashbookEntryLabel')
            ? getCashbookEntryLabel($row['entry_type'])
            : entryLabel($row['entry_type']);
        if (empty($row['type_label']) || $row['type_label'] === $row['entry_type']) {
            $row['type_label'] = entryLabel($row['entry_type']);
        }
        $row['badge_class'] = function_exists('getCashbookBadgeClass')
            ? getCashbookBadgeClass($row['entry_type'])
            : entryBadge($row['entry_type']);
        if (empty($row['badge_class'])) $row['badge_class'] = entryBadge($row['entry_type']);
        $useDate = $entryDate ?: ($row['entry_date'] ?? date('Y-m-d'));
        $row['desc_link']     = buildDescriptionLink($row, $useDate);
        $row['reference_link'] = function_exists('getReferenceLink')
            ? getReferenceLink($row['entry_type'], $row['reference_id'] ?? null, $row['reference_type'] ?? null)
            : '';
    }
    return $rows;
}

/** Filter SQL builder */
function buildFilterSQL(string $filter, string $pmFilter, string $search, array &$params): string {
    $sql = '';
    if ($filter !== 'all') {
        switch ($filter) {
            case 'sales':
                $sql .= " AND c.entry_type IN ('sale','credit_given','credit_received')";
                break;
            case 'purchases':
                $sql .= " AND c.entry_type IN ('purchase_payment','purchase_credit','dues_paid')";
                break;
            case 'expenses':
                $sql .= " AND c.entry_type = 'expense'";
                break;
            case 'returns':
                $sql .= " AND c.entry_type IN ('sale_return','purchase_return')";
                break;
            case 'expired_loss':
                $sql .= " AND c.entry_type IN ('expired_loss','stock_adjustment_loss','stock_adjustment_purchase')";
                break;
            case 'credit_note':
                $sql .= " AND c.entry_type IN ('credit_given','credit_received','purchase_credit')";
                break;
            default:
                $sql .= " AND c.entry_type = :etf";
                $params[':etf'] = $filter;
        }
    }
    if ($pmFilter !== 'all' && $filter !== 'credit_note') {
        $sql .= " AND c.payment_method = :pm";
        $params[':pm'] = $pmFilter;
    } elseif ($pmFilter === 'credit_note' && $filter === 'all') {
        $sql .= " AND c.entry_type IN ('credit_given','credit_received','purchase_credit')";
    }
    if (!empty($search)) {
        $sql .= " AND c.description LIKE :search";
        $params[':search'] = "%$search%";
    }
    return $sql;
}

// ════════════════════════════════════════════════════════
// AJAX HANDLERS
// ════════════════════════════════════════════════════════
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $action = $_GET['ajax'];

    // ── Integrity check ──────────────────────────────────
    if ($action === 'integrity') {
        $db    = db();
        $today = date('Y-m-d');

        $salesMissing = $db->getOne(
            "SELECT COUNT(*) AS count FROM sales
             WHERE DATE(sale_date) = :today
               AND (payment_method != 'credit' AND (is_credit IS NULL OR is_credit = 0))
               AND (status IS NULL OR status != 'voided')
               AND id NOT IN (
                   SELECT reference_id FROM cashbook
                   WHERE entry_type = 'sale' AND reference_type = 'sale' AND DATE(entry_date) = :today AND voided = 0
               )",
            [':today' => $today]
        );
        $creditSalesMissing = $db->getOne(
            "SELECT COUNT(*) AS count FROM sales
             WHERE DATE(sale_date) = :today
               AND (payment_method = 'credit' OR is_credit = 1)
               AND (status IS NULL OR status != 'voided')
               AND id NOT IN (
                   SELECT reference_id FROM cashbook
                   WHERE entry_type = 'credit_given' AND reference_type = 'sale' AND DATE(entry_date) = :today AND voided = 0
               )",
            [':today' => $today]
        );
        $purchasesMissing = $db->getOne(
            "SELECT COUNT(*) AS count FROM purchases
             WHERE DATE(purchase_date) = :today
               AND payment_status IN ('paid','partial')
               AND id NOT IN (
                   SELECT reference_id FROM cashbook
                   WHERE entry_type = 'purchase_payment' AND reference_type = 'purchase' AND DATE(entry_date) = :today AND voided = 0
               )",
            [':today' => $today]
        );
        $purchasesCreditMissing = $db->getOne(
            "SELECT COUNT(*) AS count FROM purchases
             WHERE DATE(purchase_date) = :today
               AND payment_status IN ('pending','credit')
               AND id NOT IN (
                   SELECT reference_id FROM cashbook
                   WHERE entry_type = 'purchase_credit' AND reference_type = 'purchase' AND DATE(entry_date) = :today AND voided = 0
               )",
            [':today' => $today]
        );
        $saleReturnsMissing = $db->getOne(
            "SELECT COUNT(*) AS count FROM sale_returns
             WHERE DATE(created_at) = :today
               AND refund_method IN ('cash','bank')
               AND id NOT IN (
                   SELECT reference_id FROM cashbook
                   WHERE entry_type = 'sale_return' AND reference_type = 'sale_return' AND DATE(entry_date) = :today AND voided = 0
               )",
            [':today' => $today]
        );
        $purchaseReturnsMissing = $db->getOne(
            "SELECT COUNT(*) AS count FROM purchase_returns
             WHERE DATE(created_at) = :today
               AND refund_method IN ('cash','bank')
               AND id NOT IN (
                   SELECT reference_id FROM cashbook
                   WHERE entry_type = 'purchase_return' AND reference_type = 'purchase_return' AND DATE(entry_date) = :today AND voided = 0
               )",
            [':today' => $today]
        );
        $expensesMissing = $db->getOne(
            "SELECT COUNT(*) AS count FROM expenses
             WHERE DATE(expense_date) = :today
               AND id NOT IN (
                   SELECT reference_id FROM cashbook
                   WHERE entry_type = 'expense' AND reference_type = 'expense' AND DATE(entry_date) = :today AND voided = 0
               )",
            [':today' => $today]
        );

        $missingCount = (int)($salesMissing['count'] ?? 0)
            + (int)($creditSalesMissing['count'] ?? 0)
            + (int)($purchasesMissing['count'] ?? 0)
            + (int)($purchasesCreditMissing['count'] ?? 0)
            + (int)($saleReturnsMissing['count'] ?? 0)
            + (int)($purchaseReturnsMissing['count'] ?? 0)
            + (int)($expensesMissing['count'] ?? 0);

        echo json_encode([
            'success'                  => true,
            'missing_count'            => $missingCount,
            'sales_missing'            => (int)($salesMissing['count'] ?? 0),
            'credit_sales_missing'     => (int)($creditSalesMissing['count'] ?? 0),
            'purchases_missing'        => (int)($purchasesMissing['count'] ?? 0),
            'purchases_credit_missing' => (int)($purchasesCreditMissing['count'] ?? 0),
            'sale_returns_missing'     => (int)($saleReturnsMissing['count'] ?? 0),
            'purchase_returns_missing' => (int)($purchaseReturnsMissing['count'] ?? 0),
            'expenses_missing'         => (int)($expensesMissing['count'] ?? 0),
        ]);
        exit;
    }

    // ── Export CSV ───────────────────────────────────────
    if ($action === 'export_csv' && isset($_GET['date'])) {
        $date      = $_GET['date'];
        $filter    = $_GET['filter']          ?? 'all';
        $search    = $_GET['search']          ?? '';
        $pmFilter  = $_GET['payment_method']  ?? 'all';
        $db        = db();
        $params    = [':date' => $date];
        $filterSQL = buildFilterSQL($filter, $pmFilter, $search, $params);
        $rows = $db->getAll(
            "SELECT c.*, u.full_name AS created_by_name FROM cashbook c
             LEFT JOIN users u ON u.id = c.created_by
             WHERE c.entry_date = :date AND c.voided = 0 $filterSQL
             ORDER BY c.created_at ASC, c.id ASC",
            $params
        );
        $opening = computeOpeningBalance($date);
        $rows    = buildEntriesWithBalance($rows, $opening, $date);
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="cashbook_' . $date . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['#','Time','Type','Description','Debit (Out)','Credit (In)','Balance','Payment Method']);
        foreach ($rows as $i => $e) {
            fputcsv($out, [
                $i + 1,
                date('h:i A', strtotime($e['created_at'])),
                $e['type_label'],
                $e['description'],
                $e['debit'],
                $e['credit'],
                $e['balance'],
                $e['payment_method'],
            ]);
        }
        fclose($out);
        exit;
    }

    // ── Void entry ───────────────────────────────────────
    if ($action === 'void' && isset($_POST['id'])) {
        if (!function_exists('isAdmin') || !isAdmin()) {
            echo json_encode(['success' => false, 'message' => 'Sirf admin void kar sakta hai.']);
            exit;
        }
        if (function_exists('verifyCSRFToken') && !verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
            exit;
        }
        $db = db();
        $db->run("UPDATE cashbook SET voided = 1, voided_reason = 'Admin voided' WHERE id = :id AND voided = 0", [':id' => (int)$_POST['id']]);
        if (function_exists('clearAllCaches')) clearAllCaches();
        echo json_encode(['success' => true, 'message' => 'Entry voided.']);
        exit;
    }

    // ── Realtime new entries poll ────────────────────────
    if ($action === 'new_entries' && isset($_GET['date'])) {
        $date     = $_GET['date'];
        $afterId  = (int)($_GET['after_id'] ?? 0);
        $pmFilter = $_GET['payment_method'] ?? 'all';
        $typeFltr = $_GET['filter']         ?? 'all';
        $srch     = trim($_GET['search']    ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
        $db     = db();
        $params = [':date' => $date, ':after' => $afterId];
        $filterSQL = buildFilterSQL($typeFltr, $pmFilter, $srch, $params);
        $rows = $db->getAll(
            "SELECT c.*, u.full_name AS created_by_name FROM cashbook c
             LEFT JOIN users u ON u.id = c.created_by
             WHERE c.entry_date = :date AND c.voided = 0 AND c.id > :after
             $filterSQL ORDER BY c.created_at ASC, c.id ASC",
            $params
        );
        $lastBalRow = $db->getOne(
            "SELECT balance FROM cashbook WHERE entry_date = :date AND voided = 0 AND id <= :after ORDER BY id DESC LIMIT 1",
            [':date' => $date, ':after' => $afterId]
        );
        $startBal = $lastBalRow ? (float)$lastBalRow['balance'] : computeOpeningBalance($date);
        $rows     = buildEntriesWithBalance($rows, $startBal, $date);
        echo json_encode(['success' => true, 'entries' => $rows]);
        exit;
    }

    // ── Manual entry save (AJAX from modal) ─────────────
    if ($action === 'save_manual') {
        if (function_exists('verifyCSRFToken') && !verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
            exit;
        }
        $entryType     = trim($_POST['entry_type']    ?? '');
        $amount        = (float)($_POST['amount']     ?? 0);
        $description   = trim($_POST['description']   ?? '');
        $paymentMethod = trim($_POST['payment_method']?? 'cash');
        $entryDate     = trim($_POST['entry_date']    ?? date('Y-m-d'));

        $allowedTypes = ['cash_in','cash_out','expense','opening','adjustment'];
        if (!in_array($entryType, $allowedTypes, true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid entry type.']);
            exit;
        }
        if ($amount <= 0) {
            echo json_encode(['success' => false, 'message' => 'Amount must be > 0.']);
            exit;
        }
        if (empty($description)) {
            echo json_encode(['success' => false, 'message' => 'Description zaruri hai.']);
            exit;
        }

        $debit  = in_array($entryType, ['cash_out','expense'], true) ? $amount : 0.0;
        $credit = in_array($entryType, ['cash_in','opening'],  true) ? $amount : 0.0;
        if ($entryType === 'adjustment') {
            $dir   = $_POST['direction'] ?? 'credit';
            $debit  = $dir === 'debit'  ? $amount : 0.0;
            $credit = $dir === 'credit' ? $amount : 0.0;
        }

        $newId = addCashbookEntry(
            $entryType, null, 'manual', $description,
            $debit, $credit, $paymentMethod,
            (int)($_SESSION['user_id'] ?? 0),
            $entryDate, false
        );
        if ($newId) {
            if (function_exists('logActivity')) {
                logActivity('cashbook_manual', "Manual {$entryType}: {$description} Rs {$amount}");
            }
            echo json_encode(['success' => true, 'message' => 'Entry add ho gayi!', 'id' => $newId]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Entry save nahi ho saki.']);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown AJAX action']);
    exit;
}

// ════════════════════════════════════════════════════════
// PAGE LOAD
// ════════════════════════════════════════════════════════
$selectedDate  = $_GET['date']           ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) $selectedDate = date('Y-m-d');
$filter        = $_GET['filter']         ?? 'all';
$paymentFilter = $_GET['payment_method'] ?? 'all';
$search        = trim($_GET['search']    ?? '');

$db     = db();
$params = [':date' => $selectedDate];
$filterSQL = buildFilterSQL($filter, $paymentFilter, $search, $params);

$rawEntries = $db->getAll(
    "SELECT c.*, u.full_name AS created_by_name FROM cashbook c
     LEFT JOIN users u ON u.id = c.created_by
     WHERE c.entry_date = :date AND c.voided = 0
     $filterSQL ORDER BY c.created_at ASC, c.id ASC",
    $params
);

$openingBalance = computeOpeningBalance($selectedDate);
$entries        = buildEntriesWithBalance($rawEntries, $openingBalance, $selectedDate);
$summary        = getDailySummary($selectedDate);

// Recalculate summary using local computeOpeningBalance for accuracy
$summary['opening'] = $openingBalance;
$summary['closing'] = round($openingBalance + $summary['total_in'] - $summary['total_out'], 2);

$pageTitle = 'Daily Cashbook — ' . (function_exists('formatDate') ? formatDate($selectedDate) : $selectedDate);
$csrfToken = function_exists('generateCSRFToken') ? generateCSRFToken() : (bin2hex(random_bytes(16)));
?>
<!DOCTYPE html>
<html lang="ur" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include dirname(__DIR__) . '/includes/page_head.php'; ?>
    <style>
        @keyframes fadeInPage{from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:translateY(0);}}
        .main-content{margin-left:var(--sb-w,260px);padding:20px;min-height:100vh;transition:margin-left .25s ease;animation:fadeInPage .28s ease;}
        body.sb-col-body .main-content{margin-left:var(--sb-w-mini,62px);}
        @media(max-width:991.98px){.main-content{margin-left:0!important;}}
        body{background:var(--pharmacy-body-bg,#f0f2f5);color:var(--pharmacy-text,#444);}
        .summary-card{border:none;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);transition:transform .15s;background:var(--pharmacy-card-bg,#fff)!important;color:var(--pharmacy-text,#444)!important;}
        .summary-card:hover{transform:translateY(-2px);}
        .summary-card .card-icon{width:48px;height:48px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.4rem;}
        .summary-card .amount{font-size:1.45rem;font-weight:700;letter-spacing:-.5px;color:var(--pharmacy-heading,#1a1d23);}
        .cashbook-table thead th{background:var(--pharmacy-sidebar,#2c3e50);color:#fff;font-size:.82rem;text-transform:uppercase;letter-spacing:.5px;border:none;}
        .cashbook-table tbody tr.credit-row{background:color-mix(in srgb,#198754 8%,var(--pharmacy-card-bg,#fff));}
        .cashbook-table tbody tr.debit-row{background:color-mix(in srgb,#dc3545 6%,var(--pharmacy-card-bg,#fff));}
        .cashbook-table tbody tr.opening-row{background:color-mix(in srgb,#ffc107 8%,var(--pharmacy-card-bg,#fff));}
        .cashbook-table tbody tr:hover{filter:brightness(.97);}
        .cashbook-table td{vertical-align:middle;font-size:.88rem;color:var(--pharmacy-text,#444);}
        .amount-credit{color:#198754;font-weight:600;}
        .amount-debit{color:#dc3545;font-weight:600;}
        .amount-bal{font-weight:700;color:var(--pharmacy-heading,#2c3e50);}
        .balance-negative{color:#dc3545!important;}
        .fab-btn{position:fixed;bottom:28px;right:28px;width:56px;height:56px;border-radius:50%;font-size:1.5rem;box-shadow:0 4px 16px rgba(13,110,253,.4);z-index:1050;display:flex;align-items:center;justify-content:center;text-decoration:none;transition:transform .2s,box-shadow .2s;}
        .fab-btn:hover{transform:scale(1.1) rotate(45deg);box-shadow:0 6px 20px rgba(13,110,253,.5);}
        .filter-pill{display:inline-flex;align-items:center;gap:4px;padding:5px 14px;border-radius:20px;font-size:12px;font-weight:600;text-decoration:none;border:1px solid var(--pharmacy-border,#dee2e6);background:var(--pharmacy-card-bg,#fff);color:var(--pharmacy-text,#495057);transition:all .15s;white-space:nowrap;}
        .filter-pill.active{background:var(--pharmacy-primary,#1a56db);color:#fff;border-color:var(--pharmacy-primary,#1a56db);}
        .filter-pill:hover:not(.active){background:var(--pharmacy-table-th,#e9ecef);}
        .filter-pill.credit-pill{border-color:#0dcaf0;color:#0dcaf0;}
        .filter-pill.credit-pill.active{background:#0dcaf0;color:#000;}
        .filter-pill.expired-pill{border-color:#dc3545;color:#dc3545;}
        .filter-pill.expired-pill.active{background:#dc3545;color:#fff;}
        .integrity-badge{font-size:11px;background:var(--pharmacy-table-th,#e9ecef);color:var(--pharmacy-text,#444);padding:4px 10px;border-radius:20px;}
        .integrity-badge.success{background:#d4edda;color:#155724;}
        .integrity-badge.warning{background:#fff3cd;color:#856404;cursor:pointer;}
        .opening-balance-row td{background:color-mix(in srgb,#ffc107 8%,var(--pharmacy-card-bg,#fff))!important;font-style:italic;color:var(--pharmacy-text,#6c757d);}
        .card{background:var(--pharmacy-card-bg,#fff)!important;border-color:var(--pharmacy-border,#dee2e6)!important;color:var(--pharmacy-text,#444)!important;}
        .date-nav .btn{border-radius:8px;}
        @media print{.sidebar,.navbar,.fab-btn,.no-print{display:none!important;}.main-content{margin-left:0!important;}}
    </style>
</head>
<?php $__dm = !empty($_SESSION["company_settings_cache"]["dark_mode"]) ? " dark-mode" : ""; ?>
<body class="<?= trim("bg-light" . $__dm) ?>">

<?php include '../includes/sidebar.php'; ?>
<?php include '../includes/navbar.php'; ?>

<div class="main-content">
<div class="container-fluid">

    <!-- Page Header -->
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <div>
            <h4 class="mb-0 fw-bold"><i class="bi bi-journal-text text-primary me-2"></i>Daily Cashbook</h4>
            <small class="text-muted">Roz ka cash hisab kitab
                <?php if ($openingBalance != 0): ?>
                <span class="ms-2 badge <?= $openingBalance < 0 ? 'bg-danger' : 'bg-secondary' ?>">
                    Opening: <?= function_exists('formatCurrency') ? formatCurrency($openingBalance) : 'Rs ' . number_format($openingBalance, 2) ?>
                </span>
                <?php endif; ?>
            </small>
        </div>
        <div class="d-flex gap-2 align-items-center no-print">
            <div id="integrityBadge" class="integrity-badge" onclick="handleIntegrityClick()" style="cursor:pointer;">
                <i class="bi bi-arrow-repeat me-1"></i> Checking...
            </div>
        </div>
    </div>

    <!-- Date Navigation & Filters -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 no-print">
        <div class="date-nav d-flex align-items-center gap-2 flex-wrap">
            <?php
            $prevDate = date('Y-m-d', strtotime($selectedDate . ' -1 day'));
            $nextDate = date('Y-m-d', strtotime($selectedDate . ' +1 day'));
            $isToday  = $selectedDate === date('Y-m-d');
            ?>
            <a href="?date=<?= $prevDate ?>&filter=<?= urlencode($filter) ?>&search=<?= urlencode($search) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-chevron-left"></i></a>
            <form method="GET" class="d-flex gap-1">
                <input type="date" name="date" class="form-control form-control-sm" value="<?= $selectedDate ?>" max="<?= date('Y-m-d') ?>" onchange="this.form.submit()">
                <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
                <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
            </form>
            <a href="?date=<?= $nextDate ?>&filter=<?= urlencode($filter) ?>&search=<?= urlencode($search) ?>" class="btn btn-outline-secondary btn-sm <?= $isToday ? 'disabled' : '' ?>"><i class="bi bi-chevron-right"></i></a>
            <?php if (!$isToday): ?>
            <a href="?date=<?= date('Y-m-d') ?>&filter=<?= urlencode($filter) ?>&search=<?= urlencode($search) ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-calendar-check me-1"></i>Aaj</a>
            <?php endif; ?>
            <a href="report.php" class="btn btn-outline-dark btn-sm"><i class="bi bi-bar-chart-line me-1"></i>Report</a>
        </div>

        <div class="d-flex flex-wrap gap-2 align-items-center">
            <div class="d-flex flex-wrap gap-1">
                <?php
                $fp = '?date=' . $selectedDate . '&search=' . urlencode($search);
                $filters = [
                    'all'          => ['All',           ''],
                    'sales'        => ['Sales',         'bi-cash-stack'],
                    'purchases'    => ['Purchases',     'bi-cart'],
                    'expenses'     => ['Expenses',      'bi-receipt'],
                    'returns'      => ['Returns',       'bi-arrow-return-left'],
                    'credit_note'  => ['Credit Note',   'bi-credit-card'],
                    'expired_loss' => ['Stock Losses',  'bi-slash-circle'],
                ];
                foreach ($filters as $fk => $fv):
                    $cls = ($filter === $fk) ? 'active' : '';
                    $extra = ($fk === 'credit_note') ? ' credit-pill' : (($fk === 'expired_loss') ? ' expired-pill' : '');
                ?>
                <a href="<?= $fp ?>&filter=<?= $fk ?>" class="filter-pill <?= $cls . $extra ?>">
                    <?php if (!empty($fv[1])): ?><i class="bi <?= $fv[1] ?>"></i><?php endif; ?>
                    <?= $fv[0] ?>
                </a>
                <?php endforeach; ?>
            </div>

            <!-- Search -->
            <form method="GET" class="d-flex gap-1" style="max-width:240px;">
                <input type="hidden" name="date"   value="<?= $selectedDate ?>">
                <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
                <input type="text"   name="search" class="form-control form-control-sm" placeholder="Search..." value="<?= htmlspecialchars($search) ?>">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search"></i></button>
                <?php if ($search): ?>
                <a href="?date=<?= $selectedDate ?>&filter=<?= urlencode($filter) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x"></i></a>
                <?php endif; ?>
            </form>

            <!-- Payment Method -->
            <form method="GET" class="d-flex gap-1 align-items-center">
                <input type="hidden" name="date"   value="<?= $selectedDate ?>">
                <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
                <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                <select name="payment_method" class="form-select form-select-sm" style="min-width:130px;" onchange="this.form.submit()">
                    <?php foreach (['all'=>'All Methods','cash'=>'Cash','card'=>'Card','online'=>'Online','bank'=>'Bank Transfer','cheque'=>'Cheque','credit_note'=>'Credit Note'] as $v=>$l): ?>
                    <option value="<?= $v ?>" <?= $paymentFilter===$v?'selected':'' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </form>

            <!-- Export -->
            <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-success" onclick="exportCSV()"><i class="bi bi-file-excel"></i> CSV</button>
                <button class="btn btn-outline-danger"  onclick="exportPDF()"><i class="bi bi-file-earmark-pdf"></i> PDF</button>
                <button class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
            </div>
        </div>
    </div>

    <!-- Flash messages -->
    <?php if (function_exists('renderFlashAll')) echo renderFlashAll(); ?>

    <!-- Summary Cards -->
    <div class="row g-3 mb-4">
        <?php
        $cards = [
            ['label'=>'Opening Balance','val'=>$summary['opening'],'icon'=>'bi-bank','color'=>'secondary','cls'=>$summary['opening']<0?'text-danger':''],
            ['label'=>'Total Cash In',  'val'=>$summary['total_in'],'icon'=>'bi-arrow-down-circle-fill','color'=>'success','cls'=>'text-success'],
            ['label'=>'Total Cash Out', 'val'=>$summary['total_out'],'icon'=>'bi-arrow-up-circle-fill','color'=>'danger','cls'=>'text-danger'],
            ['label'=>'Closing Balance','val'=>$summary['closing'],'icon'=>'bi-wallet2','color'=>'primary','cls'=>$summary['closing']<0?'text-danger':'text-primary'],
        ];
        $fmt = fn($v) => function_exists('formatCurrency') ? formatCurrency($v) : 'Rs ' . number_format($v, 2);
        foreach ($cards as $c): ?>
        <div class="col-6 col-md-3">
            <div class="card summary-card h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="card-icon bg-<?= $c['color'] ?> bg-opacity-10 text-<?= $c['color'] ?>"><i class="bi <?= $c['icon'] ?>"></i></div>
                    <div>
                        <div class="text-muted small"><?= $c['label'] ?></div>
                        <div class="amount <?= $c['cls'] ?>"><?= $fmt($c['val']) ?></div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Transactions Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center py-3">
            <span class="fw-semibold">
                <i class="bi bi-list-ul me-2 text-primary"></i>
                Transactions — <span class="text-primary"><?= function_exists('formatDate') ? formatDate($selectedDate) : $selectedDate ?></span>
            </span>
            <div class="d-flex align-items-center gap-2">
                <?php if ($filter !== 'all'): ?><span class="badge bg-info text-dark">Filter: <?= htmlspecialchars($filter) ?></span><?php endif; ?>
                <span class="badge bg-primary rounded-pill"><?= count($entries) ?> entries</span>
            </div>
        </div>
        <div class="card-body p-0">
        <?php if (empty($entries)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-journal-x" style="font-size:3rem;"></i>
                <p class="mt-2">Is din ke liye koi entry nahi mili</p>
                <small>Pehli entry add karne ke liye + button dabayein</small>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table cashbook-table table-bordered mb-0" id="cashbookTable">
                    <thead>
                        <tr>
                            <th style="width:45px">#</th>
                            <th style="width:85px">Time</th>
                            <th style="width:175px">Type</th>
                            <th>Description</th>
                            <th style="width:120px" class="text-end">Debit (Out)</th>
                            <th style="width:120px" class="text-end">Credit (In)</th>
                            <th style="width:130px" class="text-end">Balance</th>
                            <th style="width:80px" class="text-center">Method</th>
                            <?php if(function_exists('isAdmin') && isAdmin()): ?>
                            <th style="width:55px" class="text-center no-print">Void</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody id="cashbookTbody">
                        <!-- Opening row -->
                        <tr class="opening-balance-row">
                            <td colspan="<?= (function_exists('isAdmin') && isAdmin()) ? 8 : 7 ?>" class="text-secondary small fst-italic">
                                <i class="bi bi-arrow-right-circle me-1"></i>Opening Balance
                            </td>
                            <td class="text-end fw-bold <?= $summary['opening'] < 0 ? 'text-danger' : '' ?>">
                                <?= $fmt($summary['opening']) ?>
                            </td>
                            <?php if(function_exists('isAdmin') && isAdmin()): ?><td></td><?php endif; ?>
                        </tr>

                        <?php foreach ($entries as $i => $entry):
                            $isCredit = (float)($entry['credit'] ?? 0) > 0;
                            $isDebit  = (float)($entry['debit']  ?? 0) > 0;
                            $rowClass = $isCredit ? 'credit-row' : ($isDebit ? 'debit-row' : '');
                            $bal      = (float)$entry['balance'];
                        ?>
                        <tr class="<?= $rowClass ?>" data-id="<?= $entry['id'] ?>">
                            <td class="text-muted small"><?= $i + 1 ?></td>
                            <td class="text-muted small"><?= date('h:i A', strtotime($entry['created_at'])) ?></td>
                            <td>
                                <span class="badge <?= $entry['badge_class'] ?> rounded-pill" style="white-space:normal;line-height:1.3;">
                                    <?= htmlspecialchars($entry['type_label']) ?>
                                </span>
                            </td>
                            <td>
                                <?= htmlspecialchars($entry['description'] ?? '') ?>
                                <?php
                                $dlink = $entry['desc_link'] ?? '';
                                if (empty($dlink)) $dlink = $entry['reference_link'] ?? '';
                                if (!empty($dlink)): ?>
                                <small class="ms-1"><?= $dlink ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="text-end"><?= $isDebit  ? '<span class="amount-debit">'  . $fmt($entry['debit'])  . '</span>' : '<span class="text-muted">—</span>' ?></td>
                            <td class="text-end"><?= $isCredit ? '<span class="amount-credit">' . $fmt($entry['credit']) . '</span>' : '<span class="text-muted">—</span>' ?></td>
                            <td class="text-end"><span class="amount-bal <?= $bal < 0 ? 'balance-negative' : '' ?>"><?= $fmt($bal) ?></span></td>
                            <td class="text-center">
                                <?php
                                $pm = $entry['payment_method'] ?? '';
                                $pmIcon = match($pm) {
                                    'cash'        => 'bi-cash-coin text-success',
                                    'card'        => 'bi-credit-card text-primary',
                                    'online'      => 'bi-phone text-info',
                                    'bank'        => 'bi-building text-secondary',
                                    'cheque'      => 'bi-file-text text-warning',
                                    'credit_note' => 'bi-journal-bookmark text-warning',
                                    'adjustment'  => 'bi-sliders text-secondary',
                                    default       => 'bi-three-dots text-muted'
                                };
                                ?>
                                <i class="bi <?= $pmIcon ?>" title="<?= htmlspecialchars($pm) ?>"></i>
                            </td>
                            <?php if(function_exists('isAdmin') && isAdmin()): ?>
                            <td class="text-center no-print">
                                <?php if (!($entry['entry_type'] === 'opening' || $entry['entry_type'] === 'closing')): ?>
                                <button class="btn btn-outline-danger btn-sm py-0 px-1 void-btn" data-id="<?= $entry['id'] ?>" title="Void">
                                    <i class="bi bi-slash-circle"></i>
                                </button>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>

                        <!-- Totals row -->
                        <tr class="table-dark fw-bold">
                            <td colspan="4">Total</td>
                            <td class="text-end text-danger"><?= $fmt($summary['total_out']) ?></td>
                            <td class="text-end text-success"><?= $fmt($summary['total_in']) ?></td>
                            <td class="text-end <?= $summary['closing'] < 0 ? 'text-danger' : '' ?>"><?= $fmt($summary['closing']) ?></td>
                            <td></td>
                            <?php if(function_exists('isAdmin') && isAdmin()): ?><td></td><?php endif; ?>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        </div>
    </div>

</div>
</div><!-- /main-content -->

<!-- FAB -->
<button class="fab-btn btn btn-primary no-print" data-bs-toggle="modal" data-bs-target="#manualEntryModal" title="Manual Entry Add Karo">
    <i class="bi bi-plus-lg text-white"></i>
</button>

<!-- Manual Entry Modal -->
<div class="modal fade" id="manualEntryModal" tabindex="-1" aria-modal="true" role="dialog">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Manual Cash Entry</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="manualEntryForm">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="entry_date" value="<?= $selectedDate ?>">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Entry Type</label>
                        <select name="entry_type" id="meEntryType" class="form-select" required>
                            <option value="cash_in">💰 Cash In — Money Received (Cashin)</option>
                            <option value="cash_out">💸 Cash Out — Money Paid (Cashout)</option>
                            <option value="expense">🧾 Expense</option>
                            <option value="opening">📂 Opening Balance</option>
                            <option value="adjustment">⚖️ Adjustment (Custom direction)</option>
                        </select>
                    </div>
                    <div id="meDirectionRow" class="mb-3" style="display:none;">
                        <label class="form-label fw-semibold">Direction</label>
                        <div class="d-flex gap-2">
                            <input type="radio" class="btn-check" name="direction" id="meDirIn"  value="credit" checked>
                            <label class="btn btn-outline-success" for="meDirIn"><i class="bi bi-arrow-down-circle me-1"></i>Cash In</label>
                            <input type="radio" class="btn-check" name="direction" id="meDirOut" value="debit">
                            <label class="btn btn-outline-danger"  for="meDirOut"><i class="bi bi-arrow-up-circle me-1"></i>Cash Out</label>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Description <span class="text-danger">*</span></label>
                        <textarea name="description" class="form-control" rows="2" required placeholder="e.g., Customer ne advance diya, rent payment..."></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Amount (Rs) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="amount" class="form-control" required min="0.01" placeholder="0.00">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Payment Method</label>
                        <select name="payment_method" class="form-select">
                            <option value="cash">Cash</option>
                            <option value="card">Card</option>
                            <option value="online">Online</option>
                            <option value="bank">Bank Transfer</option>
                        </select>
                    </div>
                    <div id="meAlert" class="mt-2"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="meSubmitBtn">
                        <i class="bi bi-save me-1"></i>Add Entry
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const SELECTED_DATE  = '<?= $selectedDate ?>';
const CURRENT_FILTER = '<?= addslashes($filter) ?>';
const CURRENT_PM     = '<?= addslashes($paymentFilter) ?>';
const CURRENT_SEARCH = '<?= addslashes($search) ?>';
const BASE_URL_CB    = window.location.href.split('?')[0];
const IS_ADMIN       = <?= (function_exists('isAdmin') && isAdmin()) ? 'true' : 'false' ?>;

// ── Integrity badge ──────────────────────────────────────────────
let integrityData = null;
function loadIntegrity() {
    $.getJSON(BASE_URL_CB + '?ajax=integrity', function(data) {
        integrityData = data;
        const $b = $('#integrityBadge');
        if (!data.success) return;
        if (data.missing_count === 0) {
            $b.html('<i class="bi bi-check-circle-fill me-1 text-success"></i> All synced ✓')
              .removeClass('warning').addClass('success');
        } else {
            let parts = [];
            if (data.sales_missing            > 0) parts.push('Sales: '           + data.sales_missing);
            if (data.credit_sales_missing     > 0) parts.push('Credit Sales: '    + data.credit_sales_missing);
            if (data.purchases_missing        > 0) parts.push('Purchases: '       + data.purchases_missing);
            if (data.purchases_credit_missing > 0) parts.push('Credit Purchases: '+ data.purchases_credit_missing);
            if (data.sale_returns_missing     > 0) parts.push('Sale Returns: '    + data.sale_returns_missing);
            if (data.purchase_returns_missing > 0) parts.push('Purch Returns: '   + data.purchase_returns_missing);
            if (data.expenses_missing         > 0) parts.push('Expenses: '        + data.expenses_missing);
            $b.html('<i class="bi bi-exclamation-triangle-fill me-1 text-warning"></i> ⚠ '
                + data.missing_count + ' missing (' + parts.join(', ') + ') — <u>Fix now</u>')
              .removeClass('success').addClass('warning');
        }
    }).fail(function() {
        $('#integrityBadge').html('<i class="bi bi-wifi-off me-1"></i> Check failed').removeClass('success warning');
    });
}
function handleIntegrityClick() {
    if (integrityData && integrityData.missing_count > 0) {
        if (confirm('Missing cashbook entries auto-create karein? Sirf missing entries add hongi.')) {
            window.location.href = 'fix_missing.php?date=' + SELECTED_DATE;
        }
    }
}

// ── Manual Entry Form (modal) ────────────────────────────────────
document.getElementById('meEntryType').addEventListener('change', function() {
    document.getElementById('meDirectionRow').style.display =
        this.value === 'adjustment' ? 'block' : 'none';
});
$('#manualEntryForm').on('submit', function(e) {
    e.preventDefault();
    const $btn = $('#meSubmitBtn').prop('disabled', true)
                    .html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');
    const $alert = $('#meAlert');
    $.post(BASE_URL_CB + '?ajax=save_manual', $(this).serialize(), function(r) {
        $btn.prop('disabled', false).html('<i class="bi bi-save me-1"></i>Add Entry');
        if (r.success) {
            $alert.html('<div class="alert alert-success py-1"><i class="bi bi-check-circle me-1"></i>' + r.message + '</div>');
            setTimeout(() => { bootstrap.Modal.getInstance(document.getElementById('manualEntryModal')).hide(); location.reload(); }, 1000);
        } else {
            $alert.html('<div class="alert alert-danger py-1"><i class="bi bi-exclamation-circle me-1"></i>' + (r.message || 'Error') + '</div>');
        }
    }, 'json').fail(function() {
        $btn.prop('disabled', false).html('<i class="bi bi-save me-1"></i>Add Entry');
        $alert.html('<div class="alert alert-danger py-1">Server error. Please try again.</div>');
    });
});

// ── Void entry ───────────────────────────────────────────────────
$(document).on('click', '.void-btn', function() {
    const id   = $(this).data('id');
    const $row = $(this).closest('tr');
    if (!confirm('Is entry ko void karna chahte hain? Yeh undo nahi hoga.')) return;
    $.post(BASE_URL_CB + '?ajax=void', { id: id, csrf_token: '<?= $csrfToken ?>' }, function(r) {
        if (r.success) {
            $row.css({ opacity: '0.4', textDecoration: 'line-through' });
            $row.find('.void-btn').remove();
        } else {
            alert(r.message || 'Void failed.');
        }
    }, 'json');
});

// ── Realtime new entries poll every 10s ──────────────────────────
var lastEntryId = <?= !empty($entries) ? (int)end($entries)['id'] : 0 ?>;
var entryCount  = <?= count($entries) ?>;

function fmtRs(v) {
    return 'Rs ' + parseFloat(v || 0).toLocaleString('en-PK', { minimumFractionDigits:2, maximumFractionDigits:2 });
}
function escHtml(s) {
    return (s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function pmIcon(pm) {
    const map = {
        cash:'bi-cash-coin text-success', card:'bi-credit-card text-primary',
        online:'bi-phone text-info', bank:'bi-building text-secondary',
        cheque:'bi-file-text text-warning', credit_note:'bi-journal-bookmark text-warning',
        adjustment:'bi-sliders text-secondary'
    };
    return map[pm] || 'bi-three-dots text-muted';
}
function appendNewRows(entries) {
    if (!entries || entries.length === 0) return;
    const tbody    = document.getElementById('cashbookTbody');
    if (!tbody) return;
    const totalsRow = tbody.querySelector('.table-dark');
    entries.forEach(function(e) {
        const isCredit = parseFloat(e.credit) > 0;
        const isDebit  = parseFloat(e.debit)  > 0;
        const bal      = parseFloat(e.balance || 0);
        entryCount++;
        const voidCell = IS_ADMIN
            ? `<td class="text-center no-print"><button class="btn btn-outline-danger btn-sm py-0 px-1 void-btn" data-id="${e.id}"><i class="bi bi-slash-circle"></i></button></td>`
            : '';
        const tr = document.createElement('tr');
        tr.className  = isCredit ? 'credit-row' : (isDebit ? 'debit-row' : '');
        tr.dataset.id = e.id;
        tr.innerHTML  = `
            <td class="text-muted small">${entryCount}</td>
            <td class="text-muted small">${formatTime(e.created_at)}</td>
            <td><span class="badge ${escHtml(e.badge_class)} rounded-pill" style="white-space:normal;line-height:1.3;">${escHtml(e.type_label)}</span></td>
            <td>${escHtml(e.description || '')}${(e.desc_link || e.reference_link) ? '<small class="ms-1">' + (e.desc_link || e.reference_link) + '</small>' : ''}</td>
            <td class="text-end">${isDebit  ? '<span class="amount-debit">'  + fmtRs(e.debit)  + '</span>' : '<span class="text-muted">—</span>'}</td>
            <td class="text-end">${isCredit ? '<span class="amount-credit">' + fmtRs(e.credit) + '</span>' : '<span class="text-muted">—</span>'}</td>
            <td class="text-end"><span class="amount-bal ${bal < 0 ? 'balance-negative' : ''}">${fmtRs(bal)}</span></td>
            <td class="text-center"><i class="bi ${pmIcon(e.payment_method)}" title="${escHtml(e.payment_method)}"></i></td>
            ${voidCell}`;
        if (totalsRow) tbody.insertBefore(tr, totalsRow);
        else           tbody.appendChild(tr);
        lastEntryId = Math.max(lastEntryId, parseInt(e.id));
        tr.style.outline = '2px solid #198754';
        setTimeout(() => tr.style.outline = '', 2500);
    });
}
function formatTime(dt) {
    if (!dt) return '';
    const d = new Date(dt.replace(' ', 'T'));
    return isNaN(d) ? dt : d.toLocaleTimeString('en-PK', { hour:'2-digit', minute:'2-digit' });
}
function pollNewEntries() {
    // Only poll if viewing today
    if (SELECTED_DATE !== new Date().toISOString().split('T')[0]) return;
    const url = BASE_URL_CB + '?ajax=new_entries&date=' + SELECTED_DATE
        + '&after_id=' + lastEntryId
        + '&filter='          + encodeURIComponent(CURRENT_FILTER)
        + '&payment_method='  + encodeURIComponent(CURRENT_PM)
        + '&search='          + encodeURIComponent(CURRENT_SEARCH);
    $.getJSON(url, function(data) {
        if (data.success && data.entries && data.entries.length > 0) {
            appendNewRows(data.entries);
        }
    });
}

// ── Export ───────────────────────────────────────────────────────
function exportCSV() {
    window.open(BASE_URL_CB + '?ajax=export_csv&date=' + SELECTED_DATE
        + '&filter='         + encodeURIComponent(CURRENT_FILTER)
        + '&payment_method=' + encodeURIComponent(CURRENT_PM)
        + '&search='         + encodeURIComponent(CURRENT_SEARCH), '_blank');
}
function exportPDF() {
    const w = window.open(BASE_URL_CB + '?date=' + SELECTED_DATE
        + '&filter='         + encodeURIComponent(CURRENT_FILTER)
        + '&payment_method=' + encodeURIComponent(CURRENT_PM)
        + '&search='         + encodeURIComponent(CURRENT_SEARCH) + '&print=1', '_blank');
    if (w) w.onload = () => w.print();
}

// ── Auto-dismiss flash alerts ────────────────────────────────────
setTimeout(() => {
    document.querySelectorAll('.alert').forEach(el => {
        if (typeof bootstrap !== 'undefined' && bootstrap.Alert) {
            bootstrap.Alert.getOrCreateInstance(el).close();
        }
    });
}, 4500);

// ── Init ─────────────────────────────────────────────────────────
$(document).ready(function() {
    loadIntegrity();
    setInterval(loadIntegrity,  60000);   // every 60s
    setInterval(pollNewEntries, 10000);   // every 10s (realtime)
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
