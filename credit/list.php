<?php
/**
 * CREDIT/list.php — Credit Management Full List (FIXED)
 */

require_once '../config/config.php';
require_once '../includes/sync_engine.php';

requireLogin();

$db = db();

try {
    $createSQL = "CREATE TABLE IF NOT EXISTS customers (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        name       VARCHAR(120) NOT NULL,
        phone      VARCHAR(30)  NOT NULL,
        address    TEXT         DEFAULT NULL,
        notes      TEXT         DEFAULT NULL,
        created_at DATETIME     DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_phone (phone)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (method_exists($db, 'getConnection'))      { $db->getConnection()->exec($createSQL); }
    elseif (method_exists($db, 'getPDO'))         { $db->getPDO()->exec($createSQL); }
    else { $db->query($createSQL); try { $db->single(); } catch(Exception $e2){} }
} catch (Exception $e) { /* silent */ }

// ══════════════════════════════════════════════════════════════
//  AJAX HANDLERS
// ══════════════════════════════════════════════════════════════
if (isset($_GET['ajax'])) {
    ob_start();
    header('Content-Type: application/json');
    header('Cache-Control: no-cache');
    function ajaxJson($data){ ob_end_clean(); echo json_encode($data); exit; }
    $action = $_GET['ajax'];

    // ── Live search for invoice tab ────────────────────────────
    if ($action === 'search_invoices') {
        $q      = trim($_GET['q'] ?? '');
        $filter = $_GET['filter'] ?? 'all';
        $limit  = ($_GET['limit'] ?? '20') === 'all' ? 99999 : max(1,(int)($_GET['limit'] ?? 20));
        $pg     = max(1,(int)($_GET['pg'] ?? 1));
        $off    = ($pg-1)*$limit;

        if (!in_array($filter,['all','pending','paid','overdue'])) $filter='all';
        $where=[]; $params=[];
        switch ($filter) {
            case 'pending': $where[]="cc.status='active'"; break;
            case 'paid':    $where[]="cc.status='paid'";   break;
            case 'overdue': $where[]="cc.status='active'"; $where[]="cc.due_date<CURDATE()"; break;
            default:        $where[]="cc.status IN ('active','paid')";
        }
        if ($q !== '') {
            $where[]="(cc.customer_name LIKE ? OR cc.customer_phone LIKE ? OR s.invoice_no LIKE ? OR cc.description LIKE ?)";
            $params[]="%$q%"; $params[]="%$q%"; $params[]="%$q%"; $params[]="%$q%";
        }
        $whereSQL = $where ? implode(' AND ',$where) : '1';
        $bindParams = !empty($params) ? $params : null;

        try {
            $countRow = $db->getOne("SELECT COUNT(DISTINCT cc.id) AS total FROM customer_credits cc LEFT JOIN sales s ON s.id=cc.sale_id WHERE $whereSQL", $bindParams);
            $total = (int)($countRow['total'] ?? 0);

            $rows = $db->getAll(
                "SELECT cc.*,
                        COALESCE(s.total,cc.total_amount) AS display_total,
                        COALESCE(s.paid_amount,0) AS sale_upfront,
                        COALESCE(SUM(CASE WHEN (cp.note IS NULL OR (cp.note NOT LIKE 'Upfront%' AND cp.note NOT LIKE 'upfront%')) THEN cp.amount ELSE 0 END),0) AS post_sale_paid,
                        s.invoice_no, s.sale_date, s.id AS linked_sale_id
                 FROM customer_credits cc
                 LEFT JOIN credit_payments cp ON cp.credit_id=cc.id
                 LEFT JOIN sales s ON s.id=cc.sale_id
                 WHERE $whereSQL GROUP BY cc.id
                 ORDER BY CASE WHEN cc.status='active' AND cc.due_date<CURDATE() THEN 0 WHEN cc.status='active' THEN 1 ELSE 2 END, cc.due_date ASC
                 LIMIT $off, $limit",
                $bindParams
            );
            if (!is_array($rows)) $rows=[];
            ajaxJson(['rows'=>$rows,'total'=>$total,'pg'=>$pg,'limit'=>$limit]);
        } catch (Exception $e) {
            ajaxJson(['rows'=>[],'total'=>0,'pg'=>1,'limit'=>$limit,'error'=>$e->getMessage()]);
        }
    }

    // ── Get single credit detail + payment history ─────────────
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
            if (!$credit) { echo json_encode(['success' => false, 'message' => 'Credit not found']); exit; }
            $db->query(
                "SELECT COALESCE(SUM(amount), 0) AS paid FROM credit_payments
                 WHERE credit_id = :cid AND (note IS NULL OR (note NOT LIKE 'Upfront%' AND note NOT LIKE 'upfront%'))",
                [':cid' => $id]
            );
            $paidRow      = $db->single();
            $saleTotal    = (float)($credit['sale_total']   ?? $credit['total_amount'] ?? 0);
            $saleUpfront  = (float)($credit['sale_upfront'] ?? 0);
            $postSalePaid = (float)($paidRow['paid']        ?? 0);
            $credit['display_total'] = $saleTotal;
            $credit['paid']          = $saleUpfront + $postSalePaid;
            $credit['remaining']     = max(0, $saleTotal - $saleUpfront - $postSalePaid);
            $db->query(
                "SELECT *, DATE_FORMAT(payment_date, '%d %b %Y %H:%i') AS fmt_date
                 FROM credit_payments WHERE credit_id = :cid ORDER BY payment_date DESC",
                [':cid' => $id]
            );
            $payments = $db->resultSet();
            if (!is_array($payments)) $payments = [];
            ajaxJson(['success' => true, 'credit' => $credit, 'payments' => $payments]);
        } catch (Exception $e) {
            ajaxJson(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
        }
    }

    // ── Get customer invoices ───────────────────────────────────
    if ($action === 'get_customer_credits') {
        $phone = trim($_GET['phone'] ?? '');
        if (!$phone) { echo json_encode(['success' => false, 'message' => 'Phone required']); exit; }
        try {
            $db->query(
                "SELECT cc.*, COALESCE(s.total, cc.total_amount) AS display_total,
                        COALESCE(s.paid_amount, 0) AS sale_upfront,
                        s.invoice_no, s.sale_date, s.id AS sale_id_fk
                 FROM customer_credits cc LEFT JOIN sales s ON s.id = cc.sale_id
                 WHERE cc.customer_phone = :phone ORDER BY cc.status ASC, cc.due_date ASC",
                [':phone' => $phone]
            );
            $credits = $db->resultSet();
            if (!is_array($credits)) $credits = [];
            foreach ($credits as &$cr) {
                $cid = (int)$cr['id'];
                $postPaid = 0;
                try {
                    $db->query("SELECT COALESCE(SUM(amount),0) AS pp FROM credit_payments WHERE credit_id = :cid AND (note IS NULL OR (note NOT LIKE 'Upfront%' AND note NOT LIKE 'upfront%'))", [':cid' => $cid]);
                    $ppRow    = $db->single();
                    $postPaid = (float)($ppRow['pp'] ?? 0);
                } catch (Exception $e2) { $postPaid = 0; }
                $cr['post_paid']  = $postPaid;
                $cr['remaining']  = max(0, (float)$cr['display_total'] - (float)$cr['sale_upfront'] - $postPaid);
                $cr['paid_total'] = (float)$cr['sale_upfront'] + $postPaid;
            }
            unset($cr);
            $totalPending = 0;
            foreach ($credits as $cr) { if ($cr['status'] === 'active') $totalPending += $cr['remaining']; }
            ajaxJson(['success' => true, 'credits' => $credits, 'total_pending' => $totalPending]);
        } catch (Exception $e) {
            ajaxJson(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
        }
    }

    // ── Add manual credit ───────────────────────────────────────
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
            [$customer_name,$customer_phone,$amount,$due_date,$description]);
        $newId = $db->lastId();
        if (!$newId) { echo json_encode(['success' => false, 'message' => 'DB insert failed']); exit; }
        $db->query("INSERT IGNORE INTO customers (name,phone) VALUES (?,?)", [$customer_name,$customer_phone]);
        if (!empty($_SESSION['user_id'])) {
            $db->query("INSERT INTO activity_logs (user_id,action,description,ip_address,user_agent,created_at) VALUES (?,?,?,?,?,NOW())",
                [$_SESSION['user_id'], 'Credit Added', "Manual credit: {$customer_name} ({$customer_phone}) Rs ".number_format($amount,2), $_SERVER['REMOTE_ADDR']??'::1', $_SERVER['HTTP_USER_AGENT']??'']);
        }
        echo json_encode(['success' => true, 'id' => $newId, 'message' => 'Credit added']);
        exit;
    }

    // ── Record payment ──────────────────────────────────────────
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
        $saleTotal    = (float)($credit['sale_total']   ?? $credit['total_amount'] ?? 0);
        $saleUpfront  = (float)($credit['sale_upfront'] ?? 0);
        $postSalePaid = (float)($paidRow['paid']        ?? 0);
        $remaining    = max(0, $saleTotal - $saleUpfront - $postSalePaid);
        if ($amount > $remaining + 0.01) { echo json_encode(['success' => false, 'message' => 'Amount exceeds remaining Rs '.number_format($remaining,2)]); exit; }
        $db->beginTransaction();
        try {
            $db->query("INSERT INTO credit_payments (credit_id,amount,payment_method,note,payment_date) VALUES (?,?,?,?,NOW())", [$credit_id, round($amount,2), $payment_method, $note]);
            $paymentId    = $db->lastId();
            $newPostPaid  = $postSalePaid + $amount;
            $newRemaining = max(0, $saleTotal - $saleUpfront - $newPostPaid);
            $nowFullyPaid = $newRemaining < 0.01;
            if ($nowFullyPaid) { $db->query("UPDATE customer_credits SET status='paid' WHERE id=?", [$credit_id]); }
            if (!empty($credit['sale_id'])) {
                $saleRow = $db->getOne("SELECT paid_amount, total FROM sales WHERE id = :sid", [':sid' => $credit['sale_id']]);
                if ($saleRow) {
                    $newSalePaid = round((float)$saleRow['paid_amount'] + $amount, 2);
                    $saleGT      = (float)$saleRow['total'];
                    $payStat     = ($newSalePaid >= $saleGT) ? 'paid' : ($newSalePaid > 0 ? 'partial' : 'pending');
                    $db->query("UPDATE sales SET paid_amount = ?, payment_status = ? WHERE id = ?", [$newSalePaid, $payStat, $credit['sale_id']]);
                }
            }
            $db->commit();
            $customerName = $credit['customer_name'] ?? 'Customer';
            $invoiceLabel = !empty($credit['invoice_no']) ? ' (Invoice: ' . $credit['invoice_no'] . ')' : '';
            if (function_exists('ensureCashbookEntry')) {
                ensureCashbookEntry('credit_received',(int)$paymentId,'credit_payment','Credit payment received from '.$customerName.$invoiceLabel,0,round($amount,2),$payment_method,date('Y-m-d'));
            }
            if (function_exists('clearAllCaches'))           clearAllCaches();
            if (function_exists('invalidateDashboardCache')) invalidateDashboardCache();
            if (function_exists('logActivity')) { logActivity('Credit Payment',"Rs ".number_format($amount,2)." from {$customerName} (Credit #{$credit_id})".($nowFullyPaid?' — FULLY PAID':'')); }
            echo json_encode(['success'=>true,'fully_paid'=>$nowFullyPaid,'new_remaining'=>round($newRemaining,2),'new_balance'=>round($newRemaining,2),'new_paid'=>round($saleUpfront+$newPostPaid,2),'new_status'=>$nowFullyPaid?'paid':'active','sale_total'=>$saleTotal,'message'=>$nowFullyPaid?'Credit fully paid! Cashbook updated.':'Payment of Rs '.number_format($amount,2).' recorded']);
        } catch (Exception $e) {
            $db->rollback();
            echo json_encode(['success' => false, 'message' => 'Error: '.$e->getMessage()]);
        }
        exit;
    }

    // ── Export CSV (invoice-wise) ───────────────────────────────
    if ($action === 'export') {
        $filter = $_GET['filter'] ?? 'all'; $search = trim($_GET['search'] ?? '');
        $wh=[]; $pr=[];
        switch ($filter) {
            case 'pending': $wh[] = "cc.status = 'active'"; break;
            case 'paid':    $wh[] = "cc.status = 'paid'";   break;
            case 'overdue': $wh[] = "cc.status = 'active'"; $wh[] = "cc.due_date < CURDATE()"; break;
            default:        $wh[] = "cc.status IN ('active','paid')";
        }
        if ($search !== '') { $wh[]="(cc.customer_name LIKE ? OR cc.customer_phone LIKE ? OR s.invoice_no LIKE ?)"; $pr[]="%$search%"; $pr[]="%$search%"; $pr[]="%$search%"; }
        $whSQL = $wh ? implode(' AND ',$wh) : '1';
        $exportBindParams = !empty($pr) ? $pr : null;
        $rows = $db->getAll("SELECT cc.id,cc.customer_name,cc.customer_phone,cc.total_amount,COALESCE(SUM(cp.amount),0) AS paid,(cc.total_amount-COALESCE(SUM(cp.amount),0)) AS remaining,cc.due_date,cc.status,cc.created_at,s.invoice_no,s.sale_date FROM customer_credits cc LEFT JOIN credit_payments cp ON cp.credit_id=cc.id LEFT JOIN sales s ON s.id=cc.sale_id WHERE $whSQL GROUP BY cc.id ORDER BY cc.due_date ASC", $exportBindParams);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="credits_'.date('Ymd_His').'.csv"');
        $out = fopen('php://output','w'); fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($out,['ID','Customer','Phone','Invoice','Sale Date','Total','Paid','Remaining','Due Date','Status']);
        foreach ($rows as $r) { fputcsv($out,[$r['id'],$r['customer_name'],$r['customer_phone'],$r['invoice_no']??'Manual',$r['sale_date']??'',number_format($r['total_amount'],2),number_format($r['paid'],2),number_format($r['remaining'],2),$r['due_date'],$r['status']]); }
        fclose($out); exit;
    }

    // ── Export CSV customer-wise ────────────────────────────────
    if ($action === 'export_customer') {
        $phone = trim($_GET['phone'] ?? ''); $name  = trim($_GET['name']  ?? 'customer');
        if (!$phone) { echo json_encode(['success'=>false,'message'=>'No phone']); exit; }
        $credits = $db->getAll("SELECT cc.*,COALESCE(s.total,cc.total_amount) AS display_total,COALESCE(s.paid_amount,0) AS sale_upfront,COALESCE((SELECT SUM(cp2.amount) FROM credit_payments cp2 WHERE cp2.credit_id=cc.id AND (cp2.note IS NULL OR (cp2.note NOT LIKE 'Upfront%' AND cp2.note NOT LIKE 'upfront%'))),0) AS post_paid,s.invoice_no,s.sale_date FROM customer_credits cc LEFT JOIN sales s ON s.id=cc.sale_id WHERE cc.customer_phone=? ORDER BY cc.status ASC, cc.due_date ASC", [$phone]);
        header('Content-Type: text/csv; charset=utf-8');
        $safeName = preg_replace('/[^a-z0-9_]/i','_',$name);
        header('Content-Disposition: attachment; filename="'.$safeName.'_'.date('Ymd').'.csv"');
        $out = fopen('php://output','w'); fprintf($out,chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($out,['Invoice No','Sale Date','Grand Total','Upfront Paid','Post Payments','Total Paid','Remaining','Due Date','Status']);
        foreach ($credits as $cr) {
            $rem = max(0,(float)$cr['display_total']-(float)$cr['sale_upfront']-(float)$cr['post_paid']);
            $paidTotal = (float)$cr['sale_upfront']+(float)$cr['post_paid'];
            fputcsv($out,[$cr['invoice_no']??'Manual',$cr['sale_date']??$cr['created_at']??'',number_format((float)$cr['display_total'],2),number_format((float)$cr['sale_upfront'],2),number_format((float)$cr['post_paid'],2),number_format($paidTotal,2),number_format($rem,2),$cr['due_date']??'',$cr['status']]);
        }
        fclose($out); exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action']); exit;
}

// ══════════════════════════════════════════════════════════════
//  PAGE DATA
// ══════════════════════════════════════════════════════════════
$tab    = in_array($_GET['tab'] ?? '', ['invoices','customers']) ? ($_GET['tab'] ?? 'invoices') : 'invoices';
$filter = $_GET['filter'] ?? 'all';
$search = trim($_GET['search'] ?? '');
$page   = max(1,(int)($_GET['page'] ?? 1));
$perPageAllowed = [20,30,50,100];
$perPageParam   = $_GET['per_page'] ?? '20';
$perPageAll     = ($perPageParam === 'all');
$perPage        = $perPageAll ? 99999 : (in_array((int)$perPageParam,$perPageAllowed) ? (int)$perPageParam : 20);
if ($perPageAll) $page = 1;
$offset = ($page-1)*$perPage;

if (!in_array($filter,['all','pending','paid','overdue'])) $filter='all';

$where=[]; $params=[];
switch ($filter) {
    case 'pending': $where[]="cc.status='active'"; break;
    case 'paid':    $where[]="cc.status='paid'";   break;
    case 'overdue': $where[]="cc.status='active'"; $where[]="cc.due_date<CURDATE()"; break;
    default:        $where[]="cc.status IN ('active','paid')";
}
if ($search !== '') {
    $where[]="(cc.customer_name LIKE ? OR cc.customer_phone LIKE ? OR s.invoice_no LIKE ? OR cc.description LIKE ?)";
    $params[]="%$search%"; $params[]="%$search%"; $params[]="%$search%"; $params[]="%$search%";
}
$whereSQL = $where ? implode(' AND ',$where) : '1';
$bindParams = !empty($params) ? $params : null;
$countRow     = $db->getOne("SELECT COUNT(DISTINCT cc.id) AS total FROM customer_credits cc LEFT JOIN sales s ON s.id=cc.sale_id WHERE $whereSQL", $bindParams);
$totalRecords = (int)($countRow['total'] ?? 0);
$totalPages   = $perPageAll ? 1 : max(1,(int)ceil($totalRecords/$perPage));

$credits = $db->getAll(
    "SELECT cc.*, COALESCE(s.total,cc.total_amount) AS display_total,
            COALESCE(s.paid_amount,0) AS sale_upfront,
            COALESCE(SUM(CASE WHEN (cp.note IS NULL OR (cp.note NOT LIKE 'Upfront%' AND cp.note NOT LIKE 'upfront%')) THEN cp.amount ELSE 0 END),0) AS post_sale_paid,
            s.invoice_no, s.sale_date, s.id AS linked_sale_id
     FROM customer_credits cc
     LEFT JOIN credit_payments cp ON cp.credit_id=cc.id
     LEFT JOIN sales s ON s.id=cc.sale_id
     WHERE $whereSQL GROUP BY cc.id
     ORDER BY CASE WHEN cc.status='active' AND cc.due_date<CURDATE() THEN 0 WHEN cc.status='active' THEN 1 ELSE 2 END, cc.due_date ASC
     LIMIT $offset, $perPage",
    $bindParams
);

$stats = $db->getOne(
    "SELECT COUNT(CASE WHEN status='active' THEN 1 END) AS active_count,
            COALESCE(SUM(CASE WHEN status='active' THEN total_amount END),0) AS active_total,
            COUNT(CASE WHEN status='active' AND due_date<CURDATE() THEN 1 END) AS overdue_count,
            COUNT(CASE WHEN status='paid' THEN 1 END) AS paid_count
     FROM customer_credits"
);

$pendingCustRow = $db->getOne(
    "SELECT COUNT(DISTINCT cc.customer_phone) AS cnt
     FROM customer_credits cc
     LEFT JOIN sales s ON s.id = cc.sale_id
     WHERE cc.status = 'active'
       AND (COALESCE(s.total, cc.total_amount)
            - COALESCE(s.paid_amount, 0)
            - COALESCE((SELECT SUM(cp2.amount) FROM credit_payments cp2
                         WHERE cp2.credit_id = cc.id
                           AND (cp2.note IS NULL OR (cp2.note NOT LIKE 'Upfront%' AND cp2.note NOT LIKE 'upfront%'))), 0)) > 0.005"
);
$pendingCustCount = (int)($pendingCustRow['cnt'] ?? 0);

$selfURL    = BASE_URL . 'CREDIT/list.php';
$page_title = 'Credit List — Pharmacy';
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
/* Page fade-in */
@keyframes fadeInPage{from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:translateY(0);}}
.main-content{margin-left: var(--sb-w, 260px); transition: margin-left .25s ease;padding:20px 24px;animation:fadeInPage 0.28s ease;}
body.sb-col-body .main-content { margin-left: var(--sb-w-mini, 62px); }
        @media (max-width: 991.98px) { .main-content { margin-left: 0 !important; } }
