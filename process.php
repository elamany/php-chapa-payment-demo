<?php
/**
 * POST process.php
 * Validates, inserts a pending row, initialises Chapa,
 * stores the Chapa ref + checkout_url, returns { ok, checkout_url }.
 *
 * Rules:
 *   - email required (always)
 *   - name required by default; ignored when is_anonymous=1
 */

require __DIR__ . '/config.php';

header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

if (!throttle('donate_process', 3)) {
    jsonOut(['ok' => false, 'error' => 'Please wait a moment before trying again.'], 429);
}

if (!csrfValidate($_POST['csrf'] ?? null)) {
    jsonOut(['ok' => false, 'error' => 'Session expired. Please refresh the page.'], 400);
}

/* ---------- Input ---------- */
$amount = (float)($_POST['amount'] ?? 0);
$email  = trim((string)($_POST['email'] ?? ''));
$name   = trim((string)($_POST['full_name'] ?? ''));
$isAnon = (($_POST['is_anonymous'] ?? '0') === '1');

/* ---------- Name rules ---------- */
if ($isAnon) {
    $name = 'Anonymous';
} else {
    if ($name === '') {
        jsonOut(['ok' => false, 'error' => 'Please enter your name, or donate anonymously.'], 400);
    }
    if (mb_strlen($name) < 2) {
        jsonOut(['ok' => false, 'error' => 'Name looks too short.'], 400);
    }
}
$name = mb_substr(strip_tags($name), 0, 150);

/* ---------- Validate ---------- */
if ($amount < 10) {
    jsonOut(['ok' => false, 'error' => 'Minimum donation is 10 ETB.'], 400);
}
if ($amount > 1_000_000) {
    jsonOut(['ok' => false, 'error' => 'Please enter an amount below 1,000,000 ETB.'], 400);
}
$amount = round($amount);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonOut(['ok' => false, 'error' => 'Please enter a valid email address.'], 400);
}

if (!env('CHAPA_SECRET_KEY')) {
    error_log('[process] CHAPA_SECRET_KEY is not set');
    jsonOut(['ok' => false, 'error' => 'Payment provider is not configured.'], 500);
}

/* ---------- tx_ref ---------- */
$txRef = 'DON-' . date('ymdHis') . '-' . bin2hex(random_bytes(4));
$base  = baseUrl();
$ip    = clientIp();

/* ---------- Insert pending row ---------- */
try {
    $stmt = db()->prepare(
        "INSERT INTO donations
            (tx_ref, amount, currency, email, full_name, is_anonymous, status, ip)
         VALUES (?, ?, 'ETB', ?, ?, ?, 'pending', ?)"
    );
    $stmt->execute([$txRef, $amount, $email, $name, $isAnon ? 1 : 0, $ip ?: null]);
} catch (Throwable $e) {
    error_log('[process] insert failed: ' . $e->getMessage());
    jsonOut(['ok' => false, 'error' => 'Could not save donation. Please try again.'], 500);
}

/* ---------- Init Chapa ---------- */
$payload = [
    'amount'        => (float)$amount,
    'currency'      => 'ETB',
    'email'         => $email,
    'first_name'    => $name,
    'last_name'     => '',
    'tx_ref'        => $txRef,
    'callback_url'  => $base . '/callback',
    'return_url'    => $base . '/return?tx_ref=' . urlencode($txRef),
    'customization' => [
        'title'       => 'Donate',
        'description' => 'Support our platform',
    ],
];

$resp = chapaRequest('transaction/initialize', $payload);

/* Mark row failed if init blew up — keeps the audit trail honest */
$fail = function (string $msg, int $code = 502) use ($txRef): void {
    try {
        db()->prepare("UPDATE donations SET status='failed', error=? WHERE tx_ref=? AND status='pending'")
            ->execute([$msg, $txRef]);
    } catch (Throwable $e) {
        error_log('[process] fail-mark failed: ' . $e->getMessage());
    }
    jsonOut(['ok' => false, 'error' => $msg], $code);
};

if ($resp === null) {
    $fail('Payment provider unreachable. Please try again in a moment.');
}

if ($resp['code'] >= 400 || empty($resp['body']['data']['checkout_url'])) {
    $msg = $resp['body']['message'] ?? ('HTTP ' . $resp['code']);
    if (is_array($msg)) {
        $flat = [];
        array_walk_recursive($msg, function ($v) use (&$flat) { $flat[] = (string)$v; });
        $msg = implode(' ', $flat) ?: 'Unknown error';
    }
    error_log('[process] Chapa error: ' . $msg);
    $fail('Could not start payment: ' . $msg);
}

/* ---------- Persist Chapa ref + checkout URL right now ---------- */
$checkoutUrl = (string)$resp['body']['data']['checkout_url'];
$chapaRef    = extractChapaRefFromInit($resp['body']) ?? '';

try {
    db()->prepare(
        "UPDATE donations
            SET chapa_ref = ?, checkout_url = ?, raw_init = ?
          WHERE tx_ref = ?"
    )->execute([
        $chapaRef,
        $checkoutUrl,
        json_encode($resp['body'], JSON_UNESCAPED_SLASHES),
        $txRef,
    ]);
} catch (Throwable $e) {
    /* Non-fatal: the row already exists, the user can still pay.
       On verify success we'll fill chapa_ref in from `data.reference`. */
    error_log('[process] post-init update failed: ' . $e->getMessage());
}

jsonOut([
    'ok'           => true,
    'checkout_url' => $checkoutUrl,
    'tx_ref'       => $txRef,
    'chapa_ref'    => $chapaRef,
]);