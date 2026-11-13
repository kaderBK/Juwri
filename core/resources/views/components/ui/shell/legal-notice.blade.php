{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-ui.shell.legal-notice /> — P1-23: a changed (or never accepted) version of the Terms, the DPA or the AUP.
  Shown until the owner accepts; before the effective date it is the 30 days' notice of Terms §14.2. Never a
  lock-out: the dashboard keeps working whether or not the owner accepts.
--}}
@php
    $__legalUser = auth()->user();
    $__legalPending = $__legalUser instanceof \Juwri\Core\Models\User ? \Juwri\Core\Legal\LegalAcceptances::pending($__legalUser) : [];
@endphp
@if ($__legalPending !== [] && Route::has('legal.accept'))
    @php
        $__legalDocs = \Juwri\Core\Support\ViewHelpers::legalDocumentLabels($__legalPending);
        $__legalUpcoming = \Juwri\Core\Support\ViewHelpers::firstUpcoming($__legalPending);
    @endphp
    <x-ui.banner tone="info" class="ui-notice" data-legal-notice>
        <span class="ui-notice__text">
            @if ($__legalUpcoming)
                {{ __('site.legal.reaccept.upcoming', ['documents' => $__legalDocs, 'date' => $__legalUpcoming['effective']->locale(app()->getLocale())->isoFormat('LL')]) }}
            @else
                {{ __('site.legal.reaccept.updated', ['documents' => $__legalDocs]) }}
            @endif
            @foreach ($__legalPending as $__p)
                <a href="{{ \Juwri\Core\Legal\LegalDocuments::url($__p['document']) }}" target="_blank" rel="noopener">{{ \Juwri\Core\Legal\LegalDocuments::label($__p['document']) }}</a>@if (! $loop->last) · @endif
            @endforeach
        </span>
        <form method="POST" action="{{ route('legal.accept') }}">
            @csrf
            <button type="submit" class="ui-btn ui-btn--sm" data-legal-accept>{{ __('site.legal.reaccept.accept') }}</button>
        </form>
    </x-ui.banner>
@endif
