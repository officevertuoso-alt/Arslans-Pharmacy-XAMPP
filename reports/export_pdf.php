<?php
/**
 * reports/export_pdf.php
 * Phase 22 – PDF Export for all Reports
 * Generates a styled, printable HTML page → browser triggers PDF save via print dialog.
 * Supports: sales, purchases, profit_loss, stock, expiry, cashbook
 */
require_once '../config/config.php';
require_once '../includes/report_functions.php';
require_once '../includes/sync_engine.php';

requireLogin();

if (!canDo('reports', 'view')) {
    http_response_code(403);
    exit('Access denied.');
}

// ── Params ──────────────────────────────────────────────────────
$report_type    = $_GET['report']         ?? 'sales';
$from_date      = $_GET['from_date']      ?? date('Y-m-01');
$to_date        = $_GET['to_date']        ?? date('Y-m-d');
$payment_method = $_GET['payment_method'] ?? '';
$payment_status = $_GET['payment_status'] ?? '';
$supplier_id    = (int)($_GET['supplier_id'] ?? 0);
$search         = trim($_GET['search']    ?? '');
$category       = $_GET['category']      ?? '';
$filter         = $_GET['filter']        ?? 'all';

if (!strtotime($from_date)) $from_date = date('Y-m-01');
if (!strtotime($to_date))   $to_date   = date('Y-m-d');

// ── Company info ─────────────────────────────────────────────────
$cs = function_exists('getCompanySettings') ? getCompanySettings() : [];
$companyName    = $cs['company_name'] ?? $cs['pharmacy_name'] ?? 'Pharmacy';
$companyAddress = $cs['address'] ?? '';
$companyPhone   = $cs['phone']   ?? '';
$primaryColor   = preg_match('/^#[0-9a-fA-F]{6}$/', $cs['primary_color'] ?? '') ? $cs['primary_color'] : '#0d6efd';
$currency       = $cs['currency_symbol'] ?? 'Rs';

if (!function_exists('fc')) {
    function fc($a) {
        global $currency;
        return $currency . ' ' . number_format((float)$a, 2);
    }
}
if (!function_exists('fd'))  { function fd($d)  { return $d ? date('d-m-Y', strtotime($d)) : ''; } }
if (!function_exists('fdt')) { function fdt($d) { return $d ? date('d-m-Y h:i A', strtotime($d)) : ''; } }

$db = db();

// ── Data fetch ───────────────────────────────────────────────────
$reportTitle = 'Report';
$rows        = [];
$summary     = [];
$columns     = [];

