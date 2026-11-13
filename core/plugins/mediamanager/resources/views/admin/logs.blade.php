{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layouts.main')

@section('title', ___('Cleanup Logs'))

@section('content')
@once<link rel="stylesheet" href="{{ asset('assets/global/css/ui-icon.css') }}">@endonce
<link rel="stylesheet" href="{{ plugin_assets('mediamanager', 'css/mediamanager.css') }}">

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0 text-gray-800">
                <i class="ico ico-history text-secondary me-2" aria-hidden="true"><x-ui.icon name="history" /></i>{{ ___('Cleanup Logs') }}
            </h1>
            <p class="text-muted mb-0">{{ ___('History of all scan and deletion actions performed.') }}</p>
        </div>
        <a href="{{ route('admin.mediamanager.index') }}" class="btn btn-secondary">
            <i class="ico ico-arrow-left me-1" aria-hidden="true"><x-ui.icon name="arrow-left" /></i> {{ ___('Back to Dashboard') }}
        </a>
    </div>

    @if($logs->count() > 0)
    <div class="card shadow mb-4">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th width="160">{{ ___('Date & Time') }}</th>
                            <th width="100">{{ ___('Action') }}</th>
                            <th width="120">{{ ___('User') }}</th>
                            <th>{{ ___('File Path') }}</th>
                            <th width="100" class="text-end">{{ ___('Size') }}</th>
                            <th width="300">{{ ___('Details') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($logs as $log)
                        <tr>
                            <td class="align-middle">
                                <div class="small fw-bold">{{ $log->created_at->format('M d, Y') }}</div>
                                <div class="small text-muted">{{ $log->created_at->format('H:i:s') }}</div>
                            </td>
                            <td class="align-middle">
                                @if($log->action === 'delete')
                                    <span class="badge bg-danger"><i class="ico ico-trash me-1" aria-hidden="true"><x-ui.icon name="trash" /></i>{{ ___('Delete') }}</span>
                                @elseif($log->action === 'scan')
                                    <span class="badge bg-info"><i class="ico ico-refresh me-1" aria-hidden="true"><x-ui.icon name="refresh" /></i>{{ ___('Scan') }}</span>
                                @else
                                    <span class="badge bg-secondary">{{ $log->action }}</span>
                                @endif
                            </td>
                            <td class="align-middle">
                                @if($log->user)
                                    <span class="fw-bold">{{ $log->user->name ?? $log->user->email ?? '—' }}</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="align-middle">
                                @if($log->action === 'delete')
                                    <code class="small">{{ $log->file_path }}</code>
                                @elseif($log->action === 'scan')
                                    <span class="text-muted small">{{ ___('Full storage scan completed') }}</span>
                                @else
                                    <code class="small">{{ $log->file_path }}</code>
                                @endif
                            </td>
                            <td class="align-middle text-end fw-bold">
                                {{ $log->file_size > 0 ? \Plugins\mediamanager\app\Services\ImageScanner::formatBytes($log->file_size) : '—' }}
                            </td>
                            <td class="align-middle">
                                @if($log->details)
                                    @php $details = json_decode($log->details, true); @endphp
                                    @if(is_array($details))
                                        @if($log->action === 'scan')
                                            <div class="small">
                                                <span class="text-muted">{{ ___('Total') }}:</span> <strong>{{ number_format($details['total_files'] ?? 0) }}</strong>
                                                <span class="text-muted ms-2">{{ ___('Orphans') }}:</span> <strong class="text-danger">{{ number_format($details['orphaned_files'] ?? 0) }}</strong>
                                                <span class="text-muted ms-2">{{ ___('Duplicates') }}:</span> <strong class="text-warning">{{ number_format($details['duplicates'] ?? 0) }}</strong>
                                            </div>
                                        @elseif($log->action === 'delete')
                                            <div class="small">
                                                <span class="badge bg-{{ ($details['mode'] ?? '') === 'permanent' ? 'dark' : 'warning' }} me-1">
                                                    {{ ($details['mode'] ?? 'trash') === 'permanent' ? ___('Permanent') : ___('To Trash') }}
                                                </span>
                                                @if(!empty($details['reason']))
                                                    <span class="badge bg-info">{{ $details['reason'] }}</span>
                                                @endif
                                            </div>
                                        @else
                                            <code class="small">{{ $log->details }}</code>
                                        @endif
                                    @else
                                        <code class="small">{{ $log->details }}</code>
                                    @endif
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{ $logs->links() }}
    @else
    <div class="card shadow">
        <div class="card-body text-center py-5">
            <i class="ico ico-clipboard-list ico-3x text-muted mb-3" aria-hidden="true"><x-ui.icon name="clipboard-list" /></i>
            <h4>{{ ___('No logs yet') }}</h4>
            <p class="text-muted">{{ ___('Actions will be logged when you scan or delete files.') }}</p>
            <a href="{{ route('admin.mediamanager.index') }}" class="btn btn-primary mt-2">{{ ___('Back to Dashboard') }}</a>
        </div>
    </div>
    @endif
</div>
@endsection
