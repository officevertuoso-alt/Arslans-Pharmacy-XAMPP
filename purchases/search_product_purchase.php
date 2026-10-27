<?php
/**
 * AJAX: Search products for Add Purchase page
 * Returns HTML rows for dropdown
 */

require_once '../config/config.php';
require_once '../includes/product_functions.php';

// FIX S-07: Require authentication on this AJAX endpoint
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: text/html; charset=utf-8');

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
if (strlen($search) < 2) {
    echo '<div class="p-3 text-center text-muted small">Type at least 2 characters...</div>';
    exit;
}

try {
    $pdo = _getPDO();
} catch (Exception $e) {
    echo '<div class="p-3 text-danger small">Database connection error.</div>';
    exit;
}

$like = '%' . $search . '%';

// Get soft-delete column info
$deletedCond = '';
try {
    $cols = $pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('deleted_at', $cols)) $deletedCond .= " AND p.deleted_at IS NULL";
    if (in_array('is_deleted', $cols)) $deletedCond .= " AND p.is_deleted = 0";
    if (in_array('trash', $cols)) $deletedCond .= " AND p.trash = 0";
} catch (Exception $e) {}

$sql = "
    SELECT 
        p.id,
        p.product_name,
        p.product_code,
        p.brand_name,
        p.generic_name,
        p.category_id,
        p.strength,
        p.dosage_form,
        p.size_value,
        p.unit_type,
        p.units_per_pack,
        p.purchase_price AS default_purchase_price,
        p.sale_price AS default_sale_price,
        COALESCE(
            (SELECT b.selling_price 
             FROM batches b 
             WHERE b.product_id = p.id 
               AND b.remaining_quantity > 0 
               AND (b.expiry_date IS NULL OR b.expiry_date >= CURDATE())
               AND COALESCE(b.is_expired, 0) = 0
             ORDER BY b.expiry_date ASC, b.id ASC 
             LIMIT 1),
            p.sale_price,
            0
        ) AS fifo_sale_price,
        COALESCE(
            (SELECT b.purchase_price 
             FROM batches b 
             WHERE b.product_id = p.id 
               AND b.remaining_quantity > 0 
               AND (b.expiry_date IS NULL OR b.expiry_date >= CURDATE())
               AND COALESCE(b.is_expired, 0) = 0
             ORDER BY b.expiry_date ASC, b.id ASC 
             LIMIT 1),
            p.purchase_price,
            0
        ) AS fifo_purchase_price,
        COALESCE(
            (SELECT b.units_per_pack 
             FROM batches b 
             WHERE b.product_id = p.id 
               AND b.remaining_quantity > 0 
               AND (b.expiry_date IS NULL OR b.expiry_date >= CURDATE())
               AND COALESCE(b.is_expired, 0) = 0
             ORDER BY b.expiry_date ASC, b.id ASC 
             LIMIT 1),
            p.units_per_pack,
            1
        ) AS fifo_units_per_pack,
        COALESCE(
            (SELECT SUM(b.remaining_quantity)
             FROM batches b 
             WHERE b.product_id = p.id 
               AND b.remaining_quantity > 0 
               AND (b.expiry_date IS NULL OR b.expiry_date >= CURDATE())
               AND COALESCE(b.is_expired, 0) = 0),
            0
        ) AS total_stock
    FROM products p
    WHERE p.status = 1
      {$deletedCond}
      AND (p.product_name LIKE :s1 
           OR p.brand_name LIKE :s2 
           OR p.generic_name LIKE :s3 
           OR p.product_code LIKE :s4)
    ORDER BY 
        CASE WHEN p.product_name LIKE :exact THEN 0 ELSE 1 END,
        p.product_name ASC
    LIMIT 20
";

$stmt = $pdo->prepare($sql);
$stmt->bindValue(':s1', $like, PDO::PARAM_STR);
$stmt->bindValue(':s2', $like, PDO::PARAM_STR);
$stmt->bindValue(':s3', $like, PDO::PARAM_STR);
$stmt->bindValue(':s4', $like, PDO::PARAM_STR);
$stmt->bindValue(':exact', $search, PDO::PARAM_STR);
$stmt->execute();
$results = $stmt->fetchAll();

