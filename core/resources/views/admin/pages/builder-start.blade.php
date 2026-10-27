{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Advanced editor') . ' - ' . ($page->title ?? ___('Page')))
@section('header_buttons')
    <x-ui.button variant="secondary" size="sm" icon="back" :href="route('admin.pages.index')">{{ ___('Back to pages') }}</x-ui.button>
@endsection
@section('content')
<div class="ui-stack">
    <x-ui.banner tone="warning">{{ ___('Pick a starting template. Applying one replaces the current page content.') }}</x-ui.banner>

    {{-- Admin-only escape hatch: full raw HTML + JS (no sanitiser). --}}
    <x-ui.card class="ui-card--accent">
        <div class="ui-cluster ui-cluster--between">
            <div>
                <h2 class="ui-card__title">{{ ___('Trusted HTML (code)') }}</h2>
                <p class="ui-muted">{{ ___('Write full HTML, CSS and JavaScript by hand. Rendered exactly as authored — admins only.') }}</p>
            </div>
            <form action="{{ route('admin.pages.builder.enableTrusted', $page->id) }}" method="post"
                  data-ui-confirm="{{ ___('Switch to trusted raw-HTML mode? Your current page content is kept as the starting point.') }}" data-ui-danger>
                @csrf
                <x-ui.button type="submit" variant="danger" size="sm" icon="alert">{{ ___('Use code editor') }}</x-ui.button>
            </form>
        </div>
    </x-ui.card>

    <div class="ui-grid" style="--ui-grid-min: 220px">
        @foreach ($templates as $tpl)
            <x-ui.card>
                <div class="ui-stack">
                    <div class="ui-tpl-thumb">
                        @if (! empty($tpl['thumb']))
                            <img src="{{ $tpl['thumb'] }}" alt="{{ $tpl['title'] }}">
                        @else
                            <x-ui.icon name="sparkle" size="32" />
                        @endif
                    </div>
                    <div>
                        <h3 class="ui-card__title">{{ $tpl['title'] }}</h3>
                        <p class="ui-muted">{{ $tpl['description'] }}</p>
                    </div>
                    <form action="{{ route('admin.pages.builder.enable', $page->id) }}" method="post"
                          data-ui-confirm="{{ ___('Switch to this template? Your current design on this page will be replaced.') }}">
                        @csrf
                        <input type="hidden" name="template" value="{{ $tpl['id'] }}">
                        <x-ui.button type="submit" size="sm" icon="pencil" block>{{ ___('Use this template') }}</x-ui.button>
                    </form>
                </div>
            </x-ui.card>
        @endforeach
    </div>
</div>
@endsection
