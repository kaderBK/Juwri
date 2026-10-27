{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    $__legalStatus = \Juwri\Core\Legal\LegalSettings::status();
@endphp
<section id="quick_legal" role="tabpanel" aria-labelledby="tab-quick_legal" @if (($active ?? true) === false) hidden @endif>
    <x-ui.card :title="___('Legal & compliance')">
        <div class="ui-stack">
            <p class="ui-text-2">{{ ___('Owner facts and product promises for the published legal pages.') }}</p>
            <x-ui.stat :value="___(':filled of :total blanks filled', ['filled' => $__legalStatus['filled'], 'total' => $__legalStatus['total']])" :label="___('Blanks filled')" icon="clipboard-list"
                       :tone="$__legalStatus['ready'] ? 'success' : 'warning'" />
            <div class="ui-cluster">
                <x-ui.button variant="secondary" icon="file" :href="route('admin.settings.legal')">{{ ___('Open Legal & compliance') }}</x-ui.button>
            </div>
        </div>
    </x-ui.card>
</section>
