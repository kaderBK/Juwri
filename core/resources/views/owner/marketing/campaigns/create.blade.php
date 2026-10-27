{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Create campaign'))
@section('page_title', ___('Compose campaign'))
@section('page_sub', ___('Email or SMS broadcast to your consented audience. Variables: {name}.'))

@section('page_actions')
    <x-ui.button :href="route('campaigns.index')" variant="secondary" icon="back">
        {{ ___('Back') }}
    </x-ui.button>
@endsection

@section('content')
<div class="ui-scope ui-campnew" x-data="{
        channel: @js(old('channel', 'email')),
        store: @js((string) old('store_id', $stores->first()->id ?? '')),
        bioUrls: @js($bioUrls ?? []),
        insertBio() {
            const url = this.bioUrls[this.store];
            if (! url) return;
            const ta = this.$root.querySelector('textarea[name=body]');
            if (! ta) return;
            let start = ta.selectionStart, end = ta.selectionEnd;
            if (start === null || start === undefined) { start = end = ta.value.length; }
            const before = ta.value.slice(0, start);
            const sep = (before.length && ! /\s$/.test(before)) ? ' ' : '';
            const ins = sep + url;
            ta.value = before + ins + ta.value.slice(end);
            const pos = start + ins.length;
            ta.focus();
            ta.setSelectionRange(pos, pos);
            ta.dispatchEvent(new Event('input'));
        }
    }">
    <form action="{{ route('campaigns.store') }}" method="post" class="ui-campnew__form">
        @csrf

        <div class="ui-campnew__main">
            <x-ui.card :title="___('Audience')">
                <p class="ui-campnew__lead">{{ ___('Pick a store and segment. Only consented diners will receive the broadcast.') }}</p>

                <div class="ui-campnew__fields">
                    <div class="ui-campnew__field">
                        <label class="ui-label" for="campnew-store">{{ ___('Store') }}</label>
                        <select id="campnew-store" name="store_id" class="ui-input" x-model="store" required>
                            @foreach ($stores as $store)
                                <option value="{{ $store->id }}" @selected(old('store_id') == $store->id)>
                                    {{ $store->title }}
                                </option>
                            @endforeach
                        </select>
                        @error('store_id')
                            <small class="ui-campnew__error">{{ $message }}</small>
                        @enderror
                    </div>

                    <div class="ui-campnew__pair">
                        <div class="ui-campnew__field">
                            <label class="ui-label" for="campnew-channel">{{ ___('Channel') }}</label>
                            <select id="campnew-channel" name="channel" class="ui-input" x-model="channel" required>
                                <option value="email">{{ ___('Email') }}</option>
                                <option value="sms">{{ ___('SMS') }}</option>
                            </select>
                            @error('channel')
                                <small class="ui-campnew__error">{{ $message }}</small>
                            @enderror
                        </div>

                        <div class="ui-campnew__field">
                            <label class="ui-label" for="campnew-segment">{{ ___('Segment') }}</label>
                            <select id="campnew-segment" name="segment" class="ui-input" required>
                                <option value="all"    @selected(old('segment') === 'all')>{{ ___('All consented') }}</option>
                                <option value="recent" @selected(old('segment') === 'recent')>{{ ___('Recent (≤ 30d)') }}</option>
                                <option value="lapsed" @selected(old('segment') === 'lapsed')>{{ ___('Lapsed (> 30d)') }}</option>
                            </select>
                            @error('segment')
                                <small class="ui-campnew__error">{{ $message }}</small>
                            @enderror
                        </div>
                    </div>
                </div>
            </x-ui.card>

            <x-ui.card :title="___('Message')">
                <p class="ui-campnew__lead">{{ ___('Use') }} <code class="ui-ltr">{name}</code> {{ ___('to personalize each delivery.') }}</p>

                <div class="ui-campnew__fields">
                    <div class="ui-campnew__field" x-show="channel === 'email'" x-transition>
                        <label class="ui-label" for="campnew-subject">{{ ___('Subject') }}</label>
                        <input type="text" id="campnew-subject" name="subject" class="ui-input" value="{{ old('subject') }}" placeholder="{{ ___('Weekend special for {name}') }}">
                        @error('subject')
                            <small class="ui-campnew__error">{{ $message }}</small>
                        @enderror
                    </div>

                    <div class="ui-campnew__field">
                        <div class="ui-campnew__labelrow">
                            <label class="ui-label" for="campnew-body" x-text="channel === 'sms' ? @js(___('SMS body')) : @js(___('Email body'))">{{ ___('Body') }}</label>
                            {{-- biolink-tpl lane-d (T2-3): insert the selected store's bio link at the cursor.
                                 Server-gated to owners with at least one live bio page; per-store reactivity
                                 via x-show so it hides when a dark-bio store is selected. --}}
                            @if(!empty($bioUrls))
                                <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" x-cloak x-show="bioUrls[store]" @click="insertBio()">
                                    <span class="ui-btn__icon"><x-ui.icon name="external" /></span>{{ ___('Insert bio link') }}
                                </button>
                            @endif
                        </div>
                        <textarea id="campnew-body" name="body" rows="8" class="ui-input" required placeholder="{{ ___('Write your message…') }}" dir="auto">{{ old('body') }}</textarea>
                        <small class="ui-campnew__hint" id="campnew-sms-counter" x-show="channel === 'sms'" aria-live="polite"></small>
                        @error('body')
                            <small class="ui-campnew__error">{{ $message }}</small>
                        @enderror
                    </div>
                </div>
            </x-ui.card>
        </div>

        <aside class="ui-campnew__side">
            <x-ui.card :title="___('Schedule')">
                <p class="ui-campnew__lead">{{ ___('Leave blank to send immediately.') }}</p>
                <div class="ui-campnew__field">
                    <label class="ui-label" for="campnew-when">{{ ___('Scheduled at') }}</label>
                    <input type="datetime-local" id="campnew-when" name="scheduled_at" class="ui-input" value="{{ old('scheduled_at') }}">
                    @error('scheduled_at')
                        <small class="ui-campnew__error">{{ $message }}</small>
                    @enderror
                </div>
            </x-ui.card>

            <div class="ui-campnew__actions">
                <x-ui.button :href="route('campaigns.index')" variant="secondary">
                    {{ ___('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" icon="share" data-ui-busy>
                    {{ ___('Launch campaign') }}
                </x-ui.button>
            </div>
        </aside>
    </form>
</div>
@endsection

@push('scripts')
<script nonce="{{ csp_nonce() }}">
(function () {
    'use strict';
    var root = document.querySelector('.ui-campnew');
    if (!root) return;
    var body = document.getElementById('campnew-body');
    var channel = document.getElementById('campnew-channel');
    var counter = document.getElementById('campnew-sms-counter');
    if (!body || !channel || !counter) return;

    var charLabel = @js(___('Characters'));
    var segLabel = @js(___('Segments'));
    var gsmLabel = @js(___('GSM'));
    var unicodeLabel = @js(___('Unicode'));

    // P6-287: live SMS segment counter. GSM-7 fits 160 chars in the first segment and
    // 153 in each following one; extension-table chars cost two units. Any other char
    // forces UCS-2 (70 / 67 per segment, measured in UTF-16 code units).
    var GSM7 = "@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
    var GSM7_EXT = '^{}\\[~]|€';

    function segmentsFor(text) {
        var units = 0;
        for (var i = 0; i < text.length; i++) {
            var ch = text.charAt(i);
            if (GSM7.indexOf(ch) !== -1) {
                units += 1;
            } else if (GSM7_EXT.indexOf(ch) !== -1) {
                units += 2;
            } else {
                var len = text.length;
                return { chars: len, per: len <= 70 ? 70 : 67, segments: len <= 70 ? 1 : Math.ceil(len / 67), encoding: unicodeLabel };
            }
        }
        return { chars: units, per: units <= 160 ? 160 : 153, segments: units <= 160 ? 1 : Math.ceil(units / 153), encoding: gsmLabel };
    }

    function update() {
        if (channel.value !== 'sms') {
            counter.textContent = '';
            return;
        }
        var s = segmentsFor(body.value);
        counter.textContent = charLabel + ': ' + s.chars + ' / ' + s.per + ' · ' + segLabel + ': ' + s.segments + ' (' + s.encoding + ')';
    }

    body.addEventListener('input', update);
    channel.addEventListener('change', update);
    update();
})();
</script>
@endpush
