<?php
/**
 * Manual Cashbook Entry — Add Form
 * cashbook/add_entry.php
 * Step 12 — Cashbook Module
 */

require_once '../config/config.php';
require_once '../includes/cashbook_functions.php';

requireLogin();

if (!canView('cashbook')) {
    setFlash('error', 'Aap ko Cashbook entry add karne ki ijazat nahi.', 'danger');
    header('Location: ../dashboard.php');
    exit;
}

$errors      = [];
$csrfToken   = generateCSRFToken();
$returnDate  = $_GET['date'] ?? date('Y-m-d');

// ── POST Handler ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);

    // CSRF verify
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Security token galat hai. Please dobara try karein.';
    } else {
        $entryType     = sanitize($_POST['entry_type']     ?? '');
        $amount        = (float)($_POST['amount']          ?? 0);
        $description   = sanitize($_POST['description']    ?? '');
        $paymentMethod = sanitize($_POST['payment_method'] ?? 'cash');
        $entryDate     = sanitize($_POST['entry_date']     ?? date('Y-m-d'));
        $direction     = sanitize($_POST['direction']      ?? 'credit'); // in/out

        // Validation
        $allowedTypes = ['expense', 'opening', 'adjustment', 'cash_in', 'cash_out'];
        if (!in_array($entryType, $allowedTypes, true)) {
            $errors[] = 'Entry type select karein.';
        }
        if ($amount <= 0) {
            $errors[] = 'Amount zero se zyada honi chahiye.';
        }
        if (empty($description)) {
            $errors[] = 'Description likhna zaruri hai.';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate)) {
            $errors[] = 'Date galat format mein hai.';
        }
        $allowedMethods = ['cash','card','bank_transfer','other'];
        if (!in_array($paymentMethod, $allowedMethods, true)) {
            $paymentMethod = 'cash';
        }

        if (empty($errors)) {
            $debit  = ($direction === 'debit')  ? $amount : 0.0;
            $credit = ($direction === 'credit') ? $amount : 0.0;

            // expense hamesha debit, opening hamesha credit, cash_in credit, cash_out debit, adjustment user choice
            if ($entryType === 'expense' || $entryType === 'cash_out') {
                $debit = $amount; $credit = 0.0;
            } elseif ($entryType === 'opening' || $entryType === 'cash_in') {
                $credit = $amount; $debit = 0.0;
            }

            $db = db();
            try {
                $db->beginTransaction();

                $newId = addCashbookEntry(
                    $entryType,
                    null,
                    'manual',
                    $description,
                    $debit,
                    $credit,
                    $paymentMethod,
                    (int)$_SESSION['user_id'],
                    $entryDate
                );

                if (!$newId) {
                    throw new Exception('Entry save nahi ho saki.');
                }

                logActivity('cashbook_entry', "Manual entry added: {$entryType} — {$description} — Rs {$amount}");

                $db->commit();

                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => 'Entry kamyabi se add ho gayi!']);
                    exit;
                }
                setFlash('success', 'Entry kamyabi se add ho gayi!', 'success');
                header("Location: index.php?date={$entryDate}");
                exit;

            } catch (Exception $e) {
                $db->rollBack();
                $errors[] = 'Error: ' . $e->getMessage();
            }
        }
    }

    if ($isAjax && !empty($errors)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
        exit;
    }
}

