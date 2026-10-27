<?php
/**
 * ajax/preview_invoice.php
 * ============================================================
 * COMPLETE PROFESSIONAL INVOICE PREVIEW
 * Combines:
 *  - Professional A4-style wide layout (600px, Arial)
 *  - Thermal receipt style (monospace, dashed borders)
 *  - Per-item discount/tax notes
 *  - Overall discount & tax with color coding
 *  - Credit/due date support
 *  - Balance (Due/Change) logic
 *  - Full sale_data payload for process_sale.php
 * ============================================================
 */
session_start();
require_once '../config/config.php';
require_once '../includes/sale_functions.php';

requireLogin();

header('Content-Type: application/json');

// ── Invoice number generator ──────────────────────────────
if (!function_exists('generateInvoiceNo')) {
    function generateInvoiceNo() {
        return 'INV-' . date('Ymd') . '-' . strtoupper(uniqid());
    }
}

// ── Input parsing ─────────────────────────────────────────
$cartData       = isset($_POST['cart'])     ? json_decode($_POST['cart'],     true) : [];
$customerData   = isset($_POST['customer']) ? json_decode($_POST['customer'], true) : [];
$paymentMethod  = $_POST['payment_method']        ?? 'cash';
$overallDiscPct = (float)($_POST['overall_discount_pct'] ?? 0);
$overallTaxPct  = (float)($_POST['overall_tax_pct']      ?? 0);
$paidAmount     = (float)($_POST['paid_amount']           ?? 0);

if (empty($cartData)) {
    echo json_encode(['success' => false, 'message' => 'No cart data received']);
    exit();
}

// ── Build cart with per-item calculations ─────────────────
$cart     = [];
$subtotal = 0;

foreach ($cartData as $item) {
    $index    = isset($item['index'])         ? (int)$item['index']          : -1;
    $quantity = isset($item['quantity'])      ? (int)$item['quantity']        : 1;
    $itemDisc = isset($item['item_discount']) ? (float)$item['item_discount'] : 0;
    $itemTax  = isset($item['item_tax'])      ? (float)$item['item_tax']      : 0;

    if ($index >= 0 && isset($_SESSION['pos_cart'][$index])) {
        $ci                  = $_SESSION['pos_cart'][$index];
        $ci['quantity']      = $quantity;
        $ci['item_discount'] = $itemDisc;
        $ci['item_tax']      = $itemTax;

        $base              = $quantity * (float)$ci['price'];
        $discAmt           = $base * $itemDisc / 100;
        $afterD            = $base - $discAmt;
        $taxAmt            = $afterD * $itemTax / 100;
        $ci['line_base']   = $base;
        $ci['line_disc']   = $discAmt;
        $ci['line_tax']    = $taxAmt;
        $ci['line_total']  = $afterD + $taxAmt;

        $cart[]    = $ci;
        $subtotal += $ci['line_total'];
    }
}

if (empty($cart)) {
    echo json_encode(['success' => false, 'message' => 'Cart is empty or session expired']);
    exit();
}

// ── Overall totals ────────────────────────────────────────
$overallDiscAmt = $subtotal * $overallDiscPct / 100;
$afterOverallD  = $subtotal - $overallDiscAmt;
$overallTaxAmt  = $afterOverallD * $overallTaxPct / 100;
$grandTotal     = $afterOverallD + $overallTaxAmt;
$balance        = $grandTotal - $paidAmount;

// ── Company settings (compatible with both DB helper styles) ──
$db      = db();
$company = [
    'company_name' => 'PHARMACY',
    'address'      => '',
    'phone'        => '',
    'gst_no'       => '',
    'footer_text'  => 'Thank you! Visit again.',
];

// Support both getOne() and query()+single() DB styles
if (method_exists($db, 'getOne')) {
    $co = $db->getOne("SELECT * FROM company_settings WHERE id = 1");
} else {
    $db->query("SELECT * FROM company_settings WHERE id = 1");
    $co = $db->single();
}

if (!empty($co)) {
    $company['company_name'] = $co['company_name'] ?? $company['company_name'];
    $company['address']      = $co['address']      ?? $company['address'];
    $company['phone']        = $co['phone']        ?? $company['phone'];
    $company['gst_no']       = $co['gst_no']       ?? $company['gst_no'];
    $company['footer_text']  = $co['footer_text']  ?? $company['footer_text'];
}

$invoiceNo = generateInvoiceNo();

// ═══════════════════════════════════════════════════════════
//  PROFESSIONAL INVOICE HTML
//  Style: Clean A4-style card with thermal-inspired accents
// ═══════════════════════════════════════════════════════════

