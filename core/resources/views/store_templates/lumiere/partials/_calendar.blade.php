{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Routine calendar — entirely client-side (localStorage), per spec ("can be
     static first"): a real daily checklist persisted per calendar day, and a
     monthly bar chart computed from that SAME stored history (never random/
     fabricated numbers — a day with no stored checklist simply reads as 0%). --}}
<section class="lm-screen has-tabbar" data-screen="calendar" hidden>
    <div class="lm-hdr">
        <span class="lm-title">{{ ___('My Routine') }}</span>
        <button type="button" class="lm-iconbtn" data-nav="scan" aria-label="{{ ___('Scan') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8V6a2 2 0 012-2h2M20 8V6a2 2 0 00-2-2h-2M4 16v2a2 2 0 002 2h2M20 16v2a2 2 0 01-2 2h-2"/><circle cx="12" cy="12" r="3"/></svg>
        </button>
    </div>

    <div class="lm-segrow">
        <button type="button" class="lm-segbtn" id="lmCalDaily" aria-pressed="true">{{ ___('Daily') }}</button>
        <button type="button" class="lm-segbtn" id="lmCalMonthly" aria-pressed="false">{{ ___('Monthly') }}</button>
    </div>

    <div class="lm-routinecard" id="lmCalDailyCard" style="margin-top:16px">
        <div class="lm-ringbox">
            {!! $ring(0) !!}
            <span class="lm-pct" id="lmCalPct">0%</span>
        </div>
        <div class="lm-routinebody">
            <div class="lm-rt">{{ ___("Today's Progress") }}</div>
            <div class="lm-rs" id="lmCalDone">0/5 {{ ___('Routine Checklist') }}</div>
        </div>
    </div>

    <div class="lm-chartcard" id="lmCalMonthlyCard" hidden>
        <div class="lm-barsrow" id="lmCalBars"></div>
        <div class="lm-cap" id="lmCalCaption">{{ ___('This Month') }}</div>
    </div>

    <div class="lm-checklist">
        <div class="lm-label">{{ ___('Routine Checklist') }}</div>
        @foreach([
            ['cleanse', ___('Cleanse')],
            ['tone', ___('Tone')],
            ['serum', ___('Apply Serum')],
            ['moisturize', ___('Moisturize')],
            ['spf', ___('Apply SPF')],
        ] as [$step, $label])
            <button type="button" class="lm-optcard" data-routine="{{ $step }}" aria-pressed="false">
                <span class="lm-checkbox" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l4 4 10-10"/></svg>
                </span>
                <span class="lm-obody"><span class="lm-ot">{{ $label }}</span></span>
            </button>
        @endforeach
    </div>
</section>
