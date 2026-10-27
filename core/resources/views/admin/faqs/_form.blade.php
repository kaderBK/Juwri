{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- FAQ fields shared by the create and edit panels. $faq is null on create. --}}
<input type="hidden" name="active" value="0">
<x-ui.switch name="active" :checked="$faq ? (bool) $faq->active : true" :label="___('Active')" />
<div class="ui-field">
    <label class="ui-field__label" for="faq-lang">{{ ___('Language') }} *</label>
    <div class="ui-field__control">
        <select id="faq-lang" name="translation_lang">
            <option value="" @selected($faq?->translation_lang === null)>{{ ___('All') }}</option>
            @foreach (collect($admin_languages ?? [])->unique('code') as $adminLanguage)
                <option value="{{ $adminLanguage->code }}" @selected($faq?->translation_lang === $adminLanguage->code)>{{ $adminLanguage->name }}</option>
            @endforeach
        </select>
        <x-ui.icon name="chevron-down" size="16" class="ui-field__chevron" />
    </div>
</div>
<x-ui.field name="faq_title" :label="___('Title').' *'" :value="$faq?->faq_title" required />
<div class="ui-field">
    <label class="ui-field__label" for="faq-content">{{ ___('Content') }} *</label>
    <textarea id="faq-content" name="faq_content" rows="8" data-ui-rich>{{ $faq?->faq_content }}</textarea>
</div>
