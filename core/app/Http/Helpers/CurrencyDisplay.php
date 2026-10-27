<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * Currency bidi-display helpers, split out of Helper.php to keep the god-helper
 * under its M15 size-guard ceiling (ControllerSizeGuardTest — extract, don't
 * raise). These are global functions loaded via composer autoload.files, so
 * every existing caller (blades, price_symbol_format, tests) resolves them
 * unchanged regardless of which file they live in.
 *
 * WEB DISPLAY ONLY — the isolate chars are invisible but present; never use on
 * export/PDF/CSV/receipt paths (they would leak into files).
 */

/**
 * Wrap a currency sign in a Unicode isolate (U+2068 FIRST-STRONG ISOLATE …
 * U+2069 POP DIRECTIONAL ISOLATE) — the plain-text equivalent of <bdi>.
 *
 * Arabic-script signs (د.إ, ر.ق, ﷼, ر.ع.) are strong-RTL, so without isolation
 * bidi flips which side of the number they land on — opposite to neutral glyphs
 * (⃁, $, €). Isolating the sign makes ALL signs behave the same: sign-first
 * renders LEFT of the digits, sign-last renders RIGHT, uniformly — PROVIDED the
 * whole money unit sits in an LTR context (a `<bdi dir="ltr">` wrapper, as the
 * hotel folio does). In a bare RTL container with no such wrapper the isolated
 * sign lands on the wrong side, so callers rendering money into an RTL page must
 * wrap the unit in `<bdi dir="ltr">…</bdi>` rather than expecting this alone to
 * place the sign.
 *
 * WEB DISPLAY ONLY — never use on export/PDF/CSV/receipt paths (the invisible
 * chars would leak into files); those use the ISO-code fallback (currency_sign_safe).
 */
function cur_isolate(?string $sign): string
{
    $sign = (string) $sign;

    return $sign === '' ? '' : "\u{2068}".$sign."\u{2069}";
}

/**
 * Force a formatted money string to render as one left-to-right unit — currency
 * sign on the LEFT of the digits — by wrapping it in a plain-text LTR isolate
 * (U+2066 LRI … U+2069 PDI). This is the character-level equivalent of a
 * `<bdi dir="ltr">` wrapper, so it works both in HTML text and inside a
 * data-attribute that JS later swaps into the DOM (verified live in an rtl page:
 * the sign lands left in both cases).
 *
 * Scoped to USD ONLY (locked to the "$" case by request): the neutral "$" glyph
 * is what lands on the wrong side in a bare RTL container (an isolated $ renders
 * to the RIGHT of the number). Every other currency keeps its own tuned handling
 * (cur_isolate + the currency_pos_effective RTL rules), so this must never touch
 * riyal/dirham/etc. — it returns them unchanged.
 *
 * WEB DISPLAY ONLY — the isolate chars are invisible but present; never feed the
 * result to export/PDF/CSV or to code that parses it back into a number.
 */
function money_ltr(string $formatted): string
{
    if (strtoupper((string) config('settings.currency_code')) !== 'USD') {
        return $formatted;
    }

    return "\u{2066}".$formatted."\u{2069}";
}

/**
 * Same as money_ltr(), but for a COPY string that has one or more USD "$" prices
 * baked into it (marketing/translation strings like "Buy a seat at $5.99, …").
 * Each "$<number>" token is wrapped in the LTR isolate so the sign renders on the
 * LEFT of its digits in RTL — matching the money the blades render — instead of
 * bidi flipping the bare "$5.99" to "5.99$". USD-only, web display only.
 */
function usd_ltr_inline(string $text): string
{
    if (strtoupper((string) config('settings.currency_code')) !== 'USD') {
        return $text;
    }

    return preg_replace_callback(
        '/\$\d[\d,]*(?:\.\d+)?/',
        fn ($m) => "\u{2066}".$m[0]."\u{2069}",
        $text
    );
}

/**
 * WEB-display store price — the store-scoped twin of price_symbol_format (which
 * reads config('settings.*'), unset in controllers that never ran publicView).
 * Sign + number-facing gap, position-aware, wrapped in an LTR isolate so the
 * sign never bidi-flips on a price-only element. Use this instead of the ad-hoc
 * `$sign . number_format()` so every price renders uniformly (spacing/position).
 * Lives here (not Helper.php) to keep that file under its ControllerSizeGuard
 * ceiling — currency helpers group with cur_isolate()/money_ltr() above.
 */
function store_money_format(int|float|string $price, int|string $storeId): string
{
    $code = (string) (store_options($storeId, 'currency_code') ?: '');
    $rawSign = (string) (store_options($storeId, 'currency_sign') ?: ($code !== '' ? currency_symbol_for($code) : config('settings.currency_sign', '$')));
    $num = store_price_format($price, $storeId);
    $gap = currency_symbol_gap($rawSign);
    $sign = cur_isolate($rawSign);
    $body = currency_pos_effective($storeId) === 1 ? $sign.$gap.$num : $num.$gap.$sign;

    // Outer LTR-isolate ONLY the neutral USD "$", which alone bidi-flips to the
    // wrong side in a bare RTL container. The Saudi Riyal (and every RTL-script
    // sign) must NOT be wrapped: currency_pos_effective() already returns sign-first
    // for RTL precisely so bidi renders the sign visually AFTER the number — an outer
    // LTR isolate would defeat that and force the sign back BEFORE the digits. This
    // mirrors money_ltr()'s USD-only lock.
    $wrap = strtoupper($code) === 'USD';

    return $wrap ? "\u{2066}".$body."\u{2069}" : $body;
}

/**
 * PLAIN-text store price for NON-web channels (SMS, WhatsApp, printed receipts,
 * native-app / JSON API payloads): sign + a plain space + number, position-aware,
 * with NO bidi isolates (they would leak as visible junk in text/JSON channels).
 */
function store_money_plain(int|float|string $price, int|string $storeId): string
{
    $code = (string) (store_options($storeId, 'currency_code') ?: '');
    $sign = currency_sign_safe((string) store_options($storeId, 'currency_sign'), $code);
    if ($sign === '') {
        $sign = $code !== '' ? currency_symbol_for($code) : '$';
    }
    $num = store_price_format($price, $storeId);

    return currency_pos_effective($storeId) === 1 ? $sign.' '.$num : $num.' '.$sign;
}

/**
 * Owner-dashboard money in the currency the figure was computed in (P6-62). A store of that currency formats it
 * (its own sign/position/decimals); with none, the ISO code stands in for the sign — never the site default symbol,
 * which would put the wrong currency on the number. Null currency = the legacy site-default rendering.
 */
function dashboard_money(int|float|string $amount, ?string $currency = null, ?int $storeId = null): string
{
    if ($storeId) {
        return store_money_format($amount, $storeId);
    }
    if ($currency === null || $currency === '') {
        return price_symbol_format($amount);
    }

    return number_format((float) $amount, money_decimals($currency)).' '.strtoupper($currency);
}
