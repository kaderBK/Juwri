<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services\WhatsApp;

/**
 * WA-C2: the order-message template, core-owned.
 *
 * The shortcode set and the platform default used to live in the QuickOrder
 * plugin, so retiring that plugin would have taken the message format with it —
 * and every store's saved template references those exact tokens.
 *
 * The tokens are unchanged on purpose. A merchant's saved template written under
 * the plugin must keep expanding identically after the move.
 */
class OrderMessage
{
    /**
     * The tokens a merchant may use, and what each one is replaced with.
     * Keys are the literal placeholders; values are the label shown in the UI.
     */
    public const SHORTCODES = [
        '{ORDER_ID}' => 'Order Id',
        '{ORDER_DETAILS}' => 'Order Details',
        '{CUSTOMER_DETAILS}' => 'Customer Details',
        '{ORDER_TYPE}' => 'Ordering Type',
        '{ORDER_TOTAL}' => 'Order Total Price',
    ];

    /** Shipped default (raw English), used when neither the store nor the platform
     *  has one. Prefer fallbackTemplate() — it localizes the prose while keeping
     *  every {TOKEN} literal for downstream replacement. */
    public const FALLBACK = "*New Order* (#{ORDER_ID})\n\n{ORDER_DETAILS}\n\nPayable: *{ORDER_TOTAL}*\n\n*Customer details*\n{CUSTOMER_DETAILS}\n-----------------------------\nThanks for the order.";

    /**
     * I2: the shipped default with its prose localized (the {TOKENS} stay literal
     * — they are replaced downstream). Resolves in the current locale; callers
     * that send to a merchant set the owner's locale first.
     */
    public static function fallbackTemplate(): string
    {
        return '*'.___('New Order').'* (#{ORDER_ID})'."\n\n"
            .'{ORDER_DETAILS}'."\n\n"
            .___('Payable:').' *{ORDER_TOTAL}*'."\n\n"
            .'*'.___('Customer details').'*'."\n"
            .'{CUSTOMER_DETAILS}'."\n"
            .'-----------------------------'."\n"
            .___('Thanks for the order.');
    }

    /**
     * The platform-level default template.
     *
     * Reads the core option first and falls back to the legacy plugin key, so a
     * platform that configured its default under QuickOrder keeps it.
     */
    public static function platformDefault(): string
    {
        $template = config('settings.whatsapp_order_message');

        if (blank($template)) {
            $template = config('settings.store_order_whatsapp_message');
        }

        return blank($template) ? self::fallbackTemplate() : (string) $template;
    }

    /**
     * The template this store will actually send: its own, else the platform default.
     */
    public static function templateFor($storeId): string
    {
        $template = store_options($storeId, 'whatsapp_message');

        /* getStoreOption() returns FALSE for a missing option, and blank(false) is
           FALSE in Laravel — so `blank()` alone would treat "absent" as a real
           value and cast it to an empty string, sending a blank message. Test the
           boolean explicitly. Same trap as whatsapp_ordering_enabled(). */
        if ($template === false || blank($template)) {
            return self::platformDefault();
        }

        return (string) $template;
    }

    /**
     * Expand the tokens. Unknown tokens are left alone rather than blanked, so a
     * typo in a merchant's template is visible instead of silently eating text.
     *
     * @param  array<string,string>  $values  keyed by token, e.g. ['{ORDER_ID}' => 42]
     */
    public static function render(string $template, array $values): string
    {
        return str_replace(array_keys($values), array_values($values), $template);
    }

    /** Convenience: resolve the store's template and expand it in one call. */
    public static function build($storeId, array $values): string
    {
        return self::render(self::templateFor($storeId), $values);
    }
}
