{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Site block: section_ai_suite — what the AI layer does, and the providers
     behind it (names as chips; no brand artwork). --}}
@php
    $sbCaps = [
        [___('Photograph a paper menu, get a digital one'), ___('Photos, handwriting or PDFs become structured items with prices and options.')],
        [___('Describe your restaurant, get a menu draft'), ___('Generation, translation and item photos in one pass.')],
        [___('An ordering chatbot inside WhatsApp'), ___('It knows your menu, takes the order, hands it to the kitchen.')],
        [___('A design studio for print menus'), ___('AI backgrounds and layout packages, export-ready for the printer.')],
        [___('Bulk menu edits, proposed by AI'), ___('Ask for price rounding or seasonal renames, review the diff, apply in one click.')],
    ];
    $sbProviders = ['OpenAI', 'Anthropic', 'Gemini', 'DeepSeek', 'Kimi', 'MiMo', 'Groq'];
@endphp
<section class="mk-sec sb-block" data-site-block="section_ai_suite">
    <div class="mk-wrap">
        <div class="mk-split">
            <div>
                <span class="mk-eyebrow">{{ ___('AI suite') }}</span>
                <h2 class="mk-h2">{{ ___('AI does the boring work. You pick the brain.') }}</h2>
                <ul class="mk-checks sb-caps">
                    @foreach ($sbCaps as [$sbTitle, $sbBody])
                        <li><strong>{{ $sbTitle }}</strong><span>{{ $sbBody }}</span></li>
                    @endforeach
                </ul>
            </div>
            <div class="mk-panel">
                <div class="mk-panel__head"><x-ui.icon name="sparkle" /> {{ ___('Seven providers, one switch') }}</div>
                <ul class="mk-chips sb-panel-chips">
                    @foreach ($sbProviders as $sbName)
                        <li>{{ $sbName }}</li>
                    @endforeach
                </ul>
                <p class="mk-panel__foot">{{ ___('Bring your own key or use ours. Every AI screen shows exactly which provider and model ran, and the chain falls back automatically if one is down.') }}</p>
            </div>
        </div>
        @if (Route::has('studio'))
            <div class="mk-actions"><a href="{{ route('studio') }}" class="ui-btn ui-btn--secondary">{{ ___('Try the AI Menu Studio') }}</a></div>
        @endif
    </div>
</section>