.sc{background:var(--card);border-radius:14px;padding:16px 20px;box-shadow:0 1px 4px rgba(0,0,0,.07);border-left:4px solid transparent;transition:transform 0.2s ease,box-shadow 0.2s ease;}
.sc:hover{box-shadow:0 6px 20px rgba(0,0,0,.13);transform:translateY(-3px);}
.sc-blue{border-left-color:var(--pri)}.sc-red{border-left-color:var(--dan)}.sc-green{border-left-color:var(--suc)}.sc-orange{border-left-color:var(--warn)}
.sc-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--mu);}
.sc-val{font-size:22px;font-weight:700;margin:4px 0 2px;}
.sc-sub{font-size:11px;color:var(--mu);}
.filter-pill{display:inline-flex;align-items:center;gap:5px;padding:5px 14px;border-radius:20px;font-size:12px;font-weight:600;text-decoration:none;border:1px solid var(--bo);color:var(--mu);background:var(--card);transition:all .15s;}
.filter-pill:hover{border-color:var(--pri);color:var(--pri);}
.filter-pill.active{background:var(--pri);color:#fff;border-color:var(--pri);}
.filter-pill .cnt{background:rgba(0,0,0,.1);border-radius:99px;padding:1px 7px;font-size:10px;}
.filter-pill.active .cnt{background:rgba(255,255,255,.25);}
.tcard{background:var(--card);border-radius:14px;box-shadow:0 1px 4px rgba(0,0,0,.07);overflow:hidden;}
.tcard thead th{background:#f9fafb;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;padding:10px 14px;border-bottom:1px solid var(--bo);white-space:nowrap;}
.tcard thead th[data-sort]{cursor:pointer;user-select:none;transition:background .15s;}
.tcard thead th[data-sort]:hover{background:#f0f4ff;color:var(--pri);}
.sort-arrow{margin-left:3px;font-size:10px;}
.tcard tbody td{padding:10px 14px;vertical-align:middle;border-bottom:1px solid #f3f4f6;}
.tcard tbody tr:last-child td{border-bottom:none;}
.tcard tbody tr:hover{background:#f9fafb;}
.tcard tbody tr.overdue-row{background:#fff5f5;}
.b-active{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:20px;padding:3px 10px;font-size:11px;font-weight:600;}
.b-paid{background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;border-radius:20px;padding:3px 10px;font-size:11px;font-weight:600;}
.b-overdue{background:#fef2f2;color:#dc2626;border:1px solid #fecaca;border-radius:20px;padding:3px 10px;font-size:11px;font-weight:600;animation:pulse 1.6s infinite;}
.b-partial{background:#fffbeb;color:#d97706;border:1px solid #fde68a;border-radius:20px;padding:3px 10px;font-size:11px;font-weight:600;}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.6}}
.ppb{background:#f3f4f6;border-radius:99px;overflow:hidden;height:5px;margin-bottom:3px;}
.ppf{height:100%;border-radius:99px;background:linear-gradient(90deg,#1a56db,#3b82f6);}
.pay-history{background:#f8fafc;border-radius:10px;padding:10px 14px;display:none;margin-top:6px;}
.pay-history table{font-size:11px;width:100%;}
.pay-history th{color:var(--mu);font-weight:600;padding-bottom:4px;}
@keyframes highlightFade{0%{background:#fef9c3;box-shadow:inset 0 0 0 2px #f59e0b;}60%{background:#fef9c3;box-shadow:inset 0 0 0 2px #f59e0b;}100%{background:transparent;box-shadow:none;}}
.highlight-blink{animation:highlightFade 3.5s ease forwards !important;}
.toast-container{position:fixed;bottom:20px;right:20px;z-index:9999;}
.pending-chip{background:#fef2f2;color:#dc2626;border:1px solid #fecaca;border-radius:20px;padding:2px 9px;font-size:11px;font-weight:700;}
.clear-chip{background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;border-radius:20px;padding:2px 9px;font-size:11px;font-weight:600;}
.inv-tbl{width:100%;border-collapse:collapse;font-size:12px;}
.inv-tbl th{background:#f3f4f6;padding:6px 10px;text-align:left;font-weight:700;font-size:11px;text-transform:uppercase;color:var(--mu);}
.inv-tbl td{padding:7px 10px;border-bottom:1px solid #f3f4f6;}
.inv-tbl tr:last-child td{border-bottom:none;}
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
        <li class="breadcrumb-item active fw-semibold" aria-current="page">Credit Sales</li>
    </ol>
</nav>

<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-1 fw-bold"><i class="fas fa-file-invoice-dollar me-2 text-primary"></i>Credit Management</h4>
        <p class="text-muted mb-0" style="font-size:12px">Invoice-wise credit tracking & customer-wise pending dues</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-tachometer-alt me-1"></i>Dashboard</a>
        <a href="<?= BASE_URL ?>sales/customers.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-users me-1"></i>Customers</a>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addModal"
                data-requires-perm="credit.add">
            <i class="fas fa-plus me-1"></i>Add Manual Credit
        </button>
        <a href="?ajax=export&filter=<?= urlencode($filter) ?>&search=<?= urlencode($search) ?>"
           class="btn btn-outline-success btn-sm" data-requires-perm="credit.export">
            <i class="fas fa-file-csv me-1"></i>Export CSV
        </a>
    </div>
</div>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="sc sc-red"><div class="sc-label">Active Credits</div><div class="sc-val"><?= (int)($stats['active_count']??0) ?></div><div class="sc-sub">pending collection</div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="sc sc-orange"><div class="sc-label">Overdue</div><div class="sc-val"><?= (int)($stats['overdue_count']??0) ?></div><div class="sc-sub">past due date</div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="sc sc-green"><div class="sc-label">Paid Credits</div><div class="sc-val"><?= (int)($stats['paid_count']??0) ?></div><div class="sc-sub">fully settled</div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="sc sc-blue"><div class="sc-label">Total Outstanding</div><div class="sc-val" style="font-size:18px">Rs <?= number_format((float)($stats['active_total']??0),0) ?></div><div class="sc-sub">from active credits</div></div>
    </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link <?= $tab==='invoices'?'active':'' ?>" href="?tab=invoices&filter=<?= $filter ?>&search=<?= urlencode($search) ?>">
            <i class="fas fa-file-invoice me-1"></i>Invoice List
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab==='customers'?'active':'' ?>" href="<?= BASE_URL ?>sales/customers.php">
            <i class="fas fa-users me-1"></i>Customers
            <?php if ($pendingCustCount > 0): ?>
            <span class="badge bg-danger ms-1"><?= $pendingCustCount ?></span>
            <?php else: ?>
            <span class="badge bg-secondary ms-1">0</span>
            <?php endif; ?>
        </a>
    </li>
</ul>

<?php if ($tab === 'invoices'): ?>
<!-- ══════════ INVOICE TAB ══════════ -->
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <!-- Filter pills -->
    <div class="d-flex gap-2 flex-wrap">
        <?php
        $pills = ['all'=>['All',(int)($stats['active_count']??0)+(int)($stats['paid_count']??0)],
                  'pending'=>['Active',(int)($stats['active_count']??0)],
                  'overdue'=>['Overdue',(int)($stats['overdue_count']??0)],
                  'paid'=>['Paid',(int)($stats['paid_count']??0)]];
        foreach ($pills as $key => [$label,$cnt]):
        ?>
        <a href="?tab=invoices&filter=<?= $key ?><?= $search?'&search='.urlencode($search):'' ?>"
           class="filter-pill <?= $filter===$key?'active':'' ?>">
            <?= $label ?><span class="cnt"><?= $cnt ?></span>
        </a>
        <?php endforeach; ?>
    </div>
    <!-- Search + Per-page -->
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <div style="position:relative;">
            <span style="position:absolute;left:9px;top:50%;transform:translateY(-50%);color:#9ca3af;pointer-events:none;z-index:1;font-size:11px"><i class="fas fa-search"></i></span>
            <input type="text" id="invSearchInput" class="form-control form-control-sm"
                   placeholder="Name, phone, invoice…"
                   value="<?= htmlspecialchars($search) ?>"
                   style="padding-left:28px;min-width:220px" autocomplete="off">
            <span id="invSpinner" style="display:none;position:absolute;right:9px;top:50%;transform:translateY(-50%);">
                <span class="spinner-border spinner-border-sm text-primary" style="width:13px;height:13px;border-width:2px"></span>
            </span>
        </div>
        <small class="text-muted">Show:</small>
        <select id="invPerPage" class="form-select form-select-sm" style="width:75px;font-size:12px">
            <?php foreach([20=>'20',30=>'30',50=>'50',100=>'100','all'=>'All'] as $v=>$l):
                $sel = ((string)($perPageAll?'all':$perPage)===(string)$v)?'selected':'';
            ?>
            <option value="<?= $v ?>" <?= $sel ?>><?= $l ?></option>
            <?php endforeach; ?>
        </select>
        <small class="text-muted" id="invCountLabel">
            <?php if(!$perPageAll): ?>
                <?= $offset+1 ?>–<?= min($offset+$perPage,$totalRecords) ?> of <?= $totalRecords ?>
            <?php else: ?>
                All <?= $totalRecords ?>
            <?php endif; ?>
        </small>
    </div>
</div>

<div class="tcard">
    <div class="table-responsive">
    <table class="table table-hover mb-0" id="creditListTable">
        <thead>
            <tr>
                <th data-sort="id"># <span class="sort-arrow" style="color:#d1d5db"> ↕</span></th>
                <th data-sort="customer">Customer <span class="sort-arrow" style="color:#d1d5db"> ↕</span></th>
                <th data-sort="invoice">Invoice / Type <span class="sort-arrow" style="color:#d1d5db"> ↕</span></th>
                <th data-sort="total">Total <span class="sort-arrow" style="color:#d1d5db"> ↕</span></th>
                <th data-sort="paid">Paid <span class="sort-arrow" style="color:#d1d5db"> ↕</span></th>
                <th data-sort="remaining">Remaining <span class="sort-arrow" style="color:#d1d5db"> ↕</span></th>
                <th data-sort="due">Due Date <span class="sort-arrow" style="color:#d1d5db"> ↕</span></th>
                <th data-sort="pct">Progress <span class="sort-arrow" style="color:#d1d5db"> ↕</span></th>
                <th data-sort="status">Status <span class="sort-arrow" style="color:#d1d5db"> ↕</span></th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody id="invTbody">
        <tr><td colspan="10" class="text-center py-4 text-muted">
            <span class="spinner-border spinner-border-sm text-primary me-2"></span>Loading…
        </td></tr>
        </tbody>
    </table>
    </div>
    <div id="invPaginationWrap"></div>
</div>

<?php else: ?>
<?php endif; ?>

</div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-header bg-primary text-white">
            <h6 class="modal-title fw-bold"><i class="fas fa-plus-circle me-2"></i>Add Manual Credit</h6>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <div class="mb-3"><label class="form-label form-label-sm fw-semibold">Customer Name <span class="text-danger">*</span></label><input type="text" id="addName" class="form-control form-control-sm" placeholder="Full name"></div>
            <div class="mb-3"><label class="form-label form-label-sm fw-semibold">Phone <span class="text-danger">*</span></label><input type="text" id="addPhone" class="form-control form-control-sm" placeholder="03xx-xxxxxxx"></div>
            <div class="mb-3"><label class="form-label form-label-sm fw-semibold">Amount (Rs) <span class="text-danger">*</span></label><input type="number" id="addAmount" class="form-control form-control-sm" step="0.01" min="1" placeholder="0.00"></div>
            <div class="mb-3"><label class="form-label form-label-sm fw-semibold">Due Date</label><input type="date" id="addDue" class="form-control form-control-sm" value="<?= date('Y-m-d',strtotime('+7 days')) ?>"></div>
            <div class="mb-2"><label class="form-label form-label-sm fw-semibold">Description</label><textarea id="addDesc" class="form-control form-control-sm" rows="2" placeholder="Optional note…"></textarea></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-primary btn-sm" id="addBtn" onclick="submitAdd()"><i class="fas fa-save me-1"></i>Add Credit</button>
        </div>
    </div></div>
</div>

<!-- Pay Modal -->
<div class="modal fade" id="payModal" tabindex="-1">
    <div class="modal-dialog modal-sm"><div class="modal-content">
        <div class="modal-header bg-success text-white">
            <h6 class="modal-title fw-bold"><i class="fas fa-money-bill-wave me-2"></i>Record Payment</h6>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="payCreditId">
            <div class="mb-3 p-2 rounded" style="background:#f0fdf4"><div class="fw-semibold" id="payCustomerName"></div><div class="small text-muted">Remaining: <span id="payRemaining" class="text-danger fw-bold fs-6"></span></div></div>
            <div class="mb-3"><label class="form-label form-label-sm fw-semibold">Amount (Rs) <span class="text-danger">*</span></label><input type="number" id="payAmount" class="form-control form-control-sm" step="0.01" min="0.01"></div>
            <div class="mb-3"><label class="form-label form-label-sm fw-semibold">Method</label><select id="payMethod" class="form-select form-select-sm"><option value="cash">Cash</option><option value="card">Card</option><option value="online">Online</option></select></div>
            <div class="mb-2"><label class="form-label form-label-sm fw-semibold">Note</label><input type="text" id="payNote" class="form-control form-control-sm" placeholder="Optional"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-success btn-sm" id="payBtn" onclick="submitPay()"><i class="fas fa-check me-1"></i>Record Payment</button>
        </div>
    </div></div>
</div>

<!-- Customer Detail Modal -->
<div class="modal fade" id="custDetailModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header bg-dark text-white">
            <h6 class="modal-title fw-bold" id="custDetailTitle"><i class="fas fa-user me-2"></i>Customer Invoices</h6>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body" id="custDetailBody"><div class="text-center py-4"><div class="spinner-border text-primary"></div></div></div>
        <div class="modal-footer">
            <a id="custExportBtn" href="#" class="btn btn-outline-success btn-sm"><i class="fas fa-file-csv me-1"></i>Export CSV</a>
            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
        </div>
    </div></div>
</div>

<!-- Pay from Customer Detail Modal -->
<div class="modal fade" id="payModal2" tabindex="-1">
    <div class="modal-dialog modal-sm"><div class="modal-content">
        <div class="modal-header bg-success text-white">
            <h6 class="modal-title fw-bold"><i class="fas fa-money-bill-wave me-2"></i>Record Payment</h6>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="pay2CreditId">
            <div class="mb-2 p-2 rounded" style="background:#f0fdf4"><div class="fw-semibold" id="pay2Invoice"></div><div class="small text-muted">Remaining: <span id="pay2Remaining" class="text-danger fw-bold"></span></div></div>
            <div class="mb-2"><label class="form-label form-label-sm fw-semibold">Amount (Rs) <span class="text-danger">*</span></label><input type="number" id="pay2Amount" class="form-control form-control-sm" step="0.01" min="0.01"></div>
            <div class="mb-2"><label class="form-label form-label-sm fw-semibold">Method</label><select id="pay2Method" class="form-select form-select-sm"><option value="cash">Cash</option><option value="card">Card</option><option value="online">Online</option></select></div>
            <div class="mb-1"><label class="form-label form-label-sm fw-semibold">Note</label><input type="text" id="pay2Note" class="form-control form-control-sm" placeholder="Optional"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-success btn-sm" id="pay2Btn" onclick="submitPay2()"><i class="fas fa-check me-1"></i>Record Payment</button>
        </div>
    </div></div>
</div>

<div class="toast-container" id="toastContainer"></div>



<script>
var SELF         = '<?= $selfURL ?>';
var HIGHLIGHT_ID = <?= (int)($_GET['highlight']??0) ?>;
var OPEN_PAY_ID  = <?= (int)($_GET['open_pay']??0) ?>;
var BASE_URL     = '<?= BASE_URL ?>';
var _custPhone   = '';

// ── Auto-dismiss alerts after 5 seconds ──
document.querySelectorAll('.alert:not(.alert-permanent)').forEach(function(el){
    setTimeout(function(){
        var bsAlert = bootstrap.Alert.getOrCreateInstance(el);
        if(bsAlert) bsAlert.close();
    }, 5000);
});

// ── Tooltip init ──
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el){
    new bootstrap.Tooltip(el, {trigger:'hover'});
});

// ── Global sort state for invoice tab ──
var _invSort = { col: '', dir: 'asc' };
var _invCachedRows = [];
var _invCachedTotal = 0;
var _invCachedPg = 1;
var _invCachedLimit = '20';

function toast(msg,type){
    type=type||'success';
    var bg=type==='success'?'bg-success':(type==='warning'?'bg-warning text-dark':'bg-danger');
    var html='<div class="toast align-items-center text-white '+bg+' border-0 mb-2"><div class="d-flex"><div class="toast-body">'+msg+'</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div>';
    $('#toastContainer').append(html);
    var el=$('#toastContainer .toast').last()[0];
    new bootstrap.Toast(el,{delay:3500}).show();
    setTimeout(function(){$(el).remove();},4000);
}
function esc(t){return t?String(t).replace(/[&<>"']/g,function(m){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m];}):'';}

/* ══ SORT HELPERS — INVOICE TAB ══ */
function invSortRows(rows){
    if(!_invSort.col) return rows;
    var col=_invSort.col, mult=_invSort.dir==='asc'?1:-1;
    return rows.slice().sort(function(a,b){
        var va, vb;
        switch(col){
            case 'id':       va=parseInt(a.id)||0; vb=parseInt(b.id)||0; break;
            case 'customer': va=(a.customer_name||'').toLowerCase(); vb=(b.customer_name||'').toLowerCase(); break;
            case 'invoice':  va=(a.invoice_no||'zzz').toLowerCase(); vb=(b.invoice_no||'zzz').toLowerCase(); break;
            case 'total':
                va=parseFloat(a.display_total||a.total_amount)||0;
                vb=parseFloat(b.display_total||b.total_amount)||0; break;
            case 'paid':
                va=(parseFloat(a.sale_upfront)||0)+(parseFloat(a.post_sale_paid)||0);
                vb=(parseFloat(b.sale_upfront)||0)+(parseFloat(b.post_sale_paid)||0); break;
            case 'remaining':
                va=Math.max(0,(parseFloat(a.display_total||a.total_amount)||0)-(parseFloat(a.sale_upfront)||0)-(parseFloat(a.post_sale_paid)||0));
                vb=Math.max(0,(parseFloat(b.display_total||b.total_amount)||0)-(parseFloat(b.sale_upfront)||0)-(parseFloat(b.post_sale_paid)||0)); break;
            case 'due':    va=a.due_date||'9999-99-99'; vb=b.due_date||'9999-99-99'; break;
            case 'pct':
                var ta=parseFloat(a.display_total||a.total_amount)||0;
                va=ta>0?((parseFloat(a.sale_upfront)||0)+(parseFloat(a.post_sale_paid)||0))/ta:0;
                var tb=parseFloat(b.display_total||b.total_amount)||0;
                vb=tb>0?((parseFloat(b.sale_upfront)||0)+(parseFloat(b.post_sale_paid)||0))/tb:0; break;
            case 'status': va=a.status||''; vb=b.status||''; break;
            default: return 0;
        }
        if(va<vb) return -1*mult;
        if(va>vb) return  1*mult;
        return 0;
    });
}

function invUpdateSortHeaders(){
    $('th[data-sort]').each(function(){
        var col=$(this).data('sort');
        $(this).find('.sort-arrow').remove();
        var arrow=(col===_invSort.col)
            ?'<span class="sort-arrow" style="color:#1a56db">'+(_invSort.dir==='asc'?' ↑':' ↓')+'</span>'
            :'<span class="sort-arrow" style="color:#d1d5db"> ↕</span>';
        $(this).append(arrow);
    });
}

/* ══ INVOICE TAB — LIVE SEARCH + PER-PAGE ══ */
(function(){
    function getUrlParam(name,def){var m=new RegExp('[?&]'+name+'=([^&]*)').exec(window.location.search);return m?decodeURIComponent(m[1]):def;}

    var timer        = null;
    var currentQ     = $('#invSearchInput').val().trim();
    var currentPg    = parseInt(getUrlParam('page','1'))||1;
    var currentLimit = getUrlParam('per_page',$('#invPerPage').val()||'20');
    var currentFilter= '<?= $filter ?>';

    if(currentLimit) $('#invPerPage').val(currentLimit);

    var $input   = $('#invSearchInput');
    var $spinner = $('#invSpinner');
    var $count   = $('#invCountLabel');
    var $tbody   = $('#invTbody');
    var today    = '<?= date('Y-m-d') ?>';

    function fmtDate(d){if(!d)return'—';var p=d.substring(0,10).split('-');var m=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];return p[2]+' '+(m[parseInt(p[1])-1]||'')+' '+p[0];}

    function renderInvRows(rows,total,pg,limit){
        try {
            _invCachedRows  = rows;
            _invCachedTotal = total;
            _invCachedPg    = pg;
            _invCachedLimit = limit;

            var sortedRows = invSortRows(rows);
            invUpdateSortHeaders();

            var isAll=(limit==='all'||parseInt(limit)>=99999);
            var lim=isAll?99999:parseInt(limit);
            var start=isAll?1:(pg-1)*lim+1;
            var end=isAll?total:Math.min(pg*lim,total);
            $count.text(total===0?'0 records':(start+'–'+end+' of '+total));

            var totalPages=isAll?1:Math.ceil(total/lim);
            var phtml='';
            if(totalPages>1){
                phtml='<div class="d-flex justify-content-between align-items-center p-3 border-top"><small class="text-muted">Page '+pg+' of '+totalPages+'</small><nav><ul class="pagination pagination-sm mb-0">';
                if(pg>1) phtml+='<li class="page-item"><a class="page-link inv-pg" data-pg="'+(pg-1)+'" href="#"><i class="fas fa-chevron-left"></i></a></li>';
                for(var i=Math.max(1,pg-2);i<=Math.min(totalPages,pg+2);i++){phtml+='<li class="page-item '+(i===pg?'active':'')+'"><a class="page-link inv-pg" data-pg="'+i+'" href="#">'+i+'</a></li>';}
                if(pg<totalPages) phtml+='<li class="page-item"><a class="page-link inv-pg" data-pg="'+(pg+1)+'" href="#"><i class="fas fa-chevron-right"></i></a></li>';
                phtml+='</ul></nav></div>';
            }
            $('#invPaginationWrap').html(phtml);

            if(!sortedRows||sortedRows.length===0){
                $tbody.html('<tr><td colspan="10" class="text-center py-5 text-muted"><i class="fas fa-inbox fa-2x mb-2 d-block opacity-30"></i>No credit records found</td></tr>');
                return;
            }

            var html='';
            sortedRows.forEach(function(cr){
                var total2   = parseFloat(cr.display_total||cr.total_amount)||0;
                var upfront  = parseFloat(cr.sale_upfront)||0;
                var postPaid = parseFloat(cr.post_sale_paid)||0;
                var paid     = upfront+postPaid;
                var rem      = Math.max(0,total2-paid);
                var pct      = total2>0?Math.min(100,(paid/total2)*100):0;
                var isOverdue= (cr.status==='active'&&cr.due_date&&cr.due_date<today&&rem>0.005);
                var isPaid   = (cr.status==='paid'||rem<=0.005);
                var isPartial= (!isPaid&&paid>0.005&&rem>0.005);
                var badge    = isPaid
                    ? '<span class="b-paid"><i class="fas fa-check-circle me-1"></i>Paid</span>'
                    : (isOverdue
                        ? '<span class="b-overdue"><i class="fas fa-exclamation-triangle me-1"></i>Overdue</span>'
                        : (isPartial
                            ? '<span class="b-partial"><i class="fas fa-adjust me-1"></i>Partial</span>'
                            : '<span class="b-active"><i class="fas fa-circle me-1"></i>Active</span>'));

                var invCell = cr.invoice_no
                    ? '<a href="'+BASE_URL+'sales/view.php?id='+cr.linked_sale_id+'" target="_blank" class="text-primary"><i class="fas fa-receipt me-1" style="font-size:10px"></i>'+esc(cr.invoice_no)+'</a>'
                      +(cr.sale_date?'<br><small class="text-muted">'+fmtDate(cr.sale_date)+'</small>':'')
                    : '<span class="text-muted"><i class="fas fa-hand-holding-usd me-1"></i>Manual</span>'
                      +(cr.description?'<br><small class="text-muted">'+esc(cr.description.substring(0,30))+'</small>':'');

                var dueCell  = cr.due_date ? fmtDate(cr.due_date) : '—';
                var daysOver = '';
                if(isOverdue){var d=Math.floor((new Date()-new Date(cr.due_date))/86400000);daysOver='<br><small class="text-danger">'+d+'d overdue</small>';}

                var payBtn = !isPaid
                    ? '<button class="btn btn-success btn-sm" onclick="openPayModal('+cr.id+',\''+esc(cr.customer_name)+'\','+rem+')" data-requires-perm="credit.pay" title="Record payment"><i class="fas fa-money-bill-wave"></i></button>'
                    : '';

                html+='<tr data-id="'+cr.id+'" class="'+(isOverdue?'overdue-row':'')+'">'
                    +'<td class="text-muted">'+cr.id+'</td>'
                    +'<td><strong>'+esc(cr.customer_name)+'</strong><br><small class="text-muted">'+esc(cr.customer_phone)+'</small></td>'
                    +'<td>'+invCell+'</td>'
                    +'<td class="fw-semibold">Rs '+total2.toFixed(2)+'</td>'
                    +'<td class="text-success">Rs '+paid.toFixed(2)+'</td>'
                    +'<td class="fw-bold '+(rem>0.005?'text-danger':'text-success')+'">Rs '+rem.toFixed(2)+'</td>'
                    +'<td class="'+(isOverdue?'text-danger fw-bold':'')+'">'+dueCell+daysOver+'</td>'
                    +'<td style="min-width:90px"><div class="ppb"><div class="ppf" style="width:'+pct.toFixed(0)+'%"></div></div><small class="text-muted">'+pct.toFixed(0)+'% paid</small></td>'
                    +'<td>'+badge+'</td>'
                    +'<td><div class="d-flex gap-1 flex-wrap">'
                    +'<button class="btn btn-outline-primary btn-sm" onclick="toggleHistory('+cr.id+')" title="Payment history"><i class="fas fa-history"></i></button>'
                    +payBtn
                    +'</div><div id="hist_'+cr.id+'" class="pay-history"></div></td>'
                    +'</tr>';
            });
            $tbody.html(html);

            // Highlight & open pay if needed
            if(HIGHLIGHT_ID){
                var $row=$('tr[data-id="'+HIGHLIGHT_ID+'"]');
                if($row.length){$row.addClass('highlight-blink');$('html,body').animate({scrollTop:$row.offset().top-120},500);setTimeout(function(){$row.removeClass('highlight-blink');},3500);}
            }
            if(OPEN_PAY_ID){
                var $row2=$('tr[data-id="'+OPEN_PAY_ID+'"]');
                if($row2.length){var $pb=$row2.find('.btn-success');if($pb.length)setTimeout(function(){$pb.trigger('click');},600);}
            }

        } catch(err) {
            console.error('renderInvRows error:', err);
            $tbody.html('<tr><td colspan="10" class="text-center py-4 text-danger">'
                +'<i class="fas fa-exclamation-triangle me-2"></i>Render error: '+err.message
                +' — <a href="#" onclick="location.reload()">Reload</a></td></tr>');
        }
    }

    function doInvSearch(q,pg,limit){
        $spinner.show();
        var newUrl=window.location.pathname+'?tab=invoices&filter='+encodeURIComponent(currentFilter)+'&search='+encodeURIComponent(q)+'&page='+pg+'&per_page='+encodeURIComponent(limit);
        history.replaceState(null,'',newUrl);

        $.getJSON(SELF+'?ajax=search_invoices&q='+encodeURIComponent(q)+'&filter='+encodeURIComponent(currentFilter)+'&pg='+pg+'&limit='+encodeURIComponent(limit))
        .done(function(data){
            $spinner.hide();
            if(data&&data.rows!==undefined){
                renderInvRows(data.rows,data.total,data.pg,limit);
            } else {
                $tbody.html('<tr><td colspan="10" class="text-center py-4 text-muted">No data returned</td></tr>');
            }
        })
        .fail(function(xhr){
            $spinner.hide();
            console.error('Invoice search fail:', xhr.status, xhr.responseText);
            $tbody.html('<tr><td colspan="10" class="text-center py-4 text-danger">'
                +'<i class="fas fa-exclamation-triangle me-2"></i>Load failed. '
                +'<a href="#" onclick="location.reload()">Reload page</a></td></tr>');
        });
    }

    // ── Sort header click ──
    $(document).on('click','th[data-sort]',function(){
        var col=$(this).data('sort');
        if(_invSort.col===col){ _invSort.dir=_invSort.dir==='asc'?'desc':'asc'; }
        else { _invSort.col=col; _invSort.dir='asc'; }
        renderInvRows(_invCachedRows,_invCachedTotal,_invCachedPg,_invCachedLimit);
    });

    $input.on('input',function(){
        clearTimeout(timer);
        currentQ=$(this).val().trim();
        currentPg=1;
        _invSort={col:'',dir:'asc'};
        timer=setTimeout(function(){doInvSearch(currentQ,currentPg,currentLimit);},300);
    });

    $('#invPerPage').on('change',function(){
        currentLimit=$(this).val();currentPg=1;doInvSearch(currentQ,currentPg,currentLimit);
    });

    $(document).on('click','.inv-pg',function(e){
        e.preventDefault();currentPg=parseInt($(this).data('pg'));doInvSearch(currentQ,currentPg,currentLimit);
    });

    // Initial load
    doInvSearch(currentQ,currentPg,currentLimit);
})();

/* ── Add Credit ── */
function submitAdd(){
    var name=$('#addName').val().trim(),phone=$('#addPhone').val().trim(),amt=parseFloat($('#addAmount').val());
    if(!name){toast('Customer name required','danger');return;}
    if(!phone){toast('Phone required','danger');return;}
    if(isNaN(amt)||amt<=0){toast('Enter a valid amount','danger');return;}
    $('#addBtn').prop('disabled',true).html('<span class="spinner-border spinner-border-sm me-1"></span>Saving…');
    $.post(SELF+'?ajax=add_credit',{customer_name:name,customer_phone:phone,amount:amt,due_date:$('#addDue').val(),description:$('#addDesc').val().trim()},function(r){
        $('#addBtn').prop('disabled',false).html('<i class="fas fa-save me-1"></i>Add Credit');
        if(r.success){toast('Credit added successfully!');bootstrap.Modal.getInstance(document.getElementById('addModal')).hide();setTimeout(function(){location.reload();},800);}
        else{toast(r.message||'Failed','danger');}
    },'json').fail(function(){$('#addBtn').prop('disabled',false).html('<i class="fas fa-save me-1"></i>Add Credit');toast('Server error','danger');});
}

/* ── Pay Modal ── */
function openPayModal(id,name,remaining){
    $('#payCreditId').val(id);$('#payCustomerName').text(name);
    $('#payRemaining').text('Rs '+parseFloat(remaining).toFixed(2));
    $('#payAmount').val('').attr('max',remaining);$('#payMethod').val('cash');$('#payNote').val('');
    new bootstrap.Modal(document.getElementById('payModal')).show();
}

function submitPay(){
    var id=$('#payCreditId').val(),amt=parseFloat($('#payAmount').val()),rem=parseFloat($('#payRemaining').text().replace('Rs ',''));
    if(isNaN(amt)||amt<=0){toast('Enter a valid amount','danger');return;}
    if(amt>rem+0.01){toast('Amount exceeds remaining balance','danger');return;}
    $('#payBtn').prop('disabled',true).html('<span class="spinner-border spinner-border-sm me-1"></span>Saving…');
    $.post(SELF+'?ajax=pay_credit',{credit_id:id,amount:amt,payment_method:$('#payMethod').val(),note:$('#payNote').val().trim()},function(r){
        $('#payBtn').prop('disabled',false).html('<i class="fas fa-check me-1"></i>Record Payment');
        if(r.success){
            bootstrap.Modal.getInstance(document.getElementById('payModal')).hide();
            toast(r.message,'success');
            var $row=$('tr[data-id="'+id+'"]');
            var total=r.sale_total||parseFloat($row.find('td:eq(3)').text().replace('Rs ','').replace(/,/g,''));
            var newPaid=r.new_paid,newRem=r.new_remaining;
            var pct=total>0?Math.min(100,(newPaid/total)*100):0;
            $row.find('td:eq(4)').text('Rs '+newPaid.toFixed(2));
            $row.find('td:eq(5)').text('Rs '+newRem.toFixed(2)).removeClass('text-danger text-success').addClass(newRem>0.005?'text-danger':'text-success');
            $row.find('.ppf').css('width',pct.toFixed(0)+'%');
            var badge='';
            if(r.new_status==='paid')badge='<span class="b-paid"><i class="fas fa-check-circle me-1"></i>Paid</span>';
            else if(newRem<total&&newRem>0.005)badge='<span class="b-partial"><i class="fas fa-adjust me-1"></i>Partial</span>';
            else badge='<span class="b-active"><i class="fas fa-circle me-1"></i>Active</span>';
            $row.find('td:eq(8)').html(badge);
            if(r.new_status==='paid'){$row.removeClass('overdue-row');$row.find('.btn-success').remove();}
            $('#hist_'+id).hide().html('');
            // ── Notify other open tabs ──
            try{ var bc=new BroadcastChannel('credit_sync');
                 bc.postMessage({event:'payment',credit_id:parseInt(id),new_remaining:newRem,fully_paid:r.new_status==='paid',amount:amt});
                 bc.close(); }catch(e){}
        }else{toast(r.message||'Failed','danger');}
    },'json').fail(function(){$('#payBtn').prop('disabled',false).html('<i class="fas fa-check me-1"></i>Record Payment');toast('Server error','danger');});
}

/* ── Cross-tab sync: listen for payments made on other pages ── */
try {
    var _bcList = new BroadcastChannel("credit_sync");
    _bcList.onmessage = function(e){
        if(e.data && e.data.event === "payment"){
            var id   = e.data.credit_id;
            var $row = $('tr[data-id="'+id+'"]');
            if(!$row.length) return;
            var newRem = parseFloat(e.data.new_remaining)||0;
            $row.find("td:eq(5)").text("Rs "+newRem.toFixed(2)).removeClass("text-danger text-success").addClass(newRem>0.005?"text-danger":"text-success");
            if(e.data.fully_paid){
                $row.find("td:eq(8)").html('<span class="b-paid"><i class="fas fa-check-circle me-1"></i>Paid</span>');
                $row.removeClass("overdue-row");
                $row.find(".btn-success").remove();
            }
            $('#hist_'+id).hide().html("");
        }
    };
} catch(e){}

/* ── Payment History ── */
function toggleHistory(id){
    var $div=$('#hist_'+id);
    if($div.is(':visible')){$div.slideUp();return;}
    if($div.html().trim()!==''){$div.slideDown();return;}
    $div.html('<div class="text-center py-2"><span class="spinner-border spinner-border-sm text-primary"></span></div>').slideDown();
    $.ajax({url:SELF+'?ajax=get_credit&id='+id,dataType:'json',
        success:function(r){
            if(!r.success){$div.html('<div class="text-muted small">Error loading</div>');return;}
            if(!r.payments||r.payments.length===0){$div.html('<div class="text-muted small py-1"><i class="fas fa-info-circle me-1"></i>No payments recorded yet</div>');return;}
            var html='<tr><thead><tr><th style="padding:0 10px 4px 0">Date</th><th style="padding:0 10px 4px 0">Amount</th><th style="padding:0 10px 4px 0">Method</th><th>Note</th></tr></thead><tbody>';
            r.payments.forEach(function(p){html+='<tr><td style="padding:2px 10px 2px 0;color:#6b7280">'+(p.fmt_date||p.payment_date)+'</td><td style="padding:2px 10px 2px 0;color:#057a55;font-weight:600">Rs '+parseFloat(p.amount).toFixed(2)+'</td><td style="padding:2px 10px 2px 0;text-transform:capitalize">'+p.payment_method+'</td><td style="color:#6b7280">'+(p.note||'—')+'</td></tr>';});
            html+='</tbody></table>';
            var info='<div style="font-size:11px;color:#6b7280;margin-bottom:6px;"><i class="fas fa-history me-1"></i>'+r.payments.length+' payment(s) — Total: <strong class="text-success">Rs '+parseFloat(r.credit.display_total??r.credit.total_amount).toFixed(2)+'</strong> | Remaining: <strong class="text-danger">Rs '+parseFloat(r.credit.remaining).toFixed(2)+'</strong></div>';
            $div.html(info+html);
        },
        error:function(){$div.html('<div class="text-muted small">Failed to load</div>');}
    });
}

/* ── Customer Detail ── */
function viewCustomerInvoices(phone,name){
    _custPhone=phone;
    $('#custDetailTitle').html('<i class="fas fa-user me-2"></i>'+esc(name)+' — <small class="text-white-50">'+esc(phone)+'</small>');
    $('#custExportBtn').attr('href',SELF+'?ajax=export_customer&phone='+encodeURIComponent(phone)+'&name='+encodeURIComponent(name));
    $('#custDetailBody').html('<div class="text-center py-4"><div class="spinner-border text-primary"></div></div>');
    new bootstrap.Modal(document.getElementById('custDetailModal')).show();

    $.ajax({url:SELF+'?ajax=get_customer_credits&phone='+encodeURIComponent(phone),dataType:'json',
        success:function(r){
            if(!r.success){$('#custDetailBody').html('<div class="alert alert-danger">'+(r.message||'Unknown error')+'</div>');return;}
            var pendingTotal=parseFloat(r.total_pending)||0;
            var html='<div class="alert '+(pendingTotal>0?'alert-danger':'alert-success')+' py-2 px-3 mb-3"><strong>Total Pending: Rs '+pendingTotal.toFixed(2)+'</strong></div>';
            if(!r.credits||r.credits.length===0){html+='<div class="text-center text-muted py-3"><i class="fas fa-inbox fa-2x mb-2 d-block opacity-30"></i>No invoices found</div>';}
            else{
                html+='<div class="table-responsive"><table class="inv-tbl"><thead><tr><th>Invoice</th><th>Grand Total</th><th>Paid</th><th>Remaining</th><th>Due Date</th><th>Progress</th><th>Status</th><th>Action</th></tr></thead><tbody>';
                r.credits.forEach(function(cr){
                    var total=parseFloat(cr.display_total)||0,paid=parseFloat(cr.paid_total)||0,rem=parseFloat(cr.remaining)||0;
                    var pct=total>0?Math.min(100,(paid/total)*100):0,isPaid=rem<0.01,isActive=cr.status==='active';
                    var badge=isPaid?'<span style="background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;border-radius:20px;padding:2px 8px;font-size:10px;font-weight:600;">Paid</span>':'<span style="background:#fef2f2;color:#dc2626;border:1px solid #fecaca;border-radius:20px;padding:2px 8px;font-size:10px;font-weight:600;">'+(isActive?'Active':'Closed')+'</span>';
                    var invLink=cr.invoice_no?'<a href="'+BASE_URL+'sales/view.php?id='+cr.sale_id_fk+'" target="_blank" style="color:#1a56db;font-weight:600;"><i class="fas fa-receipt me-1" style="font-size:10px"></i>'+esc(cr.invoice_no)+'</a>':'<span style="color:var(--mu)"><i class="fas fa-hand-holding-usd me-1"></i>Manual</span>';
                    var payBtn=(!isPaid&&isActive)?'<button class="btn btn-outline-success btn-sm" style="font-size:10px;padding:2px 7px;" onclick="openPay2('+cr.id+',\''+esc(cr.invoice_no||'Manual')+'\','+rem+')" data-requires-perm="credit.pay"><i class="fas fa-money-bill-wave"></i> Pay</button>':'';
                    html+='<tr><td>'+invLink+'<br><small style="color:var(--mu)">'+(cr.sale_date||cr.created_at||'').substring(0,10)+'</small></td><td>Rs '+total.toFixed(2)+'</td><td class="text-success">Rs '+paid.toFixed(2)+'</td><td class="'+(rem>0.005?'text-danger fw-bold':'text-success')+'">Rs '+rem.toFixed(2)+'</td><td>'+(cr.due_date||'—')+'</td><td style="min-width:80px"><div class="ppb"><div class="ppf" style="width:'+pct.toFixed(0)+'%"></div></div><small style="color:var(--mu)">'+pct.toFixed(0)+'% paid</small></td><td>'+badge+'</td><td>'+payBtn+'</td></tr>';
                });
                html+='</tbody></table></div>';
            }
            $('#custDetailBody').html(html);
        },
        error:function(xhr){
            var em='Server error';
            try{var j=JSON.parse(xhr.responseText);if(j&&j.message)em=j.message;}catch(e){}
            $('#custDetailBody').html('<div class="alert alert-danger">'+em+'</div>');
        }
    });
}

/* ── Pay from Customer Detail ── */
function openPay2(creditId,invoiceLabel,remaining){
    $('#pay2CreditId').val(creditId);$('#pay2Invoice').text(invoiceLabel);
    $('#pay2Remaining').text('Rs '+parseFloat(remaining).toFixed(2));
    $('#pay2Amount').val('').attr('max',remaining);$('#pay2Method').val('cash');$('#pay2Note').val('');
    new bootstrap.Modal(document.getElementById('payModal2')).show();
}

function submitPay2(){
    var id=$('#pay2CreditId').val(),amt=parseFloat($('#pay2Amount').val()),rem=parseFloat($('#pay2Remaining').text().replace('Rs ',''));
    if(isNaN(amt)||amt<=0){toast('Enter valid amount','danger');return;}
    if(amt>rem+0.01){toast('Amount exceeds remaining balance','danger');return;}
    $('#pay2Btn').prop('disabled',true).html('<span class="spinner-border spinner-border-sm"></span> Saving…');
    $.post(SELF+'?ajax=pay_credit',{credit_id:id,amount:amt,payment_method:$('#pay2Method').val(),note:$('#pay2Note').val().trim()},function(r){
        $('#pay2Btn').prop('disabled',false).html('<i class="fas fa-check me-1"></i>Record Payment');
        if(r.success){
            bootstrap.Modal.getInstance(document.getElementById('payModal2')).hide();
            toast(r.message,'success');
            // ── Notify other open tabs ──
            try{ var bc=new BroadcastChannel('credit_sync');
                 bc.postMessage({event:'payment',credit_id:parseInt(id),new_remaining:r.new_remaining,fully_paid:r.new_status==='paid',amount:amt});
                 bc.close(); }catch(e){}
            setTimeout(function(){
                if(_custPhone){
                    var rawT=$('#custDetailTitle').text();
                    var nm=rawT.split('—')[0].trim().replace(/Customer Invoices/i,'').trim()||_custPhone;
                    viewCustomerInvoices(_custPhone,nm);
                }
            },500);
        }else{toast(r.message||'Failed','danger');}
    },'json').fail(function(){$('#pay2Btn').prop('disabled',false).html('<i class="fas fa-check me-1"></i>Record Payment');toast('Server error','danger');});
}
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    UniversalTable.init('creditListTable', {
        title      : 'Credit List',
        pageSize   : 25,
        excludeCols: [7, 9]
    });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
