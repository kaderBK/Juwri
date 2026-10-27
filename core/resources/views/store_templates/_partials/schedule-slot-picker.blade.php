{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  Shared schedule-slot picker — upgrades every theme's raw "Later" time control
  to real bookable slots.

  T1 built the whole server side (SchedulingService: opening hours, overnight
  windows, lead time, horizon, per-slot capacity) AND the public
  `restaurant.scheduleSlots` endpoint for "the checkout picker" — but no theme
  ever called it. All 17 ship a bare <input type="datetime-local"> (scoop: an
  empty <select> nothing populates), so a customer can pick 3 AM and only learns
  at submit that the kitchen is closed. Slot pickers with the impossible times
  simply absent are the standard for a reason.

  This layer finds the theme's own control, hides it, and renders a date select
  + time-slot select fed by the endpoint. The chosen slot is written BACK into
  the hidden original ('Y-m-d\TH:i' for datetime-local, an injected <option>
  for scoop's select), so every theme's existing submit path — read `.value`,
  append `scheduled_at` — keeps working byte for byte. Full slots render
  disabled; the first open slot is preselected (ASAP stays each theme's own
  toggle and default). If scheduling is disabled server-side the layer removes
  itself and the theme control returns untouched.
--}}
@php
    /* Same 17-template scope as the other shared sections. */
    $mbspTemplates = \Juwri\Modules\Storefront\Services\Theme\KeptTemplates::MODERN;

    $mbspOn = isset($store) && ! empty($store->id)
        && in_array(app(\App\Services\StorefrontThemeService::class)->resolveSaved($store), $mbspTemplates, true)
        && ! empty(store_options($store->id, 'allow_order_scheduling'));
@endphp

@if($mbspOn)
<style @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
/* No surface of its own (offers-strip lesson): inherit the theme's ink and let
   the two selects read as native controls of whatever form they sit in. */
.mbsp{display:flex;gap:8px;margin-top:8px}
.mbsp[hidden]{display:none!important}
.mbsp select{flex:1;min-width:0;font:inherit;font-size:.85rem;padding:9px 11px;border-radius:10px;
  border:1px solid rgba(127,127,127,.4);background:transparent;color:inherit}
