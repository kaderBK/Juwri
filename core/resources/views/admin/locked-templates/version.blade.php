{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('New version') . ' - ' . $template->name)
@section('header_buttons')
    <x-ui.button variant="secondary" size="sm" icon="back" :href="route('admin.locked-templates.index')">{{ ___('Back to templates') }}</x-ui.button>
@endsection
@section('content')
<div class="ui-stack">
    <x-ui.banner>
        {{ ___('Editing a copy of version :v. Saving creates a NEW version; pages already using this template keep their version until their owner updates.', ['v' => $from->version]) }}
        <br>{{ ___('Placeholders:') }}
        <code>@{{slot:key}}</code> {{ ___('editable value') }} ·
        <code>@{{asset}}</code> {{ ___('this template\'s files') }} ·
        <code>@{{form:action}}</code> / <code>@{{form:fields}}</code> {{ ___('lead forms') }}
    </x-ui.banner>
    @if ($errors->any())
        <x-ui.banner tone="danger"><span style="white-space: pre-line">{{ $errors->first('manifest') ?: $errors->first() }}</span></x-ui.banner>
    @endif

    <x-ui.card>
        <form action="{{ route('admin.locked-templates.versions.store', $template->id) }}" method="post" class="ui-stack">
            @csrf
            <input type="hidden" name="from" value="{{ $from->version }}">
            <div class="ui-field">
                <label for="lt-manifest" class="ui-field__label">{{ ___('Manifest (JSON)') }}</label>
                <div class="ui-field__control">
                    <textarea id="lt-manifest" name="manifest" class="ui-mono" rows="28" spellcheck="false" style="font-size: 12px">{{ old('manifest', $json) }}</textarea>
                </div>
            </div>
            <x-ui.field name="notes" id="lt-notes" :label="___('What changed (optional)')" maxlength="255" />
            <div class="ui-cluster">
                <x-ui.button type="submit" data-ui-busy>{{ ___('Validate & create version') }}</x-ui.button>
                <x-ui.button variant="secondary" target="_blank" rel="noopener"
                             :href="route('admin.locked-templates.preview', ['id' => $template->id, 'version' => $from->version])">{{ ___('Preview v:v', ['v' => $from->version]) }}</x-ui.button>
            </div>
        </form>
    </x-ui.card>
</div>
@endsection
