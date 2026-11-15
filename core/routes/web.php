<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD
// Shared throttle prefixes on purpose (one counter across all routes using it): staff-auth (staff login, staff PIN, POS unlock, kiosk PIN: one limit of 10 a minute).

use App\Http\Controllers\Admin;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PreflightPhpController;
use App\Http\Controllers\User;
use App\Http\Controllers\User\MerchantCampaignUnsubscribeController;
use App\Http\Middleware\PreventRequestForgery;
use App\Services\PlatformMetrics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Juwri\Core\Http\Controllers\Admin\LegalSettingsController;
use Juwri\Core\Http\Controllers\MaintenanceController;
use Juwri\Core\Http\Middleware\AddCspHeaders;
use Juwri\Core\Support\LocalizedRoutes;
use Juwri\Core\Support\ModuleRoutes;
use Juwri\Modules\AppStore\Http\Controllers\User\AppStoreBrowseController;
use Juwri\Modules\LegacyCompat\Support\LegacyCompatRoutes;
use Juwri\Modules\Marketing\Http\Controllers\User\CampaignController;
use Juwri\Modules\Messaging\Services\MailEvents\MailEventWebhook;
use Juwri\Modules\Pos\Http\Middleware\KioskStorefrontShell;
use Juwri\Modules\Storefront\Http\Controllers\FolioMetricsController;
use Juwri\Modules\Storefront\Http\Middleware\EnforceStorefrontCspPerTemplate;
use Juwri\Modules\Storefront\Http\Middleware\MarkAnonymousPage;
use Juwri\Modules\Verticals\Http\Controllers\User\StoreVerticalController;

ModuleRoutes::slot('Core/web-1');

/* J52b LegacyCompat: registers nothing unless legacy_compat.enabled is true (migrated installs only). Placed
   first so old printed URLs win over newer feature paths and the {slug} catch-all. */
LegacyCompatRoutes::register();
/* J52b plan §5.4: imported slugs that a newer path shadows still open their store page on the old platform's hosts
   (same action + middleware as the {slug} page below; session-mode locale, no prefix redirect). Nothing while off. */
LegacyCompatRoutes::registerSlugConflicts('User\StoreController@publicView', ['app.locale', 'cache.storefront', EnforceStorefrontCspPerTemplate::class, AddCspHeaders::class, KioskStorefrontShell::class, MarkAnonymousPage::class]);

/* Setup notice — where the `installed` gate sends requests until the app has
   an APP_KEY and a migrated database (setup = .env + migrate --seed). */
Route::get('/setup', '\Juwri\Core\Http\Controllers\SetupPendingController')->middleware('csp.marketing')->name('setup.pending'); // P6-122b

/* P6-123: one-shot web PHP version probe for `php artisan juwri:preflight` (token header, single use). */
Route::get('/_preflight/php', PreflightPhpController::class)->middleware('throttle:10,1,preflight')->name('preflight.php');

/* Routs With Laravel Localization */
if (! config('settings.include_language_code')) {
    $middlewares = [
        'middleware' => ['installed', 'checkUserIsBanned', 'app.locale', 'csp.marketing', 'cache.marketing'], // P4-17 follow-up: CSP on marketing + /login (wraps cache hits)
    ];
} else {
    $middlewares = [
        'localized' => true,
        'middleware' => ['installed', 'checkUserIsBanned', 'localize', 'localizationRedirect', 'locale.remember', 'csp.marketing', 'cache.marketing'], // P4-17 follow-up: CSP on marketing + /login
    ];
}

