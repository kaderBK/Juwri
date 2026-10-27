<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/** Soirée — tapas & zero-proof lounge showcase menu. Same shape as crave.php. */
return [
    'store' => [
        'title' => 'Soirée', 'subtitle' => 'Tapas & small plates · zero-proof bar · late evenings',
        'description' => 'A candle-lit tapas lounge: small plates built for sharing, a serious zero-proof bar and churros at midnight.',
        'color' => '#7a1f3d', 'logo' => 'charcuterie', 'cover' => 'bar-interior',
        'banners' => ['bar-interior', 'prawns-garlic', 'churros'],
        'coupons' => [['code' => 'SOIREE10', 'pct' => 10]],
    ],
    'groups' => [
        'plates' => ['t' => ['en' => 'Plates', 'ar' => 'عدد الأطباق'], 'opts' => [['Single', 'واحد', 0], ['Double (share)', 'مزدوج للمشاركة', 14]], 'required' => true],
        'heat'   => ['t' => ['en' => 'Heat', 'ar' => 'الحرارة'], 'opts' => [['Mild', 'خفيف', 0], ['Spicy', 'حار', 0]]],
        'bread'  => ['t' => ['en' => 'Bread', 'ar' => 'الخبز'], 'opts' => [['Baguette', 'باغيت', 0], ['Sourdough', 'محمص', 0]]],
    ],
    'extras' => [
        'bread'   => ['t' => ['en' => 'Warm bread basket', 'ar' => 'سلة خبز دافئ'], 'p' => 8, 'img' => 'bread-basket'],
        'oliveset' => ['t' => ['en' => 'Cheese & fig plate', 'ar' => 'طبق جبن وتين'], 'p' => 16, 'img' => 'cheese-platter'],
        'prawns'  => ['t' => ['en' => 'Extra prawns (3)', 'ar' => 'روبيان إضافي (٣)'], 'p' => 16, 'img' => 'shrimp-grilled'],
        'choc'    => ['t' => ['en' => 'Chocolate dip', 'ar' => 'صوص شوكولاتة'], 'p' => 5, 'img' => 'lava-cake'],
    ],
    'categories' => [
        [
            'n' => ['en' => 'Cold Tapas', 'ar' => 'تاباس باردة'], 'img' => 'charcuterie',
            'items' => [
                ['n' => ['en' => 'Charcuterie Soirée', 'ar' => 'لوح السهرة'], 'd' => ['en' => 'Cured meats, aged cheese, pickled things.', 'ar' => 'لحوم مقددة مع جبن معتق ومخللات.'], 'p' => 58, 'img' => 'charcuterie', 'kcal' => 640, 'pro' => 32, 'carb' => 28, 'fat' => 44, 'alg' => [1, 7], 'ext' => ['bread'], 'pop' => true, 'rate' => 4.7, 'rc' => 47],
                ['n' => ['en' => 'Bruschetta Trio', 'ar' => 'ثلاثية البروسكيتا'], 'd' => ['en' => 'Charred bread, marinated tomato, basil oil.', 'ar' => 'خبز محمص مع طماطم متبلة وزيت الريحان.'], 'p' => 24, 'img' => 'bruschetta', 'kcal' => 320, 'pro' => 8, 'carb' => 42, 'fat' => 14, 'veg' => true, 'vegan' => true, 'alg' => [7], 'grp' => ['bread'], 'rate' => 4.4, 'rc' => 26],
                ['n' => ['en' => 'Salmon Crudo', 'ar' => 'سلمون كرودو'], 'd' => ['en' => 'Sashimi-cut salmon, citrus oil, salt flakes.', 'ar' => 'شرائح سلمون نيئة مع زيت الحمضيات.'], 'p' => 44, 'img' => 'salmon-sashimi', 'kcal' => 260, 'pro' => 24, 'carb' => 2, 'fat' => 18, 'gf' => true, 'alg' => [3], 'rate' => 4.6, 'rc' => 22],
                ['n' => ['en' => 'Citrus Ceviche Cup', 'ar' => 'كأس السيفيتشي'], 'd' => ['en' => 'Lime-cured fish, red onion, coriander.', 'ar' => 'سمك منقوع بالليمون مع البصل والكزبرة.'], 'p' => 38, 'img' => 'ceviche', 'kcal' => 240, 'pro' => 28, 'carb' => 12, 'fat' => 8, 'gf' => true, 'alg' => [3], 'rate' => 4.4, 'rc' => 17],
                ['n' => ['en' => 'Fig & Cheese Plate', 'ar' => 'طبق التين والجبن'], 'd' => ['en' => 'Aged blue, figs, walnuts, grapes.', 'ar' => 'جبن أزرق معتق مع تين وجوز وعنب.'], 'p' => 42, 'img' => 'cheese-platter', 'kcal' => 580, 'pro' => 24, 'carb' => 22, 'fat' => 44, 'veg' => true, 'gf' => true, 'alg' => [1, 4], 'rate' => 4.5, 'rc' => 19],
            ],
        ],
        [
            'n' => ['en' => 'Hot Tapas', 'ar' => 'تاباس ساخنة'], 'img' => 'prawns-garlic',
            'items' => [
                ['n' => ['en' => 'Gambas al Ajillo', 'ar' => 'روبيان بالثوم'], 'd' => ['en' => 'Sizzling prawns, garlic, chili, warm bread.', 'ar' => 'روبيان مقلي بالثوم والفلفل مع الخبز.'], 'p' => 48, 'img' => 'prawns-garlic', 'gal' => ['shrimp-grilled', 'prawns-raw'], 'kcal' => 440, 'pro' => 32, 'carb' => 12, 'fat' => 28, 'spice' => 1, 'gf' => true, 'alg' => [5, 9], 'grp' => ['plates', 'heat'], 'ext' => ['prawns', 'bread'], 'pop' => true, 'spec' => true, 'rate' => 4.8, 'rc' => 58],
                ['n' => ['en' => 'Albondigas', 'ar' => 'كرات اللحم الإسبانية'], 'd' => ['en' => 'Smoked meatballs, rocket, aged balsamic.', 'ar' => 'كرات لحم مدخنة مع الجرجير والبلسمك.'], 'p' => 36, 'img' => 'kofta', 'kcal' => 480, 'pro' => 34, 'carb' => 10, 'fat' => 34, 'gf' => true, 'alg' => [], 'grp' => ['plates'], 'rate' => 4.5, 'rc' => 31],
                ['n' => ['en' => 'Tortilla Española', 'ar' => 'تورتيا إسبانية'], 'd' => ['en' => 'Slow-set potato omelette, herbs.', 'ar' => 'أومليت البطاطس البطيء مع الأعشاب.'], 'p' => 26, 'img' => 'frittata', 'kcal' => 400, 'pro' => 22, 'carb' => 22, 'fat' => 26, 'veg' => true, 'gf' => true, 'alg' => [1, 2], 'rate' => 4.3, 'rc' => 18],
                ['n' => ['en' => 'Bravas-Style Fries', 'ar' => 'بطاطس برافاس'], 'd' => ['en' => 'Crisp potatoes, smoky sauce, aioli stripe.', 'ar' => 'بطاطس مقرمشة مع صوص مدخن وأيولي.'], 'p' => 22, 'img' => 'fries-loaded', 'kcal' => 520, 'pro' => 8, 'carb' => 58, 'fat' => 28, 'veg' => true, 'spice' => 1, 'alg' => [2], 'grp' => ['heat'], 'rate' => 4.4, 'rc' => 24],
                ['n' => ['en' => 'Street Tacos (3)', 'ar' => 'تاكو (٣)'], 'd' => ['en' => 'Soft tortillas, slaw, lime crema.', 'ar' => 'تورتيا طرية مع سلطة الملفوف وكريمة الليمون.'], 'p' => 30, 'img' => 'tacos', 'kcal' => 520, 'pro' => 24, 'carb' => 54, 'fat' => 24, 'spice' => 2, 'alg' => [1, 7], 'grp' => ['heat'], 'rate' => 4.4, 'rc' => 21],
                ['n' => ['en' => 'Charred Greens', 'ar' => 'خضار مشوية'], 'd' => ['en' => 'Broccolini, chili oil, toasted almond.', 'ar' => 'بروكليني مع زيت الفلفل واللوز.'], 'p' => 20, 'img' => 'veg-greens', 'kcal' => 180, 'pro' => 7, 'carb' => 12, 'fat' => 12, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [4], 'rate' => 4.2, 'rc' => 12],
            ],
        ],
        [
            'n' => ['en' => 'Bigger Plates', 'ar' => 'أطباق أكبر'], 'img' => 'paella',
            'items' => [
                ['n' => ['en' => 'Seafood Paella for Two', 'ar' => 'باييا لشخصين'], 'd' => ['en' => 'Saffron bomba rice, mussels, prawns, socarrat.', 'ar' => 'أرز بالزعفران مع بلح البحر والروبيان.'], 'p' => 88, 'img' => 'paella', 'kcal' => 1440, 'pro' => 76, 'carb' => 156, 'fat' => 52, 'gf' => true, 'alg' => [3, 5], 'pop' => true, 'rate' => 4.7, 'rc' => 39],
                ['n' => ['en' => 'Saffron Risotto', 'ar' => 'ريزوتو الزعفران'], 'd' => ['en' => 'Carnaroli, saffron threads, parmesan.', 'ar' => 'أرز كارنارولي مع الزعفران والبارميزان.'], 'p' => 44, 'img' => 'risotto', 'kcal' => 560, 'pro' => 14, 'carb' => 72, 'fat' => 22, 'veg' => true, 'gf' => true, 'alg' => [1], 'rate' => 4.5, 'rc' => 20],
                ['n' => ['en' => 'Shrimp Linguine Pan', 'ar' => 'لينغويني الروبيان'], 'd' => ['en' => 'Garlic-butter shrimp, cherry tomato.', 'ar' => 'روبيان بزبدة الثوم مع الطماطم الكرزية.'], 'p' => 48, 'img' => 'pasta-shrimp', 'kcal' => 640, 'pro' => 32, 'carb' => 70, 'fat' => 26, 'alg' => [1, 5, 7, 9], 'rate' => 4.6, 'rc' => 23],
            ],
        ],
        [
            'n' => ['en' => 'Midnight Sweets', 'ar' => 'حلويات منتصف الليل'], 'img' => 'churros',
            'items' => [
                ['n' => ['en' => 'Churros con Chocolate', 'ar' => 'تشوروز مع الشوكولاتة'], 'd' => ['en' => 'Crisp churros, thick drinking chocolate.', 'ar' => 'تشوروز مقرمشة مع شوكولاتة ساخنة كثيفة.'], 'p' => 22, 'img' => 'churros', 'kcal' => 440, 'pro' => 6, 'carb' => 56, 'fat' => 22, 'veg' => true, 'alg' => [1, 7], 'ext' => ['choc'], 'pop' => true, 'spec' => true, 'rate' => 4.8, 'rc' => 42],
                ['n' => ['en' => 'Molten Chocolate Pot', 'ar' => 'قدر الشوكولاتة الذائبة'], 'd' => ['en' => 'Lava centre, baked to order.', 'ar' => 'قلب سائل يخبز عند الطلب.'], 'p' => 30, 'img' => 'lava-cake', 'kcal' => 460, 'pro' => 7, 'carb' => 50, 'fat' => 26, 'veg' => true, 'alg' => [1, 2, 7], 'rate' => 4.7, 'rc' => 28],
                ['n' => ['en' => 'Espresso Tiramisu', 'ar' => 'تيراميسو'], 'd' => ['en' => 'Espresso-soaked layers, cocoa snow.', 'ar' => 'طبقات الإسبريسو مع الكاكاو.'], 'p' => 26, 'img' => 'tiramisu', 'kcal' => 440, 'pro' => 8, 'carb' => 44, 'fat' => 26, 'veg' => true, 'alg' => [1, 2, 7], 'rate' => 4.6, 'rc' => 24],
                ['n' => ['en' => 'Panna Cotta', 'ar' => 'بانا كوتا'], 'd' => ['en' => 'Vanilla set cream, strawberries.', 'ar' => 'كريمة الفانيليا مع الفراولة.'], 'p' => 22, 'img' => 'pannacotta', 'kcal' => 320, 'pro' => 6, 'carb' => 34, 'fat' => 18, 'veg' => true, 'gf' => true, 'alg' => [1], 'rate' => 4.4, 'rc' => 15],
            ],
        ],
        [
            'n' => ['en' => 'Zero-Proof Bar', 'ar' => 'بار بلا كحول'], 'img' => 'mocktails-trio',
            'items' => [
                ['n' => ['en' => 'Mocktail Flight (3)', 'ar' => 'ثلاثية الموكتيل'], 'd' => ['en' => 'Tasting trio: berry, citrus, passion.', 'ar' => 'ثلاثة موكتيلات: توت وحمضيات وباشن.'], 'p' => 34, 'img' => 'mocktails-trio', 'kcal' => 240, 'pro' => 1, 'carb' => 58, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'pop' => true, 'spec' => true, 'rate' => 4.7, 'rc' => 36],
                ['n' => ['en' => 'Rose Citrus Spritz', 'ar' => 'سبريتز الورد'], 'd' => ['en' => 'Grapefruit, rose syrup, sparkling.', 'ar' => 'جريب فروت مع شراب الورد والصودا.'], 'p' => 26, 'img' => 'mocktail-pink', 'kcal' => 130, 'pro' => 0, 'carb' => 32, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.5, 'rc' => 24],
                ['n' => ['en' => 'Smoked Berry Coupe', 'ar' => 'كأس التوت المدخن'], 'd' => ['en' => 'Blackberry, smoked rosemary, soda.', 'ar' => 'توت أسود مع إكليل الجبل المدخن.'], 'p' => 24, 'img' => 'club-mocktail', 'kcal' => 140, 'pro' => 0, 'carb' => 34, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.4, 'rc' => 20],
                ['n' => ['en' => 'Garden Mojito', 'ar' => 'موهيتو الحديقة'], 'd' => ['en' => 'Crushed mint, lime wheels, soda.', 'ar' => 'نعناع مهروس مع الليمون والصودا.'], 'p' => 22, 'img' => 'mojito-2', 'kcal' => 120, 'pro' => 0, 'carb' => 30, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.5, 'rc' => 22],
                ['n' => ['en' => 'Grape & Oak Sparkler', 'ar' => 'عنب فوار'], 'd' => ['en' => 'Sparkling red grape, oak bitters.', 'ar' => 'عصير عنب فوار بنكهة البلوط.'], 'p' => 26, 'img' => 'wine-red', 'kcal' => 150, 'pro' => 0, 'carb' => 36, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.3, 'rc' => 14],
                ['n' => ['en' => 'Double Espresso', 'ar' => 'إسبريسو مزدوج'], 'd' => ['en' => 'Late-night double, sparkling water side.', 'ar' => 'جرعة مزدوجة مع مياه غازية.'], 'p' => 14, 'img' => 'coffee-espresso', 'kcal' => 10, 'pro' => 0, 'carb' => 2, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.4, 'rc' => 16],
            ],
        ],
    ],
    'reviews' => [
        ['name' => 'Valentina', 'stars' => 5, 'body' => ['en' => 'Gambas sizzling, candles lit — the whole mood.', 'ar' => 'الروبيان يقلى والشموع مضاءة — الأجواء كاملة.']],
        ['name' => 'Khaled', 'stars' => 5, 'body' => ['en' => 'Mocktail flight beats most real bars.', 'ar' => 'ثلاثية الموكتيل تتفوق على معظم البارات الحقيقية.']],
        ['name' => 'Paula', 'stars' => 4, 'body' => ['en' => 'Churros at midnight should be a human right.', 'ar' => 'تشوروز منتصف الليل يجب أن تكون حقاً إنسانياً.']],
        ['name' => 'Bader', 'stars' => 4, 'body' => ['en' => 'Small plates, big flavours, late hours.', 'ar' => 'أطباق صغيرة ونكهات كبيرة وساعات متأخرة.']],
        ['name' => 'Nina', 'stars' => 5, 'body' => ['en' => 'Paella socarrat crust done right.', 'ar' => 'قشرة الباييا محضرة بإتقان.']],
    ],
];
