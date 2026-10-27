{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('AI menu actions'))
@section('page_title', ___('AI menu actions'))
@section('page_sub', ___('Describe a change to your menu and review it before it goes live.'))

@php
    $statusChip = [
        'draft'       => 'neutral',
        'approved'    => 'accent',
        'scheduled'   => 'accent',
        'applied'     => 'success',
        'rolled_back' => 'neutral',
        'failed'      => 'danger',
        'cancelled'   => 'neutral',
    ];
    $statusLabel = [
        'draft'       => ___('Draft'),
        'approved'    => ___('Approved'),
        'scheduled'   => ___('Scheduled'),
        'applied'     => ___('Applied'),
        'rolled_back' => ___('Rolled back'),
        'failed'      => ___('Failed'),
        'cancelled'   => ___('Cancelled'),
    ];
@endphp

@section('content')
@if(session('ai_success'))
    <div class="ui-note" data-tone="success">{{ session('ai_success') }}</div>
@endif
@if(session('ai_error'))
    <div class="ui-note" data-tone="warning">{{ session('ai_error') }}</div>
@endif
@if($errors->any())
    <div class="ui-note" data-tone="danger">{{ $errors->first() }}</div>
@endif

<div class="ui-card">
    <div class="ui-card__body">
        <h4 class="ui-section-title">{{ ___('Describe a menu change') }}</h4>
        <form method="post" action="{{ route('ai.menu-actions.propose') }}">
            @csrf
            <div>
                <textarea name="prompt" class="ui-input" rows="3" maxlength="1000" required
                    placeholder="{{ ___('e.g. Hide the summer specials and raise all coffee prices by 10%.') }}">{{ old('prompt') }}</textarea>
            </div>
            <button type="submit" class="ui-btn">{{ ___('Propose changes') }}</button>
        </form>
    </div>
</div>

{{-- Drafts awaiting a decision, each with a before/after preview. --}}
<div class="ui-card" id="ai-drafts">
    <div class="ui-card__body">
        <h4 class="ui-section-title">{{ ___('Proposed changes') }}</h4>
        @forelse($drafts as $a)
            <div class="ui-bordered">
                <div class="ui-cluster ui-cluster--between ui-cluster--top">
                    <strong>{{ $a->title }}</strong>
                    <span class="ui-tag" data-tone="{{ $statusChip[$a->status] ?? 'neutral' }}">{{ $statusLabel[$a->status] ?? $a->status }}</span>
                </div>
                @if(!empty($previews[$a->id]))
                    <div class="ui-table-wrap">
                        <table class="ui-table">
                            <thead>
                                <tr class="ui-muted">
                                    <th>{{ ___('Item') }}</th>
                                    <th>{{ ___('Before') }}</th>
                                    <th>{{ ___('After') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($previews[$a->id] as $row)
                                    <tr>
                                        <td data-label="{{ ___('Item') }}">{{ $row['label'] }}</td>
                                        <td class="ui-text-2" data-label="{{ ___('Before') }}">{{ $row['before'] }}</td>
                                        <td class="ui-strong" data-label="{{ ___('After') }}">{{ $row['after'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                <form method="post" action="{{ route('ai.menu-actions.act', $a->id) }}" class="ui-form-grid">
                    @csrf
                    <div>
                        <label class="ui-label ui-small">{{ ___('Schedule for') }}</label>
                        <input type="datetime-local" name="run_at" class="ui-input ui-input--sm">
                    </div>
                    <div>
                        <label class="ui-label ui-small">{{ ___('Repeat') }}</label>
                        <select name="recurrence" class="ui-input ui-input--sm">
                            <option value="">{{ ___('Once') }}</option>
                            <option value="daily">{{ ___('Daily') }}</option>
                            <option value="weekly">{{ ___('Weekly') }}</option>
                        </select>
                    </div>
                    <div>
                        <button type="submit" name="op" value="approve" class="ui-btn ui-btn--sm ui-btn--success">{{ ___('Approve now') }}</button>
                        <button type="submit" name="op" value="schedule" class="ui-btn ui-btn--sm ui-btn--secondary">{{ ___('Schedule') }}</button>
                        <button type="submit" name="op" value="cancel" class="ui-btn ui-btn--sm ui-btn--secondary">{{ ___('Cancel') }}</button>
                    </div>
                </form>
            </div>
        @empty
            <p class="ui-muted">{{ ___('No proposed changes yet. Describe one above.') }}</p>
        @endforelse
    </div>
</div>

{{-- Scheduled changes awaiting their run time. --}}
@if($scheduled->isNotEmpty())
<div class="ui-card">
    <div class="ui-card__body">
        <h4 class="ui-section-title">{{ ___('Scheduled') }}</h4>
        @foreach($scheduled as $a)
            <div class="ui-cluster ui-cluster--between ui-rule-bottom">
                <div>
                    <strong>{{ $a->title }}</strong>
                    <span class="ui-muted">{{ optional($a->run_at)->isoFormat('D MMM YYYY, HH:mm') }}@if($a->recurrence) · {{ $a->recurrence === 'weekly' ? ___('Weekly') : ___('Daily') }}@endif</span>
                </div>
                <form method="post" action="{{ route('ai.menu-actions.act', $a->id) }}">
                    @csrf
                    <button type="submit" name="op" value="cancel" class="ui-btn ui-btn--sm ui-btn--secondary">{{ ___('Cancel') }}</button>
                </form>
            </div>
        @endforeach
    </div>
</div>
@endif

{{-- Applied changes, each reversible. --}}
@if($applied->isNotEmpty())
<div class="ui-card">
    <div class="ui-card__body">
        <h4 class="ui-section-title">{{ ___('Applied') }}</h4>
        @foreach($applied as $a)
            <div class="ui-cluster ui-cluster--between ui-rule-bottom">
                <div>
                    <strong>{{ $a->title }}</strong>
                    <span class="ui-muted">{{ optional($a->applied_at)->isoFormat('D MMM YYYY, HH:mm') }}</span>
                </div>
                <form method="post" action="{{ route('ai.menu-actions.act', $a->id) }}">
                    @csrf
                    <button type="submit" name="op" value="rollback" class="ui-btn ui-btn--sm ui-btn--danger">{{ ___('Roll back') }}</button>
                </form>
            </div>
        @endforeach
    </div>
</div>
@endif

{{-- History: rolled back / failed / cancelled. --}}
@if($history->isNotEmpty())
<div class="ui-card">
    <div class="ui-card__body">
        <h4 class="ui-section-title">{{ ___('History') }}</h4>
        @foreach($history as $a)
            <div class="ui-cluster ui-cluster--between ui-rule-bottom">
                <div>
                    <strong>{{ $a->title }}</strong>
                    @if($a->status === 'failed' && $a->error)<span class="ui-text-danger ui-small">{{ $a->error }}</span>@endif
                </div>
                <span class="ui-tag" data-tone="{{ $statusChip[$a->status] ?? 'neutral' }}">{{ $statusLabel[$a->status] ?? $a->status }}</span>
            </div>
        @endforeach
    </div>
</div>
@endif
@endsection
