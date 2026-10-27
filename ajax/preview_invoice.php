<?php
/**
 * ajax/preview_invoice.php
 * ============================================================
 * COMPLETE PROFESSIONAL INVOICE PREVIEW
 * FULLY SYNCHRONIZED WITH company_settings TABLE
 *
 * FIXES:
 *  1. All company data read from getCompanySettings() — no hardcoded strings.
 *  2. Currency symbol used from settings everywhere (not hardcoded "Rs").
 *  3. Invoice prefix used from settings for invoice number generation.
 *  4. Primary/accent colors from settings applied to invoice header.
 *  5. show_payment_on_invoice and show_batch_on_invoice respected.
 *  6. show_logo_on_invoice and show_tagline_on_invoice respected.
 *  7. Date/time format from settings applied to invoice date display.
 * ============================================================
 */
session_start();
require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/sale_functions.php';
require_once '../includes/settings_functions.php';

requireLogin();

header('Content-Type: application/json');

// ── Company settings — ALWAYS from DB ────────────────────────
$co = getCompanySettings();

$company = [
    'company_name'           => $co['company_name']           ?? 'PHARMACY',
    'pharmacy_tagline'       => $co['pharmacy_tagline']        ?? '',
    'address'                => $co['address']                 ?? '',
    'phone'                  => $co['phone']                   ?? '',
    'email'                  => $co['email']                   ?? '',
    'gst_no'                 => $co['gst_no']                  ?? '',
    'logo_path'              => $co['logo_path']               ?? '',
    'show_logo_on_invoice'   => !empty($co['show_logo_on_invoice']),
    'show_tagline_on_invoice'=> !empty($co['show_tagline_on_invoice']),
    'currency_symbol'        => $co['currency_symbol']         ?? 'Rs',
    'invoice_footer_text'    => $co['invoice_footer_text']     ?? ($co['footer_text'] ?? 'Thank you for shopping with us!'),
    'invoice_terms'          => $co['invoice_terms']           ?? '',
    'invoice_prefix'         => $co['invoice_prefix']          ?? 'INV',
    'primary_color'          => $co['primary_color']           ?? '#0f3460',
    'accent_color'           => $co['accent_color']            ?? '#e94560',
    'show_payment_on_invoice'=> !empty($co['show_payment_on_invoice']),
    'show_batch_on_invoice'  => !empty($co['show_batch_on_invoice']),
    'date_format'            => $co['date_format']             ?? 'd/m/Y',
    'time_format'            => $co['time_format']             ?? 'h:i A',
];

// ── Invoice number generator (uses invoice_prefix from settings) ──
function generateInvoiceNo_preview(string $prefix): string {
    return $prefix . '-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
}

// ── Input parsing ─────────────────────────────────────────────
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

// ── Build cart with per-item calculations ─────────────────────
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

// ── Overall totals ────────────────────────────────────────────
$overallDiscAmt = $subtotal * $overallDiscPct / 100;
$afterOverallD  = $subtotal - $overallDiscAmt;
$overallTaxAmt  = $afterOverallD * $overallTaxPct / 100;
$grandTotal     = $afterOverallD + $overallTaxAmt;
$balance        = $grandTotal - $paidAmount;

$invoiceNo   = generateInvoiceNo_preview($company['invoice_prefix']);
$_currency   = $company['currency_symbol'];
$_hdrBg      = $company['primary_color'];
$_accentBg   = $company['accent_color'];
$_dateStr    = date($company['date_format']);
$_timeStr    = date($company['time_format']);

// ═══════════════════════════════════════════════════════════════
//  PROFESSIONAL INVOICE HTML — all data from company settings
// ═══════════════════════════════════════════════════════════════

$H  = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:13px;width:100%;max-width:620px;';
$H .= 'margin:0 auto;background:#ffffff;padding:20px;border:1px solid #d0d0d0;';
$H .= 'border-radius:10px;box-shadow:0 4px 16px rgba(0,0,0,0.10);color:#1a1a2e;">';

