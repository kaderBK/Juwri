{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  Shared delivery-address autocomplete — the same server-proxied geocode crave
  uses (routes geo.autocomplete / geo.reverse → GeoController → MapsManager), so
  provider keys stay off the browser. Any storefront theme can wire its delivery
  address input to it without duplicating the engine.

  Exposes ONE global:
    window.mbAddrAutocomplete(input, {
      suggest,   // the dropdown container element (gets .hidden toggled + filled)
      locBtn,    // optional "use my location" button element
      lat, lng,  // optional hidden inputs to receive the picked coordinates
      onPick     // optional callback(value) after a pick / geolocation
    })

  Suggestion buttons carry class `mb-addr-opt` — each theme styles the dropdown
  and options with its own tokens. Degrades to a plain text field when the geo
  routes are unavailable or the provider returns nothing.
--}}
<script @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
(function () {
    var ROUTES = {
        autocomplete: @json(\Illuminate\Support\Facades\Route::has('geo.autocomplete') ? route('geo.autocomplete') : null),
        reverse: @json(\Illuminate\Support\Facades\Route::has('geo.reverse') ? route('geo.reverse') : null)
    };
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    window.mbAddrAutocomplete = function (input, opts) {
        opts = opts || {};
        if (!input || !ROUTES.autocomplete || input.dataset.mbAddrWired) { return; }
        input.dataset.mbAddrWired = '1';
        input.setAttribute('autocomplete', 'off');
        var box = opts.suggest || null, locBtn = opts.locBtn || null,
            latEl = opts.lat || null, lngEl = opts.lng || null, onPick = opts.onPick || null;
        var timer = null;
        function setCoords(la, ln) { if (latEl) { latEl.value = la || ''; } if (lngEl) { lngEl.value = ln || ''; } }
        function hideBox() { if (box) { box.hidden = true; box.innerHTML = ''; } }
        input.addEventListener('input', function () {
            var q = input.value.trim();
            setCoords('', ''); // typing invalidates a previously picked pin
            if (timer) { clearTimeout(timer); }
            if (q.length < 3) { hideBox(); return; }
            timer = setTimeout(function () {
                fetch(ROUTES.autocomplete + '?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        var res = (d && d.results) || [];
                        if (!res.length || !box) { hideBox(); return; }
                        box.innerHTML = res.map(function (r) {
                            return '<button type="button" class="mb-addr-opt" data-lat="' + (r.lat || '') + '" data-lng="' + (r.lng || '') + '" data-label="' + esc(r.label) + '">' + esc(r.label) + '</button>';
                        }).join('');
                        box.hidden = false;
                    }).catch(hideBox);
            }, 350);
        });
        if (box) {
            box.addEventListener('click', function (e) {
                var opt = e.target.closest ? e.target.closest('.mb-addr-opt') : null;
                if (!opt) { return; }
                input.value = opt.getAttribute('data-label') || '';
                setCoords(opt.getAttribute('data-lat'), opt.getAttribute('data-lng'));
                hideBox();
                if (onPick) { onPick(input.value); }
            });
        }
        input.addEventListener('blur', function () { setTimeout(hideBox, 200); });
        if (locBtn && ROUTES.reverse) {
            locBtn.addEventListener('click', function () {
                if (!navigator.geolocation) { return; }
                locBtn.classList.add('is-loading');
                navigator.geolocation.getCurrentPosition(function (pos) {
                    var la = pos.coords.latitude, ln = pos.coords.longitude;
                    fetch(ROUTES.reverse + '?lat=' + la + '&lng=' + ln, { headers: { 'Accept': 'application/json' } })
                        .then(function (r) { return r.json(); })
                        .then(function (d) {
                            locBtn.classList.remove('is-loading');
                            if (d && d.result) {
                                input.value = d.result.label || '';
                                setCoords(d.result.lat || la, d.result.lng || ln);
                                if (onPick) { onPick(input.value); }
                            }
                        }).catch(function () { locBtn.classList.remove('is-loading'); });
                }, function () { locBtn.classList.remove('is-loading'); }, { enableHighAccuracy: true, timeout: 8000 });
            });
        }
    };
})();
</script>
