{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layouts.main')

@section('title', ___('Duplicate Files'))

@section('content')
@once<link rel="stylesheet" href="{{ asset('assets/global/css/ui-icon.css') }}">@endonce
<link rel="stylesheet" href="{{ plugin_assets('mediamanager', 'css/mediamanager.css') }}">

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0 text-gray-800">
                <i class="ico ico-copy text-info me-2" aria-hidden="true"><x-ui.icon name="copy" /></i>{{ ___('Duplicate Files') }}
            </h1>
            <p class="text-muted mb-0">{{ ___('Files with identical content detected. Deleting duplicates saves disk space.') }}</p>
        </div>
        <a href="{{ route('admin.mediamanager.index') }}" class="btn btn-secondary">
            <i class="ico ico-arrow-left me-1" aria-hidden="true"><x-ui.icon name="arrow-left" /></i> {{ ___('Back to Dashboard') }}
        </a>
    </div>

    @if($groups)
        {{-- GROUP SUMMARY VIEW --}}
        @if($groups->count() > 0)
        <div class="card shadow mb-4">
            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                <h6 class="m-0 font-weight-bold mb-0">{{ ___('Duplicate Groups') }}</h6>
                <span class="text-muted">{{ $groups->total() }} {{ ___('groups') }}</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th width="100">{{ ___('Preview') }}</th>
                                <th>{{ ___('Group') }}</th>
                                <th width="100" class="text-end">{{ ___('Copies') }}</th>
                                <th width="120" class="text-end">{{ ___('Size Each') }}</th>
                                <th width="120" class="text-end">{{ ___('Wasted Space') }}</th>
                                <th width="120">{{ ___('Directory') }}</th>
                                <th width="180" class="text-center">{{ ___('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($groups as $group)
                            @php
                                $wastedSize = ($group->size ?? 0) * max(0, ($group->count ?? 1) - 1);
                                $sampleFilename = basename($group->sample_path ?? '');
                            @endphp
                            <tr>
                                <td>
                                    <div class="media-thumbnail-wrapper">
                                        <img src="{{ asset($group->sample_path ?? '') }}" alt="Duplicate" class="media-thumbnail" loading="lazy"
                                            data-ui-img-fallback="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%22100%22 height=%22100%22><rect fill=%22%23ddd%22 width=%22100%22 height=%22100%22/><text x=%2250%25%22 y=%2250%25%22 text-anchor=%22middle%22 dy=%22.3em%22 fill=%22%23999%22 font-size=%2214%22>?</text></svg>">
                                        <span class="media-duplicate-badge"><i class="ico ico-copy" aria-hidden="true"><x-ui.icon name="copy" /></i> {{ $group->count ?? 1 }}</span>
                                    </div>
                                </td>
                                <td class="align-middle">
                                    <div class="fw-bold">{{ $sampleFilename }}</div>
                                    <div class="small text-muted">{{ ___('Group') }} #{{ $group->duplicate_group_id }}</div>
                                </td>
                                <td class="align-middle text-end fw-bold">{{ $group->count ?? 1 }}</td>
                                <td class="align-middle text-end">{{ \Plugins\mediamanager\app\Services\ImageScanner::formatBytes($group->size ?? 0) }}</td>
                                <td class="align-middle text-end">
                                    <span class="text-danger fw-bold">{{ \Plugins\mediamanager\app\Services\ImageScanner::formatBytes($wastedSize) }}</span>
                                </td>
                                <td class="align-middle">
                                    <span class="badge bg-secondary">{{ $group->directory ?? '—' }}</span>
                                </td>
                                <td class="align-middle text-center">
                                    <div class="btn-group btn-group-sm">
                                        <a href="{{ route('admin.mediamanager.duplicates', ['group' => $group->duplicate_group_id]) }}" class="btn btn-outline-info" title="{{ ___('View all copies') }}">
                                            <i class="ico ico-eye me-1" aria-hidden="true"><x-ui.icon name="eye" /></i> {{ ___('View') }}
                                        </a>
                                        <form action="{{ route('admin.mediamanager.deleteDuplicateGroup') }}" method="POST" class="d-inline" data-ui-confirm="{{ ___('Delete all duplicates and keep only the oldest file? This cannot be undone.') }}">
                                            @csrf
                                            <input type="hidden" name="group_id" value="{{ $group->duplicate_group_id }}">
                                            <button type="submit" class="btn btn-outline-danger" title="{{ ___('Keep oldest, delete rest') }}">
                                                <i class="ico ico-trash me-1" aria-hidden="true"><x-ui.icon name="trash" /></i> {{ ___('Clean') }}
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{ $groups->links() }}
        @else
        <div class="card shadow">
            <div class="card-body text-center py-5">
                <i class="ico ico-checks ico-3x text-success mb-3" aria-hidden="true"><x-ui.icon name="checks" /></i>
                <h4>{{ ___('No duplicates found!') }}</h4>
                <p class="text-muted">{{ ___('All scanned files are unique, or no scan has been performed yet.') }}</p>
                <a href="{{ route('admin.mediamanager.index') }}" class="btn btn-primary mt-2">{{ ___('Back to Dashboard') }}</a>
            </div>
        </div>
        @endif

    @elseif($files)
        {{-- GROUP DETAIL VIEW --}}
        <div class="card shadow mb-4">
            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                <h6 class="m-0 font-weight-bold mb-0">
                    {{ ___('Group') }} #{{ request('group') }} — {{ $files->total() }} {{ ___('files') }}
                </h6>
                <div class="d-flex gap-2">
                    <form action="{{ route('admin.mediamanager.deleteDuplicateGroup') }}" method="POST" class="d-inline" data-ui-confirm="{{ ___('Delete all duplicates and keep only the oldest file? This cannot be undone.') }}">
                        @csrf
                        <input type="hidden" name="group_id" value="{{ request('group') }}">
                        <button type="submit" class="btn btn-danger btn-sm">
                            <i class="ico ico-trash me-1" aria-hidden="true"><x-ui.icon name="trash" /></i> {{ ___('Keep Oldest, Delete Rest') }}
                        </button>
                    </form>
                    <a href="{{ route('admin.mediamanager.duplicates') }}" class="btn btn-secondary btn-sm">
                        <i class="ico ico-arrow-left me-1" aria-hidden="true"><x-ui.icon name="arrow-left" /></i> {{ ___('Back to Groups') }}
                    </a>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th width="100">{{ ___('Preview') }}</th>
                                <th>{{ ___('Filename') }}</th>
                                <th width="120" class="text-end">{{ ___('Size') }}</th>
                                <th width="150">{{ ___('Date Modified') }}</th>
                                <th width="200">{{ ___('Full Path') }}</th>
                                <th width="120" class="text-center">{{ ___('Status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($files as $idx => $file)
                            <tr>
                                <td>
                                    <div class="media-thumbnail-wrapper">
                                        <img src="{{ asset($file->full_path) }}" alt="{{ $file->filename }}" class="media-thumbnail" loading="lazy"
                                            data-ui-img-fallback="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%22100%22 height=%22100%22><rect fill=%22%23ddd%22 width=%22100%22 height=%22100%22/><text x=%2250%25%22 y=%2250%25%22 text-anchor=%22middle%22 dy=%22.3em%22 fill=%22%23999%22 font-size=%2214%22>?</text></svg>">
                                        @if($file->is_protected)
                                        <span class="media-protection-badge" title="{{ ___('Protected') }}"><i class="ico ico-lock" aria-hidden="true"><x-ui.icon name="lock" /></i></span>
                                        @endif
                                    </div>
                                </td>
                                <td class="align-middle">
                                    <div class="fw-bold">{{ $file->filename }}</div>
                                    @if($idx === 0)
                                        <span class="badge bg-success"><i class="ico ico-crown me-1" aria-hidden="true"><x-ui.icon name="crown" /></i>{{ ___('Oldest (will keep)') }}</span>
                                    @endif
                                </td>
                                <td class="align-middle text-end fw-bold">{{ \Plugins\mediamanager\app\Services\ImageScanner::formatBytes($file->size) }}</td>
                                <td class="align-middle">
                                    <div class="small">{{ $file->modified_at ? $file->modified_at->format('M d, Y H:i') : '—' }}</div>
                                </td>
                                <td class="align-middle"><code class="small">{{ $file->full_path }}</code></td>
                                <td class="align-middle text-center">
                                    @if($file->is_protected)
                                        <span class="badge bg-primary"><i class="ico ico-lock me-1" aria-hidden="true"><x-ui.icon name="lock" /></i>{{ ___('Protected') }}</span>
                                    @elseif($file->is_referenced)
                                        <span class="badge bg-success"><i class="ico ico-link me-1" aria-hidden="true"><x-ui.icon name="link" /></i>{{ ___('Used') }}</span>
                                    @else
                                        <span class="badge bg-warning"><i class="ico ico-question-mark me-1" aria-hidden="true"><x-ui.icon name="question-mark" /></i>{{ ___('Unknown') }}</span>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
</div>
@endsection
