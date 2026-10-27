<?php
/**
 * sales/print_invoice.php
 *
 * Thermal-style 80mm invoice — EXACTLY 2 ROWS PER PRODUCT
 *
 * FIXES:
 *  1. $db properly initialized before credit due_date lookup.
 *  2. All company details read from getCompanySettings() — fully synchronized.
 *  3. Currency symbol from settings (not hardcoded 'Rs').
 *  4. Date/time format from settings.
 *  5. show_payment_on_invoice, show_batch_on_invoice flags respected.
 */

session_start();
require_once '../config/config.php';
require_once '../includes/sale_functions.php';
require_once '../includes/product_functions.php';
require_once '../includes/settings_functions.php';

requireLogin();

$saleId    = (int)($_GET['id']    ?? 0);
$autoPrint = (int)($_GET['print'] ?? 0);

if ($saleId <= 0) {
    die('<div style="padding:20px;color:red;font-family:sans-serif;">Invalid sale ID.</div>');
}

$sale = getSaleById($saleId);
if (!$sale) {
    die('<div style="padding:20px;color:red;font-family:sans-serif;">Sale not found or has been voided.</div>');
}

// ── Initialize $db for use below ──────────────────────────────
$db = db();

// ── Pharmacy prefs — always from company_settings ────────────
$_co          = getCompanySettings();
$pharmName    = $_co['company_name']            ?? 'PHARMACY';
$pharmTagline = $_co['pharmacy_tagline']        ?? '';
$pharmAddress = $_co['address']                 ?? '';
$pharmPhone   = $_co['phone']                   ?? '';
$pharmEmail   = $_co['email']                   ?? '';
$pharmGST     = $_co['gst_no']                  ?? '';
$pharmLogo    = $_co['logo_path']               ?? '';
$showLogo     = !empty($_co['show_logo_on_invoice']);
$showTagline  = !empty($_co['show_tagline_on_invoice']);
$showPayment  = !empty($_co['show_payment_on_invoice']);
$showBatch    = !empty($_co['show_batch_on_invoice']);
$currency     = $_co['currency_symbol']          ?? 'Rs';
$footerText   = $_co['invoice_footer_text']      ?? ($_co['footer_text'] ?? 'Thank you for shopping with us!');
$invoiceTerms = $_co['invoice_terms']            ?? '';
$primaryColor = $_co['primary_color']            ?? '#1a1a2e';
$dateFormat   = $_co['date_format']              ?? 'd/m/Y';
$timeFormat   = $_co['time_format']              ?? 'h:i A';
$invoicePrefix= $_co['invoice_prefix']           ?? 'INV';

// ── If credit sale, fetch due_date from customer_credits ──────
if (!empty($sale['is_credit']) && empty($sale['due_date'])) {
    try {
        $creditRow = $db->getOne(
            "SELECT due_date FROM customer_credits WHERE sale_id = :sid ORDER BY id DESC LIMIT 1",
            [':sid' => $saleId]
        );
        if ($creditRow && !empty($creditRow['due_date'])) {
            $sale['due_date'] = $creditRow['due_date'];
        }
    } catch (\Throwable $e) {
        // Gracefully ignore if table/column missing
    }
}

