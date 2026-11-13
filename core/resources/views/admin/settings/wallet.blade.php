{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<section id="quick_wallet" role="tabpanel" aria-labelledby="tab-quick_wallet" @if (($active ?? true) === false) hidden @endif>
    <form method="POST" action="{{ route('admin.settings.update') }}" data-ui-ajax>
        <x-ui.card :title="___('Wallet Top-up')">
            <div class="ui-stack">
                <x-ui.field type="select" name="wallet_topup_enabled" :label="___('Self-service top-up').' *'" :value="(string) ($settings->wallet_topup_enabled ?? '1')"
                            :options="['1' => ___('Enabled'), '0' => ___('Disabled')]" :help="___('Let users add funds to their own wallet from the wallet page.')" />
                <div class="ui-form-grid">
                    <x-ui.field type="number" name="wallet_topup_min" :label="___('Minimum amount').' *'" :value="$settings->wallet_topup_min ?? '5'" step="0.01" min="0.5" placeholder="5" required />
                    <x-ui.field type="number" name="wallet_topup_max" :label="___('Maximum amount').' *'" :value="$settings->wallet_topup_max ?? '1000'" step="0.01" min="0.5" placeholder="1000" required />
                </div>
                <x-ui.field name="wallet_topup_presets" :label="___('Quick-pick amounts')" :value="$settings->wallet_topup_presets ?? '10,25,50,100'" placeholder="10,25,50,100"
                            :help="___('Comma-separated amounts shown as buttons. Values outside the min/max range are hidden.')" />
            </div>
            @include('admin.settings._save', ['marker' => 'wallet_topup_settings'])
        </x-ui.card>
    </form>
</section>
