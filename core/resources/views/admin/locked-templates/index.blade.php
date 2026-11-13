{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Page Templates'))
@section('page_sub', ___('Locked templates: you control the design and code, resellers fill in their own text, images, links and colours.'))
@section('content')
<div class="ui-stack">
    {{-- session('success') is shown as a toast by the admin layout. --}}
    @if ($errors->any())
        <x-ui.banner tone="danger">{{ $errors->first() }}</x-ui.banner>
    @endif

    <x-ui.card variant="flush">
        @if ($templates->isEmpty())
            <x-ui.empty icon="layout" :title="___('Page Templates')">
                <p class="ui-small ui-text-2">{{ ___('No templates yet. Import the sample template with') }} <code>php artisan locked-templates:import --publish</code>.</p>
            </x-ui.empty>
        @else
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead>
                        <tr>
                            <th>{{ ___('Template') }}</th>
                            <th>{{ ___('Status') }}</th>
                            <th class="ui-center">{{ ___('Current version') }}</th>
                            <th class="ui-center">{{ ___('Versions') }}</th>
                            <th class="ui-center">{{ ___('Pages using it') }}</th>
                            <th class="ui-end">{{ ___('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($templates as $t)
                            <tr>
                                <td data-primary data-label="{{ ___('Template') }}">
                                    <span class="ui-strong">{{ $t->name }}</span>
                                    <small class="ui-cell-sub"><code>{{ $t->key }}</code>@if ($t->category) · {{ $t->category }}@endif</small>
                                    @if ($t->description)
                                        <small class="ui-cell-sub" style="max-inline-size: 48ch">{{ $t->description }}</small>
                                    @endif
                                </td>
                                <td data-label="{{ ___('Status') }}">
                                    @if ($t->isPublished())
                                        <x-ui.tag tone="success">{{ ___('Published') }}</x-ui.tag>
                                    @else
                                        <x-ui.tag>{{ ___('Draft') }}</x-ui.tag>
                                    @endif
                                </td>
                                <td class="ui-center" data-label="{{ ___('Current version') }}">{{ $t->current_version ? 'v'.$t->current_version : '—' }}</td>
                                <td class="ui-center" data-label="{{ ___('Versions') }}">{{ $versionCounts[$t->id] ?? 0 }}</td>
                                <td class="ui-center" data-label="{{ ___('Pages using it') }}">{{ $usage[$t->id] ?? 0 }}</td>
                                <td class="ui-end" data-label="">
                                    <div class="ui-cell-actions">
                                        <x-ui.button variant="secondary" size="sm" icon="eye" target="_blank" rel="noopener"
                                                     :href="route('admin.locked-templates.preview', $t->id)">{{ ___('Preview') }}</x-ui.button>
                                        <x-ui.button variant="secondary" size="sm" icon="file" :href="route('admin.locked-templates.versions.create', $t->id)">{{ ___('New version') }}</x-ui.button>
                                        <form action="{{ route('admin.locked-templates.status', $t->id) }}" method="post">
                                            @csrf
                                            @if ($t->isPublished())
                                                <input type="hidden" name="status" value="draft">
                                                <x-ui.button type="submit" variant="secondary" size="sm" class="ui-text-warning">{{ ___('Hide') }}</x-ui.button>
                                            @else
                                                <input type="hidden" name="status" value="published">
                                                <x-ui.button type="submit" size="sm">{{ ___('Publish') }}</x-ui.button>
                                            @endif
                                        </form>
                                        @if (($versionCounts[$t->id] ?? 0) > 1)
                                            <form action="{{ route('admin.locked-templates.current', $t->id) }}" method="post" class="ui-cluster" style="--ui-gap: 4px">
                                                @csrf
                                                <select name="version" class="ui-input ui-input--sm ui-input--auto" aria-label="{{ ___('Current version') }}">
                                                    @foreach ($t->versions as $v)
                                                        <option value="{{ $v->version }}" @selected($v->version === $t->current_version)>v{{ $v->version }}</option>
                                                    @endforeach
                                                </select>
                                                <x-ui.button type="submit" variant="secondary" size="sm">{{ ___('Set') }}</x-ui.button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>

    <div class="ui-columns" style="--ui-col-min: 320px">
        <x-ui.card :title="___('Default editing permissions')">
            <form action="{{ route('admin.locked-templates.defaults') }}" method="post" class="ui-stack">
                @csrf
                <p class="ui-small ui-text-2">{{ ___('Applies to every user without their own override.') }}</p>
                @foreach ($caps as $cap => $label)
                    <x-ui.switch :id="'def-'.$cap" :name="'caps['.$cap.']'" :checked="(bool) ($defaults[$cap] ?? false)" :label="___($label)" />
                @endforeach
                <div><x-ui.button type="submit" size="sm" data-ui-busy>{{ ___('Save defaults') }}</x-ui.button></div>
            </form>
        </x-ui.card>

        <x-ui.card :title="___('Per-user overrides')">
            <div class="ui-stack">
                <p class="ui-small ui-text-2">{{ ___('Give a specific user more or fewer editing rights, or limit which templates they can use.') }}</p>
                <form action="{{ route('admin.locked-templates.permissions.find') }}" method="post" class="ui-cluster">
                    @csrf
                    <input type="email" name="email" class="ui-input ui-input--sm ui-grow" placeholder="{{ ___('User email') }}" required aria-label="{{ ___('User email') }}">
                    <x-ui.button type="submit" variant="secondary" size="sm">{{ ___('Edit user permissions') }}</x-ui.button>
                </form>
                @if ($overrides->isEmpty())
                    <p class="ui-small ui-text-2">{{ ___('No overrides — everyone uses the defaults.') }}</p>
                @else
                    <div class="ui-table-wrap">
                        <table class="ui-table">
                            <thead>
                                <tr>
                                    <th>{{ ___('User') }}</th>
                                    <th>{{ ___('Default editing permissions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($overrides as $o)
                                    @php($u = $overrideUsers[$o->user_id] ?? null)
                                    <tr>
                                        <td data-primary data-label="{{ ___('User') }}">
                                            @if ($u)
                                                <a href="{{ route('admin.users.edit', $u->id) }}#locked-template-permissions">{{ $u->name ?: $u->email }}</a>
                                                <small class="ui-cell-sub">{{ $u->email }}</small>
                                            @else
                                                <span class="ui-text-2">#{{ $o->user_id }}</span>
                                            @endif
                                        </td>
                                        <td data-label="">
                                            <span class="ui-cell-tags">
                                                @foreach ($caps as $cap => $label)
                                                    @if (!empty($o->caps[$cap]))
                                                        <x-ui.tag tone="accent">{{ ___($label) }}</x-ui.tag>
                                                    @endif
                                                @endforeach
                                                @if (is_array($o->allowed_templates))
                                                    <x-ui.tag tone="warning">{{ ___(':n templates only', ['n' => count($o->allowed_templates)]) }}</x-ui.tag>
                                                @endif
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </x-ui.card>
    </div>
</div>
@endsection
