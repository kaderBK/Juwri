<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/**
 * Expanded demo catalog — Lumière Beauty Bar (cosmetic vertical: skincare,
 * makeup, fragrance, hair care, tools & accessories).
 *
 * Shape matches VerticalShowcaseSeeder::makeItem() / vertical_showcase_legacy.php
 * 'cosmetic' entry exactly — brand + item names preserved from the legacy
 * catalog, expanded to ~30 products across 5 categories. Real product photos
 * (Unsplash, sourced into ./images/<file>.jpg) are referenced via 'image'
 * where a suitable shot was found; items without a good match omit the key
 * so the seeder falls back to its placeholder.
 */

return [

    'brands' => ['Lumière Beauty', 'Velvet Bloom', 'AuraGlow', 'Silkroot'],

    'categories' => [

        [
            'name' => 'Skincare',
            'items' => [
                [
                    'name' => 'Hydrating Face Serum', 'brand' => 'Lumière Beauty', 'price' => 28.00,
                    'desc' => 'Hyaluronic acid serum for a plumped, dewy finish — lightweight enough to layer under moisturizer every day.',
                    'attrs' => [
                        'benefits' => ['Hydrating', 'Plumping', 'Dewy Finish'],
                        'ingredients' => ['Hyaluronic Acid', 'Glycerin', 'Panthenol'],
                    ],
                    'variants' => ['Size' => ['30ml', '50ml']],
                    'variant_price' => ['30ml' => 28.00, '50ml' => 42.00],
                    'batch_no' => 'LB-2026-11', 'expiry_date' => '2027-06-30',
                    'popular' => true, 'image' => 'hydrating-face-serum.jpg',
                ],
                [
                    'name' => 'Vitamin C Brightening Cream 50ml', 'brand' => 'AuraGlow', 'price' => 24.50,
                    'desc' => 'Brightening daily moisturizer with vitamin C and niacinamide for a more even, radiant tone.',
                    'attrs' => [
                        'benefits' => ['Brightening', 'Evens Tone'],
                        'ingredients' => ['Vitamin C', 'Niacinamide', 'Vitamin E'],
                    ],
                    'stock' => 40, 'image' => 'vitamin-c-cream.jpg',
                ],
                [
                    'name' => 'Gentle Foaming Cleanser 150ml', 'brand' => 'Silkroot', 'price' => 16.00,
                    'desc' => 'Sulfate-free daily cleanser, pH balanced and gentle enough for sensitive skin.',
                    'attrs' => [
                        'benefits' => ['Gentle Cleansing', 'pH Balanced'],
                        'ingredients' => ['Chamomile Extract', 'Glycerin'],
                    ],
                    'stock' => 55, 'image' => 'foaming-cleanser.jpg',
                ],
                [
                    'name' => 'Retinol Night Repair Serum', 'brand' => 'Lumière Beauty', 'price' => 34.00,
                    'desc' => 'Advanced retinol treatment that smooths texture and softens the look of fine lines overnight.',
                    'attrs' => [
                        'benefits' => ['Anti-Aging', 'Smoothing', 'Renewing'],
                        'ingredients' => ['Retinol', 'Squalane', 'Vitamin E'],
                    ],
                    'variants' => ['Size' => ['30ml', '50ml']],
                    'variant_price' => ['30ml' => 34.00, '50ml' => 52.00],
                    'batch_no' => 'LB-2026-18', 'expiry_date' => '2027-01-20',
                    'image' => 'retinol-serum.jpg',
                ],
                [
                    'name' => 'Niacinamide 10% + Zinc Serum 30ml', 'brand' => 'AuraGlow', 'price' => 19.50,
                    'desc' => 'Pore-refining serum that balances oil and calms the look of redness and blemishes.',
                    'attrs' => [
                        'benefits' => ['Pore-Refining', 'Oil Control'],
                        'ingredients' => ['Niacinamide', 'Zinc PCA'],
                    ],
                    'batch_no' => 'AG-2026-22', 'expiry_date' => '2027-08-10',
                    'image' => 'niacinamide-serum.jpg',
                ],
                [
                    'name' => 'Rose Clay Detox Mask 75ml', 'brand' => 'Velvet Bloom', 'price' => 22.00,
                    'desc' => 'Mineral-rich clay mask that draws out impurities for a deep, refreshed clean.',
                    'attrs' => [
                        'benefits' => ['Detoxifying', 'Deep Cleansing'],
                        'ingredients' => ['Rose Clay', 'Kaolin', 'Aloe Vera'],
                    ],
                    'stock' => 35, 'image' => 'rose-clay-mask.jpg',
                ],
                [
                    'name' => 'SPF 50 Daily Sunscreen Fluid 50ml', 'brand' => 'Silkroot', 'price' => 21.00,
                    'desc' => 'Lightweight broad-spectrum fluid that wears invisibly under makeup, with no white cast.',
                    'attrs' => [
                        'benefits' => ['Broad-Spectrum Protection', 'Lightweight', 'No White Cast'],
                        'ingredients' => ['Zinc Oxide', 'Vitamin E'],
                    ],
                    'batch_no' => 'SR-2026-30', 'expiry_date' => '2027-05-01',
                    'popular' => true, 'image' => 'sunscreen-fluid.jpg',
                ],
            ],
        ],

        [
            'name' => 'Makeup',
            'items' => [
                [
                    'name' => 'Matte Lipstick', 'brand' => 'Velvet Bloom', 'price' => 14.00,
                    'desc' => 'Long-wear matte lipstick, transfer-resistant formula in wearable everyday shades.',
                    'variants' => ['Shade' => ['Rosewood', 'Coral Blush', 'Berry Wine']],
                    'popular' => true, 'image' => 'matte-lipstick.jpg',
                ],
                [
                    'name' => 'Silk Foundation', 'brand' => 'Velvet Bloom', 'price' => 22.00,
                    'desc' => 'Buildable medium coverage, natural satin finish, 24h wear.',
                    'variants' => ['Shade' => ['Ivory', 'Beige', 'Honey', 'Almond', 'Espresso']],
                    'image' => 'silk-foundation.jpg',
                ],
                [
                    'name' => 'Highlighter Duo', 'brand' => 'AuraGlow', 'price' => 18.50,
                    'desc' => 'Two-tone pressed highlighter for a lit-from-within glow.',
                    'variants' => ['Shade' => ['Champagne', 'Rose Gold']],
                    'image' => 'highlighter-duo.jpg',
                ],
                [
                    'name' => 'Eyeshadow Palette', 'brand' => 'Velvet Bloom', 'price' => 26.00,
                    'desc' => '12-shade neutral palette with matte and shimmer finishes.',
                    'stock' => 25, 'image' => 'eyeshadow-palette.jpg',
                ],
                [
                    'name' => 'Volumizing Mascara', 'brand' => 'Velvet Bloom', 'price' => 17.00,
                    'desc' => 'Buildable volumizing mascara that lifts and separates without clumping.',
                    'stock' => 60, 'image' => 'mascara.jpg',
                ],
                [
                    'name' => 'Precision Liquid Eyeliner', 'brand' => 'AuraGlow', 'price' => 13.00,
                    'desc' => 'Fine-tip liquid eyeliner for a sharp, smudge-proof line.',
                    'stock' => 45,
                ],
                [
                    'name' => 'Cream Blush Stick', 'brand' => 'Velvet Bloom', 'price' => 16.50,
                    'desc' => 'Buildable cream blush stick that blends with fingertips for a natural flush.',
                    'variants' => ['Shade' => ['Peach', 'Rose', 'Berry']],
                ],
            ],
        ],

        [
            'name' => 'Fragrance',
            'items' => [
                [
                    'name' => 'Eau de Parfum', 'brand' => 'Lumière Beauty', 'price' => 45.00,
                    'desc' => 'Warm floral-amber eau de parfum, 8-10h longevity.',
                    'variants' => ['Size' => ['30ml', '50ml', '90ml']],
                    'variant_price' => ['30ml' => 32.00, '50ml' => 45.00, '90ml' => 68.00],
                    'batch_no' => 'LB-2026-01', 'expiry_date' => '2027-11-15',
                    'popular' => true, 'image' => 'eau-de-parfum.jpg',
                ],
                [
                    'name' => 'Body Mist Citrus Bloom 100ml', 'brand' => 'Silkroot', 'price' => 12.00,
                    'desc' => 'Light citrus-floral body mist for everyday wear.',
                    'stock' => 70, 'image' => 'body-mist.jpg',
                ],
                [
                    'name' => 'Rollerball Perfume Oil 10ml', 'brand' => 'Velvet Bloom', 'price' => 18.00,
                    'desc' => 'Concentrated perfume oil in a travel-friendly rollerball, alcohol-free.',
                    'stock' => 40, 'image' => 'perfume-oil-rollerball.jpg',
                ],
                [
                    'name' => 'Solid Perfume Compact', 'brand' => 'AuraGlow', 'price' => 15.00,
                    'desc' => 'Portable solid perfume balm that melts on contact with skin, purse-friendly tin.',
                    'stock' => 30, 'image' => 'solid-perfume.jpg',
                ],
                [
                    'name' => 'Scented Hand Cream Duo 2x30ml', 'brand' => 'Lumière Beauty', 'price' => 16.00,
                    'desc' => 'Two travel-size hand creams in complementary scents, fast-absorbing formula.',
                    'stock' => 50, 'image' => 'hand-cream-duo.jpg',
                ],
            ],
        ],

        [
            'name' => 'Hair Care',
            'items' => [
                [
                    'name' => 'Argan Repair Shampoo 250ml', 'brand' => 'AuraGlow', 'price' => 13.50,
                    'desc' => 'Sulfate-free shampoo with argan oil for dry, damaged hair.',
                    'stock' => 65, 'image' => 'argan-shampoo.jpg',
                ],
                [
                    'name' => 'Argan Repair Conditioner 250ml', 'brand' => 'AuraGlow', 'price' => 13.50,
                    'desc' => 'Deep-conditioning pair to the Argan Repair Shampoo.',
                    'stock' => 65, 'image' => 'argan-conditioner.jpg',
                ],
                [
                    'name' => 'Heat Protect Spray 150ml', 'brand' => 'Silkroot', 'price' => 15.00,
                    'desc' => 'Lightweight heat protectant spray, effective up to 230°C.',
                    'stock' => 55, 'image' => 'heat-protect-spray.jpg',
                ],
                [
                    'name' => 'Silk Hair Serum 50ml', 'brand' => 'Lumière Beauty', 'price' => 19.00,
                    'desc' => 'Weightless finishing oil that tames frizz and adds glass-like shine.',
                    'popular' => true, 'stock' => 40, 'image' => 'silk-hair-serum.jpg',
                ],
                [
                    'name' => 'Scalp Detox Scrub 200ml', 'brand' => 'Velvet Bloom', 'price' => 17.50,
                    'desc' => 'Exfoliating pre-shampoo scrub that clears buildup for a healthier scalp.',
                    'stock' => 30, 'image' => 'scalp-scrub.jpg',
                ],
                [
                    'name' => 'Overnight Repair Hair Mask 200ml', 'brand' => 'AuraGlow', 'price' => 20.00,
                    'desc' => 'Deep-conditioning treatment mask for damaged, over-processed hair.',
                    'stock' => 28, 'image' => 'hair-mask.jpg',
                ],
            ],
        ],

        [
            'name' => 'Tools & Accessories',
            'items' => [
                [
                    'name' => 'Makeup Sponge Set (3pc)', 'brand' => 'Velvet Bloom', 'price' => 9.99,
                    'desc' => 'Latex-free blending sponges for flawless foundation and concealer application.',
                    'stock' => 80, 'image' => 'makeup-sponge.jpg',
                ],
                [
                    'name' => 'Vegan Kabuki Brush Set (5pc)', 'brand' => 'AuraGlow', 'price' => 24.00,
                    'desc' => 'Cruelty-free synthetic brush set covering every face and eye essential.',
                    'stock' => 40, 'image' => 'kabuki-brush-set.jpg',
                ],
                [
                    'name' => 'LED Facial Cleansing Brush', 'brand' => 'Lumière Beauty', 'price' => 32.00,
                    'desc' => 'Rechargeable silicone cleansing brush with two speed settings for a deeper clean.',
                    'popular' => true, 'stock' => 25, 'image' => 'led-cleansing-brush.jpg',
                ],
                [
                    'name' => 'Rose Quartz Facial Roller', 'brand' => 'Silkroot', 'price' => 14.50,
                    'desc' => 'Chilled facial roller that de-puffs and helps serums absorb.',
                    'stock' => 35, 'image' => 'rose-quartz-roller.jpg',
                ],
                [
                    'name' => 'Travel Cosmetic Bag', 'brand' => 'Velvet Bloom', 'price' => 19.00,
                    'desc' => 'Water-resistant zip pouch sized for daily makeup essentials.',
                    'stock' => 45, 'image' => 'cosmetic-bag.jpg',
                ],
            ],
        ],

    ],

];
