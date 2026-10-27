<?php
if (!isset($_SESSION['user_id'])) { http_response_code(403); exit; }
/**
 * suppliers/list_fragment.php — AJAX-rendered table fragment
 * Phase 19: Full AJAX Modernisation
 *
 * Variables provided by ajax_handler.php (get_list):
 *   $suppliers    – array of supplier rows with aggregated purchase totals
 *   $totalRecords – total matching records
 *   $totalPages   – total pages
 *   $page         – current page number
 *   $perPage      – rows per page (20)
 *   $search       – current search string
 *   $filter       – current filter ('with_due' | 'clear' | '')
 *   $serialStart  – first row serial number
 */

if (!isset($suppliers))    { $suppliers    = []; }
if (!isset($totalRecords)) { $totalRecords = 0; }
if (!isset($totalPages))   { $totalPages   = 1; }
if (!isset($page))         { $page         = 1; }
if (!isset($perPage))      { $perPage      = 20; }
if (!isset($search))       { $search       = ''; }
if (!isset($filter))       { $filter       = ''; }
if (!isset($serialStart))  { $serialStart  = 1; }
?>

<div class="tcard-header d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
    <span class="fw-semibold" style="font-size:12px;color:var(--mu);">
        <i class="fas fa-table me-1"></i>
        <?php if ($totalRecords > 0): ?>
            Showing <?= count($suppliers) ?> of <?= number_format($totalRecords) ?> supplier<?= $totalRecords !== 1 ? 's' : '' ?>
        <?php else: ?>
            No suppliers found
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
<table class="table table-hover mb-0" id="suppliersTable">
    <thead>
        <tr>
            <th style="width:45px">#</th>
            <th>Supplier Name</th>
            <th>Contact</th>
            <th>Phone</th>
            <th class="text-end">Purchases</th>
            <th class="text-end">Paid</th>
            <th class="text-end">Due</th>
            <th style="width:160px" class="text-center">Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($suppliers)): ?>
        <tr>
            <td colspan="8" class="text-center py-5 text-muted">
                <i class="fas fa-truck fa-2x mb-2 d-block opacity-30"></i>
                <?php if ($search): ?>
                    No suppliers found for &ldquo;<?= htmlspecialchars($search) ?>&rdquo;
                <?php elseif ($filter === 'with_due'): ?>
                    No suppliers with pending dues
                <?php elseif ($filter === 'clear'): ?>
                    No suppliers with clear balance
                <?php else: ?>
                    No suppliers registered yet
                <?php endif; ?>
            </td>
        </tr>
    <?php else: ?>
        <?php foreach ($suppliers as $i => $s):
            $hasDue = (float)$s['total_due'] > 0.005;
        ?>
        <tr class="<?= $hasDue ? 'has-due' : '' ?>" data-id="<?= $s['id'] ?>">
            <td class="text-muted" style="font-size:11px"><?= $serialStart + $i ?></td>
            <td>
                <a href="view.php?id=<?= $s['id'] ?>" class="supplier-name fw-semibold text-decoration-none">
                    <?= htmlspecialchars($s['supplier_name']) ?>
                </a>
                <?php if (!empty($s['supplier_code'])): ?>
                <br><small class="text-muted"><?= htmlspecialchars($s['supplier_code']) ?></small>
                <?php endif; ?>
            </td>
            <td>
                <small><?= htmlspecialchars($s['contact_person'] ?? '—') ?></small>
                <?php if (!empty($s['email'])): ?>
                <br><small class="text-muted"><?= htmlspecialchars($s['email']) ?></small>
                <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($s['phone'] ?? '—') ?></td>
            <td class="text-end">
                <span class="text-muted small"><?= (int)$s['purchase_count'] ?> orders</span><br>
                <strong>Rs <?= number_format((float)$s['total_purchases'], 2) ?></strong>
            </td>
            <td class="text-end">
                <span class="clear-badge">Rs <?= number_format((float)$s['total_paid'], 2) ?></span>
            </td>
            <td class="text-end">
                <?php if ($hasDue): ?>
                    <span class="due-badge">Rs <?= number_format((float)$s['total_due'], 2) ?></span>
                <?php else: ?>
                    <span class="text-muted small">—</span>
                <?php endif; ?>
            </td>
            <td class="text-center">
                <div class="d-flex justify-content-center gap-1 flex-wrap">
                    <button class="btn btn-outline-primary btn-sm btn-view-supp"
                            data-requires-perm="suppliers.view"
                            data-id="<?= $s['id'] ?>"
                            title="View Details">
                        <i class="fas fa-eye"></i>
                    </button>
                    <button class="btn btn-outline-secondary btn-sm btn-edit-supp"
                            data-requires-perm="suppliers.edit"
                            data-id="<?= $s['id'] ?>"
                            data-supp='<?= htmlspecialchars(json_encode([
                                'id'                => $s['id'],
                                'supplier_name'     => $s['supplier_name'],
                                'contact_person'    => $s['contact_person']    ?? '',
                                'phone'             => $s['phone']             ?? '',
                                'alternative_phone' => $s['alternative_phone'] ?? '',
                                'email'             => $s['email']             ?? '',
                                'address'           => $s['address']           ?? '',
                                'gst_no'            => $s['gst_no']            ?? '',
                            ]), ENT_QUOTES) ?>'
                            title="Edit">
                        <i class="fas fa-edit"></i>
                    </button>
                    <?php if ($hasDue): ?>
                    <a href="pay.php?id=<?= $s['id'] ?>" class="btn btn-outline-success btn-sm" title="Pay Due"
                       data-requires-perm="suppliers.edit">
                        <i class="fas fa-money-bill-wave"></i>
                    </a>
                    <?php endif; ?>
                    <a href="statement.php?id=<?= $s['id'] ?>" class="btn btn-outline-info btn-sm" title="Statement"
                       data-requires-perm="suppliers.view">
                        <i class="fas fa-file-alt"></i>
                    </a>
                    <button class="btn btn-outline-danger btn-sm btn-delete-supp"
                            data-requires-perm="suppliers.delete"
                            data-id="<?= $s['id'] ?>"
                            data-name="<?= htmlspecialchars($s['supplier_name']) ?>"
                            data-due="<?= (float)$s['total_due'] ?>"
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
