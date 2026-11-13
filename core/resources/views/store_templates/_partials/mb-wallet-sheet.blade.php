{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    In-app "Add funds" sheet — keeps amount + gateway selection inside the
    storefront (no full-page break); only the actual payment step redirects to
    the gateway. Reuses the .mba-* skin classes the theme already defines for the
    auth sheet, so it inherits each theme's palette with no extra skinning.

    Opens via window.mbWalletOpen() or any [data-mb-wallet-open] control. Fetches
    balance + installed gateways from publicView.wallet.balance on open. The
    Continue button submits a real form to publicView.wallet.topup.start, which
    hands off to the chosen gateway (the one unavoidable redirect).

    Rendered only for a signed-in customer at a customer_wallet-enabled store
    (the includer gates it), alongside mb-auth-sheet so the shared .mba-* CSS is
    always present.
--}}
<div class="mba-scrim" id="mbwScrim" hidden>
    <div class="mba-sheet" role="dialog" aria-modal="true" aria-labelledby="mbwTitle" tabindex="-1">
        <button type="button" class="mba-x" id="mbwClose" aria-label="{{ ___('Close') }}">&times;</button>
        <h2 class="mba-title" id="mbwTitle">{{ ___('Add funds') }}</h2>

        <div class="mbw-bal" id="mbwBal" aria-live="polite">
            <span class="mbw-bal-k">{{ ___('Wallet balance') }}</span>
            <b id="mbwBalAmt">&nbsp;</b>
        </div>

        <p class="mba-msg mba-err" id="mbwErr" role="alert" hidden></p>

        <form class="mba-form" id="mbwForm" method="POST" action="{{ route('publicView.wallet.topup.start', $store->id) }}">
            @csrf
            <label class="mbw-lbl">{{ ___('Amount') }}</label>
            <div class="mbw-amts" id="mbwAmts"></div>
            <input class="mba-in" type="number" name="amount" id="mbwAmount" min="1" max="100000" step="{{ \Juwri\Core\Money\AmountInput::step(store_options($store->id, 'currency_code') ?: null) }}"
                   inputmode="decimal" placeholder="{{ ___('Enter amount') }}" required>

            <label class="mbw-lbl">{{ ___('Payment method') }}</label>
            <div class="mbw-gws" id="mbwGws"></div>

            <button type="submit" class="mba-btn" id="mbwSubmit">{{ ___('Continue to payment') }}</button>
        </form>
        <p class="mbw-note">{{ ___('You will be redirected to your payment provider to complete the top-up securely.') }}</p>
    </div>
</div>

