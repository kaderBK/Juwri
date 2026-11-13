<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/** Dough & Co — bakery & pizza showcase menu. Same shape as crave.php. */
return [
    'store' => [
        'title' => 'Dough & Co', 'subtitle' => 'Bakery & pizza · wood oven · slow ferment',
        'description' => '48-hour dough, a roaring wood oven and a bakery counter that empties by noon.',
        'color' => '#c98a2b', 'logo' => 'pizza-neapolitan', 'cover' => 'pizza-oven',
        'banners' => ['pizza-oven', 'croissant', 'pizza-margherita'],
        'coupons' => [['code' => 'DOUGH10', 'pct' => 10]],
    ],
    'groups' => [
        'pizza_size' => ['t' => ['en' => 'Size', 'ar' => 'الحجم'], 'opts' => [['9"', '٩ إنش', 0], ['12"', '١٢ إنش', 12], ['16"', '١٦ إنش', 22]], 'required' => true],
        'crust'      => ['t' => ['en' => 'Crust', 'ar' => 'العجينة'], 'opts' => [['Classic', 'كلاسيك', 0], ['Thin', 'رفيعة', 0], ['Stuffed', 'محشية', 7]]],
        'bake'       => ['t' => ['en' => 'Bake', 'ar' => 'الخبز'], 'opts' => [['Regular', 'عادي', 0], ['Well-fired', 'محمص زيادة', 0]]],
    ],
    'extras' => [
        'cheese'  => ['t' => ['en' => 'Extra mozzarella', 'ar' => 'موزاريلا إضافية'], 'p' => 6, 'img' => 'pizza-margherita'],
        'dip'     => ['t' => ['en' => 'Garlic dip', 'ar' => 'صوص الثوم'], 'p' => 3, 'img' => 'bread-basket'],
        'salad'   => ['t' => ['en' => 'Side salad', 'ar' => 'سلطة جانبية'], 'p' => 9, 'img' => 'salad-garden'],
        'butter'  => ['t' => ['en' => 'Whipped butter', 'ar' => 'زبدة مخفوقة'], 'p' => 3, 'img' => 'croissant'],
    ],
    'categories' => [
        [
            'n' => ['en' => 'Wood-Oven Pizza', 'ar' => 'بيتزا الفرن الحطبي'], 'img' => 'pizza-oven',
            'children' => [
                [
                    'n' => ['en' => 'Classics', 'ar' => 'كلاسيك'], 'img' => 'pizza-margherita',
                    'items' => [
                        ['n' => ['en' => 'Margherita DOC', 'ar' => 'مارغريتا'], 'd' => ['en' => 'San Marzano, fior di latte, basil.', 'ar' => 'طماطم سان مارزانو وموزاريلا وريحان.'], 'p' => 32, 'img' => 'pizza-margherita', 'gal' => ['pizza-neapolitan', 'pizza-oven'], 'kcal' => 820, 'pro' => 30, 'carb' => 96, 'fat' => 32, 'veg' => true, 'alg' => [1, 7], 'grp' => ['pizza_size', 'crust', 'bake'], 'ext' => ['cheese', 'dip'], 'pop' => true, 'spec' => true, 'rate' => 4.8, 'rc' => 121],
                        ['n' => ['en' => 'Pepperoni Classico', 'ar' => 'ببروني كلاسيك'], 'd' => ['en' => 'Beef pepperoni, mozzarella, oregano.', 'ar' => 'ببروني بقري مع موزاريلا وأوريغانو.'], 'p' => 40, 'img' => 'pizza-pepperoni', 'kcal' => 920, 'pro' => 36, 'carb' => 92, 'fat' => 42, 'alg' => [1, 7], 'grp' => ['pizza_size', 'crust'], 'pop' => true, 'rate' => 4.7, 'rc' => 98],
                        ['n' => ['en' => 'Napoli Leopard Crust', 'ar' => 'نابولي'], 'd' => ['en' => 'Leopard-spotted rim, buffalo mozzarella.', 'ar' => 'حواف منقطة مع موزاريلا الجاموس.'], 'p' => 38, 'img' => 'pizza-neapolitan', 'kcal' => 780, 'pro' => 28, 'carb' => 90, 'fat' => 30, 'veg' => true, 'alg' => [1, 7], 'grp' => ['pizza_size', 'bake'], 'rate' => 4.6, 'rc' => 64],
                        ['n' => ['en' => 'Oven Mushroom & Herb', 'ar' => 'بيتزا الفطر'], 'd' => ['en' => 'Mushroom, cherry tomato, rosemary oil.', 'ar' => 'فطر وطماطم كرزية وزيت إكليل الجبل.'], 'p' => 34, 'img' => 'pizza-oven', 'kcal' => 720, 'pro' => 24, 'carb' => 88, 'fat' => 26, 'veg' => true, 'alg' => [1, 7], 'grp' => ['pizza_size', 'crust'], 'rate' => 4.5, 'rc' => 42],
                    ],
                ],
                [
                    'n' => ['en' => 'Signatures', 'ar' => 'التوقيعات'], 'img' => 'pizza-arugula',
                    'items' => [
                        ['n' => ['en' => 'Rocket & Parm', 'ar' => 'جرجير وبارميزان'], 'd' => ['en' => 'Arugula, shaved parmesan, balsamic.', 'ar' => 'جرجير مع بارميزان مبشور وبلسمك.'], 'p' => 40, 'img' => 'pizza-arugula', 'kcal' => 740, 'pro' => 28, 'carb' => 82, 'fat' => 30, 'veg' => true, 'alg' => [1, 7], 'grp' => ['pizza_size'], 'rate' => 4.6, 'rc' => 38],
                        ['n' => ['en' => 'Smoked BBQ Chicken', 'ar' => 'دجاج باربكيو'], 'd' => ['en' => 'BBQ base, grilled chicken, ranch swirl.', 'ar' => 'صوص باربكيو مع دجاج مشوي ورانش.'], 'p' => 44, 'img' => 'pizza-bbq', 'kcal' => 880, 'pro' => 40, 'carb' => 86, 'fat' => 38, 'alg' => [1, 7], 'grp' => ['pizza_size', 'crust'], 'pop' => true, 'rate' => 4.7, 'rc' => 56],
                        ['n' => ['en' => 'Greek Feta & Olive', 'ar' => 'فيتا وزيتون'], 'd' => ['en' => 'Feta, kalamata, red onion, oregano oil.', 'ar' => 'فيتا وزيتون كالاماتا وبصل أحمر.'], 'p' => 42, 'img' => 'pizza-feta', 'kcal' => 800, 'pro' => 30, 'carb' => 84, 'fat' => 36, 'veg' => true, 'alg' => [1, 7], 'grp' => ['pizza_size'], 'rate' => 4.5, 'rc' => 31],
                        ['n' => ['en' => 'Garden Supreme', 'ar' => 'سوبريم الحديقة'], 'd' => ['en' => 'Charred greens, mushroom, ricotta, chili.', 'ar' => 'خضار مشوية وفطر وريكوتا وفلفل.'], 'p' => 38, 'img' => 'pizza-veggie', 'kcal' => 700, 'pro' => 26, 'carb' => 80, 'fat' => 28, 'veg' => true, 'spice' => 1, 'alg' => [1, 7], 'grp' => ['pizza_size'], 'rate' => 4.4, 'rc' => 27],
                        ['n' => ['en' => 'Feast Supreme XXL', 'ar' => 'سوبريم كبيرة'], 'd' => ['en' => '16-inch loaded, eight slices.', 'ar' => 'بيتزا ١٦ إنش محملة، ثماني قطع.'], 'p' => 58, 'img' => 'pizza-supreme', 'kcal' => 1880, 'pro' => 76, 'carb' => 184, 'fat' => 88, 'alg' => [1, 7], 'rate' => 4.5, 'rc' => 35],
                    ],
                ],
            ],
        ],
        [
            'n' => ['en' => 'Bakery Counter', 'ar' => 'ركن المخبوزات'], 'img' => 'croissant',
            'items' => [
                ['n' => ['en' => 'All-Butter Croissant', 'ar' => 'كرواسون بالزبدة'], 'd' => ['en' => '27 layers, baked every hour.', 'ar' => '٢٧ طبقة، يخبز كل ساعة.'], 'p' => 13, 'img' => 'croissant', 'kcal' => 280, 'pro' => 6, 'carb' => 30, 'fat' => 16, 'veg' => true, 'alg' => [1, 2, 7, 9], 'ext' => ['butter'], 'pop' => true, 'rate' => 4.7, 'rc' => 67],
                ['n' => ['en' => 'House Bread Basket', 'ar' => 'سلة خبز البيت'], 'd' => ['en' => 'Wood-oven rolls, two whipped butters.', 'ar' => 'خبز من الفرن الحطبي مع زبدتين.'], 'p' => 18, 'img' => 'bread-basket', 'kcal' => 420, 'pro' => 10, 'carb' => 56, 'fat' => 18, 'veg' => true, 'alg' => [1, 7, 9], 'rate' => 4.4, 'rc' => 29],
                ['n' => ['en' => 'Cinnamon Roll', 'ar' => 'رول القرفة'], 'd' => ['en' => 'Sticky spiced roll, cream-cheese glaze.', 'ar' => 'رول القرفة مع صوص الجبن الكريمي.'], 'p' => 16, 'img' => 'cinnamon-roll', 'kcal' => 420, 'pro' => 7, 'carb' => 62, 'fat' => 18, 'veg' => true, 'alg' => [1, 2, 7], 'rate' => 4.6, 'rc' => 44],
                ['n' => ['en' => 'Blueberry Muffin', 'ar' => 'مافن التوت'], 'd' => ['en' => 'Domed muffin, berry pockets.', 'ar' => 'مافن بحشوة التوت الأزرق.'], 'p' => 12, 'img' => 'muffin', 'kcal' => 340, 'pro' => 5, 'carb' => 48, 'fat' => 14, 'veg' => true, 'alg' => [1, 2, 7], 'rate' => 4.3, 'rc' => 20],
                ['n' => ['en' => 'Honey Cheese Crepes', 'ar' => 'كريب بالجبن والعسل'], 'd' => ['en' => 'Warm crepes, sweet cheese, honey.', 'ar' => 'كريب دافئ مع الجبن الحلو والعسل.'], 'p' => 22, 'img' => 'crepes-honey', 'kcal' => 460, 'pro' => 12, 'carb' => 56, 'fat' => 20, 'veg' => true, 'alg' => [1, 2, 7], 'rate' => 4.4, 'rc' => 18],
            ],
        ],
        [
            'n' => ['en' => 'Salads & Soups', 'ar' => 'سلطات وشوربات'], 'img' => 'salad-caesar',
            'items' => [
                ['n' => ['en' => 'Caesar', 'ar' => 'سيزر'], 'd' => ['en' => 'Baby gem, parm, torn croutons.', 'ar' => 'خس مع بارميزان وخبز محمص.'], 'p' => 28, 'img' => 'salad-caesar', 'kcal' => 420, 'pro' => 18, 'carb' => 22, 'fat' => 28, 'alg' => [1, 2, 3, 7], 'rate' => 4.4, 'rc' => 24],
                ['n' => ['en' => 'Garden Crunch', 'ar' => 'سلطة الحديقة'], 'd' => ['en' => 'Rainbow leaves, seeds, citrus dressing.', 'ar' => 'خضار ملونة مع بذور وصوص الحمضيات.'], 'p' => 24, 'img' => 'salad-garden', 'kcal' => 240, 'pro' => 6, 'carb' => 18, 'fat' => 16, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.3, 'rc' => 16],
                ['n' => ['en' => 'Roast Tomato Soup', 'ar' => 'شوربة الطماطم'], 'd' => ['en' => 'Fire-roasted tomato, basil oil, bread soldier.', 'ar' => 'طماطم مشوية مع زيت الريحان والخبز.'], 'p' => 20, 'img' => 'soup-lentil', 'kcal' => 260, 'pro' => 6, 'carb' => 30, 'fat' => 12, 'veg' => true, 'alg' => [1, 7], 'rate' => 4.3, 'rc' => 14],
            ],
        ],
        [
            'n' => ['en' => 'Sweet Counter', 'ar' => 'ركن الحلويات'], 'img' => 'donut',
            'items' => [
                ['n' => ['en' => 'Glazed Donut Stack', 'ar' => 'دونات مزجج'], 'd' => ['en' => 'Chocolate-drizzled rings, sprinkles.', 'ar' => 'دونات بالشوكولاتة والرشات.'], 'p' => 16, 'img' => 'donut', 'kcal' => 420, 'pro' => 5, 'carb' => 58, 'fat' => 20, 'veg' => true, 'alg' => [1, 2, 6, 7], 'pop' => true, 'rate' => 4.5, 'rc' => 31],
                ['n' => ['en' => 'Chunky Cookies (3)', 'ar' => 'كوكيز (٣)'], 'd' => ['en' => 'Dark-chocolate chunks, sea salt.', 'ar' => 'كوكيز بقطع الشوكولاتة وملح البحر.'], 'p' => 14, 'img' => 'cookie', 'kcal' => 380, 'pro' => 5, 'carb' => 50, 'fat' => 18, 'veg' => true, 'alg' => [1, 2, 6, 7], 'rate' => 4.5, 'rc' => 26],
                ['n' => ['en' => 'Fudge Brownie', 'ar' => 'براوني'], 'd' => ['en' => 'Dense cocoa, molten pockets.', 'ar' => 'براوني كثيف بجيوب الشوكولاتة.'], 'p' => 16, 'img' => 'brownie', 'kcal' => 420, 'pro' => 6, 'carb' => 52, 'fat' => 22, 'veg' => true, 'alg' => [1, 2, 4, 7], 'rate' => 4.4, 'rc' => 21],
                ['n' => ['en' => 'Berry Lattice Pie', 'ar' => 'فطيرة التوت'], 'd' => ['en' => 'Buttery lattice, mixed berries.', 'ar' => 'عجينة الزبدة مع التوت المشكل.'], 'p' => 22, 'img' => 'berry-pie', 'kcal' => 400, 'pro' => 5, 'carb' => 56, 'fat' => 18, 'veg' => true, 'alg' => [1, 7, 9], 'rate' => 4.4, 'rc' => 17],
                ['n' => ['en' => 'Rose Cupcakes (2)', 'ar' => 'كب كيك الورد'], 'd' => ['en' => 'Rose sponge, pistachio crumb.', 'ar' => 'كيك بماء الورد وفتات الفستق.'], 'p' => 18, 'img' => 'cupcakes', 'kcal' => 380, 'pro' => 5, 'carb' => 52, 'fat' => 18, 'veg' => true, 'alg' => [1, 2, 4, 7], 'rate' => 4.2, 'rc' => 12],
            ],
        ],
        [
            'n' => ['en' => 'Drinks', 'ar' => 'مشروبات'], 'img' => 'coffee-latte',
            'items' => [
                ['n' => ['en' => 'Flat White', 'ar' => 'فلات وايت'], 'd' => ['en' => 'Double ristretto, velvet foam.', 'ar' => 'ريستريتو مزدوج مع رغوة ناعمة.'], 'p' => 16, 'img' => 'coffee-latte', 'kcal' => 120, 'pro' => 6, 'carb' => 10, 'fat' => 6, 'veg' => true, 'gf' => true, 'alg' => [1], 'rate' => 4.5, 'rc' => 28],
                ['n' => ['en' => 'Cloudy Lemonade', 'ar' => 'ليموناضة'], 'd' => ['en' => 'Pressed lemon, iced.', 'ar' => 'ليمون معصور مثلج.'], 'p' => 14, 'img' => 'lemonade', 'kcal' => 140, 'pro' => 0, 'carb' => 36, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.3, 'rc' => 15],
                ['n' => ['en' => 'Fresh Orange Juice', 'ar' => 'عصير برتقال'], 'd' => ['en' => 'Squeezed to order.', 'ar' => 'يعصر عند الطلب.'], 'p' => 14, 'img' => 'juice-orange', 'kcal' => 120, 'pro' => 2, 'carb' => 28, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.3, 'rc' => 13],
                ['n' => ['en' => 'Iced Tea', 'ar' => 'شاي مثلج'], 'd' => ['en' => 'Cold-brewed, lemon wheel.', 'ar' => 'شاي بارد مع الليمون.'], 'p' => 12, 'img' => 'iced-tea', 'kcal' => 60, 'pro' => 0, 'carb' => 15, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.1, 'rc' => 10],
                ['n' => ['en' => 'Craft Cola', 'ar' => 'كولا'], 'd' => ['en' => 'Glass bottle, ice cold.', 'ar' => 'كولا باردة في زجاجة.'], 'p' => 8, 'img' => 'soda-cola', 'kcal' => 140, 'pro' => 0, 'carb' => 36, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.0, 'rc' => 9],
            ],
        ],
    ],
    'reviews' => [
        ['name' => 'Marco', 'stars' => 5, 'body' => ['en' => 'Leopard crust like Naples. The 48-hour dough shows.', 'ar' => 'حواف منقطة مثل نابولي وعجينة ٤٨ ساعة واضحة.']],
        ['name' => 'Hind', 'stars' => 5, 'body' => ['en' => 'Croissant + flat white before the pizza lunch rush. Heaven.', 'ar' => 'كرواسون وفلات وايت قبل زحمة الغداء — نعيم.']],
        ['name' => 'Peter', 'stars' => 4, 'body' => ['en' => 'BBQ chicken pizza converted this margherita purist.', 'ar' => 'بيتزا الدجاج بالباربكيو غيرت رأيي أنا عاشق المارغريتا.']],
        ['name' => 'Afnan', 'stars' => 4, 'body' => ['en' => 'Bakery counter empties fast — go early.', 'ar' => 'ركن المخبوزات ينفد سريعاً — بكّر.']],
        ['name' => 'Omar', 'stars' => 5, 'body' => ['en' => 'Kids devoured the supreme XXL in minutes.', 'ar' => 'الأطفال التهموا السوبريم الكبيرة في دقائق.']],
    ],
];
