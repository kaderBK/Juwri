{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Site block: for_developers — the public Dev MCP pitch with a terminal
     panel (left-to-right in every locale: it shows a shell command). --}}
<section class="mk-sec sb-block" id="for-developers" data-site-block="for_developers">
    <div class="mk-wrap">
        <div class="mk-split">
            <div>
                <span class="mk-eyebrow">{{ ___('For developers') }}</span>
                <h2 class="mk-h2">{{ ___('Point your AI editor at us. It just works.') }}</h2>
                <p class="mk-muted">{{ ___('Building a theme with Claude Code, Cursor, or VS Code? One command connects your AI to our public Dev MCP server — live documentation search, the exact Liquid objects, filters and tags our engine supports, and the same validation the marketplace runs at submission. The reference is generated from the engine\'s own code on every request, so it is never stale and your AI never invents an API that doesn\'t exist.') }}</p>
                <ul class="mk-checks">
                    <li><strong>{{ ___('Never-stale reference') }}</strong> — {{ ___('drops, filters and tags reflected live from the engine. Not documentation about the code; documentation from the code.') }}</li>
                    <li><strong>{{ ___('Validate before you ship') }}</strong> — {{ ___('your AI lints its own theme against our marketplace rules and fixes errors before you ever see them.') }}</li>
                    <li><strong>{{ ___('Zero setup') }}</strong> — {{ ___('public endpoint, no account, no token, no SDK.') }}</li>
                </ul>
                <div class="mk-actions sb-actions--start">
                    <a href="{{ url('developers') }}#ai-editor" class="ui-btn">{{ ___('Connect your editor') }}</a>
                </div>
            </div>
            <div class="sb-term" dir="ltr">
                <div class="sb-term__bar"><i></i><i></i><i></i><span>{{ ___('Terminal') }}</span></div>
                <pre class="sb-term__body"><span class="sb-term__dim">$</span> claude mcp add --transport http \
    platform-dev {{ url('mcp/dev') }}
<span class="sb-term__ok">✓ Connected — 6 tools available</span>

<span class="sb-term__dim">$</span> &gt; {{ ___('build me a promo-banner section') }}
<span class="sb-term__call">→ get_liquid_reference</span>
<span class="sb-term__call">→ validate_theme</span>
<span class="sb-term__ok">✓ passed: true</span></pre>
            </div>
        </div>
    </div>
</section>
