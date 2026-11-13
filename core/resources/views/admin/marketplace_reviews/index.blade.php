{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Store Reviews'))
@section('content')
<div class="ui-stack">

    {{-- stores P3-2: pending review moderation. Body/title are hostile input —
         ALWAYS escaped via {{ }}, never {!! !!}. --}}
    <x-ui.card variant="flush">
        <div class="ui-card__head">
            <div>
                <h2 class="ui-card__title">{{ ___('Pending reviews') }} <x-ui.tag tone="warning">{{ $pending->count() }}</x-ui.tag></h2>
                <p class="ui-text-2 ui-small">{{ ___('Reviews are hidden until an admin publishes them. Publishing updates the listing rating.') }}</p>
            </div>
        </div>
        @if($pending->isEmpty())
            <x-ui.empty icon="check" :title="___('Nothing waiting. 🎉')" />
        @else
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead><tr>
                        <th>{{ ___('Listing') }}</th><th>{{ ___('User') }}</th><th>{{ ___('Rating') }}</th><th>{{ ___('Review') }}</th><th>{{ ___('Submitted') }}</th><th class="ui-end">{{ ___('Actions') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach($pending as $r)
                        <tr>
                            <td data-primary data-label="{{ ___('Listing') }}">
                                <x-ui.tag>{{ class_basename($r->reviewable_type) }}</x-ui.tag>
                                <span class="ui-strong">{{ $r->reviewable?->name ?? ___('(removed)') }}</span>
                            </td>
                            <td data-label="{{ ___('User') }}">{{ $r->user?->name ?? '#'.$r->user_id }}</td>
                            <td data-label="{{ ___('Rating') }}"><span class="ui-text-warning ui-nowrap" aria-label="{{ (int) $r->rating }}/5">{{ str_repeat('★', (int) $r->rating) }}{{ str_repeat('☆', 5 - (int) $r->rating) }}</span></td>
                            <td data-label="{{ ___('Review') }}" style="max-inline-size:360px">
                                @if($r->title)<span class="ui-strong ui-block">{{ $r->title }}</span>@endif
                                <span class="ui-text-2">{{ Str::limit($r->body, 240) }}</span>
                            </td>
                            <td data-label="{{ ___('Submitted') }}" class="ui-text-2">{{ $r->created_at->diffForHumans() }}</td>
                            <td class="ui-end" data-label="">
                                <div class="ui-cell-actions">
                                    <form action="{{ route('admin.reviews.approve', $r->id) }}" method="post">@csrf
                                        <x-ui.button type="submit" variant="success" size="sm">{{ ___('Publish') }}</x-ui.button>
                                    </form>
                                    <form action="{{ route('admin.reviews.reject', $r->id) }}" method="post">@csrf
                                        <x-ui.button type="submit" variant="secondary" size="sm" class="ui-text-danger">{{ ___('Reject') }}</x-ui.button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>

    <x-ui.card variant="flush" :title="___('Recent decisions')">
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead><tr><th>{{ ___('Listing') }}</th><th>{{ ___('User') }}</th><th>{{ ___('Rating') }}</th><th>{{ ___('Status') }}</th><th>{{ ___('Decided') }}</th></tr></thead>
                <tbody>
                @forelse($recent as $r)
                    <tr>
                        <td data-primary data-label="{{ ___('Listing') }}">{{ $r->reviewable?->name ?? ___('(removed)') }}</td>
                        <td data-label="{{ ___('User') }}">{{ $r->user?->name ?? '#'.$r->user_id }}</td>
                        <td data-label="{{ ___('Rating') }}"><span class="ui-text-warning ui-nowrap" aria-label="{{ (int) $r->rating }}/5">{{ str_repeat('★', (int) $r->rating) }}</span></td>
                        <td data-label="{{ ___('Status') }}"><x-ui.tag :tone="['published' => 'success', 'rejected' => 'danger'][$r->status] ?? 'neutral'">{{ $r->status }}</x-ui.tag></td>
                        <td data-label="{{ ___('Decided') }}" class="ui-text-2">{{ $r->updated_at->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="ui-center ui-text-2">{{ ___('No decisions yet.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

</div>
@endsection
