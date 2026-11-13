{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<section class="lm-screen has-tabbar" data-screen="scan" hidden>
    <div class="lm-hdr">
        <span class="lm-title">{{ ___('Scan') }}</span>
        <button type="button" class="lm-iconbtn" data-nav="calendar" aria-label="{{ ___('Routine calendar') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M16 3v4M8 3v4M3 10h18"/></svg>
        </button>
    </div>

    <div class="lm-scanframe">
        @php $lmScanFace = $items->first(fn ($m) => $m->image && $m->image !== 'default.png'); @endphp
        @if($lmScanFace)
            @if((string) ($jsItems[$lmScanFace->id]['img']) !== '')<img src="{{ $jsItems[$lmScanFace->id]['img'] }}" alt="" data-csp-error="hide">@endif
        @else
            <div class="lm-scanframe-ph">
                <svg viewBox="0 0 24 24" width="64" height="64" fill="none" stroke="currentColor" stroke-width="1.3"><circle cx="12" cy="9" r="3.2"/><path d="M5 20c0-3.9 3.1-6.5 7-6.5s7 2.6 7 6.5"/></svg>
            </div>
        @endif
        <div class="lm-scan-corners"><span></span><span></span><span></span><span></span></div>
        <div class="lm-scan-line"></div>
        <div class="lm-scan-tag">
            <svg width="13" height="13" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z" fill="#fff"/></svg>
            {{ ___('Scan') }}
        </div>
        @if(! $skinScanRoute)
            <div class="lm-scan-demo-tag">{{ ___('Demo') }}</div>
        @endif
    </div>
    <p class="lm-pd-desc" style="text-align:center">{{ ___('Center your face inside the frame for the most accurate results.') }}</p>
    <button type="button" class="lm-btn lm-btn-dark lm-btn-block" id="lmScanBtn">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8V6a2 2 0 012-2h2M20 8V6a2 2 0 00-2-2h-2M4 16v2a2 2 0 002 2h2M20 16v2a2 2 0 01-2 2h-2"/><circle cx="12" cy="12" r="3"/></svg>
        <span id="lmScanBtnLabel">{{ ___('Scan Your Face') }}</span>
    </button>
    @if(! $skinScanRoute)
        <p class="lm-demo-note">{{ ___('No skin-scan service is connected yet — this shows a sample result.') }}</p>
    @endif
</section>
