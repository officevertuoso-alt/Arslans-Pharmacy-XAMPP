<?php
/**
 * Sale Management Functions with FIFO - Enhanced Version
 * 
 * FIXES APPLIED:
 *  1. getSaleById(): removed sr.returned_by join — column doesn't exist in sale_returns.
 *     Now joins on sr.created_by (falls back gracefully if that column also missing).
 *  2. Invoice qty display: sell_mode + units_per_pack stored in sale_items for print.
 *  3. processSale(): stores sell_mode and units_per_pack in sale_items row.
 *  4. [NEW] After successful sale, refreshes products.stock_quantity for all sold products.
 *  5. [NEW] After voiding a sale, refreshes products.stock_quantity for all affected products.
 *
 * NOTE: Sales table uses 'total' column, not 'grand_total'.
 */

// ==========================================================================
// CORE SALE FUNCTIONS
// ==========================================================================

/**
 * Refresh product stock (products.stock_quantity) for given product IDs.
 * If purchase_functions.php is loaded, uses updateProductStockAndPrice().
 * Otherwise falls back to direct SQL.
 *
 * @param array $productIds
 * @return void
 */
if (!function_exists('refreshProductStockAfterSale')) {
    function refreshProductStockAfterSale(array $productIds): void {
        if (empty($productIds)) return;
        $productIds = array_unique(array_map('intval', $productIds));
        if (function_exists('updateProductStockAndPrice')) {
            foreach ($productIds as $pid) {
                updateProductStockAndPrice($pid);
            }
        } else {
            // Fallback direct SQL
            $db = db();
            foreach ($productIds as $pid) {
                $db->run(
                    "UPDATE products p
                     SET p.stock_quantity = (
                         SELECT COALESCE(SUM(b.remaining_quantity), 0)
                         FROM batches b
                         WHERE b.product_id = p.id
                           AND b.is_expired = 0
                           AND b.remaining_quantity > 0
                     )
                     WHERE p.id = :pid",
                    [':pid' => $pid]
                );
            }
        }
    }
}

/**
 * Search products for POS (with variant-wise grouping)
 */
function searchProductsForPOS($search) {
    $db = db();
    $db->query("SELECT 
                    p.id,
                    p.product_code,
                    p.product_name,
                    p.generic_name,
                    p.brand_name,
                    p.strength,
                    p.dosage_form,
                    p.unit_type,
                    p.units_per_pack,
                    COALESCE(SUM(CASE WHEN b.expiry_date > CURDATE() AND b.is_expired = 0 THEN b.remaining_quantity ELSE 0 END), 0) as total_stock,
                    COALESCE((
                        SELECT b2.selling_price FROM batches b2
                        WHERE b2.product_id = p.id AND b2.remaining_quantity > 0
                          AND b2.is_expired = 0 AND (b2.expiry_date IS NULL OR b2.expiry_date > CURDATE())
                        ORDER BY b2.expiry_date ASC, b2.id ASC LIMIT 1
                    ), 0) as price
                FROM products p
                LEFT JOIN batches b ON p.id = b.product_id
                WHERE p.status = 1 
                AND (p.product_name LIKE :search 
                    OR p.product_code LIKE :search 
                    OR p.generic_name LIKE :search 
                    OR p.brand_name LIKE :search)
                GROUP BY p.id
                HAVING total_stock > 0
                ORDER BY p.product_name ASC
                LIMIT 50");
    $db->bind(':search', "%$search%");
    return $db->resultSet();
}

/**
 * Get available batches for a product (FIFO order - oldest expiry first)
 */
function getAvailableBatches($productId, $variantId = null) {
    $db = db();
    $sql = "SELECT 
                b.id as batch_id,
                b.batch_number,
                b.product_id,
                b.selling_price,
                b.remaining_quantity,
                b.expiry_date,
                p.product_name,
                p.generic_name,
                p.unit_type,
                p.units_per_pack
            FROM batches b
            JOIN products p ON b.product_id = p.id
            WHERE b.product_id = :product_id 
            AND b.remaining_quantity > 0 
            AND (b.expiry_date IS NULL OR b.expiry_date > CURDATE())
            AND b.is_expired = 0
            ORDER BY b.expiry_date ASC, b.id ASC";
    
    $db->query($sql);
    $db->bind(':product_id', $productId);
    return $db->resultSet();
}

/**
 * Get today's sales summary (enhanced with returns and credit data)
 * @return array
 */
function getTodaySalesSummary() {
    $db = db();
    $today = date('Y-m-d');
    
    $sales = $db->getOne(
        "SELECT 
            COUNT(*) as total_invoices,
            COALESCE(SUM(total), 0) as total_amount,
            COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN total ELSE 0 END), 0) as cash_amount,
            COALESCE(SUM(CASE WHEN payment_method = 'card' THEN total ELSE 0 END), 0) as card_amount,
            COALESCE(SUM(CASE WHEN payment_method = 'online' THEN total ELSE 0 END), 0) as online_amount,
            COALESCE(SUM(CASE WHEN payment_method = 'credit' AND is_credit = 1 THEN total - paid_amount ELSE 0 END), 0) as credit_amount,
            COUNT(CASE WHEN is_credit = 1 THEN 1 END) as credit_sale_count
        FROM sales
        WHERE DATE(sale_date) = :today AND status != 'voided'",
        [':today' => $today]
    );
    
    $returns = $db->getOne(
        "SELECT COALESCE(SUM(total_amount), 0) as total_returns_today
         FROM sale_returns
         WHERE DATE(created_at) = :today",
        [':today' => $today]
    );
    
    $creditCollected = $db->getOne(
        "SELECT COALESCE(SUM(amount), 0) as credit_collected_today
         FROM credit_payments
         WHERE DATE(payment_date) = :today",
        [':today' => $today]
    );
    
    return [
        'total_invoices'           => (int)($sales['total_invoices'] ?? 0),
        'total_amount'             => (float)($sales['total_amount'] ?? 0),
        'cash_amount'              => (float)($sales['cash_amount'] ?? 0),
        'card_amount'              => (float)($sales['card_amount'] ?? 0),
        'online_amount'            => (float)($sales['online_amount'] ?? 0),
        'credit_amount'            => (float)($sales['credit_amount'] ?? 0),
        'credit_sale_count'        => (int)($sales['credit_sale_count'] ?? 0),
        'total_returns_today'      => (float)($returns['total_returns_today'] ?? 0),
        'credit_collected_today'   => (float)($creditCollected['credit_collected_today'] ?? 0)
    ];
}

