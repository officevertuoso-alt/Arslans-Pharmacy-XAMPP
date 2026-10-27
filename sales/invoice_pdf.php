<?php
/**
 * sales/invoice_pdf.php — Phase 25
 * Generates a downloadable A4 PDF invoice for any completed sale.
 *
 * Usage:  invoice_pdf.php?id=<sale_id>
 *         invoice_pdf.php?id=<sale_id>&view=1   (inline browser view)
 *
 * Falls back to an HTML print page if a PDF library is not installed,
 * so it always works even without Composer dependencies.
 */

session_start();
require_once '../config/config.php';
require_once '../includes/sale_functions.php';
require_once '../includes/settings_functions.php';

requireLogin();

$saleId = (int)($_GET['id'] ?? 0);
$inline = (int)($_GET['view'] ?? 0);  // 1 = view in browser, 0 = download

if ($saleId <= 0) {
    die('<p style="color:red;font-family:sans-serif;padding:20px;">Invalid sale ID.</p>');
}

$sale = getSaleById($saleId);
if (!$sale) {
    die('<p style="color:red;font-family:sans-serif;padding:20px;">Sale not found.</p>');
}

$db = db();

/* ── Company settings ── */
$_co          = getCompanySettings();
$pharmName    = $_co['company_name']         ?? 'PHARMACY';
$pharmTagline = $_co['pharmacy_tagline']     ?? '';
$pharmAddr    = $_co['address']              ?? '';
$pharmPhone   = $_co['phone']               ?? '';
$pharmEmail   = $_co['email']               ?? '';
$pharmGST     = $_co['gst_no']              ?? '';
$currency     = $_co['currency_symbol']      ?? 'Rs';
$footerText   = $_co['invoice_footer_text']  ?? ($_co['footer_text'] ?? 'Thank you for shopping with us!');
$invoiceTerms = $_co['invoice_terms']        ?? '';
$primaryColor = $_co['primary_color']        ?? '#1a1a2e';
$accentColor  = $_co['accent_color']         ?? '#e94560';
$showBatch    = !empty($_co['show_batch_on_invoice']);
$showPayment  = !empty($_co['show_payment_on_invoice']);
$showTagline  = !empty($_co['show_tagline_on_invoice']);
$showLogo     = !empty($_co['show_logo_on_invoice']);
$logoPath     = $_co['logo_path']            ?? '';
$dateFormat   = $_co['date_format']          ?? 'd/m/Y';
$timeFormat   = $_co['time_format']          ?? 'h:i A';

/* ── Logo ── */
$logoUrl = '';
if ($showLogo && !empty($logoPath)) {
    $logoFsPath = (defined('ROOT_PATH') ? ROOT_PATH : '') . ltrim($logoPath, '/');
    if (file_exists($logoFsPath)) {
        $logoUrl = (defined('BASE_URL') ? BASE_URL : '/') . ltrim($logoPath, '/');
    }
}

/* ── Credit due date ── */
if (!empty($sale['is_credit']) && empty($sale['due_date'])) {
    try {
        $cr = $db->getOne("SELECT due_date FROM customer_credits WHERE sale_id = :sid ORDER BY id DESC LIMIT 1", [':sid' => $saleId]);
        if ($cr && !empty($cr['due_date'])) $sale['due_date'] = $cr['due_date'];
    } catch (\Throwable $e) {}
}

/* ── Sale items with FEFO batch info ── */
try {
    $pdo   = $db->getConnection();
    $stmt  = $pdo->prepare(
        "SELECT si.*,
                b.batch_number AS b_batch_number,
                b.expiry_date  AS b_expiry_date,
                p.product_name AS p_name,
                p.generic_name, p.brand_name, p.strength, p.dosage_form,
                p.size_value, p.unit_type, p.units_per_pack,
                p.category_id
         FROM   sale_items si
         LEFT JOIN batches  b ON b.id = si.batch_id
         LEFT JOIN products p ON p.id = si.product_id
         WHERE  si.sale_id = ?
         ORDER  BY si.id ASC"
    );
    $stmt->execute([$saleId]);
    $rawItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $rawItems = [];
}

