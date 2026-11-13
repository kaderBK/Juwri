<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * SEO Growth W4.2 — MenuTiger comparison page. Bilingual (en + ar).
 * Slug: menutiger. Title per KEYWORDS §Compare.
 *
 * Cite-check (2026-08-30, menutiger.com + help center):
 * - Free plan ("Start free, upgrade from $14/month billed annually").
 * - "Keep 100% of every order, zero commission fees" (no commission any plan).
 * - Built-in POS ("Menu Tiger POS", Loyverse integration) + multilingual.
 * - Kitchen display on the Advanced tier ($46/mo); white-label / custom domain
 *   on Premium ($119/mo).
 * - WhatsApp ordering and e-invoicing / ZATCA tax compliance are NOT advertised
 *   anywhere on menutiger.com (checked 2026-08-30) — hence the "—" cells.
 */

return [
    'name' => 'MenuTiger',

    'en' => [
        'eyebrow' => 'Comparison',
        'title' => 'MenuTiger Alternative for Restaurants (2026)',
        'meta_title' => 'Free MenuTiger Alternative: QR Menu, No Commission',
        'meta' => 'MenuTiger vs a commission-free alternative with a free plan: compare QR menus, WhatsApp ordering, and the POS and e-invoicing on paid plans, to pick the right fit for your restaurant or business.',
        'sub' => 'Both are commission-free QR-menu platforms with a free tier. Here is an honest, feature-by-feature comparison so you can pick the right fit.',
        'intro' => [
            'MenuTiger is a well-designed QR-menu and self-ordering platform with a built-in POS, a free tier and zero commission. It is a strong choice if a clean digital menu with in-app payments is mostly what you need.',
            'Where the two differ is the wider stack: WhatsApp ordering and regional e-invoicing. This page lays out both sides honestly.',
        ],
        'groups' => [
            [
                'group' => 'Pricing',
                'rows' => [
                    ['label' => 'Free plan', 'us' => true, 'them' => true],
                    ['label' => 'Commission per order', 'us' => 'None', 'them' => 'None'],
                    ['label' => 'Kitchen display', 'us' => 'Included', 'them' => 'Advanced plan ($46/mo)'],
                    ['label' => 'White-label & custom domain', 'us' => 'Included', 'them' => 'Premium ($119/mo)'],
                ],
            ],
            [
                'group' => 'Features',
                'rows' => [
                    ['label' => 'QR code menu', 'us' => true, 'them' => true],
                    ['label' => 'Multi-language menu', 'us' => 'Included', 'them' => 'Included'],
                    ['label' => 'Built-in POS', 'us' => true, 'them' => true],
                    ['label' => 'WhatsApp ordering', 'us' => true, 'them' => false],
                    ['label' => 'E-invoicing (ZATCA / JoFotara / ETA)', 'us' => true, 'them' => false],
                ],
            ],
        ],
        'better_fit' => [
            'title' => 'When MenuTiger is the better fit',
            'points' => [
                'You mainly want a polished QR menu with in-app payments and a built-in POS.',
                'You do not need WhatsApp ordering or regional e-invoicing (ZATCA/JoFotara/ETA).',
            ],
        ],
        'verdict_title' => 'The verdict',
        'verdict' => 'For a clean, commission-free QR menu with payments and a POS, MenuTiger is a solid pick. If you also want WhatsApp ordering and built-in e-invoicing for the Gulf and MENA, the alternative covers more of the stack on one flat subscription.',
        'faq_title' => 'MenuTiger comparison FAQ',
        'faqs' => [
            ['q' => 'Is MenuTiger really commission-free?', 'a' => 'Yes — MenuTiger advertises “keep 100% of every order, zero commission fees”. The alternative is also commission-free on a flat subscription.'],
            ['q' => 'Does MenuTiger support WhatsApp ordering?', 'a' => 'WhatsApp ordering is not advertised on MenuTiger’s site; the alternative offers ordering straight to WhatsApp from the table.'],
            ['q' => 'Which one handles e-invoicing?', 'a' => 'MenuTiger does not advertise tax e-invoicing; the alternative includes compliant e-invoicing with live drivers for ZATCA (Saudi Arabia), JoFotara (Jordan) and ETA (Egypt).'],
        ],
    ],

    'ar' => [
        'eyebrow' => 'مقارنة',
        'title' => 'بديل MenuTiger للمطاعم (2026)',
        'meta_title' => 'بديل MenuTiger مجاني — منيو باركود بدون عمولة',
        'meta' => 'مقارنة MenuTiger ببديل بخطة مجانية وبدون عمولة: منيو باركود، طلبات واتساب، ونقاط البيع والفاتورة الإلكترونية في الخطط المدفوعة — لتختار الأنسب لمطعمك أو نشاطك التجاري.',
        'sub' => 'كلاهما منصة منيو باركود بدون عمولة وبخطة مجانية. إليك مقارنة صادقة ميزةً بميزة لتختار الأنسب.',
        'intro' => [
            'MenuTiger منصة منيو باركود وطلب ذاتي جيدة التصميم، مع نقاط بيع مدمجة وخطة مجانية وبدون عمولة. خيار قوي إذا كان ما تحتاجه أساساً منيو رقمي أنيق مع دفع داخل التطبيق.',
            'يظهر الفرق في بقية المنظومة: طلبات واتساب والفاتورة الإلكترونية الإقليمية. هذه الصفحة تعرض الجانبين بصدق.',
        ],
        'groups' => [
            [
                'group' => 'الأسعار',
                'rows' => [
                    ['label' => 'خطة مجانية', 'us' => true, 'them' => true],
                    ['label' => 'عمولة على الطلب', 'us' => 'لا يوجد', 'them' => 'لا يوجد'],
                    ['label' => 'شاشة المطبخ', 'us' => 'مشمولة', 'them' => 'خطة Advanced ($46/شهر)'],
                    ['label' => 'علامة بيضاء ونطاق مخصص', 'us' => 'مشمولة', 'them' => 'خطة Premium ($119/شهر)'],
                ],
            ],
            [
                'group' => 'المزايا',
                'rows' => [
                    ['label' => 'منيو باركود QR', 'us' => true, 'them' => true],
                    ['label' => 'منيو متعدد اللغات', 'us' => 'مشمول', 'them' => 'مشمول'],
                    ['label' => 'نقاط بيع مدمجة', 'us' => true, 'them' => true],
                    ['label' => 'طلبات واتساب', 'us' => true, 'them' => false],
                    ['label' => 'فاتورة إلكترونية (زاتكا / جوفوترة / ETA)', 'us' => true, 'them' => false],
                ],
            ],
        ],
        'better_fit' => [
            'title' => 'متى يكون MenuTiger هو الأنسب',
            'points' => [
                'تريد أساساً منيو باركود أنيقاً مع دفع داخل التطبيق ونقاط بيع مدمجة.',
                'لا تحتاج طلبات واتساب ولا الفاتورة الإلكترونية الإقليمية (زاتكا/جوفوترة/ETA).',
            ],
        ],
        'verdict_title' => 'الخلاصة',
        'verdict' => 'لمنيو باركود نظيف بدون عمولة مع الدفع ونقاط البيع، MenuTiger خيار قوي. أما إذا أردت أيضاً طلبات واتساب وفاتورة إلكترونية مدمجة للخليج ومنطقة الشرق الأوسط، فالبديل يغطي جزءاً أكبر من المنظومة باشتراك ثابت واحد.',
        'faq_title' => 'أسئلة شائعة عن مقارنة MenuTiger',
        'faqs' => [
            ['q' => 'هل MenuTiger فعلاً بدون عمولة؟', 'a' => 'نعم — يعلن MenuTiger أنك «تحتفظ بـ100% من كل طلب بدون عمولة». والبديل أيضاً بدون عمولة باشتراك ثابت.'],
            ['q' => 'هل يدعم MenuTiger طلبات واتساب؟', 'a' => 'طلبات واتساب غير معلنة على موقع MenuTiger؛ بينما يتيح البديل إرسال الطلب مباشرة إلى واتساب من الطاولة.'],
            ['q' => 'أيهما يتولى الفاتورة الإلكترونية؟', 'a' => 'لا يعلن MenuTiger عن فاتورة إلكترونية ضريبية؛ بينما يشمل البديل فاتورة إلكترونية متوافقة مع روابط مباشرة لزاتكا (السعودية) وجوفوترة (الأردن) وETA (مصر).'],
        ],
    ],
];
