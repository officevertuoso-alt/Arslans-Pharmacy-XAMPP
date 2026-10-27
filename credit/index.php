<?php
/**
 * CREDIT/index.php — Credit Management Dashboard
 * v3.0 — Date-range stats, realtime sync with list.php, linked buttons
 */
require_once '../config/config.php';
require_once '../includes/sync_engine.php';

requireLogin();

$db = db();

// ══════════════════════════════════════════════════════════════
//  AJAX HANDLERS
// ══════════════════════════════════════════════════════════════
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache');
    $action = $_GET['ajax'];

    // ── GET: summary with optional date range ──────────────────
    if ($action === 'get_summary') {
        $dateFrom = trim($_GET['date_from'] ?? '');
        $dateTo   = trim($_GET['date_to']   ?? '');

        // Validate dates
        $hasRange = false;
        if ($dateFrom && $dateTo && strtotime($dateFrom) && strtotime($dateTo)) {
            $hasRange = true;
        }

        // Always: active credits (not date-filtered — these are current state)
        $active = $db->getOne(
            "SELECT COUNT(*) AS cnt,
                    COALESCE(SUM(
                        COALESCE(s.total, cc.total_amount)
                        - COALESCE(s.paid_amount, 0)
                        - COALESCE((SELECT SUM(cp2.amount) FROM credit_payments cp2
                                    WHERE cp2.credit_id = cc.id
                                      AND (cp2.note IS NULL OR (cp2.note NOT LIKE 'Upfront%' AND cp2.note NOT LIKE 'upfront%'))), 0)
                    ), 0) AS remaining_total
             FROM customer_credits cc
             LEFT JOIN sales s ON s.id = cc.sale_id
             WHERE cc.status = 'active'"
        );

        // Overdue (current state)
        $overdue = $db->getOne(
            "SELECT COUNT(*) AS cnt,
                    COALESCE(SUM(
                        COALESCE(s.total, cc.total_amount)
                        - COALESCE(s.paid_amount, 0)
                        - COALESCE((SELECT SUM(cp2.amount) FROM credit_payments cp2
                                    WHERE cp2.credit_id = cc.id
                                      AND (cp2.note IS NULL OR (cp2.note NOT LIKE 'Upfront%' AND cp2.note NOT LIKE 'upfront%'))), 0)
                    ), 0) AS remaining_total
             FROM customer_credits cc
             LEFT JOIN sales s ON s.id = cc.sale_id
             WHERE cc.status = 'active' AND cc.due_date < CURDATE()"
        );

        // Paid credits (current state)
        $paid = $db->getOne(
            "SELECT COUNT(*) AS cnt FROM customer_credits WHERE status = 'paid'"
        );

        // ── Date-range stats ───────────────────────────────────
        if ($hasRange) {
            // Due in range: credits whose due_date falls in range
            $dueInRange = $db->getOne(
                "SELECT COUNT(*) AS cnt,
                        COALESCE(SUM(
                            COALESCE(s.total, cc.total_amount)
                            - COALESCE(s.paid_amount, 0)
                            - COALESCE((SELECT SUM(cp2.amount) FROM credit_payments cp2
                                        WHERE cp2.credit_id = cc.id
                                          AND (cp2.note IS NULL OR (cp2.note NOT LIKE 'Upfront%' AND cp2.note NOT LIKE 'upfront%'))), 0)
                        ), 0) AS amount
                 FROM customer_credits cc
                 LEFT JOIN sales s ON s.id = cc.sale_id
                 WHERE cc.due_date BETWEEN ? AND ?",
                [$dateFrom, $dateTo]
            );

            // Pending (active) credits created in range
            $pendingInRange = $db->getOne(
                "SELECT COUNT(*) AS cnt,
                        COALESCE(SUM(
                            COALESCE(s.total, cc.total_amount)
                            - COALESCE(s.paid_amount, 0)
                            - COALESCE((SELECT SUM(cp2.amount) FROM credit_payments cp2
                                        WHERE cp2.credit_id = cc.id
                                          AND (cp2.note IS NULL OR (cp2.note NOT LIKE 'Upfront%' AND cp2.note NOT LIKE 'upfront%'))), 0)
                        ), 0) AS amount
                 FROM customer_credits cc
                 LEFT JOIN sales s ON s.id = cc.sale_id
                 WHERE cc.status = 'active' AND DATE(cc.created_at) BETWEEN ? AND ?",
                [$dateFrom, $dateTo]
            );

            // Collected in range: payments made in the date range
            $collectedInRange = $db->getOne(
                "SELECT COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS amount
                 FROM credit_payments
                 WHERE DATE(payment_date) BETWEEN ? AND ?
                   AND (note IS NULL OR (note NOT LIKE 'Upfront%' AND note NOT LIKE 'upfront%'))",
                [$dateFrom, $dateTo]
            );
        } else {
            // Default: today & this month
            $today         = date('Y-m-d');
            $firstDayMonth = date('Y-m-01');

            $dueInRange = $db->getOne(
                "SELECT COUNT(*) AS cnt,
                        COALESCE(SUM(
                            COALESCE(s.total, cc.total_amount)
                            - COALESCE(s.paid_amount, 0)
                            - COALESCE((SELECT SUM(cp2.amount) FROM credit_payments cp2
                                        WHERE cp2.credit_id = cc.id
                                          AND (cp2.note IS NULL OR (cp2.note NOT LIKE 'Upfront%' AND cp2.note NOT LIKE 'upfront%'))), 0)
                        ), 0) AS amount
                 FROM customer_credits cc
                 LEFT JOIN sales s ON s.id = cc.sale_id
                 WHERE cc.due_date = ?",
                [$today]
            );

            $pendingInRange = $db->getOne(
                "SELECT COUNT(*) AS cnt,
                        COALESCE(SUM(
                            COALESCE(s.total, cc.total_amount)
                            - COALESCE(s.paid_amount, 0)
                            - COALESCE((SELECT SUM(cp2.amount) FROM credit_payments cp2
                                        WHERE cp2.credit_id = cc.id
                                          AND (cp2.note IS NULL OR (cp2.note NOT LIKE 'Upfront%' AND cp2.note NOT LIKE 'upfront%'))), 0)
                        ), 0) AS amount
                 FROM customer_credits cc
                 LEFT JOIN sales s ON s.id = cc.sale_id
                 WHERE cc.status = 'active' AND DATE(cc.created_at) BETWEEN ? AND ?",
                [$firstDayMonth, $today]
            );

            $collectedInRange = $db->getOne(
                "SELECT COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS amount
                 FROM credit_payments
                 WHERE DATE(payment_date) BETWEEN ? AND ?
                   AND (note IS NULL OR (note NOT LIKE 'Upfront%' AND note NOT LIKE 'upfront%'))",
                [$firstDayMonth, $today]
            );
        }

        echo json_encode([
            'success'               => true,
            'active_count'          => (int)($active['cnt']               ?? 0),
            'active_remaining'      => (float)($active['remaining_total'] ?? 0),
            'overdue_count'         => (int)($overdue['cnt']              ?? 0),
            'overdue_remaining'     => (float)($overdue['remaining_total']?? 0),
            'paid_count'            => (int)($paid['cnt']                 ?? 0),
            'range_due_count'       => (int)($dueInRange['cnt']           ?? 0),
            'range_due_amount'      => (float)($dueInRange['amount']      ?? 0),
            'range_pending_count'   => (int)($pendingInRange['cnt']       ?? 0),
            'range_pending_amount'  => (float)($pendingInRange['amount']  ?? 0),
            'range_collected_count' => (int)($collectedInRange['cnt']     ?? 0),
            'range_collected_amount'=> (float)($collectedInRange['amount']?? 0),
            'has_range'             => $hasRange,
            'date_from'             => $dateFrom,
            'date_to'               => $dateTo,
        ]);
        exit;
    }

    // ── GET: overdue list ──────────────────────────────────────
    if ($action === 'get_overdue') {
        $overdue = $db->getAll(
            "SELECT cc.id, cc.customer_name, cc.customer_phone,
                    COALESCE(s.total, cc.total_amount) AS display_total,
                    COALESCE(s.paid_amount, 0) AS sale_upfront,
                    COALESCE(SUM(CASE WHEN (cp.note IS NULL OR (cp.note NOT LIKE 'Upfront%' AND cp.note NOT LIKE 'upfront%')) THEN cp.amount ELSE 0 END), 0) AS post_paid,
                    cc.due_date, s.invoice_no
             FROM customer_credits cc
             LEFT JOIN credit_payments cp ON cp.credit_id = cc.id
             LEFT JOIN sales s ON s.id = cc.sale_id
             WHERE cc.status = 'active' AND cc.due_date < CURDATE()
             GROUP BY cc.id
             ORDER BY cc.due_date ASC"
        );
        foreach ($overdue as &$o) {
            $o['remaining'] = max(0, (float)$o['display_total'] - (float)$o['sale_upfront'] - (float)$o['post_paid']);
        }
        unset($o);
        echo json_encode(['success' => true, 'overdue' => $overdue]);
        exit;
    }

    // ── GET: single credit + payments ─────────────────────────
    if ($action === 'get_credit') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) { echo json_encode(['success' => false, 'message' => 'Invalid ID']); exit; }
        try {
            $db->query(
                "SELECT cc.*, s.invoice_no, s.sale_date, s.total AS sale_total, s.paid_amount AS sale_upfront
                 FROM customer_credits cc LEFT JOIN sales s ON s.id = cc.sale_id WHERE cc.id = :id",
                [':id' => $id]
            );
            $credit = $db->single();
            if (!$credit) { echo json_encode(['success' => false, 'message' => 'Not found']); exit; }
            $paidRow     = $db->getOne("SELECT COALESCE(SUM(amount),0) AS paid FROM credit_payments WHERE credit_id = :cid AND (note IS NULL OR (note NOT LIKE 'Upfront%' AND note NOT LIKE 'upfront%'))", [':cid' => $id]);
            $saleTotal   = (float)($credit['sale_total']   ?? $credit['total_amount'] ?? 0);
            $saleUpfront = (float)($credit['sale_upfront'] ?? 0);
            $postPaid    = (float)($paidRow['paid']        ?? 0);
            $credit['display_total'] = $saleTotal;
            $credit['paid']          = $saleUpfront + $postPaid;
            $credit['remaining']     = max(0, $saleTotal - $saleUpfront - $postPaid);
            $db->query("SELECT *, DATE_FORMAT(payment_date,'%d %b %Y %H:%i') AS fmt_date FROM credit_payments WHERE credit_id = :cid ORDER BY payment_date DESC", [':cid' => $id]);
            $payments = $db->resultSet();
            echo json_encode(['success' => true, 'credit' => $credit, 'payments' => is_array($payments) ? $payments : []]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ── POST: add manual credit ────────────────────────────────
    if ($action === 'add_credit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $customer_name  = trim($_POST['customer_name']  ?? '');
        $customer_phone = trim($_POST['customer_phone'] ?? '');
        $amount         = floatval($_POST['amount']      ?? 0);
        $due_date       = trim($_POST['due_date']        ?? '');
        $description    = trim($_POST['description']     ?? '');
        if (!$customer_name)  { echo json_encode(['success' => false, 'message' => 'Customer name required']); exit; }
        if (!$customer_phone) { echo json_encode(['success' => false, 'message' => 'Phone required']); exit; }
        if ($amount <= 0)     { echo json_encode(['success' => false, 'message' => 'Amount must be > 0']); exit; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due_date) || !strtotime($due_date)) {
            $due_date = date('Y-m-d', strtotime('+7 days'));
        }
        $db->query("INSERT INTO customer_credits (sale_id,customer_name,customer_phone,total_amount,due_date,description,status,created_at) VALUES (NULL,?,?,?,?,?,'active',NOW())",
            [$customer_name, $customer_phone, $amount, $due_date, $description]);
        $newId = $db->lastId();
        if (!$newId) { echo json_encode(['success' => false, 'message' => 'DB insert failed']); exit; }
        $db->query("INSERT IGNORE INTO customers (name,phone) VALUES (?,?)", [$customer_name, $customer_phone]);
        if (!empty($_SESSION['user_id'])) {
            $db->query("INSERT INTO activity_logs (user_id,action,description,ip_address,user_agent,created_at) VALUES (?,?,?,?,?,NOW())",
                [$_SESSION['user_id'], 'Credit Added', "Manual credit: {$customer_name} Rs ".number_format($amount,2), $_SERVER['REMOTE_ADDR']??'::1', $_SERVER['HTTP_USER_AGENT']??'']);
        }
        echo json_encode(['success' => true, 'id' => $newId, 'message' => 'Credit added successfully']);
        exit;
    }

    // ── POST: record payment ───────────────────────────────────
    if ($action === 'pay_credit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $credit_id      = (int)($_POST['credit_id']      ?? 0);
        $amount         = floatval($_POST['amount']        ?? 0);
        $payment_method = trim($_POST['payment_method']    ?? 'cash');
        $note           = trim($_POST['note']              ?? '');
        if (!in_array($payment_method, ['cash','card','online'])) $payment_method = 'cash';
        if ($credit_id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid credit ID']); exit; }
        if ($amount  <= 0)   { echo json_encode(['success' => false, 'message' => 'Amount must be > 0']); exit; }
        $credit = $db->getOne("SELECT cc.*, s.total AS sale_total, s.paid_amount AS sale_upfront FROM customer_credits cc LEFT JOIN sales s ON s.id = cc.sale_id WHERE cc.id = :id AND cc.status = 'active'", [':id' => $credit_id]);
        if (!$credit) { echo json_encode(['success' => false, 'message' => 'Credit not found or already paid']); exit; }
        $paidRow     = $db->getOne("SELECT COALESCE(SUM(amount),0) AS paid FROM credit_payments WHERE credit_id = :cid AND (note IS NULL OR (note NOT LIKE 'Upfront%' AND note NOT LIKE 'upfront%'))", [':cid' => $credit_id]);
        $saleTotal   = (float)($credit['sale_total']   ?? $credit['total_amount'] ?? 0);
        $saleUpfront = (float)($credit['sale_upfront'] ?? 0);
        $postPaid    = (float)($paidRow['paid']        ?? 0);
        $remaining   = max(0, $saleTotal - $saleUpfront - $postPaid);
        if ($amount > $remaining + 0.01) { echo json_encode(['success' => false, 'message' => 'Amount exceeds remaining Rs '.number_format($remaining,2)]); exit; }
        $db->beginTransaction();
        try {
            $db->query("INSERT INTO credit_payments (credit_id,amount,payment_method,note,payment_date) VALUES (?,?,?,?,NOW())",
                [$credit_id, round($amount,2), $payment_method, $note]);
            $paymentId   = $db->lastId();
            $newPostPaid = $postPaid + $amount;
            $newRemaining= max(0, $saleTotal - $saleUpfront - $newPostPaid);
            $fullyPaid   = $newRemaining < 0.01;
            if ($fullyPaid) { $db->query("UPDATE customer_credits SET status='paid' WHERE id=?", [$credit_id]); }
            if (!empty($credit['sale_id'])) {
                $saleRow = $db->getOne("SELECT paid_amount, total FROM sales WHERE id = :sid", [':sid' => $credit['sale_id']]);
                if ($saleRow) {
                    $newSalePaid = round((float)$saleRow['paid_amount'] + $amount, 2);
                    $payStat     = ($newSalePaid >= (float)$saleRow['total']) ? 'paid' : ($newSalePaid > 0 ? 'partial' : 'pending');
                    $db->query("UPDATE sales SET paid_amount = ?, payment_status = ? WHERE id = ?", [$newSalePaid, $payStat, $credit['sale_id']]);
                }
            }
            $db->commit();
            if (function_exists('clearAllCaches'))           clearAllCaches();
            if (function_exists('invalidateDashboardCache')) invalidateDashboardCache();
            if (function_exists('logActivity'))              logActivity('Credit Payment', "Rs ".number_format($amount,2)." from ".($credit['customer_name'] ?? '')." (Credit #{$credit_id})".($fullyPaid?' — FULLY PAID':''));
            echo json_encode([
                'success'       => true,
                'fully_paid'    => $fullyPaid,
                'new_remaining' => round($newRemaining,2),
                'new_paid'      => round($saleUpfront + $newPostPaid, 2),
                'new_status'    => $fullyPaid ? 'paid' : 'active',
                'sale_total'    => $saleTotal,
                'message'       => $fullyPaid ? 'Credit fully paid!' : 'Payment of Rs '.number_format($amount,2).' recorded',
            ]);
        } catch (Exception $e) {
            $db->rollback();
            echo json_encode(['success' => false, 'message' => 'Error: '.$e->getMessage()]);
        }
        exit;
    }

    // ── POST: bulk mark overdue ────────────────────────────────
    if ($action === 'mark_overdue' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $ids = $_POST['credit_ids'] ?? [];
        if (!is_array($ids) || empty($ids)) { echo json_encode(['success' => false, 'message' => 'No credits selected']); exit; }
        $ids = array_map('intval', $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $db->beginTransaction();
        try {
            $db->query("UPDATE customer_credits SET description = CONCAT(COALESCE(description,''),' [Overdue noted: ',CURDATE(),']') WHERE id IN ($placeholders) AND status='active'", $ids);
            $db->commit();
            echo json_encode(['success' => true, 'message' => count($ids).' credits noted as overdue']);
        } catch (Exception $e) {
            $db->rollback();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ── Export CSV ─────────────────────────────────────────────
    if ($action === 'export') {
        $filter   = $_GET['filter']    ?? 'all';
        $search   = trim($_GET['search']   ?? '');
        $dateFrom = trim($_GET['date_from']?? '');
        $dateTo   = trim($_GET['date_to']  ?? '');
        $wh = []; $pr = [];
        switch ($filter) {
            case 'pending': $wh[] = "cc.status='active'"; break;
            case 'paid':    $wh[] = "cc.status='paid'";   break;
            case 'overdue': $wh[] = "cc.status='active'"; $wh[] = "cc.due_date<CURDATE()"; break;
            default:        $wh[] = "cc.status IN ('active','paid')";
        }
        if ($search !== '') { $wh[]="(cc.customer_name LIKE ? OR cc.customer_phone LIKE ? OR s.invoice_no LIKE ?)"; $pr[]="%$search%"; $pr[]="%$search%"; $pr[]="%$search%"; }
        if ($dateFrom && $dateTo) { $wh[] = "cc.due_date BETWEEN ? AND ?"; $pr[] = $dateFrom; $pr[] = $dateTo; }
        $whSQL = $wh ? implode(' AND ',$wh) : '1';
        $bindP = !empty($pr) ? $pr : null;
        $rows  = $db->getAll("SELECT cc.id,cc.customer_name,cc.customer_phone,cc.total_amount,COALESCE(SUM(cp.amount),0) AS paid,(cc.total_amount-COALESCE(SUM(cp.amount),0)) AS remaining,cc.due_date,cc.status,cc.created_at,s.invoice_no,s.sale_date FROM customer_credits cc LEFT JOIN credit_payments cp ON cp.credit_id=cc.id LEFT JOIN sales s ON s.id=cc.sale_id WHERE $whSQL GROUP BY cc.id ORDER BY cc.due_date ASC", $bindP);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="credit_report_'.date('Ymd_His').'.csv"');
        $out = fopen('php://output','w'); fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($out,['ID','Customer','Phone','Invoice','Sale Date','Total','Paid','Remaining','Due Date','Status']);
        foreach ($rows as $r) { fputcsv($out,[$r['id'],$r['customer_name'],$r['customer_phone'],$r['invoice_no']??'Manual',$r['sale_date']??'',number_format($r['total_amount'],2),number_format($r['paid'],2),number_format($r['remaining'],2),$r['due_date'],$r['status']]); }
        fclose($out); exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']); exit;
}

// ══════════════════════════════════════════════════════════════
//  PAGE DATA
// ══════════════════════════════════════════════════════════════
$listURL      = BASE_URL . 'credit/list.php';
$selfURL      = BASE_URL . 'credit/index.php';
$filter       = $_GET['filter'] ?? 'all';
$search       = trim($_GET['search'] ?? '');
$dateFromInit = $_GET['date_from'] ?? '';
$dateToInit   = $_GET['date_to']   ?? date('Y-m-d');
if (!$dateFromInit) $dateFromInit = date('Y-m-01'); // Default: first of month

$where = []; $params = [];
switch ($filter) {
    case 'pending': $where[] = "cc.status='active'"; break;
    case 'paid':    $where[] = "cc.status='paid'";   break;
    case 'overdue': $where[] = "cc.status='active'"; $where[] = "cc.due_date<CURDATE()"; break;
    default:        $where[] = "cc.status IN ('active','paid')";
}
if ($search !== '') {
    $where[] = "(cc.customer_name LIKE ? OR cc.customer_phone LIKE ? OR s.invoice_no LIKE ? OR cc.description LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
$whereSQL  = $where ? implode(' AND ',$where) : '1';
$bindP     = !empty($params) ? $params : null;

$credits = $db->getAll(
    "SELECT cc.*,
            COALESCE(s.total, cc.total_amount) AS display_total,
            COALESCE(s.paid_amount, 0) AS sale_upfront,
            COALESCE(SUM(CASE WHEN (cp.note IS NULL OR (cp.note NOT LIKE 'Upfront%' AND cp.note NOT LIKE 'upfront%')) THEN cp.amount ELSE 0 END),0) AS post_sale_paid,
            s.invoice_no, s.sale_date, s.id AS linked_sale_id
     FROM customer_credits cc
     LEFT JOIN credit_payments cp ON cp.credit_id = cc.id
     LEFT JOIN sales s ON s.id = cc.sale_id
     WHERE $whereSQL GROUP BY cc.id
     ORDER BY CASE WHEN cc.status='active' AND cc.due_date<CURDATE() THEN 0 WHEN cc.status='active' THEN 1 ELSE 2 END, cc.due_date ASC",
    $bindP
);

// Quick counts for filter pills
$counts = $db->getOne(
    "SELECT
        COUNT(*) AS total_all,
        SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) AS total_active,
        SUM(CASE WHEN status='active' AND due_date<CURDATE() THEN 1 ELSE 0 END) AS total_overdue,
        SUM(CASE WHEN status='paid' THEN 1 ELSE 0 END) AS total_paid
     FROM customer_credits"
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php include dirname(__DIR__) . '/includes/page_head.php'; ?>


<style>
:root{--bg:#f0f3f8;--card:#fff;--pri:#1a56db;--dan:#e02424;--suc:#057a55;--warn:#d97706;--mu:#6b7280;--bo:#e5e7eb;--tx:#111827;}
body{background:var(--bg);font-family:'Segoe UI',system-ui,sans-serif;font-size:13px;color:var(--tx);}
.main-content{margin-left: var(--sb-w, 260px); transition: margin-left .25s ease;padding:20px 24px;}
body.sb-col-body .main-content { margin-left: var(--sb-w-mini, 62px); }
        @media (max-width: 991.98px) { .main-content { margin-left: 0 !important; } }

/* Page fade-in */
@keyframes fadeInPage{from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:translateY(0);}}
.main-content{animation:fadeInPage 0.28s ease;}

/* Stat Cards */
.sc{background:var(--card);border-radius:14px;padding:16px 20px;box-shadow:0 1px 4px rgba(0,0,0,.08);border-left:4px solid transparent;transition:transform 0.2s ease,box-shadow 0.2s ease;}
.sc:hover{box-shadow:0 6px 20px rgba(0,0,0,.13);transform:translateY(-3px);}
.sc-blue{border-left-color:var(--pri)}.sc-red{border-left-color:var(--dan)}.sc-green{border-left-color:var(--suc)}.sc-orange{border-left-color:var(--warn)}
.sc-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--mu);}
.sc-val{font-size:22px;font-weight:800;margin:4px 0 2px;line-height:1.1;}
.sc-sub{font-size:11px;color:var(--mu);}
.sc-icon{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;}
.sc-icon.blue{background:#eff6ff;color:var(--pri)}.sc-icon.red{background:#fef2f2;color:var(--dan)}.sc-icon.green{background:#f0fdf4;color:var(--suc)}.sc-icon.orange{background:#fffbeb;color:var(--warn)}

/* Date Range Bar */
.range-bar{background:var(--card);border-radius:12px;padding:12px 18px;box-shadow:0 1px 4px rgba(0,0,0,.07);margin-bottom:18px;}
.range-bar .form-control,.range-bar .form-select{font-size:12px;border-radius:8px;}
.range-label{font-size:11px;font-weight:700;color:var(--mu);text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;}

/* Range stat mini cards */
.rsc{background:#f8fafc;border:1px solid var(--bo);border-radius:10px;padding:10px 14px;text-align:center;}
.rsc-val{font-size:18px;font-weight:800;margin:2px 0;}
.rsc-lbl{font-size:10px;font-weight:700;text-transform:uppercase;color:var(--mu);letter-spacing:.04em;}
.rsc-cnt{font-size:10px;color:var(--mu);}

/* Table */
.tcard{background:var(--card);border-radius:14px;box-shadow:0 1px 4px rgba(0,0,0,.07);overflow:hidden;}
.tcard thead th{background:#f9fafb;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;padding:10px 14px;border-bottom:1px solid var(--bo);white-space:nowrap;}
.tcard tbody td{padding:10px 14px;vertical-align:middle;border-bottom:1px solid #f3f4f6;}
.tcard tbody tr:last-child td{border-bottom:none;}
.tcard tbody tr:hover{background:#f9fafb;}
.tcard tbody tr.ov-row{background:#fff5f5;}

/* Badges */
.bac{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:20px;padding:3px 10px;font-size:11px;font-weight:600;}
.bpd{background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;border-radius:20px;padding:3px 10px;font-size:11px;font-weight:600;}
.bov{background:#fef2f2;color:#dc2626;border:1px solid #fecaca;border-radius:20px;padding:3px 10px;font-size:11px;font-weight:600;animation:pulse 1.6s infinite;}
.bpt{background:#fffbeb;color:#d97706;border:1px solid #fde68a;border-radius:20px;padding:3px 10px;font-size:11px;font-weight:600;}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.6}}

/* Progress */
.ppb{background:#f3f4f6;border-radius:99px;overflow:hidden;height:5px;margin-bottom:2px;}
.ppf{height:100%;border-radius:99px;background:linear-gradient(90deg,#1a56db,#3b82f6);}

/* Filter pills */
.filter-pill{display:inline-flex;align-items:center;gap:5px;padding:5px 13px;border-radius:20px;font-size:12px;font-weight:600;text-decoration:none;border:1px solid var(--bo);color:var(--mu);background:#fff;transition:all .15s;}
.filter-pill:hover{border-color:var(--pri);color:var(--pri);}
.filter-pill.active{background:var(--pri);color:#fff;border-color:var(--pri);}
.filter-pill .cnt{background:rgba(0,0,0,.1);border-radius:99px;padding:1px 7px;font-size:10px;}
.filter-pill.active .cnt{background:rgba(255,255,255,.25);}

/* Payment history inline */
.pay-hist{background:#f8fafc;border-radius:10px;padding:10px 14px;display:none;margin-top:6px;font-size:11px;}
.pay-hist table{width:100%;}

/* Toast */
.toast-container{position:fixed;bottom:20px;right:20px;z-index:9999;}

/* Sync indicator */
.sync-dot{width:8px;height:8px;border-radius:50%;background:#22c55e;display:inline-block;margin-right:5px;animation:syncPulse 2s infinite;}
@keyframes syncPulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(.8)}}
.last-sync{font-size:10px;color:var(--mu);}
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

<!-- ── Breadcrumb ──────────────────────────────────────────── -->
<nav aria-label="breadcrumb" class="mb-4">
    <ol class="breadcrumb" style="font-size:0.82rem;background:var(--card);padding:10px 16px;border-radius:10px;border:1px solid var(--bo);box-shadow:0 1px 4px rgba(0,0,0,.05);">
        <li class="breadcrumb-item">
            <a href="<?php echo BASE_URL; ?>dashboard.php" style="color:var(--pri);text-decoration:none;">
                <i class="fas fa-home me-1"></i>Dashboard
            </a>
        </li>
        <li class="breadcrumb-item">
            <a href="<?php echo BASE_URL; ?>credit/index.php" style="color:var(--pri);text-decoration:none;">Credit</a>
        </li>
        <li class="breadcrumb-item active fw-semibold" aria-current="page">Manage Credits</li>
    </ol>
</nav>

<!-- ── Header ──────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-1 fw-bold"><i class="fas fa-credit-card me-2 text-primary"></i>Credit Dashboard</h4>
        <p class="text-muted mb-0" style="font-size:12px">
            <span class="sync-dot"></span>
            Live sync &nbsp;|&nbsp; <span class="last-sync" id="lastSync">Refreshing…</span>
        </p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <!-- Customers button → sales/customers.php -->
        <a href="<?php echo BASE_URL; ?>sales/customers.php" class="btn btn-outline-primary btn-sm">
            <i class="fas fa-users me-1"></i>Customers
        </a>
        <!-- Credit List button → list.php -->
        <a href="<?php echo $listURL; ?>" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-list me-1"></i>Credit List
        </a>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addM"
                data-requires-perm="credit.add">
            <i class="fas fa-plus me-1"></i>Add Manual Credit
        </button>
        <a href="?ajax=export&filter=<?php echo urlencode($filter); ?>&search=<?php echo urlencode($search); ?>&date_from=<?php echo urlencode($dateFromInit); ?>&date_to=<?php echo urlencode($dateToInit); ?>"
           class="btn btn-outline-success btn-sm" data-requires-perm="credit.export">
            <i class="fas fa-download me-1"></i>Export CSV
        </a>
    </div>
</div>

<!-- ── Overall Stat Cards (always current state) ───────────── -->
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="sc sc-red">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="sc-label">Active Credits</div>
                    <div class="sc-val text-danger" id="statActiveTotal">Rs —</div>
                    <div class="sc-sub"><span id="statActiveCount">—</span> pending</div>
                </div>
                <div class="sc-icon red"><i class="fas fa-hourglass-half"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="sc sc-orange">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="sc-label">Overdue</div>
                    <div class="sc-val text-warning" id="statOverdueTotal">Rs —</div>
                    <div class="sc-sub"><span id="statOverdueCount">—</span> overdue</div>
                </div>
                <div class="sc-icon orange"><i class="fas fa-exclamation-triangle"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="sc sc-green">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="sc-label">Paid Credits</div>
                    <div class="sc-val text-success" id="statPaidCount">—</div>
                    <div class="sc-sub">fully settled</div>
                </div>
                <div class="sc-icon green"><i class="fas fa-check-circle"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="sc sc-blue">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="sc-label">Range Collected</div>
                    <div class="sc-val text-primary" id="statRangeCollected">Rs —</div>
                    <div class="sc-sub"><span id="statRangeCollectedCount">—</span> payments</div>
                </div>
                <div class="sc-icon blue"><i class="fas fa-hand-holding-usd"></i></div>
            </div>
        </div>
    </div>
</div>

<!-- ── Date Range Bar ───────────────────────────────────────── -->
<div class="range-bar mb-3">
    <div class="row align-items-end g-2">
        <div class="col-auto">
            <div class="range-label">Date Range</div>
            <div class="d-flex gap-2 align-items-center flex-wrap">
                <div>
                    <label class="form-label mb-1" style="font-size:11px;color:var(--mu)">From</label>
                    <input type="date" id="dateFrom" class="form-control form-control-sm" value="<?php echo htmlspecialchars($dateFromInit); ?>" style="width:140px">
                </div>
                <div>
                    <label class="form-label mb-1" style="font-size:11px;color:var(--mu)">To</label>
                    <input type="date" id="dateTo" class="form-control form-control-sm" value="<?php echo htmlspecialchars($dateToInit); ?>" style="width:140px">
                </div>
                <div style="margin-top:18px">
                    <div class="d-flex gap-1">
                        <button class="btn btn-primary btn-sm" onclick="applyRange()"><i class="fas fa-search me-1"></i>Apply</button>
                        <button class="btn btn-outline-secondary btn-sm" onclick="setPreset('today')">Today</button>
                        <button class="btn btn-outline-secondary btn-sm" onclick="setPreset('week')">Week</button>
                        <button class="btn btn-outline-secondary btn-sm" onclick="setPreset('month')">Month</button>
                        <button class="btn btn-outline-secondary btn-sm" onclick="setPreset('year')">Year</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Range Result Mini Cards -->
    <div class="row g-2 mt-2" id="rangeStats">
        <div class="col-4">
            <div class="rsc">
                <div class="rsc-lbl">Due in Range</div>
                <div class="rsc-val text-warning" id="rangeDueAmt">Rs —</div>
                <div class="rsc-cnt" id="rangeDueCnt">— credits</div>
            </div>
        </div>
        <div class="col-4">
            <div class="rsc">
                <div class="rsc-lbl">New Pending</div>
                <div class="rsc-val text-danger" id="rangePendingAmt">Rs —</div>
                <div class="rsc-cnt" id="rangePendingCnt">— credits</div>
            </div>
        </div>
        <div class="col-4">
            <div class="rsc">
                <div class="rsc-lbl">Collected</div>
                <div class="rsc-val text-success" id="rangeCollectedAmt">Rs —</div>
                <div class="rsc-cnt" id="rangeCollectedCnt">— payments</div>
            </div>
        </div>
    </div>
</div>

<!-- ── Filter Bar ───────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div class="d-flex gap-2 flex-wrap">
        <?php
        $pills = [
            'all'     => ['All',     (int)($counts['total_all']     ?? 0)],
            'pending' => ['Active',  (int)($counts['total_active']  ?? 0)],
            'overdue' => ['Overdue', (int)($counts['total_overdue'] ?? 0)],
            'paid'    => ['Paid',    (int)($counts['total_paid']    ?? 0)],
        ];
        foreach ($pills as $key => [$label, $cnt]):
            $active = ($filter === $key) ? 'active' : '';
        ?>
        <a href="?filter=<?php echo $key; ?><?php echo $search ? '&search='.urlencode($search) : ''; ?>"
           class="filter-pill <?php echo $active; ?>">
            <?php echo $label; ?> <span class="cnt"><?php echo $cnt; ?></span>
        </a>
        <?php endforeach; ?>
    </div>
    <form method="GET" class="d-flex gap-2">
        <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
        <input type="text" name="search" class="form-control form-control-sm" placeholder="Name, phone, invoice…" value="<?php echo htmlspecialchars($search); ?>" style="width:220px">
        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i></button>
        <?php if ($search): ?>
        <a href="?filter=<?php echo $filter; ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-times"></i></a>
        <?php endif; ?>
    </form>
</div>

<!-- ── Credits Table ────────────────────────────────────────── -->
<div class="tcard">
    <table class="table table-hover mb-0" id="creditIndexTable">
                <th>Total</th><th>Paid</th><th>Remaining</th>
                <th>Due Date</th><th>Progress</th><th>Status</th><th>Actions</th>
            </tr>
        </thead>
        <tbody id="creditsBody">
        <?php if (!empty($credits)): ?>
            <?php foreach ($credits as $cr):
                $total     = (float)($cr['display_total']  ?? $cr['total_amount']);
                $upfront   = (float)($cr['sale_upfront']   ?? 0);
                $postPaid  = (float)($cr['post_sale_paid'] ?? 0);
                $paid      = $upfront + $postPaid;
                $remaining = max(0, $total - $paid);
                $pct       = $total > 0 ? min(100, ($paid / $total) * 100) : 0;
                $today     = date('Y-m-d');
                $isOverdue = ($cr['status'] === 'active' && $cr['due_date'] < $today && $remaining > 0.005);
                $isPaid    = ($cr['status'] === 'paid' || $remaining <= 0.005);
                $badge     = $isPaid
                    ? '<span class="bpd"><i class="fas fa-check-circle me-1"></i>Paid</span>'
                    : ($isOverdue
                        ? '<span class="bov"><i class="fas fa-exclamation-triangle me-1"></i>Overdue</span>'
                        : ($postPaid > 0
                            ? '<span class="bpt"><i class="fas fa-adjust me-1"></i>Partial</span>'
                            : '<span class="bac"><i class="fas fa-circle me-1"></i>Active</span>'));
            ?>
            <tr data-credit-id="<?php echo $cr['id']; ?>" class="<?php echo $isOverdue ? 'ov-row' : ''; ?>">
                <td><input type="checkbox" class="cCb" value="<?php echo $cr['id']; ?>"></td>
                <td class="text-muted"><?php echo $cr['id']; ?></td>
                <td>
                    <strong><?php echo htmlspecialchars($cr['customer_name']); ?></strong><br>
                    <small class="text-muted"><?php echo htmlspecialchars($cr['customer_phone']); ?></small>
                </td>
                <td>
                    <?php if (!empty($cr['invoice_no'])): ?>
                        <a href="<?php echo BASE_URL; ?>sales/view.php?id=<?php echo $cr['linked_sale_id'] ?? $cr['sale_id']; ?>" target="_blank" style="color:var(--pri);font-weight:600">
                            <i class="fas fa-receipt me-1" style="font-size:10px"></i><?php echo htmlspecialchars($cr['invoice_no']); ?>
                        </a>
                    <?php else: ?>
                        <span class="text-muted"><i class="fas fa-hand-holding-usd me-1"></i>Manual</span>
                    <?php endif; ?>
                </td>
                <td class="fw-semibold">Rs <?php echo number_format($total, 2); ?></td>
                <td class="text-success">Rs <?php echo number_format($paid, 2); ?></td>
                <td class="fw-bold <?php echo $remaining > 0.005 ? 'text-danger' : 'text-success'; ?>">
                    Rs <?php echo number_format($remaining, 2); ?>
                </td>
                <td class="<?php echo $isOverdue ? 'text-danger fw-bold' : ''; ?>">
                    <?php echo $cr['due_date'] ? date('d M Y', strtotime($cr['due_date'])) : '—'; ?>
                </td>
                <td style="min-width:80px">
                    <div class="ppb"><div class="ppf" style="width:<?php echo round($pct,1); ?>%"></div></div>
                    <small class="text-muted"><?php echo round($pct); ?>%</small>
                </td>
                <td><?php echo $badge; ?></td>
                <td>
                    <div class="d-flex gap-1 flex-wrap">
                        <button class="btn btn-outline-primary btn-sm" title="Payment history" onclick="toggleHist(<?php echo $cr['id']; ?>)">
                            <i class="fas fa-history"></i>
                        </button>
                        <?php if (!$isPaid): ?>
                        <button class="btn btn-success btn-sm" title="Record payment"
                            data-requires-perm="credit.pay"
                            onclick="showPayModal(<?php echo $cr['id']; ?>,'<?php echo htmlspecialchars(addslashes($cr['customer_name'])); ?>',<?php echo $remaining; ?>)">
                            <i class="fas fa-money-bill-wave"></i>
                        </button>
                        <?php endif; ?>
                        <a href="<?php echo $listURL; ?>?tab=invoices&search=<?php echo urlencode($cr['customer_phone']); ?>"
                           class="btn btn-outline-secondary btn-sm" title="View in Credit List" target="_blank">
                            <i class="fas fa-external-link-alt"></i>
                        </a>
                    </div>
                    <div id="hist_<?php echo $cr['id']; ?>" class="pay-hist"></div>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr><td colspan="11" class="text-center py-5 text-muted">
                <i class="fas fa-inbox fa-2x mb-2 d-block opacity-25"></i>No credits found
            </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    <?php if (!empty($credits) && $filter !== 'paid'): ?>
    <div class="p-3 border-top d-flex justify-content-between align-items-center">
        <small class="text-muted"><?php echo count($credits); ?> record(s)</small>
        <button class="btn btn-outline-danger btn-sm" id="bulkBtn" data-requires-perm="credit.mark_overdue">
            <i class="fas fa-exclamation-triangle me-1"></i>Mark Selected Overdue (add note)
        </button>
    </div>
    <?php endif; ?>
</div>

</div></div>

<!-- ── Add Credit Modal ─────────────────────────────────────── -->
<div class="modal fade" id="addM" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header bg-primary text-white">
        <h6 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Add Manual Credit</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
        <div class="mb-2"><label class="form-label">Customer Name *</label><input type="text" id="addName" class="form-control form-control-sm" required></div>
        <div class="mb-2"><label class="form-label">Phone *</label><input type="text" id="addPhone" class="form-control form-control-sm" required></div>
        <div class="mb-2"><label class="form-label">Amount (Rs) *</label><input type="number" id="addAmount" class="form-control form-control-sm" step="0.01" min="1" required></div>
        <div class="mb-2"><label class="form-label">Due Date</label><input type="date" id="addDue" class="form-control form-control-sm" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>"></div>
        <div class="mb-3"><label class="form-label">Description</label><textarea id="addDesc" class="form-control form-control-sm" rows="2"></textarea></div>
        <div class="d-flex justify-content-end gap-2">
            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-primary btn-sm" id="addBtn" onclick="submitAdd()">
                <i class="fas fa-plus me-1"></i>Add Credit
            </button>
        </div>
    </div>
</div></div></div>

<!-- ── Pay Credit Modal ─────────────────────────────────────── -->
<div class="modal fade" id="payM" tabindex="-1"><div class="modal-dialog modal-sm"><div class="modal-content">
    <div class="modal-header bg-success text-white">
        <h6 class="modal-title"><i class="fas fa-money-bill-wave me-2"></i>Record Payment</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
        <input type="hidden" id="payCreditId">
        <div class="mb-2">
            <strong id="payCustomerName"></strong>
            <div class="small text-muted mt-1">Remaining: <span id="payRemaining" class="text-danger fw-bold"></span></div>
        </div>
        <div class="mb-2"><label class="form-label">Amount (Rs)</label><input type="number" id="payAmount" class="form-control form-control-sm" step="0.01" min="0.01"></div>
        <div class="mb-2"><label class="form-label">Method</label>
            <select id="payMethod" class="form-select form-select-sm">
                <option value="cash">Cash</option><option value="card">Card</option><option value="online">Online</option>
            </select>
        </div>
        <div class="mb-3"><label class="form-label">Note</label><input type="text" id="payNote" class="form-control form-control-sm"></div>
        <div class="d-flex justify-content-end gap-2">
            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-success btn-sm" id="payBtn" onclick="submitPay()">
                <i class="fas fa-check me-1"></i>Record Payment
            </button>
        </div>
    </div>
</div></div></div>

<div class="toast-container" id="toastC"></div>



<script>
var SELF     = '<?php echo $selfURL; ?>';
var LIST_URL = '<?php echo $listURL; ?>';

/* ── Auto-dismiss alerts after 5 seconds ── */
document.querySelectorAll('.alert:not(.alert-permanent)').forEach(function(el){
    setTimeout(function(){
        var bsAlert = bootstrap.Alert.getOrCreateInstance(el);
        if(bsAlert) bsAlert.close();
    }, 5000);
});

/* ── Tooltip init ── */
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el){
    new bootstrap.Tooltip(el, {trigger:'hover'});
});

/* ── Toast ── */
function toast(msg, type='success'){
    var bg = type==='success'?'bg-success':'bg-danger';
    var el = $('<div class="toast align-items-center text-white border-0 '+bg+'"><div class="d-flex"><div class="toast-body">'+msg+'</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div>');
    $('#toastC').append(el);
    new bootstrap.Toast(el[0], {delay:3500}).show();
    setTimeout(()=>el.remove(), 4500);
}

/* ── Date preset buttons ── */
function setPreset(p){
    var now = new Date();
    var y = now.getFullYear(), m = now.getMonth(), d = now.getDate();
    var fmt = dt => dt.toISOString().split('T')[0];
    var from, to = fmt(now);
    if(p==='today'){ from = to; }
    else if(p==='week'){ var s=new Date(now); s.setDate(d-6); from=fmt(s); }
    else if(p==='month'){ from = y+'-'+(m+1<10?'0':'')+(m+1)+'-01'; }
    else if(p==='year'){ from = y+'-01-01'; }
    $('#dateFrom').val(from);
    $('#dateTo').val(to);
    applyRange();
}

/* ── Apply date range → fetch summary ── */
function applyRange(){
    var from = $('#dateFrom').val();
    var to   = $('#dateTo').val();
    if(!from || !to){ toast('Select both dates','danger'); return; }
    fetchSummary(from, to);
}

/* ── Fetch summary (called on load + interval + range change) ── */
function fetchSummary(from, to){
    var url = SELF+'?ajax=get_summary';
    if(from && to) url += '&date_from='+encodeURIComponent(from)+'&date_to='+encodeURIComponent(to);
    $.getJSON(url, function(r){
        if(!r.success) return;

        // Overall cards
        $('#statActiveTotal').text('Rs '+fmtAmt(r.active_remaining));
        $('#statActiveCount').text(r.active_count);
        $('#statOverdueTotal').text('Rs '+fmtAmt(r.overdue_remaining));
        $('#statOverdueCount').text(r.overdue_count);
        $('#statPaidCount').text(r.paid_count+' credits');
        $('#statRangeCollected').text('Rs '+fmtAmt(r.range_collected_amount));
        $('#statRangeCollectedCount').text(r.range_collected_count);

        // Range mini cards
        $('#rangeDueAmt').text('Rs '+fmtAmt(r.range_due_amount));
        $('#rangeDueCnt').text(r.range_due_count+' credits');
        $('#rangePendingAmt').text('Rs '+fmtAmt(r.range_pending_amount));
        $('#rangePendingCnt').text(r.range_pending_count+' credits');
        $('#rangeCollectedAmt').text('Rs '+fmtAmt(r.range_collected_amount));
        $('#rangeCollectedCnt').text(r.range_collected_count+' payments');

        // Last sync time
        var now = new Date();
        $('#lastSync').text('Last sync: '+now.toLocaleTimeString());
    });
}

function fmtAmt(v){ return parseFloat(v||0).toLocaleString('en-PK',{minimumFractionDigits:0,maximumFractionDigits:2}); }

/* ── Add Credit ── */
function submitAdd(){
    var name  = $('#addName').val().trim();
    var phone = $('#addPhone').val().trim();
    var amt   = parseFloat($('#addAmount').val());
    if(!name){ toast('Enter customer name','danger'); return; }
    if(!phone){ toast('Enter phone number','danger'); return; }
    if(isNaN(amt)||amt<=0){ toast('Enter valid amount','danger'); return; }
    $('#addBtn').prop('disabled',true).html('<span class="spinner-border spinner-border-sm me-1"></span>Saving…');
    $.post(SELF+'?ajax=add_credit',{
        customer_name: name, customer_phone: phone,
        amount: amt, due_date: $('#addDue').val(), description: $('#addDesc').val().trim()
    }, function(r){
        $('#addBtn').prop('disabled',false).html('<i class="fas fa-plus me-1"></i>Add Credit');
        if(r.success){ bootstrap.Modal.getInstance(document.getElementById('addM')).hide(); toast(r.message,'success'); fetchSummary($('#dateFrom').val(),$('#dateTo').val()); setTimeout(()=>location.reload(),1200); }
        else toast(r.message,'danger');
    },'json').fail(()=>{ $('#addBtn').prop('disabled',false).html('<i class="fas fa-plus me-1"></i>Add Credit'); toast('Server error','danger'); });
}

/* ── Pay Modal ── */
function showPayModal(id, name, remaining){
    $('#payCreditId').val(id);
    $('#payCustomerName').text(name);
    $('#payRemaining').text('Rs '+parseFloat(remaining).toFixed(2));
    $('#payAmount').val('').attr('max', remaining);
    $('#payMethod').val('cash'); $('#payNote').val('');
    new bootstrap.Modal(document.getElementById('payM')).show();
}

/* ── Submit Payment ── */
function submitPay(){
    var id  = $('#payCreditId').val();
    var amt = parseFloat($('#payAmount').val());
    var rem = parseFloat($('#payRemaining').text().replace('Rs ',''));
    if(isNaN(amt)||amt<=0){ toast('Enter valid amount','danger'); return; }
    if(amt>rem+0.01){ toast('Amount exceeds remaining balance','danger'); return; }
    $('#payBtn').prop('disabled',true).html('<span class="spinner-border spinner-border-sm me-1"></span>Saving…');
    $.post(SELF+'?ajax=pay_credit',{
        credit_id: id, amount: amt,
        payment_method: $('#payMethod').val(),
        note: $('#payNote').val().trim()
    }, function(r){
        $('#payBtn').prop('disabled',false).html('<i class="fas fa-check me-1"></i>Record Payment');
        if(r.success){
            bootstrap.Modal.getInstance(document.getElementById('payM')).hide();
            toast(r.message,'success');
            // Update row live
            var $row = $('tr[data-credit-id="'+id+'"]');
            var total = parseFloat($row.find('td:eq(4)').text().replace('Rs ','').replace(/,/g,''));
            var newPaid = r.new_paid, newRem = r.new_remaining;
            var pct = total>0?Math.min(100,(newPaid/total)*100):0;
            $row.find('td:eq(5)').text('Rs '+newPaid.toFixed(2));
            $row.find('td:eq(6)').text('Rs '+newRem.toFixed(2)).removeClass('text-danger text-success').addClass(newRem>0.005?'text-danger':'text-success');
            $row.find('.ppf').css('width',pct.toFixed(0)+'%');
            var badge='';
            if(r.new_status==='paid') badge='<span class="bpd"><i class="fas fa-check-circle me-1"></i>Paid</span>';
            else if(newRem<total&&newRem>0.005) badge='<span class="bpt"><i class="fas fa-adjust me-1"></i>Partial</span>';
            else badge='<span class="bac"><i class="fas fa-circle me-1"></i>Active</span>';
            $row.find('td:eq(9)').html(badge);
            if(r.new_status==='paid') $row.removeClass('ov-row').find('.btn-success').remove();
            $('#hist_'+id).hide().html('');
            fetchSummary($('#dateFrom').val(),$('#dateTo').val());
        } else toast(r.message,'danger');
    },'json').fail(()=>{ $('#payBtn').prop('disabled',false).html('<i class="fas fa-check me-1"></i>Record Payment'); toast('Server error','danger'); });
}

/* ── Payment History Toggle ── */
function toggleHist(id){
    var $div = $('#hist_'+id);
    if($div.is(':visible')){ $div.slideUp(); return; }
    if($div.html().trim()!==''){ $div.slideDown(); return; }
    $div.html('<div class="text-center py-2"><span class="spinner-border spinner-border-sm text-primary"></span></div>').slideDown();
    $.getJSON(SELF+'?ajax=get_credit&id='+id, function(r){
        if(!r.success){ $div.html('<div class="text-muted">Error loading</div>'); return; }
        if(!r.payments||r.payments.length===0){ $div.html('<div class="text-muted"><i class="fas fa-info-circle me-1"></i>No payments yet</div>'); return; }
        var html='<table><thead><tr><th style="padding:0 10px 4px 0">Date</th><th style="padding:0 10px 4px 0">Amount</th><th style="padding:0 10px 4px 0">Method</th><th>Note</th></tr></thead><tbody>';
        r.payments.forEach(function(p){
            html+='<tr><td style="padding:2px 10px 2px 0;color:#6b7280">'+(p.fmt_date||p.payment_date)+'</td><td style="padding:2px 10px 2px 0;color:#057a55;font-weight:600">Rs '+parseFloat(p.amount).toFixed(2)+'</td><td style="padding:2px 10px 2px 0;text-transform:capitalize">'+p.payment_method+'</td><td style="color:#6b7280">'+(p.note||'—')+'</td></tr>';
        });
        html+='</tbody></table>';
        $div.html(html);
    });
}

/* ── Bulk Overdue ── */
$('#bulkBtn').on('click',function(){
    var ids=[];
    $('.cCb:checked').each(function(){ ids.push($(this).val()); });
    if(!ids.length){ toast('Select at least one credit','danger'); return; }
    if(!confirm('Mark '+ids.length+' credit(s) as overdue (note will be added)?')) return;
    $.post(SELF+'?ajax=mark_overdue',{credit_ids:ids},function(r){
        if(r.success){ toast(r.message,'success'); location.reload(); }
        else toast(r.message,'danger');
    },'json');
});

$('#selAll').on('change',function(){ $('.cCb').prop('checked',this.checked); });

/* ── Auto refresh every 30s ── */
var _autoRefresh = setInterval(function(){
    fetchSummary($('#dateFrom').val(), $('#dateTo').val());
}, 30000);

// Initial load
fetchSummary('<?php echo $dateFromInit; ?>', '<?php echo $dateToInit; ?>');
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    UniversalTable.init('creditIndexTable', {
        title      : 'Credits',
        pageSize   : 25,
        excludeCols: [0, 8, 10]
    });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
