<?php
/**
 * sales/pos.php — Point of Sale (POS)
 * Step 9 UI/UX Sync:
 * - Breadcrumb: Dashboard > Sales > POS / New Sale
 * - Stat cards use design system (.stat-card, CSS variables)
 * - Cart area styled card with clear header using var(--pharmacy-*)
 * - Product search — live search with smooth dropdown results
 * - Cart items — alternating rows, hover effect
 * - Total section — prominent, large font for grand total
 * - Action buttons — proper styling with transitions
 * - Modals — smooth animations
 * - All colors via CSS variables — no hardcoded theme values
 * - Page fade-in animation
 * - ALL existing POS AJAX functionality preserved 100%
 */

require_once '../config/config.php';
require_once '../includes/sale_functions.php';
require_once '../includes/settings_functions.php';

requireLogin();

$page_title = 'Point of Sale (POS) - Pharmacy Management System';

$todaySales = getTodaySalesSummary();

// ── Load ALL settings from company_settings (not system_preferences) ──
$_co        = getCompanySettings();
$defaultTax = (float)($_co['default_tax_rate'] ?? 0);   // FIX: was reading from system_preferences
$currency   = $_co['currency_symbol'] ?? 'Rs';
$pharmName  = $_co['company_name']    ?? 'PHARMACY';
$pharmAddr  = $_co['address']         ?? '';
$pharmPhone = $_co['phone']           ?? '';
$pharmGST   = $_co['gst_no']         ?? '';
$pharmTagline    = $_co['pharmacy_tagline']    ?? '';
$invoicePrefix   = $_co['invoice_prefix']      ?? 'INV';
$showPayOnInv    = !empty($_co['show_payment_on_invoice']);
$showBatchOnInv  = !empty($_co['show_batch_on_invoice']);
$showTaglineOnInv= !empty($_co['show_tagline_on_invoice']);
$nearExpiryDays  = (int)($_co['near_expiry_days'] ?? 30);
$lowStockThresh  = (int)($_co['low_stock_threshold'] ?? 10);
$dateFormat      = $_co['date_format'] ?? 'd/m/Y';
$timeFormat      = $_co['time_format'] ?? 'h:i A';

$db = db();

$db->query("SELECT id, invoice_no, customer_name, total, payment_method, sale_date FROM sales ORDER BY id DESC LIMIT 15");
$recentSales = $db->resultSet();

/* ── Pre-load credit customers for autocomplete ── */
$creditCustomersJson = '[]';
try {
    $custSql = "
        SELECT
            cc.customer_phone AS phone,
            MAX(cc.customer_name) AS name,
            COALESCE(SUM(CASE WHEN cc.status='active'
                THEN (COALESCE(s.total, cc.total_amount)
                      - COALESCE(s.paid_amount,0)
                      - COALESCE((SELECT SUM(cp2.amount) FROM credit_payments cp2
                                  WHERE cp2.credit_id=cc.id
                                    AND (cp2.note IS NULL OR (cp2.note NOT LIKE 'Upfront%' AND cp2.note NOT LIKE 'upfront%'))),0))
                ELSE 0 END), 0) AS due_amount
        FROM customer_credits cc
        LEFT JOIN sales s ON s.id = cc.sale_id
        WHERE cc.customer_name IS NOT NULL AND cc.customer_name != ''
          AND cc.customer_name != 'Walk-in Customer'
        GROUP BY cc.customer_phone
        ORDER BY due_amount DESC, name ASC
        LIMIT 300
    ";
    $custStmt = $db->getConnection()->prepare($custSql);
    $custStmt->execute();
    $custRows = $custStmt->fetchAll(PDO::FETCH_ASSOC);
    $custOut  = [];
    foreach($custRows as $cr){
        if(empty(trim($cr['name'] ?? ''))) continue;
        $custOut[] = [
            'customer_name'  => trim($cr['name']),
            'customer_phone' => trim($cr['phone'] ?? ''),
            'due_amount'     => (float)($cr['due_amount'] ?? 0),
        ];
    }
    $creditCustomersJson = json_encode(array_values($custOut), JSON_UNESCAPED_UNICODE);
} catch(Exception $e){
    $creditCustomersJson = '[]';
    error_log("POS credit customers preload error: " . $e->getMessage());
}

// Pass company settings to JS as a JSON object for use in thermal preview
// ── Extra fields for fully-synced invoice preview ────────────────
$pharmEmail       = $_co['email']                   ?? '';
$showLogoOnInv    = !empty($_co['show_logo_on_invoice']);
$logoPath         = $_co['logo_path']               ?? '';
// Logo URL for invoice preview (safe computation)
$_logoFsPath      = (defined('ROOT_PATH') ? ROOT_PATH : '') . ltrim($logoPath, '/');
$_logoExists      = ($showLogoOnInv && !empty($logoPath) && file_exists($_logoFsPath));
$logoUrl          = $_logoExists
    ? (defined('BASE_URL') ? BASE_URL : '/') . ltrim($logoPath, '/') . '?v=' . filemtime($_logoFsPath)
    : '';
