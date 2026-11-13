{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Social post writer'))
@section('page_title', ___('Social post writer'))
@section('page_sub', ___('Let AI draft a post about a dish or an offer. Nothing is posted: you copy it and share it yourself.'))

@section('page_actions')
    <form method="get" action="{{ route('stores.marketing.socialPost.index') }}">
        <select name="store_id" class="ui-input ui-input--sm ui-mods__store" data-ui-do="submit" aria-label="{{ ___('Store') }}">
            @foreach ($stores as $r)
                <option value="{{ $r->id }}" @selected($rid == $r->id)>{{ $r->title }}</option>
            @endforeach
        </select>
    </form>
@endsection

@section('content')
<div class="ui-scope ui-social-post" data-social-post>

    <x-ui.card :title="___('What is the post about?')">
        <form class="ui-social-post__form" data-social-form>
            <div class="ui-field">
                <label class="ui-label" for="sp-item">{{ ___('Menu item') }}</label>
                <select id="sp-item" name="item_id" class="ui-input">
                    <option value="">{{ ___('No item, just the offer below') }}</option>
                    @foreach ($items as $item)
                        <option value="{{ $item->id }}">{{ $item->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="ui-field">
                <label class="ui-label" for="sp-offer">{{ ___('Offer or news (optional)') }}</label>
                <textarea id="sp-offer" name="offer" class="ui-input" rows="3" maxlength="500" dir="auto"
                    placeholder="{{ ___('e.g. 20% off all burgers this weekend') }}"></textarea>
                <small class="ui-field__help">{{ ___('The AI only uses what you give it: the item details and this text.') }}</small>
            </div>
            <div class="ui-field">
                <label class="ui-label" for="sp-lang">{{ ___('Language') }}</label>
                <select id="sp-lang" name="lang" class="ui-input ui-ai-draft__lang">
                    <option value="ar" lang="ar" @selected($defaultLang === 'ar')>العربية</option>
                    <option value="en" lang="en" @selected($defaultLang === 'en')>English</option>
                </select>
            </div>
            <div>
                <x-ui.button type="submit" icon="sparkle" data-social-go>{{ ___('Write post') }}</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card :title="___('Your draft')" class="ui-social-post__result" data-social-result hidden>
        <div class="ui-field">
            <label class="ui-label" for="sp-text">{{ ___('Post text') }}</label>
            <textarea id="sp-text" class="ui-input" rows="6" dir="auto" data-social-text></textarea>
        </div>
        <div class="ui-field">
            <label class="ui-label" for="sp-tags">{{ ___('Hashtags') }}</label>
            <input id="sp-tags" type="text" class="ui-input" dir="auto" data-social-tags>
        </div>
        <div class="ui-cluster">
            <x-ui.button variant="secondary" size="sm" icon="share" data-social-copy>{{ ___('Copy post') }}</x-ui.button>
            <small class="ui-field__help">{{ ___('Edit the draft as you like. Nothing is posted for you.') }}</small>
        </div>
    </x-ui.card>

</div>
@endsection

@push('scripts')
<script nonce="{{ csp_nonce() }}">
(function () {
    'use strict';
    var root = document.querySelector('[data-social-post]');
    if (!root) return;
    var url = @js(route('stores.marketing.socialPost.draft').'?store_id='.$rid);
    var token = @js(csrf_token());
    var t = {
        need: @js(___('Pick a menu item or write an offer first.')),
        ready: @js(___('Draft ready.')),
        copied: @js(___('Copied.')),
        failed: @js(___('The AI service is unavailable right now. Please try again in a moment.'))
    };
    var form = root.querySelector('[data-social-form]');
    var go = root.querySelector('[data-social-go]');
    var result = root.querySelector('[data-social-result]');
    var text = root.querySelector('[data-social-text]');
    var tags = root.querySelector('[data-social-tags]');
    var toast = function (msg, type) { if (window.dashToast) window.dashToast(msg, { type: type }); };

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (go.getAttribute('aria-busy') === 'true') return;
        var fd = new FormData(form);
        if (!fd.get('item_id') && !String(fd.get('offer') || '').trim()) { toast(t.need, 'error'); return; }
        if (!fd.get('item_id')) fd.delete('item_id');
        go.setAttribute('aria-busy', 'true');
        go.disabled = true;
        fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' }, body: fd })
            .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d || {} }; }); })
            .then(function (res) {
                if (res.ok && res.d.success) {
                    text.value = res.d.text;
                    tags.value = (res.d.hashtags || []).join(' ');
                    [text, tags].forEach(function (el) { el.setAttribute('dir', res.d.dir || 'auto'); el.setAttribute('lang', res.d.lang || ''); });
                    result.hidden = false;
                    text.focus();
                    toast(t.ready, 'success');
                    return;
                }
                toast(res.d.error || res.d.message || t.failed, 'error');
            })
            .catch(function () { toast(t.failed, 'error'); })
            .finally(function () { go.removeAttribute('aria-busy'); go.disabled = false; });
    });

    root.querySelector('[data-social-copy]').addEventListener('click', function () {
        var body = text.value.trim() + (tags.value.trim() ? '\n\n' + tags.value.trim() : '');
        var done = function () { toast(t.copied, 'success'); };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(body).then(done, function () { text.select(); });
        } else {
            text.select();
        }
    });
})();
</script>
@endpush