// ── Outer wrapper ──────────────────────────────────────────
$H  = '<div style="';
$H .= 'font-family: Arial, Helvetica, sans-serif;';
$H .= 'font-size: 13px;';
$H .= 'width: 100%;';
$H .= 'max-width: 620px;';
$H .= 'margin: 0 auto;';
$H .= 'background: #ffffff;';
$H .= 'padding: 20px;';
$H .= 'border: 1px solid #d0d0d0;';
$H .= 'border-radius: 10px;';
$H .= 'box-shadow: 0 4px 16px rgba(0,0,0,0.10);';
$H .= 'color: #1a1a2e;';
$H .= '">';

// ── Header band ────────────────────────────────────────────
$H .= '<div style="';
$H .= 'background: linear-gradient(135deg, #1a1a2e 0%, #16213e 60%, #0f3460 100%);';
$H .= 'color: #ffffff;';
$H .= 'text-align: center;';
$H .= 'padding: 18px 15px 14px;';
$H .= 'border-radius: 7px 7px 0 0;';
$H .= 'margin: -20px -20px 16px -20px;';
$H .= '">';

$H .= '<div style="font-size: 22px; font-weight: 800; letter-spacing: 2px; text-transform: uppercase;">'
    . htmlspecialchars($company['company_name'])
    . '</div>';

if (!empty($company['address'])) {
    $H .= '<div style="font-size: 11px; margin-top: 4px; opacity: 0.85;">'
        . htmlspecialchars($company['address'])
        . '</div>';
}

$phoneGst = [];
if (!empty($company['phone'])) $phoneGst[] = '📞 ' . htmlspecialchars($company['phone']);
if (!empty($company['gst_no'])) $phoneGst[] = 'GST: ' . htmlspecialchars($company['gst_no']);
if (!empty($phoneGst)) {
    $H .= '<div style="font-size: 11px; margin-top: 3px; opacity: 0.80;">'
        . implode('&nbsp;&nbsp;|&nbsp;&nbsp;', $phoneGst)
        . '</div>';
}

// INVOICE label badge
$H .= '<div style="';
$H .= 'display: inline-block;';
$H .= 'background: #e94560;';
$H .= 'color: #fff;';
$H .= 'font-size: 10px;';
$H .= 'font-weight: 700;';
$H .= 'letter-spacing: 3px;';
$H .= 'padding: 3px 12px;';
$H .= 'border-radius: 20px;';
$H .= 'margin-top: 8px;';
$H .= '">INVOICE</div>';

$H .= '</div>'; // end header band

// ── Invoice meta (Invoice No + Date) ──────────────────────
$H .= '<div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 14px; padding: 10px 12px; background: #f7f8fc; border-radius: 6px; border-left: 4px solid #0f3460;">';
$H .= '<div>';
$H .= '<div style="font-size: 10px; color: #888; text-transform: uppercase; letter-spacing: 1px;">Invoice No</div>';
$H .= '<div style="font-size: 13px; font-weight: 700; color: #0f3460;">' . htmlspecialchars($invoiceNo) . '</div>';
$H .= '</div>';
$H .= '<div style="text-align: right;">';
$H .= '<div style="font-size: 10px; color: #888; text-transform: uppercase; letter-spacing: 1px;">Date &amp; Time</div>';
$H .= '<div style="font-size: 13px; font-weight: 600;">' . date('d/m/Y') . '</div>';
$H .= '<div style="font-size: 11px; color: #555;">' . date('h:i A') . '</div>';
$H .= '</div>';
$H .= '</div>'; // end invoice meta

// ── Customer info ──────────────────────────────────────────
$H .= '<div style="margin-bottom: 14px; padding: 10px 12px; border: 1px solid #e8e8e8; border-radius: 6px;">';
$H .= '<div style="font-size: 10px; color: #888; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 5px;">Bill To</div>';

$H .= '<div style="font-size: 14px; font-weight: 700;">'
    . htmlspecialchars($customerData['customer_name'] ?? 'Walk-in Customer')
    . '</div>';

if (!empty($customerData['customer_phone'])) {
    $H .= '<div style="font-size: 12px; color: #444; margin-top: 2px;">📞 '
        . htmlspecialchars($customerData['customer_phone'])
        . '</div>';
}

// Payment badge
$pmColors = ['cash' => '#27ae60', 'credit' => '#e74c3c', 'card' => '#2980b9', 'online' => '#8e44ad'];
$pmColor  = $pmColors[$paymentMethod] ?? '#555';
$H .= '<div style="margin-top: 5px;">';
$H .= '<span style="background:' . $pmColor . '; color:#fff; font-size:10px; font-weight:700; padding:2px 9px; border-radius:12px; letter-spacing:1px; text-transform:uppercase;">'
    . htmlspecialchars($paymentMethod)
    . '</span>';
