<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/** Picnic Park — deli & sandwiches showcase menu. Same shape as crave.php. */
return [
    'store' => [
        'title' => 'Picnic Park', 'subtitle' => 'Deli & sandwiches · boards to share · park-day energy',
        'description' => 'A sunny deli counter: stacked sandwiches, sharing boards, soups and lemonade by the jug.',
        'color' => '#e0a615', 'logo' => 'club-sandwich-2', 'cover' => 'charcuterie',
        'banners' => ['club-sandwich-2', 'charcuterie', 'lemonade'],
        'coupons' => [['code' => 'PICNIC10', 'pct' => 10]],
    ],
    'groups' => [
        'bread' => ['t' => ['en' => 'Bread', 'ar' => 'الخبز'], 'opts' => [['Sourdough', 'محمص', 0], ['Ciabatta', 'تشاباتا', 0], ['Gluten-free', 'خالٍ من الغلوتين', 4]], 'required' => true],
        'combo' => ['t' => ['en' => 'Picnic combo', 'ar' => 'كومبو النزهة'], 'opts' => [['Sandwich only', 'ساندويتش فقط', 0], ['+ soup', '+ شوربة', 9], ['+ salad', '+ سلطة', 9]]],
        'toast' => ['t' => ['en' => 'Toasted?', 'ar' => 'محمص؟'], 'opts' => [['Toasted', 'محمص', 0], ['Fresh', 'طازج', 0]], 'required' => true],
    ],
    'extras' => [
        'cheese'  => ['t' => ['en' => 'Extra cheese', 'ar' => 'جبن إضافي'], 'p' => 4, 'img' => 'cheese-platter'],
        'avocado' => ['t' => ['en' => 'Avocado', 'ar' => 'أفوكادو'], 'p' => 5, 'img' => 'avocado-toast'],
        'egg'     => ['t' => ['en' => 'Fried egg', 'ar' => 'بيضة مقلية'], 'p' => 4, 'img' => 'eggs-benedict'],
        'pickles' => ['t' => ['en' => 'Deli pickles', 'ar' => 'مخللات'], 'p' => 4, 'img' => 'veg-greens'],
        'chips'   => ['t' => ['en' => 'Kettle fries', 'ar' => 'بطاطس'], 'p' => 8, 'img' => 'fries-loaded'],
    ],
    'categories' => [
        [
            'n' => ['en' => 'Stacked Sandwiches', 'ar' => 'ساندويتشات'], 'img' => 'club-sandwich-2',
            'items' => [
                ['n' => ['en' => 'The Big Club', 'ar' => 'الكلوب الكبير'], 'd' => ['en' => 'Triple-decker chicken club, turkey rashers, egg.', 'ar' => 'كلوب ثلاثي الطبقات مع دجاج وبيض.'], 'p' => 32, 'img' => 'club-sandwich-2', 'gal' => ['sandwich-club', 'sandwich-crispy'], 'kcal' => 760, 'pro' => 38, 'carb' => 62, 'fat' => 38, 'alg' => [1, 2, 7], 'grp' => ['bread', 'combo'], 'ext' => ['cheese', 'avocado'], 'pop' => true, 'spec' => true, 'rate' => 4.7, 'rc' => 68],
                ['n' => ['en' => 'Golden Cheese Melt', 'ar' => 'ميلت الجبن الذهبي'], 'd' => ['en' => 'Three cheeses, griddled sourdough, tomato dip.', 'ar' => 'ثلاثة أجبان مع خبز محمص وصوص الطماطم.'], 'p' => 24, 'img' => 'sandwich-club', 'kcal' => 520, 'pro' => 20, 'carb' => 44, 'fat' => 30, 'veg' => true, 'alg' => [1, 7], 'grp' => ['bread', 'toast'], 'ext' => ['cheese'], 'pop' => true, 'rate' => 4.6, 'rc' => 47],
                ['n' => ['en' => 'Crispy Slaw Stack', 'ar' => 'ستاك السلو المقرمش'], 'd' => ['en' => 'Fried chicken, purple slaw, chipotle mayo.', 'ar' => 'دجاج مقرمش مع سلطة الملفوف البنفسجي.'], 'p' => 28, 'img' => 'sandwich-crispy', 'kcal' => 640, 'pro' => 30, 'carb' => 52, 'fat' => 34, 'spice' => 1, 'alg' => [1, 2, 7], 'grp' => ['combo'], 'rate' => 4.5, 'rc' => 34],
                ['n' => ['en' => 'Park Dog', 'ar' => 'هوت دوغ النزهة'], 'd' => ['en' => 'Beef frank, crispy onions, two mustards.', 'ar' => 'نقانق بقري مع بصل مقرمش وخردل.'], 'p' => 18, 'img' => 'hot-dog', 'kcal' => 420, 'pro' => 16, 'carb' => 36, 'fat' => 24, 'alg' => [6, 7], 'ext' => ['cheese'], 'rate' => 4.2, 'rc' => 22],
                ['n' => ['en' => 'Avocado Deli Toast', 'ar' => 'توست الأفوكادو'], 'd' => ['en' => 'Smashed avocado, soft egg, dukkah.', 'ar' => 'أفوكادو مهروس مع بيض ودقة.'], 'p' => 28, 'img' => 'avocado-toast', 'kcal' => 420, 'pro' => 16, 'carb' => 38, 'fat' => 24, 'veg' => true, 'alg' => [2, 4, 7], 'grp' => ['bread'], 'ext' => ['egg'], 'rate' => 4.5, 'rc' => 26],
                ['n' => ['en' => 'Morning Toast Trio', 'ar' => 'ثلاثية التوست'], 'd' => ['en' => 'Avocado, banana-tahini, tomato-feta.', 'ar' => 'أفوكادو وموز بالطحينة وطماطم بالفيتا.'], 'p' => 32, 'img' => 'toasts-brunch', 'kcal' => 520, 'pro' => 16, 'carb' => 58, 'fat' => 26, 'veg' => true, 'alg' => [1, 4, 7], 'rate' => 4.4, 'rc' => 19],
            ],
        ],
        [
            'n' => ['en' => 'Boards to Share', 'ar' => 'ألواح للمشاركة'], 'img' => 'charcuterie',
            'items' => [
                ['n' => ['en' => 'Picnic Charcuterie', 'ar' => 'لوح النزهة'], 'd' => ['en' => 'Cured meats, cheeses, pickles, baguette.', 'ar' => 'لحوم مقددة وأجبان ومخللات وخبز.'], 'p' => 56, 'img' => 'charcuterie', 'kcal' => 640, 'pro' => 32, 'carb' => 28, 'fat' => 44, 'alg' => [1, 7], 'ext' => ['pickles'], 'pop' => true, 'rate' => 4.7, 'rc' => 41],
                ['n' => ['en' => 'Cheese Board', 'ar' => 'لوح الأجبان'], 'd' => ['en' => 'Aged blue, figs, walnuts, crispbread.', 'ar' => 'جبن أزرق معتق مع تين وجوز.'], 'p' => 44, 'img' => 'cheese-platter', 'kcal' => 580, 'pro' => 24, 'carb' => 22, 'fat' => 44, 'veg' => true, 'alg' => [1, 4, 7], 'rate' => 4.5, 'rc' => 24],
                ['n' => ['en' => 'Bruschetta Basket', 'ar' => 'سلة البروسكيتا'], 'd' => ['en' => 'Charred sourdough, marinated tomato.', 'ar' => 'خبز محمص مع طماطم متبلة.'], 'p' => 22, 'img' => 'bruschetta', 'kcal' => 320, 'pro' => 8, 'carb' => 42, 'fat' => 14, 'veg' => true, 'vegan' => true, 'alg' => [7], 'rate' => 4.3, 'rc' => 16],
                ['n' => ['en' => 'Bread & Butters', 'ar' => 'خبز وزبدة'], 'd' => ['en' => 'House rolls, two whipped butters.', 'ar' => 'خبز البيت مع زبدتين مخفوقتين.'], 'p' => 16, 'img' => 'bread-basket', 'kcal' => 420, 'pro' => 10, 'carb' => 56, 'fat' => 18, 'veg' => true, 'alg' => [1, 7, 9], 'rate' => 4.2, 'rc' => 12],
            ],
        ],
        [
            'n' => ['en' => 'Sides & Soups', 'ar' => 'أطباق جانبية وشوربات'], 'img' => 'fries-loaded',
            'items' => [
                ['n' => ['en' => 'Kettle Fries', 'ar' => 'بطاطس'], 'd' => ['en' => 'Skin-on fries, deli salt.', 'ar' => 'بطاطس بقشرتها مع ملح الديلي.'], 'p' => 16, 'img' => 'fries-loaded', 'kcal' => 480, 'pro' => 6, 'carb' => 56, 'fat' => 26, 'veg' => true, 'gf' => true, 'alg' => [], 'rate' => 4.4, 'rc' => 28],
                ['n' => ['en' => 'Onion Rings', 'ar' => 'حلقات بصل'], 'd' => ['en' => 'Beer-battered, ranch dip.', 'ar' => 'حلقات مقرمشة مع صوص الرانش.'], 'p' => 16, 'img' => 'onion-rings', 'kcal' => 430, 'pro' => 6, 'carb' => 48, 'fat' => 24, 'veg' => true, 'alg' => [1, 7], 'rate' => 4.3, 'rc' => 18],
                ['n' => ['en' => 'Charred Corn Cob', 'ar' => 'ذرة مشوية'], 'd' => ['en' => 'Chili-lime butter, cheese dust.', 'ar' => 'زبدة الليمون الحار مع الجبن.'], 'p' => 14, 'img' => 'corn-grilled', 'kcal' => 260, 'pro' => 6, 'carb' => 34, 'fat' => 12, 'veg' => true, 'gf' => true, 'alg' => [1, 9], 'rate' => 4.3, 'rc' => 14],
                ['n' => ['en' => 'Roast Tomato Soup', 'ar' => 'شوربة الطماطم'], 'd' => ['en' => 'Fire-roasted, basil oil — dips the melt perfectly.', 'ar' => 'طماطم مشوية بزيت الريحان — مثالية مع الميلت.'], 'p' => 18, 'img' => 'soup-lentil', 'kcal' => 260, 'pro' => 6, 'carb' => 30, 'fat' => 12, 'veg' => true, 'alg' => [1, 7], 'pop' => true, 'rate' => 4.5, 'rc' => 25],
                ['n' => ['en' => 'Garden Side Salad', 'ar' => 'سلطة جانبية'], 'd' => ['en' => 'Rainbow leaves, citrus dressing.', 'ar' => 'خضار ملونة مع صوص الحمضيات.'], 'p' => 16, 'img' => 'salad-garden', 'kcal' => 180, 'pro' => 4, 'carb' => 14, 'fat' => 12, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.2, 'rc' => 13],
            ],
        ],
        [
            'n' => ['en' => 'Sweet Basket', 'ar' => 'سلة الحلويات'], 'img' => 'cookie',
            'items' => [
                ['n' => ['en' => 'Picnic Cookies (3)', 'ar' => 'كوكيز النزهة (٣)'], 'd' => ['en' => 'Chewy chocolate-chunk cookies.', 'ar' => 'كوكيز طرية بقطع الشوكولاتة.'], 'p' => 14, 'img' => 'cookie', 'kcal' => 380, 'pro' => 5, 'carb' => 50, 'fat' => 18, 'veg' => true, 'alg' => [1, 2, 6, 7], 'pop' => true, 'rate' => 4.5, 'rc' => 23],
                ['n' => ['en' => 'Blueberry Muffin', 'ar' => 'مافن التوت'], 'd' => ['en' => 'Bakery dome, berry pockets.', 'ar' => 'مافن بحشوة التوت.'], 'p' => 12, 'img' => 'muffin', 'kcal' => 340, 'pro' => 5, 'carb' => 48, 'fat' => 14, 'veg' => true, 'alg' => [1, 2, 7], 'rate' => 4.3, 'rc' => 15],
                ['n' => ['en' => 'Fudge Brownie', 'ar' => 'براوني'], 'd' => ['en' => 'Dense cocoa square.', 'ar' => 'مربع براوني كثيف.'], 'p' => 14, 'img' => 'brownie', 'kcal' => 420, 'pro' => 6, 'carb' => 52, 'fat' => 22, 'veg' => true, 'alg' => [1, 2, 4, 7], 'rate' => 4.4, 'rc' => 17],
                ['n' => ['en' => 'Berry Hand Pie', 'ar' => 'فطيرة التوت'], 'd' => ['en' => 'Buttery lattice, mixed berries.', 'ar' => 'عجينة زبدة مع توت مشكل.'], 'p' => 18, 'img' => 'berry-pie', 'kcal' => 400, 'pro' => 5, 'carb' => 56, 'fat' => 18, 'veg' => true, 'alg' => [1, 7, 9], 'rate' => 4.4, 'rc' => 14],
                ['n' => ['en' => 'Cinnamon Roll', 'ar' => 'رول القرفة'], 'd' => ['en' => 'Sticky roll, cream-cheese glaze.', 'ar' => 'رول القرفة مع صوص الجبن.'], 'p' => 15, 'img' => 'cinnamon-roll', 'kcal' => 420, 'pro' => 7, 'carb' => 62, 'fat' => 18, 'veg' => true, 'alg' => [1, 2, 7], 'rate' => 4.4, 'rc' => 16],
            ],
        ],
        [
            'n' => ['en' => 'Lemonade Stand', 'ar' => 'ركن الليموناضة'], 'img' => 'lemonade',
            'items' => [
                ['n' => ['en' => 'Cloudy Lemonade Jug', 'ar' => 'إبريق ليموناضة'], 'd' => ['en' => 'Pressed lemon, cane sugar, iced jug.', 'ar' => 'ليمون معصور مع سكر القصب والثلج.'], 'p' => 24, 'img' => 'lemonade', 'kcal' => 420, 'pro' => 0, 'carb' => 108, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'pop' => true, 'rate' => 4.6, 'rc' => 31],
                ['n' => ['en' => 'Mint Lemonade', 'ar' => 'ليمون بالنعناع'], 'd' => ['en' => 'Blitzed mint, crushed ice.', 'ar' => 'نعناع مخفوق مع الثلج.'], 'p' => 15, 'img' => 'mint-lemonade', 'kcal' => 130, 'pro' => 0, 'carb' => 32, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.4, 'rc' => 20],
                ['n' => ['en' => 'Iced Tea', 'ar' => 'شاي مثلج'], 'd' => ['en' => 'Cold-brewed, lemon wheel.', 'ar' => 'شاي بارد مع الليمون.'], 'p' => 12, 'img' => 'iced-tea', 'kcal' => 60, 'pro' => 0, 'carb' => 15, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.2, 'rc' => 13],
                ['n' => ['en' => 'Fresh Orange Juice', 'ar' => 'عصير برتقال'], 'd' => ['en' => 'Squeezed to order.', 'ar' => 'يعصر عند الطلب.'], 'p' => 14, 'img' => 'juice-orange', 'kcal' => 120, 'pro' => 2, 'carb' => 28, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.3, 'rc' => 15],
                ['n' => ['en' => 'Craft Cola', 'ar' => 'كولا'], 'd' => ['en' => 'Glass bottle, ice cold.', 'ar' => 'كولا باردة في زجاجة.'], 'p' => 8, 'img' => 'soda-cola', 'kcal' => 140, 'pro' => 0, 'carb' => 36, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.0, 'rc' => 8],
            ],
        ],
    ],
    'reviews' => [
        ['name' => 'Grace', 'stars' => 5, 'body' => ['en' => 'Big Club is architecture. Napkins required.', 'ar' => 'الكلوب الكبير هندسة معمارية — المناديل ضرورية.']],
        ['name' => 'Fares', 'stars' => 5, 'body' => ['en' => 'Melt + tomato soup dip. Do it.', 'ar' => 'الميلت مع شوربة الطماطم — جربها.']],
        ['name' => 'Holly', 'stars' => 4, 'body' => ['en' => 'Grabbed a board and a jug, sat in the sun. Perfect.', 'ar' => 'لوح وإبريق وجلسة تحت الشمس — كمال.']],
        ['name' => 'Nawaf', 'stars' => 4, 'body' => ['en' => 'Cookies still warm at 4pm somehow.', 'ar' => 'الكوكيز دافئ حتى الرابعة عصراً بطريقة ما.']],
        ['name' => 'Iris', 'stars' => 5, 'body' => ['en' => 'The lemonade jug is a family tradition now.', 'ar' => 'إبريق الليموناضة صار تقليداً عائلياً.']],
    ],
];