// Header band — dynamic color from settings
$H .= '<div style="background:' . $_hdrBg . ';color:#ffffff;text-align:center;'
    . 'padding:18px 15px 14px;border-radius:7px 7px 0 0;margin:-20px -20px 16px -20px;">';

// Logo (if enabled in settings)
if ($company['show_logo_on_invoice'] && !empty($company['logo_path'])) {
    $_logoFull = ROOT_PATH . ltrim($company['logo_path'], '/');
    if (file_exists($_logoFull)) {
        $_logoUrl = BASE_URL . ltrim($company['logo_path'], '/') . '?v=' . filemtime($_logoFull);
        $H .= '<div style="margin-bottom:8px;">';
        $H .= '<img src="' . htmlspecialchars($_logoUrl) . '" alt="logo" '
            . 'style="max-height:60px;max-width:180px;object-fit:contain;'
            . 'background:rgba(255,255,255,0.15);border-radius:6px;padding:4px;">';
        $H .= '</div>';
    }
}

// Company name from settings
$H .= '<div style="font-size:22px;font-weight:800;letter-spacing:2px;text-transform:uppercase;">'
    . htmlspecialchars($company['company_name']) . '</div>';

// Tagline (if enabled in settings)
if ($company['show_tagline_on_invoice'] && !empty($company['pharmacy_tagline'])) {
    $H .= '<div style="font-size:12px;margin-top:3px;opacity:0.85;font-style:italic;">'
        . htmlspecialchars($company['pharmacy_tagline']) . '</div>';
}

// Address
if (!empty($company['address'])) {
    $H .= '<div style="font-size:11px;margin-top:4px;opacity:0.85;">'
        . htmlspecialchars($company['address']) . '</div>';
}

// Phone / Email / GST
$phoneGst = [];
if (!empty($company['phone']))  $phoneGst[] = '📞 ' . htmlspecialchars($company['phone']);
if (!empty($company['email']))  $phoneGst[] = '✉ '  . htmlspecialchars($company['email']);
if (!empty($company['gst_no'])) $phoneGst[] = 'GST: ' . htmlspecialchars($company['gst_no']);
if (!empty($phoneGst)) {
    $H .= '<div style="font-size:11px;margin-top:3px;opacity:0.80;">'
        . implode('&nbsp;&nbsp;|&nbsp;&nbsp;', $phoneGst) . '</div>';
}

// INVOICE badge
$H .= '<div style="display:inline-block;background:' . $_accentBg . ';color:#fff;'
    . 'font-size:10px;font-weight:700;letter-spacing:3px;padding:3px 12px;'
    . 'border-radius:20px;margin-top:8px;">INVOICE</div>';
$H .= '</div>'; // end header band

// Invoice meta
$H .= '<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:14px;'
    . 'padding:10px 12px;background:#f7f8fc;border-radius:6px;border-left:4px solid ' . $_hdrBg . ';">';
$H .= '<div>';
$H .= '<div style="font-size:10px;color:#888;text-transform:uppercase;letter-spacing:1px;">Invoice No</div>';
$H .= '<div style="font-size:13px;font-weight:700;color:' . $_hdrBg . ';">' . htmlspecialchars($invoiceNo) . '</div>';
$H .= '</div>';
$H .= '<div style="text-align:right;">';
$H .= '<div style="font-size:10px;color:#888;text-transform:uppercase;letter-spacing:1px;">Date &amp; Time</div>';
$H .= '<div style="font-size:13px;font-weight:600;">' . $_dateStr . '</div>';
$H .= '<div style="font-size:11px;color:#555;">' . $_timeStr . '</div>';
$H .= '</div>';
$H .= '</div>';

// Customer info
$H .= '<div style="margin-bottom:14px;padding:10px 12px;border:1px solid #e8e8e8;border-radius:6px;">';
$H .= '<div style="font-size:10px;color:#888;text-transform:uppercase;letter-spacing:1px;margin-bottom:5px;">Bill To</div>';
$H .= '<div style="font-size:14px;font-weight:700;">'
    . htmlspecialchars($customerData['customer_name'] ?? 'Walk-in Customer') . '</div>';

