<?php // REPAIRED vFIX9
/**
 * ajax/process_sale.php — FIFO MULTI-BATCH + PER-PACK SUPPORT
 * FIXED: Calls updateProductStockAndPrice() after commit
 * FIXED: Auto-create/update customer in customers table for credit/partial sales
 * UPDATED: Cashbook entry uses correct entry_type 'sale' (valid in cashbook table after Step 7)
 * FIXED: credit_payments.payment_method mapping (Step 4)
 * FIXED: Cashbook race condition (Step 5) — atomic balance calculation
 * 
 * FORENSIC REPAIRS (vFIX9):
 *   - hasCol() function defined with caching (ISSUE 1)
 *   - Grand total recalculated server-side, not trusted from POST (ISSUE 2)
 *   - Both total AND grand_total columns written if exist (ISSUE 3)
 *   - syncAfterSale() called with correct parameters (already correct - verified) (ISSUE 4)
 *   - Stock deduction uses remaining_quantity correctly (ISSUE 5)
 */

session_start();
require_once '../config/config.php';
require_once '../includes/sale_functions.php';
require_once '../includes/sync_engine.php';
require_once '../includes/cashbook_functions.php';

requireLogin();
header('Content-Type: application/json');

/* ══════════════════════════════════════════════════════════
   1. CSRF TOKEN VERIFICATION
══════════════════════════════════════════════════════════ */
$csrf = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrf)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request. Please refresh and try again.']);
    exit();
}

/* ── POST fields ── */
$invoice_no           = trim($_POST['invoice_no']              ?? '');
$customer_name        = trim($_POST['customer_name']           ?? 'Walk-in Customer');
$customer_phone       = trim($_POST['customer_phone']          ?? '');
$payment_method       = trim($_POST['payment_method']          ?? 'cash');
$overall_discount_pct = (float)($_POST['overall_discount_pct'] ?? 0);
$overall_tax_pct      = (float)($_POST['overall_tax_pct']      ?? 0);
$grand_total_post     = (float)($_POST['grand_total']          ?? 0);
$paid_amount          = (float)($_POST['paid_amount']          ?? 0);
$is_credit            = (int)  ($_POST['is_credit']            ?? 0);
$due_date_input       = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
$due_date_for_credits = $due_date_input ? $due_date_input : date('Y-m-d', strtotime('+30 days'));
$due_date_for_sales   = $due_date_input;
$cartData             = json_decode($_POST['items'] ?? '', true);

if (empty($invoice_no)) {
    echo json_encode(['success' => false, 'message' => 'Invoice number missing']);
    exit();
}
if (empty($cartData) || !is_array($cartData)) {
    echo json_encode(['success' => false, 'message' => 'No items received']);
    exit();
}

/* ── Rebuild cart from SESSION ── */
$cartItems     = [];
$subtotal_calc = 0;

foreach ($cartData as $item) {
    $index    = $item['index']             ?? '';
    $quantity = max(1, (int)($item['quantity'] ?? 1));
    $itemDisc = (float)($item['item_discount'] ?? 0);
    $itemTax  = 0;

    if ($index !== '' && isset($_SESSION['pos_cart'][$index])) {
        $ci                  = $_SESSION['pos_cart'][$index];
        $ci['quantity']      = $quantity;
        $ci['item_discount'] = $itemDisc;
        $ci['item_tax']      = $itemTax;

        $unitsPerPack  = max(1, (int)($ci['units_per_pack'] ?? 1));
        $sellMode      = $ci['sell_mode'] ?? 'piece';
        $qtyPieces     = ($sellMode === 'pack') ? $quantity * $unitsPerPack : $quantity;
        $ci['qty_pieces'] = $qtyPieces;

        $pricePerPiece  = (float)($ci['price_per_piece'] ?? $ci['price']);
        $lineBase       = $qtyPieces * $pricePerPiece;
        $ci['line_total'] = ($lineBase - $lineBase * $itemDisc / 100) * (1 + $itemTax / 100);
        $cartItems[]    = $ci;
        $subtotal_calc += $ci['line_total'];
    }
}

