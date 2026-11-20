<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Editions;

use Illuminate\Contracts\Foundation\Application;
use Juwri\Core\Contracts\Billing\AddonTax;
use Juwri\Core\Contracts\Billing\EntitlementSource;
use Juwri\Core\Contracts\Billing\FeatureCommerce;
use Juwri\Core\Contracts\Billing\FeatureGate;
use Juwri\Core\Contracts\Billing\PlanCatalog;
use Juwri\Core\Contracts\Billing\PlanLimits;
use Juwri\Core\Contracts\Billing\PlanQuota;
use Juwri\Core\Contracts\Billing\PrepaidCredits;
use Juwri\Core\Contracts\Billing\RequestMeter;
use Juwri\Core\Contracts\Billing\Subscriptions;
use Juwri\Core\Contracts\Billing\UsageReport;
use Juwri\Core\Contracts\Content\HelpPillars;
use Juwri\Core\Contracts\Content\PlatformSite;
use Juwri\Core\Contracts\Content\ResellerContent;
use Juwri\Core\Contracts\Editions\Edition;
use Juwri\Core\Contracts\Editions\InstallLicenceProof;
use Juwri\Core\Contracts\Editions\StoreLimitPolicy;
use Juwri\Core\Contracts\Marketplace\Directory;
use Juwri\Core\Contracts\Marketplace\PlatformWalletTender;
use Juwri\Core\Contracts\Marketplace\ShopperBag;
use Juwri\Core\Contracts\Tenancy\ResellerContext;
use Juwri\Core\Contracts\Tenancy\ResellerSite;
use Juwri\Core\Editions\Community\CommunityEdition;
use Juwri\Core\Editions\Community\CommunityEntitlementSource;
use Juwri\Core\Editions\Community\CommunityPlanCatalog;
use Juwri\Core\Editions\Community\CommunityStoreLimits;
use Juwri\Core\Editions\Community\NoAddonTax;
use Juwri\Core\Editions\Community\NoDirectory;
use Juwri\Core\Editions\Community\NoFeatureCommerce;
use Juwri\Core\Editions\Community\NoHelpPillars;
use Juwri\Core\Editions\Community\NoInstallLicence;
use Juwri\Core\Editions\Community\NoPlanQuota;
use Juwri\Core\Editions\Community\NoPlatformSite;
use Juwri\Core\Editions\Community\NoPlatformWallet;
use Juwri\Core\Editions\Community\NoPrepaidCredits;
use Juwri\Core\Editions\Community\NoRequestMeter;
use Juwri\Core\Editions\Community\NoResellers;
use Juwri\Core\Editions\Community\NoShopperBag;
use Juwri\Core\Editions\Community\NoSubscriptions;
use Juwri\Core\Editions\Community\NoUsageReport;
use Juwri\Core\Services\EntitlementGates;

/**
 * P1-13 (plan-13 §2): the extension points Community code calls instead of ee classes, each with its Community
 * default. Bound with `singletonIf`, so an ee module provider that binds its own implementation (plain
 * `singleton`/`bind`, registered before or after Core) always wins, and without an edition package these stay.
 */
final class CommunityDefaults
{
    /** @var array<class-string, class-string> contract => Community default */
    public const BINDINGS = [
        Edition::class => CommunityEdition::class,
        StoreLimitPolicy::class => CommunityStoreLimits::class,
        InstallLicenceProof::class => NoInstallLicence::class,
        PlanCatalog::class => CommunityPlanCatalog::class,
        EntitlementSource::class => CommunityEntitlementSource::class,
        FeatureCommerce::class => NoFeatureCommerce::class,
        AddonTax::class => NoAddonTax::class,
        UsageReport::class => NoUsageReport::class,
        PlanQuota::class => NoPlanQuota::class,
        PrepaidCredits::class => NoPrepaidCredits::class, // credit packs (PRICING.md §8)
        RequestMeter::class => NoRequestMeter::class,
        Subscriptions::class => NoSubscriptions::class,
        ResellerContext::class => NoResellers::class,
        ResellerSite::class => NoResellers::class,
        ResellerContent::class => NoResellers::class,
        PlatformSite::class => NoPlatformSite::class,
        HelpPillars::class => NoHelpPillars::class,
        Directory::class => NoDirectory::class,
        ShopperBag::class => NoShopperBag::class,
        PlatformWalletTender::class => NoPlatformWallet::class,
    ];

    public static function register(Application $app): void
    {
        foreach (self::BINDINGS as $contract => $default) {
            $app->singletonIf($contract, $default);
        }

        // The entitlement resolver itself is Community (plan-13 §1c): FeatureGate/PlanLimits are always Core's.
        $app->singletonIf(EntitlementGates::class);
        $app->singletonIf(FeatureGate::class, fn ($app) => $app->make(EntitlementGates::class));
        $app->singletonIf(PlanLimits::class, fn ($app) => $app->make(EntitlementGates::class));
    }
}
