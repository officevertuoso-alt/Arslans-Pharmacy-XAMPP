<?php
/**
 * sales/print_invoice.php
 * Thermal-style printable invoice (80mm width).
 * Medicine rows show: #, Product Name, Form+Strength, Generic, Qty, Unit Price, Total
 * Non-medicine rows show: #, Product Name, Brand, Size/Value, Qty, Unit Price, Total
 */
require_once '../config/config.php';
require_once '../includes/auth.php';
requireLogin();
if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
if (!canDo('sales', 'print')) {
    echo json_encode(['success' => false, 'message' => 'Access denied. Insufficient permissions.']);
    exit;
}
require_once '../includes/sale_functions.php';

$id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$sale = getSaleById($id);

if (!$sale) {
    die('<h3 style="font-family:sans-serif;text-align:center;margin-top:40px;">Sale not found (ID: ' . $id . ')</h3>');
}

$db = db();
$db->query("SELECT * FROM company_settings WHERE id = 1");
$co = $db->single();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Invoice – <?php echo htmlspecialchars($sale['invoice_no']); ?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{
    font-family:'Courier New',Courier,monospace;
    font-size:11.5px;
    width:80mm;
    max-width:80mm;
    margin:0 auto;
    padding:8px 6px;
    color:#111;
    background:#fff;
}
.center{text-align:center;}
.co-name{font-size:15px;font-weight:700;letter-spacing:.4px;}
.co-sub{font-size:10px;margin-top:1px;}
.sep-dash{border:none;border-top:1px dashed #666;margin:6px 0;}
.sep-solid{border:none;border-top:1px solid #333;margin:4px 0;}
.info-row{display:flex;justify-content:space-between;font-size:11px;margin-bottom:2px;}
.info-row b{min-width:78px;display:inline-block;}

/* Items table */
table{width:100%;border-collapse:collapse;}
th,td{padding:2px 2px;font-size:10px;vertical-align:top;}
thead tr{border-top:1px solid #333;border-bottom:1px solid #333;}
th{font-weight:700;}
tbody tr{border-bottom:1px dotted #ccc;}
.td-r{text-align:right;}
.td-c{text-align:center;}
.item-detail{font-size:9.5px;color:#555;line-height:1.4;}

/* Totals */
.tot-block{margin-top:5px;}
.tot-row{display:flex;justify-content:space-between;font-size:11px;margin-bottom:2px;}
.tot-grand{font-size:13px;font-weight:700;border-top:1px solid #333;border-bottom:1px solid #333;padding:3px 0;margin:4px 0;}
.tot-balance{font-weight:700;}
.due{color:#c0392b;}
.change{color:#27ae60;}

/* Footer */
.footer{text-align:center;font-size:10px;margin-top:8px;padding-top:6px;border-top:1px dashed #666;}

/* Print button (hidden on print) */
.no-print{text-align:center;margin-top:16px;}
.no-print button{padding:5px 16px;margin:3px;font-size:12px;cursor:pointer;}

@media print{
    body{padding:0;width:72mm;} /* leave a little margin */
    .no-print{display:none!important;}
}
</style>
</head>
<body>

<!-- ── Header ── -->
<div class="center">
    <div class="co-name"><?php echo htmlspecialchars($co['company_name'] ?? 'PHARMACY'); ?></div>
    <?php if (!empty($co['address'])): ?>
    <div class="co-sub"><?php echo htmlspecialchars($co['address']); ?></div>
    <?php endif; ?>
    <div class="co-sub">
        Ph: <?php echo htmlspecialchars($co['phone'] ?? ''); ?>
        <?php if (!empty($co['gst_no'])): ?> | GST: <?php echo htmlspecialchars($co['gst_no']); ?><?php endif; ?>
    </div>
</div>
<hr class="sep-dash">

<!-- ── Invoice Info ── -->
<div class="info-row"><span><b>Invoice #:</b></span><span><?php echo htmlspecialchars($sale['invoice_no']); ?></span></div>
<div class="info-row"><span><b>Date:</b></span><span><?php echo date('d/m/Y h:i A', strtotime($sale['sale_date'])); ?></span></div>
<div class="info-row"><span><b>Customer:</b></span><span><?php echo htmlspecialchars($sale['customer_name']); ?></span></div>
<?php if (!empty($sale['customer_phone'])): ?>
<div class="info-row"><span><b>Phone:</b></span><span><?php echo htmlspecialchars($sale['customer_phone']); ?></span></div>
<?php endif; ?>
<?php if ($sale['payment_method'] === 'credit'): ?>
<div class="info-row due"><span><b>Credit Due:</b></span><span><?php echo !empty($sale['due_date']) ? date('d/m/Y', strtotime($sale['due_date'])) : '–'; ?></span></div>
<?php endif; ?>
<hr class="sep-dash">

<!-- ── Items ── -->
<table>
<thead>
<tr>
    <th style="width:14px;">#</th>
    <th>Item Description</th>
    <th class="td-c" style="width:24px;">Qty</th>
    <th class="td-r" style="width:44px;">U.Price</th>
    <th class="td-r" style="width:50px;">Total</th>
</tr>
</thead>
<tbody>
<?php
$sr = 1;
foreach ($sale['items'] as $item):
    $isMed     = ((int)($item['category_id'] ?? 0) === 1);
    $pName     = htmlspecialchars($item['product_name'] ?? '');
    $brand     = htmlspecialchars($item['brand_name']   ?? '');
    $generic   = htmlspecialchars($item['generic_name'] ?? '');
    $unitType  = htmlspecialchars($item['unit_type']    ?? 'Pcs');
    $unitPrice = (float)($item['unit_price']  ?? 0);
    $qty       = (int)($item['quantity']      ?? 1);
    $lineTotal = (float)($item['total']       ?? $qty * $unitPrice);
    $idPct     = (float)($item['item_discount_pct'] ?? 0);
    $idAmt     = (float)($item['item_discount_amt'] ?? 0);
    $itPct     = (float)($item['item_tax_pct']      ?? 0);
    $itAmt     = (float)($item['item_tax_amt']      ?? 0);

    if ($isMed) {
        /* Medicine row: Name / Generic · Form · Strength */
        $parts = [];
        if ($generic) $parts[] = $generic;
        if (!empty($item['detail_text'])) $parts[] = htmlspecialchars($item['detail_text']);
        $detail = implode(' · ', $parts);
    } else {
        /* Non-medicine row: Brand · Size/Value */
        $parts = [];
        if ($brand && $brand !== '-') $parts[] = $brand;
        if (!empty($item['detail_text'])) $parts[] = htmlspecialchars($item['detail_text']);
        $detail = implode(' · ', $parts);
    }
?>
<tr>
    <td><?php echo $sr++; ?></td>
    <td>
        <strong><?php echo $pName; ?></strong>
        <?php if ($detail): ?>
        <div class="item-detail"><?php echo $detail; ?></div>
        <?php endif; ?>
        <?php if ($idPct > 0 || $itPct > 0): ?>
        <div class="item-detail" style="color:#888;">
            <?php if ($idPct > 0): ?>Disc <?php echo number_format($idPct,1); ?>% −Rs <?php echo number_format($idAmt,2); ?><?php endif; ?>
            <?php if ($itPct > 0): ?> Tax <?php echo number_format($itPct,1); ?>% +Rs <?php echo number_format($itAmt,2); ?><?php endif; ?>
        </div>
        <?php endif; ?>
    </td>
    <td class="td-c"><?php echo $qty; ?></td>
    <td class="td-r">Rs <?php echo number_format($unitPrice, 2); ?></td>
    <td class="td-r"><strong>Rs <?php echo number_format($lineTotal, 2); ?></strong></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<hr class="sep-solid">

<!-- ── Totals ── -->
<div class="tot-block">
    <div class="tot-row"><span>Sub-Total:</span><span>Rs <?php echo number_format($sale['subtotal'], 2); ?></span></div>
    <?php if ($sale['discount_amount'] > 0): ?>
    <div class="tot-row due"><span>Overall Discount (<?php echo number_format($sale['discount_percent'],2); ?>%):</span><span>− Rs <?php echo number_format($sale['discount_amount'], 2); ?></span></div>
    <?php endif; ?>
    <?php if ($sale['tax_amount'] > 0): ?>
    <div class="tot-row change"><span>Overall Tax (<?php echo number_format($sale['tax_percent'],2); ?>%):</span><span>+ Rs <?php echo number_format($sale['tax_amount'], 2); ?></span></div>
    <?php endif; ?>
    <div class="tot-row tot-grand"><span>GRAND TOTAL:</span><span>Rs <?php echo number_format($sale['total'], 2); ?></span></div>
    <div class="tot-row"><span>Payment:</span><span><?php echo ucfirst($sale['payment_method']); ?></span></div>
    <div class="tot-row"><span>Paid:</span><span>Rs <?php echo number_format($sale['paid_amount'], 2); ?></span></div>
    <?php
    $bal = $sale['total'] - $sale['paid_amount'];
    if (abs($bal) > 0.009):
        $bClass = $bal > 0 ? 'due' : 'change';
        $bLabel = $bal > 0 ? 'Due' : 'Change';
    ?>
    <div class="tot-row tot-balance <?php echo $bClass; ?>">
        <span><?php echo $bLabel; ?>:</span>
        <span>Rs <?php echo number_format(abs($bal), 2); ?></span>
    </div>
    <?php endif; ?>
</div>

<!-- ── Footer ── -->
<div class="footer">
    <?php echo htmlspecialchars($co['footer_text'] ?? 'Thank you! Visit again.'); ?><br>
    <span style="color:#999;">*** Computer Generated Invoice ***</span>
</div>

<!-- Print / Close buttons -->
<div class="no-print">
    <button onclick="window.print()">🖨 Print</button>
    <button onclick="window.close()">✕ Close</button>
</div>

<script>
// Auto-print if ?print=1 in URL
if (window.location.search.indexOf('print=1') !== -1) {
    window.addEventListener('load', function(){ window.print(); });
}
</script>
</body>
</html>