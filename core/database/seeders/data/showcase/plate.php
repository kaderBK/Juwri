<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/** Plate — modern plated showcase menu. Same shape as crave.php. */
return [
    'store' => [
        'title' => 'Plate', 'subtitle' => 'Modern plated · clean lines · seasonal produce',
        'description' => 'A modern kitchen plating seasonal produce with restraint: grains, greens, fish and one perfect steak.',
        'color' => '#334155', 'logo' => 'caesar-2', 'cover' => 'chef-plating',
        'banners' => ['chef-plating', 'salmon-grilled', 'pannacotta'],
        'coupons' => [['code' => 'PLATE10', 'pct' => 10]],
    ],
    'groups' => [
        'protein' => ['t' => ['en' => 'Add protein', 'ar' => 'أضف بروتين'], 'opts' => [['None', 'بدون', 0], ['Chicken', 'دجاج', 12], ['Salmon', 'سلمون', 18], ['Steak slices', 'شرائح ستيك', 22]]],
        'dressing' => ['t' => ['en' => 'Dressing', 'ar' => 'الصوص'], 'opts' => [['Citrus', 'حمضيات', 0], ['Tahini', 'طحينة', 0], ['Buttermilk', 'لبن', 0]], 'required' => true],
        'doneness' => ['t' => ['en' => 'Doneness', 'ar' => 'درجة النضج'], 'opts' => [['Medium rare', 'متوسط النيء', 0], ['Medium', 'متوسط', 0], ['Well done', 'ناضج', 0]], 'required' => true],
    ],
    'extras' => [
        'egg'    => ['t' => ['en' => 'Soft egg', 'ar' => 'بيضة'], 'p' => 4, 'img' => 'eggs-benedict'],
        'grains' => ['t' => ['en' => 'Extra grains', 'ar' => 'حبوب إضافية'], 'p' => 7, 'img' => 'bowl-grain'],
        'bread'  => ['t' => ['en' => 'Seeded bread', 'ar' => 'خبز بالبذور'], 'p' => 6, 'img' => 'bread-basket'],
        'greens' => ['t' => ['en' => 'Charred greens', 'ar' => 'خضار مشوية'], 'p' => 10, 'img' => 'veg-greens'],
    ],
    'categories' => [
        [
            'n' => ['en' => 'Small Plates', 'ar' => 'أطباق صغيرة'], 'img' => 'bruschetta',
            'items' => [
                ['n' => ['en' => 'Tomato Toast', 'ar' => 'توست الطماطم'], 'd' => ['en' => 'Charred sourdough, marinated tomato, basil oil.', 'ar' => 'خبز محمص مع طماطم متبلة وزيت الريحان.'], 'p' => 24, 'img' => 'bruschetta', 'kcal' => 320, 'pro' => 8, 'carb' => 42, 'fat' => 14, 'veg' => true, 'vegan' => true, 'alg' => [7], 'rate' => 4.4, 'rc' => 19],
                ['n' => ['en' => 'Green Soup, Crème Swirl', 'ar' => 'شوربة خضراء'], 'd' => ['en' => 'Bright greens, crème fraîche, seed crunch.', 'ar' => 'خضار خضراء مع الكريمة وقرمشة البذور.'], 'p' => 24, 'img' => 'soup-green', 'kcal' => 220, 'pro' => 7, 'carb' => 22, 'fat' => 14, 'veg' => true, 'gf' => true, 'alg' => [1], 'rate' => 4.3, 'rc' => 13],
                ['n' => ['en' => 'Open Frittata', 'ar' => 'فريتاتا'], 'd' => ['en' => 'Skillet omelette, herbs, shaved cheese.', 'ar' => 'أومليت بالمقلاة مع الأعشاب والجبن.'], 'p' => 26, 'img' => 'frittata', 'kcal' => 400, 'pro' => 22, 'carb' => 12, 'fat' => 30, 'veg' => true, 'gf' => true, 'alg' => [1, 2], 'ext' => ['bread'], 'rate' => 4.2, 'rc' => 11],
                ['n' => ['en' => 'Avocado Plate', 'ar' => 'طبق الأفوكادو'], 'd' => ['en' => 'Smashed avocado, soft egg, dukkah, seeded toast.', 'ar' => 'أفوكادو مهروس مع بيض ودقة وخبز بالبذور.'], 'p' => 30, 'img' => 'avocado-toast', 'kcal' => 420, 'pro' => 16, 'carb' => 38, 'fat' => 24, 'veg' => true, 'alg' => [2, 4, 7], 'ext' => ['egg'], 'pop' => true, 'rate' => 4.6, 'rc' => 27],
            ],
        ],
        [
            'n' => ['en' => 'Bowls & Greens', 'ar' => 'أطباق وسلطات'], 'img' => 'bowl-grain',
            'items' => [
                ['n' => ['en' => 'Harvest Grain Bowl', 'ar' => 'طبق الحبوب'], 'd' => ['en' => 'Quinoa, roast vegetables, seeds, citrus tahini.', 'ar' => 'كينوا مع خضار محمصة وبذور وطحينة الحمضيات.'], 'p' => 36, 'img' => 'bowl-grain', 'gal' => ['salad-greek', 'veg-greens'], 'kcal' => 440, 'pro' => 14, 'carb' => 56, 'fat' => 18, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [4], 'grp' => ['protein', 'dressing'], 'ext' => ['egg', 'grains'], 'pop' => true, 'spec' => true, 'rate' => 4.7, 'rc' => 41],
                ['n' => ['en' => 'Chicken Caesar, Rebuilt', 'ar' => 'سيزر معاد بناؤها'], 'd' => ['en' => 'Charred gem, cured yolk dressing, parm crisp.', 'ar' => 'خس مشوي مع صوص الصفار والبارميزان المقرمش.'], 'p' => 38, 'img' => 'caesar-2', 'kcal' => 460, 'pro' => 34, 'carb' => 24, 'fat' => 26, 'alg' => [1, 2, 3, 7], 'pop' => true, 'rate' => 4.6, 'rc' => 33],
                ['n' => ['en' => 'Fig & Leaves', 'ar' => 'تين وأوراق'], 'd' => ['en' => 'Ripe figs, pomegranate, candied nuts, greens.', 'ar' => 'تين ناضج مع رمان ومكسرات محلاة.'], 'p' => 36, 'img' => 'salad-fig', 'kcal' => 320, 'pro' => 6, 'carb' => 42, 'fat' => 14, 'veg' => true, 'gf' => true, 'alg' => [4], 'rate' => 4.5, 'rc' => 18],
                ['n' => ['en' => 'Kale, Chickpea, Lemon', 'ar' => 'كيل وحمص وليمون'], 'd' => ['en' => 'Massaged kale, crisp chickpeas, lemon tahini.', 'ar' => 'كرنب أخضر مع حمص مقرمش وطحينة الليمون.'], 'p' => 30, 'img' => 'salad-greek', 'kcal' => 300, 'pro' => 12, 'carb' => 30, 'fat' => 16, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'grp' => ['protein', 'dressing'], 'rate' => 4.3, 'rc' => 14],
            ],
        ],
        [
            'n' => ['en' => 'Plates', 'ar' => 'الأطباق الرئيسية'], 'img' => 'salmon-grilled',
            'items' => [
                ['n' => ['en' => 'Salmon, Greens, Lemon', 'ar' => 'سلمون وخضار وليمون'], 'd' => ['en' => 'Char-grilled salmon over wilted greens.', 'ar' => 'سلمون مشوي على الخضار الذابلة.'], 'p' => 68, 'img' => 'salmon-grilled', 'kcal' => 540, 'pro' => 42, 'carb' => 10, 'fat' => 36, 'gf' => true, 'alg' => [3], 'pop' => true, 'rate' => 4.7, 'rc' => 38],
                ['n' => ['en' => 'Sirloin, Sliced', 'ar' => 'سيرلوين مقطع'], 'd' => ['en' => 'Grass-fed sirloin, asparagus, roast carrots.', 'ar' => 'سيرلوين مع الهليون والجزر المحمص.'], 'p' => 92, 'img' => 'steak-sliced', 'kcal' => 760, 'pro' => 56, 'carb' => 22, 'fat' => 48, 'gf' => true, 'alg' => [], 'grp' => ['doneness'], 'ext' => ['greens'], 'rate' => 4.7, 'rc' => 29],
                ['n' => ['en' => 'Pesto Farfalle', 'ar' => 'فارفالي بالبيستو'], 'd' => ['en' => 'Basil-pine pesto, cherry tomato, rocket.', 'ar' => 'بيستو الريحان مع الطماطم الكرزية والجرجير.'], 'p' => 36, 'img' => 'pasta-pesto', 'kcal' => 520, 'pro' => 14, 'carb' => 64, 'fat' => 24, 'veg' => true, 'alg' => [1, 4, 7], 'grp' => ['protein'], 'rate' => 4.4, 'rc' => 20],
                ['n' => ['en' => 'Mushroom Risotto', 'ar' => 'ريزوتو الفطر'], 'd' => ['en' => 'Carnaroli, mushrooms, parmesan wave.', 'ar' => 'أرز كارنارولي مع الفطر والبارميزان.'], 'p' => 44, 'img' => 'risotto', 'kcal' => 560, 'pro' => 14, 'carb' => 72, 'fat' => 22, 'veg' => true, 'gf' => true, 'alg' => [1], 'rate' => 4.5, 'rc' => 22],
                ['n' => ['en' => 'Prawns, Garlic, Bread', 'ar' => 'روبيان بالثوم'], 'd' => ['en' => 'Sizzling prawns, garlic, chili, warm bread.', 'ar' => 'روبيان بالثوم والفلفل مع خبز دافئ.'], 'p' => 54, 'img' => 'prawns-garlic', 'kcal' => 440, 'pro' => 32, 'carb' => 12, 'fat' => 28, 'spice' => 1, 'gf' => true, 'alg' => [5, 9], 'ext' => ['bread'], 'rate' => 4.6, 'rc' => 25],
            ],
        ],
        [
            'n' => ['en' => 'Desserts', 'ar' => 'حلويات'], 'img' => 'pannacotta',
            'items' => [
                ['n' => ['en' => 'Panna Cotta, Strawberry', 'ar' => 'بانا كوتا الفراولة'], 'd' => ['en' => 'Vanilla-bean set cream, macerated berries.', 'ar' => 'كريمة الفانيليا مع التوت المنقوع.'], 'p' => 26, 'img' => 'pannacotta', 'kcal' => 320, 'pro' => 6, 'carb' => 34, 'fat' => 18, 'veg' => true, 'gf' => true, 'alg' => [1], 'pop' => true, 'rate' => 4.6, 'rc' => 21],
                ['n' => ['en' => 'Basque Cheesecake', 'ar' => 'تشيز كيك الباسك'], 'd' => ['en' => 'Caramelised top, molten centre.', 'ar' => 'سطح مكرمل وقلب طري.'], 'p' => 28, 'img' => 'cheesecake', 'kcal' => 520, 'pro' => 9, 'carb' => 46, 'fat' => 34, 'veg' => true, 'alg' => [1, 2, 7], 'rate' => 4.5, 'rc' => 18],
                ['n' => ['en' => 'Fruit, Composed', 'ar' => 'فواكه مرتبة'], 'd' => ['en' => 'Carved seasonal fruit, mint, citrus dust.', 'ar' => 'فواكه موسمية مع النعناع وغبار الحمضيات.'], 'p' => 24, 'img' => 'fruit-platter', 'kcal' => 180, 'pro' => 2, 'carb' => 44, 'fat' => 1, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.3, 'rc' => 12],
            ],
        ],
        [
            'n' => ['en' => 'Drinks', 'ar' => 'مشروبات'], 'img' => 'juice-green',
            'items' => [
                ['n' => ['en' => 'Green Press', 'ar' => 'عصير أخضر'], 'd' => ['en' => 'Kiwi, apple, spinach, ginger.', 'ar' => 'كيوي وتفاح وسبانخ وزنجبيل.'], 'p' => 18, 'img' => 'juice-green', 'kcal' => 140, 'pro' => 2, 'carb' => 32, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.3, 'rc' => 14],
                ['n' => ['en' => 'Citrus Press', 'ar' => 'عصير برتقال'], 'd' => ['en' => 'Squeezed to order.', 'ar' => 'يعصر عند الطلب.'], 'p' => 15, 'img' => 'juice-orange', 'kcal' => 120, 'pro' => 2, 'carb' => 28, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.3, 'rc' => 12],
                ['n' => ['en' => 'Flat White', 'ar' => 'فلات وايت'], 'd' => ['en' => 'Double ristretto, velvet foam.', 'ar' => 'ريستريتو مزدوج مع رغوة ناعمة.'], 'p' => 16, 'img' => 'coffee-latte', 'kcal' => 120, 'pro' => 6, 'carb' => 10, 'fat' => 6, 'veg' => true, 'gf' => true, 'alg' => [1], 'rate' => 4.4, 'rc' => 16],
                ['n' => ['en' => 'Sparkling Water', 'ar' => 'مياه غازية'], 'd' => ['en' => 'Chilled bottle.', 'ar' => 'قارورة باردة.'], 'p' => 8, 'img' => 'water-sparkling', 'kcal' => 0, 'pro' => 0, 'carb' => 0, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.0, 'rc' => 7],
            ],
        ],
    ],
    'reviews' => [
        ['name' => 'Sophia', 'stars' => 5, 'body' => ['en' => 'Every plate looks like a magazine cover and tastes better.', 'ar' => 'كل طبق يبدو كغلاف مجلة وطعمه أفضل.']],
        ['name' => 'Majed', 'stars' => 4, 'body' => ['en' => 'Grain bowl with salmon — clean and satisfying.', 'ar' => 'طبق الحبوب مع السلمون — نظيف ومشبع.']],
        ['name' => 'Chloe', 'stars' => 5, 'body' => ['en' => 'Rebuilt caesar deserves its own fan club.', 'ar' => 'السيزر المعاد بناؤها تستحق نادي معجبين.']],
        ['name' => 'Ibrahim', 'stars' => 4, 'body' => ['en' => 'Minimal room, maximal flavour.', 'ar' => 'مكان بسيط ونكهة قصوى.']],
        ['name' => 'Emma', 'stars' => 5, 'body' => ['en' => 'Panna cotta wobble was scientifically perfect.', 'ar' => 'اهتزاز البانا كوتا مثالي علمياً.']],
    ],
];
