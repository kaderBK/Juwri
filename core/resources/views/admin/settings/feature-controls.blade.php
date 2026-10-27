{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    $__registry = collect(\App\Services\FeatureRegistry::all())->groupBy('group');
    $__groups = [
        'run1' => ___('Core Features'),
        'run2' => ___('Advanced Features'),
        'systems' => ___('Business Systems'),
        'tax' => ___('Fiscalization & Tax'),
        'rpages' => ___('Reseller Site Builder'),
        'payments' => ___('Payments'),
        'hotel' => ___('Hotel Guest Services'),
        'dashux' => ___('Dashboard Assistant'),
        'directory' => ___('Business Directory'),
        'parity' => ___('Ordering & Operations'),
        'monetization' => ___('Service & Growth Modules'),
    ];
    $__spotHelp = ___('Off silences every surface at once: nav lock prices, upgrade teasers, "included in your plan" ribbons and trial countdowns. Nothing else about entitlement changes.');
@endphp
<section id="quick_feature_controls" role="tabpanel" aria-labelledby="tab-quick_feature_controls" @if (($active ?? true) === false) hidden @endif>
    <form method="POST" action="{{ route('admin.settings.update') }}" data-ui-ajax>
        <x-ui.card :title="___('Feature Controls')">
            <div class="ui-stack">
                <p class="ui-text-2">{{ ___('Global kill-switches. Turning a feature off here disables it for every restaurant, overriding any plan entitlement.') }}</p>

                @foreach ($__groups as $__group => $__groupLabel)
                    @continue(! $__registry->has($__group))
                    <p class="ui-section-title">{{ $__groupLabel }}</p>
                    <div class="ui-form-grid">
                        @foreach ($__registry->get($__group) as $__feature)
                            @php
                                $__key = 'admin_allow_'.$__feature['key'];
                                $__on = isset($settings->{$__key}) ? $settings->{$__key} == '1' : (bool) $__feature['default'];
                                $__plans = \App\Services\FeatureRegistry::grantingPlans($__feature['key']);
                                $__desc = ___($__feature['description']).(! empty($__plans) ? ' · '.___('Sold on').': '.implode(', ', $__plans) : '');
                            @endphp
                            <x-ui.switch :name="$__key" off="0" :checked="$__on" :label="___($__feature['label'])" :description="$__desc" />
                        @endforeach
                        @if ($__group === 'rpages')
                            <x-ui.field type="number" name="reseller_pages_max" :label="___('Max pages per reseller')" min="1" max="999"
                                        :value="$settings->reseller_pages_max ?? app(\Juwri\Core\Contracts\Content\ResellerContent::class)->pageCapDefaults()['pages']" />
                            <x-ui.field type="number" name="reseller_blog_posts_max" :label="___('Max blog posts per reseller')" min="1" max="9999"
                                        :value="$settings->reseller_blog_posts_max ?? app(\Juwri\Core\Contracts\Content\ResellerContent::class)->pageCapDefaults()['blogPosts']" />
                        @endif
                    </div>
                @endforeach

                {{-- WI-F5: one switch for every spotlight surface, beside the other kill-switches. --}}
                <p class="ui-section-title">{{ ___('Feature Spotlight') }}</p>
                <p class="ui-small ui-text-2">{{ ___('Tells merchants what a locked feature costs, and what they already own but have never switched on.') }}</p>
                <x-ui.switch name="admin_allow_feature_spotlight" off="0" :checked="($settings->admin_allow_feature_spotlight ?? '1') == '1'" :label="___('Show feature spotlight')"
                             :description="$__spotHelp" />

                {{-- H15: which code places storefront orders; switching back to Legacy is an instant rollback. --}}
                <p class="ui-section-title">{{ ___('Storefront order pipeline') }}</p>
                <p class="ui-small ui-text-2">{{ ___('Which code places storefront orders. Switch back to Legacy at any time — it takes effect on the next order, with no deploy.') }}</p>
                <div class="ui-form-grid">
                    <x-ui.field type="select" name="storefront_order_pipeline" :label="___('Pipeline')" :value="$settings->storefront_order_pipeline ?? 'legacy'" :options="[
                        'legacy' => ___('Legacy — the storefront places its own orders'),
                        'unified' => ___('Unified — the storefront uses the shared order service'),
                    ]" />
                    <x-ui.field name="storefront_order_pipeline_stores" :label="___('Canary stores (ids, comma separated)')" :value="$settings->storefront_order_pipeline_stores ?? ''"
                                :help="___('These stores use the unified pipeline even while the platform is on Legacy.')" />
                </div>
                <p class="ui-small ui-text-2">{{ ___('Storefront orders today — unified: :u · legacy: :l', ['u' => (int) \Cache::get('sf_pipeline:unified:'.now()->format('Ymd'), 0), 'l' => (int) \Cache::get('sf_pipeline:legacy:'.now()->format('Ymd'), 0)]) }}</p>

                {{-- P3-03: platform level of the offline order queue. Effective = platform AND reseller AND store: any level off is off. --}}
                <p class="ui-section-title">{{ ___('Offline order queue') }}</p>
                <div class="ui-form-grid">
                    <x-ui.switch name="offline_queue_enabled" off="0" :checked="($settings->offline_queue_enabled ?? '1') != '0'" :label="___('Let storefronts queue orders while offline')"
                                 :description="___('Turn it off to stop it for every store. Waiting orders on customers\' phones are removed with a notice the next time they open the store. Browsing and the cart offline stay on.')" />
                </div>

                {{-- WA-S2: platform defaults for the post-checkout WhatsApp hand-off, plus a kill-switch. --}}
                <p class="ui-section-title">{{ ___('WhatsApp Hand-off') }}</p>
                <p class="ui-small ui-text-2">{{ ___('Defaults for stores that have not set their own. A store setting always wins — except the kill-switch, which wins over everything.') }}</p>
                <div class="ui-form-grid">
                    <x-ui.switch name="whatsapp_handoff_kill" off="0" :checked="($settings->whatsapp_handoff_kill ?? '0') == '1'" :label="___('Disable the WhatsApp hand-off everywhere')"
                                 :description="___('Emergency stop. Orders are still placed and still appear in the dashboard — they are simply not handed over to WhatsApp.')" />
                    <x-ui.field type="select" name="whatsapp_handoff_mode" :label="___('After checkout')" :value="$settings->whatsapp_handoff_mode ?? 'auto'" :options="[
                        'auto' => ___('Open WhatsApp automatically'),
                        'button' => ___('Show a \'Send on WhatsApp\' button'),
                        'none' => ___('Do nothing — the order is placed silently'),
                    ]" />
                    <x-ui.field type="select" name="whatsapp_handoff_target" :label="___('Open in')" :value="$settings->whatsapp_handoff_target ?? 'new'"
                                :options="['new' => ___('A new tab'), 'same' => ___('The same tab')]" />
                    <x-ui.field type="number" name="whatsapp_handoff_delay" :label="___('Delay before opening (seconds)')" :value="$settings->whatsapp_handoff_delay ?? '0'" step="0.5" min="0" max="5" />
                    <x-ui.field type="select" name="whatsapp_handoff_route" :label="___('Send the order to')" :value="$settings->whatsapp_handoff_route ?? 'branch'" :options="[
                        'branch' => ___('The branch that took the order'),
                        'fixed' => ___('Always the default branch'),
                    ]" />
                </div>
            </div>
            {{-- Saved as part of the general section; site_title rides along because that section requires it. --}}
            <input type="hidden" name="site_title" value="{{ $settings->site_title ?? '' }}">
            @include('admin.settings._save', ['marker' => 'general_setting'])
        </x-ui.card>
    </form>
</section>
