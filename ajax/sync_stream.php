<?php
// === FILE: pharmacy_software/ajax/sync_stream.php ===
/**
 * STEP 8 — Server-Sent Events (SSE) Stream Endpoint
 * Streams new sync_events rows to connected browsers in real-time.
 * No internet required — works on local LAN / same machine (XAMPP/WAMP).
 *
 * Usage: new EventSource('../ajax/sync_stream.php')
 * Client receives: data: {"event_type":"sale_completed","payload":{...}}\n\n
 */

// ── Bootstrap ─────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
requireLogin();
require_once '../includes/security.php';
requireCSRF();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

// Must be logged in — send error event and close if not
if (!isLoggedIn()) {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    echo "event: auth_error\ndata: {\"error\":\"not_authenticated\"}\n\n";
    flush();
    exit();
}

// ── SSE Headers ───────────────────────────────────────────────────────────
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no');   // Disable nginx buffering if present
header('Connection: keep-alive');

// ── PHP Runtime Config ────────────────────────────────────────────────────
set_time_limit(0);          // Run indefinitely
ignore_user_abort(true);    // Detect when client disconnects
ob_implicit_flush(true);    // Flush output immediately
if (ob_get_level()) {
    ob_end_clean();
}

// ── Determine last event ID (for resuming after reconnect) ────────────────
// Browser sends Last-Event-ID header on reconnect
$last_id = 0;
if (!empty($_SERVER['HTTP_LAST_EVENT_ID'])) {
    $last_id = (int) $_SERVER['HTTP_LAST_EVENT_ID'];
} elseif (!empty($_GET['lastEventId'])) {
    $last_id = (int) $_GET['lastEventId'];
}

// ── Helper: send one SSE message ──────────────────────────────────────────
function sendSseEvent(int $id, string $event_type, string $payload_json): void
{
    echo "id: {$id}\n";
    echo "event: {$event_type}\n";
    echo "data: {$payload_json}\n\n";
    // flush() is automatic due to ob_implicit_flush(true)
    // but call explicitly as a safety net
    if (function_exists('fastcgi_finish_request')) {
        // Not applicable for streaming — skip
    }
    flush();
}

// ── Helper: send keepalive comment ────────────────────────────────────────
function sendKeepAlive(): void
{
    echo ": keepalive " . time() . "\n\n";
    flush();
}

// ── Send initial connection confirmation ──────────────────────────────────
sendSseEvent(0, 'connected', json_encode([
    'message'   => 'SSE stream connected',
    'last_id'   => $last_id,
    'server_time' => date('Y-m-d H:i:s'),
]));

// ── Main polling loop ─────────────────────────────────────────────────────
$poll_interval  = 2;   // seconds between DB polls
$keepalive_every = 15; // send keepalive comment every N seconds
$last_keepalive  = time();

try {
    $pdo = db()->getConnection();
} catch (\Throwable $e) {
    sendSseEvent(0, 'error', json_encode(['error' => 'db_connection_failed']));
    exit();
}

while (true) {

    // ── Check if client disconnected ──────────────────────────────────────
    if (connection_aborted()) {
        break;
    }

    // ── Poll sync_events for rows newer than last_id ───────────────────────
    try {
        $stmt = $pdo->prepare(
            "SELECT id, event_type, payload, created_at
             FROM sync_events
             WHERE id > :last_id
             ORDER BY id ASC
             LIMIT 20"
        );
        $stmt->execute([':last_id' => $last_id]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $event_id   = (int) $row['id'];
            $event_type = $row['event_type'];

            // Merge created_at into payload for client timestamp display
            $payload = json_decode($row['payload'], true) ?? [];
            $payload['_server_time'] = $row['created_at'];
            $payload_json = json_encode($payload);

            sendSseEvent($event_id, $event_type, $payload_json);
            $last_id = $event_id;
        }

    } catch (\Throwable $e) {
        // DB hiccup — send error event but keep loop running
        sendSseEvent(0, 'error', json_encode(['error' => 'db_poll_failed']));
        error_log('[sync_stream] Poll error: ' . $e->getMessage());
    }

    // ── Keepalive ping ────────────────────────────────────────────────────
    if ((time() - $last_keepalive) >= $keepalive_every) {
        sendKeepAlive();
        $last_keepalive = time();
    }

    // ── Sleep before next poll ────────────────────────────────────────────
    sleep($poll_interval);
}