/**
 * Get all sales with filters, including returns data and net amounts
 */
function getAllSales($page = 1, $perPage = 20, $search = '', $fromDate = '', $toDate = '', $paymentMethod = '', $paymentStatus = '') {
    $db = db();
    $offset = ($page - 1) * $perPage;
    
    $where = "s.status != 'voided'";
    $params = [];
    
    if (!empty($search)) {
        $where .= " AND (s.invoice_no LIKE :search OR s.customer_name LIKE :search OR s.customer_phone LIKE :search)";
        $params[':search'] = "%$search%";
    }
    if (!empty($fromDate)) {
        $where .= " AND DATE(s.sale_date) >= :from_date";
        $params[':from_date'] = $fromDate;
    }
    if (!empty($toDate)) {
        $where .= " AND DATE(s.sale_date) <= :to_date";
        $params[':to_date'] = $toDate;
    }
    if (!empty($paymentMethod)) {
        $where .= " AND s.payment_method = :pm";
        $params[':pm'] = $paymentMethod;
    }
    if (!empty($paymentStatus)) {
        $where .= " AND s.payment_status = :ps";
        $params[':ps'] = $paymentStatus;
    }
    
    $sql = "SELECT 
                s.*,
                COALESCE((SELECT SUM(total_amount) FROM sale_returns WHERE sale_id = s.id), 0) as total_returned,
                (s.total - COALESCE((SELECT SUM(total_amount) FROM sale_returns WHERE sale_id = s.id), 0)) as net_after_returns,
                c.id as credit_id,
                c.total_amount as credit_total,
                c.status as credit_status
            FROM sales s
            LEFT JOIN customer_credits c ON c.sale_id = s.id
            WHERE $where
            ORDER BY s.sale_date DESC, s.id DESC
            LIMIT $offset, $perPage";
    
    $sales = $db->getAll($sql, $params);
    
    $countSql = "SELECT COUNT(*) as total FROM sales s WHERE $where";
    $totalRow = $db->getOne($countSql, $params);
    $total = $totalRow ? (int)$totalRow['total'] : 0;
    
    return [
        'data' => $sales,
        'total' => $total,
        'page' => $page,
        'perPage' => $perPage,
        'totalPages' => ceil($total / $perPage)
    ];
}

/**
 * Get sale by ID with full details: items, returns history, credit info
 *
 * FIX: Removed sr.returned_by — column does not exist in sale_returns table.
 *      Now uses sr.created_by with a safe LEFT JOIN fallback.
 *      Also fetches sell_mode and units_per_pack from sale_items for invoice display.
 */
