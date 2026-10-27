<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services;

use Juwri\Core\Models\Store;
use Juwri\Modules\Fiscal\Services\Profiles\AeProfile;
use Juwri\Modules\Fiscal\Services\Profiles\BhProfile;
use Juwri\Modules\Fiscal\Services\Profiles\DzProfile;
use Juwri\Modules\Fiscal\Services\Profiles\EgProfile;
use Juwri\Modules\Fiscal\Services\Profiles\IqProfile;
use Juwri\Modules\Fiscal\Services\Profiles\JoProfile;
use Juwri\Modules\Fiscal\Services\Profiles\KwProfile;
use Juwri\Modules\Fiscal\Services\Profiles\LyProfile;
use Juwri\Modules\Fiscal\Services\Profiles\MaProfile;
use Juwri\Modules\Fiscal\Services\Profiles\OmProfile;
use Juwri\Modules\Fiscal\Services\Profiles\QaProfile;
use Juwri\Modules\Fiscal\Services\Profiles\SaProfile;
use Juwri\Modules\Fiscal\Services\Profiles\TnProfile;

/**
 * TAX-RUN F0: resolves the FiscalProfile for a country / restaurant. The
 * per-restaurant `fiscal_country` post_option drives selection. When a
 * restaurant has no country set, resolution returns null (receipts render
 * exactly as they do today — no fiscal block).
 */
class FiscalRegistry
{
    /** @var array<string,class-string<FiscalProfile>> */
    private const MAP = [
        'SA' => SaProfile::class,
        'JO' => JoProfile::class,
        'EG' => EgProfile::class,
        'AE' => AeProfile::class,
        'OM' => OmProfile::class,
        'BH' => BhProfile::class,
        'TN' => TnProfile::class,
        'MA' => MaProfile::class,
        'QA' => QaProfile::class,
        'IQ' => IqProfile::class,
        'KW' => KwProfile::class,
        'DZ' => DzProfile::class,
        'LY' => LyProfile::class,
    ];

    /** @var array<string,FiscalProfile> */
    private static array $cache = [];

    /** All supported country codes. */
    public static function countries(): array
    {
        return array_keys(self::MAP);
    }

    /** Resolve a profile by country code, or null when unsupported/empty. */
    public static function profile(?string $country): ?FiscalProfile
    {
        $cc = strtoupper(trim((string) $country));
        if ($cc === '' || ! isset(self::MAP[$cc])) {
            return null;
        }

        return self::$cache[$cc] ??= new (self::MAP[$cc])();
    }

    /** Resolve the profile for a restaurant via its fiscal_country option. */
    public static function for(Store|int|null $store): ?FiscalProfile
    {
        if ($store === null) {
            return null;
        }

        $id = $store instanceof Store ? $store->id : (int) $store;

        return self::profile((string) store_options($id, 'fiscal_country'));
    }

    /** Countries that have a live authority driver (SA/JO/EG). */
    public static function liveDriverCountries(): array
    {
        $out = [];
        foreach (self::countries() as $cc) {
            if (self::profile($cc)?->hasLiveDriver()) {
                $out[] = $cc;
            }
        }

        return $out;
    }

    /**
     * Options list for a country <select>, sorted with live-driver countries
     * first. Value = code, label = "Country Name".
     *
     * @return array<string,string>
     */
    public static function selectOptions(): array
    {
        $out = [];
        foreach (self::countries() as $cc) {
            $p = self::profile($cc);
            $out[$cc] = $p ? $p->countryName() : $cc;
        }

        return $out;
    }
}