switch ($report_type) {

    // ── SALES ────────────────────────────────────────────────────
    case 'sales':
        $reportTitle = 'Sales Report';
        $where  = "DATE(s.sale_date) BETWEEN :from AND :to AND s.status != 'voided'";
        $params = [':from' => $from_date, ':to' => $to_date];
        if ($payment_method) { $where .= " AND s.payment_method = :pm"; $params[':pm'] = $payment_method; }
        if ($payment_status) { $where .= " AND s.payment_status = :ps"; $params[':ps'] = $payment_status; }
        $rows = $db->getAll(
            "SELECT s.invoice_no, s.sale_date, s.customer_name, s.total,
                    COALESCE(SUM(sr.total_amount),0) AS returned,
                    (s.total - COALESCE(SUM(sr.total_amount),0)) AS net,
                    s.paid_amount, s.payment_method, s.payment_status
             FROM sales s
             LEFT JOIN sale_returns sr ON sr.sale_id = s.id
             WHERE $where
             GROUP BY s.id ORDER BY s.sale_date DESC, s.id DESC", $params);
        $totals = $db->getOne("SELECT COALESCE(SUM(s.total),0) AS gross,
            COALESCE(SUM(COALESCE(sr.total_amount,0)),0) AS returns
            FROM sales s LEFT JOIN sale_returns sr ON sr.sale_id = s.id WHERE $where", $params);
        $gross = (float)($totals['gross'] ?? 0);
        $ret   = (float)($totals['returns'] ?? 0);
        $summary = [
            'Gross Sales'   => fc($gross),
            'Returns'       => fc($ret),
            'Net Sales'     => fc($gross - $ret),
            'Period'        => fd($from_date) . ' – ' . fd($to_date),
            'Total Records' => count($rows),
        ];
        $columns = ['#','Invoice','Date','Customer','Gross','Returns','Net','Paid','Method','Status'];
        break;

    // ── PURCHASES ────────────────────────────────────────────────
    case 'purchases':
    case 'purchase':
        $reportTitle = 'Purchase Report';
        $where  = "DATE(p.purchase_date) BETWEEN :from AND :to";
        $params = [':from' => $from_date, ':to' => $to_date];
        if ($supplier_id > 0) { $where .= " AND p.supplier_id = :sid"; $params[':sid'] = $supplier_id; }
        if ($payment_status)  { $where .= " AND p.payment_status = :ps"; $params[':ps'] = $payment_status; }
        $rows = $db->getAll(
            "SELECT p.purchase_invoice_no, p.purchase_date, s.supplier_name,
                    p.total, p.paid_amount, p.due_amount, p.payment_status,
                    COALESCE(SUM(pr.total_amount),0) AS returned
             FROM purchases p
             JOIN suppliers s ON s.id = p.supplier_id
             LEFT JOIN purchase_returns pr ON pr.purchase_id = p.id
             WHERE $where GROUP BY p.id ORDER BY p.purchase_date DESC", $params);
        $tot = $db->getOne("SELECT COALESCE(SUM(p.total),0) AS total,
            COALESCE(SUM(p.paid_amount),0) AS paid, COALESCE(SUM(p.due_amount),0) AS due
            FROM purchases p WHERE $where", $params);
        $summary = [
            'Total Purchases' => fc($tot['total'] ?? 0),
            'Total Paid'      => fc($tot['paid'] ?? 0),
            'Total Due'       => fc($tot['due'] ?? 0),
            'Period'          => fd($from_date) . ' – ' . fd($to_date),
            'Total Records'   => count($rows),
        ];
        $columns = ['#','Invoice','Date','Supplier','Total','Paid','Due','Returns','Status'];
        break;

    // ── PROFIT & LOSS ────────────────────────────────────────────
    case 'profit_loss':
        $reportTitle = 'Profit & Loss Report';
        $params = [':from' => $from_date, ':to' => $to_date];
        $gross_revenue = (float)$db->getOne("SELECT COALESCE(SUM(si.quantity*si.unit_price),0) AS t FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE DATE(s.sale_date) BETWEEN :from AND :to AND s.status!='voided'", $params)['t'];
        $sale_returns  = (float)$db->getOne("SELECT COALESCE(SUM(total_amount),0) AS t FROM sale_returns WHERE DATE(created_at) BETWEEN :from AND :to", $params)['t'];
        $cogs          = (float)$db->getOne("SELECT COALESCE(SUM(si.quantity*b.purchase_price),0) AS t FROM sale_items si JOIN batches b ON b.id=si.batch_id JOIN sales s ON s.id=si.sale_id WHERE DATE(s.sale_date) BETWEEN :from AND :to AND s.status!='voided'", $params)['t'];
        $expenses      = (float)$db->getOne("SELECT COALESCE(SUM(amount),0) AS t FROM expenses WHERE DATE(expense_date) BETWEEN :from AND :to", $params)['t'];
        $net_revenue   = $gross_revenue - $sale_returns;
        $gross_profit  = $net_revenue - $cogs;
        $net_profit    = $gross_profit - $expenses;
        $margin        = $net_revenue > 0 ? round(($gross_profit/$net_revenue)*100,1) : 0;
        $rows = $db->getAll(
            "SELECT DATE(s.sale_date) AS day,
                    COALESCE(SUM(si.quantity*si.unit_price),0) AS revenue,
                    COALESCE(SUM(si.quantity*b.purchase_price),0) AS cogs,
                    COALESCE(SUM(si.quantity*si.unit_price),0)-COALESCE(SUM(si.quantity*b.purchase_price),0) AS gprofit,
                    (SELECT COALESCE(SUM(amount),0) FROM expenses WHERE DATE(expense_date)=DATE(s.sale_date)) AS exp
             FROM sales s LEFT JOIN sale_items si ON si.sale_id=s.id LEFT JOIN batches b ON b.id=si.batch_id
             WHERE DATE(s.sale_date) BETWEEN :from AND :to AND s.status!='voided'
             GROUP BY day ORDER BY day ASC", $params);
        $summary = [
            'Gross Revenue' => fc($gross_revenue),
            'Sale Returns'  => fc($sale_returns),
            'Net Revenue'   => fc($net_revenue),
            'COGS'          => fc($cogs),
            'Gross Profit'  => fc($gross_profit),
            'Expenses'      => fc($expenses),
            'Net Profit'    => fc($net_profit),
            'Margin'        => $margin . '%',
            'Period'        => fd($from_date) . ' – ' . fd($to_date),
        ];
        $columns = ['Date','Revenue','COGS','Gross Profit','Expenses','Net Profit'];
        break;

    // ── STOCK ────────────────────────────────────────────────────
    case 'stock':
        $reportTitle = 'Stock Report';
        $products = getInStockProductsReport($search, $category);
        $totalQty = $totalVal = 0;
        foreach ($products as $p) {
            $totalQty += $p['current_stock'];
            $totalVal += $p['current_stock'] * $p['purchase_price'];
        }
        $rows = $products;
        $summary = [
            'Products In Stock' => count($products),
            'Total Quantity'    => number_format($totalQty),
            'Total Stock Value' => fc($totalVal),
        ];
        $columns = ['#','Code','Product','Brand','Strength','Qty','Purchase Price','Stock Value'];
        break;

    // ── EXPIRY ───────────────────────────────────────────────────
    case 'expiry':
        $reportTitle = 'Expiry Report';
        $sql = "SELECT b.batch_number, p.product_name, p.brand_name, p.strength, p.strength_unit,
                       b.remaining_quantity, b.purchase_price, b.expiry_date,
                       DATEDIFF(CURDATE(), b.expiry_date) AS days_expired,
                       s.supplier_name
                FROM batches b JOIN products p ON p.id=b.product_id
                LEFT JOIN suppliers s ON s.id=b.supplier_id
                WHERE b.expiry_date IS NOT NULL AND b.expiry_date <= CURDATE()";
        if ($filter === 'current') $sql .= " AND b.remaining_quantity > 0";
        $ep = [];
        if ($search) { $sql .= " AND (p.product_name LIKE :s OR b.batch_number LIKE :s)"; $ep[':s'] = "%$search%"; }
        $sql .= " ORDER BY b.expiry_date ASC";
        $rows = $db->getAll($sql, $ep);
        $tq = $tv = 0;
        foreach ($rows as $r) { $tq += $r['remaining_quantity']; $tv += $r['remaining_quantity'] * $r['purchase_price']; }
        $summary = [
            'Expired Batches' => count($rows),
            'Total Quantity'  => number_format($tq),
            'Loss Value'      => fc($tv),
        ];
        $columns = ['#','Batch','Product','Brand','Strength','Qty','Purchase Price','Expiry Date','Days Expired','Supplier'];
        break;

    // ── CASHBOOK ────────────────────────────────────────────────
    case 'cashbook':
        $reportTitle = 'Cashbook Report';
        require_once dirname(__DIR__) . '/includes/cashbook_functions.php';
        if (function_exists('getCashbookReport')) {
            $rows = getCashbookReport($from_date, $to_date);
        } else {
            $rows = $db->getAll(
                "SELECT DATE(entry_date) AS date,
                        COALESCE(SUM(credit),0) AS total_in,
                        COALESCE(SUM(debit),0) AS total_out
                 FROM cashbook WHERE entry_date BETWEEN :from AND :to AND voided=0
                 GROUP BY DATE(entry_date) ORDER BY date ASC",
                [':from' => $from_date, ':to' => $to_date]
            );
        }
        $totalIn  = array_sum(array_column($rows, 'total_in'));
        $totalOut = array_sum(array_column($rows, 'total_out'));
        $summary = [
            'Total Cash In'  => fc($totalIn),
            'Total Cash Out' => fc($totalOut),
            'Net Flow'       => fc($totalIn - $totalOut),
            'Period'         => fd($from_date) . ' – ' . fd($to_date),
        ];
        $columns = ['Date','Opening Balance','Cash In','Cash Out','Closing Balance','Net Flow'];
        break;
}

$periodLabel = in_array($report_type, ['stock','expiry']) ? date('d-m-Y H:i', strtotime('now')) : fd($from_date) . ' – ' . fd($to_date);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($reportTitle) ?> – PDF</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 12px; color: #212529; background: #fff; }

/* ── Print header ── */
.pdf-header { border-bottom: 3px solid <?= $primaryColor ?>; padding-bottom: 10px; margin-bottom: 14px; }
.pdf-header .company-name { font-size: 20px; font-weight: 800; color: <?= $primaryColor ?>; }
.pdf-header .company-meta { font-size: 11px; color: #6c757d; }
.pdf-header .report-title { font-size: 15px; font-weight: 700; color: #212529; }
.pdf-header .period       { font-size: 11px; color: #6c757d; }

/* ── Summary band ── */
.summary-band { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
.summary-pill {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 8px 14px;
    min-width: 120px;
}
.summary-pill .s-label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: #6c757d; }
.summary-pill .s-value { font-size: 14px; font-weight: 700; color: #212529; }

/* ── Table ── */
table { width: 100%; border-collapse: collapse; font-size: 11px; }
thead th {
    background: <?= $primaryColor ?>;
    color: #fff;
    padding: 7px 8px;
    text-align: left;
    font-weight: 600;
    white-space: nowrap;
}
tbody tr:nth-child(even) { background: #f8f9fa; }
tbody td { padding: 6px 8px; border-bottom: 1px solid #e9ecef; vertical-align: middle; }
tfoot td {
    background: #212529;
    color: #fff;
    font-weight: 700;
    padding: 7px 8px;
}
.text-end { text-align: right; }
.text-center { text-align: center; }
.badge-success { background: #198754; color: #fff; border-radius: 4px; padding: 2px 6px; }
.badge-danger  { background: #dc3545; color: #fff; border-radius: 4px; padding: 2px 6px; }
.badge-warning { background: #ffc107; color: #212529; border-radius: 4px; padding: 2px 6px; }
.badge-info    { background: #0dcaf0; color: #fff; border-radius: 4px; padding: 2px 6px; }
.profit-row { background: #d1e7dd !important; }
.loss-row   { background: #f8d7da !important; }

/* ── Footer ── */
.pdf-footer { margin-top: 14px; border-top: 1px solid #dee2e6; padding-top: 8px; display: flex; justify-content: space-between; font-size: 10px; color: #adb5bd; }

/* ── Print button ── */
.print-btn {
    position: fixed; top: 16px; right: 16px;
    background: <?= $primaryColor ?>; color: #fff;
    border: none; border-radius: 8px;
    padding: 10px 20px; font-size: 14px; font-weight: 700;
    cursor: pointer; box-shadow: 0 4px 16px rgba(0,0,0,0.2);
    transition: transform 0.15s;
    z-index: 9999;
}
.print-btn:hover { transform: translateY(-2px); }

@media print {
    .print-btn { display: none !important; }
    body { font-size: 11px; }
    @page { margin: 12mm 10mm; size: A4; }
}
</style>
</head>
<body>

<button class="print-btn" onclick="window.print()">
    <i>⎙</i> Save / Print PDF
</button>

<!-- Header -->
<div class="pdf-header">
    <table style="width:100%;border:none;">
        <tr>
            <td>
                <div class="company-name"><?= htmlspecialchars($companyName) ?></div>
                <?php if ($companyAddress): ?><div class="company-meta"><?= htmlspecialchars($companyAddress) ?></div><?php endif; ?>
                <?php if ($companyPhone): ?><div class="company-meta">Ph: <?= htmlspecialchars($companyPhone) ?></div><?php endif; ?>
            </td>
            <td style="text-align:right;">
                <div class="report-title"><?= htmlspecialchars($reportTitle) ?></div>
                <div class="period"><?= htmlspecialchars($periodLabel) ?></div>
                <div class="company-meta" style="margin-top:4px;">Generated: <?= date('d-m-Y h:i A') ?></div>
            </td>
        </tr>
    </table>
</div>

<!-- Summary Pills -->
<?php if ($summary): ?>
<div class="summary-band">
    <?php foreach ($summary as $label => $val): ?>
    <div class="summary-pill">
        <div class="s-label"><?= htmlspecialchars($label) ?></div>
        <div class="s-value"><?= htmlspecialchars($val) ?></div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Report Table -->
<table>
    <thead>
        <tr>
            <?php foreach ($columns as $col): ?>
            <th><?= htmlspecialchars($col) ?></th>
            <?php endforeach; ?>
        </tr>
    </thead>
    <tbody>
    <?php
    $sn = 1;
    if (empty($rows)):
        $colspan = count($columns);
        echo "<tr><td colspan=\"$colspan\" class=\"text-center\" style=\"padding:20px;color:#adb5bd;\">No records found</td></tr>";
    else:

    switch ($report_type):

        case 'sales':
            foreach ($rows as $r):
                $method = strtolower($r['payment_method'] ?? '');
                $bclass = match($r['payment_status'] ?? '') { 'paid' => 'badge-success', 'partial' => 'badge-warning', default => 'badge-danger' };
                echo "<tr>";
                echo "<td class=\"text-center\">{$sn}</td>";
                echo "<td><strong>".htmlspecialchars($r['invoice_no'])."</strong></td>";
                echo "<td>".fd($r['sale_date'])."</td>";
                echo "<td>".htmlspecialchars($r['customer_name'] ?? 'Walk-in')."</td>";
                echo "<td class=\"text-end\">".fc($r['total'])."</td>";
                echo "<td class=\"text-end\" style=\"color:#dc3545;\">".fc($r['returned'])."</td>";
                echo "<td class=\"text-end\"><strong>".fc($r['net'])."</strong></td>";
                echo "<td class=\"text-end\">".fc($r['paid_amount'])."</td>";
                echo "<td><span style=\"text-transform:capitalize;\">".htmlspecialchars($method)."</span></td>";
                echo "<td><span class=\"{$bclass}\">".ucfirst($r['payment_status'])."</span></td>";
                echo "</tr>";
                $sn++;
            endforeach;
            break;

        case 'purchases':
        case 'purchase':
            foreach ($rows as $r):
                $bclass = match($r['payment_status'] ?? '') { 'paid' => 'badge-success', 'partial' => 'badge-warning', default => 'badge-danger' };
                echo "<tr>";
                echo "<td class=\"text-center\">{$sn}</td>";
                echo "<td><strong>".htmlspecialchars($r['purchase_invoice_no'])."</strong></td>";
                echo "<td>".fd($r['purchase_date'])."</td>";
                echo "<td>".htmlspecialchars($r['supplier_name'])."</td>";
                echo "<td class=\"text-end\">".fc($r['total'])."</td>";
                echo "<td class=\"text-end\" style=\"color:#198754;\">".fc($r['paid_amount'])."</td>";
                echo "<td class=\"text-end\" style=\"color:#dc3545;\">".fc($r['due_amount'])."</td>";
                echo "<td class=\"text-end\" style=\"color:#ffc107;\">".fc($r['returned'])."</td>";
                echo "<td><span class=\"{$bclass}\">".ucfirst($r['payment_status'])."</span></td>";
                echo "</tr>";
                $sn++;
            endforeach;
            break;

        case 'profit_loss':
            $trev = $tcogs = $tgp = $texp = $tnp = 0;
            foreach ($rows as $r):
                $exp  = (float)($r['exp'] ?? 0);
                $np   = (float)$r['gprofit'] - $exp;
                $trev  += (float)$r['revenue'];
                $tcogs += (float)$r['cogs'];
                $tgp   += (float)$r['gprofit'];
                $texp  += $exp;
                $tnp   += $np;
                $rowClass = $np >= 0 ? 'profit-row' : 'loss-row';
                echo "<tr class=\"{$rowClass}\">";
                echo "<td>".fd($r['day'])."</td>";
                echo "<td class=\"text-end\">".fc($r['revenue'])."</td>";
                echo "<td class=\"text-end\">".fc($r['cogs'])."</td>";
                echo "<td class=\"text-end\"><strong>".fc($r['gprofit'])."</strong></td>";
                echo "<td class=\"text-end\" style=\"color:#dc3545;\">".fc($exp)."</td>";
                echo "<td class=\"text-end\"><strong>".($np >= 0 ? '<span style="color:#198754;">' : '<span style="color:#dc3545;">')."".fc($np)."</span></strong></td>";
                echo "</tr>";
            endforeach;
            echo "<tfoot><tr>";
            echo "<td><strong>TOTAL</strong></td>";
            echo "<td class=\"text-end\">".fc($trev)."</td>";
            echo "<td class=\"text-end\">".fc($tcogs)."</td>";
            echo "<td class=\"text-end\">".fc($tgp)."</td>";
            echo "<td class=\"text-end\">".fc($texp)."</td>";
            echo "<td class=\"text-end\">".fc($tnp)."</td>";
            echo "</tr></tfoot>";
            break;

        case 'stock':
            $tq = $tv = 0;
            foreach ($rows as $r):
                $qty = (int)$r['current_stock'];
                $pp  = (float)$r['purchase_price'];
                $sv  = $qty * $pp;
                $tq += $qty; $tv += $sv;
                $strength = trim(($r['strength'] ?? '') . ' ' . ($r['strength_unit'] ?? ''));
                echo "<tr>";
                echo "<td class=\"text-center\">{$sn}</td>";
                echo "<td>".htmlspecialchars($r['product_code'] ?? '-')."</td>";
                echo "<td><strong>".htmlspecialchars($r['product_name'])."</strong></td>";
                echo "<td>".htmlspecialchars($r['brand_name'] ?? '')."</td>";
                echo "<td>".htmlspecialchars($strength ?: '-')."</td>";
                echo "<td class=\"text-end\"><strong>".number_format($qty)."</strong></td>";
                echo "<td class=\"text-end\">".fc($pp)."</td>";
                echo "<td class=\"text-end\"><strong>".fc($sv)."</strong></td>";
                echo "</tr>";
                $sn++;
            endforeach;
            echo "<tfoot><tr>";
            echo "<td colspan=\"5\"><strong>TOTAL</strong></td>";
            echo "<td class=\"text-end\"><strong>".number_format($tq)."</strong></td>";
            echo "<td></td>";
            echo "<td class=\"text-end\"><strong>".fc($tv)."</strong></td>";
            echo "</tr></tfoot>";
            break;

        case 'expiry':
            $tq = $tv = 0;
            foreach ($rows as $r):
                $qty = (int)$r['remaining_quantity'];
                $pp  = (float)$r['purchase_price'];
                $sv  = $qty * $pp;
                $tq += $qty; $tv += $sv;
                $de  = (int)$r['days_expired'];
                $bclass = $de > 90 ? 'badge-danger' : ($de > 30 ? 'badge-warning' : 'badge-info');
                $strength = trim(($r['strength'] ?? '') . ' ' . ($r['strength_unit'] ?? ''));
                echo "<tr>";
                echo "<td class=\"text-center\">{$sn}</td>";
                echo "<td>".htmlspecialchars($r['batch_number'])."</td>";
                echo "<td><strong>".htmlspecialchars($r['product_name'])."</strong></td>";
                echo "<td>".htmlspecialchars($r['brand_name'] ?? '')."</td>";
                echo "<td>".htmlspecialchars($strength ?: '-')."</td>";
                echo "<td class=\"text-end\">".number_format($qty)."</td>";
                echo "<td class=\"text-end\">".fc($pp)."</td>";
                echo "<td>".fd($r['expiry_date'])."</td>";
                echo "<td><span class=\"{$bclass}\">{$de}d</span></td>";
                echo "<td>".htmlspecialchars($r['supplier_name'] ?? '')."</td>";
                echo "</tr>";
                $sn++;
            endforeach;
            echo "<tfoot><tr>";
            echo "<td colspan=\"5\"><strong>TOTAL LOSS VALUE</strong></td>";
            echo "<td class=\"text-end\"><strong>".number_format($tq)."</strong></td>";
            echo "<td class=\"text-end\" colspan=\"2\"><strong>".fc($tv)."</strong></td>";
            echo "<td colspan=\"2\"></td>";
            echo "</tr></tfoot>";
            break;

        case 'cashbook':
            foreach ($rows as $r):
                $nf = (float)($r['net_flow'] ?? ((float)$r['total_in'] - (float)$r['total_out']));
                $rowClass = $nf >= 0 ? 'profit-row' : 'loss-row';
                echo "<tr class=\"{$rowClass}\">";
                echo "<td>".fd($r['date'])."</td>";
                echo "<td class=\"text-end\">".fc($r['opening'] ?? 0)."</td>";
                echo "<td class=\"text-end\" style=\"color:#198754;\"><strong>".fc($r['total_in'])."</strong></td>";
                echo "<td class=\"text-end\" style=\"color:#dc3545;\"><strong>".fc($r['total_out'])."</strong></td>";
                echo "<td class=\"text-end\"><strong>".fc($r['closing'] ?? 0)."</strong></td>";
                echo "<td class=\"text-end\">".($nf >= 0 ? '<span style="color:#198754;">' : '<span style="color:#dc3545;">')."".fc($nf)."</span></td>";
                echo "</tr>";
            endforeach;
            break;

    endswitch;
    endif;
    ?>
    </tbody>
</table>

<!-- Footer -->
<div class="pdf-footer">
    <span><?= htmlspecialchars($companyName) ?> | <?= htmlspecialchars($reportTitle) ?></span>
    <span>Generated on <?= date('d-m-Y h:i A') ?> | Page 1</span>
</div>

<script>
// Auto-open print dialog after short delay so styles render
window.addEventListener('load', function () {
    setTimeout(function () { window.print(); }, 600);
});
</script>
</body>
</html>
