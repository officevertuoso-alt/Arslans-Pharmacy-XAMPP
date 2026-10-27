<?php
/**
 * cron/cleanup_invoice_sequences.php
 *
 * ISS-033 FIX: Clean up old invoice_sequences rows to prevent unbounded table growth.
 * Run monthly via cron: 0 0 1 * * php /path/to/pharmacy_software/cron/cleanup_invoice_sequences.php
 *
 * Keeps current year + last year rows; purges anything older.
 */

// Allow CLI execution only (not via HTTP)
if (PHP_SAPI !== 'cli' && !defined('ALLOW_CRON_HTTP')) {
    http_response_code(403);
    exit('CLI only');
}

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';

try {
    $pdo = db()->getConnection();

    // Delete rows older than last year
    $stmt = $pdo->prepare(
        "DELETE FROM invoice_sequences
         WHERE year < YEAR(NOW()) - 1
            OR (year = YEAR(NOW()) - 1 AND month < MONTH(NOW()))"
    );
    $stmt->execute();
    $deleted = $stmt->rowCount();

    $msg = "[" . date('Y-m-d H:i:s') . "] invoice_sequences cleanup: {$deleted} old row(s) removed.\n";
    echo $msg;
    error_log($msg);

} catch (Throwable $e) {
    $err = "[" . date('Y-m-d H:i:s') . "] invoice_sequences cleanup ERROR: " . $e->getMessage() . "\n";
    echo $err;
    error_log($err);
    exit(1);
}
