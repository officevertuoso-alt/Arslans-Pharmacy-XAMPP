<?php
/**
 * Print Barcode Labels – FIFO Batch Selection
 *
 * Modes:
 *  1. label_mode = 'batch_specific'  → single batch (from view.php per-batch button)
 *     Requires: product_ids[], batch_id
 *  2. (default) 'near_expiry'        → FIFO batch per product (earliest purchase date with stock)
 *     Requires: product_ids[]
 *
 * Label content per batch:
 *   - Product Name + Brand
 *   - Generic Name (if medicine)
 *   - Strength / Size
 *   - Sale Price (batch-specific)
 *   - Expiry Date
 *   - Barcode (batch number if batch exists, else product barcode, else product code)
 */

require_once '../config/config.php';
require_once '../includes/auth.php';
requireLogin();

if (!canDo('products', 'print')) {
    // BUG FIX: This is an HTML page — output HTML not JSON on deny
    die('<h3 style="color:red;padding:20px">Access denied. You do not have permission to print barcode labels.</h3>');
    exit;
}
require_once '../includes/product_functions.php';

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die('Invalid request method.');
}

// CSRF protection
if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
    die('Security token mismatch. Please refresh and try again.');
}

$productIds = isset($_POST['product_ids']) && is_array($_POST['product_ids'])
    ? array_unique(array_filter(array_map('intval', $_POST['product_ids'])))
    : [];

if (empty($productIds)) {
    die('No products selected.');
}

$labelMode = $_POST['label_mode'] ?? 'near_expiry';
$batchId   = isset($_POST['batch_id']) ? (int)$_POST['batch_id'] : 0;
$copies    = isset($_GET['copies']) ? max(1, min(10, (int)$_GET['copies'])) : 1;

$db = db();

// ─────────────────────────────────────────────────────────────────────────
// Build label data
// ─────────────────────────────────────────────────────────────────────────
$labels = [];