LocalizedRoutes::group($middlewares, function () {

    /* AUTH ROUTES */
    require __DIR__.'/auth.php';

    /* FRONTEND ROUTES */
    Route::controller(HomeController::class)->group(function () {
        Route::get('/', 'index')->name('home');
        Route::get('faq', 'faqs')->name('faqs');
        // Pitch routes (features, pricing, tools, country pages) register from the edition package.
        ModuleRoutes::slot('PlatformSite/web-1');
        ModuleRoutes::slot('Reseller/web-1');
        Route::get('contact', 'contact')->name('contact');
        Route::post('contact', 'contactSend')->middleware(['throttle:public-form', 'ratelimit:public-write'])->name('contact.send');
        Route::get('feedback', 'feedback')->name('feedback');
        Route::post('feedback', 'feedbackSend')->middleware(['throttle:public-form', 'ratelimit:public-write'])->name('feedback.send');
        Route::get('page/{slug}', 'page')->name('page');
        Route::post('newsletter', 'newsletter')->middleware('throttle:newsletter')->name('newsletter');

        if (! config('settings.include_language_code')) {
            Route::get('lang/{lang}', 'localize')->where('lang', '^[a-z]{2}$')->name('localize');
        }
    });

    /* P1-23: website legal pages (/terms, /privacy, /security, /legal/*). */
    ModuleRoutes::slot('Core/legal-1');

    /* MCP-PAGE (D2-9): GET /mcp renders the public landing page (DEC-2) — the
       marketing + setup surface. Lives INSIDE the localized group so the page's
       language picker works in BOTH locale modes exactly like the home page:
       session mode via app.locale, URL-prefix mode via /{locale}/mcp
       (lang_url() emits prefixed URLs there — a route outside this group 404s
       on /en/mcp). POST /mcp protocol behaviour is untouched (apiv1.php). */
    Route::get('mcp', '\Juwri\Modules\Agents\Http\Controllers\McpLandingController@show')->name('mcp.landing');

    Route::controller('\Juwri\Modules\Content\Http\Controllers\BlogController')->group(function () {
        Route::get('/blog', 'index')->name('blog.index');
        Route::get('blog/category/{slug}', 'category')->name('blog.category');
        Route::get('blog/tags/{slug}', 'tag')->name('blog.tag');
        Route::get('blog/{id}/{slug?}', 'single')->name('blog.single');
        Route::post('blog/{id}/{slug?}', 'comment')->name('blog.comment')->middleware('throttle:10,1,blog-comment');
    });

    /* FRONTEND LOGIN REQUIRED */
    Route::group(['namespace' => 'User', 'middleware' => ['auth', 'staff.dashboard', 'dashboard.sync', 'reseller.suspension', 'abuse.hold', 'csp.dashboard', 'owner.screen']], function () { // S12 CSP; owner.screen = PWA usage stats

        // rev/multi-vertical F0-7: per-lane dashboard routes (conflict-free merges).
        // Each lane owns routes/verticals/lane-<x>.php; they inherit this group's
        // User namespace + dashboard middleware. glob() is ordered (a..f).
        foreach (glob(base_path('routes/verticals/lane-*.php')) ?: [] as $__verticalLane) {
            require $__verticalLane;
        }

        // P1-23: accept changed legal documents (dashboard banner).
        ModuleRoutes::slot('Core/legal-2');

        Route::get('dashboard', [User\DashboardController::class, 'index'])->name('dashboard');
        // Owner dashboard PWA: owner web-push subscriptions for the current store.
        Route::post('dashboard/pwa/push/subscribe', '\\App\\Http\\Controllers\\OwnerPwaController@subscribe')->middleware('throttle:30,1,core-dashboard-pwa-push-subscribe')->name('owner.pwa.push.subscribe');
        Route::post('dashboard/pwa/push/unsubscribe', '\\App\\Http\\Controllers\\OwnerPwaController@unsubscribe')->middleware('throttle:30,1,core-dashboard-pwa-push-unsubscribe')->name('owner.pwa.push.unsubscribe');
        // Owner UI kit reference page (assets/owner-ui + <x-ui.*>); 404 outside local/testing unless admin.
        Route::get('dashboard/ui-kit', '\\Juwri\\Core\\Http\\Controllers\\OwnerUiKitController@show')->name('owner.ui.kit');
        Route::post('dashboard/context', 'DashboardController@switchContext')->name('dashboard.context');
        Route::post('dashboard/layout', 'DashboardController@saveLayout')->name('dashboard.layout');
        Route::get('dashboard/notifications', 'DashboardController@notificationCounts')->middleware('ratelimit:dashboard_poll')->name('dashboard.notifications');
        // D11-3: stamp the notification read watermark when the bell is opened.
        Route::post('dashboard/notifications/seen', 'DashboardController@markNotificationsSeen')->name('dashboard.notifications.seen');
        Route::get('dashboard/notification-preferences', 'DashboardController@notificationPrefs')->name('dashboard.notifPrefs');
        Route::post('dashboard/notification-preferences', 'DashboardController@saveNotificationPrefs')->name('dashboard.notifPrefs.save');
        Route::get('dashboard/notification-sounds', 'DashboardController@soundSettings')->name('dashboard.sounds');
        Route::post('dashboard/notification-sounds', 'DashboardController@saveSoundSettings')->name('dashboard.sounds.save');
        Route::post('dashboard/notification-sounds/upload', 'DashboardController@uploadSound')->name('dashboard.sounds.upload');
        Route::post('dashboard/notification-sounds/delete', 'DashboardController@deleteSound')->name('dashboard.sounds.delete');
        Route::get('dashboard/search', 'DashboardController@search')->middleware('throttle:search')->name('dashboard.search');
        // FIX-2 F2-12: the assistant DRAWER's XHR endpoint is the side door — the page
        // that renders it is /dashboard (never gated), so the only thing standing
        // between an unentitled owner and the paid assistant was the controller's
        // kill-switch abort. `feature:` adds the entitlement lane (JSON/POST → 403).
        Route::post('dashboard/assistant', 'DashboardController@assistant')->middleware('feature:ai_assistant')->name('dashboard.assistant');
        Route::get('reports', 'DashboardController@reports')->name('reports.index');
        // K4: tip pooling report (per-staff tips + pooled shares) + CSV export.
        Route::get('reports/tips', '\Juwri\Modules\Hr\Http\Controllers\User\TipPoolController@index')->middleware('ratelimit:export')->name('reports.tips');
        Route::post('dashboard/pins', 'DashboardController@togglePin')->name('dashboard.pins');
        Route::post('onboarding/dismiss', 'DashboardController@dismissOnboarding')->name('onboarding.dismiss');
        Route::post('onboarding/restore', 'DashboardController@restoreOnboarding')->name('onboarding.restore');
        Route::post('security-nudge/dismiss', 'DashboardController@dismissSecurityNudge')->name('security-nudge.dismiss'); // P6-47
        ModuleRoutes::slot('Analytics/ee-web-1');

        /* WhatsApp channel settings (per-restaurant) */
        Route::get('stores/{store}/whatsapp-settings', 'WhatsAppSettingsController@index')->name('stores.whatsappSettings');
        /* WA-C3: the QuickOrder plugin page moved here. Its route disappears with the
           plugin, so keep the old URL alive as a redirect for bookmarks and for any
           link still pointing at it. Registered in core, ahead of the plugin's own
           route, so it survives the plugin's removal in Lane E. */
        Route::get('stores/{store}/whatsapp-ordering', function ($store) {
            return redirect()->route('stores.whatsappSettings', $store);
        })->name('stores.whatsappOrderingLegacy');
        /* WA-D3: dismiss the "place your extra WhatsApp numbers" notice. Stored as
           a post option rather than localStorage so it sticks across devices. */
        Route::post('stores/{store}/whatsapp-extras/dismiss', 'WhatsAppSettingsController@dismissExtrasNotice')
            ->name('stores.whatsappExtras.dismiss');
        Route::post('stores/{store}/whatsapp-settings', 'WhatsAppSettingsController@update');

        ModuleRoutes::slot('Marketplace/web-3');

        /* WhatsApp team inbox */
        Route::get('stores/{store}/whatsapp-inbox', '\Juwri\Modules\Messaging\Http\Controllers\User\WhatsAppInboxController@index')->name('stores.whatsappInbox');
        Route::post('stores/{store}/whatsapp-inbox/reply', '\Juwri\Modules\Messaging\Http\Controllers\User\WhatsAppInboxController@reply')->name('stores.whatsappInbox.reply');
        // SYSTEMS-RUN S-P2: shared-inbox upgrade (status/assign/canned).
        Route::post('stores/{store}/whatsapp-inbox/status', '\Juwri\Modules\Messaging\Http\Controllers\User\WhatsAppInboxController@setStatus')->name('stores.whatsappInbox.status');
        Route::post('stores/{store}/whatsapp-inbox/assign', '\Juwri\Modules\Messaging\Http\Controllers\User\WhatsAppInboxController@assign')->name('stores.whatsappInbox.assign');
        Route::post('stores/{store}/whatsapp-inbox/canned', '\Juwri\Modules\Messaging\Http\Controllers\User\WhatsAppInboxController@storeCanned')->name('stores.whatsappInbox.canned');

        /* WhatsApp marketing broadcast */
        Route::get('stores/{store}/whatsapp-campaign', 'WhatsAppCampaignController@index')->name('stores.whatsappCampaign');
        Route::post('stores/{store}/whatsapp-campaign', 'WhatsAppCampaignController@send')->name('stores.whatsappCampaign.send');

        /* WhatsApp approved-template registry */
        Route::get('stores/{store}/whatsapp-templates', '\Juwri\Modules\Messaging\Http\Controllers\User\WhatsAppTemplateController@index')->name('stores.whatsappTemplates');
        Route::post('stores/{store}/whatsapp-templates', '\Juwri\Modules\Messaging\Http\Controllers\User\WhatsAppTemplateController@store')->name('stores.whatsappTemplates.store');
        Route::delete('stores/{store}/whatsapp-templates/{template}', '\Juwri\Modules\Messaging\Http\Controllers\User\WhatsAppTemplateController@destroy')->name('stores.whatsappTemplates.destroy');
        Route::post('dashboard/goal', 'DashboardController@saveGoal')->name('dashboard.goal.save');
        // #29: Reviews management
        Route::get('stores/{store}/reviews', 'DashboardController@reviews')->name('dashboard.reviews');
        Route::post('stores/{store}/reviews/{review}/status', 'DashboardController@updateReview')->name('dashboard.reviews.update');

        ModuleRoutes::slot('Customers/web-1');

        Route::resource('stores', User\StoreController::class);

        // #34 (#10): translation review screen
        Route::get('stores/{store}/translations', '\Juwri\Modules\Menu\Http\Controllers\User\TranslationReviewController@index')->name('stores.translations.review');
        Route::post('stores/{store}/translations/save', '\Juwri\Modules\Menu\Http\Controllers\User\TranslationReviewController@save')->name('stores.translations.save');
        Route::post('stores/{store}/translations/approve', '\Juwri\Modules\Menu\Http\Controllers\User\TranslationReviewController@approveAll')->name('stores.translations.approve');

        ModuleRoutes::slot('Storefront/web-1');

        Route::controller(User\StoreController::class)->group(function () {
            // rev/external-redirect B2: owner dashboard "Disable" button on the
            // "public menu redirects to …" banner (ownership-checked in the method).
            Route::post('stores/{store}/external-redirect/disable', 'disableExternalRedirect')->name('stores.externalRedirect.disable');

            Route::get('stores/{store}/qr-designer', 'qrBuilder')->name('stores.qrDesigner');
            // Server-rendered QR image (the old client jQuery plugin no longer ships).
            // Deliberately no ".svg" extension: nginx static-asset location blocks
            // (~* \.(svg|...)$) would intercept it and 404 before Laravel runs.
            Route::get('stores/{store}/qr-svg', 'qrSvg')->name('stores.qrSvg');
            Route::post('stores/{store}/qr-designer', 'qrBuilderSave');
            Route::get('stores/{store}/menu', 'menu')->name('stores.menu');

            Route::post('stores/{store}/add-category', 'addCategory')->name('stores.addCategory');
            Route::post('stores/{store}/update-category', 'updateCategory')->name('stores.updateCategory');
            Route::post('stores/{store}/delete-category', 'deleteCategory')->name('stores.deleteCategory');
            Route::post('stores/{store}/reorder-category', 'reorderCategory')->name('stores.reorderCategory');

            Route::post('stores/{store}/add-sub-category', 'addSubCategory')->name('stores.addSubCategory');
            Route::post('stores/{store}/update-sub-category', 'updateSubCategory')->name('stores.updateSubCategory');

            Route::post('stores/{store}/add-menu-item', 'addMenuItem')->name('stores.addMenuItem');
            Route::post('stores/{store}/update-menu-item', 'updateMenuItem')->name('stores.updateMenuItem');
            Route::post('stores/{store}/quick-image-menu-item', 'quickImageMenuItem')->name('stores.quickImageMenuItem');
            Route::post('stores/{store}/quick-edit-menu-item', 'quickEditMenuItem')->name('stores.quickEditMenuItem');
            Route::post('stores/{store}/allergens', 'storeAllergen')->name('stores.storeAllergen');
            Route::delete('stores/{store}/allergens/{allergen}', 'destroyAllergen')->name('stores.destroyAllergen');
            Route::post('stores/{store}/delete-menu-item', 'deleteMenuItem')->name('stores.deleteMenuItem');
            Route::post('stores/{store}/reorder-menu-item', 'reorderMenuItem')->name('stores.reorderMenuItem');
            Route::post('stores/{store}/menu/bulk', 'menuBulkAction')->name('stores.menuBulk');
            // D7: one-click un-86 (clear sold-out) for the Needs Attention card.
            Route::post('stores/{store}/menu/un86', 'menuUn86')->name('stores.menu.un86');
            // D4: undo/trash for menu items.
            Route::get('stores/{store}/menu/trash', 'menuTrash')->name('stores.menu.trash');
            Route::post('stores/{store}/menu/restore', 'menuRestore')->name('stores.menu.restore');
            Route::post('stores/{store}/clone', 'cloneRestaurant')->name('stores.clone');

            // Store vertical CONVERT (in place) + DUPLICATE-as-a-different-vertical.
            // A separate controller, so an explicit ::class action is used instead of
            // a bare 'method' string (the latter would resolve against the enclosing
            // Route::controller('StoreController') shorthand, not this controller).
            Route::post('stores/{store}/convert', [StoreVerticalController::class, 'convert'])->name('stores.convert');
            Route::post('stores/{store}/duplicate-vertical', [StoreVerticalController::class, 'duplicateVertical'])->name('stores.duplicateVertical');

            // #10: Menu CSV import/export
            Route::get('stores/{store}/menu/export-csv', 'menuExportCsv')->middleware('ratelimit:export')->name('stores.menuExportCsv');
            Route::post('stores/{store}/menu/import-csv', 'menuImportCsv')->name('stores.menuImportCsv');

            Route::get('stores/{store}/menu/{menu}/extras', 'menuItemExtras')->name('stores.menuItemExtras');

            // AI Menu Generator routes
            Route::post('stores/{store}/menu/ai-generate', 'aiGenerateMenu')->middleware('ratelimit:ai')->name('stores.menu.aiGenerate');
            // S1-P2: merge multi-page / multi-image drafts into one before review
            Route::post('stores/{store}/menu/ai-merge', 'aiMergeMenu')->middleware('ratelimit:ai')->name('stores.menu.aiMerge');
            Route::post('stores/{store}/menu/ai-save', 'aiSaveMenu')->name('stores.menu.aiSave');
            // #34: AI auto-translate missing menu translations
            Route::post('stores/{store}/menu/ai-translate', 'aiTranslateMenu')->middleware('ratelimit:ai')->name('stores.menu.aiTranslate');
            Route::get('stores/{store}/menu/ai-translate/progress', 'aiTranslateProgress')->name('stores.menu.aiTranslateProgress');
            // Background AI item-photo generation progress
            Route::get('stores/{store}/menu/ai-images/progress', 'aiMenuImagesProgress')->name('stores.menu.aiImagesProgress');

            Route::post('stores/{store}/menu/{menu}/extras/add-variant-option', 'menuAddVariantOption')->name('stores.menuAddVariantOption');
            Route::post('stores/{store}/menu/{menu}/extras/update-variant-option', 'menuUpdateVariantOption')->name('stores.menuUpdateVariantOption');
            Route::post('stores/{store}/menu/{menu}/extras/delete-variant-option', 'menuDeleteVariantOption')->name('stores.menuDeleteVariantOption');
            Route::post('stores/{store}/menu/{menu}/extras/reorder-variant-option', 'menuReorderVariantOption')->name('stores.menuReorderVariantOption');

            Route::post('stores/{store}/menu/{menu}/extras/add-variant', 'menuAddVariant')->name('stores.menuAddVariant');
            Route::post('stores/{store}/menu/{menu}/extras/update-variant', 'menuUpdateVariant')->name('stores.menuUpdateVariant');
            Route::post('stores/{store}/menu/{menu}/extras/delete-variant', 'menuDeleteVariant')->name('stores.menuDeleteVariant');
            Route::post('stores/{store}/menu/{menu}/extras/reorder-variant', 'menuReorderVariant')->name('stores.menuReorderVariant');

            Route::post('stores/{store}/menu/{menu}/extras/add-extra', 'menuAddExtra')->name('stores.menuAddExtra');
            Route::post('stores/{store}/menu/{menu}/extras/update-extra', 'menuUpdateExtra')->name('stores.menuUpdateExtra');
            Route::post('stores/{store}/menu/{menu}/extras/delete-extra', 'menuDeleteExtra')->name('stores.menuDeleteExtra');
            Route::post('stores/{store}/menu/{menu}/extras/reorder-extra', 'menuReorderExtra')->name('stores.menuReorderExtra');

            Route::post('stores/{store}/add-image-menu-item', 'addImageMenuItem')->middleware('ratelimit:upload')->name('stores.addImageMenuItem');
            Route::post('stores/{store}/update-image-menu-item', 'updateImageMenuItem')->middleware('ratelimit:upload')->name('stores.updateImageMenuItem');
            Route::post('stores/{store}/delete-image-menu-item', 'deleteImageMenuItem')->name('stores.deleteImageMenuItem');
            Route::post('stores/{store}/reorder-image-menu-item', 'reorderImageMenuItem')->name('stores.reorderImageMenuItem');

            Route::get('stores/{store}/tables', 'tables')->name('stores.tables');
            Route::post('stores/{store}/update-table', 'updateTable')->name('stores.updateTable');
            Route::post('stores/{store}/delete-table', 'deleteTable')->name('stores.deleteTable');

            // D9: orders CSV export (behind ratelimit:export, honours list filters).
            Route::get('stores/{store}/orders/export-csv', 'ordersExportCsv')->middleware('ratelimit:export')->name('stores.ordersExportCsv');
            Route::get('orders/{store?}', 'orders')->middleware('ratelimit:dashboard_poll')->name('stores.orders');
            // Owner redesign: one order on its own screen (push / native app deep link).
            Route::get('order/{order}', '\\Juwri\\Modules\\Ordering\\Http\\Controllers\\User\\OwnerOrderController@show')->middleware('tenant.owns')->name('stores.orders.show');
            Route::post('order/{order}/tax-invoice', '\\Juwri\\Modules\\Ordering\\Http\\Controllers\\User\\OwnerOrderController@taxInvoice')->middleware(['tenant.owns', 'throttle:10,1,owner-order-tax-invoice'])->name('stores.orders.taxInvoice'); // P6-176 B2B
            Route::get('order/{order}/receipt.pdf', '\\Juwri\\Modules\\Ordering\\Http\\Controllers\\User\\OwnerOrderController@receiptPdf')->name('stores.orders.receiptPdf');
            Route::post('order/{order}/complete', 'completeOrder')->name('stores.completeOrder');
            Route::post('order/{order}/status', 'updateOrderStatus')->middleware('tenant.owns')->name('stores.updateOrderStatus'); // S13 IDOR backstop
            Route::post('order/{order}/refund-credit', 'refundOrderToCredit')->name('stores.refundOrderToCredit'); // Coupons&Wallets P3-5
            Route::post('order/{order}/refund-original', 'refundOrderToOriginal')->name('stores.refundOrderToOriginal'); // refund to original card/gateway
            Route::post('order/{order}/refund-reject', 'rejectRefundRequest')->name('stores.rejectRefundRequest');
            // P3-06b (API-21, API-27, API-28): late payments of headless hosted orders — accept or refund.
            Route::get('late-payments', '\\Juwri\\Modules\\Ordering\\Http\\Controllers\\User\\LatePaymentsController@index')->name('stores.latePayments.index');
            Route::post('order/{order}/late-payment/accept', '\\Juwri\\Modules\\Ordering\\Http\\Controllers\\User\\LatePaymentsController@accept')->middleware('tenant.owns')->name('stores.latePayments.accept');
            Route::post('order/{order}/late-payment/refund', '\\Juwri\\Modules\\Ordering\\Http\\Controllers\\User\\LatePaymentsController@refund')->middleware('tenant.owns')->name('stores.latePayments.refund');
            // P3-09 (API-25, API-30, API-31): grocery picking — the list, one order's lines, and one picking step per post.
            Route::get('grocery/picking', '\\Juwri\\Modules\\Ordering\\Http\\Controllers\\User\\GroceryPickingController@index')->name('stores.picking.index');
            Route::get('order/{order}/picking', '\\Juwri\\Modules\\Ordering\\Http\\Controllers\\User\\GroceryPickingController@show')->middleware('tenant.owns')->name('stores.picking.show');
            Route::post('order/{order}/picking', '\\Juwri\\Modules\\Ordering\\Http\\Controllers\\User\\GroceryPickingController@step')->middleware('tenant.owns')->name('stores.picking.step');
            // P3-09: grocery settings (controller-scoped: the dashboard actor's current store, scalar item ids).
            Route::controller('\\Juwri\\Modules\\Ordering\\Http\\Controllers\\User\\GrocerySettingsController')->prefix('grocery/settings')->name('stores.grocery.')->group(function () {
                Route::get('/', 'edit')->name('settings');
                Route::post('slots', 'saveSlots')->name('slots');
                Route::post('weighed', 'saveWeighed')->name('weighed');
                Route::post('substitutions', 'saveSubstitutions')->name('substitutions');
                Route::post('item', 'saveItem')->name('item');
            });
            // P3-08: shipping zones / methods and goods returns (controller-scoped: the dashboard actor's current store, scalar ids).
            Route::controller('\\Juwri\\Modules\\Ordering\\Http\\Controllers\\User\\ShippingReturnsController')->prefix('retail')->name('stores.retail.')->group(function () {
                Route::get('shipping', 'shipping')->name('shipping');
                Route::post('shipping/zones', 'saveZone')->name('zones.store');
                Route::post('shipping/zones/{zone}', 'saveZone')->whereNumber('zone')->name('zones.update');
                Route::post('shipping/zones/{zone}/delete', 'deleteZone')->whereNumber('zone')->name('zones.delete');
                Route::post('shipping/methods', 'saveMethod')->name('methods.store');
                Route::post('shipping/methods/{method}', 'saveMethod')->whereNumber('method')->name('methods.update');
                Route::post('shipping/methods/{method}/delete', 'deleteMethod')->whereNumber('method')->name('methods.delete');
                Route::get('returns', 'returns')->name('returns');
                Route::post('returns/{return}/decide', 'decideReturn')->whereNumber('return')->name('returns.decide');
                Route::post('returns/{return}/receive', 'receiveReturn')->whereNumber('return')->name('returns.receive');
                Route::post('returns/{return}/complete', 'completeReturn')->whereNumber('return')->name('returns.complete');
            });
            Route::controller('\\Juwri\\Modules\\Menu\\Http\\Controllers\\User\\RetailCatalogueController')->prefix('retail')->name('stores.retail.')->group(function () {
                Route::get('size-guides', 'sizeGuides')->name('sizeGuides');
                Route::post('size-guides', 'saveSizeGuide')->name('sizeGuides.store');
                Route::post('size-guides/{guide}', 'saveSizeGuide')->whereNumber('guide')->name('sizeGuides.update');
                Route::post('size-guides/{guide}/delete', 'deleteSizeGuide')->whereNumber('guide')->name('sizeGuides.delete');
                Route::get('items/{menu}/variants', 'variants')->whereNumber('menu')->name('variants');
                Route::post('items/{menu}/variants/groups', 'saveGroups')->whereNumber('menu')->name('variants.groups');
                Route::post('items/{menu}/variants/generate', 'generate')->whereNumber('menu')->name('variants.generate');
                Route::post('items/{menu}/variants/cells', 'saveCells')->whereNumber('menu')->name('variants.cells');
            });
            Route::post('order/{order}/delete', 'deleteOrder')->name('stores.deleteOrder');
            // Syria A4: owner confirms/rejects a wallet_transfer payment claim (store-scoped).
            Route::post('order/{order}/wallet-transfer/confirm', 'confirmWalletTransfer')->name('stores.walletTransfer.confirm');
            Route::post('order/{order}/wallet-transfer/reject', 'rejectWalletTransfer')->name('stores.walletTransfer.reject');
            // P1-23 part 6: owner confirms/rejects a store payment (gift card, top-up, deposit) paid by wallet or bank transfer.
            Route::post('store-payment/{transaction}/transfer/confirm', '\Juwri\Modules\Payments\Http\Controllers\User\StoreTransferController@confirm')->name('stores.storeTransfer.confirm');
            Route::post('store-payment/{transaction}/transfer/reject', '\Juwri\Modules\Payments\Http\Controllers\User\StoreTransferController@reject')->name('stores.storeTransfer.reject');

            Route::resource('customers', 'CustomerController')->only(['index', 'show', 'update']);
            Route::get('customers-export', 'CustomerController@export')->middleware('ratelimit:export')->name('customers.export');
            Route::post('customers/{customer}/recalculate', 'CustomerController@recalculate')->name('customers.recalculate'); // M4

            ModuleRoutes::slot('Customers/web-2');
            // FEATURES-5 Track C: authorized staff playback of a customer voice note.
            Route::get('orders/{order}/voice-note', '\Juwri\Modules\Ordering\Http\Controllers\User\VoiceNoteController@staffStream')->name('stores.orders.voiceNote');
            // #79: Activity log
            Route::get('activity-log', '\Juwri\Core\Http\Controllers\User\ActivityLogController@index')->name('activity-log.index');
            ModuleRoutes::slot('Reseller/web-4');
            // #59: Referral / affiliate
            // MONETIZATION B6: gated behind feature:referrals (dark until V0).
            Route::get('referral', '\Juwri\Modules\Marketing\Http\Controllers\User\ReferralController@index')->middleware('feature:referrals')->name('referral.index');
            // MONETIZATION B4: the basic analytics dashboard stays FREE; the Pro
            // extras — CSV export and menu-engineering — gate behind
            // `feature:analytics_pro`. Dark until V0.
            Route::get('analytics', 'AnalyticsController@index')->name('analytics.index');
            Route::get('analytics/export', 'AnalyticsController@exportCsv')->middleware(['feature:analytics_pro', 'ratelimit:export'])->name('analytics.export');
            // K9 (dark): AI analytics copilot + peer-benchmark Insights page.
            Route::get('analytics/insights', '\Juwri\Modules\Analytics\Http\Controllers\User\AnalyticsCopilotController@index')->name('analytics.insights');
            Route::post('analytics/insights/ask', '\Juwri\Modules\Analytics\Http\Controllers\User\AnalyticsCopilotController@ask')->middleware('throttle:20,1,core-analytics-insights-ask')->name('analytics.insights.ask');
            Route::get('analytics/{storeId}/menu-engineering', 'AnalyticsController@menuEngineering')->middleware('feature:analytics_pro')->name('analytics.menuEngineering');
            ModuleRoutes::slot('Reservations/web-1');
            ModuleRoutes::slot('Tables/web-1');
            ModuleRoutes::slot('Channels/web-1');
            ModuleRoutes::slot('Billing/web-1');
            ModuleRoutes::slot('Kitchen/web-1');
            ModuleRoutes::slot('Menu/web-1');

            ModuleRoutes::slot('Storefront/web-2');
            // Media library (reusable image assets)
            Route::get('media', '\Juwri\Modules\Media\Http\Controllers\User\MediaController@index')->name('media.index');
            Route::get('media/list', '\Juwri\Modules\Media\Http\Controllers\User\MediaController@list')->name('media.list');
            Route::post('media', '\Juwri\Modules\Media\Http\Controllers\User\MediaController@store')->middleware('ratelimit:upload')->name('media.store');
            Route::delete('media/{asset}', '\Juwri\Modules\Media\Http\Controllers\User\MediaController@destroy')->name('media.destroy');
            ModuleRoutes::slot('Menu/web-2');
            // #75: Staff accounts
            Route::get('staff', '\Juwri\Modules\Team\Http\Controllers\User\StaffController@index')->name('staff.index');
            Route::post('staff', '\Juwri\Modules\Team\Http\Controllers\User\StaffController@store')->name('staff.store');
            Route::put('staff/{id}', '\Juwri\Modules\Team\Http\Controllers\User\StaffController@update')->name('staff.update');
            Route::delete('staff/{id}', '\Juwri\Modules\Team\Http\Controllers\User\StaffController@destroy')->name('staff.destroy');

            ModuleRoutes::slot('Hr/web-1');
            ModuleRoutes::slot('Inventory/web-1');
            // #20: Split bill / pay-by-seat
            Route::get('orders/{order}/split', '\Juwri\Modules\Tables\Http\Controllers\User\SplitBillController@show')->name('orders.split.show');
            Route::post('orders/{order}/split', '\Juwri\Modules\Tables\Http\Controllers\User\SplitBillController@store')->name('orders.split.store');
            Route::delete('orders/{order}/split', '\Juwri\Modules\Tables\Http\Controllers\User\SplitBillController@destroy')->name('orders.split.destroy');
            Route::post('split-shares/{share}/pay', '\Juwri\Modules\Tables\Http\Controllers\User\SplitBillController@pay')->name('orders.split.pay');
            // #76: Multiple branches per restaurant
            Route::get('branches', 'BranchController@index')->name('branches.index');
            Route::post('branches', 'BranchController@store')->name('branches.store');
            Route::put('branches/switcher', 'BranchController@switcher')->name('branches.switcher');
            Route::put('branches/{branch}', 'BranchController@update')->name('branches.update');
            Route::put('branches/{branch}/toggle', 'BranchController@toggle')->name('branches.toggle');
            Route::put('branches/{branch}/hours', 'BranchController@updateHours')->name('branches.hours');
            Route::post('branches/{branch}/exceptions', 'BranchController@storeException')->name('branches.exceptions.store');
            Route::delete('branches/{branch}/exceptions/{exception}', 'BranchController@destroyException')->name('branches.exceptions.destroy');
            Route::get('branches/qr/{store}', 'BranchController@qr')->name('branches.qr');
            Route::get('branches/reports', '\Juwri\Modules\Analytics\Http\Controllers\User\BranchReportController@index')->name('branches.reports');
            Route::get('branches/{branch}/menu', '\Juwri\Modules\Menu\Http\Controllers\User\BranchMenuController@edit')->name('branches.menu.edit');
            Route::post('branches/{branch}/menu', '\Juwri\Modules\Menu\Http\Controllers\User\BranchMenuController@update')->name('branches.menu.update');
            Route::delete('branches/{branch}', 'BranchController@destroy')->name('branches.destroy');
            ModuleRoutes::slot('Reservations/web-2');
            ModuleRoutes::slot('Integrations/web-1');
            ModuleRoutes::slot('Storefront/web-3');
            // Owner AI-ordering (agents) settings
            Route::get('ai-ordering', '\Juwri\Modules\Agents\Http\Controllers\User\AgentSettingsController@page')->name('agent.settings.page');
            Route::post('ai-ordering/{storeId}', '\Juwri\Modules\Agents\Http\Controllers\User\AgentSettingsController@save')->name('agent.settings.save');
            // P3-2: third-party apps — list / install / uninstall
            Route::get('apps', '\Juwri\Modules\AppStore\Http\Controllers\User\AppsController@page')->name('apps.page');
            Route::post('apps/{appId}/install', '\Juwri\Modules\AppStore\Http\Controllers\User\AppsController@install')->name('apps.install');
            Route::post('apps/{appId}/uninstall', '\Juwri\Modules\AppStore\Http\Controllers\User\AppsController@uninstall')->name('apps.uninstall');
            // P3-3: embedded apps — iframe host page + short-lived session token
            Route::get('apps/{appId}/embed', '\Juwri\Modules\AppStore\Http\Controllers\User\AppsController@embed')->name('apps.embed');
            Route::get('apps/{appId}/session-token', '\Juwri\Modules\AppStore\Http\Controllers\User\AppsController@sessionToken')->name('apps.sessionToken');
            // ST2 + ST3 (features-run-4): in-dashboard App Store + Theme Store. Thin
            // presentation shim over the public AppStore/Marketplace controllers
            // (StoreController reuses their query data); buy/apply/install reuse the
            // existing commerce routes. Slugs match the marketplace pattern.
            Route::get('store/apps', [AppStoreBrowseController::class, 'apps'])->name('user.store.apps');
            Route::get('store/apps/{slug}', [AppStoreBrowseController::class, 'appShow'])->name('user.store.apps.show')->where('slug', '[a-z0-9\-]+');
            Route::get('store/themes', [AppStoreBrowseController::class, 'themes'])->name('user.store.themes');
            Route::get('store/themes/{slug}', [AppStoreBrowseController::class, 'themeShow'])->name('user.store.themes.show')->where('slug', '[a-z0-9\-]+');
            // P4-7: marketplace theme updates (pin/move published versions)
            Route::get('theme-updates', '\Juwri\Modules\Storefront\Http\Controllers\User\ThemeUpdatesController@index')->name('theme.updates.index');
            Route::post('theme-updates/{storeId}', '\Juwri\Modules\Storefront\Http\Controllers\User\ThemeUpdatesController@apply')->name('theme.updates.apply');
            // P5-1: structured integrations (validated GA/Pixel/font — replaces custom code)
            Route::get('integrations-structured/{storeId}', '\Juwri\Modules\Integrations\Http\Controllers\User\StructuredIntegrationsController@show')->name('integrations.structured.show');
            Route::post('integrations-structured/{storeId}', '\Juwri\Modules\Integrations\Http\Controllers\User\StructuredIntegrationsController@save')->name('integrations.structured.save');
            ModuleRoutes::slot('Menu/web-3');
            /* ===== end google-suite Lane B (B3) block ===== */
            Route::get('ai-ordering/{storeId}/analytics', '\Juwri\Modules\Agents\Http\Controllers\User\AgentSettingsController@analytics')->name('agent.settings.analytics');
            // AO9 #45: transcript dashboard (list conversations + read one)
            Route::get('ai-ordering/{storeId}/transcripts', '\Juwri\Modules\Agents\Http\Controllers\User\AgentSettingsController@transcripts')->name('agent.settings.transcripts');
            Route::get('ai-ordering/{storeId}/transcripts/{conversationId}', '\Juwri\Modules\Agents\Http\Controllers\User\AgentSettingsController@transcript')->name('agent.settings.transcript');
            // Delivery driver management (issues driver-app tokens)
            Route::get('drivers', '\Juwri\Modules\Delivery\Http\Controllers\User\DriverController@index')->name('drivers.index');
            Route::post('drivers', '\Juwri\Modules\Delivery\Http\Controllers\User\DriverController@store')->name('drivers.store');
            Route::post('drivers/self-claim', '\Juwri\Modules\Delivery\Http\Controllers\User\DriverController@selfClaim')->name('drivers.selfClaim');
            Route::post('drivers/{id}/rotate', '\Juwri\Modules\Delivery\Http\Controllers\User\DriverController@rotateToken')->name('drivers.rotate');
            Route::post('drivers/{id}/toggle', '\Juwri\Modules\Delivery\Http\Controllers\User\DriverController@toggle')->name('drivers.toggle');
            Route::delete('drivers/{id}', '\Juwri\Modules\Delivery\Http\Controllers\User\DriverController@destroy')->name('drivers.destroy');
            ModuleRoutes::slot('Loyalty/web-1');

            ModuleRoutes::slot('Channels/web-2');

            ModuleRoutes::slot('Kitchen/web-2');

            ModuleRoutes::slot('Pos/web-1');

            ModuleRoutes::slot('Messaging/web-1');

            ModuleRoutes::slot('Integrations/web-2');
            ModuleRoutes::slot('Payments/web-1');

            ModuleRoutes::slot('Ordering/web-1');

            ModuleRoutes::slot('Marketplace/web-1');

            ModuleRoutes::slot('Accounting/web-1');

            ModuleRoutes::slot('Fiscal/web-1');

            ModuleRoutes::slot('Pos/web-2');

            ModuleRoutes::slot('Tables/web-2');

            ModuleRoutes::slot('Platform/web-1');

            ModuleRoutes::slot('Customers/web-3');

            ModuleRoutes::slot('Team/web-1');

            ModuleRoutes::slot('Hr/web-2');

            ModuleRoutes::slot('Accounting/web-2');

            ModuleRoutes::slot('Hr/web-3');

            // B5 delivery insights — heat grid + top areas + courier GPS (superadmin-gated).
            /* WI-A3: read-only, so it renders as a blurred preview for an
               unentitled owner. Gated inside the controller instead, where the
               payload can be reduced to PII-free aggregates. */
            Route::get('delivery-insights', '\Juwri\Modules\Delivery\Http\Controllers\User\DeliveryInsightsController@index')->name('stores.deliveryInsights.index');

            // B2 marketing automation — cart recovery + win-back + birthday (superadmin-gated).
            Route::controller('\Juwri\Modules\Marketing\Http\Controllers\User\MarketingAutomationController')->name('stores.automations.')->prefix('automations')->middleware('feature:marketing_automation')->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/', 'update')->name('update');
            });
            // P1-17 (J27-B): AI social post writer — drafts only, nothing is posted; AI credits + ratelimit:ai like the other content AI tools.
            Route::controller('\Juwri\Modules\Marketing\Http\Controllers\User\SocialPostController')->name('stores.marketing.socialPost.')->prefix('marketing/social-post')->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/draft', 'draft')->middleware('ratelimit:ai')->name('draft');
            });

            ModuleRoutes::slot('Channels/web-3');

            ModuleRoutes::slot('Delivery/web-1');

            ModuleRoutes::slot('Agents/web-1');

            ModuleRoutes::slot('Integrations/web-3');

            Route::get('heartbeat', 'heartbeat')->name('stores.heartbeat');
        });

        // P5-17: owner-app in-app billing (the app's own endpoints; BlockInAppPurchases only blocks the web checkout).
        Route::get('owner-app/billing/account-token', '\Juwri\Modules\Payments\Http\Controllers\User\InAppBillingController@accountToken')->middleware('throttle:owner-app-billing-token')->name('owner-app.billing.token');
        Route::get('owner-app/billing/overview', '\Juwri\Modules\Payments\Http\Controllers\User\InAppBillingController@overview')->middleware('throttle:owner-app-billing-overview')->name('owner-app.billing.overview');
        Route::post('owner-app/billing/apple', '\Juwri\Modules\Payments\Http\Controllers\User\InAppBillingController@verifyApple')->middleware('throttle:owner-app-billing-verify')->name('owner-app.billing.apple');
        Route::post('owner-app/billing/google', '\Juwri\Modules\Payments\Http\Controllers\User\InAppBillingController@verifyGoogle')->middleware('throttle:owner-app-billing-verify')->name('owner-app.billing.google');
        // P5-22: the owner app's billing screen (plan list, buy, restore, status). A Blade shell; the data comes from owner-app/billing/overview (Lane C) and the purchase runs through the native bridge.
        Route::get('owner-app/billing', fn () => view('owner.owner-app.billing'))->name('owner-app.billing.screen');
        // Owner self-service account deletion (Apple 5.1.1(v); owner-app server item S3).
        Route::get('settings/delete-account', '\Juwri\Core\Http\Controllers\User\AccountDeletionController@show')->name('user.account.delete.show');
        Route::post('settings/delete-account', '\Juwri\Core\Http\Controllers\User\AccountDeletionController@destroy')->middleware('throttle:5,1,core-settings-delete-account')->name('user.account.delete');

        Route::controller(User\SettingsController::class)->group(function () {
            Route::get('settings', 'index')->name('settings');
            Route::post('settings/edit-profile', 'editProfile')->name('settings.editProfile')->middleware('demo');
            Route::post('settings/edit-billing', 'editBilling')->name('settings.editBilling');
            Route::get('settings/integrations', 'integrations')->name('settings.integrations');
            Route::post('settings/integrations', 'updateIntegrations')->name('settings.integrations.update');
            Route::post('settings/integrations/test', 'testIntegration')->name('settings.integrations.test');
        });

        /* Self-service backup/export — the owner downloads a full ZIP of their
           own restaurant's data. Creation is export-rate-limited; the download
           streams from a private disk through an ownership check. */
        Route::controller('BackupController')->group(function () {
            Route::get('backup', 'index')->name('backup.index');
            Route::post('backup', 'store')->middleware(['ratelimit:export', 'demo'])->name('backup.store');
            // MONETIZATION B5: automatic scheduling + retention is the paid tier
            // (`feature:backup_pro`). Manual one-off export/download/restore stays
            // FREE (data-portability is a trust promise, DECISIONS non-gate).
            // Dark until V0.
            Route::post('backup/schedule', 'storeSchedule')->middleware(['feature:backup_pro', 'demo'])->name('backup.schedule.store');
            Route::delete('backup/schedule', 'destroySchedule')->middleware(['feature:backup_pro', 'demo'])->name('backup.schedule.destroy');
            // P4-07: the whole account (every store, every table, files) as one bundle — the account owner only.
            Route::post('backup/account-export', 'accountExport')->middleware(['ratelimit:export', 'demo'])->name('backup.account.export');
            Route::post('backup/fullpage-cache', 'fullPageCacheTrial')->middleware('demo')->name('backup.fullpage.trial');
            Route::get('backup/account-export/{token}', 'accountExportDownload')->middleware('ratelimit:export')->name('backup.account.download');
            Route::get('backup/{token}/status', 'status')->name('backup.status');
            Route::get('backup/{token}/download', 'download')->middleware('ratelimit:export')->name('backup.download');
            Route::delete('backup/{token}', 'destroy')->middleware('demo')->name('backup.destroy');

            // WI-E1 import/restore: upload → preview → confirm → poll.
            Route::post('backup/import', 'importUpload')->middleware(['ratelimit:export', 'demo'])->name('backup.import');
            Route::post('backup/import/{token}/confirm', 'importConfirm')->middleware('demo')->name('backup.import.confirm');
            Route::get('backup/import/{token}/status', 'importStatus')->name('backup.import.status');
            Route::delete('backup/import/{token}', 'importDestroy')->middleware('demo')->name('backup.import.destroy');
        });

        /* WI-G7 ownership transfer: two-party offer/accept for a store, direct
           move for a branch between the owner's own stores. Every action is
           ownership-scoped in the controller; consent is re-checked at accept. */
        Route::controller('\Juwri\Core\Http\Controllers\User\OwnershipTransferController')->group(function () {
            Route::get('transfer', 'index')->name('transfer.index');
            Route::post('transfer/store', 'offerStore')->middleware('demo')->name('transfer.store.offer');
            Route::post('transfer/branch', 'moveBranch')->middleware('demo')->name('transfer.branch.move');
            Route::post('transfer/{token}/accept', 'accept')->middleware('demo')->name('transfer.accept');
            Route::post('transfer/{token}/decline', 'decline')->middleware('demo')->name('transfer.decline');
            Route::post('transfer/{token}/cancel', 'cancel')->middleware('demo')->name('transfer.cancel');
        });

        /* WI-G4: owner adopts platform-catalog entries (copy-on-adopt) into the
           current store. Ownership-scoped in the controller. */
        Route::controller('PlatformCatalogController')->group(function () {
            Route::get('platform-catalog', 'index')->name('platform-catalog.index');
            Route::post('platform-catalog/category/{id}/adopt', 'adoptCategory')->middleware('demo')->name('platform-catalog.adopt-category');
            Route::post('platform-catalog/extra/{id}/adopt', 'adoptExtra')->middleware('demo')->name('platform-catalog.adopt-extra');
        });

        ModuleRoutes::slot('Billing/web-5');

        // #58: Campaigns
        Route::resource('campaigns', '\Juwri\Modules\Marketing\Http\Controllers\User\CampaignController')->only(['index', 'create', 'store', 'show']);

    });
    /* #100: STAFF MOBILE PWA (session `staff` guard). manifest + sw public so they load pre-login. */
    Route::get('staff-app/manifest.webmanifest', 'StaffAppController@manifest')->name('staffapp.manifest');
    Route::get('staff-app/sw.js', 'StaffAppController@serviceWorker')->name('staffapp.sw');
    Route::get('pos/pos-sw.js', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@serviceWorker')->name('pos.sw'); // P5: public SW
    Route::middleware(['auth:staff', 'abuse.hold', 'csp.dashboard'])->group(function () { // S12 CSP
        Route::get('staff-app', 'StaffAppController@shell')->name('staffapp.shell');
        Route::get('staff-app/orders', 'StaffAppController@orders')->name('staffapp.orders');
        Route::post('staff-app/orders/{order}/status', 'StaffAppController@updateStatus')->name('staffapp.status');
        Route::post('staff-app/push/subscribe', 'StaffAppController@subscribe')->name('staffapp.subscribe');
        // T8-P0: native app device-token registration (FCM/APNs).
        Route::post('staff-app/push/register-device', 'StaffAppController@registerDevice')->name('staffapp.registerDevice');
        Route::post('staff-app/clock-in', 'StaffAppController@clockIn')->name('staffapp.clockIn');
        Route::post('staff-app/clock-out', 'StaffAppController@clockOut')->name('staffapp.clockOut');
        // B7-P2: my published shifts (this week + next).
        Route::get('staff-app/shifts', 'StaffAppController@myShifts')->name('staffapp.shifts');
        // B10-P2: food-safety checks + logging.
        Route::get('staff-app/food-safety', 'StaffAppController@foodSafetyChecks')->name('staffapp.foodsafety');
        Route::post('staff-app/food-safety/{checkId}', 'StaffAppController@logFoodSafety')->name('staffapp.foodsafety.log');
        Route::post('staff-app/food-safety-incident', 'StaffAppController@logFoodSafetyIncident')->name('staffapp.foodsafety.incident');
        Route::get('staff-app/tips', 'StaffAppController@tipsSummary')->name('staffapp.tips');
        // SP4 #18: tableside order create (server-priced via OrderApiService)
        Route::get('staff-app/menu', 'StaffAppController@menu')->name('staffapp.menu');
        Route::post('staff-app/table-orders', 'StaffAppController@createTableOrder')->name('staffapp.tableOrder');
        // SP4 #19/#21: phone floor status + call-for-bill
        Route::get('staff-app/floor', 'StaffAppController@floor')->name('staffapp.floor');
        Route::post('staff-app/orders/{order}/request-bill', 'StaffAppController@requestBill')->name('staffapp.requestBill');
        // SP4 #22: move an order to another table
        Route::post('staff-app/orders/{order}/transfer', 'StaffAppController@transferOrder')->name('staffapp.transfer');
        // SYSTEMS-RUN W: waiter tableside (waiter_mode-gated inside controller).
        Route::get('staff-app/waiter/floor', 'StaffAppController@waiterFloor')->name('staffapp.waiter.floor');
        Route::post('staff-app/waiter/orders/{order}/items', 'StaffAppController@waiterAddItems')->name('staffapp.waiter.addItems');
        Route::post('staff-app/waiter/orders/{order}/fire', 'StaffAppController@waiterFireCourse')->name('staffapp.waiter.fire');
        Route::get('staff-app/waiter/orders/{order}/split-seat', 'StaffAppController@waiterSplitBySeat')->name('staffapp.waiter.splitSeat');
        Route::post('staff-app/waiter/orders/{order}/merge', 'StaffAppController@waiterMerge')->name('staffapp.waiter.merge');
        Route::get('staff-app/waiter/calls', 'StaffAppController@waiterCalls')->name('staffapp.waiter.calls');
        Route::post('staff-app/waiter/calls/{callId}/ack', 'StaffAppController@waiterAckCall')->name('staffapp.waiter.ackCall');
        // SP5 #24-27: KDS on mobile (surfaces existing KDS pipeline; behavior unchanged).
        Route::get('staff-app/kds', 'StaffAppController@kdsTickets')->name('staffapp.kds');
        Route::post('staff-app/kds/items/{itemId}/bump', 'StaffAppController@kdsBump')->name('staffapp.kds.bump');
        Route::post('staff-app/kds/items/{itemId}/recall', 'StaffAppController@kdsRecall')->name('staffapp.kds.recall');
        // SP8 #36/#37: opening/closing checklists (role-scoped tasks + daily ticks).
        Route::get('staff-app/tasks', 'StaffAppController@tasks')->name('staffapp.tasks');
        Route::post('staff-app/tasks', 'StaffAppController@createTask')->name('staffapp.tasks.create');
        Route::post('staff-app/tasks/{taskId}/complete', 'StaffAppController@completeTask')->name('staffapp.tasks.complete');
        Route::post('staff-app/tasks/{taskId}/uncomplete', 'StaffAppController@uncompleteTask')->name('staffapp.tasks.uncomplete');
        // SP9 #41/#42: staff comms thread + manager broadcast.
        Route::get('staff-app/messages', 'StaffAppController@messages')->name('staffapp.messages');
        Route::post('staff-app/messages', 'StaffAppController@postMessage')->name('staffapp.messages.post');
        Route::post('staff-app/broadcast', 'StaffAppController@broadcast')->name('staffapp.broadcast');
        // SP11 #49: multi-branch switcher (branch-scoped data).
        Route::get('staff-app/branches', 'StaffAppController@branches')->name('staffapp.branches');
    });

    ModuleRoutes::slot('Pos/web-3');

    /* #23: Apple/Google Pay (Stripe Payment Request Button) */
    Route::get('.well-known/apple-developer-merchantid-domain-association', '\Juwri\Modules\Ordering\Http\Controllers\User\WalletPaymentController@applePayDomainAssociation')->name('applepay.domain');
    Route::get('{store}/wallet/config', '\Juwri\Modules\Ordering\Http\Controllers\User\WalletPaymentController@config')->name('wallet.config');
    Route::post('{store}/wallet/intent', '\Juwri\Modules\Ordering\Http\Controllers\User\WalletPaymentController@intent')->middleware('throttle:30,1,core-wallet-intent')->name('wallet.intent');

    ModuleRoutes::slot('Customers/web-4');
    // Owner-authored storefront info pages (Terms/Privacy/About/Help). Public; 404s
    // for an unknown slug or an empty page (see StoreController@infoPage).
    Route::get('{store}/page/{page}', 'User\StoreController@infoPage')->where('page', '[a-z_]+')->middleware('throttle:public-check')->name('store.infoPage'); // HPMS E14(d): allow underscores (refund_policy, delivery_policy)
    ModuleRoutes::slot('Customers/web-5');
    Route::post('{store}/call-waiter', 'User\StoreController@callTheWaiter')->middleware(['throttle:restaurant-waiter', 'ratelimit:public-write'])->name('store.callTheWaiter');
    // PARITY-RUN T3: `table.session` is the dine-in scan gate. It is order-type
    // aware (delivery/takeaway/room-service pass straight through) and asks the
    // entitlement question store-scoped INSIDE the middleware — never as
    // feature:/entitled:, which would 403 every anonymous diner.
    Route::post('{store}/send-order', 'User\StoreController@submitOrder')->middleware(['throttle:restaurant-order', 'branchlimit:orders', 'table.session'])->name('store.submitOrder');
    // FEATURES-5 Track C: customer order voice notes (record -> temp token -> attach on send-order).
    Route::post('{store}/order/voice-note', '\Juwri\Modules\Ordering\Http\Controllers\User\VoiceNoteController@upload')->middleware(['throttle:restaurant-order', 'ratelimit:upload'])->name('store.voiceNote.upload');
    Route::get('{store}/order/voice-note/{token}', '\Juwri\Modules\Ordering\Http\Controllers\User\VoiceNoteController@customerStream')->middleware('throttle:public-check')->name('store.voiceNote.customerStream');
    // FEATURES-5 Track C: signed voice-note stream for the staff PWA / API (<audio> can't send a bearer token).
    Route::get('order/{order}/voice-note-signed', '\Juwri\Modules\Ordering\Http\Controllers\User\VoiceNoteController@signedStream')->middleware(['signed', 'throttle:public-check'])->name('voiceNote.signedStream');
    // T1: bookable scheduling slots for the checkout picker (ASAP/schedule-for-later).
    Route::get('{store}/schedule-slots', 'User\StoreController@scheduleSlots')->middleware('throttle:public-check')->name('store.scheduleSlots');
    Route::get('order/track/{token}', 'User\StoreController@trackOrder')->middleware(['throttle:public-poll', KioskStorefrontShell::class, MarkAnonymousPage::class.':own'])->name('store.orderTrack');
    // T5 curbside "I'm here" arrival ping (token-scoped).
    // FIX-2 F2-3: `feature:curbside` cannot stay here — once the key is plan_gated
    // that middleware resolves the subject from the SESSION, and the caller is an
    // anonymous diner, so every arrival ping would 403. The gate moved into
    // StoreController::curbsideArrived, store-scoped on the order's restaurant
    // owner (kill veto + entitlement). Same two checks, correct subject.
    Route::post('order/track/{token}/arrived', 'User\StoreController@curbsideArrived')->middleware(['throttle:public-check'])->name('store.curbsideArrived');
    // P1: order-scoped customer Web Push (closed-tab "order ready"). Anonymous +
    // token-scoped (validated in the controller), throttled per public-check —
    // never a feature:/entitled: middleware (the caller is an anonymous diner).
    Route::post('order/track/{token}/push-subscribe', 'User\StoreController@pushSubscribe')->middleware(['throttle:public-check', 'ratelimit:public-write'])->name('store.pushSubscribe');
    Route::post('order/track/{token}/push-unsubscribe', 'User\StoreController@pushUnsubscribe')->middleware(['throttle:public-check', 'ratelimit:public-write'])->name('store.pushUnsubscribe');
    // MARKETPLACE T2-4: post-delivery rating prompt on the track page (token-scoped, one per order).
    // P3-09 (basket §10): the shopper keeps or refuses a replacement the picker proposed (token-scoped, line of that order).
    Route::post('order/track/{token}/replacement', '\Juwri\Modules\Ordering\Http\Controllers\User\GroceryTrackController@answer')->middleware(['throttle:public-check', 'ratelimit:public-write'])->name('store.orderReplacement');
    Route::post('order/track/{token}/review', 'User\StoreController@submitTrackReview')->middleware(['throttle:public-check', 'ratelimit:public-write'])->name('store.orderTrackReview');
    // K1: post-payment review funnel — smart-route ≥4★ to the store's Google page.
    Route::post('order/{token}/review-funnel', 'User\StoreController@submitOrderReview')->middleware(['throttle:public-check', 'ratelimit:public-write'])->name('store.orderReviewFunnel');
    ModuleRoutes::slot('Content/web-1');

    // T2 gift cards: customer purchase (before the {slug} catch-all).
    // FIX-2 F2-4: same subject problem as curbside — the buyer is a guest, so the
    // gate lives in GiftCardService::enabled() (kill veto + store-owner entitlement
    // + per-store toggle), which BOTH the GET page and the POST money path already
    // call. That also closes the POST purchase side door, which had no gate at all.
    Route::get('{store}/gift-cards', '\Juwri\Modules\Loyalty\Http\Controllers\User\GiftCardController@buy')->name('store.giftcards.buy');
    Route::post('{store}/gift-cards', '\Juwri\Modules\Loyalty\Http\Controllers\User\GiftCardController@purchase')->middleware('throttle:restaurant-order')->name('store.giftcards.purchase');
    // #22: Guest order history lookup by phone/email
    Route::post('{store}/order-lookup', 'User\StoreController@guestOrderLookup')->middleware('throttle:public-check')->name('store.orderLookup');
    // #29: Diner item reviews
    Route::post('{store}/review', 'User\StoreController@submitMenuReview')->middleware(['throttle:public-check', 'ratelimit:public-write'])->name('store.submitReview');
    // #62: NPS feedback
    Route::post('{store}/nps', 'User\StoreController@submitNps')->middleware(['throttle:public-check', 'ratelimit:public-write'])->name('store.submitNps');
    Route::post('{store}/coupon', 'User\StoreController@applyCoupon')->middleware('throttle:public-check')->name('store.applyCoupon');
    // P3-02: the server quote of a storefront cart (view model `endpoints.quote`).
    Route::post('{store}/quote', '\Juwri\Modules\Storefront\Http\Controllers\StorefrontQuoteController')->middleware('throttle:public-check')->name('store.quote');
    // P3-11: the server quote of a booking selection (the `book` theme on the booking engine).
    Route::post('{store}/booking-quote', '\Juwri\Modules\Storefront\Http\Controllers\StorefrontBookingQuoteController')->middleware('throttle:public-check')->name('store.bookingQuote');
    // P3-13: the server quote of the goods cart (premium goods themes: carely, aurelia, freshly).
    Route::post('{store}/cart-quote', '\Juwri\Modules\Storefront\Http\Controllers\StorefrontGoodsQuoteController')->middleware('throttle:public-check')->name('store.cartQuote');
    // P3-03: the versioned menu snapshot the per-store service worker keeps for offline browsing.
    Route::get('{store}/storefront/boot.json', '\Juwri\Modules\Storefront\Http\Controllers\StorefrontBootController')->middleware('throttle:public-check')->name('store.boot');
    Route::get('{store}/storefront/snapshot.json', '\Juwri\Modules\Storefront\Http\Controllers\StorefrontSnapshotController')->middleware('throttle:public-check')->name('store.snapshot');
    Route::post('{store}/item-event', 'User\StoreController@recordItemEvent')->middleware('throttle:public-check')->name('store.recordItemEvent');
    Route::get('payment/{transaction}', [User\PaymentController::class, 'index'])->name('payment.index');
    // P6-09 V-15: phone-agent pay link — temporary signed URL texted to the caller (expiry = the hold window).
    Route::get('payment/phone/{transaction}', [User\PaymentController::class, 'index'])->middleware('signed')->name('payment.phone');
    Route::post('payment/{transaction}/pay', [User\PaymentController::class, 'pay'])->name('payment.pay')->middleware('throttle:20,1,payment-pay');
    // Syria A3: wallet_transfer customer claim (customer submits wallet + txn-id).
    // P6-154: signed like storeTransfer.claim — a plain sequential id let anyone claim another diner's order and be
    // redirected to its track page (track token leak). The pay page renders the signed URL (WalletTransferAdapter).
    Route::post('payment/{transaction}/claim', '\Juwri\Modules\Ordering\Http\Controllers\User\PaymentController@claim')->middleware(['signed', 'throttle:20,1,core-payment-claim'])->name('payment.claim');
    // P1-23 part 6: wallet-transfer claim of a store payment (gift card, top-up, deposit) — signed link from its pay page.
    Route::post('store-payment/{transaction}/transfer-claim', '\Juwri\Modules\Payments\Http\Controllers\User\StoreTransferController@claim')->middleware(['signed', 'throttle:20,1,core-store-payment-transfer-claim'])->name('storeTransfer.claim');

    /* PAYMENT ROUTES */
    Route::any('ipn/{gateway}', '\Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\PaymentController@ipn')->middleware(['ratelimit:webhook-in', 'throttle:120,1,core-ipn'])->name('ipn');
    ModuleRoutes::slot('Channels/web-5');
    // P5-17: App Store Server Notifications V2 and Google Play RTDN (Pub/Sub push) — before webhook/{gateway}.
    Route::post('webhook/in-app/apple', '\Juwri\Modules\Payments\Http\Controllers\User\InAppBillingController@appleNotification')->middleware(['ratelimit:webhook-in', 'throttle:120,1,core-webhook-inapp'])->name('webhook.inapp.apple');
    Route::post('webhook/in-app/google', '\Juwri\Modules\Payments\Http\Controllers\User\InAppBillingController@googleNotification')->middleware(['ratelimit:webhook-in', 'throttle:120,1,core-webhook-inapp'])->name('webhook.inapp.google');
    // P5-21: SoftPOS (Geidea / EdfaPay) callbacks to one store's own URL — before webhook/{gateway}.
    Route::post('webhook/softpos/{provider}/store/{store}', '\Juwri\Modules\Payments\Http\Controllers\User\SoftPosController@webhook')->whereIn('provider', ['geidea_softpos', 'edfapay_softpos'])->whereNumber('store')->middleware(['ratelimit:webhook-in', 'throttle:120,1,core-webhook-softpos'])->name('webhook.softpos');
    // PAYMENTS-RUN G10: Paylink partner activation webhook — register BEFORE webhook/{gateway} or it's shadowed.
    Route::post('webhook/paylink-partner', '\Juwri\Modules\Payments\Http\Controllers\User\PaylinkPartnerController@webhook')->middleware(['ratelimit:webhook-in', 'throttle:120,1,core-webhook-paylink-partner'])->name('paylink.partner.webhook');
    Route::post('webhook/{gateway}', '\Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\PaymentController@webhook')->middleware(['ratelimit:webhook-in', 'throttle:120,1,core-webhook'])->name('webhook');
    // A store's OWN Stripe / PayPal account notifying (verified with that store's webhook secret only; StoreWebhooks).
    Route::post('webhook/{gateway}/store/{store}', '\Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\PaymentController@storeWebhook')->whereNumber('store')->middleware(['ratelimit:webhook-in', 'throttle:120,1,core-webhook-store'])->name('webhook.store');

    /* TAX-RUN E-P2: ETA async validation callback (CSRF-exempt via webhook/*). */
    Route::post('webhook/fiscal/eta/{token}', '\Juwri\Modules\Fiscal\Http\Controllers\User\FiscalController@etaCallback')->middleware('ratelimit:webhook-in')->name('webhook.fiscal.eta');

    /* P6-249: mail provider bounce/complaint webhooks (signed; 403 when unsigned or the secret is blank). CSRF-exempt via webhook/*. */
    Route::post('webhook/mail/{provider}', '\Juwri\Modules\Messaging\Http\Controllers\MailEventWebhookController@handle')->whereIn('provider', MailEventWebhook::PROVIDERS)->middleware(['ratelimit:webhook-in', 'throttle:120,1,core-webhook-mail-events'])->name('webhook.mail.events');

    /* #104: inbound SMS webhook (STOP/START keyword handling). CSRF-exempt via webhook/*. */
    Route::post('webhook/sms/inbound', '\Juwri\Modules\Messaging\Http\Controllers\SmsWebhookController@inbound')->middleware(['throttle:120,1,core-webhook-sms-inbound', 'ratelimit:webhook-in'])->name('webhook.sms.inbound');

    /* WhatsApp webhooks (inbound + delivery/read status). CSRF-exempt via webhook/*. */
    Route::get('webhook/whatsapp/{provider}', '\Juwri\Modules\Messaging\Http\Controllers\WhatsAppWebhookController@verify')->name('webhook.whatsapp.verify');
    Route::post('webhook/whatsapp/{provider}', '\Juwri\Modules\Messaging\Http\Controllers\WhatsAppWebhookController@handle')->middleware('ratelimit:webhook-in')->name('webhook.whatsapp.handle');

    ModuleRoutes::slot('Channels/web-6');
    // T4: third-party courier status webhooks (Uber Direct, DoorDash).
    Route::post('webhook/courier/{provider}', '\Juwri\Modules\Delivery\Http\Controllers\CourierWebhookController@inbound')->middleware('ratelimit:webhook-in')->name('webhook.courier.inbound');
    ModuleRoutes::slot('Channels/web-4');
    // T6: inbound AI phone-agent call webhook (Twilio/Telnyx).
    Route::post('voice/{provider}/incoming', '\Juwri\Modules\Agents\Http\Controllers\VoiceWebhookController@incoming')->middleware('ratelimit:webhook-in')->name('voice.incoming');
    // P6-09 V-03: voice bridge entry; refuses without a valid signed bridge token.
    // P6-09 V-12: provider call-status callback (final duration → call cost row).
    Route::post('voice/{provider}/status', '\Juwri\Modules\Agents\Http\Controllers\VoiceWebhookController@status')->middleware('ratelimit:webhook-in')->name('voice.status');
    Route::get('voice/{provider}/bridge/{line}', '\Juwri\Modules\Agents\Http\Controllers\VoiceBridgeController@connect')->middleware('ratelimit:webhook-in')->name('voice.bridge');

    ModuleRoutes::slot('Reservations/web-3');

    /* ADMIN ROUTES */
    /* WI-15-sec A-R1 (c2): a staff session signs the web guard in as the store OWNER;
       when that owner is a superadmin the panel must still refuse it. `staff.dashboard`
       is default-deny for staff sessions on unmapped (admin.*) route names. */
    Route::name('admin.')->prefix(admin_url())->namespace('Admin')->middleware(['admin', 'staff.dashboard', 'admin.session', 'demo', 'csp.dashboard'])->group(function () { // S12 CSP; A1 idle timeout + step-up

        Route::redirect('/', 'admin/dashboard');

        Route::get('/dashboard', [Admin\DashboardController::class, 'index'])->name('dashboard');
        Route::get('/dashboard/chart-data', 'DashboardController@chartData')->name('dashboard.chart-data');

        ModuleRoutes::slot('Platform/web-4');
        /* Owner dashboard PWA (PWA-SPEC §3.6): which dashboard screens owners open on phones. */
        Route::get('/owner-mobile-usage', '\Juwri\Core\Http\Controllers\Admin\OwnerMobileUsageController@index')->name('ownerMobileUsage');
        Route::get('/owner-mobile-usage/export', '\Juwri\Core\Http\Controllers\Admin\OwnerMobileUsageController@export')->name('ownerMobileUsage.export');

        ModuleRoutes::slot('Content/web-2');

        // WI-G7: superadmin force-transfer a store to another user (no accept step).
        Route::post('/store/{id}/force-transfer', 'StoreController@forceTransfer')->name('store.force-transfer');

        /* WI-G4: superadmin curates the platform menu catalog. */
        Route::controller('PlatformCatalogController')->prefix('platform-catalog')->name('platform-catalog.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('category', 'storeCategory')->name('category.store');
            Route::post('extra', 'storeExtra')->name('extra.store');
            Route::delete('category/{id}', 'destroyCategory')->name('category.destroy');
            Route::delete('extra/{id}', 'destroyExtra')->name('extra.destroy');
        });
        Route::get('/orders', 'OrderAdminController@index')->name('orders.index');
        Route::post('/orders/backfill-totals', 'OrderAdminController@backfillTotals')->name('orders.backfill-totals'); // H10

        // google-partner Lane A (A7): superadmin GBP oversight (read-only + per-store kill).
        Route::controller('\Juwri\Modules\Integrations\Http\Controllers\Admin\GoogleBusinessController')->prefix('google-business')->name('google-business.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/{id}/toggle', 'toggle')->name('toggle');
        });

        ModuleRoutes::slot('Platform/web-2');

        /* TAX-RUN R-P2: superadmin fiscal compliance-status page. */
        Route::get('/fiscal-compliance', '\Juwri\Modules\Fiscal\Http\Controllers\Admin\FiscalComplianceController@index')->name('fiscal.compliance');
        /* P6-186 (J44): platform switch "force VAT per line for all KSA stores". */
        Route::post('/fiscal-compliance/ksa-vat-per-line', '\Juwri\Modules\Fiscal\Http\Controllers\Admin\FiscalComplianceController@ksaVatPerLine')->name('fiscal.ksa-vat-per-line');

        /* P1-23 (AUP 3.1(b)): superadmin review of pharmacy licence uploads before a pharmacy store is published. */
        Route::controller('\Juwri\Modules\Pharmacy\Http\Controllers\Admin\PharmacyLicenceReviewController')->prefix('pharmacy-licences')->name('pharmacy-licences.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/{id}/document', 'document')->whereNumber('id')->name('document');
            Route::post('/{id}/approve', 'approve')->whereNumber('id')->name('approve');
            Route::post('/{id}/reject', 'reject')->whereNumber('id')->name('reject');
        });

        /* GOOGLE-PARTNER Lane C: superadmin Reserve-with-Google oversight (read-only
           status + per-store enabled list + per-store reservations kill). */
        Route::get('/reserve-google', '\Juwri\Modules\Reservations\Http\Controllers\Admin\ReserveGoogleController@index')->name('reserve-google.index');
        Route::post('/reserve-google/{id}/kill', '\Juwri\Modules\Reservations\Http\Controllers\Admin\ReserveGoogleController@kill')->whereNumber('id')->name('reserve-google.kill');

        ModuleRoutes::slot('Media/web-1');

        Route::get('/webhooks', '\Juwri\Modules\Integrations\Http\Controllers\Admin\WebhookAdminController@index')->name('webhooks.index');
        // D5 (integrations pack): cross-tenant integrations monitor — webhook failure
        // leaderboard, Deliverect location links, kill-switch state. No feature gate.
        Route::get('/integrations', '\Juwri\Modules\Integrations\Http\Controllers\Admin\IntegrationsMonitorController@index')->name('integrations.index');
        // O4: platform alerts console
        Route::get('/alerts', '\Juwri\Core\Http\Controllers\Admin\AlertsAdminController@index')->name('alerts.index');
        Route::post('/alerts/{id}/resolve', '\Juwri\Core\Http\Controllers\Admin\AlertsAdminController@resolve')->name('alerts.resolve');
        // M2: storefront menu-cache state + per-store forced rebuild
        Route::get('/menu-cache', '\Juwri\Modules\Menu\Http\Controllers\Admin\MenuCacheAdminController@index')->name('menu-cache.index');
        Route::post('/menu-cache/{storeId}/bust', '\Juwri\Modules\Menu\Http\Controllers\Admin\MenuCacheAdminController@bust')->name('menu-cache.bust');
        ModuleRoutes::slot('Core/web-2');
        Route::get('/ai-usage', '\Juwri\Modules\Ai\Http\Controllers\Admin\AiUsageAdminController@index')->name('ai-usage.index');

        /* Owner app: platform Firebase service account for native push (FCM HTTP v1). */
        Route::get('/mobile-push', '\Juwri\Modules\Messaging\Http\Controllers\Admin\MobilePushCredentialsController@index')->name('mobile-push.index');
        Route::post('/mobile-push', '\Juwri\Modules\Messaging\Http\Controllers\Admin\MobilePushCredentialsController@update')->name('mobile-push.update');
        Route::delete('/mobile-push', '\Juwri\Modules\Messaging\Http\Controllers\Admin\MobilePushCredentialsController@destroy')->name('mobile-push.destroy');

        /* WhatsApp channel settings (platform) */
        Route::get('/whatsapp-settings', 'WhatsAppSettingsController@index')->name('whatsapp.settings');
        Route::post('/whatsapp-settings', 'WhatsAppSettingsController@update')->name('whatsapp.settings.update');

        /* Platform-wide activity log (audit trail) */
        Route::get('/activity', '\Juwri\Core\Http\Controllers\Admin\ActivityLogAdminController@index')->name('activity.index');
        /* P1-23: read-only list of accepted legal document versions (admin only). */
        /* P6-249: suppressed (bounced / complained) e-mail addresses. */
        Route::get('/email-suppressions', '\Juwri\Modules\Messaging\Http\Controllers\Admin\EmailSuppressionAdminController@index')->name('email-suppressions.index');
        Route::delete('/email-suppressions/{suppression}', '\Juwri\Modules\Messaging\Http\Controllers\Admin\EmailSuppressionAdminController@destroy')->whereNumber('suppression')->name('email-suppressions.destroy');
        Route::get('/legal-acceptances', '\Juwri\Core\Http\Controllers\Admin\LegalAcceptancesAdminController@index')->name('legal-acceptances.index');
        /* Wallet ledger (all wallet transactions across users) */
        Route::get('/wallet', '\Juwri\Modules\Payments\Http\Controllers\Admin\WalletLedgerAdminController@index')->name('wallet.index');
        ModuleRoutes::slot('AppStore/ee-web-5');

        // #59: Referral admin
        Route::get('/referrals', '\Juwri\Modules\Marketing\Http\Controllers\Admin\ReferralAdminController@index')->name('referrals.index');

        ModuleRoutes::slot('Billing/web-2');
        Route::post('/users/{user}/abuse-hold', 'UserController@toggleAbuseHold')->name('users.abuseHold');

        // #64: A/B experiments admin
        Route::resource('ab-experiments', '\Juwri\Modules\Marketing\Http\Controllers\Admin\AbExperimentController')->only(['index', 'create', 'store', 'destroy']);
        Route::post('/referrals/{conversion}/approve', '\Juwri\Modules\Marketing\Http\Controllers\Admin\ReferralAdminController@approve')->name('referrals.approve');
        Route::post('/referrals/{conversion}/reject', '\Juwri\Modules\Marketing\Http\Controllers\Admin\ReferralAdminController@reject')->name('referrals.reject');
        // #71: Admin wallet grant
        Route::post('/users/{user}/wallet/grant', '\Juwri\Modules\Payments\Http\Controllers\Admin\WalletAdminController@grantCredit')->name('users.wallet.grant');
        ModuleRoutes::slot('Reseller/web-2');
        Route::get('/coupons-wallets/overview', '\Juwri\Modules\Loyalty\Http\Controllers\Admin\AdminCouponsWalletsController@overview')->name('couponsWallets.overview'); // Coupons&Wallets P6-1
        ModuleRoutes::slot('Reseller/web-3');
        ModuleRoutes::slot('Content/web-3');
        // Abuse Guard: super-admin server-protection config
        Route::get('/abuse-guard', '\App\Http\Controllers\Admin\AbuseGuardController@index')->name('abuse-guard.index');
        Route::post('/abuse-guard/rate-limits', '\App\Http\Controllers\Admin\AbuseGuardController@updateRateLimits')->name('abuse-guard.rate-limits');
        Route::post('/abuse-guard/ai-spend', '\App\Http\Controllers\Admin\AbuseGuardController@updateAiSpend')->name('abuse-guard.ai-spend');
        Route::post('/abuse-guard/signup', '\App\Http\Controllers\Admin\AbuseGuardController@updateSignup')->name('abuse-guard.signup');
        Route::post('/abuse-guard/queue', '\App\Http\Controllers\Admin\AbuseGuardController@updateQueue')->name('abuse-guard.queue');
        Route::post('/abuse-guard/alerts', '\App\Http\Controllers\Admin\AbuseGuardController@updateAlerts')->name('abuse-guard.alerts');
        ModuleRoutes::slot('Reseller/web-5');

        Route::get('/stores', [Admin\StoreController::class, 'index'])->name('stores.index');
        Route::post('stores.delete', [Admin\StoreController::class, 'delete'])->name('stores.delete');
        Route::post('stores/toggle-custom-code', 'StoreController@toggleCustomCode')->name('stores.toggleCustomCode');
        // rev/external-redirect C1: superadmin per-store redirect editor.
        Route::get('stores/{store}/external-redirect', 'StoreController@editExternalRedirect')->name('stores.externalRedirect.edit');
        Route::post('stores/{store}/external-redirect', 'StoreController@updateExternalRedirect')->name('stores.externalRedirect.update');
        // rev/external-redirect C2: bulk migration-wave set/clear.
        Route::post('stores/external-redirect/bulk', 'StoreController@bulkExternalRedirect')->name('stores.externalRedirect.bulk');

        Route::resource('allergies', Admin\AllergyController::class);
        Route::post('allergies.delete', [Admin\AllergyController::class, 'delete'])->name('allergies.delete');
        Route::post('allergies.reorder', [Admin\AllergyController::class, 'reorder'])->name('allergies.reorder');

        Route::get('/notifications', '\Juwri\Core\Http\Controllers\Admin\NotificationController@index')->name('notifications.index');
        Route::get('notifications/view/{id}', '\Juwri\Core\Http\Controllers\Admin\NotificationController@view')->name('notifications.view');
        Route::get('notifications/markasread', '\Juwri\Core\Http\Controllers\Admin\NotificationController@markAsRead')->name('notifications.markasread');
        Route::delete('notifications/deleteallread', '\Juwri\Core\Http\Controllers\Admin\NotificationController@deleteAllRead')->name('notifications.deleteallread');

        Route::controller(Admin\MerchantCampaignController::class)->group(function () {
            /* Bulk email campaigns to merchants. Same engine as the trial
               reminders: batched, idempotent per campaign, opt-out aware and
               logged. The report is the per-recipient log. */
            Route::get('merchants/campaign', 'index')->name('merchants.campaign');
            Route::get('merchants/campaign/merchants', 'merchants')->name('merchants.campaign.merchants');
            Route::post('merchants/campaign/preview', 'preview')->name('merchants.campaign.preview');
            Route::post('merchants/campaign/send', 'send')->name('merchants.campaign.send');
            Route::post('merchants/campaign/cancel', 'cancel')->name('merchants.campaign.cancel');
            Route::post('merchants/campaign/test', 'testSend')->name('merchants.campaign.test');
            Route::get('merchants/campaign/report', 'report')->name('merchants.campaign.report');
        });

        Route::controller(Admin\UserController::class)->group(function () {
            Route::post('users/delete', 'delete')->name('users.delete');
            Route::post('users/delete-filtered', 'deleteFiltered')->name('users.deleteFiltered');
            Route::get('users/delete-filtered/{token}/status', 'deleteFilteredStatus')->name('users.deleteFilteredStatus');
            Route::get('users/selection-ids', 'selectionIds')->name('users.selectionIds');
            Route::get('users/export', 'export')->middleware('ratelimit:export')->name('users.export');
            Route::get('users/full-export-package', 'bulkFullExport')->middleware('ratelimit:export')->name('users.fullExportPackage');
            Route::get('users/full-export-package/{token}/status', 'bulkFullExportStatus')->name('users.fullExportPackageStatus');
            Route::get('users/full-export-package/{token}/download', 'bulkFullExportDownload')->name('users.fullExportPackageDownload');
            Route::get('users/target-export-package', 'bulkTargetExport')->middleware('ratelimit:export')->name('users.targetExportPackage');
            Route::get('users/target-export-package/{token}/status', 'bulkTargetExportStatus')->name('users.targetExportPackageStatus');
            Route::get('users/target-export-package/{token}/download', 'bulkTargetExportDownload')->name('users.targetExportPackageDownload');
            Route::get('users/hungerino-export-package', 'bulkHungerinoExport')->middleware('ratelimit:export')->name('users.hungerinoExportPackage');
            Route::get('users/hungerino-export-package/{token}/status', 'bulkHungerinoExportStatus')->name('users.hungerinoExportPackageStatus');
            Route::get('users/hungerino-export-package/{token}/download', 'bulkHungerinoExportDownload')->name('users.hungerinoExportPackageDownload');
            Route::get('users/whatsapp-export', 'whatsappExport')->middleware('ratelimit:export')->name('users.whatsappExport');
            Route::post('users/full-import-package', 'bulkFullImport')->name('users.fullImportPackage');
            Route::get('users/full-import-package/report/{reportFile}', 'bulkFullImportReport')->name('users.fullImportPackageReport');
            Route::get('users/{user}/full-export', 'fullExport')->middleware('ratelimit:export')->name('users.fullExport');
            Route::get('users/{user}/hungerino-export', 'hungerinoExport')->middleware('ratelimit:export')->name('users.hungerinoExport');
            Route::post('users/{user}/full-import', 'fullImport')->name('users.fullImport');
            Route::get('users/{user}/full-import/report/{reportFile}', 'fullImportReport')->name('users.fullImportReport');
            // C3: confirm leg of the dry-run preview (registry restore engine).
            Route::post('users/{user}/full-import/{token}/confirm', 'fullImportConfirm')->name('users.fullImportConfirm');
            Route::delete('users/{user}/edit/delete/avatar', 'deleteAvatar')->name('users.deleteAvatar');
            Route::post('users/{user}/edit/sentmail', 'sendMail')->name('users.sendmail');
            Route::get('users/{user}/edit/password', 'password')->name('users.password');
            Route::post('users/{user}/edit/password', 'updatePassword')->name('users.password.update');
            ModuleRoutes::slot('Billing/web-3');
            Route::post('users/{user}/login', 'loginAsUser')->name('users.login');
        });
        Route::resource('users', Admin\UserController::class);

        Route::controller(Admin\TemplateController::class)->group(function () {
            Route::get('/templates', 'index')->name('templates.index');
            Route::post('/templates', 'templatesActive')->name('templates.active');
        });

        ModuleRoutes::slot('Storefront/web-4');

        ModuleRoutes::slot('AppStore/web-1');

        ModuleRoutes::slot('Storefront/web-5');

        /* stores P3-2: marketplace review moderation queue (themes + apps) */
        Route::controller('MarketplaceReviewController')->group(function () {
            Route::get('/reviews', 'index')->name('reviews.index');
            Route::post('/reviews/{id}/approve', 'approve')->name('reviews.approve');
            Route::post('/reviews/{id}/reject', 'reject')->name('reviews.reject');
        });

        ModuleRoutes::slot('AppStore/web-2');
        ModuleRoutes::slot('AppStore/ee-web-2');

        ModuleRoutes::slot('Marketplace/web-2');

        ModuleRoutes::slot('Billing/web-4');
        Route::get('receipts', 'TransactionController@receipts')->name('receipts'); // MC-02 durable charge history
        Route::resource('transactions', Admin\TransactionController::class);
        Route::post('transactions.delete', [Admin\TransactionController::class, 'delete'])->name('transactions.delete');

        ModuleRoutes::slot('Billing/web-6');

        Route::resource('coupons', Admin\CouponController::class);
        Route::post('coupons.delete', [Admin\CouponController::class, 'delete'])->name('coupons.delete');

        Route::resource('testimonials', Admin\TestimonialController::class);
        Route::post('testimonials.delete', [Admin\TestimonialController::class, 'delete'])->name('testimonials.delete');

        Route::resource('advertisements', Admin\AdsController::class);

        Route::resource('pages', Admin\PageController::class);
        ModuleRoutes::slot('Content/web-4');

        Route::resource('faqs', Admin\FaqController::class);

        Route::controller(Admin\PaymentGatewayController::class)->group(function () {
            Route::get('/payment-gateways', 'index')->name('gateways.index');
            Route::get('payment-gateways/{gateway}/edit', 'edit')->name('gateways.edit');
            Route::post('payment-gateways/{gateway}', 'update')->name('gateways.update');
            // G10: Paylink partner onboarding audit (all attempts + statuses).
            Route::get('paylink-partner/onboardings', 'partnerOnboardings')->name('paylink.onboardings');
        });

        Route::controller(Admin\EmailTemplateController::class)->group(function () {
            Route::get('/email-templates', 'index')->name('mailtemplates.index');
            Route::post('email-templates/update', 'update')->name('mailtemplates.update');
        });

        Route::resource('subscriber', Admin\SubscriberController::class);

        Route::controller(Admin\LanguageController::class)->group(function () {
            Route::post('languages/reorder', 'reorder')->name('languages.reorder');
            Route::get('languages/translate/{code}', 'translate')->name('languages.translates');
            Route::post('languages/{language}/translate', 'translateUpdate')->name('languages.translates.update');
            // HPMS E13: missing-string view + JSON pack export/import.
            Route::get('languages/missing/{code}', 'missingStrings')->name('languages.missing');
            Route::get('languages/export/{code}', 'exportPack')->name('languages.export');
            Route::post('languages/import/{code}', 'importPack')->name('languages.import');
            // LANG-PIPELINE (Lane B surfaces): per-locale chrome-translate pipeline —
            // coverage/search/review page, machine-translate kickoff (ratelimit:ai) +
            // its progress poll, and review approve/reject. The coverage DASHBOARD is
            // added to the languages index (no new sidebar item). Static `pipeline/`
            // segment + progress route first so neither is captured by resource {language}.
            Route::get('languages/pipeline/progress/{code}', 'pipelineProgress')->name('languages.pipeline.progress');
            Route::get('languages/pipeline/{code}', 'pipeline')->name('languages.pipeline');
            Route::post('languages/pipeline/{code}/translate', 'pipelineTranslate')->middleware('ratelimit:ai')->name('languages.pipeline.translate');
            Route::post('languages/pipeline/{code}/approve', 'pipelineApprove')->name('languages.pipeline.approve');
            Route::post('languages/pipeline/{code}/reject', 'pipelineReject')->name('languages.pipeline.reject');
        });
        Route::resource('languages', Admin\LanguageController::class);

        Route::get('settings', [Admin\SettingsController::class, 'index'])->name('settings.index');
        Route::post('settings', [Admin\SettingsController::class, 'update'])->name('settings.update');
        /* J51b: Settings > Legal & compliance (owner facts for the legal pages). Superadmin only: the `admin` middleware of
           this group plus Gate admin.settings in the controller constructor, same as Admin\SettingsController. */
        Route::get('settings/legal', [LegalSettingsController::class, 'edit'])->name('settings.legal');
        Route::post('settings/legal', [LegalSettingsController::class, 'update'])->name('settings.legal.update');
        Route::get('settings/integrations', 'SettingsController@integrations')->name('settings.integrations');
        Route::post('settings/integrations', 'SettingsController@updateIntegrations')->name('settings.integrations.update');
        Route::post('settings/integrations/test', 'SettingsController@testIntegration')->name('settings.integrations.test');

        Route::resource('blogs', Admin\BlogController::class);
        Route::post('blog/delete', [Admin\BlogController::class, 'delete'])->name('blogs.delete');

        Route::resource('blog/categories', Admin\BlogCategoryController::class)->names('blog.category');
        Route::post('blog/categories/reorder', [Admin\BlogCategoryController::class, 'reorder'])->name('blog.category.reorder');
        Route::post('blog/categories/delete', [Admin\BlogCategoryController::class, 'delete'])->name('blog.category.delete');

        Route::get('blog/comments', [Admin\BlogCommentController::class, 'index'])->name('blog.comments.index');
        Route::post('blog/comments/delete', [Admin\BlogCommentController::class, 'delete'])->name('blog.comments.delete');
        Route::post('blog/comments/{id}/approve', [Admin\BlogCommentController::class, 'approve'])->name('blog.comments.approve');

        /* BLOG-AUTOPILOT B2/B3: autopilot tab (settings card via admin.settings.update
           + platform topic backlog). Platform topics only — reseller topics live
           behind the reseller portal endpoints in apiv1.php. */
        Route::get('blog/autopilot', 'BlogAutopilotController@index')->name('blog.autopilot.index');
        Route::post('blog/autopilot/topics', 'BlogAutopilotController@storeTopic')->name('blog.autopilot.topics.store');
        Route::post('blog/autopilot/topics/ideas', 'BlogAutopilotController@generateIdeas')->name('blog.autopilot.topics.ideas');
        Route::post('blog/autopilot/topics/{topic}/draft', 'BlogAutopilotController@draftNow')->whereNumber('topic')->name('blog.autopilot.topics.draft');
        Route::post('blog/autopilot/topics/{topic}/retry', 'BlogAutopilotController@retry')->whereNumber('topic')->name('blog.autopilot.topics.retry');
        Route::post('blog/autopilot/topics/{topic}/reject', 'BlogAutopilotController@reject')->whereNumber('topic')->name('blog.autopilot.topics.reject');

        Route::resource('plugins', Admin\PluginsController::class);
        Route::post('plugin/{id}/enable', [Admin\PluginsController::class, 'enable'])->name('plugins.enable');
        Route::post('plugin/{id}/disable', [Admin\PluginsController::class, 'disable'])->name('plugins.disable');

        ModuleRoutes::slot('Platform/web-3');
    });
});