if (!empty($customerData['customer_phone'])) {
    $H .= '<div style="font-size:12px;color:#444;margin-top:2px;">📞 '
        . htmlspecialchars($customerData['customer_phone']) . '</div>';
}

// Payment badge (only if setting enabled)
if ($company['show_payment_on_invoice']) {
    $pmColors = ['cash'=>'#27ae60','credit'=>'#e74c3c','card'=>'#2980b9','online'=>'#8e44ad'];
    $pmColor  = $pmColors[$paymentMethod] ?? '#555';
    $H .= '<div style="margin-top:5px;">';
    $H .= '<span style="background:' . $pmColor . ';color:#fff;font-size:10px;font-weight:700;'
        . 'padding:2px 9px;border-radius:12px;letter-spacing:1px;text-transform:uppercase;">'
        . htmlspecialchars($paymentMethod) . '</span>';
    $H .= '</div>';
}

if ($paymentMethod === 'credit' && !empty($customerData['due_date'])) {
    $H .= '<div style="color:#e74c3c;font-size:12px;margin-top:4px;font-weight:600;">⚠ Due Date: '
        . date($company['date_format'], strtotime($customerData['due_date'])) . '</div>';
}
$H .= '</div>';

// Items table
$H .= '<table style="width:100%;border-collapse:collapse;margin-bottom:16px;font-size:12px;">';
$H .= '<thead><tr style="background:#1a1a2e;color:#ffffff;">';
$H .= '<th style="padding:8px 6px;text-align:center;width:28px;">#</th>';
$H .= '<th style="padding:8px 6px;text-align:left;">Item / Details</th>';
$H .= '<th style="padding:8px 6px;text-align:center;width:38px;">Qty</th>';
$H .= '<th style="padding:8px 6px;text-align:right;width:80px;">Unit Price</th>';
$H .= '<th style="padding:8px 6px;text-align:right;width:80px;">Total</th>';
$H .= '</tr></thead><tbody>';

