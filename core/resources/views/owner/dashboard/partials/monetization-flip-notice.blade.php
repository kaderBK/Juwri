{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    /**
     * MONETIZATION WI V0 — merchant notice banner for the gating flip.
     *
     * Shows only when the owner has set a FUTURE flip date in
     * `settings.monetization_flip_date` (a Y-m-d string). It auto-hides the
     * moment that date passes — a notice about a change that has already
     * happened is just noise. Reseller-managed merchants are suppressed: their
     * provider sets pricing, and the D8 locked page already routes them there,
     * so the platform's own announcement would be off-brand.
     *
     * Dismissible per browser via localStorage, keyed by the date, so changing
     * the flip date re-shows the banner rather than staying dismissed forever.
     */
    $flipRaw = config('settings.monetization_flip_date');
    $flipDate = null;
    if ($flipRaw) {
        try { $flipDate = \Illuminate\Support\Carbon::parse($flipRaw); } catch (\Throwable $e) { $flipDate = null; }
    }

    $isReseller = false;
    try { $isReseller = \Juwri\Core\Support\BrandContext::current()->isReseller(); } catch (\Throwable $e) {}

    $show = $flipDate && $flipDate->isFuture() && ! $isReseller;
    $key = $flipDate ? $flipDate->format('Y-m-d') : '';
@endphp

@if($show)
    <div class="ui-cluster ui-cluster--top ui-note monetization-flip-notice" data-tone="warning"
         data-flip-key="{{ $key }}" style="display:none" role="alert">
        <span><x-ui.icon name="alert" size="18" /></span>
        <div class="ui-grow">
            <strong>{{ ___('A few tools are becoming paid add-ons on :date', ['date' => $flipDate->isoFormat('LL')]) }}</strong>
            <div class="ui-small">
                {{ ___('Features like inventory, kitchen display and reservations move to low-cost add-ons on that date. Anything included in your plan stays free, and anything you add before then is yours to keep.') }}
            </div>
            <a href="{{ \Route::has('addons.index') ? route('addons.index') : url('/addons') }}" class="ui-btn ui-btn--secondary ui-btn--sm">
                <x-ui.icon name="chevron" size="14" />{{ ___('See what’s changing') }}
            </a>
        </div>
        <button type="button" class="ui-icon-btn ui-icon-btn--ghost" data-flip-dismiss aria-label="{{ ___('Dismiss') }}"><x-ui.icon name="x" size="18" /></button>
    </div>

    @push('scripts_at_bottom')
    <script nonce="{{ csp_nonce() }}">
        (function () {
            var el = document.querySelector('.monetization-flip-notice');
            if (!el) return;
            var key = el.dataset.flipKey;
            // Dismissal is keyed by the flip date: a new date re-shows the notice.
            if (localStorage.getItem('mb_flip_notice_dismissed') === key) return;
            el.style.display = 'flex';
            el.querySelector('[data-flip-dismiss]').addEventListener('click', function () {
                el.style.transition = 'opacity .2s'; el.style.opacity = '0';
                setTimeout(function () { el.remove(); }, 220);
                localStorage.setItem('mb_flip_notice_dismissed', key);
            });
        })();
    </script>
    @endpush
@endif
