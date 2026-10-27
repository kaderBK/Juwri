<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Settings;

/**
 * P1-07: the typed store settings (formerly `store_options` rows) — legacy name => [path inside
 * `stores.settings`, type].
 *
 * Generated once from the frozen legacy settings map; a new setting is added here by hand with its type.
 * Payment gateway names live in `store_payment_gateways`; any other name that is not listed here is
 * stored as given under `custom.<name>` and read back as a string.
 *
 * Phase 3 F2: the generator typed a few numeric settings 'bool' (their sample values were 0/1):
 * scheduling_slot_capacity, reservation_deposit_min_party, gift_card_expiry_months, currency_pos are 'int';
 * the decimal amounts restaurant_taxx_charge, reservation_deposit_amount, min_order_value,
 * whatsapp_handoff_delay are 'string' (exact text; there is no decimal type). Values stored before the fix as
 * JSON true/false keep their legacy form '1'/'0' and StoreSettings::int() reads them as 1/0, so no data changes.
 */
final class StoreSettingKeys
{
    /** @var array<string, array{0: string, 1: 'bool'|'int'|'array'|'string'}> */
    public const KEYS = [
        'accept_voice_notes' => ['accept.voice_notes', 'bool'],
        'agent_wallet_spend' => ['agent.wallet_spend', 'bool'],
        'allow_auto_popup' => ['allow.auto_popup', 'bool'],
        'allow_auto_user_info' => ['allow.auto_user_info', 'bool'],
        'allow_call_waiter' => ['allow.call_waiter', 'bool'],
        'allow_coupons' => ['allow.coupons', 'bool'],
        'allow_dietary_filters' => ['allow.dietary_filters', 'bool'],
        'allow_multi_branch_switch' => ['allow.multi_branch_switch', 'bool'],
        'allow_order_scheduling' => ['allow.order_scheduling', 'bool'],
        'allow_slider_popup' => ['allow.slider_popup', 'bool'],
        'allow_tips' => ['allow.tips', 'bool'],
        'bio_migrated' => ['bio.migrated', 'bool'],
        'birthday_channel' => ['birthday.channel', 'string'],
        'birthday_offers_enabled' => ['birthday.offers_enabled', 'bool'],
        'birthday_reward_percentage' => ['birthday.reward_percentage', 'int'],
        'birthday_validity_days' => ['birthday.validity_days', 'int'],
        'back_in_stock_alerts' => ['back_in_stock.alerts', 'bool'], // P3-08 / N5: back-in-stock requests (on by default)
        'booking_verify_guest_phone' => ['booking.verify_guest_phone', 'bool'], // P3-06b API-7 ("Verify guest phone", off)
        // P3-10 Bookings (read through Juwri\Modules\Bookings\Services\BookingSettings, which holds the defaults).
        'booking_at_home' => ['booking.at_home', 'bool'],
        'booking_at_home_radius_km' => ['booking.at_home_radius_km', 'int'],
        'booking_base_lat' => ['booking.base_lat', 'string'],
        'booking_base_lng' => ['booking.base_lng', 'string'],
        'booking_cancel_window_hours' => ['booking.cancel_window_hours', 'int'],
        'booking_deposit_refunds' => ['booking.deposit_refunds', 'bool'], // D7/D8 "deposits" refund switch, off
        'booking_lead_minutes' => ['booking.lead_minutes', 'int'],
        'booking_max_days' => ['booking.max_days', 'int'],
        'booking_no_show_fee' => ['booking.no_show_fee', 'bool'], // D8, off
        'booking_no_show_fee_minor' => ['booking.no_show_fee_minor', 'int'],
        'booking_policy_note' => ['booking.policy_note', 'string'],
        'booking_reminder_email' => ['booking.reminder_email', 'bool'],
        'booking_reminder_sms' => ['booking.reminder_sms', 'bool'],
        'booking_reminder_whatsapp' => ['booking.reminder_whatsapp', 'bool'],
        'booking_slot_interval' => ['booking.slot_interval', 'int'],
        'booking_staff_choice' => ['booking.staff_choice', 'bool'],
        'booking_travel_buffer_min' => ['booking.travel_buffer_min', 'int'],
        'curbside_enabled' => ['curbside.enabled', 'bool'],
        'currency_code' => ['core.currency_code', 'string'],
        'currency_pos' => ['core.currency_pos', 'int'],
        'currency_rates_manual' => ['currency.rates_manual', 'string'],
        'currency_rates_mode' => ['currency.rates_mode', 'string'],
        'currency_rtl_auto' => ['currency.rtl_auto', 'bool'],
        'currency_sign' => ['currency.sign', 'string'],
        'currency_sign_pre_dirham' => ['currency.sign_pre_dirham', 'string'],
        'currency_sign_pre_riyal' => ['currency.sign_pre_riyal', 'string'],
        'customer_auth_mode' => ['customer.auth_mode', 'string'],
        'customer_cart_email' => ['customer.cart_email', 'bool'],
        'customer_cart_sms' => ['customer.cart_sms', 'bool'],
        'customer_cart_whatsapp' => ['customer.cart_whatsapp', 'bool'],
        'customer_order_sms' => ['customer.order_sms', 'bool'],
        'customer_order_whatsapp' => ['customer.order_whatsapp', 'bool'],
        'customer_status_sms' => ['customer.status_sms', 'bool'],
        'customer_status_whatsapp' => ['customer.status_whatsapp', 'bool'],
        'default_language' => ['core.default_language', 'string'],
        'demo_showcase' => ['demo.showcase', 'bool'],
        'dispatch_mode' => ['dispatch.mode', 'string'],
        'display_currencies' => ['display.currencies', 'string'],
        'enable_multi_currency' => ['enable.multi_currency', 'bool'],
        'enable_reservations' => ['enable.reservations', 'bool'],
        'enable_waitlist' => ['enable.waitlist', 'bool'],
        'facebook_url' => ['facebook.url', 'string'],
        'fiscal_country' => ['fiscal.country', 'string'],
        'fiscal_cr_number' => ['core.fiscal_cr_number', 'string'],
        'fiscal_legal_name' => ['fiscal.legal_name', 'string'],
        'fiscal_vat_number' => ['core.fiscal_vat_number', 'string'],
        // P6-176: KSA seller national address for ZATCA standard (B2B) invoices
        'fiscal_street' => ['fiscal.street', 'string'],
        'fiscal_building_number' => ['fiscal.building_number', 'string'],
        'fiscal_additional_number' => ['fiscal.additional_number', 'string'],
        'fiscal_district' => ['fiscal.district', 'string'],
        'fiscal_city' => ['fiscal.city', 'string'],
        'fiscal_postal_code' => ['fiscal.postal_code', 'string'],
        'follower_notify_enabled' => ['follower.notify_enabled', 'bool'],
        'fulfillment_enabled_modes' => ['fulfillment.enabled_modes', 'array'],
        'gift_card_expiry_months' => ['gift.card_expiry_months', 'int'],
        'grocery_weighed_cap_percent' => ['grocery.weighed_cap_percent', 'int'], // P3-09: weighed lines may cost up to this % over the estimate (default 10)
        'gift_card_max' => ['gift.card_max', 'int'],
        'gift_card_min' => ['gift.card_min', 'int'],
        'gift_card_presets' => ['gift.card_presets', 'string'],
        'gift_cards_enabled' => ['gift.cards_enabled', 'bool'],
        'google_review_url' => ['google.review_url', 'string'],
        'google_wallet_enabled' => ['google.wallet_enabled', 'bool'],
        'holds_release_during_payment' => ['holds.release_during_payment', 'bool'], // P3-06b API-24a/26 (off)
        'hotel_mode' => ['hotel.mode', 'bool'],
        'hotel_online_booking' => ['hotel.online_booking', 'string'],
        'hotel_room_pres' => ['hotel.room_pres', 'array'],
        'instagram_url' => ['instagram.url', 'string'],
        'item_reviews_enabled' => ['item.reviews_enabled', 'bool'],
        'kiosk_auto_print' => ['kiosk.auto_print', 'bool'],
        'kiosk_enabled' => ['kiosk.enabled', 'bool'],
        'kiosk_exit_pin' => ['kiosk.exit_pin', 'int'],
        'kiosk_idle_seconds' => ['kiosk.idle_seconds', 'int'],
        'kiosk_open_drawer' => ['kiosk.open_drawer', 'bool'],
        'kiosk_printer_width' => ['kiosk.printer_width', 'int'],
        'kiosk_setup_pin' => ['kiosk.setup_pin', 'int'],
        'low_stock_digest_state' => ['low.stock_digest_state', 'array'],
        'loyalty_teaser' => ['loyalty.teaser', 'array'],
        'meal_plans_enabled' => ['meal.plans_enabled', 'bool'],
        'menu_font' => ['menu.font', 'string'],
        'menu_languages' => ['core.menu_languages', 'array'],
        'menu_layout' => ['menu.layout', 'string'],
        'menu_show_unavailable' => ['menu.show_unavailable', 'bool'],
        'meta_capi_token' => ['meta.capi_token', 'string'],
        'meta_pixel_id' => ['meta.pixel_id', 'string'],
        'min_order_value' => ['min.order_value', 'string'],
        'mobile_push_new_order' => ['mobile.push_new_order', 'bool'],
        'mobile_push_order_status' => ['mobile.push_order_status', 'bool'],
        'mobile_push_reservation' => ['mobile.push_reservation', 'bool'],
        'mobile_push_waitlist_ready' => ['mobile.push_waitlist_ready', 'bool'],
        'notify_customer_pending_payment_cancelled' => ['notify.customer_pending_payment_cancelled', 'bool'], // P3-06b API-29 (off)
        'offline_queue_enabled' => ['offline_queue.enabled', 'bool'], // P3-02 / P3-03: store level of the offline order queue (default on)
        'offline_queue_expiry_min' => ['offline_queue.expiry_min', 'int'], // P3-02 / P3-03: minutes a queued order lives (default 30)
        'open_close_store' => ['open.close_store', 'bool'],
        'order_board_chime' => ['order.board_chime', 'bool'],
        'order_board_enabled' => ['order.board_enabled', 'bool'],
        'order_board_ready_minutes' => ['order.board_ready_minutes', 'int'],
        'order_board_type_delivery' => ['order.board_type_delivery', 'bool'],
        'order_board_type_on_table' => ['order.board_type_on_table', 'bool'],
        'order_board_type_room_service' => ['order.board_type_room_service', 'bool'],
        'order_board_type_takeaway' => ['order.board_type_takeaway', 'bool'],
        'order_lookup_by_number' => ['order.lookup_by_number', 'bool'],
        'prep_eta_static' => ['prep.eta_static', 'string'],
        'prevent_order_on_close' => ['prevent.order_on_close', 'bool'],
        'print_bridge_token' => ['print.bridge_token', 'string'],
        'print_logo' => ['print.logo', 'bool'],
        'print_open_drawer' => ['print.open_drawer', 'bool'],
        'print_rtl' => ['print.rtl', 'bool'],
        'print_rtl_font' => ['print.rtl_font', 'string'],
        'public_theme_mode' => ['public.theme_mode', 'string'],
        'qr_bg_color' => ['qr.bg_color', 'string'],
        'qr_builder_visited' => ['qr.builder_visited', 'bool'],
        'qr_fg_color' => ['qr.fg_color', 'string'],
        'qr_image' => ['qr.image', 'string'],
        'qr_mode' => ['qr.mode', 'int'],
        'qr_mode_size' => ['qr.mode_size', 'int'],
        'qr_padding' => ['qr.padding', 'int'],
        'qr_position_x' => ['qr.position_x', 'int'],
        'qr_position_y' => ['qr.position_y', 'int'],
        'qr_radius' => ['qr.radius', 'int'],
        'qr_source_label' => ['qr.source_label', 'string'],
        'qr_text' => ['qr.text', 'string'],
        'qr_text_color' => ['qr.text_color', 'string'],
        'refund_request_window_days' => ['refund.request_window_days', 'int'],
        'refunds_returns' => ['refunds.returns', 'bool'], // P3-08 / D7: returns may be refunded (off: exchange or store credit only)
        'refunds_weighed_difference' => ['refunds.weighed_difference', 'bool'], // P3-09 / D7: charge the cap and refund the weighed difference (off: authorise/capture gateways and cash only)
        'require_phone_verification' => ['require.phone_verification', 'bool'],
        'reservation_deposit_amount' => ['reservation.deposit_amount', 'string'],
        'reservation_deposit_enabled' => ['reservation.deposit_enabled', 'bool'],
        'reservation_deposit_min_party' => ['reservation.deposit_min_party', 'int'],
        'reservation_deposit_per' => ['reservation.deposit_per', 'string'],
        'reservation_deposit_refund' => ['reservation.deposit_refund', 'string'],
        'reservation_deposit_refund_pct' => ['reservation.deposit_refund_pct', 'int'],
        'restaurant_color' => ['store.color', 'string'],
        'restaurant_delivery_charge' => ['ordering.delivery_charge', 'int'],
        'restaurant_free_delivery_over' => ['ordering.free_delivery_over', 'string'], // "free delivery over X" (major units, exact text; empty/0 = off)
        'restaurant_free_delivery_covers_branch_fee' => ['ordering.free_delivery_covers_branch_fee', 'bool'], // P5-34: "free delivery over X" also waives a branch's own fee (unset = on)
        'restaurant_delivery_order' => ['ordering.delivery_order', 'bool'],
        'restaurant_on_table_order' => ['ordering.on_table_order', 'bool'],
        'laundry_turnaround_hours' => ['laundry.turnaround_hours', 'int'], // P3-11: least hours between pickup and delivery-back (default 24)
        'restaurant_online_payment' => ['ordering.online_payment', 'bool'],
        'store_submit_order_notification' => ['ordering.send_order_notification', 'bool'],
        'restaurant_takeaway_order' => ['ordering.takeaway_order', 'bool'],
        'restaurant_tax_label' => ['tax.tax_label', 'string'],
        'restaurant_tax_type' => ['tax.tax_type', 'string'],
        'restaurant_taxx_charge' => ['tax.taxx_charge', 'string'],
        'tax_engine' => ['tax.engine', 'string'], // P6-99b: '' legacy | 'vat' (TAX-ENGINE.md)
        'tax_prices_include' => ['tax.prices_include', 'bool'], // P6-99b: menu prices include VAT
        'tax_fees_taxable' => ['tax.fees_taxable', 'string'], // P6-99b, retired by P6-116 (migrated to the per-fee switches below)
        'tax_delivery_taxable' => ['tax.delivery_taxable', 'bool'], // J32: VAT on the delivery fee (off)
        'tax_tray_taxable' => ['tax.tray_taxable', 'bool'], // J32: VAT on the room-service tray charge (off)
        'tax_service_taxable' => ['tax.service_taxable', 'bool'], // J32: VAT on the service charge (off)
        'tax_levies' => ['tax.levies', 'array'], // J32: pre-VAT levies (TaxLevies), all off by default
        'tax_rate_preset' => ['tax.rate_preset', 'string'], // J32: '' | a TaxCountryRules::RATE_PRESETS key
        'tax_zero_vatex' => ['tax.zero_vatex', 'string'], // P6-175: ZATCA reason code for zero-rated (Z) lines, '' = VATEX-SA-35
        'tax_exempt_vatex' => ['tax.exempt_vatex', 'string'], // P6-175: ZATCA reason code for exempt (E) lines, '' = VATEX-SA-29
        'store_template' => ['core.store_template', 'string'],
        'returns_window_days' => ['returns.window_days', 'int'], // P3-08: goods returns window in days (0 = no returns offered)
        'scheduling_lead_minutes' => ['scheduling.lead_minutes', 'int'],
        'scheduling_max_days' => ['scheduling.max_days', 'int'],
        'scheduling_prep_minutes' => ['scheduling.prep_minutes', 'int'],
        'scheduling_slot_capacity' => ['scheduling.slot_capacity', 'int'],
        'scheduling_slot_interval' => ['scheduling.slot_interval', 'int'],
        'scheduling_types' => ['scheduling.types', 'string'],
        'sf_page_about' => ['sf.page_about', 'string'],
        'sf_page_help' => ['sf.page_help', 'string'],
        'sf_page_privacy' => ['sf.page_privacy', 'string'],
        'sf_page_terms' => ['sf.page_terms', 'string'],
        'show_background_img_on_bio_page' => ['show.background_img_on_bio_page', 'bool'],
        'show_continue_btn_on_bio_page' => ['show.continue_btn_on_bio_page', 'bool'],
        'show_discription_on_bio_page' => ['show.discription_on_bio_page', 'bool'],
        'show_lang_switch_on_bio_page' => ['show.lang_switch_on_bio_page', 'bool'],
        'show_links_on_bio_page' => ['show.links_on_bio_page', 'bool'],
        'show_logo_img_on_bio_page' => ['show.logo_img_on_bio_page', 'bool'],
        'show_never_show_on_bio_page' => ['show.never_show_on_bio_page', 'bool'],
        'show_open_bio_close_store' => ['show.open_bio_close_store', 'bool'],
        'show_open_close_store' => ['show.open_close_store', 'bool'],
        'show_social_on_bio_page' => ['show.social_on_bio_page', 'bool'],
        'show_timing_on_bio_page' => ['show.timing_on_bio_page', 'bool'],
        'show_title_on_bio_page' => ['show.title_on_bio_page', 'bool'],
        'substitution_default_option' => ['substitution.default_option', 'string'], // P3-09 / API-22: public id of the default "If it's out of stock" option (empty = the first)
        'table' => ['table.table', 'string'],
        'tip_presets' => ['tip.presets', 'string'],
        'translation_glossary' => ['translation.glossary', 'string'],
        'translation_tone' => ['translation.tone', 'string'],
        'voice_note_transcription' => ['voice.note_transcription', 'bool'],
        'waitlist_avg_turn' => ['waitlist.avg_turn', 'int'],
        'wallet_bio_link' => ['wallet.bio_link', 'bool'],
        'whatsapp_chatbot_enable' => ['whatsapp.chatbot_enable', 'bool'],
        'whatsapp_chatbot_prompt' => ['whatsapp.chatbot_prompt', 'string'],
        'whatsapp_extras_notice_dismissed' => ['whatsapp.extras_notice_dismissed', 'bool'],
        'whatsapp_handoff_delay' => ['whatsapp.handoff_delay', 'string'],
        'whatsapp_handoff_mode' => ['whatsapp.handoff_mode', 'string'],
        'whatsapp_handoff_route' => ['whatsapp.handoff_route', 'string'],
        'whatsapp_handoff_target' => ['whatsapp.handoff_target', 'string'],
        'whatsapp_message' => ['whatsapp.message', 'string'],
        'whatsapp_number' => ['whatsapp.number', 'string'],
        'whatsapp_number10' => ['whatsapp.number10', 'string'],
        'whatsapp_number2' => ['whatsapp.number2', 'string'],
        'whatsapp_number3' => ['whatsapp.number3', 'string'],
        'whatsapp_number4' => ['whatsapp.number4', 'string'],
        'whatsapp_number5' => ['whatsapp.number5', 'string'],
        'whatsapp_number6' => ['whatsapp.number6', 'string'],
        'whatsapp_number7' => ['whatsapp.number7', 'string'],
        'whatsapp_number8' => ['whatsapp.number8', 'string'],
        'whatsapp_number9' => ['whatsapp.number9', 'string'],
        'whatsapp_number_mode' => ['whatsapp.number_mode', 'string'],
        'whatsapp_order_template' => ['whatsapp.order_template', 'string'],
        'whatsapp_ordering_enable' => ['whatsapp.ordering_enable', 'bool'],
        'whatsapp_provider' => ['whatsapp.provider', 'string'],
        'whatsapp_status_template' => ['whatsapp.status_template', 'string'],
        'winback_after_days' => ['winback.after_days', 'int'],
        'winback_enabled' => ['winback.enabled', 'bool'],
        'winback_frequency_days' => ['winback.frequency_days', 'int'],
        'winback_reward_percentage' => ['winback.reward_percentage', 'int'],
        'winback_validity_days' => ['winback.validity_days', 'int'],
    ];

    /** @return array{0: string, 1: string}|null */
    public static function lookup(string $name): ?array
    {
        return self::KEYS[$name] ?? null;
    }
}