function getSaleById($id) {
    $db = db();

    $sale = $db->getOne(
        "SELECT s.*, c.id as credit_id, c.total_amount as credit_total, c.status as credit_status,
                COALESCE((SELECT SUM(total_amount) FROM sale_returns WHERE sale_id = s.id), 0) as total_returned
        FROM sales s
        LEFT JOIN customer_credits c ON c.sale_id = s.id
        WHERE s.id = :id AND s.status != 'voided'",
        [':id' => $id]
    );

    if (!$sale) return null;

    // ── Fetch sale items — include sell_mode and units_per_pack if columns exist ──
    $items = $db->getAll(
        "SELECT
            si.*,
            b.batch_number,
            b.expiry_date       AS b_expiry_date,
            b.remaining_quantity AS batch_stock
        FROM sale_items si
        LEFT JOIN batches b ON si.batch_id = b.id
        WHERE si.sale_id = :sale_id
        ORDER BY si.id ASC",
        [':sale_id' => $id]
    );
    $sale['items'] = $items;

    // ── Fetch returns — safely handle missing created_by column ──
    try {
        $returns = $db->getAll(
            "SELECT sr.*, u.full_name as returned_by_name
            FROM sale_returns sr
            LEFT JOIN users u ON sr.created_by = u.id
            WHERE sr.sale_id = :sale_id
            ORDER BY sr.created_at DESC",
            [':sale_id' => $id]
        );
    } catch (\Throwable $e) {
        // Fallback: no user join at all
        try {
            $returns = $db->getAll(
                "SELECT sr.* FROM sale_returns sr WHERE sr.sale_id = :sale_id ORDER BY sr.created_at DESC",
                [':sale_id' => $id]
            );
            foreach ($returns as &$r) { $r['returned_by_name'] = '—'; }
            unset($r);
        } catch (\Throwable $e2) {
            $returns = [];
        }
    }
    $sale['returns'] = $returns;

    $sale['net_after_returns'] = (float)$sale['total'] - (float)($sale['total_returned'] ?? 0);

    return $sale;
}

/**
 * Process sale with FIFO stock deduction
 *
 * FIX: Passes qty_pieces for stock deduction; stores sell_mode + units_per_pack
 *      in sale_items so the print_invoice can show correct piece quantities.
 * NEW: Refreshes products.stock_quantity after successful commit.
 */