if ($labelMode === 'batch_specific' && $batchId > 0) {
    // Single batch – fetch batch + product details
    $placeholders = rtrim(str_repeat('?,', count($productIds)), ',');
    $db->query("
        SELECT 
            p.id AS product_id,
            p.product_name,
            p.brand_name,
            p.generic_name,
            p.strength,
            p.size_value AS size,
            p.product_code,
            p.barcode AS product_barcode,
            b.id AS batch_id,
            b.batch_number,
            b.selling_price,
            b.purchase_price,
            b.expiry_date,
            COALESCE(NULLIF(b.batch_number, ''), p.barcode, p.product_code) AS effective_barcode
        FROM batches b
        JOIN products p ON p.id = b.product_id
        WHERE b.id = ?
          AND p.id IN ($placeholders)
        LIMIT 1
    ");
    $db->bind(1, $batchId);
    foreach ($productIds as $i => $pid) {
        $db->bind($i + 2, $pid);
    }
    $row = $db->single();
    if ($row) {
        $labels[] = $row;
    }
} else {
    // FIFO batch per product: earliest purchase date with active stock
    $placeholders = rtrim(str_repeat('?,', count($productIds)), ',');
    $db->query("
        SELECT 
            p.id AS product_id,
            p.product_name,
            p.brand_name,
            p.generic_name,
            p.strength,
            p.size_value AS size,
            p.product_code,
            p.barcode AS product_barcode,
            b.id AS batch_id,
            b.batch_number,
            b.selling_price,
            b.purchase_price,
            b.expiry_date,
            COALESCE(NULLIF(b.batch_number, ''), p.barcode, p.product_code) AS effective_barcode
        FROM products p
        LEFT JOIN (
            SELECT 
                b1.*
            FROM batches b1
            INNER JOIN (
                SELECT product_id, MIN(purchase_date) AS min_purchase
                FROM batches
                WHERE remaining_quantity > 0
                  AND (expiry_date IS NULL OR expiry_date >= CURDATE())
                GROUP BY product_id
            ) b2 ON b1.product_id = b2.product_id AND b1.purchase_date = b2.min_purchase
            WHERE b1.remaining_quantity > 0
              AND (b1.expiry_date IS NULL OR b1.expiry_date >= CURDATE())
            GROUP BY b1.product_id
            ORDER BY b1.purchase_date ASC, b1.id ASC
        ) b ON b.product_id = p.id
        WHERE p.id IN ($placeholders)
          AND p.status = 1
        ORDER BY p.product_name ASC
    ");
    foreach ($productIds as $i => $pid) {
        $db->bind($i + 1, $pid);
    }
    $labels = $db->resultSet();

    // Fallback for products with no active FIFO batch – use product master data
    if (empty($labels)) {
        $db->query("
            SELECT 
                p.id AS product_id,
                p.product_name,
                p.brand_name,
                p.generic_name,
                p.strength,
                p.size_value AS size,
                p.product_code,
                p.barcode AS product_barcode,
                NULL AS batch_id,
                NULL AS batch_number,
                p.sale_price AS selling_price,
                p.purchase_price,
                NULL AS expiry_date,
                COALESCE(NULLIF(p.barcode, ''), p.product_code) AS effective_barcode
            FROM products p
            WHERE p.id IN ($placeholders) AND p.status = 1
            ORDER BY p.product_name ASC
        ");
        foreach ($productIds as $i => $pid) {
            $db->bind($i + 1, $pid);
        }
        $labels = $db->resultSet();
    }
}

if (empty($labels)) {
    die('No label data found for the selected product(s).');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Print Barcode Labels</title>
    <style>
        /* RESET & BASE */
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            background: #e9ecef;
            font-family: Arial, Helvetica, sans-serif;
            padding: 20px;
        }

        /* CONTROL BAR (screen only) */
        .controls {
            text-align: center;
            margin-bottom: 30px;
            position: sticky;
            top: 10px;
            background: white;
            padding: 12px 20px;
            border-radius: 40px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            z-index: 100;
            display: inline-flex;
            gap: 15px;
            align-items: center;
            justify-content: center;
            width: auto;
            left: 50%;
            transform: translateX(-50%);
        }

        .controls label { font-weight: 600; }
        .controls select { padding: 6px 12px; border-radius: 6px; border: 1px solid #ccc; }
        .controls button {
            padding: 6px 18px;
            background: #007bff;
            border: none;
            color: white;
            border-radius: 30px;
            cursor: pointer;
            font-weight: bold;
        }
        .controls button:hover { background: #0056b3; }

        /* LABEL GRID – 2 columns on A4 */
        .label-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px 20px;
            max-width: 1200px;
            margin: 0 auto;
        }

        /* INDIVIDUAL LABEL CARD */
        .barcode-card {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 12px;
            padding: 20px 12px 16px;
            text-align: center;
            box-shadow: 0 2px 6px rgba(0,0,0,0.05);
            break-inside: avoid;
            page-break-inside: avoid;
        }

        .product-name {
            font-size: 14px;
            font-weight: 800;
            color: #212529;
            line-height: 1.3;
            margin-bottom: 2px;
        }
        .brand-name {
            font-size: 11px;
            color: #495057;
            font-style: italic;
            margin-bottom: 2px;
        }
        .generic-name {
            font-size: 10px;
            color: #6c757d;
            margin-bottom: 2px;
        }
        .specs {
            font-size: 10px;
            color: #6c757d;
            background: #f1f3f5;
            border-radius: 4px;
            display: inline-block;
            padding: 1px 6px;
            margin-bottom: 6px;
        }
        .price {
            font-size: 15px;
            font-weight: 800;
            color: #155724;
            margin-top: 6px;
        }
        .expiry {
            font-size: 10px;
            font-weight: 600;
            margin-top: 3px;
            padding: 1px 6px;
            border-radius: 4px;
            display: inline-block;
        }
        .expiry.danger { background: #f8d7da; color: #721c24; }
        .expiry.warning { background: #fff3cd; color: #856404; }
        .expiry.normal { color: #6c757d; }

        .barcode-number {
            font-family: 'Courier New', monospace;
            font-size: 12px;
            letter-spacing: 0.5px;
            margin-top: 8px;
            color: #495057;
            word-break: break-all;
        }

        /* PRINT OPTIMISATION */
        @media print {
            body { background: white; padding: 0; margin: 0; }
            .controls { display: none; }
            .label-grid {
                gap: 12px 16px;
                margin: 0;
                padding: 0.2in;
                width: 100%;
            }
            .barcode-card {
                border: 1px solid #ddd;
                border-radius: 6px;
                box-shadow: none;
                padding: 12px 8px;
                break-inside: avoid;
            }
            * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            @page { size: A4; margin: 0.4in; }
        }

        @media (max-width: 700px) {
            .label-grid { grid-template-columns: 1fr; }
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
</head>
<body>

<!-- Control panel -->
<div class="controls">
    <label for="copiesSelect">Labels per product:</label>
    <select id="copiesSelect">
        <?php for ($i = 1; $i <= 10; $i++): ?>
            <option value="<?php echo $i; ?>" <?php echo ($copies == $i) ? 'selected' : ''; ?>>
                <?php echo $i; ?> <?php echo ($i == 1) ? 'copy' : 'copies'; ?>
            </option>
        <?php endfor; ?>
    </select>
    <button id="refreshBtn">🔄 Refresh Preview</button>
    <button id="printBtn">🖨️ Print Labels</button>
    <button id="closeBtn">✖ Close</button>
</div>

<div class="label-grid" id="labelGrid">
<?php
$today = new DateTime();
foreach ($labels as $label):
    // Prepare extra fields
    $expiryDate = !empty($label['expiry_date']) ? new DateTime($label['expiry_date']) : null;
    $daysToExpiry = $expiryDate ? (int)$today->diff($expiryDate)->format('%r%a') : null;
    $expiryClass = '';
    $expiryLabel = '';
    if ($expiryDate) {
        $expiryLabel = 'Exp: ' . $expiryDate->format('d-M-Y');
        if ($daysToExpiry < 0)       $expiryClass = 'danger';
        elseif ($daysToExpiry <= 90) $expiryClass = 'warning';
        else                         $expiryClass = 'normal';
    }

    $barcodeVal = $label['effective_barcode'] ?? $label['product_code'];
    $displayName = htmlspecialchars($label['product_name']);
    $brand = htmlspecialchars($label['brand_name'] ?? '');
    $generic = htmlspecialchars($label['generic_name'] ?? '');
    $strength = htmlspecialchars($label['strength'] ?? '');
    $size = htmlspecialchars($label['size'] ?? '');
    $specs = array_filter([$strength, $size]);
    $salePrice = number_format((float)($label['selling_price'] ?? 0), 2);

    for ($c = 0; $c < $copies; $c++):
?>
    <div class="barcode-card">
        <div class="product-name"><?php echo $displayName; ?></div>
        <?php if ($brand): ?>
            <div class="brand-name"><?php echo $brand; ?></div>
        <?php endif; ?>
        <?php if ($generic): ?>
            <div class="generic-name"><?php echo $generic; ?></div>
        <?php endif; ?>
        <?php if (!empty($specs)): ?>
            <div class="specs"><?php echo htmlspecialchars(implode(' | ', $specs)); ?></div>
        <?php endif; ?>
        <svg class="barcode-svg" data-code="<?php echo htmlspecialchars($barcodeVal); ?>"></svg>
        <div class="barcode-number"><?php echo htmlspecialchars($barcodeVal); ?></div>
        <div class="price">Rs <?php echo $salePrice; ?></div>
        <?php if ($expiryLabel): ?>
            <div class="expiry <?php echo $expiryClass; ?>"><?php echo $expiryLabel; ?></div>
        <?php endif; ?>
    </div>
<?php
    endfor;
endforeach;
?>
</div>

<script>
    function renderAllBarcodes() {
        document.querySelectorAll('.barcode-svg').forEach(function(svg) {
            const code = svg.getAttribute('data-code');
            if (!code) return;
            try {
                JsBarcode(svg, code, {
                    format: 'CODE128',
                    width: 1.8,
                    height: 45,
                    displayValue: false,
                    fontSize: 12,
                    margin: 5
                });
            } catch(e) {
                console.warn('Barcode error for', code);
            }
        });
    }

    // Refresh with new copies (resubmit POST data)
    document.getElementById('refreshBtn').addEventListener('click', function() {
        const copies = document.getElementById('copiesSelect').value;
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = window.location.pathname + '?copies=' + copies;
        // CSRF
        const csrf = document.createElement('input');
        csrf.type = 'hidden';
        csrf.name = 'csrf_token';
        csrf.value = '<?php echo htmlspecialchars($_POST['csrf_token'] ?? ''); ?>';
        form.appendChild(csrf);
        // label_mode
        const mode = document.createElement('input');
        mode.type = 'hidden';
        mode.name = 'label_mode';
        mode.value = '<?php echo htmlspecialchars($labelMode); ?>';
        form.appendChild(mode);
        <?php if ($labelMode === 'batch_specific' && $batchId > 0): ?>
        const batch = document.createElement('input');
        batch.type = 'hidden';
        batch.name = 'batch_id';
        batch.value = <?php echo json_encode($batchId); ?>;
        form.appendChild(batch);
        <?php endif; ?>
        // product_ids
        <?php foreach ($productIds as $pid): ?>
        const inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = 'product_ids[]';
        inp.value = <?php echo json_encode($pid); ?>;
        form.appendChild(inp);
        <?php endforeach; ?>
        document.body.appendChild(form);
        form.submit();
    });

    document.getElementById('printBtn').addEventListener('click', function() {
        window.print();
    });

    document.getElementById('closeBtn').addEventListener('click', function() {
        window.close();
    });

    window.addEventListener('load', renderAllBarcodes);
</script>

</body>
</html>