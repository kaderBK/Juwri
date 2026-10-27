<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http\Middleware;

use App\Models\StaffMember;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EnsureStaffDashboardPermission
{
    private const ROUTE_PERMISSION_MAP = [
        'dashboard' => null,
        // BL-036 A2a: the sidebar store/branch switcher. switchContext() only accepts stores the
        // actor can access, and a staff member pinned to a branch stays locked to it.
        'dashboard.context' => null,
        'stores.index' => null,

        // BL-036 A2a: integration credentials and connections under a grantable prefix stay
        // owner-only (integrations are never grantable). `false` = refused for every staff member.
        'stores.dispatch.couriers' => false,
        'stores.dispatch.couriers.save' => false,
        'stores.dispatch.couriers.disconnect' => false,
        'stores.accounting.connect' => false,
        'stores.accounting.disconnect' => false,
        'fiscal.credentials' => false,
        'waitlist.rwg-settings' => false,
        'reservations.settings' => false,
        'media.sources.settings' => false,
        // BL-036 review: fiscal device onboarding / certificate renewal are owner-only credentials,
        // and confirming a manual deposit refund is a money action (owner-only).
        'fiscal.zatca.onboard' => false,
        'fiscal.zatca.renew' => false,
        'fiscal.eta.device' => false,
        'reservations.deposit.manual-refund' => false,

        'stores.orders' => 'orders',
        'stores.orders.show' => 'orders',
        'stores.completeOrder' => 'orders',
        'stores.updateOrderStatus' => 'orders',
        'stores.deleteOrder' => 'orders',

        'stores.show' => 'menu_edit',
        'stores.edit' => 'menu_edit',
        'stores.update' => 'menu_edit',
        'stores.qrDesigner' => 'menu_edit',
        'stores.menu' => 'menu_edit',
        'stores.addCategory' => 'menu_edit',
        'stores.updateCategory' => 'menu_edit',
        'stores.deleteCategory' => 'menu_edit',
        'stores.reorderCategory' => 'menu_edit',
        'stores.addSubCategory' => 'menu_edit',
        'stores.updateSubCategory' => 'menu_edit',
        'stores.addMenuItem' => 'menu_edit',
        'stores.updateMenuItem' => 'menu_edit',
        'stores.deleteMenuItem' => 'menu_edit',
        'stores.reorderMenuItem' => 'menu_edit',
        'stores.menuBulk' => 'menu_edit',
        'stores.menuExportCsv' => 'menu_edit',
        'stores.menuImportCsv' => 'menu_edit',
        'stores.menuItemExtras' => 'menu_edit',
        'stores.menu.aiGenerate' => 'menu_edit',
        'stores.menu.aiSave' => 'menu_edit',
        'stores.menuAddVariantOption' => 'menu_edit',
        'stores.menuUpdateVariantOption' => 'menu_edit',
        'stores.menuDeleteVariantOption' => 'menu_edit',
        'stores.menuReorderVariantOption' => 'menu_edit',
        'stores.menuAddVariant' => 'menu_edit',
        'stores.menuUpdateVariant' => 'menu_edit',
        'stores.menuDeleteVariant' => 'menu_edit',
        'stores.menuReorderVariant' => 'menu_edit',
        'stores.menuAddExtra' => 'menu_edit',
        'stores.menuUpdateExtra' => 'menu_edit',
        'stores.menuDeleteExtra' => 'menu_edit',
        'stores.menuReorderExtra' => 'menu_edit',
        'stores.addImageMenuItem' => 'menu_edit',
        'stores.updateImageMenuItem' => 'menu_edit',
        'stores.deleteImageMenuItem' => 'menu_edit',
        'stores.reorderImageMenuItem' => 'menu_edit',

        'customers.index' => 'customers',
        'customers.show' => 'customers',
        'customers.update' => 'customers',
        'customers.export' => 'customers',

        'analytics.index' => 'analytics',
        'analytics.export' => 'analytics',
        'dashboard.reviews' => 'analytics',
        'dashboard.reviews.update' => 'analytics',

        // KDS surface: every kds.* dashboard route rides one `kds` staff permission
        // (see the kds. prefix rule in permissionForRoute). Explicit entries kept for
        // clarity; the prefix rule also covers scheduled/release/setMode/item.*/
        // fireCourse/allDay/analytics, which were previously unmapped => default-deny,
        // so a staff member WITH the kds permission 403'd on the scheduled poll and the
        // rest of the board (crashing the KDS scheduled panel on the error payload).
        // BL-036 A2b: the POS register runs on the staff guard (PosController checks `pos` itself);
        // mapped here so the sidebar shows it to staff who hold `pos`.
        'pos.index' => 'pos',
        'kds.index' => 'kds',
        'kds.poll' => 'kds',
        'kds.bump' => 'kds',

        // WI-H1: hotel guest-folio surface. One key covers reads and writes on purpose —
        // hotel.stays.show / .print already render the full folio statement, so the read
        // screens carry the same data sensitivity as the settle endpoint.
        'hotel.stays' => 'hotel_folio',
        'hotel.stays.show' => 'hotel_folio',
        'hotel.stays.print' => 'hotel_folio',
        'hotel.folio.checkin' => 'hotel_folio',
        'hotel.folio.statement' => 'hotel_folio',
        'hotel.folio.settle' => 'hotel_folio',
        'hotel.folio.checkout' => 'hotel_folio',
        // HP merge: lane A shipped these folio-write endpoints (A5 manual adjust,
        // A11 stay edit/cancel). Unmapped routes default-deny staff, so map them to
        // the same hotel_folio key as settle — the folio operator already sees the
        // full statement and needs to correct/void charges and fix guest details.
        'hotel.folio.adjust' => 'hotel_folio',
        'hotel.folio.stay.update' => 'hotel_folio',
        'hotel.folio.stay.cancel' => 'hotel_folio',
        // HP-B1: the rest of the hotel dashboard was unmapped, so EVERY staff member
        // 403'd on the service-request board (the intended staff tool), rooms admin and
        // the info/settings screens. Two operational keys open them:
        //  - hotel_requests: the housekeeping/maintenance board staff actually work.
        //  - hotel_admin:    rooms CRUD + guest-info/settings + the hotel_mode toggle
        //    (owner-level config; granted to managers only by default).
        'hotel.requests' => 'hotel_requests',
        'hotel.requests.store' => 'hotel_requests',
        'hotel.requests.status' => 'hotel_requests',
        'hotel.rooms' => 'hotel_admin',
        'hotel.rooms.store' => 'hotel_admin',
        'hotel.rooms.bulk' => 'hotel_admin',
        'hotel.rooms.delete' => 'hotel_admin',
        'hotel.rooms.print' => 'hotel_admin',
        'hotel.info' => 'hotel_admin',
        'hotel.info.save' => 'hotel_admin',
        'hotel.mode' => 'hotel_admin',

        // HPMS (booking engine — routes/features4/hotel-pms.php, all strict tier).
        // Every new dashboard route is mapped or staff 403 (default-deny). Front-desk
        // booking + folio operations ride hotel_folio; inventory/rate/service/event
        // configuration rides hotel_admin (owner/manager); the housekeeping board is
        // hotel_requests (the same staff tool as the service-request board).
        'hotel.pms.availability' => 'hotel_folio',
        'hotel.pms.calendar' => 'hotel_folio',
        'hotel.pms.bookings' => 'hotel_folio',
        'hotel.pms.bookings.store' => 'hotel_folio',
        'hotel.pms.bookings.show' => 'hotel_folio',
        'hotel.pms.bookings.update' => 'hotel_folio',
        'hotel.pms.bookings.confirm' => 'hotel_folio',
        'hotel.pms.bookings.cancel' => 'hotel_folio',
        'hotel.pms.bookings.noshow' => 'hotel_folio',
        'hotel.pms.bookings.checkin' => 'hotel_folio',
        'hotel.pms.services.post' => 'hotel_folio',
        'hotel.pms.events.settle' => 'hotel_folio',
        'hotel.pms.types' => 'hotel_admin',
        'hotel.pms.types.store' => 'hotel_admin',
        'hotel.pms.types.update' => 'hotel_admin',
        'hotel.pms.types.delete' => 'hotel_admin',
        'hotel.pms.plans' => 'hotel_admin',
        'hotel.pms.plans.store' => 'hotel_admin',
        'hotel.pms.plans.update' => 'hotel_admin',
        'hotel.pms.plans.delete' => 'hotel_admin',
        'hotel.pms.rates' => 'hotel_admin',
        'hotel.pms.rates.bulk' => 'hotel_admin',
        'hotel.pms.services' => 'hotel_admin',
        'hotel.pms.services.store' => 'hotel_admin',
        'hotel.pms.services.update' => 'hotel_admin',
        'hotel.pms.services.delete' => 'hotel_admin',
        'hotel.pms.events' => 'hotel_admin',
        'hotel.pms.events.show' => 'hotel_admin',
        'hotel.pms.events.store' => 'hotel_admin',
        'hotel.pms.events.update' => 'hotel_admin',
        'hotel.pms.events.delete' => 'hotel_admin',
        'hotel.pms.events.items.add' => 'hotel_admin',
        'hotel.pms.events.items.remove' => 'hotel_admin',
        'hotel.pms.rooms.oo' => 'hotel_admin',
        'hotel.pms.rooms.type' => 'hotel_admin',
        'hotel.pms.housekeeping' => 'hotel_requests',
        'hotel.pms.housekeeping.task' => 'hotel_requests',
    ];

    public function handle(Request $request, Closure $next)
    {
        if (! is_staff_dashboard()) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();

        if (! $routeName) {
            abort(403);
        }

        $permission = $this->permissionForRoute($routeName);

        if ($permission === false) {
            abort(403);
        }

        if ($permission && ! dashboard_allows($permission)) {
            abort(403);
        }

        if (! $this->routeTargetsAccessibleRestaurant($request)) {
            abort(403);
        }

        return $next($request);
    }

    /**
     * BL-036 A2b: would the current staff session be let through to this named route? The
     * sidebar and account menus use it so staff never see links that 403. Always true outside
     * a staff session (the server still enforces either way).
     */
    public static function staffMayOpen(string $routeName): bool
    {
        if (! is_staff_dashboard()) {
            return true;
        }

        $permission = (new self)->permissionForRoute($routeName);

        return $permission !== false && ($permission === null || dashboard_allows($permission));
    }

    private function permissionForRoute(string $routeName): string|bool|null
    {
        if (array_key_exists($routeName, self::ROUTE_PERMISSION_MAP)) {
            return self::ROUTE_PERMISSION_MAP[$routeName];
        }

        // BL-036 A2a: the grantable areas of the staff permission sheet, by route-name prefix.
        foreach (StaffMember::PERMISSION_CATALOG as $group) {
            foreach ($group as $permission => $spec) {
                if (in_array($routeName, $spec['routes'] ?? [], true)) {
                    return $permission;
                }
                foreach ($spec['prefixes'] ?? [] as $prefix) {
                    if (Str::startsWith($routeName, $prefix)) {
                        return $permission;
                    }
                }
            }
        }

        if (Str::startsWith($routeName, 'stores.coupons.')) {
            return 'coupons';
        }

        // KDS: one `kds` permission opens the whole kitchen-display surface (board
        // poll, bump/recall, fire-course, scheduled/release, all-day, set-mode,
        // kitchen analytics). Unmapped => default-deny, which 403'd granted staff on
        // every route beyond index/poll/bump; the prefix mirrors the biolink idiom.
        if (Str::startsWith($routeName, 'kds.')) {
            return 'kds';
        }

        // BIOLINK-RUN (Lane B): every biolink.* dashboard route (settings, builder,
        // block CRUD, insights) rides one `biolink` staff permission. Unmapped =>
        // default-deny, which would 403 even a granted staff member; map the whole
        // prefix so a staff member WITH the permission reaches all of them and one
        // WITHOUT it 403s uniformly (nav is hidden via dashboard_allows('biolink')).
        if (Str::startsWith($routeName, 'biolink.')) {
            return 'biolink';
        }

        return false;
    }

    private function routeTargetsAccessibleRestaurant(Request $request): bool
    {
        $store = $request->route('store');

        if ($store && ! dashboard_can_access_store($store)) {
            return false;
        }

        $storeId = $request->route('storeId');
        if ($storeId && ! dashboard_can_access_store($storeId)) {
            return false;
        }

        $order = $request->route('order');
        if ($order && ! dashboard_can_access_order($order)) {
            return false;
        }

        return true;
    }
}
