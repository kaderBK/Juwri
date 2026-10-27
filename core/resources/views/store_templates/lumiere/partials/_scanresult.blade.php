{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<section class="lm-screen has-actionbar" data-screen="scanresult" hidden>
    <div class="lm-hdr">
        <button type="button" class="lm-iconbtn" data-back aria-label="{{ ___('Back') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg>
        </button>
        <span class="lm-title">{{ ___('Skin Age') }}</span>
        <span style="width:42px"></span>
    </div>

    <div class="lm-facepeek">
        @php $lmScanFace = $items->first(fn ($m) => $m->image && $m->image !== 'default.png'); @endphp
        @if($lmScanFace)
            @if((string) ($jsItems[$lmScanFace->id]['img']) !== '')<img src="{{ $jsItems[$lmScanFace->id]['img'] }}" alt="" data-csp-error="hide">@endif
        @else
            <span class="lm-facepeek-ph" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="9" r="3.2"/><path d="M5 20c0-3.9 3.1-6.5 7-6.5s7 2.6 7 6.5"/></svg>
            </span>
        @endif
        <div>
            <div class="lm-skinage" id="lmSkinAge">25</div>
            <div class="lm-skinagelabel">{{ ___('Skin Age') }}</div>
        </div>
    </div>

    <div class="lm-label">{{ ___('Skin Age') }} · {{ ___('Analysis') }}</div>
    @foreach([['acne', ___('Acne'), 18], ['oily', ___('Oily'), 45], ['wrinkles', ___('Wrinkles'), 12], ['dryness', ___('Dryness'), 30]] as [$mkey, $mlabel, $mval])
        <div class="lm-metricrow">
            <div class="lm-mhead"><span>{{ $mlabel }}</span><span id="lmMetric-{{ $mkey }}-val">{{ $mval }}%</span></div>
            <div class="lm-mtrack"><i id="lmMetric-{{ $mkey }}-bar" style="width:{{ $mval }}%"></i></div>
        </div>
    @endforeach

    @if($recoItems->isNotEmpty())
        <div class="lm-label">
            <svg width="13" height="13" viewBox="0 0 24 24" style="vertical-align:-2px" aria-hidden="true"><path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z" fill="var(--lm-accent)"/></svg>
            {{ ___('AI Recommendation') }}
        </div>
        @foreach($recoItems as $m)
            @php $jd = $jsItems[$m->id]; @endphp
            <a href="?item={{ $m->id }}" class="lm-reco-row" data-open-item-link="{{ $m->id }}">
                <span class="lm-rimg">
                    @if($jd['img'])
                        @if((string) ($jd['img']) !== '')<img src="{{ $jd['img'] }}" alt="" data-csp-error="hide">@endif
                    @endif
                </span>
                <span class="lm-rinfo">
                    <span class="lm-rn" style="display:block">{{ $jd['name'] }}</span>
                    <span class="lm-rb">{{ $jd['brand'] ? $jd['brand'].' · ' : '' }}{{ $jd['priceFormatted'] }}</span>
                </span>
                <button type="button" class="lm-radd" data-add="{{ $m->id }}" @if($jd['soldOut']) disabled @endif aria-label="{{ $vocab['add_to_cart'] ?? ___('Add to cart') }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                </button>
            </a>
        @endforeach
    @endif

    <div class="lm-progressstat">
        <span class="lm-pico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l4 4 10-10"/></svg></span>
        <div>
            <div class="lm-pt" id="lmProgressStatTitle">{{ ___('Your Skin Progress') }}</div>
            <div class="lm-ps">{{ ___('since your last scan') }}</div>
        </div>
    </div>
    @if(! $skinScanRoute)
        <p class="lm-demo-note">{{ ___('Sample result — connect the skin-scan service for real analysis.') }}</p>
    @endif

    <div class="lm-actionbar"><div class="lm-actionbar__inner">
        <button type="button" class="lm-btn lm-btn-dark lm-btn-block" data-nav="home">{{ ___('Done') }}</button>
    </div></div>
</section>
