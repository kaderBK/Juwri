{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-job-progress> — poll a queued-job status endpoint and render its progress.

  Matches the existing dashboard convention (GenerateMenuItemImagesJob /
  TranslateMenuJob progress cache): the endpoint returns JSON shaped like
      { status: 'idle'|'queued'|'running'|'done'|'error',
        done: 12, total: 120, message: '…', lang: 'ar' }
  ('state' is accepted as an alias for 'status'; 'label' as an alias for the
  running-line text). Honours the no-worker case: a long 'queued' stretch shows
  a "worker may not be running" hint rather than a fake spinner forever.

  ATTRIBUTES
    url        progress JSON endpoint                              (required)
    interval   base poll interval in ms                            (default: 3000)
    auto       start polling on load                               (default: false)
    label      static label above the bar                          (optional)
    unit       noun for the counter, e.g. "items"                  (default: "items")

  CONTROL (dispatch on the element, or bind Alpine)
    job-start   begin polling  (dispatch after you POST to kick the job off)
    job-stop    stop polling
  EVENTS (bubble from root)
    job-complete  detail: { data }
    job-failed    detail: { data }

  EXAMPLE
    <div x-data>
      <button type="button" @click="
          fetch(@js(route('stores.menu.aiImages', $store->id)), {method:'POST', headers:{'X-CSRF-TOKEN': @js(csrf_token())}})
            .then(() => $refs.jp.dispatchEvent(new CustomEvent('job-start')))">
        {{ ___('Generate images') }}
      </button>
      <x-job-progress x-ref="jp"
          :url="route('stores.menu.aiImagesProgress', $store->id)"
          :unit="___('images')"
          x-on:job-complete="location.reload()" />
    </div>
--}}
@props([
    'url',
    'interval' => 3000,
    'auto' => false,
    'label' => null,
    'unit' => null,
])
@php
    $unitText = $unit ?: ___('items');
@endphp
@once
{{-- Kit tokens only: the running bar gets a gentle stripe (static under
     reduced motion); [x-cloak] is scoped here so the widget never flashes. --}}
<style nonce="{{ csp_nonce() }}">
    .dash-job-progress[x-cloak], .dash-job-progress [x-cloak] { display: none !important; }
    .dash-job-progress .ui-meter__bar { transition: inline-size .3s ease; }
    .dash-job-progress .ui-meter__bar[data-state="queued"],
    .dash-job-progress .ui-meter__bar[data-state="running"] {
        background-image: linear-gradient(45deg, rgba(255,255,255,.18) 25%, transparent 25%, transparent 50%, rgba(255,255,255,.18) 50%, rgba(255,255,255,.18) 75%, transparent 75%, transparent);
        background-size: 12px 12px;
        animation: dash-job-stripes 1s linear infinite;
    }
    .dash-job-progress .ui-meter__bar[data-state="done"] { background-color: var(--ui-success); }
    .dash-job-progress .ui-meter__bar[data-state="error"] { background-color: var(--ui-danger); }
    @keyframes dash-job-stripes { from { background-position: 12px 0; } to { background-position: 0 0; } }
    @media (prefers-reduced-motion: reduce) {
        .dash-job-progress .ui-meter__bar { transition: none; animation: none !important; }
    }
</style>
@endonce
<div
    {{ $attributes->merge(['class' => 'dash-job-progress']) }}
    x-data="dashJobProgress({
        url: @js($url),
        interval: {{ (int) $interval }},
        auto: {{ $auto ? 'true' : 'false' }},
        unit: @js($unitText),
        strings: {
            queued: @js(___('Waiting to start…')),
            queuedStuck: @js(___('Still waiting — check that the background worker (cron) is running.')),
            running: @js(___('Working')),
            done: @js(___('Done')),
            failed: @js(___('Something went wrong. Please try again.')),
        },
    })"
    x-on:job-start="start()"
    x-on:job-stop="stop()"
    x-cloak
    x-show="active"