$H .= '</div>';

if ($paymentMethod === 'credit' && !empty($customerData['due_date'])) {
    $H .= '<div style="color: #e74c3c; font-size: 12px; margin-top: 4px; font-weight: 600;">⚠ Due Date: '
        . date('d/m/Y', strtotime($customerData['due_date']))
        . '</div>';
}

$H .= '</div>'; // end customer info

// ── Items table ────────────────────────────────────────────
$H .= '<table style="width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 12px;">';

// Table head
$H .= '<thead>';
$H .= '<tr style="background: #1a1a2e; color: #ffffff;">';
$H .= '<th style="padding: 8px 6px; text-align: center; width: 28px; border-radius: 0;">#</th>';
$H .= '<th style="padding: 8px 6px; text-align: left;">Item / Details</th>';
$H .= '<th style="padding: 8px 6px; text-align: center; width: 38px;">Qty</th>';
$H .= '<th style="padding: 8px 6px; text-align: right; width: 80px;">Unit Price</th>';
$H .= '<th style="padding: 8px 6px; text-align: right; width: 80px;">Total</th>';
$H .= '</tr>';
$H .= '</thead>';

$H .= '<tbody>';
$sr = 1;
foreach ($cart as $item) {
    $isMed = ((int)($item['category_id'] ?? 0) === 1);
    $pName = htmlspecialchars($item['product_name'] ?? '');
    $brand = htmlspecialchars($item['brand_name'] ?? '');

    // Build details line
    $details = '';
    if ($isMed) {
        $parts = [];
        if (!empty($item['generic_name'])) $parts[] = htmlspecialchars($item['generic_name']);
        if (!empty($item['detail_text']))  $parts[] = htmlspecialchars($item['detail_text']);
        $details = implode(' · ', $parts);
    } else {
        $parts = [];
        if (!empty($brand))               $parts[] = $brand;
        if (!empty($item['detail_text'])) $parts[] = htmlspecialchars($item['detail_text']);
        $details = implode(' · ', $parts);
    }

    // Per-item discount / tax note (thermal style: no <small> tag, use spans)
    $discNote = '';
    if ($item['item_discount'] > 0 || $item['item_tax'] > 0) {
        $discNote .= '<br><span style="font-size:10px; color:#888;">';
        if ($item['item_discount'] > 0) {
            $discNote .= 'Disc ' . number_format($item['item_discount'], 1)
                       . '% <span style="color:#e74c3c;">-' . number_format($item['line_disc'], 2) . '</span>';
            if ($item['item_tax'] > 0) $discNote .= '&nbsp;&nbsp;';
        }
        if ($item['item_tax'] > 0) {
            $discNote .= 'Tax ' . number_format($item['item_tax'], 1)
                       . '% <span style="color:#27ae60;">+' . number_format($item['line_tax'], 2) . '</span>';
        }
        $discNote .= '</span>';
    }

    // Alternate row shading
    $rowBg = ($sr % 2 === 0) ? '#f9f9fb' : '#ffffff';

    $H .= '<tr style="background:' . $rowBg . '; border-bottom: 1px solid #eee;">';
    $H .= '<td style="padding: 7px 6px; text-align: center; color: #888; font-size: 11px;">' . $sr++ . '</td>';
    $H .= '<td style="padding: 7px 6px;">'
        . '<strong style="color:#1a1a2e;">' . $pName . '</strong>'
        . ($details ? '<br><span style="font-size:10px; color:#666;">' . $details . '</span>' : '')
        . $discNote
        . '</td>';
    $H .= '<td style="padding: 7px 6px; text-align: center;">' . $item['quantity'] . '</td>';
    // Unit price – no Rs prefix (thermal style), cleaner look
    $H .= '<td style="padding: 7px 6px; text-align: right; color: #444;">'
        . number_format((float)$item['price'], 2)
        . '</td>';
    // Line total – bold, with Rs
    $H .= '<td style="padding: 7px 6px; text-align: right; font-weight: 700; color: #0f3460;">'
        . 'Rs&nbsp;' . number_format($item['line_total'], 2)
        . '</td>';
    $H .= '</tr>';
}
$H .= '</tbody>';
$H .= '</table>';

// ── Totals section ─────────────────────────────────────────
$H .= '<div style="display: flex; justify-content: flex-end; margin-bottom: 16px;">';
$H .= '<div style="width: 280px; border: 1px solid #e8e8e8; border-radius: 8px; overflow: hidden;">';

// Subtotal row
$H .= '<div style="display:flex; justify-content:space-between; padding: 8px 12px; background:#f7f8fc;">';
$H .= '<span style="color:#555;">Subtotal</span>';
$H .= '<span style="font-weight:600;">Rs ' . number_format($subtotal, 2) . '</span>';
$H .= '</div>';

