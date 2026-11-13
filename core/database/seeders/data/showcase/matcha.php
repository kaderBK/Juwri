<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/** Matcha Tea Room — Japanese tea house showcase menu. Same shape as crave.php. */
return [
    'store' => [
        'title' => 'Matcha Tea Room', 'subtitle' => 'Japanese tea house · matcha bar · sushi & sweets',
        'description' => 'A calm Japanese tea room: ceremonial matcha, hand-rolled sushi, warm bowls and delicate sweets.',
        'color' => '#3f7d4f', 'logo' => 'tea-matcha', 'cover' => 'tea-pot',
        'banners' => ['tea-matcha', 'sushi-rolls', 'pannacotta'],
        'coupons' => [['code' => 'MATCHA10', 'pct' => 10]],
    ],
    'groups' => [
        'cup'   => ['t' => ['en' => 'Cup size', 'ar' => 'حجم الكوب'], 'opts' => [['Regular', 'عادي', 0], ['Large', 'كبير', 4]], 'required' => true],
        'milk'  => ['t' => ['en' => 'Milk', 'ar' => 'الحليب'], 'opts' => [['Fresh', 'طازج', 0], ['Oat', 'شوفان', 3], ['Soy', 'صويا', 3]]],
        'temp'  => ['t' => ['en' => 'Serve', 'ar' => 'التقديم'], 'opts' => [['Hot', 'ساخن', 0], ['Iced', 'مثلج', 0]], 'required' => true],
        'rolls' => ['t' => ['en' => 'Pieces', 'ar' => 'عدد القطع'], 'opts' => [['8 pcs', '٨ قطع', 0], ['12 pcs', '١٢ قطعة', 14]], 'required' => true],
        'spicy' => ['t' => ['en' => 'Wasabi level', 'ar' => 'مستوى الواسابي'], 'opts' => [['None', 'بدون', 0], ['Light', 'خفيف', 0], ['Extra', 'إضافي', 2]]],
    ],
    'extras' => [
        'shot_matcha' => ['t' => ['en' => 'Extra matcha shot', 'ar' => 'جرعة ماتشا إضافية'], 'p' => 6, 'img' => 'tea-matcha'],
        'mochi'       => ['t' => ['en' => 'Sweet of the day', 'ar' => 'حلوى اليوم'], 'p' => 8, 'img' => 'cookie'],
        'ginger'      => ['t' => ['en' => 'Pickled ginger', 'ar' => 'زنجبيل مخلل'], 'p' => 3, 'img' => 'spring-rolls'],
        'soy_extra'   => ['t' => ['en' => 'Extra soy & wasabi', 'ar' => 'صويا وواسابي إضافي'], 'p' => 2, 'img' => 'sushi-rolls'],
        'rice_side'   => ['t' => ['en' => 'Steamed rice side', 'ar' => 'أرز على البخار'], 'p' => 7, 'img' => 'rice-fried'],
    ],
    'categories' => [
        [
            'n' => ['en' => 'Tea Bar', 'ar' => 'ركن الشاي'], 'img' => 'tea-matcha',
            'items' => [
                ['n' => ['en' => 'Ceremonial Matcha Latte', 'ar' => 'ماتشا لاتيه فاخرة'], 'd' => ['en' => 'Stone-ground ceremonial matcha, silky milk.', 'ar' => 'ماتشا مطحونة على الحجر مع حليب حريري.'], 'p' => 22, 'img' => 'tea-matcha', 'kcal' => 160, 'pro' => 6, 'carb' => 18, 'fat' => 7, 'veg' => true, 'gf' => true, 'alg' => [1], 'grp' => ['cup', 'milk', 'temp'], 'ext' => ['shot_matcha'], 'pop' => true, 'spec' => true, 'rate' => 4.8, 'rc' => 96],
                ['n' => ['en' => 'Tea Ceremony Pot', 'ar' => 'إبريق الشاي الياباني'], 'd' => ['en' => 'Whole-leaf sencha pot for two, poured slow.', 'ar' => 'إبريق سنشا بأوراق كاملة لشخصين.'], 'p' => 26, 'img' => 'tea-pot', 'kcal' => 5, 'pro' => 0, 'carb' => 1, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.6, 'rc' => 41],
                ['n' => ['en' => 'Hojicha Black', 'ar' => 'هوجيتشا'], 'd' => ['en' => 'Roasted-leaf tea, toasty and low caffeine.', 'ar' => 'شاي محمص بنكهة دافئة وكافيين منخفض.'], 'p' => 14, 'img' => 'tea-black', 'kcal' => 5, 'pro' => 0, 'carb' => 1, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'grp' => ['temp'], 'rate' => 4.4, 'rc' => 22],
                ['n' => ['en' => 'Tea & Biscuits Set', 'ar' => 'طقم الشاي والبسكويت'], 'd' => ['en' => 'Milky tea with two butter biscuits.', 'ar' => 'شاي بالحليب مع بسكويت الزبدة.'], 'p' => 16, 'img' => 'tea-cookies', 'kcal' => 220, 'pro' => 5, 'carb' => 32, 'fat' => 8, 'veg' => true, 'alg' => [1, 7, 9], 'ext' => ['mochi'], 'rate' => 4.3, 'rc' => 18],
                ['n' => ['en' => 'Iced Genmaicha', 'ar' => 'جينمايتشا مثلج'], 'd' => ['en' => 'Toasted-rice green tea over ice, lemon wheel.', 'ar' => 'شاي أخضر بالأرز المحمص مع الثلج والليمون.'], 'p' => 15, 'img' => 'iced-tea', 'kcal' => 40, 'pro' => 0, 'carb' => 10, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.2, 'rc' => 14],
                ['n' => ['en' => 'Yuzu Mint Cooler', 'ar' => 'مبرد اليوزو والنعناع'], 'd' => ['en' => 'Citrus yuzu, crushed mint, sparkling top.', 'ar' => 'حمضيات اليوزو مع النعناع والصودا.'], 'p' => 18, 'img' => 'mint-lemonade', 'kcal' => 120, 'pro' => 0, 'carb' => 30, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'pop' => true, 'rate' => 4.5, 'rc' => 27],
            ],
        ],
        [
            'n' => ['en' => 'Sushi & Raw', 'ar' => 'سوشي وأطباق نيئة'], 'img' => 'sushi-rolls',
            'items' => [
                ['n' => ['en' => 'Salmon Avocado Roll', 'ar' => 'رول السلمون والأفوكادو'], 'd' => ['en' => 'Hand-rolled salmon, avocado, sesame crust.', 'ar' => 'رول سلمون وأفوكادو بقشرة السمسم.'], 'p' => 38, 'img' => 'sushi-rolls', 'gal' => ['salmon-sashimi', 'poke-bowl'], 'kcal' => 420, 'pro' => 22, 'carb' => 52, 'fat' => 14, 'alg' => [3, 6], 'grp' => ['rolls', 'spicy'], 'ext' => ['ginger', 'soy_extra'], 'pop' => true, 'spec' => true, 'rate' => 4.8, 'rc' => 88],
                ['n' => ['en' => 'Sashimi Plate', 'ar' => 'طبق الساشيمي'], 'd' => ['en' => 'Chef-cut salmon sashimi, fresh wasabi.', 'ar' => 'شرائح سلمون طازجة مع واسابي.'], 'p' => 46, 'img' => 'salmon-sashimi', 'kcal' => 260, 'pro' => 24, 'carb' => 2, 'fat' => 18, 'gf' => true, 'alg' => [3], 'grp' => ['spicy'], 'rate' => 4.7, 'rc' => 52],
                ['n' => ['en' => 'Tokyo Poke Bowl', 'ar' => 'بوكي طوكيو'], 'd' => ['en' => 'Sushi rice, salmon cubes, edamame, ponzu.', 'ar' => 'أرز سوشي مع سلمون وإدامامي وصوص البونزو.'], 'p' => 44, 'img' => 'poke-bowl', 'kcal' => 520, 'pro' => 30, 'carb' => 58, 'fat' => 20, 'gf' => true, 'alg' => [3, 6], 'pop' => true, 'rate' => 4.7, 'rc' => 64],
                ['n' => ['en' => 'Citrus Tataki', 'ar' => 'تاتاكي الحمضيات'], 'd' => ['en' => 'Lightly-cured white fish, citrus soy.', 'ar' => 'سمك أبيض منقوع بصوص الحمضيات.'], 'p' => 40, 'img' => 'ceviche', 'kcal' => 240, 'pro' => 28, 'carb' => 12, 'fat' => 8, 'gf' => true, 'alg' => [3, 6], 'rate' => 4.4, 'rc' => 21],
            ],
        ],
        [
            'n' => ['en' => 'Warm Bowls', 'ar' => 'أطباق دافئة'], 'img' => 'noodles-soup',
            'items' => [
                ['n' => ['en' => 'Tea-House Ramen', 'ar' => 'رامن بيت الشاي'], 'd' => ['en' => 'Springy noodles, miso broth, soft greens.', 'ar' => 'نودلز مع مرق الميسو والخضار.'], 'p' => 34, 'img' => 'noodles-soup', 'kcal' => 480, 'pro' => 16, 'carb' => 66, 'fat' => 14, 'veg' => true, 'spice' => 1, 'alg' => [6, 7], 'ext' => ['rice_side'], 'pop' => true, 'rate' => 4.6, 'rc' => 47],
                ['n' => ['en' => 'Gyoza (6 pcs)', 'ar' => 'جيوزا (٦ قطع)'], 'd' => ['en' => 'Pan-seared chicken dumplings, soy-chili dip.', 'ar' => 'زلابية دجاج مقلية مع صوص الصويا الحار.'], 'p' => 24, 'img' => 'momo-dumplings', 'kcal' => 380, 'pro' => 20, 'carb' => 44, 'fat' => 12, 'spice' => 1, 'alg' => [6, 7], 'grp' => ['spicy'], 'rate' => 4.5, 'rc' => 39],
                ['n' => ['en' => 'Garden Donburi', 'ar' => 'دونبوري الخضار'], 'd' => ['en' => 'Rice bowl, seasoned vegetables, soft egg.', 'ar' => 'أرز مع خضار متبلة وبيض.'], 'p' => 30, 'img' => 'bibimbap', 'kcal' => 560, 'pro' => 20, 'carb' => 78, 'fat' => 18, 'veg' => true, 'alg' => [2, 6], 'rate' => 4.3, 'rc' => 25],
                ['n' => ['en' => 'Garlic Fried Rice', 'ar' => 'أرز مقلي بالثوم'], 'd' => ['en' => 'Wok-tossed rice, egg ribbons, scallion.', 'ar' => 'أرز مقلي مع البيض والبصل الأخضر.'], 'p' => 24, 'img' => 'rice-fried', 'kcal' => 520, 'pro' => 16, 'carb' => 78, 'fat' => 16, 'alg' => [2, 6], 'rate' => 4.2, 'rc' => 19],
                ['n' => ['en' => 'Prawn Rice Pot', 'ar' => 'أرز الروبيان'], 'd' => ['en' => 'Claypot rice, prawns, ginger-scallion oil.', 'ar' => 'أرز بالروبيان مع زيت الزنجبيل.'], 'p' => 42, 'img' => 'rice-bowl', 'kcal' => 620, 'pro' => 30, 'carb' => 82, 'fat' => 18, 'alg' => [5, 6], 'rate' => 4.5, 'rc' => 23],
            ],
        ],
        [
            'n' => ['en' => 'Small Plates', 'ar' => 'أطباق صغيرة'], 'img' => 'spring-rolls',
            'items' => [
                ['n' => ['en' => 'Veggie Harumaki', 'ar' => 'هاروماكي الخضار'], 'd' => ['en' => 'Fresh vegetable rolls, pomegranate dip.', 'ar' => 'لفائف خضار طازجة مع صوص الرمان.'], 'p' => 20, 'img' => 'spring-rolls', 'kcal' => 240, 'pro' => 6, 'carb' => 32, 'fat' => 8, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.3, 'rc' => 17],
                ['n' => ['en' => 'Sesame Garden Salad', 'ar' => 'سلطة السمسم'], 'd' => ['en' => 'Crisp leaves, goma sesame dressing.', 'ar' => 'خضار مقرمشة مع صوص السمسم الياباني.'], 'p' => 22, 'img' => 'salad-garden', 'kcal' => 240, 'pro' => 6, 'carb' => 18, 'fat' => 16, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [6], 'rate' => 4.2, 'rc' => 13],
                ['n' => ['en' => 'Goma-ae Greens', 'ar' => 'خضار بالسمسم'], 'd' => ['en' => 'Charred broccolini, sesame-miso glaze.', 'ar' => 'بروكليني مشوي بصوص الميسو والسمسم.'], 'p' => 19, 'img' => 'veg-greens', 'kcal' => 180, 'pro' => 7, 'carb' => 14, 'fat' => 12, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [6], 'rate' => 4.1, 'rc' => 11],
            ],
        ],
        [
            'n' => ['en' => 'Sweets', 'ar' => 'حلويات'], 'img' => 'pannacotta',
            'items' => [
                ['n' => ['en' => 'Strawberry Panna Cotta', 'ar' => 'بانا كوتا الفراولة'], 'd' => ['en' => 'Silky set cream, macerated strawberries.', 'ar' => 'كريمة حريرية مع الفراولة المنقوعة.'], 'p' => 20, 'img' => 'pannacotta', 'kcal' => 320, 'pro' => 6, 'carb' => 34, 'fat' => 18, 'veg' => true, 'gf' => true, 'alg' => [1], 'pop' => true, 'rate' => 4.6, 'rc' => 31],
                ['n' => ['en' => 'Yuzu Cheesecake', 'ar' => 'تشيز كيك اليوزو'], 'd' => ['en' => 'Baked cheesecake, yuzu curd, sesame crumb.', 'ar' => 'تشيز كيك مع كريمة اليوزو وفتات السمسم.'], 'p' => 24, 'img' => 'cheesecake', 'kcal' => 480, 'pro' => 9, 'carb' => 46, 'fat' => 30, 'veg' => true, 'alg' => [1, 2, 7], 'rate' => 4.5, 'rc' => 26],
                ['n' => ['en' => 'Banana Matcha Crepe', 'ar' => 'كريب الماتشا والموز'], 'd' => ['en' => 'Thin crepe, banana, matcha drizzle.', 'ar' => 'كريب رقيق مع الموز وصوص الماتشا.'], 'p' => 22, 'img' => 'crepes-banana', 'kcal' => 420, 'pro' => 8, 'carb' => 60, 'fat' => 16, 'veg' => true, 'alg' => [1, 2, 7], 'rate' => 4.4, 'rc' => 19],
                ['n' => ['en' => 'Matcha Soft Cone', 'ar' => 'كون الماتشا'], 'd' => ['en' => 'Matcha soft-serve in a crisp waffle cone.', 'ar' => 'آيس كريم ماتشا في كون الوافل.'], 'p' => 14, 'img' => 'icecream-scoop', 'kcal' => 260, 'pro' => 4, 'carb' => 34, 'fat' => 12, 'veg' => true, 'alg' => [1, 7], 'ext' => ['shot_matcha'], 'rate' => 4.5, 'rc' => 24],
                ['n' => ['en' => 'Miso Chocolate Cookies', 'ar' => 'كوكيز الميسو'], 'd' => ['en' => 'Chewy cookies, dark chocolate, miso caramel.', 'ar' => 'كوكيز طرية بالشوكولاتة وكراميل الميسو.'], 'p' => 15, 'img' => 'cookie', 'kcal' => 380, 'pro' => 5, 'carb' => 50, 'fat' => 18, 'veg' => true, 'alg' => [1, 2, 6, 7], 'rate' => 4.3, 'rc' => 15],
            ],
        ],
    ],
    'reviews' => [
        ['name' => 'Aiko', 'stars' => 5, 'body' => ['en' => 'The ceremonial matcha is the real thing — smooth, no bitterness.', 'ar' => 'الماتشا الفاخرة أصلية — ناعمة بلا مرارة.']],
        ['name' => 'Fahad', 'stars' => 5, 'body' => ['en' => 'Salmon roll and ramen in a tea room? It works beautifully.', 'ar' => 'رول السلمون والرامن في بيت شاي؟ مزيج رائع.']],
        ['name' => 'Nadia', 'stars' => 4, 'body' => ['en' => 'Calm space, panna cotta melts in the mouth.', 'ar' => 'مكان هادئ والبانا كوتا تذوب في الفم.']],
        ['name' => 'Ken', 'stars' => 5, 'body' => ['en' => 'Best gyoza outside Tokyo.', 'ar' => 'أفضل جيوزا خارج طوكيو.']],
        ['name' => 'Lama', 'stars' => 4, 'body' => ['en' => 'Yuzu cooler is my summer order.', 'ar' => 'مبرد اليوزو طلبي الصيفي.']],
    ],
];
