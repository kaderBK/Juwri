<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/**
 * rev/multi-vertical showcase pack — catalog spec for VerticalShowcaseSeeder.
 *
 * One entry per non-restaurant vertical (retail/cosmetic/clothing/carparts/
 * laundry/pharmacy). Plain data, `include`d from the seeder (not a class), so
 * it may freely call now()/config() the same way any other seeded record does.
 *
 * Item shape (all keys optional except name/price):
 *   name, desc, price, brand (goods only, must match a name in this
 *   vertical's 'brands' list), weight (kg), dimensions (display string),
 *   attrs (extra free-form Menu.attributes), variants (['Axis' => [values]]
 *   — cartesian product becomes MenuVariant rows), variant_price (axis value
 *   => price override), stock (base Menu.stock_qty when the item has no
 *   variants), popular (bool — feeds the review seeding), age_restricted,
 *   batch_no, expiry_date (pharmacy/cosmetic compliance columns), oem/xref
 *   (carparts OEM + cross-reference numbers -> Menu.attributes), fits
 *   (carparts only: list of [make, model, submodel, engine, yearFrom,
 *   yearTo] — expanded into one VehicleFitment row per year),
 *   pricing_unit/turnaround_hours (laundry/service items).
 */

return [

    'retail' => [
        'brands' => ['Norden Home', 'TrailPeak Outdoors', 'ByteForge Electronics', 'InkWell Office'],
        'categories' => [
            [
                'name' => 'Home & Kitchen',
                'items' => [
                    [
                        'name' => 'Ceramic Storage Canister Set', 'brand' => 'Norden Home', 'price' => 24.99,
                        'desc' => 'Set of 3 airtight ceramic canisters for pantry staples, stackable lids.',
                        'weight' => 1.6, 'dimensions' => '24 x 24 x 30 cm', 'attrs' => ['material' => 'Ceramic'],
                        'variants' => ['Size' => ['Small', 'Medium', 'Large']], 'popular' => true,
                    ],
                    [
                        'name' => 'Bamboo Cutting Board', 'brand' => 'Norden Home', 'price' => 18.50,
                        'desc' => 'Reversible bamboo board with juice groove, naturally antibacterial.',
                        'weight' => 0.8, 'dimensions' => '38 x 28 x 2 cm', 'attrs' => ['material' => 'Bamboo'],
                        'variants' => ['Size' => ['Small', 'Large']],
                    ],
                    [
                        'name' => 'Nordic Throw Blanket', 'brand' => 'Norden Home', 'price' => 32.00,
                        'desc' => 'Woven cotton-blend throw, 130x170cm, machine washable.',
                        'weight' => 1.1, 'variants' => ['Color' => ['Charcoal', 'Cream', 'Sage']],
                    ],
                    [
                        'name' => 'Stoneware Mug Set of 4', 'brand' => 'Norden Home', 'price' => 21.00,
                        'desc' => 'Matte-glaze stoneware mugs, 350ml, dishwasher and microwave safe.',
                        'weight' => 1.4, 'variants' => ['Color' => ['Ivory', 'Slate']],
                    ],
                ],
            ],
            [
                'name' => 'Outdoor & Garden',
                'items' => [
                    [
                        'name' => 'Insulated Water Bottle 1L', 'brand' => 'TrailPeak Outdoors', 'price' => 19.99,
                        'desc' => 'Double-wall stainless steel, keeps drinks cold 24h / hot 12h.',
                        'weight' => 0.4, 'variants' => ['Color' => ['Black', 'Blue', 'Orange']], 'popular' => true,
                    ],
                    [
                        'name' => 'Folding Camp Chair', 'brand' => 'TrailPeak Outdoors', 'price' => 39.99,
                        'desc' => 'Lightweight aluminum frame, carry bag included, 120kg rated.',
                        'weight' => 3.2, 'variants' => ['Color' => ['Green', 'Grey']],
                    ],
                    [
                        'name' => 'Solar Garden Lantern', 'brand' => 'TrailPeak Outdoors', 'price' => 14.50,
                        'desc' => 'Weatherproof solar-charging LED lantern, warm white glow.',
                        'weight' => 0.3, 'stock' => 60,
                    ],
                ],
            ],
            [
                'name' => 'Electronics & Gadgets',
                'items' => [
                    [
                        'name' => 'Wireless Earbuds', 'brand' => 'ByteForge Electronics', 'price' => 49.99,
                        'desc' => 'Bluetooth 5.3 earbuds, 28h total battery with case, IPX4.',
                        'weight' => 0.05, 'variants' => ['Color' => ['Black', 'White']], 'popular' => true,
                    ],
                    [
                        'name' => 'Fast-Charge Power Bank 10000mAh', 'brand' => 'ByteForge Electronics', 'price' => 29.99,
                        'desc' => '20W USB-C fast charging, fits in a pocket, dual-port output.',
                        'weight' => 0.22, 'stock' => 80,
                    ],
                    [
                        'name' => 'Bluetooth Speaker Mini', 'brand' => 'ByteForge Electronics', 'price' => 34.99,
                        'desc' => 'Palm-sized speaker, 12h playtime, splash resistant.',
                        'weight' => 0.3, 'variants' => ['Color' => ['Black', 'Red', 'Blue']],
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
                    ],
                    [
                        'name' => 'Gel Pen Set (12pc)', 'brand' => 'InkWell Office', 'price' => 9.50,
                        'desc' => 'Smooth-writing gel pens in assorted colors.',
                        'weight' => 0.15, 'stock' => 120,
                    ],
                    [
                        'name' => 'Desk Organizer', 'brand' => 'InkWell Office', 'price' => 22.00,
                        'desc' => 'Multi-compartment mesh-steel organizer for pens, phone and cards.',
                        'weight' => 0.9, 'variants' => ['Color' => ['Grey', 'White']],
                    ],
                ],
            ],
        ],
    ],

    'cosmetic' => [
        'brands' => ['Lumière Beauty', 'Velvet Bloom', 'AuraGlow', 'Silkroot'],
        'categories' => [
            [
                'name' => 'Skincare',
                'items' => [
                    [
                        'name' => 'Hydrating Face Serum 30ml', 'brand' => 'Lumière Beauty', 'price' => 28.00,
                        'desc' => 'Hyaluronic acid serum for a plumped, dewy finish.',
                        'attrs' => ['skin_type' => 'All skin types'],
                        'batch_no' => 'LB-2026-11', 'expiry_date' => now()->addMonths(18)->toDateString(), 'popular' => true,
                    ],
                    [
                        'name' => 'Vitamin C Brightening Cream 50ml', 'brand' => 'AuraGlow', 'price' => 24.50,
                        'desc' => 'Brightening daily moisturizer with vitamin C and niacinamide.',
                        'attrs' => ['skin_type' => 'Dull / uneven tone'],
                        'batch_no' => 'AG-2026-04', 'expiry_date' => now()->addMonths(20)->toDateString(),
                    ],
                    [
                        'name' => 'Gentle Foaming Cleanser 150ml', 'brand' => 'Silkroot', 'price' => 16.00,
                        'desc' => 'Sulfate-free daily cleanser, pH balanced.',
                        'attrs' => ['skin_type' => 'Sensitive skin'],
                        'batch_no' => 'SR-2026-07', 'expiry_date' => now()->addMonths(15)->toDateString(),
                    ],
                ],
            ],
            [
                'name' => 'Makeup',
                'items' => [
                    [
                        'name' => 'Matte Lipstick', 'brand' => 'Velvet Bloom', 'price' => 14.00,
                        'desc' => 'Long-wear matte lipstick, transfer-resistant formula.',
                        'variants' => ['Shade' => ['Rosewood', 'Coral Blush', 'Berry Wine']],
                        'batch_no' => 'VB-2026-02', 'expiry_date' => now()->addMonths(24)->toDateString(), 'popular' => true,
                    ],
                    [
                        'name' => 'Silk Foundation', 'brand' => 'Velvet Bloom', 'price' => 22.00,
                        'desc' => 'Buildable medium coverage, natural satin finish, 24h wear.',
                        'variants' => ['Shade' => ['Ivory', 'Beige', 'Honey', 'Almond']],
                        'batch_no' => 'VB-2026-05', 'expiry_date' => now()->addMonths(20)->toDateString(),
                    ],
                    [
                        'name' => 'Highlighter Duo', 'brand' => 'AuraGlow', 'price' => 18.50,
                        'desc' => 'Two-tone pressed highlighter for a lit-from-within glow.',
                        'variants' => ['Shade' => ['Champagne', 'Rose Gold']],
                        'batch_no' => 'AG-2026-09', 'expiry_date' => now()->addMonths(22)->toDateString(),
                    ],
                    [
                        'name' => 'Eyeshadow Palette', 'brand' => 'Velvet Bloom', 'price' => 26.00,
                        'desc' => '12-shade neutral palette, matte and shimmer finishes.',
                        'batch_no' => 'VB-2026-08', 'expiry_date' => now()->addMonths(24)->toDateString(),
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
                        'batch_no' => 'LB-2026-01', 'expiry_date' => now()->addMonths(30)->toDateString(), 'popular' => true,
                    ],
                    [
                        'name' => 'Body Mist Citrus Bloom 100ml', 'brand' => 'Silkroot', 'price' => 12.00,
                        'desc' => 'Light citrus-floral body mist, everyday wear.',
                        'batch_no' => 'SR-2026-03', 'expiry_date' => now()->addMonths(18)->toDateString(),
                    ],
                ],
            ],
            [
                'name' => 'Hair Care',
                'items' => [
                    [
                        'name' => 'Argan Repair Shampoo 250ml', 'brand' => 'AuraGlow', 'price' => 13.50,
                        'desc' => 'Sulfate-free shampoo with argan oil for dry, damaged hair.',
                        'batch_no' => 'AG-2026-12', 'expiry_date' => now()->addMonths(20)->toDateString(),
                    ],
                    [
                        'name' => 'Argan Repair Conditioner 250ml', 'brand' => 'AuraGlow', 'price' => 13.50,
                        'desc' => 'Deep-conditioning pair to the Argan Repair Shampoo.',
                        'batch_no' => 'AG-2026-13', 'expiry_date' => now()->addMonths(20)->toDateString(),
                    ],
                    [
                        'name' => 'Heat Protect Spray 150ml', 'brand' => 'Silkroot', 'price' => 15.00,
                        'desc' => 'Lightweight heat protectant spray, up to 230°C.',
                        'batch_no' => 'SR-2026-06', 'expiry_date' => now()->addMonths(16)->toDateString(),
                    ],
                ],
            ],
        ],
    ],

    'clothing' => [
        'brands' => ['Thread & Co.', 'Urban Loom', 'Nordic Knit', 'Pace Athletic'],
        'categories' => [
            [
                'name' => "Men's",
                'items' => [
                    [
                        'name' => 'Classic Oxford Shirt', 'brand' => 'Thread & Co.', 'price' => 39.99,
                        'desc' => 'Tailored-fit cotton oxford shirt, button-down collar.',
                        'attrs' => ['fabric' => '100% Cotton'],
                        'variants' => ['Size' => ['S', 'M', 'L', 'XL'], 'Color' => ['White', 'Blue', 'Black']],
                        'popular' => true,
                    ],
                    [
                        'name' => 'Slim Fit Chinos', 'brand' => 'Urban Loom', 'price' => 44.99,
                        'desc' => 'Stretch-cotton chinos, slim fit through the leg.',
                        'attrs' => ['fabric' => 'Cotton stretch'],
                        'variants' => ['Size' => ['30', '32', '34', '36'], 'Color' => ['Khaki', 'Navy']],
                    ],
                    [
                        'name' => 'Performance Tee', 'brand' => 'Pace Athletic', 'price' => 19.99,
                        'desc' => 'Moisture-wicking training tee, breathable mesh panels.',
                        'variants' => ['Size' => ['S', 'M', 'L', 'XL']],
                    ],
                ],
            ],
            [
                'name' => "Women's",
                'items' => [
                    [
                        'name' => 'Wool Sweater', 'brand' => 'Nordic Knit', 'price' => 54.99,
                        'desc' => 'Soft merino-blend crewneck sweater for layering.',
                        'attrs' => ['fabric' => 'Merino wool blend'],
                        'variants' => ['Size' => ['S', 'M', 'L'], 'Color' => ['Cream', 'Charcoal', 'Rust']],
                        'popular' => true,
                    ],
                    [
                        'name' => 'Wrap Midi Dress', 'brand' => 'Thread & Co.', 'price' => 49.99,
                        'desc' => 'Flattering wrap-tie midi dress, lightweight woven fabric.',
                        'variants' => ['Size' => ['XS', 'S', 'M', 'L'], 'Color' => ['Black', 'Floral']],
                    ],
                    [
                        'name' => 'High-Rise Jeans', 'brand' => 'Urban Loom', 'price' => 46.00,
                        'desc' => 'High-rise skinny jeans with a hint of stretch.',
                        'variants' => ['Size' => ['26', '28', '30', '32'], 'Color' => ['Light Wash', 'Dark Wash']],
                    ],
                ],
            ],
            [
                'name' => 'Kids',
                'items' => [
                    [
                        'name' => 'Kids Jogger Set', 'brand' => 'Pace Athletic', 'price' => 24.99,
                        'desc' => 'Cozy fleece jogger + hoodie set for everyday play.',
                        'variants' => ['Size' => ['4-5Y', '6-7Y', '8-9Y'], 'Color' => ['Grey', 'Navy']],
                    ],
                    [
                        'name' => 'Kids Graphic Tee', 'brand' => 'Thread & Co.', 'price' => 14.99,
                        'desc' => 'Soft cotton tee with a playful print, pre-shrunk.',
                        'variants' => ['Size' => ['4-5Y', '6-7Y', '8-9Y']],
                    ],
                ],
            ],
            [
                'name' => 'Accessories',
                'items' => [
                    [
                        'name' => 'Canvas Tote Bag', 'brand' => 'Urban Loom', 'price' => 22.00,
                        'desc' => 'Heavy-duty canvas tote with inner pocket.',
                        'variants' => ['Color' => ['Natural', 'Black']],
                    ],
                    [
                        'name' => 'Wool Beanie', 'brand' => 'Nordic Knit', 'price' => 16.00,
                        'desc' => 'Ribbed knit beanie, one size fits most.',
                        'variants' => ['Color' => ['Charcoal', 'Cream', 'Rust']],
                    ],
                    [
                        'name' => 'Leather Belt', 'brand' => 'Thread & Co.', 'price' => 18.00,
                        'desc' => 'Genuine leather belt with brushed metal buckle.',
                        'variants' => ['Size' => ['S', 'M', 'L']],
                    ],
                    [
                        'name' => 'Ankle Socks 3-Pack', 'brand' => 'Pace Athletic', 'price' => 9.99,
                        'desc' => 'Cushioned ankle socks, arch support.',
                        'stock' => 100,
                    ],
                ],
            ],
        ],
    ],

    'carparts' => [
        'brands' => ['Ironclad Auto Parts', 'TorqueLine', 'BrightBeam', 'VoltCore'],
        'categories' => [
            [
                'name' => 'Brakes',
                'items' => [
                    [
                        'name' => 'Ceramic Brake Pad Set - Front', 'brand' => 'Ironclad Auto Parts', 'price' => 42.99,
                        'desc' => 'Low-dust ceramic brake pads with wear sensor, front axle set.',
                        'oem' => ['04465-06121', '04465-33471'], 'xref' => ['D1367', 'MD-1234'],
                        'fits' => [
                            ['Toyota', 'Camry', null, '2.5L L4', 2015, 2019],
                            ['Toyota', 'Corolla', null, null, 2014, 2018],
                        ],
                        'popular' => true, 'weight' => 2.1,
                    ],
                    [
                        'name' => 'Ceramic Brake Pad Set - Rear', 'brand' => 'Ironclad Auto Parts', 'price' => 38.99,
                        'desc' => 'Low-dust ceramic brake pads, rear axle set.',
                        'oem' => ['43022-TVA-A00'], 'xref' => ['D1543'],
                        'fits' => [
                            ['Honda', 'Civic', null, '2.0L L4', 2016, 2021],
                            ['Honda', 'Accord', null, null, 2015, 2020],
                        ],
                        'weight' => 1.8,
                    ],
                    [
                        'name' => 'Brake Rotor (Vented, Front)', 'brand' => 'TorqueLine', 'price' => 54.00,
                        'desc' => 'Vented front brake rotor, OE replacement dimensions.',
                        'oem' => ['FL3Z-1125-D'], 'xref' => ['600-54163'],
                        'fits' => [['Ford', 'F-150', null, '3.5L V6', 2015, 2020]],
                        'weight' => 8.4,
                    ],
                ],
            ],
            [
                'name' => 'Filters',
                'items' => [
                    [
                        'name' => 'Engine Air Filter', 'brand' => 'TorqueLine', 'price' => 16.50,
                        'desc' => 'High-flow panel air filter, direct OE fit.',
                        'oem' => ['17801-0P050'], 'xref' => ['CA10467'],
                        'fits' => [
                            ['Toyota', 'Camry', null, null, 2015, 2019],
                            ['Nissan', 'Altima', null, null, 2016, 2020],
                        ],
                    ],
                    [
                        'name' => 'Oil Filter', 'brand' => 'TorqueLine', 'price' => 8.99,
                        'desc' => 'Full-flow spin-on oil filter.',
                        'oem' => ['15400-PLM-A02'], 'xref' => ['51516'],
                        'fits' => [
                            ['Honda', 'Civic', null, null, 2016, 2021],
                            ['Honda', 'CR-V', null, null, 2017, 2021],
                        ],
                        'popular' => true,
                    ],
                    [
                        'name' => 'Cabin Air Filter', 'brand' => 'TorqueLine', 'price' => 14.00,
                        'desc' => 'Particulate + odor cabin filter.',
                        'oem' => ['FL3Z-19N619-A'], 'xref' => ['CF11182'],
                        'fits' => [
                            ['Ford', 'F-150', null, null, 2015, 2020],
                            ['Chevrolet', 'Silverado', null, null, 2015, 2019],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Lighting',
                'items' => [
                    [
                        'name' => 'LED Headlight Bulb Kit (H11)', 'brand' => 'BrightBeam', 'price' => 34.99,
                        'desc' => 'Plug-and-play LED conversion kit, 6500K white, pair.',
                        'oem' => ['H11-LED'], 'xref' => ['9145-LED'],
                        'fits' => [
                            ['Toyota', 'Corolla', null, null, 2014, 2018],
                            ['Nissan', 'Altima', null, null, 2016, 2020],
                        ],
                    ],
                    [
                        'name' => 'Fog Light Assembly', 'brand' => 'BrightBeam', 'price' => 28.50,
                        'desc' => 'Direct-fit fog light housing with bulb, driver or passenger side.',
                        'oem' => ['33900-TBA-A01'], 'xref' => ['FL7080'],
                        'fits' => [['Honda', 'Civic', null, null, 2016, 2021]],
                    ],
                ],
            ],
            [
                'name' => 'Batteries & Electrical',
                'items' => [
                    [
                        'name' => 'AGM Car Battery 12V 60Ah', 'brand' => 'VoltCore', 'price' => 129.99,
                        'desc' => 'Maintenance-free AGM battery, 3-year warranty.',
                        'oem' => ['H6-AGM'], 'xref' => ['48-AGM'],
                        'fits' => [
                            ['Toyota', 'Camry', null, null, 2015, 2019],
                            ['Ford', 'F-150', null, null, 2015, 2020],
                        ],
                        'weight' => 17.5, 'popular' => true,
                    ],
                    [
                        'name' => 'Alternator (Remanufactured)', 'brand' => 'VoltCore', 'price' => 145.00,
                        'desc' => 'OE-spec remanufactured alternator, 150A output, core exchange.',
                        'oem' => ['23-3401'], 'xref' => ['11265'],
                        'fits' => [['Chevrolet', 'Silverado', null, '5.3L V8', 2015, 2019]],
                        'weight' => 6.2,
                    ],
                    [
                        'name' => 'Serpentine Belt', 'brand' => 'TorqueLine', 'price' => 22.00,
                        'desc' => 'Multi-rib serpentine belt, OE length and rib count.',
                        'oem' => ['6PK2110'], 'xref' => ['K060845'],
                        'fits' => [['Honda', 'Accord', null, null, 2015, 2020]],
                    ],
                ],
            ],
            [
                'name' => 'Fluids & Chemicals',
                'items' => [
                    [
                        'name' => 'Full Synthetic Motor Oil 5W-30 (5L)', 'brand' => 'Ironclad Auto Parts', 'price' => 32.00,
                        'desc' => 'API SP / dexos-approved full synthetic engine oil, universal fit.',
                        'stock' => 90, 'weight' => 4.7,
                    ],
                    [
                        'name' => 'Long-Life Coolant (1 Gal)', 'brand' => 'Ironclad Auto Parts', 'price' => 14.50,
                        'desc' => 'Pre-mixed 50/50 extended-life coolant, universal fit.',
                        'stock' => 70, 'weight' => 3.8,
                    ],
                ],
            ],
        ],
    ],

    'laundry' => [
        'categories' => [
            [
                'name' => 'Wash & Fold',
                'items' => [
                    [
                        'name' => 'Wash & Fold Laundry Service', 'price' => 3.50,
                        'desc' => 'Everyday laundry washed, dried and neatly folded, billed per kilogram.',
                        'pricing_unit' => 'kg', 'turnaround_hours' => 24, 'popular' => true,
                    ],
                    [
                        'name' => 'Express Wash & Fold (Same-Day)', 'price' => 5.50,
                        'desc' => 'Same-day wash and fold for time-sensitive loads, billed per kilogram.',
                        'pricing_unit' => 'kg', 'turnaround_hours' => 8,
                    ],
                    [
                        'name' => 'Bedding & Linens Wash', 'price' => 8.00,
                        'desc' => 'Duvets, sheets and large linens washed and folded, priced per item.',
                        'pricing_unit' => 'each', 'turnaround_hours' => 24,
                    ],
                ],
            ],
            [
                'name' => 'Dry Cleaning',
                'items' => [
                    [
                        'name' => 'Dry Clean - Shirt / Blouse', 'price' => 4.50,
                        'desc' => 'Professional dry cleaning and pressing for shirts and blouses.',
                        'pricing_unit' => 'each', 'turnaround_hours' => 48, 'popular' => true,
                    ],
                    [
                        'name' => 'Dry Clean - Suit (2pc)', 'price' => 14.00,
                        'desc' => 'Jacket and trouser/skirt dry cleaned and pressed as a set.',
                        'pricing_unit' => 'each', 'turnaround_hours' => 48,
                    ],
                    [
                        'name' => 'Dry Clean - Dress', 'price' => 9.50,
                        'desc' => 'Gentle dry cleaning for everyday and occasion dresses.',
                        'pricing_unit' => 'each', 'turnaround_hours' => 48,
                    ],
                    [
                        'name' => 'Dry Clean - Coat / Jacket', 'price' => 16.00,
                        'desc' => 'Deep clean and press for winter coats and heavy jackets.',
                        'pricing_unit' => 'each', 'turnaround_hours' => 72,
                    ],
                ],
            ],
            [
                'name' => 'Ironing & Pressing',
                'items' => [
                    [
                        'name' => 'Iron & Press - Shirt', 'price' => 2.50,
                        'desc' => 'Crisp, crease-free pressing for a single shirt.',
                        'pricing_unit' => 'each', 'turnaround_hours' => 24,
                    ],
                    [
                        'name' => 'Iron & Press - Trousers', 'price' => 3.00,
                        'desc' => 'Sharp center-crease pressing for trousers.',
                        'pricing_unit' => 'each', 'turnaround_hours' => 24,
                    ],
                    [
                        'name' => 'Iron & Press - Bulk Bundle (10 items)', 'price' => 18.00,
                        'desc' => 'Bundle rate for pressing up to 10 everyday garments.',
                        'pricing_unit' => 'each', 'turnaround_hours' => 24,
                    ],
                ],
            ],
            [
                'name' => 'Specialty Care',
                'items' => [
                    [
                        'name' => 'Delicate / Silk Care', 'price' => 7.50,
                        'desc' => 'Hand-finished care for silk and other delicate fabrics.',
                        'pricing_unit' => 'each', 'turnaround_hours' => 48,
                    ],
                    [
                        'name' => 'Curtain & Drapery Cleaning', 'price' => 6.00,
                        'desc' => 'Deep cleaning for curtains and drapery, billed per kilogram.',
                        'pricing_unit' => 'kg', 'turnaround_hours' => 72,
                    ],
                    [
                        'name' => 'Leather & Suede Cleaning', 'price' => 25.00,
                        'desc' => 'Specialist cleaning and conditioning for leather and suede items.',
                        'pricing_unit' => 'each', 'turnaround_hours' => 96,
                    ],
                ],
            ],
        ],
    ],

    'pharmacy' => [
        'brands' => ['Wellspring', 'PureCare', 'VitaBoost', 'CalmAid'],
        'categories' => [
            [
                'name' => 'Pain Relief',
                'items' => [
                    [
                        'name' => 'Pain Relief Tablets 500mg (24ct)', 'brand' => 'Wellspring', 'price' => 5.25,
                        'desc' => 'Fast-acting pain and fever relief tablets, 24 count.',
                        'batch_no' => 'WS-2026-14', 'expiry_date' => now()->addMonths(14)->toDateString(), 'popular' => true,
                    ],
                    [
                        'name' => 'Menthol Muscle Rub 100g', 'brand' => 'Wellspring', 'price' => 6.75,
                        'desc' => 'Topical menthol rub for muscle and joint discomfort.',
                        'batch_no' => 'WS-2026-15', 'expiry_date' => now()->addMonths(10)->toDateString(),
                    ],
                ],
            ],
            [
                'name' => 'Vitamins & Supplements',
                'items' => [
                    [
                        'name' => 'Vitamin C 1000mg (60ct)', 'brand' => 'VitaBoost', 'price' => 10.99,
                        'desc' => 'High-strength vitamin C supplement, 60 tablets.',
                        'batch_no' => 'VB-2026-21', 'expiry_date' => now()->addMonths(24)->toDateString(),
                    ],
                    [
                        'name' => 'Daily Multivitamin (30ct)', 'brand' => 'VitaBoost', 'price' => 13.50,
                        'desc' => 'Complete daily multivitamin and mineral formula.',
                        'batch_no' => 'VB-2026-22', 'expiry_date' => now()->addDays(20)->toDateString(), // expiring soon
                        'popular' => true,
                    ],
                    [
                        'name' => 'Omega-3 Fish Oil (60ct)', 'brand' => 'VitaBoost', 'price' => 16.00,
                        'desc' => 'Purified fish oil softgels, 1000mg omega-3 per serving.',
                        'batch_no' => 'VB-2026-23', 'expiry_date' => now()->addMonths(18)->toDateString(),
                    ],
                ],
            ],
            [
                'name' => 'First Aid',
                'items' => [
                    [
                        'name' => 'Adhesive Bandages Assorted (40ct)', 'brand' => 'PureCare', 'price' => 3.50,
                        'desc' => 'Assorted-size adhesive bandages for minor cuts and scrapes.',
                    ],
                    [
                        'name' => 'Antiseptic Wipes (30ct)', 'brand' => 'PureCare', 'price' => 4.25,
                        'desc' => 'Individually wrapped antiseptic cleansing wipes.',
                        'batch_no' => 'PC-2025-44', 'expiry_date' => now()->subDays(5)->toDateString(), // already expired
                    ],
                    [
                        'name' => 'Instant Cold Pack', 'brand' => 'PureCare', 'price' => 5.00,
                        'desc' => 'Single-use instant cold compress for sprains and bruises.',
                    ],
                ],
            ],
            [
                'name' => 'Cough & Cold',
                'items' => [
                    [
                        'name' => 'Cough Syrup Adult 100ml', 'brand' => 'CalmAid', 'price' => 7.75,
                        'desc' => 'Adult cough relief syrup, non-drowsy formula.',
                        'age_restricted' => true, 'batch_no' => 'CA-2026-31', 'expiry_date' => now()->addMonths(14)->toDateString(),
                        'popular' => true,
                    ],
                    [
                        'name' => 'Sinus Relief Capsules (16ct)', 'brand' => 'CalmAid', 'price' => 8.50,
                        'desc' => 'Decongestant capsules for sinus pressure and congestion.',
                        'age_restricted' => true, 'batch_no' => 'CA-2026-32', 'expiry_date' => now()->addMonths(12)->toDateString(),
                    ],
                    [
                        'name' => 'Cough Drops Honey Lemon (20ct)', 'brand' => 'CalmAid', 'price' => 3.25,
                        'desc' => 'Soothing honey-lemon throat lozenges.',
                        'batch_no' => 'CA-2026-33', 'expiry_date' => now()->addMonths(12)->toDateString(),
                    ],
                ],
            ],
            [
                'name' => 'Personal Care & Hygiene',
                'items' => [
                    [
                        'name' => 'Hand Sanitizer Gel 250ml', 'brand' => 'PureCare', 'price' => 4.00,
                        'desc' => '70% alcohol hand sanitizer gel with aloe.',
                        'batch_no' => 'PC-2026-51', 'expiry_date' => now()->addMonths(24)->toDateString(),
                    ],
                    [
                        'name' => 'Digital Thermometer', 'brand' => 'PureCare', 'price' => 9.99,
                        'desc' => 'Fast 10-second digital thermometer with fever alert.',
                    ],
                    [
                        'name' => 'Allergy Relief Tablets (30ct)', 'brand' => 'Wellspring', 'price' => 9.25,
                        'desc' => 'Non-drowsy 24-hour allergy relief tablets.',
                        'batch_no' => 'WS-2026-16', 'expiry_date' => now()->addMonths(16)->toDateString(),
                    ],
                ],
            ],
        ],
    ],

];
