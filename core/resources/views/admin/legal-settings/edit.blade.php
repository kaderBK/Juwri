{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Legal & compliance'))
@section('page_sub', ___('Owner facts and product promises for the published legal pages.'))
@section('content')
@php
    $__fill = $effective['fill'];
    $__verify = $effective['verify'];
    $__unused = $effective['unused'];

    // Label + short help for the standalone fill fields. Sub-processor vendor/location inputs are
    // rendered as rows (vendor + location + "Not used") and get no per-field meta here.
    $__fillMeta = [
        'hosting_provider' => [___('Hosting provider'), ___('The cloud provider that runs juwri Cloud: application servers, databases and file storage.')],
        'hosting_region' => [___('Hosting region'), ___('The single hosting region where all customer data lives.')],
        'hosting_certifications' => [___('Hosting certifications'), ___('Certifications the hosting provider holds (for example ISO 27001, SOC 2).')],
        'vendor_hosting' => [___('Hosting vendor'), ___('The hosting company named in the Sub-processors table.')],
        'whatsapp_number' => [___('WhatsApp phone number'), ___('The WhatsApp business line shown on the SLA page.')],
        'support_phone' => [___('Support phone'), ___('The phone support number, for higher plans only.')],
        'holiday_calendar_url' => [___('Holiday calendar URL'), ___('A public calendar listing the days support is closed.')],
        'higher_plans' => [___('Higher plans'), ___('The plans that include the phone and priority support described on the SLA page.')],
        'ico_number' => [___('ICO registration number'), ___("The UK Information Commissioner's Office registration number shown in the privacy policy.")],
        'egypt_representative' => [___('Egypt representative'), ___('The representative appointed under Egyptian data-protection law, named in the DPA.')],
        'analytics_tool' => [___('Analytics tool'), ___('The analytics tool named in the privacy policy cookie table.')],
        'pgp_fingerprint' => [___('PGP fingerprint'), ___('The fingerprint of the PGP key served at /.well-known/pgp-key.txt.')],
        'github_url' => [___('GitHub URL'), ___('Where the open-source Community Edition code and private vulnerability reporting live.')],
    ];

    // Friendly row titles for the removable sub-processors (hosting is never removable).
    $__subName = [
        'backup' => ___('Backup and object storage'),
        'cdn' => ___('Content delivery and network security'),
        'email' => ___('Transactional email delivery'),
        'messaging' => ___('SMS and WhatsApp messaging'),
        'ai' => ___('AI models'),
        'maps' => ___('Maps, geocoding and routing'),
        'monitoring' => ___('Error and performance monitoring'),
        'support' => ___('Support desk and live chat'),
        'analytics' => ___('Product analytics'),
        'push' => ___('Push notifications'),
        'logging' => ___('Logging and uptime monitoring'),
    ];

    // The four product promises: label + explanation for each {{VERIFY:key}} switch.
    $__verifyMeta = [
        'ai_no_training' => [___('No training on customer data'), ___('The AI providers we use do not train on customer data')],
        'encryption_at_rest' => [___('Encryption at rest'), ___('Customer data is encrypted at rest on the host')],
        'auth_methods' => [___('Sign-in methods'), ___('Every sign-in method listed in the Security page exists at launch')],
        'backups' => [___('Backups'), ___('Daily backups are kept for 30 days and a restore has been tested')],
    ];
@endphp

<form method="POST" action="{{ route('admin.settings.legal.update') }}">
    @csrf

    {{-- Live status: how many of the published legal pages' blanks are filled. --}}
    <x-ui.card :title="___('Status')">
        <div class="ui-cluster">
            <x-ui.stat :value="___(':filled of :total blanks filled', ['filled' => $status['filled'], 'total' => $status['total']])" :label="___('Blanks filled')" icon="clipboard-list"
                       :tone="$status['ready'] ? 'success' : 'warning'" />
            <x-ui.tag :tone="$status['ready'] ? 'success' : 'warning'">
                {{ $status['ready'] ? ___('Ready to publish') : ___('Not ready to publish') }}
            </x-ui.tag>
        </div>

        @php
            $__pending = [];
            foreach ($status['pages'] as $__pk => $__page) {
                if ($__page['remaining'] > 0) {
                    $__pending[$__pk] = $__page;
                }
            }
        @endphp

        @if ($__pending !== [])
            <p class="ui-section-title">{{ ___('Pages still needing attention') }}</p>
            <div class="ui-stack">
                @foreach ($__pending as $__doc => $__page)
                    <div class="ui-row">
                        <span class="ui-row__body">
                            <span class="ui-row__title">{{ \Juwri\Core\Legal\LegalDocuments::has($__doc) ? \Juwri\Core\Legal\LegalDocuments::label($__doc) : $__doc }}</span>
                            <span class="ui-row__sub">{{ ___(':count remaining', ['count' => $__page['remaining']]) }}</span>
                        </span>
                        <span class="ui-row__trail">
                            <a href="{{ route(\Juwri\Core\Legal\LegalDocuments::routeName($__doc)) }}?lang=en" target="_blank" rel="noopener">EN</a>
                            <a href="{{ route(\Juwri\Core\Legal\LegalDocuments::routeName($__doc)) }}?lang=ar" target="_blank" rel="noopener">AR</a>
                        </span>
                    </div>
                @endforeach
            </div>
        @endif

        <p class="ui-section-title">{{ ___('Preview') }}</p>
        <div class="ui-cluster">
            @foreach (array_keys(\Juwri\Core\Legal\LegalDocuments::all()) as $__doc)
                <span class="ui-pill" data-tone="neutral">
                    {{ \Juwri\Core\Legal\LegalDocuments::label($__doc) }}
                    <a href="{{ route(\Juwri\Core\Legal\LegalDocuments::routeName($__doc)) }}?lang=en" target="_blank" rel="noopener">EN</a>
                    <a href="{{ route(\Juwri\Core\Legal\LegalDocuments::routeName($__doc)) }}?lang=ar" target="_blank" rel="noopener">AR</a>
                </span>
            @endforeach
        </div>
    </x-ui.card>

    {{-- Slot for the "Obligations checklist" card (follow-up lane): push into 'legal_obligations' from that card's view
         or replace this stack with an @include. Keep it here, between the status box and the field groups. --}}
    @stack('legal_obligations')

    @foreach ($groups as $__group)
        @php
            $__verifyFields = [];
            $__fillFields = [];
            foreach ($__group['fields'] as $__gf) {
                if (($__gf['kind'] ?? 'fill') === 'verify') {
                    $__verifyFields[] = $__gf;
                } else {
                    $__fillFields[] = $__gf;
                }
            }
            $__plain = [];
            $__subs = [];
            foreach ($__fillFields as $__field) {
                if (! empty($__field['unusable'])) {
                    $__subs[$__field['unusable']][] = $__field;
                } else {
                    $__plain[] = $__field;
                }
            }
        @endphp
        <x-ui.card :title="___($__group['title'])">
            <div class="ui-stack">
                @if ($__verifyFields !== [])
                    @foreach ($__verifyFields as $__field)
                        @php
                            [$__vlabel, $__vdesc] = $__verifyMeta[$__field['key']] ?? [___($__field['key']), ''];
                            $__vk = $__field['key'];
                        @endphp
                        <x-ui.switch :name="'verify['.$__vk.']'" off="0" :checked="$__verify[$__vk] ?? false" :label="$__vlabel" :description="$__vdesc" />
                    @endforeach
                @endif

                @if ($__plain !== [])
                    <div class="ui-form-grid">
                        @foreach ($__plain as $__field)
                            @php
                                [$__label, $__help] = $__fillMeta[$__field['key']] ?? [___($__field['key']), ''];
                                $__k = $__field['key'];
                                $__type = in_array($__field['input'] ?? 'text', ['text', 'url', 'textarea'], true) ? $__field['input'] : 'text';
                            @endphp
                            <x-ui.field :type="$__type" :name="'fill['.$__k.']'" :label="$__label" :help="$__help" :value="$__fill[$__k] ?? ''" />
                        @endforeach
                    </div>
                @endif

                @foreach ($__subs as $__x => $__fields)
                    @php
                        $__vendorField = null;
                        $__locField = null;
                        foreach ($__fields as $__field) {
                            if (str_starts_with((string) $__field['key'], 'vendor_')) {
                                $__vendorField = $__field;
                            } else {
                                $__locField = $__field;
                            }
                        }
                    @endphp
                    <p class="ui-section-title">{{ $__subName[$__x] ?? ___($__x) }}</p>
                    <div class="ui-form-grid">
                        @if ($__vendorField)
                            <x-ui.field :name="'fill['.$__vendorField['key'].']'" :label="___('Vendor')" :value="$__fill[$__vendorField['key']] ?? ''" data-legal-field="{{ $__x }}" />
                        @endif
                        @if ($__locField)
                            <x-ui.field :name="'fill['.$__locField['key'].']'" :label="___('Processing location')" :value="$__fill[$__locField['key']] ?? ''" data-legal-field="{{ $__x }}" />
                        @endif
                    </div>
                    <x-ui.switch :name="'unused['.$__x.']'" off="0" :checked="$__unused[$__x] ?? false" :label="___('Not used')"
                                 :description="___('Removes this row from the English and Arabic Sub-processors page')" data-legal-unused="{{ $__x }}" />
                @endforeach
            </div>
        </x-ui.card>
    @endforeach

    <p class="ui-small ui-text-2">{{ ___('Values saved here override the LEGAL_* values in .env. Leave a box empty to use the .env value.') }}</p>
    <div class="ui-cluster">
        <x-ui.button type="submit" icon="check">{{ ___('Save Changes') }}</x-ui.button>
    </div>
</form>

<script nonce="{{ csp_nonce() }}">
(function () {
    document.querySelectorAll('input[type="checkbox"][data-legal-unused]').forEach(function (sw) {
        var x = sw.getAttribute('data-legal-unused');
        var fields = document.querySelectorAll('input[data-legal-field="' + x + '"], textarea[data-legal-field="' + x + '"]');
        var sync = function () {
            fields.forEach(function (field) {
                field.disabled = sw.checked;
            });
        };
        sw.addEventListener('change', sync);
        sync();
    });
})();
</script>
@endsection