if (empty($results)) {
    echo '<div class="p-3 text-center text-muted small">No products found for "' . htmlspecialchars($search) . '"</div>';
    exit;
}

// Helper function to format stock
function formatStockForSearch($totalPieces, $unitsPerPack, $unitType) {
    if ($totalPieces <= 0) return '<span class="text-danger">Out of Stock</span>';
    
    $packLabel = ($unitType === 'Strip') ? 'Strip' : (($unitType === 'Bottle') ? 'Bottle' : 'Pack');
    
    if ($unitsPerPack == 1) {
        return "<span class='text-success'>{$totalPieces} {$packLabel}</span>";
    }
    
    $packs = intdiv($totalPieces, $unitsPerPack);
    $pieces = $totalPieces % $unitsPerPack;
    $parts = [];
    if ($packs > 0) $parts[] = $packs . ' ' . ($packs == 1 ? $packLabel : $packLabel . 's');
    if ($pieces > 0) $parts[] = $pieces . ' ' . ($pieces == 1 ? rtrim($unitType, 's') : $unitType);
    
    $result = implode(' + ', $parts);
    $result .= " <span class='text-muted'>(" . $totalPieces . " " . $unitType . ")</span>";
    return "<span class='text-success'>{$result}</span>";
}

// Helper function for units per pack display
if (!function_exists('formatUnitsPerPackDisplay')) {
    function formatUnitsPerPackDisplay($units, $unitType, $dosageForm = '') {
        $units = max(1, $units);
        $ut = strtolower(trim($unitType));
        
        if ($units === 1 && ($ut === 'bottle' || $ut === 'tube' || $ut === 'vial')) {
            return "1 " . ucfirst($unitType);
        }
        return $units . " " . ucfirst($unitType) . ($units > 1 ? 's' : '') . "/Pack";
    }
}

