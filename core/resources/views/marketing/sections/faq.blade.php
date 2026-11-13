{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- FAQ list: native <details>, the first one open. Needs $faqs. --}}
<div class="mk-faq">
    @foreach ($faqs as $mkI => $mkFaq)
        <details class="mk-faq__item" @if ($mkI === 0) open @endif>
            <summary>
                <span class="mk-faq__num">{{ str_pad($mkI + 1, 2, '0', STR_PAD_LEFT) }}.</span>
                <span class="mk-faq__q">{{ $mkFaq->faq_title ?? $mkFaq->question ?? '' }}</span>
            </summary>
            <div class="mk-faq__a">@clean($mkFaq->faq_content ?? $mkFaq->answer ?? '', 'richtext')</div>
        </details>
    @endforeach
</div>
