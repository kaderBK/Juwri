<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Storefront;

use App\Models\Story;
use App\Services\Directory\OpeningHoursService;
use App\Services\FeatureAccess;
use App\Services\Verticals\VerticalRegistry;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Contracts\Tenancy\ResellerContext;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Support\BrandContext;
use Juwri\Core\Support\SafeUrl;
use Juwri\Modules\Ordering\Services\MealPlanService;
use Juwri\Modules\Storefront\Services\StoreReviewsDisplay;
use Juwri\Modules\Storefront\Storefront\DisplayRates;

/**
 * Theme Studio B0.6: the storefront data the Blade themes compute in their own
 * `@php` blocks and layout partials — which the Liquid path never ran, because
 * it returns before the Blade view. Built once per render by StoreController
 * (plain arrays/scalars only) and mapped to Drops by ThemeContext.
 *
 * Every value uses the SAME helper / gate the Blade storefront uses, so a
 * Liquid theme can never show a feature the Blade theme would hide.
 */
class StorefrontExtras
{
    public const MAX_STORIES = 20;

    /**
     * @param  object  $postOptions  masked options bag (StorefrontToggleMask already applied)
     * @param  array<string,bool>  $flags  the render's ordering flags
     * @return array{hours:array<string,mixed>,stories:list<array<string,mixed>>,social:array<string,?string>,announcement:?array<string,string>,features:array<string,bool>,platform:array{brand_name:string,brand_url:string,hide_branding:bool}}
     */
    public static function for(Store $store, object $postOptions, array $flags): array
    {
        return [
            'platform' => static::platform($store),
            'hours' => static::hours($store),
            'stories' => static::stories($store),
            'social' => static::social($store, $postOptions),
            'announcement' => static::announcement($store),
            'features' => static::features($store, $postOptions, $flags),
        ];
    }

    /** @return array<string,mixed> */
    public static function hours(Store $store): array
    {
        $manualClosed = (string) store_options($store->id, 'open_close_store', '') === '0';
        $week = null;
        $status = ['state' => 'unknown'];
        try {
            $week = OpeningHoursService::weeklyModelForPost($store);
            if ($week) {
                // Same timezone rule as the Blade chip (store_hours_payload).
                $payload = store_hours_payload($store);
                $status = OpeningHoursService::isOpenAt($week, (string) ($payload['tz'] ?? config('app.timezone')));
            }
        } catch (\Throwable $e) {
            report($e); // hours are decoration — never break the storefront
        }

        $days = [];
        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $day) {
            $slot = is_array($week[$day] ?? null) ? $week[$day] : null;
            $days[] = [
                'day' => $day,
                'closed' => $slot === null || ! empty($slot['closed']),
                'open' => $slot['open'] ?? null,
                'close' => $slot['close'] ?? null,
            ];
        }

        // Theme Studio follow-ups: "today" in the STORE's timezone, so a theme
        // never highlights the server's weekday (a Riyadh store rendered on a
        // UTC host at 01:00 is already on the next day).
        $clock = static::storeClock($store);

