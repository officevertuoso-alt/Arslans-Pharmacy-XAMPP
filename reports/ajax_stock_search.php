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

$search   = isset($_GET['search']) ? trim($_GET['search']) : '';
$category = isset($_GET['category']) ? trim($_GET['category']) : '';

// Fetch products using the updated function
$products = getInStockProductsReport($search, $category);

// Calculate totals
$totalProducts   = count($products);
$totalQuantity   = 0;
$totalStockValue = 0;

foreach ($products as $p) {
    $qty = (int)$p['current_stock'];
    $price = (float)$p['purchase_price'];
    $totalQuantity   += $qty;
    $totalStockValue += $qty * $price;
}

// Generate table HTML
ob_start();
if (empty($products)) {
    echo '<tr><td colspan="6" class="text-center py-5 text-muted">No products found</td></tr>';
} else {
    $serial = 1;
    foreach ($products as $p) {
        $currentStock = (int)$p['current_stock'];
        $purchasePrice = (float)$p['purchase_price'];
        $stockValue = $currentStock * $purchasePrice;
        ?>
        <tr>
            <td class="serial-no"><?= $serial++ ?>.</td>
            <td class="product-name-cell">
                <strong><?= htmlspecialchars($p['product_name']) ?></strong>
                <?php if (!empty($p['brand_name'])): ?>
                    <div class="brand-name"><i class="fas fa-tag fa-xs me-1"></i><?= htmlspecialchars($p['brand_name']) ?></div>
                <?php endif; ?>
                <?php if ($p['category_id'] == 1 && !empty($p['generic_name'])): ?>
                    <div class="generic-name"><i class="fas fa-pills fa-xs me-1"></i><?= htmlspecialchars($p['generic_name']) ?></div>
                <?php endif; ?>
            </td>
            <td>
                <?php 
                if ((int)$p['category_id'] === 1) {
                    $strength = trim(($p['strength'] ?? '') . ' ' . ($p['strength_unit'] ?? ''));
                    echo !empty($strength) ? '<strong>' . htmlspecialchars($strength) . '</strong>' : '-';
                    if (!empty($p['dosage_form'])) {
                        echo '<div class="strength-form"><i class="fas fa-capsules fa-xs me-1"></i>' . htmlspecialchars($p['dosage_form']) . '</div>';
                    }
                } else {
                    $size = trim(($p['strength'] ?? '') . ' ' . ($p['strength_unit'] ?? ''));
                    echo !empty($size) ? '<strong>' . htmlspecialchars($size) . '</strong>' : '-';
                    if (!empty($p['size_value'])) {
                        echo '<div class="strength-form"><i class="fas fa-arrows-alt fa-xs me-1"></i>' . htmlspecialchars($p['size_value']) . '</div>';
                    }
                }
                ?>
            </td>
            <td class="text-end fw-bold"><?= number_format($currentStock) ?></td>
            <td class="text-end price-column">Rs <?= number_format($purchasePrice, 2) ?></td>
            <td class="text-end price-column">Rs <?= number_format($stockValue, 2) ?></td>
        </tr>
        <?php
    }
    // Grand total row
    ?>
    <tr class="table-dark fw-bold">
        <td colspan="3" class="text-end fs-5">TOTAL</td>
        <td class="text-end fs-5"><?= number_format($totalQuantity) ?></td>
        <td class="text-end fs-5"></td>
        <td class="text-end fs-5 text-warning">Rs <?= number_format($totalStockValue, 2) ?></td>
    </tr>
    <?php
}
$tableHtml = ob_get_clean();

// Return JSON
echo json_encode([
    'success'         => true,
    'totalProducts'   => $totalProducts,
    'totalQuantity'   => $totalQuantity,
    'totalStockValue' => round($totalStockValue, 2),
    'tableHtml'       => $tableHtml
]);
?>