$pageTitle = 'Manual Entry Add Karo';
?>
<!DOCTYPE html>
<html lang="ur" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include dirname(__DIR__) . '/includes/page_head.php'; ?>

    
    

    <style>
        /* Page fade-in */
        @keyframes fadeInPage{from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:translateY(0);}}
        .main-content{ margin-left: var(--sb-w, 260px); padding: 20px; min-height: 100vh; transition: margin-left .25s ease; animation: fadeInPage 0.28s ease; }
        body.sb-col-body .main-content { margin-left: var(--sb-w-mini, 62px); }
        @media (max-width: 991.98px) { .main-content { margin-left: 0 !important; } }

        body { background: var(--pharmacy-body-bg, #f0f2f5); }
        .form-card {
            max-width: 560px;
            margin: 40px auto;
            border: 1px solid var(--pharmacy-border, #e5e7eb);
            border-radius: var(--card-border-radius, 16px);
            box-shadow: 0 4px 20px rgba(0,0,0,.08);
            background: var(--pharmacy-card-bg, #fff);
            transition: box-shadow 0.2s ease;
        }
        .form-card:hover { box-shadow: 0 6px 24px rgba(0,0,0,.13); }
        .form-card .card-header {
            background: linear-gradient(135deg, var(--pharmacy-primary, #2c3e50), var(--pharmacy-accent, #3498db));
            color: #fff;
            border-radius: calc(var(--card-border-radius, 16px) - 1px) calc(var(--card-border-radius, 16px) - 1px) 0 0;
            padding: 20px 24px;
            border-bottom: none;
        }
        .direction-toggle .btn { min-width: 110px; border-radius: 8px; transition: all 0.15s ease; }
        .form-label { font-weight: 500; font-size: .9rem; color: var(--pharmacy-heading, #111827); }
        .form-control, .form-select {
            border-radius: 8px;
            font-size: .9rem;
            border-color: var(--pharmacy-border, #d1d5db);
            background: var(--pharmacy-input-bg, #fff);
            color: var(--pharmacy-text, #111827);
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--pharmacy-primary, #3498db);
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--pharmacy-primary, #3498db) 15%, transparent);
        }
        .btn-submit {
            border-radius: 8px;
            padding: 10px 28px;
            font-weight: 600;
            letter-spacing: .3px;
            transition: all 0.2s ease;
        }
        /* Breadcrumb */
        .bc-bar {
            background: var(--pharmacy-card-bg, #fff);
            border: 1px solid var(--pharmacy-border, #e5e7eb);
            border-radius: 10px;
            padding: 10px 16px;
            margin-bottom: 20px;
            box-shadow: 0 1px 4px rgba(0,0,0,.05);
        }
        .bc-bar .breadcrumb { margin: 0; font-size: 0.82rem; }
        .bc-bar .breadcrumb-item a { color: var(--pharmacy-primary, #1a56db); text-decoration: none; }
        .bc-bar .breadcrumb-item.active { font-weight: 600; }
        /* Alert auto-visible */
        .alert { border-radius: 10px; }
    </style>

</head>
<?php
$__dm = !empty($_SESSION["company_settings_cache"]["dark_mode"]) ? " dark-mode" : "";
?>
<body class="<?php echo trim("bg-light" . $__dm); ?>">
<?php include '../includes/sidebar.php'; ?>
<?php include '../includes/navbar.php'; ?>

<div class="main-content p-3">

    <!-- Breadcrumb -->
    <div class="bc-bar">
        <ol class="breadcrumb">
            <li class="breadcrumb-item">
                <a href="<?php echo BASE_URL; ?>dashboard.php"><i class="bi bi-house me-1"></i>Dashboard</a>
            </li>
            <li class="breadcrumb-item">
                <a href="<?php echo BASE_URL; ?>cashbook/index.php">Cashbook</a>
            </li>
            <li class="breadcrumb-item active fw-semibold">Add Entry</li>
        </ol>
    </div>

    <div class="form-card card mx-auto">

        <!-- Header -->
        <div class="card-header">
            <div class="d-flex align-items-center gap-3">
                <i class="bi bi-pencil-square fs-4"></i>
                <div>
                    <h5 class="mb-0 fw-bold">Manual Entry</h5>
                    <small class="opacity-75">Cashbook mein naya record add karein</small>
                </div>
            </div>
        </div>

        <div class="card-body p-4">

            <!-- Errors -->
            <?php if (!empty($errors)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <ul class="mb-0 ps-3">
                    <?php foreach ($errors as $err): ?>
                    <li><?= htmlspecialchars($err) ?></li>
                    <?php endforeach; ?>
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Form -->
            <div id="entryAlertArea" class="mb-3"></div>
            <form id="entryForm" method="POST" action=""
                  data-pharm-form
                  data-success-msg="Entry kamyabi se add ho gayi!"
                  data-redirect="index.php"
                  data-redirect-delay="1500"
                  data-loading-text="Saving Entry…"
                  data-alert-target="#entryAlertArea"
                  novalidate>
                <input type="hidden" id="csrf_token" name="csrf_token"
                       value="<?= htmlspecialchars($csrfToken) ?>">

                <!-- Entry Type -->
                <div class="mb-3">
                    <label class="form-label" for="entry_type">
                        <i class="bi bi-tag me-1 text-primary"></i>Entry Type
                    </label>
                    <select class="form-select" id="entry_type" name="entry_type" required>
                        <option value="">— Type select karein —</option>
                        <option value="cash_in"    <?= (($_POST['entry_type'] ?? '') === 'cash_in')    ? 'selected' : '' ?>>Cash In (رقم آئی)</option>
                        <option value="cash_out"   <?= (($_POST['entry_type'] ?? '') === 'cash_out')   ? 'selected' : '' ?>>Cash Out (رقم گئی)</option>
                        <option value="expense"    <?= (($_POST['entry_type'] ?? '') === 'expense')    ? 'selected' : '' ?>>Expense (خرچہ)</option>
                        <option value="opening"    <?= (($_POST['entry_type'] ?? '') === 'opening')    ? 'selected' : '' ?>>Opening Balance</option>
                        <option value="adjustment" <?= (($_POST['entry_type'] ?? '') === 'adjustment') ? 'selected' : '' ?>>Adjustment</option>
                    </select>
                </div>

                <!-- Direction (only for adjustment) -->
                <div class="mb-3" id="directionRow" style="display:none">
                    <label class="form-label">
                        <i class="bi bi-arrow-left-right me-1 text-primary"></i>Direction
                    </label>
                    <div class="direction-toggle d-flex gap-2">
                        <input type="radio" class="btn-check" name="direction" id="dir_credit"
                               value="credit" <?= (($_POST['direction'] ?? 'credit') === 'credit') ? 'checked' : '' ?>>
                        <label class="btn btn-outline-success" for="dir_credit">
                            <i class="bi bi-arrow-down-circle me-1"></i>Cash In (Credit)
                        </label>

                        <input type="radio" class="btn-check" name="direction" id="dir_debit"
                               value="debit" <?= (($_POST['direction'] ?? '') === 'debit') ? 'checked' : '' ?>>
                        <label class="btn btn-outline-danger" for="dir_debit">
                            <i class="bi bi-arrow-up-circle me-1"></i>Cash Out (Debit)
                        </label>
                    </div>
                </div>

                <!-- Amount -->
                <div class="mb-3">
                    <label class="form-label" for="amount">
                        <i class="bi bi-currency-exchange me-1 text-primary"></i>Amount (Rs)
                    </label>
                    <input type="number"
                           class="form-control"
                           id="amount"
                           name="amount"
                           min="0.01"
                           step="0.01"
                           placeholder="0.00"
                           value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>"
                           required>
                </div>

                <!-- Description -->
                <div class="mb-3">
                    <label class="form-label" for="description">
                        <i class="bi bi-card-text me-1 text-primary"></i>Description
                    </label>
                    <textarea class="form-control"
                              id="description"
                              name="description"
                              rows="3"
                              maxlength="500"
                              placeholder="Entry ki wazahat likhein..."
                              required><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                </div>

                <!-- Payment Method -->
                <div class="mb-3">
                    <label class="form-label" for="payment_method">
                        <i class="bi bi-wallet me-1 text-primary"></i>Payment Method
                    </label>
                    <select class="form-select" id="payment_method" name="payment_method">
                        <?php
                        $methods = [
                            'cash'          => 'Cash (نقد)',
                            'card'          => 'Card',
                            'bank_transfer' => 'Bank Transfer',
                            'other'         => 'Other',
                        ];
                        foreach ($methods as $val => $label):
                            $sel = (($_POST['payment_method'] ?? 'cash') === $val) ? 'selected' : '';
                        ?>
                        <option value="<?= $val ?>" <?= $sel ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Date -->
                <div class="mb-4">
                    <label class="form-label" for="entry_date">
                        <i class="bi bi-calendar3 me-1 text-primary"></i>Date
                    </label>
                    <input type="date"
                           class="form-control"
                           id="entry_date"
                           name="entry_date"
                           value="<?= htmlspecialchars($_POST['entry_date'] ?? $returnDate) ?>"
                           max="<?= date('Y-m-d') ?>"
                           required>
                </div>

                <!-- Actions -->
                <div class="d-flex gap-2 justify-content-end">
                    <a href="index.php?date=<?= urlencode($returnDate) ?>"
                       class="btn btn-outline-secondary btn-submit">
                        <i class="bi bi-x-lg me-1"></i>Cancel
                    </a>
                    <button type="submit" data-submit-btn class="btn btn-primary btn-submit">
                        <i class="bi bi-check-lg me-1"></i>Save Entry
                    </button>
                </div>
            </form><!-- /entryForm -->

        </div><!-- /card-body -->
    </div><!-- /form-card -->

</div><!-- /main-content -->


<script>
// ── Auto-dismiss alerts after 5 seconds ──
document.querySelectorAll('.alert:not(.alert-permanent)').forEach(function(el){
    setTimeout(function(){
        var bsAlert = bootstrap.Alert.getOrCreateInstance(el);
        if(bsAlert) bsAlert.close();
    }, 5000);
});

// Direction row: sirf adjustment ke liye dikhao
const entryTypeSelect = document.getElementById('entry_type');
const directionRow    = document.getElementById('directionRow');

function toggleDirectionRow() {
    directionRow.style.display =
        entryTypeSelect.value === 'adjustment' ? 'block' : 'none';
}
entryTypeSelect.addEventListener('change', toggleDirectionRow);
toggleDirectionRow(); // page load par bhi check karo

// Form submit validation
document.getElementById('entryForm').addEventListener('submit', function(e) {
    const entryType = document.getElementById('entry_type').value;
    const amount    = parseFloat(document.getElementById('amount').value);
    const desc      = document.getElementById('description').value.trim();

    if (!entryType) { e.preventDefault(); Notify.warning('Entry type select karein.'); return; }
    if (!amount || amount <= 0) { e.preventDefault(); Notify.warning('Amount valid likhein.'); return; }
    if (!desc) { e.preventDefault(); Notify.warning('Description likhna zaruri hai.'); return; }
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