// Overall discount row (if applicable)
if ($overallDiscPct > 0) {
    $H .= '<div style="display:flex; justify-content:space-between; padding: 8px 12px; background:#fff5f5;">';
    $H .= '<span style="color:#e74c3c;">Discount (' . number_format($overallDiscPct, 2) . '%)</span>';
    $H .= '<span style="color:#e74c3c; font-weight:600;">− Rs ' . number_format($overallDiscAmt, 2) . '</span>';
    $H .= '</div>';
}

// Overall tax row (if applicable)
if ($overallTaxPct > 0) {
    $H .= '<div style="display:flex; justify-content:space-between; padding: 8px 12px; background:#f0fff4;">';
    $H .= '<span style="color:#27ae60;">Tax (' . number_format($overallTaxPct, 2) . '%)</span>';
    $H .= '<span style="color:#27ae60; font-weight:600;">+ Rs ' . number_format($overallTaxAmt, 2) . '</span>';
    $H .= '</div>';
}

// Grand total
$H .= '<div style="display:flex; justify-content:space-between; padding: 10px 12px; background:#1a1a2e; color:#fff;">';
$H .= '<span style="font-size:14px; font-weight:700; letter-spacing:0.5px;">GRAND TOTAL</span>';
$H .= '<span style="font-size:16px; font-weight:800; color:#e94560;">Rs ' . number_format($grandTotal, 2) . '</span>';
$H .= '</div>';

// Paid amount
$H .= '<div style="display:flex; justify-content:space-between; padding: 8px 12px; background:#f7f8fc;">';
$H .= '<span style="color:#555;">Paid (' . ucfirst($paymentMethod) . ')</span>';
$H .= '<span style="font-weight:600; color:#27ae60;">Rs ' . number_format($paidAmount, 2) . '</span>';
$H .= '</div>';

// Balance (Due or Change)
$balBg    = $balance > 0 ? '#fff5f5' : '#f0fff4';
$balColor = $balance > 0 ? '#e74c3c' : '#27ae60';
$balLabel = $balance > 0 ? '⚠ Due'   : '✔ Change';
$H .= '<div style="display:flex; justify-content:space-between; padding: 9px 12px; background:' . $balBg . '; border-top: 1px solid #eee;">';
$H .= '<span style="font-weight:700; color:' . $balColor . ';">' . $balLabel . '</span>';
$H .= '<span style="font-weight:800; color:' . $balColor . '; font-size:14px;">Rs ' . number_format(abs($balance), 2) . '</span>';
$H .= '</div>';

$H .= '</div>'; // end totals inner
$H .= '</div>'; // end totals flex wrapper

// ── Footer ─────────────────────────────────────────────────
$H .= '<div style="';
$H .= 'text-align: center;';
$H .= 'border-top: 2px dashed #d0d0d0;';
$H .= 'padding-top: 12px;';
$H .= 'margin-top: 4px;';
$H .= 'font-size: 11px;';
$H .= 'color: #777;';
$H .= '">';
$H .= '<div style="font-weight: 600; font-size: 12px; color: #444; margin-bottom: 3px;">'
    . htmlspecialchars($company['footer_text'])
    . '</div>';
$H .= '<div style="color: #aaa; font-size: 10px; letter-spacing: 1px;">✦ &nbsp; COMPUTER GENERATED INVOICE &nbsp; ✦</div>';
$H .= '</div>';

$H .= '</div>'; // close outer wrapper

// ── Sale data payload (for process_sale.php) ───────────────
$saleDataPayload = [
    'invoice_no'           => $invoiceNo,
    'customer_name'        => $customerData['customer_name']  ?? 'Walk-in Customer',
    'customer_phone'       => $customerData['customer_phone'] ?? '',
    'payment_method'       => $paymentMethod,
    'subtotal'             => $subtotal,
    'overall_discount'     => $overallDiscAmt,
    'overall_discount_pct' => $overallDiscPct,
    'overall_tax'          => $overallTaxAmt,
    'overall_tax_pct'      => $overallTaxPct,
    'grand_total'          => $grandTotal,
    'paid_amount'          => $paidAmount,
    'balance'              => $balance,
    'is_credit'            => ($paymentMethod === 'credit') ? 1 : 0,
    'due_date'             => ($paymentMethod === 'credit') ? ($customerData['due_date'] ?? null) : null,
    'sale_date'            => date('Y-m-d H:i:s'),
    'items'                => $cartData,
];

echo json_encode([
    'success'    => true,
    'html'       => $H,
    'sale_data'  => $saleDataPayload,
    'invoice_no' => $invoiceNo,
    'total'      => $grandTotal,
    'balance'    => $balance,
]);
?>