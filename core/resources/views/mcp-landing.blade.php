{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  Public /mcp landing page (D2-9). EXTENDS the home landing layout so it shares
  the EXACT site nav, footer, Syne/Instrument fonts, purple theme, theme toggle,
  language picker and light/dark tokens — one design language with the home page.
  The controller passes activeTheme + languages + a BRAND-AWARE settings object,
  so the layout renders under the test runner and never leaks the platform name
  on a reseller host. Page content lives in @section('content'), scoped under
  `.mcp-page` and namespaced (`mcp-card`) so it never collides with the layout's
  Bootstrap; buttons use the home `.btn.btn-primary` pill. Every ___() source
  string is registered + translated across 11 locales.
--}}
@extends('marketing.layout')

@php
    $totalTools = array_sum(array_column($servers, 'toolCount'));
    $faqs = [
        ['q' => ___('What is MCP?'), 'a' => ___('The Model Context Protocol is an open standard that lets AI agents (Claude, Cursor, ChatGPT and others) call real tools on a server instead of guessing. Point a client at one of our five endpoints and the agent can browse menus, place orders, read your sales, or validate a theme against live :brand data.', ['brand' => $brandName])],
        ['q' => ___('Which clients can connect?'), 'a' => ___('Any MCP-capable client over Streamable HTTP: Claude Code, Claude (web and desktop), Cursor, VS Code, ChatGPT developer mode, Windsurf, Zed, or raw curl. See the Connect your client section for the exact one-command setup for each.')],
        ['q' => ___('Which server do I need?'), 'a' => ___('Shopping agents use the public Catalog and Storefront servers. Store owners use the Admin server with an API token. Theme and app developers use the public Dev server. Diners use the Customer server with their own login. The Which server do you need? cards map each audience to its server.')],
        ['q' => ___('Is it free?'), 'a' => ___('Connecting is free. The Catalog and Dev servers are fully public: no signup, no key. The Admin, Storefront and Customer servers need a token you already own as an owner, store, or signed-in diner.')],
        ['q' => ___('Do I need an API key?'), 'a' => ___('Not for two of the five servers. Catalog (discover restaurants) and Dev (build themes) are public and need no key at all. The other three authenticate with a token you generate in your dashboard or receive when you sign in.')],
        ['q' => ___('How are writes protected?'), 'a' => ___('Every write is scoped to the token owner and audited as via: mcp. Admin tokens are ability-scoped: grant only what the agent needs and tools it lacks are hidden from the tool list entirely. Keep your client\'s human-confirmation prompt on so no tool runs without you seeing it.')],
        ['q' => ___('What are the rate limits?'), 'a' => ___('Each token is limited to 60 requests/minute and 1000/hour (the Storefront server allows 120/minute instead). Bursts beyond that return HTTP 429. Back off and retry.')],
        ['q' => ___('Can I build my own theme or app?'), 'a' => ___('Yes. Themes: download the starter theme, build with the Liquid reference, and validate with the Dev server\'s validate_theme tool before submitting to the marketplace. Apps: register a partner app for OAuth access, webhooks, embedded dashboard surfaces and usage billing. Both guides live under /developers.')],
        ['q' => ___('Can my white-label domain use it?'), 'a' => ___('Yes. Every server, name and URL on this page is brand-aware: on a reseller domain the endpoints advertise the reseller\'s own brand and slugged server names, never the platform\'s. The discovery manifest at /.well-known/mcp.json is brand-aware too.')],
        ['q' => ___('How do I get my listed data removed?'), 'a' => ___('The public Catalog server only lists active, publicly-listed stores and never exposes owner PII. Turn off public listing for a store in its dashboard settings and it stops appearing in catalog results.')],
    ];
@endphp

@section('title', $brandName.' MCP '.___('for any AI agent'))
@section('meta_description', ___('Connect Claude, Claude Code, Cursor, ChatGPT and any MCP client to :brand. Five servers for owners, shopping agents, theme developers and diners. Two need no signup at all.', ['brand' => $brandName]))
@section('canonical', url('/mcp'))

@push('structured_data')
<script type="application/ld+json">
@php
    $ld = ['@context' => 'https://schema.org', '@graph' => [
        ['@type' => 'WebPage', 'name' => $brandName.' MCP', 'description' => ___('Connect AI agents to :brand over the Model Context Protocol. Five servers; two need no signup.', ['brand' => $brandName]), 'url' => url('/mcp')],
        ['@type' => 'BreadcrumbList', 'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => $brandName, 'item' => url('/')],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'MCP', 'item' => url('/mcp')],
        ]],
        ['@type' => 'FAQPage', 'mainEntity' => \Juwri\Core\Support\ViewHelpers::faqLdRequired($faqs)],
    ]];
@endphp
{!! json_ld($ld) !!}
</script>
@endpush

