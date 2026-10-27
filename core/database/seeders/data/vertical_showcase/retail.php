<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/**
 * Expanded demo catalog for the retail vertical (Zevoa / Northgate General
 * Store — grocery quick-commerce + general goods).
 *
 * Starting point was core/database/seeders/data/vertical_showcase_legacy.php's
 * 'retail' entry (Norden Home / TrailPeak Outdoors / ByteForge Electronics /
 * InkWell Office, 4 categories, 13 items) — those brands and item names are
 * kept verbatim below and expanded with 3 new grocery categories (Fresh
 * Produce & Dairy, Pantry & Snacks, Beverages) plus a few new items per
 * existing category, for ~30 products across 7 categories.
 *
 * Only keys read by VerticalShowcaseSeeder::makeItem() are used: name, price,
 * brand, desc, image, popular, stock, weight, dimensions, variants,
 * variant_price, attrs. `image` is a bare filename expected to live under
 * database/seeders/data/showcase_images/retail/ once copied in from
 * /tmp/enrich/retail/images/ — omitted where no real photo was sourced (the
 * seeder draws a GD placeholder in that case).
 */

return [

    'brands' => [
        'Northgate Farms', 'Golden Harvest', 'Pure Springs',
        'Norden Home', 'TrailPeak Outdoors', 'ByteForge Electronics', 'InkWell Office',
    ],

    'categories' => [

        [
            'name' => 'Fresh Produce & Dairy',
            'items' => [
                [
                    'name' => 'Bananas (1kg)', 'brand' => 'Northgate Farms', 'price' => 1.29,
                    'desc' => 'Naturally ripened bananas, sweet and ready to eat.',
                    'attrs' => ['unit' => '/kg'], 'weight' => 1.0, 'stock' => 150,
                    'popular' => true, 'image' => 'bananas.jpg',
                ],
                [
                    'name' => 'Hass Avocados (4-pack)', 'brand' => 'Northgate Farms', 'price' => 3.99,
                    'desc' => 'Creamy Hass avocados, perfect for guacamole or toast.',
                    'attrs' => ['unit' => '/pack'], 'weight' => 0.6, 'stock' => 80,
                    'image' => 'avocados.jpg',
                ],
                [
                    'name' => 'Organic Baby Spinach 150g', 'brand' => 'Northgate Farms', 'price' => 2.49,
                    'desc' => 'Pre-washed organic baby spinach leaves, ready for salads or cooking.',
                    'attrs' => ['unit' => '/pack'], 'weight' => 0.15, 'stock' => 60,
                    // no real photo sourced — falls back to placeholder
                ],
                [
                    'name' => 'Vine Tomatoes (1kg)', 'brand' => 'Northgate Farms', 'price' => 2.29,
                    'desc' => 'Juicy tomatoes ripened on the vine for full flavor.',
                    'attrs' => ['unit' => '/kg'], 'weight' => 1.0, 'stock' => 90,
                    'image' => 'vine-tomatoes.jpg',
                ],
                [
                    'name' => 'Whole Milk', 'brand' => 'Northgate Farms', 'price' => 1.89,
                    'desc' => 'Fresh pasteurized whole milk, rich and creamy.',
                    'attrs' => ['unit' => '/1L'], 'weight' => 1.03,
                    'variants' => ['Size' => ['1L', '2L']],
                    'variant_price' => ['1L' => 1.89, '2L' => 3.29],
                    'popular' => true, 'image' => 'whole-milk.jpg',
                ],
                [
                    'name' => 'Free-Range Eggs (12ct)', 'brand' => 'Northgate Farms', 'price' => 3.49,
                    'desc' => 'Free-range dozen eggs from pasture-raised hens.',
                    'attrs' => ['unit' => '/dozen'], 'weight' => 0.7, 'stock' => 70,
                    'image' => 'free-range-eggs.jpg',
                ],
            ],
        ],

        [
            'name' => 'Pantry & Snacks',
            'items' => [
                [
                    'name' => 'Extra Virgin Olive Oil 500ml', 'brand' => 'Golden Harvest', 'price' => 6.99,
                    'desc' => 'Cold-pressed extra virgin olive oil, rich and fruity.',
                    'weight' => 0.55, 'stock' => 50, 'image' => 'olive-oil.jpg',
                ],
                [
                    'name' => 'Penne Pasta 500g', 'brand' => 'Golden Harvest', 'price' => 1.79,
                    'desc' => 'Durum wheat semolina penne, cooks al dente in 11 minutes.',
                    'weight' => 0.5, 'stock' => 100, 'image' => 'penne-pasta.jpg',
                ],
                [
                    'name' => 'Basmati Rice', 'brand' => 'Golden Harvest', 'price' => 5.49,
                    'desc' => 'Aromatic long-grain basmati rice, aged for extra fluffiness.',
                    'weight' => 1.0,
                    'variants' => ['Size' => ['1kg', '2kg', '5kg']],
                    'variant_price' => ['1kg' => 5.49, '2kg' => 9.99, '5kg' => 21.99],
                    'image' => 'basmati-rice.jpg',
                ],
                [
                    'name' => 'Creamy Peanut Butter 340g', 'brand' => 'Golden Harvest', 'price' => 3.29,
                    'desc' => 'Smooth and creamy peanut butter, no added sugar.',
                    'weight' => 0.34, 'stock' => 75, 'image' => 'peanut-butter.jpg',
                ],
                [
                    'name' => 'Sea Salt Kettle Chips 150g', 'brand' => 'Golden Harvest', 'price' => 2.19,
                    'desc' => 'Thick-cut kettle chips, cooked in small batches with sea salt.',
                    'weight' => 0.15, 'stock' => 130,
                    'popular' => true, 'image' => 'kettle-chips.jpg',
                ],
            ],
        ],

        [
            'name' => 'Beverages',
            'items' => [
                [
                    'name' => 'Sparkling Water 1L', 'brand' => 'Pure Springs', 'price' => 1.19,
                    'desc' => 'Naturally carbonated spring water, crisp and refreshing.',
                    'attrs' => ['unit' => '/1L'], 'weight' => 1.0, 'stock' => 100,
                    'image' => 'sparkling-water.jpg',
                ],
                [
                    'name' => 'Cold Brew Coffee 350ml', 'brand' => 'Pure Springs', 'price' => 3.79,
                    'desc' => 'Slow-steeped cold brew coffee, smooth and low-acid.',
                    'weight' => 0.35, 'stock' => 55, 'image' => 'cold-brew-coffee.jpg',
                ],
                [
                    'name' => 'Orange Juice No Pulp 1L', 'brand' => 'Pure Springs', 'price' => 2.99,
                    'desc' => '100% pure-squeezed orange juice, no pulp.',
                    'attrs' => ['unit' => '/1L'], 'weight' => 1.05, 'stock' => 65,
                    'image' => 'orange-juice.jpg',
                ],
                [
                    'name' => 'Lemon Green Tea Bags (20ct)', 'brand' => 'Pure Springs', 'price' => 3.49,
                    'desc' => 'Natural green tea with lemon, 20 individually wrapped bags.',
                    'weight' => 0.1, 'stock' => 90, 'image' => 'tea-bags.jpg',
                ],
            ],
        ],

        [
            'name' => 'Home & Kitchen',
            'items' => [
                [
                    'name' => 'Ceramic Storage Canister Set', 'brand' => 'Norden Home', 'price' => 24.99,
                    'desc' => 'Set of 3 airtight ceramic canisters for pantry staples, stackable lids.',
                    'weight' => 1.6, 'dimensions' => '24 x 24 x 30 cm', 'attrs' => ['material' => 'Ceramic'],
                    'variants' => ['Size' => ['Small', 'Medium', 'Large']],
                    'popular' => true, 'image' => 'ceramic-canister-set.jpg',
                ],
                [
                    'name' => 'Bamboo Cutting Board', 'brand' => 'Norden Home', 'price' => 18.50,
                    'desc' => 'Reversible bamboo board with juice groove, naturally antibacterial.',
                    'weight' => 0.8, 'dimensions' => '38 x 28 x 2 cm', 'attrs' => ['material' => 'Bamboo'],
                    'variants' => ['Size' => ['Small', 'Large']],
                    'image' => 'bamboo-cutting-board.jpg',
                ],
                [
                    'name' => 'Nordic Throw Blanket', 'brand' => 'Norden Home', 'price' => 32.00,
                    'desc' => 'Woven cotton-blend throw, 130x170cm, machine washable.',
                    'weight' => 1.1, 'variants' => ['Color' => ['Charcoal', 'Cream', 'Sage']],
                    'image' => 'throw-blanket.jpg',
                ],
                [
                    'name' => 'Stoneware Mug Set of 4', 'brand' => 'Norden Home', 'price' => 21.00,
                    'desc' => 'Matte-glaze stoneware mugs, 350ml, dishwasher and microwave safe.',
                    'weight' => 1.4, 'variants' => ['Color' => ['Ivory', 'Slate']],
                    'image' => 'stoneware-mugs.jpg',
                ],
                [
                    'name' => 'Non-Stick Frying Pan 28cm', 'brand' => 'Norden Home', 'price' => 27.99,
                    'desc' => 'Aluminum frying pan with durable non-stick coating, oven-safe to 180°C.',
                    'weight' => 1.2, 'stock' => 40, 'image' => 'frying-pan.jpg',
                ],
            ],
        ],

        [
            'name' => 'Electronics & Gadgets',
            'items' => [
                [
                    'name' => 'Wireless Earbuds', 'brand' => 'ByteForge Electronics', 'price' => 49.99,
                    'desc' => 'Bluetooth 5.3 earbuds, 28h total battery with case, IPX4.',
                    'weight' => 0.05, 'variants' => ['Color' => ['Black', 'White']],
                    'popular' => true, 'image' => 'wireless-earbuds.jpg',
                ],
                [
                    'name' => 'Fast-Charge Power Bank 10000mAh', 'brand' => 'ByteForge Electronics', 'price' => 29.99,
                    'desc' => '20W USB-C fast charging, fits in a pocket, dual-port output.',
                    'weight' => 0.22, 'stock' => 80, 'image' => 'power-bank.jpg',
                ],
                [
                    'name' => 'Bluetooth Speaker Mini', 'brand' => 'ByteForge Electronics', 'price' => 34.99,
                    'desc' => 'Palm-sized speaker, 12h playtime, splash resistant.',
                    'weight' => 0.3, 'variants' => ['Color' => ['Black', 'Red', 'Blue']],
                    'image' => 'bluetooth-speaker.jpg',
                ],
                [
                    'name' => 'USB-C Charging Cable 2m', 'brand' => 'ByteForge Electronics', 'price' => 9.99,
                    'desc' => 'Braided USB-C charging cable, 2 meters, fast-charge compatible.',
                    'weight' => 0.08, 'stock' => 150,
                    // no real photo sourced — falls back to placeholder
                ],
            ],
        ],

        [
            'name' => 'Outdoor & Garden',
            'items' => [
                [
                    'name' => 'Insulated Water Bottle 1L', 'brand' => 'TrailPeak Outdoors', 'price' => 19.99,
                    'desc' => 'Double-wall stainless steel, keeps drinks cold 24h / hot 12h.',
                    'weight' => 0.4, 'variants' => ['Color' => ['Black', 'Blue', 'Orange']],
                    'popular' => true, 'image' => 'insulated-water-bottle.jpg',
                ],
                [
                    'name' => 'Folding Camp Chair', 'brand' => 'TrailPeak Outdoors', 'price' => 39.99,
                    'desc' => 'Lightweight aluminum frame, carry bag included, 120kg rated.',
                    'weight' => 3.2, 'variants' => ['Color' => ['Green', 'Grey']],
                    'image' => 'camp-chair.jpg',
                ],
                [
                    'name' => 'Solar Garden Lantern', 'brand' => 'TrailPeak Outdoors', 'price' => 14.50,
                    'desc' => 'Weatherproof solar-charging LED lantern, warm white glow.',
                    'weight' => 0.3, 'stock' => 60, 'image' => 'solar-lantern.jpg',
                ],
                [
                    'name' => 'Garden Hose Nozzle Sprayer', 'brand' => 'TrailPeak Outdoors', 'price' => 12.99,
                    'desc' => 'Adjustable 8-pattern hose nozzle sprayer for garden and car washing.',
                    'weight' => 0.3, 'stock' => 45, 'image' => 'garden-hose-nozzle.jpg',
                ],
            ],
        ],

        [
            'name' => 'Office & Stationery',
            'items' => [
                [
                    'name' => 'Leather Notebook A5', 'brand' => 'InkWell Office', 'price' => 12.99,
                    'desc' => 'Refillable faux-leather cover, 192 dotted pages.',
                    'weight' => 0.35, 'variants' => ['Color' => ['Black', 'Brown', 'Navy']],
                    'image' => 'leather-notebook.jpg',
                ],
                [
                    'name' => 'Gel Pen Set (12pc)', 'brand' => 'InkWell Office', 'price' => 9.50,
                    'desc' => 'Smooth-writing gel pens in assorted colors.',
                    'weight' => 0.15, 'stock' => 120, 'image' => 'gel-pens.jpg',
                ],
                [
                    'name' => 'Desk Organizer', 'brand' => 'InkWell Office', 'price' => 22.00,
                    'desc' => 'Multi-compartment mesh-steel organizer for pens, phone and cards.',
                    'weight' => 0.9, 'variants' => ['Color' => ['Grey', 'White']],
                    // no real photo sourced — falls back to placeholder
                ],
            ],
        ],

    ],

];
