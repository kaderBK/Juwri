{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')
@section('title', ___('WhatsApp Templates'))
@section('page_title', ___('WhatsApp Templates'))
@section('page_sub', ___('Approved templates for :name — used for messages outside the 24h window', ['name' => $store->title]))
@section('content')

@php
    $__statusTone = ['approved' => 'success', 'pending' => 'warning', 'rejected' => 'danger'];
    $__statusLabel = ['approved' => ___('Approved'), 'pending' => ___('Pending'), 'draft' => ___('Draft'), 'rejected' => ___('Rejected')];
    $__catLabel = ['utility' => ___('Utility'), 'marketing' => ___('Marketing'), 'authentication' => ___('Authentication')];
@endphp

<div class="ui-scope ui-wa ui-watpl">

<nav class="ui-wa__tools" aria-label="{{ ___('WhatsApp') }}">
    <x-ui.button :href="route('stores.whatsappSettings', $store->id)" variant="secondary" size="sm" icon="settings">{{ ___('Settings') }}</x-ui.button>
    <x-ui.button :href="route('stores.whatsappInbox', $store->id)" variant="secondary" size="sm" icon="chat">{{ ___('Inbox') }}</x-ui.button>
    <x-ui.button :href="route('stores.whatsappCampaign', $store->id)" variant="secondary" size="sm" icon="share">{{ ___('Campaign') }}</x-ui.button>
</nav>

<div class="ui-watpl__grid">
    <section class="ui-card ui-card--flush ui-watpl__list">
        <div class="ui-card__head">
            <h2 class="ui-card__title">{{ ___('Registered templates') }}</h2>
            @if ($templates->isNotEmpty())
                <div class="ui-card__actions"><x-ui.tag>{{ $templates->count() }}</x-ui.tag></div>
            @endif
        </div>

        @if ($templates->isEmpty())
            <x-ui.empty icon="list" :title="___('Nothing here.')" :body="___('No templates yet. Register the template names you approved on Meta/Twilio below.')" />
        @else
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>{{ ___('Name') }}</th>
                        <th>{{ ___('Category') }}</th>
                        <th>{{ ___('Lang') }}</th>
                        <th>{{ ___('Provider') }}</th>
                        <th>{{ ___('Status') }}</th>
                        <th><span class="ui-visually-hidden">{{ ___('Actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($templates as $t)
                        <tr>
                            <td data-primary><code class="ui-watpl__code">{{ $t->name }}</code></td>
                            <td data-label="{{ ___('Category') }}"><x-ui.tag :tone="$t->category === 'marketing' ? 'accent' : 'neutral'">{{ $__catLabel[$t->category] ?? ucfirst($t->category) }}</x-ui.tag></td>
                            <td data-label="{{ ___('Lang') }}"><span class="ui-ltr">{{ $t->language }}</span></td>
                            <td data-label="{{ ___('Provider') }}">{{ ucfirst($t->provider) }}</td>
                            <td data-label="{{ ___('Status') }}"><x-ui.pill :tone="$__statusTone[$t->status] ?? 'neutral'">{{ $__statusLabel[$t->status] ?? ucfirst($t->status) }}</x-ui.pill></td>
                            <td class="ui-catalog__row-actions">
                                <form method="post" action="{{ route('stores.whatsappTemplates.destroy', [$store->id, $t->id]) }}" data-confirm="{{ ___('Remove this template?') }}" data-confirm-danger>
                                    @csrf @method('DELETE')
                                    <button type="submit" class="ui-icon-btn ui-menu-item__delete" aria-label="{{ ___('Remove') }}" title="{{ ___('Remove') }}"><x-ui.icon name="trash" /></button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <form method="post" action="{{ route('stores.whatsappTemplates.store', $store->id) }}" class="ui-watpl__form">
        @csrf
        <x-ui.card :title="___('Register a template')">
            <div class="ui-watpl__fields">
                <x-ui.field name="name" :label="___('Template name')" placeholder="order_confirm" required dir="ltr" autocomplete="off"
                    :help="___('Lowercase letters, numbers and underscores only. Must match the approved name on your provider.')" />
                <div class="ui-watpl__pair">
                    <x-ui.field type="select" name="provider" :label="___('Provider')" value="meta" :options="['meta' => 'Meta', 'twilio' => 'Twilio']" />
                    <x-ui.field type="select" name="category" :label="___('Category')" value="utility" :options="$__catLabel" />
                </div>
                <div class="ui-watpl__pair">
                    <x-ui.field name="language" :label="___('Language code')" value="en" required dir="ltr" />
                    <x-ui.field type="select" name="status" :label="___('Status')" value="approved" :options="$__statusLabel" />
                </div>
                <x-ui.field type="textarea" name="body" :label="___('Body (reference only)')" placeholder="Hi {name}, your order {id} is confirmed." rows="4" dir="auto" />
            </div>
            <div class="ui-watpl__save">
                <x-ui.button type="submit" icon="check" data-ui-busy>{{ ___('Save template') }}</x-ui.button>
            </div>
        </x-ui.card>
    </form>
</div>
</div>

@endsection
