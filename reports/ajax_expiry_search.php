<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
require_once '../config/config.php';
require_once '../includes/report_functions.php';
requireLogin();
// ISS-036 FIX: Check reports view permission — cashiers should not access inventory/expiry data
if (function_exists('canView') && !canView('reports')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied.']);
    exit;
}
header('Content-Type: application/json');

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter = isset($_GET['filter']) ? trim($_GET['filter']) : 'all';

$db = db();

/*
 * FILTER LOGIC:
 *
 * "current" → Batches jo abhi bhi expired hain AND remaining_quantity > 0
 *             (Yani jo products list ke "Expired" tab mein show ho rahi hain)
 *
 * "all"     → Saari kabhi bhi expire huin batches ka record
 *             (remaining_quantity chahe 0 ho ya nahi — poora history)
 *
 * Days Expired: DATEDIFF(CURDATE(), b.expiry_date) — kitny din pehle expire hui
 */

$sql = "SELECT 
            b.id,
            b.batch_number,
            b.remaining_quantity,
            b.expiry_date,
            b.purchase_price,
            p.product_name,
            p.generic_name,
            p.brand_name,
            p.category_id,
            p.strength,
            p.strength_unit,
            p.dosage_form,
            p.size_value,
            s.supplier_name,
            DATEDIFF(CURDATE(), b.expiry_date) AS days_expired
        FROM batches b
        JOIN products p ON b.product_id = p.id
        LEFT JOIN suppliers s ON b.supplier_id = s.id
        WHERE b.expiry_date IS NOT NULL
          AND b.expiry_date <= CURDATE()";

// Filter logic
if ($filter === 'current') {
    // Sirf wo batches jo abhi bhi expired tab mein show ho rahi hain (remaining_quantity > 0)
    $sql .= " AND b.remaining_quantity > 0";
} else {
    // All — saara record, chahe sold out ho gaya ho
    // (No remaining_quantity condition — poora history)
}

// Search by product_name OR batch_number
$params = [];
if (!empty($search)) {
    $sql .= " AND (p.product_name LIKE :search OR b.batch_number LIKE :search)";
    $params[':search'] = "%$search%";
}

$sql .= " ORDER BY b.expiry_date ASC";

try {
    $db->query($sql);
    foreach ($params as $key => $val) {
        $db->bind($key, $val);
    }
    $products = $db->resultSet();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}

// Totals
$totalProducts   = count($products);
$totalQuantity   = 0;
$totalStockValue = 0;
foreach ($products as $p) {
    $totalQuantity   += (int)$p['remaining_quantity'];
    $totalStockValue += (int)$p['remaining_quantity'] * (float)$p['purchase_price'];
}

// Build table HTML
$html = '';
if (empty($products)) {
    $html = '<tr><td colspan="9" class="text-center py-5 text-muted">'
          . '<i class="fas fa-check-circle me-2 text-success"></i>No expired products found'
          . '</td></tr>';
} else {
    $serial = 1;
    foreach ($products as $p) {
        $qty              = (int)$p['remaining_quantity'];
        $price            = (float)$p['purchase_price'];
        $value            = $qty * $price;
        $days             = (int)$p['days_expired'];
        // Safe expiry date formatting
        $expiry_ts = !empty($p['expiry_date']) ? strtotime($p['expiry_date']) : false;
        $expiry_formatted = ($expiry_ts && $expiry_ts > 0)
            ? date('d-m-Y', $expiry_ts)
            : '<span class="text-muted fst-italic">N/A</span>';

        // Strength / Size HTML
        $strength_html = '';
        if ((int)$p['category_id'] === 1) {
            $str = trim(($p['strength'] ?? '') . ' ' . ($p['strength_unit'] ?? ''));
            $strength_html = !empty($str)
                ? '<strong>' . htmlspecialchars($str) . '</strong>'
                : '-';
            if (!empty($p['dosage_form'])) {
                $strength_html .= '<div class="strength-form">'
                    . '<i class="fas fa-capsules fa-xs me-1"></i>'
                    . htmlspecialchars($p['dosage_form'])
                    . '</div>';
            }
        } else {
            $str = trim(($p['strength'] ?? '') . ' ' . ($p['strength_unit'] ?? ''));
            $strength_html = !empty($str)
                ? '<strong>' . htmlspecialchars($str) . '</strong>'
                : '-';
            if (!empty($p['size_value'])) {
                $strength_html .= '<div class="strength-form">'
                    . '<i class="fas fa-arrows-alt fa-xs me-1"></i>'
                    . htmlspecialchars($p['size_value'])
                    . '</div>';
            }
        }

        // Days label — kitny din pehle expire hui
        if (!$expiry_ts || $expiry_ts <= 0) {
            $days_label = '<span class="text-muted fst-italic">N/A</span>';
        } elseif ($days === 0) {
            $days_label = '<span class="badge bg-danger">Today</span>';
        } elseif ($days === 1) {
            $days_label = '1 day ago';
        } else {
            $days_label = $days . ' days ago';
        }

        // Row badge for zero-remaining-quantity rows (in "all" view)
        $qty_display = ($qty === 0)
            ? '<span class="badge bg-secondary">0 (Cleared)</span>'
            : number_format($qty);

        $html .= '<tr class="expired-row">';
        $html .= '<td class="serial-no">' . $serial++ . '.</td>';
        $html .= '<td><strong>' . htmlspecialchars($p['batch_number']) . '</strong>';
        if (!empty($p['supplier_name'])) {
            $html .= '<br><small class="text-muted">' . htmlspecialchars($p['supplier_name']) . '</small>';
        }
        $html .= '</td>';
        $html .= '<td class="product-name-cell"><strong>' . htmlspecialchars($p['product_name']) . '</strong>';
        if (!empty($p['brand_name'])) {
            $html .= '<div class="brand-name"><i class="fas fa-tag fa-xs me-1"></i>'
                   . htmlspecialchars($p['brand_name']) . '</div>';
        }
        if ((int)$p['category_id'] === 1 && !empty($p['generic_name'])) {
            $html .= '<div class="generic-name"><i class="fas fa-pills fa-xs me-1"></i>'
                   . htmlspecialchars($p['generic_name']) . '</div>';
        }
        $html .= '</td>';
        $html .= '<td>' . $strength_html . '</td>';
        $html .= '<td class="text-end fw-bold">' . $qty_display . '</td>';
        $html .= '<td class="text-end price-column">Rs ' . number_format($price, 2) . '</td>';
        $html .= '<td class="text-end price-column">Rs ' . number_format($value, 2) . '</td>';
        $html .= '<td class="text-danger fw-semibold">' . $expiry_formatted . '</td>';
        $html .= '<td class="text-end fw-bold text-danger">' . $days_label . '</td>';
        $html .= '</tr>';
    }

    // Grand total row
    $html .= '<tr class="table-dark fw-bold">';
    $html .= '<td colspan="4" class="text-end fs-5">TOTAL</td>';
    $html .= '<td class="text-end fs-5">' . number_format($totalQuantity) . '</td>';
    $html .= '<td class="text-end fs-5"></td>';
    $html .= '<td class="text-end fs-5 text-danger">Rs ' . number_format($totalStockValue, 2) . '</td>';
    $html .= '<td colspan="2"></td>';
    $html .= '</tr>';
}

echo json_encode([
    'success'         => true,
    'totalProducts'   => $totalProducts,
    'totalQuantity'   => $totalQuantity,
    'totalStockValue' => round($totalStockValue, 2),
    'tableHtml'       => $html,
]);