@push('styles')
<style nonce="{{ csp_nonce() }}">
    /* /mcp content — scoped, keyed off the home theme tokens (purple #8b3dff,
       Syne inherited from the layout body). Light + dark off the layout data-theme. */
    .mcp-page {
        --mcp-acc: #8b3dff;
        --mcp-surface: #ffffff; --mcp-surface-2: #f4f5f8; --mcp-line: #ececf1;
        --mcp-text: #0a0910; --mcp-text-2: #6c6b70; --mcp-code-bg: #14121c;
        --mcp-green: #16a34a; --mcp-green-bg: #e9f7ef;
        --mcp-mono: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace;
    }
    :root[data-theme="dark"] .mcp-page {
        --mcp-acc: #a06bff; --mcp-surface: #17151f; --mcp-surface-2: #1e1b2b; --mcp-line: rgba(255,255,255,.09);
        --mcp-text: #f3f1f9; --mcp-text-2: #a8a5ba; --mcp-code-bg: #0b0913;
        --mcp-green: #4ade80; --mcp-green-bg: rgba(74,222,128,.12);
    }
    .mcp-page { color: var(--mcp-text-2); }
    .mcp-page section { margin: 0 0 clamp(64px, 8vw, 104px); scroll-margin-top: 96px; }
    .mcp-page h1, .mcp-page h2, .mcp-page h3 { color: var(--mcp-text); }
    .mcp-page .display { font-weight: 600; letter-spacing: -.02em; line-height: 1.04; margin: 0; text-wrap: balance; }
    .mcp-page h1.display { font-size: clamp(2.6rem, 1.4rem + 4.8vw, 4.4rem); }
    .mcp-page h2.display { font-size: clamp(1.9rem, 1.2rem + 2.2vw, 2.9rem); margin-bottom: 12px; }
    .mcp-page h2 .tint { color: var(--mcp-acc); }
    .mcp-page .sub { color: var(--mcp-text-2); font-size: 1.125rem; line-height: 1.55; max-width: 62ch; margin: 0 0 28px; }
    .mcp-page p, .mcp-page li { color: var(--mcp-text-2); }
    .mcp-page strong { color: var(--mcp-text); }
    .mcp-page a:not(.ui-btn) { color: var(--mcp-acc); text-decoration: none; font-weight: 600; }
    .mcp-page a:not(.ui-btn):hover { text-decoration: underline; }

    .mcp-page code { font-family: var(--mcp-mono); font-size: .82em; background: color-mix(in oklab, var(--mcp-acc) 10%, transparent); color: var(--mcp-text); padding: 2px 6px; border-radius: 6px; }
    .mcp-page pre { position: relative; margin: 0 0 12px; background: var(--mcp-code-bg); color: #e8e6f4; border-radius: 14px; padding: 15px 46px 15px 16px; overflow-x: auto; font-family: var(--mcp-mono); font-size: .82rem; line-height: 1.6; direction: ltr; text-align: left; }
    .mcp-page pre code { background: none; padding: 0; color: inherit; }
    /* The copy button + the right-edge fade both live on the NON-scrolling
       .codeblock wrapper (siblings of the <pre>), NOT inside the <pre>. Before,
       the fade was a pre::after: it scrolled WITH the command and ended up over
       the middle of the code, hiding text. On the wrapper it stays pinned to the
       right edge, fading the code cleanly under the always-visible copy button
       no matter how far the command is scrolled. min-width keeps the button from
       resizing when the label swaps Copy↔Copied. */
    /* The code inside is always LTR (pre { direction: ltr }); force the whole
       code-block island LTR too, so on an RTL (Arabic) page the copy button and
       the right-edge fade stay on the RIGHT — the side the LTR command scrolls
       toward — instead of flipping to the left via inset-inline-end. */
    .mcp-page .codeblock { position: relative; margin: 0 0 12px; direction: ltr; }
    .mcp-page .codeblock pre { margin: 0; }
    .mcp-page .codeblock::after { content: ""; position: absolute; z-index: 1; inset-block: 1px; inset-inline-end: 1px; width: 90px; pointer-events: none; border-radius: 14px; background: linear-gradient(to left in oklab, var(--mcp-code-bg) 42%, transparent); }
    .mcp-page .copy { position: absolute; z-index: 2; top: 8px; inset-inline-end: 8px; min-width: 3.6em; text-align: center; background: rgba(255,255,255,.08); color: #cfcde0; border: 0; border-radius: 999px; box-shadow: inset 0 0 0 1px rgba(255,255,255,.12); font-size: 11px; font-weight: 600; padding: 5px 11px; cursor: pointer; }
    .mcp-page .copy:hover { color: #fff; background: rgba(255,255,255,.16); }

    .mcp-page table { width: 100%; border-collapse: collapse; margin: 12px 0; font-size: 14px; }
    .mcp-page th, .mcp-page td { text-align: start; padding: 12px; vertical-align: top; }
    .mcp-page tbody tr td { border-top: 1px solid var(--mcp-line); }
    .mcp-page thead th { color: var(--mcp-text-2); font-weight: 600; font-size: 12px; padding-bottom: 8px; }
    .mcp-page td:last-child { font-variant-numeric: tabular-nums; }

    .mcp-page .badge { display: inline-block; font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 999px; }
    .mcp-page .badge.public { background: var(--mcp-green-bg); color: var(--mcp-green); }
    .mcp-page .badge.token { background: color-mix(in oklab, var(--mcp-acc) 15%, transparent); color: var(--mcp-acc); }


    .mcp-page .hero { text-align: center; padding: clamp(24px, 5vw, 56px) 0 clamp(32px, 4vw, 48px); }
    .mcp-page .tiles { display: flex; justify-content: center; gap: 10px; margin-bottom: 28px; }
    .mcp-page .tile { width: 56px; height: 56px; border-radius: 16px; display: flex; align-items: center; justify-content: center; font-family: var(--mcp-mono); font-weight: 600; font-size: 14px; color: var(--mcp-text-2); background: var(--mcp-surface-2); box-shadow: inset 0 0 0 1px var(--mcp-line); transform: rotate(-4deg); }
    .mcp-page .tile:nth-child(2n) { transform: rotate(3deg); }
    .mcp-page .tile.core { width: 70px; height: 70px; border-radius: 20px; transform: none; background: linear-gradient(160deg, color-mix(in oklab, var(--mcp-acc) 78%, white), var(--mcp-acc)); color: #fff; font-weight: 700; box-shadow: 0 12px 28px -8px color-mix(in oklab, var(--mcp-acc) 55%, transparent); }
    .mcp-page .hero .lead { font-size: 1.2rem; line-height: 1.5; max-width: 46ch; margin: 20px auto 0; }
    .mcp-page .hero .lead b { color: var(--mcp-text); }
    .mcp-page .hero .cta { margin-top: 28px; }
    .mcp-page .clients-strip { display: flex; flex-wrap: wrap; justify-content: center; gap: 8px; margin-top: 32px; }
    .mcp-page .chip { font-size: 12.5px; font-weight: 500; padding: 7px 14px; border-radius: 999px; color: var(--mcp-text-2); background: var(--mcp-surface); box-shadow: inset 0 0 0 1px var(--mcp-line); }

    .mcp-page .install, .mcp-page .mcp-card, .mcp-page .agent-install { background: var(--mcp-surface); border-radius: 20px; box-shadow: 0 1px 2px rgba(10,9,16,.04), 0 0 0 1px var(--mcp-line); }
    :root[data-theme="dark"] .mcp-page .install, :root[data-theme="dark"] .mcp-page .mcp-card, :root[data-theme="dark"] .mcp-page .agent-install { box-shadow: inset 0 0 0 1px var(--mcp-line); }
    .mcp-page .install { padding: 26px 26px 30px; }
    .mcp-page .mcp-card { padding: 22px 24px; min-width: 0; }
    .mcp-page .mcp-card.t-amber { background: linear-gradient(168deg, color-mix(in oklab, var(--mcp-acc) 7%, var(--mcp-surface)), var(--mcp-surface) 60%); }
    .mcp-page .mcp-card.t-green { background: linear-gradient(168deg, color-mix(in oklab, var(--mcp-green) 8%, var(--mcp-surface)), var(--mcp-surface) 60%); }
    .mcp-page .mcp-card.t-steel { background: linear-gradient(168deg, var(--mcp-surface-2), var(--mcp-surface) 60%); }
    .mcp-page .mcp-card h3 { display: flex; align-items: center; gap: 10px; font-size: 1.0625rem; }
    .mcp-page .mcp-card .out { color: var(--mcp-text-2); font-size: 14px; }
    .mcp-page .mcp-card ol { padding-inline-start: 20px; margin: 12px 0; }
    .mcp-page .mcp-card ol li { margin-bottom: 8px; font-size: 14px; }
    .mcp-page .avatar { width: 32px; height: 32px; border-radius: 9px; flex: 0 0 auto; display: inline-flex; align-items: center; justify-content: center; background: color-mix(in oklab, var(--mcp-acc) 14%, transparent); color: var(--mcp-acc); font-family: var(--mcp-mono); font-weight: 700; font-size: 12px; }
    .mcp-page .meta { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin: 12px 0; color: var(--mcp-text-2); font-size: 13px; }
    .mcp-page .links { font-size: 14px; }

    .mcp-page .grid { display: grid; gap: 18px; }
    .mcp-page .grid.cols-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .mcp-page .grid.cols-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .mcp-page .prompt-card { display: flex; flex-direction: column; gap: 14px; }
    .mcp-page .prompt-card .q { font-size: 15.5px; font-weight: 600; color: var(--mcp-text); margin: 0; line-height: 1.45; }
    .mcp-page .prompt-card .foot { margin-top: auto; display: flex; align-items: center; justify-content: space-between; gap: 10px; }

    .mcp-page .tabs { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 0 26px; }
    .mcp-page .tabs button { background: transparent; border: 0; border-radius: 999px; cursor: pointer; color: var(--mcp-text-2); padding: 8px 15px; font: inherit; font-size: 13px; font-weight: 600; box-shadow: inset 0 0 0 1px var(--mcp-line); }
    .mcp-page .tabs button:hover { color: var(--mcp-text); }
    .mcp-page .tabs button[aria-selected="true"] { background: var(--mcp-acc); color: #fff; box-shadow: none; }
    .mcp-page .steps { display: grid; grid-template-columns: 1fr 1.4fr 1fr; gap: 28px; align-items: start; }
    .mcp-page .step { min-width: 0; }
    .mcp-page .step .n { width: 25px; height: 25px; border-radius: 999px; margin-bottom: 12px; display: flex; align-items: center; justify-content: center; background: color-mix(in oklab, var(--mcp-acc) 14%, transparent); color: var(--mcp-acc); font-size: 12px; font-weight: 700; }
    .mcp-page .step h3 { font-size: 15px; margin: 0 0 6px; }
    .mcp-page .step p { color: var(--mcp-text-2); font-size: 14px; margin: 0 0 12px; }
    .mcp-page .tabpanel[hidden] { display: none; }
    .mcp-page .escape { color: var(--mcp-text-2); font-size: 14px; text-align: center; margin-top: 20px; }
    .mcp-page .agent-install { margin-top: 18px; padding: 20px 22px; background: linear-gradient(168deg, color-mix(in oklab, var(--mcp-acc) 9%, var(--mcp-surface)), var(--mcp-surface) 60%); }
    .mcp-page .agent-install__head { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 14px; }
    .mcp-page .agent-install__head h3 { margin: 0 0 3px; font-size: 1rem; }
    .mcp-page .agent-install__head .out { margin: 0; color: var(--mcp-text-2); font-size: 13.5px; }

    .mcp-page details.acc { border-top: 1px solid var(--mcp-line); }
    .mcp-page details.acc:last-of-type { border-bottom: 1px solid var(--mcp-line); }
    .mcp-page details.acc summary { cursor: pointer; list-style: none; position: relative; padding: 18px 0; padding-inline-end: 34px; font-weight: 700; font-size: 15.5px; color: var(--mcp-text); }
    .mcp-page details.acc summary::-webkit-details-marker { display: none; }
    .mcp-page details.acc summary::after { content: "+"; position: absolute; inset-inline-end: 4px; top: 50%; transform: translateY(-50%); color: var(--mcp-text-2); font-size: 20px; font-weight: 400; }
    .mcp-page details.acc[open] summary::after { content: "\2212"; }
    .mcp-page details.acc > :not(summary) { margin-bottom: 18px; }
    .mcp-page .note { background: var(--mcp-surface); border-radius: 14px; padding: 15px 18px; margin: 18px 0; box-shadow: inset 0 0 0 1px var(--mcp-line); border-inline-start: 3px solid var(--mcp-acc); }
    .mcp-page .note.warn { border-inline-start-color: #ef4444; }

    @media (max-width: 900px) {
        .mcp-page .grid.cols-2, .mcp-page .grid.cols-3, .mcp-page .steps { grid-template-columns: minmax(0, 1fr); }
        .mcp-page .tile { width: 46px; height: 46px; } .mcp-page .tile.core { width: 56px; height: 56px; }
    }
</style>
@endpush

@section('content')
<div class="mcp-page">
    <div class="mk-wrap">
        {{-- ============================ HERO ============================ --}}
        <section id="hero" class="hero" aria-labelledby="hero-h">
            <div class="tiles" aria-hidden="true">
                <span class="tile">&#123;&#125;</span>
                <span class="tile">&gt;_</span>
                <span class="tile core">MCP</span>
                <span class="tile">[]</span>
                <span class="tile">()</span>
            </div>
            <h1 id="hero-h" class="display">{{ $brandName }} MCP<br>{{ ___('for any AI agent') }}</h1>
            <p class="lead">{{ ___('Five servers connect agents to live menus, orders, analytics and theme tooling.') }} <b>{{ ___('Two need no signup at all.') }}</b></p>
            <p class="cta"><a class="ui-btn" href="#clients">{{ ___('Connect in under a minute') }}</a></p>
            <div class="clients-strip" aria-label="{{ ___('Supported clients') }}">
                <span class="chip">Claude</span>
                <span class="chip">Claude Code</span>
                <span class="chip">Cursor</span>
                <span class="chip">ChatGPT</span>
                <span class="chip">VS Code</span>
                <span class="chip">Windsurf</span>
            </div>
        </section>

        {{-- ================= INSTALL CARD (client tabs + steps) ================= --}}
        <section id="clients" aria-labelledby="clients-h">
            <h2 id="clients-h" class="display">{{ ___('Connect your') }} <span class="tint">{{ ___('client') }}</span></h2>
            <p class="sub">{{ ___('Every snippet targets the public Catalog server, so it works verbatim with zero setup. Swap in any endpoint below; token servers add') }} <code>--header "Authorization: Bearer YOUR_TOKEN"</code>.</p>

            <div class="install">
                <div class="tabs" role="tablist" aria-label="{{ ___('MCP clients') }}">
                    <button role="tab" id="tab-claudecode" aria-controls="panel-claudecode" aria-selected="true">Claude Code</button>
                    <button role="tab" id="tab-claude" aria-controls="panel-claude" aria-selected="false">Claude (web/desktop)</button>
                    <button role="tab" id="tab-cursor" aria-controls="panel-cursor" aria-selected="false">Cursor</button>
                    <button role="tab" id="tab-vscode" aria-controls="panel-vscode" aria-selected="false">VS Code</button>
                    <button role="tab" id="tab-chatgpt" aria-controls="panel-chatgpt" aria-selected="false">ChatGPT</button>
                    <button role="tab" id="tab-other" aria-controls="panel-other" aria-selected="false">Windsurf / Zed</button>
                    <button role="tab" id="tab-curl" aria-controls="panel-curl" aria-selected="false">{{ ___('Any client / curl') }}</button>
                </div>

                <div class="steps">
                    <div class="step">
                        <div class="n">1</div>
                        <h3>{{ ___('Copy the endpoint') }}</h3>
                        <p>{{ ___('The public Catalog URL. You will hand it to your client in step 2.') }}</p>
                        <div class="codeblock"><pre><code>{{ $servers['catalog']['url'] }}</code></pre><button class="copy" type="button">{{ ___('Copy') }}</button></div>
                    </div>

                    <div class="step">
                        <div class="n">2</div>
                        <h3>{{ ___('Add it to your client') }}</h3>

                        <div class="tabpanel" role="tabpanel" id="panel-claudecode" aria-labelledby="tab-claudecode">
                            <p>{{ ___('One command in your terminal, then run') }} <code>/mcp</code> {{ ___('to confirm it connected:') }}</p>
                            <div class="codeblock"><pre><code>claude mcp add --transport http {{ $servers['catalog']['name'] }} {{ $servers['catalog']['url'] }}</code></pre><button class="copy" type="button">{{ ___('Copy') }}</button></div>
                            <p>{{ ___('For a token server (e.g. Admin), pass the header:') }}</p>
                            <div class="codeblock"><pre><code>claude mcp add --transport http {{ $servers['admin']['name'] }} {{ $servers['admin']['url'] }} --header "Authorization: Bearer YOUR_TOKEN"</code></pre><button class="copy" type="button">{{ ___('Copy') }}</button></div>
                        </div>

                        <div class="tabpanel" role="tabpanel" id="panel-claude" aria-labelledby="tab-claude" hidden>
                            <p>{{ ___('In Claude on the web or desktop:') }} <strong>{{ ___('Settings') }} &rarr; {{ ___('Connectors') }} &rarr; {{ ___('Add custom connector') }}</strong>, {{ ___('then paste the endpoint URL from step 1.') }}</p>
                            <p><a class="ui-btn ui-btn--secondary" href="https://claude.ai/customize/connectors?modal=add-custom-connector" target="_blank" rel="noopener">{{ ___('Open the Add-connector dialog') }} &rarr;</a></p>
                        </div>

                        <div class="tabpanel" role="tabpanel" id="panel-cursor" aria-labelledby="tab-cursor" hidden>
                            <p>{{ ___('One-click install (opens Cursor):') }}</p>
                            <p><a class="ui-btn" href="{{ $servers['catalog']['cursorDeeplink'] }}">{{ ___('Add to Cursor') }} &rarr;</a></p>
                            <p>{{ ___('Or add it manually to') }} <code>~/.cursor/mcp.json</code>:</p>
                            <div class="codeblock"><pre><code>{
  "mcpServers": {
    "{{ $servers['catalog']['name'] }}": { "url": "{{ $servers['catalog']['url'] }}" }
  }
}</code></pre><button class="copy" type="button">{{ ___('Copy') }}</button></div>
                        </div>

                        <div class="tabpanel" role="tabpanel" id="panel-vscode" aria-labelledby="tab-vscode" hidden>
                            <p>{{ ___('One-click install (opens VS Code):') }}</p>
                            <p><a class="ui-btn" href="{{ $servers['catalog']['vscodeDeeplink'] }}">{{ ___('Add to VS Code') }} &rarr;</a></p>
                            <p>{{ ___('Or add it manually to') }} <code>.vscode/mcp.json</code>. {{ ___('Note VS Code uses a different schema') }} (<code>type</code> + <code>servers</code>):</p>
                            <div class="codeblock"><pre><code>{
  "servers": {
    "{{ $servers['catalog']['name'] }}": { "type": "http", "url": "{{ $servers['catalog']['url'] }}" }
  }
}</code></pre><button class="copy" type="button">{{ ___('Copy') }}</button></div>
                        </div>

                        <div class="tabpanel" role="tabpanel" id="panel-chatgpt" aria-labelledby="tab-chatgpt" hidden>
                            <p>{{ ___('Enable developer mode in ChatGPT, add a custom connector, and paste the server URL from step 1.') }}</p>
                        </div>

                        <div class="tabpanel" role="tabpanel" id="panel-other" aria-labelledby="tab-other" hidden>
                            <p>{{ ___('Windsurf, Zed and other clients that expect a command wrap the HTTP endpoint with') }} <code>mcp-remote</code>:</p>
                            <div class="codeblock"><pre><code>{
  "command": "npx",
  "args": ["-y", "mcp-remote", "{{ $servers['catalog']['url'] }}"]
}</code></pre><button class="copy" type="button">{{ ___('Copy') }}</button></div>
                        </div>

                        <div class="tabpanel" role="tabpanel" id="panel-curl" aria-labelledby="tab-curl" hidden>
                            <p>{{ ___('The servers speak JSON-RPC 2.0 over a single-response Streamable HTTP POST. List the tools:') }}</p>
                            <div class="codeblock"><pre><code>curl -s {{ $servers['catalog']['url'] }} \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'</code></pre><button class="copy" type="button">{{ ___('Copy') }}</button></div>
                            <p>{{ ___('Call a tool (add') }} <code>-H "Authorization: Bearer YOUR_TOKEN"</code> {{ ___('for token servers):') }}</p>
                            <div class="codeblock"><pre><code>curl -s {{ $servers['catalog']['url'] }} \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"search_stores","arguments":{"query":"pizza"}}}'</code></pre><button class="copy" type="button">{{ ___('Copy') }}</button></div>
                        </div>
                    </div>

                    <div class="step">
                        <div class="n">3</div>
                        <h3>{{ ___('Ask your first question') }}</h3>
                        <p>{{ ___('The agent calls real tools and answers with live data. Try this one:') }}</p>
                        <p><a class="ui-btn ui-btn--secondary" href="https://claude.ai/new?q={{ rawurlencode(___('Find me the best-rated shawarma nearby and show the menu.')) }}" target="_blank" rel="noopener">{{ ___('Open in Claude') }} &rarr;</a></p>
                    </div>
                </div>
            </div>

            @php
                $agentPrompt = 'Set up the '.$brandName.' Catalog MCP server for me. Run: claude mcp add --transport http '
                    .$servers['catalog']['name'].' '.$servers['catalog']['url']
                    .' then confirm it connected and list its tools.';
            @endphp
            <div class="agent-install">
                <div class="agent-install__head">
                    <span class="avatar" aria-hidden="true">AI</span>
                    <div>
                        <h3>{{ ___('Or let your coding agent set it up') }}</h3>
                        <p class="out">{{ ___('Paste this into Claude Code, Cursor, or any coding agent. It adds the server for you, no terminal needed.') }}</p>
                    </div>
                </div>
                <div class="codeblock"><pre dir="ltr"><code>{{ $agentPrompt }}</code></pre><button class="copy" type="button">{{ ___('Copy') }}</button></div>
            </div>

            <p class="escape">{{ ___('Building or debugging instead? The deep protocol reference lives at') }} <a href="{{ url('/developers') }}#mcp-overview">/developers</a>.</p>
        </section>

        {{-- ==================== SERVER TABLE ==================== --}}
        <section id="quickstart" aria-labelledby="quickstart-h">
            <h2 id="quickstart-h" class="display">{{ ___('Five servers,') }} <span class="tint">{{ ___('one protocol') }}</span></h2>
            <p class="sub">{{ ___(':tools tools across :servers servers. Two are public. All speak JSON-RPC 2.0 over Streamable HTTP POST.', ['tools' => $totalTools, 'servers' => count($servers)]) }}</p>
            <table>
                <thead><tr><th>{{ ___('Server') }}</th><th>{{ ___('Endpoint') }}</th><th>{{ ___('Auth') }}</th><th>{{ ___('Tools') }}</th></tr></thead>
                <tbody>
                @foreach ($servers as $server)
                    <tr>
                        <td><code>{{ $server['name'] }}</code></td>
                        <td><code>{{ $server['url'] }}</code></td>
                        <td><span class="badge {{ $server['isPublic'] ? 'public' : 'token' }}">{{ $server['authLabel'] }}</span></td>
                        <td><a href="#tools-{{ $server['key'] }}">{{ $server['toolCount'] }}</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </section>

        {{-- ========================= AUDIENCES ========================= --}}
        <section id="audiences" aria-labelledby="audiences-h">
            <h2 id="audiences-h" class="display">{{ ___('Which server do') }} <span class="tint">{{ ___('you') }}</span> {{ ___('need?') }}</h2>
            <p class="sub">{{ ___('Pick your role. Each card points at the right server, its auth, and its tools.') }}</p>
            <div class="grid cols-2">
                <div class="mcp-card t-amber">
                    <h3><span class="avatar">OW</span> {{ ___('Store owner') }}</h3>
                    <p class="out">{{ ___('Manage menus, orders, coupons, hours and read your own analytics from your AI assistant.') }}</p>
                    <p class="meta"><span class="badge token">{{ ___('API token') }}</span> <span>{{ ___(':n tools', ['n' => $servers['admin']['toolCount']]) }}</span></p>
                    <div class="codeblock"><pre><code>{{ $servers['admin']['url'] }}</code></pre><button class="copy" type="button">{{ ___('Copy') }}</button></div>
                    <p class="links"><a href="#tools-admin">{{ ___('Admin tools') }} &rarr;</a> &nbsp; <a href="{{ url('/connect-ai') }}">{{ ___('Get a token (Connect AI)') }} &rarr;</a> &nbsp; <a href="{{ url('/api') }}">{{ ___('API keys') }} &rarr;</a></p>
                </div>
                <div class="mcp-card t-green">
                    <h3><span class="avatar">AG</span> {{ ___('Shopping-agent builder') }}</h3>
                    <p class="out">{{ ___('Discover restaurants across the platform, then browse one store and place an unpaid, server-priced order.') }}</p>
                    <p class="meta"><span class="badge public">{{ ___('Catalog: public') }}</span> <span class="badge token">{{ ___('Storefront: token') }}</span> <span>{{ ___(':n tools', ['n' => $servers['catalog']['toolCount'] + $servers['storefront']['toolCount']]) }}</span></p>
                    <div class="codeblock"><pre><code>{{ $servers['catalog']['url'] }}</code></pre><button class="copy" type="button">{{ ___('Copy') }}</button></div>
                    <p class="links"><a href="#tools-catalog">{{ ___('Catalog tools') }} &rarr;</a> &nbsp; <a href="#tools-storefront">{{ ___('Storefront tools') }} &rarr;</a></p>
                </div>
                <div class="mcp-card t-steel">
                    <h3><span class="avatar">DV</span> {{ ___('Theme / app developer') }}</h3>
                    <p class="out">{{ ___('Learn the platform, get the authoritative Liquid reference, and validate your theme with the marketplace\'s own checks, all in your editor.') }}</p>
                    <p class="meta"><span class="badge public">{{ ___('Public') }}</span> <span>{{ ___(':n tools', ['n' => $servers['dev']['toolCount']]) }}</span></p>
                    <div class="codeblock"><pre><code>{{ $servers['dev']['url'] }}</code></pre><button class="copy" type="button">{{ ___('Copy') }}</button></div>
                    <p class="links"><a href="#tools-dev">{{ ___('Dev tools') }} &rarr;</a> &nbsp; <a href="#build">{{ ___('Build themes & apps') }} &rarr;</a></p>
                </div>
                <div class="mcp-card">
                    <h3><span class="avatar">CU</span> {{ ___('Diner / customer') }}</h3>
                    <p class="out">{{ ___('Look up your own orders, track a delivery, and rebuild a past order as a draft from a signed-in assistant.') }}</p>
                    <p class="meta"><span class="badge token">{{ ___('Customer token') }}</span> <span>{{ ___(':n tools', ['n' => $servers['customer']['toolCount']]) }}</span></p>
                    <div class="codeblock"><pre><code>{{ $servers['customer']['url'] }}</code></pre><button class="copy" type="button">{{ ___('Copy') }}</button></div>
                    <p class="links"><a href="#tools-customer">{{ ___('Customer tools') }} &rarr;</a> &nbsp; <a href="{{ url('/developers') }}#customer-auth">{{ ___('How sign-in works') }} &rarr;</a></p>
                </div>
            </div>
        </section>

        {{-- ==================== BUILD: THEMES + APPS ==================== --}}
        <section id="build" aria-labelledby="build-h">
            <h2 id="build-h" class="display">{{ ___('Build for') }} <span class="tint">{{ $brandName }}</span></h2>
            <p class="sub">{{ ___('Ship a storefront theme or a partner app. The public Dev server turns your editor into a platform expert while you build.') }}</p>
            <div class="grid cols-2">
                <div class="mcp-card t-amber">
                    <h3><span class="avatar">TH</span> {{ ___('Create a theme') }}</h3>
                    <p class="out">{{ ___('Liquid-based storefront themes with OS 2.0 sections, a typed settings schema and the full commerce runtime.') }}</p>
                    <ol>
                        <li>{{ ___('Download the starter theme and open the theme guide: package layout, theme.json, sections, and the Drop reference.') }}</li>
                        <li>{{ ___('Connect the Dev server and build with the Liquid reference and section schema answering from your editor.') }}</li>
                        <li>{{ ___('Run validate_theme before you submit: it applies the marketplace\'s own checks and lists every error.') }}</li>
                    </ol>
                    <div class="codeblock"><pre><code>claude mcp add --transport http {{ $servers['dev']['name'] }} {{ $servers['dev']['url'] }}</code></pre><button class="copy" type="button">{{ ___('Copy') }}</button></div>
                    <p class="links"><a class="ui-btn ui-btn--secondary" href="{{ url('/developers/themes') }}">{{ ___('Theme guide') }} &rarr;</a> &nbsp; <a href="{{ url('/developers/themes/starter.zip') }}">starter.zip</a></p>
                </div>
                <div class="mcp-card t-steel">
                    <h3><span class="avatar">AP</span> {{ ___('Build an app') }}</h3>
                    <p class="out">{{ ___('Partner apps extend the dashboard: OAuth access, session tokens, embedded surfaces, webhooks and usage billing.') }}</p>
                    <ol>
                        <li>{{ ___('Read the app guide: OAuth and tokens, scopes, embedded apps and webhook events.') }}</li>
                        <li>{{ ___('Register your partner app, request only the scopes you need, and develop against a sandbox store.') }}</li>
                        <li>{{ ___('Meet the listing and review requirements, then submit for the app marketplace.') }}</li>
                    </ol>
                    <p>{{ ___('While you build, search_docs and fetch_full_doc on the Dev server answer API and webhook questions without leaving the editor.') }}</p>
                    <p class="links"><a class="ui-btn ui-btn--secondary" href="{{ url('/developers/apps') }}">{{ ___('App guide') }} &rarr;</a> &nbsp; <a href="{{ url('/developers') }}">{{ ___('API reference') }} &rarr;</a></p>
                </div>
            </div>
        </section>

        {{-- =========================== TOOLS =========================== --}}
        <section id="tools" aria-labelledby="tools-h">
            <h2 id="tools-h" class="display">{{ ___('Tool') }} <span class="tint">{{ ___('catalog') }}</span></h2>
            <p class="sub">{{ ___(':tools tools across :servers servers. Expand a server to see what its agent can do.', ['tools' => $totalTools, 'servers' => count($servers)]) }} {{ ___('Full JSON schemas live in the') }} <a href="{{ url('/developers') }}#mcp-overview">{{ ___('developer reference') }}</a>.</p>

            <details class="acc" id="tools-catalog">
                <summary>{{ $servers['catalog']['name'] }} ({{ ___(':n tools', ['n' => $servers['catalog']['toolCount']]) }}) <span class="badge public">{{ ___('Public') }}</span></summary>
                <p class="out">{{ ___('Discover restaurants across the platform. Active, publicly-listed stores only; never owner PII.') }}</p>
                <table>
                    <tr><td><code>search_stores</code></td><td>{{ ___('Find restaurants by name or cuisine.') }}</td></tr>
                    <tr><td><code>search_items</code></td><td>{{ ___('Search a dish across every store ("who sells shawarma near me").') }}</td></tr>
                    <tr><td><code>get_store</code></td><td>{{ ___('One store\'s details plus a deep-link hint into its Storefront server.') }}</td></tr>
                </table>
            </details>

            <details class="acc" id="tools-storefront">
                <summary>{{ $servers['storefront']['name'] }} ({{ ___(':n tools', ['n' => $servers['storefront']['toolCount']]) }}) <span class="badge token">{{ ___('Storefront token') }}</span></summary>
                <p class="out">{{ ___('Everything a shopping agent needs at one restaurant.') }} <code>create_order</code> {{ ___('places an unpaid order priced by the server. Never send prices.') }}</p>
                <table>
                    <tr><td><code>search_menu</code></td><td>{{ ___('Search the menu at this store.') }}</td></tr>
                    <tr><td><code>get_item</code></td><td>{{ ___('Full detail for one item (variants, extras, flags).') }}</td></tr>
                    <tr><td><code>validate_cart</code></td><td>{{ ___('Check a cart and get the server-computed total before ordering.') }}</td></tr>
                    <tr><td><code>create_order</code></td><td>{{ ___('Place an unpaid, server-priced order.') }}</td></tr>
                    <tr><td><code>order_status</code></td><td>{{ ___('Current status of an order.') }}</td></tr>
                    <tr><td><code>order_eta</code></td><td>{{ ___('Estimated time until the order is ready.') }}</td></tr>
                    <tr><td><code>order_history</code></td><td>{{ ___('Past orders at this store.') }}</td></tr>
                    <tr><td><code>reorder_last</code></td><td>{{ ___('Rebuild the last order as a fresh cart.') }}</td></tr>
                    <tr><td><code>store_credit_balance</code></td><td>{{ ___('Store credit available to the shopper.') }}</td></tr>
                    <tr><td><code>restaurant_info</code></td><td>{{ ___('Hours, address and ordering options.') }}</td></tr>
                    <tr><td><code>suggest_upsell</code></td><td>{{ ___('Relevant add-ons for the current cart.') }}</td></tr>
                    <tr><td><code>dietary_answer</code></td><td>{{ ___('Answer a dietary question about the menu.') }}</td></tr>
                </table>
            </details>

            <details class="acc" id="tools-admin">
                <summary>{{ $servers['admin']['name'] }} ({{ ___(':n tools', ['n' => $servers['admin']['toolCount']]) }}) <span class="badge token">{{ ___('API token') }}</span></summary>
                <p class="out">{{ ___('The owner\'s toolbox. Tools are ability-gated per token: anything the token can\'t do is hidden from the list. The two CRM tools also need the crm_suite feature. Every write is audited via: mcp.') }}</p>
                <table>
                    <tr><td><code>list_restaurants</code></td><td>{{ ___('Your restaurants.') }}</td></tr>
                    <tr><td><code>get_menu</code></td><td>{{ ___('A restaurant\'s categories and items.') }}</td></tr>
                    <tr><td><code>list_categories</code></td><td>{{ ___('Menu categories with item counts.') }}</td></tr>
                    <tr><td><code>list_orders</code></td><td>{{ ___('Orders, filterable by status/date/search.') }}</td></tr>
                    <tr><td><code>get_order</code></td><td>{{ ___('Full detail for one order.') }}</td></tr>
                    <tr><td><code>get_analytics</code></td><td>{{ ___('Order count and revenue over N days.') }}</td></tr>
                    <tr><td><code>sales_report</code></td><td>{{ ___('Authoritative sales summary for a date range.') }}</td></tr>
                    <tr><td><code>get_restaurant_settings</code></td><td>{{ ___('Profile + ordering settings snapshot.') }}</td></tr>
                    <tr><td><code>list_coupons</code></td><td>{{ ___('Your discount coupons.') }}</td></tr>
                    <tr><td><code>list_customers</code> <span class="badge token">crm_suite</span></td><td>{{ ___('Your CRM customer list.') }}</td></tr>
                    <tr><td><code>get_customer</code> <span class="badge token">crm_suite</span></td><td>{{ ___('One customer\'s full CRM record.') }}</td></tr>
                    <tr><td><code>add_menu_item</code></td><td>{{ ___('Create a menu item.') }}</td></tr>
                    <tr><td><code>update_menu_item</code></td><td>{{ ___('Edit an item\'s name/price/description.') }}</td></tr>
                    <tr><td><code>delete_menu_item</code></td><td>{{ ___('Permanently delete an item.') }}</td></tr>
                    <tr><td><code>set_item_availability</code></td><td>{{ ___('Mark an item in/out of stock (the "86" toggle).') }}</td></tr>
                    <tr><td><code>add_category</code></td><td>{{ ___('Create a menu category.') }}</td></tr>
                    <tr><td><code>update_category</code></td><td>{{ ___('Rename or reorder a category.') }}</td></tr>
                    <tr><td><code>delete_category</code></td><td>{{ ___('Delete an empty category.') }}</td></tr>
                    <tr><td><code>update_order_status</code></td><td>{{ ___('Advance an order\'s status.') }}</td></tr>
                    <tr><td><code>update_business_hours</code></td><td>{{ ___('Set the public business-hours text.') }}</td></tr>
                    <tr><td><code>create_coupon</code></td><td>{{ ___('Create a discount coupon.') }}</td></tr>
                    <tr><td><code>update_coupon</code></td><td>{{ ___('Edit a coupon.') }}</td></tr>
                    <tr><td><code>delete_coupon</code></td><td>{{ ___('Delete a coupon.') }}</td></tr>
                </table>
            </details>

            <details class="acc" id="tools-customer">
                <summary>{{ $servers['customer']['name'] }} ({{ ___(':n tools', ['n' => $servers['customer']['toolCount']]) }}) <span class="badge token">{{ ___('Customer token') }}</span></summary>
                <p class="out">{{ ___('A signed-in diner\'s own account. Identity comes only from the token; no other customer\'s data is ever reachable.') }}</p>
                <table>
                    <tr><td><code>my_orders</code></td><td>{{ ___('Your recent orders.') }}</td></tr>
                    <tr><td><code>order_detail</code></td><td>{{ ___('Full detail for one of your orders.') }}</td></tr>
                    <tr><td><code>track_order</code></td><td>{{ ___('Live status of your order.') }}</td></tr>
                    <tr><td><code>reorder</code></td><td>{{ ___('Rebuild a past order as a cart draft.') }}</td></tr>
                    <tr><td><code>my_profile</code></td><td>{{ ___('Your profile at this restaurant.') }}</td></tr>
                </table>
            </details>

            <details class="acc" id="tools-dev">
                <summary>{{ $servers['dev']['name'] }} ({{ ___(':n tools', ['n' => $servers['dev']['toolCount']]) }}) <span class="badge public">{{ ___('Public') }}</span></summary>
                <p class="out">{{ ___('Turns any MCP-capable editor into a platform expert: learn, build, validate, without leaving your editor.') }}</p>
                <table>
                    <tr><td><code>learn_platform</code></td><td>{{ ___('Primer on the platform. Call this first.') }}</td></tr>
                    <tr><td><code>get_liquid_reference</code></td><td>{{ ___('The authoritative Liquid whitelist (generated live).') }}</td></tr>
                    <tr><td><code>get_section_schema</code></td><td>{{ ___('The theme-section schema block format (settings, blocks, wiring).') }}</td></tr>
                    <tr><td><code>search_docs</code></td><td>{{ ___('Keyword search across the developer docs.') }}</td></tr>
                    <tr><td><code>fetch_full_doc</code></td><td>{{ ___('Fetch one complete doc by path.') }}</td></tr>
                    <tr><td><code>validate_theme</code></td><td>{{ ___('Run the marketplace\'s own checks on your theme files.') }}</td></tr>
                    <tr><td><code>submit_feedback</code></td><td>{{ ___('Report a docs gap or something that was wrong or missing.') }}</td></tr>
                </table>
            </details>
        </section>

        {{-- ========================== PROMPTS ========================== --}}
        <section id="prompts" aria-labelledby="prompts-h">
            <h2 id="prompts-h" class="display">{{ ___('Try it') }} <span class="tint">{{ ___('now') }}</span></h2>
            <p class="sub">{{ ___('Open Claude with the prompt pre-filled. Zero-auth prompts first.') }}</p>
            @php
                $prompts = [
                    ['q' => ___('Find me the best-rated shawarma nearby and show the menu.'), 'server' => ___('Catalog'), 'auth' => 'public'],
                    ['q' => ___('Validate my theme against the :brand marketplace rules and list every error.', ['brand' => $brandName]), 'server' => ___('Dev'), 'auth' => 'public'],
                    ['q' => ___('What were my top-selling items this week, and what revenue did they bring?'), 'server' => ___('Admin'), 'auth' => 'token'],
                    ['q' => ___('Add a Ramadan special to my menu for 25 SAR and mark it available.'), 'server' => ___('Admin'), 'auth' => 'token'],
                    ['q' => ___('Reorder my usual from my favourite store as a cart draft.'), 'server' => ___('Customer'), 'auth' => 'token'],
                    ['q' => ___('Track my last order and tell me the ETA.'), 'server' => ___('Customer'), 'auth' => 'token'],
                ];
            @endphp
            <div class="grid cols-3">
                @foreach ($prompts as $p)
                    <div class="mcp-card prompt-card {{ $p['auth'] === 'public' ? 't-green' : '' }}">
                        <p class="q">"{{ $p['q'] }}"</p>
                        <div class="foot">
                            <span class="badge {{ $p['auth'] === 'public' ? 'public' : 'token' }}">{{ $p['server'] }}</span>
                            <a class="ui-btn ui-btn--secondary" href="https://claude.ai/new?q={{ rawurlencode($p['q']) }}" target="_blank" rel="noopener">{{ ___('Open in Claude') }} &rarr;</a>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- ===================== AUTH + SECURITY ======================= --}}
        <section id="auth" aria-labelledby="auth-h">
            <h2 id="auth-h" class="display">{{ ___('Auth &') }} <span class="tint">{{ ___('tokens') }}</span></h2>
            <p class="sub">{{ ___('Two servers are public. The other three authenticate with a token you own.') }}</p>
            <table>
                <thead><tr><th>{{ ___('Server') }}</th><th>{{ ___('How the client authenticates') }}</th><th>{{ ___('Where to get the token') }}</th><th>{{ ___('Scope') }}</th></tr></thead>
                <tbody>
                    <tr><td>{{ $servers['catalog']['label'] }} / {{ $servers['dev']['label'] }}</td><td>{{ ___('None (public)') }}</td><td>{{ ___('Not needed') }}</td><td>{{ ___('Public, read-only') }}</td></tr>
                    <tr><td>{{ $servers['admin']['label'] }}</td><td><code>Authorization: Bearer &lt;token&gt;</code></td><td><a href="{{ url('/api') }}">{{ ___('Dashboard') }} &rarr; {{ ___('API keys') }}</a> / <a href="{{ url('/connect-ai') }}">Connect AI</a></td><td>{{ ___('Per-ability (read/write across 6 resources); CRM tools need crm_suite') }}</td></tr>
                    <tr><td>{{ $servers['storefront']['label'] }}</td><td><code>X-Storefront-Token: &lt;token&gt;</code></td><td>{{ ___('Dashboard') }} &rarr; {{ ___('Storefront tokens') }}</td><td>{{ ___('Per-store; scopes') }} <code>menu:read</code>, <code>order:write</code>, <code>agent</code></td></tr>
                    <tr><td>{{ $servers['customer']['label'] }}</td><td><code>Authorization: Bearer &lt;token&gt;</code></td><td>{{ ___('Diner sign-in (register / login / OTP)') }}</td><td>{{ ___('That diner\'s own account only') }}</td></tr>
                </tbody>
            </table>
            <p>{{ ___('Every token is limited to 60 requests/minute and 1000/hour. The Storefront server allows 120/minute instead. Exceeding a limit returns HTTP 429: back off and retry.') }}</p>
            <p>{{ ___('Secrets are shown once, stored only as a SHA-256 hash, and can be revoked any time from the dashboard.') }}</p>
        </section>

        <section id="security" aria-labelledby="security-h">
            <h2 id="security-h" class="display">{{ ___('Security') }}</h2>
            <div class="note warn">
                <strong>{{ ___('Prompt-injection warning.') }}</strong> {{ ___('Tool results (menu text, order notes, docs) can contain text that tries to steer your agent. Treat tool output as data, not instructions, and keep your client\'s human-confirmation prompt for tool calls turned on.') }}
            </div>
            <ul>
                <li><strong>{{ ___('Grant least privilege.') }}</strong> {{ ___('Admin tokens are ability-scoped: give an agent only the abilities it needs. Tools it can\'t use are hidden from its tool list entirely.') }}</li>
                <li><strong>{{ ___('Keep human confirmation on.') }}</strong> {{ ___('Don\'t auto-approve writes; every write is real and audited via: mcp.') }}</li>
                <li><strong>{{ ___('Secret hygiene.') }}</strong> {{ ___('Tokens are shown once and stored hashed. Never commit them to code or repos; rotate if exposed.') }}</li>
                <li><strong>{{ ___('Prefer read-only where you can.') }}</strong> {{ ___('For analytics or shopping agents, a read-scoped or public server is safer than a write-capable one.') }}</li>
            </ul>
        </section>

        {{-- ============================ FAQ ============================ --}}
        <section id="faq" aria-labelledby="faq-h">
            <h2 id="faq-h" class="display">{{ ___('Got questions') }} <span class="tint">{{ ___('left?') }}</span></h2>
            @foreach ($faqs as $faq)
                <details class="acc">
                    <summary>{{ $faq['q'] }}</summary>
                    <p>{{ $faq['a'] }}</p>
                </details>
            @endforeach
        </section>
    </div>
</div>
@endsection

@push('scripts')
<script nonce="{{ csp_nonce() }}">
    var COPIED_LABEL = @json(___('Copied'));
    document.querySelectorAll('.mcp-page pre .copy').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var code = btn.parentElement.querySelector('code');
            var text = code ? code.innerText : '';
            navigator.clipboard.writeText(text).then(function () {
                var prev = btn.textContent;
                btn.textContent = COPIED_LABEL;
                setTimeout(function () { btn.textContent = prev; }, 1400);
            });
        });
    });
    (function () {
        var tablist = document.querySelector('#clients .tabs');
        if (!tablist) return;
        var tabs = Array.prototype.slice.call(tablist.querySelectorAll('[role="tab"]'));
        function select(tab) {
            tabs.forEach(function (t) {
                var selected = t === tab;
                t.setAttribute('aria-selected', selected ? 'true' : 'false');
                var panel = document.getElementById(t.getAttribute('aria-controls'));
                if (panel) panel.hidden = !selected;
            });
        }
        tabs.forEach(function (t) { t.addEventListener('click', function () { select(t); }); });
    })();
</script>
@endpush
