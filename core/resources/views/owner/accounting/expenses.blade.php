{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Expenses'))
@section('page_title', ___('Expenses'))
@section('page_sub', ___('Track spending and post it to your books.'))

@section('page_actions')
    <form method="get" action="{{ route('stores.books.expenses') }}">
        <select name="store_id" class="ui-input ui-input--sm" data-ui-do="submit" aria-label="{{ ___('Store') }}">
            @foreach ($stores as $r)
                <option value="{{ $r->id }}" @selected($rid == $r->id)>{{ $r->title }}</option>
            @endforeach
        </select>
    </form>
@endsection

@section('content')
@include('owner.partials.feature-tabs', ['tabs' => juwri_feature_tabs('accounting')])

<div class="ui-scope ui-books">
    {{-- session('success') already surfaces as a toast via partials.flash. --}}
    @if(isset($errors) && $errors->any())
        <x-ui.banner tone="danger">{{ $errors->first() }}</x-ui.banner>
    @endif

    <x-ui.card :title="___('New expense')">
        <form method="post" action="{{ route('stores.books.expenses.store') }}?store_id={{ $rid }}" enctype="multipart/form-data" class="ui-books__form">
            @csrf
            <div class="ui-books__grid">
                <div class="ui-books__field ui-books__field--wide">
                    <label class="ui-label" for="ex-account">{{ ___('Account') }}</label>
                    <select id="ex-account" name="gl_account_id" class="ui-input">
                        @foreach($accounts as $a)
                            <option value="{{ $a->id }}">{{ $a->code }} — {{ $a->displayName() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="ui-books__field ui-books__field--wide">
                    <label class="ui-label" for="ex-desc">{{ ___('Description') }}</label>
                    <input id="ex-desc" type="text" name="description" class="ui-input" maxlength="500">
                </div>
                <div class="ui-books__field">
                    <label class="ui-label" for="ex-amount">{{ ___('Amount') }}</label>
                    <input id="ex-amount" type="number" step="0.01" min="0" name="amount" class="ui-input" inputmode="decimal" required>
                </div>
                <div class="ui-books__field">
                    <label class="ui-label" for="ex-tax">{{ ___('VAT') }}</label>
                    <input id="ex-tax" type="number" step="0.01" min="0" name="tax" class="ui-input" inputmode="decimal" value="0">
                </div>
                <div class="ui-books__field">
                    <label class="ui-label" for="ex-date">{{ ___('Date') }}</label>
                    <input id="ex-date" type="date" name="date" class="ui-input" value="{{ now()->toDateString() }}" required>
                </div>
                <div class="ui-books__field">
                    <label class="ui-label" for="ex-source">{{ ___('Paid from') }}</label>
                    <select id="ex-source" name="payment_source" class="ui-input">
                        <option value="cash">{{ ___('Cash / Bank') }}</option>
                        <option value="payable">{{ ___('Accounts Payable') }}</option>
                    </select>
                </div>
                <div class="ui-books__field">
                    <label class="ui-label" for="ex-repeat">{{ ___('Repeat') }}</label>
                    <select id="ex-repeat" name="recur_interval" class="ui-input">
                        <option value="">{{ ___('One-off') }}</option>
                        <option value="weekly">{{ ___('Weekly') }}</option>
                        <option value="monthly">{{ ___('Monthly') }}</option>
                    </select>
                </div>
                <div class="ui-books__field">
                    <label class="ui-label" for="ex-receipt">{{ ___('Receipt') }}</label>
                    <input id="ex-receipt" type="file" name="receipt" class="ui-input" accept=".jpg,.jpeg,.png,.pdf">
                </div>
            </div>
            <div class="ui-books__submit">
                <x-ui.button type="submit" icon="plus" data-ui-busy>{{ ___('Record expense') }}</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card :title="___('Expenses')" variant="flush">
        <x-slot:actions><x-ui.tag>{{ $expenses->count() }}</x-ui.tag></x-slot:actions>
        @if($expenses->isEmpty())
            <x-ui.empty :title="___('No expenses yet.')" icon="wallet" />
        @else
            <table class="ui-table">
                <thead><tr>
                    <th>{{ ___('Description') }}</th>
                    <th>{{ ___('Date') }}</th>
                    <th>{{ ___('Account') }}</th>
                    <th class="ui-table__num">{{ ___('Amount') }}</th>
                    <th class="ui-table__num">{{ ___('VAT') }}</th>
                    <th>{{ ___('Receipt') }}</th>
                    <th><span class="ui-visually-hidden">{{ ___('Actions') }}</span></th>
                </tr></thead>
                <tbody>
                @foreach($expenses as $e)
                    <tr>
                        <td data-primary>{{ $e->description ?: '—' }}</td>
                        <td data-label="{{ ___('Date') }}"><span class="ui-ltr">{{ optional($e->date)->toDateString() }}</span></td>
                        <td data-label="{{ ___('Account') }}">
                            <span class="ui-books__acct">
                                <code>{{ optional($e->glAccount)->code }}</code>
                                @if($e->recur_parent_id)<x-ui.tag tone="accent">{{ ___('recurring') }}</x-ui.tag>@endif
                            </span>
                        </td>
                        <td class="ui-table__num" data-label="{{ ___('Amount') }}"><strong class="ui-ltr">{{ number_format((float)$e->amount, 2) }}</strong></td>
                        <td class="ui-table__num" data-label="{{ ___('VAT') }}"><span class="ui-ltr">{{ number_format((float)$e->tax, 2) }}</span></td>
                        <td data-label="{{ ___('Receipt') }}">
                            @if($e->receipt_path)
                                <x-ui.button variant="link" size="sm" icon="external" :href="route('stores.books.expenses.receipt', $e->id)" target="_blank">{{ ___('View') }}</x-ui.button>
                            @else
                                <span class="ui-books__muted">—</span>
                            @endif
                        </td>
                        <td class="ui-catalog__row-actions">
                            <form method="post" action="{{ route('stores.books.expenses.destroy', $e->id) }}?store_id={{ $rid }}" data-confirm="{{ ___('Delete this expense?') }}" data-confirm-danger>
                                @csrf @method('DELETE')
                                <button type="submit" class="ui-icon-btn ui-menu-item__delete" aria-label="{{ ___('Delete') }}" title="{{ ___('Delete') }}"><x-ui.icon name="trash" /></button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </x-ui.card>
</div>
@endsection
