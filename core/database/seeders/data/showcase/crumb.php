<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/** Crumb Bakehouse — artisan bakery showcase menu. Same shape as crave.php. */
return [
    'store' => [
        'title' => 'Crumb Bakehouse', 'subtitle' => 'Artisan bakery · slow ferment · morning queue',
        'description' => 'Stone-milled flour, overnight ferments and a display case that sells out: croissants, cakes and proper coffee.',
        'color' => '#8a5a2b', 'logo' => 'croissant', 'cover' => 'bread-basket',
        'banners' => ['croissant', 'cake-chocolate', 'crepes-honey'],
        'coupons' => [['code' => 'CRUMB10', 'pct' => 10]],
        // Items pinned onto Happy Hour on top of the auto picks (by en name).
        'happy_hour_extra' => ['Baked Cheesecake'],
    ],
    'groups' => [
        'warm'  => ['t' => ['en' => 'Serve', 'ar' => 'التقديم'], 'opts' => [['Warm', 'دافئ', 0], ['As is', 'كما هو', 0]], 'required' => true],
        'slice' => ['t' => ['en' => 'Portion', 'ar' => 'الحصة'], 'opts' => [['Slice', 'قطعة', 0], ['Half cake', 'نصف كيكة', 38], ['Whole cake', 'كيكة كاملة', 82]], 'required' => true],
        'milk'  => ['t' => ['en' => 'Milk', 'ar' => 'الحليب'], 'opts' => [['Fresh', 'طازج', 0], ['Oat', 'شوفان', 3]]],
    ],
    'extras' => [
        'butter' => ['t' => ['en' => 'Cultured butter', 'ar' => 'زبدة مخمرة'], 'p' => 4, 'img' => 'bread-basket'],
        'honey'  => ['t' => ['en' => 'Mountain honey', 'ar' => 'عسل جبلي'], 'p' => 4, 'img' => 'crepes-honey'],
        'jam'    => ['t' => ['en' => 'Berry jam', 'ar' => 'مربى التوت'], 'p' => 4, 'img' => 'berry-pie'],
        'scoop'  => ['t' => ['en' => 'Vanilla scoop', 'ar' => 'كرة فانيليا'], 'p' => 6, 'img' => 'icecream-scoop'],
    ],
    'categories' => [
        [
            'n' => ['en' => 'Morning Bakes', 'ar' => 'مخبوزات الصباح'], 'img' => 'croissant',
            'items' => [
                ['n' => ['en' => 'All-Butter Croissant', 'ar' => 'كرواسون بالزبدة'], 'd' => ['en' => '27 layers, folded over three days.', 'ar' => '٢٧ طبقة تطوى على مدى ثلاثة أيام.'], 'p' => 13, 'img' => 'croissant', 'gal' => ['cinnamon-roll', 'bread-basket'], 'kcal' => 280, 'pro' => 6, 'carb' => 30, 'fat' => 16, 'veg' => true, 'alg' => [1, 2, 7, 9], 'grp' => ['warm'], 'ext' => ['butter', 'jam'], 'pop' => true, 'spec' => true, 'rate' => 4.8, 'rc' => 89],
                ['n' => ['en' => 'Cinnamon Knot', 'ar' => 'عقدة القرفة'], 'd' => ['en' => 'Cardamom-cinnamon knot, pearl sugar.', 'ar' => 'عقدة القرفة والهيل مع سكر اللؤلؤ.'], 'p' => 16, 'img' => 'cinnamon-roll', 'kcal' => 420, 'pro' => 7, 'carb' => 62, 'fat' => 18, 'veg' => true, 'alg' => [1, 2, 7], 'grp' => ['warm'], 'pop' => true, 'rate' => 4.7, 'rc' => 61],
                ['n' => ['en' => 'Blueberry Crumb Muffin', 'ar' => 'مافن التوت'], 'd' => ['en' => 'Domed muffin, streusel top.', 'ar' => 'مافن بحشوة التوت وفتات الستروسل.'], 'p' => 13, 'img' => 'muffin', 'kcal' => 340, 'pro' => 5, 'carb' => 48, 'fat' => 14, 'veg' => true, 'alg' => [1, 2, 7], 'rate' => 4.4, 'rc' => 27],
                ['n' => ['en' => 'House Bread Basket', 'ar' => 'سلة خبز البيت'], 'd' => ['en' => 'Sourdough rolls, two whipped butters.', 'ar' => 'خبز محمص مع زبدتين مخفوقتين.'], 'p' => 18, 'img' => 'bread-basket', 'kcal' => 420, 'pro' => 10, 'carb' => 56, 'fat' => 18, 'veg' => true, 'alg' => [1, 7, 9], 'ext' => ['butter', 'honey'], 'rate' => 4.5, 'rc' => 24],
                ['n' => ['en' => 'Breakfast Egg Toast', 'ar' => 'توست البيض'], 'd' => ['en' => 'Egg-in-a-hole brioche, grilled tomato.', 'ar' => 'بيض داخل البريوش مع طماطم مشوية.'], 'p' => 22, 'img' => 'breakfast-spread', 'kcal' => 380, 'pro' => 16, 'carb' => 32, 'fat' => 20, 'veg' => true, 'alg' => [1, 2, 7], 'rate' => 4.3, 'rc' => 18],
                ['n' => ['en' => 'Avocado Sourdough', 'ar' => 'أفوكادو على خبز محمص'], 'd' => ['en' => 'Smashed avocado, soft egg, dukkah.', 'ar' => 'أفوكادو مهروس مع بيض ودقة.'], 'p' => 28, 'img' => 'avocado-toast', 'kcal' => 420, 'pro' => 16, 'carb' => 38, 'fat' => 24, 'veg' => true, 'alg' => [2, 4, 7], 'rate' => 4.5, 'rc' => 22],
            ],
        ],
        [
            'n' => ['en' => 'Cake Case', 'ar' => 'خزانة الكيك'], 'img' => 'cake-chocolate',
            'items' => [
                ['n' => ['en' => 'Chocolate Fudge Cake', 'ar' => 'كيك الشوكولاتة'], 'd' => ['en' => 'Four dark layers, ganache drip.', 'ar' => 'أربع طبقات داكنة مع الغاناش.'], 'p' => 26, 'img' => 'cake-chocolate', 'kcal' => 540, 'pro' => 7, 'carb' => 66, 'fat' => 28, 'veg' => true, 'alg' => [1, 2, 4, 7], 'grp' => ['slice'], 'pop' => true, 'rate' => 4.7, 'rc' => 52],
                ['n' => ['en' => 'Red Velvet', 'ar' => 'رد فلفت'], 'd' => ['en' => 'Cream-cheese frosting, fresh strawberry.', 'ar' => 'كريمة الجبن مع الفراولة الطازجة.'], 'p' => 24, 'img' => 'cake-redvelvet', 'kcal' => 480, 'pro' => 6, 'carb' => 58, 'fat' => 26, 'veg' => true, 'alg' => [1, 2, 7], 'grp' => ['slice'], 'rate' => 4.6, 'rc' => 41],
                ['n' => ['en' => 'Baked Cheesecake', 'ar' => 'تشيز كيك'], 'd' => ['en' => 'New York style, blueberry compote.', 'ar' => 'على طريقة نيويورك مع كومبوت التوت.'], 'p' => 26, 'img' => 'cheesecake', 'kcal' => 520, 'pro' => 9, 'carb' => 48, 'fat' => 32, 'veg' => true, 'alg' => [1, 2, 7], 'grp' => ['slice'], 'rate' => 4.6, 'rc' => 38],
                ['n' => ['en' => 'Tiramisu Tray', 'ar' => 'تيراميسو'], 'd' => ['en' => 'Espresso-soaked layers, mascarpone.', 'ar' => 'طبقات الإسبريسو والماسكاربوني.'], 'p' => 24, 'img' => 'tiramisu', 'kcal' => 440, 'pro' => 8, 'carb' => 44, 'fat' => 26, 'veg' => true, 'alg' => [1, 2, 7], 'rate' => 4.6, 'rc' => 33],
                ['n' => ['en' => 'Rose Cupcakes (2)', 'ar' => 'كب كيك الورد (٢)'], 'd' => ['en' => 'Rose sponge, pistachio crumb, cream swirl.', 'ar' => 'كيك بماء الورد وفتات الفستق والكريمة.'], 'p' => 18, 'img' => 'cupcakes', 'kcal' => 380, 'pro' => 5, 'carb' => 52, 'fat' => 18, 'veg' => true, 'alg' => [1, 2, 4, 7], 'rate' => 4.3, 'rc' => 17],
            ],
        ],
        [
            'n' => ['en' => 'Counter Sweets', 'ar' => 'حلويات الكاونتر'], 'img' => 'cookie',
            'items' => [
                ['n' => ['en' => 'Chunky Cookies (3)', 'ar' => 'كوكيز (٣)'], 'd' => ['en' => 'Chewy dark-chocolate chunk, sea salt.', 'ar' => 'كوكيز طرية بالشوكولاتة وملح البحر.'], 'p' => 14, 'img' => 'cookie', 'kcal' => 380, 'pro' => 5, 'carb' => 50, 'fat' => 18, 'veg' => true, 'alg' => [1, 2, 6, 7], 'grp' => ['warm'], 'pop' => true, 'rate' => 4.6, 'rc' => 44],
                ['n' => ['en' => 'Fudge Brownie', 'ar' => 'براوني'], 'd' => ['en' => 'Dense cocoa, molten pockets.', 'ar' => 'براوني كثيف بجيوب الشوكولاتة.'], 'p' => 16, 'img' => 'brownie', 'kcal' => 420, 'pro' => 6, 'carb' => 52, 'fat' => 22, 'veg' => true, 'alg' => [1, 2, 4, 7], 'grp' => ['warm'], 'ext' => ['scoop'], 'rate' => 4.5, 'rc' => 31],
                ['n' => ['en' => 'Glazed Donuts (2)', 'ar' => 'دونات (٢)'], 'd' => ['en' => 'Brioche rings, chocolate drizzle.', 'ar' => 'دونات البريوش مع الشوكولاتة.'], 'p' => 15, 'img' => 'donut', 'kcal' => 420, 'pro' => 5, 'carb' => 58, 'fat' => 20, 'veg' => true, 'alg' => [1, 2, 6, 7], 'rate' => 4.4, 'rc' => 25],
                ['n' => ['en' => 'Berry Lattice Pie', 'ar' => 'فطيرة التوت'], 'd' => ['en' => 'Buttery lattice, mixed-berry filling.', 'ar' => 'عجينة الزبدة مع حشوة التوت.'], 'p' => 22, 'img' => 'berry-pie', 'kcal' => 400, 'pro' => 5, 'carb' => 56, 'fat' => 18, 'veg' => true, 'alg' => [1, 7, 9], 'ext' => ['scoop'], 'rate' => 4.5, 'rc' => 23],
                ['n' => ['en' => 'Cinnamon Churros', 'ar' => 'تشوروز'], 'd' => ['en' => 'Crisp churros, chocolate pot.', 'ar' => 'تشوروز مقرمشة مع صوص الشوكولاتة.'], 'p' => 18, 'img' => 'churros', 'kcal' => 440, 'pro' => 6, 'carb' => 56, 'fat' => 22, 'veg' => true, 'alg' => [1, 7], 'rate' => 4.4, 'rc' => 19],
                ['n' => ['en' => 'Honey Cheese Crepes', 'ar' => 'كريب بالجبن والعسل'], 'd' => ['en' => 'Warm crepes, sweet cheese, honey.', 'ar' => 'كريب دافئ مع الجبن الحلو والعسل.'], 'p' => 22, 'img' => 'crepes-honey', 'kcal' => 460, 'pro' => 12, 'carb' => 56, 'fat' => 20, 'veg' => true, 'alg' => [1, 2, 7], 'ext' => ['honey'], 'rate' => 4.5, 'rc' => 21],
                ['n' => ['en' => 'Banana Crepes', 'ar' => 'كريب الموز'], 'd' => ['en' => 'Thin crepes, banana, chocolate drizzle.', 'ar' => 'كريب رقيق مع الموز والشوكولاتة.'], 'p' => 20, 'img' => 'crepes-banana', 'kcal' => 420, 'pro' => 8, 'carb' => 60, 'fat' => 16, 'veg' => true, 'alg' => [1, 2, 7], 'rate' => 4.3, 'rc' => 16],
                ['n' => ['en' => 'Strawberry Panna Cotta', 'ar' => 'بانا كوتا'], 'd' => ['en' => 'Vanilla set cream, strawberries.', 'ar' => 'كريمة الفانيليا مع الفراولة.'], 'p' => 18, 'img' => 'pannacotta', 'kcal' => 320, 'pro' => 6, 'carb' => 34, 'fat' => 18, 'veg' => true, 'gf' => true, 'alg' => [1], 'rate' => 4.3, 'rc' => 14],
            ],
        ],
        [
            'n' => ['en' => 'Coffee & Fresh', 'ar' => 'قهوة وعصائر'], 'img' => 'coffee-cappuccino',
            'items' => [
                ['n' => ['en' => 'Bakehouse Cappuccino', 'ar' => 'كابتشينو المخبز'], 'd' => ['en' => 'Classic thirds, local roaster beans.', 'ar' => 'كابتشينو كلاسيكي ببن محلي.'], 'p' => 16, 'img' => 'coffee-cappuccino', 'kcal' => 130, 'pro' => 6, 'carb' => 12, 'fat' => 6, 'veg' => true, 'gf' => true, 'alg' => [1], 'grp' => ['milk'], 'pop' => true, 'rate' => 4.5, 'rc' => 34],
                ['n' => ['en' => 'Flat White', 'ar' => 'فلات وايت'], 'd' => ['en' => 'Double ristretto, velvet foam.', 'ar' => 'ريستريتو مزدوج مع رغوة ناعمة.'], 'p' => 16, 'img' => 'coffee-latte', 'kcal' => 120, 'pro' => 6, 'carb' => 10, 'fat' => 6, 'veg' => true, 'gf' => true, 'alg' => [1], 'grp' => ['milk'], 'rate' => 4.5, 'rc' => 28],
                ['n' => ['en' => 'Espresso', 'ar' => 'إسبريسو'], 'd' => ['en' => 'Double shot, 25-second pull.', 'ar' => 'جرعة مزدوجة مثالية.'], 'p' => 12, 'img' => 'coffee-espresso', 'kcal' => 10, 'pro' => 0, 'carb' => 2, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.5, 'rc' => 22],
                ['n' => ['en' => 'Fresh Orange Juice', 'ar' => 'عصير برتقال'], 'd' => ['en' => 'Squeezed to order.', 'ar' => 'يعصر عند الطلب.'], 'p' => 14, 'img' => 'juice-orange', 'kcal' => 120, 'pro' => 2, 'carb' => 28, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.3, 'rc' => 15],
                ['n' => ['en' => 'Tea & Biscuits', 'ar' => 'شاي وبسكويت'], 'd' => ['en' => 'Milky tea, two butter biscuits.', 'ar' => 'شاي بالحليب مع بسكويت الزبدة.'], 'p' => 12, 'img' => 'tea-cookies', 'kcal' => 220, 'pro' => 5, 'carb' => 32, 'fat' => 8, 'veg' => true, 'alg' => [1, 7, 9], 'rate' => 4.2, 'rc' => 12],
            ],
        ],
    ],
    'reviews' => [
        ['name' => 'Claire', 'stars' => 5, 'body' => ['en' => 'That croissant crackle. Worth the morning queue.', 'ar' => 'قرمشة الكرواسون تستحق طابور الصباح.']],
        ['name' => 'Sultan', 'stars' => 5, 'body' => ['en' => 'Cinnamon knot warm from the oven — order two.', 'ar' => 'عقدة القرفة دافئة من الفرن — اطلب اثنتين.']],
        ['name' => 'Mona', 'stars' => 4, 'body' => ['en' => 'Cake case is dangerous. Red velvet wins.', 'ar' => 'خزانة الكيك خطيرة والرد فلفت الفائز.']],
        ['name' => 'Theo', 'stars' => 4, 'body' => ['en' => 'Proper flat white to go with proper bread.', 'ar' => 'فلات وايت ممتاز مع خبز ممتاز.']],
        ['name' => 'Lena', 'stars' => 5, 'body' => ['en' => 'Everything sells out by noon. Go early, thank me later.', 'ar' => 'كل شيء ينفد قبل الظهر — بكّر واشكرني لاحقاً.']],
    ],
];
