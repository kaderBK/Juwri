{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Confirm full restore'))
@section('page_sub', ___('Confirm full restore for :name', ['name' => $user->name ?? $user->email]))
@section('content')
<div class="ui-stack">
    <x-ui.banner tone="warning">{{ ___('This is destructive. Every store below will be DELETED and re-created from the uploaded archive. Review the per-table counts before you confirm.') }}</x-ui.banner>
    @unless ($complete)
        <x-ui.banner tone="danger">{{ ___('This archive predates the full table registry. Restoring it deletes each store and re-creates only part of it — every table shown in red below will be permanently lost. Tick the box to accept a partial, lossy restore.') }}</x-ui.banner>
    @endunless

    @foreach ($plan as $storeId => $store)
        @php
            $tables = array_unique(array_merge(array_keys($store['delete']), array_keys($store['restore'])));
            usort($tables, function ($a, $b) use ($store) {
                $aLost = ($store['delete'][$a] ?? 0) > 0 && ($store['restore'][$a] ?? 0) === 0;
                $bLost = ($store['delete'][$b] ?? 0) > 0 && ($store['restore'][$b] ?? 0) === 0;

                return ($bLost <=> $aLost) ?: strcmp($a, $b);
            });
        @endphp
        <x-ui.card variant="flush" :title="$store['name'].' #'.$storeId">
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead>
                        <tr>
                            <th>{{ ___('Table') }}</th>
                            <th class="ui-end">{{ ___('Will be deleted') }}</th>
                            <th class="ui-end">{{ ___('Will be restored') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tables as $table)
                            @php
                                $del = (int) ($store['delete'][$table] ?? 0);
                                $res = (int) ($store['restore'][$table] ?? 0);
                                $lost = $del > 0 && $res === 0;
                            @endphp
                            <tr @class(['is-danger' => $lost])>
                                <td data-primary data-label="{{ ___('Table') }}">
                                    <code>{{ $table }}</code>
                                    @if ($lost)<x-ui.tag tone="danger">{{ ___('Will be lost — this archive does not contain this table') }}</x-ui.tag>@endif
                                </td>
                                <td class="ui-end" data-label="{{ ___('Will be deleted') }}">{{ $del }}</td>
                                <td class="ui-end" data-label="{{ ___('Will be restored') }}">{{ $res }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endforeach

    <div class="ui-small">{!! $manifestSummary !!}</div>

    <form method="POST" action="{{ route('admin.users.fullImportConfirm', [$user->id, $token]) }}" class="ui-stack">
        @csrf
        @unless ($complete)
            <x-ui.check name="allow_partial_restore" value="1" required :label="___('I accept a partial, lossy restore')" />
        @endunless
        <div class="ui-cluster">
            <x-ui.button type="submit" variant="danger">{{ ___('Restore now') }}</x-ui.button>
            <x-ui.button variant="secondary" :href="route('admin.users.index')">{{ ___('Cancel') }}</x-ui.button>
        </div>
    </form>
</div>
@endsection