$sr = 1;
foreach ($cart as $item) {
    $isMed = ((int)($item['category_id'] ?? 0) === 1);
    $pName = htmlspecialchars($item['product_name'] ?? '');
    $brand = htmlspecialchars($item['brand_name'] ?? '');

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

    $discNote = '';
    if ($item['item_discount'] > 0 || $item['item_tax'] > 0) {
        $discNote .= '<br><span style="font-size:10px;color:#888;">';
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

    // Batch number (if setting enabled)
    $batchNote = '';
    if ($company['show_batch_on_invoice'] && !empty($item['batch_number'])) {
        $batchNote = '<br><span style="font-size:9px;color:#999;">Batch: ' . htmlspecialchars($item['batch_number']) . '</span>';
    }

    $rowBg = ($sr % 2 === 0) ? '#f9f9fb' : '#ffffff';
    $H .= '<tr style="background:' . $rowBg . ';border-bottom:1px solid #eee;">';
    $H .= '<td style="padding:7px 6px;text-align:center;color:#888;font-size:11px;">' . $sr++ . '</td>';
    $H .= '<td style="padding:7px 6px;">'
        . '<strong style="color:#1a1a2e;">' . $pName . '</strong>'
        . ($details ? '<br><span style="font-size:10px;color:#666;">' . $details . '</span>' : '')
        . $batchNote
        . $discNote
        . '</td>';
    $H .= '<td style="padding:7px 6px;text-align:center;">' . $item['quantity'] . '</td>';
    $H .= '<td style="padding:7px 6px;text-align:right;color:#444;">'
        . number_format((float)$item['price'], 2) . '</td>';
    $H .= '<td style="padding:7px 6px;text-align:right;font-weight:700;color:' . $_hdrBg . ';">'
        . $_currency . '&nbsp;' . number_format($item['line_total'], 2) . '</td>';
    $H .= '</tr>';
}
$H .= '</tbody></table>';

// Totals
$H .= '<div style="display:flex;justify-content:flex-end;margin-bottom:16px;">';
$H .= '<div style="width:280px;border:1px solid #e8e8e8;border-radius:8px;overflow:hidden;">';

$H .= '<div style="display:flex;justify-content:space-between;padding:8px 12px;background:#f7f8fc;">';
$H .= '<span style="color:#555;">Subtotal</span>';
$H .= '<span style="font-weight:600;">' . $_currency . ' ' . number_format($subtotal, 2) . '</span>';
$H .= '</div>';

if ($overallDiscPct > 0) {
    $H .= '<div style="display:flex;justify-content:space-between;padding:8px 12px;background:#fff5f5;">';
    $H .= '<span style="color:#e74c3c;">Discount (' . number_format($overallDiscPct, 2) . '%)</span>';
    $H .= '<span style="color:#e74c3c;font-weight:600;">− ' . $_currency . ' ' . number_format($overallDiscAmt, 2) . '</span>';
    $H .= '</div>';
}

if ($overallTaxPct > 0) {
    $H .= '<div style="display:flex;justify-content:space-between;padding:8px 12px;background:#f0fff4;">';
    $H .= '<span style="color:#27ae60;">Tax (' . number_format($overallTaxPct, 2) . '%)</span>';
    $H .= '<span style="color:#27ae60;font-weight:600;">+ ' . $_currency . ' ' . number_format($overallTaxAmt, 2) . '</span>';
    $H .= '</div>';
}

$H .= '<div style="display:flex;justify-content:space-between;padding:10px 12px;background:#1a1a2e;color:#fff;">';
$H .= '<span style="font-size:14px;font-weight:700;letter-spacing:0.5px;">GRAND TOTAL</span>';
$H .= '<span style="font-size:16px;font-weight:800;color:' . $_accentBg . ';">' . $_currency . ' ' . number_format($grandTotal, 2) . '</span>';
$H .= '</div>';

$H .= '<div style="display:flex;justify-content:space-between;padding:8px 12px;background:#f7f8fc;">';
$H .= '<span style="color:#555;">Paid (' . ucfirst($paymentMethod) . ')</span>';
$H .= '<span style="font-weight:600;color:#27ae60;">' . $_currency . ' ' . number_format($paidAmount, 2) . '</span>';
$H .= '</div>';

$balBg    = $balance > 0 ? '#fff5f5' : '#f0fff4';
$balColor = $balance > 0 ? '#e74c3c' : '#27ae60';
$balLabel = $balance > 0 ? '⚠ Due'   : '✔ Change';
$H .= '<div style="display:flex;justify-content:space-between;padding:9px 12px;background:' . $balBg . ';border-top:1px solid #eee;">';
$H .= '<span style="font-weight:700;color:' . $balColor . ';">' . $balLabel . '</span>';
$H .= '<span style="font-weight:800;color:' . $balColor . ';font-size:14px;">' . $_currency . ' ' . number_format(abs($balance), 2) . '</span>';
$H .= '</div>';

$H .= '</div></div>'; // end totals

// Footer — from company settings
$H .= '<div style="text-align:center;border-top:2px dashed #d0d0d0;padding-top:12px;margin-top:4px;font-size:11px;color:#777;">';
$H .= '<div style="font-weight:600;font-size:12px;color:#444;margin-bottom:3px;">'
    . htmlspecialchars($company['invoice_footer_text']) . '</div>';
if (!empty($company['invoice_terms'])) {
    $H .= '<div style="font-size:10px;color:#888;margin-top:4px;padding:5px 10px;'
        . 'background:#f7f8fc;border-radius:4px;border-top:1px solid #eee;">'
        . htmlspecialchars($company['invoice_terms']) . '</div>';
}
$H .= '<div style="color:#aaa;font-size:10px;letter-spacing:1px;">✦ &nbsp; COMPUTER GENERATED INVOICE &nbsp; ✦</div>';
$H .= '<div style="color:#bbb;font-size:9px;margin-top:4px;">Powered by Dr. Arslan Yasin Sukhera</div>';
$H .= '</div>';
$H .= '</div>'; // close outer wrapper

// Sale data payload
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