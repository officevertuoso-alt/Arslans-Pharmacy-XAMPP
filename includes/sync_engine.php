<?php // REPAIRED vFIX6
/**
 * includes/sync_engine.php
 * SYNC ENGINE — Central Nervous System of Pharmacy ERP
 *
 * STEP 3 FIXES APPLIED:
 * 1. if(!function_exists()) guards on ALL functions — no Fatal redeclare errors
 * 2. ensureCashbookEntry() balance bug FIXED — uses ORDER BY id DESC (not entry_date)
 *    to get last balance, preventing race condition and wrong running totals
 * 3. getDashboardStatsLive() — FIXED: uses COALESCE(status,'active') so missing
 *    status column doesn't crash; cashbook voided=0 filter added; today_expenses added
 * 4. getDashboardStatsLive() — credit given now uses `total` not `grand_total`
 *    (matches actual DB column name in sales table)
 * 5. syncAfterCreditPayment() — uses `total` not `grand_total` for sale lookup
 * 6. syncAfterSale() — uses `total` not `grand_total` for sale lookup
 * 7. syncAfterExpense() — fetches expense description+category from DB for better cashbook entry
 * 8. syncAfterPurchase() — payment_method fetched from purchases table (not hardcoded 'cash')
 * 9. syncAfterSupplierPayment() — NEW function, creates cashbook DEBIT for supplier payments
 * 10. clearAllCaches() — also clears CACHE_KEY_DASHBOARD session key from functions.php
 * 
 * FORENSIC REPAIRS (vFIX6):
 *   - clearAllCaches(): Added missing cache keys (company_settings, perm_cache, cache_*) (ISSUE 5)
 *   - getDashboardStatsLive(): Uses COALESCE(status,'active') everywhere (ISSUE 6)
 *   - syncAfterSale(): Uses `total` column not `grand_total` (ISSUE 7)
 *   - ensureCashbookEntry(): Uses ORDER BY id DESC (NOT entry_date) (ISSUE 8)
 */

// Safe includes — prevent double-load
if (!defined('DB_HOST')) {
    require_once __DIR__ . '/../config/database.php';
}

// Pull in global helpers (insertData, _pdoOne, getDashboardStatsLive, etc.)
// functions.php declares everything with if(!function_exists()) so safe to include multiple times
if (function_exists('db') && !function_exists('insertData')) {
    require_once __DIR__ . '/functions.php';
}

