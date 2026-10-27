{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Update Password'))
@section('content')
<div class="ui-columns ui-columns--wide-first">
    <div class="ui-stack">
        @include('admin.users._tabs', ['current' => 'password'])
        <form action="{{ route('admin.users.password.update', $user->id) }}" method="POST">
            @csrf
            <x-ui.card :title="___('Update Password')">
                <div class="ui-form-grid">
                    <x-ui.field type="password" name="password" :label="___('Password').' *'" autocomplete="new-password" required />
                    <x-ui.field type="password" name="password_confirmation" :label="___('Confirm Password').' *'" autocomplete="new-password" required />
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
@endsection
