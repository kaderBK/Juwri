{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Insights'))
@section('page_title', ___('Insights'))
@section('page_sub', ___('Ask about your sales and see how you compare.'))

@section('content')
<div class="ui-scope ui-insights">
    <div class="ui-push__grid">
        <section class="ui-card ui-insights__chat" x-data="copilot(@js(route('analytics.insights.ask')), @js(csrf_token()))">
            <h2 class="ui-insights__title"><x-ui.icon name="sparkle" size="18" />{{ ___('Ask about your sales') }}</h2>
            <div id="copilot-thread" class="ui-insights__thread" aria-live="polite">
                <template x-for="(m, i) in messages" :key="i">
                    <div class="ui-insights__msg" :data-role="m.role === 'you' ? 'you' : 'assistant'">
                        <span class="ui-insights__who" x-text="m.role"></span>
                        <span class="ui-insights__text" x-text="m.text"></span>
                    </div>
                </template>
                <p x-show="!messages.length" class="ui-insights__hint">{{ ___('e.g. "What were my top sales days last month?"') }}</p>
            </div>
            <form @submit.prevent="ask()" class="ui-insights__ask">
                <input type="text" x-model="q" class="ui-input" placeholder="{{ ___('Ask a question…') }}" aria-label="{{ ___('Ask a question…') }}" :disabled="busy">
                <button type="submit" class="ui-btn" :disabled="busy || !q.trim()" :aria-busy="busy ? 'true' : 'false'">{{ ___('Ask') }}</button>
            </form>
        </section>

        <aside class="ui-card ui-insights__bench">
            <h2 class="ui-insights__title">{{ ___('vs similar restaurants') }}</h2>
            @if($benchmark)
                <div class="ui-account__facts ui-insights__facts">
                    <div><dt>{{ ___('Your 30-day AOV') }}</dt><dd>{{ price_symbol_format($benchmark['my_aov']) }}</dd></div>
                    <div><dt>{{ ___('Cohort median') }}</dt><dd>{{ price_symbol_format($benchmark['median_aov']) }}</dd></div>
                </div>
                <p class="ui-insights__hint">{{ ___(':n similar stores', ['n' => $benchmark['cohort_n']]) }}</p>
                <x-ui.pill :tone="$benchmark['above_median'] ? 'success' : 'warning'">
                    {{ $benchmark['above_median'] ? ___('Above the median') : ___('Below the median') }}
                </x-ui.pill>
            @else
                <x-ui.empty :title="___('Not enough peers yet to show a benchmark.')" icon="chart" />
            @endif
        </aside>
    </div>
</div>

@push('scripts')
<script nonce="{{ csp_nonce() }}">
window.copilot = function (url, token) {
    return {
        q: '', busy: false, messages: [],
        ask: function () {
            var self = this, q = this.q.trim();
            if (!q || this.busy) { return; }
            this.busy = true;
            this.messages.push({ role: 'you', text: q });
            this.q = '';
            fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
                body: JSON.stringify({ question: q })
            }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
              .then(function (res) {
                self.busy = false;
                self.messages.push({ role: 'assistant', text: (res.j && (res.j.answer || res.j.message)) || 'Sorry, I could not answer that.' });
            }).catch(function () {
                self.busy = false;
                self.messages.push({ role: 'assistant', text: @json(___('The assistant is unavailable right now.')) });
            });
        }
    };
};
</script>
@endpush
@endsection