/* Unsubscribe Route */
Route::get('campaigns/unsubscribe/{customer}', [CampaignController::class, 'unsubscribeConfirm'])->middleware(AddCspHeaders::class)->name('campaigns.unsubscribe'); // P6-122b (storefront unsubscribe view); P6-222: GET only confirms
Route::post('campaigns/unsubscribe/{customer}', [CampaignController::class, 'unsubscribe'])->middleware(['throttle:30,1,campaign-unsubscribe', AddCspHeaders::class])->name('campaigns.unsubscribe.do'); // P6-222: POST (form or RFC 8058 one-click) withdraws consent

/* Opt-out from a merchant campaign email. OUTSIDE the auth group — it is
   clicked from an inbox, often on a device with no session. The signed URL is
   the authorisation; the id alone proves nothing. */
Route::get('campaigns/merchant/unsubscribe/{user}', [MerchantCampaignUnsubscribeController::class, 'unsubscribeConfirm'])
    ->middleware(['throttle:20,1,merchant-campaign-unsubscribe', AddCspHeaders::class])
    ->name('campaigns.merchant.unsubscribe');
Route::post('campaigns/merchant/unsubscribe/{user}', [MerchantCampaignUnsubscribeController::class, 'unsubscribe'])
    ->middleware(['throttle:20,1,merchant-campaign-unsubscribe', AddCspHeaders::class])
    ->name('campaigns.merchant.unsubscribe.do');

