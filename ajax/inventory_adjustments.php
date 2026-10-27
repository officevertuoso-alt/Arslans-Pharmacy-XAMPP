<?php
/**
 * ajax/inventory_adjustments.php — Phase 15: Inventory AJAX
 *
 * Handles AJAX requests for the Stock Adjustments list:
 *   - Table rows (paginated)
 *   - Summary cards (damage/theft/correction/loss_value)
 *   - Pagination HTML
 *   - Search + Filter (date range, type)
 *
 * Method : GET
 * Params :
 *   from        (date, default: first of month)
 *   to          (date, default: today)
 *   type        (string, optional: damage|theft|correction|expiry_manual)
 *   search      (string, optional: product name/code/batch search)
 *   page        (int, default: 1)
 *   per_page    (int, default: 25)
 *
 * Returns: JSON { success, tableHtml, paginationHtml, summary, total, page, totalPages }
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

// Ensure stock_adjustments table exists
$pdo = _getPDO();
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `stock_adjustments` (
        `id`               int(11)       NOT NULL AUTO_INCREMENT,
        `batch_id`         int(11)       NOT NULL,
        `product_id`       int(11)       NOT NULL DEFAULT 0,
        `adjustment_type`  enum('damage','theft','correction','expiry_manual') NOT NULL,
        `quantity`         int(11)       NOT NULL COMMENT 'negative=removed, positive=added',
        `reason`           varchar(255)  NOT NULL,
        `cashbook_entry_id` int(11)      DEFAULT NULL,
        `created_by`       int(11)       NOT NULL,
        `created_at`       timestamp     NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `idx_batch`   (`batch_id`),
        KEY `idx_product` (`product_id`),
        KEY `idx_date`    (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
} catch (Exception $e) {
    error_log('inventory_adjustments.php table check: ' . $e->getMessage());
}

// ── Input sanitization ────────────────────────────────────────────────────────
function safeStr(string $val, int $maxLen = 100): string {
    return substr(trim(strip_tags($val)), 0, $maxLen);
}

$fromDate   = safeStr($_GET['from']     ?? date('Y-m-01'));
$toDate     = safeStr($_GET['to']       ?? date('Y-m-d'));
$typeFilter = safeStr($_GET['type']     ?? '');
$search     = safeStr($_GET['search']   ?? '', 200);
$page       = max(1, (int)($_GET['page']     ?? 1));
$perPage    = min(100, max(10, (int)($_GET['per_page'] ?? 25)));

if (!strtotime($fromDate)) $fromDate = date('Y-m-01');
if (!strtotime($toDate))   $toDate   = date('Y-m-d');

$validTypes = ['damage', 'theft', 'correction', 'expiry_manual'];
if ($typeFilter && !in_array($typeFilter, $validTypes, true)) $typeFilter = '';

// ── Build WHERE clause ────────────────────────────────────────────────────────
$where  = "sa.created_at BETWEEN :from AND :to_end";
$params = [':from' => $fromDate . ' 00:00:00', ':to_end' => $toDate . ' 23:59:59'];

if ($typeFilter) {
    $where .= " AND sa.adjustment_type = :type";
    $params[':type'] = $typeFilter;
}

if ($search !== '') {
    $where .= " AND (p.product_name LIKE :search OR p.product_code LIKE :search OR b.batch_number LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

// ── Count ─────────────────────────────────────────────────────────────────────
$totalCount = 0;
try {
    $cRow = getOne(
        "SELECT COUNT(*) AS cnt
         FROM stock_adjustments sa
         JOIN batches b ON b.id = sa.batch_id
         JOIN products p ON p.id = b.product_id
         WHERE $where",
        $params
    );
    $totalCount = $cRow ? (int)$cRow['cnt'] : 0;
} catch (Exception $e) {
    error_log('inventory_adjustments count: ' . $e->getMessage());
}

$totalPages = $totalCount > 0 ? (int)ceil($totalCount / $perPage) : 1;
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// ── Main query ────────────────────────────────────────────────────────────────
$adjustments = [];
try {
    $adjustments = getAll(
        "SELECT
             sa.id, sa.adjustment_type, sa.quantity, sa.reason, sa.created_at, sa.cashbook_entry_id,
             p.id AS product_id, p.product_name, p.product_code,
             b.id AS batch_id, b.batch_number, b.expiry_date, b.purchase_price,
             u.full_name AS done_by
         FROM stock_adjustments sa
         JOIN batches b ON b.id = sa.batch_id
         JOIN products p ON p.id = b.product_id
         LEFT JOIN users u ON u.id = sa.created_by
         WHERE $where
         ORDER BY sa.created_at DESC
         LIMIT $perPage OFFSET $offset",
        $params
    );
} catch (Exception $e) {
    error_log('inventory_adjustments query: ' . $e->getMessage());
}

// ── Summary stats ─────────────────────────────────────────────────────────────
$summary = ['damage' => 0, 'theft' => 0, 'correction' => 0, 'expiry_manual' => 0, 'loss_value' => 0];
try {
    // Summary uses same WHERE but without pagination
    $sWhere  = "sa.created_at BETWEEN :from AND :to_end";
    $sParams = [':from' => $fromDate . ' 00:00:00', ':to_end' => $toDate . ' 23:59:59'];
    if ($typeFilter) { $sWhere .= " AND sa.adjustment_type = :type"; $sParams[':type'] = $typeFilter; }
    if ($search !== '') {
        $sWhere .= " AND (p.product_name LIKE :search OR p.product_code LIKE :search OR b.batch_number LIKE :search)";
        $sParams[':search'] = '%' . $search . '%';
    }

    $sRows = getAll(
        "SELECT sa.adjustment_type, SUM(sa.quantity) AS total_qty,
                SUM(CASE WHEN sa.quantity < 0 THEN ABS(sa.quantity) * b.purchase_price ELSE 0 END) AS loss_val
         FROM stock_adjustments sa
         JOIN batches b ON b.id = sa.batch_id
         JOIN products p ON p.id = b.product_id
         WHERE $sWhere
         GROUP BY sa.adjustment_type",
        $sParams
    );
    foreach ($sRows as $s) {
        $summary[$s['adjustment_type']] = (int)$s['total_qty'];
        $summary['loss_value'] += (float)$s['loss_val'];
    }
} catch (Exception $e) {
    error_log('inventory_adjustments summary: ' . $e->getMessage());
}

// ── Generate table HTML ───────────────────────────────────────────────────────
$typeBadgeMap = [
    'damage'        => 'danger',
    'theft'         => 'warning',
    'correction'    => 'info',
    'expiry_manual' => 'secondary',
];

ob_start();
if (empty($adjustments)): ?>
<tr>
  <td colspan="10" class="text-center text-muted py-5">
    <i class="fas fa-box-open fa-2x d-block mb-2 opacity-25"></i>
    No stock adjustments found for the selected filters.
  </td>
</tr>
<?php else:
    foreach ($adjustments as $a):
        $typeBadge = $typeBadgeMap[$a['adjustment_type']] ?? 'secondary';
        $isLoss    = $a['quantity'] < 0;
        $lossValue = $isLoss ? abs($a['quantity']) * (float)$a['purchase_price'] : 0;
?>
<tr>
  <td class="ps-3 text-muted"><?php echo (int)$a['id']; ?></td>
  <td>
    <span class="fw-semibold"><?php echo date('d M Y', strtotime($a['created_at'])); ?></span>
    <small class="d-block text-muted"><?php echo date('H:i', strtotime($a['created_at'])); ?></small>
  </td>
  <td>
    <a href="../products/view.php?id=<?php echo (int)$a['product_id']; ?>" class="text-decoration-none fw-semibold">
      <?php echo htmlspecialchars($a['product_name']); ?>
    </a>
    <small class="d-block text-muted"><?php echo htmlspecialchars($a['product_code']); ?></small>
  </td>
  <td>
    <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($a['batch_number']); ?></span>
    <?php if ($a['expiry_date']): ?>
    <small class="d-block text-muted">Exp: <?php echo date('M Y', strtotime($a['expiry_date'])); ?></small>
    <?php endif; ?>
  </td>
  <td>
    <span class="badge bg-<?php echo $typeBadge; ?>">
      <?php echo ucfirst(str_replace('_', ' ', $a['adjustment_type'])); ?>
    </span>
  </td>
  <td class="text-center fw-bold <?php echo $isLoss ? 'text-danger' : 'text-success'; ?>">
    <?php echo ($a['quantity'] > 0 ? '+' : '') . number_format($a['quantity']); ?>
  </td>
  <td class="text-end text-danger">
    <?php echo $lossValue > 0 ? 'Rs. ' . number_format($lossValue, 2) : '<span class="text-muted">—</span>'; ?>
  </td>
  <td class="text-wrap" style="max-width:200px;">
    <small><?php echo htmlspecialchars($a['reason']); ?></small>
  </td>
  <td>
    <?php if ($a['cashbook_entry_id']): ?>
      <span class="badge bg-success-subtle text-success border border-success">
        <i class="fas fa-check-circle me-1"></i>Synced
      </span>
    <?php else: ?>
      <span class="badge bg-secondary-subtle text-secondary border">
        <?php echo $isLoss ? 'N/A' : '—'; ?>
      </span>
    <?php endif; ?>
  </td>
  <td><small><?php echo htmlspecialchars($a['done_by'] ?? 'System'); ?></small></td>
</tr>
<?php
    endforeach;
endif;
$tableHtml = ob_get_clean();

// ── Generate pagination HTML ──────────────────────────────────────────────────
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
      <a class="page-link" href="#" data-page="<?php echo $page - 1; ?>">
        <i class="fas fa-chevron-left"></i>
      </a>
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
      <a class="page-link" href="#" data-page="<?php echo $page + 1; ?>">
        <i class="fas fa-chevron-right"></i>
      </a>
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
        'damage'       => abs((int)$summary['damage']),
        'theft'        => abs((int)$summary['theft']),
        'correction'   => (int)$summary['correction'],
        'expiry_manual'=> abs((int)$summary['expiry_manual']),
        'loss_value'   => round((float)$summary['loss_value'], 2),
    ],
    'total'          => $totalCount,
    'page'           => $page,
    'totalPages'     => $totalPages,
    'perPage'        => $perPage,
    'filters'        => [
        'from'   => $fromDate,
        'to'     => $toDate,
        'type'   => $typeFilter,
        'search' => $search,
    ],
], JSON_UNESCAPED_UNICODE);
exit;
