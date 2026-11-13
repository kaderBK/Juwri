{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- WA-D3: the store had more than one legacy WhatsApp number. Number 1 moved to
     the default branch automatically; the rest could not be placed, because only
     the merchant knows which physical location each belongs to — inventing a
     branch to hold one would be worse than asking.

     Dismissal is stored server-side (a post option), not in localStorage, so it
     sticks across devices and does not nag the same owner on their phone. --}}

@php
    /* dashboard_current_store() is the session-scoped store; $ctxStore only
       exists inside the sidebar partial's own @php block, not here. */
    $__waStore = function_exists('dashboard_current_store')
        ? optional(dashboard_current_store())->id
        : null;
    $__waExtras = $__waStore ? whatsapp_extra_numbers($__waStore) : [];
    $__waShow = $__waExtras && ! whatsapp_extras_notice_dismissed($__waStore);
@endphp

@if ($__waShow)
    <div class="ui-cluster ui-cluster--top ui-note" data-tone="warning" role="alert" id="wa-extras-notice">
        <span><x-ui.icon name="alert" size="18" /></span>
        <div class="ui-grow">
            <strong>{{ ___('You have :count extra WhatsApp numbers to place', ['count' => count($__waExtras)]) }}</strong>
            <div class="ui-small">
                {{ ___('Your main number now lives on your default branch. These were not moved automatically, because only you know which branch each belongs to:') }}
            </div>
            <div class="ui-cluster">
                @foreach ($__waExtras as $__waNumber)
                    <code class="ui-small">{{ $__waNumber }}</code>
                @endforeach
            </div>
            <div>
                <a href="{{ route('branches.index') }}">{{ ___('Add them to your branches') }}</a>
            </div>
        </div>
        <form method="post" action="{{ route('stores.whatsappExtras.dismiss', $__waStore) }}">
            @csrf
            <button type="submit" class="ui-icon-btn ui-icon-btn--ghost" aria-label="{{ ___('Dismiss') }}"><x-ui.icon name="x" size="18" /></button>
        </form>
    </div>
@endif
