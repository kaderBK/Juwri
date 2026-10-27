{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Employee file'))
@section('page_title', $staff->name)
@section('page_sub', ___('Employee file'))

@section('content')

@if(session('success'))<div class="ui-note" data-tone="success">{{ session('success') }}</div>@endif
@if(isset($errors) && $errors->any())<div class="ui-note" data-tone="danger">{{ $errors->first() }}</div>@endif

<div class="ui-form-grid">
    <div>
        <div class="ui-card"><div class="ui-card__body">
            <h4 class="ui-section-title">{{ ___('Profile') }}</h4>
            <form method="post" action="{{ route('staff.hrm.profile', $staff->id) }}">
                @csrf
                <div class="ui-form-grid">
                    <div><label class="ui-label ui-small">{{ ___('National ID / Iqama') }}</label><input name="national_id" class="ui-input ui-input--sm" value="{{ $staff->national_id }}"></div>
                    <div><label class="ui-label ui-small">{{ ___('Date of birth') }}</label><input type="date" name="date_of_birth" class="ui-input ui-input--sm" value="{{ optional($staff->date_of_birth)->toDateString() }}"></div>
                    <div class="ui-form-grid__full"><label class="ui-label ui-small">{{ ___('Address') }}</label><input name="address" class="ui-input ui-input--sm" value="{{ $staff->address }}"></div>
                    <div><label class="ui-label ui-small">{{ ___('Emergency contact') }}</label><input name="emergency_contact" class="ui-input ui-input--sm" value="{{ $staff->emergency_contact }}"></div>
                    <div><label class="ui-label ui-small">{{ ___('Emergency phone') }}</label><input name="emergency_phone" class="ui-input ui-input--sm" value="{{ $staff->emergency_phone }}"></div>
                    <div><label class="ui-label ui-small">{{ ___('Employment start') }}</label><input type="date" name="employment_start" class="ui-input ui-input--sm" value="{{ optional($staff->employment_start)->toDateString() }}"></div>
                    <div><label class="ui-label ui-small">{{ ___('Contract type') }}</label>
                        <select name="contract_type" class="ui-input ui-input--sm">
                            @foreach(['' => '—', 'full_time' => ___('Full time'), 'part_time' => ___('Part time'), 'casual' => ___('Casual')] as $k => $v)
                                <option value="{{ $k }}" @selected($staff->contract_type === $k)>{{ $v }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div><label class="ui-label ui-small">{{ ___('Base wage') }}</label><input type="number" step="0.01" name="base_wage" class="ui-input ui-input--sm" value="{{ $staff->base_wage }}"></div>
                </div>
                <button class="ui-btn ui-btn--sm">{{ ___('Save profile') }}</button>
            </form>
        </div></div>

        <div class="ui-card"><div class="ui-card__body">
            <h4 class="ui-section-title">{{ ___('Wage rates by role') }}</h4>
            <ul class="ui-plain-list ui-small">
                @foreach($rates as $rate)<li>{{ ___(ucfirst($rate->role)) }}: {{ number_format((float)$rate->hourly_rate, 2) }}/hr</li>@endforeach
            </ul>
            <form method="post" action="{{ route('staff.hrm.rate', $staff->id) }}" class="ui-form-grid">
                @csrf
                <div><select name="role" class="ui-input ui-input--sm">@foreach(['manager','cashier','kitchen','waiter'] as $rl)<option value="{{ $rl }}">{{ ___(ucfirst($rl)) }}</option>@endforeach</select></div>
                <div><input type="number" step="0.01" name="hourly_rate" class="ui-input ui-input--sm" placeholder="{{ ___('Rate/hr') }}" required></div>
                <div><button class="ui-btn ui-btn--secondary ui-btn--sm ui-w-full">{{ ___('Set') }}</button></div>
            </form>
        </div></div>
    </div>

    <div>
        <div class="ui-card"><div class="ui-card__body">
            <h4 class="ui-section-title">{{ ___('Onboarding') }} <span class="ui-tag" data-tone="accent">{{ $progress }}%</span></h4>
            <progress class="ui-progress" max="100" value="{{ (int) $progress }}" aria-label="{{ ___('Onboarding') }}">{{ $progress }}%</progress>
            @foreach($onboarding as $item)
                <form method="post" action="{{ route('staff.hrm.onboarding.toggle', [$staff->id, $item->id]) }}" class="ui-cluster">
                    @csrf
                    <button class="ui-btn ui-btn--sm {{ $item->done ? 'ui-btn--success' : 'ui-btn--secondary' }}">{{ $item->done ? '✓' : '○' }}</button>
                    <span class="{{ $item->done ? 'ui-strike ui-text-2' : '' }}">{{ $item->label }}</span>
                </form>
            @endforeach
        </div></div>

        <div class="ui-card"><div class="ui-card__body">
            <h4 class="ui-section-title">{{ ___('Documents') }}</h4>
            <table class="ui-table">
                @foreach($documents as $doc)
                    <tr>
                        <td data-label="{{ ___('Document') }}">{{ ___(ucfirst(str_replace('_',' ',$doc->kind))) }} {{ $doc->label }}
                            @if($doc->isExpiringSoon())<span class="ui-tag" data-tone="warning">{{ ___('expiring') }}</span>@endif
                        </td>
                        <td data-label="{{ ___('Expires') }}">{{ optional($doc->expires_at)->toDateString() }}</td>
                        <td class="ui-end" data-label="">
                            @if($doc->path)<a href="{{ route('staff.hrm.documents.download', [$staff->id, $doc->id]) }}" target="_blank" class="ui-btn ui-btn--sm ui-btn--secondary">{{ ___('View') }}</a>@endif
                            <form method="post" action="{{ route('staff.hrm.documents.destroy', [$staff->id, $doc->id]) }}" class="ui-inline">@csrf @method('DELETE')<button class="ui-btn ui-btn--sm ui-btn--danger">×</button></form>
                        </td>
                    </tr>
                @endforeach
            </table>
            <form method="post" action="{{ route('staff.hrm.documents.store', $staff->id) }}" enctype="multipart/form-data" class="ui-form-grid">
                @csrf
                <div><select name="kind" class="ui-input ui-input--sm">@foreach(['contract','id','health_cert','custom'] as $k)<option value="{{ $k }}">{{ ___(ucfirst(str_replace('_',' ',$k))) }}</option>@endforeach</select></div>
                <div><input type="date" name="expires_at" class="ui-input ui-input--sm" placeholder="{{ ___('Expiry') }}"></div>
                <div><input type="file" name="file" class="ui-input ui-input--sm" accept=".jpg,.jpeg,.png,.pdf" required></div>
                <div class="ui-form-grid__full"><button class="ui-btn ui-btn--sm">{{ ___('Upload document') }}</button></div>
            </form>
        </div></div>
    </div>
</div>

@endsection