/* WI-B4 (upsell-visibility): opt-out of trial reminder mail. Deliberately
   OUTSIDE the auth group — it is clicked from an inbox, often on a device with
   no session. The signed URL is the authorisation; the id alone proves nothing. */
ModuleRoutes::slot('Billing/web-7');

/* Cron Job — P6-119f: secret in the X-Juwri-Cron-Token header or a POST body
   (checked in the controller; ?token= only when CRON_ALLOW_QUERY_TOKEN=true) +
   throttled. POST carries no session, so CSRF does not apply. */
Route::match(['get', 'post'], '/cronjob', [MaintenanceController::class, 'cronjob'])
    ->middleware('throttle:6,1,core-cronjob')
    ->withoutMiddleware(PreventRequestForgery::class);

// #99 / P2-7 / stores P5-1: public developer docs (no auth). Wrapped in a
// localized group (prefix = LaravelLocalization::setLocale()) so /{locale}/developers
// resolves like /{locale}/mcp — the big localized group above closes at ~1808 and
// does NOT reach here, so without this /en/developers 404s in URL-prefix mode.
// Registered before the `{slug}` catch-all, or a restaurant page swallows them.
// Mirror the big localized group's CONDITIONAL locale handling (same as /mcp) so
// the language picker works in both modes: session mode uses app.locale
// (reads session('locale')); URL-prefix mode adds the /{locale} prefix so
// /en/developers resolves. The 'installed'/'checkUserIsBanned' guards are omitted
// — these are public docs that must render before the app is "installed".
$developerDocsGroup = config('settings.include_language_code')
    ? ['localized' => true, 'middleware' => ['localize', 'localizationRedirect', 'locale.remember', 'csp.marketing']]
    : ['middleware' => ['app.locale', 'csp.marketing']]; // P6-122b CSP
