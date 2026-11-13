{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Disputes'))
@section('page_title', ___('Disputes'))
@section('page_sub', ___('Payment chargebacks — respond before the deadline.'))

@section('page_actions')
    <form method="get" action="{{ route('stores.disputes.index') }}">
        <select name="store_id" class="ui-input ui-input--sm ui-mods__store" data-ui-do="submit" aria-label="{{ ___('Store') }}">
            @foreach ($stores as $r)
                <option value="{{ $r->id }}" @selected($rid == $r->id)>{{ $r->title }}</option>
            @endforeach
        </select>
    </form>
@endsection

@section('content')

<div class="ui-scope ui-disp">
@if($disputes->isEmpty())
    <div class="ui-card">
        <x-ui.empty icon="check" :title="___('No disputes. 🎉')" />
    </div>
@else
    @foreach($disputes as $d)
        @php
            $overdue = $d->evidence_due_at && $d->isOpen() && $d->evidence_due_at->isPast();
            $soon = $d->evidence_due_at && $d->isOpen() && !$overdue && $d->evidence_due_at->diffInDays(now()) <= 3;
        @endphp
        <article class="ui-card ui-disp__item" @if($d->isOpen()) data-open @endif @if($overdue) data-overdue @endif>
            <header class="ui-disp__head">
                <div class="ui-disp__amount">
                    <strong class="ui-ltr">{{ $d->currency }} {{ number_format($d->amount, 2) }}</strong>
                    <span class="ui-disp__reason">{{ str_replace('_', ' ', (string) $d->reason) }}</span>
                </div>
                <x-ui.pill :tone="$d->isOpen() ? 'warning' : ($d->status === 'won' ? 'success' : 'neutral')">{{ str_replace('_', ' ', (string) $d->status) }}</x-ui.pill>
            </header>

            <dl class="ui-disp__facts">
                <div><dt>{{ ___('Opened') }}</dt><dd class="ui-ltr">{{ optional($d->opened_at)->format('d M Y') ?: '—' }}</dd></div>
                <div>
                    <dt>{{ ___('Respond by') }}</dt>
                    <dd>
                        @if($d->evidence_due_at && $d->isOpen())
                            <span @class(['ui-disp__due', 'is-overdue' => $overdue, 'is-soon' => $soon])>
                                {{ $overdue ? ___('overdue') : $d->evidence_due_at->diffForHumans() }}
                            </span>
                        @else
                            —
                        @endif
                    </dd>
                </div>
                <div><dt>{{ ___('Order') }}</dt><dd class="ui-ltr">@if($d->order_id)#{{ $d->order_id }}@else—@endif</dd></div>
            </dl>

            <div class="ui-disp__actions">
                @if($d->isOpen())
                    <button type="button" class="ui-btn ui-btn--sm"
                            data-ui-do="toggle" data-ui-target="evidence-{{ $d->id }}" aria-controls="evidence-{{ $d->id }}" aria-expanded="false">
                        <span class="ui-btn__icon"><x-ui.icon name="upload" /></span>
                        {{ $d->evidence_submitted_at ? ___('Update evidence') : ___('Submit evidence') }}
                    </button>
                @endif
                <a class="ui-btn ui-btn--secondary ui-btn--sm" target="_blank" rel="noopener"
                   href="https://dashboard.stripe.com/disputes/{{ $d->external_id }}"><span class="ui-btn__icon"><x-ui.icon name="external" /></span>{{ ___('Open in Stripe') }}</a>
            </div>

            @if($d->isOpen())
                {{-- WIRING-RUN E6: evidence is submitted here now, not only in the Stripe dashboard. --}}
                <div id="evidence-{{ $d->id }}" hidden>
                    <form method="post" action="{{ route('stores.disputes.evidence', $d->id) }}" class="ui-disp__form">
                        @csrf
                        @if($d->evidence_submitted_at)
                            <x-ui.banner tone="success">
                                {{ ___('Evidence sent') }} {{ $d->evidence_submitted_at->diffForHumans() }}. {{ ___('Submitting again replaces it.') }}
                            </x-ui.banner>
                        @endif
                        <div class="ui-disp__fields">
                            @foreach($evidenceFields as $field)
                                @php $isLong = in_array($field, ['uncategorized_text', 'refund_policy_disclosure', 'cancellation_policy_disclosure'], true); @endphp
                                <div @class(['ui-disp__field', 'ui-disp__field--long' => $isLong])>
                                    <label class="ui-label" for="ev-{{ $d->id }}-{{ $field }}">{{ ___(ucfirst(str_replace('_', ' ', $field))) }}</label>
                                    @if($isLong)
                                        <textarea class="ui-input" id="ev-{{ $d->id }}-{{ $field }}" name="{{ $field }}" rows="2" maxlength="20000"></textarea>
                                    @else
                                        <input class="ui-input" id="ev-{{ $d->id }}-{{ $field }}" name="{{ $field }}" maxlength="20000">
                                    @endif
                                </div>
                            @endforeach
                        </div>
                        <div class="ui-disp__send">
                            <x-ui.button type="submit" size="sm" icon="upload" data-ui-busy>{{ ___('Send to Stripe') }}</x-ui.button>
                            <small>{{ ___('Receipts and other file evidence still go through the Stripe dashboard.') }}</small>
                        </div>
                    </form>
                </div>
            @endif
        </article>
    @endforeach
@endif
</div>

@endsection
