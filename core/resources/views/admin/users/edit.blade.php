{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Edit User'))
@section('content')
@php $m8Reason = \Juwri\Modules\Storefront\Services\Storefront\StorefrontAvailability::reasonForUser($user); @endphp
<div class="ui-columns ui-columns--wide-first">
    <div class="ui-stack">
        @include('admin.users._tabs', ['current' => 'account'])

        <form id="user-avatar-delete" action="{{ route('admin.users.deleteAvatar', $user->id) }}" method="POST" hidden
              data-ui-confirm="{{ ___('Are you sure?').' '.___('It will refresh the page.') }}" data-ui-danger>
            @csrf
            @method('DELETE')
        </form>

        <form action="{{ route('admin.users.update', $user->id) }}" method="POST" enctype="multipart/form-data">
            @csrf
            @method('PUT')
            <x-ui.card :title="___('Account details')">
                <div class="ui-stack">
                    <div class="ui-cluster">
                        <x-ui.image-field name="avatar" :label="___('Upload new photo')" :src="asset('storage/profile/'.$user->image)" :size="100" :hint="___('Allowed JPG, JPEG or PNG.')" />
                        <x-ui.button type="submit" form="user-avatar-delete" variant="ghost" size="sm" icon="x" class="ui-text-danger">{{ ___('Remove') }}</x-ui.button>
                    </div>
                    <x-ui.field name="name" :label="___('Fullname').' *'" :value="$user->name" required />
                    <div class="ui-form-grid">
                        <x-ui.field name="username" :label="___('Username').' *'" :value="$user->username" required />
                        <div class="ui-field">
                            <label class="ui-field__label" for="user-email">{{ ___('Email Address') }} *</label>
                            <div class="ui-input-group">
                                <input id="user-email" type="email" name="email" class="ui-input" value="{{ $user->email }}" required>
                                <button type="button" class="ui-btn ui-btn--secondary" data-ui-open="send-email-panel" title="{{ ___('Send Email') }}" aria-label="{{ ___('Send Email') }}">
                                    <span class="ui-btn__icon"><x-ui.icon name="send" size="16" /></span>
                                </button>
                            </div>
                        </div>
                        <x-ui.field type="select" name="user_type" :label="___('User Type')" :value="$user->user_type" required
                                    :options="['user' => ___('User'), 'admin' => ___('Admin')]" />
                        <div class="ui-field">
                            <label class="ui-field__label" for="user-status">{{ ___('Account status') }} *</label>
                            <div class="ui-field__control">
                                <select id="user-status" name="status" required aria-describedby="user-status-help">
                                    <option value="0" @selected((string) $user->status === '0')>{{ ___('Ban') }}</option>
                                    <option value="1" @selected((string) $user->status === '1')>{{ ___('Active') }}</option>
                                </select>
                                <x-ui.icon name="chevron-down" size="16" class="ui-field__chevron" />
                            </div>
                            {{-- WI-M8: what suspension / plan status actually turns off — the public menu API. --}}
                            <span class="ui-field__help" id="user-status-help">{{ ___('Public menu API') }}:
                                <x-ui.tag :tone="$m8Reason === null ? 'success' : 'danger'">{{ \Juwri\Modules\Storefront\Services\Storefront\StorefrontAvailability::label($m8Reason) }}</x-ui.tag></span>
                        </div>
                        <x-ui.field type="select" name="email_status" :label="___('Email status').' *'" :value="is_null($user->email_verified_at) ? '0' : '1'" required
                                    :options="['0' => ___('Unverified'), '1' => ___('Verified')]" />
                        <div class="ui-field">
                            <label class="ui-field__label" for="user-country">{{ ___('Country') }} *</label>
                            <div class="ui-field__control">
                                <select id="user-country" name="country" required>
                                    <option value="" disabled @selected(empty($user->country_code))>{{ ___('Choose') }}</option>
                                    @foreach (countries() as $country)
                                        <option value="{{ $country->id }}" @selected($country->code == ($user->country_code ?? null))>{{ $country->name }}</option>
                                    @endforeach
                                </select>
                                <x-ui.icon name="chevron-down" size="16" class="ui-field__chevron" />
                            </div>
                        </div>
                    </div>
                </div>
                <div class="ui-card__foot">
                    <x-ui.button type="submit" icon="check">{{ ___('Save Changes') }}</x-ui.button>
                </div>
            </x-ui.card>
        </form>
    </div>
    <div class="ui-stack">
        @include('admin.users.userdetails')
    </div>
</div>

<x-ui.sheet id="send-email-panel" :title="___('Send Mail to').' '.$user->email">
    <form id="user-send-mail-form" action="{{ route('admin.users.sendmail', $user->id) }}" method="POST" class="ui-stack">
        @csrf
        <div class="ui-form-grid">
            <x-ui.field name="subject" :label="___('Subject').' *'" required />
            <x-ui.field type="email" name="reply_to" :label="___('Reply to').' *'" :value="request()->user()->email" required />
        </div>
        <div class="ui-field">
            <label class="ui-field__label" for="user-mail-message">{{ ___('Message') }}</label>
            <textarea id="user-mail-message" name="message" rows="10" data-ui-rich></textarea>
        </div>
    </form>
    <x-slot:footer>
        <x-ui.button variant="secondary" data-ui-close>{{ ___('Cancel') }}</x-ui.button>
        <x-ui.button type="submit" form="user-send-mail-form" icon="send">{{ ___('Send') }}</x-ui.button>
    </x-slot:footer>
</x-ui.sheet>
@endsection
