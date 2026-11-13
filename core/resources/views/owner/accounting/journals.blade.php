{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Journals'))
@section('page_title', ___('Journals'))
@section('page_sub', ___('General-ledger entries.'))

@section('page_actions')
    <form method="get" action="{{ route('stores.books.journals') }}" class="ui-books__filter">
        <select name="store_id" class="ui-input ui-input--sm" data-ui-do="submit" aria-label="{{ ___('Store') }}">
            @foreach ($stores as $r)
                <option value="{{ $r->id }}" @selected($rid == $r->id)>{{ $r->title }}</option>
            @endforeach
        </select>
        <input type="date" name="from" value="{{ $from }}" class="ui-input ui-input--sm" aria-label="{{ ___('From') }}">
        <input type="date" name="to" value="{{ $to }}" class="ui-input ui-input--sm" aria-label="{{ ___('To') }}">
        <x-ui.button type="submit" icon="filter">{{ ___('Filter') }}</x-ui.button>
        <x-ui.button :href="route('stores.books.export', ['store_id' => $rid, 'type' => 'journals', 'from' => $from, 'to' => $to])" variant="secondary" icon="download">{{ ___('Export CSV') }}</x-ui.button>
    </form>
@endsection

@section('content')
@include('owner.partials.feature-tabs', ['tabs' => juwri_feature_tabs('accounting')])

<p class="ui-books__note">{{ ___('The screen shows the latest 100 entries; the CSV export has every line in the range.') }}</p>

<div class="ui-scope ui-books">
    @forelse($journals as $j)
        <article class="ui-card ui-card--flush ui-books__journal">
            <header class="ui-books__journal-head">
                <div class="ui-books__journal-id">
                    <code>{{ $j->journal_no }}</code>
                    <span class="ui-books__journal-date ui-ltr">{{ optional($j->date)->toDateString() }}</span>
                </div>
                @if($j->memo)<p class="ui-books__journal-memo">{{ $j->memo }}</p>@endif
            </header>
            {{-- Kept as a 3-column ledger on phones too: debit/credit side by side is the point of a journal. --}}
            <table class="ui-books__ledger">
                <thead><tr>
                    <th>{{ ___('Account') }}</th>
                    <th class="ui-books__ledger-num">{{ ___('Debit') }}</th>
                    <th class="ui-books__ledger-num">{{ ___('Credit') }}</th>
                </tr></thead>
                <tbody>
                @foreach($j->lines as $l)
                    <tr>
                        <td>
                            <span class="ui-books__ledger-acct"><code>{{ optional($l->account)->code }}</code> {{ optional($l->account)->displayName() }}</span>
                        </td>
                        <td class="ui-books__ledger-num"><span class="ui-ltr">{{ (float)$l->debit ? number_format((float)$l->debit, 2) : '' }}</span></td>
                        <td class="ui-books__ledger-num"><span class="ui-ltr">{{ (float)$l->credit ? number_format((float)$l->credit, 2) : '' }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </article>
    @empty
        <div class="ui-card"><x-ui.empty :title="___('No journals yet.')" icon="book-open" /></div>
    @endforelse
</div>
@endsection
