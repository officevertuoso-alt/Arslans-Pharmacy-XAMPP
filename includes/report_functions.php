<?php
/**
 * Report Functions for Pharmacy Management System
 * Updated for Stock Report - Only shows in-stock products
 */

// Get In-Stock Products Report (only products with current stock > 0 and not expired)
// Search is performed ONLY on product_name (as requested)
function getInStockProductsReport($search = '', $category = '') {
    $db = db();
    
    // Use INNER JOIN to only include products that have valid stock batches
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
                0 as reorder_level,
                COALESCE(SUM(b.remaining_quantity), 0) as current_stock,
                COALESCE(MIN(b.purchase_price), 0) as purchase_price,
                COALESCE(MIN(b.selling_price), 0) as selling_price,
                COUNT(DISTINCT b.id) as batch_count
            FROM products p
            INNER JOIN batches b ON p.id = b.product_id
            WHERE p.status = 1
                AND b.expiry_date > CURDATE()
                AND b.is_expired = 0
                AND b.is_blocked = 0
                AND b.remaining_quantity > 0";
    
    $params = [];
    
    // Apply search – only on product name
    if (!empty($search)) {
        $sql .= " AND p.product_name LIKE :search";
        $params[':search'] = "%$search%";
    }
    
    // Apply category filter
    if ($category === 'medicine') {
        $sql .= " AND p.category_id = 1";
    } elseif ($category === 'non_medicine') {
        $sql .= " AND p.category_id = 2";
    }
    
    $sql .= " GROUP BY p.id
              ORDER BY p.product_name ASC";
    
    $db->query($sql);
    foreach ($params as $key => $value) {
        $db->bind($key, $value);
    }
    return $db->resultSet();
}

// Get stock summary report (original - kept for backward compatibility)
function getStockReport($search = '', $category = '', $lowStockOnly = false) {
    $db = db();
    $where = "p.status = 1";
    $params = [];
    
    if (!empty($search)) {
        $where .= " AND (p.product_name LIKE :search OR p.generic_name LIKE :search OR p.brand_name LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    if (!empty($category)) {
        $where .= " AND p.category_id = :category";
        $params[':category'] = $category;
    }
    
    $sql = "SELECT 
                p.id,
                p.product_code,
                p.product_name,
                p.generic_name,
                p.brand_name,
                p.strength,
                p.strength_unit,
                p.dosage_form,
                p.unit_type,
                0 as reorder_level,
                COALESCE(SUM(CASE WHEN b.expiry_date > CURDATE() AND b.is_expired = 0 THEN b.remaining_quantity ELSE 0 END), 0) as current_stock,
                COALESCE(SUM(CASE WHEN b.expiry_date <= CURDATE() OR b.is_expired = 1 THEN b.remaining_quantity ELSE 0 END), 0) as expired_stock,
                COUNT(DISTINCT b.id) as batch_count,
                MIN(b.expiry_date) as nearest_expiry,
                COALESCE(MIN(b.purchase_price), 0) as last_purchase_price,
                COALESCE(MIN(b.selling_price), 0) as selling_price
            FROM products p
            LEFT JOIN batches b ON p.id = b.product_id
            WHERE $where
            GROUP BY p.id";
    
    if ($lowStockOnly) {
        $sql .= " HAVING current_stock <= 0 AND current_stock > 0"; // reorder_level column removed
    }
    
    $sql .= " ORDER BY current_stock ASC, p.product_name ASC";
    
    $db->query($sql);
    foreach ($params as $key => $value) {
        $db->bind($key, $value);
    }
    
    return $db->resultSet();
}