LocalizedRoutes::group($developerDocsGroup, function () {
    Route::get('developers', 'DeveloperDocsController@index')->name('developers');
    Route::get('developers/themes', 'DeveloperDocsController@themes')->name('developers.themes');
    Route::get('developers/apps', 'DeveloperDocsController@apps')->name('developers.apps');
});

// Platform P5: machine-readable API summary for AI agents.
Route::get('llms.txt', 'DeveloperDocsController@llms')->name('llms');

// B6 digital signage: PUBLIC token-secured boards. Registered BEFORE the {slug}
// catch-all. No auth cookie — the token is the only credential.
Route::get('signage/{token}', '\Juwri\Modules\Pos\Http\Controllers\SignageController@board')->name('signage.board')->middleware(['app.locale', 'throttle:public-check', 'csp.app']); // P6-122b
Route::get('signage/{token}/state', '\Juwri\Modules\Pos\Http\Controllers\SignageController@state')->name('signage.state')->middleware('throttle:120,1,core-signage-state');

ModuleRoutes::slot('Kitchen/web-3');

// P2-7: downloadable starter theme (a binary; kept unprefixed — the /developers
// HTML pages that link it are localized in the group above). Before the catch-all.
Route::get('developers/themes/starter.zip', 'DeveloperDocsController@starterZip')->middleware('throttle:20,1,core-developers-themes-starter-zip')->name('developers.themes.starter'); // D2-6: zip rebuild per hit

