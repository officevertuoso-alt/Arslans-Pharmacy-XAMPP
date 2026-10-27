<?php
// Phase 28 Security: CLI-only access guard
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    $remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($remoteIp, ['127.0.0.1', '::1'], true)) {
        http_response_code(403);
        die('403 Forbidden: Cron scripts must run from CLI or localhost.');
    }
}

/**
 * Cron job – Fix sale totals (daily safety net)
 * Run this every night to ensure sales.total = sum(line totals) - discount + tax
 */

// Load configuration (adjust path if needed)
require_once __DIR__ . '/../config/config.php';

// Use the Database class (or raw PDO)
$db = db();

// Update all sales where total is wrong
$sql = "UPDATE sales s
        SET s.total = (
            SELECT COALESCE(SUM(si.total), 0)
            FROM sale_items si
            WHERE si.sale_id = s.id
        ) - s.discount + s.tax_amount
        WHERE ABS(s.total - (
            SELECT COALESCE(SUM(si.total), 0)
            FROM sale_items si
            WHERE si.sale_id = s.id
        ) + s.discount - s.tax_amount) > 0.01";

$affected = $db->run($sql); // returns number of rows updated

// Log the result (optional)
$log = "[" . date('Y-m-d H:i:s') . "] Sale totals fixed. Updated rows: " . $affected . PHP_EOL;
file_put_contents(__DIR__ . '/fix_sale_totals.log', $log, FILE_APPEND);

// Output (visible only when run manually)
echo "Sale totals corrected. $affected row(s) updated.\n";