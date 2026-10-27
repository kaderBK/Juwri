<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
|--------------------------------------------------------------------------
| juwri module map: layers and allowed module -> module dependencies (P1-02)
|--------------------------------------------------------------------------
|
| Source: juwri-architecture.html (modules, 6 layers) and the editions
| module map.
|
| Rules (read by tests/Architecture/ModuleBoundariesTest.php):
|  - A module may import Core (Juwri\Core) and any module in a LOWER layer
|    ('allowed' lists them explicitly). Same-layer imports are allowed only
|    where listed below (Auth -> Core, Hr -> Team, Pharmacy/Laundry -> Verticals).
|    Everything else goes through events or interfaces owned by the lower module.
|  - Community code (app/Core, app/Modules) never imports an edition
|    package; an edition package may import Community.
|  - From another module import only its public parts (Contracts, Data,
|    Events; Models may be read downward, never written).
|
| Layers: 0 Core, 1 Shared services, 2 Foundations, 3 Transactions,
|         4 Operations, 5 Experiences.
| Edition: community | ee | split. This file lists the Community modules;
| modules of an installed edition package (and its halves of split modules)
| come from that package's manifest, merged by Juwri\Core\Support\ModuleMap.
| The map is acyclic by construction (edges only point down, plus the listed
| same-layer edges); the boundary test checks that too.
*/

