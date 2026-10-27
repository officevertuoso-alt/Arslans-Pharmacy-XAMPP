<?php
/**
 * sales/ajax_handler.php — Central AJAX Endpoint for Sales Module
 * Phase 17: Full AJAX conversion
 *
 * Actions:
 *   get_list          – Paginated sales list (HTML fragment or JSON)
 *   get_sale          – Fetch single sale for view/edit
 *   delete_sale       – Void a sale (admin only)
 *   update_sale       – Edit sale header (customer name, payment method)
 *   process_return    – Process a sale return
 *   get_return_items  – Get returnable items for a sale
 *   generate_invoice  – Generate invoice HTML (preview)
 *   search_products   – Product search for POS
 *   get_customers     – Customer search/autocomplete
 *   get_summary       – Summary stat cards (JSON)
 *   get_invoice_html  – Full invoice HTML for print
 */

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/sale_functions.php';
require_once __DIR__ . '/../includes/return_functions.php';
require_once __DIR__ . '/../includes/sync_engine.php';

header('Content-Type: application/json');

// ── Auth ───────────────────────────────────────────────────────────────────
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$action = $_REQUEST['action'] ?? '';
$userId = (int)($_SESSION['user_id'] ?? 0);
$db     = db();

// Phase 21: base-level sales view permission check
if (!canView('sales') && !in_array($action, ['process_return','void_sale'])) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Permission denied.']); exit;
}

