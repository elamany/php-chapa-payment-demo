<?php
require __DIR__ . '/config.php';

$csrf  = csrfToken();
$asset = 'assets';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Donate — Support This Project</title>
    <meta name="description" content="Support our platform with a donation via Chapa.">
    <meta name="robots" content="index,follow">
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.2/css/all.min.css"
          crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="<?= $asset ?>/css/donate.css?v=1">
</head>
<body>

<div class="don-wrap">

    <header class="don-head">
        <span class="don-eyebrow">Donate</span>
        <h1>Support this project</h1>
    </header>

    <form id="donForm" class="don-form" novalidate>
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
        <input type="hidden" name="final_amount" id="donFinalAmount" value="">

        <!-- Amount -->
        <section class="don-block">
            <h2 class="don-block__title">Amount</h2>

            <div class="don-amounts" role="radiogroup" aria-label="Donation amount">
                <label class="don-amount">
                    <input type="radio" name="preset_amount" value="50" class="don-amount__input">
                    <span class="don-amount__text">50 ETB</span>
                </label>
                <label class="don-amount">
                    <input type="radio" name="preset_amount" value="100" class="don-amount__input">
                    <span class="don-amount__text">100 ETB</span>
                </label>
                <label class="don-amount">
                    <input type="radio" name="preset_amount" value="500" class="don-amount__input">
                    <span class="don-amount__text">500 ETB</span>
                </label>
                <label class="don-amount">
                    <input type="radio" name="preset_amount" value="1000" class="don-amount__input">
                    <span class="don-amount__text">1,000 ETB</span>
                </label>
                <label class="don-amount">
                    <input type="radio" name="preset_amount" value="custom" class="don-amount__input" id="donCustomRadio">
                    <span class="don-amount__text">Custom</span>
                </label>
            </div>

            <div class="don-custom" id="donCustomWrap" hidden>
                <input type="number"
                       id="donCustomInput"
                       name="custom_amount"
                       class="don-input"
                       placeholder="Enter any amount (min 10)"
                       min="10"
                       step="1"
                       inputmode="numeric"
                       autocomplete="off">
                <span class="don-custom__unit">ETB</span>
            </div>
        </section>

        <!-- Contact -->
        <section class="don-block">
            <h2 class="don-block__title">Your details</h2>

            <div class="don-field">
                <label class="don-field__label" for="donEmail">
                    Email <span class="don-field__note">(needed for your Chapa receipt)</span>
                </label>
                <input type="email"
                       id="donEmail"
                       name="email"
                       class="don-input"
                       maxlength="255"
                       placeholder="you@example.com"
                       autocomplete="email"
                       required>
            </div>

            <div class="don-field" id="donNameGroup">
                <label class="don-field__label" for="donName">
                    Name <span class="don-field__note" id="donNameNote">(required)</span>
                </label>
                <input type="text"
                       id="donName"
                       name="full_name"
                       class="don-input"
                       maxlength="150"
                       placeholder="Shown on your receipt"
                       autocomplete="name"
                       required>
            </div>

            <label class="don-check">
                <input type="checkbox" id="donAnonymous" name="is_anonymous" value="1">
                <span>Donate anonymously — hide my name from any public display</span>
            </label>
        </section>

        <div id="donNotice" class="don-notice" hidden></div>

        <button type="submit" class="don-submit" id="donSubmit">
            <i class="fa-solid fa-lock" aria-hidden="true"></i>
            <span id="donSubmitLabel">Continue to payment</span>
        </button>
    </form>
</div>

<div class="don-loading" id="donLoading" hidden>
    <div class="don-loading__box">
        <i class="fa-solid fa-spinner fa-spin don-loading__icon" aria-hidden="true"></i>
        <p class="don-loading__title" id="donLoadingTitle">Opening secure checkout…</p>
    </div>
</div>

<script src="<?= $asset ?>/js/donate.js?v=1" defer></script>
</body>
</html>