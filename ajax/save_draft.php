<?php
// === FILE: pharmacy_software/ajax/save_draft.php ===
/**
 * STEP 13 — Server-Side Crash Recovery
 * Saves / loads / deletes form drafts in the form_drafts DB table.
 * Called every 30 seconds by pages that include autosave.js.
 * Works on local server only — no internet required.
 *
 * Actions (POST param: action):
 *   save   — upsert draft_data for user+page_key
 *   load   — return saved draft for user+page_key
 *   delete — remove draft for user+page_key
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

// ── Auth check ────────────────────────────────────────────────────────────────
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'not_authenticated']);
    exit();
}

// ISS-016 FIX: Verify CSRF token on mutation endpoint
$csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!function_exists('verifyCSRFToken') || !verifyCSRFToken($csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid or missing CSRF token.']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$action = trim($_POST['action'] ?? $_GET['action'] ?? '');

// ── Validate page_key ─────────────────────────────────────────────────────────
$pageKey = trim($_POST['page_key'] ?? $_GET['page_key'] ?? '');
if (empty($pageKey) || strlen($pageKey) > 100) {
    echo json_encode(['success' => false, 'error' => 'invalid_page_key']);
    exit();
}
// Sanitize — allow only alphanumeric, underscore, hyphen
$pageKey = preg_replace('/[^a-zA-Z0-9_\-]/', '', $pageKey);

// ── Route action ──────────────────────────────────────────────────────────────
try {
    $pdo = db()->getConnection();

    switch ($action) {

        // ── SAVE ─────────────────────────────────────────────────────────────
        case 'save':
            $draftData = $_POST['draft_data'] ?? '';
            if (empty($draftData)) {
                echo json_encode(['success' => false, 'error' => 'empty_draft']);
                exit();
            }
            // Limit size to 1MB to prevent abuse
            if (strlen($draftData) > 1048576) {
                echo json_encode(['success' => false, 'error' => 'draft_too_large']);
                exit();
            }

            $stmt = $pdo->prepare(
                "INSERT INTO form_drafts (user_id, page_key, draft_data, saved_at)
                 VALUES (:uid, :key, :data, NOW())
                 ON DUPLICATE KEY UPDATE
                     draft_data = VALUES(draft_data),
                     saved_at   = NOW()"
            );
            $stmt->execute([
                ':uid'  => $userId,
                ':key'  => $pageKey,
                ':data' => $draftData,
            ]);

            echo json_encode([
                'success'  => true,
                'action'   => 'saved',
                'saved_at' => date('Y-m-d H:i:s'),
            ]);
            break;

        // ── LOAD ─────────────────────────────────────────────────────────────
        case 'load':
            $stmt = $pdo->prepare(
                "SELECT draft_data, saved_at
                 FROM form_drafts
                 WHERE user_id = :uid AND page_key = :key
                 LIMIT 1"
            );
            $stmt->execute([':uid' => $userId, ':key' => $pageKey]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($row) {
                echo json_encode([
                    'success'    => true,
                    'found'      => true,
                    'draft_data' => $row['draft_data'],
                    'saved_at'   => $row['saved_at'],
                ]);
            } else {
                echo json_encode(['success' => true, 'found' => false]);
            }
            break;

        // ── DELETE ────────────────────────────────────────────────────────────
        case 'delete':
            $stmt = $pdo->prepare(
                "DELETE FROM form_drafts
                 WHERE user_id = :uid AND page_key = :key"
            );
            $stmt->execute([':uid' => $userId, ':key' => $pageKey]);

            echo json_encode(['success' => true, 'action' => 'deleted']);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'unknown_action']);
            break;
    }

} catch (\Throwable $e) {
    error_log('[save_draft] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'server_error']);
}