<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/** Aurora Bistro — Nordic bistro showcase menu. Same shape as crave.php. */
return [
    'store' => [
        'title' => 'Aurora Bistro', 'subtitle' => 'Nordic bistro · seasonal plates · slow comfort',
        'description' => 'A light-filled Nordic bistro: cured fish, root vegetables, meatballs and cinnamon buns done properly.',
        'color' => '#2b5fa6', 'logo' => 'salmon-pan', 'cover' => 'restaurant-interior',
        'banners' => ['salmon-grilled', 'kofta', 'cinnamon-roll'],
        'coupons' => [['code' => 'AURORA10', 'pct' => 10]],
    ],
    'groups' => [
        'bread'  => ['t' => ['en' => 'Bread', 'ar' => 'الخبز'], 'opts' => [['Rye', 'جاودار', 0], ['Sourdough', 'محمص', 0], ['Gluten-free', 'خالٍ من الغلوتين', 4]]],
        'side'   => ['t' => ['en' => 'Side', 'ar' => 'الطبق الجانبي'], 'opts' => [['Mash', 'بطاطس مهروسة', 0], ['Roast roots', 'خضار جذرية', 0], ['Green salad', 'سلطة خضراء', 0]], 'required' => true],
        'portion' => ['t' => ['en' => 'Portion', 'ar' => 'الحجم'], 'opts' => [['Standard', 'عادي', 0], ['Large', 'كبير', 12]], 'required' => true],
    ],
    'extras' => [
        'lingon'  => ['t' => ['en' => 'Lingonberry jam', 'ar' => 'مربى التوت البري'], 'p' => 4, 'img' => 'berry-pie'],
        'rye'     => ['t' => ['en' => 'Extra rye bread', 'ar' => 'خبز جاودار إضافي'], 'p' => 4, 'img' => 'bread-basket'],
        'egg'     => ['t' => ['en' => 'Soft egg', 'ar' => 'بيضة'], 'p' => 4, 'img' => 'eggs-benedict'],
        'cheese'  => ['t' => ['en' => 'Aged cheese shavings', 'ar' => 'جبن معتق'], 'p' => 6, 'img' => 'cheese-platter'],
        'greens'  => ['t' => ['en' => 'Buttered greens', 'ar' => 'خضار بالزبدة'], 'p' => 9, 'img' => 'veg-greens'],
    ],
    'categories' => [
        [
            'n' => ['en' => 'To Start', 'ar' => 'للبداية'], 'img' => 'bruschetta',
            'items' => [
                ['n' => ['en' => 'Rye Toasts & Tomato', 'ar' => 'توست الجاودار'], 'd' => ['en' => 'Charred rye, marinated tomato, dill oil.', 'ar' => 'خبز جاودار محمص مع طماطم متبلة وزيت الشبت.'], 'p' => 22, 'img' => 'bruschetta', 'kcal' => 320, 'pro' => 8, 'carb' => 42, 'fat' => 14, 'veg' => true, 'vegan' => true, 'alg' => [7], 'grp' => ['bread'], 'rate' => 4.4, 'rc' => 26],
                ['n' => ['en' => 'Pumpkin Velouté', 'ar' => 'شوربة اليقطين'], 'd' => ['en' => 'Silky roast pumpkin, brown-butter seeds.', 'ar' => 'يقطين محمص مع بذور الزبدة البنية.'], 'p' => 24, 'img' => 'pumpkin-soup', 'kcal' => 240, 'pro' => 5, 'carb' => 30, 'fat' => 12, 'veg' => true, 'gf' => true, 'alg' => [1, 9], 'rate' => 4.5, 'rc' => 22],
                ['n' => ['en' => 'Nettle & Pea Soup', 'ar' => 'شوربة البازلاء الخضراء'], 'd' => ['en' => 'Bright green soup, crème fraîche swirl.', 'ar' => 'شوربة خضراء مع لمسة الكريمة.'], 'p' => 22, 'img' => 'soup-green', 'kcal' => 220, 'pro' => 7, 'carb' => 22, 'fat' => 14, 'veg' => true, 'gf' => true, 'alg' => [1], 'rate' => 4.2, 'rc' => 14],
                ['n' => ['en' => 'Bread Board & Butters', 'ar' => 'لوح الخبز والزبدة'], 'd' => ['en' => 'House rolls, two whipped butters.', 'ar' => 'خبز البيت مع زبدتين مخفوقتين.'], 'p' => 18, 'img' => 'bread-basket', 'kcal' => 420, 'pro' => 10, 'carb' => 56, 'fat' => 18, 'veg' => true, 'alg' => [1, 7, 9], 'rate' => 4.3, 'rc' => 19],
                ['n' => ['en' => 'Nordic Cheese Plate', 'ar' => 'طبق الأجبان'], 'd' => ['en' => 'Aged blue, crispbread, fig.', 'ar' => 'جبن أزرق معتق مع خبز مقرمش وتين.'], 'p' => 38, 'img' => 'cheese-platter', 'kcal' => 580, 'pro' => 24, 'carb' => 22, 'fat' => 44, 'veg' => true, 'alg' => [1, 4, 7], 'rate' => 4.5, 'rc' => 17],
            ],
        ],
        [
            'n' => ['en' => 'Mains', 'ar' => 'الأطباق الرئيسية'], 'img' => 'salmon-pan',
            'items' => [
                ['n' => ['en' => 'Meatballs & Lingon', 'ar' => 'كرات اللحم الشمالية'], 'd' => ['en' => 'Classic bistro meatballs, cream sauce, lingonberry.', 'ar' => 'كرات لحم كلاسيكية مع صوص الكريمة والتوت البري.'], 'p' => 48, 'img' => 'kofta', 'kcal' => 640, 'pro' => 38, 'carb' => 36, 'fat' => 38, 'alg' => [1, 7], 'grp' => ['side', 'portion'], 'ext' => ['lingon', 'rye'], 'pop' => true, 'spec' => true, 'rate' => 4.8, 'rc' => 92],
                ['n' => ['en' => 'Pan-Seared Salmon', 'ar' => 'سلمون مقلي'], 'd' => ['en' => 'Crisp-skin salmon, dill butter, new potatoes.', 'ar' => 'سلمون بجلد مقرمش مع زبدة الشبت والبطاطس.'], 'p' => 66, 'img' => 'salmon-pan', 'gal' => ['salmon-grilled', 'fish-grilled-whole'], 'kcal' => 560, 'pro' => 42, 'carb' => 8, 'fat' => 38, 'gf' => true, 'alg' => [1, 3, 9], 'grp' => ['side'], 'pop' => true, 'rate' => 4.8, 'rc' => 78],
                ['n' => ['en' => 'Grilled Salmon Plate', 'ar' => 'طبق السلمون المشوي'], 'd' => ['en' => 'Char-grilled salmon over greens, lemon.', 'ar' => 'سلمون مشوي على الخضار مع الليمون.'], 'p' => 64, 'img' => 'salmon-grilled', 'kcal' => 540, 'pro' => 42, 'carb' => 10, 'fat' => 36, 'gf' => true, 'alg' => [3], 'rate' => 4.7, 'rc' => 54],
                ['n' => ['en' => 'Whole Baked Fish', 'ar' => 'سمكة مخبوزة'], 'd' => ['en' => 'Market fish, herbed barley, charred lemon.', 'ar' => 'سمكة السوق مع شعير بالأعشاب وليمون مشوي.'], 'p' => 70, 'img' => 'fish-grilled-whole', 'kcal' => 620, 'pro' => 48, 'carb' => 44, 'fat' => 26, 'alg' => [3, 7], 'rate' => 4.6, 'rc' => 31],
                ['n' => ['en' => 'Crispy Fish & Chips', 'ar' => 'سمك وبطاطس'], 'd' => ['en' => 'Battered fillet, thick chips, dill tartare.', 'ar' => 'فيليه مقرمش مع بطاطس وصوص التارتار بالشبت.'], 'p' => 42, 'img' => 'fish-chips', 'kcal' => 720, 'pro' => 34, 'carb' => 66, 'fat' => 36, 'alg' => [2, 3, 7], 'rate' => 4.4, 'rc' => 37],
                ['n' => ['en' => 'Skillet Roast Chicken', 'ar' => 'دجاج بالمقلاة'], 'd' => ['en' => 'Half bird, rosemary potatoes, pan jus.', 'ar' => 'نصف دجاجة مع بطاطس إكليل الجبل ومرق المقلاة.'], 'p' => 52, 'img' => 'chicken-skillet', 'kcal' => 720, 'pro' => 54, 'carb' => 38, 'fat' => 40, 'gf' => true, 'alg' => [], 'grp' => ['side'], 'rate' => 4.6, 'rc' => 42],
                ['n' => ['en' => 'Pearl Barley Risotto', 'ar' => 'ريزوتو الشعير'], 'd' => ['en' => 'Creamy barley, mushrooms, aged cheese.', 'ar' => 'شعير كريمي مع الفطر والجبن المعتق.'], 'p' => 40, 'img' => 'risotto', 'kcal' => 560, 'pro' => 14, 'carb' => 72, 'fat' => 22, 'veg' => true, 'alg' => [1, 7], 'ext' => ['cheese', 'greens'], 'rate' => 4.5, 'rc' => 25],
            ],
        ],
        [
            'n' => ['en' => 'Green & Grain', 'ar' => 'سلطات وحبوب'], 'img' => 'bowl-grain',
            'items' => [
                ['n' => ['en' => 'Harvest Grain Bowl', 'ar' => 'طبق الحبوب'], 'd' => ['en' => 'Barley, roast roots, seeds, buttermilk dressing.', 'ar' => 'شعير مع خضار جذرية وبذور وصوص اللبن.'], 'p' => 34, 'img' => 'bowl-grain', 'kcal' => 440, 'pro' => 14, 'carb' => 56, 'fat' => 18, 'veg' => true, 'alg' => [1, 4, 7], 'rate' => 4.4, 'rc' => 21],
                ['n' => ['en' => 'Kale & Rye Crunch', 'ar' => 'سلطة الكيل'], 'd' => ['en' => 'Massaged kale, rye croutons, lemon dressing.', 'ar' => 'كرنب أخضر مع خبز الجاودار المحمص وصوص الليمون.'], 'p' => 28, 'img' => 'salad-greek', 'kcal' => 300, 'pro' => 12, 'carb' => 30, 'fat' => 16, 'veg' => true, 'alg' => [7], 'rate' => 4.2, 'rc' => 13],
                ['n' => ['en' => 'Buttered Greens', 'ar' => 'خضار بالزبدة'], 'd' => ['en' => 'Broccolini, brown butter, toasted almonds.', 'ar' => 'بروكليني مع الزبدة البنية واللوز المحمص.'], 'p' => 22, 'img' => 'veg-greens', 'kcal' => 180, 'pro' => 7, 'carb' => 12, 'fat' => 12, 'veg' => true, 'gf' => true, 'alg' => [1, 4, 9], 'rate' => 4.1, 'rc' => 10],
            ],
        ],
        [
            'n' => ['en' => 'Fika & Desserts', 'ar' => 'حلويات وفيكا'], 'img' => 'cinnamon-roll',
            'items' => [
                ['n' => ['en' => 'Kanelbulle', 'ar' => 'رول القرفة السويدي'], 'd' => ['en' => 'Cardamom-cinnamon bun, pearl sugar.', 'ar' => 'رول القرفة والهيل مع سكر اللؤلؤ.'], 'p' => 16, 'img' => 'cinnamon-roll', 'kcal' => 420, 'pro' => 7, 'carb' => 62, 'fat' => 18, 'veg' => true, 'alg' => [1, 2, 7], 'pop' => true, 'spec' => true, 'rate' => 4.8, 'rc' => 58],
                ['n' => ['en' => 'Berry Lattice Pie', 'ar' => 'فطيرة التوت'], 'd' => ['en' => 'Forest-berry filling, buttery lattice.', 'ar' => 'حشوة توت الغابة مع عجينة الزبدة.'], 'p' => 24, 'img' => 'berry-pie', 'kcal' => 400, 'pro' => 5, 'carb' => 56, 'fat' => 18, 'veg' => true, 'alg' => [1, 7, 9], 'rate' => 4.5, 'rc' => 27],
                ['n' => ['en' => 'Vanilla Panna Cotta', 'ar' => 'بانا كوتا الفانيليا'], 'd' => ['en' => 'Set cream, macerated berries.', 'ar' => 'كريمة الفانيليا مع التوت المنقوع.'], 'p' => 20, 'img' => 'pannacotta', 'kcal' => 320, 'pro' => 6, 'carb' => 34, 'fat' => 18, 'veg' => true, 'gf' => true, 'alg' => [1], 'rate' => 4.4, 'rc' => 18],
                ['n' => ['en' => 'Waffles & Berries', 'ar' => 'وافل بالتوت'], 'd' => ['en' => 'Heart waffles, berry compote, cream.', 'ar' => 'وافل على شكل قلوب مع كومبوت التوت.'], 'p' => 24, 'img' => 'waffles', 'kcal' => 560, 'pro' => 12, 'carb' => 82, 'fat' => 22, 'veg' => true, 'alg' => [1, 2, 7, 9], 'rate' => 4.4, 'rc' => 20],
                ['n' => ['en' => 'Baked Cheesecake', 'ar' => 'تشيز كيك'], 'd' => ['en' => 'Blueberry compote, oat crumb.', 'ar' => 'تشيز كيك مع كومبوت التوت وفتات الشوفان.'], 'p' => 24, 'img' => 'cheesecake', 'kcal' => 520, 'pro' => 9, 'carb' => 48, 'fat' => 32, 'veg' => true, 'alg' => [1, 2, 7], 'rate' => 4.5, 'rc' => 23],
            ],
        ],
        [
            'n' => ['en' => 'Drinks', 'ar' => 'مشروبات'], 'img' => 'coffee-frenchpress',
            'items' => [
                ['n' => ['en' => 'Filter Coffee Pot', 'ar' => 'قهوة مقطرة'], 'd' => ['en' => 'Bottomless bistro filter pot.', 'ar' => 'إبريق قهوة مقطرة على طريقة البيسترو.'], 'p' => 18, 'img' => 'coffee-frenchpress', 'kcal' => 15, 'pro' => 1, 'carb' => 3, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.4, 'rc' => 20],
                ['n' => ['en' => 'Herbal Tea Pot', 'ar' => 'إبريق شاي أعشاب'], 'd' => ['en' => 'Chamomile-mint pot for two.', 'ar' => 'بابونج ونعناع لشخصين.'], 'p' => 16, 'img' => 'tea-pot', 'kcal' => 5, 'pro' => 0, 'carb' => 1, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.2, 'rc' => 12],
                ['n' => ['en' => 'Berry Fizz', 'ar' => 'فوار التوت'], 'd' => ['en' => 'Blackberry, lime, soda, dried orange.', 'ar' => 'توت أسود مع الليمون والصودا.'], 'p' => 20, 'img' => 'club-mocktail', 'kcal' => 140, 'pro' => 0, 'carb' => 34, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.3, 'rc' => 15],
                ['n' => ['en' => 'Cloudy Lemonade', 'ar' => 'ليموناضة'], 'd' => ['en' => 'Pressed lemon, iced jugs.', 'ar' => 'ليمون معصور مع الثلج.'], 'p' => 14, 'img' => 'lemonade', 'kcal' => 140, 'pro' => 0, 'carb' => 36, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.2, 'rc' => 13],
                ['n' => ['en' => 'Sparkling Water', 'ar' => 'مياه غازية'], 'd' => ['en' => 'Chilled bottle.', 'ar' => 'قارورة باردة.'], 'p' => 6, 'img' => 'water-sparkling', 'kcal' => 0, 'pro' => 0, 'carb' => 0, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.0, 'rc' => 7],
            ],
        ],
    ],
    'reviews' => [
        ['name' => 'Erik', 'stars' => 5, 'body' => ['en' => 'Meatballs taste like my grandmother made them.', 'ar' => 'كرات اللحم كأن جدتي أعدتها.']],
        ['name' => 'Hessa', 'stars' => 5, 'body' => ['en' => 'The kanelbulle with filter coffee is proper fika.', 'ar' => 'رول القرفة مع القهوة المقطرة فيكا حقيقية.']],
        ['name' => 'Tom', 'stars' => 4, 'body' => ['en' => 'Salmon crisp on the outside, butter-soft inside.', 'ar' => 'السلمون مقرمش من الخارج وطري كالزبدة.']],
        ['name' => 'Amira', 'stars' => 4, 'body' => ['en' => 'Calm, bright room. Barley risotto is a sleeper hit.', 'ar' => 'مكان هادئ ومضيء وريزوتو الشعير مفاجأة رائعة.']],
        ['name' => 'Lukas', 'stars' => 5, 'body' => ['en' => 'Berry pie like a Scandinavian summer.', 'ar' => 'فطيرة التوت مثل صيف اسكندنافي.']],
    ],
];
