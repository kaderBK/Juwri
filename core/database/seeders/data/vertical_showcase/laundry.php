<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/**
 * Fresh Fold Laundry — Freshly (bookla-style service theme) demo catalog.
 *
 * Consumed the same way as core/database/seeders/data/vertical_showcase_legacy.php's
 * 'laundry' entry: no brands, no variants, no stock — VerticalShowcaseSeeder::makeItem()
 * reads name/price/desc/image/popular/pricing_unit/turnaround_hours only.
 *
 * NOTE on pricing_unit: the freshly theme (core/resources/views/store_templates/
 * freshly/index.blade.php ~L73) does a strict `in_array($m->pricing_unit, ['each','kg'])`
 * check — any other stored value silently falls back to 'each' / "per item". So despite
 * the brief's shorthand ('/kg' | '/item' | '/pair' | '/set'), this file sticks to the
 * two literal values the theme and seeder actually understand: 'kg' for by-weight
 * loads (Wash & Fold, Bedding & Linens) and 'each' for per-piece services (Dry
 * Cleaning, Ironing & Pressing, Shoe & Leather Care, Specialty Care) — pair/set
 * semantics are carried in the name/desc text instead of a fake enum value.
 */

return [
    'brands' => [],
    'categories' => [

        [
            'name' => 'Wash & Fold',
            'items' => [
                [
                    'name' => 'Wash & Fold Laundry Service', 'price' => 3.50,
                    'desc' => 'Everyday laundry washed in cold-safe cycles, tumble dried and neatly folded, billed per kilogram.',
                    'pricing_unit' => 'kg', 'turnaround_hours' => 24, 'popular' => true,
                    'image' => 'wash-fold.jpg',
                ],
                [
                    'name' => 'Express Wash & Fold (Same-Day)', 'price' => 5.50,
                    'desc' => 'Same-day wash and fold for time-sensitive loads, ready in as little as 8 hours, billed per kilogram.',
                    'pricing_unit' => 'kg', 'turnaround_hours' => 8,
                    'image' => 'wash-fold.jpg',
                ],
                [
                    'name' => 'Delicate Cold Wash & Fold', 'price' => 4.75,
                    'desc' => 'Gentle cold-cycle wash for mixed delicates and activewear, air-dried and folded, billed per kilogram.',
                    'pricing_unit' => 'kg', 'turnaround_hours' => 24,
                    'image' => 'wash-fold.jpg',
                ],
                [
                    'name' => 'Heavy-Duty Wash (Towels & Workwear)', 'price' => 4.00,
                    'desc' => 'High-temperature wash and sanitize for towels, gym kit and heavy workwear, billed per kilogram.',
                    'pricing_unit' => 'kg', 'turnaround_hours' => 24,
                    'image' => 'towels-workwear.jpg',
                ],
            ],
        ],

        [
            'name' => 'Dry Cleaning',
            'items' => [
                [
                    'name' => 'Dry Clean - Shirt / Blouse', 'price' => 4.50,
                    'desc' => 'Professional dry cleaning and pressing for shirts and blouses, priced per piece.',
                    'pricing_unit' => 'each', 'turnaround_hours' => 48, 'popular' => true,
                    'image' => 'dry-clean.jpg',
                ],
                [
                    'name' => 'Dry Clean - Suit (2pc Set)', 'price' => 14.00,
                    'desc' => 'Jacket and trouser or skirt dry cleaned and pressed together as one set.',
                    'pricing_unit' => 'each', 'turnaround_hours' => 48,
                    'image' => 'dry-clean.jpg',
                ],
                [
                    'name' => 'Dry Clean - Dress', 'price' => 9.50,
                    'desc' => 'Gentle solvent cleaning for everyday and occasion dresses, priced per piece.',
                    'pricing_unit' => 'each', 'turnaround_hours' => 48,
                    'image' => 'dry-clean.jpg',
                ],
                [
                    'name' => 'Dry Clean - Coat / Jacket', 'price' => 16.00,
                    'desc' => 'Deep clean and press for winter coats and heavy jackets, priced per piece.',
                    'pricing_unit' => 'each', 'turnaround_hours' => 72,
                    'image' => 'dry-clean.jpg',
                ],
                [
                    'name' => 'Express Dry Clean (Same-Day)', 'price' => 9.00,
                    'desc' => 'Rush dry cleaning for any single garment, ready the same day, priced per piece.',
                    'pricing_unit' => 'each', 'turnaround_hours' => 8,
                    'image' => 'dry-clean.jpg',
                ],
            ],
        ],

        [
            'name' => 'Ironing & Pressing',
            'items' => [
                [
                    'name' => 'Iron & Press - Shirt', 'price' => 2.50,
                    'desc' => 'Crisp, crease-free steam pressing for a single shirt.',
                    'pricing_unit' => 'each', 'turnaround_hours' => 24,
                    'image' => 'ironing.jpg',
                ],
                [
                    'name' => 'Iron & Press - Trousers', 'price' => 3.00,
                    'desc' => 'Sharp center-crease pressing for trousers, priced per pair.',
                    'pricing_unit' => 'each', 'turnaround_hours' => 24,
                    'image' => 'ironing.jpg',
                ],
                [
                    'name' => 'Iron & Press - Bulk Bundle (10 items)', 'price' => 18.00,
                    'desc' => 'Bundle rate for pressing up to 10 everyday garments as one set.',
                    'pricing_unit' => 'each', 'turnaround_hours' => 24,
                    'image' => 'ironing.jpg',
                ],
                [
                    'name' => 'Express Press (Same-Day)', 'price' => 4.00,
                    'desc' => 'Rush pressing for a single garment, ready the same day.',
                    'pricing_unit' => 'each', 'turnaround_hours' => 8,
                    'image' => 'ironing.jpg',
                ],
            ],
        ],

        [
            'name' => 'Bedding & Linens',
            'items' => [
                [
                    'name' => 'Duvet & Comforter Wash', 'price' => 6.50,
                    'desc' => 'Deep wash and fluff-dry for duvets and comforters of any size, billed per kilogram.',
                    'pricing_unit' => 'kg', 'turnaround_hours' => 48, 'popular' => true,
                    'image' => 'bedding.jpg',
                ],
                [
                    'name' => 'Bed Sheet & Pillowcase Set Wash', 'price' => 5.00,
                    'desc' => 'Full sheet and pillowcase sets washed, pressed and folded, billed per kilogram.',
                    'pricing_unit' => 'kg', 'turnaround_hours' => 24,
                    'image' => 'bedding.jpg',
                ],
                [
                    'name' => 'Blanket & Throw Wash', 'price' => 5.50,
                    'desc' => 'Heavy-fabric wash for wool and fleece blankets or throws, billed per kilogram.',
                    'pricing_unit' => 'kg', 'turnaround_hours' => 48,
                    'image' => 'bedding.jpg',
                ],
                [
                    'name' => 'Curtain & Drapery Cleaning', 'price' => 6.00,
                    'desc' => 'Deep cleaning for curtains and drapery that lifts dust without losing shape, billed per kilogram.',
                    'pricing_unit' => 'kg', 'turnaround_hours' => 72,
                    'image' => 'curtains.jpg',
                ],
            ],
        ],

        [
            'name' => 'Shoe & Leather Care',
            'items' => [
                [
                    'name' => 'Sneaker Deep Clean', 'price' => 12.00,
                    'desc' => 'Sole-to-lace deep clean and deodorize for sneakers, priced per pair.',
                    'pricing_unit' => 'each', 'turnaround_hours' => 48, 'popular' => true,
                    'image' => 'sneakers.jpg',
                ],
                [
                    'name' => 'Leather Shoe Cleaning & Polish', 'price' => 15.00,
                    'desc' => 'Conditioning clean and hand polish that restores shine, priced per pair.',
                    'pricing_unit' => 'each', 'turnaround_hours' => 48,
                    'image' => 'leather-shoes.jpg',
                ],
                [
                    'name' => 'Suede Shoe Cleaning & Protection', 'price' => 18.00,
                    'desc' => 'Specialist nap-safe suede clean with a protective finish, priced per pair.',
                    'pricing_unit' => 'each', 'turnaround_hours' => 72,
                    'image' => 'leather-shoes.jpg',
                ],
                [
                    'name' => 'Leather Bag Cleaning & Conditioning', 'price' => 22.00,
                    'desc' => 'Deep clean and leather-conditioning treatment for handbags and totes, priced per piece.',
                    'pricing_unit' => 'each', 'turnaround_hours' => 96,
                    'image' => 'leather-shoes.jpg',
                ],
            ],
        ],

        [
            'name' => 'Specialty Care',
            'items' => [
                [
                    'name' => 'Delicate / Silk Care', 'price' => 7.50,
                    'desc' => 'Hand-finished care for silk, chiffon and other delicate fabrics, priced per piece.',
                    'pricing_unit' => 'each', 'turnaround_hours' => 48,
                    'image' => 'wash-fold.jpg',
                ],
                [
                    'name' => 'Wedding Gown Preservation', 'price' => 45.00,
                    'desc' => 'Museum-grade cleaning, boxing and preservation for wedding and formal gowns.',
                    'pricing_unit' => 'each', 'turnaround_hours' => 120, 'popular' => true,
                    'image' => 'wedding-gown.jpg',
                ],
                [
                    'name' => 'Leather & Suede Jacket Cleaning', 'price' => 25.00,
                    'desc' => 'Specialist cleaning and conditioning for leather and suede jackets, priced per piece.',
                    'pricing_unit' => 'each', 'turnaround_hours' => 96,
                    'image' => 'leather-jacket.jpg',
                ],
                [
                    'name' => 'Stain Removal Treatment (Add-On)', 'price' => 5.00,
                    'desc' => 'Targeted pre-treatment for stubborn stains, added on to any wash or dry-clean order.',
                    'pricing_unit' => 'each', 'turnaround_hours' => 24,
                ],
            ],
        ],

    ],
];
