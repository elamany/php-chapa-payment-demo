<?php
/**
 * POST callback.php — Chapa server-to-server notification.
 * Loads the row, skips if already paid, else verifies + updates.
 * Returns ok:false (with 200) when the update could not be persisted,
 * so Chapa schedules a retry.
 */

require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
http_response_code(200);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'note' => 'POST required']);
    exit;
}

$raw   = (string)file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) $input = $_POST;

$txRef = trim((string)($input['trx_ref'] ?? $input['tx_ref'] ?? ''));

if ($txRef === '' || !validTxRef($txRef)) {
    error_log('[callback] missing/invalid trx_ref: ' . $raw);
    echo json_encode(['ok' => false, 'note' => 'invalid reference']);
    exit;
}

try {
    $stmt = db()->prepare("SELECT id, status FROM donations WHERE tx_ref = ? LIMIT 1");
    $stmt->execute([$txRef]);
    $row = $stmt->fetch();
} catch (Throwable $e) {
    error_log('[callback] lookup failed: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'note' => 'lookup failed']);
    exit;
}

if (!$row) {
    error_log('[callback] unknown tx_ref: ' . $txRef);
    /* ack so Chapa stops retrying — this row was never ours */
    echo json_encode(['ok' => true, 'note' => 'unknown tx_ref']);
    exit;
}

if ($row['status'] === 'paid') {
    echo json_encode(['ok' => true, 'note' => 'already paid']);
    exit;
}

/* ---------- Verify with Chapa ---------- */
$resp = chapaRequest('transaction/verify/' . rawurlencode($txRef));

if ($resp === null) {
    error_log('[callback] verify transport failure for ' . $txRef);
    echo json_encode(['ok' => false, 'note' => 'verify failed']);
    exit;
}

error_log('[callback] verify HTTP=' . $resp['code']
        . ' body=' . substr(json_encode($resp['body']), 0, 500));

$effective = applyVerifyResult($txRef, $resp['body'], (string)$row['status']);

/* Only ack if we actually persisted 'paid' */
$ok = ($effective === 'paid' || $effective === 'failed');

if (!$ok) {
    error_log('[callback] update did not stick, tx_ref=' . $txRef . ' effective=' . $effective);
    echo json_encode(['ok' => false, 'note' => 'not persisted', 'status' => $effective]);
    exit;
}

echo json_encode(['ok' => true, 'status' => $effective]);