{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Theme Reviews'))
@section('content')
<div class="ui-stack">

    {{-- P4-4: pending review queue --}}
    <x-ui.card variant="flush">
        <div class="ui-card__head">
            <div>
                <h2 class="ui-card__title">{{ ___('Pending review') }} <x-ui.tag tone="warning">{{ $pending->count() }}</x-ui.tag></h2>
                <p class="ui-text-2 ui-small">{{ ___('Submissions that passed the automated gates and wait for a human decision. Published versions become immutable.') }}</p>
            </div>
        </div>
        @if($pending->isEmpty())
            <x-ui.empty icon="check" :title="___('Nothing waiting. 🎉')" />
        @else
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead><tr>
                        <th>{{ ___('Theme') }}</th><th>{{ ___('Version') }}</th><th>{{ ___('Lint') }}</th><th>{{ ___('Quality gate') }}</th><th>{{ ___('Submitted') }}</th><th class="ui-end">{{ ___('Actions') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach($pending as $v)
                        <tr>
                            <td data-primary data-label="{{ ___('Theme') }}"><span class="ui-strong">{{ $v->theme->name }}</span> <code class="ui-mono ui-small">{{ $v->theme->slug }}</code></td>
                            <td data-label="{{ ___('Version') }}"><code class="ui-mono">{{ $v->semver }}</code></td>
                            <td data-label="{{ ___('Lint') }}">
                                @if(($v->lint_report['passed'] ?? false))
                                    <span class="ui-cell-tags">
                                        <x-ui.tag tone="success">{{ ___('clean') }}</x-ui.tag>
                                        @if(!empty($v->lint_report['warnings']))
                                            <x-ui.tag>{{ count($v->lint_report['warnings']) }} {{ ___('warnings') }}</x-ui.tag>
                                        @endif
                                    </span>
                                    @if(!empty($v->lint_report['warnings']))
                                        <details class="ui-small">
                                            <summary class="ui-text-2">{{ ___('Warnings') }}</summary>
                                            <ul class="ui-plain-list">
                                                @foreach($v->lint_report['warnings'] as $warning)
                                                    <li><code class="ui-mono">{{ $warning['rule'] ?? '' }}</code> <span class="ui-text-2">{{ $warning['file'] ?? '' }}</span> — {{ $warning['message'] ?? '' }}</li>
                                                @endforeach
                                            </ul>
                                        </details>
                                    @endif
                                @else
                                    <x-ui.tag tone="danger">{{ count($v->lint_report['errors'] ?? []) }} {{ ___('errors') }}</x-ui.tag>
                                @endif
                            </td>
                            <td data-label="{{ ___('Quality gate') }}">
                                @if($v->gate_report === null)
                                    <x-ui.tag>{{ ___('pending') }}</x-ui.tag>
                                @elseif(!empty($v->gate_report['skipped']))
                                    {{-- B0.9: no Lighthouse on this host / no preview — never blocks review --}}
                                    <x-ui.tag title="{{ $v->gate_report['reason'] ?? '' }}">{{ ___('skipped') }}</x-ui.tag>
                                @elseif($v->gate_report['passed'] ?? false)
                                    <x-ui.tag tone="success">P{{ $v->gate_report['performance'] }} / A{{ $v->gate_report['accessibility'] }}</x-ui.tag>
                                @else
                                    <x-ui.tag tone="danger">{{ ___('failed') }}</x-ui.tag>
                                @endif
                                @if($v->gate_report !== null)
                                    <details class="ui-small">
                                        <summary class="ui-text-2">{{ ___('Gate report') }}</summary>
                                        @if(!empty($v->gate_report['skipped']))
                                            <p class="ui-text-2">{{ $v->gate_report['reason'] ?? '' }}</p>
                                        @else
                                            @foreach(($v->gate_report['reasons'] ?? []) as $reason)
                                                <p class="ui-text-danger">{{ $reason }}</p>
                                            @endforeach
                                            <ul class="ui-plain-list">
                                                @foreach(($v->gate_report['runs'] ?? []) as $run)
                                                    <li><code class="ui-mono">{{ $run['form_factor'] ?? '' }}</code> P{{ $run['performance'] ?? '–' }} / A{{ $run['accessibility'] ?? '–' }} <span class="ui-text-2 ui-break">{{ $run['url'] ?? '' }}</span></li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </details>
                                @endif
                            </td>
                            <td data-label="{{ ___('Submitted') }}" class="ui-text-2">{{ $v->created_at->diffForHumans() }}</td>
                            <td class="ui-end" data-label="">
                                <div class="ui-cell-actions">
                                    <x-ui.button variant="ghost" size="sm" icon="eye" target="_blank" rel="noopener"
                                                 :href="route('marketplace.preview', $v->theme->slug).'?version='.$v->semver">{{ ___('Preview') }}</x-ui.button>
                                    {{-- Theme Studio follow-ups: queue the Lighthouse gate again (signed preview URLs) --}}
                                    <form action="{{ route('admin.theme-reviews.gate', $v->id) }}" method="post">@csrf
                                        <x-ui.button type="submit" variant="secondary" size="sm" icon="refresh">{{ ___('Re-run gate') }}</x-ui.button>
                                    </form>
                                    <form action="{{ route('admin.theme-reviews.approve', $v->id) }}" method="post">@csrf
                                        <x-ui.button type="submit" variant="success" size="sm">{{ ___('Approve & publish') }}</x-ui.button>
                                    </form>
                                    <x-ui.button variant="secondary" size="sm" class="ui-text-danger" data-ui-open="reject-theme-{{ $v->id }}">{{ ___('Reject') }}</x-ui.button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>

    {{-- stores P1-4: theme store listings (metadata + screenshots) --}}
    <x-ui.card variant="flush">
        <div class="ui-card__head">
            <div>
                <h2 class="ui-card__title">{{ ___('Store listings') }}</h2>
                <p class="ui-text-2 ui-small">{{ ___('Each theme\'s Theme Store metadata — category, tagline and screenshot gallery.') }} {{ ___('Drag to set the store order. Listings under “Then, by rating” stay in rating order until you drag them up. The number on a listing still sets its place.') }}</p>
            </div>
        </div>
        @if($themes->isEmpty())
            <x-ui.empty icon="sparkle" :title="___('No themes yet.')" />
        @else
            <style nonce="{{ csp_nonce() }}">
                .listing-pad td { height: .5rem; padding: 0; border: 0; }
                .listing-row.is-dragging { opacity: .45; }
                .listing-sort.is-drop-target { outline: 2px dashed var(--ui-accent, #0d6efd); outline-offset: -2px; }
                .quick-reorder-icon { cursor: grab; display: inline-flex; align-items: center; }
            </style>
            <div class="ui-table-wrap">
                <table class="ui-table" id="listing-catalogue" data-action="{{ route('admin.theme-reviews.reorder') }}">
                    <thead><tr>
                        <th style="width:2rem"></th>
                        <th>{{ ___('Theme') }}</th>
                        <th>{{ ___('Position') }}</th>
                        <th>{{ ___('Category') }}</th>
                        <th>{{ ___('Tagline') }}</th>
                        <th>{{ ___('Screenshots') }}</th>
                        <th class="ui-end">{{ ___('Actions') }}</th>
                    </tr></thead>
                    <tbody id="listing-order" class="listing-sort">
                    @foreach($orderedListings as $t)
                        @include('admin.theme_reviews._listing_row', ['t' => $t])
                    @endforeach
                        <tr class="listing-pad"><td colspan="7"></td></tr>
                    </tbody>
                    <tbody>
                        <tr>
                            <td colspan="7" class="ui-text-2 ui-small">{{ ___('Then, by rating') }}</td>
                        </tr>
                    </tbody>
                    <tbody id="listing-tail" class="listing-sort">
                    @foreach($ratingListings as $t)
                        @include('admin.theme_reviews._listing_row', ['t' => $t])
                    @endforeach
                        <tr class="listing-pad"><td colspan="7"></td></tr>
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>

    {{-- Recent decisions --}}
    <x-ui.card variant="flush" :title="___('Recent decisions')">
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead><tr><th>{{ ___('Theme') }}</th><th>{{ ___('Version') }}</th><th>{{ ___('Status') }}</th><th>{{ ___('Notes') }}</th><th>{{ ___('Decided') }}</th></tr></thead>
                <tbody>
                @forelse($recent as $v)
                    <tr>
                        <td data-primary data-label="{{ ___('Theme') }}">{{ $v->theme->name }}</td>
                        <td data-label="{{ ___('Version') }}"><code class="ui-mono">{{ $v->semver }}</code></td>
                        <td data-label="{{ ___('Status') }}"><x-ui.tag :tone="['published' => 'success', 'rejected' => 'danger'][$v->status] ?? 'neutral'">{{ $v->status }}</x-ui.tag></td>
                        <td data-label="{{ ___('Notes') }}" class="ui-text-2">{{ Str::limit($v->review_notes, 80) }}</td>
                        <td data-label="{{ ___('Decided') }}" class="ui-text-2">{{ $v->updated_at->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="ui-center ui-text-2">{{ ___('No decisions yet.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    {{-- P6-243: theme purchases — refund credits the buyer and reverses (or flags) the seller's share --}}
    <x-ui.card variant="flush" :title="___('Theme purchases')">
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead><tr><th>{{ ___('Theme') }}</th><th>{{ ___('Buyer') }}</th><th>{{ ___('Price') }}</th><th>{{ ___('Bought') }}</th><th></th></tr></thead>
                <tbody>
                @forelse($purchases ?? [] as $p)
                    <tr>
                        <td data-primary data-label="{{ ___('Theme') }}">{{ $p->theme?->name }}</td>
                        <td data-label="{{ ___('Buyer') }}">{{ $p->user?->email }}</td>
                        <td data-label="{{ ___('Price') }}">{{ $p->price_paid }} {{ $p->currency_code }}</td>
                        <td data-label="{{ ___('Bought') }}" class="ui-text-2">{{ $p->created_at?->diffForHumans() }}</td>
                        <td>
                            @if ($p->refunded_at)
                                <x-ui.tag tone="neutral">{{ ___('Refunded') }}</x-ui.tag>
                            @else
                                <form method="POST" action="{{ route('admin.theme-purchases.refund', $p->id) }}" data-ui-confirm="{{ ___('Refund this theme purchase?') }}" data-ui-danger class="ui-cluster">
                                    @csrf
                                    <input type="text" name="reason" required maxlength="500" class="ui-input ui-input--sm" placeholder="{{ ___('Refund reason') }}" aria-label="{{ ___('Refund reason') }}">
                                    <x-ui.button type="submit" variant="secondary" size="sm">{{ ___('Refund purchase') }}</x-ui.button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="ui-center ui-text-2">{{ ___('No theme purchases yet.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

</div>

{{-- Rejection notes are required and shown to the theme author. --}}
@foreach($pending as $v)
    <x-ui.sheet :id="'reject-theme-'.($v->id)" :title="___('Reject')" :description="$v->theme->name.' '.$v->semver">
        <form id="reject-theme-form-{{ $v->id }}" action="{{ route('admin.theme-reviews.reject', $v->id) }}" method="post">@csrf
            <x-ui.field type="textarea" name="notes" :id="'reject-theme-notes-'.($v->id)" :label="___('Rejection notes for the author:')" rows="4" required />
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" data-ui-close>{{ ___('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" variant="danger" form="reject-theme-form-{{ $v->id }}">{{ ___('Reject') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.sheet>
@endforeach
@endsection

@push('scripts_at_bottom')
    <script nonce="{{ csp_nonce() }}">
        (function () {
            var table = document.getElementById('listing-catalogue');
            var orderBody = document.getElementById('listing-order');
            var tailBody = document.getElementById('listing-tail');
            if (!table || !orderBody || !tailBody) return;

            var url = table.getAttribute('data-action');
            var csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            var dragging = null;
            var saving = false;
            var again = false;

            function ids(tbody) {
                return Array.prototype.map.call(
                    tbody.querySelectorAll(':scope > tr.listing-row'),
                    function (row) { return Number(row.getAttribute('data-id')); }
                );
            }

            function paint() {
                Array.prototype.forEach.call(orderBody.querySelectorAll(':scope > tr.listing-row'), function (row, i) {
                    var cell = row.querySelector('[data-pos]');
                    if (cell) cell.textContent = String(i + 1);
                });
                Array.prototype.forEach.call(tailBody.querySelectorAll(':scope > tr.listing-row'), function (row) {
                    var cell = row.querySelector('[data-pos]');
                    if (cell) cell.textContent = '—';
                });
            }

            function save() {
                if (saving) { again = true; return; }
                saving = true;
                fetch(url, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrf,
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ ordered: ids(orderBody), tail: ids(tailBody) })
                }).then(function (res) {
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    return res.json();
                }).then(function (data) {
                    paint();
                    if (data && data.success && window.ownerUi && window.ownerUi.toast) {
                        window.ownerUi.toast(data.message, { tone: 'success' });
                    }
                }).catch(function () {
                    window.location.reload();
                }).finally(function () {
                    saving = false;
                    if (again) { again = false; save(); }
                });
            }

            [orderBody, tailBody].forEach(function (tbody) {
                tbody.addEventListener('dragstart', function (e) {
                    var row = e.target.closest('tr.listing-row');
                    if (!row) return;
                    dragging = row;
                    row.classList.add('is-dragging');
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', row.getAttribute('data-id') || '');
                });
                tbody.addEventListener('dragend', function () {
                    if (dragging) dragging.classList.remove('is-dragging');
                    dragging = null;
                    orderBody.classList.remove('is-drop-target');
                    tailBody.classList.remove('is-drop-target');
                });
                tbody.addEventListener('dragover', function (e) {
                    if (!dragging) return;
                    e.preventDefault();
                    tbody.classList.add('is-drop-target');
                    var target = e.target.closest('tr.listing-row');
                    var pad = tbody.querySelector(':scope > tr.listing-pad');
                    if (target && target !== dragging && target.parentNode === tbody) {
                        var rect = target.getBoundingClientRect();
                        var after = (e.clientY - rect.top) > (rect.height / 2);
                        tbody.insertBefore(dragging, after ? target.nextSibling : target);
                    } else if (pad) {
                        tbody.insertBefore(dragging, pad);
                    }
                });
                tbody.addEventListener('dragleave', function (e) {
                    if (!tbody.contains(e.relatedTarget)) {
                        tbody.classList.remove('is-drop-target');
                    }
                });
                tbody.addEventListener('drop', function (e) {
                    if (!dragging) return;
                    e.preventDefault();
                    tbody.classList.remove('is-drop-target');
                    save();
                });
            });
        })();
    </script>
@endpush
