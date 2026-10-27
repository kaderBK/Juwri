<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/** Scoop — ice cream & desserts showcase menu. Same shape as crave.php. */
return [
    'store' => [
        'title' => 'Scoop', 'subtitle' => 'Ice cream & desserts · house-churned · sprinkle everything',
        'description' => 'A pastel dessert parlour: house-churned scoops, over-the-top shakes, warm waffles and a cake counter.',
        'color' => '#e06ba6', 'logo' => 'icecream-cone', 'cover' => 'sundae-caramel',
        'banners' => ['icecream-cone', 'freakshake-dark', 'waffles'],
        'coupons' => [['code' => 'SCOOP10', 'pct' => 10]],
    ],
    'groups' => [
        'scoops' => ['t' => ['en' => 'Scoops', 'ar' => 'عدد الكرات'], 'opts' => [['Single', 'واحدة', 0], ['Double', 'اثنتان', 6], ['Triple', 'ثلاث', 11]], 'required' => true],
        'holder' => ['t' => ['en' => 'Cone or cup', 'ar' => 'كون أم كوب'], 'opts' => [['Waffle cone', 'كون وافل', 0], ['Cup', 'كوب', 0], ['Sprinkle bowl', 'وعاء الرشات', 4]], 'required' => true],
        'toppers' => ['t' => ['en' => 'Toppers', 'ar' => 'الإضافات'], 'opts' => [['Sprinkles', 'رشات', 1], ['Caramel', 'كراميل', 2], ['Chocolate', 'شوكولاتة', 2], ['Pecans', 'بقان', 3]], 'min' => 0, 'max' => 4],
    ],
    'extras' => [
        'scoop'   => ['t' => ['en' => 'Extra scoop', 'ar' => 'كرة إضافية'], 'p' => 6, 'img' => 'icecream-scoop'],
        'brownie' => ['t' => ['en' => 'Brownie chunk', 'ar' => 'قطع براوني'], 'p' => 5, 'img' => 'brownie'],
        'cream'   => ['t' => ['en' => 'Whipped cream', 'ar' => 'كريمة مخفوقة'], 'p' => 3, 'img' => 'milkshake'],
        'cookie'  => ['t' => ['en' => 'Cookie crumb', 'ar' => 'فتات كوكيز'], 'p' => 3, 'img' => 'cookie'],
    ],
    'categories' => [
        [
            'n' => ['en' => 'Scoops & Sundaes', 'ar' => 'كرات وصنداي'], 'img' => 'icecream-cone',
            'items' => [
                ['n' => ['en' => 'Classic Cone', 'ar' => 'كون كلاسيك'], 'd' => ['en' => 'Hand-rolled waffle cone, scoop of choice.', 'ar' => 'كون وافل ملفوف يدوياً مع نكهتك المفضلة.'], 'p' => 12, 'img' => 'icecream-scoop', 'kcal' => 260, 'pro' => 4, 'carb' => 34, 'fat' => 12, 'veg' => true, 'alg' => [1, 7], 'grp' => ['scoops', 'holder', 'toppers'], 'pop' => true, 'rate' => 4.6, 'rc' => 58],
                ['n' => ['en' => 'Sprinkle Party Bowl', 'ar' => 'وعاء الرشات'], 'd' => ['en' => 'Three scoops in a sprinkle-crusted waffle bowl.', 'ar' => 'ثلاث كرات في وعاء الوافل الملون.'], 'p' => 22, 'img' => 'icecream-cone', 'gal' => ['icecream-scoop', 'sundae-caramel'], 'kcal' => 480, 'pro' => 8, 'carb' => 60, 'fat' => 24, 'veg' => true, 'alg' => [1, 2, 7], 'grp' => ['toppers'], 'ext' => ['scoop', 'brownie'], 'pop' => true, 'spec' => true, 'rate' => 4.7, 'rc' => 66],
                ['n' => ['en' => 'Salted Caramel Sundae', 'ar' => 'صنداي الكراميل المملح'], 'd' => ['en' => 'Soft-serve, salted caramel, roasted pecans.', 'ar' => 'آيس كريم مع الكراميل المملح والبقان.'], 'p' => 20, 'img' => 'sundae-caramel', 'kcal' => 430, 'pro' => 7, 'carb' => 52, 'fat' => 22, 'veg' => true, 'alg' => [1, 4], 'grp' => ['toppers'], 'ext' => ['cream', 'brownie'], 'rate' => 4.6, 'rc' => 49],
            ],
        ],
        [
            'n' => ['en' => 'Freakshakes & Floats', 'ar' => 'فريك شيك وفلوت'], 'img' => 'freakshake-dark',
            'items' => [
                ['n' => ['en' => 'OG Freakshake', 'ar' => 'الفريك شيك الأصلي'], 'd' => ['en' => 'Chocolate tower, whipped peak, pretzel.', 'ar' => 'برج شوكولاتة مع قمة الكريمة والبريتزل.'], 'p' => 28, 'img' => 'freakshake-dark', 'kcal' => 720, 'pro' => 14, 'carb' => 92, 'fat' => 34, 'veg' => true, 'alg' => [1, 4, 6, 7], 'ext' => ['cream', 'brownie', 'scoop'], 'pop' => true, 'spec' => true, 'rate' => 4.8, 'rc' => 71],
                ['n' => ['en' => 'Cookies & Cream Shake', 'ar' => 'شيك الكوكيز'], 'd' => ['en' => 'Crushed cookies blended thick.', 'ar' => 'كوكيز مطحون مخفوق كثيف.'], 'p' => 24, 'img' => 'milkshake-choc', 'kcal' => 640, 'pro' => 12, 'carb' => 84, 'fat' => 28, 'veg' => true, 'alg' => [1, 6, 7], 'ext' => ['cookie', 'cream'], 'rate' => 4.6, 'rc' => 43],
                ['n' => ['en' => 'Vanilla Malt Shake', 'ar' => 'شيك الفانيليا'], 'd' => ['en' => 'Old-school malted vanilla.', 'ar' => 'فانيليا كلاسيكية بالشعير.'], 'p' => 22, 'img' => 'milkshake', 'kcal' => 560, 'pro' => 11, 'carb' => 76, 'fat' => 24, 'veg' => true, 'alg' => [1, 7], 'ext' => ['cream'], 'rate' => 4.5, 'rc' => 34],
                ['n' => ['en' => 'Cola Float', 'ar' => 'كولا فلوت'], 'd' => ['en' => 'Glass-bottle cola, vanilla scoop.', 'ar' => 'كولا باردة مع كرة فانيليا.'], 'p' => 14, 'img' => 'soda-cola', 'kcal' => 280, 'pro' => 2, 'carb' => 56, 'fat' => 6, 'veg' => true, 'alg' => [1], 'ext' => ['scoop'], 'rate' => 4.3, 'rc' => 21],
            ],
        ],
        [
            'n' => ['en' => 'Warm & Waffles', 'ar' => 'دافئ ووافل'], 'img' => 'waffles',
            'items' => [
                ['n' => ['en' => 'Waffle & Scoop', 'ar' => 'وافل مع آيس كريم'], 'd' => ['en' => 'Hot waffle, cold scoop, berry compote.', 'ar' => 'وافل ساخن مع آيس كريم بارد وكومبوت التوت.'], 'p' => 26, 'img' => 'waffles', 'kcal' => 620, 'pro' => 12, 'carb' => 88, 'fat' => 26, 'veg' => true, 'alg' => [1, 2, 7, 9], 'grp' => ['toppers'], 'ext' => ['scoop'], 'pop' => true, 'rate' => 4.7, 'rc' => 52],
                ['n' => ['en' => 'Pancake Stack', 'ar' => 'بان كيك'], 'd' => ['en' => 'Tall stack, maple, banana, blueberry.', 'ar' => 'بان كيك مع القيقب والموز والتوت.'], 'p' => 26, 'img' => 'pancakes', 'kcal' => 620, 'pro' => 14, 'carb' => 96, 'fat' => 20, 'veg' => true, 'alg' => [1, 2, 7], 'ext' => ['scoop'], 'rate' => 4.5, 'rc' => 33],
                ['n' => ['en' => 'Banana Crepes', 'ar' => 'كريب الموز'], 'd' => ['en' => 'Thin crepes, banana, chocolate drizzle.', 'ar' => 'كريب رقيق مع الموز والشوكولاتة.'], 'p' => 20, 'img' => 'crepes-banana', 'kcal' => 420, 'pro' => 8, 'carb' => 60, 'fat' => 16, 'veg' => true, 'alg' => [1, 2, 7], 'rate' => 4.4, 'rc' => 26],
                ['n' => ['en' => 'Honey Cheese Crepes', 'ar' => 'كريب الجبن والعسل'], 'd' => ['en' => 'Warm crepes, sweet cheese, honey.', 'ar' => 'كريب دافئ مع الجبن الحلو والعسل.'], 'p' => 22, 'img' => 'crepes-honey', 'kcal' => 460, 'pro' => 12, 'carb' => 56, 'fat' => 20, 'veg' => true, 'alg' => [1, 2, 7], 'rate' => 4.4, 'rc' => 20],
                ['n' => ['en' => 'Cinnamon Churros', 'ar' => 'تشوروز'], 'd' => ['en' => 'Crisp churros, chocolate pot.', 'ar' => 'تشوروز مقرمشة مع صوص الشوكولاتة.'], 'p' => 18, 'img' => 'churros', 'kcal' => 440, 'pro' => 6, 'carb' => 56, 'fat' => 22, 'veg' => true, 'alg' => [1, 7], 'rate' => 4.5, 'rc' => 28],
                ['n' => ['en' => 'Warm Brownie & Scoop', 'ar' => 'براوني دافئ مع آيس كريم'], 'd' => ['en' => 'Fudge brownie, molten pockets, vanilla scoop.', 'ar' => 'براوني دافئ مع كرة فانيليا.'], 'p' => 22, 'img' => 'brownie', 'kcal' => 620, 'pro' => 9, 'carb' => 74, 'fat' => 32, 'veg' => true, 'alg' => [1, 2, 4, 7], 'ext' => ['scoop', 'cream'], 'rate' => 4.6, 'rc' => 31],
                ['n' => ['en' => 'Molten Lava Cake', 'ar' => 'كيك اللافا'], 'd' => ['en' => 'Dark-chocolate centre, baked to order.', 'ar' => 'قلب شوكولاتة سائل يخبز عند الطلب.'], 'p' => 26, 'img' => 'lava-cake', 'kcal' => 460, 'pro' => 7, 'carb' => 50, 'fat' => 26, 'veg' => true, 'alg' => [1, 2, 7], 'ext' => ['scoop'], 'spec' => true, 'rate' => 4.8, 'rc' => 38, 'stock' => 10],
            ],
        ],
        [
            'n' => ['en' => 'Cake Counter', 'ar' => 'ركن الكيك'], 'img' => 'cake-redvelvet',
            'items' => [
                ['n' => ['en' => 'Red Velvet Slice', 'ar' => 'رد فلفت'], 'd' => ['en' => 'Cream-cheese frosting, fresh strawberry.', 'ar' => 'كريمة الجبن مع الفراولة الطازجة.'], 'p' => 24, 'img' => 'cake-redvelvet', 'kcal' => 480, 'pro' => 6, 'carb' => 58, 'fat' => 26, 'veg' => true, 'alg' => [1, 2, 7], 'pop' => true, 'rate' => 4.6, 'rc' => 36],
                ['n' => ['en' => 'Chocolate Fudge Slice', 'ar' => 'كيك الشوكولاتة'], 'd' => ['en' => 'Dark layers, ganache drip.', 'ar' => 'طبقات داكنة مع الغاناش.'], 'p' => 24, 'img' => 'cake-chocolate', 'kcal' => 540, 'pro' => 7, 'carb' => 66, 'fat' => 28, 'veg' => true, 'alg' => [1, 2, 4, 7], 'rate' => 4.6, 'rc' => 32],
                ['n' => ['en' => 'Blueberry Cheesecake', 'ar' => 'تشيز كيك التوت'], 'd' => ['en' => 'Baked NY style, blueberry compote.', 'ar' => 'تشيز كيك مع كومبوت التوت الأزرق.'], 'p' => 24, 'img' => 'cheesecake', 'kcal' => 520, 'pro' => 9, 'carb' => 48, 'fat' => 32, 'veg' => true, 'alg' => [1, 2, 7], 'rate' => 4.5, 'rc' => 27],
                ['n' => ['en' => 'Tiramisu Cup', 'ar' => 'تيراميسو'], 'd' => ['en' => 'Espresso layers, mascarpone cloud.', 'ar' => 'طبقات الإسبريسو والماسكاربوني.'], 'p' => 22, 'img' => 'tiramisu', 'kcal' => 440, 'pro' => 8, 'carb' => 44, 'fat' => 26, 'veg' => true, 'alg' => [1, 2, 7], 'rate' => 4.5, 'rc' => 22],
                ['n' => ['en' => 'Strawberry Panna Cotta', 'ar' => 'بانا كوتا'], 'd' => ['en' => 'Vanilla set cream, strawberries.', 'ar' => 'كريمة الفانيليا مع الفراولة.'], 'p' => 18, 'img' => 'pannacotta', 'kcal' => 320, 'pro' => 6, 'carb' => 34, 'fat' => 18, 'veg' => true, 'gf' => true, 'alg' => [1], 'rate' => 4.4, 'rc' => 18],
                ['n' => ['en' => 'Party Cupcakes (2)', 'ar' => 'كب كيك (٢)'], 'd' => ['en' => 'Cream swirls, sprinkles.', 'ar' => 'كب كيك بالكريمة والرشات.'], 'p' => 16, 'img' => 'cupcakes', 'kcal' => 380, 'pro' => 5, 'carb' => 52, 'fat' => 18, 'veg' => true, 'alg' => [1, 2, 4, 7], 'rate' => 4.3, 'rc' => 15],
                ['n' => ['en' => 'Glazed Donuts (2)', 'ar' => 'دونات (٢)'], 'd' => ['en' => 'Rings, chocolate drizzle, sprinkles.', 'ar' => 'دونات مع الشوكولاتة والرشات.'], 'p' => 14, 'img' => 'donut', 'kcal' => 420, 'pro' => 5, 'carb' => 58, 'fat' => 20, 'veg' => true, 'alg' => [1, 2, 6, 7], 'rate' => 4.4, 'rc' => 19],
                ['n' => ['en' => 'Chunky Cookies (3)', 'ar' => 'كوكيز (٣)'], 'd' => ['en' => 'Dark-chocolate chunks, sea salt.', 'ar' => 'كوكيز بقطع الشوكولاتة وملح البحر.'], 'p' => 14, 'img' => 'cookie', 'kcal' => 380, 'pro' => 5, 'carb' => 50, 'fat' => 18, 'veg' => true, 'alg' => [1, 2, 6, 7], 'rate' => 4.5, 'rc' => 24],
            ],
        ],
        [
            'n' => ['en' => 'Fresh & Light', 'ar' => 'منعش وخفيف'], 'img' => 'fruit-platter',
            'items' => [
                ['n' => ['en' => 'Seasonal Fruit Platter', 'ar' => 'طبق فواكه'], 'd' => ['en' => 'Carved fruit, mint, citrus dust.', 'ar' => 'فواكه مقطعة مع النعناع.'], 'p' => 24, 'img' => 'fruit-platter', 'kcal' => 180, 'pro' => 2, 'carb' => 44, 'fat' => 1, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.3, 'rc' => 14],
                ['n' => ['en' => 'Fig & Rose Plate', 'ar' => 'طبق التين والورد'], 'd' => ['en' => 'Fresh figs, berries, rose petals.', 'ar' => 'تين طازج مع توت وبتلات الورد.'], 'p' => 26, 'img' => 'fruit-fig-plate', 'kcal' => 220, 'pro' => 3, 'carb' => 48, 'fat' => 2, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.2, 'rc' => 11],
                ['n' => ['en' => 'Watermelon Cooler', 'ar' => 'مبرد البطيخ'], 'd' => ['en' => 'Fresh watermelon, lime, ice.', 'ar' => 'بطيخ طازج مع الليمون والثلج.'], 'p' => 16, 'img' => 'drink-watermelon', 'kcal' => 110, 'pro' => 1, 'carb' => 26, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.3, 'rc' => 13],
                ['n' => ['en' => 'Mint Lemonade', 'ar' => 'ليمون بالنعناع'], 'd' => ['en' => 'Blitzed mint, crushed ice.', 'ar' => 'نعناع مخفوق مع الثلج.'], 'p' => 15, 'img' => 'mint-lemonade', 'kcal' => 130, 'pro' => 0, 'carb' => 32, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.4, 'rc' => 16],
                ['n' => ['en' => 'Affogato', 'ar' => 'أفوجاتو'], 'd' => ['en' => 'Espresso poured over vanilla scoop.', 'ar' => 'إسبريسو يسكب على كرة الفانيليا.'], 'p' => 18, 'img' => 'coffee-espresso', 'kcal' => 220, 'pro' => 4, 'carb' => 26, 'fat' => 11, 'veg' => true, 'gf' => true, 'alg' => [1], 'ext' => ['scoop'], 'rate' => 4.5, 'rc' => 19],
            ],
        ],
    ],
    'reviews' => [
        ['name' => 'Lulu', 'stars' => 5, 'body' => ['en' => 'Sprinkle bowl made my daughter\'s whole month.', 'ar' => 'وعاء الرشات أسعد ابنتي لشهر كامل.']],
        ['name' => 'Adam', 'stars' => 5, 'body' => ['en' => 'OG Freakshake is a dessert, a drink and a dare.', 'ar' => 'الفريك شيك حلا ومشروب وتحدٍ في آن واحد.']],
        ['name' => 'Rand', 'stars' => 4, 'body' => ['en' => 'Hot waffle + cold scoop = physics done right.', 'ar' => 'وافل ساخن مع آيس كريم بارد = فيزياء صحيحة.']],
        ['name' => 'Joe', 'stars' => 4, 'body' => ['en' => 'Affogato properly bitter, properly sweet.', 'ar' => 'الأفوجاتو مر وحلو بتوازن مثالي.']],
        ['name' => 'Farah', 'stars' => 5, 'body' => ['en' => 'Cake counter looks like a pastel dream.', 'ar' => 'ركن الكيك يبدو كحلم باستيلي.']],
    ],
];