>
    @if($label)
        <div class="ui-small ui-strong" style="margin-block-end: 4px">{{ $label }}</div>
    @endif
    <div class="ui-cluster ui-cluster--between ui-small" style="--ui-gap: var(--ui-space-2); margin-block-end: 4px">
        <span x-text="line"></span>
        <span class="ui-muted ui-ltr" x-cloak x-show="total > 0" x-text="done + '/' + total"></span>
    </div>
    <div class="ui-meter">
        <div class="ui-meter__bar"
             role="progressbar"
             :data-state="state"
             :style="'inline-size: ' + pct + '%'"
             :aria-valuenow="pct" aria-valuemin="0" aria-valuemax="100"
             @if($label) aria-label="{{ $label }}" @else :aria-label="line" @endif></div>
    </div>
</div>

@once
@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
    // Alpine factory for x-job-progress. Registered so it is available on BOTH
    // full page loads AND Turbo (SPA) navigations. alpine:init fires only on the
    // first load, so on a Turbo visit — where Alpine is already running — we must
    // register immediately; otherwise Alpine re-scans the swapped-in DOM and
    // throws "dashJobProgress is not defined" (the item list still renders, but
    // this progress widget breaks and floods the console).
    (function () {
        var __jpFactory = (cfg) => ({
            url: cfg.url,
            interval: cfg.interval || 3000,
            unit: cfg.unit || '',
            strings: cfg.strings || {},
            active: false,
            state: 'idle',
            done: 0,
            total: 0,
            line: '',
            timer: null,
            _queuedPolls: 0,
            get pct() {
                if (this.state === 'done') return 100;
                if (this.state === 'error') return 100;
                if (this.total > 0) return Math.min(100, Math.round((this.done / this.total) * 100));
                return this.state === 'running' ? 8 : 3; // indeterminate hint
            },
            init() {
                if (cfg.auto) this.start();
            },
            start() {
                this.active = true;
                this._queuedPolls = 0;
                this.state = 'queued';
                this.line = this.strings.queued;
                this.stop();
                this.tick();
                this.timer = setInterval(() => this.tick(), this.interval);
            },
            stop() {
                if (this.timer) { clearInterval(this.timer); this.timer = null; }
            },
            async tick() {
                let p;
                try {
                    p = await (await fetch(this.url, { headers: { 'Accept': 'application/json' } })).json();
                } catch (e) { return; /* transient network — keep polling */ }
                const status = p.status || p.state || 'idle';
                this.done = p.done || 0;
                this.total = p.total || 0;
                if (status === 'queued') {
                    this._queuedPolls++;
                    this.state = 'queued';
                    // Back off gently while queued to spare the server.
                    this.line = this._queuedPolls > 12 ? this.strings.queuedStuck : this.strings.queued;
                } else if (status === 'running') {
                    this._queuedPolls = 0;
                    this.state = 'running';
                    const lang = p.lang ? ' (' + p.lang + ')' : '';
                    this.line = (p.label || this.strings.running) + lang;
                } else if (status === 'done') {
                    this.state = 'done';
                    this.line = this.strings.done;
                    this.stop();
                    this.$root.dispatchEvent(new CustomEvent('job-complete', { bubbles: true, detail: { data: p } }));
                    if (window.dashToast) window.dashToast('success', this.strings.done);
                } else if (status === 'error') {
                    this.state = 'error';
                    this.line = p.message || this.strings.failed;
                    this.stop();
                    this.$root.dispatchEvent(new CustomEvent('job-failed', { bubbles: true, detail: { data: p } }));
                    if (window.dashToast) window.dashToast('danger', this.line);
                }
                // status 'idle' → leave state as-is; job hasn't reported yet.
            },
        });
        var __jpRegister = function () { window.Alpine.data('dashJobProgress', __jpFactory); };
        if (window.Alpine && typeof window.Alpine.data === 'function') {
            __jpRegister();
        } else {
            document.addEventListener('alpine:init', __jpRegister);
        }
    })();
</script>
@endpush
@endonce