// INTEGRATIONS-RUN: tenant-facing integration guidance docs (whitelisted slugs),
// registered before the `{slug}` catch-all.
Route::get('integrations/docs/{slug}', '\Juwri\Modules\Content\Http\Controllers\IntegrationsDocsController@show')->middleware('csp.marketing')->name('integrations.docs'); // P6-122b

// T7-P3: GloriaFood migration landing page (before the {slug} catch-all).
// switch-from-gloriafood registers from the edition pitch slot.

ModuleRoutes::slot('Reseller/web-6');

// P4-4b: public theme marketplace (before the {slug} catch-all).
// stores P1-5: browse renders localized copy (EN/AR/…) — needs the session-locale
// middleware, same as studio/app. Without it /lang/xx switches are ignored here.
Route::get('marketplace', 'MarketplaceController@index')->middleware(['app.locale', 'throttle:search', 'csp.app'])->name('marketplace.index');
// Store-chrome language switch: the marketplace/app-store pages are UNPREFIXED
// and render session('locale') (app.locale). In URL-prefix mode there is
// no session-setting switch route (/lang/{code} 301s to the localized home), so
// the shared nav needs one that works in BOTH locale modes and returns back.
Route::get('marketplace/lang/{code}', 'MarketplaceController@setLocale')->name('store.lang')->where('code', '[a-z]{2}');
Route::get('marketplace/{slug}', 'MarketplaceController@show')->middleware(['app.locale', 'csp.app'])->name('marketplace.show')->where('slug', '[a-z0-9\-]+');
Route::get('marketplace/{slug}/preview', 'MarketplaceController@preview')->middleware(AddCspHeaders::class)->name('marketplace.preview')->where('slug', '[a-z0-9\-]+');
Route::post('marketplace/{slug}/buy', 'MarketplaceController@buy')->name('marketplace.buy')->middleware('throttle:20,1,marketplace-buy')->where('slug', '[a-z0-9\-]+');
Route::post('marketplace/{slug}/apply', 'MarketplaceController@apply')->name('marketplace.apply')->middleware('throttle:10,1,marketplace-apply')->where('slug', '[a-z0-9\-]+');
// stores P3-1: theme review write (eligibility server-checked; pending until moderated)
Route::post('marketplace/{slug}/review', 'MarketplaceReviewController@storeTheme')->middleware(['auth', 'staff.dashboard'])->name('marketplace.review')->where('slug', '[a-z0-9\-]+'); // owner-only: staff session = owner web guard

