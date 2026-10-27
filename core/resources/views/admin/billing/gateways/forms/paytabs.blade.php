{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- PayTabs account regions (the adapter maps each to its own API host). --}}
<x-ui.field type="select" name="paytabs_region" :label="___('Paytabs Region').' *'" :value="$settings->paytabs_region ?? null" :options="[
    'ARE' => 'United Arab Emirates', 'SAU' => 'Saudi Arabia', 'OMN' => 'Oman', 'JOR' => 'Jordan',
    'EGY' => 'Egypt', 'IRQ' => 'Iraq', 'PSE' => 'Palestine', 'GLOBAL' => 'Global',
]" />
<x-ui.field name="paytabs_profile_id" :label="___('Paytabs Profile id').' *'" :value="$settings->paytabs_profile_id ?? null" autocomplete="off" />
<x-ui.field name="paytabs_secret_key" :label="___('Paytabs Server Key').' *'" :value="$settings->paytabs_secret_key ?? null" autocomplete="off" />
