<?php
/**
 * Export Reports to Excel (CSV format)
 * Phase 22 – Supports: Sales, Stock, Purchase, Profit/Loss, Expired Products, Cashbook
 * Stock Report exports only in-stock (non-expired) products
 * Expired Report supports 'all' (default) or 'today' filter
 */
require_once '../config/config.php';
require_once '../includes/report_functions.php';
require_once '../includes/sync_engine.php';
require_once '../includes/cashbook_functions.php';

requireLogin();

if (!canDo('reports', 'export')) {
    http_response_code(403);
    exit('Access denied. Export permission required.');
}
// Get report type
$report_type = $_GET['report'] ?? 'sales';
$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date = $_GET['to_date'] ?? date('Y-m-d');
$search = $_GET['search'] ?? '';
$category = $_GET['category'] ?? '';
$payment_method = $_GET['payment_method'] ?? '';
$payment_status = $_GET['payment_status'] ?? '';
$supplier_id = $_GET['supplier_id'] ?? '';
$filter = $_GET['filter'] ?? 'all'; // For expired report: 'all' or 'today'

// Set filename
$filename = $report_type . '_report_' . date('Y-m-d_H-i-s');

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '.csv"');

// Create output stream
$output = fopen('php://output', 'w');

// Add UTF-8 BOM for Excel compatibility
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Generate report based on type
switch ($report_type) {
    case 'sales':
        exportSalesReport($output, $from_date, $to_date, $payment_method, $payment_status);
        break;
    case 'stock':
        exportStockReport($output, $search, $category);
        break;
    case 'purchase':
        exportPurchaseReport($output, $from_date, $to_date, $supplier_id);
        break;
    case 'profit_loss':
        exportProfitLossReport($output, $from_date, $to_date);
        break;
    case 'expired':
        exportExpiredReport($output, $search, $filter);
        break;
    case 'cashbook':
        exportCashbookReport($output, $from_date, $to_date);
        break;
    default:
        exportSalesReport($output, $from_date, $to_date, $payment_method, $payment_status);
        break;
}

fclose($output);
exit();

/**
 * Export Sales Report to CSV
 */
function exportSalesReport($output, $from_date, $to_date, $payment_method, $payment_status) {
    $db = db();
    
    $where = "DATE(s.sale_date) BETWEEN :from AND :to AND s.status != 'voided'";
    $params = [':from' => $from_date, ':to' => $to_date];
    
    if ($payment_method) {
        $where .= " AND s.payment_method = :pm";
        $params[':pm'] = $payment_method;
    }
    if ($payment_status) {
        $where .= " AND s.payment_status = :ps";
        $params[':ps'] = $payment_status;
    }
    
    $sales = $db->getAll(
        "SELECT s.id, s.invoice_no, s.customer_name, s.customer_phone, s.sale_date,
                s.total, s.paid_amount, s.payment_method, s.payment_status, s.is_credit,
                COALESCE(SUM(sr.total_amount), 0) AS total_returned,
                (s.total - COALESCE(SUM(sr.total_amount), 0)) AS net_amount,
                cc.total_amount AS credit_original
         FROM sales s
         LEFT JOIN sale_returns sr ON sr.sale_id = s.id
         LEFT JOIN customer_credits cc ON cc.sale_id = s.id
         WHERE $where
         GROUP BY s.id
         ORDER BY s.sale_date DESC, s.id DESC",
        $params
    );
    
    // Write headers
    fputcsv($output, [
        'Invoice #',
        'Date',
        'Customer Name',
        'Customer Phone',
        'Gross Amount (Rs)',
        'Returns (Rs)',
        'Net Amount (Rs)',
        'Paid Amount (Rs)',
        'Payment Method',
        'Payment Status',
        'Credit Sale',
        'Credit Amount (Rs)',
        'Credit Remaining (Rs)'
    ]);
    
    $totalGross = 0;
    $totalReturns = 0;
    $totalNet = 0;
    $totalPaid = 0;
    $totalCreditRemaining = 0;
    
    foreach ($sales as $sale) {
        $credit_remaining = 0;
        if ($sale['is_credit'] && $sale['credit_original'] > 0) {
            $credit_paid = $db->getOne(
                "SELECT COALESCE(SUM(amount), 0) as paid FROM credit_payments WHERE credit_id = :cid",
                [':cid' => $sale['id']]
            );
            $credit_remaining = $sale['credit_original'] - ($credit_paid['paid'] ?? 0);
            if ($credit_remaining < 0) $credit_remaining = 0;
        }
        
        $totalGross += $sale['total'];
        $totalReturns += $sale['total_returned'];
        $totalNet += $sale['net_amount'];
        $totalPaid += $sale['paid_amount'];
        $totalCreditRemaining += $credit_remaining;
        
        fputcsv($output, [
            $sale['invoice_no'],
            date('d-m-Y H:i:s', strtotime($sale['sale_date'])),
            $sale['customer_name'] ?? 'Walk-in Customer',
            $sale['customer_phone'] ?? '',
            number_format($sale['total'], 2),
            number_format($sale['total_returned'], 2),
            number_format($sale['net_amount'], 2),
            number_format($sale['paid_amount'], 2),
            ucfirst($sale['payment_method']),
            ucfirst($sale['payment_status']),
            $sale['is_credit'] ? 'Yes' : 'No',
            $sale['is_credit'] ? number_format($sale['credit_original'], 2) : '0.00',
            number_format($credit_remaining, 2)
        ]);
    }
    
    fputcsv($output, []);
    fputcsv($output, ['SUMMARY', '', '', '', '', '', '', '', '', '', '', '', '']);
    fputcsv($output, ['Total Gross Sales:', number_format($totalGross, 2)]);
    fputcsv($output, ['Total Returns:', number_format($totalReturns, 2)]);
    fputcsv($output, ['Total Net Sales:', number_format($totalNet, 2)]);
    fputcsv($output, ['Total Received:', number_format($totalPaid, 2)]);
    fputcsv($output, ['Total Credit Remaining:', number_format($totalCreditRemaining, 2)]);
    fputcsv($output, ['Period:', date('d-m-Y', strtotime($from_date)) . ' to ' . date('d-m-Y', strtotime($to_date))]);
    fputcsv($output, ['Generated On:', date('d-m-Y H:i:s')]);
}

