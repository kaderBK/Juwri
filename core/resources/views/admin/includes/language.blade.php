{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Content-language switcher for list pages that take ?lang= (FAQs, pages). Needs $current_language. --}}
<div class="ui-pop-host" data-ui-pop>
    <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" data-ui-pop-toggle aria-expanded="false" aria-haspopup="true">
        <span class="ui-btn__icon"><x-ui.icon name="language" size="16" /></span>{{ strtoupper($current_language) }}
    </button>
    <div class="ui-pop ui-pop--end" data-ui-pop-panel hidden>
        @foreach (collect($admin_languages ?? [])->unique('code') as $adminLanguage)
            <a class="ui-pop__item" href="?lang={{ urlencode($adminLanguage->code) }}" @if ($adminLanguage->code == $current_language) aria-current="true" @endif>
                <span class="ui-pop__label">{{ $adminLanguage->name }}</span>
                @if ($adminLanguage->code == $current_language)<x-ui.icon name="check" size="16" />@endif
            </a>
        @endforeach
    </div>
</div>
