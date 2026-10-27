{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('FAQs') . ' - '. strtoupper($current_language))
@section('header_buttons')
    @include('admin.includes.language')
    <x-ui.button size="sm" icon="plus" :data-url="route('admin.faqs.create')" data-toggle="slidePanel">{{ ___('Add New') }}</x-ui.button>
@endsection
@section('content')
    @if ($faqs->isEmpty())
        <x-ui.empty icon="list" :title="___('No FAQs yet')">
            <x-ui.button size="sm" icon="plus" :data-url="route('admin.faqs.create')" data-toggle="slidePanel">{{ ___('Add New') }}</x-ui.button>
        </x-ui.empty>
    @else
        <x-ui.card variant="flush">
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead>
                        <tr>
                            <th>{{ ___('Title') }}</th>
                            <th>{{ ___('Language') }}</th>
                            <th>{{ ___('Active') }}</th>
                            <th><span class="ui-visually-hidden">{{ ___('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($faqs as $faq)
                            <tr>
                                <td data-primary data-label="{{ ___('Title') }}"><strong>{{ text_shorting($faq->faq_title, 40) }}</strong></td>
                                <td data-label="{{ ___('Language') }}"><x-ui.tag>{{ strtoupper($faq->language ? $faq->language->code : ___('All')) }}</x-ui.tag></td>
                                <td data-label="{{ ___('Active') }}">
                                    @if ($faq->active)
                                        <x-ui.tag tone="success">{{ ___('Active') }}</x-ui.tag>
                                    @else
                                        <x-ui.tag tone="danger">{{ ___('Inactive') }}</x-ui.tag>
                                    @endif
                                </td>
                                <td data-label="">
                                    <div class="ui-cell-actions">
                                        <button type="button" class="ui-icon-btn ui-icon-btn--ghost" data-url="{{ route('admin.faqs.edit', $faq->id) }}"
                                                data-toggle="slidePanel" title="{{ ___('Edit') }}" aria-label="{{ ___('Edit') }}"><x-ui.icon name="pencil" size="18" /></button>
                                        <form action="{{ route('admin.faqs.destroy', $faq->id) }}" method="POST" data-ui-confirm="{{ ___('Are you sure?') }}" data-ui-danger>
                                            @csrf @method('DELETE')
                                            <button type="submit" class="ui-icon-btn ui-icon-btn--ghost ui-text-danger" title="{{ ___('Delete') }}" aria-label="{{ ___('Delete') }}"><x-ui.icon name="trash" size="18" /></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endif
@endsection
