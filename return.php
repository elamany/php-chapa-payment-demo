<?php
/**
 * GET return.php?tx_ref=...
 * Loads the row, verifies with Chapa if not already paid,
 * persists the result, then renders the outcome from the DB.
 */

require __DIR__ . '/config.php';

$txRef  = trim((string)($_GET['tx_ref'] ?? ''));
$status = 'unknown';
$amount = 0.0;
$isAnon = false;
$row    = null;

if ($txRef !== '' && validTxRef($txRef)) {
    try {
        $stmt = db()->prepare(
            "SELECT id, tx_ref, amount, currency, email, full_name, is_anonymous, status
               FROM donations WHERE tx_ref = ? LIMIT 1"
        );
        $stmt->execute([$txRef]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        error_log('[return] lookup failed: ' . $e->getMessage());
    }
}


if ($row) {
    $amount = (float)$row['amount'];
    $isAnon = (bool)$row['is_anonymous'];
    $status = (string)$row['status'];

    if ($status !== 'paid' && env('CHAPA_SECRET_KEY')) {
        $resp = chapaRequest('transaction/verify/' . rawurlencode($txRef));

        if ($resp !== null) {
            error_log('[return] verify HTTP=' . $resp['code']
                    . ' body=' . substr(json_encode($resp['body']), 0, 500));

            /* applyVerifyResult returns the actual DB status after the update */
            $status = applyVerifyResult($txRef, $resp['body'], $status);
        } else {
            error_log('[return] verify transport failure for ' . $txRef);
        }
    }
} elseif ($txRef !== '') {
    $status = 'unknown';
}

$donateUrl = 'index.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Donation Status</title>
    <meta name="robots" content="noindex,nofollow">
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.2/css/all.min.css"
          crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="assets/css/donate.css?v=1">
</head>
<body>
<div class="don-wrap">
    <div class="don-result">

        <?php if ($status === 'paid'): ?>
            <div class="don-result__icon don-result__icon--ok">
                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
            </div>
            <h1 class="don-result__title">Thank you for your donation</h1>
            <p class="don-result__lead">
                We've received <strong><?= htmlspecialchars(number_format($amount)) ?> ETB</strong>.
                A Chapa receipt is on its way to your email.
            </p>
            <p class="don-result__ref">Reference: <code><?= htmlspecialchars($txRef) ?></code></p>
            <?php if ($isAnon): ?>
                <p class="don-result__lead">Recorded as an anonymous donation.</p>
            <?php endif; ?>
            <a class="don-result__btn don-result__btn--primary" href="<?= htmlspecialchars($donateUrl) ?>">
                <i class="fa-solid fa-check" aria-hidden="true"></i> Done
            </a>

        <?php elseif ($status === 'pending'): ?>
            <div class="don-result__icon don-result__icon--pending">
                <i class="fa-solid fa-clock" aria-hidden="true"></i>
            </div>
            <h1 class="don-result__title">Waiting on confirmation</h1>
            <p class="don-result__lead">
                Your payment hasn't been confirmed yet. If you completed the payment,
                you'll get a Chapa receipt as soon as it clears.
            </p>
            <p class="don-result__ref">Reference: <code><?= htmlspecialchars($txRef) ?></code></p>
            <a class="don-result__btn don-result__btn--ghost" href="<?= htmlspecialchars($donateUrl) ?>">
                <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to donate
            </a>

        <?php elseif ($status === 'failed'): ?>
            <div class="don-result__icon don-result__icon--fail">
                <i class="fa-solid fa-circle-xmark" aria-hidden="true"></i>
            </div>
            <h1 class="don-result__title">Payment didn't go through</h1>
            <p class="don-result__lead">
                Chapa reported the transaction as failed. Nothing has been charged.
            </p>
            <a class="don-result__btn don-result__btn--primary" href="<?= htmlspecialchars($donateUrl) ?>">
                <i class="fa-solid fa-rotate-right" aria-hidden="true"></i> Try again
            </a>

        <?php else: ?>
            <div class="don-result__icon don-result__icon--fail">
                <i class="fa-solid fa-circle-question" aria-hidden="true"></i>
            </div>
            <h1 class="don-result__title">We couldn't find that donation</h1>
            <p class="don-result__lead">
                The reference in the URL doesn't match any transaction we can verify.
            </p>
            <a class="don-result__btn don-result__btn--primary" href="<?= htmlspecialchars($donateUrl) ?>">
                <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to donate
            </a>
        <?php endif; ?>

    </div>
</div>
</body>
</html>