{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- C2 · "Generate with AI" chooser card (SPEC T1-4). Included by _chooser ONLY
     when `ai_biolink` allows for this store's owner (@includeWhen aiEnabled), so
     the surface renders NOTHING at all when the feature is off — never a disabled
     button. Clicking runs the AI draft; the proposal lands in the review panel
     the builder renders (data-biolink-ai-review). No inline script/style here. --}}
<div data-biolink-ai-card>
    <button type="button" class="ui-stack bio-starter-card ui-btn ui-btn--secondary ui-w-full ui-fill ui-center ui-bordered ui-relative"
            data-biolink-ai-generate style="min-height:190px;">
        <span class="ui-tag ui-absolute ui-top-0 ui-end-0 ui-text-accent" data-tone="accent">{{ ___('AI') }}</span>
        <span class="ui-cluster ui-cluster--center ui-inline ui-text-accent" style="width:44px;height:44px;">
            <x-ui.shell.nav-icon glyph="sparkles" style="font-size:1.1rem;" />
        </span>
        <span class="ui-strong">{{ ___('Generate with AI') }}</span>
        <span class="ui-muted">{{ ___('We’ll draft a page from your menu and details.') }}</span>
        <span class="ui-spin ui-text-accent ui-hidden" role="status" data-biolink-ai-spinner aria-hidden="true"></span>
    </button>
</div>