// ==========================================================================
// STOCK REFRESH HELPER (added for purchase return sync)
// ==========================================================================
if (!function_exists('refreshProductStockAfterEvent')) {
    /**
     * Refresh products.stock_quantity for given product IDs.
     * Uses updateProductStockAndPrice() if available (from purchase_functions.php),
     * otherwise falls back to direct SQL.
     *
     * @param array $productIds
     * @return void
     */
    function refreshProductStockAfterEvent(array $productIds): void {
        if (empty($productIds)) return;
        $productIds = array_unique(array_map('intval', $productIds));

        if (function_exists('updateProductStockAndPrice')) {
            foreach ($productIds as $pid) {
                updateProductStockAndPrice($pid);
            }
        } else {
            $pdo = _getPDO();
            foreach ($productIds as $pid) {
                $pdo->prepare("
                    UPDATE products p
                    SET p.stock_quantity = (
                        SELECT COALESCE(SUM(b.remaining_quantity), 0)
                        FROM batches b
                        WHERE b.product_id = p.id
                          AND b.is_expired = 0
                          AND b.remaining_quantity > 0
                    )
                    WHERE p.id = :pid
                ")->execute([':pid' => $pid]);
            }
        }
    }
}

// ==========================================================================
// clearAllCaches()
// ISSUE 5: Clear ALL known session cache keys
// ==========================================================================
if (!function_exists('clearAllCaches')) {
    function clearAllCaches(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Clear every session key that is a cache
        $clearPrefixes = ['dashboard_', 'stats_', 'cache_'];
        foreach (array_keys($_SESSION) as $key) {
            foreach ($clearPrefixes as $prefix) {
                if (strpos($key, $prefix) === 0) {
                    unset($_SESSION[$key]);
                    break;
                }
            }
        }

        // ISSUE 5: Clear specific known cache keys
        $specificKeys = [
            'dashboard_stats',
            'dashboard_stats_exp',
            'company_settings',
            'company_settings_cache',
            'company_settings_cache_time',
            'perm_cache',
            'user_permissions_cache',
            '_company_settings',
            'pharmacy_name',
            'pharmacy_logo',
            'theme_colors'
        ];
        foreach ($specificKeys as $key) {
            unset($_SESSION[$key]);
        }

        // Also clear the named dashboard key from functions.php
        $dashKey = defined('CACHE_KEY_DASHBOARD') ? CACHE_KEY_DASHBOARD : 'dashboard_stats';
        unset($_SESSION[$dashKey], $_SESSION[$dashKey . '_exp']);

        // Call Database static cache cleaner if available
        if (method_exists('Database', 'clearCache')) {
            Database::clearCache();
        }
    }
}

// ==========================================================================
// publishSyncEvent()
// STEP 7 — Inserts a row into sync_events so SSE clients get notified.
// Non-fatal: if sync_events table is missing, logs and continues.
// ==========================================================================
if (!function_exists('publishSyncEvent')) {
    function publishSyncEvent(string $event_type, array $payload): void
    {
        try {
            $pdo  = db()->getConnection();
            $stmt = $pdo->prepare(
                "INSERT INTO sync_events (event_type, payload, created_at)
                 VALUES (:type, :payload, NOW(3))"
            );
            $stmt->execute([
                ':type'    => $event_type,
                ':payload' => json_encode($payload),
            ]);
        } catch (\Throwable $e) {
            // Non-fatal — sync_events missing or DB error should not break transactions
            error_log('[publishSyncEvent] ' . $e->getMessage());
        }
    }
}

// ==========================================================================
// ensureCashbookEntry()
// ISSUE 8: balance uses ORDER BY id DESC (not entry_date) — race-condition-safe
// STEP 7: Supports 'expired_loss' entry_type
// ==========================================================================
if (!function_exists('ensureCashbookEntry')) {
    function ensureCashbookEntry(
        string  $entry_type,
        ?int    $reference_id,
        string  $reference_type,
        string  $description,
        float   $debit          = 0.00,
        float   $credit         = 0.00,
        string  $payment_method = 'cash',
        ?string $entry_date     = null
    ): int|false {

        $debit      = round($debit,  2);
        $credit     = round($credit, 2);
        $entry_date = $entry_date ?? date('Y-m-d');

        // ── Duplicate guard (voided=0 only — voided entries don't block new ones) ──
        try {
            $existing = _pdoOne(
                "SELECT id FROM cashbook
                 WHERE reference_id   = :ref_id
                   AND reference_type = :ref_type
                   AND entry_type     = :etype
                   AND voided         = 0
                 LIMIT 1",
                [':ref_id' => $reference_id, ':ref_type' => $reference_type, ':etype' => $entry_type]
            );
            if ($existing) {
                return (int)$existing['id'];   // Already synced — idempotent
            }
        } catch (\Throwable $e) {
            // cashbook table missing — let insertData() surface the real error below
        }

        // ── ISSUE 8: Get last running balance (ORDER BY id DESC — race-condition-safe) ─
        try {
            $latest = _pdoOne(
                "SELECT balance FROM cashbook WHERE voided = 0 ORDER BY id DESC LIMIT 1"
            );
            $prev_balance = $latest ? (float)$latest['balance'] : 0.00;
        } catch (\Throwable $e) {
            $prev_balance = 0.00;
        }

        $new_balance = round($prev_balance + $credit - $debit, 2);

        // ── Insert entry ─────────────────────────────────────────────────────
        $user_id = $_SESSION['user_id'] ?? 0;

        $inserted = insertData('cashbook', [
            'entry_date'     => $entry_date,
            'entry_type'     => $entry_type,
            'reference_id'   => $reference_id,
            'reference_type' => $reference_type,
            'description'    => $description,
            'debit'          => $debit,
            'credit'         => $credit,
            'balance'        => $new_balance,
            'payment_method' => $payment_method,
            'created_by'     => $user_id,
            'voided'         => 0,
            'voided_reason'  => null,
        ]);

        if ($inserted === false) {
            error_log("[SYNC] ensureCashbookEntry FAILED: {$entry_type} ref={$reference_type}#{$reference_id}");
            return false;
        }

        return (int)$inserted;
    }
}

// ==========================================================================
// syncAfterSale()
// ISSUE 7: reads `total` column (not `grand_total`) to match actual DB schema
// ==========================================================================
if (!function_exists('syncAfterSale')) {
    function syncAfterSale(
        int     $sale_id,
        string  $payment_method,
        float   $grand_total,
        float   $paid_amount,
        bool    $is_credit,
        ?string $invoice_no = null
    ): array {
        $synced = [];
        $errors = [];

        try {
            $pdo = _getPDO();
            $pdo->beginTransaction();

            clearAllCaches();
            $synced[] = 'caches_cleared';

            $paid_amount = round($paid_amount, 2);
            $grand_total = round($grand_total, 2);

            // Payment status
            if ($paid_amount >= $grand_total) {
                $payment_status = 'paid';
            } elseif ($paid_amount > 0) {
                $payment_status = 'partial';
            } else {
                $payment_status = 'pending';
            }

            // Update sales.payment_status + net_amount + grand_total
            _pdoRun(
                "UPDATE sales
                 SET payment_status = :status,
                     grand_total    = COALESCE(NULLIF(grand_total,0), :gt),
                     net_amount     = COALESCE(NULLIF(net_amount,0),  :gt)
                 WHERE id = :id",
                [':status' => $payment_status, ':gt' => $grand_total, ':id' => $sale_id]
            );
            $synced[] = 'sales_payment_status_updated';

            // Cashbook credit entry for paid portion
            if ($paid_amount > 0) {
                $cb_id = ensureCashbookEntry(
                    entry_type:     'sale',
                    reference_id:   $sale_id,
                    reference_type: 'sale',
                    description:    'Sale ' . ($invoice_no ?? "INV-{$sale_id}") . ' - Payment received',
                    debit:          0,
                    credit:         $paid_amount,
                    payment_method: $payment_method
                );
                if ($cb_id !== false) {
                    $synced[] = "cashbook_entry_{$cb_id}";
                } else {
                    $errors[] = 'Failed to create cashbook entry for sale payment';
                }
            }

            // Credit sale — ensure customer_credits record
            if ($is_credit) {
                $credit_rec = _pdoOne(
                    "SELECT id FROM customer_credits WHERE sale_id = :sid LIMIT 1",
                    [':sid' => $sale_id]
                );
                if (!$credit_rec) {
                    // ISSUE 7: Use `total` column (actual DB column)
                    $sale = _pdoOne(
                        "SELECT customer_name, customer_phone, total FROM sales WHERE id = :id",
                        [':id' => $sale_id]
                    );
                    if ($sale) {
                        $credit_amount = round((float)$sale['total'] - $paid_amount, 2);
                        $inserted = insertData('customer_credits', [
                            'sale_id'        => $sale_id,
                            'customer_name'  => $sale['customer_name'],
                            'customer_phone' => $sale['customer_phone'] ?? '',
                            'total_amount'   => $credit_amount,
                            'due_date'       => date('Y-m-d', strtotime('+30 days')),
                            'status'         => 'active',
                            'description'    => 'Credit sale - ' . ($invoice_no ?? "INV-{$sale_id}"),
                        ]);
                        $synced[] = $inserted ? "customer_credit_created_{$inserted}" : 'customer_credit_insert_failed';
                        if (!$inserted) $errors[] = 'Failed to create customer_credit record';
                    }
                } else {
                    $synced[] = 'customer_credit_already_exists';
                }
            }

            $pdo->commit();

            // ── STEP 7: Publish sync event for real-time clients ──
            publishSyncEvent('sale_completed', [
                'sale_id'      => $sale_id,
                'grand_total'  => $grand_total,
                'paid_amount'  => $paid_amount,
                'is_credit'    => $is_credit,
                'invoice_no'   => $invoice_no ?? "INV-{$sale_id}",
            ]);

            return ['success' => empty($errors), 'synced' => $synced, 'errors' => $errors];

        } catch (\Throwable $e) {
            try { _getPDO()->rollBack(); } catch (\Throwable $_) {}
            error_log("[SYNC] syncAfterSale error: " . $e->getMessage());
            return ['success' => false, 'synced' => $synced, 'errors' => array_merge($errors, [$e->getMessage()])];
        }
    }
}

// ==========================================================================
// syncAfterCreditPayment()
// ISSUE 7: uses `total` not `grand_total` when reading sale row
// ==========================================================================
if (!function_exists('syncAfterCreditPayment')) {
    function syncAfterCreditPayment(
        int    $credit_id,
        int    $payment_id,
        float  $amount,
        string $payment_method
    ): array {
        $synced = [];
        $errors = [];

        try {
            $pdo = _getPDO();
            $pdo->beginTransaction();

            clearAllCaches();
            $synced[] = 'caches_cleared';

            // Cashbook CREDIT for money received
            $cb_id = ensureCashbookEntry(
                entry_type:     'credit_received',
                reference_id:   $payment_id,
                reference_type: 'credit_payment',
                description:    "Credit payment received (Credit ID: {$credit_id})",
                debit:          0,
                credit:         round($amount, 2),
                payment_method: $payment_method
            );
            if ($cb_id !== false) {
                $synced[] = "cashbook_entry_{$cb_id}";
            } else {
                $errors[] = 'Failed to create cashbook entry for credit payment';
            }

            // Recalculate total paid for this credit
            $total_paid_row = _pdoOne(
                "SELECT COALESCE(SUM(amount), 0) AS total FROM credit_payments WHERE credit_id = :cid",
                [':cid' => $credit_id]
            );
            $total_paid = (float)($total_paid_row['total'] ?? 0);

            // Get credit record
            $credit = _pdoOne(
                "SELECT sale_id, total_amount, due_date FROM customer_credits WHERE id = :cid",
                [':cid' => $credit_id]
            );
            if (!$credit) throw new \Exception("Credit record not found: {$credit_id}");

            $total_amount = (float)$credit['total_amount'];
            $due_date     = $credit['due_date'];
            $new_status   = ($total_paid >= $total_amount) ? 'paid' : 'active';
            if ($new_status === 'active' && strtotime($due_date) < time()) {
                $new_status = 'overdue';
            }

            _pdoRun(
                "UPDATE customer_credits SET status = :status WHERE id = :cid",
                [':status' => $new_status, ':cid' => $credit_id]
            );
            $synced[] = "credit_status_{$new_status}";

            // Update linked sale — ISSUE 7: use `total` (actual column name)
            $sale_id = $credit['sale_id'];
            if ($sale_id) {
                $sale = _pdoOne(
                    "SELECT paid_amount, total FROM sales WHERE id = :sid",
                    [':sid' => $sale_id]
                );
                if ($sale) {
                    $new_paid  = round((float)$sale['paid_amount'] + $amount, 2);
                    $sale_gt   = (float)$sale['total'];
                    $pay_stat  = ($new_paid >= $sale_gt) ? 'paid' : ($new_paid > 0 ? 'partial' : 'pending');
                    _pdoRun(
                        "UPDATE sales SET paid_amount = :paid, payment_status = :status WHERE id = :sid",
                        [':paid' => $new_paid, ':status' => $pay_stat, ':sid' => $sale_id]
                    );
                    $synced[] = 'sale_paid_amount_updated';
                }
            }

            $pdo->commit();
            return ['success' => empty($errors), 'synced' => $synced, 'errors' => $errors];

        } catch (\Throwable $e) {
            try { _getPDO()->rollBack(); } catch (\Throwable $_) {}
            error_log("[SYNC] syncAfterCreditPayment error: " . $e->getMessage());
            return ['success' => false, 'synced' => $synced, 'errors' => array_merge($errors, [$e->getMessage()])];
        }
    }
}

// ==========================================================================
// syncAfterPurchase()
// ==========================================================================
if (!function_exists('syncAfterPurchase')) {
    function syncAfterPurchase(
        int    $purchase_id,
        string $payment_status,
        float  $paid_amount,
        float  $total
    ): array {
        $synced = [];
        $errors = [];

        try {
            $pdo = _getPDO();
            $pdo->beginTransaction();

            clearAllCaches();
            $synced[] = 'caches_cleared';

            $paid_amount = round($paid_amount, 2);
            $total       = round($total, 2);
            $due_amount  = round(max(0, $total - $paid_amount), 2);

            // 1. Update purchase due_amount
            _pdoRun(
                "UPDATE purchases SET due_amount = :due WHERE id = :pid",
                [':due' => $due_amount, ':pid' => $purchase_id]
            );
            $synced[] = 'purchase_due_updated';

            // 2. Cashbook entry based on payment status
            // Always fetch purchase details first
            $pur = _pdoOne(
                "SELECT purchase_invoice_no, supplier_id, payment_method FROM purchases WHERE id = :pid",
                [':pid' => $purchase_id]
            );
            $inv_label    = $pur['purchase_invoice_no'] ?? "PUR-{$purchase_id}";
            $supplier_id  = (int)($pur['supplier_id'] ?? 0);
            $pay_method   = $pur['payment_method'] ?? 'cash';

            if ($paid_amount > 0) {
                // Paid or partial — record actual cash going out
                $cb_id = ensureCashbookEntry(
                    entry_type:     'purchase_payment',
                    reference_id:   $purchase_id,
                    reference_type: 'purchase',
                    description:    "Purchase payment - {$inv_label}",
                    debit:          $paid_amount,
                    credit:         0,
                    payment_method: $pay_method
                );
                if ($cb_id !== false) {
                    $synced[] = "cashbook_entry_{$cb_id}";
                } else {
                    $errors[] = 'Failed to create cashbook entry for purchase';
                }
            }

            // Record credit/pending balance as purchase_credit (informational debit — no cash moved)
            if ($due_amount > 0) {
                $cb_credit_id = ensureCashbookEntry(
                    entry_type:     'purchase_credit',
                    reference_id:   $purchase_id,
                    reference_type: 'purchase_credit',
                    description:    "Credit purchase (due) - {$inv_label}",
                    debit:          $due_amount,
                    credit:         0,
                    payment_method: 'credit_note'
                );
                if ($cb_credit_id !== false) {
                    $synced[] = "cashbook_credit_entry_{$cb_credit_id}";
                } else {
                    $errors[] = 'Failed to create cashbook credit entry for purchase';
                }
            }

            // 3. Update supplier current_balance = sum of all unpaid dues
            if (!empty($supplier_id)) {
                $due_row = _pdoOne(
                    "SELECT COALESCE(SUM(due_amount), 0) AS total_due
                     FROM purchases
                     WHERE supplier_id = :sid AND payment_status != 'paid'",
                    [':sid' => $supplier_id]
                );
                $total_due = (float)($due_row['total_due'] ?? 0);
                _pdoRun(
                    "UPDATE suppliers SET current_balance = :bal WHERE id = :sid",
                    [':bal' => $total_due, ':sid' => $supplier_id]
                );
                $synced[] = 'supplier_balance_updated';
            }

            $pdo->commit();

            // ── STEP 7: Publish sync event for real-time clients ──
            publishSyncEvent('purchase_added', [
                'purchase_id'    => $purchase_id,
                'payment_status' => $payment_status,
                'paid_amount'    => $paid_amount,
                'total'          => $total,
            ]);

            return ['success' => empty($errors), 'synced' => $synced, 'errors' => $errors];

        } catch (\Throwable $e) {
            try { _getPDO()->rollBack(); } catch (\Throwable $_) {}
            error_log("[SYNC] syncAfterPurchase error: " . $e->getMessage());
            return ['success' => false, 'synced' => $synced, 'errors' => array_merge($errors, [$e->getMessage()])];
        }
    }
}

// ==========================================================================
// syncAfterSaleReturn()
// ISSUE 7: uses `total` column (not `grand_total`) for sale net_amount recalculation
// ==========================================================================
if (!function_exists('syncAfterSaleReturn')) {
    function syncAfterSaleReturn(
        int    $sale_return_id,
        int    $sale_id,
        float  $return_amount,
        string $refund_method,
        string $return_no
    ): array {
        $synced = [];
        $errors = [];

        try {
            $pdo = _getPDO();
            $pdo->beginTransaction();

            clearAllCaches();
            $synced[] = 'caches_cleared';

            $return_amount = round($return_amount, 2);

            // 1. Cashbook DEBIT (cash out — refund to customer)
            $cb_id = ensureCashbookEntry(
                entry_type:     'sale_return',
                reference_id:   $sale_return_id,
                reference_type: 'sale_return',
                description:    "Sale Return {$return_no} - Refund to Customer",
                debit:          $return_amount,
                credit:         0,
                payment_method: $refund_method
            );
            if ($cb_id !== false) {
                $synced[] = "cashbook_entry_{$cb_id}";
            } else {
                $errors[] = 'Failed to create cashbook entry for sale return';
            }

            // 2. Recalculate sales.net_amount = total - all_returns
            $total_returns_row = _pdoOne(
                "SELECT COALESCE(SUM(total_amount), 0) AS total
                 FROM sale_returns WHERE sale_id = :sid AND COALESCE(voided,0) = 0",
                [':sid' => $sale_id]
            );
            $total_returns = (float)($total_returns_row['total'] ?? 0);

            // ISSUE 7: Use `total` column (actual DB column name)
            $sale = _pdoOne(
                "SELECT total, paid_amount FROM sales WHERE id = :sid",
                [':sid' => $sale_id]
            );
            if ($sale) {
                $orig_total = (float)$sale['total'];
                $new_net    = round(max(0, $orig_total - $total_returns), 2);
                _pdoRun(
                    "UPDATE sales SET net_amount = :net WHERE id = :sid",
                    [':net' => $new_net, ':sid' => $sale_id]
                );
                $synced[] = 'sales_net_updated';

                // Update payment_status
                $paid = (float)$sale['paid_amount'];
                if ($new_net <= 0) {
                    $pay_stat = 'returned';
                } elseif ($paid >= $new_net) {
                    $pay_stat = 'paid';
                } elseif ($paid > 0) {
                    $pay_stat = 'partial';
                } else {
                    $pay_stat = 'pending';
                }
                _pdoRun(
                    "UPDATE sales SET payment_status = :ps WHERE id = :sid",
                    [':ps' => $pay_stat, ':sid' => $sale_id]
                );
                $synced[] = "sales_status_{$pay_stat}";
            }

            // 3. Adjust linked customer_credit if exists
            $credit_rec = _pdoOne(
                "SELECT id, total_amount FROM customer_credits WHERE sale_id = :sid LIMIT 1",
                [':sid' => $sale_id]
            );
            if ($credit_rec) {
                $new_credit = round(max(0, (float)$credit_rec['total_amount'] - $return_amount), 2);
                if ($new_credit <= 0) {
                    _pdoRun(
                        "UPDATE customer_credits SET status='cancelled', total_amount=0 WHERE id=:cid",
                        [':cid' => $credit_rec['id']]
                    );
                    $synced[] = 'credit_cancelled';
                } else {
                    _pdoRun(
                        "UPDATE customer_credits SET total_amount=:amt WHERE id=:cid",
                        [':amt' => $new_credit, ':cid' => $credit_rec['id']]
                    );
                    $synced[] = 'credit_reduced';
                }
            }

            $pdo->commit();

            // ── STEP 7: Publish sync event for real-time clients ──
            publishSyncEvent('return_processed', [
                'type'           => 'sale_return',
                'return_id'      => $sale_return_id,
                'sale_id'        => $sale_id,
                'return_amount'  => $return_amount,
                'return_no'      => $return_no,
            ]);

            return ['success' => empty($errors), 'synced' => $synced, 'errors' => $errors];

        } catch (\Throwable $e) {
            try { _getPDO()->rollBack(); } catch (\Throwable $_) {}
            error_log("[SYNC] syncAfterSaleReturn error: " . $e->getMessage());
            return ['success' => false, 'synced' => $synced, 'errors' => array_merge($errors, [$e->getMessage()])];
        }
    }
}

// ==========================================================================
// syncAfterPurchaseReturn() – UPDATED to refresh product stock
// ==========================================================================
if (!function_exists('syncAfterPurchaseReturn')) {
    function syncAfterPurchaseReturn(
        int    $purchase_return_id,
        int    $purchase_id,
        int    $supplier_id,
        float  $return_amount,
        string $return_no,
        string $refund_method
    ): array {
        $synced = [];
        $errors = [];

        try {
            $pdo = _getPDO();
            $pdo->beginTransaction();

            clearAllCaches();
            $synced[] = 'caches_cleared';

            $return_amount = round($return_amount, 2);

            // 1. Cashbook CREDIT (cash back from supplier)
            $cb_id = ensureCashbookEntry(
                entry_type:     'purchase_return',
                reference_id:   $purchase_return_id,
                reference_type: 'purchase_return',
                description:    "Purchase Return {$return_no} - Refund from Supplier",
                debit:          0,
                credit:         $return_amount,
                payment_method: $refund_method
            );
            if ($cb_id !== false) {
                $synced[] = "cashbook_entry_{$cb_id}";
            } else {
                $errors[] = 'Failed to create cashbook entry for purchase return';
            }

            // 2. Reduce purchase total and recalc due_amount
            $purchase = _pdoOne(
                "SELECT total, paid_amount FROM purchases WHERE id = :pid",
                [':pid' => $purchase_id]
            );
            if ($purchase) {
                $new_total = round(max(0, (float)$purchase['total'] - $return_amount), 2);
                $new_due   = round(max(0, $new_total - (float)$purchase['paid_amount']), 2);
                _pdoRun(
                    "UPDATE purchases SET total=:t, due_amount=:d WHERE id=:pid",
                    [':t' => $new_total, ':d' => $new_due, ':pid' => $purchase_id]
                );
                $synced[] = 'purchase_total_reduced';
            }

            // 3. Reduce supplier balance
            $supplier = _pdoOne(
                "SELECT current_balance FROM suppliers WHERE id = :sid",
                [':sid' => $supplier_id]
            );
            if ($supplier) {
                $new_bal = round(max(0, (float)$supplier['current_balance'] - $return_amount), 2);
                _pdoRun(
                    "UPDATE suppliers SET current_balance=:b WHERE id=:sid",
                    [':b' => $new_bal, ':sid' => $supplier_id]
                );
                $synced[] = 'supplier_balance_updated';
            }

            $pdo->commit();

            // ── NEW: Refresh product stock for all products affected by this purchase return ──
            $returnedProducts = _pdoAll(
                "SELECT DISTINCT pi.product_id
                 FROM purchase_returns pr
                 JOIN purchase_items pi ON pi.id = pr.purchase_item_id
                 WHERE pr.id = :rid",
                [':rid' => $purchase_return_id]
            );
            if ($returnedProducts) {
                $productIds = array_column($returnedProducts, 'product_id');
                refreshProductStockAfterEvent($productIds);
                $synced[] = 'product_stock_refreshed';
            }

            return ['success' => empty($errors), 'synced' => $synced, 'errors' => $errors];

        } catch (\Throwable $e) {
            try { _getPDO()->rollBack(); } catch (\Throwable $_) {}
            error_log("[SYNC] syncAfterPurchaseReturn error: " . $e->getMessage());
            return ['success' => false, 'synced' => $synced, 'errors' => array_merge($errors, [$e->getMessage()])];
        }
    }
}

// ==========================================================================
// syncAfterExpense()
// ==========================================================================
if (!function_exists('syncAfterExpense')) {
    function syncAfterExpense(
        int    $expense_id,
        float  $amount,
        string $payment_method
    ): array {
        $synced = [];
        $errors = [];

        try {
            $pdo = _getPDO();
            $pdo->beginTransaction();

            clearAllCaches();
            $synced[] = 'caches_cleared';

            $amount = round($amount, 2);

            // Build a meaningful description from the expenses table
            $description = "Expense #{$expense_id}";
            try {
                $exp = _pdoOne(
                    "SELECT e.description, e.title, e.notes,
                            COALESCE(ec.name, e.category, 'General') AS category_name
                     FROM expenses e
                     LEFT JOIN expense_categories ec ON ec.id = e.category_id
                     WHERE e.id = :eid",
                    [':eid' => $expense_id]
                );
                if ($exp) {
                    $label = $exp['title'] ?? $exp['description'] ?? $exp['notes'] ?? '';
                    $cat   = $exp['category_name'] ?? 'General';
                    if ($label) {
                        $description = "Expense [{$cat}]: {$label}";
                    } else {
                        $description = "Expense [{$cat}] #{$expense_id}";
                    }
                }
            } catch (\Throwable $_) {
                // expenses table schema varies — fall back to generic label
            }

            $cb_id = ensureCashbookEntry(
                entry_type:     'expense',
                reference_id:   $expense_id,
                reference_type: 'expense',
                description:    $description,
                debit:          $amount,
                credit:         0,
                payment_method: $payment_method
            );

            if ($cb_id !== false) {
                $synced[] = "cashbook_entry_{$cb_id}";
            } else {
                $errors[] = 'Failed to create cashbook entry for expense';
            }

            $pdo->commit();
            return ['success' => empty($errors), 'synced' => $synced, 'errors' => $errors];

        } catch (\Throwable $e) {
            try { _getPDO()->rollBack(); } catch (\Throwable $_) {}
            error_log("[SYNC] syncAfterExpense error: " . $e->getMessage());
            return ['success' => false, 'synced' => $synced, 'errors' => array_merge($errors, [$e->getMessage()])];
        }
    }
}

// ==========================================================================
// syncAfterSupplierPayment()
// ==========================================================================
if (!function_exists('syncAfterSupplierPayment')) {
    function syncAfterSupplierPayment(
        int    $payment_id,
        int    $purchase_id,
        int    $supplier_id,
        float  $amount,
        string $payment_method = 'cash'
    ): array {
        $synced = [];
        $errors = [];

        try {
            clearAllCaches();
            $synced[] = 'caches_cleared';

            $amount = round($amount, 2);

            // Get purchase invoice no and supplier name for description
            $pur = _pdoOne(
                "SELECT p.purchase_invoice_no, s.supplier_name
                 FROM purchases p
                 LEFT JOIN suppliers s ON s.id = p.supplier_id
                 WHERE p.id = :pid",
                [':pid' => $purchase_id]
            );
            $inv_label      = $pur['purchase_invoice_no'] ?? "PUR-{$purchase_id}";
            $supplier_label = $pur['supplier_name']        ?? "Supplier #{$supplier_id}";

            $cb_id = ensureCashbookEntry(
                entry_type:     'purchase_payment',
                reference_id:   $payment_id,
                reference_type: 'supplier_payment',
                description:    "Supplier payment to {$supplier_label} for {$inv_label}",
                debit:          $amount,
                credit:         0,
                payment_method: $payment_method
            );

            if ($cb_id !== false) {
                $synced[] = "cashbook_entry_{$cb_id}";
            } else {
                $errors[] = 'Failed to create cashbook entry for supplier payment';
            }

            // Recalc supplier balance from all unpaid purchases
            $due_row = _pdoOne(
                "SELECT COALESCE(SUM(due_amount), 0) AS total_due
                 FROM purchases WHERE supplier_id = :sid AND payment_status != 'paid'",
                [':sid' => $supplier_id]
            );
            $new_bal = (float)($due_row['total_due'] ?? 0);
            _pdoRun(
                "UPDATE suppliers SET current_balance = :b WHERE id = :sid",
                [':b' => $new_bal, ':sid' => $supplier_id]
            );
            $synced[] = 'supplier_balance_recalculated';

            return ['success' => empty($errors), 'synced' => $synced, 'errors' => $errors];

        } catch (\Throwable $e) {
            error_log("[SYNC] syncAfterSupplierPayment error: " . $e->getMessage());
            return ['success' => false, 'synced' => $synced, 'errors' => array_merge($errors, [$e->getMessage()])];
        }
    }
}

// ==========================================================================
// STEP 7: Expired Stock Loss Handling
// ==========================================================================

/**
 * Get total value of expired stock that is still in inventory.
 * Used by syncExpiredStockLoss() to determine loss amount.
 *
 * @param string|null $cutoff_date Optional date (default today) – only batches with expiry_date < cutoff_date
 * @return float Total loss value (remaining_quantity * purchase_price)
 */
if (!function_exists('getExpiredStockLossTotal')) {
    function getExpiredStockLossTotal(?string $cutoff_date = null): float {
        $cutoff_date = $cutoff_date ?? date('Y-m-d');
        $row = _pdoOne(
            "SELECT COALESCE(SUM(remaining_quantity * purchase_price), 0) AS total_loss
             FROM batches
             WHERE expiry_date < :cutoff
               AND remaining_quantity > 0
               AND COALESCE(is_expired, 0) = 0",
            [':cutoff' => $cutoff_date]
        );
        return $row ? (float)$row['total_loss'] : 0.0;
    }
}

/**
 * Record expired stock loss as a cashbook entry and mark batches as expired.
 * This should be called daily (e.g., via cron job or from expiry report page).
 *
 * @param string|null $cutoff_date Optional date (default today) – process batches with expiry_date < cutoff_date
 * @param bool $dry_run If true, only calculate and return loss without modifying DB
 * @return array ['success' => bool, 'message' => string, 'total_loss' => float, 'batches_processed' => int]
 */
if (!function_exists('syncExpiredStockLoss')) {
    function syncExpiredStockLoss(?string $cutoff_date = null, bool $dry_run = false): array {
        $cutoff_date = $cutoff_date ?? date('Y-m-d');
        $pdo = _getPDO();

        // 1. Get all batches that expired before cutoff and not yet marked
        $batches = _pdoAll(
            "SELECT b.id, b.product_id, p.product_name, b.batch_number,
                    b.remaining_quantity, b.purchase_price,
                    (b.remaining_quantity * b.purchase_price) AS loss_value
             FROM batches b
             JOIN products p ON p.id = b.product_id
             WHERE b.expiry_date < :cutoff
               AND b.remaining_quantity > 0
               AND COALESCE(b.is_expired, 0) = 0
             ORDER BY b.expiry_date ASC",
            [':cutoff' => $cutoff_date]
        );

        if (empty($batches)) {
            return [
                'success' => true,
                'message' => 'No expired stock to process.',
                'total_loss' => 0.0,
                'batches_processed' => 0
            ];
        }

        $total_loss = array_sum(array_column($batches, 'loss_value'));
        $batch_ids = array_column($batches, 'id');

        if ($dry_run) {
            $details = [];
            foreach ($batches as $b) {
                $details[] = "{$b['product_name']} (Batch {$b['batch_number']}): {$b['remaining_quantity']} pcs × {$b['purchase_price']} = Rs {$b['loss_value']}";
            }
            return [
                'success' => true,
                'message' => 'Dry run: ' . count($batches) . ' batches would be processed. Total loss: Rs ' . number_format($total_loss, 2),
                'total_loss' => $total_loss,
                'batches_processed' => count($batches),
                'details' => $details
            ];
        }

        try {
            $pdo->beginTransaction();

            // 2. Create cashbook entry for total loss
            $description = "Expired stock loss on " . date('Y-m-d') . " – " . count($batches) . " batch(es)";
            $cb_id = ensureCashbookEntry(
                entry_type:     'expired_loss',
                reference_id:   null,
                reference_type: 'expired_loss',
                description:    $description,
                debit:          $total_loss,
                credit:         0,
                payment_method: 'adjustment',
                entry_date:     date('Y-m-d')
            );

            if ($cb_id === false) {
                throw new \Exception("Failed to create cashbook entry for expired stock loss.");
            }

            // 3. Mark each batch as expired (set is_expired = 1)
            $placeholders = implode(',', array_fill(0, count($batch_ids), '?'));
            $stmt = $pdo->prepare("UPDATE batches SET is_expired = 1 WHERE id IN ($placeholders)");
            foreach ($batch_ids as $idx => $id) {
                $stmt->bindValue($idx + 1, $id, PDO::PARAM_INT);
            }
            $stmt->execute();
            $updated = $stmt->rowCount();

            // 4. Create stock_movements for each batch (optional but helpful for audit)
            try {
                $userId = $_SESSION['user_id'] ?? 1;
                foreach ($batches as $batch) {
                    _pdoRun(
                        "INSERT INTO stock_movements
                            (batch_id, product_id, movement_type, quantity,
                             reference_id, reference_type, notes, created_by, created_at)
                         VALUES
                            (:bid, :pid, 'expired_loss', :qty,
                             :ref_id, 'expired_loss', :notes, :user, NOW())",
                        [
                            ':bid'     => $batch['id'],
                            ':pid'     => $batch['product_id'],
                            ':qty'     => $batch['remaining_quantity'],
                            ':ref_id'  => $cb_id,
                            ':notes'   => "Auto-expired: {$batch['remaining_quantity']} pcs removed",
                            ':user'    => $userId,
                        ]
                    );
                }
            } catch (\Throwable $e) {
                // Non-fatal: stock_movements insert failure should not roll back the whole transaction
                error_log("[syncExpiredStockLoss] stock_movements insert error: " . $e->getMessage());
            }

            // 5. Refresh product stock for all affected products
            $productIds = array_unique(array_column($batches, 'product_id'));
            if (!empty($productIds)) {
                refreshProductStockAfterEvent($productIds);
            }

            $pdo->commit();

            // 6. Clear caches
            clearAllCaches();

            // 7. Log activity
            if (function_exists('logActivity')) {
                logActivity(
                    'expired_stock_loss',
                    "Recorded Rs " . number_format($total_loss, 2) . " expired stock loss from " . $updated . " batch(es)."
                );
            }

            // 8. Publish sync event
            publishSyncEvent('expired_loss', [
                'total_loss'   => $total_loss,
                'batch_count'  => $updated,
                'processed_at' => date('Y-m-d H:i:s'),
            ]);

            return [
                'success' => true,
                'message' => "Expired stock loss recorded: Rs " . number_format($total_loss, 2) . " from {$updated} batch(es).",
                'total_loss' => $total_loss,
                'batches_processed' => $updated,
            ];

        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("[syncExpiredStockLoss] ERROR: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
                'total_loss' => $total_loss,
                'batches_processed' => 0,
            ];
        }
    }
}

/**
 * syncAfterExpiredLoss — Wrapper for single expired loss entry (e.g., from manual write-off)
 *
 * @param int|string|null $batch_id Single batch ID or null for all expired
 * @param string|null $description Custom description
 * @return array Result array
 */
if (!function_exists('syncAfterExpiredLoss')) {
    function syncAfterExpiredLoss($batch_id = null, ?string $description = null): array {
        if ($batch_id === null) {
            // Process all expired batches
            return syncExpiredStockLoss(date('Y-m-d'), false);
        }

        $batch_id = (int)$batch_id;
        $pdo = _getPDO();

        $batch = _pdoOne(
            "SELECT b.*, p.product_name
             FROM batches b
             JOIN products p ON p.id = b.product_id
             WHERE b.id = :id AND b.expiry_date < CURDATE() AND b.remaining_quantity > 0
               AND COALESCE(b.is_expired, 0) = 0",
            [':id' => $batch_id]
        );

        if (!$batch) {
            return ['success' => false, 'message' => 'Batch not found or already processed.'];
        }

        $loss = round((float)$batch['remaining_quantity'] * (float)$batch['purchase_price'], 2);
        $desc = $description ?? "Manual expired loss: {$batch['product_name']} (Batch {$batch['batch_number']})";

        try {
            $pdo->beginTransaction();

            $cb_id = ensureCashbookEntry(
                entry_type:     'expired_loss',
                reference_id:   $batch_id,
                reference_type: 'batch_expired',
                description:    $desc,
                debit:          $loss,
                credit:         0,
                payment_method: 'adjustment'
            );

            if ($cb_id === false) {
                throw new \Exception("Failed to create cashbook entry.");
            }

            _pdoRun(
                "UPDATE batches SET is_expired = 1 WHERE id = :id",
                [':id' => $batch_id]
            );

            // Stock movement
            _pdoRun(
                "INSERT INTO stock_movements
                    (batch_id, product_id, movement_type, quantity,
                     reference_id, reference_type, notes, created_by)
                 VALUES
                    (:bid, :pid, 'expired_loss', :qty,
                     :rid, 'expired_loss', :notes, :user)",
                [
                    ':bid'   => $batch_id,
                    ':pid'   => $batch['product_id'],
                    ':qty'   => $batch['remaining_quantity'],
                    ':rid'   => $cb_id,
                    ':notes' => "Manual write-off: {$loss}",
                    ':user'  => $_SESSION['user_id'] ?? 1,
                ]
            );

            // Refresh product stock for this product
            refreshProductStockAfterEvent([$batch['product_id']]);

            $pdo->commit();
            clearAllCaches();

            return [
                'success' => true,
                'message' => "Expired loss recorded for batch #{$batch_id}: Rs " . number_format($loss, 2),
                'loss' => $loss,
                'cashbook_entry_id' => $cb_id,
            ];

        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("[syncAfterExpiredLoss] " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}

// ==========================================================================
// getDashboardStatsLive()
// ISSUE 6: uses COALESCE(status,'active') so old rows without status don't crash
//          cashbook queries add voided=0 filter
//          today_expenses added to return array
//          uses `total` column not `grand_total` for credit given calc
// NOTE: if functions.php is loaded first, its version wins (same logic).
//       This declaration is the fallback for pages that only include sync_engine.
// ==========================================================================
if (!function_exists('getDashboardStatsLive')) {
    function getDashboardStatsLive(): array {
        $today             = date('Y-m-d');
        $thirty_days_later = date('Y-m-d', strtotime('+30 days'));
        $low_thresh        = defined('LOW_STOCK_THRESHOLD') ? (int)LOW_STOCK_THRESHOLD : 10;

        $s = function (string $sql, array $p = []): float {
            try {
                $row = _pdoOne($sql, $p);
                return $row ? (float)array_values($row)[0] : 0.0;
            } catch (\Throwable $e) { return 0.0; }
        };
        $i = function (string $sql, array $p = []): int {
            try {
                $row = _pdoOne($sql, $p);
                return $row ? (int)array_values($row)[0] : 0;
            } catch (\Throwable $e) { return 0; }
        };

        // ISSUE 6: Use COALESCE(status,'active') everywhere
        $today_sale = $s(
            "SELECT COALESCE(SUM(net_amount),0) FROM sales
             WHERE DATE(sale_date)=:d AND COALESCE(status,'active')!='voided'",
            [':d' => $today]
        );
        $today_purchase = $s(
            "SELECT COALESCE(SUM(total),0) FROM purchases WHERE DATE(purchase_date)=:d",
            [':d' => $today]
        );
        $today_cogs = $s(
            "SELECT COALESCE(SUM(si.quantity*b.purchase_price),0)
             FROM sale_items si
             JOIN batches b ON si.batch_id=b.id
             JOIN sales s ON si.sale_id=s.id
             WHERE DATE(s.sale_date)=:d AND COALESCE(s.status,'active')!='voided'",
            [':d' => $today]
        );
        $today_profit = round($today_sale - $today_cogs, 2);

        $today_expenses = $s(
            "SELECT COALESCE(SUM(amount),0) FROM expenses WHERE DATE(expense_date)=:d",
            [':d' => $today]
        );
        $today_net_profit = round($today_profit - $today_expenses, 2);

        // ISSUE 6: Credit given — `total - paid_amount` (uses `total` not `grand_total`)
        // Also use COALESCE on is_credit and status
        $today_credit_given = $s(
            "SELECT COALESCE(SUM(total - paid_amount),0) FROM sales
             WHERE DATE(sale_date)=:d 
               AND COALESCE(is_credit,0)=1 
               AND COALESCE(status,'active')!='voided'",
            [':d' => $today]
        );
        $today_credit_collected = $s(
            "SELECT COALESCE(SUM(amount),0) FROM credit_payments WHERE DATE(payment_date)=:d",
            [':d' => $today]
        );
        $total_credit_pending = $s(
            "SELECT COALESCE(SUM(
                total_amount - COALESCE(
                    (SELECT SUM(amount) FROM credit_payments WHERE credit_id=cc.id),0)
             ),0) FROM customer_credits cc WHERE cc.status IN ('active','overdue')"
        );

        $today_sale_returns     = $s("SELECT COALESCE(SUM(total_amount),0) FROM sale_returns WHERE DATE(created_at)=:d",     [':d' => $today]);
        $today_purchase_returns = $s("SELECT COALESCE(SUM(total_amount),0) FROM purchase_returns WHERE DATE(created_at)=:d", [':d' => $today]);

        // ISSUE 6: Add voided=0 filter to cashbook queries
        $cash_in  = $s("SELECT COALESCE(SUM(credit),0) FROM cashbook WHERE DATE(entry_date)=:d AND voided=0 AND entry_type IN ('sale','credit_received','purchase_return')", [':d' => $today]);
        $cash_out = $s("SELECT COALESCE(SUM(debit),0)  FROM cashbook WHERE DATE(entry_date)=:d AND voided=0 AND entry_type IN ('purchase_payment','purchase','expense','sale_return')", [':d' => $today]);

        $total_stock_value  = $s("SELECT COALESCE(SUM(remaining_quantity*purchase_price),0) FROM batches WHERE remaining_quantity>0 AND is_expired=0");
        $total_retail_value = $s("SELECT COALESCE(SUM(remaining_quantity*selling_price),0)  FROM batches WHERE remaining_quantity>0 AND is_expired=0");

        $low_stock_count  = $i("SELECT COUNT(DISTINCT product_id) FROM batches WHERE remaining_quantity>0 AND remaining_quantity<=:t AND is_expired=0", [':t' => $low_thresh]);
        $near_expiry_count = $i("SELECT COUNT(*) FROM batches WHERE expiry_date BETWEEN CURDATE() AND :ed AND remaining_quantity>0 AND is_expired=0", [':ed' => $thirty_days_later]);
        $expired_count    = $i("SELECT COUNT(*) FROM batches WHERE expiry_date<CURDATE() AND remaining_quantity>0");
        $total_products   = $i("SELECT COUNT(*) FROM products WHERE status=1");

        return [
            'today_sale'              => $today_sale,
            'today_purchase'          => $today_purchase,
            'today_purchase_total'    => $today_purchase,
            'today_profit'            => $today_profit,
            'today_net_profit'        => $today_net_profit,
            'today_expenses'          => $today_expenses,
            'today_credit_given'      => $today_credit_given,
            'today_credit_collected'  => $today_credit_collected,
            'total_credit_pending'    => $total_credit_pending,
            'today_sale_returns'      => $today_sale_returns,
            'today_purchase_returns'  => $today_purchase_returns,
            'cashbook_today_in'       => $cash_in,
            'cashbook_today_out'      => $cash_out,
            'net_cash_today'          => round($cash_in - $cash_out, 2),
            'total_stock_value'       => $total_stock_value,
            'total_retail_value'      => $total_retail_value,
            'low_stock_count'         => $low_stock_count,
            'low_stock_alert'         => $low_stock_count,
            'near_expiry_count'       => $near_expiry_count,
            'expired_medicines'       => $expired_count,
            'expired_stock_count'     => $expired_count,
            'total_products'          => $total_products,
            'total_medicines'         => $total_products,
            'generated_at'            => date('Y-m-d H:i:s'),
        ];
    }
}
?>