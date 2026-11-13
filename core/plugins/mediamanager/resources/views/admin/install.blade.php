{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layouts.main')

@section('title', ___('Media Manager - Install'))

@section('content')
@once<link rel="stylesheet" href="{{ asset('assets/global/css/ui-icon.css') }}">@endonce
<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card shadow">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">{{ ___('Install Media Manager') }}</h6>
                </div>
                <div class="card-body">
                    <p class="lead">{{ ___('Welcome to Media Manager.') }}</p>
                    <p>{{ ___('This plugin will help you identify and remove unused images from your storage, freeing up disk space.') }}</p>

                    <div class="alert alert-info">
                        <h6>{{ ___('Before you begin:') }}</h6>
                        <ul class="mb-0">
                            <li>{{ ___('Make sure you have a full backup of your storage folder.') }}</li>
                            <li>{{ ___('The plugin will create two database tables:') }} <code>plugin_mediamanager_files</code> {{ ___('and') }} <code>plugin_mediamanager_logs</code>.</li>
                            <li>{{ ___('Deleted files are moved to a trash folder:') }} <code>storage/.mediamanager-trash/</code></li>
                        </ul>
                    </div>

                    <form action="{{ route('admin.mediamanager.install.post') }}" method="POST">
                        @csrf
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="ico ico-settings me-2" aria-hidden="true"><x-ui.icon name="settings" /></i> {{ ___('Install Now') }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