return [
    'Core' => [
        'layer' => 0,
        'edition' => 'community',
        'path' => 'app/Core',
        'namespace' => 'Juwri\\Core',
        // Tenancy (accounts, stores, branches), users, money and currencies, languages, settings, feature registry, UI kit, module registry, entitlements, outbox, security helpers. Lives in app/Core (Juwri\Core).
        'allowed' => [],
    ],
    'Auth' => [
        'layer' => 0,
        'edition' => 'community',
        'path' => 'app/Modules/Auth',
        'namespace' => 'Juwri\\Modules\\Auth',
        // Sign-in: password, 2FA, staff PIN, phone OTP, social login (architecture page folds sign-in into Core; kept as its own L0 module per EDITIONS.md + data maps). SSO/SAML/SCIM -> ee.
        // P1-13 13h: extension point Auth\Contracts\SsoProvider (Community default: none); no ee code yet.
        'allowed' => ['Core'],
    ],
    'Payments' => [
        'layer' => 1,
        'edition' => 'community',
        'path' => 'app/Modules/Payments',
        'namespace' => 'Juwri\\Modules\\Payments',
        // Gateway adapters (bring-your-own keys). Platform payments / revenue share -> Cloud.
        'allowed' => ['Core', 'Auth'],
    ],
    'Fiscal' => [
        'layer' => 1,
        'edition' => 'community',
        'path' => 'app/Modules/Fiscal',
        'namespace' => 'Juwri\\Modules\\Fiscal',
        // Invoices, tax, fiscal QR (ZATCA). Certified e-invoicing network connectors are Community too (owner, 2026-10-03).
        'allowed' => ['Core', 'Auth'],
    ],
    'Messaging' => [
        'layer' => 1,
        'edition' => 'community',
        'path' => 'app/Modules/Messaging',
        'namespace' => 'Juwri\\Modules\\Messaging',
        // Email, WhatsApp, SMS, push with own credentials.
        'allowed' => ['Core', 'Auth'],
    ],
    'Media' => [
        'layer' => 1,
        'edition' => 'community',
        'path' => 'app/Modules/Media',
        'namespace' => 'Juwri\\Modules\\Media',
        // Uploads, image processing, storage drivers.
        'allowed' => ['Core', 'Auth'],
    ],
    'Ai' => [
        'layer' => 1,
        'edition' => 'community',
        'path' => 'app/Modules/Ai',
        'namespace' => 'Juwri\\Modules\\Ai',
        // AI provider chain with own keys. Hosted credits -> Cloud.
        'allowed' => ['Core', 'Auth'],
    ],
    'Menu' => [
        'layer' => 2,
        'edition' => 'community',
        'path' => 'app/Modules/Menu',
        'namespace' => 'Juwri\\Modules\\Menu',
        // Menus, modifiers, availability. Central chain menu push -> ee.
        'allowed' => ['Core', 'Auth', 'Payments', 'Fiscal', 'Messaging', 'Media', 'Ai'],
    ],
    'Customers' => [
        'layer' => 2,
        'edition' => 'community',
        'path' => 'app/Modules/Customers',
        'namespace' => 'Juwri\\Modules\\Customers',
        // Profiles, addresses, consent.
        'allowed' => ['Core', 'Auth', 'Payments', 'Fiscal', 'Messaging', 'Media', 'Ai'],
    ],
    'Team' => [
        'layer' => 2,
        'edition' => 'community',
        'path' => 'app/Modules/Team',
        'namespace' => 'Juwri\\Modules\\Team',
        // Staff, roles, shifts.
        'allowed' => ['Core', 'Auth', 'Payments', 'Fiscal', 'Messaging', 'Media', 'Ai'],
    ],
    'Hr' => [
        'layer' => 2,
        'edition' => 'community',
        'path' => 'app/Modules/Hr',
        'namespace' => 'Juwri\\Modules\\Hr',
        // Basic staff records (architecture page folds HR into Team; kept separate per EDITIONS.md). Payroll exports -> ee.
        'allowed' => ['Core', 'Auth', 'Payments', 'Fiscal', 'Messaging', 'Media', 'Ai', 'Team'],
    ],
    'Tables' => [
        'layer' => 2,
        'edition' => 'community',
        'path' => 'app/Modules/Tables',
        'namespace' => 'Juwri\\Modules\\Tables',
        // Floor plans, QR table ordering.
        'allowed' => ['Core', 'Auth', 'Payments', 'Fiscal', 'Messaging', 'Media', 'Ai'],
    ],
    'Ordering' => [
        'layer' => 3,
        'edition' => 'community',
        'path' => 'app/Modules/Ordering',
        'namespace' => 'Juwri\\Modules\\Ordering',
        // Cart, checkout, order lifecycle, scheduling.
        'allowed' => ['Core', 'Auth', 'Payments', 'Fiscal', 'Messaging', 'Media', 'Ai', 'Menu', 'Customers', 'Team', 'Hr', 'Tables'],
    ],
    'Reservations' => [
        'layer' => 3,
        'edition' => 'community',
        'path' => 'app/Modules/Reservations',
        'namespace' => 'Juwri\\Modules\\Reservations',
        // Bookings, waitlist.
        'allowed' => ['Core', 'Auth', 'Payments', 'Fiscal', 'Messaging', 'Media', 'Ai', 'Menu', 'Customers', 'Team', 'Hr', 'Tables'],
    ],
    'Bookings' => [
        'layer' => 3,
        'edition' => 'community',
        'path' => 'app/Modules/Bookings',
        'namespace' => 'Juwri\\Modules\\Bookings',
        // P3-10: services booking engine (staff x service x duration, holds, deposits, reminders) beside Reservations
        // (tables + RwG). Community (owner Q2); plan keys `booking` / `booking_api`.
        'allowed' => ['Core', 'Auth', 'Payments', 'Fiscal', 'Messaging', 'Media', 'Ai', 'Menu', 'Customers', 'Team', 'Hr', 'Tables'],
    ],
    'Pos' => [
        'layer' => 4,
        'edition' => 'community',
        'path' => 'app/Modules/Pos',
        'namespace' => 'Juwri\\Modules\\Pos',
        // Register, offline mode, receipts.
        'allowed' => ['Core', 'Auth', 'Payments', 'Fiscal', 'Messaging', 'Media', 'Ai', 'Menu', 'Customers', 'Team', 'Hr', 'Tables', 'Ordering', 'Reservations'],
    ],
    'Kitchen' => [
        'layer' => 4,
        'edition' => 'community',
        'path' => 'app/Modules/Kitchen',
        'namespace' => 'Juwri\\Modules\\Kitchen',
        // KDS, printing.
        'allowed' => ['Core', 'Auth', 'Payments', 'Fiscal', 'Messaging', 'Media', 'Ai', 'Menu', 'Customers', 'Team', 'Hr', 'Tables', 'Ordering', 'Reservations'],
    ],
    'Delivery' => [
        'layer' => 4,
        'edition' => 'community',
        'path' => 'app/Modules/Delivery',
        'namespace' => 'Juwri\\Modules\\Delivery',
        // Own drivers, zones, driver app.
        'allowed' => ['Core', 'Auth', 'Payments', 'Fiscal', 'Messaging', 'Media', 'Ai', 'Menu', 'Customers', 'Team', 'Hr', 'Tables', 'Ordering', 'Reservations'],
    ],
    'Channels' => [
        'layer' => 4,
        'edition' => 'community',
        'path' => 'app/Modules/Channels',
        'namespace' => 'Juwri\\Modules\\Channels',
        // Delivery-marketplace / POS-bridge connectors.
        'allowed' => ['Core', 'Auth', 'Payments', 'Fiscal', 'Messaging', 'Media', 'Ai', 'Menu', 'Customers', 'Team', 'Hr', 'Tables', 'Ordering', 'Reservations'],
    ],
    'Loyalty' => [
        'layer' => 4,
        'edition' => 'community',
        'path' => 'app/Modules/Loyalty',
        'namespace' => 'Juwri\\Modules\\Loyalty',
        // Points, rewards, gift cards, coupons.
        'allowed' => ['Core', 'Auth', 'Payments', 'Fiscal', 'Messaging', 'Media', 'Ai', 'Menu', 'Customers', 'Team', 'Hr', 'Tables', 'Ordering', 'Reservations'],
    ],
    'Inventory' => [
        'layer' => 4,
        'edition' => 'split',
        'path' => 'app/Modules/Inventory',
        'namespace' => 'Juwri\\Modules\\Inventory',
        // Stock, recipes, suppliers = Community (single-branch inventory in full). Owner 2026-10-05: stock transfers
        // between branches are a chain tool -> the ee half (Chain or Enterprise licence).
        'allowed' => ['Core', 'Auth', 'Payments', 'Fiscal', 'Messaging', 'Media', 'Ai', 'Menu', 'Customers', 'Team', 'Hr', 'Tables', 'Ordering', 'Reservations'],
    ],
    'Hotel' => [
        'layer' => 4,
        'edition' => 'community',
        'path' => 'app/Modules/Hotel',
        'namespace' => 'Juwri\\Modules\\Hotel',
        // Room service, charge-to-room, folios.
        'allowed' => ['Core', 'Auth', 'Payments', 'Fiscal', 'Messaging', 'Media', 'Ai', 'Menu', 'Customers', 'Team', 'Hr', 'Tables', 'Ordering', 'Reservations'],
    ],
    'Verticals' => [
        'layer' => 4,
        'edition' => 'community',
        'path' => 'app/Modules/Verticals',
        'namespace' => 'Juwri\\Modules\\Verticals',
        // Vertical presets (cafe, bakery, grocery...).
        'allowed' => ['Core', 'Auth', 'Payments', 'Fiscal', 'Messaging', 'Media', 'Ai', 'Menu', 'Customers', 'Team', 'Hr', 'Tables', 'Ordering', 'Reservations'],
    ],
    'Pharmacy' => [
        'layer' => 4,
        'edition' => 'community',
        'path' => 'app/Modules/Pharmacy',
        'namespace' => 'Juwri\\Modules\\Pharmacy',
        // Pharmacy vertical (architecture page: sub-folder of Verticals; own module here, P1-13 13c-R: Community).
        'allowed' => ['Core', 'Auth', 'Payments', 'Fiscal', 'Messaging', 'Media', 'Ai', 'Menu', 'Customers', 'Team', 'Hr', 'Tables', 'Ordering', 'Reservations', 'Verticals'],
    ],
    'Laundry' => [
        'layer' => 4,
        'edition' => 'community',
        'path' => 'app/Modules/Laundry',
        'namespace' => 'Juwri\\Modules\\Laundry',
        // Laundry vertical (architecture page: sub-folder of Verticals; own module here, P1-13 13c-R: Community).
        'allowed' => ['Core', 'Auth', 'Payments', 'Fiscal', 'Messaging', 'Media', 'Ai', 'Menu', 'Customers', 'Team', 'Hr', 'Tables', 'Ordering', 'Reservations', 'Verticals'],
    ],
    'Accounting' => [
        'layer' => 4,
        'edition' => 'community',
        'path' => 'app/Modules/Accounting',
        'namespace' => 'Juwri\\Modules\\Accounting',
        // Ledger exports, accounting integrations.
        'allowed' => ['Core', 'Auth', 'Payments', 'Fiscal', 'Messaging', 'Media', 'Ai', 'Menu', 'Customers', 'Team', 'Hr', 'Tables', 'Ordering', 'Reservations'],
    ],
    'Storefront' => [
        'layer' => 5,
        'edition' => 'community',
        'path' => 'app/Modules/Storefront',
        'namespace' => 'Juwri\\Modules\\Storefront',
        // Store site + kit themes.
        'allowed' => ['Core', 'Auth', 'Payments', 'Fiscal', 'Messaging', 'Media', 'Ai', 'Menu', 'Customers', 'Team', 'Hr', 'Tables', 'Ordering', 'Reservations', 'Bookings', 'Pos', 'Kitchen', 'Delivery', 'Channels', 'Loyalty', 'Inventory', 'Hotel', 'Verticals', 'Pharmacy', 'Laundry', 'Accounting'],
    ],
    'Biolink' => [
        'layer' => 5,
        'edition' => 'community',
        'path' => 'app/Modules/Biolink',
        'namespace' => 'Juwri\\Modules\\Biolink',
        // Link-in-bio pages.
        'allowed' => ['Core', 'Auth', 'Payments', 'Fiscal', 'Messaging', 'Media', 'Ai', 'Menu', 'Customers', 'Team', 'Hr', 'Tables', 'Ordering', 'Reservations', 'Pos', 'Kitchen', 'Delivery', 'Channels', 'Loyalty', 'Inventory', 'Hotel', 'Verticals', 'Pharmacy', 'Laundry', 'Accounting'],
    ],
    'Content' => [
        'layer' => 5,
        'edition' => 'community',
        'path' => 'app/Modules/Content',
        'namespace' => 'Juwri\\Modules\\Content',
        // Pages, blog, help, GrapesJS builder.
        'allowed' => ['Core', 'Auth', 'Payments', 'Fiscal', 'Messaging', 'Media', 'Ai', 'Menu', 'Customers', 'Team', 'Hr', 'Tables', 'Ordering', 'Reservations', 'Pos', 'Kitchen', 'Delivery', 'Channels', 'Loyalty', 'Inventory', 'Hotel', 'Verticals', 'Pharmacy', 'Laundry', 'Accounting'],
    ],
    'Marketing' => [
        'layer' => 5,
        'edition' => 'community',
        'path' => 'app/Modules/Marketing',
        'namespace' => 'Juwri\\Modules\\Marketing',
        // Coupons campaigns, A/B basic. Advanced journeys -> ee.
        // P1-13 13h: MarketingAutomationController (cart recovery, win-back, birthday offers) = basic single-step
        // automations -> Community; no multi-step journey code exists yet (a future journey builder is ee).
        'allowed' => ['Core', 'Auth', 'Payments', 'Fiscal', 'Messaging', 'Media', 'Ai', 'Menu', 'Customers', 'Team', 'Hr', 'Tables', 'Ordering', 'Reservations', 'Pos', 'Kitchen', 'Delivery', 'Channels', 'Loyalty', 'Inventory', 'Hotel', 'Verticals', 'Pharmacy', 'Laundry', 'Accounting'],
    ],
    'AppStore' => [
        'layer' => 5,
        'edition' => 'split',
        'path' => 'app/Modules/AppStore',
        'namespace' => 'Juwri\\Modules\\AppStore',
        // Installing apps/themes = Community; paid publishing, revenue share, review queue -> ee.
        // P1-13 13h: ee half in the Enterprise package: partner dashboard (app/theme submission,
        // listing editor, sandbox, earnings + statements), app review queue, seller cash-out queue. Community keeps the
        // install flows, catalog editor, theme review queue (Theme Studio publishes through it) and app billing.
        'allowed' => ['Core', 'Auth', 'Payments', 'Fiscal', 'Messaging', 'Media', 'Ai', 'Menu', 'Customers', 'Team', 'Hr', 'Tables', 'Ordering', 'Reservations', 'Pos', 'Kitchen', 'Delivery', 'Channels', 'Loyalty', 'Inventory', 'Hotel', 'Verticals', 'Pharmacy', 'Laundry', 'Accounting'],
    ],
    'Agents' => [
        'layer' => 5,
        'edition' => 'split',
        'path' => 'app/Modules/Agents',
        'namespace' => 'Juwri\\Modules\\Agents',
        // Agent core, tools, MCP, per-store rules = Community; org-wide governance -> ee.
        // P1-13 13h: extension point Agents\Contracts\AgentPolicyStore (Community default: per-store rules); no ee code yet.
        'allowed' => ['Core', 'Auth', 'Payments', 'Fiscal', 'Messaging', 'Media', 'Ai', 'Menu', 'Customers', 'Team', 'Hr', 'Tables', 'Ordering', 'Reservations', 'Pos', 'Kitchen', 'Delivery', 'Channels', 'Loyalty', 'Inventory', 'Hotel', 'Verticals', 'Pharmacy', 'Laundry', 'Accounting'],
    ],
    'Integrations' => [
        'layer' => 5,
        'edition' => 'community',
        'path' => 'app/Modules/Integrations',
        'namespace' => 'Juwri\\Modules\\Integrations',
        // API, webhooks, Zapier/n8n/Make, SDKs.
        'allowed' => ['Core', 'Auth', 'Payments', 'Fiscal', 'Messaging', 'Media', 'Ai', 'Menu', 'Customers', 'Team', 'Hr', 'Tables', 'Ordering', 'Reservations', 'Pos', 'Kitchen', 'Delivery', 'Channels', 'Loyalty', 'Inventory', 'Hotel', 'Verticals', 'Pharmacy', 'Laundry', 'Accounting'],
    ],
    'Analytics' => [
        'layer' => 5,
        'edition' => 'split',
        'path' => 'app/Modules/Analytics',
        'namespace' => 'Juwri\\Modules\\Analytics',
        // Standard reports = Community; cross-branch/benchmark/cohort -> ee.
        // P1-13 13h: ee half in the Enterprise package: peer benchmarks across businesses (model, benchmarks:compute, opt-in
        // route, dashboard/copilot hook slots) and the cross-branch comparison (BranchComparison); the per-branch report stays.
        'allowed' => ['Core', 'Auth', 'Payments', 'Fiscal', 'Messaging', 'Media', 'Ai', 'Menu', 'Customers', 'Team', 'Hr', 'Tables', 'Ordering', 'Reservations', 'Pos', 'Kitchen', 'Delivery', 'Channels', 'Loyalty', 'Inventory', 'Hotel', 'Verticals', 'Pharmacy', 'Laundry', 'Accounting'],
    ],
    'LegacyCompat' => [
        'layer' => 5,
        'edition' => 'community',
        'path' => 'app/Modules/LegacyCompat',
        'namespace' => 'Juwri\\Modules\\LegacyCompat',
        // J52b: old-platform URL compatibility for migrated installs only; OFF by default (legacy_compat.enabled).
        // Ordering: the SalesRestrictions contract only (an old qr-id counts a scan only on a store the page would show).
        // Loyalty: the GoogleWalletPassIds contract only (passes saved on phones keep their source ids).
        // Payments: the StoreGatewayKeys contract (gateway option names and secrets) for the importer.
        // Ordering + Payments Models: read-only guard on imported history (listens to model events, never writes).
        'allowed' => ['Core', 'Ordering', 'Loyalty', 'Payments'],
    ],
];
