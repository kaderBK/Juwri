{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Plugins'))
@section('header_buttons')
    <x-ui.button size="sm" icon="upload" :data-url="route('admin.plugins.create')" data-toggle="slidePanel">{{ ___('Upload Plugin') }}</x-ui.button>
@endsection
@section('content')
<div class="ui-stack">
    @if ($installed_plugins_not_in_marketplace->count() > 0)
        <h2 class="ui-section-title">{{ ___('Installed Plugins') }}</h2>
        @foreach ($installed_plugins_not_in_marketplace as $plugin)
            @include('admin.plugins._card', ['plugin' => $plugin, 'listed' => false])
        @endforeach
    @endif

    <h2 class="ui-section-title">{{ ___('Available Plugins') }}</h2>
    @forelse ($plugins as $plugin)
        @include('admin.plugins._card', ['plugin' => $plugin, 'listed' => true])
    @empty
        <x-ui.empty icon="settings" :title="___('No records found')" />
    @endforelse
</div>
@endsection
