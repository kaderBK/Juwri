{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- P3-06 "Are you still there?": the dialog the core JS opens after idle_seconds without a touch (Continue / Start over), with a visible
     countdown. $live = true draws the real, hidden overlay; false draws the dialog alone (the kiosk-states board). --}}
@php($__idle = (int) ($vm['context']['kiosk']['idle_seconds'] ?? 60))
@php($__left = new \Illuminate\Support\HtmlString('<bdi dir="ltr" class="c-code" data-kiosk-left>15</bdi>'))
@if (! empty($live))<div class="c-koverlay" data-kiosk-overlay="idle" hidden>@endif
<div class="c-kdialog" role="dialog" aria-modal="true" aria-labelledby="kidle-title" aria-describedby="kidle-text" data-kiosk-idle>
    <span class="c-kdialog__icon">{{ $kit->icon('timer') }}</span>
    <{{ ! empty($live) ? 'h2' : 'h3' }} class="c-kdialog__title" id="kidle-title" tabindex="-1">{{ $kit->s('Are you still there?') }}</{{ ! empty($live) ? 'h2' : 'h3' }}>
    <p class="c-kdialog__text" id="kidle-text" role="timer" aria-live="off">{{ $kit->t('No activity for :idle s. Your order will be cleared in :seconds s.', ['idle' => $kit->ltr((string) $__idle), 'seconds' => $__left]) }}</p>
    <div class="c-kdialog__actions">
        <button class="c-btn c-btn--primary c-btn--xl" type="button" data-kiosk-continue>{{ $kit->s('Continue') }}</button>
        <button class="c-btn c-btn--secondary c-btn--xl" type="button" data-kiosk-startover>{{ $kit->s('Start over') }}</button>
    </div>
</div>
@if (! empty($live))</div>@endif
