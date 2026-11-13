{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layouts.main')

@section('title', ___('Unknown Files'))

@section('content')
@once<link rel="stylesheet" href="{{ asset('assets/global/css/ui-icon.css') }}">@endonce
<link rel="stylesheet" href="{{ plugin_assets('mediamanager', 'css/mediamanager.css') }}">

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0 text-gray-800">
                <i class="ico ico-help-circle text-warning me-2" aria-hidden="true"><x-ui.icon name="help-circle" /></i>{{ ___('Unknown Files') }}
            </h1>
            <p class="text-muted mb-0">{{ ___('These files exist in storage but the scanner does not know which database table references them. They may be hardcoded icons, templates, or leftover files.') }}</p>
        </div>
        <a href="{{ route('admin.mediamanager.index') }}" class="btn btn-secondary">
            <i class="ico ico-arrow-left me-1" aria-hidden="true"><x-ui.icon name="arrow-left" /></i> {{ ___('Back to Dashboard') }}
        </a>
    </div>

    <!-- Info Alert -->
    <div class="alert alert-warning mb-4">
        <i class="ico ico-info me-2" aria-hidden="true"><x-ui.icon name="info" /></i>
        <strong>{{ ___('How to handle Unknown files:') }}</strong>
        <ul class="mb-0 mt-1">
            <li>{{ ___('If you recognize them as needed (e.g. payment icons, QR templates), click') }} <strong>{{ ___('Protect') }}</strong> {{ ___('to prevent accidental deletion.') }}</li>
            <li>{{ ___('If you are sure they are unused, select them and click') }} <strong>{{ ___('Move to Trash') }}</strong>.</li>
            <li>{{ ___('If you want them properly tracked, tell me what database table references them and I can add a mapping.') }}</li>
        </ul>
    </div>

    <!-- Filters & Search -->
    <div class="card shadow mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.mediamanager.unknown') }}" class="row g-3 align-items-end">
                <div class="col-md-5">
                    <label class="form-label fw-bold"><i class="ico ico-search me-1" aria-hidden="true"><x-ui.icon name="search" /></i>{{ ___('Search') }}</label>
                    <input type="text" name="search" class="form-control" placeholder="{{ ___('Filename or path...') }}" value="{{ $search ?? '' }}">
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

    <!-- Bulk Actions -->
    <div class="card shadow mb-4" id="bulk-actions-card" style="display: none;">
        <div class="card-body d-flex align-items-center justify-content-between">
            <div>
                <span id="selected-count" class="fw-bold">0</span> {{ ___('files selected') }}
                (<span id="selected-size" class="text-muted">0 B</span>)
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-danger" id="bulk-delete-btn" data-mode="trash">
                    <i class="ico ico-trash me-1" aria-hidden="true"><x-ui.icon name="trash" /></i> {{ ___('Move to Trash') }}
                </button>
                <button type="button" class="btn btn-dark" id="bulk-delete-permanent-btn" data-mode="permanent">
                    <i class="ico ico-circle-x me-1" aria-hidden="true"><x-ui.icon name="circle-x" /></i> {{ ___('Delete Permanently') }}
                </button>
                <button type="button" class="btn btn-warning" id="bulk-protect-btn">
                    <i class="ico ico-lock me-1" aria-hidden="true"><x-ui.icon name="lock" /></i> {{ ___('Protect All') }}
                </button>
                <button type="button" class="btn btn-secondary" id="deselect-all-btn">
                    <i class="ico ico-x me-1" aria-hidden="true"><x-ui.icon name="x" /></i> {{ ___('Deselect All') }}
                </button>
            </div>
        </div>
    </div>

    @if($files->count() > 0)
    <div class="row">
        <div class="col-lg-9">
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <div>
                        <input type="checkbox" id="select-all" class="form-check-input me-2">
                        <label for="select-all" class="fw-bold mb-0">{{ ___('Select All') }}</label>
                    </div>
                    <span class="text-muted">{{ $files->total() }} {{ ___('files') }}</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th width="40"></th>
                                    <th width="120">{{ ___('Preview') }}</th>
                                    <th>{{ ___('Filename') }}</th>
                                    <th width="120">{{ ___('Directory') }}</th>
                                    <th width="100" class="text-end">{{ ___('Size') }}</th>
                                    <th width="150">{{ ___('Date Modified') }}</th>
                                    <th width="100" class="text-center">{{ ___('Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($files as $file)
                                <tr class="media-row" data-id="{{ $file->id }}" data-filename="{{ $file->filename }}" data-size="{{ $file->size }}">
                                    <td class="align-middle">
                                        <input type="checkbox" name="file_ids[]" value="{{ $file->id }}" class="form-check-input file-checkbox">
                                    </td>
                                    <td>
                                        <div class="media-thumbnail-wrapper">
                                            <img src="{{ asset($file->full_path) }}" alt="{{ $file->filename }}" class="media-thumbnail" loading="lazy"
                                                data-ui-do="hide-on-error">
                                            @if($file->is_protected)
                                            <span class="media-protection-badge" title="{{ ___('Protected') }}"><i class="ico ico-lock" aria-hidden="true"><x-ui.icon name="lock" /></i></span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="align-middle">
                                        <div class="fw-bold">{{ $file->filename }}</div>
                                        <div class="small text-muted"><code>{{ $file->full_path }}</code></div>
                                        @if($file->is_protected)
                                            <span class="badge bg-primary"><i class="ico ico-lock me-1" aria-hidden="true"><x-ui.icon name="lock" /></i>{{ ___('Protected') }}</span>
                                        @endif
                                    </td>
                                    <td class="align-middle">
                                        <span class="badge bg-warning text-dark">{{ $file->directory }}</span>
                                    </td>
                                    <td class="align-middle text-end fw-bold">{{ \Plugins\mediamanager\app\Services\ImageScanner::formatBytes($file->size) }}</td>
                                    <td class="align-middle">
                                        <div class="small">{{ $file->modified_at ? $file->modified_at->format('M d, Y H:i') : '—' }}</div>
                                    </td>
                                    <td class="align-middle text-center">
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" class="btn btn-outline-primary view-details-btn" data-path="{{ $file->full_path }}" title="{{ ___('View Details') }}">
                                                <i class="ico ico-info" aria-hidden="true"><x-ui.icon name="info" /></i>
                                            </button>
                                            <form action="{{ route('admin.mediamanager.toggleProtect') }}" method="POST" class="d-inline">
                                                @csrf
                                                <input type="hidden" name="id" value="{{ $file->id }}">
                                                <button type="submit" class="btn btn-outline-{{ $file->is_protected ? 'warning' : 'secondary' }}" title="{{ $file->is_protected ? ___('Remove Protection') : ___('Protect from Deletion') }}">
                                                    <i class="ico ico-{{ $file->is_protected ? 'lock' : 'lock-open' }}" aria-hidden="true"><x-ui.icon :name="$file->is_protected ? 'lock' : 'lock-open'" /></i>
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

            {{ $files->links() }}
        </div>

        <div class="col-lg-3">
            <div class="card shadow sticky-top" style="top: 1rem;">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold"><i class="ico ico-info me-2" aria-hidden="true"><x-ui.icon name="info" /></i>{{ ___('Image Details') }}</h6>
                </div>
                <div class="card-body" id="detail-panel">
                    <p class="text-muted text-center">{{ ___('Click the info button on any image to view details here.') }}</p>
                </div>
            </div>
        </div>
    </div>
    @else
    <div class="card shadow">
        <div class="card-body text-center py-5">
            <i class="ico ico-circle-check ico-3x text-success mb-3" aria-hidden="true"><x-ui.icon name="circle-check" /></i>
            <h4>{{ ___('No unknown files found!') }}</h4>
            <p class="text-muted">{{ ___('All files are properly categorized as used, orphaned, or protected.') }}</p>
            <a href="{{ route('admin.mediamanager.index') }}" class="btn btn-primary mt-2">{{ ___('Back to Dashboard') }}</a>
        </div>
    </div>
    @endif
</div>

<!-- Confirmation Modal -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="ico ico-alert me-2" aria-hidden="true"><x-ui.icon name="alert" /></i>{{ ___('Confirm Deletion') }}</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p id="delete-confirm-text">{{ ___('Are you sure you want to delete the selected files?') }}</p>
                <div class="alert alert-warning">
                    <strong id="delete-confirm-count">0</strong> {{ ___('files') }} — <strong id="delete-confirm-size">0 B</strong>
                </div>
                <div id="delete-permanent-warning" class="alert alert-danger d-none">
                    <i class="ico ico-alert me-2" aria-hidden="true"><x-ui.icon name="alert" /></i>
                    {{ ___('This action is PERMANENT and cannot be undone.') }}
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ ___('Cancel') }}</button>
                <form id="delete-confirm-form" method="POST" action="{{ route('admin.mediamanager.deleteOrphans') }}">
                    @csrf
                    <input type="hidden" name="mode" id="delete-mode" value="trash">
                    <button type="submit" class="btn btn-danger" id="delete-confirm-btn">
                        <i class="ico ico-trash me-1" aria-hidden="true"><x-ui.icon name="trash" /></i> {{ ___('Delete') }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const rows = document.querySelectorAll('.media-row');
    const checkboxes = document.querySelectorAll('.file-checkbox');
    const selectAll = document.getElementById('select-all');
    const bulkCard = document.getElementById('bulk-actions-card');
    const countEl = document.getElementById('selected-count');
    const sizeEl = document.getElementById('selected-size');
    const detailPanel = document.getElementById('detail-panel');

    const fileSizes = {};
    rows.forEach(r => { fileSizes[r.dataset.id] = parseInt(r.dataset.size) || 0; });

    function formatBytes(bytes) {
        if (bytes === 0) return '0 B';
        const k = 1024, sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    }

    function updateBulk() {
        const checked = document.querySelectorAll('.file-checkbox:checked');
        const count = checked.length;
        let totalSize = 0;
        checked.forEach(cb => { totalSize += fileSizes[cb.value] || 0; });

        countEl.textContent = count;
        sizeEl.textContent = formatBytes(totalSize);
        bulkCard.style.display = count > 0 ? 'block' : 'none';

        if (selectAll) {
            selectAll.checked = count === checkboxes.length && count > 0;
        }
    }

    checkboxes.forEach(cb => cb.addEventListener('change', updateBulk));

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            checkboxes.forEach(cb => { cb.checked = this.checked; });
            updateBulk();
        });
    }

    document.getElementById('deselect-all-btn')?.addEventListener('click', function() {
        checkboxes.forEach(cb => { cb.checked = false; });
        if (selectAll) selectAll.checked = false;
        updateBulk();
    });

    function setupDeleteBtn(btn) {
        btn?.addEventListener('click', function() {
            const mode = this.dataset.mode;
            const checked = document.querySelectorAll('.file-checkbox:checked');
            if (checked.length === 0) return;

            let totalSize = 0;
            const ids = [];
            checked.forEach(cb => {
                ids.push(cb.value);
                totalSize += fileSizes[cb.value] || 0;
            });

            document.getElementById('delete-confirm-count').textContent = ids.length;
            document.getElementById('delete-confirm-size').textContent = formatBytes(totalSize);
            document.getElementById('delete-mode').value = mode;

            const warning = document.getElementById('delete-permanent-warning');
            const confirmBtn = document.getElementById('delete-confirm-btn');
            if (mode === 'permanent') {
                warning.classList.remove('d-none');
                confirmBtn.className = 'btn btn-dark';
            } else {
                warning.classList.add('d-none');
                confirmBtn.className = 'btn btn-danger';
            }

            const form = document.getElementById('delete-confirm-form');
            form.querySelectorAll('input[name="file_ids[]"]').forEach(el => el.remove());
            ids.forEach(id => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'file_ids[]';
                input.value = id;
                form.appendChild(input);
            });

            new bootstrap.Modal(document.getElementById('deleteConfirmModal')).show();
        });
    }

    setupDeleteBtn(document.getElementById('bulk-delete-btn'));
    setupDeleteBtn(document.getElementById('bulk-delete-permanent-btn'));

    // Bulk protect
    document.getElementById('bulk-protect-btn')?.addEventListener('click', function() {
        const checked = document.querySelectorAll('.file-checkbox:checked');
        if (checked.length === 0) return;
        const ids = Array.from(checked).map(cb => cb.value);
        if (!confirm('{{ ___("Protect " ) }}' + ids.length + '{{ ___(" selected files from deletion?") }}')) return;

        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '{{ route("admin.mediamanager.toggleProtect") }}';
        form.innerHTML = '@csrf';
        ids.forEach(id => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'id';
            input.value = id;
            form.appendChild(input);
        });
        document.body.appendChild(form);
        form.submit();
    });

    // View details
    document.querySelectorAll('.view-details-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const path = this.dataset.path;
            detailPanel.innerHTML = '<div class="text-center py-3"><i class="ico ico-loader-2 ico-spin ico-2x" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 3a9 9 0 1 0 9 9"/></svg></i><p class="mt-2 text-muted">Loading...</p></div>';

            fetch('{{ route("admin.mediamanager.imageInfo") }}?path=' + encodeURIComponent(path))
                .then(r => r.json())
                .then(data => {
                    if (data.error) {
                        detailPanel.innerHTML = '<div class="alert alert-danger">' + data.error + '</div>';
                        return;
                    }
                    const file = data.file;
                    let html = '';

                    html += '<div class="text-center mb-3">';
                    html += '<img src="' + (file?.url || '') + '" class="img-fluid rounded shadow-sm" style="max-height:200px;" data-ui-do="hide-on-error">';
                    html += '</div>';

                    html += '<h6 class="fw-bold mb-2"><i class="ico ico-file me-2" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2"/></svg></i>File Info</h6>';
                    html += '<table class="table table-sm">';
                    html += '<tr><td class="text-muted">{{ ___("Filename") }}</td><td class="fw-bold">' + (file?.filename || '') + '</td></tr>';
                    if (data.dimensions) {
                        html += '<tr><td class="text-muted">{{ ___("Dimensions") }}</td><td>' + data.dimensions.width + ' × ' + data.dimensions.height + '</td></tr>';
                    }
                    if (data.mime) {
                        html += '<tr><td class="text-muted">{{ ___("Type") }}</td><td>' + data.mime + '</td></tr>';
                    }
                    html += '<tr><td class="text-muted">{{ ___("Size") }}</td><td>' + formatBytes(file?.size || 0) + '</td></tr>';
                    html += '<tr><td class="text-muted">{{ ___("Directory") }}</td><td><code>' + (file?.directory || '') + '</code></td></tr>';
                    html += '<tr><td class="text-muted">{{ ___("Modified") }}</td><td>' + (file?.modified_at || '—') + '</td></tr>';
                    html += '</table>';

                    html += '<h6 class="fw-bold mb-2"><i class="ico ico-tag me-2" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6.5 7.5a1 1 0 1 0 2 0a1 1 0 1 0 -2 0"/><path d="M3 6v5.172a2 2 0 0 0 .586 1.414l7.71 7.71a2.41 2.41 0 0 0 3.408 0l5.592 -5.592a2.41 2.41 0 0 0 0 -3.408l-7.71 -7.71a2 2 0 0 0 -1.414 -.586h-5.172a3 3 0 0 0 -3 3"/></svg></i>Status</h6>';
                    html += '<span class="badge bg-warning text-dark me-1">UNKNOWN</span>';
                    if (file?.is_protected) html += '<span class="badge bg-info"><i class="ico ico-lock me-1" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 13a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v6a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-6"/><path d="M11 16a1 1 0 1 0 2 0a1 1 0 0 0 -2 0"/><path d="M8 11v-4a4 4 0 1 1 8 0v4"/></svg></i>PROTECTED</span>';

                    if (file?.is_protected) {
                        html += '<hr class="my-3">';
                        html += '<div class="alert alert-info">{{ ___("This file is protected and will not be deleted automatically.") }}</div>';
                    } else {
                        html += '<hr class="my-3">';
                        html += '<form action="{{ route("admin.mediamanager.toggleProtect") }}" method="POST">';
                        html += '@csrf';
                        html += '<input type="hidden" name="id" value="' + (file?.id || '') + '">';
                        html += '<button type="submit" class="btn btn-sm btn-warning w-100">';
                        html += '<i class="ico ico-lock me-1" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 13a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v6a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-6"/><path d="M11 16a1 1 0 1 0 2 0a1 1 0 0 0 -2 0"/><path d="M8 11v-4a4 4 0 1 1 8 0v4"/></svg></i>{{ ___("Protect from Deletion") }}';
                        html += '</button></form>';
                    }

                    detailPanel.innerHTML = html;
                })
                .catch(err => {
                    detailPanel.innerHTML = '<div class="alert alert-danger">{{ ___("Failed to load details.") }}</div>';
                });
        });
    });
});
</script>
@endsection
