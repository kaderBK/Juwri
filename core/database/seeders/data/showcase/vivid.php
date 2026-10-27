<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/** Vivid Kitchen — street food showcase menu. Same shape as crave.php. */
return [
    'store' => [
        'title' => 'Vivid Kitchen', 'subtitle' => 'Street food · bold flavours · night market energy',
        'description' => 'A neon night-market kitchen: tacos, wraps, boxes and fried things with serious sauce.',
        'color' => '#d81e5b', 'logo' => 'tacos', 'cover' => 'nachos',
        'banners' => ['tacos', 'noodles-soup', 'churros'],
        'coupons' => [['code' => 'VIVID10', 'pct' => 10], ['code' => 'STREET15', 'pct' => 15]],
    ],
    'groups' => [
        'heat'  => ['t' => ['en' => 'Heat level', 'ar' => 'مستوى الحرارة'], 'opts' => [['Mild', 'خفيف', 0], ['Medium', 'متوسط', 0], ['Fire', 'ناري', 0]], 'required' => true],
        'combo' => ['t' => ['en' => 'Make it a box', 'ar' => 'اجعلها وجبة'], 'opts' => [['Item only', 'الصنف فقط', 0], ['+ fries & drink', '+ بطاطس ومشروب', 11]]],
        'dips'  => ['t' => ['en' => 'Dips', 'ar' => 'الصوصات'], 'opts' => [['Garlic', 'ثوم', 2], ['Sriracha mayo', 'مايو سريراتشا', 2], ['Cheese', 'جبن', 3]], 'min' => 0, 'max' => 3],
        'protein' => ['t' => ['en' => 'Protein', 'ar' => 'البروتين'], 'opts' => [['Chicken', 'دجاج', 0], ['Beef', 'لحم', 5], ['Falafel', 'فلافل', 0]], 'required' => true],
    ],
    'extras' => [
        'jalapeno' => ['t' => ['en' => 'Jalapeños', 'ar' => 'هالبينو'], 'p' => 2, 'img' => 'nachos'],
        'cheese'   => ['t' => ['en' => 'Cheese sauce', 'ar' => 'صوص الجبن'], 'p' => 3, 'img' => 'mac-cheese'],
        'fries'    => ['t' => ['en' => 'Street fries', 'ar' => 'بطاطس'], 'p' => 8, 'img' => 'fries-loaded'],
        'rings'    => ['t' => ['en' => 'Onion rings (4)', 'ar' => 'حلقات بصل (٤)'], 'p' => 5, 'img' => 'onion-rings'],
        'egg'      => ['t' => ['en' => 'Fried egg', 'ar' => 'بيضة مقلية'], 'p' => 4, 'img' => 'eggs-benedict'],
    ],
    'categories' => [
        [
            'n' => ['en' => 'Wraps & Rolls', 'ar' => 'راب ولفائف'], 'img' => 'tacos',
            'items' => [
                ['n' => ['en' => 'Baja Street Tacos (3)', 'ar' => 'تاكو الشارع (٣)'], 'd' => ['en' => 'Soft corn tortillas, slaw, lime crema.', 'ar' => 'تورتيا ذرة طرية مع سلطة الملفوف وكريمة الليمون.'], 'p' => 28, 'img' => 'tacos', 'kcal' => 520, 'pro' => 24, 'carb' => 54, 'fat' => 24, 'spice' => 2, 'alg' => [1, 7], 'grp' => ['protein', 'heat'], 'ext' => ['jalapeno', 'cheese'], 'pop' => true, 'spec' => true, 'rate' => 4.7, 'rc' => 88],
                ['n' => ['en' => 'Halloumi Street Wrap', 'ar' => 'راب الحلوم'], 'd' => ['en' => 'Seared halloumi, crunch veg, mint yogurt.', 'ar' => 'حلوم مشوي مع خضار مقرمشة ولبن بالنعناع.'], 'p' => 24, 'img' => 'wrap-halloumi', 'kcal' => 460, 'pro' => 18, 'carb' => 42, 'fat' => 24, 'veg' => true, 'alg' => [1, 7], 'grp' => ['heat'], 'rate' => 4.4, 'rc' => 33],
                ['n' => ['en' => 'Shawarma Roll', 'ar' => 'رول الشاورما'], 'd' => ['en' => 'Spit shawarma, toum, pickles, saj wrap.', 'ar' => 'شاورما مع الثوم والمخلل في خبز الصاج.'], 'p' => 22, 'img' => 'shawarma', 'kcal' => 580, 'pro' => 34, 'carb' => 44, 'fat' => 30, 'alg' => [7], 'grp' => ['protein', 'combo'], 'pop' => true, 'rate' => 4.6, 'rc' => 71],
                ['n' => ['en' => 'NY Street Dog', 'ar' => 'هوت دوغ نيويورك'], 'd' => ['en' => 'Beef frank, crispy onions, mustard.', 'ar' => 'نقانق بقري مع بصل مقرمش وخردل.'], 'p' => 18, 'img' => 'hot-dog', 'kcal' => 420, 'pro' => 16, 'carb' => 36, 'fat' => 24, 'alg' => [6, 7], 'grp' => ['combo'], 'ext' => ['cheese', 'jalapeno'], 'rate' => 4.2, 'rc' => 29],
                ['n' => ['en' => 'Falafel Box', 'ar' => 'بوكس الفلافل'], 'd' => ['en' => 'Crisp falafel, salad, pita, tahini.', 'ar' => 'فلافل مقرمشة مع سلطة وخبز وطحينة.'], 'p' => 20, 'img' => 'falafel-plate-real', 'kcal' => 420, 'pro' => 14, 'carb' => 48, 'fat' => 20, 'veg' => true, 'vegan' => true, 'alg' => [7], 'grp' => ['heat'], 'rate' => 4.5, 'rc' => 38],
            ],
        ],
        [
            'n' => ['en' => 'Boxes & Bowls', 'ar' => 'بوكسات وأطباق'], 'img' => 'bibimbap',
            'items' => [
                ['n' => ['en' => 'Seoul Box', 'ar' => 'بوكس سيول'], 'd' => ['en' => 'Stone-bowl rice, veg, fried egg, gochujang.', 'ar' => 'أرز بالخضار والبيض المقلي وصوص الغوتشوجانغ.'], 'p' => 34, 'img' => 'bibimbap', 'kcal' => 580, 'pro' => 22, 'carb' => 76, 'fat' => 20, 'spice' => 2, 'alg' => [2, 6], 'grp' => ['heat'], 'ext' => ['egg'], 'pop' => true, 'rate' => 4.6, 'rc' => 44],
                ['n' => ['en' => 'Wok Box', 'ar' => 'بوكس الووك'], 'd' => ['en' => 'Smoky fried rice, egg ribbons, scallion.', 'ar' => 'أرز مقلي مدخن مع البيض والبصل الأخضر.'], 'p' => 26, 'img' => 'rice-fried', 'kcal' => 520, 'pro' => 16, 'carb' => 78, 'fat' => 16, 'alg' => [2, 6], 'grp' => ['protein'], 'rate' => 4.3, 'rc' => 27],
                ['n' => ['en' => 'Night Noodles', 'ar' => 'نودلز الليل'], 'd' => ['en' => 'Springy noodles, chili crisp, scallion broth.', 'ar' => 'نودلز مع زيت الفلفل المقرمش ومرق البصل الأخضر.'], 'p' => 28, 'img' => 'noodles-soup', 'kcal' => 480, 'pro' => 14, 'carb' => 66, 'fat' => 16, 'veg' => true, 'spice' => 3, 'alg' => [6, 7], 'grp' => ['heat'], 'rate' => 4.5, 'rc' => 36],
                ['n' => ['en' => 'Street Momo (6)', 'ar' => 'مومو الشارع (٦)'], 'd' => ['en' => 'Steamed dumplings, soy-chili dip.', 'ar' => 'زلابية على البخار مع صوص الصويا الحار.'], 'p' => 22, 'img' => 'momo-dumplings', 'kcal' => 380, 'pro' => 20, 'carb' => 44, 'fat' => 12, 'spice' => 1, 'alg' => [6, 7], 'rate' => 4.4, 'rc' => 31],
                ['n' => ['en' => 'Kebab Fries Box', 'ar' => 'بوكس كباب وبطاطس'], 'd' => ['en' => 'Char-grilled skewers over seasoned fries.', 'ar' => 'أسياخ مشوية فوق بطاطس متبلة.'], 'p' => 36, 'img' => 'kebab-fries', 'kcal' => 720, 'pro' => 44, 'carb' => 48, 'fat' => 38, 'alg' => [], 'grp' => ['heat'], 'rate' => 4.6, 'rc' => 40],
                ['n' => ['en' => 'Fresh Roll Duo', 'ar' => 'ثنائي اللفائف الطازجة'], 'd' => ['en' => 'Rainbow veg rolls, pomegranate dip.', 'ar' => 'لفائف خضار ملونة مع صوص الرمان.'], 'p' => 18, 'img' => 'spring-rolls', 'kcal' => 240, 'pro' => 6, 'carb' => 32, 'fat' => 8, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.2, 'rc' => 16],
            ],
        ],
        [
            'n' => ['en' => 'Fried & Crunchy', 'ar' => 'مقرمشات'], 'img' => 'fried-chicken',
            'items' => [
                ['n' => ['en' => 'Crunch Bucket', 'ar' => 'دلو الكرانش'], 'd' => ['en' => 'Double-brined fried chicken, secret dust.', 'ar' => 'دجاج مقلي منقوع مرتين بخلطة سرية.'], 'p' => 36, 'img' => 'fried-chicken', 'gal' => ['fried-chicken-basket', 'tenders'], 'kcal' => 780, 'pro' => 48, 'carb' => 44, 'fat' => 44, 'spice' => 1, 'alg' => [2, 7], 'grp' => ['heat', 'combo'], 'ext' => ['fries'], 'pop' => true, 'rate' => 4.7, 'rc' => 79],
                ['n' => ['en' => 'Golden Tenders', 'ar' => 'أصابع دجاج'], 'd' => ['en' => 'Crunchy tenders, honey-mustard.', 'ar' => 'أصابع مقرمشة مع صوص العسل والخردل.'], 'p' => 26, 'img' => 'tenders', 'kcal' => 560, 'pro' => 34, 'carb' => 42, 'fat' => 26, 'alg' => [1, 2, 7], 'grp' => ['dips'], 'rate' => 4.4, 'rc' => 42],
                ['n' => ['en' => 'Street Smash Combo', 'ar' => 'كومبو السماش'], 'd' => ['en' => 'Diner-style smash burger, crinkle fries.', 'ar' => 'سماش برجر مع بطاطس مموجة.'], 'p' => 34, 'img' => 'burger-fries-2', 'kcal' => 920, 'pro' => 42, 'carb' => 84, 'fat' => 48, 'alg' => [1, 7], 'rate' => 4.5, 'rc' => 48],
                ['n' => ['en' => 'Slaw Stack Sandwich', 'ar' => 'ساندويتش السلو'], 'd' => ['en' => 'Fried chicken, purple slaw, chipotle mayo.', 'ar' => 'دجاج مقلي مع سلطة الملفوف البنفسجي.'], 'p' => 28, 'img' => 'sandwich-crispy', 'kcal' => 640, 'pro' => 30, 'carb' => 52, 'fat' => 34, 'spice' => 2, 'alg' => [1, 2, 7], 'grp' => ['heat'], 'rate' => 4.4, 'rc' => 30],
                ['n' => ['en' => 'Fish & Chips Cone', 'ar' => 'سمك وبطاطس'], 'd' => ['en' => 'Battered fillet, paper-cone chips.', 'ar' => 'فيليه مقرمش مع بطاطس في قمع ورقي.'], 'p' => 32, 'img' => 'fish-chips', 'kcal' => 720, 'pro' => 34, 'carb' => 66, 'fat' => 36, 'alg' => [2, 3, 7], 'rate' => 4.3, 'rc' => 22],
            ],
        ],
        [
            'n' => ['en' => 'Sides', 'ar' => 'أطباق جانبية'], 'img' => 'fries-loaded',
            'items' => [
                ['n' => ['en' => 'Loaded Street Fries', 'ar' => 'بطاطس محملة'], 'd' => ['en' => 'Cheese sauce, beef bits, chives.', 'ar' => 'صوص الجبن مع قطع اللحم والثوم المعمر.'], 'p' => 22, 'img' => 'fries-loaded', 'kcal' => 610, 'pro' => 16, 'carb' => 58, 'fat' => 34, 'alg' => [1], 'ext' => ['jalapeno', 'cheese'], 'pop' => true, 'rate' => 4.6, 'rc' => 52],
                ['n' => ['en' => 'Nachos Grande', 'ar' => 'ناتشوز'], 'd' => ['en' => 'Tortilla chips, cheese, pico, jalapeños.', 'ar' => 'رقائق التورتيا مع الجبن والهالبينو.'], 'p' => 26, 'img' => 'nachos', 'kcal' => 560, 'pro' => 14, 'carb' => 52, 'fat' => 32, 'spice' => 1, 'alg' => [1, 7], 'grp' => ['dips'], 'rate' => 4.5, 'rc' => 41],
                ['n' => ['en' => 'Crispy Onion Rings', 'ar' => 'حلقات البصل'], 'd' => ['en' => 'Beer-battered, paprika salt.', 'ar' => 'حلقات مقرمشة مع ملح البابريكا.'], 'p' => 18, 'img' => 'onion-rings', 'kcal' => 430, 'pro' => 6, 'carb' => 48, 'fat' => 24, 'veg' => true, 'alg' => [1, 7], 'grp' => ['dips'], 'rate' => 4.3, 'rc' => 25],
                ['n' => ['en' => 'Elote Corn', 'ar' => 'ذرة إيلوتي'], 'd' => ['en' => 'Grilled corn, chili-lime butter, cheese dust.', 'ar' => 'ذرة مشوية مع زبدة الليمون الحار والجبن.'], 'p' => 15, 'img' => 'corn-grilled', 'kcal' => 260, 'pro' => 6, 'carb' => 34, 'fat' => 12, 'veg' => true, 'gf' => true, 'spice' => 1, 'alg' => [1, 9], 'rate' => 4.4, 'rc' => 19],
            ],
        ],
        [
            'n' => ['en' => 'Sweet Street', 'ar' => 'حلويات الشارع'], 'img' => 'churros',
            'items' => [
                ['n' => ['en' => 'Cinnamon Churros', 'ar' => 'تشوروز'], 'd' => ['en' => 'Crisp churros, chocolate dip pot.', 'ar' => 'تشوروز مقرمشة مع صوص الشوكولاتة.'], 'p' => 18, 'img' => 'churros', 'kcal' => 440, 'pro' => 6, 'carb' => 56, 'fat' => 22, 'veg' => true, 'alg' => [1, 7], 'pop' => true, 'rate' => 4.6, 'rc' => 37],
                ['n' => ['en' => 'Banana Crepe Roll', 'ar' => 'كريب الموز'], 'd' => ['en' => 'Street crepe, banana, chocolate drizzle.', 'ar' => 'كريب مع الموز وصوص الشوكولاتة.'], 'p' => 20, 'img' => 'crepes-banana', 'kcal' => 420, 'pro' => 8, 'carb' => 60, 'fat' => 16, 'veg' => true, 'alg' => [1, 2, 7], 'rate' => 4.4, 'rc' => 21],
                ['n' => ['en' => 'Donut Stack', 'ar' => 'دونات'], 'd' => ['en' => 'Warm rings, chocolate, sprinkles.', 'ar' => 'دونات دافئ مع الشوكولاتة والرشات.'], 'p' => 16, 'img' => 'donut', 'kcal' => 420, 'pro' => 5, 'carb' => 58, 'fat' => 20, 'veg' => true, 'alg' => [1, 2, 6, 7], 'rate' => 4.3, 'rc' => 18],
                ['n' => ['en' => 'Sprinkle Ice-Cream Bowl', 'ar' => 'آيس كريم بالرشات'], 'd' => ['en' => 'Soft-serve in a sprinkle waffle bowl.', 'ar' => 'آيس كريم في وعاء الوافل الملون.'], 'p' => 20, 'img' => 'icecream-cone', 'kcal' => 480, 'pro' => 8, 'carb' => 60, 'fat' => 24, 'veg' => true, 'alg' => [1, 2, 7], 'rate' => 4.4, 'rc' => 23],
            ],
        ],
        [
            'n' => ['en' => 'Shakes & Coolers', 'ar' => 'شيك ومبردات'], 'img' => 'milkshake-choc',
            'items' => [
                ['n' => ['en' => 'Choc Freakshake', 'ar' => 'فريك شيك الشوكولاتة'], 'd' => ['en' => 'Over-the-top shake, cookie crown.', 'ar' => 'ميلك شيك ضخم بتاج الكوكيز.'], 'p' => 26, 'img' => 'milkshake-choc', 'kcal' => 680, 'pro' => 13, 'carb' => 88, 'fat' => 30, 'veg' => true, 'alg' => [1, 6, 7], 'pop' => true, 'rate' => 4.6, 'rc' => 34],
                ['n' => ['en' => 'Classic Vanilla Shake', 'ar' => 'شيك الفانيليا'], 'd' => ['en' => 'Thick malt shake, chocolate drizzle.', 'ar' => 'شيك كثيف مع صوص الشوكولاتة.'], 'p' => 22, 'img' => 'milkshake', 'kcal' => 560, 'pro' => 11, 'carb' => 76, 'fat' => 24, 'veg' => true, 'alg' => [1, 7], 'rate' => 4.4, 'rc' => 22],
                ['n' => ['en' => 'Watermelon Chili Cooler', 'ar' => 'مبرد البطيخ الحار'], 'd' => ['en' => 'Fresh watermelon, lime, chili-salt rim.', 'ar' => 'بطيخ طازج مع الليمون وحافة الملح الحار.'], 'p' => 18, 'img' => 'drink-watermelon', 'kcal' => 110, 'pro' => 1, 'carb' => 26, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'spice' => 1, 'alg' => [], 'rate' => 4.3, 'rc' => 16],
                ['n' => ['en' => 'Mint Lemonade', 'ar' => 'ليمون بالنعناع'], 'd' => ['en' => 'Blitzed mint, crushed ice.', 'ar' => 'نعناع مخفوق مع الثلج المجروش.'], 'p' => 16, 'img' => 'mint-lemonade', 'kcal' => 130, 'pro' => 0, 'carb' => 32, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.4, 'rc' => 20],
                ['n' => ['en' => 'Virgin Mojito', 'ar' => 'موهيتو'], 'd' => ['en' => 'Muddled mint, lime, sparkling top.', 'ar' => 'نعناع مهروس مع الليمون والصودا.'], 'p' => 18, 'img' => 'mojito-mocktail', 'kcal' => 120, 'pro' => 0, 'carb' => 30, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.4, 'rc' => 18],
                ['n' => ['en' => 'Craft Cola', 'ar' => 'كولا'], 'd' => ['en' => 'Ice-cold glass bottle.', 'ar' => 'كولا باردة في زجاجة.'], 'p' => 8, 'img' => 'soda-cola', 'kcal' => 140, 'pro' => 0, 'carb' => 36, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.0, 'rc' => 10],
            ],
        ],
    ],
    'reviews' => [
        ['name' => 'Zaid', 'stars' => 5, 'body' => ['en' => 'Tacos hit like a Tijuana food truck. Fire sauce is FIRE.', 'ar' => 'التاكو مثل عربات تيخوانا والصوص الناري نار فعلاً.']],
        ['name' => 'Monica', 'stars' => 5, 'body' => ['en' => 'Night noodles at midnight — exactly what the name promises.', 'ar' => 'نودلز الليل في منتصف الليل — كما يعد الاسم تماماً.']],
        ['name' => 'Turki', 'stars' => 4, 'body' => ['en' => 'Crunch bucket lives up to the crunch.', 'ar' => 'دلو الكرانش قرمشة حقيقية.']],
        ['name' => 'Aya', 'stars' => 4, 'body' => ['en' => 'Elote and churros — the sides are the stars.', 'ar' => 'الذرة والتشوروز — الأطباق الجانبية هي النجوم.']],
        ['name' => 'Hamad', 'stars' => 5, 'body' => ['en' => 'Freakshake broke my diet and I regret nothing.', 'ar' => 'الفريك شيك كسر نظامي الغذائي ولا أندم على شيء.']],
    ],
];
