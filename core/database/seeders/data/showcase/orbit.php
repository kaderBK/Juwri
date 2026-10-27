<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/** Orbit Diner — retro diner showcase menu. Same shape as crave.php. */
return [
    'store' => [
        'title' => 'Orbit Diner', 'subtitle' => 'مطعم على الطراز الكلاسيكي · مقاعد ومشروبات · فطور طوال اليوم',
        'description' => 'كروم ونيون ومقاعد حمراء: برجر سماش، وفطور طوال اليوم، وميلك شيك بكل الإضافات.',
        'happy_hour_label' => ['en' => 'Happy Hour', 'ar' => 'ساعة السعادة'],
        'color' => '#1f9bd8', 'logo' => 'burger-fries-2', 'cover' => 'diner-interior',
        'banners' => ['diner-interior', 'burger-fries-2', 'milkshake'],
        'coupons' => [['code' => 'ORBIT10', 'pct' => 10]],
    ],
    'groups' => [
        'patty' => ['t' => ['en' => 'Patty', 'ar' => 'قطع اللحم'], 'opts' => [['Single', 'مفرد', 0], ['Double', 'دبل', 9]], 'required' => true],
        'combo' => ['t' => ['en' => 'Make it a combo', 'ar' => 'اجعلها وجبة'], 'opts' => [['Solo', 'فقط', 0], ['Fries + drink', 'بطاطس + مشروب', 12]], 'required' => true],
        'eggs' => ['t' => ['en' => 'Egg style', 'ar' => 'طريقة البيض'], 'opts' => [['Sunny', 'عيون', 0], ['Scrambled', 'مخفوق', 0], ['Poached', 'مسلوق', 0]]],
        'shake_top' => ['t' => ['en' => 'Shake topper', 'ar' => 'إضافة الشيك'], 'opts' => [['Whipped cream', 'كريمة', 0], ['Cherry', 'كرز', 1], ['Cookie crumb', 'فتات كوكيز', 2]], 'min' => 0, 'max' => 3],
    ],
    'extras' => [
        'cheese' => ['t' => ['en' => 'Cheese slice', 'ar' => 'شريحة جبن'], 'p' => 3, 'img' => 'cheese-platter'],
        'egg' => ['t' => ['en' => 'Fried egg', 'ar' => 'بيضة مقلية'], 'p' => 4, 'img' => 'eggs-benedict'],
        'rings' => ['t' => ['en' => 'Onion rings (4)', 'ar' => 'حلقات بصل'], 'p' => 5, 'img' => 'onion-rings'],
        'fries' => ['t' => ['en' => 'Crinkle fries', 'ar' => 'بطاطس مموجة'], 'p' => 8, 'img' => 'fries-loaded'],
        'scoop' => ['t' => ['en' => 'Ice-cream scoop', 'ar' => 'كرة آيس كريم'], 'p' => 6, 'img' => 'icecream-scoop'],
    ],
    'categories' => [
        [
            'n' => ['en' => 'Booth Burgers', 'ar' => 'برجر الداينر'], 'img' => 'burger-classic',
            'items' => [
                ['n' => ['en' => 'Orbit Classic', 'ar' => 'أوربت كلاسيك'], 'd' => ['en' => 'Griddle-smashed beef, american cheese, diner sauce.', 'ar' => 'لحم سماش مع جبن أمريكي وصوص الداينر.'], 'p' => 26, 'img' => 'burger-classic', 'gal' => ['burger-cheese', 'burger-double'], 'kcal' => 540, 'pro' => 28, 'carb' => 42, 'fat' => 26, 'alg' => [1, 2, 7], 'grp' => ['patty', 'combo'], 'ext' => ['cheese', 'egg'], 'pop' => true, 'spec' => true, 'rate' => 4.7, 'rc' => 84],
                ['n' => ['en' => 'Route Combo Tray', 'ar' => 'صينية الكومبو'], 'd' => ['en' => 'Cheeseburger, crinkle fries, retro tray.', 'ar' => 'تشيز برجر مع بطاطس مموجة على صينية.'], 'p' => 36, 'img' => 'burger-fries-2', 'kcal' => 920, 'pro' => 42, 'carb' => 84, 'fat' => 48, 'alg' => [1, 7], 'grp' => ['patty'], 'pop' => true, 'rate' => 4.6, 'rc' => 62],
                ['n' => ['en' => 'Double Stack', 'ar' => 'دبل ستاك'], 'd' => ['en' => 'Double beef, double cheese, pickles.', 'ar' => 'لحم دبل مع جبن دبل ومخلل.'], 'p' => 34, 'img' => 'burger-double', 'kcal' => 820, 'pro' => 44, 'carb' => 44, 'fat' => 48, 'alg' => [1, 2, 7], 'grp' => ['combo'], 'rate' => 4.6, 'rc' => 48],
                ['n' => ['en' => 'Counter Cheese Melt', 'ar' => 'ميلت الكاونتر'], 'd' => ['en' => 'Griddled beef, three cheeses, sesame bun.', 'ar' => 'لحم مشوي مع ثلاثة أجبان.'], 'p' => 30, 'img' => 'burger-cheese', 'kcal' => 700, 'pro' => 36, 'carb' => 42, 'fat' => 42, 'alg' => [1, 7], 'grp' => ['combo'], 'rate' => 4.5, 'rc' => 36],
                ['n' => ['en' => 'Chrome Dog', 'ar' => 'هوت دوغ كروم'], 'd' => ['en' => 'Beef frank, crispy onions, mustard stripe.', 'ar' => 'نقانق بقري مع بصل مقرمش وخردل.'], 'p' => 18, 'img' => 'hot-dog', 'kcal' => 420, 'pro' => 16, 'carb' => 36, 'fat' => 24, 'alg' => [6, 7], 'ext' => ['cheese'], 'rate' => 4.2, 'rc' => 21],
                ['n' => ['en' => 'Midnight Club', 'ar' => 'كلوب منتصف الليل'], 'd' => ['en' => 'Triple-decker club, fries on the side.', 'ar' => 'كلوب ثلاثي الطبقات مع بطاطس.'], 'p' => 32, 'img' => 'club-sandwich-2', 'kcal' => 760, 'pro' => 38, 'carb' => 62, 'fat' => 38, 'alg' => [1, 2, 7], 'rate' => 4.4, 'rc' => 26],
            ],
        ],
        [
            'n' => ['en' => 'Fried Counter', 'ar' => 'ركن المقليات'], 'img' => 'fried-chicken',
            'items' => [
                ['n' => ['en' => 'Southern Fried Basket', 'ar' => 'سلة الدجاج الجنوبي'], 'd' => ['en' => 'Pressure-fried chicken, 11-spice dust.', 'ar' => 'دجاج مقلي بخلطة ١١ بهار.'], 'p' => 34, 'img' => 'fried-chicken', 'kcal' => 780, 'pro' => 48, 'carb' => 44, 'fat' => 44, 'alg' => [2, 7], 'grp' => ['combo'], 'pop' => true, 'rate' => 4.6, 'rc' => 51],
                ['n' => ['en' => 'Diner Tenders', 'ar' => 'أصابع الدجاج'], 'd' => ['en' => 'Crunchy tenders, honey-mustard pot.', 'ar' => 'أصابع مقرمشة مع صوص العسل والخردل.'], 'p' => 26, 'img' => 'tenders', 'kcal' => 560, 'pro' => 34, 'carb' => 42, 'fat' => 26, 'alg' => [1, 2, 7], 'rate' => 4.4, 'rc' => 33],
                ['n' => ['en' => 'Fish & Chips', 'ar' => 'سمك وبطاطس'], 'd' => ['en' => 'Battered fillet, thick chips, tartare.', 'ar' => 'فيليه مقرمش مع بطاطس وصوص التارتار.'], 'p' => 34, 'img' => 'fish-chips', 'kcal' => 720, 'pro' => 34, 'carb' => 66, 'fat' => 36, 'alg' => [2, 3, 7], 'rate' => 4.3, 'rc' => 24],
                ['n' => ['en' => 'Mac & Cheese Skillet', 'ar' => 'ماك آند تشيز'], 'd' => ['en' => 'Three-cheese macaroni, crumb top.', 'ar' => 'معكرونة بثلاثة أجبان وفتات محمص.'], 'p' => 24, 'img' => 'mac-cheese', 'kcal' => 640, 'pro' => 22, 'carb' => 60, 'fat' => 34, 'veg' => true, 'alg' => [1, 7], 'rate' => 4.4, 'rc' => 27],
                ['n' => ['en' => 'Crinkle Fries', 'ar' => 'بطاطس مموجة'], 'd' => ['en' => 'Golden crinkles, diner salt.', 'ar' => 'بطاطس مموجة ذهبية.'], 'p' => 14, 'img' => 'fries-loaded', 'kcal' => 480, 'pro' => 6, 'carb' => 56, 'fat' => 26, 'veg' => true, 'gf' => true, 'alg' => [], 'rate' => 4.3, 'rc' => 22],
                ['n' => ['en' => 'Onion Ring Tower', 'ar' => 'برج حلقات البصل'], 'd' => ['en' => 'Stacked rings, ranch dip.', 'ar' => 'حلقات مكدسة مع صوص الرانش.'], 'p' => 18, 'img' => 'onion-rings', 'kcal' => 430, 'pro' => 6, 'carb' => 48, 'fat' => 24, 'veg' => true, 'alg' => [1, 7], 'rate' => 4.3, 'rc' => 18],
            ],
        ],
        [
            'n' => ['en' => 'All-Day Breakfast', 'ar' => 'فطور طوال اليوم'], 'img' => 'pancakes',
            'items' => [
                ['n' => ['en' => 'Bottomless Pancakes', 'ar' => 'بان كيك بلا حدود'], 'd' => ['en' => 'Tall stack, maple, blueberries, banana.', 'ar' => 'بان كيك مع القيقب والتوت والموز.'], 'p' => 28, 'img' => 'pancakes', 'kcal' => 620, 'pro' => 14, 'carb' => 96, 'fat' => 20, 'veg' => true, 'alg' => [1, 2, 7], 'grp' => ['eggs'], 'pop' => true, 'spec' => true, 'rate' => 4.7, 'rc' => 66],
                ['n' => ['en' => 'Waffle Plate', 'ar' => 'طبق الوافل'], 'd' => ['en' => 'Crisp waffles, berry compote, butter pat.', 'ar' => 'وافل مقرمش مع كومبوت التوت.'], 'p' => 26, 'img' => 'waffles', 'kcal' => 560, 'pro' => 12, 'carb' => 82, 'fat' => 22, 'veg' => true, 'alg' => [1, 2, 7, 9], 'ext' => ['scoop'], 'rate' => 4.5, 'rc' => 38],
                ['n' => ['en' => 'Benedict at the Counter', 'ar' => 'بينديكت الكاونتر'], 'd' => ['en' => 'Poached eggs, smoked salmon, hollandaise.', 'ar' => 'بيض مسلوق مع سلمون مدخن وهولنديز.'], 'p' => 36, 'img' => 'eggs-benedict', 'kcal' => 540, 'pro' => 26, 'carb' => 30, 'fat' => 36, 'alg' => [1, 2, 3, 7, 9], 'rate' => 4.6, 'rc' => 29],
                ['n' => ['en' => 'Sunrise Egg Toast', 'ar' => 'توست البيض'], 'd' => ['en' => 'Egg-in-a-hole brioche, grilled tomato.', 'ar' => 'بيض داخل البريوش مع طماطم مشوية.'], 'p' => 22, 'img' => 'breakfast-spread', 'kcal' => 380, 'pro' => 16, 'carb' => 32, 'fat' => 20, 'veg' => true, 'alg' => [1, 2, 7], 'grp' => ['eggs'], 'rate' => 4.3, 'rc' => 20],
            ],
        ],
        [
            'n' => ['en' => 'Shake Bar', 'ar' => 'ركن الشيك'], 'img' => 'milkshake',
            'items' => [
                ['n' => ['en' => 'Vanilla Malt Shake', 'ar' => 'شيك الفانيليا'], 'd' => ['en' => 'Old-school malted vanilla, thick straw.', 'ar' => 'فانيليا كلاسيكية كثيفة.'], 'p' => 22, 'img' => 'milkshake', 'kcal' => 560, 'pro' => 11, 'carb' => 76, 'fat' => 24, 'veg' => true, 'alg' => [1, 7], 'grp' => ['shake_top'], 'pop' => true, 'rate' => 4.6, 'rc' => 47],
                ['n' => ['en' => 'Choc Tower Shake', 'ar' => 'شيك برج الشوكولاتة'], 'd' => ['en' => 'Chocolate shake, whipped tower, cookie crown.', 'ar' => 'شيك شوكولاتة مع برج الكريمة والكوكيز.'], 'p' => 26, 'img' => 'milkshake-choc', 'kcal' => 680, 'pro' => 13, 'carb' => 88, 'fat' => 30, 'veg' => true, 'alg' => [1, 6, 7], 'grp' => ['shake_top'], 'rate' => 4.6, 'rc' => 39],
                ['n' => ['en' => 'Galaxy Freakshake', 'ar' => 'فريك شيك المجرة'], 'd' => ['en' => 'Over-the-top dark shake, pretzel antenna.', 'ar' => 'شيك ضخم داكن مع البريتزل.'], 'p' => 28, 'img' => 'freakshake-dark', 'kcal' => 720, 'pro' => 14, 'carb' => 92, 'fat' => 34, 'veg' => true, 'alg' => [1, 4, 6, 7], 'grp' => ['shake_top'], 'spec' => true, 'rate' => 4.7, 'rc' => 34],
                ['n' => ['en' => 'Ice-Cold Cola Float', 'ar' => 'كولا فلوت'], 'd' => ['en' => 'Glass-bottle cola + vanilla scoop.', 'ar' => 'كولا باردة مع كرة فانيليا.'], 'p' => 14, 'img' => 'soda-cola', 'kcal' => 280, 'pro' => 2, 'carb' => 56, 'fat' => 6, 'veg' => true, 'alg' => [1], 'ext' => ['scoop'], 'rate' => 4.3, 'rc' => 19],
                ['n' => ['en' => 'Cloudy Lemonade', 'ar' => 'ليموناضة'], 'd' => ['en' => 'Pressed lemon, crushed ice.', 'ar' => 'ليمون معصور مع الثلج.'], 'p' => 14, 'img' => 'lemonade', 'kcal' => 140, 'pro' => 0, 'carb' => 36, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.2, 'rc' => 14],
            ],
        ],
        [
            'n' => ['en' => 'Pie Case', 'ar' => 'خزانة الفطائر'], 'img' => 'berry-pie',
            'items' => [
                ['n' => ['en' => 'Diner Berry Pie', 'ar' => 'فطيرة التوت'], 'd' => ['en' => 'Counter-case lattice pie, à la mode option.', 'ar' => 'فطيرة الخزانة الزجاجية مع خيار الآيس كريم.'], 'p' => 20, 'img' => 'berry-pie', 'kcal' => 400, 'pro' => 5, 'carb' => 56, 'fat' => 18, 'veg' => true, 'alg' => [1, 7, 9], 'ext' => ['scoop'], 'pop' => true, 'rate' => 4.6, 'rc' => 28],
                ['n' => ['en' => 'Hot Fudge Sundae', 'ar' => 'صنداي الفدج'], 'd' => ['en' => 'Soft-serve, hot fudge, roasted pecans.', 'ar' => 'آيس كريم مع الفدج الساخن والبقان.'], 'p' => 20, 'img' => 'sundae-caramel', 'kcal' => 430, 'pro' => 7, 'carb' => 52, 'fat' => 22, 'veg' => true, 'alg' => [1, 4], 'rate' => 4.5, 'rc' => 24],
                ['n' => ['en' => 'Sprinkle Bowl', 'ar' => 'آيس كريم بالرشات'], 'd' => ['en' => 'Three scoops, sprinkle-crusted bowl.', 'ar' => 'ثلاث كرات في وعاء الرشات.'], 'p' => 18, 'img' => 'icecream-cone', 'kcal' => 480, 'pro' => 8, 'carb' => 60, 'fat' => 24, 'veg' => true, 'alg' => [1, 2, 7], 'rate' => 4.4, 'rc' => 18],
                ['n' => ['en' => 'Counter Donuts (2)', 'ar' => 'دونات (٢)'], 'd' => ['en' => 'Glazed rings, chocolate drizzle.', 'ar' => 'دونات مزجج مع الشوكولاتة.'], 'p' => 14, 'img' => 'donut', 'kcal' => 420, 'pro' => 5, 'carb' => 58, 'fat' => 20, 'veg' => true, 'alg' => [1, 2, 6, 7], 'rate' => 4.3, 'rc' => 15],
                ['n' => ['en' => 'Warm Brownie', 'ar' => 'براوني دافئ'], 'd' => ['en' => 'Fudge brownie, optional scoop.', 'ar' => 'براوني دافئ مع خيار الآيس كريم.'], 'p' => 16, 'img' => 'brownie', 'kcal' => 420, 'pro' => 6, 'carb' => 52, 'fat' => 22, 'veg' => true, 'alg' => [1, 2, 4, 7], 'ext' => ['scoop'], 'rate' => 4.4, 'rc' => 17],
            ],
        ],
    ],
    'reviews' => [
        ['name' => 'Buddy', 'stars' => 5, 'body' => ['en' => 'Booths, neon, malt shakes — time travel that tastes great.', 'ar' => 'مقاعد ونيون وشيك — سفر عبر الزمن بطعم رائع.']],
        ['name' => 'Rasha', 'stars' => 5, 'body' => ['en' => 'Pancakes at 9pm. This is freedom.', 'ar' => 'بان كيك الساعة التاسعة مساءً — هذه هي الحرية.']],
        ['name' => 'Gene', 'stars' => 4, 'body' => ['en' => 'Combo tray exactly like the old highway diners.', 'ar' => 'صينية الكومبو مثل مطاعم الطرق القديمة تماماً.']],
        ['name' => 'Muneera', 'stars' => 4, 'body' => ['en' => 'Galaxy freakshake broke my table\'s diet pact.', 'ar' => 'فريك شيك المجرة كسر اتفاق الرجيم على طاولتنا.']],
        ['name' => 'Sam', 'stars' => 5, 'body' => ['en' => 'Berry pie à la mode. Ask for it warm.', 'ar' => 'فطيرة التوت مع الآيس كريم — اطلبها دافئة.']],
    ],
];
