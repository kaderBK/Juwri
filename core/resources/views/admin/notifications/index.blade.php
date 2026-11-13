{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Notifications').' ('.$totalUnread.')')
@section('header_buttons')
    <x-ui.button variant="secondary" size="sm" icon="check" :href="route('admin.notifications.markasread')">{{ ___('Mark All as Read') }}</x-ui.button>
    <form action="{{ route('admin.notifications.deleteallread') }}" method="POST" data-ui-confirm="{{ ___('Are you sure?') }}" data-ui-danger>
        @csrf
        @method('DELETE')
        <x-ui.button type="submit" variant="danger" size="sm" icon="trash">{{ ___('Delete All Read') }}</x-ui.button>
    </form>
@endsection
@section('content')
@php
    $__kinds = [
        'new_user' => ['users', 'success'],
        'new_comment' => ['chat', 'warning'],
        'new_payment' => ['orders', 'accent'],
    ];
@endphp
    @if ($notifications->isEmpty())
        <x-ui.empty icon="bell" :title="___('No Data Found.')" />
    @else
        <x-ui.list>
            @foreach ($notifications as $notification)
                @php [$__icon, $__tone] = $__kinds[$notification->type] ?? ['bell', 'neutral']; @endphp
                <x-ui.row :href="$notification->link ? route('admin.notifications.view', $notification->id) : null"
                          :icon="$__icon" :tone="$__tone" :title="$notification->title" :sub="$notification->created_at->diffForHumans()">
                    @unless ($notification->status)
                        <x-slot:trail><x-ui.pill tone="accent">{{ ___('New') }}</x-ui.pill></x-slot:trail>
                    @endunless
                </x-ui.row>
            @endforeach
        </x-ui.list>
    @endif
@endsection
