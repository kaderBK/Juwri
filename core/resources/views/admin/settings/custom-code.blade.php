{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<section id="quick_custom_code" role="tabpanel" aria-labelledby="tab-quick_custom_code" @if (($active ?? true) === false) hidden @endif>
    <form method="POST" action="{{ route('admin.settings.update') }}" data-ui-ajax>
        <x-ui.card :title="___('External Javascript or Css In header')">
            <div class="ui-field">
                <label class="ui-field__label" for="external_code">{{ ___('Custom Code') }}</label>
                <textarea id="external_code" name="external_code" class="ui-input ui-code-area" rows="10" spellcheck="false" aria-describedby="external_code-help">{{ $settings->external_code ?? '' }}</textarea>
                <span class="ui-field__help" id="external_code-help">{{ ___('You can add any javascript code and style css. This code will be placed on the head part.') }}</span>
            </div>
            @include('admin.settings._save', ['marker' => 'custom_code_setting'])
        </x-ui.card>
    </form>
</section>