/**
 * Export Stock Report to CSV - ONLY IN-STOCK (NON-EXPIRED) PRODUCTS
 */
function exportStockReport($output, $search, $category) {
    $db = db();
    
    $sql = "SELECT 
                p.id,
                p.product_code,
                p.product_name,
                p.generic_name,
                p.brand_name,
                p.category_id,
                p.strength,
                p.strength_unit,
                p.dosage_form,
                p.size_value,
                p.unit_type,
                p.reorder_level,
                COALESCE(SUM(CASE WHEN b.expiry_date > CURDATE() AND b.is_expired = 0 AND b.is_blocked = 0 
                           THEN b.remaining_quantity ELSE 0 END), 0) as current_stock,
                COALESCE(MIN(b.purchase_price), 0) as purchase_price,
                COALESCE(MIN(b.selling_price), 0) as selling_price,
                COUNT(DISTINCT b.id) as batch_count
            FROM products p
            LEFT JOIN batches b ON p.id = b.product_id
            WHERE p.status = 1";
    
    $params = [];
    
    if (!empty($search)) {
        $sql .= " AND (p.product_name LIKE :search OR p.generic_name LIKE :search OR p.brand_name LIKE :search OR p.product_code LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    if ($category == 'medicine') {
        $sql .= " AND p.category_id = 1";
    } elseif ($category == 'non_medicine') {
        $sql .= " AND p.category_id = 2";
    }
    
    $sql .= " GROUP BY p.id
              HAVING current_stock > 0
              ORDER BY p.product_name ASC";
    
    $products = $db->getAll($sql, $params);
    
    fputcsv($output, [
        'S.No',
        'Product Code',
        'Product Name',
        'Brand Name',
        'Generic Name',
        'Category',
        'Strength / Size',
        'Quantity (Pieces)',
        'Purchase Price (Rs)',
        'Stock Value (Rs)'
    ]);
    
    $serial = 1;
    $totalQuantity = 0;
    $totalValue = 0;
    
    foreach ($products as $product) {
        $currentStock = isset($product['current_stock']) ? (int)$product['current_stock'] : 0;
        $purchasePrice = isset($product['purchase_price']) ? (float)$product['purchase_price'] : 0;
        $stockValue = $currentStock * $purchasePrice;
        
        $totalQuantity += $currentStock;
        $totalValue += $stockValue;
        
        $strengthSize = '';
        if ($product['category_id'] == 1) {
            if (!empty($product['strength'])) {
                $strengthSize .= $product['strength'];
                if (!empty($product['strength_unit'])) {
                    $strengthSize .= ' ' . $product['strength_unit'];
                }
            }
            if (!empty($product['dosage_form'])) {
                $strengthSize .= ' (' . $product['dosage_form'] . ')';
            }
        } else {
            if (!empty($product['strength'])) {
                $strengthSize .= $product['strength'];
                if (!empty($product['strength_unit'])) {
                    $strengthSize .= ' ' . $product['strength_unit'];
                }
            }
            if (!empty($product['size_value'])) {
                $strengthSize .= ' ' . $product['size_value'];
            }
        }
        
        $categoryName = ($product['category_id'] == 1) ? 'Medicine' : 'Non-Medicine';
        
        fputcsv($output, [
            $serial++,
            $product['product_code'] ?? '-',
            $product['product_name'],
            $product['brand_name'] ?? '',
            ($product['category_id'] == 1) ? ($product['generic_name'] ?? '') : '',
            $categoryName,
            $strengthSize ?: '-',
            $currentStock,
            number_format($purchasePrice, 2),
            number_format($stockValue, 2)
        ]);
    }
    
    fputcsv($output, []);
    fputcsv($output, ['SUMMARY', '', '', '', '', '', '', '', '', '']);
    fputcsv($output, ['Total Products (In Stock):', $serial - 1]);
    fputcsv($output, ['Total Quantity (Pieces):', $totalQuantity]);
    fputcsv($output, ['Total Stock Value:', 'Rs ' . number_format($totalValue, 2)]);
    
    if ($search) fputcsv($output, ['Filter Applied:', $search]);
    if ($category == 'medicine') fputcsv($output, ['Category Filter:', 'Medicine']);
    elseif ($category == 'non_medicine') fputcsv($output, ['Category Filter:', 'Non-Medicine']);
    
    fputcsv($output, ['Generated On:', date('d-m-Y H:i:s')]);
}