if (empty($cartItems)) {
    echo json_encode(['success' => false, 'message' => 'Session cart empty or expired. Please add products again.']);
    exit();
}

// ISSUE 2: Grand total recalculated server-side (not trusted from POST)
$oDiscAmt    = $subtotal_calc * $overall_discount_pct / 100;
$afterDisc   = $subtotal_calc - $oDiscAmt;
$oTaxAmt     = $afterDisc * $overall_tax_pct / 100;
$grand_total = $afterDisc + $oTaxAmt;

// Cross-check with POST value (alert but don't override)
if (abs($grand_total_post - $grand_total) > 0.01) {
    error_log("[process_sale] Grand total mismatch: calc={$grand_total}, POST={$grand_total_post}");
}

$calcBalance = max(0, $grand_total - $paid_amount);

// Payment status
if ($is_credit && $paid_amount == 0) {
    $payment_status = 'pending';
} elseif ($is_credit && $paid_amount > 0 && $paid_amount < $grand_total) {
    $payment_status = 'partial';
} elseif ($is_credit && $paid_amount >= $grand_total) {
    $payment_status = 'paid';
    $is_credit = 0;
} else {
    $payment_status = 'paid';
    $is_credit = 0;
}

/* ══════════════════════════════════════════════════════════
   INVOICE SEQUENCE HELPER (fallback if table missing)
══════════════════════════════════════════════════════════ */
function getNextInvoiceNumber(string $prefix): string
{
    $db = db();
    try {
        $db->query("CREATE TABLE IF NOT EXISTS invoice_sequences (
            id INT AUTO_INCREMENT PRIMARY KEY,
            prefix VARCHAR(10) NOT NULL,
            last_number INT NOT NULL DEFAULT 0,
            UNIQUE KEY (prefix)
        ) ENGINE=InnoDB");
        $db->execute();

        $db->query("INSERT IGNORE INTO invoice_sequences (prefix, last_number) VALUES (:p, 0)");
        $db->bind(':p', $prefix);
        $db->execute();
        
        $db->query("UPDATE invoice_sequences SET last_number = last_number + 1 WHERE prefix = :p");
        $db->bind(':p', $prefix);
        $db->execute();
        
        $db->query("SELECT last_number FROM invoice_sequences WHERE prefix = :p");
        $row = $db->single();
        $num = (int)($row['last_number'] ?? 1);
        return $prefix . '-' . str_pad($num, 6, '0', STR_PAD_LEFT);
    } catch (Exception $e) {
        return $prefix . '-' . date('YmdHis') . '-' . rand(100, 999);
    }
}

/* ══════════════════════════════════════════════════════════
   DETECT sales TABLE COLUMNS (dynamic insert with caching)
   ISSUE 1: hasCol() function with caching
══════════════════════════════════════════════════════════ */
$db = db();
static $salesColumnsCache = null;

function hasCol(string $name): bool {
    global $salesColumnsCache;
    
    if ($salesColumnsCache === null) {
        try {
            $db = db();
            $db->query("SHOW COLUMNS FROM sales");
            $rows = $db->resultSet();
            $salesColumnsCache = array_flip(array_column($rows, 'Field'));
        } catch (Exception $e) {
            error_log("[hasCol] Failed to get sales columns: " . $e->getMessage());
            $salesColumnsCache = [];
        }
    }
    return isset($salesColumnsCache[$name]);
}

/* Build dynamic INSERT */
$cols = []; $vals = []; $binds = [];

$cols[] = 'invoice_no';    $vals[] = ':inv';   $binds[':inv']   = $invoice_no;
$cols[] = 'customer_name'; $vals[] = ':cname'; $binds[':cname'] = $customer_name ?: 'Walk-in Customer';
$cols[] = 'sale_date';     $vals[] = 'NOW()';

