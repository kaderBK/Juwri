{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    $__s = $settings;
    $__otpHelp = ___('When on, the storefront "find my orders" lookup only returns orders after the diner verifies their phone with an SMS code this session.');
@endphp
<section id="quick_settings_general" role="tabpanel" aria-labelledby="tab-quick_settings_general" @if (($active ?? true) === false) hidden @endif>
    <form method="POST" action="{{ route('admin.settings.update') }}" data-ui-ajax>
        <x-ui.card :title="___('General')">
            <div class="ui-stack">
                <p class="ui-section-title">{{ ___('Site & SEO') }}</p>
                <x-ui.field name="site_title" :label="___('Site Title').' *'" :value="$__s->site_title ?? null" required
                            :help="___('The site title is what you would like your website to be known as, this will be used in emails and in the title of your webpages.')" />
                <div class="ui-form-grid">
                    <x-ui.field type="textarea" name="meta_keywords" :label="___('Meta Keywords').' *'" :value="$__s->meta_keywords ?? null" required />
                    <x-ui.field type="textarea" name="meta_description" :label="___('Meta Description').' *'" :value="$__s->meta_description ?? null" required />
                </div>

                <p class="ui-section-title">{{ ___('Platform Options') }}</p>
                <div class="ui-form-grid">
                    <x-ui.switch name="admin_allergies" off="0" :checked="($__s->admin_allergies ?? '') == '1'" :label="___('Allergies')" />
                    <x-ui.switch name="admin_send_order_notification" off="0" :checked="($__s->admin_send_order_notification ?? '') == '1'" :label="___('Send New Order Notification to Restaurants')" />
                    <x-ui.switch name="admin_allow_online_payment" off="0" :checked="($__s->admin_allow_online_payment ?? '') == '1'" :label="___('Allow online payment for stores')" />
                    <x-ui.switch name="store_text_editor" off="0" :checked="($__s->store_text_editor ?? '') == '1'" :label="___('Allow Text Editor For Restaurant Description')" />
                    <x-ui.field type="select" name="default_user_plan" :label="___('Default Membership Plan for New Users').' *'" :value="$__s->default_user_plan ?? 'free'"
                                :options="['free' => ___('Free'), 'trial' => ___('Trial')]" />
                    <x-ui.switch name="non_active_msg" off="0" :checked="($__s->non_active_msg ?? '') == '1'" :label="___('Show/hide Verify Email Message')"
                                 :description="___('When disallow, An verify email message will be hide to non-verified users.')" />
                    <x-ui.switch name="non_active_allow" off="0" :checked="($__s->non_active_allow ?? '') == '1'" :label="___('Allow non verified users to publish content')"
                                 :description="___('When disallow, An error message will be shown to non-verified users to verify their email address.')" />
                    <x-ui.switch name="userlangsel" off="0" :checked="($__s->userlangsel ?? '') == '1'" :label="___('Allow User Language Selection')" />
                    <x-ui.switch name="disable_landing_page" off="0" :checked="($__s->disable_landing_page ?? '') == '1'" :label="___('Disable Landing Page')" />
                    <x-ui.switch name="enable_user_registration" off="0" :checked="(bool) ($__s->enable_user_registration ?? 1)" :label="___('New Users Registration')" />
                    <x-ui.switch name="enable_force_ssl" off="0" :checked="($__s->enable_force_ssl ?? '') == '1'" :label="___('Force SSL')" />
                    <x-ui.switch name="include_language_code" off="0" :checked="($__s->include_language_code ?? '') == '1'" :label="___('Include Language Code in URL')" />
                </div>

                <p class="ui-section-title">{{ ___('Homepage & Legal') }}</p>
                <div class="ui-form-grid">
                    <x-ui.switch name="enable_faqs" off="0" :checked="($__s->enable_faqs ?? '') == '1'" :label="___('FAQs')" />
                    <x-ui.switch name="show_membershipplan_home" off="0" :checked="($__s->show_membershipplan_home ?? '') == '1'" :label="___('Show membership plan on home page')" />
                    {{-- Default ON. Home page only; /pricing always shows plans. --}}
                    <x-ui.switch name="pricing_show_on_home" off="0" :checked="($__s->pricing_show_on_home ?? null) != '0'" :label="___('Show pricing on home page')"
                                 :description="___('When on, the pricing plans section appears on the home page. Turn off to hide it there. The dedicated /pricing page always shows plans.')" />
                    {{-- PRICING-REDESIGN B5: default ON. --}}
                    <x-ui.switch name="pricing_compare_on_home" off="0" :checked="($__s->pricing_compare_on_home ?? null) != '0'" :label="___('Show pricing comparison table on home page')"
                                 :description="___('When on, the grouped feature-comparison table appears below the pricing cards on the home page. The dedicated /pricing page always shows it.')" />
                    <x-ui.switch name="pricing_free_trial_cards" off="0" :checked="($__s->pricing_free_trial_cards ?? null) != '0'" :label="___('Show Free & Trial tiers on the pricing page')"
                                 :description="___('When on, the Free Package and Try-For-7-Days tiers appear as cards on the pricing page (and the home pricing section). Turn off to show paid plans only.')" />
                    <x-ui.switch name="feature_matrix_grouped" off="0" :checked="($__s->feature_matrix_grouped ?? null) != '0'" :label="___('Group the plan Feature Matrix into sections')"
                                 :description="___('When on, the admin plan Feature Matrix is organised into labelled sections (Core, Storefront & ordering, Operations, …) instead of one long list.')" />
                    <x-ui.switch name="show_partner_logo_home" off="0" :checked="($__s->show_partner_logo_home ?? '') == '1'" :label="___('Show partners slider on home page')" />
                    <x-ui.switch name="cookie_consent" off="0" :checked="($__s->cookie_consent ?? '') == '1'" :label="___('Show/hide Cookie Consent Box')" />
                    <x-ui.field name="termcondition_link" :label="___('Term & Condition Page Link').' *'" :value="$__s->termcondition_link ?? null" required />
                    <x-ui.field name="privacy_link" :label="___('Privacy Page Link').' *'" :value="$__s->privacy_link ?? null" required />
                    <x-ui.field name="cookie_link" :label="___('Cookie Policy Page Link')" :value="$__s->cookie_link ?? null" />
                    {{-- Marketing site: optional WhatsApp support link (digits; never shown on a reseller host). --}}
                    <x-ui.field type="tel" name="support_whatsapp" :label="___('Support WhatsApp number')" :value="$__s->support_whatsapp ?? null" inputmode="tel" dir="ltr"
                                placeholder="966500000000" :help="___('Shown as a WhatsApp support link on the marketing site. International format, digits only. Leave empty to hide it.')" />
                </div>

                <p class="ui-section-title">{{ ___('Operations') }}</p>
                <div class="ui-form-grid">
                    {{-- WI-R1: superadmin backup-import caps; blank = the shipped default. --}}
                    <x-ui.field type="number" name="admin_import_max_entries" :label="___('Backup import: max files per archive')" :value="$__s->admin_import_max_entries ?? null" min="1"
                                :placeholder="\Juwri\Core\Support\BackupZipValidator::DEFAULT_MAX_ENTRIES" />
                    <x-ui.field type="number" name="admin_import_max_uncompressed_mb" :label="___('Backup import: max uncompressed size (MB)')" :value="$__s->admin_import_max_uncompressed_mb ?? null" min="1"
                                :placeholder="\Juwri\Core\Support\BackupZipValidator::DEFAULT_MAX_UNCOMPRESSED_MB" />
                    {{-- F6: the third-party WATI widget; the built-in wa.me hand-off is unaffected. --}}
                    <x-ui.switch name="storefront_wati_widget" off="0" :checked="($__s->storefront_wati_widget ?? '0') === '1'" :label="___('Third-party WhatsApp chat widget on storefronts')"
                                 :description="___('Loads the external WATI (clare.ai) chat bubble on menu pages. Turn off to remove the third-party script; the built-in WhatsApp order hand-off keeps working.')" />
                    {{-- S4: default OFF. --}}
                    <x-ui.switch name="order_lookup_require_otp" off="0" :checked="($__s->order_lookup_require_otp ?? '0') === '1'" :label="___('Require phone OTP for guest order lookup')"
                                 :description="$__otpHelp" />
                    {{-- WI-M8: default ON. --}}
                    <x-ui.switch name="api_public_menu_gate" off="0" :checked="($__s->api_public_menu_gate ?? '1') !== '0'" :label="___('Gate the public menu API on account and plan status')"
                                 :description="___('When on, the anonymous /api/v1/menu endpoint returns 404 for a suspended merchant or an inactive plan, matching the web storefront. Turn off only to restore service during an incident.')" />
                    {{-- C2/P6-97: default ON in production, OFF elsewhere (an unsaved setting must not save as off). --}}
                    <x-ui.switch name="platform_backup_restore_check_enabled" off="0" :checked="($__s->platform_backup_restore_check_enabled ?? (config('app.env') === 'production' ? '1' : '0')) === '1'" :label="___('Weekly backup restore drill')"
                                 :description="___('Once a week, restore the newest platform database backup into a throwaway schema and verify it. Requires the database user to have CREATE and DROP DATABASE permission.')" />
                    {{-- M7: default OFF. --}}
                    <x-ui.switch name="platform_media_backup_enabled" off="0" :checked="($__s->platform_media_backup_enabled ?? '0') === '1'" :label="___('Mirror media off-box nightly')"
                                 :description="___('Copy every uploaded image and document to the configured S3 bucket each night. Without this, the database backup restores a platform whose images are all missing.')" />
                    {{-- WI-C3: default OFF. --}}
                    <x-ui.switch name="admin_backup_registry_engine" off="0" :checked="($__s->admin_backup_registry_engine ?? '0') === '1'" :label="___('Registry-driven superadmin backup engine')"
                                 :description="___('When on, a full user export covers every store table the platform knows about and a restore puts all of them back. Leave off until you have re-exported the users you may need to restore.')" />
                    {{-- C4: default ON. --}}
                    <x-ui.switch name="storefront_public_cache" off="0" :checked="($__s->storefront_public_cache ?? '1') !== '0'" :label="___('Public storefront caching')"
                                 :description="___('When on, anonymous menu pages send Cache-Control: public with an ETag so browsers and CDNs can reuse them. Turn off if a proxy in front of the site is serving one visitor a page rendered for another.')" />
                    {{-- rev/external-redirect C3: default ON (kill-switch). --}}
                    <x-ui.switch name="external_redirect_global" off="0" :checked="($__s->external_redirect_global ?? '1') !== '0'" :label="___('External redirect (platform-wide)')"
                                 :description="___('When on, stores that opted out of the platform can redirect their public menu to an external URL. Turn OFF to suppress every store\'s redirect at once (an emergency kill-switch during migration).')" />
                    {{-- H7: default OFF. --}}
                    <x-ui.switch name="healthz_queue_backlog_fails" off="0" :checked="($__s->healthz_queue_backlog_fails ?? '0') === '1'" :label="___('Sustained queue backlog fails /healthz')"
                                 :description="___('When on, /healthz returns 503 if the oldest queued job is older than the backlog threshold. Leave off if /healthz is wired to a load balancer — a 503 would remove the web node from rotation.')" />
                    {{-- H8: default ON. --}}
                    <x-ui.switch name="admin_env_health_alert" off="0" :checked="($__s->admin_env_health_alert ?? '1') === '1'" :label="___('Alert on environment file problems')"
                                 :description="___('Adds a platform alert when the .env file has a leaked, duplicated or unparseable key. The System Info panel stays visible either way.')" />
                    {{-- H3: debug is a deploy-time .env setting, never web-editable. --}}
                </div>
            </div>
            @include('admin.settings._save', ['marker' => 'general_setting'])
        </x-ui.card>
    </form>
</section>
