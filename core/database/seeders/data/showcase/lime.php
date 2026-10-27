<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/** Lime Fresh — healthy bowls showcase menu. Same shape as crave.php. */
return [
    'store' => [
        'title' => 'Lime Fresh', 'subtitle' => 'Healthy bowls · cold-pressed · macro-counted',
        'description' => 'Bright healthy kitchen: build-your-bowl, big salads, cold-pressed juices — every macro on the label.',
        'color' => '#5fa832', 'logo' => 'salad-garden', 'cover' => 'poke-bowl',
        'banners' => ['poke-bowl', 'juice-green', 'fruit-platter'],
        'coupons' => [['code' => 'LIME10', 'pct' => 10]],
    ],
    'groups' => [
        'base'    => ['t' => ['en' => 'Base', 'ar' => 'الأساس'], 'opts' => [['Quinoa', 'كينوا', 0], ['Brown rice', 'أرز بني', 0], ['Leaves', 'أوراق خضراء', 0]], 'required' => true],
        'protein' => ['t' => ['en' => 'Protein', 'ar' => 'البروتين'], 'opts' => [['Chicken', 'دجاج', 0], ['Salmon', 'سلمون', 14], ['Falafel', 'فلافل', 0], ['Halloumi', 'حلوم', 6]], 'required' => true],
        'dressing' => ['t' => ['en' => 'Dressing', 'ar' => 'الصوص'], 'opts' => [['Citrus', 'حمضيات', 0], ['Tahini', 'طحينة', 0], ['Yogurt mint', 'لبن بالنعناع', 0]], 'required' => true],
        'boost'   => ['t' => ['en' => 'Boosters', 'ar' => 'معززات'], 'opts' => [['Avocado', 'أفوكادو', 5], ['Soft egg', 'بيضة', 4], ['Seeds mix', 'خليط بذور', 3]], 'min' => 0, 'max' => 3],
    ],
    'extras' => [
        'avocado' => ['t' => ['en' => 'Avocado half', 'ar' => 'نصف أفوكادو'], 'p' => 5, 'img' => 'avocado-toast'],
        'egg'     => ['t' => ['en' => 'Soft egg', 'ar' => 'بيضة'], 'p' => 4, 'img' => 'eggs-benedict'],
        'greens'  => ['t' => ['en' => 'Charred greens', 'ar' => 'خضار مشوية'], 'p' => 8, 'img' => 'veg-greens'],
        'fruit'   => ['t' => ['en' => 'Fruit cup', 'ar' => 'كوب فواكه'], 'p' => 9, 'img' => 'fruit-platter'],
    ],
    'categories' => [
        [
            'n' => ['en' => 'Signature Bowls', 'ar' => 'أطباق مميزة'], 'img' => 'poke-bowl',
            'items' => [
                ['n' => ['en' => 'Salmon Poke Bowl', 'ar' => 'بوكي السلمون'], 'd' => ['en' => 'Sushi rice, salmon cubes, edamame, avocado, ponzu.', 'ar' => 'أرز سوشي مع سلمون وإدامامي وأفوكادو.'], 'p' => 46, 'img' => 'poke-bowl', 'gal' => ['bowl-grain', 'salad-garden'], 'kcal' => 520, 'pro' => 30, 'carb' => 58, 'fat' => 20, 'gf' => true, 'alg' => [3, 6], 'grp' => ['base', 'boost'], 'pop' => true, 'spec' => true, 'rate' => 4.8, 'rc' => 74],
                ['n' => ['en' => 'Build-Your-Bowl', 'ar' => 'ركّب طبقك'], 'd' => ['en' => 'Pick base, protein, dressing, boosters — we do the rest.', 'ar' => 'اختر الأساس والبروتين والصوص والمعززات.'], 'p' => 38, 'img' => 'bowl-grain', 'kcal' => 440, 'pro' => 24, 'carb' => 48, 'fat' => 16, 'gf' => true, 'alg' => [], 'grp' => ['base', 'protein', 'dressing', 'boost'], 'pop' => true, 'rate' => 4.7, 'rc' => 61],
                ['n' => ['en' => 'Seoul Protein Bowl', 'ar' => 'طبق البروتين الكوري'], 'd' => ['en' => 'Warm rice, seasoned veg, soft egg, gochujang.', 'ar' => 'أرز دافئ مع خضار متبلة وبيض وصوص كوري.'], 'p' => 36, 'img' => 'bibimbap', 'kcal' => 580, 'pro' => 22, 'carb' => 76, 'fat' => 20, 'spice' => 2, 'alg' => [2, 6], 'grp' => ['protein'], 'rate' => 4.5, 'rc' => 32],
                ['n' => ['en' => 'Prawn Citrus Bowl', 'ar' => 'طبق الروبيان'], 'd' => ['en' => 'Herbed rice, grilled prawns, citrus dressing.', 'ar' => 'أرز بالأعشاب مع روبيان مشوي وصوص الحمضيات.'], 'p' => 44, 'img' => 'rice-bowl', 'kcal' => 520, 'pro' => 28, 'carb' => 64, 'fat' => 14, 'gf' => true, 'alg' => [5], 'grp' => ['base', 'dressing'], 'rate' => 4.5, 'rc' => 26],
            ],
        ],
        [
            'n' => ['en' => 'Big Salads', 'ar' => 'سلطات كبيرة'], 'img' => 'salad-garden',
            'items' => [
                ['n' => ['en' => 'Garden Crunch', 'ar' => 'سلطة الحديقة'], 'd' => ['en' => 'Rainbow leaves, avocado, seeds, citrus dressing.', 'ar' => 'خضار ملونة مع أفوكادو وبذور.'], 'p' => 30, 'img' => 'salad-garden', 'kcal' => 240, 'pro' => 6, 'carb' => 18, 'fat' => 16, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'grp' => ['protein', 'dressing'], 'ext' => ['avocado', 'egg'], 'pop' => true, 'rate' => 4.6, 'rc' => 44],
                ['n' => ['en' => 'Kale & Chickpea', 'ar' => 'كيل وحمص'], 'd' => ['en' => 'Massaged kale, crispy chickpeas, lemon tahini.', 'ar' => 'كرنب مع حمص مقرمش وطحينة الليمون.'], 'p' => 28, 'img' => 'salad-greek', 'kcal' => 300, 'pro' => 12, 'carb' => 30, 'fat' => 16, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'grp' => ['dressing'], 'rate' => 4.4, 'rc' => 27],
                ['n' => ['en' => 'Fattoush Fresh', 'ar' => 'فتوش'], 'd' => ['en' => 'Crisp greens, feta, sumac, pita crisps.', 'ar' => 'خضار مقرمشة مع فيتا وسماق ورقائق خبز.'], 'p' => 28, 'img' => 'fattoush-2', 'kcal' => 280, 'pro' => 8, 'carb' => 26, 'fat' => 16, 'veg' => true, 'alg' => [1, 7], 'rate' => 4.5, 'rc' => 31],
                ['n' => ['en' => 'Tabbouleh Jar', 'ar' => 'تبولة'], 'd' => ['en' => 'Parsley layers, bulgur, lemon — grab & go.', 'ar' => 'تبولة بالبقدونس والبرغل في برطمان.'], 'p' => 22, 'img' => 'tabbouleh', 'kcal' => 220, 'pro' => 5, 'carb' => 34, 'fat' => 8, 'veg' => true, 'vegan' => true, 'alg' => [7], 'rate' => 4.3, 'rc' => 18],
                ['n' => ['en' => 'Chicken Caesar Light', 'ar' => 'سيزر خفيفة'], 'd' => ['en' => 'Grilled chicken, yogurt caesar dressing.', 'ar' => 'دجاج مشوي مع صوص سيزر باللبن.'], 'p' => 34, 'img' => 'caesar-2', 'kcal' => 380, 'pro' => 34, 'carb' => 20, 'fat' => 18, 'alg' => [1, 2, 3, 7], 'rate' => 4.4, 'rc' => 24],
                ['n' => ['en' => 'Fig & Pomegranate', 'ar' => 'تين ورمان'], 'd' => ['en' => 'Ripe figs, pomegranate pearls, greens.', 'ar' => 'تين ناضج مع حبوب الرمان والخضار.'], 'p' => 34, 'img' => 'salad-fig', 'kcal' => 320, 'pro' => 6, 'carb' => 42, 'fat' => 14, 'veg' => true, 'gf' => true, 'alg' => [4], 'spec' => true, 'rate' => 4.5, 'rc' => 16],
            ],
        ],
        [
            'n' => ['en' => 'Wraps & Light', 'ar' => 'راب وأطباق خفيفة'], 'img' => 'wrap-halloumi',
            'items' => [
                ['n' => ['en' => 'Halloumi Garden Wrap', 'ar' => 'راب الحلوم'], 'd' => ['en' => 'Seared halloumi, crunch veg, mint yogurt.', 'ar' => 'حلوم مشوي مع خضار ولبن بالنعناع.'], 'p' => 26, 'img' => 'wrap-halloumi', 'kcal' => 460, 'pro' => 18, 'carb' => 42, 'fat' => 24, 'veg' => true, 'alg' => [1, 7], 'pop' => true, 'rate' => 4.5, 'rc' => 29],
                ['n' => ['en' => 'Fresh Veg Rolls', 'ar' => 'لفائف الخضار'], 'd' => ['en' => 'Rainbow rolls, pomegranate dip.', 'ar' => 'لفائف ملونة مع صوص الرمان.'], 'p' => 20, 'img' => 'spring-rolls', 'kcal' => 240, 'pro' => 6, 'carb' => 32, 'fat' => 8, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.3, 'rc' => 15],
                ['n' => ['en' => 'Falafel Plate', 'ar' => 'صحن فلافل'], 'd' => ['en' => 'Baked falafel, salad, tahini pour.', 'ar' => 'فلافل بالفرن مع سلطة وطحينة.'], 'p' => 24, 'img' => 'falafel-plate-real', 'kcal' => 380, 'pro' => 14, 'carb' => 44, 'fat' => 16, 'veg' => true, 'vegan' => true, 'alg' => [7], 'rate' => 4.4, 'rc' => 21],
                ['n' => ['en' => 'Avocado Power Toast', 'ar' => 'توست الأفوكادو'], 'd' => ['en' => 'Seeded sourdough, avocado, soft egg.', 'ar' => 'خبز بالبذور مع أفوكادو وبيض.'], 'p' => 28, 'img' => 'avocado-toast', 'kcal' => 420, 'pro' => 16, 'carb' => 38, 'fat' => 24, 'veg' => true, 'alg' => [2, 4, 7], 'ext' => ['egg', 'avocado'], 'rate' => 4.5, 'rc' => 23],
            ],
        ],
        [
            'n' => ['en' => 'Soups', 'ar' => 'شوربات'], 'img' => 'pumpkin-soup',
            'items' => [
                ['n' => ['en' => 'Pumpkin Velvet', 'ar' => 'شوربة اليقطين'], 'd' => ['en' => 'Roast pumpkin, toasted seeds.', 'ar' => 'يقطين محمص مع بذور محمصة.'], 'p' => 20, 'img' => 'pumpkin-soup', 'kcal' => 240, 'pro' => 5, 'carb' => 30, 'fat' => 12, 'veg' => true, 'gf' => true, 'alg' => [1], 'rate' => 4.4, 'rc' => 17],
                ['n' => ['en' => 'Green Detox', 'ar' => 'ديتوكس أخضر'], 'd' => ['en' => 'Broccoli, spinach, coconut cream.', 'ar' => 'بروكلي وسبانخ مع كريمة جوز الهند.'], 'p' => 20, 'img' => 'soup-green', 'kcal' => 220, 'pro' => 7, 'carb' => 22, 'fat' => 14, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.2, 'rc' => 12],
                ['n' => ['en' => 'Lentil Lemon', 'ar' => 'عدس بالليمون'], 'd' => ['en' => 'Warm lentils, lemon, cumin.', 'ar' => 'عدس دافئ مع الليمون والكمون.'], 'p' => 18, 'img' => 'soup-lentil', 'kcal' => 260, 'pro' => 12, 'carb' => 38, 'fat' => 6, 'veg' => true, 'vegan' => true, 'alg' => [], 'rate' => 4.3, 'rc' => 14],
            ],
        ],
        [
            'n' => ['en' => 'Cold-Pressed & Sweet', 'ar' => 'عصائر وحلويات خفيفة'], 'img' => 'juice-green',
            'items' => [
                ['n' => ['en' => 'Green Machine', 'ar' => 'الآلة الخضراء'], 'd' => ['en' => 'Kiwi, apple, spinach, ginger kick.', 'ar' => 'كيوي وتفاح وسبانخ وزنجبيل.'], 'p' => 19, 'img' => 'juice-green', 'kcal' => 140, 'pro' => 2, 'carb' => 32, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'pop' => true, 'rate' => 4.5, 'rc' => 33],
                ['n' => ['en' => 'Sunrise Orange', 'ar' => 'برتقال الشروق'], 'd' => ['en' => 'Cold-pressed orange, nothing else.', 'ar' => 'برتقال معصور على البارد فقط.'], 'p' => 16, 'img' => 'juice-orange', 'kcal' => 120, 'pro' => 2, 'carb' => 28, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.4, 'rc' => 25],
                ['n' => ['en' => 'Watermelon Cooler', 'ar' => 'مبرد البطيخ'], 'd' => ['en' => 'Fresh watermelon, lime.', 'ar' => 'بطيخ طازج مع الليمون.'], 'p' => 17, 'img' => 'drink-watermelon', 'kcal' => 110, 'pro' => 1, 'carb' => 26, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.3, 'rc' => 18],
                ['n' => ['en' => 'Mint Lemonade', 'ar' => 'ليمون بالنعناع'], 'd' => ['en' => 'No added sugar option.', 'ar' => 'خيار بدون سكر مضاف.'], 'p' => 15, 'img' => 'mint-lemonade', 'kcal' => 90, 'pro' => 0, 'carb' => 22, 'fat' => 0, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.4, 'rc' => 20],
                ['n' => ['en' => 'Seasonal Fruit Cup', 'ar' => 'كوب فواكه'], 'd' => ['en' => 'Carved fruit, mint.', 'ar' => 'فواكه مقطعة مع النعناع.'], 'p' => 18, 'img' => 'fruit-platter', 'kcal' => 140, 'pro' => 2, 'carb' => 34, 'fat' => 1, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.3, 'rc' => 15],
                ['n' => ['en' => 'Fig Plate', 'ar' => 'طبق التين'], 'd' => ['en' => 'Fresh figs, berries, rose petals.', 'ar' => 'تين طازج مع توت وبتلات ورد.'], 'p' => 26, 'img' => 'fruit-fig-plate', 'kcal' => 220, 'pro' => 3, 'carb' => 48, 'fat' => 2, 'veg' => true, 'vegan' => true, 'gf' => true, 'alg' => [], 'rate' => 4.2, 'rc' => 11],
                ['n' => ['en' => 'Protein Panna Cotta', 'ar' => 'بانا كوتا البروتين'], 'd' => ['en' => 'High-protein set cream, strawberries.', 'ar' => 'كريمة عالية البروتين مع الفراولة.'], 'p' => 20, 'img' => 'pannacotta', 'kcal' => 280, 'pro' => 18, 'carb' => 26, 'fat' => 12, 'veg' => true, 'gf' => true, 'alg' => [1], 'rate' => 4.3, 'rc' => 13],
            ],
        ],
    ],
    'reviews' => [
        ['name' => 'Yara', 'stars' => 5, 'body' => ['en' => 'Macros printed on everything — a lifter\'s dream.', 'ar' => 'الماكروز مطبوعة على كل شيء — حلم الرياضيين.']],
        ['name' => 'Daniel', 'stars' => 5, 'body' => ['en' => 'Poke bowl fresh enough to hear the ocean.', 'ar' => 'البوكي طازج لدرجة تسمع معها البحر.']],
        ['name' => 'Shahad', 'stars' => 4, 'body' => ['en' => 'Build-your-bowl never gets boring.', 'ar' => 'ركّب طبقك لا يمل أبداً.']],
        ['name' => 'Karim', 'stars' => 4, 'body' => ['en' => 'Green Machine cured my Sunday.', 'ar' => 'الآلة الخضراء أنقذت يومي.']],
        ['name' => 'Lea', 'stars' => 5, 'body' => ['en' => 'Finally a healthy spot where dressing isn\'t an afterthought.', 'ar' => 'أخيراً مكان صحي لا يهمل الصوص.']],
    ],
];
