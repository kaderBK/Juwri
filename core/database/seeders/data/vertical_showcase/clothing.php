<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/**
 * Thread & Co. Apparel — expanded demo catalog for the clothing vertical
 * (maison editorial theme). Scratch output only — see makeItem()/makeVariants()
 * in core/app/Modules/Storefront/Database/Seeders/VerticalShowcaseSeeder.php for the exact keys this
 * shape feeds and how the 'variants' axes become MenuVariant.options (Color
 * axis -> theme swatch dots, Size axis -> theme size pills).
 *
 * Images: bare filenames under images/, resolved by the seeder against
 * database/seeders/data/showcase_images/clothing/<file> once copied in.
 */

return [

    'brands' => ['Thread & Co.', 'Nordic Knit', 'Urban Loom', 'Pace Athletic', 'Camden Denim', 'Wanderline'],

    'categories' => [

        [
            'name' => "Men's",
            'items' => [
                [
                    'name' => 'Classic Oxford Shirt', 'brand' => 'Thread & Co.', 'price' => 42.00,
                    'desc' => 'Tailored-fit cotton oxford shirt with a button-down collar, boardroom to weekend.',
                    'attrs' => ['fabric' => '100% Cotton'],
                    'variants' => ['Color' => ['White', 'Sky Blue', 'Black'], 'Size' => ['S', 'M', 'L', 'XL']],
                    'popular' => true,
                ],
                [
                    'name' => 'Slim Fit Chinos', 'brand' => 'Urban Loom', 'price' => 46.00,
                    'desc' => 'Stretch-cotton chinos cut slim through the leg with a clean, tapered finish.',
                    'attrs' => ['fabric' => 'Cotton stretch'],
                    'variants' => ['Color' => ['Khaki', 'Navy', 'Olive'], 'Size' => ['30', '32', '34', '36']],
                    'image' => 'chinos-pants.jpg',
                ],
                [
                    'name' => 'Merino Crewneck Sweater', 'brand' => 'Nordic Knit', 'price' => 58.00,
                    'desc' => 'Fine-gauge merino wool crewneck, soft next to skin and built for layering.',
                    'attrs' => ['fabric' => '100% Merino wool'],
                    'variants' => ['Color' => ['Charcoal', 'Camel', 'Navy'], 'Size' => ['S', 'M', 'L', 'XL']],
                    'image' => 'mens-sweater.jpg',
                ],
                [
                    'name' => 'Performance Training Tee', 'brand' => 'Pace Athletic', 'price' => 22.00,
                    'desc' => 'Moisture-wicking training tee with breathable mesh side panels.',
                    'variants' => ['Color' => ['Black', 'Grey', 'Red'], 'Size' => ['S', 'M', 'L', 'XL']],
                    'image' => 'athletic-tee.jpg',
                ],
                [
                    'name' => 'Denim Trucker Jacket', 'brand' => 'Camden Denim', 'price' => 68.00,
                    'desc' => 'Classic denim trucker jacket with button chest pockets and a broken-in wash.',
                    'attrs' => ['fabric' => '100% Cotton denim'],
                    'variants' => ['Color' => ['Light Wash', 'Dark Wash', 'Black'], 'Size' => ['S', 'M', 'L', 'XL']],
                    'image' => 'denim-jacket.jpg',
                ],
                [
                    'name' => 'Linen Blend Shirt', 'brand' => 'Thread & Co.', 'price' => 44.00,
                    'desc' => 'Breathable linen-cotton shirt for warm-weather ease, cut with a relaxed fit.',
                    'attrs' => ['fabric' => 'Linen-cotton blend'],
                    'variants' => ['Color' => ['White', 'Sage', 'Terracotta'], 'Size' => ['S', 'M', 'L', 'XL']],
                ],
                [
                    'name' => 'Wool Overcoat', 'brand' => 'Nordic Knit', 'price' => 185.00,
                    'desc' => 'Full-length wool-blend overcoat with a notch lapel and a tailored silhouette.',
                    'attrs' => ['fabric' => 'Wool blend'],
                    'variants' => ['Color' => ['Charcoal', 'Camel'], 'Size' => ['S', 'M', 'L', 'XL']],
                    'image' => 'wool-overcoat.jpg',
                ],
            ],
        ],

        [
            'name' => "Women's",
            'items' => [
                [
                    'name' => 'Wool Blend Sweater', 'brand' => 'Nordic Knit', 'price' => 56.00,
                    'desc' => 'Relaxed crewneck sweater in a soft wool blend, an easy layering staple.',
                    'variants' => ['Color' => ['Cream', 'Charcoal', 'Rust'], 'Size' => ['XS', 'S', 'M', 'L']],
                    'popular' => true, 'image' => 'wool-sweater-women.jpg',
                ],
                [
                    'name' => 'Wrap Midi Dress', 'brand' => 'Thread & Co.', 'price' => 52.00,
                    'desc' => 'Flattering wrap-tie midi dress in lightweight woven fabric.',
                    'variants' => ['Color' => ['Black', 'Terracotta', 'Floral'], 'Size' => ['XS', 'S', 'M', 'L']],
                    'image' => 'wrap-dress.jpg',
                ],
                [
                    'name' => 'High-Rise Skinny Jeans', 'brand' => 'Urban Loom', 'price' => 48.00,
                    'desc' => 'High-rise skinny jeans with a hint of stretch for all-day comfort.',
                    'variants' => ['Color' => ['Light Wash', 'Dark Wash', 'Black'], 'Size' => ['26', '28', '30', '32']],
                    'image' => 'skinny-jeans.jpg',
                ],
                [
                    'name' => 'Silk Blouse', 'brand' => 'Thread & Co.', 'price' => 54.00,
                    'desc' => 'Fluid silk-blend blouse with a relaxed drape, easy to dress up or down.',
                    'attrs' => ['fabric' => 'Silk blend'],
                    'variants' => ['Color' => ['Ivory', 'Blush', 'Navy'], 'Size' => ['XS', 'S', 'M', 'L']],
                    'image' => 'silk-blouse.jpg',
                ],
                [
                    'name' => 'Pleated Midi Skirt', 'brand' => 'Urban Loom', 'price' => 46.00,
                    'desc' => 'Fluid pleated midi skirt that moves with every step.',
                    'variants' => ['Color' => ['Camel', 'Black'], 'Size' => ['XS', 'S', 'M', 'L']],
                    'image' => 'pleated-skirt.jpg',
                ],
                [
                    'name' => 'Cropped Puffer Vest', 'brand' => 'Pace Athletic', 'price' => 58.00,
                    'desc' => 'Lightweight cropped puffer vest for on-the-go warmth without the bulk.',
                    'variants' => ['Color' => ['Black', 'Olive'], 'Size' => ['XS', 'S', 'M', 'L']],
                ],
                [
                    'name' => 'Cashmere-Blend Cardigan', 'brand' => 'Nordic Knit', 'price' => 72.00,
                    'desc' => 'Buttoned cardigan in a cashmere blend with a soft drape, easy layering.',
                    'attrs' => ['fabric' => 'Cashmere blend'],
                    'variants' => ['Color' => ['Camel', 'Cream', 'Charcoal'], 'Size' => ['S', 'M', 'L']],
                    'popular' => true, 'image' => 'cashmere-cardigan.jpg',
                ],
            ],
        ],

        [
            'name' => 'Kids',
            'items' => [
                [
                    'name' => 'Kids Jogger Set', 'brand' => 'Pace Athletic', 'price' => 28.00,
                    'desc' => 'Cozy fleece jogger and hoodie set built for everyday play.',
                    'variants' => ['Color' => ['Grey', 'Navy'], 'Size' => ['4-5Y', '6-7Y', '8-9Y']],
                    'image' => 'kids-jogger-set.jpg',
                ],
                [
                    'name' => 'Kids Graphic Tee', 'brand' => 'Thread & Co.', 'price' => 16.00,
                    'desc' => 'Soft pre-shrunk cotton tee with a playful print.',
                    'variants' => ['Color' => ['White', 'Yellow'], 'Size' => ['4-5Y', '6-7Y', '8-9Y']],
                    'image' => 'kids-graphic-tee.jpg',
                ],
                [
                    'name' => 'Kids Puffer Jacket', 'brand' => 'Nordic Knit', 'price' => 44.00,
                    'desc' => 'Lightweight insulated puffer jacket for chilly mornings.',
                    'variants' => ['Color' => ['Red', 'Navy'], 'Size' => ['4-5Y', '6-7Y', '8-9Y']],
                    'image' => 'kids-puffer-jacket.jpg',
                ],
                [
                    'name' => 'Kids Denim Overalls', 'brand' => 'Camden Denim', 'price' => 34.00,
                    'desc' => 'Classic denim overalls with adjustable straps and roomy pockets.',
                    'variants' => ['Color' => ['Light Wash'], 'Size' => ['2-3Y', '4-5Y', '6-7Y']],
                    'image' => 'kids-denim-overalls.jpg',
                ],
                [
                    'name' => 'Kids Knit Beanie & Scarf Set', 'brand' => 'Nordic Knit', 'price' => 19.00,
                    'desc' => 'Ribbed knit beanie and scarf set to keep little ones warm.',
                    'variants' => ['Color' => ['Charcoal', 'Pink'], 'Size' => ['One Size']],
                    'image' => 'kids-beanie.jpg',
                ],
            ],
        ],

        [
            'name' => 'Accessories',
            'items' => [
                [
                    'name' => 'Canvas Tote Bag', 'brand' => 'Urban Loom', 'price' => 24.00,
                    'desc' => 'Heavy-duty canvas tote with an inner zip pocket.',
                    'variants' => ['Color' => ['Natural', 'Black']],
                    'image' => 'canvas-tote-bag.jpg',
                ],
                [
                    'name' => 'Wool Beanie', 'brand' => 'Nordic Knit', 'price' => 18.00,
                    'desc' => 'Ribbed knit beanie, one size fits most.',
                    'variants' => ['Color' => ['Charcoal', 'Cream', 'Rust']],
                    'image' => 'wool-beanie.jpg',
                ],
                [
                    'name' => 'Leather Belt', 'brand' => 'Thread & Co.', 'price' => 28.00,
                    'desc' => 'Genuine leather belt with a brushed metal buckle.',
                    'attrs' => ['fabric' => 'Genuine leather'],
                    'variants' => ['Color' => ['Brown', 'Black'], 'Size' => ['S', 'M', 'L']],
                    'image' => 'leather-belt.jpg',
                ],
                [
                    'name' => 'Silk Scarf', 'brand' => 'Thread & Co.', 'price' => 32.00,
                    'desc' => 'Printed silk scarf, a finishing touch for any outfit.',
                    'attrs' => ['fabric' => '100% Silk'],
                    'variants' => ['Color' => ['Terracotta', 'Navy', 'Blush']],
                    'popular' => true, 'image' => 'silk-scarf.jpg',
                ],
                [
                    'name' => 'Structured Crossbody Bag', 'brand' => 'Urban Loom', 'price' => 64.00,
                    'desc' => 'Structured crossbody bag in supple vegan leather with an adjustable strap.',
                    'variants' => ['Color' => ['Camel', 'Black']],
                    'image' => 'crossbody-bag.jpg',
                ],
                [
                    'name' => 'Leather Wallet', 'brand' => 'Thread & Co.', 'price' => 38.00,
                    'desc' => 'Slim bifold wallet in genuine leather with card slots.',
                    'attrs' => ['fabric' => 'Genuine leather'],
                    'variants' => ['Color' => ['Brown', 'Black']],
                    'image' => 'leather-wallet.jpg',
                ],
            ],
        ],

        [
            'name' => 'Footwear',
            'items' => [
                [
                    'name' => 'Leather Chelsea Boots', 'brand' => 'Wanderline', 'price' => 98.00,
                    'desc' => 'Classic Chelsea boots in smooth leather with an elastic side panel.',
                    'attrs' => ['fabric' => 'Genuine leather'],
                    'variants' => ['Color' => ['Black', 'Brown'], 'Size' => ['7', '8', '9', '10', '11']],
                    'popular' => true, 'image' => 'chelsea-boots.jpg',
                ],
                [
                    'name' => 'Canvas Low-Top Sneakers', 'brand' => 'Pace Athletic', 'price' => 54.00,
                    'desc' => 'Everyday canvas low-top sneakers with a cushioned footbed.',
                    'variants' => ['Color' => ['White', 'Black', 'Navy'], 'Size' => ['7', '8', '9', '10', '11']],
                    'image' => 'canvas-sneakers.jpg',
                ],
                [
                    'name' => 'Suede Desert Boots', 'brand' => 'Wanderline', 'price' => 86.00,
                    'desc' => 'Classic desert boots in soft suede with a crepe sole.',
                    'attrs' => ['fabric' => 'Suede'],
                    'variants' => ['Color' => ['Sand', 'Charcoal'], 'Size' => ['7', '8', '9', '10', '11']],
                    'image' => 'desert-boots.jpg',
                ],
                [
                    'name' => 'Running Trainers', 'brand' => 'Pace Athletic', 'price' => 72.00,
                    'desc' => 'Responsive running trainers with breathable engineered mesh.',
                    'variants' => ['Color' => ['Black', 'Grey', 'Red'], 'Size' => ['7', '8', '9', '10', '11']],
                    'popular' => true, 'image' => 'running-shoes.jpg',
                ],
                [
                    'name' => 'Ballet Flats', 'brand' => 'Wanderline', 'price' => 52.00,
                    'desc' => 'Classic ballet flats in soft leather, an everyday go-to.',
                    'attrs' => ['fabric' => 'Genuine leather'],
                    'variants' => ['Color' => ['Black', 'Nude', 'Camel'], 'Size' => ['5', '6', '7', '8', '9']],
                    'image' => 'ballet-flats.jpg',
                ],
            ],
        ],

    ],

];