/**
 * Export Purchase Report to CSV
 */
function exportPurchaseReport($output, $from_date, $to_date, $supplier_id) {
    $db = db();
    
    $where = "DATE(p.purchase_date) BETWEEN :from AND :to";
    $params = [':from' => $from_date, ':to' => $to_date];
    
    if ($supplier_id) {
        $where .= " AND p.supplier_id = :sid";
        $params[':sid'] = $supplier_id;
    }
    
    $purchases = $db->getAll(
        "SELECT p.purchase_invoice_no, p.purchase_date, p.total, p.paid_amount, p.due_amount,
                p.payment_status, s.supplier_name,
                (SELECT COUNT(*) FROM purchase_items WHERE purchase_id = p.id) as items_count
         FROM purchases p
         JOIN suppliers s ON p.supplier_id = s.id
         WHERE $where
         ORDER BY p.purchase_date DESC",
        $params
    );
    
    fputcsv($output, [
        'S.No',
        'Invoice #',
        'Date',
        'Supplier Name',
        'Total Amount (Rs)',
        'Paid Amount (Rs)',
        'Due Amount (Rs)',
        'Payment Status',
        'Items Count'
    ]);
    
    $serial = 1;
    $totalAmount = 0;
    $totalPaid = 0;
    $totalDue = 0;
    
    foreach ($purchases as $purchase) {
        $totalAmount += $purchase['total'];
        $totalPaid += $purchase['paid_amount'];
        $totalDue += $purchase['due_amount'];
        
        fputcsv($output, [
            $serial++,
            $purchase['purchase_invoice_no'],
            date('d-m-Y', strtotime($purchase['purchase_date'])),
            $purchase['supplier_name'],
            number_format($purchase['total'], 2),
            number_format($purchase['paid_amount'], 2),
            number_format($purchase['due_amount'], 2),
            ucfirst($purchase['payment_status']),
            $purchase['items_count']
        ]);
    }
    
    fputcsv($output, []);
    fputcsv($output, ['SUMMARY', '', '', '', '', '', '', '', '']);
    fputcsv($output, ['Total Purchases:', number_format($totalAmount, 2)]);
    fputcsv($output, ['Total Paid:', number_format($totalPaid, 2)]);
    fputcsv($output, ['Total Due:', number_format($totalDue, 2)]);
    fputcsv($output, ['Total Invoices:', $serial - 1]);
    fputcsv($output, ['Period:', date('d-m-Y', strtotime($from_date)) . ' to ' . date('d-m-Y', strtotime($to_date))]);
    fputcsv($output, ['Generated On:', date('d-m-Y H:i:s')]);
}

