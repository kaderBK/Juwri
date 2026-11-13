{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layouts.main')

@section('title', ___('Media Manager Settings'))

@section('content')
@once<link rel="stylesheet" href="{{ asset('assets/global/css/ui-icon.css') }}">@endonce
<link rel="stylesheet" href="{{ plugin_assets('mediamanager', 'css/mediamanager.css') }}">

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0 text-gray-800">
                <i class="ico ico-settings text-primary me-2" aria-hidden="true"><x-ui.icon name="settings" /></i>{{ ___('Media Manager Settings') }}
            </h1>
            <p class="text-muted mb-0">{{ ___('Configure which directories to scan for media files.') }}</p>
        </div>
        <a href="{{ route('admin.mediamanager.index') }}" class="btn btn-secondary">
            <i class="ico ico-arrow-left me-1" aria-hidden="true"><x-ui.icon name="arrow-left" /></i> {{ ___('Back to Dashboard') }}
        </a>
    </div>

    @if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">
        <i class="ico ico-circle-check me-2" aria-hidden="true"><x-ui.icon name="circle-check" /></i>{{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    @endif

    @if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="ico ico-circle-x me-2" aria-hidden="true"><x-ui.icon name="circle-x" /></i>{{ session('error') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    @endif

    <div class="row">
        <div class="col-lg-8">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold"><i class="ico ico-folder-open me-2" aria-hidden="true"><x-ui.icon name="folder-open" /></i>{{ ___('Scan Directories') }}</h6>
                </div>
                <div class="card-body">
                    <form action="{{ route('admin.mediamanager.saveSettings') }}" method="POST">
                        @csrf
                        <p class="text-muted">{{ ___('Select which directories under /storage/ to scan for image files.') }}</p>

                        <div class="mb-4">
                            @foreach($allDirectories as $dir)
                            @php $isEnabled = in_array($dir, $savedDirectories); @endphp
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="scan_directories[]" value="{{ $dir }}" id="dir_{{ md5($dir) }}" {{ $isEnabled ? 'checked' : '' }}>
                                <label class="form-check-label fw-bold" for="dir_{{ md5($dir) }}">
                                    <i class="ico ico-folder text-warning me-1" aria-hidden="true"><x-ui.icon name="folder" /></i> {{ $dir }}
                                </label>
                            </div>
                            @endforeach
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="ico ico-device-floppy me-1" aria-hidden="true"><x-ui.icon name="device-floppy" /></i> {{ ___('Save Settings') }}
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold"><i class="ico ico-info me-2" aria-hidden="true"><x-ui.icon name="info" /></i>{{ ___('About') }}</h6>
                </div>
                <div class="card-body">
                    <p class="small text-muted">
                        <strong>Media Manager</strong> v1.0.0<br>
                        {{ ___('Scans your storage directories to find unused and duplicate images, helping you free up disk space safely.') }}
                    </p>
                    <hr>
                    <p class="small text-muted mb-0">
                        <i class="ico ico-shield me-1" aria-hidden="true"><x-ui.icon name="shield" /></i>
                        {{ ___('Protected files will never be deleted automatically. You can protect important images from the orphan view.') }}
                    </p>
                </div>
            </div>

            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold"><i class="ico ico-bolt me-2" aria-hidden="true"><x-ui.icon name="bolt" /></i>{{ ___('Quick Actions') }}</h6>
                </div>
                <div class="card-body">
                    <a href="{{ route('admin.mediamanager.index') }}" class="btn btn-primary w-100 mb-2">
                        <i class="ico ico-refresh me-1" aria-hidden="true"><x-ui.icon name="refresh" /></i> {{ ___('Run New Scan') }}
                    </a>
                    <a href="{{ route('admin.mediamanager.duplicates') }}" class="btn btn-info w-100 mb-2">
                        <i class="ico ico-copy me-1" aria-hidden="true"><x-ui.icon name="copy" /></i> {{ ___('View Duplicates') }}
                    </a>
                    <a href="{{ route('admin.mediamanager.logs') }}" class="btn btn-secondary w-100">
                        <i class="ico ico-history me-1" aria-hidden="true"><x-ui.icon name="history" /></i> {{ ___('View Logs') }}
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