@include('store_templates._partials.mb-sheet-base')
<style nonce="{{ csp_nonce() }}">
    /* Subtle accent tint (works on any accent incl. near-white/near-black) rather
       than a solid accent fill, so the banner never looks stark on a theme whose
       accent is white/black. */
    .mbw-bal{display:flex;align-items:center;justify-content:space-between;gap:12px;
        background:color-mix(in srgb, var(--mba-accent,#6b4ea8) 12%, transparent);
        border:1px solid var(--mba-border,rgba(0,0,0,.1));
        color:var(--mba-ink,#1a1c22);border-radius:14px;padding:14px 16px;margin-bottom:16px}
    .mbw-bal-k{font-size:12.5px;text-transform:uppercase;letter-spacing:.05em;color:var(--mba-muted,#8a8f9c)}
    .mbw-bal b{font-size:1.5rem;font-weight:800;color:var(--mba-ink,#1a1c22)}
    .mbw-lbl{display:block;font-weight:600;font-size:13px;margin:4px 0 8px}
    .mbw-amts{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:10px}
    .mbw-amt{border:1px solid var(--mba-border,rgba(0,0,0,.14));background:transparent;color:var(--mba-ink,#1a1c22);
        border-radius:10px;padding:11px 4px;font:inherit;font-weight:700;cursor:pointer}
    .mbw-amt.is-on{border-color:var(--mba-accent,#6b4ea8);background:var(--mba-accent-ring,rgba(107,78,168,.14));color:var(--mba-accent,#6b4ea8)}
    .mbw-gws{display:flex;flex-direction:column;gap:8px;margin:0 0 16px}
    .mbw-gws label{display:flex;align-items:center;gap:10px;border:1px solid var(--mba-border,rgba(0,0,0,.14));
        border-radius:12px;padding:12px 14px;font-weight:600;cursor:pointer;margin:0;color:var(--mba-ink,#1a1c22)}
    .mbw-gws input{accent-color:var(--mba-accent,#6b4ea8);width:18px;height:18px}
    .mbw-note{color:var(--mba-muted,#8a8f9c);font-size:12px;text-align:center;margin-top:12px}
</style>

<script nonce="{{ csp_nonce() }}">
(function () {
    var scrim = document.getElementById('mbwScrim');
    if (!scrim) return;
    var cfg = { balanceUrl: @json(route('publicView.wallet.balance', $store->id)) };
    var sheet = scrim.querySelector('.mba-sheet');
    var errEl = document.getElementById('mbwErr');
    var amtsEl = document.getElementById('mbwAmts');
    var gwsEl = document.getElementById('mbwGws');
    var amount = document.getElementById('mbwAmount');
    var balAmt = document.getElementById('mbwBalAmt');
    var submit = document.getElementById('mbwSubmit');
    var form = document.getElementById('mbwForm');
    var lastFocus = null, loaded = false;

    function showErr(m) { errEl.textContent = m; errEl.hidden = false; }
    function money(sym, n) { return sym + Number(n || 0).toFixed(2); }

    function open() {
        errEl.hidden = true;
        lastFocus = document.activeElement;
        scrim.hidden = false;
        requestAnimationFrame(function () { scrim.classList.add('is-open'); });
        sheet.focus();
        document.addEventListener('keydown', onKey);
        load();
    }
    function close() {
        scrim.classList.remove('is-open');
        document.removeEventListener('keydown', onKey);
        setTimeout(function () { scrim.hidden = true; }, 200);
        if (lastFocus && lastFocus.focus) lastFocus.focus();
    }
    function onKey(e) { if (e.key === 'Escape') close(); }

    function load() {
        if (loaded) return;
        fetch(cfg.balanceUrl, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                loaded = true;
                var sym = d.symbol || '';
                balAmt.textContent = money(sym, d.balance);
                (d.presets || []).forEach(function (p) {
                    var b = document.createElement('button');
                    b.type = 'button'; b.className = 'mbw-amt'; b.textContent = money(sym, p).replace('.00', '');
                    b.setAttribute('data-amt', p);
                    b.addEventListener('click', function () {
                        [].forEach.call(amtsEl.children, function (x) { x.classList.remove('is-on'); });
                        b.classList.add('is-on'); amount.value = p;
                    });
                    amtsEl.appendChild(b);
                });
                if (!(d.gateways || []).length) {
                    showErr(@json(___('No payment method is available right now.')));
                    submit.disabled = true;
                    return;
                }
                d.gateways.forEach(function (g, i) {
                    var lab = document.createElement('label');
                    var inp = document.createElement('input');
                    inp.type = 'radio'; inp.name = 'payment_method'; inp.value = g.id; if (i === 0) inp.checked = true;
                    var sp = document.createElement('span'); sp.textContent = g.title;
                    lab.appendChild(inp); lab.appendChild(sp); gwsEl.appendChild(lab);
                });
            })
            .catch(function () { showErr(@json(___('Something went wrong. Please try again.'))); });
    }

    window.mbWalletOpen = open;
    document.addEventListener('click', function (e) {
        var o = e.target.closest('[data-mb-wallet-open]');
        if (o) { e.preventDefault(); open(); }
    });
    document.getElementById('mbwClose').addEventListener('click', close);
    scrim.addEventListener('click', function (e) { if (e.target === scrim) close(); });
    amount.addEventListener('input', function () {
        [].forEach.call(amtsEl.children, function (x) {
            x.classList.toggle('is-on', x.getAttribute('data-amt') === amount.value);
        });
    });
    form.addEventListener('submit', function () { submit.disabled = true; submit.textContent = @json(___('Redirecting…')); });
})();
</script>
