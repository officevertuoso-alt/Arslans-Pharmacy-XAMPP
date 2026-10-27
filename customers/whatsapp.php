<?php
/**
 * customers/whatsapp.php — WhatsApp Credit Reminder Integration
 * FIX M-04: Implements WhatsApp integration referenced in sidebar/specs
 * Powered by Dr. Arslan Yasin Sukhera
 */
require_once '../config/config.php';
require_once '../includes/auth.php';
requireLogin();

$customerId   = (int)($_GET['customer_id'] ?? 0);
$customerPhone = sanitize($_GET['phone'] ?? '');
$mode          = sanitize($_GET['mode'] ?? 'balance'); // balance | invoice | general

// ── Fetch customer info ───────────────────────────────────────────────────────
$customer = null;
if ($customerId > 0) {
    $customer = getOne(
        "SELECT c.id, c.name, c.phone, c.email,
                COALESCE((
                    SELECT SUM(cc.total_amount) - COALESCE(SUM(
                        (SELECT SUM(cp.amount) FROM credit_payments cp WHERE cp.credit_id = cc.id)
                    ),0)
                    FROM customer_credits cc
                    WHERE cc.customer_phone = c.phone AND cc.status IN ('active','overdue')
                ), 0) AS outstanding_balance
         FROM customers c
         WHERE c.id = :id LIMIT 1",
        [':id' => $customerId]
    );
}

// Fallback: look up by phone in customer_credits if no customers table entry
if (!$customer && !empty($customerPhone)) {
    $customer = getOne(
        "SELECT customer_name AS name, customer_phone AS phone,
                COALESCE(SUM(total_amount) - COALESCE(SUM(
                    (SELECT SUM(cp.amount) FROM credit_payments cp WHERE cp.credit_id = id)
                ),0), 0) AS outstanding_balance
         FROM customer_credits
         WHERE customer_phone = :phone AND status IN ('active','overdue')
         GROUP BY customer_phone, customer_name
         LIMIT 1",
        [':phone' => $customerPhone]
    );
}

if (!$customer) {
    if (defined('BASE_URL')) {
        header('Location: ' . BASE_URL . 'customers/customers.php?error=Customer+not+found');
    } else {
        header('Location: customers.php?error=Customer+not+found');
    }
    exit;
}

$name    = $customer['name'] ?? 'Valued Customer';
$phone   = preg_replace('/[^0-9+]/', '', $customer['phone'] ?? $customerPhone);
$balance = number_format((float)($customer['outstanding_balance'] ?? 0), 2);

// ── Ensure phone has country code ─────────────────────────────────────────────
if (!empty($phone) && $phone[0] !== '+') {
    // Pakistan default: add +92 and strip leading 0
    if (str_starts_with($phone, '0')) {
        $phone = '+92' . substr($phone, 1);
    } else {
        $phone = '+92' . $phone;
    }
}

// ── Build message based on mode ───────────────────────────────────────────────
$pharmacyName = 'Arslan\'s Pharmacy';
try {
    $settings = getOne("SELECT pharmacy_name FROM company_settings LIMIT 1");
    if (!empty($settings['pharmacy_name'])) {
        $pharmacyName = $settings['pharmacy_name'];
    }
} catch (Throwable $e) { /* use default */ }

switch ($mode) {
    case 'invoice':
        $invoiceNo = sanitize($_GET['invoice'] ?? '');
        $message = "Dear {$name},\n\nThis is a reminder from {$pharmacyName} regarding your outstanding invoice {$invoiceNo}.\n\nYour current balance is: Rs {$balance}\n\nPlease visit us or call to clear your dues at your earliest convenience.\n\nThank you for your business.\n— Dr. Arslan Yasin Sukhera";
        break;
    case 'general':
        $message = "Dear {$name},\n\nGreetings from {$pharmacyName}!\n\nWe hope you are doing well. Please feel free to visit us for all your medical needs.\n\n— Dr. Arslan Yasin Sukhera";
        break;
    default: // balance
        $message = "Dear {$name},\n\nThis is a payment reminder from {$pharmacyName}.\n\nYour outstanding balance is: Rs {$balance}\n\nKindly visit us or contact us to clear your dues.\n\nThank you.\n— Dr. Arslan Yasin Sukhera";
        break;
}

// ── Log the WhatsApp action ───────────────────────────────────────────────────
if (function_exists('logActivity')) {
    logActivity('WhatsApp Reminder', "Sent WhatsApp reminder to {$name} ({$phone}) — Balance: Rs {$balance}");
}

// ── Redirect to WhatsApp ──────────────────────────────────────────────────────
$encodedMessage = urlencode($message);
$waUrl = "https://wa.me/{$phone}?text={$encodedMessage}";

header("Location: {$waUrl}");
exit;
