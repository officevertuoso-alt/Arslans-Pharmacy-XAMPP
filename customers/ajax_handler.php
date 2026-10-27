<?php
/**
 * customers/ajax_handler.php — Central AJAX Endpoint for Customers Module
 * Phase 18: Full AJAX Modernisation
 *
 * Actions:
 *   get_list         – Paginated, filtered customer list (HTML fragment)
 *   get_summary      – Stat-card counts (JSON)
 *   get_customer     – Single customer row + credit detail (JSON)
 *   save_customer    – Add or edit a customer (POST → JSON)
 *   delete_customer  – Delete a customer (POST → JSON)  [admin only; blocked if active credits]
 *   pay_credit       – Record a credit payment (POST → JSON)
 *   search           – Live name/phone autocomplete (JSON)
 *   export_customer  – Single-customer CSV download
 *   export_all       – All-customers CSV download
 */

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/sync_engine.php';

requireLogin();

$db     = db();
$action = sanitize($_REQUEST['action'] ?? '');
$userId = (int)($_SESSION['user_id'] ?? 0);

// ── Helper ─────────────────────────────────────────────────────────────────
function custJsonError(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

// ── Route ──────────────────────────────────────────────────────────────────
switch ($action) {

    // ── Live autocomplete search (POS / credit modals) ─────────────────────
    case 'search':
        header('Content-Type: application/json');
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 1) { echo json_encode([]); exit; }

        $rows = $db->getAll(
            "SELECT c.id, c.name, c.phone,
                    COALESCE(SUM(CASE WHEN cc.status='active'
                        THEN (COALESCE(s.total, cc.total_amount)
                              - COALESCE(s.paid_amount,0)
                              - COALESCE((SELECT SUM(cp2.amount) FROM credit_payments cp2
                                          WHERE cp2.credit_id=cc.id
                                            AND (cp2.note IS NULL OR (cp2.note NOT LIKE 'Upfront%' AND cp2.note NOT LIKE 'upfront%'))), 0))
                        ELSE 0 END), 0) AS total_pending
             FROM customers c
             LEFT JOIN customer_credits cc ON (cc.customer_phone = c.phone AND cc.status='active')
             LEFT JOIN sales s ON s.id = cc.sale_id
             WHERE c.name LIKE ? OR c.phone LIKE ?
             GROUP BY c.id
             ORDER BY total_pending DESC, c.name ASC
             LIMIT 10",
            ["%$q%", "%$q%"]
        );
        echo json_encode($rows);
        exit;

    // ── Summary stat cards ─────────────────────────────────────────────────
    case 'get_summary':
        header('Content-Type: application/json');
        $summary = $db->getOne(
            "SELECT
                COUNT(*) AS total_customers,
                COUNT(CASE WHEN EXISTS(
                    SELECT 1 FROM customer_credits cc2
                    WHERE cc2.customer_phone = c.phone AND cc2.status='active'
                ) THEN 1 END) AS with_pending
             FROM customers c"
        );
        $pendingSum = $db->getOne(
            "SELECT COALESCE(SUM(
                COALESCE(s.total, cc.total_amount)
                - COALESCE(s.paid_amount,0)
                - COALESCE((SELECT SUM(cp2.amount) FROM credit_payments cp2
                             WHERE cp2.credit_id=cc.id
                               AND (cp2.note IS NULL OR (cp2.note NOT LIKE 'Upfront%' AND cp2.note NOT LIKE 'upfront%'))), 0)
             ), 0) AS grand_pending
             FROM customer_credits cc
             LEFT JOIN sales s ON s.id = cc.sale_id
             WHERE cc.status='active'"
        );
        echo json_encode([
            'success'           => true,
            'total_customers'   => (int)($summary['total_customers'] ?? 0),
            'with_pending'      => (int)($summary['with_pending']    ?? 0),
            'grand_pending'     => (float)($pendingSum['grand_pending'] ?? 0),
        ]);
        exit;

    // ── Paginated list (HTML fragment) ─────────────────────────────────────
    case 'get_list':
        $page    = max(1, (int)($_GET['page']   ?? 1));
        $perPage = 25;
        $offset  = ($page - 1) * $perPage;
        $search  = sanitize($_GET['search'] ?? '');
        $filter  = sanitize($_GET['filter'] ?? ''); // 'pending' | 'clear' | ''

        $where  = [];
        $params = [];

        if ($search !== '') {
            $where[]             = '(c.name LIKE :search OR c.phone LIKE :search)';
            $params[':search']   = "%$search%";
        }
        if ($filter === 'pending') {
            $where[] = "EXISTS(SELECT 1 FROM customer_credits cc2 WHERE cc2.customer_phone=c.phone AND cc2.status='active')";
        } elseif ($filter === 'clear') {
            $where[] = "NOT EXISTS(SELECT 1 FROM customer_credits cc2 WHERE cc2.customer_phone=c.phone AND cc2.status='active')";
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $countRow     = $db->getOne("SELECT COUNT(*) AS total FROM customers c $whereSQL", $params);
        $totalRecords = (int)($countRow['total'] ?? 0);
        $totalPages   = max(1, (int)ceil($totalRecords / $perPage));

        $customers = $db->getAll(
            "SELECT c.*,
                    COUNT(DISTINCT cc.id) AS total_credits,
                    COUNT(DISTINCT CASE WHEN cc.status='active' THEN cc.id END) AS active_credits,
                    COALESCE(SUM(CASE WHEN cc.status='active'
                        THEN (COALESCE(s.total, cc.total_amount)
                              - COALESCE(s.paid_amount,0)
                              - COALESCE((SELECT SUM(cp2.amount) FROM credit_payments cp2
                                          WHERE cp2.credit_id=cc.id
                                            AND (cp2.note IS NULL OR (cp2.note NOT LIKE 'Upfront%' AND cp2.note NOT LIKE 'upfront%'))), 0))
                        ELSE 0 END), 0) AS total_pending
             FROM customers c
             LEFT JOIN customer_credits cc ON cc.customer_phone = c.phone
             LEFT JOIN sales s ON s.id = cc.sale_id
             $whereSQL
             GROUP BY c.id
             ORDER BY total_pending DESC, c.name ASC
             LIMIT $offset, $perPage",
            $params
        );

        header('Content-Type: text/html; charset=utf-8');
        ob_start();
        $serialStart = $offset + 1;
        include __DIR__ . '/list_fragment.php';
        echo ob_get_clean();
        exit;

    // ── Single customer detail + credits ───────────────────────────────────
    case 'get_customer':
        header('Content-Type: application/json');
        $cid = (int)($_GET['id'] ?? 0);
        if (!$cid) custJsonError('Invalid ID');

        $customer = $db->getOne("SELECT * FROM customers WHERE id=:id", [':id' => $cid]);
        if (!$customer) custJsonError('Customer not found', 404);

        $credits = $db->getAll(
            "SELECT cc.*,
                    COALESCE(s.total, cc.total_amount)        AS display_total,
                    COALESCE(s.paid_amount, 0)                AS sale_upfront,
                    COALESCE((SELECT SUM(cp2.amount) FROM credit_payments cp2
                               WHERE cp2.credit_id=cc.id
                                 AND (cp2.note IS NULL OR (cp2.note NOT LIKE 'Upfront%' AND cp2.note NOT LIKE 'upfront%'))), 0) AS post_paid,
                    s.invoice_no, s.sale_date, s.id AS sale_id_fk
             FROM customer_credits cc
             LEFT JOIN sales s ON s.id = cc.sale_id
             WHERE cc.customer_phone = :ph
             ORDER BY cc.status ASC, cc.due_date ASC",
            [':ph' => $customer['phone']]
        );

        $totalPending = 0;
        foreach ($credits as &$cr) {
            $cr['remaining'] = max(0, (float)$cr['display_total'] - (float)$cr['sale_upfront'] - (float)$cr['post_paid']);
            $cr['paid']      = (float)$cr['sale_upfront'] + (float)$cr['post_paid'];
            if ($cr['status'] === 'active') $totalPending += $cr['remaining'];
        }
        unset($cr);

        echo json_encode([
            'success'       => true,
            'customer'      => $customer,
            'credits'       => $credits,
            'total_pending' => $totalPending,
        ]);
        exit;

    // ── Save customer (add or edit) ────────────────────────────────────────
    case 'save_customer':
        header('Content-Type: application/json');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') custJsonError('POST required', 405);
        if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) custJsonError('Invalid security token', 403);

        $id      = (int)($_POST['id']      ?? 0);
        $name    = trim($_POST['name']     ?? '');
        $phone   = trim($_POST['phone']    ?? '');
        $address = trim($_POST['address']  ?? '');
        $notes   = trim($_POST['notes']    ?? '');

        if (!$name)  custJsonError('Name is required');
        if (!$phone) custJsonError('Phone is required');

        if ($id) {
            // Edit — check phone uniqueness excluding self
            $conflict = $db->getOne("SELECT id FROM customers WHERE phone=? AND id<>?", [$phone, $id]);
            if ($conflict) custJsonError('Phone already used by another customer (ID ' . $conflict['id'] . ')');

            // Get old phone to update customer_credits if phone changed
            $old = $db->getOne("SELECT phone FROM customers WHERE id=?", [$id]);
            $db->query(
                "UPDATE customers SET name=?, phone=?, address=?, notes=? WHERE id=?",
                [$name, $phone, $address, $notes, $id]
            );
            // Cascade phone update to customer_credits if changed
            if ($old && $old['phone'] !== $phone) {
                $db->query(
                    "UPDATE customer_credits SET customer_phone=?, customer_name=? WHERE customer_phone=?",
                    [$phone, $name, $old['phone']]
                );
            }
            logActivity('Customer Updated', "ID $id – $name ($phone)", $userId);
            echo json_encode(['success' => true, 'id' => $id, 'message' => 'Customer updated successfully']);
        } else {
            $exists = $db->getOne("SELECT id FROM customers WHERE phone=?", [$phone]);
            if ($exists) custJsonError('Phone already registered (ID ' . $exists['id'] . ')');

            $db->query(
                "INSERT INTO customers (name, phone, address, notes) VALUES (?,?,?,?)",
                [$name, $phone, $address, $notes]
            );
            $newId = $db->lastId();
            logActivity('Customer Added', "ID $newId – $name ($phone)", $userId);
            echo json_encode(['success' => true, 'id' => $newId, 'message' => 'Customer added successfully']);
        }
        exit;

    // ── Delete customer ────────────────────────────────────────────────────
    case 'delete_customer':
        header('Content-Type: application/json');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') custJsonError('POST required', 405);
        if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) custJsonError('Invalid security token', 403);

        // Only admins may delete
        if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
            custJsonError('Admin access required', 403);
        }

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) custJsonError('Customer ID required');

        $customer = $db->getOne("SELECT * FROM customers WHERE id=?", [$id]);
        if (!$customer) custJsonError('Customer not found', 404);

        // Block if active credits exist
        $activeCredits = $db->getOne(
            "SELECT COUNT(*) AS cnt FROM customer_credits WHERE customer_phone=? AND status='active'",
            [$customer['phone']]
        );
        if ((int)($activeCredits['cnt'] ?? 0) > 0) {
            custJsonError('Cannot delete: customer has active credit balances. Clear all dues first.');
        }

        $db->query("DELETE FROM customers WHERE id=?", [$id]);
        logActivity('Customer Deleted', "ID $id – {$customer['name']} ({$customer['phone']})", $userId);
        echo json_encode(['success' => true, 'message' => "Customer \"{$customer['name']}\" deleted"]);
        exit;

    // ── Record credit payment ──────────────────────────────────────────────
    case 'pay_credit':
        header('Content-Type: application/json');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') custJsonError('POST required', 405);
        if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) custJsonError('Invalid security token', 403);

        $creditId      = (int)($_POST['credit_id']      ?? 0);
        $amount        = floatval($_POST['amount']       ?? 0);
        $paymentMethod = trim($_POST['payment_method']   ?? 'cash');
        $note          = trim($_POST['note']             ?? '');

        if (!in_array($paymentMethod, ['cash', 'card', 'online'])) $paymentMethod = 'cash';
        if ($creditId <= 0) custJsonError('Invalid credit ID');
        if ($amount   <= 0) custJsonError('Amount must be greater than 0');

        $credit = $db->getOne(
            "SELECT cc.*, s.total AS sale_total, s.paid_amount AS sale_upfront
             FROM customer_credits cc
             LEFT JOIN sales s ON s.id = cc.sale_id
             WHERE cc.id=:id AND cc.status='active'",
            [':id' => $creditId]
        );
        if (!$credit) custJsonError('Credit not found or already paid');

        $paidRow     = $db->getOne(
            "SELECT COALESCE(SUM(amount),0) AS paid FROM credit_payments
             WHERE credit_id=:cid AND (note IS NULL OR (note NOT LIKE 'Upfront%' AND note NOT LIKE 'upfront%'))",
            [':cid' => $creditId]
        );
        $saleTotal   = (float)($credit['sale_total']    ?? $credit['total_amount'] ?? 0);
        $saleUpfront = (float)($credit['sale_upfront']  ?? 0);
        $postPaid    = (float)($paidRow['paid']         ?? 0);
        $remaining   = max(0, $saleTotal - $saleUpfront - $postPaid);

        if ($amount > $remaining + 0.01) {
            custJsonError('Amount exceeds remaining balance (Rs ' . number_format($remaining, 2) . ')');
        }

        $db->beginTransaction();
        try {
            $db->query(
                "INSERT INTO credit_payments (credit_id, amount, payment_method, note, payment_date) VALUES (?,?,?,?,NOW())",
                [$creditId, round($amount, 2), $paymentMethod, $note]
            );
            $payId      = $db->lastId();
            $newPostPaid = $postPaid + $amount;
            $newRem     = max(0, $saleTotal - $saleUpfront - $newPostPaid);
            $fullyPaid  = $newRem < 0.01;
            if ($fullyPaid) {
                $db->query("UPDATE customer_credits SET status='paid' WHERE id=?", [$creditId]);
            }
            $db->commit();

            if (function_exists('syncAfterCreditPayment')) {
                syncAfterCreditPayment($creditId, $payId, $amount, $paymentMethod);
            }
            logActivity('Credit Payment',
                'Rs ' . number_format($amount, 2) . " from {$credit['customer_name']} (Credit #$creditId)" .
                ($fullyPaid ? ' — FULLY PAID' : ''),
                $userId
            );

            echo json_encode([
                'success'       => true,
                'fully_paid'    => $fullyPaid,
                'new_remaining' => $newRem,
                'message'       => $fullyPaid
                    ? 'Credit fully paid!'
                    : 'Payment of Rs ' . number_format($amount, 2) . ' recorded',
            ]);
        } catch (Exception $e) {
            $db->rollback();
            custJsonError('Transaction error: ' . $e->getMessage());
        }
        exit;

    // ── Export single customer CSV ─────────────────────────────────────────
    case 'export_customer':
        $phone = trim($_GET['phone'] ?? '');
        if (!$phone) { header('Content-Type: application/json'); custJsonError('Phone required'); }

        $credits = $db->getAll(
            "SELECT cc.*,
                    COALESCE(s.total, cc.total_amount)  AS display_total,
                    COALESCE(s.paid_amount, 0)           AS sale_upfront,
                    COALESCE((SELECT SUM(cp2.amount) FROM credit_payments cp2
                               WHERE cp2.credit_id=cc.id
                                 AND (cp2.note IS NULL OR (cp2.note NOT LIKE 'Upfront%' AND cp2.note NOT LIKE 'upfront%'))), 0) AS post_paid,
                    s.invoice_no, s.sale_date
             FROM customer_credits cc
             LEFT JOIN sales s ON s.id = cc.sale_id
             WHERE cc.customer_phone = ?
             ORDER BY cc.status ASC, cc.due_date ASC",
            [$phone]
        );

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="customer_' . preg_replace('/[^a-z0-9]/i', '_', $phone) . '_' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, ['Invoice No', 'Sale Date', 'Total Amount', 'Upfront Paid', 'Post Payments', 'Remaining', 'Due Date', 'Status']);
        foreach ($credits as $cr) {
            $rem = max(0, (float)$cr['display_total'] - (float)$cr['sale_upfront'] - (float)$cr['post_paid']);
            fputcsv($out, [
                $cr['invoice_no'] ?? 'Manual',
                $cr['sale_date']  ?? $cr['created_at'] ?? '',
                number_format((float)$cr['display_total'], 2),
                number_format((float)$cr['sale_upfront'],  2),
                number_format((float)$cr['post_paid'],     2),
                number_format($rem, 2),
                $cr['due_date'] ?? '',
                $cr['status'],
            ]);
        }
        fclose($out);
        exit;

    // ── Export all customers CSV ───────────────────────────────────────────
    case 'export_all':
        $rows = $db->getAll(
            "SELECT c.name, c.phone, c.address,
                    COUNT(DISTINCT cc.id) AS total_credits,
                    COUNT(DISTINCT CASE WHEN cc.status='active' THEN cc.id END) AS active_credits,
                    COALESCE(SUM(CASE WHEN cc.status='active'
                        THEN (COALESCE(s.total, cc.total_amount)
                              - COALESCE(s.paid_amount,0)
                              - COALESCE((SELECT SUM(cp2.amount) FROM credit_payments cp2
                                          WHERE cp2.credit_id=cc.id
                                            AND (cp2.note IS NULL OR (cp2.note NOT LIKE 'Upfront%' AND cp2.note NOT LIKE 'upfront%'))), 0))
                        ELSE 0 END), 0) AS total_pending
             FROM customers c
             LEFT JOIN customer_credits cc ON cc.customer_phone = c.phone
             LEFT JOIN sales s ON s.id = cc.sale_id
             GROUP BY c.id
             ORDER BY total_pending DESC",
            []
        );
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="all_customers_' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, ['Customer Name', 'Phone', 'Address', 'Total Credits', 'Active Credits', 'Total Pending (Rs)']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['name'],
                $r['phone'],
                $r['address']         ?? '',
                $r['total_credits'],
                $r['active_credits'],
                number_format((float)$r['total_pending'], 2),
            ]);
        }
        fclose($out);
        exit;

    default:
        header('Content-Type: application/json');
        custJsonError('Unknown action');
}