function processSale($cart, $customerData, $paymentData) {
    $db = db();
    $db->beginTransaction();
    
    try {
        $invoiceNo = generateInvoiceNo();
        $subtotal = 0;
        
        foreach ($cart as $item) {
            $subtotal += $item['total'];
        }
        
        $discountPercent = isset($paymentData['discount_percent']) ? (float)$paymentData['discount_percent'] : 0;
        $discountAmount = ($subtotal * $discountPercent) / 100;
        $taxPercent = isset($paymentData['tax_percent']) ? (float)$paymentData['tax_percent'] : 0;
        $afterDiscount = $subtotal - $discountAmount;
        $taxAmount = ($afterDiscount * $taxPercent) / 100;
        $total = $afterDiscount + $taxAmount;
        
        $isCredit = isset($customerData['is_credit']) && $customerData['is_credit'];
        $paidAmount = $isCredit ? 0 : $total;

        $db->query(
            "INSERT INTO sales (invoice_no, customer_name, customer_phone, sale_date,
             subtotal, discount, discount_percent, tax_amount, tax_percent, total, paid_amount,
             payment_method, payment_status, created_by, due_date, is_credit, net_amount)
             VALUES (:invoice_no, :customer_name, :customer_phone, NOW(), :subtotal,
             :discount, :discount_percent, :tax, :tax_percent, :total, :paid,
             :payment_method, :payment_status, :created_by, :due_date, :is_credit, :net)"
        );
        $db->bind(':invoice_no', $invoiceNo);
        $db->bind(':customer_name', $customerData['customer_name'] ?? 'Walk-in Customer');
        $db->bind(':customer_phone', $customerData['customer_phone'] ?? null);
        $db->bind(':subtotal', $subtotal);
        $db->bind(':discount', $discountAmount);
        $db->bind(':discount_percent', $discountPercent);
        $db->bind(':tax', $taxAmount);
        $db->bind(':tax_percent', $taxPercent);
        $db->bind(':total', $total);
        $db->bind(':paid', $paidAmount);
        $db->bind(':payment_method', $paymentData['payment_method'] ?? 'cash');
        $db->bind(':payment_status', $isCredit ? 'pending' : 'paid');
        $db->bind(':created_by', $_SESSION['user_id']);
        $db->bind(':due_date', $customerData['due_date'] ?? null);
        $db->bind(':is_credit', $isCredit ? 1 : 0);
        $db->bind(':net', $total);
        
        if (!$db->execute()) {
            throw new Exception("Failed to create sale record: " . $db->getError());
        }
        
        $saleId = $db->lastInsertId();
        
        $affectedProductIds = []; // collect product IDs for stock refresh
        
        foreach ($cart as $item) {
            $remainingQty = (int)($item['qty_pieces'] ?? $item['quantity']);
            $sellMode     = $item['sell_mode'] ?? 'piece';
            $unitsPerPack = max(1, (int)($item['units_per_pack'] ?? 1));
            
            $sql = "SELECT id, remaining_quantity, selling_price, expiry_date FROM batches 
                    WHERE product_id = :product_id 
                    AND remaining_quantity > 0 
                    AND (expiry_date IS NULL OR expiry_date >= CURDATE())
                    AND COALESCE(is_expired, 0) = 0
                    ORDER BY expiry_date ASC, id ASC FOR UPDATE";
            
            $db->query($sql);
            $db->bind(':product_id', (int)$item['product_id']);
            $batches = $db->resultSet();
            
            if (empty($batches)) {
                throw new Exception("No stock available for product: " . $item['product_name']);
            }
            
            foreach ($batches as $batch) {
                if ($remainingQty <= 0) break;
                
                $takeQty   = min($remainingQty, (int)$batch['remaining_quantity']);
                $lineTotal = $takeQty * (float)$batch['selling_price'];
                
                // Try to insert with sell_mode + units_per_pack cols; fallback without
                try {
                    $db->query(
                        "INSERT INTO sale_items
                            (sale_id, batch_id, product_id, product_name, generic_name, brand_name,
                             quantity, unit_price, total, expiry_date, sell_mode, units_per_pack)
                         VALUES
                            (:sale_id, :batch_id, :product_id, :product_name, :generic_name, :brand_name,
                             :quantity, :unit_price, :total, :expiry_date, :sell_mode, :units_per_pack)"
                    );
                    $db->bind(':sell_mode', $sellMode);
                    $db->bind(':units_per_pack', $unitsPerPack);
                } catch (\Throwable $e) {
                    $db->query(
                        "INSERT INTO sale_items
                            (sale_id, batch_id, product_id, product_name, generic_name, brand_name,
                             quantity, unit_price, total, expiry_date)
                         VALUES
                            (:sale_id, :batch_id, :product_id, :product_name, :generic_name, :brand_name,
                             :quantity, :unit_price, :total, :expiry_date)"
                    );
                }
                $db->bind(':sale_id',      $saleId);
                $db->bind(':batch_id',     (int)$batch['id']);
                $db->bind(':product_id',   (int)$item['product_id']);
                $db->bind(':product_name', $item['product_name']);
                $db->bind(':generic_name', $item['generic_name'] ?? null);
                $db->bind(':brand_name',   $item['brand_name']   ?? null);
                $db->bind(':quantity',     $takeQty);
                $db->bind(':unit_price',   (float)$batch['selling_price']);
                $db->bind(':total',        round($lineTotal, 2));
                $db->bind(':expiry_date',  $batch['expiry_date'] ?? null);
                $db->execute();
                
                // Deduct stock
                $newRemain = (int)$batch['remaining_quantity'] - $takeQty;
                $db->query("UPDATE batches SET remaining_quantity = :new WHERE id = :id AND remaining_quantity >= :take");
                $db->bind(':new', $newRemain);
                $db->bind(':id', (int)$batch['id']);
                $db->bind(':take', $takeQty);
                if (!$db->execute() || $db->rowCount() == 0) {
                    throw new Exception("Stock update failed for batch: " . $batch['id']);
                }
                
                // Stock movement log (swallow on missing columns)
                try {
                    $db->query(
                        "INSERT INTO stock_movements
                            (batch_id, movement_type, quantity, reference_id, reference_type, previous_quantity, new_quantity)
                         VALUES (:bid, 'sale', :qty, :rid, 'sale', :prev, :new)"
                    );
                    $db->bind(':bid',  (int)$batch['id']);
                    $db->bind(':qty',  $takeQty);
                    $db->bind(':rid',  $saleId);
                    $db->bind(':prev', (int)$batch['remaining_quantity']);
                    $db->bind(':new',  $newRemain);
                    $db->execute();
                } catch (\Throwable $e2) { /* swallow */ }
                
                $remainingQty -= $takeQty;
                
                // Collect product ID for stock refresh
                $affectedProductIds[] = (int)$item['product_id'];
            }
            
            if ($remainingQty > 0) {
                throw new Exception("Insufficient stock for product: " . $item['product_name']);
            }
        }
        
        if ($isCredit) {
            $creditAmount = $total - $paidAmount;
            $db->query(
                "INSERT INTO customer_credits (sale_id, customer_name, customer_phone, total_amount, due_date, status)
                 VALUES (:sid, :cname, :cphone, :amount, :due, 'active')"
            );
            $db->bind(':sid',    $saleId);
            $db->bind(':cname',  $customerData['customer_name']);
            $db->bind(':cphone', $customerData['customer_phone'] ?? '');
            $db->bind(':amount', $creditAmount);
            $db->bind(':due',    $customerData['due_date'] ?? date('Y-m-d', strtotime('+30 days')));
            $db->execute();
        }
        
        $db->commit();
        
        // ── After commit, refresh product stock for all affected products ──
        if (!empty($affectedProductIds)) {
            refreshProductStockAfterSale($affectedProductIds);
        }
        
        logActivity('Sale', 'Created sale invoice: ' . $invoiceNo);
        return $saleId;
        
    } catch (Exception $e) {
        $db->rollback();
        error_log("Sale Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Recalculate sale net amount after returns and update payment status
 */
function recalculateSaleNetAmount($sale_id) {
    $db = db();
    
    $sale = $db->getOne("SELECT total FROM sales WHERE id = :id", [':id' => $sale_id]);
    if (!$sale) return 0;
    
    $totalReturned = $db->getOne(
        "SELECT COALESCE(SUM(total_amount), 0) as total FROM sale_returns WHERE sale_id = :sid",
        [':sid' => $sale_id]
    );
    $returnAmount = (float)($totalReturned['total'] ?? 0);
    $newNet = round($sale['total'] - $returnAmount, 2);
    
    $db->run("UPDATE sales SET net_amount = :net WHERE id = :id", [':net' => $newNet, ':id' => $sale_id]);
    
    $saleData = $db->getOne("SELECT paid_amount FROM sales WHERE id = :id", [':id' => $sale_id]);
    $paid = (float)($saleData['paid_amount'] ?? 0);
    if ($newNet <= 0) {
        $status = 'returned';
    } elseif ($paid >= $newNet) {
        $status = 'paid';
    } elseif ($paid > 0) {
        $status = 'partial';
    } else {
        $status = 'pending';
    }
    $db->run("UPDATE sales SET payment_status = :status WHERE id = :id", [':status' => $status, ':id' => $sale_id]);
    
    return $newNet;
}

/**
 * Get payment summary for a sale
 */
function getSalePaymentSummary($sale_id) {
    $db = db();
    $sale = $db->getOne(
        "SELECT total, paid_amount, net_amount, is_credit, payment_status FROM sales WHERE id = :id",
        [':id' => $sale_id]
    );
    if (!$sale) return null;
    
    $totalReturned = $db->getOne(
        "SELECT COALESCE(SUM(total_amount), 0) as total FROM sale_returns WHERE sale_id = :sid",
        [':sid' => $sale_id]
    );
    $returnAmount = (float)($totalReturned['total'] ?? 0);
    
    $creditRemaining = 0;
    if ($sale['is_credit']) {
        $credit = $db->getOne(
            "SELECT total_amount, status FROM customer_credits WHERE sale_id = :sid",
            [':sid' => $sale_id]
        );
        $creditRemaining = $credit ? (float)$credit['total_amount'] : 0;
    }
    
    return [
        'total'            => (float)$sale['total'],
        'paid_amount'      => (float)$sale['paid_amount'],
        'net_amount'       => (float)$sale['net_amount'],
        'total_returned'   => $returnAmount,
        'is_credit'        => (bool)$sale['is_credit'],
        'payment_status'   => $sale['payment_status'],
        'credit_remaining' => $creditRemaining
    ];
}

/**
 * Get customer credit history by phone
 */
function getCustomerCreditHistory($phone) {
    $db = db();
    $credits = $db->getAll(
        "SELECT 
            c.id, c.sale_id, c.total_amount, c.due_date, c.status, c.description,
            s.invoice_no, s.sale_date, s.paid_amount,
            COALESCE((SELECT SUM(amount) FROM credit_payments WHERE credit_id = c.id), 0) as amount_paid
        FROM customer_credits c
        JOIN sales s ON c.sale_id = s.id
        WHERE c.customer_phone = :phone
        ORDER BY c.created_at DESC",
        [':phone' => $phone]
    );
    
    $grandTotal = 0;
    $totalPaid = 0;
    $totalRemaining = 0;
    foreach ($credits as &$credit) {
        $credit['remaining'] = $credit['total_amount'] - $credit['amount_paid'];
        $grandTotal += $credit['total_amount'];
        $totalPaid += $credit['amount_paid'];
        $totalRemaining += $credit['remaining'];
    }
    
    return [
        'credits' => $credits,
        'summary' => [
            'total_credit' => $grandTotal,
            'total_paid'   => $totalPaid,
            'total_due'    => $totalRemaining,
            'count'        => count($credits)
        ]
    ];
}

/**
 * Void a sale (restore stock, void cashbook, cancel credit)
 * NEW: After void, refreshes products.stock_quantity for all affected products.
 */
function voidSale($sale_id, $reason) {
    $db = db();
    $db->beginTransaction();
    
    try {
        $sale = $db->getOne("SELECT * FROM sales WHERE id = :id AND status != 'voided'", [':id' => $sale_id]);
        if (!$sale) {
            throw new Exception("Sale not found or already voided");
        }
        
        $items = $db->getAll("SELECT batch_id, quantity, product_id FROM sale_items WHERE sale_id = :sid", [':sid' => $sale_id]);
        $affectedProductIds = [];
        foreach ($items as $item) {
            $db->run("UPDATE batches SET remaining_quantity = remaining_quantity + :qty WHERE id = :bid",
                     [':qty' => $item['quantity'], ':bid' => $item['batch_id']]);
            try {
                $db->run(
                    "INSERT INTO stock_movements (batch_id, movement_type, quantity, reference_id, reference_type, notes) 
                     VALUES (:bid, 'adjustment_in', :qty, :rid, 'sale_void', :note)",
                    [':bid' => $item['batch_id'], ':qty' => $item['quantity'], ':rid' => $sale_id, ':note' => $reason]
                );
            } catch (\Throwable $e) { /* swallow if columns missing */ }
            $affectedProductIds[] = (int)$item['product_id'];
        }
        
        try {
            $db->run("UPDATE cashbook SET voided = 1, voided_reason = :reason 
                     WHERE reference_id = :rid AND reference_type = 'sale' AND voided = 0",
                     [':reason' => $reason, ':rid' => $sale_id]);
        } catch (\Throwable $e) { /* swallow */ }
        
        $db->run("UPDATE customer_credits SET status = 'cancelled', 
                     description = CONCAT(COALESCE(description, ''), ' - Voided: ', :reason) 
                 WHERE sale_id = :sid AND status IN ('active', 'overdue')",
                 [':reason' => $reason, ':sid' => $sale_id]);
        
        $db->run("UPDATE sales SET status = 'voided', voided_reason = :reason, voided_at = NOW() WHERE id = :id",
                 [':reason' => $reason, ':id' => $sale_id]);
        
        $db->commit();
        
        // ── After commit, refresh product stock for all affected products ──
        if (!empty($affectedProductIds)) {
            refreshProductStockAfterSale($affectedProductIds);
        }
        
        logActivity('Sale Void', "Voided sale {$sale['invoice_no']} - Reason: {$reason}");
        return true;
        
    } catch (Exception $e) {
        $db->rollback();
        error_log("Void Sale Error: " . $e->getMessage());
        return false;
    }
}

// ==========================================================================
// ADDITIONAL HELPER FUNCTIONS
// ==========================================================================

function getAllProductsForPOS() {
    $db = db();
    $sql = "SELECT 
                p.id,
                p.product_code,
                p.product_name,
                p.generic_name,
                p.brand_name,
                p.strength,
                p.dosage_form,
                p.unit_type,
                p.units_per_pack,
                COALESCE(SUM(CASE WHEN b.expiry_date > CURDATE() AND b.is_expired = 0 THEN b.remaining_quantity ELSE 0 END), 0) as total_stock,
                COALESCE((
                    SELECT b2.selling_price FROM batches b2
                    WHERE b2.product_id = p.id AND b2.remaining_quantity > 0
                      AND b2.is_expired = 0 AND (b2.expiry_date IS NULL OR b2.expiry_date > CURDATE())
                    ORDER BY b2.expiry_date ASC, b2.id ASC LIMIT 1
                ), 0) as price
            FROM products p
            LEFT JOIN batches b ON p.id = b.product_id
            WHERE p.status = 1
            GROUP BY p.id
            HAVING total_stock > 0
            ORDER BY p.product_name ASC
            LIMIT 500";
    
    $db->query($sql);
    return $db->resultSet();
}

// ==========================================================================
// DASHBOARD STATISTICS FUNCTIONS
// ==========================================================================

function getTotalStockValue() {
    if (!function_exists('db')) return 0;
    $db = db();
    $row = $db->getOne(
        "SELECT COALESCE(SUM(b.remaining_quantity * b.purchase_price), 0) AS total_value
         FROM batches b
         WHERE b.is_expired = 0 AND b.remaining_quantity > 0",
        []
    );
    return $row ? (float)$row['total_value'] : 0;
}

function getDashboardStats() {
    if (function_exists('getDashboardStatsLive')) {
        return getDashboardStatsLive();
    }
    if (!function_exists('db')) return [];
    $db  = db();
    $today = date('Y-m-d');
    $sale  = $db->getOne("SELECT COALESCE(SUM(net_amount),0) AS t FROM sales WHERE DATE(sale_date)=:d AND status!='voided'", [':d'=>$today]);
    $purch = $db->getOne("SELECT COALESCE(SUM(total),0) AS t FROM purchases WHERE DATE(purchase_date)=:d", [':d'=>$today]);
    $cred  = $db->getOne("SELECT COALESCE(SUM(amount),0) AS t FROM customer_credits WHERE status='active'", []);
    $low   = $db->getOne("SELECT COUNT(*) AS t FROM products p LEFT JOIN (SELECT product_id, SUM(remaining_quantity) AS qty FROM batches WHERE is_expired=0 GROUP BY product_id) b ON b.product_id=p.id WHERE COALESCE(b.qty,0) < p.reorder_level", []);
    $expiry= $db->getOne("SELECT COUNT(DISTINCT product_id) AS t FROM batches WHERE is_expired=0 AND remaining_quantity>0 AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)", []);
    return [
        'today_sale'          => (float)($sale['t']  ?? 0),
        'today_purchase_total'=> (float)($purch['t'] ?? 0),
        'total_credit_pending'=> (float)($cred['t']  ?? 0),
        'low_stock_count'     => (int)($low['t']     ?? 0),
        'near_expiry_count'   => (int)($expiry['t']  ?? 0),
        'today_credit_given'  => 0,
        'today_credit_collected'=> 0,
        'today_sale_returns'  => 0,
        'today_purchase_returns'=> 0,
        'net_cash_today'      => (float)($sale['t'] ?? 0),
        'today_expenses'      => 0,
        'cashbook_today_in'   => 0,
        'cashbook_today_out'  => 0,
    ];
}

function getNearExpiryProducts($limit = 10) {
    if (!function_exists('db')) return [];
    $db = db();
    $limit = (int)$limit;
    return $db->getAll(
        "SELECT p.id, p.product_name, p.generic_name, p.product_code,
                b.batch_number, b.expiry_date, b.remaining_quantity,
                DATEDIFF(b.expiry_date, CURDATE()) AS days_left
         FROM batches b
         JOIN products p ON p.id = b.product_id
         WHERE b.is_expired = 0
           AND b.remaining_quantity > 0
           AND b.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
         ORDER BY b.expiry_date ASC
         LIMIT $limit",
        []
    );
}

function getNearShortStockProducts($limit = 10) {
    if (!function_exists('db')) return [];
    $db = db();
    $limit = (int)$limit;
    return $db->getAll(
        "SELECT p.id, p.product_name, p.generic_name, p.product_code,
                p.reorder_level, COALESCE(SUM(b.remaining_quantity), 0) AS total_stock
         FROM products p
         LEFT JOIN batches b ON b.product_id = p.id AND b.is_expired = 0
         GROUP BY p.id
         HAVING total_stock < p.reorder_level
         ORDER BY total_stock ASC
         LIMIT $limit",
        []
    );
}

function getRecentSales($limit = 5) {
    if (!function_exists('db')) return [];
    $db    = db();
    $limit = (int)$limit;
    return $db->getAll(
        "SELECT s.id, s.invoice_number, s.sale_date,
                COALESCE(s.net_amount, s.total, 0) AS net_amount,
                s.payment_method, s.payment_status,
                COALESCE(s.customer_name, 'Walk-in') AS customer_name
         FROM sales s
         WHERE s.status != 'voided'
         ORDER BY s.sale_date DESC, s.id DESC
         LIMIT $limit",
        []
    );
}

function getRecentPurchases($limit = 5) {
    if (!function_exists('db')) return [];
    $db    = db();
    $limit = (int)$limit;
    return $db->getAll(
        "SELECT p.id, p.invoice_number, p.purchase_date,
                COALESCE(p.total, 0) AS total,
                p.payment_status,
                COALESCE(s.supplier_name, 'Unknown') AS supplier_name
         FROM purchases p
         LEFT JOIN suppliers s ON s.id = p.supplier_id
         ORDER BY p.purchase_date DESC, p.id DESC
         LIMIT $limit",
        []
    );
}

if (!function_exists('getTopSellingProducts')) {
function getTopSellingProducts($limit = 5) {
    if (!function_exists('db')) return [];
    $db    = db();
    $limit = (int)$limit;
    return $db->getAll(
        "SELECT p.id, p.product_name, p.generic_name,
                SUM(si.quantity) AS total_quantity_sold,
                SUM(si.quantity * si.unit_price) AS total_amount
         FROM sale_items si
         JOIN products p ON p.id = si.product_id
         JOIN sales s ON s.id = si.sale_id
         WHERE s.status != 'voided'
           AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
         GROUP BY p.id
         ORDER BY total_quantity_sold DESC
         LIMIT $limit",
        []
    );
}
} // end function_exists('getTopSellingProducts')

function getMonthlySalesData() {
    if (!function_exists('db')) return [];
    $db = db();
    return $db->getAll(
        "SELECT DATE_FORMAT(sale_date, '%b %Y') AS month_label,
                DATE_FORMAT(sale_date, '%Y-%m') AS month_key,
                COALESCE(SUM(net_amount), SUM(total), 0) AS total_sales,
                COUNT(*) AS sale_count
         FROM sales
         WHERE status != 'voided'
           AND sale_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
         GROUP BY month_key
         ORDER BY month_key ASC",
        []
    );
}

function getExpiredProductsList($limit = 5) {
    if (!function_exists('db')) return [];
    $db    = db();
    $limit = (int)$limit;
    return $db->getAll(
        "SELECT p.id, p.product_name, p.generic_name, p.product_code,
                b.batch_number, b.expiry_date, b.remaining_quantity,
                DATEDIFF(CURDATE(), b.expiry_date) AS days_expired
         FROM batches b
         JOIN products p ON p.id = b.product_id
         WHERE b.expiry_date < CURDATE()
           AND b.remaining_quantity > 0
         ORDER BY b.remaining_quantity DESC
         LIMIT $limit",
        []
    );
}

function getRecentActivities($limit = 10) {
    if (!function_exists('db')) return [];
    $db    = db();
    $limit = (int)$limit;
    try {
        return $db->getAll(
            "SELECT al.id, al.action, al.description, al.created_at,
                    COALESCE(u.full_name, u.username, 'System') AS user_name
             FROM activity_logs al
             LEFT JOIN users u ON u.id = al.user_id
             ORDER BY al.created_at DESC
             LIMIT $limit",
            []
        );
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Save sale from AJAX POS (compatibility wrapper)
 */
function saveSaleAndDeductStock(array $saleData): array {
    $cart = [];
    foreach ($saleData['items'] as $item) {
        $cart[] = [
            'product_id'    => $item['product_id'],
            'product_name'  => $item['product_name'],
            'quantity'      => $item['quantity'],
            'qty_pieces'    => $item['qty_pieces']    ?? $item['quantity'],
            'sell_mode'     => $item['sell_mode']     ?? 'piece',
            'units_per_pack'=> $item['units_per_pack']?? 1,
            'price'         => $item['price'],
            'total'         => $item['quantity'] * $item['price'],
            'generic_name'  => $item['generic_name']  ?? '',
            'brand_name'    => $item['brand_name']     ?? '',
        ];
    }
    
    $customerData = [
        'customer_name'  => $saleData['customer_name'],
        'customer_phone' => $saleData['customer_phone'],
        'is_credit'      => $saleData['is_credit'],
        'due_date'       => $saleData['due_date'] ?? null
    ];
    
    $paymentData = [
        'payment_method'   => $saleData['payment_method'],
        'discount_percent' => $saleData['overall_discount'] ?? 0,
        'tax_percent'      => $saleData['overall_tax'] ?? 0
    ];
    
    $saleId = processSale($cart, $customerData, $paymentData);
    if ($saleId) {
        return ['success' => true, 'sale_id' => $saleId];
    } else {
        return ['success' => false, 'message' => 'Sale processing failed'];
    }
}