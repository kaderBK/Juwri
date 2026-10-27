{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Odoo'))
@section('page_title', ___('Odoo'))
@section('page_sub', ___('Send paid orders, payments, refunds, taxes, customers and stock moves to your Odoo. One way: nothing comes back from Odoo.'))

@section('content')
    <x-plan-gate-notice feature="odoo_connector" :owner="$store->user" />

    @if ($error)
        <x-ui.banner tone="danger" data-odoo-error>{{ $error }}</x-ui.banner>
    @endif

    @if ($allowed)
    <x-ui.card :title="___('Connection')" data-odoo-connection>
        <form method="POST" action="{{ route('integrations.odoo.save') }}" class="ui-api__form" data-odoo-form>
            @csrf
            <div class="ui-api__fields">
                <div>
                    <label class="ui-label" for="odoo-url">{{ ___('Odoo address') }}</label>
                    <input id="odoo-url" type="url" name="url" class="ui-input ui-ltr" required maxlength="255" placeholder="https://mycompany.odoo.com" value="{{ old('url', $connection['url'] ?? '') }}">
                </div>
                <div>
                    <label class="ui-label" for="odoo-db">{{ ___('Database') }}</label>
                    <input id="odoo-db" type="text" name="database" class="ui-input ui-ltr" required maxlength="120" value="{{ old('database', $connection['database'] ?? '') }}">
                </div>
                <div>
                    <label class="ui-label" for="odoo-user">{{ ___('User (login e-mail)') }}</label>
                    <input id="odoo-user" type="text" name="username" class="ui-input ui-ltr" required maxlength="190" value="{{ old('username', $connection['username'] ?? '') }}">
                </div>
                <div>
                    <label class="ui-label" for="odoo-key">{{ ___('API key') }}</label>
                    <input id="odoo-key" type="password" name="api_key" class="ui-input ui-ltr" maxlength="255" autocomplete="new-password"
                        placeholder="{{ $connected ? ___('Saved — leave empty to keep it') : '' }}">
                </div>
                @if ($connected)
                    <fieldset class="ui-fieldset" style="border:1px solid rgba(128,128,128,.35);border-radius:8px;padding:12px;margin:0 0 12px" data-odoo-group="taxes">
                        <legend>{{ ___('Taxes') }}</legend>
                        <p class="ui-muted" id="odoo-tax-help">{{ ___('Your sales tax is sent to this Odoo tax.') }}</p>
                        <label class="ui-label" for="odoo-tax">{{ ___('Odoo tax for your sales tax') }}</label>
                        <select id="odoo-tax" name="odoo_tax_id" class="ui-input" aria-describedby="odoo-tax-help">
                            <option value="">—</option>
                            @foreach ($odooOptions['taxes'] as $t)
                                <option value="{{ $t['id'] }}" @selected((int) ($odooSettings['odoo_tax_id'] ?? 0) === (int) $t['id'])>{{ $t['name'] }} ({{ $t['amount'] }} {{ $t['amount_type'] === 'percent' ? '%' : '' }})</option>
                            @endforeach
                        </select>
                    </fieldset>
                    <fieldset class="ui-fieldset" style="border:1px solid rgba(128,128,128,.35);border-radius:8px;padding:12px;margin:0 0 12px" data-odoo-group="payments">
                        <legend>{{ ___('Payment methods') }}</legend>
                        <p class="ui-muted" id="odoo-pay-help">{{ ___('Cash payments go to the cash journal. Every other method (card, online) goes to the card and online journal.') }}</p>
                        @foreach (['odoo_journal_cash_id' => ___('Journal for cash payments'), 'odoo_journal_bank_id' => ___('Journal for card and online payments')] as $name => $label)
                            <label class="ui-label" for="{{ $name }}">{{ $label }}</label>
                            <select id="{{ $name }}" name="{{ $name }}" class="ui-input" aria-describedby="odoo-pay-help">
                                <option value="">—</option>
                                @foreach ($odooOptions['journals'] as $j)
                                    <option value="{{ $j['id'] }}" @selected((int) ($odooSettings[$name] ?? 0) === (int) $j['id'])>{{ $j['name'] }}</option>
                                @endforeach
                            </select>
                        @endforeach
                        @if ($seen['methods'])
                            <p class="ui-muted" data-odoo-seen-methods>{{ ___('Methods used in your orders:') }} {{ implode(', ', $seen['methods']) }}</p>
                        @endif
                    </fieldset>
                    <fieldset class="ui-fieldset" style="border:1px solid rgba(128,128,128,.35);border-radius:8px;padding:12px;margin:0 0 12px" data-odoo-group="stock">
                        <legend>{{ ___('Order types and stock') }}</legend>
                        <p class="ui-muted" id="odoo-pick-help">{{ ___('All order types use this delivery operation in Odoo.') }}</p>
                        <label class="ui-label" for="odoo-picking">{{ ___('Stock: delivery operation (products matched by SKU)') }}</label>
                        <select id="odoo-picking" name="odoo_picking_type_id" class="ui-input" aria-describedby="odoo-pick-help">
                            <option value="">{{ ___('Do not send stock moves') }}</option>
                            @foreach ($odooOptions['pickingTypes'] as $p)
                                <option value="{{ $p['id'] }}" @selected((int) ($odooSettings['odoo_picking_type_id'] ?? 0) === (int) $p['id'])>{{ $p['name'] }}</option>
                            @endforeach
                        </select>
                        @if ($seen['types'])
                            <p class="ui-muted" data-odoo-seen-types>{{ ___('Order types used in your orders:') }} {{ implode(', ', $seen['types']) }}</p>
                        @endif
                    </fieldset>
                @endif
                <div>
                    <label class="ui-check"><input type="checkbox" name="enabled" value="1" @checked(($odooSettings['odoo_enabled'] ?? '') === '1')> {{ ___('Send new paid orders and refunds to Odoo') }}</label>
                </div>
            </div>
            <button type="submit" class="ui-btn">{{ ___('Save and check the connection') }}</button>
        </form>
        @if ($connected)
            <form method="POST" action="{{ route('integrations.odoo.test') }}" data-odoo-test>
                @csrf
                <button type="submit" class="ui-btn ui-btn--ghost">{{ ___('Test connection') }}</button>
            </form>
            <form method="POST" action="{{ route('integrations.odoo.disconnect') }}" data-confirm="{{ ___('Disconnect Odoo? Nothing more will be sent.') }}" data-confirm-danger>
                @csrf
                <button type="submit" class="ui-btn ui-btn--ghost">{{ ___('Disconnect') }}</button>
            </form>
        @endif
    </x-ui.card>
    @endif

    @if ($allowed && $connected)
    <x-ui.card :title="___('Products matched by SKU')" data-odoo-skus>
        <p class="ui-muted">{{ ___('A menu item is matched to the Odoo product whose Internal Reference equals its SKU. This list is read only.') }}</p>
        <p data-odoo-sku-summary>{{ ___('Menu items:') }} {{ $skuPreview['total'] }} · {{ ___('With a SKU:') }} {{ $skuPreview['withSku'] }}@if ($skuPreview['checked']) · {{ ___('Found in Odoo:') }} {{ count($skuPreview['matched']) }} · {{ ___('Not found in Odoo:') }} {{ count($skuPreview['missing']) }}@endif</p>
        @if ($skuPreview['checked'] && $skuPreview['missing'])
            <p data-odoo-sku-missing>{{ ___('Not found in Odoo:') }} <span class="ui-ltr">{{ implode(', ', $skuPreview['missing']) }}</span></p>
        @endif
    </x-ui.card>
    @endif

    <x-ui.card :title="___('Sent to Odoo')" data-odoo-records>
        <nav aria-label="{{ ___('Filter by status') }}" class="ui-tabs" data-odoo-filter>
            @foreach (['' => ___('All'), 'failed' => ___('Failed'), 'retrying' => ___('Will retry'), 'pending' => ___('Waiting'), 'synced' => ___('Sent'), 'skipped' => ___('Not sent')] as $key => $label)
                <a class="ui-btn ui-btn--sm {{ $filter === $key ? '' : 'ui-btn--ghost' }}" href="{{ route('integrations.odoo.show', $key === '' ? [] : ['status' => $key]) }}" @if ($filter === $key) aria-current="true" @endif>{{ $label }}@if ($key !== '') ({{ $counts[$key] ?? 0 }})@endif</a>
            @endforeach
        </nav>
        @if ($records->isEmpty())
            <p class="ui-muted">{{ ___('Nothing sent yet.') }}</p>
        @else
            <table class="ui-table">
                <thead><tr><th>{{ ___('Order') }}</th><th>{{ ___('What') }}</th><th>{{ ___('Status') }}</th><th>{{ ___('Details') }}</th><th></th></tr></thead>
                <tbody>
                    @foreach ($records as $r)
                        <tr data-odoo-record="{{ $r->id }}" data-status="{{ $r->status }}">
                            <td data-primary data-label="{{ ___('Order') }}">#{{ $r->order_id }}</td>
                            <td data-label="{{ ___('What') }}">{{ $r->kind === 'order' ? ___('Sale') : ___('Refund') }}</td>
                            <td data-label="{{ ___('Status') }}">{{ match ($r->status) { 'synced' => ___('Sent'), 'failed' => ___('Failed'), 'retrying' => ___('Will retry'), 'skipped' => ___('Not sent'), default => ___('Waiting') } }}</td>
                            <td class="ui-muted" data-label="{{ ___('Details') }}">{{ $r->last_error ?: ($r->synced_at ? $r->synced_at->diffForHumans() : '') }}</td>
                            <td data-label="">
                                @if ($allowed && in_array($r->status, ['failed', 'retrying', 'skipped'], true))
                                    <form method="POST" action="{{ route('integrations.odoo.retry', $r->id) }}">@csrf<button type="submit" class="ui-btn ui-btn--sm">{{ ___('Retry') }}</button></form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-ui.card>
@endsection
