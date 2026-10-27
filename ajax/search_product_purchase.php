<?php
/**
 * AJAX: Search products for Add Purchase page
 * Returns HTML rows for dropdown — full detail cards
 *
 * COMPLETE UPDATE v3 - FIXED:
 *  1. Proper parent window communication
 *  2. Error handling for missing parent functions
 *  3. Consistent product data structure
 *  4. Better debugging console logs
 */

require_once '../config/config.php';
require_once '../includes/product_functions.php';
require_once '../includes/auth.php';

// ISS-016 FIX: Require authentication — these endpoints expose product/pricing data
requireLogin();


header('Content-Type: text/html; charset=utf-8');

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
if (strlen($search) < 2) {
    echo '<div class="p-3 text-center text-muted small"><i class="fas fa-search me-1"></i> Type at least 2 characters…</div>';
    exit;
}

try {
    $pdo = _getPDO();
} catch (Exception $e) {
    echo '<div class="p-3 text-danger small"><i class="fas fa-exclamation-triangle me-1"></i> Database connection error.</div>';
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

// Get active products with batch information
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
        p.pack_type,
        p.purchase_price AS default_purchase_price,
        p.sale_price AS default_sale_price,
        p.image_path,
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
        ) AS total_stock,
        (SELECT COUNT(*) 
         FROM batches b 
         WHERE b.product_id = p.id 
           AND b.remaining_quantity > 0 
           AND (b.expiry_date IS NULL OR b.expiry_date >= CURDATE())
           AND COALESCE(b.is_expired, 0) = 0
        ) AS active_batch_count,
        (SELECT b.expiry_date 
         FROM batches b 
         WHERE b.product_id = p.id 
           AND b.remaining_quantity > 0 
           AND b.expiry_date IS NOT NULL
           AND COALESCE(b.is_expired, 0) = 0
         ORDER BY b.expiry_date ASC 
         LIMIT 1
        ) AS nearest_expiry,
        (SELECT MIN(b.reorder_level) 
         FROM batches b 
         WHERE b.product_id = p.id 
           AND b.remaining_quantity > 0 
           AND (b.expiry_date IS NULL OR b.expiry_date >= CURDATE())
           AND COALESCE(b.is_expired, 0) = 0
        ) AS min_reorder_level
    FROM products p
    WHERE p.status = 1
      {$deletedCond}
      AND (p.product_name LIKE :s1 
           OR p.brand_name LIKE :s2 
           OR p.generic_name LIKE :s3 
           OR p.product_code LIKE :s4
           OR p.barcode LIKE :s5)
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
$stmt->bindValue(':s5', $like, PDO::PARAM_STR);
$stmt->bindValue(':exact', $search, PDO::PARAM_STR);
$stmt->execute();
$results = $stmt->fetchAll();

if (empty($results)) {
    echo '<div class="p-3 text-center text-muted small"><i class="fas fa-search me-1"></i> No products found for "<strong>' 
         . htmlspecialchars($search) . '</strong>"</div>';
    exit;
}

// Helper function to format stock in packs+pieces
function formatStockForSearch($totalPieces, $unitsPerPack, $unitType, $dosageForm) {
    if ($totalPieces <= 0) return '<span class="text-danger">Out of Stock</span>';
    
    if ($dosageForm === 'Tablet' && $unitType === 'Strip') $pieceLabel = 'Tablet';
    elseif ($dosageForm === 'Syrup' && $unitType === 'Bottle') $pieceLabel = 'Syrup';
    elseif ($unitType === 'Pcs') $pieceLabel = 'Pc';
    elseif ($unitType === 'Strip') $pieceLabel = 'Tablet';
    elseif ($unitType === 'Bottle') $pieceLabel = 'Bottle';
    else $pieceLabel = rtrim($unitType, 's');
    
    $packLabel = ($unitType === 'Strip') ? 'Strip' : (($unitType === 'Bottle') ? 'Bottle' : 'Pack');
    
    if ($unitsPerPack == 1) {
        $label = ($totalPieces == 1) ? $pieceLabel : $pieceLabel . 's';
        return "<span class='text-success'>{$totalPieces} {$label}</span>";
    }
    
    $packs = intdiv($totalPieces, $unitsPerPack);
    $pieces = $totalPieces % $unitsPerPack;
    $parts = [];
    if ($packs > 0) $parts[] = $packs . ' ' . ($packs == 1 ? $packLabel : $packLabel . 's');
    if ($pieces > 0) $parts[] = $pieces . ' ' . ($pieces == 1 ? $pieceLabel : $pieceLabel . 's');
    
    $result = implode(' + ', $parts);
    $result .= " <span class='text-muted'>(" . $totalPieces . " " . $pieceLabel . "s)</span>";
    return "<span class='text-success'>{$result}</span>";
}

