(function () {
    'use strict';

    var form         = document.getElementById('donForm');
    if (!form) return;

    var presetInputs = form.querySelectorAll('input[name="preset_amount"]');
    var customInput  = document.getElementById('donCustomInput');
    var hidden       = document.getElementById('donFinalAmount');
    var notice       = document.getElementById('donNotice');
    var submitBtn    = document.getElementById('donSubmit');
    var submitLabel  = document.getElementById('donSubmitLabel');
    var anonBox      = document.getElementById('donAnonymous');
    var emailInput   = document.getElementById('donEmail');
    var nameGroup    = document.getElementById('donNameGroup');
    var nameInput    = document.getElementById('donName');
    var nameNote     = document.getElementById('donNameNote');
    var customRadio  = document.getElementById('donCustomRadio');
    var customWrap   = document.getElementById('donCustomWrap');
    var loading      = document.getElementById('donLoading');

    var MIN = 10;
    var MAX = 1000000;

    function show(msg) {
        notice.textContent = msg;
        notice.hidden = false;
    }
    function clear() {
        notice.hidden = true;
        notice.textContent = '';
    }

    /* ----------------------------------------------------------
     * Anonymity:
     *  - checked   → name field hidden, NOT required
     *  - unchecked → name field visible and REQUIRED (default)
     * ---------------------------------------------------------- */
    function syncAnon() {
        var anon = anonBox.checked;

        nameGroup.style.display = anon ? 'none' : '';
        nameInput.required      = !anon;
        nameNote.textContent    = anon ? '' : '(required)';

        if (anon) nameInput.value = '';
    }
    anonBox.addEventListener('change', syncAnon);
    syncAnon();

    /* Amount pills */
    presetInputs.forEach(function (r) {
        r.addEventListener('change', function () {
            if (!r.checked) return;
            clear();

            if (r === customRadio) {
                customWrap.hidden = false;
                hidden.value = customInput.value || '';
                setTimeout(function () { customInput.focus(); }, 30);
            } else {
                customWrap.hidden = true;
                customInput.value = '';
                hidden.value = r.value;
            }
        });
    });

    customInput.addEventListener('input', function () {
        if (customInput.value !== '') {
            customRadio.checked = true;
            hidden.value = customInput.value;
        } else {
            hidden.value = '';
        }
        clear();
    });

    /* Submit */
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        clear();

        var amount = parseFloat(hidden.value);
        var anon   = anonBox.checked;
        var email  = (emailInput.value || '').trim();
        var name   = (nameInput.value || '').trim();

        if (!amount || isNaN(amount)) {
            show('Choose an amount or enter a custom one.');
            customInput.focus();
            return;
        }
        if (amount < MIN) {
            show('Minimum donation is ' + MIN + ' ETB.');
            customInput.focus();
            return;
        }
        if (amount > MAX) {
            show('Please enter an amount below 1,000,000 ETB.');
            return;
        }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            show('Please enter a valid email address.');
            emailInput.focus();
            return;
        }
        if (!anon && name === '') {
            show('Please enter your name, or tick "Donate anonymously".');
            nameInput.focus();
            return;
        }

        submitBtn.disabled = true;
        submitLabel.textContent = 'Opening Chapa…';
        loading.hidden = false;
        document.body.classList.add('don-loading-active');

        var fd = new FormData();
        fd.append('csrf',         form.querySelector('input[name="csrf"]').value);
        fd.append('amount',       amount);
        fd.append('email',        email);
        fd.append('full_name',    anon ? '' : name);
        fd.append('is_anonymous', anon ? '1' : '0');

        fetch('process', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (r) {
            return r.json().catch(function () {
                return { ok: false, error: 'Network error.' };
            });
        })
        .then(function (data) {
            if (!data || !data.ok || !data.checkout_url) {
                throw new Error((data && data.error) || 'Could not start the payment.');
            }
            window.location.href = data.checkout_url;
        })
        .catch(function (err) {
            loading.hidden = true;
            document.body.classList.remove('don-loading-active');
            show(err.message || 'Something went wrong. Try again.');
            submitBtn.disabled = false;
            submitLabel.textContent = 'Continue to payment';
        });
    });
})();