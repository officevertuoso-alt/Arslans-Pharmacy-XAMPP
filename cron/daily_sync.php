<?php
// Phase 28 Security: CLI-only access guard
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    $remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($remoteIp, ['127.0.0.1', '::1'], true)) {
        http_response_code(403);
        die('403 Forbidden: Cron scripts must run from CLI or localhost.');
    }
}

// cron/daily_sync.php – Daily maintenance
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/sync_engine.php';

// 1. Process expired stock loss
$result = syncExpiredStockLoss(date('Y-m-d'), false);
echo "Expired stock: " . $result['message'] . PHP_EOL;

// 2. Optional: Recalculate any purchase totals that may have drifted (safety)
// (Triggers already handle this, but double-check)
$db = db();
$db->run("UPDATE purchases p SET p.due_amount = p.total - p.paid_amount WHERE p.payment_status != 'paid'");
echo "Purchase due amounts recalculated." . PHP_EOL;

// 3. Clear caches
clearAllCaches();
echo "Caches cleared." . PHP_EOL;