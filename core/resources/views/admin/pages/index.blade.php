{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Pages').' - '.strtoupper($current_language))
@section('header_buttons')
    @include('admin.includes.language')
    <x-ui.button size="sm" icon="plus" :data-url="route('admin.pages.create')" data-toggle="slidePanel">{{ ___('Add New') }}</x-ui.button>
@endsection
@section('content')
<div class="ui-stack">
    <label class="ui-dt__search">
        <span class="ui-visually-hidden">{{ ___('Search') }}</span>
        <input type="search" class="ui-input" placeholder="{{ ___('Search') }}" data-ui-table-search="pages-table" autocomplete="off">
    </label>
    <x-ui.card variant="flush">
        <div class="ui-table-wrap">
            <table class="ui-table" id="pages-table">
                <thead>
                    <tr>
                        <th>{{ ___('Title') }}</th>
                        <th>{{ ___('Language') }}</th>
                        <th>{{ ___('Page URL') }}</th>
                        <th>{{ ___('Active') }}</th>
                        <th><span class="ui-visually-hidden">{{ ___('Actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($pages as $page)
                        <tr>
                            <td data-primary data-label="{{ ___('Title') }}">
                                <strong>{{ $page->title }}</strong>
                                @if ($page->type)<x-ui.shell.glyph name="lock" kit="alert" size="14" class="ui-text-2" />@endif
                            </td>
                            <td data-label="{{ ___('Language') }}"><x-ui.tag>{{ strtoupper($page->language ? $page->language->code : ___('All')) }}</x-ui.tag></td>
                            <td data-label="{{ ___('Page URL') }}">
                                <a href="{{ route('page', $page->slug) }}" target="_blank" rel="noopener" class="ui-break">{{ route('page', $page->slug) }} <x-ui.icon name="external" size="14" /></a>
                            </td>
                            <td data-label="{{ ___('Active') }}">
                                @if ($page->active)
                                    <x-ui.tag tone="success">{{ ___('Active') }}</x-ui.tag>
                                @else
                                    <x-ui.tag tone="danger">{{ ___('Inactive') }}</x-ui.tag>
                                @endif
                            </td>
                            <td data-label="">
                                <div class="ui-cell-actions">
                                    <a href="{{ route('admin.pages.builder', $page->id) }}" @class(['ui-icon-btn', 'ui-icon-btn--ghost', 'ui-text-accent' => $page->isBuilderMode()])
                                       title="{{ $page->isBuilderMode() ? ___('Advanced editor') : ___('Switch to the advanced editor') }}"
                                       aria-label="{{ $page->isBuilderMode() ? ___('Advanced editor') : ___('Switch to the advanced editor') }}"><x-ui.icon name="sparkle" size="18" /></a>
                                    <button type="button" class="ui-icon-btn ui-icon-btn--ghost" data-url="{{ route('admin.pages.edit', $page->id) }}" data-toggle="slidePanel"
                                            title="{{ ___('Edit') }}" aria-label="{{ ___('Edit') }}"><x-ui.icon name="pencil" size="18" /></button>
                                    <form action="{{ route('admin.pages.destroy', $page->id) }}" method="POST" data-ui-confirm="{{ ___('Are you sure?') }}" data-ui-danger>
                                        @csrf @method('DELETE')
                                        <button type="submit" class="ui-icon-btn ui-icon-btn--ghost ui-text-danger" title="{{ ___('Delete') }}" aria-label="{{ ___('Delete') }}"><x-ui.icon name="trash" size="18" /></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="ui-center ui-text-2">{{ ___('No records found') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>
</div>
@endsection
