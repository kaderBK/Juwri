{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<section id="quick_international" role="tabpanel" aria-labelledby="tab-quick_international" @if (($active ?? true) === false) hidden @endif>
    <form method="POST" action="{{ route('admin.settings.update') }}" data-ui-ajax>
        <x-ui.card :title="___('International')">
            <div class="ui-stack">
                <div class="ui-form-grid">
                    <x-ui.field type="select" name="lang" :label="___('Set Default language').' *'" :value="env('DEFAULT_LANGUAGE')"
                                :options="collect($admin_languages ?? [])->pluck('name', 'code')->all()" />
                    <x-ui.field type="select" name="timezone" :label="___('Timezone').' *'" :value="$settings->timezone ?? null" :options="(array) config('timezones')" />
                </div>
                <p class="ui-section-title">{{ ___('Currency') }}</p>
                <div class="ui-form-grid">
                    <x-ui.field name="currency_code" id="currency_code" :label="___('Currency Code').' *'" :value="$settings->currency_code ?? null" placeholder="USD" required />
                    <div class="ui-stack">
                        <x-ui.field name="currency_sign" id="currency_sign" :label="___('Currency Symbol').' *'" :value="$settings->currency_sign ?? null" placeholder="$" required />
                        {{-- S4: one-click fill of the official new symbol (Saudi Riyal U+20C1 / UAE Dirham U+20C3);
                             shown by the autofill script only when the currency owns one. --}}
                        <button type="button" id="mb-use-riyal-symbol" class="ui-btn ui-btn--secondary ui-btn--sm" style="display:none">
                            {{ ___('Use new symbol') }} <span class="mb-use-sym-glyph">&#x20C1;</span>
                        </button>
                        @include('partials.currency-autofill')
                    </div>
                    <x-ui.field type="select" name="currency_pos" :label="___('Currency position').' *'" :value="(string) ($settings->currency_pos ?? '1')"
                                :options="['1' => ___('Before price'), '0' => ___('After price')]" />
                </div>
            </div>
            @include('admin.settings._save', ['marker' => 'international_setting'])
        </x-ui.card>
    </form>
</section>
