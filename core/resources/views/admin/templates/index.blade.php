{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Templates'))
@section('content')
    @if (empty($templates) && empty($extra_templates))
        {{-- No bought theme folder is installed any more (WI-16h): every public page renders on the kit. --}}
        <x-ui.empty icon="sparkle" :title="___('Nothing here yet.')" />
    @endif
    <div class="ui-grid" style="--ui-grid-min: 240px">
        @foreach ($templates as $temp)
            <x-ui.card :title="ucfirst($temp['name'])" heading="h3">
                <div class="ui-stack">
                    <img src="{{ $temp['image'] }}" class="ui-img-fluid ui-bordered" alt="{{ ucfirst($temp['name']) }}">
                    @if (active_theme_name() == $temp['name'])
                        <x-ui.button variant="secondary" icon="check" block disabled>{{ ___('Activated') }}</x-ui.button>
                    @else
                        <form action="{{ route('admin.templates.active') }}" method="post">
                            @csrf
                            <x-ui.button type="submit" name="name" :value="$temp['name']" block>{{ ___('Active Me') }}</x-ui.button>
                        </form>
                    @endif
                </div>
            </x-ui.card>
        @endforeach

        @foreach ($extra_templates ?: [] as $temp)
            <x-ui.card :title="ucfirst($temp['name'])" heading="h3">
                <div class="ui-stack">
                    <img src="{{ $temp['image'] }}" class="ui-img-fluid ui-bordered" alt="{{ ucfirst($temp['name']) }}">
                    <x-ui.button :href="$temp['url']" icon="download" target="_blank" rel="noopener" block>{{ ___('Get This') }}</x-ui.button>
                </div>
            </x-ui.card>
        @endforeach
    </div>
@endsection