/**
 * Export Profit/Loss Report to CSV
 */
function exportProfitLossReport($output, $from_date, $to_date) {
    $db = db();
    
    $salesTotal = $db->getOne(
        "SELECT COALESCE(SUM(total), 0) as total FROM sales 
         WHERE DATE(sale_date) BETWEEN :from AND :to AND status != 'voided'",
        [':from' => $from_date, ':to' => $to_date]
    );
    
    $cogs = $db->getOne(
        "SELECT COALESCE(SUM(si.quantity * b.purchase_price), 0) as total
         FROM sale_items si
         JOIN sales s ON si.sale_id = s.id
         JOIN batches b ON si.batch_id = b.id
         WHERE DATE(s.sale_date) BETWEEN :from AND :to AND s.status != 'voided'",
        [':from' => $from_date, ':to' => $to_date]
    );
    
    $totalSales = $salesTotal['total'] ?? 0;
    $totalCost = $cogs['total'] ?? 0;
    $grossProfit = $totalSales - $totalCost;
    $profitMargin = $totalSales > 0 ? ($grossProfit / $totalSales) * 100 : 0;
    
    $dailySales = $db->getAll(
        "SELECT DATE(sale_date) as sale_date, COUNT(*) as invoices, SUM(total) as amount
         FROM sales 
         WHERE DATE(sale_date) BETWEEN :from AND :to AND status != 'voided'
         GROUP BY DATE(sale_date)
         ORDER BY sale_date ASC",
        [':from' => $from_date, ':to' => $to_date]
    );
    
    fputcsv($output, ['PROFIT & LOSS REPORT']);
    fputcsv($output, ['Period:', date('d-m-Y', strtotime($from_date)) . ' to ' . date('d-m-Y', strtotime($to_date))]);
    fputcsv($output, []);
    fputcsv($output, ['SUMMARY']);
    fputcsv($output, ['Total Sales Revenue:', number_format($totalSales, 2)]);
    fputcsv($output, ['Cost of Goods Sold (COGS):', number_format($totalCost, 2)]);
    fputcsv($output, ['Gross Profit:', number_format($grossProfit, 2)]);
    fputcsv($output, ['Profit Margin (%):', number_format($profitMargin, 2) . '%']);
    fputcsv($output, []);
    
    fputcsv($output, ['DAILY SALES BREAKDOWN']);
    fputcsv($output, ['Date', 'Invoices', 'Amount (Rs)']);
    
    foreach ($dailySales as $day) {
        fputcsv($output, [
            date('d-m-Y', strtotime($day['sale_date'])),
            $day['invoices'],
            number_format($day['amount'], 2)
        ]);
    }
    
    fputcsv($output, []);
    fputcsv($output, ['Generated On:', date('d-m-Y H:i:s')]);
}

/**
 * Export Expired Products Report to CSV
 * Supports filter: 'all' (default, all expired) or 'today' (expired exactly today)
 */
