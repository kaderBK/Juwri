{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Shared base skin for the .mba-* bottom-sheet components (auth sheet + wallet
     sheet). Included with @once by each, so it loads exactly once whether one or
     both sheets are on the page. Themed through --mba-* CSS vars. --}}
@once
<style nonce="{{ csp_nonce() }}">
    .mba-scrim{position:fixed;inset:0;z-index:2147483000;display:flex;align-items:flex-end;justify-content:center;
        background:rgba(15,17,26,.5);opacity:0;transition:opacity .2s ease;padding:0}
    .mba-scrim.is-open{opacity:1}
    @media (min-width:600px){.mba-scrim{align-items:center;padding:20px}}
    .mba-sheet{width:100%;max-width:var(--mba-max,420px);background:var(--mba-surface,#fff);color:var(--mba-ink,#1a1c22);
        border-radius:var(--mba-radius,22px) var(--mba-radius,22px) 0 0;padding:22px 20px calc(22px + env(safe-area-inset-bottom));
        box-shadow:0 -12px 40px -12px rgba(0,0,0,.35);position:relative;transform:translateY(16px);transition:transform .22s ease;
        max-height:92vh;overflow-y:auto;overflow-x:hidden;text-align:start}
    .mba-scrim.is-open .mba-sheet{transform:none}
    .mba-sheet:focus,.mba-sheet:focus-visible{outline:none}
    @media (min-width:600px){.mba-sheet{border-radius:var(--mba-radius,22px)}}
    .mba-x{position:absolute;inset-inline-end:14px;top:12px;width:34px;height:34px;border:0;background:transparent;
        font-size:26px;line-height:1;color:var(--mba-muted,#8a8f9c);cursor:pointer;border-radius:50%}
    .mba-x:hover{background:var(--mba-hair,rgba(0,0,0,.06))}
    .mba-title{margin:2px 0 14px;font-size:1.25rem;font-weight:700;color:var(--mba-ink,#1a1c22)}
    .mba-tabs{display:flex;gap:6px;background:var(--mba-hair,rgba(0,0,0,.05));border-radius:100px;padding:4px;margin-bottom:16px}
    .mba-tab{flex:1;border:0;background:transparent;padding:9px 4px;border-radius:100px;font:inherit;font-size:13px;
        font-weight:600;color:var(--mba-muted,#8a8f9c);cursor:pointer}
    .mba-tab.is-on{background:var(--mba-surface,#fff);color:var(--mba-ink,#1a1c22);box-shadow:0 2px 8px -3px rgba(0,0,0,.25)}
    .mba-form{display:flex;flex-direction:column;gap:10px}
    .mba-in{width:100%;height:48px;border:1px solid var(--mba-border,rgba(0,0,0,.14));border-radius:var(--mba-in-radius,12px);
        background:var(--mba-in-bg,#fff);color:var(--mba-ink,#1a1c22);padding:0 14px;font:inherit;font-size:15px;outline:none}
    .mba-in:focus{border-color:var(--mba-accent,#6b4ea8);box-shadow:0 0 0 3px var(--mba-accent-ring,rgba(107,78,168,.18))}
    .mba-code{letter-spacing:.5em;text-align:center;font-weight:700}
    .mba-btn{height:50px;border:0;border-radius:var(--mba-btn-radius,100px);background:var(--mba-accent,#6b4ea8);
        color:var(--mba-on-accent,#fff);font:inherit;font-size:15px;font-weight:700;cursor:pointer;margin-top:2px;width:100%}
    .mba-btn:hover{filter:brightness(1.05)}
    .mba-btn:disabled{opacity:.6;cursor:default}
    .mba-sub{margin:0 0 10px;font-size:13.5px;color:var(--mba-muted,#8a8f9c)}
    .mba-msg{margin:0 0 12px;font-size:13.5px;font-weight:600;border-radius:10px;padding:9px 12px}
    .mba-err{background:rgba(220,38,38,.1);color:#dc2626}
    .mba-ok{background:rgba(22,163,74,.12);color:#16a34a}
    @media (prefers-reduced-motion:reduce){.mba-scrim,.mba-sheet{transition:none}}
</style>
@endonce
