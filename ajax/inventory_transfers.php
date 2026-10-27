<?php
/**
 * ajax/inventory_transfers.php — Phase 15: Inventory AJAX
 *
 * Handles AJAX requests for the Stock Transfers list:
 *   - Table rows (paginated)
 *   - Summary (total transfers, units moved)
 *   - Search + Filter (date range, product search)
 *   - Pagination HTML
 *
 * Also handles GET?action=get_batches for batch dropdown in transfer form.
 *
 * Method : GET
 * Params :
 *   action      (string, optional: get_batches)
 *   product_id  (int, when action=get_batches)
 *   from        (date)
 *   to          (date)
 *   search      (string)
 *   page        (int)
 *   per_page    (int)
 */

require_once '../config/config.php';
require_once '../includes/auth.php';
requireLogin();
require_once '../includes/security.php';
requireCSRF();
if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
if (!canDo('stock', 'view')) {
    echo json_encode(['success' => false, 'message' => 'Access denied. Insufficient permissions.']);
    exit;
}
require_once '../includes/sync_engine.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Please login.']);
    exit;
}

if (function_exists('canView') && !canView('stock')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied.']);
    exit;
}

// Ensure stock_transfers table exists
$pdo = _getPDO();
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `stock_transfers` (
        `id`              int(11)      NOT NULL AUTO_INCREMENT,
        `from_batch_id`   int(11)      NOT NULL,
        `to_batch_id`     int(11)      NOT NULL,
        `product_id`      int(11)      NOT NULL,
        `quantity`        int(11)      NOT NULL COMMENT 'units transferred',
        `reason`          varchar(255) NOT NULL DEFAULT '',
        `created_by`      int(11)      NOT NULL,
        `created_at`      timestamp    NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `idx_product` (`product_id`),
        KEY `idx_from`    (`from_batch_id`),
        KEY `idx_to`      (`to_batch_id`),
        KEY `idx_date`    (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
} catch (Exception $e) {
    error_log('inventory_transfers.php table check: ' . $e->getMessage());
}

// ── Action: get_batches for transfer form ─────────────────────────────────────
$action = trim($_GET['action'] ?? '');

if ($action === 'get_batches') {
    $pid = (int)($_GET['product_id'] ?? 0);
    if (!$pid) {
        echo json_encode(['success' => true, 'batches' => []]);
        exit;
    }
    try {
        $batches = getAll(
            "SELECT b.id, b.batch_number, b.expiry_date, b.remaining_quantity,
                    b.purchase_price, b.selling_price, b.is_expired
             FROM batches b
             WHERE b.product_id = :pid AND b.remaining_quantity > 0 AND b.is_blocked = 0
             ORDER BY b.expiry_date ASC, b.id ASC",
            [':pid' => $pid]
        );
        echo json_encode(['success' => true, 'batches' => $batches ?: []]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error loading batches.', 'batches' => []]);
    }
    exit;
}

// ── List mode ─────────────────────────────────────────────────────────────────
$fromDate = trim($_GET['from']     ?? date('Y-m-01'));
$toDate   = trim($_GET['to']       ?? date('Y-m-d'));
$search   = trim($_GET['search']   ?? '');
$page     = max(1, (int)($_GET['page']     ?? 1));
$perPage  = min(100, max(10, (int)($_GET['per_page'] ?? 25)));

if (!strtotime($fromDate)) $fromDate = date('Y-m-01');
if (!strtotime($toDate))   $toDate   = date('Y-m-d');

// ── Build WHERE ───────────────────────────────────────────────────────────────
$where  = "st.created_at BETWEEN :from AND :to_end";
$params = [':from' => $fromDate . ' 00:00:00', ':to_end' => $toDate . ' 23:59:59'];

if ($search !== '') {
    $where .= " AND (p.product_name LIKE :search OR p.product_code LIKE :search OR bf.batch_number LIKE :search OR bt.batch_number LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

// ── Count ─────────────────────────────────────────────────────────────────────
$totalCount = 0;
try {
    $cRow = getOne(
        "SELECT COUNT(*) AS cnt
         FROM stock_transfers st
         JOIN products p ON p.id = st.product_id
         JOIN batches bf ON bf.id = st.from_batch_id
         JOIN batches bt ON bt.id = st.to_batch_id
         WHERE $where",
        $params
    );
    $totalCount = $cRow ? (int)$cRow['cnt'] : 0;
} catch (Exception $e) {
    error_log('inventory_transfers count: ' . $e->getMessage());
}

$totalPages = $totalCount > 0 ? (int)ceil($totalCount / $perPage) : 1;
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// ── Transfers query ───────────────────────────────────────────────────────────
$transfers = [];
try {
    $transfers = getAll(
        "SELECT st.id, st.quantity, st.reason, st.created_at,
                p.id AS product_id, p.product_name, p.product_code,
                bf.id AS from_batch_id, bf.batch_number AS from_batch_number, bf.expiry_date AS from_expiry,
                bt.id AS to_batch_id,   bt.batch_number AS to_batch_number,   bt.expiry_date AS to_expiry,
                u.full_name AS done_by
         FROM stock_transfers st
         JOIN products p  ON p.id  = st.product_id
         JOIN batches bf  ON bf.id = st.from_batch_id
         JOIN batches bt  ON bt.id = st.to_batch_id
         LEFT JOIN users u ON u.id = st.created_by
         WHERE $where
         ORDER BY st.created_at DESC
         LIMIT $perPage OFFSET $offset",
        $params
    );
} catch (Exception $e) {
    error_log('inventory_transfers query: ' . $e->getMessage());
}

// ── Summary ───────────────────────────────────────────────────────────────────
$summaryTotal = 0;
$summaryUnits = 0;
try {
    $sRow = getOne(
        "SELECT COUNT(*) AS total_transfers, COALESCE(SUM(st.quantity),0) AS total_units
         FROM stock_transfers st
         JOIN products p ON p.id = st.product_id
         JOIN batches bf ON bf.id = st.from_batch_id
         JOIN batches bt ON bt.id = st.to_batch_id
         WHERE $where",
        $params
    );
    $summaryTotal = $sRow ? (int)$sRow['total_transfers'] : 0;
    $summaryUnits = $sRow ? (int)$sRow['total_units']     : 0;
} catch (Exception $e) {}

// ── Table HTML ────────────────────────────────────────────────────────────────
ob_start();
if (empty($transfers)): ?>
<tr>
  <td colspan="9" class="text-center text-muted py-5">
    <i class="fas fa-exchange-alt fa-2x d-block mb-2 opacity-25"></i>
    No stock transfers found for the selected filters.
  </td>
</tr>
<?php else:
    foreach ($transfers as $t):
?>
<tr>
  <td class="ps-3 text-muted"><?php echo (int)$t['id']; ?></td>
  <td>
    <span class="fw-semibold"><?php echo date('d M Y', strtotime($t['created_at'])); ?></span>
    <small class="d-block text-muted"><?php echo date('H:i', strtotime($t['created_at'])); ?></small>
  </td>
  <td>
    <a href="../products/view.php?id=<?php echo (int)$t['product_id']; ?>" class="text-decoration-none fw-semibold">
      <?php echo htmlspecialchars($t['product_name']); ?>
    </a>
    <small class="d-block text-muted"><?php echo htmlspecialchars($t['product_code']); ?></small>
  </td>
  <td>
    <span class="badge bg-warning text-dark border"><?php echo htmlspecialchars($t['from_batch_number']); ?></span>
    <?php if ($t['from_expiry'] && $t['from_expiry'] !== '0000-00-00'): ?>
    <small class="d-block text-muted">Exp: <?php echo date('M Y', strtotime($t['from_expiry'])); ?></small>
    <?php endif; ?>
  </td>
  <td class="text-center text-muted"><i class="fas fa-long-arrow-alt-right"></i></td>
  <td>
    <span class="badge bg-success text-white border"><?php echo htmlspecialchars($t['to_batch_number']); ?></span>
    <?php if ($t['to_expiry'] && $t['to_expiry'] !== '0000-00-00'): ?>
    <small class="d-block text-muted">Exp: <?php echo date('M Y', strtotime($t['to_expiry'])); ?></small>
    <?php endif; ?>
  </td>
  <td class="text-center fw-bold text-primary"><?php echo number_format((int)$t['quantity']); ?></td>
  <td class="text-wrap" style="max-width:180px;">
    <small><?php echo htmlspecialchars($t['reason'] ?: '—'); ?></small>
  </td>
  <td><small><?php echo htmlspecialchars($t['done_by'] ?? 'System'); ?></small></td>
</tr>
<?php
    endforeach;
endif;
$tableHtml = ob_get_clean();

// ── Pagination HTML ───────────────────────────────────────────────────────────
ob_start();
if ($totalPages > 1):
    $maxVisible = 7;
    $half       = (int)floor($maxVisible / 2);
    $startPage  = max(1, $page - $half);
    $endPage    = min($totalPages, $startPage + $maxVisible - 1);
    if ($endPage - $startPage < $maxVisible - 1) {
        $startPage = max(1, $endPage - $maxVisible + 1);
    }
?>
<nav>
  <ul class="pagination pagination-sm justify-content-center mb-0">
    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
      <a class="page-link" href="#" data-page="<?php echo $page - 1; ?>"><i class="fas fa-chevron-left"></i></a>
    </li>
    <?php if ($startPage > 1): ?>
      <li class="page-item"><a class="page-link" href="#" data-page="1">1</a></li>
      <?php if ($startPage > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
    <?php endif; ?>
    <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
      <a class="page-link" href="#" data-page="<?php echo $i; ?>"><?php echo $i; ?></a>
    </li>
    <?php endfor; ?>
    <?php if ($endPage < $totalPages): ?>
      <?php if ($endPage < $totalPages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
      <li class="page-item"><a class="page-link" href="#" data-page="<?php echo $totalPages; ?>"><?php echo $totalPages; ?></a></li>
    <?php endif; ?>
    <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
      <a class="page-link" href="#" data-page="<?php echo $page + 1; ?>"><i class="fas fa-chevron-right"></i></a>
    </li>
  </ul>
</nav>
<?php
endif;
$paginationHtml = ob_get_clean();

// ── Response ──────────────────────────────────────────────────────────────────
echo json_encode([
    'success'        => true,
    'tableHtml'      => $tableHtml,
    'paginationHtml' => $paginationHtml,
    'summary'        => [
        'total_transfers' => $summaryTotal,
        'total_units'     => $summaryUnits,
    ],
    'total'      => $totalCount,
    'page'       => $page,
    'totalPages' => $totalPages,
], JSON_UNESCAPED_UNICODE);
exit;