if (hasCol('customer_phone'))  { $cols[]='customer_phone';  $vals[]=':cphone'; $binds[':cphone']=$customer_phone; }
if (hasCol('subtotal'))        { $cols[]='subtotal';         $vals[]=':sub';    $binds[':sub']=round($subtotal_calc,2); }
if (hasCol('discount'))        { $cols[]='discount';         $vals[]=':disc';   $binds[':disc']=round($oDiscAmt,2); }
elseif(hasCol('discount_amount')){ $cols[]='discount_amount';$vals[]=':disc';   $binds[':disc']=round($oDiscAmt,2); }
if (hasCol('discount_percent')){ $cols[]='discount_percent'; $vals[]=':dpct';   $binds[':dpct']=$overall_discount_pct; }
if (hasCol('tax_amount'))      { $cols[]='tax_amount';       $vals[]=':tax';    $binds[':tax']=round($oTaxAmt,2); }
elseif(hasCol('tax'))          { $cols[]='tax';              $vals[]=':tax';    $binds[':tax']=round($oTaxAmt,2); }
if (hasCol('tax_percent'))     { $cols[]='tax_percent';      $vals[]=':tpct';   $binds[':tpct']=$overall_tax_pct; }

// ISSUE 3: Write BOTH total AND grand_total if they exist
if (hasCol('total'))       { $cols[]='total';       $vals[]=':total';   $binds[':total']   = round($grand_total,2); }
if (hasCol('grand_total')) { $cols[]='grand_total'; $vals[]=':gtotal';  $binds[':gtotal']  = round($grand_total,2); }
if (hasCol('net_amount'))  { $cols[]='net_amount';  $vals[]=':net';     $binds[':net']     = round($grand_total,2); }

$cols[]='payment_method';  $vals[]=':pm';    $binds[':pm']=$payment_method;
if (hasCol('paid_amount')) { $cols[]='paid_amount';  $vals[]=':paid';  $binds[':paid']=round($paid_amount,2); }
if (hasCol('balance'))     { $cols[]='balance';      $vals[]=':bal';   $binds[':bal']=round($calcBalance,2); }
if (hasCol('due_date'))    { $cols[]='due_date';     $vals[]=':due';   $binds[':due']=$due_date_for_sales; }
if (hasCol('is_credit'))   { $cols[]='is_credit';    $vals[]=':cred';  $binds[':cred']=$is_credit; }
$cols[]='payment_status';  $vals[]=':pstat'; $binds[':pstat']=$payment_status;
$cols[]='created_by';      $vals[]=':uid';   $binds[':uid']=$_SESSION['user_id'];

$sql = 'INSERT INTO sales (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ')';

