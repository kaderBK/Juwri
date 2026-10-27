<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * SEO Growth W3.3 — Saudi Arabia country landing page (cc: sa). AR + EN.
 * Compliance hooks: ZATCA (Fatoora) e-invoicing + SFDA calorie display on menus.
 * A full ZATCA guide lives at guides/zatca-einvoicing. Specifics change —
 * copy points readers to ZATCA/SFDA and a tax advisor.
 */

return [
    '_default' => 'ar',

    'ar' => [
        'region_name' => 'السعودية',
        'name' => 'السعودية',
        'eyebrow' => 'منيو الكتروني',
        'title' => 'منيو الكتروني السعودية — متوافق مع زاتكا و SFDA',
        'meta' => 'منيو الكتروني QR للمطاعم والكافيهات في السعودية: ابدأ مجاناً بمنيو باركود وطلبات واتساب بدون عمولة. الفاتورة الإلكترونية المتوافقة مع زاتكا مشمولة في خطتي Pro وChain (ومتاحة كإضافة لخطة Starter)، مع عرض السعرات الحرارية وفق SFDA.',
        'sub' => 'قائمة رقمية بباركود QR دون عمولة على طلباتك، وعرض السعرات الحرارية على الأصناف كما تتطلب الهيئة العامة للغذاء والدواء، وفاتورة إلكترونية متوافقة مع زاتكا مشمولة في خطتي Pro وChain (ومتاحة كإضافة لخطة Starter).',
        'intro' => [
            'حوّل مطعمك أو متجرك في الرياض أو جدة أو الدمام إلى منيو الكتروني يعمل من هاتف الزبون، مع كل ما تحتاجه للامتثال المحلي في مكان واحد.',
            'فاتورة إلكترونية موقّعة متوافقة مع منصة فاتورة (زاتكا) — مشمولة في خطتي Pro وChain (ومتاحة كإضافة لخطة Starter) — ورمز QR على كل إيصال، وحقل للسعرات الحرارية على كل صنف وفق متطلبات SFDA.',
        ],
        'cta_primary' => 'ابدأ مجاناً',
        'cta_demo' => 'شاهد منيو مباشراً',
        'demo_slug' => 'crave-demo',
        'gateways_title' => 'وسائل الدفع',
        'gateways' => ['مدى', 'Apple Pay', 'STC Pay', 'بطاقة', 'الدفع عند الاستلام'],
        'compliance' => [
            'title' => 'متوافق مع زاتكا و SFDA',
            'body' => 'في خطتي Pro وChain (وكإضافة لخطة Starter) يصدر نظامك فواتير إلكترونية موقّعة ومترابطة مع رمز QR للمرحلة الثانية؛ ويعرض السعرات الحرارية على الأصناف كما تتطلب الهيئة العامة للغذاء والدواء.',
            'points' => [
                'فاتورة إلكترونية متوافقة مع مرحلتي زاتكا (فاتورة).',
                'رمز QR على كل إيصال مبسّط.',
                'عرض السعرات الحرارية على كل صنف (SFDA).',
                'راجع بوابة زاتكا لتأكيد موعد دفعتك (Wave).',
            ],
        ],
        'pain_title' => 'بدون عمولة على طلباتك',
        'pain' => 'تطبيقات التوصيل تأخذ حصة من كل طلب. مع قناتك الخاصة (QR وواتساب ومتجرك) تحتفظ بـ 100% مقابل اشتراك ثابت.',
        'prices' => [
            'title' => 'ابدأ اليوم',
            'items' => ['خطة مجانية للبدء دون بطاقة (منيو QR وطلبات أونلاين).', 'نقاط البيع وزاتكا في الخطط المدفوعة.'],
        ],
        'faq_title' => 'أسئلة شائعة — المنيو الإلكتروني في السعودية',
        'faqs' => [
            ['q' => 'كيف اسوي منيو الكتروني لمطعمي أو متجري في السعودية؟', 'a' => 'أنشئ حساباً مجاناً، أضف أصنافك وأسعارك والسعرات، فتحصل على رمز QR ورابط يفتح قائمتك على جوال الزبون خلال دقائق.'],
            ['q' => 'هل المنيو متوافق مع زاتكا؟', 'a' => 'نعم — الفاتورة الإلكترونية لزاتكا مشمولة في خطتي Pro وChain (ومتاحة كإضافة لخطة Starter): يصدر النظام فواتير موقّعة مع رمز QR للمرحلة الثانية ويتكامل مع منصة فاتورة. راجع دليل زاتكا لدينا للتفاصيل.'],
            ['q' => 'هل يعرض المنيو السعرات الحرارية وفق SFDA؟', 'a' => 'نعم، يمكنك عرض السعرات الحرارية على كل صنف كما تتطلب الهيئة العامة للغذاء والدواء.'],
        ],
    ],

    'en' => [
        'region_name' => 'Saudi Arabia',
        'name' => 'Saudi Arabia',
        'eyebrow' => 'QR menu',
        'title' => 'QR Menu Saudi Arabia — ZATCA & SFDA Ready',
        'meta' => 'QR code menu for restaurants and cafés in Saudi Arabia: start free, with WhatsApp orders and no commission. ZATCA-compliant e-invoicing is included on Pro and Chain plans (and available as an add-on for Starter), with SFDA calorie display.',
        'sub' => 'A QR digital menu with no commission on your orders, per-item calorie display as the SFDA requires, and ZATCA-compliant e-invoicing — included on Pro and Chain plans (and available as an add-on for Starter).',
        'intro' => [
            'Turn your restaurant or shop in Riyadh, Jeddah or Dammam into a digital menu that runs from the customer’s phone — with everything you need for local compliance in one place.',
            'Signed e-invoices integrated with ZATCA’s Fatoora platform (included on Pro and Chain plans (and available as an add-on for Starter)), a QR code on every receipt, and a calorie field on every item for SFDA.',
        ],
        'cta_primary' => 'Start free',
        'cta_demo' => 'See a live menu',
        'demo_slug' => 'crave-demo',
        'gateways_title' => 'Payments',
        'gateways' => ['mada', 'Apple Pay', 'STC Pay', 'Card', 'Cash on delivery'],
        'compliance' => [
            'title' => 'ZATCA & SFDA ready',
            'body' => 'On Pro and Chain plans (and as an add-on for Starter) your system issues signed, chained e-invoices with a Phase-2 QR code; it displays calories per item as the SFDA requires.',
            'points' => [
                'ZATCA (Fatoora) Phase 1 & 2 compliant e-invoicing.',
                'QR code on every simplified receipt.',
                'Per-item calorie display (SFDA).',
                'Check the ZATCA portal to confirm your wave.',
            ],
        ],
        'pain_title' => 'No commission on your orders',
        'pain' => 'Delivery apps take a cut of every order. With your own channel — QR, WhatsApp and your storefront — you keep 100% for a flat subscription.',
        'prices' => [
            'title' => 'Start today',
            'items' => ['Free plan, no card (QR menu and online ordering)', 'POS and ZATCA on paid plans'],
        ],
        'faq_title' => 'Saudi Arabia restaurant QR menu FAQ',
        'faqs' => [
            ['q' => 'How do I make a QR menu for my restaurant or shop in Saudi Arabia?', 'a' => 'Create a free account, add your items, prices and calories, and you get a QR code and link that opens your menu on the customer’s phone in minutes.'],
            ['q' => 'Is the menu ZATCA compliant?', 'a' => 'Yes — ZATCA e-invoicing is included on Pro and Chain plans (and available as an add-on for Starter): the system issues signed e-invoices with a Phase-2 QR code and integrates with the Fatoora platform. See our ZATCA guide for details.'],
            ['q' => 'Does it show SFDA calorie information?', 'a' => 'Yes, you can display calories on every item as the SFDA requires.'],
        ],
    ],
];
