<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * Module-boundary baseline (P1-18): today's 'not a public part' cross-module references, mirroring the report of
 * ModuleBoundariesTest (96 references: the 93 left after P1-09 + 3 seeder files moved by P1-11; 95 of them are in classes, listed below). Each entry: using class => the
 * non-public classes of OTHER modules it may still use. The arch test fails on any reference not listed here, so the baseline can
 * only shrink: remove a line when the reference is gone (later work items route them through Contracts/Data/Events).
 * P1-13 13i part 2b: the Enterprise package's own entries live in its tests (the edition test hook merges them per class).
 * P1-13 13e: the 4 Platform entries are gone (3 classes went back to App\, outside the modules; the E2E seeder now
 * looks the Hotel module's seeder up in the seeder registry).
 * Files that declare no class (route files) cannot be seen by arch(); the existing ModuleBoundariesTest still reports them.
 */

return [
    'Juwri\\Modules\\Storefront\\Storefront\\CommerceRuntime' => [  // Storefront
        'Juwri\\Modules\\Pharmacy\\Services\\Pharmacy\\PharmacyComplianceService',
    ],
    'Juwri\\Modules\\Storefront\\Http\\Controllers\\Api\\V1\\StorefrontController' => [  // Storefront
        'Juwri\\Modules\\Verticals\\Http\\Controllers\\Api\\V1\\Concerns\\SummarizesVertical',
    ],
    'Juwri\\Modules\\Storefront\\Services\\ThemeStudio\\AiSectionAuthor' => [  // Storefront
        'Juwri\\Modules\\Ai\\Services\\AiProviderException',
    ],
    'Juwri\\Modules\\Storefront\\Observers\\MenuBannerObserver' => [  // Storefront
        'Juwri\\Modules\\Menu\\Jobs\\NotifyFollowersJob',
        'Juwri\\Modules\\Menu\\Observers\\MenuObserver',
    ],
    'Juwri\\Modules\\Verticals\\Http\\Controllers\\User\\GoodsCheckoutController' => [  // Verticals
        'Juwri\\Modules\\Ordering\\Services\\Fulfillment\\FulfillmentSettingsService',
        'Juwri\\Modules\\Ordering\\Services\\Orders\\SchedulingService',
    ],
    'Juwri\\Modules\\Verticals\\Http\\Controllers\\User\\RetailItemController' => [  // Verticals
        'Juwri\\Modules\\Menu\\Http\\Controllers\\User\\Concerns\\ImportsMenuCsvCore',
        'Juwri\\Modules\\Menu\\Services\\MenuAdminService',
    ],
    'Juwri\\Modules\\Verticals\\Http\\Controllers\\User\\ServiceItemController' => [  // Verticals
        'Juwri\\Modules\\Menu\\Services\\MenuAdminService',
    ],
    'Juwri\\Modules\\Verticals\\Http\\Controllers\\User\\RetailCategoryController' => [  // Verticals
        'Juwri\\Modules\\Menu\\Services\\MenuAdminService',
    ],
    'Juwri\\Modules\\Verticals\\Http\\Controllers\\User\\VariantMatrixController' => [  // Verticals
        'Juwri\\Modules\\Menu\\Services\\Catalog\\VariantMatrixGenerator',
    ],
    'Juwri\\Modules\\Verticals\\Services\\StoreVerticalConversionService' => [  // Verticals
        'Juwri\\Modules\\Ordering\\Services\\Fulfillment\\FulfillmentSettingsService',
    ],
    'Juwri\\Modules\\Verticals\\Services\\Fulfillment\\GoodsOrderService' => [  // Verticals
        'Juwri\\Modules\\Ordering\\Services\\Fulfillment\\FulfillmentSettingsService',
        'Juwri\\Modules\\Ordering\\Services\\Orders\\OrderPricing',
        'Juwri\\Modules\\Ordering\\Services\\Orders\\SchedulingService',
    ],
    'Juwri\\Modules\\Agents\\Tools\\StorefrontTools' => [  // Agents
        'Juwri\\Modules\\Hotel\\Services\\Hotel\\BookingService',
        'Juwri\\Modules\\Hotel\\Services\\Hotel\\RateService',
    ],
    'Juwri\\Modules\\Agents\\Tools\\OwnerTools' => [  // Agents
        'Juwri\\Modules\\Menu\\Services\\MenuAdminService',
        'Juwri\\Modules\\Ordering\\Enums\\OrderStatus',
    ],
    'Juwri\\Modules\\Agents\\Mcp\\Catalog\\CatalogTools' => [  // Agents
        'Juwri\\Modules\\Menu\\Services\\CatalogQueryService',
        'Juwri\\Modules\\Menu\\Services\\StarterMenuService',
    ],
    'Juwri\\Modules\\Content\\Http\\Controllers\\Studio\\StudioController' => [  // Content
        'Juwri\\Modules\\Menu\\Services\\Ai\\MenuGeneratorService',
    ],
    'Juwri\\Modules\\Content\\Services\\Blog\\Autopilot\\FakeAutopilotAi' => [  // Content
        'Juwri\\Modules\\Ai\\Services\\AiImageResult',
        'Juwri\\Modules\\Ai\\Services\\AiProviderException',
        'Juwri\\Modules\\Ai\\Services\\AiProviderFactory',
        'Juwri\\Modules\\Ai\\Services\\AiService',
    ],
    'Juwri\\Modules\\Content\\Services\\Studio\\StudioDraftImporter' => [  // Content
        'Juwri\\Modules\\Menu\\Services\\Ai\\MenuGeneratorService',
    ],
    'Juwri\\Modules\\Content\\Services\\LockedTemplates\\Renderer' => [  // Content
        'Juwri\\Modules\\Tables\\Services\\Qr\\QrRenderService',
    ],
    'Juwri\\Modules\\Pharmacy\\Http\\Controllers\\User\\PharmacyComplianceController' => [  // Pharmacy
        'Juwri\\Modules\\Menu\\Services\\MenuAdminService',
        'Juwri\\Modules\\Verticals\\Http\\Controllers\\User\\RetailItemController',
    ],
    'Juwri\\Modules\\Integrations\\Http\\Controllers\\User\\GloriaFoodImportController' => [  // Integrations
        'Juwri\\Modules\\Customers\\Services\\Import\\CustomerImporter',
    ],
    'Juwri\\Modules\\Integrations\\Http\\Controllers\\Admin\\SocialProvidersController' => [  // Integrations
        'Juwri\\Modules\\Auth\\Services\\Social\\AppleClientSecret',
    ],
    'Juwri\\Modules\\Integrations\\Services\\Import\\GloriaFoodImporter' => [  // Integrations
        'Juwri\\Modules\\Menu\\Services\\MenuAdminService',
    ],
    'Juwri\\Modules\\Laundry\\Http\\Controllers\\User\\LaundryBookingController' => [  // Laundry
        'Juwri\\Modules\\Ordering\\Services\\Fulfillment\\FulfillmentSettingsService',
        'Juwri\\Modules\\Ordering\\Services\\Orders\\SchedulingService',
        'Juwri\\Modules\\Verticals\\Http\\Controllers\\User\\GoodsCartController',
        'Juwri\\Modules\\Verticals\\Http\\Controllers\\User\\GoodsCheckoutController',
    ],
    'Juwri\\Modules\\Laundry\\Http\\Controllers\\User\\LaundryServiceBoardController' => [  // Laundry
        'Juwri\\Modules\\Verticals\\Http\\Controllers\\User\\RetailOrderBoardController',
        'Juwri\\Modules\\Verticals\\Services\\Fulfillment\\RetailOrderBoardService',
    ],
    'Juwri\\Modules\\Laundry\\Services\\Laundry\\WeightCaptureService' => [  // Laundry
        'Juwri\\Modules\\Ordering\\Jobs\\SendCustomerPushJob',
    ],
    'Juwri\\Modules\\Laundry\\Services\\Laundry\\ServiceBoardVocab' => [  // Laundry
        'Juwri\\Modules\\Verticals\\Services\\Fulfillment\\RetailOrderBoardService',
    ],
    // P1-13 13c: came in from app/Services (was outside the module rules); hotel meal plans ride Ordering's service.
    'Juwri\\Modules\\Hotel\\Services\\Hotel\\HotelService' => [  // Hotel
        'Juwri\\Modules\\Ordering\\Services\\MealPlanService',
    ],
    // P1-13 13c: came in from app/Services (was outside the module rules); it decorates the goods cart/checkout.
    'Juwri\\Modules\\Laundry\\Services\\Laundry\\ServiceBookingService' => [  // Laundry
        'Juwri\\Modules\\Verticals\\Services\\Fulfillment\\GoodsCartService',
        'Juwri\\Modules\\Verticals\\Services\\Fulfillment\\GoodsOrderService',
    ],
    'Juwri\\Modules\\Channels\\Http\\Controllers\\User\\MarnController' => [  // Channels
        'Juwri\\Modules\\Menu\\Services\\Catalog\\CatalogExport',
    ],
    'Juwri\\Modules\\Channels\\Http\\Controllers\\User\\FoodicsController' => [  // Channels
        'Juwri\\Modules\\Menu\\Services\\Catalog\\CatalogExport',
    ],
    'Juwri\\Modules\\Channels\\Services\\Aggregators\\Keeta\\KeetaMenuPusher' => [  // Channels
        'Juwri\\Modules\\Menu\\Services\\Catalog\\CatalogExport',
    ],
    'Juwri\\Modules\\Channels\\Services\\Aggregators\\Deliverect\\DeliverectMenuMapper' => [  // Channels
        'Juwri\\Modules\\Menu\\Services\\Catalog\\CatalogExport',
    ],
    'Juwri\\Modules\\Channels\\Services\\Aggregators\\Swiggy\\SwiggyMenuPusher' => [  // Channels
        'Juwri\\Modules\\Menu\\Services\\Catalog\\CatalogExport',
    ],
    'Juwri\\Modules\\Channels\\Services\\Aggregators\\Zomato\\ZomatoMenuPusher' => [  // Channels
        'Juwri\\Modules\\Menu\\Services\\Catalog\\CatalogExport',
    ],
    'Juwri\\Modules\\Channels\\Services\\Aggregators\\Jahez\\JahezMenuPusher' => [  // Channels
        'Juwri\\Modules\\Menu\\Services\\Catalog\\CatalogExport',
    ],
    'Juwri\\Modules\\Channels\\Services\\PosBridge\\Foodics\\FoodicsImporter' => [  // Channels
        'Juwri\\Modules\\Menu\\Services\\MenuAdminService',
    ],
    'Juwri\\Modules\\Channels\\Services\\PosBridge\\Marn\\MarnImporter' => [  // Channels
        'Juwri\\Modules\\Menu\\Services\\MenuAdminService',
    ],
    'Juwri\\Modules\\Pos\\Http\\Controllers\\SignageController' => [  // Pos
        'Juwri\\Modules\\Ordering\\Enums\\OrderStatus',
    ],
    'Juwri\\Modules\\Pos\\Http\\Controllers\\Api\\V1\\StaffAppController' => [  // Pos
        'Juwri\\Modules\\Menu\\Services\\MenuAdminService',
        'Juwri\\Modules\\Team\\Http\\Middleware\\StaffTokenAuth',
    ],
    'Juwri\\Modules\\Pos\\Http\\Controllers\\Staff\\PosController' => [  // Pos
        'Juwri\\Modules\\Menu\\Services\\Availability\\MenuScheduleService',
        'Juwri\\Modules\\Menu\\Services\\Combos\\ComboService',
        'Juwri\\Modules\\Ordering\\Services\\Orders\\OrderPricing',
        'Juwri\\Modules\\Tables\\Services\\SplitBillService',
        'Juwri\\Modules\\Tables\\Services\\TableMergeService',
        'Juwri\\Modules\\Tables\\Services\\TableSessionService',
    ],
    'Juwri\\Modules\\Pos\\Services\\Printing\\EscPosRenderer' => [  // Pos
        'Juwri\\Modules\\Tables\\Services\\TableMergeService',
    ],
    'Juwri\\Modules\\Pos\\Services\\Waiter\\WaiterService' => [  // Pos
        'Juwri\\Modules\\Tables\\Services\\TableMergeService',
    ],
    'Juwri\\Modules\\Analytics\\Http\\Controllers\\User\\AnalyticsController' => [  // Analytics
        'Juwri\\Modules\\Menu\\Services\\MenuEngineeringService',
    ],
    'Juwri\\Modules\\Loyalty\\Http\\Controllers\\User\\PriceScheduleController' => [  // Loyalty
        'Juwri\\Modules\\Menu\\Services\\Availability\\MenuScheduleService',
    ],
    'Juwri\\Modules\\Kitchen\\Http\\Controllers\\User\\KdsController' => [  // Kitchen
        'Juwri\\Modules\\Ordering\\Services\\Orders\\EtaEstimator',
        'Juwri\\Modules\\Ordering\\Services\\Orders\\SchedulingService',
        'Juwri\\Modules\\Tables\\Services\\TableMergeService',
    ],
];