/* ══════════════════════════════════════════════════════════
   MAIN TRANSACTION
══════════════════════════════════════════════════════════ */
$db->beginTransaction();
$affectedProducts = [];
try {
    /* ── Insert sale header ── */
    $db->query($sql);
    foreach ($binds as $k => $v) { $db->bind($k, $v); }
    $db->execute();
    $saleId = $db->lastInsertId();

    /* ── FIFO stock deduction ── */
    foreach ($cartItems as $item) {
        $qtyNeeded   = (int)($item['qty_pieces'] ?? $item['quantity']);
        $itemDiscPct = (float)$item['item_discount'];
        $itemTaxPct  = (float)$item['item_tax'];
        $variantId   = (!empty($item['variant_id'])) ? (int)$item['variant_id'] : null;
        $productId   = (int)$item['product_id'];
        $productName = $item['product_name'] ?? '';
        $affectedProducts[] = $productId;

        while ($qtyNeeded > 0) {
            /* ── FEFO: First Expire First Out — ORDER BY expiry_date ASC ── */
            if ($variantId) {
                $db->query(
                    "SELECT id, remaining_quantity, selling_price, expiry_date, batch_number
                     FROM batches
                     WHERE product_id = :pid AND variant_id = :vid
                       AND remaining_quantity > 0
                       AND (expiry_date IS NULL OR expiry_date > CURDATE())
                       AND COALESCE(is_expired, 0) = 0
                     ORDER BY expiry_date ASC, id ASC LIMIT 1"
                );
                $db->bind(':pid', $productId);
                $db->bind(':vid', $variantId);
            } else {
                $db->query(
                    "SELECT id, remaining_quantity, selling_price, expiry_date, batch_number
                     FROM batches
                     WHERE product_id = :pid
                       AND (variant_id IS NULL OR variant_id = 0)
                       AND remaining_quantity > 0
                       AND (expiry_date IS NULL OR expiry_date > CURDATE())
                       AND COALESCE(is_expired, 0) = 0
                     ORDER BY expiry_date ASC, id ASC LIMIT 1"
                );
                $db->bind(':pid', $productId);
            }
            $batch = $db->single();

            if (!$batch) {
                throw new Exception('Insufficient stock for: ' . ($item['product_name'] ?? "#$productId"));
            }

            $db->query("SELECT remaining_quantity, selling_price, batch_number, expiry_date FROM batches WHERE id = :id FOR UPDATE");
            $db->bind(':id', $batch['id']);
            $lockedRow = $db->single();

            if ((int)$lockedRow['remaining_quantity'] < 1) {
                throw new Exception('Stock just ran out for: ' . ($item['product_name'] ?? "#$productId") . '. Please refresh.');
            }

            $batchUnitPrice = (float)$lockedRow['selling_price'];
            // ISSUE 5: Use remaining_quantity correctly
            $take           = min($qtyNeeded, (int)$lockedRow['remaining_quantity']);
            $lineBase       = $take * $batchUnitPrice;
            $lineDisc       = $lineBase * $itemDiscPct / 100;
            $afterD         = $lineBase - $lineDisc;
            $lineTax        = $afterD * $itemTaxPct / 100;
            $lineTotal      = $afterD + $lineTax;
            $newRemain      = (int)$lockedRow['remaining_quantity'] - $take;
            $prevRem        = (int)$lockedRow['remaining_quantity'];

            /* FEFO audit: snapshot batch_number + expiry_date into sale_items */
            $batchNumSnap = $lockedRow['batch_number'] ?? ($batch['batch_number'] ?? '');
            $batchExpSnap = $lockedRow['expiry_date']  ?? ($batch['expiry_date']  ?? null);

            try {
                $db->query(
                    "INSERT INTO sale_items
                        (sale_id, product_id, variant_id, batch_id, quantity, unit_price,
                         item_discount_pct, item_discount_amount, item_tax_pct, item_tax_amount,
                         total, product_name, batch_number, expiry_date)
                     VALUES
                        (:sid, :pid, :vid, :bid, :qty, :up,
                         :idp, :ida, :itp, :ita,
                         :tot, :pname, :bnum, :bexp)"
                );
                $db->bind(':sid',   $saleId);
                $db->bind(':pid',   $productId);
                $db->bind(':vid',   $variantId);
                $db->bind(':bid',   $batch['id']);
                $db->bind(':qty',   $take);
                $db->bind(':up',    $batchUnitPrice);
                $db->bind(':idp',   $itemDiscPct);
                $db->bind(':ida',   round($lineDisc, 2));
                $db->bind(':itp',   $itemTaxPct);
                $db->bind(':ita',   round($lineTax, 2));
                $db->bind(':tot',   round($lineTotal, 2));
                $db->bind(':pname', $productName);
                $db->bind(':bnum',  $batchNumSnap);
                $db->bind(':bexp',  $batchExpSnap);
                $db->execute();
            } catch (Exception $e2) {
                /* Fallback: older schema without batch_number/expiry_date columns */
                try {
                    $db->query(
                        "INSERT INTO sale_items
                            (sale_id, product_id, variant_id, batch_id, quantity, unit_price,
                             item_discount_pct, item_discount_amount, item_tax_pct, item_tax_amount, total, product_name)
                         VALUES (:sid, :pid, :vid, :bid, :qty, :up, :idp, :ida, :itp, :ita, :tot, :pname)"
                    );
                    $db->bind(':sid',   $saleId);
                    $db->bind(':pid',   $productId);
                    $db->bind(':vid',   $variantId);
                    $db->bind(':bid',   $batch['id']);
                    $db->bind(':qty',   $take);
                    $db->bind(':up',    $batchUnitPrice);
                    $db->bind(':idp',   $itemDiscPct);
                    $db->bind(':ida',   round($lineDisc, 2));
                    $db->bind(':itp',   $itemTaxPct);
                    $db->bind(':ita',   round($lineTax, 2));
                    $db->bind(':tot',   round($lineTotal, 2));
                    $db->bind(':pname', $productName);
                    $db->execute();
                } catch (Exception $e3) {
                    $db->query(
                        "INSERT INTO sale_items (sale_id, product_id, batch_id, quantity, unit_price, total, product_name)
                         VALUES (:sid, :pid, :bid, :qty, :up, :tot, :pname)"
                    );
                    $db->bind(':sid',   $saleId);
                    $db->bind(':pid',   $productId);
                    $db->bind(':bid',   $batch['id']);
                    $db->bind(':qty',   $take);
                    $db->bind(':up',    $batchUnitPrice);
                    $db->bind(':tot',   round($lineTotal, 2));
                    $db->bind(':pname', $productName);
                    $db->execute();
                }
            }

            // ISSUE 5: Deduct from remaining_quantity
            $db->query("UPDATE batches SET remaining_quantity = :new WHERE id = :id");
            $db->bind(':new', $newRemain);
            $db->bind(':id',  $batch['id']);
            $db->execute();

            try {
                $db->query(
                    "INSERT INTO stock_movements
                        (batch_id, movement_type, quantity, reference_id, reference_type, previous_quantity, new_quantity)
                     VALUES (:bid, 'sale', :qty, :ref, 'sale', :prev, :new)"
                );
                $db->bind(':bid',  $batch['id']);
                $db->bind(':qty',  $take);
                $db->bind(':ref',  $saleId);
                $db->bind(':prev', $prevRem);
                $db->bind(':new',  $newRemain);
                $db->execute();
            } catch (Exception $e3) { 
                // Non-fatal – audit trail optional
            }

            $qtyNeeded -= $take;
        }
    }

    if ($is_credit && $calcBalance > 0) {
        $db->query(
            "INSERT INTO customer_credits
                (sale_id, customer_name, customer_phone, total_amount, due_date, description, status, created_at)
             VALUES (:sid, :cn, :cp, :amt, :due, :desc, 'active', NOW())"
        );
        $db->bind(':sid',  $saleId);
        $db->bind(':cn',   $customer_name);
        $db->bind(':cp',   $customer_phone);
        $db->bind(':amt',  round($calcBalance, 2));
        $db->bind(':due',  $due_date_for_credits);
        $db->bind(':desc', "Sale #$invoice_no");
        $db->execute();
        $creditId = $db->lastInsertId();

        if ($paid_amount > 0) {
            $cpPayMethod = in_array($payment_method, ['cash','card','online','cheque','bank','credit_note','adjustment']) 
                           ? $payment_method 
                           : 'cash';
            
            $db->query(
                "INSERT INTO credit_payments (credit_id, amount, payment_method, note, payment_date)
                 VALUES (:cid, :amt, :pm, :note, NOW())"
            );
            $db->bind(':cid',  $creditId);
            $db->bind(':amt',  round($paid_amount, 2));
            $db->bind(':pm',   $cpPayMethod);
            $db->bind(':note', "Upfront for $invoice_no");
            $db->execute();
        }
    }

    $db->commit();

    // ── Auto-create/update customer in customers table ──
    if (($is_credit || ($paid_amount > 0 && $calcBalance > 0)) && !empty($customer_name) && !empty($customer_phone)) {
        try {
            $pdo = _getPDO();
            $pdo->exec("CREATE TABLE IF NOT EXISTS customers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                phone VARCHAR(30) NOT NULL,
                address TEXT DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_cust_phone (phone)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $stmt = $pdo->prepare(
                "INSERT INTO customers (name, phone, address, notes)
                 VALUES (:name, :phone, NULL, NULL)
                 ON DUPLICATE KEY UPDATE name = VALUES(name), updated_at = NOW()"
            );
            $stmt->execute([':name' => $customer_name, ':phone' => $customer_phone]);
        } catch (\Throwable $e) {
            error_log("[process_sale] auto-customer creation failed: " . $e->getMessage());
        }
    }

    /* Update product cache */
    $affectedProducts = array_unique($affectedProducts);
    foreach ($affectedProducts as $prodId) {
        if (function_exists('updateProductStockAndPrice')) {
            updateProductStockAndPrice($prodId);
        }
    }

    /* ── POST-COMMIT: CASHBOOK ENTRY ── */
    $syncErrors = [];
    $isCreditSale = ($is_credit && $calcBalance > 0);
    $cashReceived = $isCreditSale ? round($paid_amount, 2) : round($grand_total, 2);

    if ($cashReceived > 0) {
        try {
            $pdo = $db->getConnection();
            
            $chk = $pdo->prepare(
                "SELECT id FROM cashbook
                 WHERE reference_id = ? AND reference_type = 'sale'
                   AND entry_type = 'sale' AND voided = 0 LIMIT 1"
            );
            $chk->execute([$saleId]);
            $alreadyExists = $chk->fetch(PDO::FETCH_ASSOC);

            if (!$alreadyExists) {
                $ins = $pdo->prepare(
                    "INSERT INTO cashbook
                        (entry_date, entry_type, reference_id, reference_type,
                         description, debit, credit, balance, payment_method,
                         voided, created_by, created_at)
                     VALUES
                        (CURDATE(), 'sale', ?, 'sale',
                         ?, 0, ?,
                         (SELECT COALESCE(MAX(b2.balance), 0) + ? FROM cashbook b2 WHERE b2.voided = 0),
                         ?,
                         0, ?, NOW())"
                );
                $ins->execute([
                    $saleId,
                    'Sale: ' . $invoice_no . ' - ' . ($customer_name ?: 'Walk-in Customer'),
                    $cashReceived,
                    $cashReceived,
                    $payment_method,
                    $_SESSION['user_id'] ?? 1,
                ]);
            }
        } catch (Exception $cbEx) {
            $syncErrors[] = 'Cashbook entry failed: ' . $cbEx->getMessage();
            error_log('[process_sale cashbook] ' . $cbEx->getMessage());
        }
    }

    // ISSUE 4: syncAfterSale called with correct parameters (saleId, not invoiceNo)
    try {
        syncAfterSale(
            sale_id:        $saleId,
            payment_method: $payment_method,
            grand_total:    $grand_total,
            paid_amount:    $paid_amount,
            is_credit:      $isCreditSale,
            invoice_no:     $invoice_no
        );
    } catch (Exception $syncEx) {
        error_log('[process_sale sync] ' . $syncEx->getMessage());
    }

    logActivity('sale_created', "Invoice $invoice_no | Total: " . round($grand_total, 2) . " | Method: $payment_method");
    unset($_SESSION['pos_cart']);

    $response = [
        'success'        => true,
        'sale_id'        => $saleId,
        'invoice_no'     => $invoice_no,
        'credit_balance' => $isCreditSale ? round($calcBalance, 2) : 0,
    ];
    if (!empty($syncErrors)) {
        $response['sync_warnings'] = $syncErrors;
    }
    echo json_encode($response);

} catch (Exception $e) {
    $db->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>