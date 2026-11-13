{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- CMS page fields shared by the create and edit panels. $page is null on create. --}}
<input type="hidden" name="active" value="0">
<x-ui.switch name="active" :checked="$page ? (bool) $page->active : true" :label="___('Active')" />
<div class="ui-field">
    <label class="ui-field__label" for="page-lang">{{ ___('Language') }} *</label>
    <div class="ui-field__control">
        <select id="page-lang" name="translation_lang">
            <option value="" @selected($page?->translation_lang === null)>{{ ___('All') }}</option>
            @foreach (collect($admin_languages ?? [])->unique('code') as $adminLanguage)
                <option value="{{ $adminLanguage->code }}" @selected($page?->translation_lang === $adminLanguage->code)>{{ $adminLanguage->name }}</option>
            @endforeach
        </select>
        <x-ui.icon name="chevron-down" size="16" class="ui-field__chevron" />
    </div>
</div>
<x-ui.field type="select" name="type" :label="___('Type').' *'" :value="$page ? (string) $page->type : '0'"
    :options="['0' => ___('Public'), '1' => ___('Private')]" />
<x-ui.field name="page_title" :label="___('Title').' *'" :value="$page?->title" :required="! $page" />
<x-ui.field name="slug" :label="___('Slug')" :value="$page?->slug" />
@if ($page && $page->isBuilderMode())
    <div class="ui-field">
        <span class="ui-field__label">{{ ___('Content') }}</span>
        <x-ui.banner>{{ ___('This page uses the advanced editor.') }}</x-ui.banner>
        <x-ui.button :href="route('admin.pages.builder', $page->id)" icon="pencil" block>{{ ___('Open Advanced Editor') }}</x-ui.button>
    </div>
@else
    <div class="ui-field">
        <label class="ui-field__label" for="page-content">{{ ___('Content') }} *</label>
        <textarea id="page-content" name="page_content" rows="10" data-ui-rich>{{ $page?->content }}</textarea>
    </div>
@endif
