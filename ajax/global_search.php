<?php
/**
 * ajax/global_search.php — Global Topbar Search Endpoint
 * ═══════════════════════════════════════════════════════
 * Searches products, customers, and sales by query string.
 * Returns JSON: { products: [], customers: [], sales: [] }
 *
 * Phase 3 — Global Header
 */

require_once '../config/config.php';
require_once '../includes/auth.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) {
    echo json_encode(['products' => [], 'customers' => [], 'sales' => []]);
    exit;
}

$result = ['products' => [], 'customers' => [], 'sales' => []];

try {
    $pdo  = db()->getConnection();
    $like = '%' . $q . '%';

    // ── Products ──────────────────────────────────────────────────
    $stmtP = $pdo->prepare(
        "SELECT p.id, p.name, p.generic_name,
                COALESCE(c.name, '') AS category,
                COALESCE(SUM(b.remaining_quantity), 0) AS stock
         FROM   products p
         LEFT   JOIN categories c ON c.id = p.category_id
         LEFT   JOIN batches b ON b.product_id = p.id
            AND b.expiry_date >= CURDATE() AND b.is_blocked = 0
         WHERE  p.status = 1
           AND  (p.name LIKE :q OR p.generic_name LIKE :q OR p.barcode LIKE :q)
         GROUP  BY p.id
         ORDER  BY p.name
         LIMIT  6"
    );
    $stmtP->execute([':q' => $like]);
    $result['products'] = $stmtP->fetchAll(PDO::FETCH_ASSOC);

    // ── Customers ─────────────────────────────────────────────────
    $stmtC = $pdo->prepare(
        "SELECT id, name, phone, email
         FROM   customers
         WHERE  name LIKE :q OR phone LIKE :q OR email LIKE :q
         ORDER  BY name
         LIMIT  5"
    );
    $stmtC->execute([':q' => $like]);
    $result['customers'] = $stmtC->fetchAll(PDO::FETCH_ASSOC);

    // ── Sales ─────────────────────────────────────────────────────
    $stmtS = $pdo->prepare(
        "SELECT s.id, s.invoice_no,
                COALESCE(c.name, 'Walk-in') AS customer_name,
                s.grand_total AS total,
                s.sale_date
         FROM   sales s
         LEFT   JOIN customers c ON c.id = s.customer_id
         WHERE  s.invoice_no LIKE :q OR c.name LIKE :q
         ORDER  BY s.sale_date DESC
         LIMIT  5"
    );
    $stmtS->execute([':q' => $like]);
    $result['sales'] = $stmtS->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Search failed', 'products' => [], 'customers' => [], 'sales' => []]);
    exit;
}

echo json_encode($result);