        return [
            'today_key' => $clock['today_key'],
            'now_time' => $clock['now_time'],
            'is_open' => store_open_now($store),
            'manual_closed' => $manualClosed,
            'state' => $manualClosed ? 'closed' : $status['state'],
            'today' => storefront_hours_today($store),
            'closes_at' => $status['closes_at'] ?? null,
            'opens_at' => $status['opens_at'] ?? null,
            'has_hours' => $week !== null,
            'week' => $week !== null ? $days : [],
        ];
    }

    /**
     * Weekday key ('mon'..'sun') and HH:MM right now in the store's timezone —
     * the same timezone rule as store_hours_payload() (the Blade open chip).
     *
     * @return array{today_key:string,now_time:string}
     */
    public static function storeClock(?Store $store): array
    {
        $tz = (string) config('app.timezone', 'UTC');
        if ($store && $store->id) {
            try {
                $tz = (string) (store_hours_payload($store)['tz'] ?? $tz);
            } catch (\Throwable $e) {
                report($e);
            }
        }
        try {
            $now = now()->setTimezone($tz);
        } catch (\Throwable) {
            $now = now();
        }

        return ['today_key' => strtolower($now->format('D')), 'now_time' => $now->format('H:i')];
    }

    /**
     * The `platform` global: the brand the "Powered by" line names and whether
     * the store may hide it.
     *
     * Brand: the reseller-aware brand context the Blade storefront uses
     * (brand_context(): a reseller's custom domain, or the signed-in user's
     * reseller), falling back to the STORE OWNER's active reseller so anonymous
     * diners of a white-label merchant never see the platform name. The name is
     * the short brand (seo_brand()'s rule); the link is the reseller's own
     * domain, else the platform home (route('home'), as the Blade themes link).
     *
     * Hide rule: the plan feature `hide_branding` — the exact gate the Blade
     * themes wrap their "Powered by" footer in (store_plan_allows) — or a
     * reseller tier that removes the powered-by line (`remove_powered_by`).
     *
     * @return array{brand_name:string,brand_url:string,hide_branding:bool}
     */
    public static function platform(?Store $store): array
    {
        $ctx = brand_context();
        $reseller = $ctx->reseller ?? ($store ? static::ownerReseller($store) : null);
        if ($reseller && ! $ctx->isReseller()) {
            $ctx = new BrandContext($reseller);
        }

        $url = route('home');
        if ($reseller && is_string($reseller->getAttribute('custom_domain')) && trim($reseller->getAttribute('custom_domain')) !== '') {
            $url = 'https://'.strtolower(trim($reseller->getAttribute('custom_domain')));
        }

        $hide = $store !== null && $store->id && store_plan_allows($store, 'hide_branding');
        if (! $hide && $reseller) {
            try {
                $hide = app(ResellerContext::class)->entitled($reseller, 'remove_powered_by');
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return [
            'brand_name' => $ctx->isReseller() ? $ctx->shortName() : seo_brand(),
            'brand_url' => $url,
            'hide_branding' => (bool) $hide,
        ];
    }

    /** The store owner's reseller, when active (white-label merchant on the platform host). */
    protected static function ownerReseller(Store $store): ?Model
    {
        try {
            $owner = $store->user;
            $reseller = $owner instanceof User ? app(ResellerContext::class)->forUser($owner) : null;

            return $reseller && $reseller->getAttribute('active') ? $reseller : null;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /** @return list<array<string,mixed>> */
    public static function stories(Store $store): array
    {
        if (! $store->id) {
            return [];
        }

        return Story::where('store_id', $store->id)->visible()
            ->limit(self::MAX_STORIES)->get()
            ->map(fn (Story $s) => [
                'id' => (int) $s->id,
                'image_url' => $s->media_full_url,
                'caption' => $s->caption,
                'cta_label' => $s->cta_label ?: ($s->cta_url ? ___('Order now') : null),
                'cta_url' => $s->cta_url ? storefront_localized_link($s->cta_url) : null,
            ])->values()->all();
    }

    /** @return array<string,?string> */
    public static function social(Store $store, object $postOptions): array
    {
        $digits = null;
        try {
            $digits = storefront_whatsapp_number($store) ?: null;
        } catch (\Throwable $e) {
            report($e);
        }

        $str = static fn ($v) => is_string($v) && trim($v) !== '' ? trim($v) : null;

        return [
            'instagram_url' => $str(store_options($store->id, 'instagram_url')),
            'facebook_url' => $str(store_options($store->id, 'facebook_url')),
            'google_review_url' => $str(SafeUrl::web((string) ($postOptions->google_review_url ?? ''))),
            'whatsapp' => $digits,
            'whatsapp_url' => $digits ? 'https://wa.me/'.$digits : null,
            'phone' => $str($store->phone),
            'address' => $str($store->address),
            'maps_url' => $str($store->address) ? 'https://www.google.com/maps/search/?api=1&query='.rawurlencode(trim((string) $store->address)) : null,
        ];
    }

    /** @return array<string,string>|null */
    public static function announcement(Store $store): ?array
    {
        if ((string) store_options($store->id, 'store_announcement_active', '') !== '1') {
            return null;
        }
        $text = trim((string) store_options($store->id, 'store_announcement', ''));

        // key = same dismissal fingerprint the Blade partial stores (mb_ann_{id}).
        return $text === '' ? null : ['text' => $text, 'key' => substr(hash('crc32b', $text), 0, 8)];
    }

    /**
     * @param  array<string,bool>  $flags
     * @return array<string,bool>
     */
    public static function features(Store $store, object $o, array $flags): array
    {
        $id = (int) $store->id;
        $allows = static fn (string $key) => $id > 0 && FeatureAccess::storeAllows($id, $key);
        $registry = app(VerticalRegistry::class);
        $type = $store->store_type ?? null;

        $loyalty = false;
        try {
            $loyalty = $id > 0 && $allows('loyalty') && storefront()->loyaltyProgram($store) !== null;
        } catch (\Throwable $e) {
            report($e);
        }

        $mcRates = [];
        try {
            $mcRates = DisplayRates::fromOptions($o, store_currency($id))->rates;
        } catch (\Throwable $e) {
            report($e);
        }

        return [
            'reservations' => ! empty($o->enable_reservations),
            'waitlist' => ! empty($o->enable_waitlist),
            'loyalty' => $loyalty,
            'gift_cards' => ! empty($o->gift_cards_enabled),          // masked by StorefrontToggleMask
            'catering' => $id > 0 && storefront_catering_enabled($id),
            'meal_plans' => $id > 0 && MealPlanService::enabled($id),
            'curbside' => ! empty($o->curbside_enabled),              // masked
            'multi_currency' => ! empty($o->enable_multi_currency) && count($mcRates) > 0,
            'dietary_filters' => ! isset($o->allow_dietary_filters) || $o->allow_dietary_filters == 1,
            'call_waiter' => ! empty($o->allow_call_waiter) && ! empty($flags['on_table']),
            'coupons' => ($o->allow_coupons ?? null) == 1,
            'tips' => ($o->allow_tips ?? null) == 1,
            'scheduling' => ! empty($o->allow_order_scheduling),       // masked
            'customer_wallet' => $allows('customer_wallet'),
            'store_reviews' => $id > 0 && StoreReviewsDisplay::enabledFor($id),
            'google_review' => trim((string) ($o->google_review_url ?? '')) !== '',
            // Vertical capabilities (config/verticals.php)
            'variant_goods' => $registry->has($type, 'variant_goods'),
            'expiry' => $registry->has($type, 'expiry'),
            'age_gate' => $registry->has($type, 'age_gate'),
            'fitment' => $registry->has($type, 'fitment'),
            'service' => $registry->has($type, 'service'),
        ];
    }
}
