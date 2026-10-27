<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\GoogleOrdering;

use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Ordering\Contracts\StorefrontOrdering;
use Juwri\Modules\Storefront\Services\StoreAddressResolver;
use Juwri\Modules\Storefront\Services\ViewModel\OrderTypeKeys;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

/**
 * P5-20 (ROADMAP 24 G3): the Ordering Redirect deep links — one stable HTTPS link per core fulfilment mode a
 * store offers, on the store's own primary address, in the store's languages.
 *
 * Google links the diner OUT to the store's own ordering page; the public route `storefront.googleOrdering.link`
 * preselects the order type after the redirect. No state: everything is derived from the store and the shared
 * {@see StoreAddressResolver}, so custom domain / reseller / platform-host forms and the locale prefix are right.
 */
final class OrderLinks
{
    public function __construct(private StoreAddressResolver $resolver) {}

    /**
     * The deep link for one core fulfilment mode, or null when the store does not offer it.
     *
     * When $branch is given (a multi-branch store's per-branch merchant), the link carries
     * `branch=<branch public_id>` before the utm params so the storefront preselects that branch.
     */
    public function url(Store $store, string $coreType, ?string $locale = null, ?Branch $branch = null): ?string
    {
        $kitKey = OrderTypeKeys::toKit($coreType);
        if ($kitKey === null || ! $this->offers($store, $coreType)) {
            return null;
        }

        $base = $this->resolver->localized((int) $store->id, (string) $store->slug, $this->prefixFor($locale), 'order/'.$kitKey);

        $query = [];
        if ($branch !== null) {
            $query['branch'] = (string) $branch->public_id;
        }
        $query['utm_source'] = (string) config('services.google_ordering.utm_source', 'google');
        $query['utm_medium'] = (string) config('services.google_ordering.utm_medium', 'ordering_redirect');

        return $base.'?'.http_build_query($query);
    }

    /**
     * The language codes the store's storefront is available in (its menu languages that the platform still
     * serves). `['']` when language prefixes are off — meaning "no prefix".
     *
     * @return list<string>
     */
    public function locales(Store $store): array
    {
        if (! config('settings.include_language_code')) {
            return [''];
        }

        $codes = array_values(array_unique(array_map('strval', StoreSettings::array((int) $store->id, 'menu_languages'))));
        if ($codes === []) {
            return [''];
        }

        $active = Language::query()->whereIn('code', $codes)->where('active', 1)->pluck('code')->map('strval')->all();

        $out = [];
        foreach ($codes as $code) {
            if (in_array($code, $active, true)) {
                $out[] = $code;
            }
        }

        return $out === [] ? [''] : $out;
    }

    /**
     * The URL prefix for $locale: null for the default language (it lives at the unprefixed URL) and for "no prefix".
     */
    private function prefixFor(?string $locale): ?string
    {
        if ($locale === null || $locale === '') {
            return null;
        }
        if (config('laravellocalization.hideDefaultLocaleInURL', true) && $locale === (string) LaravelLocalization::getDefaultLocale()) {
            return null;
        }

        return $locale;
    }

    private function offers(Store $store, string $coreType): bool
    {
        try {
            $types = app(StorefrontOrdering::class)->orderTypes($store);
        } catch (\Throwable) {
            return false;
        }

        return in_array($coreType, array_map('strval', (array) $types), true);
    }
}
