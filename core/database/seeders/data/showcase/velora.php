<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/**
 * Velora — warm Mediterranean showcase menu (WI-G2 pro rebuild).
 * Same shape as crave.php (see its header for the contract).
 */
return [
    'store' => [
        'title'    => 'Velora',
        'subtitle' => 'Mediterranean kitchen · charcoal grill · mezze table',
        'description' => 'Sun-drenched Mediterranean plates: mezze to share, charcoal skewers, saffron rice and seafood straight off the plancha.',
        'color'    => '#a6572b',
        'logo'     => 'tagine',
        'cover'    => 'restaurant-interior',
        'banners'  => ['kebab-skewers', 'seafood-platter', 'baklava-2'],
        'coupons'  => [['code' => 'VELORA10', 'pct' => 10], ['code' => 'MEZZE15', 'pct' => 15]],
    ],

    'groups' => [
        'protein'   => ['t' => ['en' => 'Protein', 'ar' => 'البروتين'], 'opts' => [['Chicken', 'دجاج', 0], ['Beef', 'لحم', 6], ['Lamb', 'ضأن', 10], ['Prawns', 'روبيان', 14]], 'required' => true],
        'skewers'   => ['t' => ['en' => 'Skewer count', 'ar' => 'عدد الأسياخ'], 'opts' => [['2 skewers', 'سيخان', 0], ['3 skewers', '٣ أسياخ', 14], ['4 skewers', '٤ أسياخ', 26]], 'required' => true],
        'bread'     => ['t' => ['en' => 'Bread', 'ar' => 'الخبز'], 'opts' => [['Saj', 'صاج', 0], ['Sourdough', 'خبز محمص', 0], ['Gluten-free', 'خالٍ من الغلوتين', 4]]],
        'rice_size' => ['t' => ['en' => 'Portion', 'ar' => 'الحجم'], 'opts' => [['Solo', 'فردي', 0], ['To share (2-3)', 'للمشاركة (٢-٣)', 28]], 'required' => true],
        'sauces'    => ['t' => ['en' => 'Sauces', 'ar' => 'الصوصات'], 'opts' => [['Toum garlic', 'ثوم', 2], ['Harissa', 'هريسة', 2], ['Tahini', 'طحينة', 2], ['Yogurt mint', 'لبن بالنعناع', 2]], 'min' => 0, 'max' => 4],
        'doneness'  => ['t' => ['en' => 'Doneness', 'ar' => 'درجة النضج'], 'opts' => [['Medium', 'متوسط', 0], ['Medium-well', 'فوق المتوسط', 0], ['Well done', 'ناضج تماماً', 0]]],
    ],

    'extras' => [
        'halloumi'  => ['t' => ['en' => 'Grilled halloumi', 'ar' => 'حلوم مشوي'], 'p' => 8, 'img' => 'cheese-platter'],
        'saj'       => ['t' => ['en' => 'Extra saj bread', 'ar' => 'خبز صاج إضافي'], 'p' => 3, 'img' => 'bread-basket'],
        'pickles'   => ['t' => ['en' => 'Pickle plate', 'ar' => 'طبق مخللات'], 'p' => 4, 'img' => 'veg-greens'],
        'skewer'    => ['t' => ['en' => 'Extra skewer', 'ar' => 'سيخ إضافي'], 'p' => 14, 'img' => 'kebab-charcoal'],
        'prawns3'   => ['t' => ['en' => 'Grilled prawns (3)', 'ar' => 'روبيان مشوي (٣)'], 'p' => 18, 'img' => 'shrimp-grilled'],
        'salad_side' => ['t' => ['en' => 'Side salad', 'ar' => 'سلطة جانبية'], 'p' => 8, 'img' => 'fattoush-2'],
        'figs'      => ['t' => ['en' => 'Fresh figs', 'ar' => 'تين طازج'], 'p' => 9, 'img' => 'fruit-fig-plate'],
        'honey'     => ['t' => ['en' => 'Honey drizzle', 'ar' => 'رشة عسل'], 'p' => 3, 'img' => 'crepes-honey'],
    ],

    'categories' => [
        [
            'n' => ['en' => 'Mezze & Starters', 'ar' => 'مقبلات ومازة'], 'img' => 'falafel-plate-real',
            'items' => [
                ['n' => ['en' => 'Falafel Mezze Plate', 'ar' => 'صحن فلافل'], 'd' => ['en' => 'Crisp falafel, garden salad, warm pita, tahini pour.', 'ar' => 'فلافل مقرمشة مع سلطة وخبز دافئ وطحينة.'], 'p' => 22, 'img' => 'falafel-plate-real', 'kcal' => 420, 'pro' => 14, 'carb' => 48, 'fat' => 20, 'veg' => true, 'vegan' => true, 'alg' => [7], 'grp' => ['sauces', 'bread'], 'ext' => ['saj', 'pickles'], 'pop' => true, 'rate' => 4.7, 'rc' => 118],
                ['n' => ['en' => 'Bruschetta Trio', 'ar' => 'ثلاثية البروسكيتا'], 'd' => ['en' => 'Charred sourdough, marinated tomato, basil oil.', 'ar' => 'خبز محمص مع طماطم متبلة وزيت الريحان.'], 'p' => 20, 'img' => 'bruschetta', 'kcal' => 320, 'pro' => 8, 'carb' => 42, 'fat' => 14, 'veg' => true, 'vegan' => true, 'alg' => [7], 'rate' => 4.5, 'rc' => 47],
                ['n' => ['en' => 'Vine Garden Rolls', 'ar' => 'لفائف الحديقة'], 'd' => ['en' => 'Fresh rolls of crisp vegetables, pomegranate dip.', 'ar' => 'لفائف خضار مقرمشة مع صوص الرمان.'], 'p' => 18, 'img' => 'spring-rolls', 'kcal' => 240, 'pro' => 6, 'carb' => 32, 'fat' => 8, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.3, 'rc' => 28],
                ['n' => ['en' => 'Halloumi Garden Wrap', 'ar' => 'راب الحلوم'], 'd' => ['en' => 'Seared halloumi, crunch veg, mint yogurt, saj wrap.', 'ar' => 'حلوم مشوي مع خضار ولبن بالنعناع في خبز الصاج.'], 'p' => 24, 'img' => 'wrap-halloumi', 'kcal' => 460, 'pro' => 18, 'carb' => 42, 'fat' => 24, 'veg' => true, 'alg' => [1, 7], 'grp' => ['bread'], 'rate' => 4.4, 'rc' => 33],
                ['n' => ['en' => 'Artisan Cheese Board', 'ar' => 'لوح الأجبان'], 'd' => ['en' => 'Aged blue, figs, walnuts, grape clusters.', 'ar' => 'أجبان معتقة مع تين وجوز وعنب.'], 'p' => 42, 'img' => 'cheese-platter', 'kcal' => 580, 'pro' => 24, 'carb' => 22, 'fat' => 44, 'veg' => true, 'gf' => true, 'alg' => [1, 4], 'ext' => ['figs', 'honey'], 'rate' => 4.6, 'rc' => 25],
                ['n' => ['en' => 'Charred Market Greens', 'ar' => 'خضار مشوية'], 'd' => ['en' => 'Broccolini, lemon oil, toasted seeds.', 'ar' => 'بروكليني مع زيت الليمون والبذور المحمصة.'], 'p' => 19, 'img' => 'veg-greens', 'kcal' => 180, 'pro' => 7, 'carb' => 14, 'fat' => 12, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.2, 'rc' => 17],
            ],
        ],
        [
            'n' => ['en' => 'Salads', 'ar' => 'سلطات'], 'img' => 'fattoush-2',
            'items' => [
                ['n' => ['en' => 'Fattoush', 'ar' => 'فتوش'], 'd' => ['en' => 'Crisp greens, feta, sumac dressing, pita crisps.', 'ar' => 'خضار مقرمشة مع فيتا وصوص السماق ورقائق الخبز.'], 'p' => 24, 'img' => 'fattoush-2', 'kcal' => 280, 'pro' => 8, 'carb' => 26, 'fat' => 16, 'veg' => true, 'alg' => [1, 7], 'pop' => true, 'rate' => 4.7, 'rc' => 86],
                ['n' => ['en' => 'Tabbouleh Jar', 'ar' => 'تبولة'], 'd' => ['en' => 'Parsley-forward layers, bulgur, lemon dressing, to-go jar.', 'ar' => 'تبولة بالبقدونس والبرغل وصوص الليمون في برطمان.'], 'p' => 20, 'img' => 'tabbouleh', 'kcal' => 220, 'pro' => 5, 'carb' => 34, 'fat' => 8, 'veg' => true, 'vegan' => true, 'alg' => [7], 'rate' => 4.4, 'rc' => 39],
                ['n' => ['en' => 'Fig & Pomegranate Salad', 'ar' => 'سلطة التين والرمان'], 'd' => ['en' => 'Ripe figs, pomegranate pearls, candied nuts, greens.', 'ar' => 'تين ناضج مع حبوب الرمان والمكسرات المحلاة.'], 'p' => 32, 'img' => 'salad-fig', 'kcal' => 320, 'pro' => 6, 'carb' => 42, 'fat' => 14, 'veg' => true, 'gf' => true, 'alg' => [4], 'spec' => true, 'rate' => 4.6, 'rc' => 22],
                ['n' => ['en' => 'Grilled Chicken Caesar', 'ar' => 'سيزر الدجاج'], 'd' => ['en' => 'Char-grilled chicken, shaved parm, golden croutons.', 'ar' => 'دجاج مشوي مع بارميزان وخبز محمص ذهبي.'], 'p' => 32, 'img' => 'caesar-2', 'kcal' => 460, 'pro' => 34, 'carb' => 24, 'fat' => 26, 'alg' => [1, 2, 3, 7], 'rate' => 4.5, 'rc' => 41],
            ],
        ],
        [
            'n' => ['en' => 'Charcoal Grill', 'ar' => 'مشاوي على الفحم'], 'img' => 'kebab-charcoal',
            'children' => [
                [
                    'n' => ['en' => 'Skewers & Kebab', 'ar' => 'أسياخ وكباب'], 'img' => 'chicken-shish',
                    'items' => [
                        ['n' => ['en' => 'Adana Kofta Plate', 'ar' => 'كفتة أضنة'], 'd' => ['en' => 'Hand-minced spiced kofta, charred tomato, rice, ezme.', 'ar' => 'كفتة متبلة مفرومة يدوياً مع طماطم مشوية وأرز.'], 'p' => 46, 'img' => 'chicken-shish', 'kcal' => 640, 'pro' => 42, 'carb' => 44, 'fat' => 34, 'spice' => 2, 'alg' => [], 'grp' => ['skewers', 'sauces'], 'ext' => ['skewer', 'saj'], 'pop' => true, 'spec' => true, 'rate' => 4.8, 'rc' => 134],
                        ['n' => ['en' => 'Charcoal Kebab Skewers', 'ar' => 'كباب على الفحم'], 'd' => ['en' => 'Marinated cubes seared over live coals.', 'ar' => 'مكعبات لحم متبلة تشوى على الجمر.'], 'p' => 48, 'img' => 'kebab-charcoal', 'kcal' => 580, 'pro' => 44, 'carb' => 18, 'fat' => 36, 'gf' => true, 'alg' => [], 'grp' => ['protein', 'skewers', 'doneness'], 'ext' => ['skewer'], 'pop' => true, 'rate' => 4.9, 'rc' => 156],
                        ['n' => ['en' => 'Mixed Grill Feast', 'ar' => 'مشكل مشاوي'], 'd' => ['en' => 'Shish, kofta, wings and lamb — a board for two.', 'ar' => 'شيش وكفتة وأجنحة وضأن — لوح لشخصين.'], 'p' => 96, 'img' => 'kebab-skewers', 'gal' => ['grill-spread'], 'kcal' => 1240, 'pro' => 92, 'carb' => 46, 'fat' => 74, 'alg' => [], 'grp' => ['sauces'], 'ext' => ['skewer', 'saj', 'salad_side'], 'spec' => true, 'rate' => 4.8, 'rc' => 88],
                        ['n' => ['en' => 'Lamb Kebab & Frites', 'ar' => 'كباب ضأن مع بطاطس'], 'd' => ['en' => 'Juicy lamb skewers, grilled tomato, golden fries.', 'ar' => 'أسياخ ضأن طرية مع طماطم مشوية وبطاطس ذهبية.'], 'p' => 52, 'img' => 'kebab-fries', 'kcal' => 760, 'pro' => 46, 'carb' => 48, 'fat' => 42, 'alg' => [], 'grp' => ['skewers'], 'rate' => 4.6, 'rc' => 54],
                        ['n' => ['en' => 'Smoked Kofta Meatballs', 'ar' => 'كرات الكفتة المدخنة'], 'd' => ['en' => 'Char-kissed meatballs on rocket, pine-nut butter.', 'ar' => 'كرات لحم مدخنة على الجرجير مع زبدة الصنوبر.'], 'p' => 38, 'img' => 'kofta', 'kcal' => 520, 'pro' => 36, 'carb' => 12, 'fat' => 36, 'gf' => true, 'alg' => [4], 'grp' => ['sauces'], 'rate' => 4.5, 'rc' => 36],
                        ['n' => ['en' => 'Shawarma Board', 'ar' => 'لوح الشاورما'], 'd' => ['en' => 'Slow-spit shawarma, toum, pickles, saj on the side.', 'ar' => 'شاورما على السيخ مع الثوم والمخلل وخبز الصاج.'], 'p' => 34, 'img' => 'shawarma', 'kcal' => 620, 'pro' => 38, 'carb' => 40, 'fat' => 34, 'alg' => [7], 'grp' => ['protein', 'bread', 'sauces'], 'pop' => true, 'rate' => 4.7, 'rc' => 97],
                    ],
                ],
                [
                    'n' => ['en' => 'Oven & Roast', 'ar' => 'من الفرن'], 'img' => 'chicken-skillet',
                    'items' => [
                        ['n' => ['en' => 'Skillet Roast Chicken', 'ar' => 'دجاج بالمقلاة'], 'd' => ['en' => 'Half chicken, rosemary potatoes, pan jus.', 'ar' => 'نصف دجاجة مع بطاطس بإكليل الجبل ومرق المقلاة.'], 'p' => 52, 'img' => 'chicken-skillet', 'kcal' => 720, 'pro' => 54, 'carb' => 38, 'fat' => 40, 'gf' => true, 'alg' => [], 'rate' => 4.6, 'rc' => 48],
                        ['n' => ['en' => 'Whole Roast Chicken', 'ar' => 'دجاجة كاملة مشوية'], 'd' => ['en' => 'Brick-oven whole bird, garlic confit, herbs — for two.', 'ar' => 'دجاجة كاملة من الفرن الحجري مع الثوم والأعشاب — لشخصين.'], 'p' => 78, 'img' => 'chicken-roast', 'kcal' => 1380, 'pro' => 110, 'carb' => 22, 'fat' => 92, 'gf' => true, 'alg' => [], 'grp' => ['sauces'], 'ext' => ['salad_side', 'saj'], 'rate' => 4.7, 'rc' => 39, 'stock' => 6],
                    ],
                ],
            ],
        ],
        [
            'n' => ['en' => 'Rice, Tagine & Stews', 'ar' => 'أرز وطواجن'], 'img' => 'mandi-plate',
            'items' => [
                ['n' => ['en' => 'Royal Lamb Mandi', 'ar' => 'مندي لحم ملكي'], 'd' => ['en' => 'Smoked basmati, fall-apart lamb, mandi spice oil.', 'ar' => 'أرز بسمتي مدخن مع لحم ضأن متفتت وبهارات المندي.'], 'p' => 58, 'img' => 'mandi-plate', 'kcal' => 880, 'pro' => 52, 'carb' => 86, 'fat' => 38, 'gf' => true, 'alg' => [], 'grp' => ['rice_size'], 'pop' => true, 'spec' => true, 'rate' => 4.9, 'rc' => 143],
                ['n' => ['en' => 'Chicken Kabsa', 'ar' => 'كبسة دجاج'], 'd' => ['en' => 'Spiced tomato rice, roasted chicken, cucumber yogurt.', 'ar' => 'أرز بالطماطم والبهارات مع دجاج محمر ولبن بالخيار.'], 'p' => 44, 'img' => 'kabsa', 'kcal' => 760, 'pro' => 46, 'carb' => 88, 'fat' => 26, 'gf' => true, 'alg' => [1], 'grp' => ['rice_size'], 'pop' => true, 'rate' => 4.7, 'rc' => 112],
                ['n' => ['en' => 'Seven-Vegetable Tagine', 'ar' => 'طاجن الخضار'], 'd' => ['en' => 'Clay-pot vegetables, apricot, ras el hanout broth.', 'ar' => 'خضار في طاجن الفخار مع المشمش وبهارات راس الحانوت.'], 'p' => 36, 'img' => 'tagine', 'kcal' => 420, 'pro' => 10, 'carb' => 64, 'fat' => 14, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.5, 'rc' => 31],
                ['n' => ['en' => 'Harissa Chicken Stew', 'ar' => 'يخنة الدجاج بالهريسة'], 'd' => ['en' => 'Slow-simmered chicken, smoky harissa, herbs.', 'ar' => 'دجاج مطهو ببطء مع الهريسة المدخنة والأعشاب.'], 'p' => 38, 'img' => 'stew-red', 'kcal' => 520, 'pro' => 36, 'carb' => 28, 'fat' => 30, 'spice' => 2, 'gf' => true, 'alg' => [], 'grp' => ['bread'], 'rate' => 4.4, 'rc' => 27],
                ['n' => ['en' => 'Seafood Paella', 'ar' => 'باييا بحرية'], 'd' => ['en' => 'Saffron bomba rice, mussels, prawns, socarrat crust.', 'ar' => 'أرز بالزعفران مع بلح البحر والروبيان.'], 'p' => 68, 'img' => 'paella', 'kcal' => 720, 'pro' => 38, 'carb' => 78, 'fat' => 26, 'gf' => true, 'alg' => [3, 5], 'grp' => ['rice_size'], 'ext' => ['prawns3'], 'spec' => true, 'rate' => 4.7, 'rc' => 58],
                ['n' => ['en' => 'Saffron Mushroom Risotto', 'ar' => 'ريزوتو الزعفران'], 'd' => ['en' => 'Carnaroli rice, saffron threads, parmesan wave.', 'ar' => 'أرز كارنارولي مع خيوط الزعفران والبارميزان.'], 'p' => 42, 'img' => 'risotto', 'kcal' => 560, 'pro' => 14, 'carb' => 72, 'fat' => 22, 'veg' => true, 'gf' => true, 'alg' => [1], 'rate' => 4.5, 'rc' => 24],
            ],
        ],
        [
            'n' => ['en' => 'Pasta & Oven Bakes', 'ar' => 'باستا ومخبوزات الفرن'], 'img' => 'lasagna',
            'items' => [
                ['n' => ['en' => 'Ricotta Ravioli', 'ar' => 'رافيولي الريكوتا'], 'd' => ['en' => 'Hand-folded ravioli, sage butter, pecorino.', 'ar' => 'رافيولي مطوية يدوياً مع زبدة المريمية.'], 'p' => 40, 'img' => 'ravioli', 'kcal' => 540, 'pro' => 20, 'carb' => 58, 'fat' => 26, 'veg' => true, 'alg' => [1, 2, 7, 9], 'rate' => 4.6, 'rc' => 35],
                ['n' => ['en' => 'Lasagna al Forno', 'ar' => 'لازانيا بالفرن'], 'd' => ['en' => 'Seven layers, slow ragù, bubbling mozzarella.', 'ar' => 'سبع طبقات مع الراغو وموزاريلا ذائبة.'], 'p' => 44, 'img' => 'lasagna', 'kcal' => 680, 'pro' => 34, 'carb' => 54, 'fat' => 36, 'alg' => [1, 7], 'pop' => true, 'rate' => 4.7, 'rc' => 63],
                ['n' => ['en' => 'Pesto Farfalle', 'ar' => 'فارفالي بالبيستو'], 'd' => ['en' => 'Basil-pine pesto, cherry tomato, rocket.', 'ar' => 'بيستو الريحان والصنوبر مع الطماطم الكرزية.'], 'p' => 34, 'img' => 'pasta-pesto', 'kcal' => 520, 'pro' => 14, 'carb' => 64, 'fat' => 24, 'veg' => true, 'alg' => [1, 4, 7], 'rate' => 4.4, 'rc' => 29],
                ['n' => ['en' => 'Fusilli Primavera', 'ar' => 'فوسيلي بريمافيرا'], 'd' => ['en' => 'Spring vegetables, tomato-basil toss.', 'ar' => 'خضار الربيع مع الطماطم والريحان.'], 'p' => 32, 'img' => 'pasta-fusilli', 'kcal' => 480, 'pro' => 14, 'carb' => 68, 'fat' => 16, 'veg' => true, 'vegan' => true, 'alg' => [7], 'rate' => 4.3, 'rc' => 21],
            ],
        ],
        [
            'n' => ['en' => 'From the Sea', 'ar' => 'من البحر'], 'img' => 'seafood-platter',
            'items' => [
                ['n' => ['en' => 'Salmon a la Plancha', 'ar' => 'سلمون على الصاج'], 'd' => ['en' => 'Crisp-skin salmon, lemon-butter emulsion, herbs.', 'ar' => 'سلمون بجلد مقرمش مع زبدة الليمون.'], 'p' => 64, 'img' => 'salmon-pan', 'kcal' => 560, 'pro' => 42, 'carb' => 8, 'fat' => 38, 'gf' => true, 'alg' => [1, 3, 9], 'pop' => true, 'rate' => 4.8, 'rc' => 72],
                ['n' => ['en' => 'Garlic Butter Prawns', 'ar' => 'روبيان بزبدة الثوم'], 'd' => ['en' => 'Sizzling prawns, garlic, chili, warm bread.', 'ar' => 'روبيان مقلي بالثوم والفلفل مع خبز دافئ.'], 'p' => 52, 'img' => 'prawns-garlic', 'kcal' => 440, 'pro' => 32, 'carb' => 12, 'fat' => 28, 'spice' => 1, 'gf' => true, 'alg' => [5, 9], 'ext' => ['saj', 'prawns3'], 'rate' => 4.7, 'rc' => 56],
                ['n' => ['en' => 'Chilled Prawn Platter', 'ar' => 'طبق الروبيان البارد'], 'd' => ['en' => 'Poached tiger prawns on ice, two dips.', 'ar' => 'روبيان مسلوق يقدم بارداً مع صوصين.'], 'p' => 58, 'img' => 'shrimp-grilled', 'kcal' => 320, 'pro' => 40, 'carb' => 6, 'fat' => 14, 'gf' => true, 'alg' => [5], 'rate' => 4.5, 'rc' => 26],
                ['n' => ['en' => 'Whole Grilled Fish', 'ar' => 'سمكة مشوية كاملة'], 'd' => ['en' => 'Market fish over herbed rice, charred lemon.', 'ar' => 'سمكة السوق على أرز بالأعشاب مع ليمون مشوي.'], 'p' => 72, 'img' => 'fish-grilled-whole', 'kcal' => 620, 'pro' => 48, 'carb' => 44, 'fat' => 26, 'gf' => true, 'alg' => [3], 'rate' => 4.6, 'rc' => 34],
                ['n' => ['en' => 'Velora Seafood Board', 'ar' => 'لوح فيلورا البحري'], 'd' => ['en' => 'Grilled octopus, prawns, calamari — the whole coast.', 'ar' => 'أخطبوط وروبيان وكاليماري مشوي — الساحل كاملاً.'], 'p' => 128, 'img' => 'seafood-platter', 'gal' => ['seafood-market'], 'kcal' => 940, 'pro' => 88, 'carb' => 24, 'fat' => 52, 'gf' => true, 'alg' => [3, 5], 'spec' => true, 'rate' => 4.8, 'rc' => 43, 'stock' => 4],
                ['n' => ['en' => 'Salmon Crudo', 'ar' => 'سلمون كرودو'], 'd' => ['en' => 'Sashimi-cut salmon, citrus oil, sea salt.', 'ar' => 'شرائح سلمون نيئة مع زيت الحمضيات وملح البحر.'], 'p' => 46, 'img' => 'salmon-sashimi', 'kcal' => 260, 'pro' => 24, 'carb' => 2, 'fat' => 18, 'gf' => true, 'alg' => [3], 'rate' => 4.4, 'rc' => 19],
                ['n' => ['en' => 'Citrus Ceviche', 'ar' => 'سيفيتشي الحمضيات'], 'd' => ['en' => 'Lime-cured white fish, red onion, coriander.', 'ar' => 'سمك أبيض منقوع بالليمون مع البصل الأحمر والكزبرة.'], 'p' => 44, 'img' => 'ceviche', 'kcal' => 240, 'pro' => 28, 'carb' => 12, 'fat' => 8, 'gf' => true, 'alg' => [3], 'rate' => 4.3, 'rc' => 16],
                ['n' => ['en' => 'Salmon Fine Plate', 'ar' => 'طبق السلمون الفاخر'], 'd' => ['en' => 'Seared salmon, apple salsa, reduction glaze.', 'ar' => 'سلمون محمر مع صلصة التفاح وصوص مركز.'], 'p' => 68, 'img' => 'seafood-salmon', 'kcal' => 520, 'pro' => 40, 'carb' => 14, 'fat' => 34, 'gf' => true, 'alg' => [3], 'rate' => 4.6, 'rc' => 22],
            ],
        ],
        [
            'n' => ['en' => 'Desserts', 'ar' => 'حلويات'], 'img' => 'baklava-2',
            'items' => [
                ['n' => ['en' => 'Pistachio Baklava Tray', 'ar' => 'بقلاوة بالفستق'], 'd' => ['en' => 'Forty-layer filo, Aleppo pistachio, orange-blossom syrup.', 'ar' => 'رقائق الفيلو مع فستق حلبي وقطر بماء الزهر.'], 'p' => 28, 'img' => 'baklava-2', 'kcal' => 520, 'pro' => 9, 'carb' => 58, 'fat' => 28, 'veg' => true, 'alg' => [4, 7, 9], 'pop' => true, 'spec' => true, 'rate' => 4.9, 'rc' => 94],
                ['n' => ['en' => 'Honey Cheese Crepes', 'ar' => 'كريب بالجبن والعسل'], 'd' => ['en' => 'Warm crepes, sweet cheese, mountain honey.', 'ar' => 'كريب دافئ مع جبن حلو وعسل جبلي.'], 'p' => 24, 'img' => 'crepes-honey', 'kcal' => 460, 'pro' => 12, 'carb' => 56, 'fat' => 20, 'veg' => true, 'alg' => [1, 2, 7], 'ext' => ['honey'], 'rate' => 4.6, 'rc' => 38],
                ['n' => ['en' => 'Fig & Rose Plate', 'ar' => 'طبق التين والورد'], 'd' => ['en' => 'Fresh figs, berries, rose petals, pomegranate dust.', 'ar' => 'تين طازج مع توت وبتلات الورد.'], 'p' => 30, 'img' => 'fruit-fig-plate', 'kcal' => 220, 'pro' => 3, 'carb' => 48, 'fat' => 2, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.4, 'rc' => 18],
                ['n' => ['en' => 'Seasonal Fruit Platter', 'ar' => 'طبق فواكه موسمية'], 'd' => ['en' => 'Carved seasonal fruit, mint, citrus dust.', 'ar' => 'فواكه موسمية مقطعة مع النعناع.'], 'p' => 26, 'img' => 'fruit-platter', 'kcal' => 180, 'pro' => 2, 'carb' => 44, 'fat' => 1, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.3, 'rc' => 14],
                ['n' => ['en' => 'Rose Cupcakes (2)', 'ar' => 'كب كيك الورد (٢)'], 'd' => ['en' => 'Rose-water sponge, pistachio crumb, cream swirl.', 'ar' => 'كيك بماء الورد مع فتات الفستق والكريمة.'], 'p' => 18, 'img' => 'cupcakes', 'kcal' => 380, 'pro' => 5, 'carb' => 52, 'fat' => 18, 'veg' => true, 'alg' => [1, 2, 4, 7], 'rate' => 4.2, 'rc' => 12],
            ],
        ],
        [
            'n' => ['en' => 'Tea & Refreshers', 'ar' => 'شاي ومرطبات'], 'img' => 'tea-pot',
            'items' => [
                ['n' => ['en' => 'Moroccan Mint Tea Pot', 'ar' => 'إبريق شاي مغربي'], 'd' => ['en' => 'Gunpowder green tea, fresh mint, poured high.', 'ar' => 'شاي أخضر مع نعناع طازج يصب من علو.'], 'p' => 18, 'img' => 'tea-pot', 'kcal' => 40, 'pro' => 0, 'carb' => 10, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'pop' => true, 'rate' => 4.7, 'rc' => 52],
                ['n' => ['en' => 'Istikana Black Tea', 'ar' => 'شاي استكانة'], 'd' => ['en' => 'Loose-leaf black tea, served in glass.', 'ar' => 'شاي أسود ورقي يقدم في كأس زجاجي.'], 'p' => 8, 'img' => 'tea-black', 'kcal' => 5, 'pro' => 0, 'carb' => 1, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.4, 'rc' => 28],
                ['n' => ['en' => 'Tea & Biscuits', 'ar' => 'شاي وبسكويت'], 'd' => ['en' => 'Milky karak-style tea, two butter biscuits.', 'ar' => 'شاي كرك بالحليب مع بسكويت الزبدة.'], 'p' => 12, 'img' => 'tea-cookies', 'kcal' => 220, 'pro' => 5, 'carb' => 32, 'fat' => 8, 'veg' => true, 'alg' => [1, 7, 9], 'rate' => 4.3, 'rc' => 19],
                ['n' => ['en' => 'Cloudy Lemonade', 'ar' => 'ليموناضة'], 'd' => ['en' => 'Pressed lemon, cane sugar, iced jugs.', 'ar' => 'ليمون معصور مع سكر القصب والثلج.'], 'p' => 16, 'img' => 'lemonade', 'kcal' => 140, 'pro' => 0, 'carb' => 36, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.5, 'rc' => 31],
                ['n' => ['en' => 'Pink Citrus Cooler', 'ar' => 'مبرد الحمضيات الوردي'], 'd' => ['en' => 'Grapefruit, rose syrup, sparkling top.', 'ar' => 'جريب فروت مع شراب الورد والصودا.'], 'p' => 20, 'img' => 'mocktail-pink', 'kcal' => 130, 'pro' => 0, 'carb' => 32, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'spec' => true, 'rate' => 4.4, 'rc' => 16],
                ['n' => ['en' => 'Garden Mojito', 'ar' => 'موهيتو الحديقة'], 'd' => ['en' => 'Crushed mint, lime wheels, soda over ice.', 'ar' => 'نعناع مهروس مع شرائح الليمون والصودا.'], 'p' => 19, 'img' => 'mojito-2', 'kcal' => 120, 'pro' => 0, 'carb' => 30, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.5, 'rc' => 23],
            ],
        ],
    ],

    'reviews' => [
        ['name' => 'Reem S.', 'stars' => 5, 'body' => ['en' => 'The mandi falls apart with a spoon. Best in the city.', 'ar' => 'المندي يتفتت بالملعقة. الأفضل في المدينة.']],
        ['name' => 'Faisal', 'stars' => 5, 'body' => ['en' => 'Charcoal kebab tastes like a Bosphorus night.', 'ar' => 'الكباب على الفحم طعمه مثل ليالي البسفور.']],
        ['name' => 'Maha', 'stars' => 4, 'body' => ['en' => 'Fattoush is crazy fresh, mezze portions generous.', 'ar' => 'الفتوش طازج جداً وكميات المازة سخية.']],
        ['name' => 'Yousef', 'stars' => 5, 'body' => ['en' => 'Baklava tray gone in five minutes. Order two.', 'ar' => 'صينية البقلاوة اختفت في خمس دقائق. اطلب اثنتين.']],
        ['name' => 'Dana', 'stars' => 4, 'body' => ['en' => 'Seafood board is a feast — worth every riyal.', 'ar' => 'اللوح البحري وليمة — يستحق كل ريال.']],
    ],
];
