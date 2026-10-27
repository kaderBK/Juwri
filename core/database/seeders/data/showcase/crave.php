<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/**
 * Crave House — dark app-style American grill showcase menu (WI-G2 pro rebuild).
 *
 * Shape consumed by ThemeShowcaseSeeder::buildProStore():
 * - groups:  library MenuVariantOption rows (additive option groups; themes read
 *            them as `globalGroups` via the item_modifier_group pivot, P1-06).
 * - extras:  library MenuExtra rows (priced add-ons w/ image; merged into `extras`).
 * - categories: two-tier tree. `children` = sub-categories (menu_categories.parent).
 * - items:   n/d = ['en'=>…,'ar'=>…]; img/gal reference photo keys in
 *            database/seeders/assets/showcase/<key>.jpg; alg = global allergy ids
 *            (1 Milk 2 Egg 3 Fish 4 Nuts 5 Shellfish 6 Soy 7 Wheat).
 */
return [
    'store' => [
        'title'    => 'Crave House',
        'subtitle' => 'American grill · smash burgers · late-night cravings',
        'description' => 'Charcoal-grilled smash burgers, wood-fired pizza and slow-smoked BBQ, served fast in a dark neon diner vibe.',
        'color'    => '#e0480f',
        'logo'     => 'burger-classic',
        'cover'    => 'diner-interior',
        'banners'  => ['burger-smash', 'pizza-oven', 'grill-spread'],
        'coupons'  => [['code' => 'CRAVE10', 'pct' => 10], ['code' => 'CRAVE20', 'pct' => 20]],
    ],

    'groups' => [
        'burger_patty' => ['t' => ['en' => 'Patty', 'ar' => 'قطع اللحم'], 'opts' => [['Single', 'مفرد', 0], ['Double', 'دبل', 9], ['Triple', 'ثلاثي', 16]], 'required' => true],
        'combo'        => ['t' => ['en' => 'Make it a combo', 'ar' => 'اجعلها وجبة'], 'opts' => [['Sandwich only', 'ساندويتش فقط', 0], ['Fries + drink', 'بطاطس + مشروب', 12]], 'required' => true],
        'pizza_size'   => ['t' => ['en' => 'Size', 'ar' => 'الحجم'], 'opts' => [['9"', '٩ إنش', 0], ['12"', '١٢ إنش', 12], ['16"', '١٦ إنش', 22]], 'required' => true],
        'crust'        => ['t' => ['en' => 'Crust', 'ar' => 'العجينة'], 'opts' => [['Classic', 'كلاسيك', 0], ['Thin', 'رفيعة', 0], ['Stuffed', 'محشية', 7]]],
        'heat'         => ['t' => ['en' => 'Heat level', 'ar' => 'مستوى الحرارة'], 'opts' => [['Mild', 'خفيف', 0], ['Medium', 'متوسط', 0], ['Extra hot', 'حار جداً', 0]]],
        'dips'         => ['t' => ['en' => 'Dips', 'ar' => 'الصوصات'], 'opts' => [['Garlic', 'ثوم', 2], ['BBQ', 'باربكيو', 2], ['Ranch', 'رانش', 2], ['Hot honey', 'عسل حار', 3]], 'min' => 0, 'max' => 4],
        'cup'          => ['t' => ['en' => 'Cup size', 'ar' => 'حجم الكوب'], 'opts' => [['Regular', 'عادي', 0], ['Large', 'كبير', 4]], 'required' => true],
        'milk'         => ['t' => ['en' => 'Milk', 'ar' => 'الحليب'], 'opts' => [['Fresh', 'طازج', 0], ['Oat', 'شوفان', 3], ['Almond', 'لوز', 3]]],
    ],

    'extras' => [
        'cheese'    => ['t' => ['en' => 'Cheese slice', 'ar' => 'شريحة جبن'], 'p' => 3, 'img' => 'cheese-platter'],
        'beefbacon' => ['t' => ['en' => 'Beef bacon', 'ar' => 'لحم مقدد بقري'], 'p' => 6, 'img' => 'brisket'],
        'patty'     => ['t' => ['en' => 'Extra patty', 'ar' => 'قطعة لحم إضافية'], 'p' => 9, 'img' => 'burger-classic'],
        'egg'       => ['t' => ['en' => 'Fried egg', 'ar' => 'بيضة مقلية'], 'p' => 4, 'img' => 'eggs-benedict'],
        'jalapeno'  => ['t' => ['en' => 'Jalapeños', 'ar' => 'هالبينو'], 'p' => 2, 'img' => 'nachos'],
        'rings'     => ['t' => ['en' => 'Onion rings (4)', 'ar' => 'حلقات بصل (٤)'], 'p' => 5, 'img' => 'onion-rings'],
        'trufffries' => ['t' => ['en' => 'Truffle fries side', 'ar' => 'بطاطس ترافل جانبية'], 'p' => 12, 'img' => 'fries-truffle'],
        'avocado'   => ['t' => ['en' => 'Avocado', 'ar' => 'أفوكادو'], 'p' => 5, 'img' => 'avocado-toast'],
        'shot'      => ['t' => ['en' => 'Extra espresso shot', 'ar' => 'جرعة إسبريسو إضافية'], 'p' => 5, 'img' => 'coffee-espresso'],
        'cream'     => ['t' => ['en' => 'Whipped cream', 'ar' => 'كريمة مخفوقة'], 'p' => 3, 'img' => 'milkshake'],
        'scoop'     => ['t' => ['en' => 'Ice-cream scoop', 'ar' => 'كرة آيس كريم'], 'p' => 6, 'img' => 'icecream-scoop'],
        'brownie'   => ['t' => ['en' => 'Brownie chunk', 'ar' => 'قطع براوني'], 'p' => 5, 'img' => 'brownie'],
    ],

    'categories' => [
        [
            'n' => ['en' => 'Starters', 'ar' => 'المقبلات'], 'img' => 'nachos',
            'items' => [
                ['n' => ['en' => 'Nachos Grande', 'ar' => 'ناتشوز غراندي'], 'd' => ['en' => 'Corn tortilla chips, cheese sauce, pico de gallo, jalapeños.', 'ar' => 'رقائق تورتيا مع صوص الجبن والبيكو دي جالو والهالبينو.'], 'p' => 26, 'img' => 'nachos', 'kcal' => 560, 'pro' => 14, 'carb' => 52, 'fat' => 32, 'alg' => [1, 7], 'grp' => ['dips'], 'ext' => ['jalapeno', 'cheese'], 'pop' => true, 'rate' => 4.7, 'rc' => 84],
                ['n' => ['en' => 'Crispy Onion Rings', 'ar' => 'حلقات البصل المقرمشة'], 'd' => ['en' => 'Beer-battered rings, smoked paprika salt, ranch dip.', 'ar' => 'حلقات بصل مقرمشة مع ملح البابريكا وصوص الرانش.'], 'p' => 18, 'img' => 'onion-rings', 'kcal' => 430, 'pro' => 6, 'carb' => 48, 'fat' => 24, 'veg' => true, 'alg' => [1, 7], 'grp' => ['dips'], 'rate' => 4.5, 'rc' => 51],
                ['n' => ['en' => 'Loaded Fries', 'ar' => 'بطاطس محملة'], 'd' => ['en' => 'Skin-on fries, cheese sauce, beef bacon bits, chives.', 'ar' => 'بطاطس بقشرتها مع صوص الجبن وقطع اللحم المقدد.'], 'p' => 22, 'img' => 'fries-loaded', 'kcal' => 610, 'pro' => 16, 'carb' => 58, 'fat' => 34, 'alg' => [1], 'ext' => ['cheese', 'jalapeno'], 'pop' => true, 'rate' => 4.6, 'rc' => 96],
                ['n' => ['en' => 'Truffle Parmesan Fries', 'ar' => 'بطاطس الترافل بالبارميزان'], 'd' => ['en' => 'Truffle oil, parmesan snow, chives — the fan favourite.', 'ar' => 'زيت الترافل وجبن البارميزان — الأكثر طلباً.'], 'p' => 24, 'img' => 'fries-truffle', 'kcal' => 480, 'pro' => 9, 'carb' => 52, 'fat' => 26, 'veg' => true, 'gf' => true, 'alg' => [1], 'spec' => true, 'rate' => 4.8, 'rc' => 132],
                ['n' => ['en' => 'Mac & Cheese Skillet', 'ar' => 'ماك آند تشيز'], 'd' => ['en' => 'Three-cheese elbow macaroni, toasted crumb top.', 'ar' => 'معكرونة بثلاثة أنواع جبن مع فتات محمص.'], 'p' => 24, 'img' => 'mac-cheese', 'kcal' => 640, 'pro' => 22, 'carb' => 60, 'fat' => 34, 'veg' => true, 'alg' => [1, 7], 'rate' => 4.4, 'rc' => 47],
                ['n' => ['en' => 'Charred Corn', 'ar' => 'ذرة مشوية'], 'd' => ['en' => 'Grilled corn, chili-lime butter, cotija-style cheese.', 'ar' => 'ذرة مشوية مع زبدة الليمون الحار والجبن.'], 'p' => 14, 'img' => 'corn-grilled', 'kcal' => 260, 'pro' => 6, 'carb' => 34, 'fat' => 12, 'veg' => true, 'gf' => true, 'alg' => [1, 9], 'rate' => 4.3, 'rc' => 29],
            ],
        ],
        [
            'n' => ['en' => 'Burgers', 'ar' => 'برجر'], 'img' => 'burger-smash',
            'children' => [
                [
                    'n' => ['en' => 'Beef Burgers', 'ar' => 'برجر لحم'], 'img' => 'burger-classic',
                    'items' => [
                        ['n' => ['en' => 'Classic Deluxe', 'ar' => 'كلاسيك ديلوكس'], 'd' => ['en' => 'Charcoal-grilled beef, lettuce, tomato, pickles, house mayo.', 'ar' => 'لحم مشوي على الفحم مع خس وطماطم ومخلل ومايونيز البيت.'], 'p' => 28, 'img' => 'burger-classic', 'gal' => ['burger-cheese', 'burger-fries-combo'], 'kcal' => 540, 'pro' => 28, 'carb' => 42, 'fat' => 26, 'alg' => [1, 2, 7], 'grp' => ['burger_patty', 'combo'], 'ext' => ['cheese', 'beefbacon', 'egg', 'jalapeno'], 'pop' => true, 'spec' => true, 'rate' => 4.8, 'rc' => 214],
                        ['n' => ['en' => 'Smoky Smash', 'ar' => 'سماش مدخن'], 'd' => ['en' => 'Two smashed patties, caramelised onion, smoked cheddar, burnt-butter mayo.', 'ar' => 'قطعتا سماش مع بصل مكرمل وشيدر مدخن ومايو الزبدة.'], 'p' => 34, 'img' => 'burger-smash', 'gal' => ['burger-double'], 'kcal' => 680, 'pro' => 35, 'carb' => 40, 'fat' => 38, 'spice' => 1, 'alg' => [1, 7, 9], 'grp' => ['combo'], 'ext' => ['patty', 'cheese', 'jalapeno'], 'pop' => true, 'rate' => 4.9, 'rc' => 187],
                        ['n' => ['en' => 'Double Trouble', 'ar' => 'دبل ترابل'], 'd' => ['en' => 'Double beef, double cheese, secret sauce, brioche bun.', 'ar' => 'لحم دبل وجبن دبل مع الصوص السري وخبز بريوش.'], 'p' => 38, 'img' => 'burger-double', 'kcal' => 820, 'pro' => 44, 'carb' => 44, 'fat' => 48, 'alg' => [1, 2, 7], 'grp' => ['combo'], 'ext' => ['beefbacon', 'egg'], 'rate' => 4.7, 'rc' => 143],
                        ['n' => ['en' => 'Bacon Stack', 'ar' => 'ستاك اللحم المقدد'], 'd' => ['en' => 'Beef bacon, crispy shallots, cheddar, BBQ glaze, served with fries.', 'ar' => 'لحم مقدد بقري مع بصل مقرمش وشيدر وصوص باربكيو، تقدم مع البطاطس.'], 'p' => 42, 'img' => 'burger-bacon', 'kcal' => 890, 'pro' => 46, 'carb' => 58, 'fat' => 52, 'alg' => [1, 7], 'grp' => ['burger_patty'], 'ext' => ['cheese', 'rings'], 'rate' => 4.6, 'rc' => 98],
                        ['n' => ['en' => 'Truffle Gourmet', 'ar' => 'ترافل غورميه'], 'd' => ['en' => 'Wagyu-blend patty, truffle aioli, rocket, aged cheddar.', 'ar' => 'لحم واغيو مع أيولي الترافل والجرجير والشيدر المعتق.'], 'p' => 52, 'img' => 'burger-gourmet', 'kcal' => 720, 'pro' => 38, 'carb' => 38, 'fat' => 44, 'alg' => [1, 2, 7], 'ext' => ['trufffries', 'egg'], 'spec' => true, 'rate' => 4.9, 'rc' => 76],
                        ['n' => ['en' => 'Flame Combo', 'ar' => 'كومبو اللهب'], 'd' => ['en' => 'Flame-grilled classic with seasoned fries and a cold drink.', 'ar' => 'برجر مشوي على اللهب مع بطاطس متبلة ومشروب بارد.'], 'p' => 39, 'img' => 'burger-fries-combo', 'kcal' => 980, 'pro' => 40, 'carb' => 92, 'fat' => 50, 'alg' => [1, 7], 'grp' => ['burger_patty'], 'rate' => 4.5, 'rc' => 121],
                    ],
                ],
                [
                    'n' => ['en' => 'Chicken & Sandwiches', 'ar' => 'دجاج وساندويتشات'], 'img' => 'burger-chicken',
                    'items' => [
                        ['n' => ['en' => 'Crispy Chicken Burger', 'ar' => 'برجر دجاج مقرمش'], 'd' => ['en' => 'Buttermilk-fried thigh, slaw, house pickle sauce, brioche.', 'ar' => 'دجاج مقرمش مع سلطة الملفوف وصوص المخلل في خبز بريوش.'], 'p' => 26, 'img' => 'burger-chicken', 'kcal' => 610, 'pro' => 31, 'carb' => 48, 'fat' => 30, 'spice' => 1, 'alg' => [1, 2, 7], 'grp' => ['combo', 'heat'], 'ext' => ['cheese', 'jalapeno'], 'pop' => true, 'rate' => 4.7, 'rc' => 156],
                        ['n' => ['en' => 'Diner Cheese Melt', 'ar' => 'ميلت الجبن'], 'd' => ['en' => 'Griddled beef, three cheeses, toasted sesame bun.', 'ar' => 'لحم مشوي مع ثلاثة أجبان في خبز السمسم المحمص.'], 'p' => 30, 'img' => 'burger-cheese', 'kcal' => 700, 'pro' => 36, 'carb' => 42, 'fat' => 42, 'alg' => [1, 7], 'grp' => ['combo'], 'rate' => 4.6, 'rc' => 88],
                        ['n' => ['en' => 'Route 66 Combo', 'ar' => 'كومبو روت ٦٦'], 'd' => ['en' => 'Diner-style cheeseburger with crinkle fries, retro tray.', 'ar' => 'تشيز برجر على طريقة المطاعم الأمريكية مع بطاطس مموجة.'], 'p' => 36, 'img' => 'burger-fries-2', 'kcal' => 920, 'pro' => 42, 'carb' => 84, 'fat' => 48, 'alg' => [1, 7], 'rate' => 4.4, 'rc' => 63],
                        ['n' => ['en' => 'Crispy Slaw Sandwich', 'ar' => 'ساندويتش السلو المقرمش'], 'd' => ['en' => 'Fried chicken, purple slaw, chipotle mayo, potato bun.', 'ar' => 'دجاج مقلي مع سلطة الملفوف البنفسجي ومايو الشيبوتلي.'], 'p' => 28, 'img' => 'sandwich-crispy', 'kcal' => 640, 'pro' => 30, 'carb' => 52, 'fat' => 34, 'spice' => 2, 'alg' => [1, 2, 7], 'grp' => ['heat'], 'rate' => 4.5, 'rc' => 44],
                        ['n' => ['en' => 'NY Street Dog', 'ar' => 'هوت دوغ نيويورك'], 'd' => ['en' => 'Beef frank, crispy onions, mustard, ketchup, soft roll.', 'ar' => 'نقانق بقري مع بصل مقرمش وخردل وكاتشب.'], 'p' => 18, 'img' => 'hot-dog', 'kcal' => 420, 'pro' => 16, 'carb' => 36, 'fat' => 24, 'alg' => [6, 7], 'ext' => ['cheese', 'jalapeno'], 'rate' => 4.2, 'rc' => 38],
                        ['n' => ['en' => 'Club House Toastie', 'ar' => 'كلوب هاوس'], 'd' => ['en' => 'Triple-decker chicken club, turkey rashers, egg, fries.', 'ar' => 'كلوب ساندويتش ثلاثي الطبقات مع دجاج وبيض وبطاطس.'], 'p' => 32, 'img' => 'club-sandwich-2', 'kcal' => 760, 'pro' => 38, 'carb' => 62, 'fat' => 38, 'alg' => [1, 2, 7], 'rate' => 4.6, 'rc' => 57],
                        ['n' => ['en' => 'Griddled Cheese Toast', 'ar' => 'توست الجبن'], 'd' => ['en' => 'Sourdough, molten cheese pull, tomato dip trio.', 'ar' => 'خبز محمص مع جبن ذائب وثلاثة صوصات.'], 'p' => 20, 'img' => 'sandwich-club', 'kcal' => 520, 'pro' => 20, 'carb' => 44, 'fat' => 30, 'veg' => true, 'alg' => [1, 7], 'rate' => 4.3, 'rc' => 25],
                    ],
                ],
            ],
        ],
        [
            'n' => ['en' => 'Wood-Fired Pizza', 'ar' => 'بيتزا على الحطب'], 'img' => 'pizza-oven',
            'children' => [
                [
                    'n' => ['en' => 'Classics', 'ar' => 'كلاسيك'], 'img' => 'pizza-margherita',
                    'items' => [
                        ['n' => ['en' => 'Margherita', 'ar' => 'مارغريتا'], 'd' => ['en' => 'San Marzano tomato, fior di latte, basil, olive oil.', 'ar' => 'طماطم سان مارزانو وموزاريلا وريحان وزيت زيتون.'], 'p' => 32, 'img' => 'pizza-margherita', 'gal' => ['pizza-neapolitan'], 'kcal' => 820, 'pro' => 30, 'carb' => 96, 'fat' => 32, 'veg' => true, 'alg' => [1, 7], 'grp' => ['pizza_size', 'crust'], 'pop' => true, 'rate' => 4.7, 'rc' => 165],
                        ['n' => ['en' => 'Double Pepperoni', 'ar' => 'دبل ببروني'], 'd' => ['en' => 'Loaded beef pepperoni, mozzarella, hot honey drizzle.', 'ar' => 'ببروني بقري وموزاريلا مع رشة عسل حار.'], 'p' => 42, 'img' => 'pizza-supreme', 'gal' => ['pizza-pepperoni'], 'kcal' => 940, 'pro' => 38, 'carb' => 92, 'fat' => 44, 'spice' => 1, 'alg' => [1, 7], 'grp' => ['pizza_size', 'crust'], 'pop' => true, 'spec' => true, 'rate' => 4.8, 'rc' => 198],
                        ['n' => ['en' => 'Napoli Wood-Fired', 'ar' => 'نابولي على الحطب'], 'd' => ['en' => 'Leopard-spotted crust, buffalo mozzarella, torn basil.', 'ar' => 'عجينة نابولية مع موزاريلا الجاموس والريحان.'], 'p' => 38, 'img' => 'pizza-neapolitan', 'kcal' => 780, 'pro' => 28, 'carb' => 90, 'fat' => 30, 'veg' => true, 'alg' => [1, 7], 'grp' => ['pizza_size'], 'rate' => 4.6, 'rc' => 74],
                        ['n' => ['en' => 'Oven Veggie', 'ar' => 'بيتزا الخضار'], 'd' => ['en' => 'Mushroom, cherry tomato, olives, rosemary, garlic oil.', 'ar' => 'فطر وطماطم كرزية وزيتون وإكليل الجبل.'], 'p' => 34, 'img' => 'pizza-oven', 'kcal' => 720, 'pro' => 24, 'carb' => 88, 'fat' => 26, 'veg' => true, 'alg' => [1, 7], 'grp' => ['pizza_size', 'crust'], 'rate' => 4.4, 'rc' => 52],
                    ],
                ],
                [
                    'n' => ['en' => 'Signatures', 'ar' => 'التوقيعات'], 'img' => 'pizza-bbq',
                    'items' => [
                        ['n' => ['en' => 'BBQ Chicken Ranch', 'ar' => 'دجاج باربكيو رانش'], 'd' => ['en' => 'Smoked BBQ base, grilled chicken, ranch swirl, chives.', 'ar' => 'صوص باربكيو مدخن مع دجاج مشوي ورانش.'], 'p' => 44, 'img' => 'pizza-bbq', 'kcal' => 880, 'pro' => 40, 'carb' => 86, 'fat' => 38, 'alg' => [1, 7], 'grp' => ['pizza_size', 'crust'], 'pop' => true, 'rate' => 4.7, 'rc' => 112],
                        ['n' => ['en' => 'Rocket & Parm', 'ar' => 'جرجير وبارميزان'], 'd' => ['en' => 'Fresh arugula, shaved parmesan, balsamic glaze.', 'ar' => 'جرجير طازج مع بارميزان مبشور وخل بلسمي.'], 'p' => 40, 'img' => 'pizza-arugula', 'kcal' => 740, 'pro' => 28, 'carb' => 82, 'fat' => 30, 'veg' => true, 'alg' => [1, 7], 'grp' => ['pizza_size'], 'rate' => 4.5, 'rc' => 41],
                        ['n' => ['en' => 'Greek Feta', 'ar' => 'فيتا يونانية'], 'd' => ['en' => 'Feta, kalamata olives, red onion, oregano oil.', 'ar' => 'جبنة فيتا وزيتون كالاماتا وبصل أحمر وأوريغانو.'], 'p' => 42, 'img' => 'pizza-feta', 'kcal' => 800, 'pro' => 30, 'carb' => 84, 'fat' => 36, 'veg' => true, 'alg' => [1, 7], 'grp' => ['pizza_size', 'crust'], 'rate' => 4.6, 'rc' => 36],
                        ['n' => ['en' => 'Garden Supreme', 'ar' => 'سوبريم الحديقة'], 'd' => ['en' => 'Charred greens, mushroom, ricotta, chili flakes.', 'ar' => 'خضار مشوية وفطر وريكوتا ورقائق الفلفل الحار.'], 'p' => 38, 'img' => 'pizza-veggie', 'kcal' => 700, 'pro' => 26, 'carb' => 80, 'fat' => 28, 'veg' => true, 'spice' => 1, 'alg' => [1, 7], 'grp' => ['pizza_size'], 'rate' => 4.3, 'rc' => 28],
                    ],
                ],
            ],
        ],
        [
            'n' => ['en' => 'Fried & Wings', 'ar' => 'مقليات وأجنحة'], 'img' => 'wings-korean',
            'items' => [
                ['n' => ['en' => 'Korean Gochujang Wings', 'ar' => 'أجنحة كورية'], 'd' => ['en' => 'Double-fried wings, gochujang glaze, sesame, scallion.', 'ar' => 'أجنحة مقلية مرتين بصوص الغوتشوجانغ والسمسم.'], 'p' => 32, 'img' => 'wings-korean', 'gal' => ['smoked-wings'], 'kcal' => 720, 'pro' => 42, 'carb' => 38, 'fat' => 40, 'spice' => 3, 'alg' => [6, 7], 'grp' => ['heat', 'dips'], 'pop' => true, 'rate' => 4.8, 'rc' => 174, 'stock' => 8],
                ['n' => ['en' => 'Smoked Honey Wings', 'ar' => 'أجنحة مدخنة بالعسل'], 'd' => ['en' => 'Slow-smoked, honey-glazed, pickled onion, white sauce.', 'ar' => 'أجنحة مدخنة بالعسل مع بصل مخلل وصوص أبيض.'], 'p' => 34, 'img' => 'smoked-wings', 'kcal' => 680, 'pro' => 40, 'carb' => 34, 'fat' => 38, 'spice' => 1, 'alg' => [1], 'grp' => ['dips'], 'rate' => 4.7, 'rc' => 92],
                ['n' => ['en' => 'Buttermilk Tenders', 'ar' => 'أصابع الدجاج'], 'd' => ['en' => 'Crunchy tenders, honey-mustard, toasted brioche.', 'ar' => 'أصابع دجاج مقرمشة مع صوص العسل والخردل.'], 'p' => 28, 'img' => 'tenders', 'kcal' => 560, 'pro' => 34, 'carb' => 42, 'fat' => 26, 'alg' => [1, 2, 7], 'grp' => ['dips'], 'rate' => 4.4, 'rc' => 59],
                ['n' => ['en' => 'Southern Fried Chicken', 'ar' => 'دجاج مقلي جنوبي'], 'd' => ['en' => '24-hour brined, pressure-fried, secret 11-spice dust.', 'ar' => 'دجاج منقوع ٢٤ ساعة ومقلي بخلطة ١١ بهار سرية.'], 'p' => 36, 'img' => 'fried-chicken', 'gal' => ['fried-chicken-basket'], 'kcal' => 780, 'pro' => 48, 'carb' => 44, 'fat' => 44, 'spice' => 1, 'alg' => [2, 7], 'grp' => ['heat'], 'pop' => true, 'rate' => 4.7, 'rc' => 141],
                ['n' => ['en' => 'Crispy Basket', 'ar' => 'سلة المقرمشات'], 'd' => ['en' => 'Fried chicken pieces, waffle chips, gravy pot.', 'ar' => 'قطع دجاج مقرمشة مع رقائق الوافل وصوص الغريفي.'], 'p' => 38, 'img' => 'fried-chicken-basket', 'kcal' => 860, 'pro' => 46, 'carb' => 62, 'fat' => 46, 'alg' => [1, 2, 7], 'rate' => 4.5, 'rc' => 47],
                ['n' => ['en' => 'Broast Platter', 'ar' => 'صحن بروست'], 'd' => ['en' => 'Golden broasted chicken, garlic toum, pickles.', 'ar' => 'بروست ذهبي مع الثوم والمخلل.'], 'p' => 34, 'img' => 'chicken-broast', 'kcal' => 800, 'pro' => 44, 'carb' => 52, 'fat' => 44, 'alg' => [2, 7], 'grp' => ['heat', 'dips'], 'rate' => 4.6, 'rc' => 83],
                ['n' => ['en' => 'Fish & Chips', 'ar' => 'سمك وبطاطس'], 'd' => ['en' => 'Crispy battered fillet, thick-cut chips, tartare.', 'ar' => 'فيليه سمك مقرمش مع بطاطس وصوص التارتار.'], 'p' => 38, 'img' => 'fish-chips', 'kcal' => 720, 'pro' => 34, 'carb' => 66, 'fat' => 36, 'alg' => [2, 3, 7], 'rate' => 4.4, 'rc' => 39],
                ['n' => ['en' => 'Mandu Momo (6 pcs)', 'ar' => 'ماندو مومو (٦ قطع)'], 'd' => ['en' => 'Steamed chicken dumplings, soy-chili dip.', 'ar' => 'زلابية دجاج على البخار مع صوص الصويا الحار.'], 'p' => 22, 'img' => 'momo-dumplings', 'kcal' => 380, 'pro' => 20, 'carb' => 44, 'fat' => 12, 'spice' => 1, 'alg' => [6, 7], 'rate' => 4.3, 'rc' => 31],
            ],
        ],
        [
            'n' => ['en' => 'From the Grill', 'ar' => 'من المشواة'], 'img' => 'steak-grill',
            'items' => [
                ['n' => ['en' => 'Ribeye & Frites', 'ar' => 'ريب آي مع بطاطس'], 'd' => ['en' => '300g grain-fed ribeye, herb butter, skin-on frites.', 'ar' => 'ريب آي ٣٠٠غ مع زبدة الأعشاب والبطاطس.'], 'p' => 95, 'img' => 'steak-ribeye', 'kcal' => 980, 'pro' => 62, 'carb' => 42, 'fat' => 62, 'gf' => true, 'alg' => [1, 9], 'grp' => ['heat'], 'pop' => true, 'rate' => 4.8, 'rc' => 87],
                ['n' => ['en' => 'Sliced Sirloin Board', 'ar' => 'سيرلوين مقطع'], 'd' => ['en' => 'Char-grilled sirloin, asparagus, roasted carrots.', 'ar' => 'سيرلوين مشوي مع الهليون والجزر المحمص.'], 'p' => 82, 'img' => 'steak-sliced', 'kcal' => 760, 'pro' => 56, 'carb' => 24, 'fat' => 48, 'gf' => true, 'alg' => [], 'rate' => 4.7, 'rc' => 54],
                ['n' => ['en' => 'Slow BBQ Ribs', 'ar' => 'أضلاع باربكيو'], 'd' => ['en' => 'Half-rack, 8-hour smoke, house BBQ mop, slaw.', 'ar' => 'أضلاع مدخنة ٨ ساعات مع صوص باربكيو وسلطة ملفوف.'], 'p' => 78, 'img' => 'ribs-bbq', 'gal' => ['brisket'], 'kcal' => 920, 'pro' => 58, 'carb' => 38, 'fat' => 58, 'alg' => [6], 'spec' => true, 'rate' => 4.9, 'rc' => 103],
                ['n' => ['en' => 'Smoked Brisket Plate', 'ar' => 'صحن البريسكت'], 'd' => ['en' => '12-hour oak-smoked brisket, burnt ends, pickles.', 'ar' => 'بريسكت مدخن ١٢ ساعة مع الأطراف المحروقة والمخلل.'], 'p' => 72, 'img' => 'brisket', 'kcal' => 840, 'pro' => 54, 'carb' => 22, 'fat' => 56, 'gf' => true, 'alg' => [], 'rate' => 4.7, 'rc' => 66, 'stock' => 5],
                ['n' => ['en' => 'Grilled Chicken Plate', 'ar' => 'صحن دجاج مشوي'], 'd' => ['en' => 'Marinated breast, buttered corn & peas, mash.', 'ar' => 'صدر دجاج متبل مع ذرة بالزبدة وبطاطس مهروسة.'], 'p' => 42, 'img' => 'grilled-chicken', 'kcal' => 560, 'pro' => 48, 'carb' => 34, 'fat' => 22, 'gf' => true, 'alg' => [1, 9], 'grp' => ['heat'], 'rate' => 4.5, 'rc' => 71],
                ['n' => ['en' => 'Kebab & Fries', 'ar' => 'كباب مع بطاطس'], 'd' => ['en' => 'Char-grilled beef skewers, grilled tomato, fries.', 'ar' => 'أسياخ لحم مشوية على الفحم مع طماطم وبطاطس.'], 'p' => 48, 'img' => 'kebab-fries', 'kcal' => 720, 'pro' => 44, 'carb' => 48, 'fat' => 38, 'alg' => [], 'rate' => 4.6, 'rc' => 49],
            ],
        ],
        [
            'n' => ['en' => 'Pasta & Bowls', 'ar' => 'باستا وأطباق'], 'img' => 'pasta-carbonara',
            'items' => [
                ['n' => ['en' => 'Carbonara', 'ar' => 'كاربونارا'], 'd' => ['en' => 'Silky egg-yolk sauce, turkey rashers, pecorino.', 'ar' => 'صوص صفار البيض الحريري مع شرائح الديك الرومي وجبن البيكورينو.'], 'p' => 38, 'img' => 'pasta-carbonara', 'kcal' => 680, 'pro' => 28, 'carb' => 72, 'fat' => 32, 'alg' => [1, 2, 7], 'pop' => true, 'rate' => 4.6, 'rc' => 77],
                ['n' => ['en' => 'Penne Arrabbiata', 'ar' => 'بيني أرابياتا'], 'd' => ['en' => 'Fiery tomato-garlic sugo, basil, chili flakes.', 'ar' => 'صوص طماطم حار مع الثوم والريحان.'], 'p' => 32, 'img' => 'pasta-tomato', 'kcal' => 560, 'pro' => 18, 'carb' => 84, 'fat' => 16, 'veg' => true, 'vegan' => true, 'spice' => 2, 'alg' => [7], 'grp' => ['heat'], 'rate' => 4.4, 'rc' => 42],
                ['n' => ['en' => 'Alfredo Fettuccine', 'ar' => 'فيتوتشيني ألفريدو'], 'd' => ['en' => 'Triple-cream parmesan sauce, cracked pepper.', 'ar' => 'صوص البارميزان الكريمي مع الفلفل الأسود.'], 'p' => 36, 'img' => 'pasta-alfredo', 'kcal' => 720, 'pro' => 24, 'carb' => 76, 'fat' => 38, 'veg' => true, 'alg' => [1, 7], 'rate' => 4.5, 'rc' => 58],
                ['n' => ['en' => 'Shrimp Aglio Pan', 'ar' => 'باستا الروبيان'], 'd' => ['en' => 'Garlic-butter shrimp, cherry tomato, linguine.', 'ar' => 'روبيان بزبدة الثوم مع الطماطم الكرزية واللينغويني.'], 'p' => 48, 'img' => 'pasta-shrimp', 'kcal' => 640, 'pro' => 32, 'carb' => 70, 'fat' => 26, 'alg' => [1, 5, 7, 9], 'rate' => 4.7, 'rc' => 45],
                ['n' => ['en' => 'Midnight Truffle Spaghetti', 'ar' => 'سباغيتي الترافل'], 'd' => ['en' => 'Black-pepper emulsion, truffle oil, aged parm.', 'ar' => 'مستحلب الفلفل الأسود مع زيت الترافل والبارميزان.'], 'p' => 44, 'img' => 'truffle-pasta', 'kcal' => 620, 'pro' => 22, 'carb' => 74, 'fat' => 28, 'veg' => true, 'alg' => [1, 7], 'spec' => true, 'rate' => 4.8, 'rc' => 34],
                ['n' => ['en' => 'Salmon Poke Bowl', 'ar' => 'بوكي السلمون'], 'd' => ['en' => 'Sushi rice, salmon cubes, edamame, avocado, ponzu.', 'ar' => 'أرز سوشي مع مكعبات السلمون والإدامامي والأفوكادو.'], 'p' => 46, 'img' => 'poke-bowl', 'kcal' => 520, 'pro' => 30, 'carb' => 58, 'fat' => 20, 'gf' => true, 'alg' => [3, 6], 'pop' => true, 'rate' => 4.7, 'rc' => 62],
                ['n' => ['en' => 'Harvest Grain Bowl', 'ar' => 'طبق الحبوب'], 'd' => ['en' => 'Quinoa, roasted veg, seeds, citrus tahini.', 'ar' => 'كينوا مع خضار محمصة وبذور وطحينة الحمضيات.'], 'p' => 34, 'img' => 'bowl-grain', 'kcal' => 440, 'pro' => 14, 'carb' => 56, 'fat' => 18, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [4], 'rate' => 4.3, 'rc' => 27],
                ['n' => ['en' => 'Wok Fried Rice', 'ar' => 'أرز مقلي'], 'd' => ['en' => 'Smoky wok rice, egg ribbons, scallion, soy glaze.', 'ar' => 'أرز مقلي على النار العالية مع البيض والبصل الأخضر.'], 'p' => 28, 'img' => 'rice-fried', 'kcal' => 520, 'pro' => 16, 'carb' => 78, 'fat' => 16, 'alg' => [2, 6], 'rate' => 4.2, 'rc' => 33],
                ['n' => ['en' => 'Seoul Bibimbap', 'ar' => 'بيبيمباب'], 'd' => ['en' => 'Stone-bowl rice, seasoned veg, fried egg, gochujang.', 'ar' => 'أرز بالخضار المتبلة والبيض المقلي وصوص الغوتشوجانغ.'], 'p' => 38, 'img' => 'bibimbap', 'kcal' => 580, 'pro' => 22, 'carb' => 76, 'fat' => 20, 'spice' => 2, 'alg' => [2, 6], 'grp' => ['heat'], 'rate' => 4.5, 'rc' => 29],
            ],
        ],
        [
            'n' => ['en' => 'Salads & Soups', 'ar' => 'سلطات وشوربات'], 'img' => 'salad-caesar',
            'items' => [
                ['n' => ['en' => 'Chicken Caesar', 'ar' => 'سيزر بالدجاج'], 'd' => ['en' => 'Baby gem, grilled chicken, parm, torn croutons.', 'ar' => 'خس صغير مع دجاج مشوي وبارميزان وخبز محمص.'], 'p' => 34, 'img' => 'salad-caesar', 'gal' => ['caesar-2'], 'kcal' => 480, 'pro' => 34, 'carb' => 22, 'fat' => 28, 'alg' => [1, 2, 3, 7], 'pop' => true, 'rate' => 4.6, 'rc' => 69],
                ['n' => ['en' => 'Garden Crunch', 'ar' => 'سلطة الحديقة'], 'd' => ['en' => 'Rainbow leaves, avocado, seeds, citrus dressing.', 'ar' => 'خضار ملونة مع أفوكادو وبذور وصوص الحمضيات.'], 'p' => 28, 'img' => 'salad-garden', 'kcal' => 240, 'pro' => 6, 'carb' => 18, 'fat' => 16, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.4, 'rc' => 35],
                ['n' => ['en' => 'Kale & Chickpea', 'ar' => 'كيل وحمص'], 'd' => ['en' => 'Massaged kale, crispy chickpeas, lemon tahini.', 'ar' => 'كرنب أخضر مع حمص مقرمش وطحينة الليمون.'], 'p' => 26, 'img' => 'salad-greek', 'kcal' => 300, 'pro' => 12, 'carb' => 30, 'fat' => 16, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.2, 'rc' => 22],
                ['n' => ['en' => 'Roast Tomato Soup', 'ar' => 'شوربة الطماطم'], 'd' => ['en' => 'Fire-roasted tomato, basil oil, sourdough soldier.', 'ar' => 'طماطم مشوية مع زيت الريحان وخبز محمص.'], 'p' => 20, 'img' => 'soup-lentil', 'kcal' => 260, 'pro' => 6, 'carb' => 30, 'fat' => 12, 'veg' => true, 'alg' => [1, 7], 'rate' => 4.3, 'rc' => 26],
                ['n' => ['en' => 'Market Veggie Soup', 'ar' => 'شوربة الخضار'], 'd' => ['en' => 'Chunky seasonal vegetables, herb broth.', 'ar' => 'خضار موسمية مع مرق الأعشاب.'], 'p' => 18, 'img' => 'soup-tomato', 'kcal' => 200, 'pro' => 6, 'carb' => 28, 'fat' => 6, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.1, 'rc' => 18],
                ['n' => ['en' => 'Pumpkin Velvet', 'ar' => 'شوربة اليقطين'], 'd' => ['en' => 'Silky roast pumpkin, toasted seeds, chili oil dot.', 'ar' => 'يقطين محمص مع بذور محمصة ونقاط زيت الفلفل.'], 'p' => 22, 'img' => 'pumpkin-soup', 'kcal' => 240, 'pro' => 5, 'carb' => 30, 'fat' => 12, 'veg' => true, 'gf' => true, 'alg' => [1], 'rate' => 4.4, 'rc' => 21],
                ['n' => ['en' => 'Green Detox Bowl', 'ar' => 'شوربة الديتوكس'], 'd' => ['en' => 'Broccoli, spinach, coconut cream, coriander.', 'ar' => 'بروكلي وسبانخ مع كريمة جوز الهند والكزبرة.'], 'p' => 22, 'img' => 'soup-green', 'kcal' => 220, 'pro' => 7, 'carb' => 22, 'fat' => 14, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.0, 'rc' => 14],
                ['n' => ['en' => 'Late-Night Noodle Soup', 'ar' => 'شوربة النودلز'], 'd' => ['en' => 'Springy noodles, veg broth, chili crisp on top.', 'ar' => 'نودلز مع مرق الخضار وزيت الفلفل المقرمش.'], 'p' => 24, 'img' => 'noodles-soup', 'kcal' => 380, 'pro' => 12, 'carb' => 60, 'fat' => 10, 'veg' => true, 'spice' => 2, 'alg' => [6, 7], 'rate' => 4.2, 'rc' => 17],
            ],
        ],
        [
            'n' => ['en' => 'All-Day Breakfast', 'ar' => 'فطور طوال اليوم'], 'img' => 'pancakes',
            'items' => [
                ['n' => ['en' => 'Buttermilk Pancakes', 'ar' => 'بان كيك'], 'd' => ['en' => 'Tall stack, maple syrup, blueberries, banana.', 'ar' => 'طبقات البان كيك مع شراب القيقب والتوت والموز.'], 'p' => 28, 'img' => 'pancakes', 'kcal' => 620, 'pro' => 14, 'carb' => 96, 'fat' => 20, 'veg' => true, 'alg' => [1, 2, 7], 'pop' => true, 'rate' => 4.7, 'rc' => 91],
                ['n' => ['en' => 'Golden Waffles', 'ar' => 'وافل ذهبي'], 'd' => ['en' => 'Crisp Belgian waffles, berry compote, butter pat.', 'ar' => 'وافل بلجيكي مقرمش مع كومبوت التوت والزبدة.'], 'p' => 26, 'img' => 'waffles', 'kcal' => 560, 'pro' => 12, 'carb' => 82, 'fat' => 22, 'veg' => true, 'alg' => [1, 2, 7, 9], 'rate' => 4.5, 'rc' => 48],
                ['n' => ['en' => 'Avocado Smash Toast', 'ar' => 'توست الأفوكادو'], 'd' => ['en' => 'Sourdough, smashed avocado, soft egg, dukkah.', 'ar' => 'خبز محمص مع أفوكادو مهروس وبيض والدقة.'], 'p' => 30, 'img' => 'avocado-toast', 'kcal' => 420, 'pro' => 16, 'carb' => 38, 'fat' => 24, 'veg' => true, 'alg' => [2, 4, 7], 'rate' => 4.6, 'rc' => 57],
                ['n' => ['en' => 'Eggs Benedict', 'ar' => 'بيض بينديكت'], 'd' => ['en' => 'Poached eggs, smoked salmon, hollandaise, muffin.', 'ar' => 'بيض مسلوق مع سلمون مدخن وصوص الهولنديز.'], 'p' => 36, 'img' => 'eggs-benedict', 'kcal' => 540, 'pro' => 26, 'carb' => 30, 'fat' => 36, 'alg' => [1, 2, 3, 7, 9], 'rate' => 4.7, 'rc' => 44],
                ['n' => ['en' => 'Sunrise Egg Toast', 'ar' => 'توست البيض'], 'd' => ['en' => 'Egg-in-a-hole brioche, grilled tomato, greens.', 'ar' => 'بيض داخل خبز البريوش مع طماطم مشوية.'], 'p' => 22, 'img' => 'breakfast-spread', 'kcal' => 380, 'pro' => 16, 'carb' => 32, 'fat' => 20, 'veg' => true, 'alg' => [1, 2, 7], 'rate' => 4.3, 'rc' => 26],
                ['n' => ['en' => 'Shakshuka Pan', 'ar' => 'شكشوكة'], 'd' => ['en' => 'Bubbling tomato-pepper sauce, baked eggs, bread.', 'ar' => 'صوص الطماطم والفلفل مع بيض مخبوز وخبز.'], 'p' => 26, 'img' => 'shakshuka', 'kcal' => 420, 'pro' => 20, 'carb' => 30, 'fat' => 24, 'veg' => true, 'spice' => 1, 'gf' => false, 'alg' => [2, 7], 'rate' => 4.6, 'rc' => 38],
                ['n' => ['en' => 'Skillet Frittata', 'ar' => 'فريتاتا'], 'd' => ['en' => 'Open-faced omelette, melted cheese, herbs.', 'ar' => 'أومليت مفتوح مع الجبن الذائب والأعشاب.'], 'p' => 24, 'img' => 'frittata', 'kcal' => 400, 'pro' => 22, 'carb' => 12, 'fat' => 30, 'veg' => true, 'gf' => true, 'alg' => [1, 2], 'rate' => 4.2, 'rc' => 19],
                ['n' => ['en' => 'Brunch Board', 'ar' => 'لوح البرانش'], 'd' => ['en' => 'Trio of toasts: avocado, banana-tahini, tomato-feta.', 'ar' => 'ثلاثية التوست: أفوكادو، موز وطحينة، طماطم وفيتا.'], 'p' => 34, 'img' => 'toasts-brunch', 'kcal' => 520, 'pro' => 16, 'carb' => 58, 'fat' => 26, 'veg' => true, 'alg' => [1, 4, 7], 'rate' => 4.4, 'rc' => 23],
                ['n' => ['en' => 'Butter Croissant', 'ar' => 'كرواسون بالزبدة'], 'd' => ['en' => 'Flaky all-butter croissant, baked all morning.', 'ar' => 'كرواسون هش بالزبدة يخبز طوال الصباح.'], 'p' => 12, 'img' => 'croissant', 'kcal' => 280, 'pro' => 6, 'carb' => 30, 'fat' => 16, 'veg' => true, 'alg' => [1, 2, 7, 9], 'rate' => 4.5, 'rc' => 33],
                ['n' => ['en' => 'Cinnamon Roll', 'ar' => 'رول القرفة'], 'd' => ['en' => 'Sticky spiced roll, cream-cheese glaze.', 'ar' => 'رول القرفة مع صوص الجبن الكريمي.'], 'p' => 16, 'img' => 'cinnamon-roll', 'kcal' => 420, 'pro' => 7, 'carb' => 62, 'fat' => 18, 'veg' => true, 'alg' => [1, 2, 7], 'rate' => 4.6, 'rc' => 41],
            ],
        ],
        [
            'n' => ['en' => 'Desserts', 'ar' => 'حلويات'], 'img' => 'sundae-caramel',
            'children' => [
                [
                    'n' => ['en' => 'Cakes & Bakes', 'ar' => 'كيك ومخبوزات'], 'img' => 'cake-chocolate',
                    'items' => [
                        ['n' => ['en' => 'Chocolate Fudge Cake', 'ar' => 'كيك الشوكولاتة'], 'd' => ['en' => 'Four dark layers, ganache drip, cocoa soil.', 'ar' => 'أربع طبقات شوكولاتة داكنة مع الغاناش.'], 'p' => 26, 'img' => 'cake-chocolate', 'kcal' => 540, 'pro' => 7, 'carb' => 66, 'fat' => 28, 'veg' => true, 'alg' => [1, 2, 4, 7], 'pop' => true, 'rate' => 4.8, 'rc' => 88],
                        ['n' => ['en' => 'Red Velvet Slice', 'ar' => 'رد فلفت'], 'd' => ['en' => 'Cream-cheese frosting, fresh strawberry.', 'ar' => 'كيك أحمر مع كريمة الجبن والفراولة.'], 'p' => 24, 'img' => 'cake-redvelvet', 'kcal' => 480, 'pro' => 6, 'carb' => 58, 'fat' => 26, 'veg' => true, 'alg' => [1, 2, 7], 'rate' => 4.6, 'rc' => 52],
                        ['n' => ['en' => 'Blueberry Cheesecake', 'ar' => 'تشيز كيك التوت'], 'd' => ['en' => 'Baked New York style, blueberry compote.', 'ar' => 'تشيز كيك نيويورك مع كومبوت التوت الأزرق.'], 'p' => 26, 'img' => 'cheesecake', 'kcal' => 520, 'pro' => 9, 'carb' => 48, 'fat' => 32, 'veg' => true, 'alg' => [1, 2, 7], 'rate' => 4.7, 'rc' => 61],
                        ['n' => ['en' => 'Molten Lava Cake', 'ar' => 'كيك اللافا'], 'd' => ['en' => 'Oozing dark-chocolate centre, 12-minute bake.', 'ar' => 'قلب شوكولاتة سائل، يخبز عند الطلب.'], 'p' => 28, 'img' => 'lava-cake', 'kcal' => 460, 'pro' => 7, 'carb' => 50, 'fat' => 26, 'veg' => true, 'alg' => [1, 2, 7], 'spec' => true, 'rate' => 4.9, 'rc' => 74, 'stock' => 10],
                        ['n' => ['en' => 'Tiramisu Jar', 'ar' => 'تيراميسو'], 'd' => ['en' => 'Espresso-soaked savoiardi, mascarpone cloud.', 'ar' => 'طبقات الإسبريسو والماسكاربوني.'], 'p' => 24, 'img' => 'tiramisu', 'kcal' => 440, 'pro' => 8, 'carb' => 44, 'fat' => 26, 'veg' => true, 'alg' => [1, 2, 7], 'rate' => 4.7, 'rc' => 46],
                        ['n' => ['en' => 'Fudge Brownie', 'ar' => 'براوني'], 'd' => ['en' => 'Dense cocoa brownie, molten chocolate rivers.', 'ar' => 'براوني كثيف مع أنهار الشوكولاتة الذائبة.'], 'p' => 18, 'img' => 'brownie', 'kcal' => 420, 'pro' => 6, 'carb' => 52, 'fat' => 22, 'veg' => true, 'alg' => [1, 2, 4, 7], 'rate' => 4.5, 'rc' => 39],
                        ['n' => ['en' => 'Berry Lattice Pie', 'ar' => 'فطيرة التوت'], 'd' => ['en' => 'Buttery lattice crust, mixed-berry filling.', 'ar' => 'عجينة الزبدة المشبكة مع حشوة التوت المشكل.'], 'p' => 22, 'img' => 'berry-pie', 'kcal' => 400, 'pro' => 5, 'carb' => 56, 'fat' => 18, 'veg' => true, 'alg' => [1, 7, 9], 'rate' => 4.4, 'rc' => 20],
                    ],
                ],
                [
                    'n' => ['en' => 'Frozen & Sweet', 'ar' => 'مثلجات وسكريات'], 'img' => 'icecream-cone',
                    'items' => [
                        ['n' => ['en' => 'Salted Caramel Sundae', 'ar' => 'صنداي الكراميل'], 'd' => ['en' => 'Soft-serve, salted caramel, roasted pecans.', 'ar' => 'آيس كريم مع كراميل مملح وجوز البقان المحمص.'], 'p' => 20, 'img' => 'sundae-caramel', 'kcal' => 430, 'pro' => 7, 'carb' => 52, 'fat' => 22, 'veg' => true, 'alg' => [1, 4], 'grp' => [], 'ext' => ['scoop', 'brownie', 'cream'], 'pop' => true, 'rate' => 4.7, 'rc' => 58],
                        ['n' => ['en' => 'Sprinkle Ice-Cream Bowl', 'ar' => 'آيس كريم بالرشات'], 'd' => ['en' => 'Three scoops in a sprinkle-crusted waffle bowl.', 'ar' => 'ثلاث كرات في وعاء الوافل المزين بالرشات.'], 'p' => 22, 'img' => 'icecream-cone', 'kcal' => 480, 'pro' => 8, 'carb' => 60, 'fat' => 24, 'veg' => true, 'alg' => [1, 2, 7], 'ext' => ['scoop'], 'rate' => 4.5, 'rc' => 31],
                        ['n' => ['en' => 'Classic Cone', 'ar' => 'كون كلاسيك'], 'd' => ['en' => 'Hand-rolled waffle cone, scoop of the day.', 'ar' => 'كون وافل ملفوف يدوياً مع نكهة اليوم.'], 'p' => 12, 'img' => 'icecream-scoop', 'kcal' => 260, 'pro' => 4, 'carb' => 34, 'fat' => 12, 'veg' => true, 'alg' => [1, 7], 'rate' => 4.3, 'rc' => 24],
                        ['n' => ['en' => 'Glazed Donut Stack', 'ar' => 'دونات مزجج'], 'd' => ['en' => 'Chocolate-drizzled ring donuts, rainbow sprinkles.', 'ar' => 'دونات مع الشوكولاتة والرشات الملونة.'], 'p' => 16, 'img' => 'donut', 'kcal' => 420, 'pro' => 5, 'carb' => 58, 'fat' => 20, 'veg' => true, 'alg' => [1, 2, 6, 7], 'rate' => 4.4, 'rc' => 27],
                        ['n' => ['en' => 'Chunky Cookies (3)', 'ar' => 'كوكيز (٣)'], 'd' => ['en' => 'Chewy dark-chocolate chunk cookies, sea salt.', 'ar' => 'كوكيز طرية بقطع الشوكولاتة الداكنة وملح البحر.'], 'p' => 14, 'img' => 'cookie', 'kcal' => 380, 'pro' => 5, 'carb' => 50, 'fat' => 18, 'veg' => true, 'alg' => [1, 2, 6, 7], 'rate' => 4.6, 'rc' => 35],
                        ['n' => ['en' => 'Cinnamon Churros', 'ar' => 'تشوروز'], 'd' => ['en' => 'Crisp churros, cinnamon sugar, chocolate pot.', 'ar' => 'تشوروز مقرمشة مع سكر القرفة وصوص الشوكولاتة.'], 'p' => 18, 'img' => 'churros', 'kcal' => 440, 'pro' => 6, 'carb' => 56, 'fat' => 22, 'veg' => true, 'alg' => [1, 7], 'rate' => 4.5, 'rc' => 29],
                        ['n' => ['en' => 'Blueberry Muffin', 'ar' => 'مافن التوت'], 'd' => ['en' => 'Bakery-style domed muffin, berry pockets.', 'ar' => 'مافن بحشوة التوت الأزرق.'], 'p' => 12, 'img' => 'muffin', 'kcal' => 340, 'pro' => 5, 'carb' => 48, 'fat' => 14, 'veg' => true, 'alg' => [1, 2, 7], 'rate' => 4.2, 'rc' => 16],
                        ['n' => ['en' => 'Banana Crepes', 'ar' => 'كريب الموز'], 'd' => ['en' => 'Thin crepes, banana, chocolate drizzle.', 'ar' => 'كريب رقيق مع الموز وصوص الشوكولاتة.'], 'p' => 20, 'img' => 'crepes-banana', 'gal' => ['crepes-honey'], 'kcal' => 420, 'pro' => 8, 'carb' => 60, 'fat' => 16, 'veg' => true, 'alg' => [1, 2, 7], 'rate' => 4.4, 'rc' => 22],
                        ['n' => ['en' => 'Strawberry Panna Cotta', 'ar' => 'بانا كوتا'], 'd' => ['en' => 'Vanilla-bean set cream, macerated strawberries.', 'ar' => 'كريمة الفانيليا مع الفراولة المنقوعة.'], 'p' => 18, 'img' => 'pannacotta', 'kcal' => 320, 'pro' => 6, 'carb' => 34, 'fat' => 18, 'veg' => true, 'gf' => true, 'alg' => [1], 'rate' => 4.3, 'rc' => 15],
                    ],
                ],
            ],
        ],
        [
            'n' => ['en' => 'Drinks', 'ar' => 'مشروبات'], 'img' => 'milkshake-choc',
            'children' => [
                [
                    'n' => ['en' => 'Coffee Bar', 'ar' => 'ركن القهوة'], 'img' => 'coffee-latte',
                    'items' => [
                        ['n' => ['en' => 'Flat White', 'ar' => 'فلات وايت'], 'd' => ['en' => 'Double ristretto, velvet micro-foam.', 'ar' => 'ريستريتو مزدوج مع رغوة حريرية.'], 'p' => 16, 'img' => 'coffee-latte', 'kcal' => 120, 'pro' => 6, 'carb' => 10, 'fat' => 6, 'veg' => true, 'gf' => true, 'alg' => [1], 'grp' => ['cup', 'milk'], 'ext' => ['shot'], 'pop' => true, 'rate' => 4.6, 'rc' => 73],
                        ['n' => ['en' => 'Cappuccino', 'ar' => 'كابتشينو'], 'd' => ['en' => 'Classic thirds: espresso, steamed milk, foam.', 'ar' => 'إسبريسو مع حليب مبخر ورغوة.'], 'p' => 15, 'img' => 'coffee-cappuccino', 'kcal' => 130, 'pro' => 6, 'carb' => 12, 'fat' => 6, 'veg' => true, 'gf' => true, 'alg' => [1], 'grp' => ['cup', 'milk'], 'ext' => ['shot'], 'rate' => 4.5, 'rc' => 55],
                        ['n' => ['en' => 'Espresso', 'ar' => 'إسبريسو'], 'd' => ['en' => 'Single-origin double shot, 25-second pull.', 'ar' => 'جرعة مزدوجة من بن أحادي المصدر.'], 'p' => 12, 'img' => 'coffee-espresso', 'kcal' => 10, 'pro' => 0, 'carb' => 2, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.6, 'rc' => 40],
                        ['n' => ['en' => 'Iced Spanish Latte', 'ar' => 'لاتيه إسباني مثلج'], 'd' => ['en' => 'Double espresso, condensed milk, over ice.', 'ar' => 'إسبريسو مزدوج مع حليب مكثف وثلج.'], 'p' => 18, 'img' => 'coffee-coldbrew', 'kcal' => 190, 'pro' => 6, 'carb' => 24, 'fat' => 8, 'veg' => true, 'gf' => true, 'alg' => [1], 'grp' => ['cup'], 'ext' => ['shot'], 'pop' => true, 'rate' => 4.7, 'rc' => 86],
                        ['n' => ['en' => 'Signature Latte Art', 'ar' => 'لاتيه آرت'], 'd' => ['en' => 'Barista pour-art latte on single-origin beans.', 'ar' => 'لاتيه بفن الرسم على بن مختص.'], 'p' => 17, 'img' => 'coffee-art', 'kcal' => 150, 'pro' => 7, 'carb' => 14, 'fat' => 7, 'veg' => true, 'gf' => true, 'alg' => [1], 'grp' => ['milk'], 'rate' => 4.5, 'rc' => 34],
                        ['n' => ['en' => 'French Press Pot', 'ar' => 'فرنش برس'], 'd' => ['en' => 'Slow-steeped pot for two, dark roast.', 'ar' => 'إبريق قهوة منقوعة ببطء لشخصين.'], 'p' => 22, 'img' => 'coffee-frenchpress', 'kcal' => 15, 'pro' => 1, 'carb' => 3, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.3, 'rc' => 18],
                        ['n' => ['en' => 'Matcha Latte', 'ar' => 'ماتشا لاتيه'], 'd' => ['en' => 'Ceremonial-grade matcha, silky steamed milk.', 'ar' => 'ماتشا فاخرة مع حليب مبخر.'], 'p' => 20, 'img' => 'tea-matcha', 'kcal' => 160, 'pro' => 6, 'carb' => 18, 'fat' => 7, 'veg' => true, 'gf' => true, 'alg' => [1], 'grp' => ['cup', 'milk'], 'rate' => 4.4, 'rc' => 26],
                    ],
                ],
                [
                    'n' => ['en' => 'Shakes & Coolers', 'ar' => 'ميلك شيك ومبردات'], 'img' => 'freakshake-dark',
                    'items' => [
                        ['n' => ['en' => 'OG Freakshake', 'ar' => 'فريك شيك'], 'd' => ['en' => 'Over-the-top chocolate shake, whipped tower, pretzel.', 'ar' => 'ميلك شيك شوكولاتة ضخم مع برج الكريمة.'], 'p' => 28, 'img' => 'freakshake-dark', 'gal' => ['milkshake-choc'], 'kcal' => 720, 'pro' => 14, 'carb' => 92, 'fat' => 34, 'veg' => true, 'alg' => [1, 4, 6, 7], 'ext' => ['cream', 'brownie', 'scoop'], 'pop' => true, 'spec' => true, 'rate' => 4.8, 'rc' => 97],
                        ['n' => ['en' => 'Cookies & Cream Shake', 'ar' => 'شيك الكوكيز'], 'd' => ['en' => 'Crushed cookies blended thick, cream cap.', 'ar' => 'كوكيز مطحون مخفوق مع الكريمة.'], 'p' => 24, 'img' => 'milkshake-choc', 'kcal' => 640, 'pro' => 12, 'carb' => 84, 'fat' => 28, 'veg' => true, 'alg' => [1, 6, 7], 'ext' => ['cream'], 'rate' => 4.6, 'rc' => 52],
                        ['n' => ['en' => 'Vanilla Malt Shake', 'ar' => 'شيك الفانيليا'], 'd' => ['en' => 'Old-school malted vanilla, chocolate drizzle.', 'ar' => 'فانيليا كلاسيكية مع صوص الشوكولاتة.'], 'p' => 22, 'img' => 'milkshake', 'kcal' => 560, 'pro' => 11, 'carb' => 76, 'fat' => 24, 'veg' => true, 'alg' => [1, 7], 'rate' => 4.4, 'rc' => 30],
                        ['n' => ['en' => 'Berry Mocktail Trio', 'ar' => 'ثلاثية الموكتيل'], 'd' => ['en' => 'Tasting trio: berry, citrus, passion coolers.', 'ar' => 'ثلاثة موكتيلات: توت وحمضيات وباشن فروت.'], 'p' => 30, 'img' => 'mocktails-trio', 'kcal' => 240, 'pro' => 1, 'carb' => 58, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'spec' => true, 'rate' => 4.5, 'rc' => 21],
                        ['n' => ['en' => 'Dark Berry Fizz', 'ar' => 'فوار التوت الداكن'], 'd' => ['en' => 'Blackberry, lime, soda, dried-orange wheel.', 'ar' => 'توت أسود مع الليمون والصودا وشريحة البرتقال.'], 'p' => 20, 'img' => 'club-mocktail', 'kcal' => 140, 'pro' => 0, 'carb' => 34, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.3, 'rc' => 17],
                        ['n' => ['en' => 'Virgin Mojito', 'ar' => 'موهيتو'], 'd' => ['en' => 'Muddled mint, lime, sparkling top.', 'ar' => 'نعناع مهروس مع الليمون والصودا.'], 'p' => 18, 'img' => 'mojito-mocktail', 'gal' => ['mojito-2'], 'kcal' => 120, 'pro' => 0, 'carb' => 30, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'pop' => true, 'rate' => 4.6, 'rc' => 44],
                        ['n' => ['en' => 'Mint Lemonade', 'ar' => 'ليمون بالنعناع'], 'd' => ['en' => 'Cloudy lemon, blitzed mint, crushed ice.', 'ar' => 'ليمون مع نعناع مثلج.'], 'p' => 16, 'img' => 'mint-lemonade', 'gal' => ['lemonade'], 'kcal' => 130, 'pro' => 0, 'carb' => 32, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.5, 'rc' => 38],
                        ['n' => ['en' => 'Watermelon Cooler', 'ar' => 'مبرد البطيخ'], 'd' => ['en' => 'Fresh watermelon, lime, chili-salt rim option.', 'ar' => 'بطيخ طازج مع الليمون.'], 'p' => 18, 'img' => 'drink-watermelon', 'kcal' => 110, 'pro' => 1, 'carb' => 26, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.2, 'rc' => 14],
                        ['n' => ['en' => 'Fresh Orange Juice', 'ar' => 'عصير برتقال طازج'], 'd' => ['en' => 'Squeezed to order, no sugar added.', 'ar' => 'يعصر عند الطلب بدون سكر مضاف.'], 'p' => 14, 'img' => 'juice-orange', 'kcal' => 120, 'pro' => 2, 'carb' => 28, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.4, 'rc' => 25],
                        ['n' => ['en' => 'Green Machine', 'ar' => 'العصير الأخضر'], 'd' => ['en' => 'Kiwi, apple, spinach, ginger kick.', 'ar' => 'كيوي وتفاح وسبانخ مع الزنجبيل.'], 'p' => 18, 'img' => 'juice-green', 'kcal' => 140, 'pro' => 2, 'carb' => 32, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.1, 'rc' => 12],
                        ['n' => ['en' => 'Iced Tea', 'ar' => 'شاي مثلج'], 'd' => ['en' => 'Cold-brewed black tea, lemon wheel.', 'ar' => 'شاي أسود منقوع بارد مع الليمون.'], 'p' => 12, 'img' => 'iced-tea', 'kcal' => 60, 'pro' => 0, 'carb' => 15, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.2, 'rc' => 19],
                        ['n' => ['en' => 'Craft Cola', 'ar' => 'كولا'], 'd' => ['en' => 'Ice-cold glass-bottle cola.', 'ar' => 'كولا باردة في زجاجة.'], 'p' => 8, 'img' => 'soda-cola', 'kcal' => 140, 'pro' => 0, 'carb' => 36, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.0, 'rc' => 11],
                        ['n' => ['en' => 'Sparkling Water', 'ar' => 'مياه غازية'], 'd' => ['en' => 'Chilled sparkling water.', 'ar' => 'مياه غازية باردة.'], 'p' => 6, 'img' => 'water-sparkling', 'kcal' => 0, 'pro' => 0, 'carb' => 0, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.0, 'rc' => 8],
                    ],
                ],
            ],
        ],
    ],

    'reviews' => [
        ['name' => 'Sara M.', 'stars' => 5, 'body' => ['en' => 'The smash burger is unreal — crispy edges, juicy middle.', 'ar' => 'برجر السماش لا يصدق — أطراف مقرمشة وقلب طري.']],
        ['name' => 'Khalid A.', 'stars' => 5, 'body' => ['en' => 'Korean wings + truffle fries. That is the order.', 'ar' => 'الأجنحة الكورية مع بطاطس الترافل. هذا هو الطلب.']],
        ['name' => 'Lina', 'stars' => 4, 'body' => ['en' => 'Great late-night spot, pizza crust perfectly charred.', 'ar' => 'مكان رائع في الليل، عجينة البيتزا محمصة بامتياز.']],
        ['name' => 'Omar', 'stars' => 5, 'body' => ['en' => 'Freakshake is a dessert and a show at the same time.', 'ar' => 'الفريك شيك حلا وعرض في نفس الوقت.']],
        ['name' => 'Noura', 'stars' => 4, 'body' => ['en' => 'Fast delivery, fries still crispy on arrival.', 'ar' => 'توصيل سريع والبطاطس وصلت مقرمشة.']],
    ],
];