.mbsp select:disabled{opacity:.5}
.mbsp option:disabled{color:#9a9a9a}
</style>
<script @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
(function () {
    var URL_BASE = @json(route('store.scheduleSlots', $store->id));
    var I18N = {
        today: @json(___('Today')),
        tomorrow: @json(___('Tomorrow')),
        full: @json(___('full')),
        noSlots: @json(___('No times available')),
        asap: @json(___('As soon as possible')),
    };
    var LOCALE = document.documentElement.lang || 'en';

    /* The theme's own "Later" control. One per page in practice. */
    var SEL = 'input[type="datetime-local"][name="scheduled_at"], input[type="datetime-local"].when-time, ' +
              'input[type="datetime-local"]#whenTime, select.when-time, select#whenTime, #cvScheduledAt';

    function currentType() {
        var on = document.querySelector('[data-ot].is-on, [data-ot][aria-pressed="true"]');
        var t = on ? (on.getAttribute('data-ot') || '') : '';
        if (t === 'roomservice') t = 'room_service';
        return ['on-table', 'takeaway', 'delivery', 'room_service'].indexOf(t) !== -1 ? t : 'takeaway';
    }

    function todayYmd() {
        var now = new Date(); var pad = function (n) { return (n < 10 ? '0' : '') + n; };
        return now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate());
    }

    function dateLabel(ymd) {
        var now = new Date(); var pad = function (n) { return (n < 10 ? '0' : '') + n; };
        var tYmd = todayYmd();
        now.setDate(now.getDate() + 1);
        var tomorrowYmd = now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate());
        if (ymd === tYmd) return I18N.today;
        if (ymd === tomorrowYmd) return I18N.tomorrow;
        try {
            return new Intl.DateTimeFormat(LOCALE, { weekday: 'short', month: 'short', day: 'numeric' })
                .format(new Date(ymd + 'T12:00:00'));
        } catch (e) { return ymd; }
    }

    function writeBack(orig, value) {
        if (!orig) return;
        if (orig.tagName === 'SELECT') {
            var opt = orig.querySelector('option[data-mbsp]');
            if (!opt) { opt = document.createElement('option'); opt.setAttribute('data-mbsp', ''); orig.appendChild(opt); }
            opt.value = value; opt.textContent = value;
            orig.value = value;
        } else {
            /* datetime-local rejects "Y-m-d H:i"; it wants the T separator. */
            orig.value = value ? value.replace(' ', 'T') : '';
        }
        try { orig.dispatchEvent(new Event('change', { bubbles: true })); } catch (e) {}
    }

    function build(orig) {
        if (orig.hasAttribute('data-mbsp-wired')) return;
        orig.setAttribute('data-mbsp-wired', '');

        var wrap = document.createElement('div');
        wrap.className = 'mbsp';
        var dSel = document.createElement('select');
        var tSel = document.createElement('select');
        wrap.appendChild(dSel); wrap.appendChild(tSel);
        orig.insertAdjacentElement('afterend', wrap);
        orig.style.display = 'none';

        var cache = {};
        function load(date) {
            var type = currentType();
            var key = type + '|' + (date || '');
            var url = URL_BASE + '?type=' + encodeURIComponent(type) + (date ? '&date=' + encodeURIComponent(date) : '');
            var p = cache[key] || (cache[key] = fetch(url, { headers: { Accept: 'application/json' } })
                .then(function (r) { return r.json(); }));
            p.then(function (d) {
                if (!d || d.enabled === false) { wrap.remove(); orig.style.display = ''; orig.removeAttribute('data-mbsp-wired'); return; }
                dSel.innerHTML = (d.dates || []).map(function (ymd) {
                    return '<option value="' + ymd + '"' + (ymd === d.date ? ' selected' : '') + '>' + dateLabel(ymd) + '</option>';
                }).join('');
                var slots = d.slots || [];
                if (!slots.length) {
                    tSel.innerHTML = '<option value="">' + I18N.noSlots + '</option>';
                    tSel.disabled = true; writeBack(orig, '');
                    return;
                }
                tSel.disabled = false;
                var first = null;
                var opts = slots.map(function (s) {
                    if (!s.full && first === null) first = s.value;
                    return '<option value="' + s.value + '"' + (s.full ? ' disabled' : '') + '>'
                        + s.label + (s.full ? ' — ' + I18N.full : '') + '</option>';
                });
                /* Respect the diner's ASAP intent. The theme's default is "order
                   now" (empty scheduled_at); this picker used to preselect the
                   first slot and write it straight back into the hidden native
                   input, so an ASAP tap silently rode out as a SCHEDULED order —
                   which the server then rejected as too-early. So for TODAY we
                   lead with an explicit "as soon as possible" option (empty
                   value → native input stays empty → true ASAP) and only write a
                   real slot when the diner picks one. A FUTURE date is itself an
                   explicit scheduling choice, so there we still land on the first
                   open slot. */
                if (d.date === todayYmd()) {
                    opts.unshift('<option value="" selected>' + I18N.asap + '</option>');
                    tSel.innerHTML = opts.join('');
                    tSel.value = ''; writeBack(orig, '');
                } else {
                    tSel.innerHTML = opts.join('');
                    if (first !== null) { tSel.value = first; writeBack(orig, first); }
                    else { tSel.selectedIndex = -1; writeBack(orig, ''); }
                }
            }).catch(function () { /* endpoint hiccup: leave the theme control */ });
        }

        dSel.addEventListener('change', function () { load(dSel.value); });
        tSel.addEventListener('change', function () { writeBack(orig, tSel.value); });

        /* Slots differ per order type — refetch (cached) when the diner flips it. */
        document.addEventListener('click', function (e) {
            if (e.target && e.target.closest && e.target.closest('[data-ot]')) {
                setTimeout(function () { cache = {}; load(dSel.value || null); }, 60);
            }
        });

        /* Themes reveal the control by unhiding it when "Later" is chosen —
           mirror that visibility onto the wrapper. */
        function mirror() {
            var hidden = orig.hidden || orig.closest('[hidden]') !== null;
            wrap.hidden = hidden;
        }
        new MutationObserver(mirror).observe(orig, { attributes: true, attributeFilter: ['hidden', 'style', 'class'] });
        mirror();

        load(null);
    }

    function scan() {
        var nodes = document.querySelectorAll(SEL);
        for (var i = 0; i < nodes.length; i++) build(nodes[i]);
    }
    // Theme bundles build parts of their UI after DOM ready.
    scan(); setTimeout(scan, 900); setTimeout(scan, 2500);
})();
</script>
@endif
