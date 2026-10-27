{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Site Pages'))

@section('content')
{{-- HOME-PAGES HP-41: builder index — the home page card + slug pages list.
     Publishing the home switches '/' from the legacy Blade home to the block
     page; unpublishing switches it straight back (kill switch). --}}
<div class="ui-stack">
    <x-ui.card>
        <div class="ui-cluster ui-cluster--between">
            <div>
                <h2 class="ui-section-title">{{ ___('Home page') }}
                    @if($home->status === 'published')
                        <x-ui.tag tone="success">{{ ___('Published') }}</x-ui.tag>
                    @else
                        <x-ui.tag>{{ ___('Draft') }}</x-ui.tag>
                    @endif
                </h2>
                <p class="ui-small ui-text-2">
                    {{ $home->status === 'published'
                        ? ___('The block-composed home page is live on “/”.')
                        : ___('“/” currently serves the built-in home. Publish from the composer to switch.') }}
                </p>
            </div>
            <div class="ui-cluster">
                <x-ui.button variant="secondary" size="sm" icon="eye" target="_blank" rel="noopener"
                             :href="route('admin.site-pages.preview', $home->id)">{{ ___('Preview draft') }}</x-ui.button>
                <x-ui.button size="sm" icon="pencil" :href="route('admin.site-pages.edit', $home->id)">{{ ___('Open composer') }}</x-ui.button>
            </div>
        </div>
    </x-ui.card>

    <x-ui.card variant="flush" :title="___('Pages')">
        <div class="ui-stack">
            <form method="POST" action="{{ route('admin.site-pages.store') }}" class="ui-cluster ui-cluster--bottom" style="padding-inline: 20px">
                @csrf
                <x-ui.field name="title" id="sp-title" :label="___('Title')" required maxlength="120" />
                <x-ui.field name="slug" id="sp-slug" :label="___('URL slug')" required maxlength="80" pattern="[a-z0-9-]+" placeholder="about-us" />
                <x-ui.button type="submit" icon="plus" data-ui-busy>{{ ___('Create page') }}</x-ui.button>
            </form>

            @if($sitePages->isEmpty())
                <x-ui.empty icon="file" :title="___('No custom pages yet.')" />
            @else
                <div class="ui-table-wrap">
                    <table class="ui-table">
                        <thead>
                            <tr>
                                <th>{{ ___('Title') }}</th>
                                <th>{{ ___('URL') }}</th>
                                <th>{{ ___('Status') }}</th>
                                <th class="ui-end">{{ ___('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($sitePages as $p)
                                <tr>
                                    <td data-primary data-label="{{ ___('Title') }}">{{ \Juwri\Core\Support\LocalizedContent::resolve($p->title, get_lang()) ?: '—' }}</td>
                                    <td data-label="{{ ___('URL') }}"><code>/page/{{ $p->slug }}</code></td>
                                    <td data-label="{{ ___('Status') }}">
                                        @if($p->status === 'published')
                                            <x-ui.tag tone="success">{{ ___('Published') }}</x-ui.tag>
                                        @else
                                            <x-ui.tag>{{ ___('Draft') }}</x-ui.tag>
                                        @endif
                                    </td>
                                    <td class="ui-end" data-label="">
                                        <div class="ui-cell-actions">
                                            <a class="ui-icon-btn ui-icon-btn--ghost" href="{{ route('admin.site-pages.edit', $p->id) }}"
                                               title="{{ ___('Edit') }}" aria-label="{{ ___('Edit') }}"><x-ui.icon name="pencil" size="18" /></a>
                                            <form method="POST" action="{{ route('admin.site-pages.destroy', $p->id) }}"
                                                  data-ui-confirm="{{ ___('Delete this page?') }}" data-ui-danger>
                                                @csrf @method('DELETE')
                                                <button type="submit" class="ui-icon-btn ui-icon-btn--ghost ui-text-danger"
                                                        title="{{ ___('Delete') }}" aria-label="{{ ___('Delete') }}"><x-ui.icon name="trash" size="18" /></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </x-ui.card>
</div>
@endsection