function exportExpiredReport($output, $search, $filter = 'all') {
    $db = db();
    
    $sql = "SELECT 
                b.batch_number,
                b.remaining_quantity,
                b.expiry_date,
                b.purchase_price,
                p.product_name,
                p.generic_name,
                p.brand_name,
                p.category_id,
                p.strength,
                p.strength_unit,
                p.dosage_form,
                p.size_value,
                s.supplier_name,
                DATEDIFF(CURDATE(), b.expiry_date) as days_expired
            FROM batches b
            JOIN products p ON b.product_id = p.id
            LEFT JOIN suppliers s ON b.supplier_id = s.id
            WHERE b.remaining_quantity > 0";
    
    if ($filter == 'today') {
        $sql .= " AND DATE(b.expiry_date) = CURDATE()";
    } else {
        $sql .= " AND b.expiry_date < CURDATE()";
    }
    
    $params = [];
    if (!empty($search)) {
        $sql .= " AND (p.product_name LIKE :search 
                 OR p.generic_name LIKE :search 
                 OR p.brand_name LIKE :search 
                 OR b.batch_number LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    $sql .= " ORDER BY b.expiry_date ASC";
    
    $products = $db->getAll($sql, $params);
    
    fputcsv($output, [
        'S.No',
        'Batch Number',
        'Supplier',
        'Product Name',
        'Brand Name',
        'Generic Name',
        'Strength / Size',
        'Quantity (Pieces)',
        'Purchase Price (Rs)',
        'Stock Value (Rs)',
        'Expiry Date',
        'Days Expired'
    ]);
    
    $serial = 1;
    $totalQuantity = 0;
    $totalValue = 0;
    
    foreach ($products as $product) {
        $quantity = (int)$product['remaining_quantity'];
        $purchasePrice = (float)$product['purchase_price'];
        $stockValue = $quantity * $purchasePrice;
        
        $totalQuantity += $quantity;
        $totalValue += $stockValue;
        
        $strengthSize = '';
        if ($product['category_id'] == 1) {
            if (!empty($product['strength'])) {
                $strengthSize .= $product['strength'];
                if (!empty($product['strength_unit'])) {
                    $strengthSize .= ' ' . $product['strength_unit'];
                }
            }
            if (!empty($product['dosage_form'])) {
                $strengthSize .= ' (' . $product['dosage_form'] . ')';
            }
        } else {
            if (!empty($product['strength'])) {
                $strengthSize .= $product['strength'];
                if (!empty($product['strength_unit'])) {
                    $strengthSize .= ' ' . $product['strength_unit'];
                }
            }
            if (!empty($product['size_value'])) {
                $strengthSize .= ' ' . $product['size_value'];
            }
        }
        
        fputcsv($output, [
            $serial++,
            $product['batch_number'],
            $product['supplier_name'] ?? '',
            $product['product_name'],
            $product['brand_name'] ?? '',
            ($product['category_id'] == 1) ? ($product['generic_name'] ?? '') : '',
            $strengthSize ?: '-',
            $quantity,
            number_format($purchasePrice, 2),
            number_format($stockValue, 2),
            date('d-m-Y', strtotime($product['expiry_date'])),
            $product['days_expired'] . ' days ago'
        ]);
    }
    
    fputcsv($output, []);
    fputcsv($output, ['SUMMARY', '', '', '', '', '', '', '', '', '', '', '']);
    fputcsv($output, ['Total Expired Batches:', $serial - 1]);
    fputcsv($output, ['Total Quantity:', $totalQuantity]);
    fputcsv($output, ['Total Stock Value:', 'Rs ' . number_format($totalValue, 2)]);
    
    if ($search) fputcsv($output, ['Search Filter:', $search]);
    if ($filter == 'today') fputcsv($output, ['Date Filter:', 'Today\'s Expired Only']);
    else fputcsv($output, ['Date Filter:', 'All Expired']);
    
    fputcsv($output, ['Generated On:', date('d-m-Y H:i:s')]);
}

/**
 * Export Cashbook Report to CSV — Phase 22
 */
function exportCashbookReport($output, $from_date, $to_date) {
    $cs  = function_exists('getCompanySettings') ? getCompanySettings() : [];
    $report = function_exists('getCashbookReport') ? getCashbookReport($from_date, $to_date) : [];

    fputcsv($output, ['CASHBOOK REPORT']);
    fputcsv($output, ['Period:', date('d-m-Y', strtotime($from_date)) . ' to ' . date('d-m-Y', strtotime($to_date))]);
    fputcsv($output, []);
    fputcsv($output, ['Date', 'Opening Balance', 'Total In', 'Total Out', 'Closing Balance', 'Net Flow']);

    $totalIn = $totalOut = 0;
    foreach ($report as $row) {
        $nf = (float)($row['net_flow'] ?? ((float)$row['total_in'] - (float)$row['total_out']));
        fputcsv($output, [
            $row['date'],
            number_format((float)($row['opening'] ?? 0), 2, '.', ''),
            number_format((float)$row['total_in'],        2, '.', ''),
            number_format((float)$row['total_out'],       2, '.', ''),
            number_format((float)($row['closing'] ?? 0),  2, '.', ''),
            number_format($nf,                             2, '.', ''),
        ]);
        $totalIn  += (float)$row['total_in'];
        $totalOut += (float)$row['total_out'];
    }

    fputcsv($output, []);
    fputcsv($output, ['SUMMARY']);
    fputcsv($output, ['Total Cash In:',  number_format($totalIn,  2)]);
    fputcsv($output, ['Total Cash Out:', number_format($totalOut, 2)]);
    fputcsv($output, ['Net Flow:',       number_format($totalIn - $totalOut, 2)]);
    fputcsv($output, ['Days Covered:',   count($report)]);
    fputcsv($output, ['Generated On:',   date('d-m-Y H:i:s')]);
}
?>