{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    $__waLegacyReads = (int) (config('settings.whatsapp_legacy_read_count') ?: 0);
    $__waLegacyLast = config('settings.whatsapp_legacy_read_last_at');
    $__waLegacyRows = 0;
    foreach (array_merge(['whatsapp_number'], array_map(fn ($i) => 'whatsapp_number'.$i, range(2, 10))) as $__waKey) {
        $__waLegacyRows += count(\Juwri\Core\Settings\StoreSettings::valuesFor($__waKey));
    }
    $__cspEnabled = (bool) config('csp.enabled', true);
    $__cspEnforced = (string) config('csp.policy') !== '';
    $__envHealth = \Juwri\Core\Services\Ops\EnvFileHealth::report();
@endphp
{{-- Storefront content security is read-only: CSP_ENFORCE is a deploy-time .env value (WI-M1). --}}
<section id="quick_system_info" role="tabpanel" aria-labelledby="tab-quick_system_info" @if (($active ?? true) === false) hidden @endif>
    <x-ui.card :title="___('System Info')">
        <div class="ui-stack">
            @foreach ([
                ___('App Details') => [
                    ___('Name') => config('appinfo.name'),
                    ___('Version') => config('appinfo.version'),
                    ___('Laravel Version') => app()->version(),
                    ___('Default Language') => strtoupper((string) config('app.locale')),
                    ___('Timezone') => config('app.timezone'),
                ],
                ___('Server Details') => [
                    ___('PHP Version') => phpversion(),
                    ___('Software') => $_SERVER['SERVER_SOFTWARE'] ?? '—',
                    ___('Protocol') => strtoupper((string) ($_SERVER['SERVER_PROTOCOL'] ?? '—')),
                    ___('HTTP Host') => $_SERVER['HTTP_HOST'] ?? '—',
                    ___('Port') => $_SERVER['SERVER_PORT'] ?? '—',
                    ___('IP Address') => $_SERVER['SERVER_ADDR'] ?? '—',
                ],
                ___('Storefront content security') => [
                    ___('CSP headers') => $__cspEnabled ? ___('On') : ___('Off'),
                    ___('Storefront policy mode') => $__cspEnforced ? ___('Enforcing') : ___('Report only'),
                    ___('Violation report endpoint') => config('csp.report_uri'),
                ],
            ] as $__heading => $__pairs)
                <p class="ui-section-title">{{ $__heading }}</p>
                <dl class="ui-dl">
                    @foreach ($__pairs as $__k => $__val)
                        <div><dt>{{ $__k }}</dt><dd>{{ $__val }}</dd></div>
                    @endforeach
                </dl>
            @endforeach

            {{-- WA-D1/D4: "Legacy reads" is the evidence for retiring whatsapp_number (zero = safe to delete). --}}
            <p class="ui-section-title">{{ ___('WhatsApp legacy migration') }}</p>
            <dl class="ui-dl">
                <div><dt>{{ ___('Legacy option rows remaining') }}</dt><dd>{{ number_format($__waLegacyRows) }}</dd></div>
                <div><dt>{{ ___('Legacy reads recorded') }}</dt><dd @class(['ui-text-warning' => $__waLegacyReads, 'ui-text-success' => ! $__waLegacyReads])>{{ number_format($__waLegacyReads) }}</dd></div>
                <div><dt>{{ ___('Last legacy read') }}</dt><dd>{{ $__waLegacyLast ?: ___('Never') }}</dd></div>
            </dl>
            <x-ui.banner tone="info">{{ ___('Re-run after importing a legacy database:') }} <code>php artisan whatsapp:migrate-legacy --dry-run</code></x-ui.banner>

            {{-- H8: read-only .env diagnostics — key names and verdicts only, never a value. --}}
            <p class="ui-section-title">{{ ___('Environment file health') }}</p>
            @if (! $__envHealth['readable'])
                <x-ui.banner tone="warning">{{ ___('The .env file could not be read from the web process.') }}</x-ui.banner>
            @elseif ($__envHealth['ok'])
                <dl class="ui-dl">
                    <div><dt>{{ ___('Environment file') }}</dt><dd class="ui-text-success">{{ ___('No issues found') }}</dd></div>
                </dl>
            @else
                <dl class="ui-dl">
                    @foreach ($__envHealth['issues'] as $__issue)
                        <div>
                            <dt><code>{{ $__issue['key'] }}</code></dt>
                            <dd class="ui-text-warning">
                                @switch($__issue['code'])
                                    @case('app_key_leak') {{ ___('Holds a copy of APP_KEY') }} @break
                                    @case('malformed_value') {{ ___('Malformed value') }} @break
                                    @case('duplicate_key') {{ ___('Defined more than once') }} @break
                                    @default {{ ___('Unquoted value with a space or #') }}
                                @endswitch
                            </dd>
                        </div>
                    @endforeach
                </dl>
                <x-ui.banner tone="warning">{{ ___('Run this on the server for details and the fix:') }} <code>php artisan platform:env-health</code></x-ui.banner>
            @endif

            <p class="ui-section-title">{{ ___('System Cache') }}</p>
            <form method="POST" action="{{ route('admin.settings.update') }}" data-ui-ajax class="ui-stack">
                <x-ui.banner tone="info">{{ ___('Clear all caches and error logs') }}</x-ui.banner>
                <input type="hidden" name="clear_cache" value="1">
                <x-ui.button type="submit" variant="danger" icon="refresh" block>{{ ___('Clear Cache') }}</x-ui.button>
            </form>
        </div>
    </x-ui.card>
</section>
