<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/**
 * Ember Steakhouse — dark grill-house showcase menu (WI-G2 pro rebuild).
 * Same shape as crave.php (see its header for the contract).
 */
return [
    'store' => [
        'title'    => 'Ember Steakhouse',
        'subtitle' => 'Dry-aged cuts · live-fire grill · smoke & oak',
        'description' => 'A temple of fire: dry-aged steaks seared over oak embers, slow smoke, dark leather booths and a serious dessert trolley.',
        'color'    => '#8a2b1f',
        'logo'     => 'steak-tomahawk',
        'cover'    => 'bar-interior',
        'banners'  => ['grill-charcoal', 'steak-ribeye', 'lava-cake'],
        'coupons'  => [['code' => 'EMBER10', 'pct' => 10], ['code' => 'STEAKNIGHT', 'pct' => 15]],
    ],

    'groups' => [
        'doneness'  => ['t' => ['en' => 'Doneness', 'ar' => 'درجة النضج'], 'opts' => [['Rare', 'نيء قليلاً', 0], ['Medium rare', 'متوسط النيء', 0], ['Medium', 'متوسط', 0], ['Well done', 'ناضج تماماً', 0]], 'required' => true],
        'cut_size'  => ['t' => ['en' => 'Cut size', 'ar' => 'حجم القطعة'], 'opts' => [['250g', '٢٥٠غ', 0], ['350g', '٣٥٠غ', 32], ['500g', '٥٠٠غ', 60]], 'required' => true],
        'butter'    => ['t' => ['en' => 'Finishing butter', 'ar' => 'زبدة التقديم'], 'opts' => [['Herb', 'أعشاب', 0], ['Smoked chili', 'فلفل مدخن', 3], ['Truffle', 'ترافل', 8], ['None', 'بدون', 0]]],
        'side_pick' => ['t' => ['en' => 'Included side', 'ar' => 'الطبق الجانبي'], 'opts' => [['Fries', 'بطاطس', 0], ['Mash', 'بطاطس مهروسة', 0], ['Grilled greens', 'خضار مشوية', 0], ['Side salad', 'سلطة', 0]], 'required' => true],
        'sauce'     => ['t' => ['en' => 'Sauce', 'ar' => 'الصوص'], 'opts' => [['Peppercorn', 'فلفل أسود', 4], ['Mushroom', 'فطر', 4], ['BBQ mop', 'باربكيو', 4], ['Chimichurri', 'تشيميتشوري', 4]], 'min' => 0, 'max' => 2],
    ],

    'extras' => [
        'prawn_top'  => ['t' => ['en' => 'Grilled prawn topper (3)', 'ar' => 'روبيان مشوي (٣)'], 'p' => 22, 'img' => 'shrimp-grilled'],
        'egg_top'    => ['t' => ['en' => 'Fried egg topper', 'ar' => 'بيضة مقلية'], 'p' => 5, 'img' => 'eggs-benedict'],
        'truff_mac'  => ['t' => ['en' => 'Truffle mac & cheese side', 'ar' => 'ماك آند تشيز بالترافل'], 'p' => 18, 'img' => 'mac-cheese'],
        'bone_marrow' => ['t' => ['en' => 'Roast bone marrow', 'ar' => 'نخاع العظم المحمص'], 'p' => 16, 'img' => 'steak-plate-fine'],
        'extra_bread' => ['t' => ['en' => 'Ember bread service', 'ar' => 'خبز إمبر'], 'p' => 8, 'img' => 'bread-basket'],
        'side_fries' => ['t' => ['en' => 'Extra fries', 'ar' => 'بطاطس إضافية'], 'p' => 10, 'img' => 'fries-loaded'],
        'greens'     => ['t' => ['en' => 'Charred greens side', 'ar' => 'خضار مشوية'], 'p' => 12, 'img' => 'veg-greens'],
        'scoop'      => ['t' => ['en' => 'Vanilla scoop', 'ar' => 'كرة فانيليا'], 'p' => 6, 'img' => 'icecream-scoop'],
    ],

    'categories' => [
        [
            'n' => ['en' => 'Starters', 'ar' => 'المقبلات'], 'img' => 'charcuterie',
            'items' => [
                ['n' => ['en' => 'Butcher\'s Charcuterie', 'ar' => 'لوح اللحوم المقددة'], 'd' => ['en' => 'House-cured meats, aged cheese, pickled things, warm baguette.', 'ar' => 'لحوم مقددة منزلياً مع جبن معتق ومخللات وخبز دافئ.'], 'p' => 58, 'img' => 'charcuterie', 'kcal' => 640, 'pro' => 32, 'carb' => 28, 'fat' => 44, 'alg' => [1, 7], 'ext' => ['extra_bread'], 'pop' => true, 'rate' => 4.7, 'rc' => 63],
                ['n' => ['en' => 'Smoked Meatballs', 'ar' => 'كرات اللحم المدخنة'], 'd' => ['en' => 'Oak-smoked beef meatballs, rocket, aged balsamic.', 'ar' => 'كرات لحم مدخنة على البلوط مع الجرجير والبلسمك.'], 'p' => 36, 'img' => 'kofta', 'kcal' => 480, 'pro' => 34, 'carb' => 10, 'fat' => 34, 'gf' => true, 'alg' => [], 'rate' => 4.5, 'rc' => 34],
                ['n' => ['en' => 'Ember Bread & Butters', 'ar' => 'خبز إمبر والزبدة'], 'd' => ['en' => 'Wood-oven rolls, three whipped butters.', 'ar' => 'خبز من فرن الحطب مع ثلاث زبدات مخفوقة.'], 'p' => 22, 'img' => 'bread-basket', 'kcal' => 420, 'pro' => 10, 'carb' => 56, 'fat' => 18, 'veg' => true, 'alg' => [1, 7, 9], 'rate' => 4.4, 'rc' => 27],
                ['n' => ['en' => 'Steakhouse Caesar', 'ar' => 'سيزر ستيك هاوس'], 'd' => ['en' => 'Charred baby gem, anchovy dressing, parm crisp.', 'ar' => 'خس مشوي مع صوص الأنشوجة ورقائق البارميزان.'], 'p' => 30, 'img' => 'caesar-2', 'kcal' => 380, 'pro' => 14, 'carb' => 18, 'fat' => 28, 'alg' => [1, 2, 3, 7], 'rate' => 4.4, 'rc' => 22],
                ['n' => ['en' => 'Chilled Prawn Cocktail', 'ar' => 'كوكتيل الروبيان'], 'd' => ['en' => 'Iced tiger prawns, marie-rose, lemon.', 'ar' => 'روبيان مثلج مع صوص ماري روز والليمون.'], 'p' => 44, 'img' => 'prawns-raw', 'kcal' => 280, 'pro' => 32, 'carb' => 8, 'fat' => 12, 'gf' => true, 'alg' => [2, 5], 'rate' => 4.3, 'rc' => 18],
            ],
        ],
        [
            'n' => ['en' => 'Steaks', 'ar' => 'ستيك'], 'img' => 'steak-tomahawk',
            'children' => [
                [
                    'n' => ['en' => 'Prime Cuts', 'ar' => 'القطع الفاخرة'], 'img' => 'steak-ribeye',
                    'items' => [
                        ['n' => ['en' => 'Dry-Aged Ribeye', 'ar' => 'ريب آي معتق'], 'd' => ['en' => '35-day dry-aged ribeye, oak-ember sear, herb butter.', 'ar' => 'ريب آي معتق ٣٥ يوماً يشوى على جمر البلوط مع زبدة الأعشاب.'], 'p' => 145, 'img' => 'steak-ribeye', 'gal' => ['steak-grill'], 'kcal' => 980, 'pro' => 62, 'carb' => 6, 'fat' => 74, 'gf' => true, 'alg' => [1, 9], 'grp' => ['doneness', 'cut_size', 'butter', 'side_pick', 'sauce'], 'ext' => ['prawn_top', 'bone_marrow', 'egg_top'], 'pop' => true, 'spec' => true, 'rate' => 4.9, 'rc' => 167],
                        ['n' => ['en' => 'Tomahawk for Two', 'ar' => 'توماهوك لشخصين'], 'd' => ['en' => '1.2kg bone-in tomahawk, carved tableside, two sides.', 'ar' => 'توماهوك ١٫٢ كغ بالعظم يقطع أمامك مع طبقين جانبيين.'], 'p' => 320, 'img' => 'steak-tomahawk', 'kcal' => 2200, 'pro' => 150, 'carb' => 20, 'fat' => 160, 'gf' => true, 'alg' => [1, 9], 'grp' => ['doneness', 'butter', 'sauce'], 'ext' => ['prawn_top', 'truff_mac', 'bone_marrow'], 'spec' => true, 'rate' => 4.9, 'rc' => 84, 'stock' => 3],
                        ['n' => ['en' => 'Charcoal Sirloin Board', 'ar' => 'سيرلوين على الفحم'], 'd' => ['en' => 'Grass-fed sirloin, charred asparagus, roast carrots.', 'ar' => 'سيرلوين مع الهليون المشوي والجزر المحمص.'], 'p' => 98, 'img' => 'steak-sliced', 'kcal' => 760, 'pro' => 56, 'carb' => 22, 'fat' => 48, 'gf' => true, 'alg' => [], 'grp' => ['doneness', 'butter', 'sauce'], 'rate' => 4.7, 'rc' => 71],
                        ['n' => ['en' => 'Cast-Iron Chop', 'ar' => 'شوب بالمقلاة الحديدية'], 'd' => ['en' => 'Thick-cut chop, burnt apple, broccolini.', 'ar' => 'قطعة سميكة مع التفاح المحروق والبروكليني.'], 'p' => 88, 'img' => 'steak-chop', 'kcal' => 720, 'pro' => 52, 'carb' => 20, 'fat' => 48, 'gf' => true, 'alg' => [], 'grp' => ['doneness', 'side_pick'], 'rate' => 4.6, 'rc' => 42],
                        ['n' => ['en' => 'Filet & Potato Fondant', 'ar' => 'فيليه مع البطاطس'], 'd' => ['en' => 'Centre-cut filet, fondant potato, wilted greens, jus.', 'ar' => 'فيليه مع بطاطس فوندان وخضار ومرق اللحم.'], 'p' => 128, 'img' => 'steak-plate-fine', 'kcal' => 680, 'pro' => 48, 'carb' => 30, 'fat' => 40, 'gf' => true, 'alg' => [1, 9], 'grp' => ['doneness', 'butter', 'sauce'], 'rate' => 4.8, 'rc' => 56],
                        ['n' => ['en' => 'Steak Frites Classique', 'ar' => 'ستيك فريت'], 'd' => ['en' => 'Bistro cut over golden frites, café butter.', 'ar' => 'قطعة بيسترو فوق البطاطس الذهبية مع الزبدة.'], 'p' => 92, 'img' => 'grill-spread', 'gal' => ['steak-ribeye'], 'kcal' => 880, 'pro' => 50, 'carb' => 48, 'fat' => 54, 'alg' => [1, 9], 'grp' => ['doneness', 'sauce'], 'ext' => ['side_fries'], 'rate' => 4.5, 'rc' => 38],
                    ],
                ],
                [
                    'n' => ['en' => 'Smoke & Slow', 'ar' => 'تدخين بطيء'], 'img' => 'grill-charcoal',
                    'items' => [
                        ['n' => ['en' => 'Oak-Smoked Brisket', 'ar' => 'بريسكت مدخن'], 'd' => ['en' => '14-hour oak smoke, burnt ends, house pickles.', 'ar' => 'بريسكت مدخن ١٤ ساعة مع الأطراف المحروقة والمخلل.'], 'p' => 78, 'img' => 'brisket', 'kcal' => 840, 'pro' => 54, 'carb' => 18, 'fat' => 58, 'gf' => true, 'alg' => [], 'grp' => ['side_pick'], 'pop' => true, 'rate' => 4.8, 'rc' => 92, 'stock' => 6],
                        ['n' => ['en' => 'Ember Rack of Ribs', 'ar' => 'أضلاع إمبر'], 'd' => ['en' => 'Full rack, molasses mop, charred over embers.', 'ar' => 'أضلاع كاملة بصوص دبس السكر تشوى على الجمر.'], 'p' => 96, 'img' => 'ribs-bbq', 'kcal' => 1120, 'pro' => 68, 'carb' => 42, 'fat' => 72, 'alg' => [6], 'grp' => ['side_pick'], 'spec' => true, 'rate' => 4.8, 'rc' => 77],
                        ['n' => ['en' => 'Live-Fire Cuts', 'ar' => 'قطع على النار الحية'], 'd' => ['en' => 'Butcher\'s selection seared directly over coals.', 'ar' => 'تشكيلة الجزار تشوى مباشرة على الفحم.'], 'p' => 110, 'img' => 'grill-charcoal', 'kcal' => 900, 'pro' => 64, 'carb' => 8, 'fat' => 66, 'gf' => true, 'alg' => [], 'grp' => ['doneness', 'sauce'], 'rate' => 4.7, 'rc' => 45],
                    ],
                ],
            ],
        ],
        [
            'n' => ['en' => 'Roasts & Birds', 'ar' => 'مشويات الفرن'], 'img' => 'chicken-roast',
            'items' => [
                ['n' => ['en' => 'Rotisserie Half Chicken', 'ar' => 'نصف دجاجة روتيسري'], 'd' => ['en' => 'Ember-roasted half bird, garlic jus, lemon.', 'ar' => 'نصف دجاجة مشوية على الجمر مع مرق الثوم والليمون.'], 'p' => 56, 'img' => 'chicken-roast', 'kcal' => 720, 'pro' => 58, 'carb' => 10, 'fat' => 48, 'gf' => true, 'alg' => [], 'grp' => ['side_pick'], 'rate' => 4.6, 'rc' => 47],
                ['n' => ['en' => 'Sunday Roast Turkey', 'ar' => 'ديك رومي مشوي'], 'd' => ['en' => 'Carved roast turkey, root vegetables, gravy boat.', 'ar' => 'ديك رومي مشوي مع خضار جذرية وصوص الغريفي.'], 'p' => 68, 'img' => 'turkey-roast', 'kcal' => 780, 'pro' => 64, 'carb' => 32, 'fat' => 40, 'gf' => true, 'alg' => [1, 9], 'rate' => 4.4, 'rc' => 21],
            ],
        ],
        [
            'n' => ['en' => 'Sea over Fire', 'ar' => 'بحري على النار'], 'img' => 'salmon-grilled',
            'items' => [
                ['n' => ['en' => 'Cedar Salmon', 'ar' => 'سلمون الأرز'], 'd' => ['en' => 'Cedar-plank salmon, zucchini ribbons, lime.', 'ar' => 'سلمون على لوح الأرز مع شرائح الكوسا والليمون.'], 'p' => 74, 'img' => 'salmon-grilled', 'kcal' => 540, 'pro' => 42, 'carb' => 10, 'fat' => 36, 'gf' => true, 'alg' => [3], 'pop' => true, 'rate' => 4.7, 'rc' => 51],
                ['n' => ['en' => 'Fire-Kissed Salmon Plate', 'ar' => 'طبق السلمون الناري'], 'd' => ['en' => 'Seared salmon, apple-celery salsa, glaze.', 'ar' => 'سلمون محمر مع صلصة التفاح والكرفس.'], 'p' => 78, 'img' => 'seafood-salmon', 'kcal' => 520, 'pro' => 40, 'carb' => 14, 'fat' => 34, 'gf' => true, 'alg' => [3], 'rate' => 4.6, 'rc' => 28],
                ['n' => ['en' => 'Grilled Tiger Prawns', 'ar' => 'روبيان مشوي'], 'd' => ['en' => 'Butterflied prawns, ember butter, charred lemon.', 'ar' => 'روبيان مفتوح مع زبدة الجمر والليمون المشوي.'], 'p' => 62, 'img' => 'shrimp-grilled', 'kcal' => 380, 'pro' => 38, 'carb' => 6, 'fat' => 22, 'gf' => true, 'alg' => [5, 9], 'rate' => 4.5, 'rc' => 24],
            ],
        ],
        [
            'n' => ['en' => 'Sides', 'ar' => 'أطباق جانبية'], 'img' => 'fries-loaded',
            'items' => [
                ['n' => ['en' => 'Duck-Fat Fries', 'ar' => 'بطاطس بدهن البط'], 'd' => ['en' => 'Triple-cooked, crispy shell, fluffy middle.', 'ar' => 'بطاطس مطهوة ثلاث مرات، مقرمشة من الخارج.'], 'p' => 24, 'img' => 'fries-loaded', 'kcal' => 480, 'pro' => 6, 'carb' => 56, 'fat' => 26, 'gf' => true, 'alg' => [], 'rate' => 4.6, 'rc' => 44],
                ['n' => ['en' => 'Truffle Mac & Cheese', 'ar' => 'ماك آند تشيز بالترافل'], 'd' => ['en' => 'Baked three-cheese macaroni, truffle crumb.', 'ar' => 'معكرونة بثلاثة أجبان مع فتات الترافل.'], 'p' => 32, 'img' => 'mac-cheese', 'kcal' => 640, 'pro' => 22, 'carb' => 58, 'fat' => 36, 'veg' => true, 'alg' => [1, 7], 'pop' => true, 'rate' => 4.7, 'rc' => 39],
                ['n' => ['en' => 'Charred Greens', 'ar' => 'خضار مشوية'], 'd' => ['en' => 'Broccolini, chili oil, toasted almond.', 'ar' => 'بروكليني مع زيت الفلفل واللوز المحمص.'], 'p' => 22, 'img' => 'veg-greens', 'kcal' => 180, 'pro' => 7, 'carb' => 12, 'fat' => 12, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [4], 'rate' => 4.3, 'rc' => 19],
                ['n' => ['en' => 'Ember Corn', 'ar' => 'ذرة على الجمر'], 'd' => ['en' => 'Whole cob roasted in husk, smoked butter.', 'ar' => 'ذرة كاملة تشوى بقشرتها مع الزبدة المدخنة.'], 'p' => 18, 'img' => 'corn-grilled', 'kcal' => 260, 'pro' => 6, 'carb' => 34, 'fat' => 12, 'veg' => true, 'gf' => true, 'alg' => [1, 9], 'rate' => 4.2, 'rc' => 15],
            ],
        ],
        [
            'n' => ['en' => 'Desserts', 'ar' => 'حلويات'], 'img' => 'lava-cake',
            'items' => [
                ['n' => ['en' => 'Molten Chocolate Bomb', 'ar' => 'قنبلة الشوكولاتة الذائبة'], 'd' => ['en' => 'Dark-chocolate lava centre, baked to order.', 'ar' => 'قلب شوكولاتة داكنة سائل، يخبز عند الطلب.'], 'p' => 32, 'img' => 'lava-cake', 'kcal' => 460, 'pro' => 7, 'carb' => 50, 'fat' => 26, 'veg' => true, 'alg' => [1, 2, 7], 'ext' => ['scoop'], 'pop' => true, 'spec' => true, 'rate' => 4.9, 'rc' => 81, 'stock' => 12],
                ['n' => ['en' => 'Espresso Tiramisu', 'ar' => 'تيراميسو الإسبريسو'], 'd' => ['en' => 'Table-built tiramisu, cocoa snow.', 'ar' => 'تيراميسو يجهز على الطاولة مع الكاكاو.'], 'p' => 28, 'img' => 'tiramisu', 'kcal' => 440, 'pro' => 8, 'carb' => 44, 'fat' => 26, 'veg' => true, 'alg' => [1, 2, 7], 'rate' => 4.7, 'rc' => 46],
                ['n' => ['en' => 'Burnt Basque Cheesecake', 'ar' => 'تشيز كيك الباسك'], 'd' => ['en' => 'Caramelised top, molten centre, berry coulis.', 'ar' => 'سطح مكرمل وقلب طري مع صوص التوت.'], 'p' => 28, 'img' => 'cheesecake', 'kcal' => 520, 'pro' => 9, 'carb' => 46, 'fat' => 34, 'veg' => true, 'alg' => [1, 2, 7], 'rate' => 4.6, 'rc' => 37],
                ['n' => ['en' => 'Smoked Chocolate Trifle', 'ar' => 'ترايفل الشوكولاتة'], 'd' => ['en' => 'Layered chocolate cream, cookie soil, smoke dome.', 'ar' => 'طبقات كريمة الشوكولاتة مع فتات الكوكيز.'], 'p' => 26, 'img' => 'sundae-caramel', 'kcal' => 480, 'pro' => 7, 'carb' => 54, 'fat' => 26, 'veg' => true, 'alg' => [1, 6, 7], 'rate' => 4.5, 'rc' => 23],
            ],
        ],
        [
            'n' => ['en' => 'Drinks', 'ar' => 'مشروبات'], 'img' => 'club-mocktail',
            'items' => [
                ['n' => ['en' => 'Smoked Berry Fizz', 'ar' => 'فوار التوت المدخن'], 'd' => ['en' => 'Blackberry, smoked rosemary, soda.', 'ar' => 'توت أسود مع إكليل الجبل المدخن والصودا.'], 'p' => 24, 'img' => 'club-mocktail', 'kcal' => 140, 'pro' => 0, 'carb' => 34, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'pop' => true, 'rate' => 4.5, 'rc' => 26],
                ['n' => ['en' => 'Grape & Oak Sparkler', 'ar' => 'فوار العنب والبلوط'], 'd' => ['en' => 'Sparkling red grape, oak bitters, zero proof.', 'ar' => 'عصير عنب فوار مع نكهة البلوط، خالٍ من الكحول.'], 'p' => 26, 'img' => 'wine-red', 'kcal' => 150, 'pro' => 0, 'carb' => 36, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.2, 'rc' => 13],
                ['n' => ['en' => 'Double Espresso', 'ar' => 'إسبريسو مزدوج'], 'd' => ['en' => 'Dark-roast double, served with sparkling water.', 'ar' => 'جرعة مزدوجة محمصة داكنة مع مياه غازية.'], 'p' => 14, 'img' => 'coffee-espresso', 'kcal' => 10, 'pro' => 0, 'carb' => 2, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.5, 'rc' => 22],
                ['n' => ['en' => 'Vanilla Bourbon-Style Shake', 'ar' => 'شيك الفانيليا'], 'd' => ['en' => 'Thick vanilla malt, smoked-sugar rim, zero proof.', 'ar' => 'شيك فانيليا كثيف مع حافة السكر المدخن.'], 'p' => 26, 'img' => 'milkshake', 'kcal' => 560, 'pro' => 11, 'carb' => 76, 'fat' => 24, 'veg' => true, 'alg' => [1, 7], 'rate' => 4.4, 'rc' => 17],
                ['n' => ['en' => 'Still / Sparkling Water', 'ar' => 'مياه عادية / غازية'], 'd' => ['en' => 'Chilled bottle for the table.', 'ar' => 'قارورة باردة للطاولة.'], 'p' => 8, 'img' => 'water-sparkling', 'kcal' => 0, 'pro' => 0, 'carb' => 0, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.0, 'rc' => 9],
            ],
        ],
    ],

    'reviews' => [
        ['name' => 'Abdullah R.', 'stars' => 5, 'body' => ['en' => 'The dry-aged ribeye is the best steak I have had in Riyadh.', 'ar' => 'الريب آي المعتق أفضل ستيك جربته في الرياض.']],
        ['name' => 'Huda', 'stars' => 5, 'body' => ['en' => 'Tomahawk carved at the table — pure theatre.', 'ar' => 'التوماهوك يقطع على الطاولة — عرض حقيقي.']],
        ['name' => 'Tariq', 'stars' => 4, 'body' => ['en' => 'Brisket melts. Sides are huge, come hungry.', 'ar' => 'البريسكت يذوب. الأطباق الجانبية ضخمة.']],
        ['name' => 'Amal', 'stars' => 5, 'body' => ['en' => 'Molten chocolate bomb ended the night perfectly.', 'ar' => 'قنبلة الشوكولاتة أنهت الليلة بشكل مثالي.']],
        ['name' => 'Salem', 'stars' => 4, 'body' => ['en' => 'Dark, moody, delicious. Book the corner booth.', 'ar' => 'أجواء داكنة وأكل لذيذ. احجز الركن.']],
    ],
];
