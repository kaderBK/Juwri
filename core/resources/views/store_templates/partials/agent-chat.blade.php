{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    ZERO-TOUCH Track A — on-menu agent chat + voice widget.

    Usage: @include('store_templates.partials.agent-chat', ['store' => $store])
    Renders NOTHING unless the owner enabled the widget (agent_chat_enabled)
    AND the plan carries ai_chatbot (App\Support\AgentChatWidget::enabled).
    Safe to drop into every Blade theme; JS-runtime themes get the data seam
    (AgentChatWidget::seam) instead.

    - Theme-agnostic vanilla JS (customer themes don't load Alpine); the logic
      lives in the versioned asset assets/js/agent-chat-widget.js — only the
      config bootstrap is inline (nonce'd, CSP-safe).
    - RTL-safe (logical properties only), mobile-first, no style attributes.
    - Hidden until JS boots (root carries `hidden`) → no-JS visitors see nothing.
    - Voice input is layered at runtime: Web Speech API → MediaRecorder POST to
      agent.stt (only when Track B's route exists) → mic hidden entirely.
--}}
@php
    $__acPost = $store ?? null;
@endphp
@if(\App\Support\AgentChatWidget::enabled($__acPost))
@php
    $__acOpts   = store_options($__acPost->id);
    $__acLabel  = (string) (($__acOpts->agent_chat_welcome ?? '') ?: ___('Chat & order'));
    $__acHex    = ltrim((string) ($__acPost->color ?: '#10b981'), '#');
    if (strlen($__acHex) === 3) { $__acHex = $__acHex[0].$__acHex[0].$__acHex[1].$__acHex[1].$__acHex[2].$__acHex[2]; }
    $__acRgb    = (strlen($__acHex) === 6 && ctype_xdigit($__acHex)) ? array_map('hexdec', str_split($__acHex, 2)) : [16, 185, 129];
    $__acLum    = (0.299 * $__acRgb[0] + 0.587 * $__acRgb[1] + 0.114 * $__acRgb[2]) / 255;
    $__acAccent = (strlen($__acHex) === 6 && ctype_xdigit($__acHex)) ? '#'.$__acHex : '#10b981';
    $__acFg     = $__acLum > 0.55 ? '#111318' : '#ffffff';
    $__acStrings = \App\Support\AgentChatWidget::strings();
    $__acCfg = [
        'endpoint'         => route('agent.chat.message', $__acPost->id),
        // A↔B seam: null until Track B registers agent.stt; the widget then
        // offers the MediaRecorder voice fallback automatically.
        'stt'              => \App\Support\AgentChatWidget::sttEndpoint($__acPost),
        'csrf'             => csrf_token(),
        'locale'           => str_replace('_', '-', (string) app()->getLocale()),
        'storeId'           => $__acPost->id,
        'maxRecordSeconds' => 15,
        'maxUploadBytes'   => 2 * 1024 * 1024,
        'strings'          => $__acStrings,
    ];
@endphp
<style nonce="{{ csp_nonce() }}">
    {{-- Two hooks for host themes; both default to a no-op, and a theme opts in
         from its own stylesheet (the FAB renders outside the theme's root
         element, so these must be declared on :root — each theme's stylesheet
         only loads on that theme, so the scoping still holds).

         --agent-fab-offset      lifts the FAB clear of a bottom bar.
         --agent-fab-visibility  suppresses it entirely while a sheet/drawer or
                                 other overlay owns the bottom of the screen.

         Both are plain custom properties, so a theme can drive them off its own
         existing state classes with :root:has(...) — no theme JS, no inline
         styles, nothing for the CSP to block. `visibility` also disables
         pointer events and inherits to the panel, and the widget's JS only ever
         touches the `hidden` property, so the two never fight. --}}
    #agent-chat{--ac-accent:{{ $__acAccent }};--ac-accent-fg:{{ $__acFg }};
        position:fixed;inset-inline-end:16px;inset-block-end:calc(16px + var(--agent-fab-offset, 0px));z-index:9999;
        visibility:var(--agent-fab-visibility, visible);
        transition:inset-block-end .3s cubic-bezier(.4,0,.2,1);
        font-family:system-ui,-apple-system,"Segoe UI",sans-serif;line-height:1.45}
    #agent-chat *{box-sizing:border-box}
    {{-- author display rules would beat the UA [hidden] style — pin it --}}
    #agent-chat[hidden],#agent-chat [hidden]{display:none!important}
    .agent-chat__launcher{display:flex;align-items:center;gap:8px;border:0;border-radius:999px;
        padding:12px 18px;background:var(--ac-accent);color:var(--ac-accent-fg);font-weight:700;font-size:14px;
        cursor:pointer;box-shadow:0 6px 20px rgba(0,0,0,.3)}
    .agent-chat__launcher:active{transform:translateY(1px)}
    .agent-chat__launcher svg{flex-shrink:0}
    .agent-chat__panel{position:fixed;inset-inline-end:16px;inset-block-end:calc(80px + var(--agent-fab-offset, 0px));
        display:flex;flex-direction:column;width:min(380px,calc(100vw - 32px));height:min(560px,72vh);
        background:#12151b;color:#e7e9ee;border-radius:16px;overflow:hidden;
        box-shadow:0 10px 40px rgba(0,0,0,.5);text-align:start}
    @media (max-width:480px){.agent-chat__panel{inset-inline-start:16px;width:auto}}
    .agent-chat__head{display:flex;align-items:center;justify-content:space-between;gap:8px;
        padding:12px 14px;background:#0f1115;border-block-end:1px solid #232833}
    .agent-chat__title{font-weight:700;font-size:14px;margin:0}
    .agent-chat__close{border:0;background:transparent;color:#8b93a3;font-size:20px;line-height:1;
        cursor:pointer;padding:4px 8px;border-radius:8px}
    .agent-chat__close:hover{color:#e7e9ee;background:#1a1e26}
    .agent-chat__log{flex:1;overflow-y:auto;padding:12px;font-size:14px;display:flex;flex-direction:column;gap:8px}
    .agent-chat__msg{max-width:85%;padding:8px 12px;border-radius:12px;white-space:pre-wrap;word-break:break-word}
    .agent-chat__msg--user{align-self:flex-end;background:var(--ac-accent);color:var(--ac-accent-fg);
        border-end-end-radius:4px}
    .agent-chat__msg--bot{align-self:flex-start;background:#1c212b;border-end-start-radius:4px}
    .agent-chat__card{align-self:flex-start;max-width:85%;background:#12261d;border:1px solid #1f4633;
        border-radius:12px;padding:10px 12px;font-size:13px}
    .agent-chat__card b{display:block;font-size:14px;margin-block-end:2px}
    .agent-chat__card a{display:inline-block;margin-block-start:8px;padding:7px 12px;border-radius:8px;
        background:var(--ac-accent);color:var(--ac-accent-fg);font-weight:700;text-decoration:none}
    .agent-chat__typing{align-self:flex-start;display:none;gap:4px;padding:10px 12px;background:#1c212b;border-radius:12px}
    .agent-chat__typing.is-on{display:flex}
    .agent-chat__typing i{width:6px;height:6px;border-radius:50%;background:#8b93a3;animation:agentChatBlink 1.2s infinite}
    .agent-chat__typing i:nth-child(2){animation-delay:.2s}
    .agent-chat__typing i:nth-child(3){animation-delay:.4s}
    @keyframes agentChatBlink{0%,80%,100%{opacity:.25}40%{opacity:1}}
    .agent-chat__hint{margin:0 12px 8px;padding:8px 10px;border-radius:10px;background:#3b2a12;color:#ffd9a0;font-size:12px}
    .agent-chat__form{display:flex;align-items:center;gap:6px;padding:8px;border-block-start:1px solid #232833;background:#0f1115}
    .agent-chat__input{flex:1;min-width:0;border:0;background:#161a22;color:#fff;padding:10px 12px;
        border-radius:10px;font-size:14px;outline:none}
    .agent-chat__input:focus{box-shadow:0 0 0 2px var(--ac-accent)}
    .agent-chat__send,.agent-chat__mic{display:flex;align-items:center;justify-content:center;flex-shrink:0;
        width:38px;height:38px;border:0;border-radius:10px;cursor:pointer}
    .agent-chat__send{background:var(--ac-accent);color:var(--ac-accent-fg)}
    .agent-chat__mic{background:#1c212b;color:#aeb6c4}
    .agent-chat__mic.is-live{background:#7f1d1d;color:#fff;animation:agentChatPulse 1.2s infinite}
    .agent-chat__mic.is-busy{opacity:.6;cursor:wait}
    @keyframes agentChatPulse{0%,100%{box-shadow:0 0 0 0 rgba(239,68,68,.55)}60%{box-shadow:0 0 0 8px rgba(239,68,68,0)}}
    [dir=rtl] .agent-chat__send svg{transform:scaleX(-1)}
    @media (prefers-reduced-motion: reduce){.agent-chat__typing i,.agent-chat__mic.is-live{animation:none}}
</style>
<div id="agent-chat" hidden>
    <button type="button" id="agent-chat-btn" class="agent-chat__launcher"
            aria-haspopup="dialog" aria-expanded="false" aria-controls="agent-chat-panel">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
        <span>{{ $__acLabel }}</span>
    </button>
    <div id="agent-chat-panel" class="agent-chat__panel" role="dialog" aria-label="{{ $__acStrings['title'] }}" hidden>
        <div class="agent-chat__head">
            <p class="agent-chat__title">{{ $__acStrings['title'] }}</p>
            <button type="button" id="agent-chat-close" class="agent-chat__close" aria-label="{{ $__acStrings['close'] }}">&times;</button>
        </div>
        <div id="agent-chat-log" class="agent-chat__log" aria-live="polite">
            <div id="agent-chat-typing" class="agent-chat__typing" aria-hidden="true"><i></i><i></i><i></i></div>
        </div>
        <p id="agent-chat-hint" class="agent-chat__hint" role="status" hidden></p>
        <form id="agent-chat-form" class="agent-chat__form">
            <button type="button" id="agent-chat-mic" class="agent-chat__mic" hidden
                    aria-label="{{ $__acStrings['micStart'] }}" aria-pressed="false" title="{{ $__acStrings['micStart'] }}">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
            </button>
            <input id="agent-chat-input" class="agent-chat__input" autocomplete="off" maxlength="1000"
                   enterkeyhint="send" placeholder="{{ $__acStrings['placeholder'] }}">
            <button type="submit" id="agent-chat-send" class="agent-chat__send" aria-label="{{ $__acStrings['send'] }}" title="{{ $__acStrings['send'] }}">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
            </button>
        </form>
    </div>
</div>
<script nonce="{{ csp_nonce() }}">
    window.__agentChatCfg = @json($__acCfg);
</script>
<script src="{{ asset('assets/js/agent-chat-widget.js') }}?v={{ config('appinfo.version') }}.{{ @filemtime(public_path('assets/js/agent-chat-widget.js')) }}" defer></script>
@endif