$invoiceFooterText= $_co['invoice_footer_text']     ?? ($_co['footer_text'] ?? 'Thank you for shopping with us!');
$invoiceTerms     = $_co['invoice_terms']           ?? '';
$primaryColor     = $_co['primary_color']           ?? '#1a1a2e';
$accentColor      = $_co['accent_color']            ?? '#e94560';
$companySettingsJson = json_encode([
    // Core identity
    'name'              => $pharmName,
    'tagline'           => $pharmTagline,
    'address'           => $pharmAddr,
    'phone'             => $pharmPhone,
    'email'             => $pharmEmail,
    'gst_no'            => $pharmGST,
    // Invoice display flags
    'show_payment'      => $showPayOnInv,
    'show_batch'        => $showBatchOnInv,
    'show_tagline'      => $showTaglineOnInv,
    'show_logo'         => $showLogoOnInv,
    // Invoice content
    'logo_url'          => $logoUrl,
    'footer_text'       => $invoiceFooterText,
    'invoice_terms'     => $invoiceTerms,
    'invoice_prefix'    => $invoicePrefix,
    'currency'          => $currency,
    // Colors
    'primary_color'     => $primaryColor,
    'accent_color'      => $accentColor,
    // Other
    'near_expiry_days'  => $nearExpiryDays,
    'low_stock_thresh'  => $lowStockThresh,
    'date_format'       => $dateFormat,
    'time_format'       => $timeFormat,
    'default_tax_rate'  => $defaultTax,
], JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="light dark">
<?php include dirname(__DIR__) . '/includes/page_head.php'; ?>
<style>
/* ── Reset & Base ── */
*, *::before, *::after { box-sizing: border-box; }

body {
  background: var(--pharmacy-body-bg, #eef0f4);
  font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
  font-size: 13px;
  color: var(--pharmacy-text, #343a40);
  margin: 0; padding: 0;
}

/* ── Page fade-in ── */
.main-content {
  margin-left: var(--sb-w, 260px);
  padding: 12px;
  min-height: 100vh;
  animation: fadeInPage 0.28s ease;
}
@keyframes fadeInPage {
  from { opacity: 0; transform: translateY(8px); }
  to   { opacity: 1; transform: translateY(0); }
}
@media (max-width: 992px) {
  .main-content { margin-left: 0; }
  .pos-two-columns { flex-direction: column; }
  .right-column { max-width: 100%; min-width: auto; }
}

/* ── Stat cards — design system ── */
.stats-bar { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; }
.stat-card {
  background: var(--pharmacy-card-bg, #fff);
  border-radius: var(--card-border-radius, 10px);
  padding: 10px 16px;
  flex: 1;
  min-width: 130px;
  text-align: center;
  box-shadow: 0 2px 10px rgba(0,0,0,0.06);
  border: 1px solid var(--pharmacy-border, #e9ecef);
  border-top: 3px solid var(--pharmacy-primary, #667eea);
  transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.stat-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(0,0,0,0.10);
}
.stat-card small {
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.4px;
  color: color-mix(in srgb, var(--pharmacy-text, #343a40) 60%, transparent);
  display: block;
  margin-bottom: 4px;
}
.stat-card h4 {
  margin: 0;
  font-size: 1.25rem;
  font-weight: 700;
  color: var(--pharmacy-heading, #212529);
}
.stat-card.stat-invoices { border-top-color: var(--pharmacy-primary, #0d6efd); }
.stat-card.stat-sales    { border-top-color: #198754; }
.stat-card.stat-cash     { border-top-color: #0dcaf0; }
.stat-card.stat-card-pay { border-top-color: #ffc107; }
.stat-card.stat-credit   { border-top-color: #dc3545; }

/* ── Two-column POS layout ── */
.pos-two-columns { display: flex; gap: 10px; }
.left-column  { flex: 1.2; min-width: 0; }
.right-column { flex: 1.4; min-width: 480px; max-width: 560px; }

/* ── Search boxes ── */
.search-box {
  background: var(--pharmacy-card-bg, #fff);
  border-radius: 10px;
  padding: 10px 12px;
  margin-bottom: 10px;
  box-shadow: 0 1px 6px rgba(0,0,0,0.06);
  border: 1px solid var(--pharmacy-border, #e9ecef);
  position: relative;
}
.search-box.barcode-box {
  border-bottom-left-radius: 0;
  border-bottom-right-radius: 0;
  border-bottom: 2px solid color-mix(in srgb, var(--pharmacy-primary, #667eea) 20%, transparent);
  margin-bottom: 0;
}
.search-box.text-box {
  border-top-left-radius: 0;
  border-top-right-radius: 0;
  border-top: 1px dashed var(--pharmacy-border, #e0e0e0);
  margin-top: 0;
}
.search-input-wrap { position: relative; }
.search-input-wrap input {
  width: 100%;
  padding: 10px 38px 10px 14px;
  border: 2px solid var(--pharmacy-border, #e0e0e0);
  border-radius: 8px;
  font-size: 14px;
  background: var(--pharmacy-card-bg, #fff);
  color: var(--pharmacy-text, #343a40);
  transition: border-color 0.2s ease, box-shadow 0.2s ease;
}
.search-input-wrap input:focus {
  border-color: var(--pharmacy-primary, #667eea);
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--pharmacy-primary, #667eea) 12%, transparent);
  outline: none;
}
.search-clear {
  position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
  cursor: pointer; color: color-mix(in srgb, var(--pharmacy-text, #343a40) 45%, transparent);
  display: none; font-size: 16px;
  transition: color 0.15s;
}
.search-clear:hover { color: var(--pharmacy-heading, #212529); }
.search-count {
  font-size: 11px;
  color: color-mix(in srgb, var(--pharmacy-text, #343a40) 55%, transparent);
  margin-top: 4px;
}
.search-hint {
  font-size: 10px;
  color: color-mix(in srgb, var(--pharmacy-text, #343a40) 50%, transparent);
  margin-top: 3px;
}

/* ── Products table ── */
.products-container {
  background: var(--pharmacy-card-bg, #fff);
  border-radius: 10px;
  box-shadow: 0 2px 8px rgba(0,0,0,0.06);
  border: 1px solid var(--pharmacy-border, #e9ecef);
  overflow-x: auto;
  overflow-y: auto;
  max-height: 500px;
}
.products-header, .product-row {
  display: grid;
  grid-template-columns: 2.2fr 1.2fr 0.7fr 0.8fr 1.1fr;
  gap: 6px;
  align-items: center;
  min-width: 620px;
}
.products-header {
  background: var(--pharmacy-table-th, #f2f4f8);
  font-weight: 700;
  border-bottom: 2px solid var(--pharmacy-border, #e0e4ed);
  font-size: 10px;
  color: var(--pharmacy-heading, #343a40);
  text-transform: uppercase;
  letter-spacing: 0.4px;
  position: sticky;
  top: 0;
  padding: 8px 10px;
  z-index: 2;
}
.product-row {
  padding: 8px 10px;
  border-bottom: 1px solid var(--pharmacy-border, #e9ecef);
  transition: background 0.15s ease;
  cursor: pointer;
  background: var(--pharmacy-card-bg, #fff);
  font-size: 12px;
}
.product-row:hover {
  background: color-mix(in srgb, var(--pharmacy-primary, #667eea) 7%, var(--pharmacy-card-bg, #fff));
  border-left: 3px solid var(--pharmacy-primary, #667eea);
  padding-left: 9px;
}
.product-col { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.product-row .product-col:first-child { white-space: normal; word-break: break-word; }
.product-name { font-weight: 700; font-size: 13px; margin-bottom: 1px; color: var(--pharmacy-heading, #212529); }
.product-sub  { font-size: 10px; color: color-mix(in srgb, var(--pharmacy-text, #343a40) 65%, transparent); }

/* ── Stock badges ── */
.stock-low    { color: #e6ac00; font-weight: 700; }
.stock-out    { color: #dc3545; font-weight: 700; }
.stock-normal { color: #198754; font-weight: 600; }

/* ── Mode toggle ── */
.mode-toggle {
  display: inline-flex;
  border: 1px solid var(--pharmacy-border, #ddd);
  border-radius: 20px;
  overflow: hidden;
  font-size: 10px;
  background: var(--pharmacy-table-th, #f8f9fa);
  cursor: default;
  margin-top: 3px;
}
.mode-toggle span {
  padding: 2px 6px;
  cursor: pointer;
  transition: background 0.15s, color 0.15s;
  white-space: nowrap;
  color: var(--pharmacy-text, #343a40);
}
.mode-toggle span.active {
  background: var(--pharmacy-primary, #667eea);
  color: #fff;
  font-weight: 700;
}
.mode-toggle span:not(.active):hover {
  background: color-mix(in srgb, var(--pharmacy-border, #ddd) 50%, transparent);
}

/* ── Cart container ── */
.cart-container {
  background: var(--pharmacy-card-bg, #fff);
  border-radius: var(--card-border-radius, 10px);
  box-shadow: 0 2px 10px rgba(0,0,0,0.07);
  border: 1px solid var(--pharmacy-border, #e9ecef);
  margin-bottom: 10px;
  overflow: hidden;
}
.cart-header {
  background: linear-gradient(135deg, var(--pharmacy-primary, #667eea), color-mix(in srgb, var(--pharmacy-primary, #667eea) 60%, #764ba2));
  color: #fff;
  padding: 11px 14px;
  font-weight: 700;
  font-size: 13px;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.cart-header .cart-title { display: flex; align-items: center; gap: 8px; }
.cart-items {
  max-height: 400px;
  overflow-y: auto;
  overflow-x: auto;
}
.cart-item {
  border-bottom: 1px solid var(--pharmacy-border, #f0f2f5);
  padding: 8px 10px;
  transition: background 0.15s;
}
.cart-item:nth-child(even) {
  background: color-mix(in srgb, var(--pharmacy-table-th, #f8f9fa) 40%, var(--pharmacy-card-bg, #fff));
}
.cart-item:hover {
  background: color-mix(in srgb, var(--pharmacy-primary, #667eea) 5%, var(--pharmacy-card-bg, #fff));
}
.cart-item-name   { font-weight: 600; font-size: 12px; line-height: 1.3; color: var(--pharmacy-heading, #212529); }
.cart-item-detail { font-size: 10px; color: color-mix(in srgb, var(--pharmacy-text, #343a40) 60%, transparent); margin-top: 1px; }
.cart-item-controls { display: flex; align-items: center; gap: 4px; margin-top: 5px; flex-wrap: nowrap; }
.cart-empty {
  text-align: center;
  color: color-mix(in srgb, var(--pharmacy-text, #343a40) 40%, transparent);
  padding: 30px 10px;
}

/* ── Qty + Disc buttons ── */
.qty-btn {
  width: 24px; height: 24px;
  border: 1px solid var(--pharmacy-border, #ddd);
  background: var(--pharmacy-table-th, #f8f9fa);
  border-radius: 4px; cursor: pointer;
  font-size: 13px; line-height: 1;
  color: var(--pharmacy-text, #343a40);
  transition: all 0.15s ease;
}
.qty-btn:hover {
  background: var(--pharmacy-primary, #667eea);
  color: #fff;
  border-color: var(--pharmacy-primary, #667eea);
}
.qty-input {
  width: 52px; padding: 3px; text-align: center;
  border: 1px solid var(--pharmacy-border, #ddd);
  border-radius: 4px; font-size: 12px;
  background: var(--pharmacy-card-bg, #fff);
  color: var(--pharmacy-text, #343a40);
  transition: border-color 0.15s;
}
.qty-input:focus { border-color: var(--pharmacy-primary, #667eea); outline: none; }
.item-disc-input {
  width: 42px; padding: 3px;
  border: 1px solid var(--pharmacy-border, #ddd);
  border-radius: 4px; text-align: center; font-size: 11px;
  background: var(--pharmacy-card-bg, #fff);
  color: var(--pharmacy-text, #343a40);
  transition: border-color 0.15s;
}
.remove-btn {
  display: inline-flex; align-items: center; justify-content: center;
  width: 26px; height: 26px; border-radius: 50%;
  border: 1.5px solid #dc3545; color: #dc3545;
  cursor: pointer; font-size: 13px; background: var(--pharmacy-card-bg, #fff);
  transition: background 0.15s, color 0.15s;
  flex-shrink: 0;
}
.remove-btn:hover { background: #dc3545; color: #fff; }

.label-small { font-size: 10px; color: color-mix(in srgb, var(--pharmacy-text, #343a40) 55%, transparent); margin: 0 1px; }
.cart-item-total { font-weight: 700; font-size: 12px; margin-left: auto; white-space: nowrap; color: #198754; }

/* ── Cart totals ── */
.cart-totals {
  padding: 10px 12px;
  background: color-mix(in srgb, var(--pharmacy-table-th, #f8f9fa) 70%, var(--pharmacy-card-bg, #fff));
  border-top: 2px solid var(--pharmacy-border, #e9ecef);
}
.total-line {
  display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;
  color: var(--pharmacy-text, #343a40); font-size: 12px;
}
.grand-total-line {
  display: flex; justify-content: space-between; align-items: center;
  padding: 7px 0; border-top: 2px solid var(--pharmacy-border, #ddd); margin-top: 4px;
  color: var(--pharmacy-heading, #212529);
}
.grand-total-amount {
  font-size: 1.2rem;
  font-weight: 700;
  color: #198754;
}
.disc-tax-row {
  display: grid;
  grid-template-columns: 82px 52px 14px 14px 62px 1fr;
  align-items: center; gap: 3px; margin-bottom: 5px;
}
.disc-tax-label { font-size: 12px; color: var(--pharmacy-text, #555); white-space: nowrap; }
.disc-tax-pct {
  width: 52px; padding: 2px 4px;
  border: 1px solid var(--pharmacy-border, #ddd); border-radius: 4px;
  font-size: 11px; text-align: center;
  background: var(--pharmacy-card-bg, #fff); color: var(--pharmacy-text, #343a40);
  transition: border-color 0.15s;
}
.disc-tax-pct:focus { border-color: var(--pharmacy-primary, #667eea); outline: none; }
.disc-tax-unit { font-size: 10px; color: color-mix(in srgb, var(--pharmacy-text, #343a40) 50%, transparent); text-align: center; }
.disc-tax-sep  { font-size: 10px; color: var(--pharmacy-border, #ccc); text-align: center; }
.disc-tax-rs {
  width: 62px; padding: 2px 4px;
  border: 1px solid var(--pharmacy-border, #ddd); border-radius: 4px;
  font-size: 11px; text-align: right;
  background: var(--pharmacy-card-bg, #fff); color: var(--pharmacy-text, #343a40);
  transition: border-color 0.15s;
}
.disc-tax-rs:focus { border-color: var(--pharmacy-primary, #667eea); outline: none; }
.disc-tax-result { font-size: 11px; font-weight: 600; text-align: right; white-space: nowrap; min-width: 60px; }

/* ── Payment method buttons ── */
.pay-methods { display: flex; gap: 5px; margin-bottom: 8px; flex-wrap: wrap; }
.pay-method-btn {
  flex: 1; padding: 6px 4px;
  border: 2px solid var(--pharmacy-border, #dee2e6);
  border-radius: 8px;
  background: var(--pharmacy-card-bg, #fff);
  text-align: center; cursor: pointer;
  font-size: 11px; font-weight: 600; min-width: 60px;
  color: var(--pharmacy-text, #343a40);
  transition: all 0.18s ease;
}
.pay-method-btn:hover {
  transform: translateY(-1px);
  box-shadow: 0 3px 10px rgba(0,0,0,0.1);
}
.pay-method-btn.active-cash   { border-color: #198754; background: color-mix(in srgb, #198754 10%, var(--pharmacy-card-bg, #fff)); color: #198754; }
.pay-method-btn.active-card   { border-color: #0d6efd; background: color-mix(in srgb, #0d6efd 10%, var(--pharmacy-card-bg, #fff)); color: #0d6efd; }
.pay-method-btn.active-online { border-color: #fd7e14; background: color-mix(in srgb, #fd7e14 10%, var(--pharmacy-card-bg, #fff)); color: #fd7e14; }
.pay-method-btn.active-credit { border-color: #dc3545; background: color-mix(in srgb, #dc3545 10%, var(--pharmacy-card-bg, #fff)); color: #dc3545; }

.balance-due    { color: #dc3545; font-weight: 700; }
.balance-change { color: #198754; font-weight: 700; }

/* ── Customer panel ── */
.customer-panel-box {
  background: color-mix(in srgb, var(--pharmacy-primary, #667eea) 6%, var(--pharmacy-card-bg, #fff));
  border: 1.5px solid color-mix(in srgb, var(--pharmacy-primary, #667eea) 30%, transparent);
  border-radius: 8px;
  padding: 8px 10px;
}
.customer-panel-label {
  font-size: 11px; font-weight: 700;
  color: var(--pharmacy-heading, #4a5568);
  margin-bottom: 6px;
  display: flex; align-items: center; gap: 5px;
}

/* ── Action buttons ── */
.btn-new-sale {
  background: var(--pharmacy-card-bg, #fff);
  border: 2px solid var(--pharmacy-border, #dee2e6);
  color: var(--pharmacy-text, #343a40);
  border-radius: 8px;
  font-size: 12px; font-weight: 600;
  padding: 7px 12px;
  transition: all 0.18s ease;
  cursor: pointer;
}
.btn-new-sale:hover {
  border-color: var(--pharmacy-primary, #667eea);
  color: var(--pharmacy-primary, #667eea);
  transform: translateY(-1px);
}
.btn-generate-invoice {
  background: linear-gradient(135deg, #198754, #20c997);
  border: none; color: #fff;
  border-radius: 8px; font-size: 12px; font-weight: 700;
  padding: 8px 14px;
  transition: all 0.2s ease;
  cursor: pointer;
}
.btn-generate-invoice:hover {
  filter: brightness(1.08);
  transform: translateY(-1px);
  box-shadow: 0 4px 14px rgba(25, 135, 84, 0.35);
}

/* ── Recent sales ── */
.recent-sales {
  background: var(--pharmacy-card-bg, #fff);
  border-radius: var(--card-border-radius, 10px);
  box-shadow: 0 2px 8px rgba(0,0,0,0.06);
  border: 1px solid var(--pharmacy-border, #e9ecef);
  overflow: hidden;
  max-height: 200px;
  overflow-y: auto;
}
.recent-header {
  background: var(--pharmacy-heading, #2d3748);
  color: #fff;
  padding: 9px 14px;
  font-weight: 700;
  font-size: 12px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  position: sticky;
  top: 0;
}
.recent-item {
  padding: 7px 12px;
  border-bottom: 1px solid var(--pharmacy-border, #f0f2f5);
  cursor: pointer;
  display: flex;
  justify-content: space-between;
  align-items: center;
  transition: background 0.15s;
  font-size: 12px;
  color: var(--pharmacy-text, #343a40);
}
.recent-item:hover {
  background: color-mix(in srgb, var(--pharmacy-primary, #667eea) 6%, var(--pharmacy-card-bg, #fff));
}
.credit-badge {
  background: #dc3545; color: #fff;
  font-size: 10px; border-radius: 4px; padding: 1px 5px;
  text-decoration: none; transition: background 0.15s;
}
.credit-badge:hover { background: #b02a37; color: #fff; }

/* ── Mode badges ── */
.sell-mode-badge { font-size: 9px; border-radius: 3px; padding: 1px 5px; font-weight: 700; margin-left: 4px; }
.sell-mode-piece { background: color-mix(in srgb, #0d6efd 12%, transparent); color: #1565c0; }
.sell-mode-pack  { background: color-mix(in srgb, #198754 12%, transparent); color: #2e7d32; }

/* ── Invoice modal ── */
#invoice_preview { padding: 0; background: var(--pharmacy-card-bg, #fff); }
/* modal animation — see css/style.css */
/* modal styles — see css/style.css */

/* ── Paid amount input ── */
.paid-required {
  border: 2px solid var(--pharmacy-border, #dee2e6) !important;
  background: var(--pharmacy-card-bg, #fff) !important;
  color: var(--pharmacy-text, #343a40) !important;
  transition: border-color 0.15s !important;
}
.paid-required:focus {
  border-color: var(--pharmacy-primary, #667eea) !important;
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--pharmacy-primary, #667eea) 12%, transparent) !important;
}

/* ── Form controls in cart ── */
.form-control, .form-control-sm {
  color: var(--pharmacy-text, #343a40) !important;
  background: var(--pharmacy-card-bg, #fff) !important;
  border-color: var(--pharmacy-border, #dee2e6) !important;
  transition: border-color 0.15s, box-shadow 0.15s !important;
}
.form-control:focus, .form-control-sm:focus {
  border-color: var(--pharmacy-primary, #667eea) !important;
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--pharmacy-primary, #667eea) 12%, transparent) !important;
}

/* ── Customer autocomplete ── */
#customer_autocomplete {
  background: var(--pharmacy-card-bg, #fff);
  border: 1.5px solid var(--pharmacy-primary, #667eea);
  border-top: none;
  border-radius: 0 0 6px 6px;
  z-index: 9999;
  max-height: 200px;
  overflow-y: auto;
  box-shadow: 0 4px 12px rgba(0,0,0,0.12);
}
.cust-ac-item { transition: background 0.12s; color: var(--pharmacy-text, #343a40); }
.cust-ac-item:hover { background: color-mix(in srgb, var(--pharmacy-primary, #667eea) 8%, var(--pharmacy-card-bg, #fff)) !important; }
.highlight-match { background: #fff3cd; padding: 0 1px; border-radius: 2px; font-weight: 800; }
.cust-due-badge {
  background: #dc3545; color: #fff;
  border-radius: 20px; padding: 2px 8px;
  font-size: 10px; font-weight: 600;
}

/* ── Barcode scan status ── */
#barcode_toast {
  position: fixed; top: 70px; left: 50%;
  transform: translateX(-50%); z-index: 9999;
  min-width: 260px; display: none;
}

/* ── Thermal print area ── */
#thermal-print-area { display: none; }
@media print {
  body * { visibility: hidden; }
  #thermal-print-area, #thermal-print-area * { visibility: visible; }
  #thermal-print-area {
    position: fixed; left: 0; top: 0; width: 80mm;
    font-family: 'Courier New', Courier, monospace; font-size: 11px;
  }
  @page { size: 80mm auto; margin: 0; }
}

/* ── General button transitions ── */
.btn { transition: all 0.18s ease; }

/* ── Scrollbar styling ── */
.cart-items::-webkit-scrollbar,
.products-container::-webkit-scrollbar,
.recent-sales::-webkit-scrollbar { width: 5px; }
.cart-items::-webkit-scrollbar-thumb,
.products-container::-webkit-scrollbar-thumb,
.recent-sales::-webkit-scrollbar-thumb {
  background: color-mix(in srgb, var(--pharmacy-border, #dee2e6) 80%, transparent);
  border-radius: 3px;
}
</style>
</head>
<?php
$__dm = !empty($_SESSION["company_settings_cache"]["dark_mode"]) ? " dark-mode" : "";
?>
<body class="<?php echo trim("bg-light" . $__dm); ?>">

<?php include '../includes/sidebar.php'; ?>
<?php include '../includes/navbar.php'; ?>

<div class="main-content">
<div class="container-fluid">

<!-- ── Stats Bar ── -->
<div class="stats-bar">
    <div class="stat-card stat-invoices">
        <small>Today's Invoices</small>
        <h4><?php echo $todaySales['total_invoices'] ?? 0; ?></h4>
    </div>
    <div class="stat-card stat-sales">
        <small>Today's Sales</small>
        <h4><?= htmlspecialchars($currency) ?> <?php echo number_format($todaySales['total_amount'] ?? 0, 0); ?></h4>
    </div>
    <div class="stat-card stat-cash">
        <small>Cash</small>
        <h4><?= htmlspecialchars($currency) ?> <?php echo number_format($todaySales['cash_amount'] ?? 0, 0); ?></h4>
    </div>
    <div class="stat-card stat-card-pay">
        <small>Card / Online</small>
        <h4><?= htmlspecialchars($currency) ?> <?php echo number_format(($todaySales['card_amount'] ?? 0) + ($todaySales['online_amount'] ?? 0), 0); ?></h4>
    </div>
    <div class="stat-card stat-credit">
        <small>Credit</small>
        <h4><?= htmlspecialchars($currency) ?> <?php echo number_format($todaySales['credit_amount'] ?? 0, 0); ?></h4>
    </div>
</div>

<div class="pos-two-columns">

<!-- ══ LEFT: Product Search & List ══ -->
<div class="left-column">
    <!-- Barcode scanner input -->
    <div class="search-box barcode-box">
        <div class="search-input-wrap">
            <input type="text" id="barcode_scan_input"
                   placeholder="📷 Scan barcode here (auto-focus)…"
                   autocomplete="off" autocorrect="off" spellcheck="false"
                   style="border-color:var(--pharmacy-primary,#667eea);font-family:monospace;letter-spacing:1px;">
            <span id="barcode_scan_status" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);font-size:12px;color:#198754;display:none;">
                <i class="fas fa-check-circle"></i>
            </span>
        </div>
        <div class="search-hint">
            <i class="fas fa-info-circle"></i> Barcode scanner Enter press kare — product auto-add hoga
        </div>
    </div>

    <!-- Text search input -->
    <div class="search-box text-box">
        <div class="search-input-wrap">
            <input type="text" id="product_search"
                   placeholder="🔍 Search by name, brand, generic, strength, size, code…"
                   autocomplete="off">
            <span class="search-clear" id="search_clear" title="Clear">✕</span>
        </div>
        <!-- Phase 24: Search Mode Tabs -->
        <div id="search_mode_tabs" style="display:flex;gap:4px;padding:5px 0 3px;flex-wrap:wrap;">
            <button type="button" class="srch-tab srch-tab-active" data-mode="all"
                    title="Search all fields (default)"
                    style="font-size:11px;padding:2px 9px;border-radius:12px;border:1px solid var(--pharmacy-primary,#667eea);background:var(--pharmacy-primary,#667eea);color:#fff;cursor:pointer;transition:all .15s;">
                All
            </button>
            <button type="button" class="srch-tab" data-mode="generic"
                    title="Search generic name only (or type g: prefix)"
                    style="font-size:11px;padding:2px 9px;border-radius:12px;border:1px solid var(--pharmacy-primary,#667eea);background:transparent;color:var(--pharmacy-primary,#667eea);cursor:pointer;transition:all .15s;">
                Generic
            </button>
            <button type="button" class="srch-tab" data-mode="brand"
                    title="Search brand name only (or type b: prefix)"
                    style="font-size:11px;padding:2px 9px;border-radius:12px;border:1px solid var(--pharmacy-primary,#667eea);background:transparent;color:var(--pharmacy-primary,#667eea);cursor:pointer;transition:all .15s;">
                Brand
            </button>
            <button type="button" class="srch-tab" data-mode="code"
                    title="Search product code / barcode (or type # prefix)"
                    style="font-size:11px;padding:2px 9px;border-radius:12px;border:1px solid var(--pharmacy-primary,#667eea);background:transparent;color:var(--pharmacy-primary,#667eea);cursor:pointer;transition:all .15s;">
                Code
            </button>
            <span style="font-size:10px;color:color-mix(in srgb,var(--pharmacy-text,#343a40) 50%,transparent);align-self:center;margin-left:4px;">
                Tips: <code style="font-size:10px;">g:</code> generic &nbsp;
                <code style="font-size:10px;">b:</code> brand &nbsp;
                <code style="font-size:10px;">#</code> code
            </span>
        </div>
        <div class="search-count" id="search_count"></div>
    </div>

    <!-- Products list -->
    <div class="products-container">
        <div class="products-header">
            <div>Product Name</div>
            <div>Form / Size</div>
            <div>Unit</div>
            <div>Stock</div>
            <div>Price / Batches</div>
        </div>
        <div id="products_list">
            <div class="text-center py-5">
                <div class="spinner-border text-primary"></div>
                <p class="mt-2" style="color:color-mix(in srgb,var(--pharmacy-text,#343a40) 55%,transparent)">Loading products…</p>
            </div>
        </div>
    </div>
</div>

<!-- ══ RIGHT: Cart ══ -->
<div class="right-column">
    <!-- Cart card -->
    <div class="cart-container">
        <div class="cart-header">
            <div class="cart-title">
                <i class="fas fa-shopping-cart"></i>
                Cart / Current Sale
            </div>
            <span class="badge" style="background:rgba(255,255,255,0.2);color:#fff;font-weight:600;" id="cart_item_count">0 items</span>
        </div>

        <!-- Cart items -->
        <div id="cart_items" class="cart-items">
            <div class="cart-empty">
                <i class="fas fa-cart-plus fa-2x mb-2 d-block"></i>
                Cart empty — add products from the left
            </div>
        </div>

        <!-- Cart totals & checkout -->
        <div class="cart-totals">
            <!-- Subtotal -->
            <div class="total-line">
                <span>Subtotal:</span>
                <strong id="cart_subtotal"><?= htmlspecialchars($currency) ?> 0.00</strong>
            </div>

            <!-- Discount row -->
            <div class="disc-tax-row">
                <span class="disc-tax-label">Overall Disc:</span>
                <input type="number" id="overall_discount_pct" class="disc-tax-pct"
                       value="0" min="0" max="100" step="0.5" oninput="onODiscPct()">
                <span class="disc-tax-unit">%</span>
                <span class="disc-tax-sep">|</span>
                <input type="number" id="overall_discount_rs_inp" class="disc-tax-rs"
                       value="0" min="0" step="0.5" oninput="onODiscRs()">
                <span id="overall_discount_rs" class="disc-tax-result text-danger">
                    -<?= htmlspecialchars($currency) ?> 0.00
                </span>
            </div>

            <!-- Tax row -->
            <div class="disc-tax-row">
                <span class="disc-tax-label">Overall Tax:</span>
                <input type="number" id="overall_tax_pct" class="disc-tax-pct"
                       value="<?php echo $defaultTax; ?>" min="0" max="100" step="0.5" oninput="onOTaxPct()">
                <span class="disc-tax-unit">%</span>
                <span class="disc-tax-sep">|</span>
                <input type="number" id="overall_tax_rs_inp" class="disc-tax-rs"
                       value="0" min="0" step="0.5" oninput="onOTaxRs()">
                <span id="overall_tax_rs" class="disc-tax-result text-success">
                    +<?= htmlspecialchars($currency) ?> 0.00
                </span>
            </div>

            <!-- Grand total — prominent -->
            <div class="grand-total-line">
                <strong>Grand Total:</strong>
                <strong class="grand-total-amount" id="cart_total"><?= htmlspecialchars($currency) ?> 0.00</strong>
            </div>

            <!-- Payment method -->
            <div class="mb-2 mt-2">
                <label class="small fw-bold" style="color:var(--pharmacy-heading,#212529)">Payment Method</label>
                <div class="pay-methods">
                    <div class="pay-method-btn active-cash" id="pm_cash" onclick="setPayMethod('cash')">
                        <i class="fas fa-money-bill-wave"></i> Cash
                    </div>
                    <div class="pay-method-btn" id="pm_card" onclick="setPayMethod('card')">
                        <i class="fas fa-credit-card"></i> Card
                    </div>
                    <div class="pay-method-btn" id="pm_online" onclick="setPayMethod('online')">
                        <i class="fas fa-mobile-alt"></i> Online
                    </div>
                    <div class="pay-method-btn" id="pm_credit" onclick="setPayMethod('credit')">
                        <i class="fas fa-file-invoice"></i> Credit
                    </div>
                </div>
                <input type="hidden" id="payment_method" value="cash">
            </div>

            <!-- Customer panel -->
            <div id="customer_panel" style="display:block;margin-top:6px;">
                <div class="customer-panel-box">
                    <div class="customer-panel-label">
                        <i class="fas fa-user-circle" style="color:var(--pharmacy-primary,#667eea)"></i>
                        <span id="customer_panel_label">Customer Details (Optional)</span>
                        <span id="customer_panel_required"
                              style="color:#dc3545;font-size:10px;margin-left:4px;display:none;">* Required</span>
                    </div>
                    <div style="position:relative;margin-bottom:6px;">
                        <input type="text" id="customer_name"
                               class="form-control form-control-sm"
                               placeholder="Customer Name (optional)"
                               autocomplete="off"
                               oninput="onCustomerNameInput(this)"
                               onkeyup="onCustomerNameInput(this)"
                               onfocus="if(this.value.trim().length>0)onCustomerNameInput(this)">
                        <div id="customer_autocomplete"
                             style="display:none;position:absolute;top:100%;left:0;right:0;"></div>
                        <div id="existing_due_reminder"
                             style="display:none;font-size:10px;color:#dc3545;margin-top:2px;padding:2px 4px;background:color-mix(in srgb,#dc3545 8%,var(--pharmacy-card-bg,#fff));border-radius:4px;">
                        </div>
                    </div>
                    <input type="text" id="customer_phone"
                           class="form-control form-control-sm mb-1"
                           placeholder="Phone (optional)"
                           oninput="calcBalance()">
                    <div id="due_date_wrap" style="display:none;margin-top:4px;">
                        <label style="font-size:10px;color:color-mix(in srgb,var(--pharmacy-text,#343a40) 65%,transparent);margin-bottom:2px;display:block;">
                            <i class="fas fa-calendar-alt me-1"></i>Due Date *
                        </label>
                        <input type="date" id="due_date"
                               class="form-control form-control-sm"
                               value="" min="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>
            </div>

            <!-- Partial payment alert -->
            <div id="partial_alert" style="display:none;margin-top:5px;" class="alert alert-warning py-1 px-2 mb-0">
                <i class="fas fa-exclamation-triangle"></i>
                <small> <strong>Partial Payment</strong> — remaining balance will be saved as credit.
                  <span id="partial_balance_detail" style="display:block;margin-top:2px;font-size:10px;"></span>
                </small>
            </div>

            <!-- Paid amount -->
            <div class="total-line mt-2">
                <span style="font-size:12px;color:var(--pharmacy-text,#343a40)">
                    Paid Amount (<?= htmlspecialchars($currency) ?>):
                    <span class="text-danger">*</span>
                </span>
                <input type="number" id="paid_amount"
                       class="form-control form-control-sm paid-required"
                       style="width:110px;"
                       value="0" min="0" step="1"
                       oninput="calcBalance()"
                       placeholder="Enter amount">
            </div>

            <!-- Balance -->
            <div class="total-line">
                <span style="font-size:12px;color:var(--pharmacy-text,#343a40)">Balance:</span>
                <span id="balance_display" class="balance-due"><?= htmlspecialchars($currency) ?> 0.00 Due</span>
            </div>

            <!-- Action buttons -->
            <div class="d-flex gap-2 mt-3">
                <button class="btn-new-sale flex-grow-1" onclick="newSale()">
                    <i class="fas fa-redo me-1"></i> New Sale
                </button>
                <button class="btn-generate-invoice flex-grow-1" onclick="showInvoicePreview()">
                    <i class="fas fa-receipt me-1"></i> Generate Invoice
                </button>
            </div>
        </div>
    </div>

    <!-- Recent Sales -->
    <div class="recent-sales">
        <div class="recent-header">
            <span><i class="fas fa-history me-1"></i> Recent Sales</span>
            <a href="<?php echo BASE_URL; ?>sales/list.php"
               style="font-size:11px;color:rgba(255,255,255,0.65);text-decoration:none;"
               onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,0.65)'">
                View All →
            </a>
        </div>
        <?php foreach($recentSales as $sale): ?>
        <div class="recent-item">
            <div onclick="window.open('print_invoice.php?id=<?php echo $sale['id']; ?>', '_blank', 'width=420,height=650')"
                 style="flex:1;cursor:pointer;">
                <b style="color:var(--pharmacy-heading,#212529)"><?php echo htmlspecialchars($sale['invoice_no']); ?></b>
                <small style="color:color-mix(in srgb,var(--pharmacy-text,#343a40) 55%,transparent);margin-left:4px;">
                    <?php echo htmlspecialchars($sale['customer_name']); ?>
                </small>
            </div>
            <div class="text-end d-flex align-items-center gap-1">
                <span style="color:var(--pharmacy-text,#343a40)"><?= htmlspecialchars($currency) ?> <?php echo number_format($sale['total'], 0); ?></span>
                <a href="<?php echo BASE_URL; ?>sales/invoice_pdf.php?id=<?php echo $sale['id']; ?>&view=1"
                   target="_blank"
                   class="badge bg-dark text-decoration-none"
                   title="A4 Invoice / PDF"
                   onclick="event.stopPropagation();"
                   style="font-size:9px;padding:3px 5px;">
                    <i class="fas fa-file-pdf"></i>
                </a>
                <a href="<?php echo BASE_URL; ?>returns/sale_return.php?invoice_no=<?php echo urlencode($sale['invoice_no']); ?>"
                   class="badge bg-warning text-dark text-decoration-none"
                   title="Return this sale"
                   onclick="event.stopPropagation();"
                   style="font-size:9px;padding:3px 5px;">
                    <i class="fas fa-undo-alt"></i>
                </a>
                <a href="<?php echo BASE_URL; ?>sales/view.php?id=<?php echo $sale['id']; ?>"
                   class="<?php echo $sale['payment_method'] === 'credit' ? 'credit-badge' : 'badge bg-secondary text-decoration-none'; ?>"
                   title="View Invoice"
                   onclick="event.stopPropagation();">
                    <?php echo strtoupper($sale['payment_method']); ?>
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<!-- end right-column -->

</div><!-- end pos-two-columns -->
</div><!-- end container-fluid -->
</div><!-- end main-content -->

<!-- ══ Invoice Preview Modal ══ -->
<div class="modal fade" id="invoiceModal" tabindex="-1" aria-labelledby="invoiceModalLabel" aria-modal="true" role="dialog">
    <div class="modal-dialog modal-dialog-centered modal-440">
        <div class="modal-content">
            <div class="modal-header">
                <h6 id="invoiceModalLabel" class="modal-title"><i class="fas fa-receipt me-2"></i>Invoice Preview</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body modal-receipt">
                <div id="invoice_preview" style="padding:12px;"></div>
            </div>
            <div class="modal-footer" style="background:var(--pharmacy-card-bg,#fff);border-top:1px solid var(--pharmacy-border,#dee2e6)">
                <input type="hidden" id="csrf_token_field" value="<?php echo generateCSRFToken(); ?>">
                <button class="btn btn-danger btn-sm" data-bs-dismiss="modal" onclick="cancelInvoice()">
                    <i class="fas fa-times me-1"></i>Cancel
                </button>
                <button class="btn btn-outline-secondary btn-sm" id="btn_print_thermal" onclick="printThermalDirect()" title="Print 80mm Thermal Receipt" disabled>
                    <i class="fas fa-print me-1"></i>Thermal
                </button>
                <button class="btn btn-outline-primary btn-sm" id="btn_view_a4" onclick="viewA4Invoice()" title="View / Print A4 Invoice" disabled>
                    <i class="fas fa-file-invoice me-1"></i>A4
                </button>
                <button class="btn btn-outline-success btn-sm" id="btn_pdf_download" onclick="downloadPdf()" title="Save as PDF" disabled>
                    <i class="fas fa-file-pdf me-1"></i>PDF
                </button>
                <button class="btn btn-success btn-sm" onclick="confirmInvoice()">
                    <i class="fas fa-check me-1"></i>Confirm &amp; Print
                </button>
            </div>
        </div>
    </div>
</div>

<div id="thermal-print-area"></div>

<script src="../js/autosave.js"></script>
<script>
/* ════════════════════════════════════════════════════════════
   POS — FULLY SYNCHRONIZED WITH COMPANY SETTINGS
   FIXES:
    1. defaultTax from company_settings (not system_preferences)
    2. currency symbol from company_settings used everywhere
    3. buildLocalThermalPreview() uses pharmacy name, not "PHARMACY"
    4. near_expiry_days from company_settings for badge coloring
════════════════════════════════════════════════════════════ */

var defaultTax      = <?php echo $defaultTax; ?>;
var allProducts     = [];
var productStockMap = {};
var cartState = [];
var _custCache = <?php echo $creditCustomersJson; ?>;

// Company settings object — synced from PHP
var COMPANY = <?php echo $companySettingsJson; ?>;
var CURRENCY = COMPANY.currency || 'Rs';

function esc(t){
    return t ? String(t).replace(/[&<>"']/g, function(m){
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m];
    }) : '';
}

/* ════════════════════════════════════════════════
   PRODUCT LIST — 5 COLUMNS
════════════════════════════════════════════════ */
function renderProductList(products){
    if(!products || products.length === 0){
        $('#products_list').html('<div class="text-center py-5" style="color:color-mix(in srgb,var(--pharmacy-text,#343a40) 45%,transparent)"><i class="fas fa-search fa-2x mb-2 d-block"></i>No products found</div>');
        return;
    }
    var html = '';
    for(var i = 0; i < products.length; i++){
        var p        = products[i];
        var isMed    = (parseInt(p.category_id) === 1);
        var upp      = parseInt(p.units_per_pack) || 1;
        var hasPack  = upp > 1;
        var pid      = p.id;
        var stockPcs = parseInt(p.total_stock) || 0;
        var batchCount = parseInt(p.active_batch_count) || 0;

        var nameHtml = '<div class="product-name">'+esc(p.product_name)+'</div>';
        if(isMed){
            var generic = esc(p.generic_name || '');
            var brand   = esc(p.brand_name || '');
            var sub = [];
            if(generic) sub.push(generic);
            if(brand) sub.push('['+brand+']');
            if(sub.length) nameHtml += '<div class="product-sub">'+sub.join(' ')+'</div>';
        } else {
            var brand2 = esc(p.brand_name || '');
            if(brand2) nameHtml += '<div class="product-sub">['+brand2+']</div>';
        }

        var formHtml = '';
        if(isMed){
            var form = esc(p.dosage_form || '');
            var str  = esc(p.strength || '');
            if(form) formHtml += '<div class="product-name">'+form+'</div>';
            if(str)  formHtml += '<div class="product-sub">'+str+'</div>';
            if(!form && !str) formHtml = '<span style="color:color-mix(in srgb,var(--pharmacy-text,#343a40) 40%,transparent)">—</span>';
        } else {
            var sz = esc(p.size_value || p.strength || '');
            formHtml = '<div class="product-name">'+(sz?sz:'—')+'</div>';
        }

        var unitHtml = hasPack ? upp+'/'+esc(p.unit_type||'Pcs') : esc(p.unit_type||'Pcs');

        var stockCls = stockPcs <= 0 ? 'stock-out' : (stockPcs <= COMPANY.low_stock_thresh ? 'stock-low' : 'stock-normal');
        var stockHtml = '';
        if(stockPcs <= 0){
            stockHtml = '<span class="stock-out">Out of Stock</span>';
        } else if(hasPack){
            var fullPacks = Math.floor(stockPcs / upp);
            var leftPcs   = stockPcs % upp;
            var parts = [];
            if(fullPacks > 0) parts.push(fullPacks+' Pack'+(fullPacks>1?'s':''));
            if(leftPcs  > 0)  parts.push(leftPcs+' Pc'+(leftPcs>1?'s':''));
            var topLine = parts.join(' + ');
            stockHtml = '<div class="'+stockCls+'" style="line-height:1.3;">'
                +'<span style="font-size:11px;font-weight:700;">'+topLine+'</span>'
                +'<br><span style="font-size:9px;color:color-mix(in srgb,var(--pharmacy-text,#343a40) 55%,transparent);">('+stockPcs+' Pcs)</span>'
                +'</div>';
        } else {
            stockHtml = '<div class="'+stockCls+'" style="font-size:12px;font-weight:700;">'+stockPcs+' Pcs</div>';
        }

        var unitPrice = parseFloat(p.near_expiry_price || p.sale_price || p.price || 0);
        var packPrice = hasPack ? (unitPrice * upp).toFixed(2) : 0;

        // Near-expiry indicator using company setting
        var nearExpBadge = '';
        if(p.near_expiry_days !== null && p.near_expiry_days <= COMPANY.near_expiry_days){
            nearExpBadge = '<span style="font-size:8px;background:color-mix(in srgb,#ffc107 18%,transparent);color:#856404;border-radius:3px;padding:1px 4px;margin-left:3px;">Near Expiry</span>';
        }

        var priceHtml = '<div style="font-size:11px;font-weight:700;color:#198754;">'+CURRENCY+' '+unitPrice.toFixed(2)+nearExpBadge+'</div>';
        var toggleHtml = '';
        if(hasPack){
            toggleHtml = '<div class="mode-toggle" id="mgt_'+pid+'">'
                +'<span class="active" data-mode="piece" onclick="selectMode(event,'+pid+',\'piece\','+unitPrice+','+packPrice+')">Piece</span>'
                +'<span data-mode="pack"  onclick="selectMode(event,'+pid+',\'pack\','+unitPrice+','+packPrice+')">Pack</span>'
                +'</div>';
        } else {
            toggleHtml = '<div style="font-size:9px;color:color-mix(in srgb,var(--pharmacy-text,#343a40) 50%,transparent);">Per Pc</div>';
        }
        var batchInfo = batchCount > 0 ? '<div style="font-size:9px;color:color-mix(in srgb,var(--pharmacy-text,#343a40) 55%,transparent);">'+batchCount+' batch'+(batchCount>1?'es':'')+'</div>' : '';

        html += '<div class="product-row" id="prow_'+pid+'" data-pid="'+pid
            +'" data-price="'+unitPrice+'" data-pack-price="'+packPrice
            +'" data-upp="'+upp+'" data-mode="piece" onclick="handleProductRowClick(event,'+pid+','+unitPrice+')">'
            +'<div class="product-col">'+nameHtml+'</div>'
            +'<div class="product-col">'+formHtml+'</div>'
            +'<div class="product-col">'+unitHtml+'</div>'
            +'<div class="product-col">'+stockHtml+'</div>'
            +'<div class="product-col">'+priceHtml+toggleHtml+batchInfo+'</div>'
        +'</div>';
    }
    $('#products_list').html(html);
}

function selectMode(evt, pid, mode, piecePrice, packPrice){
    evt.stopPropagation();
    var $row = $('#prow_'+pid);
    var $tgl = $('#mgt_'+pid);
    $tgl.find('span').removeClass('active');
    $tgl.find('span[data-mode="'+mode+'"]').addClass('active');
    $row.attr('data-mode', mode);
    var usedPrice = (mode === 'pack') ? packPrice : piecePrice;
    $row.attr('data-price', usedPrice);
    $row.find('.product-col:last-child div:first-child').html(CURRENCY+' '+parseFloat(usedPrice).toFixed(2));
}

function handleProductRowClick(evt, pid, priceHint){
    if($(evt.target).closest('.mode-toggle').length) return;
    var $row  = $('#prow_'+pid);
    var mode  = $row.attr('data-mode') || 'piece';
    var price = parseFloat($row.attr('data-price')) || priceHint;
    addToCart(pid, price, 1, mode);
}

/* ════════════════════════════════════════════════
   PRODUCT SEARCH
════════════════════════════════════════════════ */
/* ── Phase 24 POS Audit: Enhanced search scoring ──────────────────────────
 * IMPROVEMENTS:
 *  1. Multi-word search: "panadol 500mg" matches products containing ALL words.
 *  2. Search mode prefix: "g:" = generic only, "b:" = brand only, "#" = code/barcode.
 *  3. Exact-word boundary bonus to avoid partial-word false positives.
 *  4. Strength field promoted to score 60 (pharmacists search by strength often).
 *  5. Composite field search: matches across concatenated name+strength+dosage.
 * ─────────────────────────────────────────────────────────────────────── */
var SEARCH_MODE = 'all'; // 'all' | 'generic' | 'brand' | 'code'

function scoreProduct(p, term){
    var name    = (p.product_name||'').toLowerCase();
    var code    = (p.product_code||'').toLowerCase();
    var generic = (p.generic_name||'').toLowerCase();
    var brand   = (p.brand_name||'').toLowerCase();
    var dosage  = (p.dosage_form||'').toLowerCase();
    var size    = (p.size_value||'').toLowerCase();
    var strength= (p.strength||'').toLowerCase();
    var composite = name + ' ' + strength + ' ' + dosage; // e.g. "panadol 500 tablet"

    // Search mode: restrict field
    if(SEARCH_MODE === 'generic') {
        if(generic.startsWith(term))      return 100;
        if(generic.indexOf(term) !== -1)  return 80;
        return -1;
    }
    if(SEARCH_MODE === 'brand') {
        if(brand.startsWith(term))        return 100;
        if(brand.indexOf(term) !== -1)    return 80;
        return -1;
    }
    if(SEARCH_MODE === 'code') {
        if(code === term)                 return 100;
        if(code.startsWith(term))         return 90;
        if(code.indexOf(term) !== -1)     return 70;
        return -1;
    }

    // Default 'all' mode
    if(name === term)                    return 110; // exact match
    if(name.startsWith(term))            return 100;
    if(composite.startsWith(term))       return 95;
    if(name.indexOf(term) !== -1)        return 80;
    if(code === term)                    return 78;  // exact code/barcode
    if(code.startsWith(term))            return 75;
    if(generic.startsWith(term))         return 65;
    if(strength.startsWith(term))        return 62;  // strength promoted
    if(generic.indexOf(term) !== -1)     return 55;
    if(brand.startsWith(term))           return 50;
    if(brand.indexOf(term) !== -1)       return 45;
    if(strength.indexOf(term) !== -1)    return 40;
    if(composite.indexOf(term) !== -1)   return 35;
    if(dosage.indexOf(term) !== -1)      return 30;
    if(size.indexOf(term) !== -1)        return 20;
    if(code.indexOf(term) !== -1)        return 10;
    return -1;
}

function filterProducts(term){
    if(!term || !term.trim()) return allProducts.slice();
    term = term.trim();

    // Detect search mode prefix
    SEARCH_MODE = 'all';
    if(term.toLowerCase().startsWith('g:')){ SEARCH_MODE='generic'; term=term.slice(2).trim(); }
    else if(term.toLowerCase().startsWith('b:')){ SEARCH_MODE='brand'; term=term.slice(2).trim(); }
    else if(term.startsWith('#')){ SEARCH_MODE='code'; term=term.slice(1).trim(); }

    if(!term) return allProducts.slice();

    // Multi-word: split by space — ALL words must match (AND logic)
    var words = term.toLowerCase().split(/\s+/).filter(function(w){ return w.length>0; });

    if(words.length <= 1){
        // Single term: fast scored path
        var t = words[0] || '';
        var scored = [];
        for(var i = 0; i < allProducts.length; i++){
            var s = scoreProduct(allProducts[i], t);
            if(s >= 0) scored.push({p: allProducts[i], s: s});
        }
        scored.sort(function(a,b){ return b.s - a.s; });
        return scored.map(function(x){ return x.p; });
    }

    // Multi-word: must pass ALL words, score = sum of individual scores
    var scored = [];
    for(var i = 0; i < allProducts.length; i++){
        var totalScore = 0;
        var allMatch   = true;
        for(var j = 0; j < words.length; j++){
            var ws = scoreProduct(allProducts[i], words[j]);
            if(ws < 0){ allMatch = false; break; }
            totalScore += ws;
        }
        if(allMatch) scored.push({p: allProducts[i], s: totalScore});
    }
    scored.sort(function(a,b){ return b.s - a.s; });
    return scored.map(function(x){ return x.p; });
}

function loadAllProducts(){
    $('#products_list').html('<div class="text-center py-4"><div class="spinner-border spinner-border-sm text-primary"></div> Loading\u2026</div>');
    $.ajax({
        url: '../ajax/search_product_pos.php',
        method: 'GET',
        data: {live: 1, instock: 1},
        success: function(response){
            var products;
            if(typeof response === 'string'){
                try {
                    var jsonStart = response.indexOf('[');
                    if(jsonStart === -1) throw new Error('No JSON');
                    products = JSON.parse(response.substring(jsonStart));
                } catch(e){
                    $('#products_list').html('<div class="text-center py-4 text-danger"><i class="fas fa-exclamation-triangle fa-2x mb-2 d-block"></i><b>Products load nahi hue</b><br><small>'+esc(response.substring(0,200))+'</small><br><br><a href="#" class="btn btn-sm btn-primary" onclick="loadAllProducts();return false;"><i class="fas fa-redo"></i> Retry</a></div>');
                    return;
                }
            } else {
                products = response;
            }
            if(!Array.isArray(products)){
                $('#products_list').html('<div class="text-center py-4 text-danger"><i class="fas fa-exclamation-triangle fa-2x mb-2 d-block"></i><b>Server Error</b><br><small>'+(products&&products.error?esc(products.error):'Invalid response')+'</small><br><br><a href="#" class="btn btn-sm btn-primary" onclick="loadAllProducts();return false;"><i class="fas fa-redo"></i> Retry</a></div>');
                return;
            }
            allProducts     = products;
            productStockMap = {};
            for(var i = 0; i < allProducts.length; i++){
                productStockMap[allProducts[i].id] = parseInt(allProducts[i].total_stock) || 0;
                if(allProducts[i].currency_symbol) CURRENCY = allProducts[i].currency_symbol;
            }
            renderProductList(allProducts);
            $('#search_count').text(allProducts.length + ' products loaded');
        },
        error: function(xhr){
            var code = xhr.status||0;
            var reason = code===404?'File not found: ajax/search_product_pos.php':code===500?'PHP Server Error (500)':code===0?'Server connection failed':'HTTP '+code;
            $('#products_list').html('<div class="text-center py-4 text-danger"><i class="fas fa-exclamation-triangle fa-2x mb-2 d-block"></i><b>Products load nahi hue</b><br><span>'+esc(reason)+'</span><br><br><a href="#" class="btn btn-sm btn-primary" onclick="loadAllProducts();return false;"><i class="fas fa-redo"></i> Retry</a></div>');
        }
    });
}

$('#product_search').on('input', function(){
    var val = $(this).val();
    $('#search_clear').css('display', val ? 'block' : 'none');
    if(allProducts.length === 0){ loadAllProducts(); return; }
    var filtered = filterProducts(val);
    renderProductList(filtered);
    $('#search_count').text(val.trim() ? filtered.length+' of '+allProducts.length+' products' : allProducts.length+' products loaded');
});

$('#search_clear').on('click', function(){
    $('#product_search').val('').trigger('input').focus();
    setSearchMode('all', document.querySelector('.srch-tab[data-mode="all"]'));
});

/* ── Phase 24: Search Mode Tab Handler ── */
function setSearchMode(mode, btn){
    SEARCH_MODE = mode;
    // Update tab styles
    document.querySelectorAll('.srch-tab').forEach(function(t){
        var isActive = (t.getAttribute('data-mode') === mode);
        t.style.background = isActive ? 'var(--pharmacy-primary,#667eea)' : 'transparent';
        t.style.color      = isActive ? '#fff' : 'var(--pharmacy-primary,#667eea)';
    });
    // Update placeholder hint
    var ph = {all:'🔍 Search by name, brand, generic, strength, size, code…',
              generic:'🔍 Generic name search (e.g. Paracetamol)…',
              brand:'🔍 Brand name search (e.g. GSK, Pfizer)…',
              code:'🔍 Product code / barcode search…'};
    $('#product_search').attr('placeholder', ph[mode] || ph['all']);
    // Re-filter current input
    $('#product_search').trigger('input').focus();
}

// Keyboard shortcut: Alt+G=generic, Alt+B=brand, Alt+C=code, Alt+A=all
$(document).on('keydown', function(e){
    if(!e.altKey) return;
    var modeMap = {KeyG:'generic', KeyB:'brand', KeyC:'code', KeyA:'all'};
    var m = modeMap[e.code];
    if(m){ e.preventDefault(); setSearchMode(m, document.querySelector('.srch-tab[data-mode="'+m+'"]')); }
});

/* ════════════════════════════════════════════════
   CART FUNCTIONS
════════════════════════════════════════════════ */
function usedPiecesForProduct(pid, excludeIdx){
    var used = 0;
    for(var i = 0; i < cartState.length; i++){
        if(cartState[i].product_id !== pid) continue;
        if(String(cartState[i].index) === String(excludeIdx)) continue;
        used += parseInt(cartState[i].qty_pieces) || 0;
    }
    return used;
}

function renderCart(){
    if(!cartState || cartState.length === 0){
        $('#cart_items').html('<div class="cart-empty"><i class="fas fa-cart-plus fa-2x mb-2 d-block"></i>Cart empty — add products from the left</div>');
        $('#cart_item_count').text('0 items');
        recalcTotals();
        return;
    }

    var grouped  = {};
    var pidOrder = [];
    for(var i = 0; i < cartState.length; i++){
        var it  = cartState[i];
        var pid = it.product_id;
        if(!grouped[pid]){ grouped[pid] = {packItem:null, pieceItem:null}; pidOrder.push(pid); }
        if((it.sell_mode||'piece') === 'pack') grouped[pid].packItem  = it;
        else                                    grouped[pid].pieceItem = it;
    }

    var html  = '';
    var count = 0;

    for(var g = 0; g < pidOrder.length; g++){
        var pid  = pidOrder[g];
        var grp  = grouped[pid];
        var ref  = grp.packItem || grp.pieceItem;
        var isMed= (parseInt(ref.category_id) === 1);
        var upp  = parseInt(ref.units_per_pack) || 1;
        var stockPcs = ref.available_stock || productStockMap[pid] || 9999;
        var maxPacks = Math.floor(stockPcs / upp);

        count += (grp.packItem ? 1 : 0) + (grp.pieceItem ? 1 : 0);

        var detailLine = '';
        if(isMed){
            var gn  = ref.generic_name ? '<span style="font-weight:600;color:var(--pharmacy-primary,#1a5276);">'+esc(ref.generic_name)+'</span>' : '';
            var fp2 = ref.dosage_form  ? esc(ref.dosage_form) : '';
            var sp3 = ref.strength     ? esc(ref.strength)    : '';
            var sub = [fp2,sp3].filter(Boolean).join(' ');
            if(gn||sub) detailLine = '<div class="cart-item-detail">'+gn+(sub?' <small>&middot; '+sub+'</small>':'')+'</div>';
        } else {
            var bn = ref.brand_name ? '<span style="font-weight:600;color:var(--pharmacy-heading,#212529);">'+esc(ref.brand_name)+'</span>' : '';
            var sv = ref.size_value ? ' <small>&middot; '+esc(ref.size_value)+'</small>' : '';
            if(bn||sv) detailLine = '<div class="cart-item-detail">'+bn+sv+'</div>';
        }

        var sCls = stockPcs <= 0 ? 'stock-out' : (stockPcs <= COMPANY.low_stock_thresh ? 'stock-low' : 'stock-normal');
        var sBadge = '';
        if(upp > 1){
            var fp3 = Math.floor(stockPcs / upp);
            var lp  = stockPcs % upp;
            var sPts = [];
            if(fp3 > 0) sPts.push(fp3+' Pack'+(fp3>1?'s':''));
            if(lp  > 0) sPts.push(lp+' Pc'+(lp>1?'s':''));
            sBadge = '<span class="'+sCls+'" style="font-size:10px;font-weight:700;white-space:nowrap;">'
                +(sPts.length?sPts.join(' + '):'0')
                +' <small style="font-weight:400;">('+stockPcs+' Pcs)</small></span>';
        } else {
            sBadge = '<span class="'+sCls+'" style="font-size:10px;font-weight:700;">'+stockPcs+' Pcs</span>';
        }

        html += '<div class="cart-item" id="ci_block_'+pid+'">'
            +'<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:3px;">'
                +'<div style="flex:1;min-width:0;">'
                    +'<div class="cart-item-name">'+esc(ref.product_name)+'</div>'
                    +detailLine
                +'</div>'
                +'<div style="text-align:right;flex-shrink:0;padding-left:6px;padding-top:1px;">'+sBadge+'</div>'
            +'</div>';

        function buildModeRow(modeItem, mode){
            if(!modeItem) return '';
            var midx  = String(modeItem.index);
            var uppR  = parseInt(modeItem.units_per_pack) || 1;
            var mBase = modeItem.price * modeItem.quantity;
            var mDisc = mBase * (modeItem.item_discount||0) / 100;
            var mTot  = mBase - mDisc;
            var otherPcs = usedPiecesForProduct(pid, midx);
            var avail    = Math.max(0, stockPcs - otherPcs);
            var maxQ     = mode === 'pack' ? Math.floor(avail / uppR) : avail;
            var badge    = mode === 'pack'
                ? '<span class="sell-mode-badge sell-mode-pack" style="display:inline-block;min-width:54px;text-align:center;font-size:9px;white-space:nowrap;">PACK\xd7'+uppR+'</span>'
                : '<span class="sell-mode-badge sell-mode-piece" style="display:inline-block;min-width:54px;text-align:center;font-size:9px;">PIECE</span>';
            var priceCalc = '<span class="ci-price-label" style="font-size:9px;color:color-mix(in srgb,var(--pharmacy-text,#343a40) 65%,transparent);white-space:nowrap;">\xd7'+CURRENCY+' '+parseFloat(modeItem.price).toFixed(2)+'</span>';
            return '<div id="ci_'+midx+'" style="display:grid;grid-template-columns:54px 24px 58px 24px 26px auto 16px 34px 1fr 26px;align-items:center;gap:2px;padding:3px 0 3px 2px;border-top:1px dashed var(--pharmacy-border,#eee);min-width:0;overflow-x:auto;">'
                +badge
                +'<button type="button" class="qty-btn" data-action="minus" data-idx="'+midx+'" style="width:24px;height:24px;font-size:13px;">&#8722;</button>'
                +'<input type="number" class="qty-input" data-idx="'+midx+'" data-stock="'+maxQ+'" data-pid="'+pid+'" data-mode="'+mode+'" value="'+modeItem.quantity+'" min="1" max="'+maxQ+'" style="width:58px;text-align:center;border:1px solid var(--pharmacy-border,#ddd);border-radius:4px;font-size:12px;padding:2px;background:var(--pharmacy-card-bg,#fff);color:var(--pharmacy-text,#343a40);" oninput="onQtyInput(\''+midx+'\',this)">'
                +'<button type="button" class="qty-btn" data-action="plus" data-idx="'+midx+'" style="width:24px;height:24px;font-size:13px;">+</button>'
                +'<span style="font-size:10px;color:color-mix(in srgb,var(--pharmacy-text,#343a40) 45%,transparent);text-align:center;white-space:nowrap;">/'+maxQ+'</span>'
                +priceCalc
                +'<span style="font-size:10px;color:color-mix(in srgb,var(--pharmacy-text,#343a40) 55%,transparent);text-align:right;">D%</span>'
                +'<input type="number" class="item-disc-input" data-idx="'+midx+'" value="'+(modeItem.item_discount||0)+'" min="0" max="100" style="width:34px;border:1px solid var(--pharmacy-border,#ddd);border-radius:4px;font-size:11px;text-align:center;padding:2px;background:var(--pharmacy-card-bg,#fff);color:var(--pharmacy-text,#343a40);" oninput="onDiscInput(\''+midx+'\',this)">'
                +'<span class="cart-item-total" id="ci_total_'+midx+'" style="text-align:right;font-weight:700;font-size:12px;white-space:nowrap;padding-left:3px;">'+CURRENCY+' '+mTot.toFixed(2)+'</span>'
                +'<span class="remove-btn" data-idx="'+midx+'" title="Remove" style="width:24px;height:24px;font-size:11px;margin-left:2px;flex-shrink:0;"><i class="fas fa-times"></i></span>'
            +'</div>';
        }

        html += buildModeRow(grp.packItem,  'pack');
        html += buildModeRow(grp.pieceItem, 'piece');
        html += '</div>';
    }

    $('#cart_items').html(html);
    $('#cart_item_count').text(count+' item'+(count>1?'s':''));
    recalcTotals();
}

$(document).on('click', '.remove-btn', function(e){
    e.preventDefault(); e.stopPropagation();
    var idx = String($(this).data('idx'));
    removeFromCart(idx);
});

$(document).on('click', '.qty-btn', function(e){
    e.preventDefault();
    var action = $(this).data('action');
    var idx    = String($(this).data('idx'));
    changeQty(idx, action === 'plus' ? 1 : -1);
});

function stateItem(idx){
    idx = String(idx);
    for(var i = 0; i < cartState.length; i++){
        if(String(cartState[i].index) === idx) return cartState[i];
    }
    return null;
}

function recalcTotals(){
    var subtotal = 0;
    for(var i = 0; i < cartState.length; i++){
        var it      = cartState[i];
        var itemDisc = parseFloat(it.item_discount) || 0;
        var batches  = it.batches || [];
        var upp      = parseInt(it.units_per_pack)  || 1;
        var mode     = it.sell_mode || 'piece';
        var qtyPcs   = parseInt(it.qty_pieces) || (it.quantity * (mode==='pack' ? upp : 1));
        if(batches.length > 0){
            var rem = qtyPcs;
            for(var b = 0; b < batches.length && rem > 0; b++){
                var batchAvail = parseInt(batches[b].remaining_quantity) || 0;
                var take = Math.min(rem, batchAvail);
                if(take <= 0) continue;
                var bPrice   = parseFloat(batches[b].selling_price) || parseFloat(it.price) || 0;
                var lineBase = take * bPrice;
                var after    = lineBase * (1 - itemDisc/100);
                subtotal    += after;
                rem -= take;
            }
            if(rem > 0){
                var base2  = rem * (parseFloat(it.price) || 0);
                var after2 = base2 * (1 - itemDisc/100);
                subtotal  += after2;
            }
        } else {
            var base  = (parseFloat(it.price) || 0) * it.quantity;
            var after = base * (1 - itemDisc/100);
            subtotal += after;
        }
    }
    var oPct   = parseFloat($('#overall_discount_pct').val()) || 0;
    var oTax   = parseFloat($('#overall_tax_pct').val()) || 0;
    var oDisc  = subtotal * oPct / 100;
    var afterO = subtotal - oDisc;
    var oTaxA  = afterO * oTax / 100;
    var grand  = afterO + oTaxA;
    $('#cart_subtotal').text(CURRENCY+' '+subtotal.toFixed(2));
    $('#overall_discount_rs').text('-'+CURRENCY+' '+oDisc.toFixed(2));
    $('#overall_tax_rs').text('+'+CURRENCY+' '+oTaxA.toFixed(2));
    var dInp = document.getElementById('overall_discount_rs_inp');
    var tInp = document.getElementById('overall_tax_rs_inp');
    if(dInp && document.activeElement !== dInp) dInp.value = oDisc.toFixed(2);
    if(tInp && document.activeElement !== tInp) tInp.value = oTaxA.toFixed(2);
    $('#cart_total').text(CURRENCY+' '+grand.toFixed(2));
    calcBalance();
}

function onODiscPct(){ recalcTotals(); }
function onOTaxPct(){ recalcTotals(); }

function onODiscRs(){
    var sub = getSubtotalFromCart();
    if(sub <= 0){ recalcTotals(); return; }
    var rs     = parseFloat($('#overall_discount_rs_inp').val()) || 0;
    var oTax   = parseFloat($('#overall_tax_pct').val()) || 0;
    var afterO = sub - rs;
    var oTaxA  = afterO * oTax / 100;
    var grand  = afterO + oTaxA;
    $('#overall_discount_pct').val((rs / sub * 100).toFixed(2));
    $('#cart_subtotal').text(CURRENCY+' '+sub.toFixed(2));
    $('#overall_discount_rs').text('-'+CURRENCY+' '+rs.toFixed(2));
    $('#overall_tax_rs').text('+'+CURRENCY+' '+oTaxA.toFixed(2));
    var tInp = document.getElementById('overall_tax_rs_inp');
    if(tInp && document.activeElement !== tInp) tInp.value = oTaxA.toFixed(2);
    $('#cart_total').text(CURRENCY+' '+grand.toFixed(2));
    calcBalance();
}

function onOTaxRs(){
    var sub    = getSubtotalFromCart();
    var oPct   = parseFloat($('#overall_discount_pct').val()) || 0;
    var oDisc  = sub * oPct / 100;
    var afterO = sub - oDisc;
    if(afterO <= 0){ recalcTotals(); return; }
    var rs    = parseFloat($('#overall_tax_rs_inp').val()) || 0;
    var grand = afterO + rs;
    $('#overall_tax_pct').val((rs / afterO * 100).toFixed(2));
    $('#cart_subtotal').text(CURRENCY+' '+sub.toFixed(2));
    $('#overall_discount_rs').text('-'+CURRENCY+' '+oDisc.toFixed(2));
    $('#overall_tax_rs').text('+'+CURRENCY+' '+rs.toFixed(2));
    var dInp = document.getElementById('overall_discount_rs_inp');
    if(dInp && document.activeElement !== dInp) dInp.value = oDisc.toFixed(2);
    $('#cart_total').text(CURRENCY+' '+grand.toFixed(2));
    calcBalance();
}

function getSubtotalFromCart(){
    var s = 0;
    for(var i=0;i<cartState.length;i++){
        var it=cartState[i];
        var itemDisc=parseFloat(it.item_discount)||0;
        var batches=it.batches||[];
        var upp=parseInt(it.units_per_pack)||1;
        var mode=it.sell_mode||'piece';
        var qtyPcs=parseInt(it.qty_pieces)||(it.quantity*(mode==='pack'?upp:1));
        if(batches.length>0){
            var rem=qtyPcs;
            for(var b=0;b<batches.length&&rem>0;b++){
                var take=Math.min(rem,parseInt(batches[b].remaining_quantity)||0);
                if(take<=0) continue;
                var bp=parseFloat(batches[b].selling_price)||parseFloat(it.price)||0;
                var lb=take*bp;
                s+=lb*(1-itemDisc/100);
                rem-=take;
            }
            if(rem>0){ s+=rem*(parseFloat(it.price)||0)*(1-itemDisc/100); }
        } else {
            s+=it.price*it.quantity*(1-itemDisc/100);
        }
    }
    return s;
}

function updateItemTotalSpan(idx){
    var it = stateItem(idx);
    if(!it) return;
    var base  = it.price * it.quantity;
    var total = base * (1 - (it.item_discount||0)/100);
    $('#ci_total_'+idx).text(CURRENCY+' '+total.toFixed(2));
    $('#ci_'+idx+' .ci-price-label').text('\xd7'+CURRENCY+' '+parseFloat(it.price).toFixed(2));
}

function onQtyInput(idx, el){
    var qty = parseInt(el.value);
    if(isNaN(qty) || qty < 1){ qty = 1; el.value = 1; }
    var it  = stateItem(idx);
    if(!it) return;
    var sellMode     = it.sell_mode || 'piece';
    var unitsPerPack = parseInt(it.units_per_pack) || 1;
    var stockPieces  = it.available_stock || productStockMap[it.product_id] || 9999;

    var newPieces   = (sellMode === 'pack') ? qty * unitsPerPack : qty;
    var otherPcs    = usedPiecesForProduct(it.product_id, idx);
    var totalNeeded = newPieces + otherPcs;

    if(totalNeeded > stockPieces){
        var avail       = Math.max(0, stockPieces - otherPcs);
        var maxDisplay  = (sellMode === 'pack') ? Math.floor(avail / unitsPerPack) : avail;
        if(maxDisplay < 1) maxDisplay = 0;
        qty = maxDisplay;
        el.value = maxDisplay;
        if(otherPcs > 0){
            Notify.warning('Stock limit! '+(Math.floor(otherPcs/unitsPerPack))+' pack(s) already selected. Only '+maxDisplay+' more '+(sellMode==='pack'?'pack(s)':'unit(s)')+' available.');
        } else {
            Notify.warning('Only '+maxDisplay+' '+(sellMode==='pack'?'pack(s)':'unit(s)')+' available in stock.');
        }
        if(maxDisplay < 1){ return; }
    }

    it.quantity   = qty;
    it.qty_pieces = (sellMode === 'pack') ? qty * unitsPerPack : qty;
    updateItemTotalSpan(idx);
    recalcTotals();
    clearTimeout(window._cartRenderTimer);
    window._cartRenderTimer = setTimeout(function(){ renderCart(); }, 600);

    clearTimeout(el._t);
    el._t = setTimeout(function(){
        $.post('../ajax/update_cart.php', {index: idx, quantity: qty}, function(r){
            if(r && r.success){
                if(r.available_pieces !== undefined){ it.available_stock = parseInt(r.available_pieces); productStockMap[it.product_id] = it.available_stock; }
                if(r.price !== undefined && parseFloat(r.price) > 0){ it.price = parseFloat(r.price); updateItemTotalSpan(idx); recalcTotals(); }
            } else if(r && r.success === false){
                Notify.error(r.message || 'Stock limit exceeded');
                fetchAndRenderCart();
            }
        }, 'json').fail(function(){ });
    }, 500);
}

function changeQty(idx, delta){
    var it = stateItem(idx);
    if(!it) return;
    var sellMode     = it.sell_mode || 'piece';
    var unitsPerPack = parseInt(it.units_per_pack) || 1;
    var stockPieces  = it.available_stock || productStockMap[it.product_id] || 9999;
    var nw           = it.quantity + delta;
    if(nw < 1) nw = 1;

    var newPieces   = (sellMode === 'pack') ? nw * unitsPerPack : nw;
    var otherPcs    = usedPiecesForProduct(it.product_id, idx);
    var totalNeeded = newPieces + otherPcs;

    if(totalNeeded > stockPieces){
        var avail      = Math.max(0, stockPieces - otherPcs);
        var maxDisplay = (sellMode === 'pack') ? Math.floor(avail / unitsPerPack) : avail;
        if(otherPcs > 0){ Notify.warning('Stock limit! '+otherPcs+' pieces already reserved. Only '+maxDisplay+' '+(sellMode==='pack'?'pack(s)':'unit(s)')+' more available.'); }
        else { Notify.warning('Only '+maxDisplay+' '+(sellMode==='pack'?'pack(s)':'unit(s)')+' available in stock.'); }
        return;
    }

    it.quantity   = nw;
    it.qty_pieces = (sellMode === 'pack') ? nw * unitsPerPack : nw;
    $('input.qty-input[data-idx="'+idx+'"]').val(nw);
    updateItemTotalSpan(idx);
    recalcTotals();
    clearTimeout(window._cartRenderTimer);
    window._cartRenderTimer = setTimeout(function(){ renderCart(); }, 400);

    $.post('../ajax/update_cart.php', {index: idx, quantity: nw}, function(r){
        if(r && r.success){
            if(r.available_pieces !== undefined){ it.available_stock = parseInt(r.available_pieces); productStockMap[it.product_id] = it.available_stock; }
            if(r.price !== undefined && parseFloat(r.price) > 0){ it.price = parseFloat(r.price); updateItemTotalSpan(idx); recalcTotals(); }
        } else if(r && r.success === false){ Notify.error(r.message || 'Stock limit exceeded'); fetchAndRenderCart(); }
    }, 'json');
}

function onDiscInput(idx, el){
    var v = parseFloat(el.value) || 0;
    var it = stateItem(idx);
    if(it) it.item_discount = v;
    updateItemTotalSpan(idx);
    recalcTotals();
    clearTimeout(el._t);
    el._t = setTimeout(function(){
        $.post('../ajax/update_cart_item.php', {index: idx, field:'item_discount', value: v}, null, 'json');
    }, 500);
}

function addToCart(pid, priceHint, qty, sellMode){
    sellMode = sellMode || 'piece';
    qty = qty || 1;

    var stockPcs = productStockMap[pid];
    if(stockPcs !== undefined){
        if(stockPcs <= 0){ Notify.warning('This product is out of stock.'); return; }
        var uppC = 1;
        for(var pi = 0; pi < allProducts.length; pi++){ if(allProducts[pi].id == pid){ uppC = parseInt(allProducts[pi].units_per_pack) || 1; break; } }
        var newPcs = (sellMode === 'pack') ? qty * uppC : qty;
        var usedPcs = 0;
        for(var ci = 0; ci < cartState.length; ci++){ if(cartState[ci].product_id == pid) usedPcs += parseInt(cartState[ci].qty_pieces) || 0; }
        var existingItem = null;
        for(var ei = 0; ei < cartState.length; ei++){ if(cartState[ei].product_id == pid && (cartState[ei].sell_mode||'piece') === sellMode){ existingItem = cartState[ei]; break; } }
        var existingPcs = existingItem ? (parseInt(existingItem.qty_pieces)||0) : 0;
        var totalAfterAdd = (usedPcs - existingPcs) + (existingItem ? existingPcs + newPcs : newPcs);
        if(totalAfterAdd > stockPcs){
            var avail = Math.max(0, stockPcs - (usedPcs - existingPcs));
            var maxMore = (sellMode === 'pack') ? Math.floor(avail / uppC) : avail;
            Notify.warning('Stock limit! Only '+maxMore+' more '+(sellMode==='pack'?'pack(s)':'unit(s)')+' available ('+stockPcs+' pcs total in stock).');
            return;
        }
    }

    $.post('../ajax/add_to_cart.php',
        {product_id: pid, price: priceHint, quantity: qty, sell_mode: sellMode},
        function(r){
            if(r && r.success){ if(r.stock !== undefined) productStockMap[pid] = parseInt(r.stock); fetchAndRenderCart(); }
            else { Notify.error(r && r.message ? r.message : 'Could not add product'); }
        }, 'json'
    ).fail(function(){ Notify.error('Server error — could not add product. Please try again.'); });
}

function removeFromCart(idx){
    idx = String(idx);
    if(!confirm('Remove this item from cart?')) return;
    $.post('../ajax/remove_from_cart.php', {index: idx}, function(r){ fetchAndRenderCart(); }, 'json').fail(function(){ fetchAndRenderCart(); });
}

function fetchAndRenderCart(){
    $.get('../ajax/get_cart.php', function(r){
        cartState = [];
        if(r && r.cart && r.cart.length){
            for(var i = 0; i < r.cart.length; i++){
                var item = r.cart[i];
                var prod = null;
                for(var j = 0; j < allProducts.length; j++){ if(allProducts[j].id == item.product_id){ prod = allProducts[j]; break; } }
                var avStock = parseInt(item.available_stock) || productStockMap[item.product_id] || 9999;
                if(item.available_stock) productStockMap[item.product_id] = avStock;
                cartState.push({
                    index:          String(item.index),
                    product_id:     item.product_id,
                    product_name:   item.product_name || '',
                    detail_text:    item.detail_text  || '',
                    brand_name:     item.brand_name   || (prod ? prod.brand_name   : ''),
                    generic_name:   item.generic_name || (prod ? prod.generic_name : ''),
                    category_id:    item.category_id  || (prod ? prod.category_id  : 0),
                    strength:       item.strength     || (prod ? prod.strength     : ''),
                    dosage_form:    item.dosage_form  || (prod ? prod.dosage_form  : ''),
                    size_value:     item.size_value   || (prod ? prod.size_value   : ''),
                    units_per_pack: parseInt(item.units_per_pack) || (prod ? parseInt(prod.units_per_pack) : 1),
                    unit_type:      item.unit_type    || (prod ? prod.unit_type    : ''),
                    price:          parseFloat(item.price)          || 0,
                    price_per_piece:parseFloat(item.price_per_piece)|| parseFloat(item.price) || 0,
                    quantity:       parseInt(item.quantity)         || 1,
                    qty_pieces:     parseInt(item.qty_pieces)       || parseInt(item.quantity) || 1,
                    sell_mode:      item.sell_mode    || 'piece',
                    item_discount:  parseFloat(item.item_discount)  || 0,
                    item_tax:       parseFloat(item.item_tax)       || 0,
                    available_stock:avStock,
                    batches:        item.batches || []
                });
            }
        }
        renderCart();
    }, 'json').fail(function(){ renderCart(); });
}

$('#overall_discount_pct, #overall_tax_pct').on('input', recalcTotals);

/* ════════════════════════════════════════════════
   CUSTOMER AUTOCOMPLETE
════════════════════════════════════════════════ */
function onCustomerNameInput(el){
    calcBalance();
    $('#existing_due_reminder').hide();
    var val      = (el.value || '').trim();
    var valLower = val.toLowerCase();
    var $drop    = $('#customer_autocomplete');
    if(val.length < 1){ $drop.hide(); return; }

    clearTimeout(window._custSearchTimer);
    window._custSearchTimer = setTimeout(function(){
        var matches = _custCache.filter(function(c){
            var name  = (c.customer_name  || '').toLowerCase();
            var phone = (c.customer_phone || '').toLowerCase();
            return name.indexOf(valLower) !== -1 || phone.indexOf(valLower) !== -1;
        });

        $.getJSON('../sales/customers.php', {ajax:'search', q: val, limit: 15}, function(data){
            if(data && Array.isArray(data.rows) && data.rows.length > 0){
                data.rows.forEach(function(r){
                    var mapped = { customer_name: r.name||'', customer_phone: r.phone||'', due_amount: parseFloat(r.total_pending||0) };
                    var found = false;
                    for(var i = 0; i < _custCache.length; i++){ if(_custCache[i].customer_phone === mapped.customer_phone){ _custCache[i] = mapped; found = true; break; } }
                    if(!found) _custCache.push(mapped);
                });
                matches = _custCache.filter(function(c){ var name=(c.customer_name||'').toLowerCase(),phone=(c.customer_phone||'').toLowerCase(); return name.indexOf(valLower)!==-1||phone.indexOf(valLower)!==-1; });
            }
            renderCustDropdown(matches, val, valLower);
        }).fail(function(){ renderCustDropdown(matches, val, valLower); });

        if(matches.length > 0) renderCustDropdown(matches, val, valLower);
    }, 150);

    if(_custCache.length === 0 && !window._custCacheLoading){
        window._custCacheLoading = true;
        $.getJSON('../sales/customers.php', {ajax:'search', q:'', limit:300}, function(data){
            window._custCacheLoading = false;
            if(data && Array.isArray(data.rows)){ _custCache = data.rows.map(function(r){ return {customer_name:r.name||'',customer_phone:r.phone||'',due_amount:parseFloat(r.total_pending||0)}; }); }
        }).fail(function(){ window._custCacheLoading = false; });
    }
}

function renderCustDropdown(matches, val, valLower){
    var $drop = $('#customer_autocomplete');
    if(!val || val.length < 1){ $drop.hide(); return; }
    matches.sort(function(a,b){ var an=(a.customer_name||'').toLowerCase(),bn=(b.customer_name||'').toLowerCase(),as=an.startsWith(valLower)?0:1,bs=bn.startsWith(valLower)?0:1; if(as!==bs)return as-bs; return an.localeCompare(bn); });
    var html = '';
    matches.slice(0, 12).forEach(function(c){
        var nameHL    = hlMatch(c.customer_name||'', valLower);
        var phoneHL   = hlMatch(c.customer_phone||'', valLower);
        var dueAmount = parseFloat(c.due_amount || 0);
        var dueHtml   = dueAmount > 0
            ? '<span class="cust-due-badge ms-1">Due: '+CURRENCY+' '+dueAmount.toFixed(2)+'</span>'
            : '<span style="color:#198754;font-size:10px;margin-left:6px;"><i class="fas fa-check-circle"></i> Clear</span>';
        html += '<div class="cust-ac-item" style="padding:7px 10px;cursor:pointer;border-bottom:1px solid var(--pharmacy-border,#f0f2f5);font-size:12px;background:var(--pharmacy-card-bg,#fff);" onclick="selectCreditCustomer(\''+escapeHtml(c.customer_name)+'\',\''+escapeHtml(c.customer_phone||'')+'\','+dueAmount+'); return false;">'
            +'<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:4px;">'
            +'<div><i class="fas fa-user-circle" style="color:var(--pharmacy-primary,#667eea);font-size:11px;margin-right:4px;"></i><b>'+nameHL+'</b>'+(c.customer_phone?' <span style="color:color-mix(in srgb,var(--pharmacy-text,#343a40) 55%,transparent);font-size:10px;">&#128222; '+phoneHL+'</span>':'')+'</div>'
            +dueHtml+'</div></div>';
    });
    html += '<div class="cust-ac-item" style="padding:7px 10px;cursor:pointer;font-size:12px;background:color-mix(in srgb,#198754 5%,var(--pharmacy-card-bg,#fff));border-top:2px dashed color-mix(in srgb,#198754 30%,transparent);" onclick="addNewCustomerFromDropdown(); return false;">'
        +'<i class="fas fa-user-plus" style="color:#198754;margin-right:5px;"></i><b style="color:#0a5c36;">Add New Customer:</b> <em style="color:var(--pharmacy-text,#333);">&ldquo;'+esc(val)+'&rdquo;</em>'
        +'<div style="font-size:10px;color:color-mix(in srgb,var(--pharmacy-text,#343a40) 50%,transparent);margin-top:1px;">(Same name, different customer)</div></div>';
    $drop.html(html).show();
}

function escapeHtml(str){ if(!str)return''; return String(str).replace(/[&<>]/g,function(m){ if(m==='&')return'&amp;';if(m==='<')return'&lt;';if(m==='>') return'&gt;';return m; }).replace(/'/g,"\\'"); }
function hlMatch(str,term){ if(!str||!term)return esc(str||''); var idx=str.toLowerCase().indexOf(term); if(idx<0)return esc(str); return esc(str.substring(0,idx))+'<mark style="background:#fff3cd;padding:0 1px;border-radius:2px;font-weight:800;">'+esc(str.substring(idx,idx+term.length))+'</mark>'+esc(str.substring(idx+term.length)); }
function selectCreditCustomer(name,phone,dueAmount){ $('#customer_name').val(name); $('#customer_phone').val(phone||''); $('#customer_autocomplete').hide(); var $rem=$('#existing_due_reminder'); if(dueAmount>0){ $rem.html('<i class="fas fa-exclamation-triangle"></i> Existing due: '+CURRENCY+' '+parseFloat(dueAmount).toFixed(2)).show(); } else { $rem.hide(); } calcBalance(); setTimeout(function(){ $('#customer_phone').focus(); },50); }
function addNewCustomerFromDropdown(){ $('#customer_autocomplete').hide(); $('#existing_due_reminder').hide(); $('#customer_phone').val('').focus(); }
$(document).on('click', function(e){ if(!$(e.target).closest('#customer_name, #customer_autocomplete, .cust-ac-item').length) $('#customer_autocomplete').hide(); });
$(document).on('keydown', '#customer_name', function(e){ if(e.key==='Escape'||e.key==='Tab') $('#customer_autocomplete').hide(); });

/* ════════════════════════════════════════════════
   BALANCE CALCULATION
════════════════════════════════════════════════ */
function calcBalance(){
    var total  = parseFloat($('#cart_total').text().replace(CURRENCY,'').replace(/,/g,'').trim()) || 0;
    var paid   = parseFloat($('#paid_amount').val()) || 0;
    var bal    = total - paid;
    var method = $('#payment_method').val();

    if(_userSelectedMethod !== 'credit'){
        if(paid > 0 && bal > 0.005){ if(method !== 'credit'){ setPayMethodSilent('credit'); method = 'credit'; } }
        else { if(method === 'credit'){ setPayMethodSilent(_userSelectedMethod); method = _userSelectedMethod; } }
    }

    var isCredit  = (method === 'credit');
    var isPartial = isCredit && (_userSelectedMethod !== 'credit') && paid > 0 && bal > 0.005;

    $('#balance_display')
        .text(CURRENCY+' '+Math.abs(bal).toFixed(2)+(bal > 0.005 ? ' Due' : ' Change'))
        .toggleClass('balance-due',    bal > 0.005)
        .toggleClass('balance-change', bal <= 0.005);

    var $label = $('#customer_panel_label');
    var $req   = $('#customer_panel_required');
    var $due   = $('#due_date_wrap');
    var $alert = $('#partial_alert');
    var $name  = $('#customer_name');
    var $phone = $('#customer_phone');

    $('#customer_panel').show();

    if(isCredit){
        $label.text(isPartial ? 'Customer Details (Partial Payment)' : 'Credit Customer Details');
        $req.show(); $due.show();
        $name.attr('placeholder','Customer Name *');
        $phone.attr('placeholder','Phone *');
        if (isPartial) {
            var creditBal = Math.max(0, bal).toFixed(2);
            $('#partial_balance_detail').text('Paid: '+CURRENCY+' '+paid.toFixed(2)+' | Credit Due: '+CURRENCY+' '+creditBal);
        }
        $alert.toggle(isPartial);
    } else {
        $label.text('Customer Details (Optional)');
        $req.hide(); $due.hide();
        $name.attr('placeholder','Customer Name (optional)');
        $phone.attr('placeholder','Phone (optional)');
        $alert.hide();
    }
}

function setPayMethodSilent(method){
    $('#payment_method').val(method);
    ['cash','card','online','credit'].forEach(function(m){ $('#pm_'+m).removeClass('active-'+m); });
    $('#pm_'+method).addClass('active-'+method);
}

var _userSelectedMethod = 'cash';

function setPayMethod(method){
    if(method === 'credit'){
        var grand = parseFloat($('#cart_total').text().replace(CURRENCY,'').replace(/,/g,'').trim()) || 0;
        var paid  = parseFloat($('#paid_amount').val()) || 0;
        if(grand > 0 && paid >= grand){
            var $btn = $('#pm_credit');
            $btn.css({'border-color':'#dc3545','background':'color-mix(in srgb,#dc3545 12%,var(--pharmacy-card-bg,#fff))','color':'#dc3545'});
            $('#pm_credit_msg').remove();
            $btn.after('<div id="pm_credit_msg" style="font-size:10px;color:#dc3545;margin-top:2px;text-align:center;">Full payment entered — Credit not applicable</div>');
            setTimeout(function(){ $btn.css({'border-color':'','background':'','color':''}); $('#pm_credit_msg').remove(); }, 2400);
            return;
        }
    }
    _userSelectedMethod = method;
    setPayMethodSilent(method);
    if(method === 'credit'){ $('#paid_amount').attr('placeholder','Enter amount (0 = full credit)'); }
    else { $('#paid_amount').attr('placeholder','Enter amount'); }
    calcBalance();
}

function newSale(){
    if(!confirm('Clear cart and start new sale?')) return;
    $.post('../ajax/clear_cart.php', {}, function(){
        cartState = [];
        renderCart();
        $('#overall_discount_pct').val(0);
        $('#overall_tax_pct').val(defaultTax);
        $('#overall_discount_rs_inp').val(0);
        $('#overall_tax_rs_inp').val(0);
        $('#customer_name,#customer_phone').val('');
        $('#due_date').val('');
        $('#paid_amount').val(0);
        $('#existing_due_reminder').hide();
        $('#customer_autocomplete').hide();
        setPayMethod('cash');
        $('#product_search').val('').trigger('input');
        $('#partial_alert').hide();
    });
}

function showFieldError(selector, msg){
    var $el = $(selector);
    $el.addClass('is-invalid').focus();
    var $fb = $el.next('.invalid-feedback');
    if(!$fb.length){ $el.after('<div class="invalid-feedback" style="font-size:10px;">'+esc(msg)+'</div>'); }
    else { $fb.text(msg); }
    setTimeout(function(){ $el.removeClass('is-invalid'); $el.next('.invalid-feedback').remove(); }, 3000);
}

/* ════════════════════════════════════════════════
   INVOICE PREVIEW — uses COMPANY settings
   FIX: No longer hardcodes "PHARMACY" — uses COMPANY.name
════════════════════════════════════════════════ */
function showInvoicePreview(){
    if(cartState.length === 0){ Notify.error('Cart is empty'); return; }

    var paid   = parseFloat($('#paid_amount').val()) || 0;
    var method = $('#payment_method').val();
    var grand  = parseFloat($('#cart_total').text().replace(CURRENCY,'').replace(/,/g,'').trim()) || 0;
    var bal    = grand - paid;

    if(paid <= 0 && method !== 'credit'){ $('#paid_amount').focus().select(); return; }

    var custName  = $('#customer_name').val().trim();
    var custPhone = $('#customer_phone').val().trim();
    var dueDate   = $('#due_date').val();
    var isCredit  = 0;
    var needsCredit = (method === 'credit') || (paid > 0 && bal > 0.005);

    if(needsCredit){
        isCredit = 1;
        if(!custName){ showFieldError('#customer_name', 'Credit/Partial payment ke liye Customer Name zaroori hai'); return; }
        if(!custPhone){ showFieldError('#customer_phone', 'Customer Phone zaroori hai'); return; }
        if(!dueDate){ showFieldError('#due_date', 'Due date select karein'); return; }
        method = 'credit';
    } else {
        isCredit  = 0;
        custName  = custName || 'Walk-in Customer';
        dueDate   = '';
    }

    var oPct = parseFloat($('#overall_discount_pct').val()) || 0;
    var oTax = parseFloat($('#overall_tax_pct').val()) || 0;

    var previewResult = buildLocalThermalPreview(custName, custPhone, method, oPct, oTax, paid, dueDate);
    window._pendingInvoice = {
        success: true, html: previewResult.html, invoice_no: previewResult.invoice_no,
        custName: custName, custPhone: custPhone, dueDate: dueDate,
        isCredit: isCredit, method: method, oPct: oPct, oTax: oTax, paid: paid
    };
    /* Reset post-sale buttons until sale is confirmed */
    $('#btn_print_thermal,#btn_view_a4,#btn_pdf_download').prop('disabled', true);
    new bootstrap.Modal(document.getElementById('invoiceModal')).show();
    $('#invoice_preview').html(previewResult.html);
}

/* FIXED: buildLocalThermalPreview uses COMPANY object (company_settings) */
function buildLocalThermalPreview(custName, custPhone, method, oPct, oTax, paid, dueDate){
    var now  = new Date();
    var dd   = String(now.getDate()).padStart(2,'0');
    var mm   = String(now.getMonth()+1).padStart(2,'0');
    var yyyy = now.getFullYear();
    var hh   = now.getHours();
    var min  = String(now.getMinutes()).padStart(2,'0');
    var ampm = hh >= 12 ? 'pm' : 'am';
    hh = hh % 12 || 12;
    var dateStr = dd+'/'+mm+'/'+yyyy+' '+hh+':'+min+' '+ampm;
    var tmpInv  = COMPANY.invoice_prefix+'-'+yyyy+mm+dd+'-'+Math.floor(Math.random()*9000+1000);
    var methodLabel = {'cash':'Cash','card':'Card','online':'Online','credit':'Credit'}[method]||method;

    function shorten(s, max){ if(!s)return''; s=String(s); return s.length>max?s.substring(0,max-1)+'\u2026':s; }

    var mergedMap = {}, mergedOrder = [];
    for(var i = 0; i < cartState.length; i++){
        var ci = cartState[i];
        var pid = ci.product_id;
        var md  = ci.sell_mode || 'piece';
        var upp = parseInt(ci.units_per_pack) || 1;
        var pcs = parseInt(ci.qty_pieces) || (ci.quantity * (md==='pack' ? upp : 1));
        if(!mergedMap[pid]){
            mergedMap[pid] = { product_id:pid, product_name:ci.product_name||'', isMed:parseInt(ci.category_id)===1,
                dosage_form:ci.dosage_form||'', strength:ci.strength||'', generic_name:ci.generic_name||'',
                brand_name:ci.brand_name||'', size_value:ci.size_value||'', unit_type:ci.unit_type||'Pcs',
                upp:upp, item_discount:parseFloat(ci.item_discount)||0, batches:ci.batches||[],
                totalPieces:pcs, price:parseFloat(ci.price)||0 };
            mergedOrder.push(pid);
        } else { mergedMap[pid].totalPieces += pcs; if(ci.batches&&ci.batches.length>mergedMap[pid].batches.length) mergedMap[pid].batches=ci.batches; }
    }

    var invoiceRows = [], subtotal = 0;
    for(var m = 0; m < mergedOrder.length; m++){
        var item = mergedMap[mergedOrder[m]];
        var remaining = item.totalPieces, batches = item.batches, itemDisc = item.item_discount;
        var batchSerial = 0, itemRows = [];
        if(batches.length > 0){
            for(var b = 0; b < batches.length && remaining > 0; b++){
                var bat = batches[b];
                var takePC = Math.min(remaining, parseInt(bat.remaining_quantity)||0);
                if(takePC <= 0) continue;
                batchSerial++;
                var bPrice = parseFloat(bat.selling_price) || item.price;
                var lb = takePC * bPrice, ld = lb * itemDisc / 100, lT = lb - ld;
                subtotal += lT;
                itemRows.push({ batchSerial:batchSerial, qty:takePC, price:bPrice, lineTotal:lT, item:item, isFirst:batchSerial===1 });
                remaining -= takePC;
            }
        }
        if(itemRows.length === 0 || remaining > 0){
            var fp = parseFloat((batches[0]||{}).selling_price) || item.price || 0;
            var fq = itemRows.length===0 ? item.totalPieces : remaining;
            var flb = fq*fp, fld = flb*itemDisc/100, flT = flb-fld;
            subtotal += flT; batchSerial++;
            itemRows.push({ batchSerial:batchSerial, qty:fq, price:fp, lineTotal:flT, item:item, isFirst:batchSerial===1 });
        }
        for(var ir = 0; ir < itemRows.length; ir++) invoiceRows.push(itemRows[ir]);
    }

    var oDiscA = subtotal * oPct / 100, afterOD = subtotal - oDiscA;
    var grand  = afterOD + (afterOD * oTax / 100);
    var bal    = grand - paid;

    // Use COMPANY.name from settings — FIX for "PHARMACY" hardcode
    var W = 'font-family:\'Courier New\',Courier,monospace;font-size:11px;width:320px;margin:0 auto;color:#111;background:#fff;line-height:1.55;';
    var D = '<div style="'+W+'">';

    // ── Invoice header (fully synced with company_settings) ──────────────
    D += '<div style="text-align:center;padding-bottom:5px;border-bottom:2px dashed #444;margin-bottom:5px;">';
    if(COMPANY.show_logo && COMPANY.logo_url){
        D += '<div style="margin-bottom:5px;"><img src="'+COMPANY.logo_url+'" alt="logo" style="max-height:55px;max-width:150px;object-fit:contain;"></div>';
    }
    D += '<div style="font-size:15px;font-weight:bold;letter-spacing:1px;">'+esc(COMPANY.name)+'</div>';
    if(COMPANY.show_tagline && COMPANY.tagline){
        D += '<div style="font-size:9px;color:#777;font-style:italic;">'+esc(COMPANY.tagline)+'</div>';
    }
    if(COMPANY.address) D += '<div style="font-size:9px;color:#777;">'+esc(COMPANY.address)+'</div>';
    if(COMPANY.phone)   D += '<div style="font-size:9px;color:#777;">Tel: '+esc(COMPANY.phone)+'</div>';
    if(COMPANY.email)   D += '<div style="font-size:9px;color:#777;">'+esc(COMPANY.email)+'</div>';
    if(COMPANY.gst_no)  D += '<div style="font-size:9px;color:#777;">GST: '+esc(COMPANY.gst_no)+'</div>';
    D += '<div style="font-size:9px;color:#777;">** Computer Generated Invoice **</div>';
    D += '</div>';

    D += '<div style="border-top:1px dashed #444;border-bottom:1px dashed #444;margin:3px 0;padding:4px 0;">'
        +'<div>Invoice#: <b>'+esc(tmpInv)+'</b></div>'
        +'<div>Date: '+dateStr+'</div>'
        +'<div>Customer: <b>'+esc(custName||'Walk-in Customer')+'</b></div>';
    if(custPhone) D += '<div>Phone: '+esc(custPhone)+'</div>';
    if(COMPANY.show_payment) D += '<div>Payment: '+esc(methodLabel)+'</div>';
    if(method==='credit' && dueDate) D += '<div style="color:#c0392b;font-weight:bold;">Due Date: '+esc(dueDate)+'</div>';
    D += '</div>';

    D += '<table style="width:100%;border-collapse:collapse;font-size:10px;margin-top:4px;">'
        +'<thead><tr style="border-top:1px solid #111;border-bottom:1px solid #111;">'
        +'<th style="text-align:left;padding:2px 1px;font-size:9.5px;">#</th>'
        +'<th style="text-align:left;padding:2px 2px;font-size:9.5px;">Item</th>'
        +'<th style="text-align:center;padding:2px 1px;font-size:9.5px;">Qty</th>'
        +'<th style="text-align:right;padding:2px 1px;font-size:9.5px;">Price</th>'
        +'<th style="text-align:right;padding:2px 2px;font-size:9.5px;">Total</th>'
        +'</thead><tbody>';

    var srNo = 0;
    for(var r = 0; r < invoiceRows.length; r++){
        var row = invoiceRows[r], itm = row.item;
        var upp2 = parseInt(itm.upp) || 1, isCont = !row.isFirst;
        if(row.isFirst) srNo++;
        var prodCell = '';
        if(row.isFirst){
            var line1 = itm.isMed
                ? '<b>'+esc(shorten(itm.product_name,18))+(itm.dosage_form?' ('+itm.dosage_form+')':'')+(itm.strength?' -'+itm.strength:'')+'</b>'
                : '<b>'+esc(shorten(itm.product_name,22))+(itm.size_value?' ('+itm.size_value+')':'')+'</b>';
            prodCell = line1;
            var line2 = '';
            if(itm.isMed && itm.generic_name) line2 = shorten(itm.generic_name,22)+(upp2>1?' (\xd7'+upp2+')':'');
            else if(!itm.isMed && itm.brand_name) line2 = shorten(itm.brand_name,22)+(upp2>1?' (\xd7'+upp2+')':'');
            else if(upp2>1) line2 = '(\xd7'+upp2+')';
            if(line2) prodCell += '<br><span style="font-size:9.5px;color:#444;">'+esc(line2)+'</span>';
            if(itm.item_discount>0) prodCell += '<br><span style="font-size:8.5px;color:#c0392b;">Disc '+itm.item_discount.toFixed(1)+'%</span>';
        } else {
            prodCell = '<span style="color:#7c3aed;font-size:9.5px;">\u21b3 '+esc(shorten(itm.product_name,22))+'</span>';
        }
        var rowBg = isCont ? 'background:#f5f0ff;' : '';
        D += '<tr style="'+rowBg+'border-bottom:1px dotted #ccc;">'
            +'<td style="padding:3px 1px;color:#555;font-weight:bold;">'+(row.isFirst?srNo:'')+'</td>'
            +'<td style="padding:3px 2px;line-height:1.4;word-break:break-word;">'+prodCell+'</td>'
            +'<td style="text-align:center;padding:3px 1px;font-weight:bold;">'+row.qty+'</td>'
            +'<td style="text-align:right;padding:3px 1px;">'+row.price.toFixed(2)+'</td>'
            +'<td style="text-align:right;padding:3px 2px;font-weight:bold;">'+row.lineTotal.toFixed(2)+'</td>'
        +'</tr>';
    }
    D += '</tbody></table>';

    D += '<div style="border-top:1px dashed #444;margin-top:4px;padding-top:4px;font-size:10.5px;">';
    if(invoiceRows.length > 1 || oPct > 0) D += '<div style="display:flex;justify-content:space-between;"><span>Sub-Total:</span><b>'+CURRENCY+' '+subtotal.toFixed(2)+'</b></div>';
    if(oPct > 0) D += '<div style="display:flex;justify-content:space-between;color:#c0392b;"><span>Discount ('+oPct.toFixed(1)+'%)</span><span>- '+CURRENCY+' '+oDiscA.toFixed(2)+'</span></div>';
    if(oTax > 0) D += '<div style="display:flex;justify-content:space-between;color:#27ae60;"><span>Tax ('+oTax.toFixed(1)+'%)</span><span>+ '+CURRENCY+' '+(afterOD*oTax/100).toFixed(2)+'</span></div>';
    D += '<div style="display:flex;justify-content:space-between;font-weight:bold;font-size:12.5px;border-top:1px solid #333;margin-top:3px;padding-top:3px;"><span>GRAND TOTAL:</span><span>'+CURRENCY+' '+grand.toFixed(2)+'</span></div>';
    if(COMPANY.show_payment) D += '<div style="display:flex;justify-content:space-between;font-size:10px;margin-top:2px;"><span>Payment: '+methodLabel+'</span><span>Paid: '+CURRENCY+' '+paid.toFixed(2)+'</span></div>';
    else D += '<div style="display:flex;justify-content:space-between;font-size:10px;margin-top:2px;"><span>Paid:</span><span>'+CURRENCY+' '+paid.toFixed(2)+'</span></div>';
    if(Math.abs(bal) > 0.005){
        var bCol = bal>0?'#c0392b':'#27ae60', bLbl = bal>0?'Balance Due:':'Change:';
        D += '<div style="display:flex;justify-content:space-between;font-weight:bold;color:'+bCol+';font-size:10.5px;"><span>'+bLbl+'</span><span>'+CURRENCY+' '+Math.abs(bal).toFixed(2)+'</span></div>';
    }
    if(method==='credit' && dueDate) D += '<div style="color:#c0392b;font-size:10px;font-weight:bold;margin-top:2px;">Credit Due: '+esc(dueDate)+'</div>';
    D += '</div>';

    // ── Invoice footer (from company_settings) ───────────────────────────
    var footerTxt   = (COMPANY.footer_text   || 'Thank you for shopping with us!');
    var termsTxt    = (COMPANY.invoice_terms || '');
    D += '<div style="border-top:1px dashed #444;margin-top:5px;padding-top:5px;text-align:center;font-size:9.5px;color:#555;">';
    D += esc(footerTxt);
    if(termsTxt){
        D += '<br><span style="font-size:8.5px;color:#888;">'+esc(termsTxt)+'</span>';
    }
    D += '<br><span style="color:#999;">*** Powered by Pharmacy POS ***</span></div>';
    D += '</div>';

    return { html: D, invoice_no: tmpInv };
}

function cancelInvoice(){ window._pendingInvoice = null; }

function confirmInvoice(){
    if(cartState.length === 0){ Notify.error('Cart is empty — cannot process.'); return; }

    var btn = $('#invoiceModal .btn-success');
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Processing\u2026');

    var inv      = window._pendingInvoice || {};
    var method   = inv.method    || $('#payment_method').val();
    var oPct     = inv.oPct      !== undefined ? inv.oPct  : (parseFloat($('#overall_discount_pct').val()) || 0);
    var oTax     = inv.oTax      !== undefined ? inv.oTax  : (parseFloat($('#overall_tax_pct').val()) || 0);
    var paid     = inv.paid      !== undefined ? inv.paid  : (parseFloat($('#paid_amount').val()) || 0);
    var custName = inv.custName  || 'Walk-in Customer';
    var custPhone= inv.custPhone || '';
    var dueDate  = inv.dueDate   || '';
    var isCredit = inv.isCredit  || 0;

    var subtotal = 0;
    for(var i = 0; i < cartState.length; i++){
        var it = cartState[i], dsc = parseFloat(it.item_discount)||0, bts = it.batches||[];
        var uppI = parseInt(it.units_per_pack)||1, mode = it.sell_mode||'piece';
        var qpI  = parseInt(it.qty_pieces)||(it.quantity*(mode==='pack'?uppI:1));
        if(bts.length>0){ var rem=qpI; for(var bi=0;bi<bts.length&&rem>0;bi++){ var tk=Math.min(rem,parseInt(bts[bi].remaining_quantity)||0); if(tk<=0)continue; var bp=parseFloat(bts[bi].selling_price)||parseFloat(it.price)||0; subtotal+=tk*bp*(1-dsc/100); rem-=tk; } if(rem>0)subtotal+=rem*(parseFloat(it.price)||0)*(1-dsc/100); }
        else { subtotal+=it.price*it.quantity*(1-dsc/100); }
    }
    var oDisc = subtotal*oPct/100, afterO = subtotal-oDisc, oTaxA = afterO*oTax/100;
    var grand = afterO+oTaxA, balance = grand-paid;

    var cartItems = [];
    for(var j = 0; j < cartState.length; j++){
        cartItems.push({ index:cartState[j].index, quantity:cartState[j].quantity, item_discount:cartState[j].item_discount, item_tax:cartState[j].item_tax });
    }

    var invNo = inv.invoice_no || '';
    if(!invNo){ var d=new Date(),im=String(d.getMonth()+1).padStart(2,'0'),id2=String(d.getDate()).padStart(2,'0'); invNo=COMPANY.invoice_prefix+'-'+d.getFullYear()+im+id2+'-'+Math.floor(Math.random()*9000+1000); }

    var postData = {
        csrf_token: $('#csrf_token_field').val(), invoice_no: invNo,
        customer_name: custName, customer_phone: custPhone, payment_method: method,
        subtotal: subtotal.toFixed(2), overall_discount_pct: oPct, overall_discount: oDisc.toFixed(2),
        overall_tax_pct: oTax, overall_tax: oTaxA.toFixed(2), grand_total: grand.toFixed(2),
        paid_amount: paid.toFixed(2), balance: balance.toFixed(2), is_credit: isCredit,
        due_date: dueDate || '', items: JSON.stringify(cartItems)
    };

    $.post('../ajax/process_sale.php', postData, function(r){
        btn.prop('disabled', false).html('<i class="fas fa-check me-1"></i>Confirm & Print');
        if(r && r.success){
            window._lastSaleId = r.sale_id;
            /* Enable post-sale action buttons */
            $('#btn_print_thermal,#btn_view_a4,#btn_pdf_download').prop('disabled', false);

            bootstrap.Modal.getInstance(document.getElementById('invoiceModal')).hide();
            window.open('print_invoice.php?id='+r.sale_id+'&print=1', '_blank', 'width=440,height=700');
            cartState = [];
            renderCart();
            $('#overall_discount_pct').val(0);
            $('#overall_tax_pct').val(defaultTax);
            $('#overall_discount_rs_inp').val(0);
            $('#overall_tax_rs_inp').val(0);
            $('#customer_name,#customer_phone').val('');
            $('#due_date').val('');
            $('#paid_amount').val(0);
            $('#existing_due_reminder').hide();
            $('#customer_autocomplete').hide();
            setPayMethod('cash');
            window._pendingInvoice = null;
            // ── STEP 11: Clear auto-saved cart after successful sale ──
            var _posUserId = <?php echo (int)($_SESSION['user_id'] ?? 0); ?>;
            AutoSave.clear('pos_cart_user_' + _posUserId);
            setTimeout(function(){ loadAllProducts(); }, 800);
        } else {
            var msg = (r && r.message) ? r.message : 'Processing failed. Please check server logs.';
            Notify.error('Error: ' + msg);
        }
    }, 'json').fail(function(xhr){
        btn.prop('disabled', false).html('<i class="fas fa-check me-1"></i>Confirm & Print');
        var errMsg = 'Server error. Check that ajax/process_sale.php exists and is accessible.';
        try{ var resp=JSON.parse(xhr.responseText); if(resp&&resp.message) errMsg=resp.message; }catch(e){}
        Notify.error(errMsg);
    });
}

/* ════════════════════════════════════════════════
   BARCODE SCANNER
════════════════════════════════════════════════ */
(function(){
    var BARCODE_TIMEOUT = 80;
    function beep(ok){ try{ var ctx=new(window.AudioContext||window.webkitAudioContext)(),o=ctx.createOscillator(),g=ctx.createGain(); o.connect(g);g.connect(ctx.destination);o.frequency.value=ok?880:330;g.gain.setValueAtTime(0.2,ctx.currentTime);g.gain.exponentialRampToValueAtTime(0.001,ctx.currentTime+0.18);o.start(ctx.currentTime);o.stop(ctx.currentTime+0.18); }catch(e){} }
    function showScanStatus(ok,msg){ var $s=$('#barcode_scan_status');$s.html(ok?'<i class="fas fa-check-circle text-success"></i>':'<i class="fas fa-times-circle text-danger"></i>').show();var $toast=$('#barcode_toast');if(!$toast.length){$('body').append('<div id="barcode_toast" style="position:fixed;top:70px;left:50%;transform:translateX(-50%);z-index:9999;min-width:260px;display:none;"></div>');$toast=$('#barcode_toast');}$toast.html('<div class="alert alert-'+(ok?'success':'danger')+' py-2 px-3 shadow mb-0" style="font-size:13px;"><i class="fas fa-'+(ok?'check':'exclamation-triangle')+'"></i> '+msg+'</div>').fadeIn(150);setTimeout(function(){$toast.fadeOut(300);$s.hide();},2000); }
    function handleBarcodeLookup(barcode){ if(!barcode||barcode.length<3)return;$('#barcode_scan_input').val('').addClass('border-primary');$.ajax({url:'../ajax/get_product_by_barcode.php',method:'GET',data:{barcode:barcode},dataType:'json',success:function(r){$('#barcode_scan_input').removeClass('border-primary');if(r&&r.success&&r.product){var p=r.product;var stock=parseInt(p.total_stock)||0;if(stock<=0){beep(false);showScanStatus(false,esc(p.product_name)+' — Out of stock');return;}if(!productStockMap[p.id])productStockMap[p.id]=stock;var found=false;for(var i=0;i<allProducts.length;i++){if(allProducts[i].id==p.id){found=true;break;}}if(!found)allProducts.push(p);addToCart(p.id,parseFloat(p.near_expiry_price||p.sale_price||0),1,'piece');beep(true);showScanStatus(true,esc(p.product_name)+' added to cart');}else{beep(false);showScanStatus(false,'Barcode not found: '+esc(barcode));}},error:function(){$('#barcode_scan_input').removeClass('border-primary');beep(false);showScanStatus(false,'Server error — barcode lookup failed');}}); }
    $(document).on('keydown','#barcode_scan_input',function(e){ if(e.key==='Enter'){e.preventDefault();var val=$(this).val().trim();if(val)handleBarcodeLookup(val);return;} });
    var globalBuffer='',globalTimer=null;
    $(document).on('keydown',function(e){ var tag=(e.target.tagName||'').toLowerCase();if(tag==='input'||tag==='textarea'||tag==='select')return;if(e.key==='Enter'){clearTimeout(globalTimer);if(globalBuffer.length>=3)handleBarcodeLookup(globalBuffer);globalBuffer='';return;}if(e.key.length===1){globalBuffer+=e.key;clearTimeout(globalTimer);globalTimer=setTimeout(function(){globalBuffer='';},BARCODE_TIMEOUT*2);} });
})();

function refreshNotificationCount(){ $.ajax({url:'../ajax/get_notifications.php',method:'GET',dataType:'json',success:function(r){if(r&&typeof r.unread_count!=='undefined'){var count=parseInt(r.unread_count)||0;var $badge=$('#notification_count,.notification-badge').first();if($badge.length){$badge.text(count).toggle(count>0);}}}}); }

/* ═══════════════════════════════════════════════════════════
   PHASE 25 — Advanced POS Functions
   1. printThermalDirect   — thermal receipt after sale
   2. viewA4Invoice        — A4 professional invoice
   3. downloadPdf          — opens A4 invoice in new tab (Save as PDF)
   4. FEFO batch info badge in cart
═══════════════════════════════════════════════════════════ */

/* Thermal print for last confirmed sale */
function printThermalDirect(){
    var sid = window._lastSaleId;
    if(!sid){ Notify.warn('No sale processed yet.'); return; }
    window.open('print_invoice.php?id='+sid+'&print=1','_blank','width=440,height=720');
}

/* A4 invoice view (browser, printable) */
function viewA4Invoice(){
    var sid = window._lastSaleId;
    if(!sid){ Notify.warn('No sale processed yet.'); return; }
    window.open('invoice_pdf.php?id='+sid+'&view=1','_blank','width=900,height=720');
}

/* PDF download — opens A4 page and triggers browser print→Save as PDF */
function downloadPdf(){
    var sid = window._lastSaleId;
    if(!sid){ Notify.warn('No sale processed yet.'); return; }
    window.open('invoice_pdf.php?id='+sid,'_blank','width=900,height=720');
}

/* ── FEFO cart badge helper ─────────────────────────────────
   Called by renderCart to add FEFO warning badge when a
   cart item spans multiple batches (FEFO split).
──────────────────────────────────────────────────────────── */
function getFEFOBadge(batches){
    if(!batches || batches.length <= 1) return '';
    return '<span title="First Expire First Out — '+batches.length+' batches will be used" '
        +'style="display:inline-block;background:#e8f5e9;color:#1b5e20;font-size:8px;'
        +'font-weight:700;border:1px solid #a5d6a7;border-radius:3px;padding:0 4px;'
        +'margin-left:4px;letter-spacing:0.4px;vertical-align:middle;">'
        +'FEFO×'+batches.length+'</span>';
}

/* Patch renderCart to show FEFO badge and nearest expiry in cart items */
var _origRenderCart = renderCart;
renderCart = function(){
    _origRenderCart();
    /* After render, inject FEFO+expiry info under each cart product name */
    for(var i = 0; i < cartState.length; i++){
        var it = cartState[i];
        var batches = it.batches || [];
        if(batches.length === 0) continue;
        /* Nearest expiry (first batch in FEFO order) */
        var nearBatch = batches[0];
        var daysLeft  = parseInt(nearBatch.days_left);
        var expDate   = nearBatch.expiry_date || '';
        var batchNo   = nearBatch.batch_number || '';
        if(!expDate) continue;
        var expInfo   = '';
        var expColor  = '#555';
        if(!isNaN(daysLeft)){
            if(daysLeft <= 0)      { expColor='#c0392b'; expInfo='EXPIRES TODAY'; }
            else if(daysLeft <= 30){ expColor='#e67e22'; expInfo='Exp '+expDate+' ('+daysLeft+'d)'; }
            else                   { expColor='#27ae60'; expInfo='Exp '+expDate; }
        } else {
            expInfo = 'Exp '+expDate;
        }
        var bLabel = batchNo ? 'Batch: '+esc(batchNo)+' · ' : '';
        var fefoEl = '<div class="cart-fefo-info" style="font-size:9px;color:'+expColor+';margin-top:1px;">'
            + '<i class="fas fa-layer-group" style="margin-right:3px;"></i>'
            + bLabel + expInfo
            + (batches.length > 1 ? ' <span style="background:#e8f5e9;color:#1b5e20;border:1px solid #a5d6a7;border-radius:3px;padding:0 3px;font-size:8px;font-weight:700;">FEFO×'+batches.length+'</span>' : '')
            + '</div>';
        /* Inject after cart-item-detail in this item's block */
        var pid = it.product_id;
        var $block = $('#ci_block_'+pid);
        if($block.length && !$block.find('.cart-fefo-info').length){
            var $detail = $block.find('.cart-item-detail').last();
            if($detail.length) $detail.after(fefoEl);
            else $block.find('.cart-item-name').first().after(fefoEl);
        }
    }
};

$(function(){
    loadAllProducts();
    fetchAndRenderCart();
    setPayMethod('cash');
    calcBalance();
    $('#overall_tax_pct').val(defaultTax);
    $('#paid_amount').on('input', calcBalance);
    $('#barcode_scan_input').focus();

    $.getJSON('../sales/customers.php', {ajax:'search', q:'', limit:300}, function(data){
        if(data && Array.isArray(data.rows) && data.rows.length > 0){
            var mapped = data.rows.map(function(r){ return {customer_name:r.name||'',customer_phone:r.phone||'',due_amount:parseFloat(r.total_pending||0)}; });
            var existing = {};
            _custCache.forEach(function(c){ existing[c.customer_phone||'']=true; });
            mapped.forEach(function(c){ var key=c.customer_phone||''; if(!existing[key]){_custCache.push(c);existing[key]=true;} else{for(var i=0;i<_custCache.length;i++){if(_custCache[i].customer_phone===key){_custCache[i].due_amount=c.due_amount;break;}}} });
        }
    });

    $(document).on('click',function(e){ if($(e.target).closest('#customer_panel,#customer_autocomplete,.cust-ac-item').length)return;var tag=(e.target.tagName||'').toLowerCase();if(tag!=='input'&&tag!=='textarea'&&tag!=='select'&&tag!=='button'&&tag!=='a'){$('#barcode_scan_input').focus();} });

    refreshNotificationCount();
    setInterval(refreshNotificationCount, 120000);
});
</script>

<script>
/* ── STEP 11: POS Cart Auto-Save (Power Failure Recovery) ── */
(function () {
    var userId     = <?php echo (int)($_SESSION['user_id'] ?? 0); ?>;
    var CART_KEY   = 'pos_cart_user_' + userId;
    var SAVE_MS    = 5000;  // save every 5 seconds
    var saveTimer  = null;

    // ── Save current cartState to localStorage ────────────────
    function saveCart() {
        if (typeof cartState === 'undefined' || !cartState.length) return;
        try {
            var snapshot = {
                cartState    : cartState,
                customerName : $('#customer_name').val() || '',
                customerPhone: $('#customer_phone').val() || '',
                payMethod    : $('#payment_method_hidden').val() || 'cash',
                paidAmount   : $('#paid_amount').val() || 0,
                savedAt      : new Date().toISOString(),
            };
            localStorage.setItem('pharmacy_autosave_' + CART_KEY, JSON.stringify(snapshot));
            AutoSave.showIndicator();
        } catch (e) { /* storage unavailable */ }
    }

    // ── Restore saved cart on page load ───────────────────────
    function checkRestore() {
        try {
            var raw = localStorage.getItem('pharmacy_autosave_' + CART_KEY);
            if (!raw) return;
            var snap = JSON.parse(raw);
            if (!snap || !snap.cartState || !snap.cartState.length) return;

            var ts = '';
            try { ts = new Date(snap.savedAt).toLocaleTimeString(); } catch(e){}

            // Show restore banner
            var banner = document.createElement('div');
            banner.id  = 'pos-restore-banner';
            banner.style.cssText = 'position:fixed;top:0;left:0;right:0;background:#1d4ed8;color:#fff;padding:12px 20px;z-index:99998;display:flex;align-items:center;justify-content:space-between;gap:12px;font-size:13px;font-family:inherit;box-shadow:0 2px 8px rgba(0,0,0,0.2);';
            banner.innerHTML =
                '<span>&#9889; Unsaved cart from <strong>' + ts + '</strong> found &mdash; Restore?</span>' +
                '<span style="display:flex;gap:8px;">' +
                    '<button id="pos-btn-restore" style="background:#fff;color:#1d4ed8;border:none;padding:5px 14px;border-radius:6px;font-weight:700;cursor:pointer;font-size:12px;">Restore Cart</button>' +
                    '<button id="pos-btn-discard" style="background:rgba(255,255,255,0.15);color:#fff;border:1px solid rgba(255,255,255,0.4);padding:5px 14px;border-radius:6px;cursor:pointer;font-size:12px;">Discard</button>' +
                '</span>';
            document.body.prepend(banner);

            document.getElementById('pos-btn-restore').addEventListener('click', function () {
                banner.remove();
                // Restore cart state
                cartState = snap.cartState;
                renderCart();
                // Restore customer fields
                if (snap.customerName)  $('#customer_name').val(snap.customerName);
                if (snap.customerPhone) $('#customer_phone').val(snap.customerPhone);
                if (snap.paidAmount)    $('#paid_amount').val(snap.paidAmount);
                AutoSave.showIndicator('Cart restored from ' + ts);
            });

            document.getElementById('pos-btn-discard').addEventListener('click', function () {
                banner.remove();
                localStorage.removeItem('pharmacy_autosave_' + CART_KEY);
            });

        } catch (e) { /* parse error — ignore */ }
    }

    // ── Start auto-save interval ──────────────────────────────
    function startAutoSave() {
        if (saveTimer) clearInterval(saveTimer);
        saveTimer = setInterval(saveCart, SAVE_MS);
    }

    // ── Stop and clear on page unload ─────────────────────────
    window.addEventListener('beforeunload', function () {
        saveCart(); // final save on close/refresh
    });

    // ── Init: wait for DOM + cartState to be ready ────────────
    $(document).ready(function () {
        startAutoSave();
        // Small delay so fetchAndRenderCart() finishes first
        setTimeout(checkRestore, 1200);
    });

})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
