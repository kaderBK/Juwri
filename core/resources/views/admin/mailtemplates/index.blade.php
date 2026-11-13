{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Email Templates'))
@section('header_buttons')
    <x-ui.button variant="secondary" size="sm" icon="settings" :href="route('admin.settings.index').'#quick_smtp'">{{ ___('Settings') }}</x-ui.button>
@endsection
@section('content')
    <form method="post" action="{{ route('admin.mailtemplates.update') }}" id="saveEmailTemplate" data-ui-ajax>
        @csrf
        <x-ui.card :title="___('Email Notifications')">
            <div class="ui-stack">
                @foreach ($email_template as $i => $template)
                    <details class="ui-disclosure">
                        <summary class="ui-disclosure__head">{{ $i + 1 }}. {{ $template['title'] }}</summary>
                        <div class="ui-disclosure__body ui-stack">
                            <x-ui.field :name="$template['subject']" :id="'mt-'.$template['id'].'-subject'" :label="___('Subject')" :value="$settings->{$template['subject']} ?? ''" />
                            <div class="ui-field">
                                <label class="ui-field__label" for="mt-{{ $template['id'] }}-message">{{ ___('Message') }}</label>
                                <textarea id="mt-{{ $template['id'] }}-message" name="{{ $template['message'] }}" rows="6" data-ui-rich>{{ trim((string) ($settings->{$template['message']} ?? '')) }}</textarea>
                            </div>
                            <div class="ui-field">
                                <span class="ui-field__label">{{ ___('Shortcodes') }}</span>
                                <div class="ui-chips">
                                    @foreach ($template['shortcodes'] as $shortcode)
                                        <button type="button" class="ui-chip ui-mono" data-ui-copy-text="{{ $shortcode['code'] }}" title="{{ $shortcode['title'] }} · {{ ___('Copy') }}">{{ $shortcode['code'] }}</button>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </details>
                @endforeach
            </div>
            <div class="ui-card__foot">
                <x-ui.button variant="secondary" type="reset">{{ ___('Reset') }}</x-ui.button>
                <x-ui.button type="submit" name="email_setting">{{ ___('Save') }}</x-ui.button>
            </div>
        </x-ui.card>
    </form>
@endsection