/* ── XSS helper ── */
function he($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/* ── Shorten text for thermal print ── */
function shorten($text, $maxLength = 20) {
    $text = trim($text);
    if (strlen($text) <= $maxLength) return $text;
    return mb_substr($text, 0, $maxLength - 2) . '..';
}

/* ── Get product details for display ── */
$rawItems = $sale['items'] ?? [];
$productDetails = [];
if (!empty($rawItems)) {
    $allPids = array_unique(array_column($rawItems, 'product_id'));
    if (!empty($allPids)) {
        $inKeys = [];
        $inParams = [];
        foreach ($allPids as $i => $pid) {
            $k = ':pid' . $i;
            $inKeys[] = $k;
            $inParams[$k] = $pid;
        }
        try {
            $pRows = $db->getAll(
                "SELECT id, product_name, generic_name, brand_name, dosage_form, strength,
                        category_id, size_value, unit_type, units_per_pack
                 FROM products WHERE id IN (" . implode(',', $inKeys) . ")",
                $inParams
            );
            foreach ($pRows as $pr) {
                $productDetails[(int)$pr['id']] = $pr;
            }
        } catch (\Throwable $e) {
            // Gracefully continue without product details
        }
    }
}

/* ── Group items by product_id ── */
$groupedItems = [];
$pidOrder = [];

foreach ($rawItems as $item) {
    $pid   = (int)($item['product_id'] ?? 0);
    $pdata = $productDetails[$pid] ?? [];

    if (!isset($groupedItems[$pid])) {
        $pidOrder[] = $pid;
        $groupedItems[$pid] = [
            'product_id'    => $pid,
            'product_name'  => $pdata['product_name'] ?? $item['product_name'] ?? '',
            'generic_name'  => $item['generic_name']  ?? $pdata['generic_name'] ?? '',
            'brand_name'    => $item['brand_name']    ?? $pdata['brand_name']   ?? '',
            'strength'      => $pdata['strength']     ?? '',
            'dosage_form'   => $pdata['dosage_form']  ?? '',
            'size_value'    => $pdata['size_value']   ?? '',
            'category_id'   => (int)($pdata['category_id'] ?? 0),
            'unit_type'     => $pdata['unit_type']    ?? 'Pcs',
            'units_per_pack'=> max(1, (int)($item['units_per_pack'] ?? $pdata['units_per_pack'] ?? 1)),
            'batches'       => [],
            'total_qty_pcs' => 0,
            'total_amount'  => 0.0,
        ];
    }
    $groupedItems[$pid]['batches'][]      = $item;
    $groupedItems[$pid]['total_qty_pcs'] += (int)($item['quantity'] ?? 0);
    $groupedItems[$pid]['total_amount']  += (float)($item['total']  ?? 0);
}

/* ── Sort batches within each group by expiry date (FIFO) ── */
foreach ($groupedItems as &$group) {
    usort($group['batches'], function($a, $b) {
        $expA = $a['expiry_date'] ?? $a['b_expiry_date'] ?? '9999-12-31';
        $expB = $b['expiry_date'] ?? $b['b_expiry_date'] ?? '9999-12-31';
        return strcmp($expA, $expB);
    });
}
unset($group);

/* ── Financials ── */
$subtotal   = (float)($sale['subtotal']              ?? 0);
$discAmt    = (float)($sale['overall_discount']       ?? $sale['discount']          ?? 0);
$discPct    = (float)($sale['overall_discount_pct']   ?? $sale['discount_percent']  ?? 0);
$taxAmt     = (float)($sale['overall_tax']            ?? $sale['tax_amount']        ?? $sale['tax'] ?? 0);
$taxPct     = (float)($sale['overall_tax_pct']        ?? $sale['tax_percent']       ?? 0);
$grandTotal = (float)($sale['grand_total']            ?? $sale['total']             ?? 0);
$paid       = (float)($sale['paid_amount']            ?? $grandTotal);
$balance    = $grandTotal - $paid;

/* ── Helper: short dosage form ── */
function getShortForm($form) {
    $shortForms = [
        'Tablet' => 'Tab', 'Capsule' => 'Cap', 'Syrup' => 'Syr',
        'Suspension' => 'Susp', 'Injection' => 'Inj', 'Cream' => 'Crm',
        'Ointment' => 'Oint', 'Gel' => 'Gel', 'Drops' => 'Drp',
        'Lotion' => 'Lot', 'Solution' => 'Sol', 'Powder' => 'Pdr',
        'Suppository' => 'Supp', 'Patch' => 'Pch', 'Inhaler' => 'Inh',
    ];
    return $shortForms[$form] ?? substr($form, 0, 6);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Invoice #<?= he($sale['invoice_no']) ?></title>
<style>
* { margin:0; padding:0; box-sizing:border-box; font-family:'Courier New',Courier,monospace !important; }
html, body { font-size:11px; color:#111; background:#e8eaf0; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
.invoice-wrap { width:320px; margin:14px auto; background:#fff; padding:14px 12px 12px; box-shadow:0 4px 18px rgba(0,0,0,.18); border-radius:4px; }
.hdr { text-align:center; border-bottom:2px dashed #333; padding-bottom:8px; margin-bottom:8px; }
.hdr-name { font-size:16px; font-weight:900; letter-spacing:2px; text-transform:uppercase; }
.hdr-sub { font-size:9.5px; color:#555; margin-top:2px; }
.hdr-tag { font-size:9px; color:#888; margin-top:3px; font-style:italic; }
.meta { font-size:9.5px; line-height:1.65; margin-bottom:7px; }
.meta b { font-weight:700; }
.inv-table { width:100%; border-collapse:collapse; table-layout:auto; font-size:9px; }
.inv-table .col-no { width:14px; }
.inv-table .col-btch { width:28px; text-align:center; }
.inv-table .col-qty { width:26px; text-align:center; }
.inv-table .col-price { width:46px; text-align:right; }
.inv-table .col-total { width:52px; text-align:right; }
.inv-table thead tr { border-top:1.5px solid #111; border-bottom:1.5px solid #111; }
.inv-table thead th { padding:3px 2px; font-weight:900; font-size:8.5px; letter-spacing:.2px; white-space:nowrap; }
.inv-table thead th:first-child { text-align:left; }
.inv-table thead th:nth-child(2) { text-align:left; }
.inv-table thead th:nth-child(3) { text-align:center; }
.inv-table thead th:nth-child(4) { text-align:center; }
.inv-table thead th:nth-child(5) { text-align:right; }
.inv-table thead th:nth-child(6) { text-align:right; }
.inv-table tbody tr { border-bottom:1px dotted #ccc; }
.inv-table tbody td { padding:3px 2px; vertical-align:top; font-size:9px; line-height:1.35; }
.inv-table td.td-item { word-break:break-word; overflow-wrap:break-word; line-height:1.3; }
.inv-table td.td-btch { text-align:center; font-weight:800; font-size:9.5px; }
.inv-table td.td-qty { text-align:center; font-weight:900; font-size:9.5px; }
.inv-table td.td-price { text-align:right; font-size:9px; }
.inv-table td.td-total { text-align:right; font-weight:800; font-size:9px; }
.totals { border-top:1.5px dashed #333; margin-top:5px; padding-top:5px; font-size:9.5px; line-height:1.75; }
.totals .t-row { display:flex; justify-content:space-between; }
.totals .t-row b { font-weight:700; }
.totals .t-grand { display:flex; justify-content:space-between; border-top:1.5px solid #111; margin-top:3px; padding-top:4px; font-size:12px; font-weight:900; }
.totals .t-disc { color:#c0392b; }
.totals .t-tax { color:#27ae60; }
.totals .t-bal { font-weight:800; font-size:10px; color:#c0392b; }
.totals .t-change { font-weight:800; font-size:10px; color:#27ae60; }
.credit-box { margin-top:6px; padding:5px 8px; background:#fdecea; border:1.5px solid #f5c6cb; border-radius:4px; color:#c0392b; font-size:10px; font-weight:800; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
.returns-block { margin-top:6px; font-size:9.5px; border-top:2px dashed #444; padding-top:7px; }
.ret-hdr { font-weight:900; color:#c0392b; margin-bottom:5px; }
.ret-item { border-bottom:1px dotted #ccc; padding:3px 0; }
.ret-item .ret-no { font-weight:700; }
.ret-item .ret-amt { float:right; color:#c0392b; font-weight:700; }
.ret-item .ret-note { color:#888; display:block; margin-top:1px; }
.ret-net { display:flex; justify-content:space-between; margin-top:4px; font-weight:900; font-size:10px; }
.footer { text-align:center; border-top:2px dashed #333; margin-top:8px; padding-top:6px; font-size:9px; color:#666; }
.footer .footer-sub { color:#aaa; }
.action-btns { width:320px; margin:10px auto; display:flex; gap:8px; }
.action-btns button { flex:1; padding:9px 4px; border:none; border-radius:7px; cursor:pointer; font-size:13px; font-weight:700; }
.btn-print { background:#28a745; color:#fff; }
.btn-close { background:#6c757d; color:#fff; }
@media print {
    @page { margin:0; size:80mm auto; }
    html, body { width:80mm; margin:0 !important; padding:0 !important; background:#fff !important; font-size:11px !important; -webkit-print-color-adjust:exact !important; print-color-adjust:exact !important; }
    .invoice-wrap { width:100% !important; margin:0 !important; padding:10px 8px !important; box-shadow:none !important; border-radius:0 !important; }
    .no-print { display:none !important; }
}
</style>
</head>
<body>

<div class="invoice-wrap" id="invoice">

    <!-- ══ HEADER ══ -->
    <div class="hdr">
        <?php if ($showLogo && !empty($pharmLogo)): ?>
        <?php $_lf = ROOT_PATH . ltrim($pharmLogo, '/'); $_lurl = BASE_URL . ltrim($pharmLogo, '/') . '?v=' . (file_exists($_lf) ? filemtime($_lf) : time()); ?>
        <div style="text-align:center;margin-bottom:6px;">
            <img src="<?= he($_lurl) ?>" alt="<?= he($pharmName) ?>"
                 style="max-height:55px;max-width:160px;object-fit:contain;">
        </div>
        <?php endif; ?>
        <div class="hdr-name"><?= he($pharmName) ?></div>
        <?php if ($showTagline && !empty($pharmTagline)): ?>
        <div class="hdr-sub" style="font-style:italic;"><?= he($pharmTagline) ?></div>
        <?php endif; ?>
        <?php if ($pharmAddress): ?><div class="hdr-sub"><?= he($pharmAddress) ?></div><?php endif; ?>
        <?php if ($pharmPhone):   ?><div class="hdr-sub">Tel: <?= he($pharmPhone) ?></div><?php endif; ?>
        <?php if ($pharmEmail):   ?><div class="hdr-sub"><?= he($pharmEmail) ?></div><?php endif; ?>
        <?php if ($pharmGST):     ?><div class="hdr-sub">GST: <?= he($pharmGST) ?></div><?php endif; ?>
        <div class="hdr-tag">** Computer Generated Invoice **</div>
    </div>

    <!-- ══ META ══ -->
    <div class="meta">
        <div><b>Invoice#:</b> <?= he($sale['invoice_no']) ?></div>
        <div><b>Date:</b> <?= date($dateFormat . ' ' . $timeFormat, strtotime($sale['sale_date'])) ?></div>
        <div><b>Customer:</b> <?= he($sale['customer_name'] ?: 'Walk-in Customer') ?></div>
        <?php if (!empty($sale['customer_phone'])): ?>
        <div><b>Phone:</b> <?= he($sale['customer_phone']) ?></div>
        <?php endif; ?>
        <?php if ($showPayment): ?>
        <div><b>Payment:</b> <?= he(ucfirst($sale['payment_method'] ?? 'Cash')) ?></div>
        <?php endif; ?>
        <?php if (!empty($sale['is_credit']) && !empty($sale['due_date'])): ?>
        <div style="color:#c0392b;font-weight:700;"><b>Due Date:</b> <?= he(date($dateFormat, strtotime($sale['due_date']))) ?></div>
        <?php endif; ?>
    </div>

    <!-- ══ ITEMS TABLE ══ -->
    <table class="inv-table">
        <thead>
            <tr>
                <th class="col-no">#</th>
                <th>Item / Details</th>
                <?php if ($showBatch): ?><th class="col-btch">Btch</th><?php endif; ?>
                <th class="col-qty">Qty</th>
                <th class="col-price">Price</th>
                <th class="col-total">Total</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $srNo = 0;
        foreach ($pidOrder as $pid):
            $g = $groupedItems[$pid];
            $srNo++;
            $upp     = $g['units_per_pack'];
            $isMed   = ((int)($g['category_id'] ?? 0) === 1);
            $generic = trim($g['generic_name'] ?? '');
            $brand   = trim($g['brand_name']   ?? '');
            $form    = trim($g['dosage_form']  ?? '');
            $strength= trim($g['strength']     ?? '');
            $size    = trim($g['size_value']   ?? '');

            $batchSerial = 0;

            foreach ($g['batches'] as $bi => $b):
                $batchSerial++;
                $qtyPcs      = (int)($b['quantity']              ?? 0);
                $pricePC     = (float)($b['unit_price']          ?? 0);
                $rowTotal    = (float)($b['total']               ?? 0);
                $discPctItem = (float)($b['item_discount_pct']   ?? $b['discount_pct'] ?? 0);
                $batchNum    = $b['batch_number']                 ?? '';
                $isFirst     = ($bi === 0);

                $detailsHtml = '';

                if ($isFirst) {
                    $row1 = he($g['product_name']);
                    if ($isMed && $form) $row1 .= ' (' . getShortForm($form) . ')';
                    if ($strength)       $row1 .= ' ' . he($strength);
                    if (!$isMed && $size) $row1 .= ' (' . he($size) . ')';

                    $detailsHtml = '<b style="font-size:10px;">' . $row1 . '</b>';

                    $row2 = '';
                    if ($isMed && $generic) $row2 .= shorten($generic, 22);
                    if (!$isMed && $brand)  $row2 .= shorten($brand, 22);
                    if ($upp > 1) {
                        if ($row2) $row2 .= ' ';
                        $row2 .= '(×' . $upp . ')';
                    }
                    if ($row2) {
                        $detailsHtml .= '<br><span style="font-size:8.5px;color:#444;">' . he($row2) . '</span>';
                    }
                    if ($discPctItem > 0) {
                        $detailsHtml .= '<br><span style="font-size:7px;color:#c0392b;">Disc ' . number_format($discPctItem, 1) . '%</span>';
                    }
                    /* FEFO badge when product spans multiple batches */
                    if (count($g['batches']) > 1) {
                        $detailsHtml .= '<br><span style=\"font-size:7px;background:#e8f5e9;color:#1b5e20;padding:0 3px;border-radius:2px;font-weight:700;\">FEFO×' . count($g['batches']) . '</span>';
                    }
                } else {
                    $shortName   = shorten($g['product_name'], 22);
                    $detailsHtml = '<span style="color:#aaa;font-size:8px;">↳ ' . he($shortName) . '</span>';
                    if ($discPctItem > 0) {
                        $detailsHtml .= '<br><span style="font-size:7px;color:#c0392b;">Disc ' . number_format($discPctItem, 1) . '%</span>';
                    }
                }
                /* Phase 25 FEFO: show batch number + expiry date on thermal */
                $batchExpiry = $b['expiry_date'] ?? $b['b_expiry_date'] ?? '';
                if ($showBatch && (!empty($batchNum) || !empty($batchExpiry))) {
                    $expStr = $batchExpiry ? date('d/m/Y', strtotime($batchExpiry)) : '';
                    $bLine  = '';
                    if (!empty($batchNum)) $bLine .= 'B:' . he(substr($batchNum, 0, 10));
                    if (!empty($expStr))   $bLine .= ($bLine ? ' ' : '') . 'Exp:' . $expStr;
                    if ($bLine) {
                        $detailsHtml .= '<br><span style=\"font-size:7.5px;color:#666;\">' . $bLine . '</span>';
                    }
                }
        ?>
            <tr>
                <td class="col-no" style="color:#888;font-size:9px;font-weight:600;vertical-align:top;"><?= $isFirst ? $srNo : '' ?></td>
                <td class="td-item" style="vertical-align:top;"><?= $detailsHtml ?></td>
                <?php if ($showBatch): ?>
                <td class="td-btch" style="vertical-align:top;font-size:8px;color:#555;"><?= $batchSerial ?><?= ($showBatch && !empty($batchNum)) ? '<br><span style="font-size:7px;color:#888;">'.he(substr($batchNum,0,8)).'</span>' : '' ?></td>
                <?php endif; ?>
                <td class="td-qty" style="vertical-align:top;"><?= $qtyPcs ?></td>
                <td class="td-price" style="vertical-align:top;"><?= number_format($pricePC, 2) ?></td>
                <td class="td-total" style="vertical-align:top;"><?= number_format($rowTotal, 2) ?></td>
            </tr>
        <?php
            endforeach;
        endforeach;
        ?>
        </tbody>
    </table>

    <!-- ══ TOTALS ══ -->
    <div class="totals">
        <?php if ($discAmt > 0 || $taxAmt > 0 || count($pidOrder) > 1): ?>
        <div class="t-row">
            <span>Sub-Total</span><b><?= $currency ?> <?= number_format($subtotal, 2) ?></b>
        </div>
        <?php endif; ?>

        <?php if ($discAmt > 0): ?>
        <div class="t-row t-disc">
            <span>Discount<?= $discPct > 0 ? ' (' . number_format($discPct, 1) . '%)' : '' ?></span>
            <span>- <?= $currency ?> <?= number_format($discAmt, 2) ?></span>
        </div>
        <?php endif; ?>

        <?php if ($taxAmt > 0): ?>
        <div class="t-row t-tax">
            <span>Tax<?= $taxPct > 0 ? ' (' . number_format($taxPct, 1) . '%)' : '' ?></span>
            <span>+ <?= $currency ?> <?= number_format($taxAmt, 2) ?></span>
        </div>
        <?php endif; ?>

        <div class="t-grand">
            <span>GRAND TOTAL</span><span><?= $currency ?> <?= number_format($grandTotal, 2) ?></span>
        </div>

        <div class="t-row" style="margin-top:3px;">
            <span>Paid<?= $showPayment ? ' (' . ucfirst(he($sale['payment_method'] ?? 'cash')) . ')' : '' ?></span>
            <span><?= $currency ?> <?= number_format($paid, 2) ?></span>
        </div>

        <?php if (abs($balance) > 0.005): ?>
        <div class="t-row <?= $balance > 0 ? 't-bal' : 't-change' ?>">
            <span><?= $balance > 0 ? 'Balance Due:' : 'Change:' ?></span>
            <span><?= $currency ?> <?= number_format(abs($balance), 2) ?></span>
        </div>
        <?php endif; ?>

        <?php if (!empty($sale['is_credit'])): ?>
        <div class="credit-box">
            ⚠ CREDIT SALE — Due: <?= he((!empty($sale['due_date'])) ? date($dateFormat, strtotime($sale['due_date'])) : 'Not set') ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ══ RETURNS HISTORY ══ -->
    <?php if (!empty($sale['returns'])): ?>
    <div class="returns-block">
        <div class="ret-hdr">↺ RETURNS HISTORY</div>
        <?php foreach ($sale['returns'] as $ret): ?>
        <div class="ret-item">
            <span class="ret-no"><?= he($ret['return_no'] ?? '#'.$ret['id']) ?></span>
            <span class="ret-amt">-<?= $currency ?> <?= number_format((float)($ret['total_amount'] ?? 0), 2) ?></span>
            <span class="ret-note"><?= he($ret['reason'] ?? '') ?></span>
        </div>
        <?php endforeach; ?>
        <div class="ret-net">
            <span>Net After Returns:</span>
            <span><?= $currency ?> <?= number_format((float)($sale['net_after_returns'] ?? $grandTotal), 2) ?></span>
        </div>
    </div>
    <?php endif; ?>

    <!-- ══ FOOTER ══ -->
    <div class="footer">
        <?= he($footerText) ?><br>
        <?php if (!empty($invoiceTerms)): ?>
        <span style="font-size:9px;color:#888;display:block;margin-top:3px;"><?= he($invoiceTerms) ?></span>
        <?php endif; ?>
        <span class="footer-sub">*** Powered by Dr. Arslan Yasin Sukhera ***</span>
    </div>

</div><!-- /invoice-wrap -->

<!-- Buttons (screen only) -->
<div class="action-btns no-print">
    <button class="btn-print" onclick="window.print()">🖨 Thermal Print</button>
    <a href="invoice_pdf.php?id=<?= (int)$saleId ?>&view=1" target="_blank"
       style="display:inline-block;padding:8px 16px;background:#1a1a2e;color:#fff;border-radius:6px;font-size:12px;text-decoration:none;font-weight:600;">
        📄 A4 / PDF
    </a>
    <button class="btn-close" onclick="window.close()">✕ Close</button>
</div>

<?php if ($autoPrint): ?>
<script>
    window.addEventListener('load', function(){
        setTimeout(function(){ window.print(); }, 450);
    });
</script>
<?php endif; ?>

</body>
</html>