// Helper function to format units per pack display
if (!function_exists('formatUnitsPerPackDisplay')) {
    function formatUnitsPerPackDisplay($units, $unitType, $dosageForm = '', $packType = '') {
        $units = max(1, $units);
        $df = strtolower(trim($dosageForm));
        $ut = strtolower(trim($unitType));
        
        if ($units === 1 && ($ut === 'bottle' || $ut === 'tube' || $ut === 'vial' || $ut === 'ampoule')) {
            return "1 " . ucfirst($unitType);
        }
        if ($ut === 'strip' && ($df === 'tablet' || $df === 'tablets')) {
            return $units . " Tablets/Strip";
        }
        if ($ut === 'strip' && ($df === 'capsule' || $df === 'capsules')) {
            return $units . " Capsules/Strip";
        }
        if ($ut === 'bottle' && $df === 'syrup') {
            return $units . " ml/Bottle";
        }
        if ($ut === 'pcs' || $ut === 'piece' || $ut === 'pieces') {
            return $units === 1 ? "1 Pc/Pack" : $units . " Pcs/Pack";
        }
        return $units . " " . ucfirst($unitType) . ($units > 1 ? 's' : '') . "/Pack";
    }
}

// Render each result card
foreach ($results as $p) {
    $isMedicine = ((int)($p['category_id'] ?? 0)) === 1;
    $totalStock = (int)($p['total_stock'] ?? 0);
    $activeBatches = (int)($p['active_batch_count'] ?? 0);
    $salePrice = number_format((float)($p['fifo_sale_price'] ?? 0), 2);
    $purchasePrice = number_format((float)($p['fifo_purchase_price'] ?? 0), 2);
    $unitsPerPack = (int)($p['fifo_units_per_pack'] ?? 1);
    $unitType = htmlspecialchars($p['unit_type'] ?? 'Pcs');
    $dosageForm = htmlspecialchars($p['dosage_form'] ?? '');
    $minReorderLevel = (int)($p['min_reorder_level'] ?? 10);
    
    // Stock display
    if ($totalStock <= 0) {
        $stockHtml = '<span class="text-danger fw-bold"><i class="fas fa-times-circle me-1"></i>Out of Stock</span>';
    } elseif ($totalStock <= $minReorderLevel) {
        $stockHtml = '<span class="text-warning fw-bold"><i class="fas fa-exclamation-triangle me-1"></i>' . formatStockForSearch($totalStock, $unitsPerPack, $unitType, $dosageForm) . '</span>';
    } else {
        $stockHtml = '<span class="text-success fw-bold">' . formatStockForSearch($totalStock, $unitsPerPack, $unitType, $dosageForm) . '</span>';
    }
    
    // Expiry info
    $expiryHtml = '';
    if (!empty($p['nearest_expiry'])) {
        $expDays = (int)ceil((strtotime($p['nearest_expiry']) - time()) / 86400);
        $expFormatted = date('d M Y', strtotime($p['nearest_expiry']));
        if ($expDays < 0) {
            $expiryHtml = '<span class="text-danger"><i class="fas fa-skull-crossbones me-1"></i>Expired</span>';
        } elseif ($expDays <= 30) {
            $expiryHtml = '<span class="text-warning"><i class="fas fa-clock me-1"></i>Exp: ' . $expFormatted . ' (' . $expDays . 'd)</span>';
        } else {
            $expiryHtml = '<span class="text-muted"><i class="fas fa-calendar-alt me-1"></i>Exp: ' . $expFormatted . '</span>';
        }
    }
    
    // Build pills
    $pills = [];
    if ($isMedicine) {
        if (!empty($p['brand_name'])) {
            $pills[] = '<span class="badge bg-light text-dark me-1"><i class="fas fa-tag fa-xs me-1"></i>' . htmlspecialchars($p['brand_name']) . '</span>';
        }
        if (!empty($p['generic_name'])) {
            $pills[] = '<span class="badge bg-info bg-opacity-10 text-info me-1"><i class="fas fa-pills fa-xs me-1"></i>' . htmlspecialchars($p['generic_name']) . '</span>';
        }
        $specs = [];
        if (!empty($p['strength'])) $specs[] = htmlspecialchars($p['strength']);
        if (!empty($p['dosage_form'])) $specs[] = htmlspecialchars($p['dosage_form']);
        if ($specs) {
            $pills[] = '<span class="badge bg-secondary bg-opacity-10 text-secondary me-1"><i class="fas fa-flask fa-xs me-1"></i>' . implode(' · ', $specs) . '</span>';
        }
    } else {
        if (!empty($p['brand_name'])) {
            $pills[] = '<span class="badge bg-light text-dark me-1"><i class="fas fa-tag fa-xs me-1"></i>' . htmlspecialchars($p['brand_name']) . '</span>';
        }
        if (!empty($p['size_value'])) {
            $pills[] = '<span class="badge bg-secondary bg-opacity-10 text-secondary me-1"><i class="fas fa-arrows-alt-h fa-xs me-1"></i>' . htmlspecialchars($p['size_value']) . '</span>';
        }
    }
    
    if ($expiryHtml) {
        $pills[] = '<span class="badge bg-light text-dark me-1">' . $expiryHtml . '</span>';
    }
    
    if ($activeBatches > 0) {
        $pills[] = '<span class="badge bg-primary bg-opacity-10 text-primary"><i class="fas fa-layer-group fa-xs me-1"></i>' . $activeBatches . ' batch(es)</span>';
    }
    
    $catBadge = $isMedicine
        ? '<span class="badge bg-info me-1">Medicine</span>'
        : '<span class="badge bg-secondary me-1">Non-Medicine</span>';
    
    // Units/Pack display
    $unitsPerPackDisplay = formatUnitsPerPackDisplay($unitsPerPack, $unitType, $dosageForm, $p['pack_type'] ?? '');
    
    ?>
<div class="search-result-card p-3 border-bottom" 
     style="cursor:pointer; transition:background 0.2s;" 
     onmouseover="this.style.backgroundColor='#f8f9fa'" 
     onmouseout="this.style.backgroundColor='white'"
     onclick="handleAddToCart(<?php echo (int)$p['id']; ?>)" 
     tabindex="0"
     onkeydown="if(event.key==='Enter') handleAddToCart(<?php echo (int)$p['id']; ?>)">
    
    <div class="d-flex justify-content-between align-items-start mb-2">
        <div class="flex-grow-1">
            <div class="d-flex align-items-center flex-wrap gap-2 mb-1">
                <strong class="fs-6"><?php echo htmlspecialchars($p['product_name']); ?></strong>
                <?php echo $catBadge; ?>
                <?php if (!empty($p['product_code'])): ?>
                    <code class="small text-muted"><?php echo htmlspecialchars($p['product_code']); ?></code>
                <?php endif; ?>
            </div>
            <?php if (!empty($pills)): ?>
                <div class="d-flex flex-wrap gap-1 mb-2"><?php echo implode('', $pills); ?></div>
            <?php endif; ?>
        </div>
        <div class="text-end ms-3">
            <button class="btn btn-sm btn-primary rounded-pill px-3" onclick="event.stopPropagation(); handleAddToCart(<?php echo (int)$p['id']; ?>);">
                <i class="fas fa-plus-circle me-1"></i> Add
            </button>
        </div>
    </div>
    
    <div class="row g-2 small">
        <div class="col-md-6">
            <div class="d-flex justify-content-between align-items-center border-bottom pb-1 mb-1">
                <span class="text-muted">Purchase Price:</span>
                <strong class="text-danger">Rs <?php echo $purchasePrice; ?></strong>
                <span class="text-muted small">/ <?php echo $unitType; ?></span>
            </div>
            <div class="d-flex justify-content-between align-items-center border-bottom pb-1 mb-1">
                <span class="text-muted">Sale Price:</span>
                <strong class="text-success">Rs <?php echo $salePrice; ?></strong>
                <span class="text-muted small">/ <?php echo $unitType; ?></span>
            </div>
            <div class="d-flex justify-content-between align-items-center">
                <span class="text-muted">Units/Pack:</span>
                <strong><?php echo $unitsPerPackDisplay; ?></strong>
            </div>
        </div>
        <div class="col-md-6">
            <div class="d-flex justify-content-between align-items-center border-bottom pb-1 mb-1">
                <span class="text-muted">Current Stock:</span>
                <?php echo $stockHtml; ?>
            </div>
            <?php if ($minReorderLevel > 0 && $totalStock > 0): ?>
            <div class="d-flex justify-content-between align-items-center border-bottom pb-1 mb-1">
                <span class="text-muted">Reorder Level:</span>
                <span class="text-warning">≤ <?php echo $minReorderLevel; ?> units</span>
            </div>
            <?php endif; ?>
            <?php if ($activeBatches > 0): ?>
            <div class="d-flex justify-content-between align-items-center">
                <span class="text-muted">Active Batches:</span>
                <span class="text-primary"><?php echo $activeBatches; ?> batch(es)</span>
            </div>
            <?php endif; ?>
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
    transform: translateX(2px);
}
</style>

<script>
// Global function to handle adding product to cart
function handleAddToCart(productId) {
    console.log('handleAddToCart called with productId:', productId);
    
    // Try multiple ways to call the parent's addToCart function
    if (window.parent && typeof window.parent.addToCart === 'function') {
        console.log('Calling parent.addToCart');
        window.parent.addToCart(productId);
    } 
    else if (window.opener && typeof window.opener.addToCart === 'function') {
        console.log('Calling opener.addToCart');
        window.opener.addToCart(productId);
    }
    else if (window.parent && window.parent.postMessage) {
        // Fallback: use postMessage to communicate with parent
        console.log('Using postMessage to parent');
        window.parent.postMessage({
            type: 'addToCart',
            productId: productId
        }, '*');
    }
    else {
        // Last resort: redirect
        console.log('No parent addToCart found, redirecting');
        window.location.href = '../purchases/add.php?product_id=' + productId;
    }
}

// Also expose to window for safety
window.addToCart = handleAddToCart;

// Listen for messages from parent (if needed)
window.addEventListener('message', function(event) {
    if (event.data && event.data.type === 'addToCartResponse') {
        console.log('Received response from parent:', event.data);
    }
});
</script>
<?php
exit;
?>