// ── Route ──────────────────────────────────────────────────────────────────
switch ($action) {

    // ── Paginated sales list ───────────────────────────────────────────────
    case 'get_list':
        requirePharmacist();
        $page      = max(1, (int)($_GET['page'] ?? 1));
        $search    = sanitize($_GET['search']    ?? '');
        $fromDate  = sanitize($_GET['from_date'] ?? '');
        $toDate    = sanitize($_GET['to_date']   ?? '');
        $pmFilter  = sanitize($_GET['payment_method'] ?? '');
        $psFilter  = sanitize($_GET['payment_status'] ?? '');

        $sales = getAllSales($page, 20, $search, $fromDate, $toDate, $pmFilter, $psFilter);

        // Pre-fetch credit info (no N+1 — one query)
        $creditMap = [];
        if (!empty($sales['data'])) {
            $ids = array_column($sales['data'], 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $crRows = $db->getAll(
                "SELECT cc.sale_id, cc.id AS credit_id, cc.total_amount, cc.status,
                        COALESCE(SUM(CASE WHEN (cp.note IS NULL OR (cp.note NOT LIKE 'Upfront%' AND cp.note NOT LIKE 'upfront%')) THEN cp.amount ELSE 0 END), 0) AS post_sale_paid
                 FROM customer_credits cc
                 LEFT JOIN credit_payments cp ON cp.credit_id = cc.id
                 WHERE cc.sale_id IN ($placeholders)
                 GROUP BY cc.sale_id, cc.id",
                $ids
            );
            foreach ($crRows as $cr) {
                $creditMap[$cr['sale_id']] = $cr;
            }
        }

        header('Content-Type: text/html; charset=utf-8');

        // Render HTML fragment
        ob_start();
        $serialStart = ($page - 1) * 20 + 1;
        include __DIR__ . '/list_fragment.php';
        $html = ob_get_clean();

        echo $html;
        exit;

    // ── Get single sale ────────────────────────────────────────────────────
    case 'get_sale':
        $id   = (int)($_GET['id'] ?? 0);
        if (!$id) { jsonSalesError('Sale ID required'); }

        $sale = getSaleById($id);
        if (!$sale) { jsonSalesError('Sale not found or voided', 404); }

        // Credit info
        $creditInfo = $db->getOne(
            "SELECT cc.*,
                    COALESCE(SUM(CASE WHEN (cp.note IS NULL OR (cp.note NOT LIKE 'Upfront%' AND cp.note NOT LIKE 'upfront%')) THEN cp.amount ELSE 0 END), 0) AS total_paid,
                    (cc.total_amount - COALESCE(SUM(CASE WHEN (cp.note IS NULL OR (cp.note NOT LIKE 'Upfront%' AND cp.note NOT LIKE 'upfront%')) THEN cp.amount ELSE 0 END), 0)) AS remaining
             FROM customer_credits cc
             LEFT JOIN credit_payments cp ON cp.credit_id = cc.id
             WHERE cc.sale_id = :sid
             GROUP BY cc.id
             ORDER BY cc.id DESC LIMIT 1",
            [':sid' => $id]
        );
        $sale['credit_info'] = $creditInfo;

        echo json_encode(['success' => true, 'data' => $sale]);
        break;

    // ── Delete (void) a sale ───────────────────────────────────────────────
    case 'delete_sale':
        requireSalesAdmin();
        requireSalesMethodPost();
        verifySalesCSRF();

        $id     = (int)($_POST['sale_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? 'Voided by admin');
        if (!$id)    { jsonSalesError('Sale ID required'); }
        if (!$reason){ jsonSalesError('Void reason required'); }

        // Check sale exists and is not already voided
        $sale = $db->getOne("SELECT * FROM sales WHERE id = :id", [':id' => $id]);
        if (!$sale) { jsonSalesError('Sale not found', 404); }
        if ($sale['status'] === 'voided') { jsonSalesError('Sale already voided'); }

        $ok = voidSale($id, $reason);
        if ($ok) {
            // Sync cashbook
            try {
                syncAfterSale($id, $sale['payment_method'], (float)$sale['total'], 0, true, $sale['invoice_no']);
            } catch (\Throwable $e) { /* swallow sync error — void already done */ }

            echo json_encode(['success' => true, 'message' => 'Sale voided successfully', 'sale_id' => $id]);
        } else {
            jsonSalesError('Failed to void sale. Please try again.');
        }
        break;

    // ── Edit sale header ───────────────────────────────────────────────────
    case 'update_sale':
        requirePharmacist();
        requireSalesMethodPost();
        verifySalesCSRF();

        $id             = (int)($_POST['sale_id'] ?? 0);
        $customerName   = sanitize($_POST['customer_name']  ?? '');
        $customerPhone  = sanitize($_POST['customer_phone'] ?? '');
        $paymentMethod  = sanitize($_POST['payment_method'] ?? '');
        $notes          = sanitize($_POST['notes']          ?? '');

        if (!$id)           { jsonSalesError('Sale ID required'); }
        if (!$customerName) { jsonSalesError('Customer name required'); }

        $allowedMethods = ['cash', 'card', 'online', 'credit'];
        if ($paymentMethod && !in_array($paymentMethod, $allowedMethods, true)) {
            jsonSalesError('Invalid payment method');
        }

        $sale = $db->getOne("SELECT * FROM sales WHERE id = :id AND status != 'voided'", [':id' => $id]);
        if (!$sale) { jsonSalesError('Sale not found or voided', 404); }

        $fields = [':customer_name' => $customerName, ':id' => $id];
        $setClauses = ['customer_name = :customer_name'];

        if ($customerPhone !== '') {
            $setClauses[]            = 'customer_phone = :customer_phone';
            $fields[':customer_phone'] = $customerPhone;
        }
        if ($paymentMethod) {
            $setClauses[]            = 'payment_method = :payment_method';
            $fields[':payment_method'] = $paymentMethod;
        }
        if ($notes !== '') {
            $setClauses[]   = 'notes = :notes';
            $fields[':notes'] = $notes;
        }

        $db->run(
            "UPDATE sales SET " . implode(', ', $setClauses) . " WHERE id = :id",
            $fields
        );

        $updated = getSaleById($id);
        echo json_encode(['success' => true, 'message' => 'Sale updated successfully', 'data' => $updated]);
        break;

    // ── Get returnable items for a sale ────────────────────────────────────
    case 'get_return_items':
        $saleIdOrInvoice = sanitize($_GET['sale_id'] ?? $_GET['invoice_no'] ?? '');
        if (!$saleIdOrInvoice) { jsonSalesError('Sale ID or invoice number required'); }

        $isId = is_numeric($saleIdOrInvoice);
        $sale = getSaleForReturn($saleIdOrInvoice, $isId);
        if (!$sale) { jsonSalesError('Sale not found', 404); }

        $items = getReturnableItems($sale['id'], 'sale');

        echo json_encode([
            'success' => true,
            'sale'    => [
                'id'            => $sale['id'],
                'invoice_no'    => $sale['invoice_no'],
                'customer_name' => $sale['customer_name'],
                'total'         => $sale['total'],
                'sale_date'     => $sale['sale_date'],
            ],
            'items'   => $items,
        ]);
        break;

    // ── Process a sale return ──────────────────────────────────────────────
    case 'process_return':
        requirePharmacist();
        requireSalesMethodPost();
        verifySalesCSRF();

        $saleId = (int)($_POST['sale_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $items  = json_decode($_POST['items'] ?? '[]', true);

        if (!$saleId)          { jsonSalesError('Sale ID required'); }
        if (!$reason)          { jsonSalesError('Return reason required'); }
        if (empty($items))     { jsonSalesError('No items selected for return'); }

        // Validate items
        foreach ($items as &$it) {
            $it['sale_item_id'] = (int)($it['sale_item_id'] ?? 0);
            $it['quantity']     = (int)($it['quantity']     ?? 0);
            if ($it['quantity'] <= 0) { jsonSalesError('Invalid return quantity'); }
        }
        unset($it);

        $sale = $db->getOne("SELECT * FROM sales WHERE id = :id AND status != 'voided'", [':id' => $saleId]);
        if (!$sale) { jsonSalesError('Sale not found or voided', 404); }

        // Use return functions
        $result = processSaleReturn($saleId, $items, $reason);

        if ($result) {
            // Sync cashbook & stock
            try { syncAfterSaleReturn($saleId, $items, $reason); } catch (\Throwable $e) { /* swallow */ }

            echo json_encode([
                'success' => true,
                'message' => 'Return processed successfully',
                'sale_id' => $saleId,
            ]);
        } else {
            jsonSalesError('Failed to process return. Please try again.');
        }
        break;

    // ── Invoice HTML (for print/preview) ──────────────────────────────────
    case 'get_invoice_html':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) { jsonSalesError('Sale ID required'); }

        $sale = getSaleById($id);
        if (!$sale) { jsonSalesError('Sale not found or voided', 404); }

        ob_start();
        // Use the existing print_invoice.php logic by including it in buffer mode
        $_GET['id']    = $id;
        $_GET['print'] = 0;
        include __DIR__ . '/print_invoice.php';
        $html = ob_get_clean();

        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;

    // ── Product search for POS ─────────────────────────────────────────────
    case 'search_products':
        $q = sanitize($_GET['q'] ?? '');
        if (strlen($q) < 1) {
            echo json_encode(['success' => true, 'data' => []]);
            break;
        }
        $products = searchProductsForPOS($q);
        echo json_encode([
            'success' => true,
            'data'    => array_map(fn($p) => [
                'id'            => $p['id'],
                'product_code'  => $p['product_code'],
                'product_name'  => $p['product_name'],
                'generic_name'  => $p['generic_name']  ?? '',
                'brand_name'    => $p['brand_name']    ?? '',
                'strength'      => $p['strength']      ?? '',
                'dosage_form'   => $p['dosage_form']   ?? '',
                'unit_type'     => $p['unit_type']     ?? '',
                'units_per_pack'=> $p['units_per_pack'] ?? 1,
                'total_stock'   => $p['total_stock']   ?? 0,
                'price'         => $p['price']         ?? 0,
                'text'          => $p['product_name'] . ($p['strength'] ? ' ' . $p['strength'] : '') . ' (Stock: ' . $p['total_stock'] . ')',
            ], $products),
        ]);
        break;

    // ── Customer search / autocomplete ─────────────────────────────────────
    case 'get_customers':
        $q = sanitize($_GET['q'] ?? '');
        $customers = $db->getAll(
            "SELECT DISTINCT customer_name, customer_phone
             FROM sales
             WHERE status != 'voided'
               AND customer_name != 'Walk-in Customer'
               AND (customer_name LIKE :q OR customer_phone LIKE :q)
             ORDER BY customer_name ASC
             LIMIT 20",
            [':q' => "%$q%"]
        );
        echo json_encode([
            'success' => true,
            'data'    => array_map(fn($c) => [
                'name'  => $c['customer_name'],
                'phone' => $c['customer_phone'],
                'text'  => $c['customer_name'] . ($c['customer_phone'] ? ' (' . $c['customer_phone'] . ')' : ''),
            ], $customers),
        ]);
        break;

    // ── Summary stat cards ─────────────────────────────────────────────────
    case 'get_summary':
        $fromDate = sanitize($_GET['from_date'] ?? date('Y-m-01'));
        $toDate   = sanitize($_GET['to_date']   ?? date('Y-m-d'));
        $search   = sanitize($_GET['search']    ?? '');

        $where  = "s.status != 'voided'";
        $params = [];

        if ($search) {
            $where .= " AND (s.invoice_no LIKE :search OR s.customer_name LIKE :search OR s.customer_phone LIKE :search)";
            $params[':search'] = "%$search%";
        }
        if ($fromDate) {
            $where .= " AND DATE(s.sale_date) >= :from";
            $params[':from'] = $fromDate;
        }
        if ($toDate) {
            $where .= " AND DATE(s.sale_date) <= :to";
            $params[':to'] = $toDate;
        }

        $summary = $db->getOne(
            "SELECT
                COUNT(*) AS total_invoices,
                COALESCE(SUM(s.total), 0) AS gross_total,
                COALESCE(SUM(COALESCE((SELECT SUM(r.total_amount) FROM sale_returns r WHERE r.sale_id = s.id), 0)), 0) AS total_returns,
                COALESCE(SUM(s.total - COALESCE((SELECT SUM(r.total_amount) FROM sale_returns r WHERE r.sale_id = s.id), 0)), 0) AS net_total,
                COALESCE(SUM(CASE WHEN s.payment_method = 'cash' THEN s.total ELSE 0 END), 0) AS cash_total,
                COALESCE(SUM(CASE WHEN s.payment_method = 'card' THEN s.total ELSE 0 END), 0) AS card_total,
                COALESCE(SUM(CASE WHEN s.payment_method = 'online' THEN s.total ELSE 0 END), 0) AS online_total,
                COALESCE(SUM(CASE WHEN s.is_credit = 1 THEN s.total ELSE 0 END), 0) AS credit_total,
                COUNT(CASE WHEN s.is_credit = 1 THEN 1 END) AS credit_count
             FROM sales s
             WHERE $where",
            $params
        );

        echo json_encode(['success' => true, 'data' => $summary]);
        break;

    // ── Check sale status (for polling) ───────────────────────────────────
    case 'get_sale_status':
        $id   = (int)($_GET['id'] ?? 0);
        if (!$id) { jsonSalesError('Sale ID required'); }
        $row = $db->getOne("SELECT id, status, invoice_no FROM sales WHERE id = :id", [':id' => $id]);
        if (!$row) { jsonSalesError('Sale not found', 404); }
        echo json_encode(['success' => true, 'data' => $row]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown action: ' . htmlspecialchars($action)]);
}

// ── Helpers ────────────────────────────────────────────────────────────────
function requireSalesMethodPost(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'POST required']);
        exit;
    }
}

function verifySalesCSRF(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh and try again.']);
        exit;
    }
}

function requireSalesAdmin(): void {
    if (empty($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'pharmacist'], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin access required.']);
        exit;
    }
}

function jsonSalesError(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}