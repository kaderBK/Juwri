{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layouts.main')

@section('title', ___('Used Files'))

@section('content')
@once<link rel="stylesheet" href="{{ asset('assets/global/css/ui-icon.css') }}">@endonce
<link rel="stylesheet" href="{{ plugin_assets('mediamanager', 'css/mediamanager.css') }}">

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0 text-gray-800">
                <i class="ico ico-circle-check text-success me-2" aria-hidden="true"><x-ui.icon name="circle-check" /></i>{{ ___('Used Files') }}
            </h1>
            <p class="text-muted mb-0">{{ ___('These files are actively referenced by your database records.') }}</p>
        </div>
        <a href="{{ route('admin.mediamanager.index') }}" class="btn btn-secondary">
            <i class="ico ico-arrow-left me-1" aria-hidden="true"><x-ui.icon name="arrow-left" /></i> {{ ___('Back to Dashboard') }}
        </a>
    </div>

    <!-- Filters & Search -->
    <div class="card shadow mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.mediamanager.used') }}" class="row g-3 align-items-end">
                <div class="col-md-5">
                    <label class="form-label fw-bold"><i class="ico ico-search me-1" aria-hidden="true"><x-ui.icon name="search" /></i>{{ ___('Search') }}</label>
                    <input type="text" name="search" class="form-control" placeholder="{{ ___('Filename, reference name, or owner...') }}" value="{{ $search ?? '' }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold"><i class="ico ico-folder me-1" aria-hidden="true"><x-ui.icon name="folder" /></i>{{ ___('Directory') }}</label>
                    <select name="directory" class="form-select">
                        <option value="">{{ ___('All Directories') }}</option>
                        @foreach($directories as $dir)
                        <option value="{{ $dir->directory }}" {{ ($directory ?? '') === $dir->directory ? 'selected' : '' }}>
                            {{ $dir->directory }} ({{ number_format($dir->count) }})
                        </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary w-100"><i class="ico ico-filter me-1" aria-hidden="true"><x-ui.icon name="filter" /></i> {{ ___('Filter') }}</button>
                </div>
            </form>
        </div>
    </div>

    <!-- File List -->
    @if($files->count() > 0)
    <div class="row">
        <div class="col-lg-9">
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold mb-0">{{ ___('Files') }}</h6>
                    <span class="text-muted">{{ $files->total() }} {{ ___('files') }}</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th width="100">{{ ___('Preview') }}</th>
                                    <th>{{ ___('Filename') }}</th>
                                    <th width="250">{{ ___('Used By') }}</th>
                                    <th width="120">{{ ___('Directory') }}</th>
                                    <th width="100" class="text-end">{{ ___('Size') }}</th>
                                    <th width="150">{{ ___('Date Modified') }}</th>
                                    <th width="80" class="text-center">{{ ___('Status') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($files as $file)
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
                                        <div class="small text-muted"><code>{{ $file->full_path }}</code></div>
                                    </td>
                                    <td class="align-middle">
                                        @if($file->used_by_summary)
                                            <div class="d-flex align-items-center">
                                                <i class="ico ico-database text-success me-2" aria-hidden="true"><x-ui.icon name="database" /></i>
                                                <div>
                                                    <div class="fw-bold small">{{ $file->used_by_summary }}</div>
                                                    @if($file->reference_table)
                                                        <div class="small text-muted"><code>{{ $file->reference_table }}</code> #{{ $file->reference_id }}</div>
                                                    @endif
                                                </div>
                                            </div>
                                        @else
                                            <span class="text-muted small">—</span>
                                        @endif
                                    </td>
                                    <td class="align-middle">
                                        <span class="badge bg-success-subtle text-success">{{ $file->directory }}</span>
                                    </td>
                                    <td class="align-middle text-end fw-bold">{{ \Plugins\mediamanager\app\Services\ImageScanner::formatBytes($file->size) }}</td>
                                    <td class="align-middle">
                                        <div class="small">{{ $file->modified_at ? $file->modified_at->format('M d, Y H:i') : '—' }}</div>
                                    </td>
                                    <td class="align-middle text-center">
                                        @if($file->is_duplicate)
                                            <span class="badge bg-warning" title="{{ ___('This file has duplicates') }}"><i class="ico ico-copy" aria-hidden="true"><x-ui.icon name="copy" /></i></span>
                                        @endif
                                        @if($file->is_protected)
                                            <span class="badge bg-primary" title="{{ ___('Protected') }}"><i class="ico ico-lock" aria-hidden="true"><x-ui.icon name="lock" /></i></span>
                                        @endif
                                        <span class="badge bg-success"><i class="ico ico-link" aria-hidden="true"><x-ui.icon name="link" /></i></span>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{ $files->links() }}
        </div>

        <!-- Side Panel: Directory Summary -->
        <div class="col-lg-3">
            <div class="card shadow sticky-top" style="top: 1rem;">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold"><i class="ico ico-folder-open me-2" aria-hidden="true"><x-ui.icon name="folder-open" /></i>{{ ___('By Directory') }}</h6>
                </div>
                <div class="card-body p-0">
                    <ul class="list-group list-group-flush">
                        @foreach($directories as $dir)
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <a href="{{ route('admin.mediamanager.used', ['directory' => $dir->directory]) }}" class="text-decoration-none {{ ($directory ?? '') === $dir->directory ? 'fw-bold' : '' }}">
                                {{ $dir->directory }}
                            </a>
                            <div>
                                <span class="badge bg-success me-1">{{ number_format($dir->count) }}</span>
                                <span class="badge bg-secondary">{{ \Plugins\mediamanager\app\Services\ImageScanner::formatBytes($dir->total_size) }}</span>
                            </div>
                        </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    </div>
    @else
    <div class="card shadow">
        <div class="card-body text-center py-5">
            <i class="ico ico-folder-open ico-3x text-muted mb-3" aria-hidden="true"><x-ui.icon name="folder-open" /></i>
            <h4>{{ ___('No used files found') }}</h4>
            <p class="text-muted">{{ ___('No files match your search criteria, or no scan has been performed yet.') }}</p>
            <a href="{{ route('admin.mediamanager.index') }}" class="btn btn-primary mt-2">{{ ___('Back to Dashboard') }}</a>
        </div>
    </div>
    @endif
</div>
@endsection