/* ── Group items by product (FEFO multi-batch rows merge per product) ── */
$groupedItems = [];
foreach ($rawItems as $item) {
    $pid  = (int)($item['product_id'] ?? 0);
    $pname = $item['product_name'] ?? $item['p_name'] ?? 'Unknown';
    if (!isset($groupedItems[$pid])) {
        $groupedItems[$pid] = [
            'product_id'    => $pid,
            'product_name'  => $pname,
            'generic_name'  => $item['generic_name']  ?? '',
            'brand_name'    => $item['brand_name']    ?? '',
            'strength'      => $item['strength']      ?? '',
            'dosage_form'   => $item['dosage_form']   ?? '',
            'size_value'    => $item['size_value']    ?? '',
            'category_id'   => (int)($item['category_id']  ?? 0),
            'unit_type'     => $item['unit_type']     ?? 'Pcs',
            'units_per_pack'=> max(1,(int)($item['units_per_pack'] ?? 1)),
            'batches'       => [],
            'total_qty'     => 0,
            'total_amount'  => 0.0,
        ];
    }
    $groupedItems[$pid]['batches'][]    = $item;
    $groupedItems[$pid]['total_qty']   += (int)($item['quantity'] ?? 0);
    $groupedItems[$pid]['total_amount']+= (float)($item['total'] ?? 0);
}

/* ── Financials ── */
$subtotal   = (float)($sale['subtotal']           ?? 0);
$discAmt    = (float)($sale['discount']           ?? $sale['discount_amount'] ?? 0);
$discPct    = (float)($sale['discount_percent']   ?? 0);
$taxAmt     = (float)($sale['tax_amount']         ?? $sale['tax'] ?? 0);
$taxPct     = (float)($sale['tax_percent']        ?? 0);
$grandTotal = (float)($sale['grand_total']        ?? $sale['total'] ?? 0);
$paidAmt    = (float)($sale['paid_amount']        ?? $grandTotal);
$balance    = $grandTotal - $paidAmt;

$payMethod  = $sale['payment_method'] ?? 'cash';
$payLabels  = ['cash'=>'Cash','card'=>'Card','online'=>'Online','credit'=>'Credit','bank'=>'Bank','cheque'=>'Cheque'];
$payLabel   = $payLabels[$payMethod] ?? ucfirst($payMethod);
$saleDate   = !empty($sale['sale_date']) ? date($dateFormat . ' ' . $timeFormat, strtotime($sale['sale_date'])) : date('d/m/Y');
$isCredit   = !empty($sale['is_credit']);
$dueDate    = !empty($sale['due_date']) ? date($dateFormat, strtotime($sale['due_date'])) : '';