// Render each result
foreach ($results as $p) {
    $isMedicine = ((int)($p['category_id'] ?? 0)) === 1;
    $totalStock = (int)($p['total_stock'] ?? 0);
    $salePrice = number_format((float)($p['fifo_sale_price'] ?? $p['default_sale_price'] ?? 0), 2);
    $purchasePrice = number_format((float)($p['fifo_purchase_price'] ?? $p['default_purchase_price'] ?? 0), 2);
    $unitsPerPack = (int)($p['fifo_units_per_pack'] ?? $p['units_per_pack'] ?? 1);
    $unitType = htmlspecialchars($p['unit_type'] ?? 'Pcs');
    $dosageForm = htmlspecialchars($p['dosage_form'] ?? '');
    
    // Stock display
    if ($totalStock <= 0) {
        $stockHtml = '<span class="text-danger fw-bold">Out of Stock</span>';
    } else {
        $stockHtml = formatStockForSearch($totalStock, $unitsPerPack, $unitType);
    }
    
    // Units/Pack display
    $unitsPerPackDisplay = formatUnitsPerPackDisplay($unitsPerPack, $unitType, $dosageForm);
    
    // Prepare product data as JSON for onclick
    $productData = json_encode([
        'id' => (int)$p['id'],
        'product_name' => $p['product_name'],
        'product_code' => $p['product_code'],
        'brand_name' => $p['brand_name'],
        'generic_name' => $p['generic_name'],
        'category_id' => (int)$p['category_id'],
        'strength' => $p['strength'],
        'dosage_form' => $p['dosage_form'],
        'size_value' => $p['size_value'],
        'unit_type' => $p['unit_type'],
        'units_per_pack' => $unitsPerPack,
        'total_stock' => $totalStock,
        'fifo_units_per_pack' => $unitsPerPack,
        'fifo_purchase_price' => (float)($p['fifo_purchase_price'] ?? $p['default_purchase_price'] ?? 0),
        'fifo_sale_price' => (float)($p['fifo_sale_price'] ?? $p['default_sale_price'] ?? 0),
        'default_purchase_price' => (float)($p['default_purchase_price'] ?? 0),
        'default_sale_price' => (float)($p['default_sale_price'] ?? 0),
    ]);
    
    ?>
<div class="search-result-card p-3 border-bottom" 
     style="cursor:pointer; transition:background 0.2s;" 
     onclick="window.parent.addToCartFromObject(<?php echo htmlspecialchars($productData, ENT_QUOTES, 'UTF-8'); ?>)" 
     tabindex="0"
     onkeydown="if(event.key==='Enter') window.parent.addToCartFromObject(<?php echo htmlspecialchars($productData, ENT_QUOTES, 'UTF-8'); ?>)">
    
    <div class="d-flex justify-content-between align-items-start mb-2">
        <div class="flex-grow-1">
            <div class="d-flex align-items-center flex-wrap gap-2 mb-1">
                <strong class="fs-6"><?php echo htmlspecialchars($p['product_name']); ?></strong>
                <?php if ($isMedicine): ?>
                    <span class="badge bg-info">Medicine</span>
                <?php else: ?>
                    <span class="badge bg-secondary">Non-Medicine</span>
                <?php endif; ?>
                <?php if (!empty($p['product_code'])): ?>
                    <code class="small text-muted"><?php echo htmlspecialchars($p['product_code']); ?></code>
                <?php endif; ?>
            </div>
            <?php if ($isMedicine && !empty($p['generic_name'])): ?>
                <div class="small text-muted">Generic: <?php echo htmlspecialchars($p['generic_name']); ?></div>
            <?php endif; ?>
            <?php if (!empty($p['brand_name'])): ?>
                <div class="small text-muted">Brand: <?php echo htmlspecialchars($p['brand_name']); ?></div>
            <?php endif; ?>
        </div>
        <div class="text-end ms-3">
            <button class="btn btn-sm btn-primary rounded-pill px-3" onclick="event.stopPropagation(); window.parent.addToCartFromObject(<?php echo htmlspecialchars($productData, ENT_QUOTES, 'UTF-8'); ?>);">
                <i class="fas fa-plus-circle me-1"></i> Add
            </button>
        </div>
    </div>
    
    <div class="row g-2 small">
        <div class="col-md-6">
            <div class="d-flex justify-content-between border-bottom pb-1 mb-1">
                <span class="text-muted">Purchase Price:</span>
                <strong class="text-danger">Rs <?php echo $purchasePrice; ?></strong>
                <span class="text-muted">/ <?php echo $unitType; ?></span>
            </div>
            <div class="d-flex justify-content-between border-bottom pb-1 mb-1">
                <span class="text-muted">Sale Price:</span>
                <strong class="text-success">Rs <?php echo $salePrice; ?></strong>
                <span class="text-muted">/ <?php echo $unitType; ?></span>
            </div>
            <div class="d-flex justify-content-between">
                <span class="text-muted">Units/Pack:</span>
                <strong><?php echo $unitsPerPackDisplay; ?></strong>
            </div>
        </div>
        <div class="col-md-6">
            <div class="d-flex justify-content-between border-bottom pb-1 mb-1">
                <span class="text-muted">Current Stock:</span>
                <?php echo $stockHtml; ?>
            </div>
        </div>
    </div>
    
    <?php if ($isMedicine && (!empty($p['strength']) || !empty($p['dosage_form']))): ?>
    <div class="mt-2 pt-1 border-top">
        <small class="text-muted">
            <i class="fas fa-prescription-bottle me-1"></i>
            <?php 
            $medDetails = [];
            if (!empty($p['dosage_form'])) $medDetails[] = htmlspecialchars($p['dosage_form']);
            if (!empty($p['strength'])) $medDetails[] = htmlspecialchars($p['strength']);
            echo implode(' • ', $medDetails);
            ?>
        </small>
    </div>
    <?php elseif (!$isMedicine && !empty($p['size_value'])): ?>
    <div class="mt-2 pt-1 border-top">
        <small class="text-muted">
            <i class="fas fa-ruler me-1"></i>
            Size: <?php echo htmlspecialchars($p['size_value']); ?>
        </small>
    </div>
    <?php endif; ?>
    
</div>
    <?php
}
?>

<style>
.search-result-card {
    transition: all 0.2s ease;
}
.search-result-card:hover {
    background-color: #f8f9fa !important;
}
</style>
<?php
exit;
?>