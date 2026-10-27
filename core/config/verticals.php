<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/**
 * rev/multi-vertical F0-1 — the single source of truth for store verticals.
 *
 * A store's `posts.store_type` selects one entry here. The VerticalRegistry
 * (App\Services\Verticals\VerticalRegistry) reads it to decide capabilities:
 * which item attributes/forms render, which fulfillment modes are allowed, which
 * storefront theme set is offered, and whether food-only fields (veg/nonveg,
 * allergens, nutrition, KDS) appear at all.
 *
 * DECISIONS D4/D5: this is a discriminator + capability set, NOT a schema fork.
 * Adding a vertical later = a new entry here (+ its item-form partial / theme),
 * never a new table hierarchy. Labels/vocab are resolved through ___() in views
 * and the VerticalVocab service (F0-2) — do not hardcode nouns in blades.
 *
 * `fulfillment` lists the DB `orders.type` values the vertical allows. Restaurant
 * keeps its EXACT legacy literals ('on-table','takeaway','delivery','room_service')
 * so existing stores stay byte-identical. New modes ('ship','pickup_slot',
 * 'appointment','in_store') arrive with F0-3's enum→string migration; 'at_home' (services
 * that visit the customer) arrives with Phase 3 (P3-00).
 */

return [

    // Backward-compatible default: every legacy store is a restaurant.
    'default' => 'restaurant',

    /*
     * F0-2 — per-group UI vocabulary. The word is an English key resolved through
     * ___() at render time so all 10 locales translate it (DECISIONS A5). A store's
     * `group` (food|goods|services) selects the column; a missing key falls back to
     * the 'food' word, then to the key itself. Restaurant (food) keeps today's
     * exact wording so legacy stores read unchanged.
     *
     * NEVER hardcode these nouns in a new blade — call VerticalVocab::for().
     */
    'vocab' => [
        'food' => [
            'item' => 'Dish',
            'items' => 'Dishes',
            'catalog' => 'Menu',
            'category' => 'Category',
            'categories' => 'Categories',
            'create_item' => 'Add dish',
            'add_to_cart' => 'Add to order',
            'order' => 'Order',
            'orders' => 'Orders',
            'board' => 'Kitchen',
        ],
        'goods' => [
            'item' => 'Product',
            'items' => 'Products',
            'catalog' => 'Catalog',
            'category' => 'Category',
            'categories' => 'Categories',
            'create_item' => 'Add product',
            'add_to_cart' => 'Add to cart',
            'order' => 'Order',
            'orders' => 'Orders',
            'board' => 'Fulfilment',
        ],
        'services' => [
            'item' => 'Service',
            'items' => 'Services',
            'catalog' => 'Services',
            'category' => 'Category',
            'categories' => 'Categories',
            'create_item' => 'Add service',
            'add_to_cart' => 'Book',
            'order' => 'Booking',
            'orders' => 'Bookings',
            'board' => 'Service board',
        ],
        'stays' => [
            'item' => 'Room',
            'items' => 'Rooms',
            'catalog' => 'Rooms',
            'category' => 'Category',
            'categories' => 'Categories',
            'create_item' => 'Add room',
            'add_to_cart' => 'Book',
            'order' => 'Reservation',
            'orders' => 'Reservations',
            'board' => 'Front desk',
        ],
    ],

    /*
     * The store-ENTITY noun ("Restaurant" / "Store" / …) per group, in the
     * grammatical forms the dashboard chrome needs. `store_noun($store, $slot)`
     * (Helper.php) reads the right form for the current locale + slot so a
     * single ":noun" placeholder renders correctly in en and ar templates.
     *
     * Vertical-Neutral Copy Audit: a pharmacy/salon owner must never read
     * "Restaurant" in per-store dashboard chrome, while a restaurant owner keeps
     * "Restaurant". English distinguishes case/number; Arabic distinguishes
     * grammatical role (definite/possessive/indefinite/plural). `_generic` is the
     * fallback when no store is in context (null store_type).
     *
     * Arabic keeps مطعم only for food and فندق for hotels; every other vertical
     * uses متجر (the house-neutral term already in the newer admin copy), even
     * where English says "Business" — the two are both neutral and read well.
     */
    'noun' => [
        // en:  title / lower / plural      ar:  def / possessive(your) / indefinite / def-plural
        'food' => ['title' => 'Restaurant', 'lower' => 'restaurant', 'plural' => 'restaurants',
            'ar_def' => 'المطعم', 'ar_poss' => 'مطعمك', 'ar_indef' => 'مطعم', 'ar_plural' => 'المطاعم'],
        'goods' => ['title' => 'Store', 'lower' => 'store', 'plural' => 'stores',
            'ar_def' => 'المتجر', 'ar_poss' => 'متجرك', 'ar_indef' => 'متجر', 'ar_plural' => 'المتاجر'],
        'services' => ['title' => 'Business', 'lower' => 'business', 'plural' => 'businesses',
            'ar_def' => 'المتجر', 'ar_poss' => 'متجرك', 'ar_indef' => 'متجر', 'ar_plural' => 'المتاجر'],
        'stays' => ['title' => 'Hotel', 'lower' => 'hotel', 'plural' => 'hotels',
            'ar_def' => 'الفندق', 'ar_poss' => 'فندقك', 'ar_indef' => 'فندق', 'ar_plural' => 'الفنادق'],
        '_generic' => ['title' => 'Business', 'lower' => 'business', 'plural' => 'businesses',
            'ar_def' => 'المتجر', 'ar_poss' => 'متجرك', 'ar_indef' => 'متجر', 'ar_plural' => 'المتاجر'],
    ],

    /*
     * F0-3 — fulfillment mode catalog. The single source of truth for valid
     * `orders.type` values now that the column is a VARCHAR (no more enum ALTERs).
     * `needs_address` / `needs_slot` drive the storefront checkout. Legacy food
     * modes keep their EXACT literals so POS/KDS/marketplace/hotel are unaffected.
     */
    'fulfillment' => [
        'on-table' => ['label' => 'Dine-in', 'needs_address' => false, 'needs_slot' => false],
        'takeaway' => ['label' => 'Takeaway', 'needs_address' => false, 'needs_slot' => false],
        'delivery' => ['label' => 'Delivery', 'needs_address' => true, 'needs_slot' => false],
        'room_service' => ['label' => 'Room service', 'needs_address' => false, 'needs_slot' => false],
        // Non-food modes (multi-vertical):
        'ship' => ['label' => 'Ship to address', 'needs_address' => true, 'needs_slot' => false],
        'pickup_slot' => ['label' => 'Pickup', 'needs_address' => false, 'needs_slot' => true],
        'appointment' => ['label' => 'Appointment', 'needs_address' => false, 'needs_slot' => true],
        'in_store' => ['label' => 'In-store', 'needs_address' => false, 'needs_slot' => false],
        // P3-00: the provider comes to the customer (services): an address AND a time slot.
        'at_home' => ['label' => 'At home', 'needs_address' => true, 'needs_slot' => true],
    ],

    'types' => [

        'restaurant' => [
            'label' => 'Restaurant',           // translated in views via ___()
            'group' => 'food',                 // food | goods | services
            'food_attrs' => true,              // veg/nonveg, allergens, nutrition, spice, KDS
            'variant_goods' => false,          // size×color matrix w/ per-variant stock
            'fitment' => false,                // vehicle YMM compatibility (car parts)
            'age_gate' => false,               // storefront age-restriction interstitial
            'expiry' => false,                 // batch/expiry tracking
            'service' => false,                // per-garment/per-kg service pricing + booking
            'item_form' => 'food',             // dashboard item-form partial key
            'themes' => 'food',                // storefront theme set key
            'fulfillment' => ['on-table', 'takeaway', 'delivery', 'room_service'],
        ],

        'retail' => [
            'label' => 'Retail store',
            'group' => 'goods',
            'food_attrs' => false,
            'variant_goods' => true,
            'fitment' => false,
            'age_gate' => false,
            'expiry' => false,
            'service' => false,
            'item_form' => 'retail',
            'themes' => 'retail',
            'fulfillment' => ['ship', 'pickup_slot', 'in_store'],
        ],

        'cosmetic' => [
            'label' => 'Cosmetics',
            'group' => 'goods',
            'food_attrs' => false,
            'variant_goods' => true,
            'fitment' => false,
            'age_gate' => false,
            'expiry' => true,
            'service' => false,
            'item_form' => 'retail',
            'themes' => 'retail',
            'fulfillment' => ['ship', 'pickup_slot', 'in_store'],
        ],

        'clothing' => [
            'label' => 'Clothing store',
            'group' => 'goods',
            'food_attrs' => false,
            'variant_goods' => true,
            'fitment' => false,
            'age_gate' => false,
            'expiry' => false,
            'service' => false,
            'item_form' => 'retail',
            'themes' => 'retail',
            'fulfillment' => ['ship', 'pickup_slot', 'in_store'],
        ],

        'carparts' => [
            'label' => 'Car parts',
            'group' => 'goods',
            'food_attrs' => false,
            'variant_goods' => true,
            'fitment' => true,
            'age_gate' => false,
            'expiry' => false,
            'service' => false,
            'item_form' => 'retail',
            'themes' => 'retail',
            'fulfillment' => ['ship', 'pickup_slot', 'in_store'],
        ],

        'laundry' => [
            'label' => 'Laundry & services',
            'group' => 'services',
            'food_attrs' => false,
            'variant_goods' => false,
            'fitment' => false,
            'age_gate' => false,
            'expiry' => false,
            'service' => true,
            'item_form' => 'service',
            'themes' => 'service',
            'fulfillment' => ['pickup_slot', 'delivery', 'appointment'],
        ],

        'pharmacy' => [
            'label' => 'Pharmacy (OTC)',       // D3: OTC ONLY — no Rx, ever
            'group' => 'goods',
            'food_attrs' => false,
            'variant_goods' => true,
            'fitment' => false,
            'age_gate' => true,
            'expiry' => true,
            'service' => false,
            'item_form' => 'retail',
            'themes' => 'retail',
            'fulfillment' => ['ship', 'pickup_slot', 'in_store'],
        ],

        // P3-00 — the three Phase 3 store types. Each reuses an existing capability set (no schema fork, D4/D5):
        // `cafe` is a food store (food vocab, KDS, food item form), `grocery` is a goods store (goods vocab,
        // retail catalogue + goods checkout), `services` is a bookable-services store on the service
        // item form + booking flow that laundry already uses (the staff x time booking engine is P3-10).
        // The default storefront theme stays an inherited one until the kit theme has its views
        // (brew P3-07, basket P3-09, book P3-11): see KitThemes and VerticalThemeSets.

        'cafe' => [
            'label' => 'Café',
            'group' => 'food',
            'food_attrs' => true,
            'variant_goods' => false,
            'fitment' => false,
            'age_gate' => false,
            'expiry' => false,
            'service' => false,
            'item_form' => 'food',
            'themes' => 'food',
            'fulfillment' => ['on-table', 'takeaway', 'delivery'],
        ],

        'grocery' => [
            'label' => 'Grocery store',
            'group' => 'goods',
            'food_attrs' => false,
            'variant_goods' => false,
            'fitment' => false,
            'age_gate' => false,
            'expiry' => false,
            'service' => false,
            'item_form' => 'retail',
            'themes' => 'retail',
            'fulfillment' => ['delivery', 'pickup_slot'],
        ],

        'services' => [
            'label' => 'Appointments and services',
            'group' => 'services',
            'food_attrs' => false,
            'variant_goods' => false,
            'fitment' => false,
            'age_gate' => false,
            'expiry' => false,
            'service' => true,
            'item_form' => 'service',
            'themes' => 'service',
            'fulfillment' => ['appointment', 'at_home'],
        ],

        'hotel' => [
            'label' => 'Hotel',
            'group' => 'stays',                // Room/Rooms/Book/Reservation vocab
            'food_attrs' => false,
            'variant_goods' => false,
            'fitment' => false,
            'age_gate' => false,
            'expiry' => false,
            'service' => false,
            'item_form' => 'food',             // in-room dining reuses the food item form
            'themes' => 'hotel',               // dedicated hotel theme set (VerticalThemeSets)
            // Room-nights are booked through the existing hotel_bookings engine
            // (check_in/check_out dates), NOT orders.type — so no new fulfillment
            // mode is needed. These modes cover in-room dining + amenity delivery.
            'fulfillment' => ['room_service', 'on-table', 'delivery', 'pickup_slot'],
        ],

    ],
];
