<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * SEO Growth W4.6 — Foodics comparison page. Bilingual en + ar (Gulf).
 * Slug: foodics.
 *
 * Cite-check (2026-08-30, foodics.com):
 * - Cloud POS & restaurant-management suite; KSA, UAE, Egypt, Kuwait.
 * - No free plan — enterprise, pricing on request (region pricing pages).
 * - QR ordering / "Pay at Table" (scan to view, split and pay), KDS, waiter
 *   apps, loyalty, accounting; dedicated hardware (Foodics One, customer/kitchen
 *   display screens).
 * - WhatsApp ordering NOT advertised on foodics.com (checked 2026-08-30) → "—".
 * - ZATCA compliance is a well-documented Foodics capability for the Gulf, so
 *   e-invoicing is shown as a both-have (not a claim against them).
 * Honest framing: Foodics is an enterprise POS; we are a free QR-menu/WhatsApp-
 * ordering platform with a free plan (POS from Starter, ZATCA from Pro).
 */

return [
    'name' => 'Foodics',

    'en' => [
        'eyebrow' => 'Comparison',
        'title' => 'Foodics Alternative for Restaurants (2026)',
        'meta_title' => 'Foodics Alternative: QR Menu & WhatsApp Ordering (Free Plan)',
        'meta' => 'Looking for a Foodics alternative? Start free with a QR menu and WhatsApp ordering; POS from Starter (SAR 49), ZATCA e-invoicing from Pro (SAR 99), 0% commission. Compare features and prices side by side.',
        'sub' => 'Foodics is a premium enterprise POS for the Gulf; this is a QR-menu and WhatsApp-ordering platform with a free plan, POS from Starter (SAR 49) and ZATCA e-invoicing from Pro (SAR 99). Here is where each fits.',
        'intro' => [
            'Foodics is a mature cloud POS and restaurant-management suite used across Saudi Arabia, the UAE, Egypt and Kuwait, with deep POS, kitchen screens, waiter apps, inventory and dedicated hardware. It is enterprise software with pricing on request and no free plan.',
            'If what you want is a self-serve QR menu with WhatsApp ordering that starts free and issues ZATCA-compliant e-invoices from the Pro plan (SAR 99), that is a lighter, different fit. This page compares the two honestly.',
        ],
        'groups' => [
            [
                'group' => 'Pricing',
                'rows' => [
                    ['label' => 'Free plan', 'us' => true, 'them' => false],
                    ['label' => 'Pricing model', 'us' => 'Free plan + flat subscription', 'them' => 'Enterprise — pricing on request'],
                    ['label' => 'Commission per order', 'us' => 'None', 'them' => 'None'],
                ],
            ],
            [
                'group' => 'Features',
                'rows' => [
                    ['label' => 'QR menu & pay at table', 'us' => true, 'them' => true],
                    ['label' => 'WhatsApp ordering', 'us' => true, 'them' => false],
                    ['label' => 'ZATCA e-invoicing', 'us' => 'From Pro (SAR 99)', 'them' => true],
                    ['label' => 'Full enterprise POS + KDS + waiter app', 'us' => 'POS from Starter (SAR 49)', 'them' => true],
                    ['label' => 'Dedicated hardware', 'us' => 'No — any phone', 'them' => true],
                ],
            ],
        ],
        'better_fit' => [
            'title' => 'When Foodics is the better fit',
            'points' => [
                'You run a larger Gulf operation needing a full enterprise POS: deep inventory, kitchen screens, waiter apps and dedicated hardware.',
                'You want a single vendor for POS, accounting and hardware across multiple branches.',
            ],
        ],
        'verdict_title' => 'The verdict',
        'verdict' => 'For a full enterprise POS-RMS with hardware, Foodics is a strong Gulf choice. If you want a ZATCA-ready QR menu with WhatsApp ordering and no hardware to buy, the alternative is the better starting point: start free, then POS from Starter (SAR 49) and ZATCA from Pro (SAR 99) on one flat subscription.',
        'faq_title' => 'Foodics comparison FAQ',
        'faqs' => [
            ['q' => 'Does Foodics have a free plan?', 'a' => 'No — Foodics is enterprise software with pricing on request; there is no free plan. The alternative offers a free plan.'],
            ['q' => 'Are both ZATCA-ready?', 'a' => 'Yes — both handle ZATCA e-invoicing for Saudi Arabia; on the alternative it comes from the Pro plan (SAR 99), and it also covers JoFotara (Jordan) and ETA (Egypt).'],
            ['q' => 'Does Foodics offer WhatsApp ordering?', 'a' => 'WhatsApp ordering is not advertised on foodics.com; the alternative takes orders straight to WhatsApp from the table.'],
        ],
    ],

    'ar' => [
        'eyebrow' => 'مقارنة',
        'title' => 'بديل Foodics للمطاعم (2026)',
        'meta_title' => 'بديل Foodics — منيو باركود وطلبات واتساب بدون عمولة (خطة مجانية)',
        'meta' => 'تبحث عن بديل لـ Foodics؟ ابدأ مجاناً بمنيو الكتروني بباركود وطلبات واتساب؛ الكاشير من خطة Starter (49 ريال) والفاتورة الإلكترونية لزاتكا من خطة Pro (99 ريال) — بدون عمولة. قارن المزايا والأسعار.',
        'sub' => 'Foodics نظام نقاط بيع مؤسسي متكامل للخليج؛ وهذا بديل لمنيو باركود وطلبات واتساب بخطة مجانية، والكاشير من خطة Starter (49 ريال) والفاتورة الإلكترونية لزاتكا من خطة Pro (99 ريال). إليك أين يناسب كلٌّ منهما.',
        'intro' => [
            'Foodics نظام نقاط بيع وإدارة مطاعم سحابي ناضج، مستخدم في السعودية والإمارات ومصر والكويت، مع نقاط بيع عميقة وشاشات مطبخ وتطبيق نادل ومخزون وأجهزة مخصصة. هو برنامج مؤسسي بأسعار عند الطلب وبدون خطة مجانية.',
            'إذا كان ما تريده منيو باركود مع طلبات واتساب يبدأ مجاناً ويصدر فاتورة إلكترونية متوافقة مع زاتكا من خطة Pro (99 ريال)، فهذا خيار أخف ومختلف. هذه الصفحة تقارن الجانبين بصدق.',
        ],
        'groups' => [
            [
                'group' => 'الأسعار',
                'rows' => [
                    ['label' => 'خطة مجانية', 'us' => true, 'them' => false],
                    ['label' => 'نموذج التسعير', 'us' => 'خطة مجانية + اشتراك ثابت', 'them' => 'مؤسسي — السعر عند الطلب'],
                    ['label' => 'عمولة على الطلب', 'us' => 'لا يوجد', 'them' => 'لا يوجد'],
                ],
            ],
            [
                'group' => 'المزايا',
                'rows' => [
                    ['label' => 'منيو باركود والدفع من الطاولة', 'us' => true, 'them' => true],
                    ['label' => 'طلبات واتساب', 'us' => true, 'them' => false],
                    ['label' => 'فاتورة إلكترونية (زاتكا)', 'us' => 'من خطة Pro (99 ريال)', 'them' => true],
                    ['label' => 'نقاط بيع مؤسسية + شاشات مطبخ + تطبيق نادل', 'us' => 'نقاط بيع من خطة Starter (49 ريال)', 'them' => true],
                    ['label' => 'أجهزة مخصصة', 'us' => 'لا — أي هاتف', 'them' => true],
                ],
            ],
        ],
        'better_fit' => [
            'title' => 'متى يكون Foodics هو الأنسب',
            'points' => [
                'تدير عملية كبيرة في الخليج وتحتاج نظام نقاط بيع مؤسسياً كاملاً: مخزون عميق، شاشات مطبخ، تطبيق نادل، وأجهزة مخصصة.',
                'تريد مورّداً واحداً لنقاط البيع والمحاسبة والأجهزة عبر عدة فروع.',
            ],
        ],
        'verdict_title' => 'الخلاصة',
        'verdict' => 'لنظام نقاط بيع مؤسسي كامل مع الأجهزة، Foodics خيار خليجي قوي. أما إذا أردت منيو باركود متوافقاً مع زاتكا مع طلبات واتساب وبدون شراء أجهزة، فالبديل هو نقطة البداية الأفضل: ابدأ مجاناً، ثم نقاط البيع من خطة Starter (49 ريال) وزاتكا من خطة Pro (99 ريال) باشتراك ثابت واحد.',
        'faq_title' => 'أسئلة شائعة عن مقارنة Foodics',
        'faqs' => [
            ['q' => 'هل لدى Foodics خطة مجانية؟', 'a' => 'لا — Foodics برنامج مؤسسي بأسعار عند الطلب، وبدون خطة مجانية. البديل يوفّر خطة مجانية.'],
            ['q' => 'هل كلاهما متوافق مع زاتكا؟', 'a' => 'نعم — كلاهما يتولى الفاتورة الإلكترونية لزاتكا في السعودية؛ والبديل يوفرها من خطة Pro (99 ريال) ويغطي أيضاً جوفوترة (الأردن) وETA (مصر).'],
            ['q' => 'هل يوفّر Foodics طلبات واتساب؟', 'a' => 'طلبات واتساب غير معلنة على موقع Foodics؛ بينما يتيح البديل استقبال الطلب مباشرة على واتساب من الطاولة.'],
        ],
    ],
];
