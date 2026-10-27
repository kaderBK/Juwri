{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- One plugin row. $plugin (installed or marketplace entry); $listed = true for the marketplace list. --}}
@php $listed = $listed ?? false; @endphp
<x-ui.card>
    <div class="ui-plugin">
        <a class="ui-plugin__art" href="{{ $plugin->url ?? '#' }}" target="_blank" rel="noopener" aria-label="{{ $plugin->name }}">
            <x-ui.icon name="settings" size="28" />
            @if (! empty($plugin->image))
                <img src="{{ $plugin->image }}" width="72" height="72" alt="" data-ui-do="hide-on-error">
            @endif
        </a>
        <div class="ui-plugin__body">
            <h3 class="ui-plugin__name">
                <a href="{{ $plugin->url ?? '#' }}" target="_blank" rel="noopener">{{ $plugin->name }}</a>
                @if ($plugin->installed)<x-ui.tag tone="accent">{{ ___('Installed') }}</x-ui.tag>@endif
                @if ($plugin->enabled)<x-ui.tag tone="success">{{ ___('Enabled') }}</x-ui.tag>@endif
            </h3>
            <p class="ui-text-2">{{ $plugin->description }}</p>
            <p class="ui-small ui-text-2">
                <strong>{{ ___('Author') }}</strong> <a href="{{ $plugin->author_url ?? '#' }}" target="_blank" rel="noopener">{{ $plugin->author }}</a>
                · <strong>{{ ___('Version') }}</strong> {{ $plugin->version }}
            </p>
            @if (! $plugin->is_compatible)
                <p class="ui-small ui-text-danger">{!! ___('This plugin is not compatible with the current version of your script. Update your script to version <strong>:VERSION_CODE</strong> or higher.', ['VERSION_CODE' => e($plugin->min_app_version)]) !!}</p>
            @endif
            @if ($listed && ! empty($plugin->update_available))
                <p class="ui-small ui-text-accent">{!! $plugin->update_message !!}</p>
            @endif
        </div>
        <div class="ui-plugin__actions">
            @if ($listed && ! $plugin->installed)
                <x-ui.button variant="secondary" size="sm" icon="download" :href="$plugin->url" target="_blank" rel="noopener">{{ ___('Install') }}</x-ui.button>
            @elseif ($plugin->enabled)
                @if (isset($plugin->settings_route))
                    <x-ui.button variant="secondary" size="sm" icon="settings" :href="route($plugin->settings_route)">{{ ___('Settings') }}</x-ui.button>
                @endif
                <form action="{{ route('admin.plugins.disable', $plugin->id) }}" method="post">
                    @csrf
                    <x-ui.button type="submit" variant="secondary" size="sm" icon="x">{{ ___('Disable') }}</x-ui.button>
                </form>
            @else
                @if ($plugin->is_compatible)
                    <form action="{{ route('admin.plugins.enable', $plugin->id) }}" method="post">
                        @csrf
                        <x-ui.button type="submit" size="sm" icon="check">{{ ___('Enable') }}</x-ui.button>
                    </form>
                @endif
                <form action="{{ route('admin.plugins.destroy', $plugin->id) }}" method="post" data-ui-confirm="{{ ___('Are you sure?') }}" data-ui-danger>
                    @method('delete')
                    @csrf
                    <x-ui.button type="submit" variant="danger" size="sm" icon="trash">{{ ___('Delete') }}</x-ui.button>
                </form>
            @endif
        </div>
    </div>
</x-ui.card>