/* ── Helper ── */
function he25($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmtDate25($d, $fmt='d/m/Y') { return $d ? date($fmt, strtotime($d)) : ''; }

/* ───────────────────────────────────────────────────
   HTML / CSS — A4 layout, print-optimised
──────────────────────────────────────────────────── */
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Invoice <?= he25($sale['invoice_no']) ?></title>
<style>
:root {
  --primary: <?= he25($primaryColor) ?>;
  --accent:  <?= he25($accentColor) ?>;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
  font-family: 'Segoe UI', Arial, Helvetica, sans-serif;
  font-size: 12px;
  color: #1a1a1a;
  background: #e8eaf0;
  -webkit-print-color-adjust: exact;
  print-color-adjust: exact;
}

/* ── Screen toolbar ── */
.toolbar {
  background: var(--primary);
  color: #fff;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 24px;
}
.toolbar h5 { font-size: 14px; font-weight: 600; margin: 0; flex: 1; }
.toolbar button {
  border: none; border-radius: 6px; padding: 7px 18px;
  cursor: pointer; font-size: 12px; font-weight: 600;
  display: flex; align-items: center; gap: 6px;
}
.btn-print  { background: #fff; color: var(--primary); }
.btn-close2 { background: rgba(255,255,255,0.18); color: #fff; }

/* ── A4 page ── */
.page {
  width: 210mm;
  min-height: 297mm;
  margin: 20px auto;
  background: #fff;
  padding: 14mm 16mm 14mm;
  box-shadow: 0 4px 30px rgba(0,0,0,.18);
}

/* ── Header strip ── */
.inv-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  border-bottom: 3px solid var(--primary);
  padding-bottom: 12px;
  margin-bottom: 14px;
}
.inv-header-left .logo { max-height: 60px; max-width: 180px; object-fit: contain; margin-bottom: 6px; display: block; }
.inv-header-left .company-name { font-size: 20px; font-weight: 800; color: var(--primary); letter-spacing: 1px; text-transform: uppercase; }
.inv-header-left .company-sub  { font-size: 10px; color: #666; line-height: 1.6; margin-top: 2px; }
.inv-header-right { text-align: right; }
.inv-header-right .invoice-label { font-size: 22px; font-weight: 900; color: var(--accent); text-transform: uppercase; letter-spacing: 2px; }
.inv-header-right .invoice-no { font-size: 13px; font-weight: 700; color: var(--primary); margin-top: 4px; }
.inv-header-right .invoice-date { font-size: 10px; color: #666; margin-top: 2px; }

/* ── Meta row ── */
.meta-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
  margin-bottom: 16px;
}
.meta-box {
  background: #f5f7fb;
  border: 1px solid #e0e4ef;
  border-radius: 6px;
  padding: 10px 12px;
}
.meta-box h6 {
  font-size: 9px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.6px;
  color: var(--primary);
  margin-bottom: 6px;
  border-bottom: 1px solid #e0e4ef;
  padding-bottom: 4px;
}
.meta-box table { width: 100%; border-collapse: collapse; }
.meta-box table td { font-size: 10.5px; padding: 1.5px 0; color: #333; }
.meta-box table td:first-child { color: #777; width: 40%; }
.meta-box table td.bold { font-weight: 700; color: #1a1a1a; }

/* ── FEFO badge ── */
.fefo-badge {
  display: inline-block;
  background: #e8f5e9;
  color: #2e7d32;
  font-size: 8px;
  font-weight: 700;
  border-radius: 3px;
  padding: 1px 4px;
  letter-spacing: 0.5px;
  border: 1px solid #a5d6a7;
  margin-left: 4px;
}

/* ── Items table ── */
.items-table {
  width: 100%;
  border-collapse: collapse;
  margin-bottom: 14px;
}
.items-table thead tr {
  background: var(--primary);
  color: #fff;
}
.items-table thead th {
  padding: 8px 10px;
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.4px;
  white-space: nowrap;
}
.items-table thead th.text-right { text-align: right; }
.items-table thead th.text-center { text-align: center; }
.items-table tbody tr { border-bottom: 1px solid #eee; }
.items-table tbody tr:nth-child(even) { background: #fafbff; }
.items-table tbody tr.batch-row { background: #f3f0ff; }
.items-table tbody td { padding: 7px 10px; font-size: 11px; vertical-align: top; }
.items-table tbody td.text-right { text-align: right; }
.items-table tbody td.text-center { text-align: center; }
.items-table .item-name { font-weight: 700; color: #1a1a1a; }
.items-table .item-detail { font-size: 9.5px; color: #777; margin-top: 1px; }
.items-table .batch-arrow { color: #7c3aed; font-size: 10px; }
.items-table tfoot td { padding: 6px 10px; font-size: 11px; font-weight: 600; }

/* ── Totals block ── */
.totals-wrap {
  display: flex;
  justify-content: flex-end;
  margin-bottom: 18px;
}
.totals-box {
  width: 240px;
  border: 1px solid #e0e4ef;
  border-radius: 6px;
  overflow: hidden;
}
.totals-box table { width: 100%; border-collapse: collapse; }
.totals-box tr td { padding: 5px 10px; font-size: 11px; }
.totals-box tr td:last-child { text-align: right; font-weight: 600; }
.totals-box tr.subtotal-row { background: #f5f7fb; }
.totals-box tr.disc-row { color: #c0392b; }
.totals-box tr.tax-row  { color: #1a7431; }
.totals-box tr.grand-row {
  background: var(--primary); color: #fff;
  font-size: 13px; font-weight: 800;
}
.totals-box tr.paid-row  { background: #f5f7fb; color: #1a7431; }
.totals-box tr.due-row   { background: #fff3cd; color: #856404; font-weight: 700; }
.totals-box tr.change-row{ background: #d4edda; color: #155724; font-weight: 700; }

/* ── Credit alert ── */
.credit-alert {
  background: #fff3cd;
  border: 1.5px solid #ffc107;
  border-radius: 6px;
  padding: 8px 14px;
  margin-bottom: 14px;
  font-size: 11px;
  color: #856404;
  font-weight: 600;
}

/* ── Footer ── */
.inv-footer {
  border-top: 2px solid var(--primary);
  padding-top: 10px;
  text-align: center;
  font-size: 10px;
  color: #666;
}
.inv-footer .footer-main { font-size: 12px; font-weight: 600; color: #333; }
.inv-footer .terms { margin-top: 4px; font-size: 9.5px; color: #999; }
.inv-footer .powered { margin-top: 6px; font-size: 9px; color: #bbb; }

/* ── Signature area ── */
.sig-row {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  gap: 20px;
  margin: 20px 0 10px;
}
.sig-box { text-align: center; }
.sig-box .sig-line { border-top: 1px solid #999; margin-top: 28px; padding-top: 4px; font-size: 9.5px; color: #777; }

/* ── Payment status badge ── */
.pay-status {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 10px;
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}
.pay-status.paid    { background: #d4edda; color: #155724; }
.pay-status.partial { background: #fff3cd; color: #856404; }
.pay-status.pending { background: #f8d7da; color: #721c24; }
.pay-status.credit  { background: #cce5ff; color: #004085; }

@media print {
  body { background: #fff; }
  .toolbar { display: none !important; }
  .page { margin: 0; box-shadow: none; padding: 10mm 12mm; }
}
</style>
</head>
<body>

<!-- Screen toolbar (hidden on print) -->
<div class="toolbar">
  <h5><i style="margin-right:6px;">📄</i> Invoice <?= he25($sale['invoice_no']) ?></h5>
  <button class="btn-print" onclick="window.print()">🖨️ Print / Save PDF</button>
  <button class="btn-close2" onclick="window.close()">✕ Close</button>
</div>

<div class="page">

  <!-- Header -->
  <div class="inv-header">
    <div class="inv-header-left">
      <?php if ($logoUrl): ?>
        <img src="<?= he25($logoUrl) ?>" alt="Logo" class="logo">
      <?php endif; ?>
      <div class="company-name"><?= he25($pharmName) ?></div>
      <?php if ($showTagline && $pharmTagline): ?>
        <div class="company-sub" style="font-style:italic;color:var(--accent)"><?= he25($pharmTagline) ?></div>
      <?php endif; ?>
      <div class="company-sub">
        <?php if ($pharmAddr):  ?><?= he25($pharmAddr) ?><br><?php endif; ?>
        <?php if ($pharmPhone): ?>Tel: <?= he25($pharmPhone) ?><?php if ($pharmEmail): ?> | <?php endif; ?><?php endif; ?>
        <?php if ($pharmEmail): ?>Email: <?= he25($pharmEmail) ?><?php endif; ?>
        <?php if ($pharmPhone || $pharmEmail): ?><br><?php endif; ?>
        <?php if ($pharmGST):   ?>GST No: <?= he25($pharmGST) ?><?php endif; ?>
      </div>
    </div>
    <div class="inv-header-right">
      <div class="invoice-label">Invoice</div>
      <div class="invoice-no"># <?= he25($sale['invoice_no']) ?></div>
      <div class="invoice-date"><?= he25($saleDate) ?></div>
      <?php
        $pStatus = $sale['payment_status'] ?? ($isCredit ? 'credit' : 'paid');
        $statusCls = in_array($pStatus, ['paid','partial','pending','credit']) ? $pStatus : 'paid';
      ?>
      <div style="margin-top:6px;">
        <span class="pay-status <?= $statusCls ?>"><?= strtoupper($statusCls) ?></span>
      </div>
    </div>
  </div><!-- /inv-header -->

  <!-- Meta boxes -->
  <div class="meta-row">
    <!-- Bill To -->
    <div class="meta-box">
      <h6>Bill To</h6>
      <table>
        <tr><td>Name</td><td class="bold"><?= he25($sale['customer_name'] ?? 'Walk-in Customer') ?></td></tr>
        <?php if (!empty($sale['customer_phone'])): ?>
        <tr><td>Phone</td><td><?= he25($sale['customer_phone']) ?></td></tr>
        <?php endif; ?>
        <?php if ($isCredit && $dueDate): ?>
        <tr><td>Due Date</td><td class="bold" style="color:#c0392b"><?= he25($dueDate) ?></td></tr>
        <?php endif; ?>
      </table>
    </div>
    <!-- Payment Info -->
    <div class="meta-box">
      <h6>Payment Details</h6>
      <table>
        <tr><td>Invoice No</td><td class="bold"><?= he25($sale['invoice_no']) ?></td></tr>
        <tr><td>Date</td><td><?= he25($saleDate) ?></td></tr>
        <?php if ($showPayment): ?>
        <tr><td>Method</td><td><?= he25($payLabel) ?></td></tr>
        <?php endif; ?>
        <tr><td>Status</td><td><span class="pay-status <?= $statusCls ?>"><?= strtoupper($statusCls) ?></span></td></tr>
      </table>
    </div>
  </div>

  <!-- Credit warning -->
  <?php if ($isCredit && $balance > 0): ?>
  <div class="credit-alert">
    ⚠️ <strong>Credit Sale</strong> — Balance due:
    <strong><?= he25($currency) ?> <?= number_format($balance, 2) ?></strong>
    <?php if ($dueDate): ?> by <strong><?= he25($dueDate) ?></strong><?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Items table -->
  <table class="items-table">
    <thead>
      <tr>
        <th style="width:26px">#</th>
        <th>Item Description</th>
        <?php if ($showBatch): ?>
        <th class="text-center" style="width:80px">Batch</th>
        <th class="text-center" style="width:70px">Expiry</th>
        <?php endif; ?>
        <th class="text-center" style="width:46px">Qty</th>
        <th class="text-right" style="width:70px">Unit Price</th>
        <th class="text-right" style="width:52px">Disc%</th>
        <th class="text-right" style="width:78px">Amount</th>
      </tr>
    </thead>
    <tbody>
    <?php $srNo = 0; foreach ($groupedItems as $group):
        $srNo++;
        $isMed = ((int)$group['category_id'] === 1);
        $batches = $group['batches'];
        $batchCount = count($batches);
        foreach ($batches as $bIdx => $b):
            $bNum    = $b['batch_number'] ?? $b['b_batch_number'] ?? '';
            $bExp    = $b['expiry_date']  ?? $b['b_expiry_date']  ?? '';
            $bExpFmt = $bExp ? fmtDate25($bExp, 'd/m/Y') : '—';
            $bQty    = (int)($b['quantity'] ?? 0);
            $bPrice  = (float)($b['unit_price'] ?? 0);
            $bDisc   = (float)($b['item_discount_pct'] ?? 0);
            $bTotal  = (float)($b['total'] ?? 0);
            $isFirst = ($bIdx === 0);
            $isCont  = !$isFirst;
    ?>
      <tr class="<?= $isCont ? 'batch-row' : '' ?>">
        <td class="text-center" style="color:#aaa;"><?= $isFirst ? $srNo : '' ?></td>
        <td>
          <?php if ($isFirst): ?>
            <div class="item-name">
              <?= he25($group['product_name']) ?>
              <?php if ($batchCount > 1): ?>
                <span class="fefo-badge">FEFO ×<?= $batchCount ?></span>
              <?php endif; ?>
            </div>
            <div class="item-detail">
              <?php if ($isMed):
                $parts = array_filter([$group['dosage_form'], $group['strength']]);
                echo he25($group['generic_name']);
                if ($parts) echo ' · ' . he25(implode(' ', $parts));
              else:
                if ($group['brand_name']) echo he25($group['brand_name']);
                if ($group['size_value']) echo ' · ' . he25($group['size_value']);
              endif; ?>
            </div>
          <?php else: ?>
            <span class="batch-arrow">↳</span>
            <span style="font-size:10px;color:#7c3aed;">Batch continuation</span>
          <?php endif; ?>
        </td>
        <?php if ($showBatch): ?>
        <td class="text-center" style="font-size:10px;color:#555"><?= he25($bNum ?: '—') ?></td>
        <td class="text-center" style="font-size:10px;color:#555"><?= he25($bExpFmt) ?></td>
        <?php endif; ?>
        <td class="text-center"><?= $bQty ?></td>
        <td class="text-right"><?= he25($currency) ?> <?= number_format($bPrice, 2) ?></td>
        <td class="text-right"><?= $bDisc > 0 ? number_format($bDisc, 1) . '%' : '—' ?></td>
        <td class="text-right"><strong><?= he25($currency) ?> <?= number_format($bTotal, 2) ?></strong></td>
      </tr>
    <?php endforeach; endforeach; ?>
    </tbody>
  </table>

  <!-- Totals -->
  <div class="totals-wrap">
    <div class="totals-box">
      <table>
        <?php if ($discAmt > 0 || $taxAmt > 0): ?>
        <tr class="subtotal-row">
          <td>Sub-Total</td>
          <td><?= he25($currency) ?> <?= number_format($subtotal > 0 ? $subtotal : $grandTotal + $discAmt - $taxAmt, 2) ?></td>
        </tr>
        <?php endif; ?>
        <?php if ($discAmt > 0): ?>
        <tr class="disc-row">
          <td>Discount<?= $discPct > 0 ? ' (' . number_format($discPct, 1) . '%)' : '' ?></td>
          <td>− <?= he25($currency) ?> <?= number_format($discAmt, 2) ?></td>
        </tr>
        <?php endif; ?>
        <?php if ($taxAmt > 0): ?>
        <tr class="tax-row">
          <td>Tax<?= $taxPct > 0 ? ' (' . number_format($taxPct, 1) . '%)' : '' ?></td>
          <td>+ <?= he25($currency) ?> <?= number_format($taxAmt, 2) ?></td>
        </tr>
        <?php endif; ?>
        <tr class="grand-row">
          <td>GRAND TOTAL</td>
          <td><?= he25($currency) ?> <?= number_format($grandTotal, 2) ?></td>
        </tr>
        <tr class="paid-row">
          <td>Amount Paid</td>
          <td><?= he25($currency) ?> <?= number_format($paidAmt, 2) ?></td>
        </tr>
        <?php if ($balance > 0.005): ?>
        <tr class="due-row">
          <td>Balance Due</td>
          <td><?= he25($currency) ?> <?= number_format($balance, 2) ?></td>
        </tr>
        <?php elseif ($balance < -0.005): ?>
        <tr class="change-row">
          <td>Change</td>
          <td><?= he25($currency) ?> <?= number_format(abs($balance), 2) ?></td>
        </tr>
        <?php endif; ?>
      </table>
    </div>
  </div>

  <!-- Signature row -->
  <div class="sig-row">
    <div class="sig-box"><div class="sig-line">Prepared By</div></div>
    <div class="sig-box"><div class="sig-line">Checked By</div></div>
    <div class="sig-box"><div class="sig-line">Customer Signature</div></div>
  </div>

  <!-- Footer -->
  <div class="inv-footer">
    <div class="footer-main"><?= he25($footerText) ?></div>
    <?php if ($invoiceTerms): ?>
    <div class="terms"><?= he25($invoiceTerms) ?></div>
    <?php endif; ?>
    <div class="powered">★ Generated by Pharmacy POS System ★ &nbsp;|&nbsp; <?= he25($pharmName) ?></div>
  </div>

</div><!-- /page -->

<script>
<?php if ($inline == 0): ?>
// Auto-trigger print/save dialog for download mode
window.addEventListener('load', function() {
    setTimeout(function(){ window.print(); }, 500);
});
<?php endif; ?>
</script>
</body>
</html>
