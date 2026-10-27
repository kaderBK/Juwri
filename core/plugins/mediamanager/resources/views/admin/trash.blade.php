{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layouts.main')

@section('title', ___('Trash'))

@section('content')
@once<link rel="stylesheet" href="{{ asset('assets/global/css/ui-icon.css') }}">@endonce
<link rel="stylesheet" href="{{ plugin_assets('mediamanager', 'css/mediamanager.css') }}">

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0 text-gray-800">
                <i class="ico ico-trash text-secondary me-2" aria-hidden="true"><x-ui.icon name="trash" /></i>{{ ___('Trash') }}
            </h1>
            <p class="text-muted mb-0">{{ ___('Files moved here can be restored to their original location. Empty trash to delete them permanently.') }}</p>
        </div>
        <a href="{{ route('admin.mediamanager.index') }}" class="btn btn-secondary">
            <i class="ico ico-arrow-left me-1" aria-hidden="true"><x-ui.icon name="arrow-left" /></i> {{ ___('Back to Dashboard') }}
        </a>
    </div>

    @if(count($trashFiles) > 0)
    <div class="row mb-4">
        <div class="col-xl-4 col-md-6 mb-4">
            <div class="card border-left-secondary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-secondary text-uppercase mb-1">{{ ___('Trashed Files') }}</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">{{ number_format(count($trashFiles)) }}</div>
                        </div>
                        <div class="col-auto">
                            <i class="ico ico-trash ico-2x text-gray-300" aria-hidden="true"><x-ui.icon name="trash" /></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-md-6 mb-4">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">{{ ___('Total Size') }}</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">{{ \Plugins\mediamanager\app\Services\ImageScanner::formatBytes($totalSize) }}</div>
                        </div>
                        <div class="col-auto">
                            <i class="ico ico-server-2 ico-2x text-gray-300" aria-hidden="true"><x-ui.icon name="server-2" /></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-md-6 mb-4">
            <div class="card shadow h-100 py-2">
                <div class="card-body d-flex align-items-center justify-content-center">
                    <form action="{{ route('admin.mediamanager.emptyTrash') }}" method="POST" data-ui-confirm="{{ ___("Permanently delete ALL files in trash? This cannot be undone.") }}">
                        @csrf
                        <button type="submit" class="btn btn-outline-danger">
                            <i class="ico ico-trash me-2" aria-hidden="true"><x-ui.icon name="trash" /></i> {{ ___('Empty Trash') }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th width="100">{{ ___('Preview') }}</th>
                            <th>{{ ___('Filename') }}</th>
                            <th width="120">{{ ___('Original Directory') }}</th>
                            <th width="100" class="text-end">{{ ___('Size') }}</th>
                            <th width="180">{{ ___('Date Deleted') }}</th>
                            <th width="120" class="text-center">{{ ___('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($trashFiles as $file)
                        <tr>
                            <td>
                                <div class="media-thumbnail-wrapper">
                                    <img src="{{ asset('storage/.mediamanager-trash/' . $file['path']) }}" alt="{{ $file['filename'] }}" class="media-thumbnail" loading="lazy"
                                        data-ui-do="hide-on-error">
                                </div>
                            </td>
                            <td class="align-middle">
                                <div class="fw-bold">{{ $file['filename'] }}</div>
                                <div class="small text-muted">storage/.mediamanager-trash/{{ $file['path'] }}</div>
                            </td>
                            <td class="align-middle">
                                <span class="badge bg-secondary">{{ $file['directory'] }}</span>
                            </td>
                            <td class="align-middle text-end fw-bold">{{ \Plugins\mediamanager\app\Services\ImageScanner::formatBytes($file['size']) }}</td>
                            <td class="align-middle">
                                <div class="small">{{ $file['deleted_at'] ? date('M d, Y H:i', $file['deleted_at']) : '—' }}</div>
                            </td>
                            <td class="align-middle text-center">
                                <form action="{{ route('admin.mediamanager.restoreFromTrash') }}" method="POST" class="d-inline">
                                    @csrf
                                    <input type="hidden" name="path" value="storage/{{ $file['directory'] ? $file['directory'] . '/' : '' }}{{ $file['filename'] }}">
                                    <button type="submit" class="btn btn-sm btn-success" title="{{ ___('Restore to original location') }}">
                                        <i class="ico ico-restore me-1" aria-hidden="true"><x-ui.icon name="restore" /></i> {{ ___('Restore') }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    @else
    <div class="card shadow">
        <div class="card-body text-center py-5">
            <i class="ico ico-trash ico-3x text-muted mb-3" aria-hidden="true"><x-ui.icon name="trash" /></i>
            <h4>{{ ___('Trash is empty') }}</h4>
            <p class="text-muted">{{ ___('No files have been moved to trash yet.') }}</p>
            <a href="{{ route('admin.mediamanager.index') }}" class="btn btn-primary mt-2">{{ ___('Back to Dashboard') }}</a>
        </div>
    </div>
    @endif
</div>
@endsection
