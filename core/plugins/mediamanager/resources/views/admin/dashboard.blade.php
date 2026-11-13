{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layouts.main')

@section('title', ___('Media Manager'))

@section('content')
@once<link rel="stylesheet" href="{{ asset('assets/global/css/ui-icon.css') }}">@endonce
<link rel="stylesheet" href="{{ plugin_assets('mediamanager', 'css/mediamanager.css') }}">

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0 text-gray-800">{{ ___('Media Manager') }}</h1>
        <button type="button" id="start-scan-btn" class="btn btn-primary">
            <i class="ico ico-refresh me-2" aria-hidden="true"><x-ui.icon name="refresh" /></i> {{ ___('Start Scan') }}
        </button>
    </div>

    @if($lastScan)
    <div class="alert alert-info d-flex justify-content-between align-items-center">
        <span><i class="ico ico-clock me-2" aria-hidden="true"><x-ui.icon name="clock" /></i>{{ ___('Last scan:') }} {{ \Carbon\Carbon::parse($lastScan)->diffForHumans() }}</span>
        <span>{{ ___('Total files scanned:') }} {{ number_format($summary['total']['count'] ?? 0) }}</span>
    </div>
    @endif

    <!-- Scan Progress -->
    <div id="scan-progress-card" class="card mb-4 d-none">
        <div class="card-body">
            <h5 class="card-title"><i class="ico ico-loader-2 ico-spin me-2" aria-hidden="true"><x-ui.icon name="loader-2" /></i>{{ ___('Scanning Storage...') }}</h5>
            <div class="progress mb-3" style="height: 25px;">
                <div id="scan-progress-bar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%">0%</div>
            </div>
            <p id="scan-status" class="mb-0 text-muted">{{ ___('Initializing...') }}</p>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row">
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">{{ ___('Total Files') }}</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">{{ number_format($summary['total']['count'] ?? 0) }}</div>
                            <div class="small text-muted">{{ \Plugins\mediamanager\app\Services\ImageScanner::formatBytes($summary['total']['size'] ?? 0) }}</div>
                        </div>
                        <div class="col-auto">
                            <i class="ico ico-server-2 ico-2x text-gray-300" aria-hidden="true"><x-ui.icon name="server-2" /></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">{{ ___('Used Files') }}</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">{{ number_format($summary['used']['count'] ?? 0) }}</div>
                            <div class="small text-muted">{{ \Plugins\mediamanager\app\Services\ImageScanner::formatBytes($summary['used']['size'] ?? 0) }}</div>
                        </div>
                        <div class="col-auto">
                            <i class="ico ico-circle-check ico-2x text-gray-300" aria-hidden="true"><x-ui.icon name="circle-check" /></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-danger shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">{{ ___('Orphaned Files') }}</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">{{ number_format($summary['orphaned']['count'] ?? 0) }}</div>
                            <div class="small text-muted">{{ \Plugins\mediamanager\app\Services\ImageScanner::formatBytes($summary['orphaned']['size'] ?? 0) }}</div>
                        </div>
                        <div class="col-auto">
                            <i class="ico ico-alert ico-2x text-gray-300" aria-hidden="true"><x-ui.icon name="alert" /></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">{{ ___('Unknown Files') }}</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">{{ number_format($summary['unknown']['count'] ?? 0) }}</div>
                            <div class="small text-muted">{{ \Plugins\mediamanager\app\Services\ImageScanner::formatBytes($summary['unknown']['size'] ?? 0) }}</div>
                        </div>
                        <div class="col-auto">
                            <i class="ico ico-help-circle ico-2x text-gray-300" aria-hidden="true"><x-ui.icon name="help-circle" /></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Second row: Duplicates + Trash -->
    <div class="row mb-4">
        <div class="col-xl-6 mb-4">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">{{ ___('Duplicate Files') }}</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">{{ number_format($summary['duplicates']['count'] ?? 0) }}</div>
                            <div class="small text-muted">{{ ___('Wasting') }} {{ \Plugins\mediamanager\app\Services\ImageScanner::formatBytes($summary['duplicates']['size'] ?? 0) }}</div>
                        </div>
                        <div class="col-auto">
                            <i class="ico ico-copy ico-2x text-gray-300" aria-hidden="true"><x-ui.icon name="copy" /></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-6 mb-4">
            <div class="card shadow h-100 py-2" style="border-left: 4px solid #6c757d;">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-secondary text-uppercase mb-1">{{ ___('Trash') }}</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">{{ count($trashFiles) }} {{ ___('files') }}</div>
                            <div class="small text-muted">{{ \Plugins\mediamanager\app\Services\ImageScanner::formatBytes($trashSize) }}</div>
                        </div>
                        <div class="col-auto d-flex align-items-center">
                            <a href="{{ route('admin.mediamanager.trash') }}" class="btn btn-sm btn-outline-secondary me-2">
                                <i class="ico ico-eye me-1" aria-hidden="true"><x-ui.icon name="eye" /></i> {{ ___('View') }}
                            </a>
                            <form action="{{ route('admin.mediamanager.emptyTrash') }}" method="POST" class="me-2" data-ui-confirm="{{ ___("Empty the entire trash? This cannot be undone.") }}">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-danger" {{ empty($trashFiles) ? 'disabled' : '' }}>
                                    <i class="ico ico-trash me-1" aria-hidden="true"><x-ui.icon name="trash" /></i> {{ ___('Empty Trash') }}
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-6 mb-4">
            <div class="card shadow h-100 py-2" style="border-left: 4px solid #ffc107;">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">{{ ___('Unknown Files') }}</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">{{ number_format($summary['unknown']['count'] ?? 0) }}</div>
                            <div class="small text-muted">{{ \Plugins\mediamanager\app\Services\ImageScanner::formatBytes($summary['unknown']['size'] ?? 0) }}</div>
                        </div>
                        <div class="col-auto">
                            <a href="{{ route('admin.mediamanager.unknown') }}" class="btn btn-sm btn-warning">
                                <i class="ico ico-help-circle me-1" aria-hidden="true"><x-ui.icon name="help-circle" /></i> {{ ___('View') }}
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow">
                <div class="card-body d-flex flex-wrap gap-2">
                    <a href="{{ route('admin.mediamanager.orphans') }}" class="btn btn-danger">
                        <i class="ico ico-alert me-2" aria-hidden="true"><x-ui.icon name="alert" /></i> {{ ___('View Orphans') }}
                    </a>
                    <a href="{{ route('admin.mediamanager.used') }}" class="btn btn-success">
                        <i class="ico ico-circle-check me-2" aria-hidden="true"><x-ui.icon name="circle-check" /></i> {{ ___('View Used Files') }}
                    </a>
                    <a href="{{ route('admin.mediamanager.unknown') }}" class="btn btn-warning">
                        <i class="ico ico-help-circle me-2" aria-hidden="true"><x-ui.icon name="help-circle" /></i> {{ ___('View Unknown Files') }}
                    </a>
                    <a href="{{ route('admin.mediamanager.duplicates') }}" class="btn btn-info">
                        <i class="ico ico-copy me-2" aria-hidden="true"><x-ui.icon name="copy" /></i> {{ ___('View Duplicates') }}
                    </a>
                    <a href="{{ route('admin.mediamanager.trash') }}" class="btn btn-secondary">
                        <i class="ico ico-trash me-2" aria-hidden="true"><x-ui.icon name="trash" /></i> {{ ___('View Trash') }}
                    </a>
                    <a href="{{ route('admin.mediamanager.logs') }}" class="btn btn-secondary">
                        <i class="ico ico-history me-2" aria-hidden="true"><x-ui.icon name="history" /></i> {{ ___('Cleanup Logs') }}
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Storage Summary by Status -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary"><i class="ico ico-chart-pie me-2" aria-hidden="true"><x-ui.icon name="chart-pie" /></i>{{ ___('Storage Summary') }}</h6>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>{{ ___('Status') }}</th>
                            <th class="text-end">{{ ___('Files') }}</th>
                            <th class="text-end">{{ ___('Size') }}</th>
                            <th class="text-end">{{ ___('% of Total') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php $totalSize = max(1, $summary['total']['size'] ?? 1); @endphp
                        <tr class="table-success">
                            <td><i class="ico ico-circle-check me-2" aria-hidden="true"><x-ui.icon name="circle-check" /></i>{{ ___('Used') }}</td>
                            <td class="text-end">{{ number_format($summary['used']['count'] ?? 0) }}</td>
                            <td class="text-end">{{ \Plugins\mediamanager\app\Services\ImageScanner::formatBytes($summary['used']['size'] ?? 0) }}</td>
                            <td class="text-end">{{ round((($summary['used']['size'] ?? 0) / $totalSize) * 100, 1) }}%</td>
                        </tr>
                        <tr class="table-danger">
                            <td><i class="ico ico-alert me-2" aria-hidden="true"><x-ui.icon name="alert" /></i>{{ ___('Orphaned') }}</td>
                            <td class="text-end">{{ number_format($summary['orphaned']['count'] ?? 0) }}</td>
                            <td class="text-end">{{ \Plugins\mediamanager\app\Services\ImageScanner::formatBytes($summary['orphaned']['size'] ?? 0) }}</td>
                            <td class="text-end">{{ round((($summary['orphaned']['size'] ?? 0) / $totalSize) * 100, 1) }}%</td>
                        </tr>
                        <tr class="table-warning">
                            <td><i class="ico ico-help-circle me-2" aria-hidden="true"><x-ui.icon name="help-circle" /></i>{{ ___('Unknown') }}</td>
                            <td class="text-end">{{ number_format($summary['unknown']['count'] ?? 0) }}</td>
                            <td class="text-end">{{ \Plugins\mediamanager\app\Services\ImageScanner::formatBytes($summary['unknown']['size'] ?? 0) }}</td>
                            <td class="text-end">{{ round((($summary['unknown']['size'] ?? 0) / $totalSize) * 100, 1) }}%</td>
                        </tr>
                        <tr class="table-secondary">
                            <td><i class="ico ico-lock me-2" aria-hidden="true"><x-ui.icon name="lock" /></i>{{ ___('Protected') }}</td>
                            <td class="text-end">{{ number_format($summary['protected']['count'] ?? 0) }}</td>
                            <td class="text-end">{{ \Plugins\mediamanager\app\Services\ImageScanner::formatBytes($summary['protected']['size'] ?? 0) }}</td>
                            <td class="text-end">{{ round((($summary['protected']['size'] ?? 0) / $totalSize) * 100, 1) }}%</td>
                        </tr>
                        <tr class="table-info">
                            <td><i class="ico ico-copy me-2" aria-hidden="true"><x-ui.icon name="copy" /></i>{{ ___('Duplicates') }}</td>
                            <td class="text-end">{{ number_format($summary['duplicates']['count'] ?? 0) }}</td>
                            <td class="text-end">{{ \Plugins\mediamanager\app\Services\ImageScanner::formatBytes($summary['duplicates']['size'] ?? 0) }}</td>
                            <td class="text-end">{{ round((($summary['duplicates']['size'] ?? 0) / $totalSize) * 100, 1) }}%</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Directory Breakdown -->
    @if(!empty($directoryStats) && $directoryStats->count() > 0)
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary"><i class="ico ico-folder-open me-2" aria-hidden="true"><x-ui.icon name="folder-open" /></i>{{ ___('Directory Breakdown') }}</h6>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover">
                    <thead>
                        <tr>
                            <th>{{ ___('Directory') }}</th>
                            <th class="text-end">{{ ___('Total Files') }}</th>
                            <th class="text-end">{{ ___('Total Size') }}</th>
                            <th class="text-end">{{ ___('Used') }}</th>
                            <th class="text-end">{{ ___('Orphaned') }}</th>
                            <th class="text-end">{{ ___('Other') }}</th>
                            <th class="text-end">{{ ___('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($directoryStats as $dir)
                        <tr>
                            <td><i class="ico ico-folder me-2 text-warning" aria-hidden="true"><x-ui.icon name="folder" /></i><code>{{ $dir['directory'] }}</code></td>
                            <td class="text-end">{{ number_format($dir['total_count']) }}</td>
                            <td class="text-end">{{ \Plugins\mediamanager\app\Services\ImageScanner::formatBytes($dir['total_size']) }}</td>
                            <td class="text-end"><span class="badge bg-success">{{ number_format($dir['used_count']) }}</span></td>
                            <td class="text-end"><span class="badge bg-danger">{{ number_format($dir['orphaned_count']) }}</span></td>
                            <td class="text-end"><span class="badge bg-secondary">{{ number_format($dir['other_count']) }}</span></td>
                            <td class="text-end">
                                <a href="{{ route('admin.mediamanager.orphans', ['directory' => $dir['directory']]) }}" class="btn btn-sm btn-outline-danger" title="{{ ___('View orphans in this directory') }}">
                                    <i class="ico ico-trash" aria-hidden="true"><x-ui.icon name="trash" /></i>
                                </a>
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

<script>
    document.getElementById('start-scan-btn')?.addEventListener('click', async function() {
        const btn = this;
        const progressCard = document.getElementById('scan-progress-card');
        const progressBar = document.getElementById('scan-progress-bar');
        const statusEl = document.getElementById('scan-status');

        btn.disabled = true;
        progressCard.classList.remove('d-none');
        statusEl.textContent = '{{ ___("Initializing scan...") }}';

        try {
            const initResponse = await fetch('{{ route("admin.mediamanager.scan.init") }}');
            const initData = await initResponse.json();

            if (initData.error) {
                throw new Error(initData.error);
            }

            const directories = initData.directories;
            const total = directories.length;
            let completed = 0;
            let hasError = false;
            let totalFiles = 0;
            let orphanedFiles = 0;

            for (const directory of directories) {
                statusEl.textContent = '{{ ___("Scanning") }}: ' + directory + ' (' + (completed + 1) + '/' + total + ')';

                try {
                    const scanResponse = await fetch('{{ route("admin.mediamanager.scan") }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        },
                        body: JSON.stringify({ directory: directory })
                    });
                    const scanData = await scanResponse.json();

                    if (scanData.stats) {
                        totalFiles += scanData.stats.total || 0;
                        orphanedFiles += scanData.stats.orphaned || 0;
                    }
                } catch (e) {
                    hasError = true;
                    console.error('Scan error for ' + directory, e);
                }

                completed++;
                const percent = Math.round((completed / total) * 100);
                progressBar.style.width = percent + '%';
                progressBar.textContent = percent + '%';
            }

            // Mark scan complete (runs duplicate detection)
            statusEl.textContent = '{{ ___("Running duplicate detection...") }}';
            const completeResponse = await fetch('{{ route("admin.mediamanager.scan.complete") }}');
            const completeData = await completeResponse.json();

            if (hasError) {
                statusEl.innerHTML = '<span class="text-warning">{{ ___("Scan completed with some errors.") }}</span>';
            } else {
                statusEl.innerHTML = '<span class="text-success"><i class="ico ico-circle-check me-2" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 12a9 9 0 1 0 18 0a9 9 0 1 0 -18 0"/><path d="M9 12l2 2l4 -4"/></svg></i>{{ ___("Scan completed successfully!") }}</span> ' +
                    totalFiles + ' {{ ___("files scanned") }}, ' + orphanedFiles + ' {{ ___("orphans found") }}.';
            }

            progressBar.classList.remove('progress-bar-animated');
            progressBar.classList.add('bg-success');

            // Reload after 2 seconds to show updated stats
            setTimeout(() => { window.location.reload(); }, 2000);

        } catch (error) {
            statusEl.innerHTML = '<span class="text-danger"><i class="ico ico-circle-x me-2" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 12a9 9 0 1 0 18 0a9 9 0 1 0 -18 0"/><path d="M10 10l4 4m0 -4l-4 4"/></svg></i>{{ ___("Scan failed:") }} ' + error.message + '</span>';
            progressBar.classList.add('bg-danger');
        }

        btn.disabled = false;
    });
</script>
@endsection
