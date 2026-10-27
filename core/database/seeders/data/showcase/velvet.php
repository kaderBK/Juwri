<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/** Velvet Lounge — fine dining showcase menu. Same shape as crave.php. */
return [
    'store' => [
        'title' => 'Velvet Lounge', 'subtitle' => 'Fine dining · tasting plates · velvet-dark rooms',
        'description' => 'An intimate fine-dining room: composed plates, table-side finishing and a short menu that changes with the market.',
        'color' => '#5b2a86', 'logo' => 'steak-plate-fine', 'cover' => 'restaurant-interior',
        'banners' => ['steak-plate-fine', 'truffle-pasta', 'lava-cake'],
        'coupons' => [['code' => 'VELVET10', 'pct' => 10]],
    ],
    'groups' => [
        'course'   => ['t' => ['en' => 'Course size', 'ar' => 'حجم الطبق'], 'opts' => [['Tasting', 'تذوق', 0], ['Full', 'كامل', 22]], 'required' => true],
        'doneness' => ['t' => ['en' => 'Doneness', 'ar' => 'درجة النضج'], 'opts' => [['Medium rare', 'متوسط النيء', 0], ['Medium', 'متوسط', 0], ['Well done', 'ناضج', 0]], 'required' => true],
        'pairing'  => ['t' => ['en' => 'Zero-proof pairing', 'ar' => 'مشروب مرافق'], 'opts' => [['None', 'بدون', 0], ['Grape sparkler', 'عنب فوار', 18], ['Citrus spritz', 'سبريتز حمضيات', 16]]],
    ],
    'extras' => [
        'truffle' => ['t' => ['en' => 'Shaved truffle', 'ar' => 'ترافل مبشور'], 'p' => 28, 'img' => 'truffle-pasta'],
        'bread'   => ['t' => ['en' => 'Sourdough & cultured butter', 'ar' => 'خبز وزبدة مخمرة'], 'p' => 12, 'img' => 'bread-basket'],
        'cheese'  => ['t' => ['en' => 'Cheese course', 'ar' => 'طبق أجبان'], 'p' => 32, 'img' => 'cheese-platter'],
        'greens'  => ['t' => ['en' => 'Charred greens', 'ar' => 'خضار مشوية'], 'p' => 14, 'img' => 'veg-greens'],
    ],
    'categories' => [
        [
            'n' => ['en' => 'First Plates', 'ar' => 'الأطباق الأولى'], 'img' => 'salad-fig',
            'items' => [
                ['n' => ['en' => 'Amuse Crostini', 'ar' => 'كروستيني افتتاحي'], 'd' => ['en' => 'Charred sourdough, marinated tomato, basil oil.', 'ar' => 'خبز محمص مع طماطم متبلة وزيت الريحان.'], 'p' => 28, 'img' => 'bruschetta', 'kcal' => 320, 'pro' => 8, 'carb' => 42, 'fat' => 14, 'veg' => true, 'vegan' => true, 'alg' => [7], 'rate' => 4.5, 'rc' => 22],
                ['n' => ['en' => 'Salmon Crudo', 'ar' => 'سلمون كرودو'], 'd' => ['en' => 'Sashimi-cut salmon, citrus oil, sea salt flakes.', 'ar' => 'شرائح سلمون نيئة مع زيت الحمضيات وملح البحر.'], 'p' => 48, 'img' => 'salmon-sashimi', 'kcal' => 260, 'pro' => 24, 'carb' => 2, 'fat' => 18, 'gf' => true, 'alg' => [3], 'pop' => true, 'rate' => 4.7, 'rc' => 36],
                ['n' => ['en' => 'Citrus-Cured Ceviche', 'ar' => 'سيفيتشي الحمضيات'], 'd' => ['en' => 'Lime-cured white fish, shaved onion, coriander flower.', 'ar' => 'سمك أبيض منقوع بالليمون مع البصل والكزبرة.'], 'p' => 46, 'img' => 'ceviche', 'kcal' => 240, 'pro' => 28, 'carb' => 12, 'fat' => 8, 'gf' => true, 'alg' => [3], 'rate' => 4.5, 'rc' => 21],
                ['n' => ['en' => 'Fig & Aged Cheese Salad', 'ar' => 'سلطة التين والجبن المعتق'], 'd' => ['en' => 'Ripe figs, pomegranate pearls, candied walnuts.', 'ar' => 'تين ناضج مع حبوب الرمان والجوز المحلى.'], 'p' => 42, 'img' => 'salad-fig', 'kcal' => 320, 'pro' => 6, 'carb' => 42, 'fat' => 14, 'veg' => true, 'gf' => true, 'alg' => [1, 4], 'spec' => true, 'rate' => 4.6, 'rc' => 19],
                ['n' => ['en' => 'Pumpkin Velouté', 'ar' => 'فيلوتيه اليقطين'], 'd' => ['en' => 'Silked roast pumpkin, brown-butter seeds, chili oil dot.', 'ar' => 'يقطين محمص ناعم مع بذور الزبدة البنية.'], 'p' => 32, 'img' => 'pumpkin-soup', 'kcal' => 240, 'pro' => 5, 'carb' => 30, 'fat' => 12, 'veg' => true, 'gf' => true, 'alg' => [1, 9], 'rate' => 4.4, 'rc' => 16],
                ['n' => ['en' => 'Chilled Prawns on Ice', 'ar' => 'روبيان على الثلج'], 'd' => ['en' => 'Poached tiger prawns, marie-rose, lemon.', 'ar' => 'روبيان مسلوق يقدم على الثلج مع الصوص.'], 'p' => 54, 'img' => 'prawns-raw', 'kcal' => 280, 'pro' => 32, 'carb' => 8, 'fat' => 12, 'gf' => true, 'alg' => [2, 5], 'rate' => 4.4, 'rc' => 15],
            ],
        ],
        [
            'n' => ['en' => 'Mains', 'ar' => 'الأطباق الرئيسية'], 'img' => 'steak-plate-fine',
            'items' => [
                ['n' => ['en' => 'Tenderloin, Fondant, Jus', 'ar' => 'تندرلوين مع البطاطس'], 'd' => ['en' => 'Centre-cut tenderloin, fondant potato, glossy jus.', 'ar' => 'تندرلوين مع بطاطس فوندان ومرق لامع.'], 'p' => 138, 'img' => 'steak-plate-fine', 'gal' => ['steak-sliced', 'steak-grill'], 'kcal' => 680, 'pro' => 48, 'carb' => 30, 'fat' => 40, 'gf' => true, 'alg' => [1, 9], 'grp' => ['doneness', 'course', 'pairing'], 'ext' => ['truffle', 'greens'], 'pop' => true, 'spec' => true, 'rate' => 4.9, 'rc' => 64],
                ['n' => ['en' => 'Salmon Beurre Blanc', 'ar' => 'سلمون بصوص الزبدة'], 'd' => ['en' => 'Seared salmon, apple-celery salsa, beurre blanc.', 'ar' => 'سلمون محمر مع صلصة التفاح وصوص الزبدة الأبيض.'], 'p' => 88, 'img' => 'seafood-salmon', 'kcal' => 520, 'pro' => 40, 'carb' => 14, 'fat' => 34, 'gf' => true, 'alg' => [1, 3, 9], 'grp' => ['course'], 'rate' => 4.7, 'rc' => 41],
                ['n' => ['en' => 'Truffle Tonnarelli', 'ar' => 'تونّاريلي الترافل'], 'd' => ['en' => 'Black-pepper emulsion, truffle oil, aged parmesan.', 'ar' => 'مستحلب الفلفل الأسود مع الترافل والبارميزان المعتق.'], 'p' => 68, 'img' => 'truffle-pasta', 'kcal' => 620, 'pro' => 22, 'carb' => 74, 'fat' => 28, 'veg' => true, 'alg' => [1, 7], 'ext' => ['truffle'], 'pop' => true, 'rate' => 4.8, 'rc' => 47],
                ['n' => ['en' => 'Saffron Risotto', 'ar' => 'ريزوتو الزعفران'], 'd' => ['en' => 'Carnaroli rice, saffron threads, parmesan wave.', 'ar' => 'أرز كارنارولي مع الزعفران والبارميزان.'], 'p' => 58, 'img' => 'risotto', 'kcal' => 560, 'pro' => 14, 'carb' => 72, 'fat' => 22, 'veg' => true, 'gf' => true, 'alg' => [1], 'grp' => ['course'], 'rate' => 4.6, 'rc' => 28],
                ['n' => ['en' => 'Hand-Folded Ravioli', 'ar' => 'رافيولي مطوية يدوياً'], 'd' => ['en' => 'Ricotta ravioli, sage butter, pecorino snow.', 'ar' => 'رافيولي الريكوتا مع زبدة المريمية.'], 'p' => 62, 'img' => 'ravioli', 'kcal' => 540, 'pro' => 20, 'carb' => 58, 'fat' => 26, 'veg' => true, 'alg' => [1, 2, 7, 9], 'rate' => 4.6, 'rc' => 25],
                ['n' => ['en' => 'Market Fish, Charred Lemon', 'ar' => 'سمكة السوق'], 'd' => ['en' => 'Whole market fish, herbed grain, charred lemon.', 'ar' => 'سمكة السوق مع الحبوب بالأعشاب والليمون المشوي.'], 'p' => 96, 'img' => 'fish-grilled-whole', 'kcal' => 620, 'pro' => 48, 'carb' => 44, 'fat' => 26, 'alg' => [3, 7], 'grp' => ['pairing'], 'rate' => 4.5, 'rc' => 19],
            ],
        ],
        [
            'n' => ['en' => 'To Finish', 'ar' => 'للختام'], 'img' => 'lava-cake',
            'items' => [
                ['n' => ['en' => 'Molten Chocolate, Table-Side', 'ar' => 'شوكولاتة ذائبة'], 'd' => ['en' => 'Lava centre opened at the table, gold dust.', 'ar' => 'قلب سائل يفتح أمامك على الطاولة.'], 'p' => 42, 'img' => 'lava-cake', 'kcal' => 460, 'pro' => 7, 'carb' => 50, 'fat' => 26, 'veg' => true, 'alg' => [1, 2, 7], 'pop' => true, 'spec' => true, 'rate' => 4.9, 'rc' => 52, 'stock' => 8],
                ['n' => ['en' => 'Vanilla Bean Panna Cotta', 'ar' => 'بانا كوتا الفانيليا'], 'd' => ['en' => 'Set cream, macerated strawberries, basil.', 'ar' => 'كريمة الفانيليا مع الفراولة والريحان.'], 'p' => 32, 'img' => 'pannacotta', 'kcal' => 320, 'pro' => 6, 'carb' => 34, 'fat' => 18, 'veg' => true, 'gf' => true, 'alg' => [1], 'rate' => 4.5, 'rc' => 24],
                ['n' => ['en' => 'Fig & Rose Composition', 'ar' => 'تكوين التين والورد'], 'd' => ['en' => 'Fresh figs, berries, rose petals, pomegranate dust.', 'ar' => 'تين وتوت وبتلات ورد وغبار الرمان.'], 'p' => 36, 'img' => 'fruit-fig-plate', 'kcal' => 220, 'pro' => 3, 'carb' => 48, 'fat' => 2, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.4, 'rc' => 14],
                ['n' => ['en' => 'Cheese Trolley', 'ar' => 'عربة الأجبان'], 'd' => ['en' => 'Aged selection, figs, walnuts, crispbread.', 'ar' => 'تشكيلة أجبان معتقة مع التين والجوز.'], 'p' => 48, 'img' => 'cheese-platter', 'kcal' => 580, 'pro' => 24, 'carb' => 22, 'fat' => 44, 'veg' => true, 'alg' => [1, 4, 7], 'rate' => 4.6, 'rc' => 17],
            ],
        ],
        [
            'n' => ['en' => 'Cellar Zero', 'ar' => 'مشروبات راقية'], 'img' => 'mocktail-pink',
            'items' => [
                ['n' => ['en' => 'Rose Citrus Spritz', 'ar' => 'سبريتز الورد'], 'd' => ['en' => 'Grapefruit, rose syrup, sparkling top.', 'ar' => 'جريب فروت مع شراب الورد والصودا.'], 'p' => 28, 'img' => 'mocktail-pink', 'kcal' => 130, 'pro' => 0, 'carb' => 32, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'pop' => true, 'rate' => 4.5, 'rc' => 21],
                ['n' => ['en' => 'Grape Sparkler', 'ar' => 'عنب فوار'], 'd' => ['en' => 'Sparkling red grape, oak bitters, zero proof.', 'ar' => 'عصير عنب فوار بنكهة البلوط، خالٍ من الكحول.'], 'p' => 30, 'img' => 'wine-red', 'kcal' => 150, 'pro' => 0, 'carb' => 36, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.3, 'rc' => 13],
                ['n' => ['en' => 'Smoked Berry Coupe', 'ar' => 'كأس التوت المدخن'], 'd' => ['en' => 'Blackberry, smoked rosemary, soda.', 'ar' => 'توت أسود مع إكليل الجبل المدخن.'], 'p' => 26, 'img' => 'club-mocktail', 'kcal' => 140, 'pro' => 0, 'carb' => 34, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.4, 'rc' => 15],
                ['n' => ['en' => 'Double Espresso', 'ar' => 'إسبريسو مزدوج'], 'd' => ['en' => 'Served with sparkling water.', 'ar' => 'يقدم مع مياه غازية.'], 'p' => 16, 'img' => 'coffee-espresso', 'kcal' => 10, 'pro' => 0, 'carb' => 2, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.5, 'rc' => 18],
                ['n' => ['en' => 'Still / Sparkling', 'ar' => 'مياه'], 'd' => ['en' => 'Chilled bottle for the table.', 'ar' => 'قارورة باردة للطاولة.'], 'p' => 10, 'img' => 'water-sparkling', 'kcal' => 0, 'pro' => 0, 'carb' => 0, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.0, 'rc' => 8],
            ],
        ],
    ],
    'reviews' => [
        ['name' => 'Layla', 'stars' => 5, 'body' => ['en' => 'Tenderloin arrived glossy and perfect. Service is a ballet.', 'ar' => 'التندرلوين وصل لامعاً ومثالياً والخدمة كالباليه.']],
        ['name' => 'James', 'stars' => 5, 'body' => ['en' => 'Truffle tonnarelli is worth the booking alone.', 'ar' => 'تونّاريلي الترافل يستحق الحجز وحده.']],
        ['name' => 'Dina', 'stars' => 4, 'body' => ['en' => 'Table-side molten chocolate — pure theatre.', 'ar' => 'الشوكولاتة الذائبة على الطاولة — عرض حقيقي.']],
        ['name' => 'Waleed', 'stars' => 5, 'body' => ['en' => 'Quiet, dark, flawless. The crudo melts.', 'ar' => 'هادئ وفاخر ولا تشوبه شائبة والكرودو يذوب.']],
        ['name' => 'Elena', 'stars' => 4, 'body' => ['en' => 'Zero-proof pairings actually thought through.', 'ar' => 'المشروبات المرافقة مدروسة فعلاً.']],
    ],
];
