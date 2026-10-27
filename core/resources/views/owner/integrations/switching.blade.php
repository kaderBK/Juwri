{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Switch to juwri'))
@section('page_title', ___('Switch to juwri'))
@section('page_sub', ___('Bring your menu, customers and loyalty points from a file or from Odoo. You always see what will happen before anything is imported.'))

@section('content')
    @php
        $__labels = ['menu' => ___('Menu'), 'customers' => ___('Customers'), 'loyalty' => ___('Loyalty points')];
        $__fields = ['name' => ___('Name'), 'price' => ___('Price'), 'sku' => ___('SKU'), 'phone' => ___('Phone'), 'email' => ___('E-mail'), 'points' => ___('Points')];
        $__actions = ['create' => ___('New'), 'update' => ___('Update'), 'credit' => ___('Add points'), 'skip' => ___('No change')];
    @endphp

    @php
        $__step = $import === null ? 1 : ($import->status === 'committed' ? 3 : 2);
        $__steps = [1 => ___('1. Choose a file'), 2 => ___('2. Check'), 3 => ___('3. Imported')];
    @endphp
    <ol class="ui-steps" data-switch-steps aria-label="{{ ___('Steps') }}" style="display:flex;gap:16px;list-style:none;padding:0;margin:0 0 16px;flex-wrap:wrap">
        @foreach ($__steps as $n => $label)
            <li data-step="{{ $n }}" @if ($n === $__step) aria-current="step" @endif style="{{ $n === $__step ? 'font-weight:700' : 'opacity:.6' }}">{{ $label }}</li>
        @endforeach
    </ol>

    @if ($import === null)
        <x-ui.card :title="___('Import')" data-switch-form>
            <form method="POST" action="{{ route('integrations.switch.preview') }}" enctype="multipart/form-data" class="ui-api__form">
                @csrf
                <div class="ui-api__fields">
                    <div>
                        <label class="ui-label" for="switch-dataset">{{ ___('What to import') }}</label>
                        <select id="switch-dataset" name="dataset" class="ui-input">
                            @foreach ($datasets as $d)
                                <option value="{{ $d }}" @selected(old('dataset') === $d)>{{ $__labels[$d] }}</option>
                            @endforeach
                        </select>
                        <p class="ui-muted" data-switch-templates>
                            {{ ___('Templates:') }}
                            @foreach (['juwri' => 'juwri', 'foodics' => ___('Foodics-style'), 'arabic' => ___('Arabic headers')] as $style => $styleLabel)
                                <span>{{ $styleLabel }}:
                                @foreach ($datasets as $d)
                                    <a href="{{ route('integrations.switch.template', ['dataset' => $d] + ($style === 'juwri' ? [] : ['style' => $style])) }}" data-switch-template="{{ $d }}" data-style="{{ $style }}">{{ $__labels[$d] }}</a>@if (! $loop->last) · @endif
                                @endforeach
                                </span>@if (! $loop->last) <br> @endif
                            @endforeach
                        </p>
                    </div>
                    <div>
                        <label class="ui-label" for="switch-mode">{{ ___('Items that already exist (menu)') }}</label>
                        <select id="switch-mode" name="mode" class="ui-input">
                            <option value="add">{{ ___('Keep them as they are') }}</option>
                            <option value="update" @selected(old('mode') === 'update')>{{ ___('Update price, description and availability') }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="ui-label"><input type="radio" name="source" value="file" checked> {{ ___('A file (.csv or .xlsx — juwri template, Foodics or another export)') }}</label>
                        <input type="file" name="file" id="switch-file" accept=".csv,.xlsx,text/csv" class="ui-input" aria-describedby="switch-file-help">
                        <p class="ui-muted" id="switch-file-help">{{ ___('Up to 5 MB. The first row must be the column names. Foodics-style and Arabic column names are understood.') }}</p>
                    </div>
                    <div>
                        <label class="ui-label"><input type="radio" name="source" value="odoo" @checked(old('source') === 'odoo')> {{ ___('My previous Odoo (the key is used once and not kept)') }}</label>
                        <input type="url" name="url" class="ui-input ui-ltr" placeholder="https://mycompany.odoo.com" value="{{ old('url') }}">
                        <input type="text" name="database" class="ui-input ui-ltr" placeholder="{{ ___('Database') }}" value="{{ old('database') }}">
                        <input type="text" name="username" class="ui-input ui-ltr" placeholder="{{ ___('User (login e-mail)') }}" value="{{ old('username') }}">
                        <input type="password" name="api_key" class="ui-input ui-ltr" placeholder="{{ ___('API key') }}" autocomplete="off">
                    </div>
                </div>
                @if ($errors->any())
                    <x-ui.banner tone="danger" data-switch-error role="alert">{{ $errors->first() }}</x-ui.banner>
                @endif
                <button type="submit" class="ui-btn">{{ ___('Check the file (nothing is imported yet)') }}</button>
            </form>
        </x-ui.card>

        @if ($imports->isNotEmpty())
            <x-ui.card :title="___('Earlier imports')">
                <table class="ui-table">
                    <tbody>
                        @foreach ($imports as $i)
                            <tr><td data-label="{{ ___('Data') }}"><a href="{{ route('integrations.switch.show', $i->id) }}">{{ $__labels[$i->dataset] ?? $i->dataset }}</a></td>
                                <td data-label="{{ ___('Source') }}">{{ $i->source }}</td><td data-label="{{ ___('Status') }}">{{ $i->status }}</td><td class="ui-muted" data-label="{{ ___('Date') }}">{{ $i->created_at?->diffForHumans() }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </x-ui.card>
        @endif
    @else
        @php
            $__r = $import->status === 'committed' ? ($import->summary ?? []) : ($import->plan ?? []);
            $__errs = $__r['errors'] ?? [];
            $__rows = $__r['rows'] ?? [];
            $__done = $import->status === 'committed';
        @endphp
        <x-ui.card :title="($__done ? ___('Imported') : ___('Check before importing')).' — '.($__labels[$import->dataset] ?? '')" data-switch-plan data-status="{{ $import->status }}">
            @if ($__done)
                <p data-switch-result role="status">{{ ___('Done. Nothing is imported twice: opening this page again changes nothing.') }}</p>
            @elseif ($import->status === 'previewed')
                <p class="ui-muted" data-switch-dryrun>{{ ___('This is a preview. Nothing has been imported yet.') }}</p>
            @endif
            <p data-switch-counts aria-live="polite">
                @foreach (['create', 'update', 'credit', 'skip'] as $a)
                    @if (($__r['counts'][$a] ?? 0) > 0)
                        <span data-count="{{ $a }}">{{ $__actions[$a] }}: {{ $__r['counts'][$a] }}</span> ·
                    @endif
                @endforeach
                @if (($__r['counts']['categories'] ?? 0) > 0)
                    <span data-count="categories">{{ ___('New categories') }}: {{ $__r['counts']['categories'] }}</span> ·
                @endif
                <span data-count="error">{{ ___('Errors') }}: {{ $__r['counts']['error'] ?? 0 }}</span>
            </p>
            @if (! empty($__errs))
                @if (! $__done)
                    <x-ui.banner tone="warning" data-switch-error-note>{{ ___('Rows with a problem are left out. Fix them in your file and check again, or import the rest.') }}</x-ui.banner>
                @endif
                <table class="ui-table" data-switch-errors>
                    <caption class="ui-muted">{{ ___('Rows with a problem') }}</caption>
                    <thead><tr><th scope="col">{{ ___('Row') }}</th><th scope="col">{{ ___('Column') }}</th><th scope="col">{{ ___('Problem') }}</th></tr></thead>
                    <tbody>@foreach (array_slice($__errs, 0, 200) as $e)<tr><td data-label="{{ ___('Row') }}">{{ $e['row'] }}</td><td data-field="{{ $e['field'] ?? '' }}" data-label="{{ ___('Column') }}">{{ isset($e['field']) ? ($__fields[$e['field']] ?? $e['field']) : '' }}</td><td data-label="{{ ___('Problem') }}">{{ $e['message'] }}</td></tr>@endforeach</tbody>
                </table>
                @if (count($__errs) > 200)
                    <p class="ui-muted" data-switch-more>{{ ___('Showing the first 200 problems.') }}</p>
                @endif
            @endif
            <table class="ui-table" data-switch-rows>
                <caption class="ui-muted">{{ ___('What will happen, row by row') }}</caption>
                <thead><tr><th scope="col">{{ ___('Row') }}</th><th scope="col">{{ ___('What happens') }}</th><th scope="col">{{ ___('Name') }}</th><th scope="col"></th></tr></thead>
                <tbody>@foreach (array_slice($__rows, 0, 300) as $row)<tr data-action="{{ $row['action'] }}"><td data-label="{{ ___('Row') }}">{{ $row['row'] }}</td><td data-label="{{ ___('What happens') }}">{{ $__actions[$row['action']] ?? $row['action'] }}</td><td data-label="{{ ___('Name') }}">{{ $row['label'] }}</td><td class="ui-muted" data-label="">{{ $row['detail'] }}</td></tr>@endforeach</tbody>
            </table>
            @if (count($__rows) > 300)
                <p class="ui-muted" data-switch-more-rows>{{ ___('Showing the first 300 rows. All rows are imported.') }}</p>
            @endif
            @if (in_array($import->status, ['queued', 'running', 'failed'], true))
                <div data-switch-progress data-status="{{ $import->status }}" data-progress-url="{{ route('integrations.switch.progress', $import->id) }}" data-active="{{ $import->status === 'failed' ? 0 : 1 }}">
                    <progress class="ui-progress" max="{{ max(1, (int) $import->rows_total) }}" value="{{ (int) $import->rows_done }}" data-switch-bar style="width:100%" aria-label="{{ ___('Importing… :done of :total rows', ['done' => (int) $import->rows_done, 'total' => (int) $import->rows_total]) }}"></progress>
                    <p data-switch-progress-label role="status" aria-live="polite" data-tpl="{{ ___('Importing… :done of :total rows', ['done' => ':done', 'total' => ':total']) }}" data-wait="{{ ___('Waiting to start…') }}" data-failed="{{ ___('The import stopped. Nothing is repeated; you can continue where it stopped.') }}">
                        @if ($import->status === 'failed')
                            {{ ___('The import stopped. Nothing is repeated; you can continue where it stopped.') }}
                        @elseif ($import->status === 'queued')
                            {{ ___('Waiting to start…') }}
                        @else
                            {{ ___('Importing… :done of :total rows', ['done' => (int) $import->rows_done, 'total' => (int) $import->rows_total]) }}
                        @endif
                    </p>
                    @if ($import->status === 'failed' && $import->error)
                        <x-ui.banner tone="danger" data-switch-error-note role="alert">{{ $import->error }}</x-ui.banner>
                    @endif
                </div>
                @if ($import->status === 'failed')
                    <form method="POST" action="{{ route('integrations.switch.commit', $import->id) }}" data-switch-resume>
                        @csrf
                        <button type="submit" class="ui-btn">{{ ___('Continue the import') }}</button>
                    </form>
                @endif
                <script nonce="{{ csp_nonce() }}">
                (function () {
                    var box = document.querySelector('[data-switch-progress]');
                    if (!box || box.dataset.active !== '1') { return; }
                    var bar = box.querySelector('[data-switch-bar]'), label = box.querySelector('[data-switch-progress-label]');
                    function tick() {
                        fetch(box.dataset.progressUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                            .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
                            .then(function (d) {
                                if (d.status === 'committed' || d.status === 'failed') { window.location.reload(); return; }
                                bar.max = Math.max(1, d.rows_total || 0); bar.value = d.rows_done || 0;
                                label.textContent = d.status === 'queued' ? label.dataset.wait
                                    : label.dataset.tpl.replace(':done', d.rows_done || 0).replace(':total', d.rows_total || 0);
                                setTimeout(tick, 2000);
                            })
                            .catch(function () { setTimeout(tick, 5000); });
                    }
                    setTimeout(tick, 1500);
                })();
                </script>
            @endif
            @if ($import->status === 'previewed')
                <p class="ui-muted" data-switch-wait>{{ ___('Importing can take a minute for a big file. Keep this page open.') }}</p>
                <form method="POST" action="{{ route('integrations.switch.commit', $import->id) }}" data-switch-commit>
                    @csrf
                    <button type="submit" class="ui-btn">{{ ___('Import now') }}</button>
                </form>
                <form method="POST" action="{{ route('integrations.switch.discard', $import->id) }}">
                    @csrf
                    <button type="submit" class="ui-btn ui-btn--ghost">{{ ___('Cancel') }}</button>
                </form>
            @elseif ($import->status === 'committed')
                <a class="ui-btn ui-btn--ghost" href="{{ route('integrations.switch.index') }}" data-switch-again>{{ ___('Import another file') }}</a>
            @endif
        </x-ui.card>
    @endif
@endsection
