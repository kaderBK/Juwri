{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    Shared map-picker engine (behaviour only — every theme supplies its OWN
    styled trigger button, modal chrome, map area and confirm control, so the
    picker looks native to each theme's design language).

    Usage from a theme (after this partial is @included / reachable):

        window.mbMapPicker({
            trigger:  document.getElementById('...'),   // opens the map (required)
            modal:    document.getElementById('...'),   // toggled hidden (required)
            map:      document.getElementById('...'),    // Leaflet renders here (required)
            input:    document.getElementById('...'),    // filled on confirm (required)
            confirm:  document.getElementById('...'),   // confirm control (required)
            close:    document.getElementById('...'),    // one element OR array (optional)
            preview:  document.getElementById('...'),    // shows the live address (optional)
            lat:      document.getElementById('...'),    // hidden coord input (optional)
            lng:      document.getElementById('...'),    // hidden coord input (optional)
            center:   [24.7136, 46.6753],                 // fallback centre (optional)
            i18n:     { locating: '…', hint: '…' }        // preview strings (optional)
        });

    Leaflet is self-hosted and lazy-loaded on first open (kept off the critical
    path for every page). Same-origin src is allowed under CSP script-src 'self';
    the geo/reverse endpoint provides the address for the map centre.
--}}
@if(\Illuminate\Support\Facades\Route::has('geo.reverse'))
<script @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
(function () {
    if (window.mbMapPicker) { return; }
    var LEAFLET_JS  = @json(asset('assets/vendor/leaflet/leaflet.js'));
    var LEAFLET_CSS = @json(asset('assets/vendor/leaflet/leaflet.css'));
    var REVERSE     = @json(route('geo.reverse'));
    var DEFAULT_CENTER = [24.7136, 46.6753]; /* Riyadh */
    var leafletP = null;

    function loadLeaflet() {
        if (window.L) { return Promise.resolve(window.L); }
        if (leafletP) { return leafletP; }
        leafletP = new Promise(function (resolve, reject) {
            if (!document.querySelector('link[data-mb-leaflet]')) {
                var css = document.createElement('link');
                css.rel = 'stylesheet'; css.href = LEAFLET_CSS; css.setAttribute('data-mb-leaflet', '1');
                document.head.appendChild(css);
            }
            var s = document.createElement('script');
            s.src = LEAFLET_JS; s.async = true;
            s.onload = function () { resolve(window.L); };
            s.onerror = function () { reject(new Error('leaflet')); };
            document.head.appendChild(s);
        });
        return leafletP;
    }

    function toArray(v) { return v == null ? [] : (v.forEach ? v : [v]); }

    window.mbMapPicker = function (cfg) {
        cfg = cfg || {};
        var trigger = cfg.trigger, modal = cfg.modal, mapEl = cfg.map,
            input = cfg.input, confirmEl = cfg.confirm;
        if (!trigger || !modal || !mapEl || !input || !confirmEl) { return null; }
        if (trigger.dataset.mbMapWired) { return null; }
        trigger.dataset.mbMapWired = '1';

        var previewEl = cfg.preview || null;
        var latEl = cfg.lat || null, lngEl = cfg.lng || null;
        var toggleFn = (typeof cfg.toggle === 'function') ? cfg.toggle : null; /* theme owns show/hide + backdrop */
        var center = (cfg.center && cfg.center.length === 2) ? cfg.center : DEFAULT_CENTER;
        var i18n = cfg.i18n || {};
        var sLocating = i18n.locating || 'Finding address…';
        var sHint = i18n.hint || 'Move the map to place the pin';

        var map = null, revTimer = null, lastLabel = '', lastLat = null, lastLng = null;

        function reverse(lat, lng) {
            lastLat = lat; lastLng = lng;
            if (previewEl) { previewEl.textContent = sLocating; }
            fetch(REVERSE + '?lat=' + encodeURIComponent(lat) + '&lng=' + encodeURIComponent(lng),
                  { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    lastLabel = (d && d.result && d.result.label) || '';
                    if (previewEl) { previewEl.textContent = lastLabel || sHint; }
                })
                .catch(function () { lastLabel = ''; if (previewEl) { previewEl.textContent = sHint; } });
        }

        function initMap(L, lat, lng) {
            /* ensure a centred pin exists (theme may style .mb-map-pin) */
            if (!mapEl.querySelector('.mb-map-pin')) {
                var pin = document.createElement('div');
                pin.className = 'mb-map-pin';
                pin.setAttribute('aria-hidden', 'true');
                mapEl.appendChild(pin);
            }
            map = L.map(mapEl, { attributionControl: false, zoomControl: true }).setView([lat, lng], 15);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);
            map.on('moveend', function () {
                var c = map.getCenter();
                clearTimeout(revTimer);
                revTimer = setTimeout(function () { reverse(c.lat, c.lng); }, 500);
            });
            reverse(lat, lng);
        }

        function showModal() {
            if (toggleFn) { toggleFn(true); return; }
            modal.hidden = false; modal.removeAttribute('hidden'); modal.classList.add('is-open');
        }
        function hideModal() {
            if (toggleFn) { toggleFn(false); return; }
            modal.hidden = true; modal.setAttribute('hidden', 'hidden'); modal.classList.remove('is-open');
        }
        function resize() { if (map) { map.invalidateSize(); } }

        function open() {
            showModal();
            if (map) { setTimeout(resize, 80); setTimeout(resize, 480); return; }
            loadLeaflet().then(function (L) {
                /* Draw the map straight away — at a coord already picked (typeahead),
                   else the default centre — so it never blocks on the geolocation
                   permission prompt. If we had no starting point, recentre in the
                   background once/if the visitor grants location. */
                var seedLat = parseFloat(latEl && latEl.value), seedLng = parseFloat(lngEl && lngEl.value);
                var haveSeed = !isNaN(seedLat) && !isNaN(seedLng);
                initMap(L, haveSeed ? seedLat : center[0], haveSeed ? seedLng : center[1]);
                setTimeout(resize, 80); setTimeout(resize, 480);
                if (!haveSeed && navigator.geolocation) {
                    navigator.geolocation.getCurrentPosition(
                        function (p) { if (map) { map.setView([p.coords.latitude, p.coords.longitude], 15); } },
                        function () { /* denied/timed out — keep the default centre */ },
                        { timeout: 6000, maximumAge: 60000, enableHighAccuracy: false });
                }
            }).catch(function () { hideModal(); trigger.style.display = 'none'; });
        }

        trigger.addEventListener('click', function (e) { e.preventDefault(); open(); });
        toArray(cfg.close).forEach(function (el) { if (el) { el.addEventListener('click', function (e) { e.preventDefault(); hideModal(); }); } });
        confirmEl.addEventListener('click', function (e) {
            e.preventDefault();
            if (lastLabel) {
                if ('value' in input) { input.value = lastLabel; }
                else { input.textContent = lastLabel; }
                input.dispatchEvent(new Event('input', { bubbles: true }));
            }
            if (latEl && lastLat != null) { latEl.value = lastLat; }
            if (lngEl && lastLng != null) { lngEl.value = lastLng; }
            hideModal();
        });

        return { open: open, close: hideModal };
    };
})();
</script>
@endif
