{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Saudi Riyal (U+20C1) rollout — S10: shared currency-sign autofill + the SAR
     one-click button behaviour, for the owner store settings and admin platform
     settings. Emits the one authoritative ISO→symbol map and, when the merchant
     changes the currency code, fills the sign — but only when the sign is empty or
     still equals the previous code's mapped symbol, so a custom sign is never
     clobbered. --}}
<script nonce="{{ csp_nonce() }}">
    (function () {
        var MAP = @json(currency_symbol_map());
        var code = document.getElementById('currency_code') || document.querySelector('[name=currency_code]');
        var sign = document.getElementById('currency_sign') || document.querySelector('[name=currency_sign]');
        var btn = document.getElementById('mb-use-riyal-symbol');
        if (!code || !sign) { return; }

        function norm(v) { return (v || '').trim().toUpperCase(); }
        // Codes that own a new dedicated symbol whose glyph we ship a webfont for.
        var NEW_SIGN = { SAR: '⃁', AED: '⃃' };
        function syncBtn() {
            if (!btn) { return; }
            var c = norm(code.value);
            var on = Object.prototype.hasOwnProperty.call(NEW_SIGN, c);
            btn.style.display = on ? '' : 'none';
            if (on) {
                var g = btn.querySelector('.mb-use-sym-glyph');
                if (g) { g.textContent = NEW_SIGN[c]; }
            }
        }

        var prev = norm(code.value);

        code.addEventListener('input', function () {
            var next = norm(code.value);
            var auto = Object.prototype.hasOwnProperty.call(MAP, prev) ? MAP[prev] : '';
            // Autofill only when the sign is empty or still the previous code's
            // mapped symbol — never overwrite a sign the merchant typed themselves.
            if ((sign.value === '' || sign.value === auto) && Object.prototype.hasOwnProperty.call(MAP, next)) {
                sign.value = MAP[next];
                sign.dispatchEvent(new Event('input', { bubbles: true }));
            }
            prev = next;
            syncBtn();
        });

        if (btn) {
            btn.addEventListener('click', function () {
                var c = norm(code.value);
                // AED → U+20C3 dirham; everything else (SAR) → U+20C1 riyal.
                if (c === 'AED') { sign.value = '⃃'; }
                else { sign.value = '⃁'; }
                sign.dispatchEvent(new Event('input', { bubbles: true }));
            });
        }

        syncBtn();
    })();
</script>
