<?php
/**
 * suppliers/ajax_handler.php — Central AJAX Endpoint for Suppliers Module
 * Phase 19: Full AJAX Modernisation
 *
 * Actions:
 *   get_list        – Paginated, filtered supplier list (HTML fragment)
 *   get_summary     – Stat-card counts (JSON)
 *   get_supplier    – Single supplier detail (JSON)
 *   save_supplier   – Add or edit a supplier (POST → JSON)
 *   delete_supplier – Delete / deactivate a supplier (POST → JSON)
 *   search          – Live name/phone autocomplete (JSON)
 */

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/purchase_functions.php';

requireLogin();

$db     = db();
$action = sanitize($_REQUEST['action'] ?? '');
$userId = (int)($_SESSION['user_id'] ?? 0);

// ── Helper ──────────────────────────────────────────────────────────────────
function suppJsonError(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

// ── Route ───────────────────────────────────────────────────────────────────
switch ($action) {

    // ── Live autocomplete search (purchases / pay modal) ───────────────────
    case 'search':
        header('Content-Type: application/json');
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 1) { echo json_encode([]); exit; }

        $rows = getAll(
            "SELECT id, supplier_name, phone, email, current_balance
             FROM suppliers
             WHERE status = 1 AND (supplier_name LIKE :s OR phone LIKE :s2 OR contact_person LIKE :s3)
             ORDER BY supplier_name ASC
             LIMIT 10",
            [':s' => "%$q%", ':s2' => "%$q%", ':s3' => "%$q%"]
        );
        echo json_encode($rows);
        exit;

    // ── Summary stat cards ─────────────────────────────────────────────────
    case 'get_summary':
        header('Content-Type: application/json');

        $totRow = getOne(
            "SELECT
                COUNT(*) AS total_suppliers,
                COALESCE(SUM(CASE WHEN current_balance > 0 THEN 1 ELSE 0 END), 0) AS with_due
             FROM suppliers WHERE status = 1"
        );
        $balRow = getOne(
            "SELECT
                COALESCE(SUM(p.total_amount), 0) AS grand_purchases,
                COALESCE(SUM(p.paid_amount), 0)  AS grand_paid,
                COALESCE(SUM(p.due_amount), 0)   AS grand_due
             FROM purchases p
             WHERE p.status != 'cancelled'"
        );
        echo json_encode([
            'success'          => true,
            'total_suppliers'  => (int)($totRow['total_suppliers'] ?? 0),
            'with_due'         => (int)($totRow['with_due']        ?? 0),
            'grand_purchases'  => (float)($balRow['grand_purchases'] ?? 0),
            'grand_paid'       => (float)($balRow['grand_paid']      ?? 0),
            'grand_due'        => (float)($balRow['grand_due']       ?? 0),
        ]);
        exit;

    // ── Paginated list (HTML fragment) ─────────────────────────────────────
    case 'get_list':
        if (!canView('suppliers')) suppJsonError('Permission denied', 403);

        $page    = max(1, (int)($_GET['page']   ?? 1));
        $perPage = 20;
        $offset  = ($page - 1) * $perPage;
        $search  = sanitize($_GET['search'] ?? '');
        $filter  = sanitize($_GET['filter'] ?? ''); // 'with_due' | 'clear' | ''

        $where  = ["s.status = 1"];
        $params = [];

        if ($search !== '') {
            $where[]           = '(s.supplier_name LIKE :search OR s.phone LIKE :search OR s.email LIKE :search OR s.contact_person LIKE :search)';
            $params[':search'] = "%$search%";
        }
        if ($filter === 'with_due') {
            $where[] = "s.current_balance > 0";
        } elseif ($filter === 'clear') {
            $where[] = "(s.current_balance IS NULL OR s.current_balance <= 0)";
        }

        $whereSQL = 'WHERE ' . implode(' AND ', $where);

        $countRow     = getOne("SELECT COUNT(*) AS total FROM suppliers s $whereSQL", $params);
        $totalRecords = (int)($countRow['total'] ?? 0);
        $totalPages   = max(1, (int)ceil($totalRecords / $perPage));

        $suppliers = getAll(
            "SELECT s.*,
                    COALESCE(SUM(CASE WHEN p.status != 'cancelled' THEN p.total_amount ELSE 0 END), 0) AS total_purchases,
                    COALESCE(SUM(CASE WHEN p.status != 'cancelled' THEN p.paid_amount  ELSE 0 END), 0) AS total_paid,
                    COALESCE(SUM(CASE WHEN p.status != 'cancelled' THEN p.due_amount   ELSE 0 END), 0) AS total_due,
                    COUNT(CASE WHEN p.status != 'cancelled' THEN p.id END)                            AS purchase_count
             FROM suppliers s
             LEFT JOIN purchases p ON p.supplier_id = s.id
             $whereSQL
             GROUP BY s.id
             ORDER BY s.supplier_name ASC
             LIMIT :limit OFFSET :offset",
            array_merge($params, [':limit' => $perPage, ':offset' => $offset])
        );

        header('Content-Type: text/html; charset=utf-8');
        ob_start();
        $serialStart = $offset + 1;
        include __DIR__ . '/list_fragment.php';
        echo ob_get_clean();
        exit;

    // ── Single supplier detail ─────────────────────────────────────────────
    case 'get_supplier':
        header('Content-Type: application/json');
        if (!canView('suppliers')) suppJsonError('Permission denied', 403);

        $sid = (int)($_GET['id'] ?? 0);
        if (!$sid) suppJsonError('Invalid ID');

        $supplier = getOne(
            "SELECT s.*,
                    COALESCE(SUM(CASE WHEN p.status!='cancelled' THEN p.total_amount ELSE 0 END),0) AS total_purchases,
                    COALESCE(SUM(CASE WHEN p.status!='cancelled' THEN p.paid_amount  ELSE 0 END),0) AS total_paid,
                    COALESCE(SUM(CASE WHEN p.status!='cancelled' THEN p.due_amount   ELSE 0 END),0) AS total_due,
                    COUNT(CASE WHEN p.status!='cancelled' THEN p.id END)                           AS purchase_count
             FROM suppliers s
             LEFT JOIN purchases p ON p.supplier_id = s.id
             WHERE s.id = :id
             GROUP BY s.id",
            [':id' => $sid]
        );
        if (!$supplier) suppJsonError('Supplier not found', 404);

        // Recent purchases
        $purchases = getAll(
            "SELECT id, invoice_no, purchase_date, total_amount, paid_amount, due_amount, payment_status, status
             FROM purchases
             WHERE supplier_id = :id AND status != 'cancelled'
             ORDER BY purchase_date DESC
             LIMIT 10",
            [':id' => $sid]
        );

        echo json_encode([
            'success'   => true,
            'supplier'  => $supplier,
            'purchases' => $purchases,
        ]);
        exit;

    // ── Save supplier (add or edit) ────────────────────────────────────────
    case 'save_supplier':
        header('Content-Type: application/json');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') suppJsonError('POST required', 405);
        if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) suppJsonError('Invalid security token', 403);

        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            if (!canEdit('suppliers')) suppJsonError('Permission denied', 403);
        } else {
            if (!canAdd('suppliers')) suppJsonError('Permission denied', 403);
        }

        $data = [
            'supplier_name'     => trim($_POST['supplier_name']     ?? ''),
            'contact_person'    => trim($_POST['contact_person']    ?? ''),
            'phone'             => trim($_POST['phone']             ?? ''),
            'alternative_phone' => trim($_POST['alternative_phone'] ?? ''),
            'email'             => trim($_POST['email']             ?? ''),
            'address'           => trim($_POST['address']           ?? ''),
            'gst_no'            => trim($_POST['gst_no']            ?? ''),
        ];

        if (empty($data['supplier_name'])) suppJsonError('Supplier name is required');
        if (empty($data['phone']))         suppJsonError('Phone number is required');

        if ($id) {
            $existing = getOne("SELECT id FROM suppliers WHERE id = :id", [':id' => $id]);
            if (!$existing) suppJsonError('Supplier not found', 404);

            updateSupplier($id, $data);
            logActivity('Supplier Updated', "ID $id – {$data['supplier_name']} ({$data['phone']})", $userId);
            echo json_encode(['success' => true, 'id' => $id, 'message' => 'Supplier updated successfully']);
        } else {
            $data['opening_balance'] = (float)($_POST['opening_balance'] ?? 0);
            $newId = addSupplier($data);
            if (!$newId) suppJsonError('Failed to add supplier. Please try again.');
            logActivity('Supplier Added', "ID $newId – {$data['supplier_name']} ({$data['phone']})", $userId);
            echo json_encode(['success' => true, 'id' => $newId, 'message' => 'Supplier added successfully']);
        }
        exit;

    // ── Delete supplier ────────────────────────────────────────────────────
    case 'delete_supplier':
        header('Content-Type: application/json');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') suppJsonError('POST required', 405);
        if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) suppJsonError('Invalid security token', 403);
        if (!canDelete('suppliers')) suppJsonError('Permission denied', 403);

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) suppJsonError('Supplier ID required');

        $supplier = getOne("SELECT * FROM suppliers WHERE id = :id", [':id' => $id]);
        if (!$supplier) suppJsonError('Supplier not found', 404);

        // Block if pending dues exist
        $dueRow = getOne(
            "SELECT COALESCE(SUM(due_amount), 0) AS total_due
             FROM purchases
             WHERE supplier_id = :id AND payment_status != 'paid' AND status != 'cancelled'",
            [':id' => $id]
        );
        $pendingDue = (float)($dueRow['total_due'] ?? 0);
        if ($pendingDue > 0.005) {
            suppJsonError('Cannot delete: supplier has pending dues of Rs ' . number_format($pendingDue, 2) . '. Clear all dues first.');
        }

        deleteSupplier($id);
        logActivity('Supplier Deleted', "ID $id – {$supplier['supplier_name']} ({$supplier['phone']})", $userId);
        echo json_encode(['success' => true, 'message' => "Supplier \"{$supplier['supplier_name']}\" deleted"]);
        exit;

    default:
        header('Content-Type: application/json');
        suppJsonError('Unknown action');
}