// stores P2: public App Store (approved apps only). `/apps` stays the logged-in
// install dashboard (auth group above); this is the public browse/detail surface.
// Registered before the {slug} catch-all; localized copy → app.locale.
Route::get('apps/store', '\Juwri\Modules\AppStore\Http\Controllers\AppStoreController@index')->middleware(['app.locale', 'throttle:search', 'csp.app'])->name('apps.store.index');
Route::get('apps/store/{slug}', '\Juwri\Modules\AppStore\Http\Controllers\AppStoreController@show')->middleware(['app.locale', 'csp.app'])->name('apps.store.show')->where('slug', '[a-z0-9\-]+');
// stores P3-1: app review write (eligibility = installed; pending until moderated)
Route::post('apps/store/{slug}/review', 'MarketplaceReviewController@storeApp')->middleware(['auth', 'staff.dashboard'])->name('apps.store.review')->where('slug', '[a-z0-9\-]+'); // owner-only: staff session = owner web guard

// stores P4: partner dashboard (any authed user can be a partner). Before {slug}.
//
// OWNER-ONLY: a staff dashboard session logs the web guard in AS THE OWNER
// (StaffLoginController), so `auth` alone let any staff role act as the owner's
// partner account — rotate a live app OAuth secret (DoS the integration), create/
// edit/publish the owner's marketplace listings, spend nothing but break things.
// staff.dashboard default-denies these unmapped routes for any staff session (403)
// while the owner passes through, the same fence the main dashboard group uses.
// P1-13 13h: the partner dashboard is ee (AppStore/ee-web-3, ee-web-4); installed-app re-consent is Community (AppStore/web-3).
Route::middleware(['staff.dashboard', 'csp.dashboard'])->group(function () { // P6-122: enforced CSP on the partner dashboard
    ModuleRoutes::slot('AppStore/ee-web-3');
    ModuleRoutes::slot('AppStore/web-3');
    ModuleRoutes::slot('AppStore/ee-web-4');
}); // end owner-only partner-seller group

// Agents P2: public on-menu chat widget endpoint (gated + metered in-controller).
Route::post('agent-chat/{storeId}/message', '\Juwri\Modules\Agents\Http\Controllers\AgentChatController@message')
    ->middleware(['throttle:30,1,core-agent-chat-message', 'ratelimit:ai'])->name('agent.chat.message');

// DOCS-PLATFORM D2-3: MCP discovery — advertises the public MCP servers so a
// client can find them without reading the docs. Brand-aware server names.
Route::get('.well-known/mcp.json', 'DeveloperDocsController@mcpDiscovery')->name('mcp.discovery');
ModuleRoutes::slot('Agents/web-2');

// Owner mobile app: deep-link association files (404 until the app identity is
// configured — see config/mobile_app.php).
Route::get('.well-known/apple-app-site-association', '\Juwri\Core\Http\Controllers\OwnerAppLinksController@appleAppSiteAssociation')->name('owner-app.aasa');
Route::get('.well-known/assetlinks.json', '\Juwri\Core\Http\Controllers\OwnerAppLinksController@assetLinks')->name('owner-app.assetlinks');
// P1-23: RFC 9116 security.txt + PGP key (404 unless LEGAL_PAGES).
ModuleRoutes::slot('Core/legal-3');

// Owner dashboard PWA (rev/mobile-app/PWA-SPEC.md): manifest, service worker,
// offline page and generated icons — public so they load before/without a session.
// The SW sits at the root so its scope can cover the whole dashboard URL space.
Route::get('dashboard-sw.js', 'OwnerPwaController@serviceWorker')->name('owner.pwa.sw');
Route::get('dashboard/manifest.webmanifest', 'OwnerPwaController@manifest')->name('owner.pwa.manifest');
Route::get('dashboard/offline', 'OwnerPwaController@offline')->middleware('csp.marketing')->name('owner.pwa.offline'); // P6-122b
Route::get('dashboard/pwa-icon-{size}.png', 'OwnerPwaController@icon')->whereNumber('size')->name('owner.pwa.icon');

// Owner mobile app, server item S6: Hotwire Native "historical location" routes
// (port of turbo-rails). The app's path configuration intercepts these URLs and
// pops / refreshes / stays on the current native screen; the server only needs
// them to exist so a browser that follows one gets a blank 200, never a 404.
// Controllers send an in-app modal form there with OwnerApp::historicalRedirect().
Route::get('recede_historical_location', '\Juwri\Core\Http\Controllers\OwnerAppLinksController@historicalLocation')->name('owner-app.historical.recede');
Route::get('resume_historical_location', '\Juwri\Core\Http\Controllers\OwnerAppLinksController@historicalLocation')->name('owner-app.historical.resume');
Route::get('refresh_historical_location', '\Juwri\Core\Http\Controllers\OwnerAppLinksController@historicalLocation')->name('owner-app.historical.refresh');

// Owner account deletion: signed restore link from the confirmation email
// (valid until the purge date; the owner is logged out, so no auth middleware).
// BL-036 A1b: GET = confirm page only (mail scanners pre-fetching the link change nothing;
// links already sent land here), POST (CSRF, same signature) restores.
Route::get('account/restore/{deletion}', '\Juwri\Core\Http\Controllers\User\AccountDeletionController@restoreConfirm')
    ->middleware(['signed', 'throttle:10,1,core-account-restore', 'csp.marketing'])->whereNumber('deletion')->name('account.deletion.restore');
Route::post('account/restore/{deletion}', '\Juwri\Core\Http\Controllers\User\AccountDeletionController@restore')
    ->middleware(['signed', 'throttle:10,1,core-account-restore', 'csp.marketing'])->whereNumber('deletion')->name('account.deletion.restore.perform');

// Agents P4: agent-discovery surfaces (UCP-style manifest + per-restaurant llms.txt).
Route::get('.well-known/ucp', 'AgentManifestController@wellKnown')->name('agent.wellknown');
Route::get('agent/{storeId}/manifest', 'AgentManifestController@manifest')->name('agent.manifest');
Route::get('agent/{storeId}/llms.txt', 'AgentManifestController@llms')->name('agent.llms');

ModuleRoutes::slot('Reseller/web-7');

ModuleRoutes::slot('Content/web-5');

// rev/seo R20: IndexNow key verification file at /<key>.txt. Registered before
// the {slug} catch-all so it isn't resolved as a restaurant page.
if ($indexNowKey = config('seo.indexnow_key')) {
    Route::get($indexNowKey.'.txt', function () use ($indexNowKey) {
        return response($indexNowKey, 200)->header('Content-Type', 'text/plain');
    })->name('indexnow.key');
}

// These pages render localized copy, so they need the session-locale middleware
// (they sit outside the localized HomeController group above).
// rev/seo R3: legacy /lang/{code} switch URLs (old sitemap + external links)
// still exist in the wild — in prefix mode they 301 to the localized home.
// (In session mode the in-group 'localize' route at the top of this file
// handles the same path as a session switch.)
if (config('settings.include_language_code')) {
    Route::get('lang/{lang}', 'HomeController@localizeRedirect')
        ->where('lang', '^[a-z]{2}$')->name('localize.legacy');
}

// rev/seo R2b: indexable studio marketing pages need per-locale URLs in prefix
// mode (/en/studio) and session locale otherwise — mirrors the main localized
// group near the top of this file.
if (! config('settings.include_language_code')) {
    $studioLocalized = ['middleware' => ['app.locale', 'csp.marketing']];
} else {
    $studioLocalized = [
        'localized' => true,
        'middleware' => ['localize', 'localizationRedirect', 'locale.remember', 'csp.marketing'],
    ];
}
LocalizedRoutes::group($studioLocalized, function () {
    Route::get('studio', '\Juwri\Modules\Content\Http\Controllers\Studio\StudioController@index')->name('studio');
    // Alternate landing designs kept as secondary pages (also single-segment → before {slug}).
    // studio-v1 and studio-v2 register from the edition pitch slot.
});
// Gemini-style studio app shell.
Route::get('studio/app', '\Juwri\Modules\Content\Http\Controllers\Studio\StudioController@app')->middleware(['app.locale', 'csp.marketing'])->name('studio.app.shell'); // P6-122b CSP
Route::post('studio/output', '\Juwri\Modules\Content\Http\Controllers\Studio\StudioController@setOutput')->middleware('throttle:30,1,core-studio-output')->name('studio.output');

// Folio Polish B8: public, anonymous flip-book page-view beacon (sendBeacon).
// No auth / feature middleware (a diner is not a subject); CSRF-exempt in
// PreventRequestForgery::$except; throttled because it fires on every page turn.
Route::post('folio-metrics/{storeId}', [FolioMetricsController::class, 'store'])
    ->middleware('throttle:60,1,core-folio-metrics')->whereNumber('storeId')->name('folio.metrics');
ModuleRoutes::slot('Content/web-6');

/* POST PAGE VIEW */
// This old-URL catch-all matches ANY `restaurant/{a}/{b}` — including the
// single-trailing-segment customer JSON endpoints in the auth:customer group
// below (my-orders, notifications), which it silently shadowed (they rendered
// the storefront HTML instead of JSON). Exclude those reserved second segments
// so the real controllers win. Two-segment paths (wallet/*, loyalty/balance)
// were never affected.
Route::get('restaurant/{slug}/{slug_title?}', 'User\StoreController@publicView')
    // Single path segment only ([^/]*, NOT .* which matches slashes) and never the
    // reserved customer-JSON sub-paths — otherwise this greedily swallows the
    // deeper auth:customer routes (wallet/balance, wallet/topup, my-orders…).
    ->where('slug_title', '^(?!(my-orders|notifications)$)[^/]*$') // for old urls
    ->middleware([EnforceStorefrontCspPerTemplate::class, AddCspHeaders::class]); // P6-122b: same policy as {slug}
