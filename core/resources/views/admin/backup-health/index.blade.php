{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Backup Health'))
@section('content')
{{-- session('success') is shown as a toast by the admin layout. --}}
<div class="ui-stack">
    {{-- KPI chips: age, validity, off-box copy, restore drill --}}
    <section class="ui-kpis" aria-label="{{ ___('Backup Health') }}">
        <x-ui.stat :value="$ageHours === null ? ___('none') : $ageHours.'h'" :label="___('Last dump age')" icon="clock"
                   :tone="($ageHours === null || $ageHours > $maxAgeHours) ? 'danger' : null" />
        {{-- J45 (P6-204): a failed run shows its reason; a missing production key shows the plain fix. --}}
        @php
            $__validLabel = ___('Last dump valid');
            if ($lastBackup && ! $lastBackup->ok && $lastBackup->error) {
                $__validLabel = $lastBackup->error === \Juwri\Core\Services\Ops\BackupCrypto::MISSING_KEY_IN_PRODUCTION
                    ? ___('Backups are stopped: BACKUP_ENCRYPTION_KEY is not set. Run php artisan backup:generate-key, put the key in .env, then run the backup again.')
                    : $__validLabel.' · '.\Illuminate\Support\Str::limit($lastBackup->error, 60);
            }
        @endphp
        <x-ui.stat :value="$lastBackup ? ($lastBackup->ok ? ___('Valid') : ___('REJECTED')) : ___('none')" :label="$__validLabel" icon="shield"
                   :tone="($lastBackup && $lastBackup->ok) ? null : 'danger'" />
        {{-- M7: real off-box state — mirrored / failed (with reason) / no target. --}}
        @php
            if ($lastBackup && $lastBackup->mirrored) {
                $__offboxValue = ___('Mirrored');
                $__offboxLabel = $offboxTarget;
            } elseif ($offboxTarget) {
                $__offboxValue = ___('FAILED');
                $__offboxLabel = $lastBackup ? \Illuminate\Support\Str::limit($lastBackup->mirror_error, 40) : ___('Off-box copy');
            } else {
                $__offboxValue = ___('no target');
                $__offboxLabel = ___('Off-box copy');
            }
        @endphp
        <x-ui.stat :value="$__offboxValue" :label="(string) $__offboxLabel" icon="upload"
                   :tone="! $offboxTarget ? 'warning' : (($lastBackup && ! $lastBackup->mirrored) ? 'danger' : null)" />
        @php
            if (! $drillEnabled) {
                $__drillValue = ___('off');
            } elseif (! $lastDrill) {
                $__drillValue = ___('never');
            } else {
                $__drillValue = $lastDrill->ok ? ___('passed') : ___('FAILED');
            }
        @endphp
        {{-- P6-97: result AND time of the last drill (shown even when the drill is now off). --}}
        @php
            $__drillLabel = ___('Last restore drill');
            if ($lastDrill && $lastDrill->created_at) {
                $__drillLabel .= ' · '.$lastDrill->created_at->format('Y-m-d H:i').' ('.$lastDrill->created_at->diffForHumans().')';
            }
            if ($lastDrill && ! $lastDrill->ok && $lastDrill->error) {
                $__drillLabel .= ' · '.\Illuminate\Support\Str::limit($lastDrill->error, 60);
            }
        @endphp
        <x-ui.stat :value="$__drillValue" :label="$__drillLabel" icon="refresh"
                   :tone="($drillEnabled && $lastDrill && ! $lastDrill->ok) ? 'danger' : null" />
    </section>

    {{-- P6-166: say it in words, not only by the chip colour. Bucket name only, never the keys. --}}
    @if (! $offboxTarget)
        <x-ui.banner tone="warning">{{ ___('No off-box backup target: every DB dump stays on this server. Set AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY and AWS_BUCKET in .env.') }}</x-ui.banner>
    @endif

    <x-ui.card variant="flush" :title="___('Backup Health')">
        <x-slot:actions>
            <form method="POST" action="{{ route('admin.backup-health.restore-check') }}">
                @csrf
                <x-ui.button type="submit" variant="secondary" size="sm" icon="restore">{{ ___('Run restore drill now') }}</x-ui.button>
            </form>
        </x-slot:actions>
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>{{ ___('Kind') }}</th>
                        <th>{{ ___('Result') }}</th>
                        <th>{{ ___('Detail') }}</th>
                        <th>{{ ___('When') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($runs as $run)
                        <tr>
                            <td data-primary data-label="{{ ___('Kind') }}"><x-ui.tag>{{ $run->kind }}</x-ui.tag></td>
                            <td data-label="{{ ___('Result') }}">
                                @if ($run->ok)
                                    <x-ui.tag tone="success">{{ ___('ok') }}</x-ui.tag>
                                @else
                                    <x-ui.tag tone="danger">{{ ___('failed') }}</x-ui.tag>
                                @endif
                            </td>
                            <td data-label="{{ ___('Detail') }}"><span class="ui-small ui-break">{{ $run->error ?: ($run->path ? basename($run->path) : '—') }}</span></td>
                            <td data-label="{{ ___('When') }}"><span class="ui-small">{{ optional($run->created_at)->diffForHumans() }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="ui-center ui-text-2">{{ ___('No backup runs recorded yet.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    {{-- M7: media coverage — the DB dump covers rows, this mirrors the images those rows point at. --}}
    <x-ui.card :title="___('Media coverage')">
        <x-slot:actions>
            <form method="POST" action="{{ route('admin.backup-health.media-mirror') }}">
                @csrf
                <x-ui.button type="submit" variant="secondary" size="sm" icon="upload">{{ ___('Mirror media now') }}</x-ui.button>
            </form>
        </x-slot:actions>
        <div class="ui-stack">
            @if (! $mediaEnabled)
                <x-ui.banner tone="warning">{{ ___('Media is NOT included in any platform backup. Turn on "Mirror media off-box nightly" in Settings → General.') }}</x-ui.banner>
            @endif
            <p class="ui-small">{{ ___('Media root') }}: <code class="ui-mono ui-break">{{ $mediaRoot }}</code></p>
            @if ($lastMedia)
                <div class="ui-cluster">
                    <x-ui.tag :tone="$lastMedia->ok ? 'success' : 'danger'">{{ $lastMedia->ok ? ___('Mirrored') : ___('Failed') }}</x-ui.tag>
                    <span>{{ number_format((int) $lastMedia->file_count) }} {{ ___('files') }}</span>
                    <span class="ui-text-2">{{ $lastMedia->detail }}</span>
                    <span class="ui-text-2">{{ optional($lastMedia->created_at)->diffForHumans() }}</span>
                </div>
                @if ($lastMedia->error)
                    <p class="ui-small ui-text-danger ui-break">{{ $lastMedia->error }}</p>
                @endif
            @else
                <p class="ui-small">{{ ___('The media mirror has never run.') }}</p>
            @endif
        </div>
    </x-ui.card>

    <x-ui.card variant="flush" :title="___('Dump files on this box')">
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>{{ ___('File') }}</th>
                        <th>{{ ___('Size') }}</th>
                        <th>{{ ___('Modified') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($files as $file)
                        <tr>
                            <td data-primary data-label="{{ ___('File') }}"><code class="ui-mono ui-break">{{ $file['name'] }}</code></td>
                            <td data-label="{{ ___('Size') }}">{{ number_format($file['bytes'] / 1048576, 1) }} MB</td>
                            <td data-label="{{ ___('Modified') }}"><span class="ui-small">{{ $file['mtime'] ? \Carbon\Carbon::createFromTimestamp($file['mtime'])->diffForHumans() : '—' }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="ui-center ui-text-2">{{ ___('No dump files found. On a :driver box this is expected.', ['driver' => $driver]) }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>
</div>
@endsection
