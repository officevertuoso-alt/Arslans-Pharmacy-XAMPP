<?php
if (!isset($_SESSION['user_id'])) { http_response_code(403); exit; }
/**
 * customers/list_fragment.php — AJAX-rendered table fragment
 * Phase 18: Full AJAX Modernisation
 *
 * Variables provided by ajax_handler.php (get_list):
 *   $customers    – array of customer rows
 *   $totalRecords – total matching records
 *   $totalPages   – total pages
 *   $page         – current page number
 *   $perPage      – rows per page (25)
 *   $search       – current search string
 *   $filter       – current filter ('pending' | 'clear' | '')
 *   $serialStart  – first row serial number
 */

if (!isset($customers))    { $customers    = []; }
if (!isset($totalRecords)) { $totalRecords = 0; }
if (!isset($totalPages))   { $totalPages   = 1; }
if (!isset($page))         { $page         = 1; }
if (!isset($perPage))      { $perPage      = 25; }
if (!isset($search))       { $search       = ''; }
if (!isset($filter))       { $filter       = ''; }
if (!isset($serialStart))  { $serialStart  = 1; }

$HANDLER = BASE_URL . 'customers/ajax_handler.php';
?>

<div class="tcard-header d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
    <span class="fw-semibold" style="font-size:12px;color:var(--mu);">
        <i class="fas fa-table me-1"></i>
        <?php if ($totalRecords > 0): ?>
            Showing <?= count($customers) ?> of <?= number_format($totalRecords) ?> customer<?= $totalRecords !== 1 ? 's' : '' ?>
        <?php else: ?>
            No customers found
        <?php endif; ?>
    </span>
    <?php if ($totalPages > 1): ?>
    <nav>
        <ul class="pagination pagination-sm mb-0">
            <?php if ($page > 1): ?>
            <li class="page-item">
                <a class="page-link page-nav" href="#" data-page="<?= $page - 1 ?>">
                    <i class="fas fa-chevron-left"></i>
                </a>
            </li>
            <?php endif; ?>
            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                <a class="page-link page-nav" href="#" data-page="<?= $i ?>"><?= $i ?></a>
            </li>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
            <li class="page-item">
                <a class="page-link page-nav" href="#" data-page="<?= $page + 1 ?>">
                    <i class="fas fa-chevron-right"></i>
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </nav>
    <?php endif; ?>
</div>

<div class="table-responsive">
<table class="table table-hover mb-0" id="customersTable">
    <thead>
        <tr>
            <th style="width:45px">#</th>
            <th>Customer</th>
            <th>Phone</th>
            <th>Address</th>
            <th>Credits</th>
            <th>Total Pending</th>
            <th style="width:150px">Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($customers)): ?>
        <tr>
            <td colspan="7" class="text-center py-5 text-muted">
                <i class="fas fa-users fa-2x mb-2 d-block opacity-30"></i>
                <?php if ($search): ?>
                    No customers found for &ldquo;<?= htmlspecialchars($search) ?>&rdquo;
                <?php elseif ($filter === 'pending'): ?>
                    No customers with pending balances
                <?php elseif ($filter === 'clear'): ?>
                    No customers with clear balance
                <?php else: ?>
                    No customers registered yet
                <?php endif; ?>
            </td>
        </tr>
    <?php else: ?>
        <?php foreach ($customers as $i => $c):
            $hasPending = (float)$c['total_pending'] > 0.005;
        ?>
        <tr class="<?= $hasPending ? 'has-pending' : '' ?>" data-id="<?= $c['id'] ?>">
            <td class="text-muted" style="font-size:11px"><?= $serialStart + $i ?></td>
            <td>
                <strong><?= htmlspecialchars($c['name']) ?></strong>
                <?php if (!empty($c['notes'])): ?>
                <br><small class="text-muted"><?= htmlspecialchars(mb_strimwidth($c['notes'], 0, 40, '…')) ?></small>
                <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($c['phone']) ?></td>
            <td><small class="text-muted"><?= htmlspecialchars($c['address'] ?? '—') ?></small></td>
            <td>
                <span class="badge bg-secondary"><?= (int)$c['total_credits'] ?> total</span>
                <?php if ((int)$c['active_credits'] > 0): ?>
                <span class="badge bg-danger ms-1"><?= (int)$c['active_credits'] ?> active</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($hasPending): ?>
                    <span class="pending-badge">Rs <?= number_format((float)$c['total_pending'], 2) ?></span>
                <?php else: ?>
                    <span class="clear-badge"><i class="fas fa-check-circle me-1"></i>Clear</span>
                <?php endif; ?>
            </td>
            <td>
                <div class="d-flex gap-1">
                    <button class="btn btn-outline-primary btn-sm btn-view"
                            data-requires-perm="customers.view"
                            data-id="<?= $c['id'] ?>"
                            title="View Details">
                        <i class="fas fa-eye"></i>
                    </button>
                    <button class="btn btn-outline-secondary btn-sm btn-edit"
                            data-requires-perm="customers.edit"
                            data-id="<?= $c['id'] ?>"
                            data-cust='<?= htmlspecialchars(json_encode([
                                'id'      => $c['id'],
                                'name'    => $c['name'],
                                'phone'   => $c['phone'],
                                'address' => $c['address'] ?? '',
                                'notes'   => $c['notes']   ?? '',
                            ]), ENT_QUOTES) ?>'
                            title="Edit">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="btn btn-outline-success btn-sm btn-export-one"
                            data-requires-perm="credit.export"
                            data-phone="<?= htmlspecialchars(urlencode($c['phone'])) ?>"
                            title="Export CSV">
                        <i class="fas fa-file-csv"></i>
                    </button>
                    <button class="btn btn-outline-danger btn-sm btn-delete"
                            data-requires-perm="customers.delete"
                            data-id="<?= $c['id'] ?>"
                            data-name="<?= htmlspecialchars($c['name']) ?>"
                            data-pending="<?= (float)$c['total_pending'] ?>"
                            title="Delete">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>
</div>