// Get expiry report
// $status = 'all'     → All expired batches (full history, qty 0 bhi)
// $status = 'current' → Only expired batches with remaining_quantity > 0
//                       (jo abhi bhi products list ke expired tab mein hain)
function getExpiryReport($status = 'all', $search = '') {
    $db = db();

    // Base: all expired batches (expiry_date < today)
    $where  = "b.expiry_date IS NOT NULL AND b.expiry_date <= CURDATE()";
    $params = [];

    if ($status === 'current') {
        // Only batches still showing in expired stock
        $where .= " AND b.remaining_quantity > 0";
    }
    // 'all' — no remaining_quantity restriction (full history)

    if (!empty($search)) {
        $where .= " AND (p.product_name LIKE :search OR b.batch_number LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    $sql = "SELECT 
                b.id,
                b.batch_number,
                b.product_id,
                b.remaining_quantity,
                b.expiry_date,
                b.purchase_price,
                b.selling_price,
                p.product_name,
                p.generic_name,
                p.brand_name,
                p.category_id,
                p.strength,
                p.strength_unit,
                p.dosage_form,
                p.size_value,
                s.supplier_name,
                DATEDIFF(CURDATE(), b.expiry_date) AS days_expired
            FROM batches b
            JOIN products p ON b.product_id = p.id
            LEFT JOIN suppliers s ON b.supplier_id = s.id
            WHERE $where
            ORDER BY b.expiry_date ASC";
    
    $db->query($sql);
    foreach ($params as $key => $value) {
        $db->bind($key, $value);
    }
    
    return $db->resultSet();
}

// Get sales report
function getSalesReport($fromDate = '', $toDate = '', $paymentMethod = '') {
    $db = db();
    $where = "1";
    $params = [];
    
    if (!empty($fromDate)) {
        $where .= " AND DATE(sale_date) >= :from_date";
        $params[':from_date'] = $fromDate;
    }
    
    if (!empty($toDate)) {
        $where .= " AND DATE(sale_date) <= :to_date";
        $params[':to_date'] = $toDate;
    }
    
    if (!empty($paymentMethod)) {
        $where .= " AND payment_method = :payment_method";
        $params[':payment_method'] = $paymentMethod;
    }
    
    $sql = "SELECT 
                s.*,
                (SELECT COUNT(*) FROM sale_items WHERE sale_id = s.id) as items_count
            FROM sales s
            WHERE $where
            ORDER BY s.sale_date DESC";
    
    $db->query($sql);
    foreach ($params as $key => $value) {
        $db->bind($key, $value);
    }
    
    $sales = $db->resultSet();
    
    // Calculate totals
    $totals = [
        'total_sales' => 0,
        'total_amount' => 0,
        'total_discount' => 0,
        'total_tax' => 0,
        'cash_amount' => 0,
        'card_amount' => 0,
        'online_amount' => 0,
        'total_invoices' => count($sales)
    ];
    
    foreach ($sales as $sale) {
        $totals['total_amount'] += $sale['total'];
        $totals['total_discount'] += $sale['discount'];
        $totals['total_tax'] += $sale['tax_amount'];
        
        if ($sale['payment_method'] == 'cash') {
            $totals['cash_amount'] += $sale['total'];
        } elseif ($sale['payment_method'] == 'card') {
            $totals['card_amount'] += $sale['total'];
        } else {
            $totals['online_amount'] += $sale['total'];
        }
    }
    
    return [
        'data' => $sales,
        'totals' => $totals
    ];
}

// Get purchase report
function getPurchaseReport($fromDate = '', $toDate = '', $supplierId = '') {
    $db = db();
    $where = "1";
    $params = [];
    
    if (!empty($fromDate)) {
        $where .= " AND p.purchase_date >= :from_date";
        $params[':from_date'] = $fromDate;
    }
    
    if (!empty($toDate)) {
        $where .= " AND p.purchase_date <= :to_date";
        $params[':to_date'] = $toDate;
    }
    
    if (!empty($supplierId)) {
        $where .= " AND p.supplier_id = :supplier_id";
        $params[':supplier_id'] = $supplierId;
    }
    
    $sql = "SELECT 
                p.*,
                s.supplier_name,
                (SELECT COUNT(*) FROM purchase_items WHERE purchase_id = p.id) as items_count
            FROM purchases p
            JOIN suppliers s ON p.supplier_id = s.id
            WHERE $where
            ORDER BY p.purchase_date DESC";
    
    $db->query($sql);
    foreach ($params as $key => $value) {
        $db->bind($key, $value);
    }
    
    $purchases = $db->resultSet();
    
    // Calculate totals
    $totals = [
        'total_purchases' => 0,
        'total_amount' => 0,
        'total_discount' => 0,
        'total_tax' => 0,
        'total_invoices' => count($purchases)
    ];
    
    foreach ($purchases as $purchase) {
        $totals['total_amount'] += $purchase['total'];
        $totals['total_discount'] += $purchase['discount'];
        $totals['total_tax'] += $purchase['tax_amount'];
    }
    
    return [
        'data' => $purchases,
        'totals' => $totals
    ];
}

// Get profit/loss report
function getProfitLossReport($fromDate = '', $toDate = '') {
    $db = db();
    
    // Get sales data
    $salesData = getSalesReport($fromDate, $toDate);
    $totalSales = $salesData['totals']['total_amount'];
    
    // Get cost of goods sold (COGS)
    $where = "1";
    $params = [];
    
    if (!empty($fromDate)) {
        $where .= " AND s.sale_date >= :from_date";
        $params[':from_date'] = $fromDate;
    }
    
    if (!empty($toDate)) {
        $where .= " AND s.sale_date <= :to_date";
        $params[':to_date'] = $toDate;
    }
    
    $sql = "SELECT 
                si.product_id,
                si.product_name,
                si.quantity,
                si.unit_price as sale_price,
                b.purchase_price,
                (si.quantity * b.purchase_price) as cost
            FROM sale_items si
            JOIN sales s ON si.sale_id = s.id
            JOIN batches b ON si.batch_id = b.id
            WHERE $where";
    
    $db->query($sql);
    foreach ($params as $key => $value) {
        $db->bind($key, $value);
    }
    
    $items = $db->resultSet();
    $totalCost = 0;
    
    foreach ($items as $item) {
        $totalCost += $item['cost'];
    }
    
    $grossProfit = $totalSales - $totalCost;
    $profitMargin = $totalSales > 0 ? ($grossProfit / $totalSales) * 100 : 0;
    
    return [
        'total_sales' => $totalSales,
        'total_cost' => $totalCost,
        'gross_profit' => $grossProfit,
        'profit_margin' => $profitMargin,
        'items_sold' => count($items)
    ];
}

// Get daily sales summary for chart
function getDailySalesSummary($days = 30) {
    $db = db();
    $sql = "
        SELECT 
            DATE(sale_date) as sale_date,
            COUNT(*) as invoice_count,
            SUM(total) as total_amount,
            SUM(discount) as total_discount
        FROM sales
        WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
        GROUP BY DATE(sale_date)
        ORDER BY sale_date ASC
    ";
    return $db->getAll($sql, [':days' => $days]);
}

// Get top selling products report
function getTopSellingProducts($limit = 10, $fromDate = '', $toDate = '') {
    $db = db();
    $where = "1";
    $params = [':limit' => $limit];
    
    if (!empty($fromDate)) {
        $where .= " AND s.sale_date >= :from_date";
        $params[':from_date'] = $fromDate;
    }
    
    if (!empty($toDate)) {
        $where .= " AND s.sale_date <= :to_date";
        $params[':to_date'] = $toDate;
    }
    
    $sql = "SELECT 
                p.id,
                p.product_name,
                p.generic_name,
                p.brand_name,
                SUM(si.quantity) as total_quantity,
                SUM(si.total) as total_amount,
                COUNT(DISTINCT s.id) as invoice_count
            FROM sale_items si
            JOIN products p ON si.product_id = p.id
            JOIN sales s ON si.sale_id = s.id
            WHERE $where
            GROUP BY p.id
            ORDER BY total_quantity DESC
            LIMIT :limit";
    
    return $db->getAll($sql, $params);
}

// Get stock value summary
function getStockValueSummary() {
    $db = db();
    
    $sql = "SELECT 
                COALESCE(SUM(CASE WHEN b.expiry_date > CURDATE() AND b.is_expired = 0 AND b.is_blocked = 0 
                           THEN b.remaining_quantity * b.purchase_price ELSE 0 END), 0) as total_stock_value,
                COALESCE(SUM(CASE WHEN b.expiry_date > CURDATE() AND b.is_expired = 0 AND b.is_blocked = 0 
                           THEN b.remaining_quantity ELSE 0 END), 0) as total_quantity,
                COUNT(DISTINCT b.product_id) as total_products
            FROM batches b";
    
    return $db->getOne($sql);
}

// Export data to CSV
function exportToCSV($data, $filename, $headers = []) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Add UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Write headers
    if (!empty($headers)) {
        fputcsv($output, $headers);
    } elseif (!empty($data)) {
        fputcsv($output, array_keys($data[0]));
    }
    
    // Write data
    foreach ($data as $row) {
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit();
}

// Export stock report to CSV
function exportStockReportToCSV($products, $search = '', $category = '') {
    $filename = 'stock_report_' . date('Y-m-d');
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Add UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Write headers
    fputcsv($output, [
        'S.No',
        'Product Name',
        'Brand Name',
        'Generic Name',
        'Strength / Size',
        'Category',
        'Quantity (Pieces)',
        'Purchase Price (Rs)',
        'Stock Value (Rs)'
    ]);
    
    // Write data
    $serial = 1;
    $totalQuantity = 0;
    $totalValue = 0;
    
    foreach ($products as $product) {
        $currentStock = isset($product['current_stock']) ? (int)$product['current_stock'] : 0;
        $purchasePrice = isset($product['purchase_price']) ? (float)$product['purchase_price'] : 0;
        $stockValue = $currentStock * $purchasePrice;
        
        $totalQuantity += $currentStock;
        $totalValue += $stockValue;
        
        // Format strength/size
        $strengthSize = '';
        if ($product['category_id'] == 1) {
            // Medicine
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
            // Non-Medicine
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
            $product['product_name'],
            $product['brand_name'] ?? '',
            ($product['category_id'] == 1) ? ($product['generic_name'] ?? '') : '',
            $strengthSize ?: '-',
            $categoryName,
            $currentStock,
            number_format($purchasePrice, 2),
            number_format($stockValue, 2)
        ]);
    }
    
    // Add summary rows
    fputcsv($output, []);
    fputcsv($output, ['SUMMARY', '', '', '', '', '', '', '', '']);
    fputcsv($output, ['Total Products:', count($products)]);
    fputcsv($output, ['Total Quantity:', $totalQuantity]);
    fputcsv($output, ['Total Stock Value:', 'Rs ' . number_format($totalValue, 2)]);
    fputcsv($output, ['Generated On:', date('Y-m-d H:i:s')]);
    
    fclose($output);
    exit();
}
?>