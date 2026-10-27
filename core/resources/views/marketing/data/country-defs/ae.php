<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * SEO Growth W3.4 — UAE / Dubai country landing page (cc: ae). EN-first + AR.
 * Angle: keep 100%, no Talabat commission. VAT + the UAE's phased e-invoicing
 * programme as the compliance note (confirm dates with the FTA).
 */

return [
    '_default' => 'en',

    'en' => [
        'region_name' => 'UAE',
        'name' => 'United Arab Emirates',
        'eyebrow' => 'QR menu',
        'title' => 'QR Menu Dubai & UAE — Keep 100%, No Talabat Commission',
        'meta' => 'Free QR code menu for restaurants and cafés in Dubai and the UAE: WhatsApp and QR ordering with zero commission — keep 100% of every order instead of paying Talabat.',
        'sub' => 'Take orders on your own QR, link and storefront across Dubai and the UAE — keep 100% of every order instead of handing a cut to Talabat.',
        'intro' => [
            'Turn your restaurant, shop or café in Dubai, Abu Dhabi or Sharjah into a digital menu that runs from the customer’s phone: scan a QR, order, and it lands on WhatsApp — no aggregator commission.',
            'Show VAT correctly and issue tidy receipts, ready for the UAE’s move to e-invoicing.',
        ],
        'cta_primary' => 'Start free',
        'cta_demo' => 'See a live menu',
        'demo_slug' => 'crave-demo',
        'gateways_title' => 'Payments',
        'gateways' => ['Card', 'Apple Pay', 'Google Pay', 'Tabby', 'Tamara', 'Cash on delivery'],
        'compliance' => [
            'title' => 'VAT-ready, e-invoicing on the way',
            'body' => 'Show 5% VAT correctly on every order and hand customers a clean receipt. The UAE is rolling out e-invoicing in phases — your system is built to keep up.',
            'points' => [
                'Correct 5% VAT display and tidy receipts.',
                'Ready for the UAE’s phased e-invoicing programme.',
                'Confirm timelines with the Federal Tax Authority (FTA).',
            ],
        ],
        'pain_title' => 'No Talabat commission',
        'pain' => 'Aggregators like Talabat take up to ~30% of each order. On your own channel you keep 100% for a flat subscription — busy nights stay yours.',
        'prices' => [
            'title' => 'Start today',
            'items' => ['Free plan, no card', 'Upgrade only when you grow'],
        ],
        'faq_title' => 'Dubai & UAE restaurant QR menu FAQ',
        'faqs' => [
            ['q' => 'How do I create a QR menu for my restaurant or shop in Dubai?', 'a' => 'Create a free account, add your items and prices, and you get a QR code and link that opens your menu on the customer’s phone in minutes.'],
            ['q' => 'How is this different from Talabat?', 'a' => 'Talabat is an aggregator that charges commission on each order. This is your own ordering channel — QR, WhatsApp and your storefront — with no commission, so you keep 100%.'],
            ['q' => 'Does it handle UAE VAT?', 'a' => 'Yes — show 5% VAT correctly and issue clean receipts, ready for the UAE’s move to e-invoicing.'],
        ],
    ],

    'ar' => [
        'region_name' => 'الإمارات',
        'name' => 'الإمارات',
        'eyebrow' => 'منيو الكتروني',
        'title' => 'منيو الكتروني دبي والإمارات — احتفظ بـ 100% وبدون عمولة طلبات',
        'meta' => 'منيو الكتروني QR للمطاعم والكافيهات في دبي والإمارات: طلبات QR وواتساب بدون عمولة — احتفظ بـ 100% من كل طلب بدل دفع عمولة طلبات.',
        'sub' => 'استقبل الطلبات على قناتك الخاصة (QR ورابط ومتجرك) في دبي والإمارات — واحتفظ بـ 100% بدل اقتطاع حصة لطلبات.',
        'intro' => [
            'حوّل مطعمك أو متجرك في دبي أو أبوظبي أو الشارقة إلى منيو الكتروني يعمل من هاتف الزبون: مسح QR، طلب، ويصلك على واتساب — دون عمولة منصّات.',
        ],
        'cta_primary' => 'ابدأ مجاناً',
        'cta_demo' => 'شاهد منيو مباشراً',
        'demo_slug' => 'crave-demo',
        'gateways_title' => 'وسائل الدفع',
        'gateways' => ['بطاقة', 'Apple Pay', 'Google Pay', 'Tabby', 'Tamara', 'الدفع عند الاستلام'],
        'compliance' => [
            'title' => 'جاهز لضريبة القيمة المضافة والفوترة الإلكترونية',
            'body' => 'اعرض ضريبة القيمة المضافة 5% بشكل صحيح على كل طلب، وسلّم الزبون إيصالاً واضحاً، مع الاستعداد لتطبيق الفوترة الإلكترونية في الإمارات.',
            'points' => ['عرض صحيح لضريبة 5% وإيصالات مرتبة.', 'جاهز لبرنامج الفوترة الإلكترونية المرحلي.', 'راجع الهيئة الاتحادية للضرائب لتأكيد المواعيد.'],
        ],
        'pain_title' => 'بدون عمولة طلبات',
        'pain' => 'تأخذ المنصّات مثل طلبات حتى نحو 30% من كل طلب. على قناتك الخاصة تحتفظ بـ 100% مقابل اشتراك ثابت.',
        'prices' => [
            'title' => 'ابدأ اليوم',
            'items' => ['خطة مجانية دون بطاقة.', 'ترقية اختيارية مع النمو.'],
        ],
        'faq_title' => 'أسئلة شائعة — المنيو الإلكتروني في دبي والإمارات',
        'faqs' => [
            ['q' => 'كيف أنشئ منيو الكتروني لمطعمي أو متجري في دبي؟', 'a' => 'أنشئ حساباً مجاناً، أضف أصنافك وأسعارك، فتحصل على رمز QR ورابط يفتح قائمتك على هاتف الزبون خلال دقائق.'],
            ['q' => 'ما الفرق عن طلبات؟', 'a' => 'طلبات منصّة وسيطة تأخذ عمولة على كل طلب. هذه قناتك الخاصة دون عمولة، فتحتفظ بـ 100%.'],
        ],
    ],
];
