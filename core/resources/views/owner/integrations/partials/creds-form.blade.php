{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  Shared BYOK credential form for an integration connection page.
  Dumb partial — the track passes everything in:
    $action  (string)  POST route to save credentials
    $rid     (int)     restaurant id (query param for tenant scoping)
    $fields  (array)   [ ['name'=>'api_key','label'=>'API key','type'=>'password','value'=>'','placeholder'=>'','col'=>6,'required'=>true], ... ]
    $secrets (array)   currently-stored secrets (for masked "leave blank to keep")
    $hint    (string)  optional helper line under the button
--}}
<form method="post" action="{{ $action }}?store_id={{ $rid }}" class="ui-form-grid integration-creds-form">
    @csrf
    @foreach($fields as $f)
        @php
            $name = $f['name'];
            $type = $f['type'] ?? 'text';
            $col  = $f['col'] ?? 6;
            $has  = !empty($secrets[$name] ?? null);
            $isSecret = $type === 'password';
        @endphp
        <div @class(['ui-form-grid__full' => (int) $col >= 12])>
            <label class="ui-label">{{ ___($f['label']) }}</label>
            <input
                name="{{ $name }}"
                type="{{ $type }}"
                class="ui-input"
                autocomplete="off"
                value="{{ $isSecret ? '' : ($secrets[$name] ?? ($f['value'] ?? '')) }}"
                placeholder="{{ $isSecret && $has ? '•••••••• ' . ___('leave blank to keep') : ($f['placeholder'] ?? '') }}"
                @if(!empty($f['required']) && !$has) required @endif
            >
        </div>
    @endforeach
    <div class="ui-form-grid__full">
        <button class="ui-btn">{{ ___('Save connection') }}</button>
        <small class="ui-text-2 ui-block">{{ $hint ?? ___('Credentials are stored encrypted and never shown again.') }}</small>
    </div>
</form>
