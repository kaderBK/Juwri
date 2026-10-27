<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/** Glow Coffee — specialty coffee showcase menu. Same shape as crave.php. */
return [
    'store' => [
        'title' => 'Glow Coffee', 'subtitle' => 'Specialty coffee · all-day brunch · fresh bakes',
        'description' => 'Single-origin espresso, slow brunch plates and a counter full of fresh bakes, in a warm glass-and-glow room.',
        'color' => '#0f7a6b', 'logo' => 'coffee-art', 'cover' => 'coffee-latte',
        'banners' => ['coffee-art', 'avocado-toast', 'cinnamon-roll'],
        'coupons' => [['code' => 'GLOW10', 'pct' => 10]],
    ],
    'groups' => [
        'cup'  => ['t' => ['en' => 'Cup size', 'ar' => 'حجم الكوب'], 'opts' => [['Regular', 'عادي', 0], ['Large', 'كبير', 4]], 'required' => true],
        'milk' => ['t' => ['en' => 'Milk', 'ar' => 'الحليب'], 'opts' => [['Fresh', 'طازج', 0], ['Oat', 'شوفان', 3], ['Almond', 'لوز', 3]]],
        'shots' => ['t' => ['en' => 'Shots', 'ar' => 'الجرعات'], 'opts' => [['Single', 'واحدة', 0], ['Double', 'مزدوجة', 4]], 'required' => true],
        'eggs' => ['t' => ['en' => 'Egg style', 'ar' => 'طريقة البيض'], 'opts' => [['Poached', 'مسلوق', 0], ['Fried', 'مقلي', 0], ['Scrambled', 'مخفوق', 0]]],
    ],
    'extras' => [
        'shot'    => ['t' => ['en' => 'Extra espresso shot', 'ar' => 'جرعة إسبريسو إضافية'], 'p' => 5, 'img' => 'coffee-espresso'],
        'syrup'   => ['t' => ['en' => 'Flavour syrup', 'ar' => 'شراب منكه'], 'p' => 3, 'img' => 'coffee-coldbrew'],
        'egg_add' => ['t' => ['en' => 'Add an egg', 'ar' => 'بيضة إضافية'], 'p' => 4, 'img' => 'eggs-benedict'],
        'avocado' => ['t' => ['en' => 'Avocado', 'ar' => 'أفوكادو'], 'p' => 5, 'img' => 'avocado-toast'],
        'bake'    => ['t' => ['en' => 'Bake of the day', 'ar' => 'مخبوز اليوم'], 'p' => 8, 'img' => 'croissant'],
    ],
    'categories' => [
        [
            'n' => ['en' => 'Espresso Bar', 'ar' => 'ركن الإسبريسو'], 'img' => 'coffee-espresso',
            'items' => [
                ['n' => ['en' => 'Signature Flat White', 'ar' => 'فلات وايت مميز'], 'd' => ['en' => 'Double ristretto, velvet micro-foam.', 'ar' => 'ريستريتو مزدوج مع رغوة حريرية.'], 'p' => 17, 'img' => 'coffee-latte', 'gal' => ['coffee-art', 'coffee-cappuccino'], 'kcal' => 120, 'pro' => 6, 'carb' => 10, 'fat' => 6, 'veg' => true, 'gf' => true, 'alg' => [1], 'grp' => ['cup', 'milk'], 'ext' => ['shot', 'syrup'], 'pop' => true, 'rate' => 4.8, 'rc' => 112],
                ['n' => ['en' => 'Cappuccino', 'ar' => 'كابتشينو'], 'd' => ['en' => 'Classic thirds on single-origin beans.', 'ar' => 'كابتشينو كلاسيكي ببن أحادي المصدر.'], 'p' => 16, 'img' => 'coffee-cappuccino', 'kcal' => 130, 'pro' => 6, 'carb' => 12, 'fat' => 6, 'veg' => true, 'gf' => true, 'alg' => [1], 'grp' => ['cup', 'milk'], 'ext' => ['shot'], 'rate' => 4.6, 'rc' => 74],
                ['n' => ['en' => 'Espresso', 'ar' => 'إسبريسو'], 'd' => ['en' => '25-second pull, tasting notes on the card.', 'ar' => 'جرعة مثالية مع بطاقة نكهات البن.'], 'p' => 12, 'img' => 'coffee-espresso', 'kcal' => 10, 'pro' => 0, 'carb' => 2, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'grp' => ['shots'], 'rate' => 4.7, 'rc' => 58],
                ['n' => ['en' => 'Iced Spanish Latte', 'ar' => 'لاتيه إسباني مثلج'], 'd' => ['en' => 'Double espresso, condensed milk, over ice.', 'ar' => 'إسبريسو مزدوج مع حليب مكثف وثلج.'], 'p' => 19, 'img' => 'coffee-coldbrew', 'kcal' => 190, 'pro' => 6, 'carb' => 24, 'fat' => 8, 'veg' => true, 'gf' => true, 'alg' => [1], 'grp' => ['cup'], 'ext' => ['shot'], 'pop' => true, 'spec' => true, 'rate' => 4.8, 'rc' => 97],
                ['n' => ['en' => 'Barista Art Latte', 'ar' => 'لاتيه بفن الرسم'], 'd' => ['en' => 'Pour-art latte, beans of the week.', 'ar' => 'لاتيه بفن الرسم وبن الأسبوع.'], 'p' => 18, 'img' => 'coffee-art', 'kcal' => 150, 'pro' => 7, 'carb' => 14, 'fat' => 7, 'veg' => true, 'gf' => true, 'alg' => [1], 'grp' => ['milk'], 'rate' => 4.6, 'rc' => 43],
                ['n' => ['en' => 'French Press for Two', 'ar' => 'فرنش برس لشخصين'], 'd' => ['en' => 'Slow-steeped pot, dark roast.', 'ar' => 'قهوة منقوعة ببطء بتحميص داكن.'], 'p' => 24, 'img' => 'coffee-frenchpress', 'kcal' => 15, 'pro' => 1, 'carb' => 3, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.4, 'rc' => 21],
            ],
        ],
        [
            'n' => ['en' => 'All-Day Brunch', 'ar' => 'برانش طوال اليوم'], 'img' => 'avocado-toast',
            'items' => [
                ['n' => ['en' => 'Avocado Smash', 'ar' => 'توست الأفوكادو'], 'd' => ['en' => 'Sourdough, smashed avocado, soft egg, dukkah.', 'ar' => 'خبز محمص مع أفوكادو وبيض والدقة.'], 'p' => 32, 'img' => 'avocado-toast', 'kcal' => 420, 'pro' => 16, 'carb' => 38, 'fat' => 24, 'veg' => true, 'alg' => [2, 4, 7], 'grp' => ['eggs'], 'ext' => ['egg_add', 'avocado'], 'pop' => true, 'rate' => 4.7, 'rc' => 83],
                ['n' => ['en' => 'Eggs Benedict', 'ar' => 'بيض بينديكت'], 'd' => ['en' => 'Poached eggs, smoked salmon, hollandaise.', 'ar' => 'بيض مسلوق مع سلمون مدخن وصوص الهولنديز.'], 'p' => 38, 'img' => 'eggs-benedict', 'kcal' => 540, 'pro' => 26, 'carb' => 30, 'fat' => 36, 'alg' => [1, 2, 3, 7, 9], 'rate' => 4.7, 'rc' => 56],
                ['n' => ['en' => 'Shakshuka Pan', 'ar' => 'شكشوكة'], 'd' => ['en' => 'Baked eggs in tomato-pepper sauce, bread.', 'ar' => 'بيض مخبوز في صوص الطماطم والفلفل مع الخبز.'], 'p' => 28, 'img' => 'shakshuka', 'kcal' => 420, 'pro' => 20, 'carb' => 30, 'fat' => 24, 'veg' => true, 'spice' => 1, 'alg' => [2, 7], 'rate' => 4.6, 'rc' => 44],
                ['n' => ['en' => 'Sunrise Egg Toast', 'ar' => 'توست البيض'], 'd' => ['en' => 'Egg-in-a-hole brioche, grilled tomato.', 'ar' => 'بيض داخل خبز البريوش مع طماطم مشوية.'], 'p' => 24, 'img' => 'breakfast-spread', 'kcal' => 380, 'pro' => 16, 'carb' => 32, 'fat' => 20, 'veg' => true, 'alg' => [1, 2, 7], 'rate' => 4.3, 'rc' => 27],
                ['n' => ['en' => 'Brunch Toast Trio', 'ar' => 'ثلاثية التوست'], 'd' => ['en' => 'Avocado, banana-tahini, tomato-feta toasts.', 'ar' => 'توست الأفوكادو والموز بالطحينة والطماطم بالفيتا.'], 'p' => 34, 'img' => 'toasts-brunch', 'kcal' => 520, 'pro' => 16, 'carb' => 58, 'fat' => 26, 'veg' => true, 'alg' => [1, 4, 7], 'rate' => 4.4, 'rc' => 25],
                ['n' => ['en' => 'Buttermilk Pancakes', 'ar' => 'بان كيك'], 'd' => ['en' => 'Tall stack, maple, blueberries.', 'ar' => 'بان كيك مع شراب القيقب والتوت.'], 'p' => 28, 'img' => 'pancakes', 'kcal' => 620, 'pro' => 14, 'carb' => 96, 'fat' => 20, 'veg' => true, 'alg' => [1, 2, 7], 'pop' => true, 'rate' => 4.6, 'rc' => 61],
                ['n' => ['en' => 'Golden Waffles', 'ar' => 'وافل ذهبي'], 'd' => ['en' => 'Crisp waffles, berry compote, butter.', 'ar' => 'وافل مقرمش مع كومبوت التوت والزبدة.'], 'p' => 26, 'img' => 'waffles', 'kcal' => 560, 'pro' => 12, 'carb' => 82, 'fat' => 22, 'veg' => true, 'alg' => [1, 2, 7, 9], 'rate' => 4.5, 'rc' => 38],
                ['n' => ['en' => 'Open Frittata', 'ar' => 'فريتاتا'], 'd' => ['en' => 'Skillet omelette, melted cheese, herbs.', 'ar' => 'أومليت بالمقلاة مع الجبن والأعشاب.'], 'p' => 26, 'img' => 'frittata', 'kcal' => 400, 'pro' => 22, 'carb' => 12, 'fat' => 30, 'veg' => true, 'gf' => true, 'alg' => [1, 2], 'rate' => 4.2, 'rc' => 18],
            ],
        ],
        [
            'n' => ['en' => 'Fresh Bakes', 'ar' => 'مخبوزات طازجة'], 'img' => 'croissant',
            'items' => [
                ['n' => ['en' => 'All-Butter Croissant', 'ar' => 'كرواسون بالزبدة'], 'd' => ['en' => 'Flaky layers, baked all morning.', 'ar' => 'طبقات هشة تخبز طوال الصباح.'], 'p' => 13, 'img' => 'croissant', 'kcal' => 280, 'pro' => 6, 'carb' => 30, 'fat' => 16, 'veg' => true, 'alg' => [1, 2, 7, 9], 'pop' => true, 'rate' => 4.6, 'rc' => 52],
                ['n' => ['en' => 'Cinnamon Roll', 'ar' => 'رول القرفة'], 'd' => ['en' => 'Sticky roll, cream-cheese glaze.', 'ar' => 'رول القرفة مع صوص الجبن الكريمي.'], 'p' => 17, 'img' => 'cinnamon-roll', 'kcal' => 420, 'pro' => 7, 'carb' => 62, 'fat' => 18, 'veg' => true, 'alg' => [1, 2, 7], 'rate' => 4.6, 'rc' => 47],
                ['n' => ['en' => 'Blueberry Muffin', 'ar' => 'مافن التوت'], 'd' => ['en' => 'Domed bakery muffin, berry pockets.', 'ar' => 'مافن بحشوة التوت الأزرق.'], 'p' => 13, 'img' => 'muffin', 'kcal' => 340, 'pro' => 5, 'carb' => 48, 'fat' => 14, 'veg' => true, 'alg' => [1, 2, 7], 'rate' => 4.3, 'rc' => 22],
                ['n' => ['en' => 'Glazed Donuts (2)', 'ar' => 'دونات مزجج (٢)'], 'd' => ['en' => 'Chocolate-drizzled rings, sprinkles.', 'ar' => 'دونات بالشوكولاتة والرشات.'], 'p' => 15, 'img' => 'donut', 'kcal' => 420, 'pro' => 5, 'carb' => 58, 'fat' => 20, 'veg' => true, 'alg' => [1, 2, 6, 7], 'rate' => 4.4, 'rc' => 26],
                ['n' => ['en' => 'Chunky Cookie', 'ar' => 'كوكيز'], 'd' => ['en' => 'Dark-chocolate chunks, sea salt.', 'ar' => 'كوكيز بقطع الشوكولاتة وملح البحر.'], 'p' => 10, 'img' => 'cookie', 'kcal' => 380, 'pro' => 5, 'carb' => 50, 'fat' => 18, 'veg' => true, 'alg' => [1, 2, 6, 7], 'rate' => 4.5, 'rc' => 31],
                ['n' => ['en' => 'Honey Crepes', 'ar' => 'كريب بالعسل'], 'd' => ['en' => 'Warm crepes, sweet cheese, honey.', 'ar' => 'كريب دافئ مع الجبن الحلو والعسل.'], 'p' => 22, 'img' => 'crepes-honey', 'kcal' => 460, 'pro' => 12, 'carb' => 56, 'fat' => 20, 'veg' => true, 'alg' => [1, 2, 7], 'rate' => 4.4, 'rc' => 20],
            ],
        ],
        [
            'n' => ['en' => 'Sweets & Cakes', 'ar' => 'حلويات وكيك'], 'img' => 'tiramisu',
            'items' => [
                ['n' => ['en' => 'Espresso Tiramisu', 'ar' => 'تيراميسو الإسبريسو'], 'd' => ['en' => 'Our espresso, mascarpone cloud, cocoa.', 'ar' => 'إسبريسو المحل مع الماسكاربوني والكاكاو.'], 'p' => 26, 'img' => 'tiramisu', 'kcal' => 440, 'pro' => 8, 'carb' => 44, 'fat' => 26, 'veg' => true, 'alg' => [1, 2, 7], 'pop' => true, 'spec' => true, 'rate' => 4.8, 'rc' => 49],
                ['n' => ['en' => 'Fudge Brownie', 'ar' => 'براوني'], 'd' => ['en' => 'Dense cocoa brownie, warm option.', 'ar' => 'براوني كثيف يقدم دافئاً.'], 'p' => 18, 'img' => 'brownie', 'kcal' => 420, 'pro' => 6, 'carb' => 52, 'fat' => 22, 'veg' => true, 'alg' => [1, 2, 4, 7], 'rate' => 4.5, 'rc' => 28],
                ['n' => ['en' => 'Chocolate Layer Cake', 'ar' => 'كيك الشوكولاتة'], 'd' => ['en' => 'Dark layers, ganache drip.', 'ar' => 'طبقات شوكولاتة داكنة مع الغاناش.'], 'p' => 24, 'img' => 'cake-chocolate', 'kcal' => 540, 'pro' => 7, 'carb' => 66, 'fat' => 28, 'veg' => true, 'alg' => [1, 2, 4, 7], 'rate' => 4.6, 'rc' => 33],
            ],
        ],
        [
            'n' => ['en' => 'Cold & Fresh', 'ar' => 'مشروبات باردة'], 'img' => 'juice-orange',
            'items' => [
                ['n' => ['en' => 'Fresh Orange Juice', 'ar' => 'عصير برتقال طازج'], 'd' => ['en' => 'Squeezed to order.', 'ar' => 'يعصر عند الطلب.'], 'p' => 15, 'img' => 'juice-orange', 'kcal' => 120, 'pro' => 2, 'carb' => 28, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.4, 'rc' => 24],
                ['n' => ['en' => 'Green Machine', 'ar' => 'العصير الأخضر'], 'd' => ['en' => 'Kiwi, apple, spinach, ginger.', 'ar' => 'كيوي وتفاح وسبانخ وزنجبيل.'], 'p' => 18, 'img' => 'juice-green', 'kcal' => 140, 'pro' => 2, 'carb' => 32, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.2, 'rc' => 15],
                ['n' => ['en' => 'Cloudy Lemonade', 'ar' => 'ليموناضة'], 'd' => ['en' => 'Pressed lemon over crushed ice.', 'ar' => 'ليمون معصور مع الثلج المجروش.'], 'p' => 14, 'img' => 'lemonade', 'kcal' => 140, 'pro' => 0, 'carb' => 36, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.3, 'rc' => 18],
                ['n' => ['en' => 'Iced Tea', 'ar' => 'شاي مثلج'], 'd' => ['en' => 'Cold-brewed black tea, lemon.', 'ar' => 'شاي أسود بارد مع الليمون.'], 'p' => 12, 'img' => 'iced-tea', 'kcal' => 60, 'pro' => 0, 'carb' => 15, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.1, 'rc' => 12],
                ['n' => ['en' => 'Sparkling Water', 'ar' => 'مياه غازية'], 'd' => ['en' => 'Chilled bottle.', 'ar' => 'قارورة باردة.'], 'p' => 6, 'img' => 'water-sparkling', 'kcal' => 0, 'pro' => 0, 'carb' => 0, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.0, 'rc' => 8],
            ],
        ],
    ],
    'reviews' => [
        ['name' => 'Joud', 'stars' => 5, 'body' => ['en' => 'Flat white is dialed in perfectly every single time.', 'ar' => 'الفلات وايت مضبوط بإتقان في كل مرة.']],
        ['name' => 'Michael', 'stars' => 5, 'body' => ['en' => 'Avocado smash + spanish latte = my weekend.', 'ar' => 'توست الأفوكادو مع اللاتيه الإسباني = نهاية أسبوعي.']],
        ['name' => 'Rana', 'stars' => 4, 'body' => ['en' => 'Croissants sell out by noon for a reason.', 'ar' => 'الكرواسون ينفد قبل الظهر لسبب وجيه.']],
        ['name' => 'Ali', 'stars' => 5, 'body' => ['en' => 'Espresso tiramisu made with their own shots — genius.', 'ar' => 'تيراميسو بإسبريسو المحل — فكرة عبقرية.']],
        ['name' => 'Sara', 'stars' => 4, 'body' => ['en' => 'Cozy glow, fast wifi, great beans.', 'ar' => 'أجواء دافئة وواي فاي سريع وبن ممتاز.']],
    ],
];
