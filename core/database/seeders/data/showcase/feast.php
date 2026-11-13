<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/** Feast Hall — family feast showcase menu. Same shape as crave.php. */
return [
    'store' => [
        'title' => 'Feast Hall', 'subtitle' => 'Family feasts · sharing trays · kids welcome',
        'description' => 'Big tables, bigger trays: mandi and kabsa for the family, sharing grills, kids plates and honest homestyle cooking.',
        'color' => '#b8460f', 'logo' => 'kabsa', 'cover' => 'grill-spread',
        'banners' => ['grill-spread', 'kabsa', 'fruit-platter'],
        'coupons' => [['code' => 'FEAST10', 'pct' => 10], ['code' => 'FAMILY20', 'pct' => 20]],
    ],
    'groups' => [
        'tray'  => ['t' => ['en' => 'Tray size', 'ar' => 'حجم الصينية'], 'opts' => [['For 2', 'لشخصين', 0], ['For 4', 'لأربعة', 42], ['For 6', 'لستة', 78]], 'required' => true],
        'rice'  => ['t' => ['en' => 'Rice', 'ar' => 'الأرز'], 'opts' => [['White basmati', 'بسمتي أبيض', 0], ['Spiced kabsa', 'كبسة متبلة', 0], ['Extra rice', 'أرز إضافي', 8]]],
        'sauces' => ['t' => ['en' => 'Sauces', 'ar' => 'الصوصات'], 'opts' => [['Daqqus', 'دقوس', 2], ['Garlic toum', 'ثوم', 2], ['Yogurt', 'لبن', 2]], 'min' => 0, 'max' => 3],
        'kids'  => ['t' => ['en' => 'Kids side', 'ar' => 'جانب الأطفال'], 'opts' => [['Fries', 'بطاطس', 0], ['Rice', 'أرز', 0], ['Corn', 'ذرة', 0]], 'required' => true],
    ],
    'extras' => [
        'salad'  => ['t' => ['en' => 'Family salad bowl', 'ar' => 'سلطة عائلية'], 'p' => 14, 'img' => 'salad-garden'],
        'soup'   => ['t' => ['en' => 'Lentil soup cups (4)', 'ar' => 'شوربة عدس (٤)'], 'p' => 16, 'img' => 'soup-lentil'],
        'bread'  => ['t' => ['en' => 'Bread basket', 'ar' => 'سلة خبز'], 'p' => 8, 'img' => 'bread-basket'],
        'pickle' => ['t' => ['en' => 'Pickle plate', 'ar' => 'طبق مخللات'], 'p' => 5, 'img' => 'veg-greens'],
        'fruit'  => ['t' => ['en' => 'Fruit platter', 'ar' => 'طبق فواكه'], 'p' => 22, 'img' => 'fruit-platter'],
    ],
    'categories' => [
        [
            'n' => ['en' => 'Family Trays', 'ar' => 'صواني عائلية'], 'img' => 'mandi-plate',
            'items' => [
                ['n' => ['en' => 'Lamb Mandi Tray', 'ar' => 'صينية مندي لحم'], 'd' => ['en' => 'Smoked basmati, fall-apart lamb, mandi oil.', 'ar' => 'أرز بسمتي مدخن مع لحم يتفتت وبهارات المندي.'], 'p' => 68, 'img' => 'mandi-plate', 'kcal' => 880, 'pro' => 52, 'carb' => 86, 'fat' => 38, 'gf' => true, 'alg' => [], 'grp' => ['tray', 'rice', 'sauces'], 'ext' => ['salad', 'bread'], 'pop' => true, 'spec' => true, 'rate' => 4.9, 'rc' => 156],
                ['n' => ['en' => 'Chicken Kabsa Tray', 'ar' => 'صينية كبسة دجاج'], 'd' => ['en' => 'Spiced tomato rice, roasted chicken, daqqus.', 'ar' => 'أرز بالبهارات مع دجاج محمر ودقوس.'], 'p' => 54, 'img' => 'kabsa', 'kcal' => 760, 'pro' => 46, 'carb' => 88, 'fat' => 26, 'gf' => true, 'alg' => [], 'grp' => ['tray', 'sauces'], 'ext' => ['salad', 'soup'], 'pop' => true, 'rate' => 4.8, 'rc' => 132],
                ['n' => ['en' => 'Family BBQ Board', 'ar' => 'لوح مشاوي عائلي'], 'd' => ['en' => 'Mixed grill spread: skewers, wings, sides.', 'ar' => 'مشاوي مشكلة: أسياخ وأجنحة وأطباق جانبية.'], 'p' => 120, 'img' => 'grill-spread', 'gal' => ['kebab-skewers'], 'kcal' => 1240, 'pro' => 92, 'carb' => 46, 'fat' => 74, 'alg' => [], 'grp' => ['tray', 'sauces'], 'ext' => ['bread', 'pickle'], 'spec' => true, 'rate' => 4.8, 'rc' => 84],
                ['n' => ['en' => 'Feast Roast Turkey', 'ar' => 'ديك رومي العائلة'], 'd' => ['en' => 'Whole carved turkey, roots, gravy boats.', 'ar' => 'ديك رومي كامل مع خضار جذرية وصوص الغريفي.'], 'p' => 145, 'img' => 'turkey-roast', 'kcal' => 1800, 'pro' => 160, 'carb' => 60, 'fat' => 90, 'gf' => true, 'alg' => [1, 9], 'grp' => ['tray'], 'rate' => 4.6, 'rc' => 29, 'stock' => 4],
                ['n' => ['en' => 'Seafood Rice for Four', 'ar' => 'أرز بحري لأربعة'], 'd' => ['en' => 'Saffron rice, mussels, prawns, crust corners.', 'ar' => 'أرز بالزعفران مع بلح البحر والروبيان.'], 'p' => 98, 'img' => 'paella', 'kcal' => 1440, 'pro' => 76, 'carb' => 156, 'fat' => 52, 'gf' => true, 'alg' => [3, 5], 'grp' => ['tray'], 'rate' => 4.6, 'rc' => 38],
                ['n' => ['en' => 'Whole Roast Chicken', 'ar' => 'دجاجة كاملة'], 'd' => ['en' => 'Brick-oven bird, garlic confit — for two.', 'ar' => 'دجاجة من الفرن الحجري مع الثوم — لشخصين.'], 'p' => 76, 'img' => 'chicken-roast', 'kcal' => 1380, 'pro' => 110, 'carb' => 22, 'fat' => 92, 'gf' => true, 'alg' => [], 'grp' => ['rice', 'sauces'], 'rate' => 4.7, 'rc' => 51],
            ],
        ],
        [
            'n' => ['en' => 'Homestyle Mains', 'ar' => 'أطباق البيت'], 'img' => 'stew-red',
            'items' => [
                ['n' => ['en' => 'Grandma\'s Chicken Stew', 'ar' => 'يخنة الدجاج'], 'd' => ['en' => 'Slow-simmered chicken, warm spices, herbs.', 'ar' => 'دجاج مطهو ببطء مع البهارات الدافئة.'], 'p' => 38, 'img' => 'stew-red', 'kcal' => 520, 'pro' => 36, 'carb' => 28, 'fat' => 30, 'gf' => true, 'alg' => [], 'ext' => ['bread'], 'rate' => 4.5, 'rc' => 41],
                ['n' => ['en' => 'Seven-Veg Tagine', 'ar' => 'طاجن الخضار'], 'd' => ['en' => 'Clay-pot vegetables, apricot, warm broth.', 'ar' => 'خضار في الفخار مع المشمش والمرق الدافئ.'], 'p' => 34, 'img' => 'tagine', 'kcal' => 420, 'pro' => 10, 'carb' => 64, 'fat' => 14, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.4, 'rc' => 26],
                ['n' => ['en' => 'Family Lasagna', 'ar' => 'لازانيا عائلية'], 'd' => ['en' => 'Seven layers, slow ragù, molten top.', 'ar' => 'سبع طبقات مع الراغو وجبن ذائب.'], 'p' => 46, 'img' => 'lasagna', 'kcal' => 680, 'pro' => 34, 'carb' => 54, 'fat' => 36, 'alg' => [1, 7], 'pop' => true, 'rate' => 4.6, 'rc' => 47],
                ['n' => ['en' => 'Feast Pizza XXL', 'ar' => 'بيتزا العائلة الكبيرة'], 'd' => ['en' => '16-inch loaded family pizza, 8 slices.', 'ar' => 'بيتزا ١٦ إنش محملة، ٨ قطع.'], 'p' => 58, 'img' => 'pizza-supreme', 'kcal' => 1880, 'pro' => 76, 'carb' => 184, 'fat' => 88, 'alg' => [1, 7], 'rate' => 4.5, 'rc' => 44],
                ['n' => ['en' => 'Broast Family Box', 'ar' => 'بوكس بروست عائلي'], 'd' => ['en' => '8-piece golden broast, toum, pickles.', 'ar' => 'بروست ذهبي ٨ قطع مع الثوم والمخلل.'], 'p' => 62, 'img' => 'fried-chicken-basket', 'kcal' => 1720, 'pro' => 92, 'carb' => 124, 'fat' => 92, 'alg' => [2, 7], 'grp' => ['sauces'], 'rate' => 4.6, 'rc' => 58],
            ],
        ],
        [
            'n' => ['en' => 'Kids Corner', 'ar' => 'ركن الأطفال'], 'img' => 'mac-cheese',
            'items' => [
                ['n' => ['en' => 'Kids Mac & Cheese', 'ar' => 'ماك آند تشيز للأطفال'], 'd' => ['en' => 'Creamy elbow macaroni, mild and cheesy.', 'ar' => 'معكرونة كريمية بالجبن للأطفال.'], 'p' => 18, 'img' => 'mac-cheese', 'kcal' => 480, 'pro' => 16, 'carb' => 48, 'fat' => 26, 'veg' => true, 'alg' => [1, 7], 'grp' => ['kids'], 'pop' => true, 'rate' => 4.5, 'rc' => 34],
                ['n' => ['en' => 'Kids Penne Rosa', 'ar' => 'بيني للأطفال'], 'd' => ['en' => 'Soft penne, mild tomato sauce.', 'ar' => 'بيني طرية مع صوص طماطم خفيف.'], 'p' => 16, 'img' => 'pasta-tomato', 'kcal' => 380, 'pro' => 12, 'carb' => 64, 'fat' => 8, 'veg' => true, 'alg' => [7], 'grp' => ['kids'], 'rate' => 4.3, 'rc' => 21],
                ['n' => ['en' => 'Kids Tenders (3)', 'ar' => 'أصابع دجاج للأطفال'], 'd' => ['en' => 'Mild crunchy tenders, ketchup.', 'ar' => 'أصابع مقرمشة خفيفة مع الكاتشب.'], 'p' => 18, 'img' => 'tenders', 'kcal' => 420, 'pro' => 26, 'carb' => 32, 'fat' => 20, 'alg' => [1, 2, 7], 'grp' => ['kids'], 'rate' => 4.4, 'rc' => 27],
                ['n' => ['en' => 'Mini Hot Dog', 'ar' => 'هوت دوغ صغير'], 'd' => ['en' => 'Soft roll, mild beef frank.', 'ar' => 'خبز طري مع نقانق خفيفة.'], 'p' => 14, 'img' => 'hot-dog', 'kcal' => 320, 'pro' => 12, 'carb' => 30, 'fat' => 18, 'alg' => [6, 7], 'grp' => ['kids'], 'rate' => 4.2, 'rc' => 15],
            ],
        ],
        [
            'n' => ['en' => 'Soups & Salads', 'ar' => 'شوربات وسلطات'], 'img' => 'soup-lentil',
            'items' => [
                ['n' => ['en' => 'Lentil Soup', 'ar' => 'شوربة عدس'], 'd' => ['en' => 'Warm lentil soup, lemon, croutons.', 'ar' => 'شوربة عدس دافئة مع الليمون والخبز المحمص.'], 'p' => 16, 'img' => 'soup-lentil', 'kcal' => 260, 'pro' => 12, 'carb' => 38, 'fat' => 6, 'veg' => true, 'vegan' => true, 'alg' => [7], 'pop' => true, 'rate' => 4.5, 'rc' => 39],
                ['n' => ['en' => 'Market Veggie Soup', 'ar' => 'شوربة الخضار'], 'd' => ['en' => 'Chunky vegetables, herb broth.', 'ar' => 'خضار موسمية مع مرق الأعشاب.'], 'p' => 16, 'img' => 'soup-tomato', 'kcal' => 200, 'pro' => 6, 'carb' => 28, 'fat' => 6, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.2, 'rc' => 17],
                ['n' => ['en' => 'Family Garden Salad', 'ar' => 'سلطة الحديقة'], 'd' => ['en' => 'Rainbow leaves, seeds, citrus dressing.', 'ar' => 'خضار ملونة مع بذور وصوص الحمضيات.'], 'p' => 22, 'img' => 'salad-garden', 'kcal' => 240, 'pro' => 6, 'carb' => 18, 'fat' => 16, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.3, 'rc' => 22],
                ['n' => ['en' => 'Fattoush Bowl', 'ar' => 'فتوش'], 'd' => ['en' => 'Crisp greens, sumac, pita crisps.', 'ar' => 'خضار مقرمشة مع السماق ورقائق الخبز.'], 'p' => 22, 'img' => 'fattoush-2', 'kcal' => 280, 'pro' => 8, 'carb' => 26, 'fat' => 16, 'veg' => true, 'alg' => [1, 7], 'rate' => 4.4, 'rc' => 25],
                ['n' => ['en' => 'Caesar for the Table', 'ar' => 'سيزر للطاولة'], 'd' => ['en' => 'Big-bowl caesar, parmesan, croutons.', 'ar' => 'سيزر بوعاء كبير مع البارميزان.'], 'p' => 28, 'img' => 'salad-caesar', 'kcal' => 460, 'pro' => 20, 'carb' => 24, 'fat' => 30, 'alg' => [1, 2, 3, 7], 'rate' => 4.3, 'rc' => 18],
            ],
        ],
        [
            'n' => ['en' => 'Desserts', 'ar' => 'حلويات'], 'img' => 'cupcakes',
            'items' => [
                ['n' => ['en' => 'Berry Lattice Pie', 'ar' => 'فطيرة التوت'], 'd' => ['en' => 'Buttery lattice, mixed-berry filling, share it.', 'ar' => 'عجينة الزبدة مع حشوة التوت — للمشاركة.'], 'p' => 26, 'img' => 'berry-pie', 'kcal' => 400, 'pro' => 5, 'carb' => 56, 'fat' => 18, 'veg' => true, 'alg' => [1, 7, 9], 'pop' => true, 'rate' => 4.5, 'rc' => 28],
                ['n' => ['en' => 'Party Cupcakes (4)', 'ar' => 'كب كيك الحفلة (٤)'], 'd' => ['en' => 'Cream swirls, sprinkles, kid-approved.', 'ar' => 'كب كيك بالكريمة والرشات.'], 'p' => 24, 'img' => 'cupcakes', 'kcal' => 760, 'pro' => 10, 'carb' => 104, 'fat' => 36, 'veg' => true, 'alg' => [1, 2, 4, 7], 'rate' => 4.3, 'rc' => 19],
                ['n' => ['en' => 'Seasonal Fruit Platter', 'ar' => 'طبق فواكه'], 'd' => ['en' => 'Carved fruit for the table.', 'ar' => 'فواكه مقطعة للطاولة.'], 'p' => 26, 'img' => 'fruit-platter', 'kcal' => 180, 'pro' => 2, 'carb' => 44, 'fat' => 1, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.4, 'rc' => 16],
                ['n' => ['en' => 'Warm Fudge Brownies', 'ar' => 'براوني دافئ'], 'd' => ['en' => 'Tray brownies, chocolate rivers.', 'ar' => 'براوني بأنهار الشوكولاتة.'], 'p' => 22, 'img' => 'brownie', 'kcal' => 420, 'pro' => 6, 'carb' => 52, 'fat' => 22, 'veg' => true, 'alg' => [1, 2, 4, 7], 'rate' => 4.5, 'rc' => 24],
            ],
        ],
        [
            'n' => ['en' => 'Drinks', 'ar' => 'مشروبات'], 'img' => 'lemonade',
            'items' => [
                ['n' => ['en' => 'Lemonade Jug', 'ar' => 'إبريق ليموناضة'], 'd' => ['en' => 'Family jug, pressed lemon, ice.', 'ar' => 'إبريق عائلي من الليمون المعصور.'], 'p' => 26, 'img' => 'lemonade', 'kcal' => 560, 'pro' => 0, 'carb' => 144, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'pop' => true, 'rate' => 4.5, 'rc' => 26],
                ['n' => ['en' => 'Fresh Orange Juice', 'ar' => 'عصير برتقال'], 'd' => ['en' => 'Squeezed to order.', 'ar' => 'يعصر عند الطلب.'], 'p' => 14, 'img' => 'juice-orange', 'kcal' => 120, 'pro' => 2, 'carb' => 28, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.4, 'rc' => 19],
                ['n' => ['en' => 'Tea Pot for the Table', 'ar' => 'إبريق شاي'], 'd' => ['en' => 'After-feast tea, poured all around.', 'ar' => 'شاي ما بعد الوليمة للجميع.'], 'p' => 16, 'img' => 'tea-pot', 'kcal' => 5, 'pro' => 0, 'carb' => 1, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.3, 'rc' => 14],
                ['n' => ['en' => 'Craft Cola', 'ar' => 'كولا'], 'd' => ['en' => 'Ice-cold glass bottle.', 'ar' => 'كولا باردة في زجاجة.'], 'p' => 8, 'img' => 'soda-cola', 'kcal' => 140, 'pro' => 0, 'carb' => 36, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.0, 'rc' => 9],
            ],
        ],
    ],
    'reviews' => [
        ['name' => 'Um Khalid', 'stars' => 5, 'body' => ['en' => 'Mandi tray fed six of us and everyone went quiet. Best sign.', 'ar' => 'صينية المندي أشبعت ستة وسكت الجميع — أفضل دليل.']],
        ['name' => 'Abu Fahad', 'stars' => 5, 'body' => ['en' => 'Kabsa daqqus is the real deal.', 'ar' => 'دقوس الكبسة أصلي فعلاً.']],
        ['name' => 'Maryam', 'stars' => 4, 'body' => ['en' => 'Kids corner saved our evening. Mac & cheese approved.', 'ar' => 'ركن الأطفال أنقذ سهرتنا والماك آند تشيز نال الرضا.']],
        ['name' => 'Saleh', 'stars' => 4, 'body' => ['en' => 'BBQ board is a mountain. Bring friends.', 'ar' => 'لوح المشاوي جبل — أحضر أصدقاءك.']],
        ['name' => 'Noor', 'stars' => 5, 'body' => ['en' => 'Warm brownies with the after-feast tea. Perfect ending.', 'ar' => 'براوني دافئ مع شاي ما بعد الوليمة — نهاية مثالية.']],
    ],
];
