{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Newsletter Subscribers'))
@section('header_buttons')
    <x-ui.button size="sm" icon="plus" :data-url="route('admin.subscriber.create')" data-toggle="slidePanel">{{ ___('Add Subscriber') }}</x-ui.button>
@endsection
@section('content')
<div class="ui-stack">
    <label class="ui-dt__search">
        <span class="ui-visually-hidden">{{ ___('Search') }}</span>
        <input type="search" class="ui-input" placeholder="{{ ___('Search') }}" data-ui-table-search="subscribers-table" autocomplete="off">
    </label>
    <x-ui.card variant="flush">
        <div class="ui-table-wrap">
            <table class="ui-table" id="subscribers-table">
                <thead>
                    <tr>
                        <th>{{ ___('Email') }}</th>
                        <th>{{ ___('Joined') }}</th>
                        <th><span class="ui-visually-hidden">{{ ___('Actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($subscribers as $subscriber)
                        <tr>
                            <td data-primary data-label="{{ ___('Email') }}">{{ $subscriber->email }}</td>
                            <td data-label="{{ ___('Joined') }}" data-order="{{ $subscriber->joined ? (int) strtotime((string) $subscriber->joined) : 0 }}">{{ date_formating($subscriber->joined) }}</td>
                            <td data-label="">
                                <div class="ui-cell-actions">
                                    <button type="button" class="ui-icon-btn ui-icon-btn--ghost" data-url="{{ route('admin.subscriber.edit', $subscriber->id) }}"
                                            data-toggle="slidePanel" title="{{ ___('Edit') }}" aria-label="{{ ___('Edit') }}"><x-ui.icon name="pencil" size="18" /></button>
                                    <form action="{{ route('admin.subscriber.destroy', $subscriber->id) }}" method="POST" data-ui-confirm="{{ ___('Are you sure?') }}" data-ui-danger>
                                        @csrf @method('DELETE')
                                        <button type="submit" class="ui-icon-btn ui-icon-btn--ghost ui-text-danger" title="{{ ___('Delete') }}" aria-label="{{ ___('Delete') }}"><x-ui.icon name="trash" size="18" /></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="ui-center ui-text-2">{{ ___('No records found') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>
</div>
@endsection