// rev/seo R2b: in prefix mode the storefront GET pages live in a localized
// group too — their layouts already emit per-locale hreflang via lang_url(),
// and menu language switching targets those prefixed URLs. POST/JSON routes
// stay unprefixed (forms/JS use absolute route() URLs).
if (! config('settings.include_language_code')) {
    // WIRING-RUN: this used to be an EMPTY middleware list, which silently broke
    // language selection on the storefront in session mode. There is no URL prefix
    // to resolve here, but the diner's stored choice still has to be applied — the
    // main route group above uses `app.locale` for exactly this, and without
    // it every customer menu rendered in the default locale regardless of the
    // language picked, voiding the storefront translations entirely.
    $storefrontLocalized = ['middleware' => ['app.locale', 'cache.storefront']];
} else {
    $storefrontLocalized = [
        'localized' => true,
        'middleware' => ['localize', 'localizationRedirect', 'locale.remember', 'cache.storefront'],
    ];
}
Route::get('{slug}/manifest.json', 'User\StoreController@publicViewManifest')->name('publicView.manifest');
// BIOLINK-RUN A6: public click beacon (anon). POST stays unprefixed (JS posts to
// the absolute route() URL). The block id is the tapped bio_block; the beacon
// body echoes the source stamped on the page (G5). ratelimit like sibling writes.
Route::post('{slug}/bio/c/{block}', '\Juwri\Modules\Biolink\Http\Controllers\User\BioPublicController@click')->name('bio.public.click')->middleware(['throttle:60,1,core-bio-c', 'ratelimit:public-write'])->where('block', '[0-9]+');
// BIOLINK-RUN A8: email/phone capture → the store's existing Customer marketing
// rail. Honeypot + ratelimit; consent implied by submit (line rendered by C).
Route::post('{slug}/bio/subscribe', '\Juwri\Modules\Biolink\Http\Controllers\User\BioPublicController@subscribe')->name('bio.public.subscribe')->middleware(['throttle:10,1,core-bio-subscribe', 'ratelimit:public-write']);
// #56: Customer unsubscribe
Route::get('{slug}/unsubscribe', 'User\StoreController@unsubscribeInfo')->name('publicView.unsubscribe.info')->middleware(AddCspHeaders::class); // P6-122b
Route::post('{slug}/unsubscribe', 'User\StoreController@unsubscribe')->name('publicView.unsubscribe')->middleware(['throttle:10,1,core-unsubscribe', 'ratelimit:public-write']);
// #81: Public join waitlist
Route::post('{slug}/waitlist', '\Juwri\Modules\Reservations\Http\Controllers\User\WaitlistController@publicJoin')->name('publicView.waitlist.join')->middleware(['throttle:20,1,core-waitlist', 'ratelimit:public-write', 'branchlimit:waitlist']);
// #80: Public reservation booking
Route::post('{slug}/reservation', '\Juwri\Modules\Reservations\Http\Controllers\User\ReservationController@publicBook')->name('publicView.reservation.book')->middleware(['throttle:10,1,core-reservation', 'ratelimit:public-write', 'branchlimit:reservation']);
// T5: reservation deposit step (anti-no-show). Capability = the reservation's random token.
Route::get('{slug}/reservation/deposit/{token}', '\Juwri\Modules\Reservations\Http\Controllers\User\ReservationController@depositPay')->name('publicView.reservation.deposit')->middleware(AddCspHeaders::class); // P6-122b (AddPaymentPageCsp replaces it with the page's payment policy)
Route::post('{slug}/reservation/deposit/{token}', '\Juwri\Modules\Reservations\Http\Controllers\User\ReservationController@depositCharge')->name('publicView.reservation.deposit.charge')->middleware(['throttle:10,1,core-reservation-deposit', 'ratelimit:public-write']);
// B8 catering: public request submission (before the {slug} catch-all).
Route::post('{slug}/catering', '\Juwri\Modules\Ordering\Http\Controllers\User\CateringPublicController@store')->name('publicView.catering.request')->middleware(['throttle:10,1,core-catering', 'ratelimit:public-write']);
// Catering deposit step (async): customer pays via the link the owner sends after accepting.
Route::get('{slug}/catering/deposit/{token}', '\Juwri\Modules\Ordering\Http\Controllers\User\CateringPublicController@depositPay')->name('publicView.catering.deposit')->middleware(AddCspHeaders::class); // P6-122b (AddPaymentPageCsp replaces it with the page's payment policy)
Route::post('{slug}/catering/deposit/{token}', '\Juwri\Modules\Ordering\Http\Controllers\User\CateringPublicController@depositCharge')->name('publicView.catering.deposit.charge')->middleware(['throttle:10,1,core-catering-deposit', 'ratelimit:public-write']);
// B12 multi-vendor order hub: PUBLIC cross-store cart (before {slug}). Gated OFF —
// all routes 404 unless admin_allow_multivendor_cart is on.
/* Marketplace (Fusion) + Directory + Shopper public surface — extracted to
   routes/marketplace.php (R2d). `require`d here (NOT registered separately in
   RouteServiceProvider) so it runs in this same `web` group / namespace / scope
   and keeps its position BEFORE the {slug} catch-all — route table byte-identical. */
require __DIR__.'/marketplace.php';

// B9 kiosk: public kiosk web mode (before the {slug} catch-all).
Route::get('{slug}/kiosk', '\Juwri\Modules\Pos\Http\Controllers\KioskController@show')->name('publicView.kiosk')->middleware(['throttle:public-check', AddCspHeaders::class]); // P6-122b
Route::post('{slug}/kiosk/setup', '\Juwri\Modules\Pos\Http\Controllers\KioskController@setup')->name('publicView.kiosk.setup')->middleware(['throttle:10,1,staff-auth', 'ratelimit:public-write']);
Route::post('{slug}/kiosk/reset', '\Juwri\Modules\Pos\Http\Controllers\KioskController@reset')->name('publicView.kiosk.reset')->middleware(['throttle:20,1,core-kiosk-reset', 'ratelimit:public-write']);
Route::post('{slug}/kiosk/exit', '\Juwri\Modules\Pos\Http\Controllers\KioskController@exit')->name('publicView.kiosk.exit')->middleware(['throttle:10,1,staff-auth', 'ratelimit:public-write']);
// Item 26: loyalty at the kiosk (opt-in; a phone code or the customer's pass QR before anything is shown)
Route::post('{slug}/kiosk/loyalty/code', '\Juwri\Modules\Pos\Http\Controllers\KioskLoyaltyController@code')->name('publicView.kiosk.loyalty.code')->middleware(['throttle:5,1,kiosk-loyalty-code', 'ratelimit:public-write']);
Route::post('{slug}/kiosk/loyalty/verify', '\Juwri\Modules\Pos\Http\Controllers\KioskLoyaltyController@verify')->name('publicView.kiosk.loyalty.verify')->middleware(['throttle:10,1,kiosk-loyalty-verify', 'ratelimit:public-write']);
Route::post('{slug}/kiosk/loyalty/pass', '\Juwri\Modules\Pos\Http\Controllers\KioskLoyaltyController@pass')->name('publicView.kiosk.loyalty.pass')->middleware(['throttle:10,1,kiosk-loyalty-verify', 'ratelimit:public-write']);
Route::get('{slug}/kiosk/loyalty/me', '\Juwri\Modules\Pos\Http\Controllers\KioskLoyaltyController@me')->name('publicView.kiosk.loyalty.me')->middleware('throttle:60,1,kiosk-loyalty-read');
Route::post('{slug}/kiosk/loyalty/reorder', '\Juwri\Modules\Pos\Http\Controllers\KioskLoyaltyController@reorder')->name('publicView.kiosk.loyalty.reorder')->middleware(['throttle:30,1,kiosk-loyalty-read', 'ratelimit:public-write']);
Route::post('{slug}/kiosk/loyalty/forget', '\Juwri\Modules\Pos\Http\Controllers\KioskLoyaltyController@forget')->name('publicView.kiosk.loyalty.forget')->middleware(['throttle:30,1,kiosk-loyalty-read', 'ratelimit:public-write']);
Route::get('{slug}/kiosk-pass', '\Juwri\Modules\Pos\Http\Controllers\KioskLoyaltyController@issuePass')->name('publicView.kiosk.pass')->middleware('throttle:20,1,kiosk-loyalty-pass');
ModuleRoutes::slot('Loyalty/web-2');
/* J52b LegacyCompat: old customer paths of the routes above. Adds nothing while the module is off or while the core
   route above still answers the same method + path (never shadows core). */
LegacyCompatRoutes::registerCustomer();
// P0-4: CSP violation sink (report-only pass). Logs then 204s. No CSRF (browser-posted).
// P5-5: violations also feed the abuse metrics — a published theme phoning
// home to an off-platform host shows up as a per-host counter spike.
Route::post('csp-report', function (Request $request) {
    /* PROD CPU (2026-09-17): CSP reports are a BROWSER feature — a crawler has no
       policy to violate. In one 6h window 18,253 of the 19,967 POSTs here came
       from meta-externalads alone (92%), and each one boots the framework, writes
       a PlatformMetrics row and burns a cache op before returning an empty 204.
       Bots are also the one population whose reports are worthless: they tell us
       nothing about what real diners' browsers block. Drop them before any work.
       A denylist of crawler tokens, not an allowlist of browsers: a dropped
       report costs only telemetry, so err toward keeping unfamiliar agents. An
       absent UA is a script, not a browser — no engine omits it on a report. */
    $agent = (string) $request->userAgent();
    if ($agent === '' || preg_match('/bot|crawler|spider|externalads|externalhit|slurp|headless/i', $agent)) {
        return response()->noContent();
    }

    $payload = $request->json()->all() ?: $request->all();

    $blocked = data_get($payload, 'csp-report.blocked-uri', data_get($payload, 'blocked-uri', ''));
    $host = is_string($blocked) ? (parse_url($blocked, PHP_URL_HOST) ?: substr($blocked, 0, 60)) : 'unknown';
    $host = $host ?: 'unknown';

    // The per-host abuse metric records every report that gets this far (bots
    // having been dropped above) — that is the signal the
    // AbuseMonitor digest reads. The log WARNING, however, is one synchronous write
    // per browser violation: report-only CSP with `connect-src 'self'` trips on
    // every 3rd-party widget, so it was ~57k writes/day on the CPU-throttled prod
    // box. Rate-limit the LOG to at most one line per host per hour (a representative
    // sample for debugging) while the metric keeps the full aggregate. Cache::add is
    // an atomic add-if-absent, so this is one cheap cache op, not a read+write race.
    if (cache()->add('csp-log:'.$host, 1, 3600)) {
        Log::channel(config('logging.default'))->warning('csp.violation', $payload);
    }

    PlatformMetrics::record('csp.violation', $host);

    return response()->noContent();
})->name('csp.report')->middleware('throttle:csp-report');

// Dashboard client-error beacon: window.onerror/unhandledrejection in the old-theme
// layout navigator.sendBeacon() here so client-side breakage (extension DOM
// mutation, Alpine expression errors) is visible in logs. Session cookie rides
// the beacon, so auth()->id() resolves; CSRF-exempt (sendBeacon sets no header).
Route::post('dash/client-error', '\Juwri\Core\Http\Controllers\ClientErrorController@store')
    ->name('dash.clientError')->middleware('throttle:60,1,core-dash-client-error');

// SYSTEMS-RUN S-P1: public feedback survey (tokened) — MUST precede the {slug} catch-all.
Route::get('feedback/{token}', '\Juwri\Modules\Customers\Http\Controllers\User\FeedbackPublicController@show')->name('feedback.survey')->middleware(AddCspHeaders::class); // P6-122b
Route::post('feedback/{token}', '\Juwri\Modules\Customers\Http\Controllers\User\FeedbackPublicController@submit')->middleware('throttle:10,1,core-feedback')->name('feedback.submit');

ModuleRoutes::slot('Delivery/web-2');

/* WIRING-RUN A0: per-feature route files — MUST precede the {slug} catch-all.
   Each feature/track owns exactly one file under routes/features{,4,5}/ so
   parallel agents never edit this shared file. These used to be required ~400
   lines BELOW the catch-all, which silently broke any single-segment dashboard
   path they declared: GET /stories resolved to publicView and 404'd for every
   owner. RouteShadowingTest freezes the invariant for all such routes. */
/* The feature-track route files sit outside the localized group above, so their
   dashboard pages (hotel PMS, rooms, AI tools, biolink, …) 404'd under a locale
   prefix (/en/hotel/rooms) even though the unprefixed URL worked. Give them the
   same optional prefix: setLocale() is empty for a URL without a locale segment,
   so every unprefixed URL, POST and callback resolves exactly as before. No
   redirect middleware here — only the prefix. */
LocalizedRoutes::group(config('settings.include_language_code') ? ['localized' => true] : [], function () {
    foreach (glob(__DIR__.'/features/*.php') as $themePackFeatureRoutes) {
        require $themePackFeatureRoutes;
    }
    foreach (glob(__DIR__.'/features4/*.php') as $featuresRun4Routes) {
        require $featuresRun4Routes;
    }
    foreach (glob(__DIR__.'/features5/*.php') as $featuresRun5Routes) {
        require $featuresRun5Routes;
    }
});
/* INTEGRATIONS-PACK F0-11: per-lane route files (lane A = deliverect.php,
   lane D = ui.php) so parallel lanes never edit this shared file. Loaded here,
   inside the MUST-precede block, so any single-segment path they declare
   (e.g. /integrations) resolves before the {slug} storefront catch-all below. */
foreach (glob(__DIR__.'/integrations/*.php') as $integrationsRoutes) {
    require $integrationsRoutes;
}

/* MCP-PAGE (D2-9): GET /mcp lives INSIDE the localized route group near the
   top of this file (home parity in both locale modes — see the comment there).
   Only the POST-only 405 responders for the protocol sub-paths remain below. */

/* Streamable HTTP is POST-only in this implementation: no resumable SSE
   session is exposed. Register these before the storefront catch-all so GET
   deterministically returns 405 instead of being mistaken for a store slug.
   Only the four sub-paths keep the 405 — GET /mcp is the landing page above. */
foreach (['mcp/dev', 'mcp/storefront', 'mcp/catalog', 'mcp/customer'] as $mcpPath) {
    Route::get($mcpPath, static fn () => response()->noContent(405)->header('Allow', 'POST'));
}

LocalizedRoutes::group($storefrontLocalized, function () {
    // rev/multi-vertical F0-7: per-lane PUBLIC storefront routes, registered BEFORE
    // the {slug} catch-all so they are never shadowed by a store slug. Each lane owns
    // routes/verticals/public/lane-<x>.php.
    // P6-122b: the vertical storefront pages (cart, checkout, goods, laundry booking, wishlist, size guide, tracking)
    // carry the same enforced storefront policy as the {slug} page itself.
    Route::middleware([EnforceStorefrontCspPerTemplate::class, AddCspHeaders::class])->group(function () {
        foreach (glob(base_path('routes/verticals/public/lane-*.php')) ?: [] as $__verticalPublicLane) {
            require $__verticalPublicLane;
        }
    });

    ModuleRoutes::slot('Biolink/web-1');
    Route::get('{slug}', 'User\StoreController@publicView')->name('publicView')->middleware([EnforceStorefrontCspPerTemplate::class, AddCspHeaders::class, KioskStorefrontShell::class, MarkAnonymousPage::class]);
});

// ═══════════════════════════════════════════════════════════════════════════
// #99: REST API v1 + driver API + MCP servers
// Extracted to routes/apiv1.php (R2d). `require`d here — NOT registered
// separately in RouteServiceProvider — so it runs in this same `web` group /
// namespace / scope and keeps its position AFTER the {slug} catch-all above,
// leaving the route table byte-identical (route:list diff empty).
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__.'/apiv1.php';
