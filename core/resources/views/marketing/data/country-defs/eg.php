<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * SEO Growth W3.5 — Egypt country landing page (cc: eg). AR-first (EG dialect:
 * برنامج/تحميل كاشير, prices in جنيه) + EN. Compliance hook: ETA e-receipt
 * (الإيصال الإلكتروني). Confirm scope/dates with the Egyptian Tax Authority.
 */

return [
    '_default' => 'ar',

    'ar' => [
        'region_name' => 'مصر',
        'name' => 'مصر',
        'eyebrow' => 'منيو الكتروني',
        'title' => 'منيو الكتروني مصر — الإيصال الإلكتروني ETA',
        'meta' => 'منيو الكتروني QR للمطاعم والكافيهات في مصر: ابدأ مجاناً بمنيو باركود وطلبات واتساب؛ وبرنامج الكاشير والإيصال الإلكتروني المتوافق مع مصلحة الضرائب ETA في الخطط المدفوعة — بدون عمولة.',
        'sub' => 'منيو باركود وبرنامج كاشير للمطاعم والمتاجر، طلبات مباشرة على واتساب، وإيصال إلكتروني متوافق مع منظومة ETA — بأسعار بالجنيه ودون عمولة على طلباتك.',
        'intro' => [
            'حوّل مطعمك أو متجرك في القاهرة أو الإسكندرية أو الجيزة إلى منيو الكتروني وبرنامج كاشير يشتغل من موبايل الزبون: يمسح باركود QR، يتصفّح المنيو، ويبعت الطلب على واتساب.',
            'ومع تحوّل مصر للإيصال الإلكتروني، نظامك يصدر الإيصالات جاهزة للامتثال مع منظومة مصلحة الضرائب المصرية (ETA).',
        ],
        'cta_primary' => 'ابدأ مجاناً',
        'cta_demo' => 'شاهد منيو مباشراً',
        'demo_slug' => 'vivid-demo',
        'gateways_title' => 'وسائل الدفع',
        'gateways' => ['فوري', 'محفظة موبايل', 'بطاقة', 'InstaPay', 'الدفع عند الاستلام'],
        'compliance' => [
            'title' => 'الإيصال الإلكتروني (ETA)',
            'body' => 'الإيصال الإلكتروني منظومة من مصلحة الضرائب المصرية لإصدار إيصالات البيع للمستهلك إلكترونياً. نظامك يصدر الإيصالات بصيغة جاهزة للامتثال.',
            'points' => [
                'إيصالات إلكترونية جاهزة لمنظومة ETA.',
                'برنامج كاشير كامل مع منيو باركود في مكان واحد.',
                'راجع مصلحة الضرائب المصرية لتأكيد نطاق الإلزام وموعده.',
            ],
        ],
        'pain_title' => 'بدون عمولة على طلباتك',
        'pain' => 'تطبيقات التوصيل بتاخد نسبة من كل أوردر. مع قناتك الخاصة تحتفظ بـ 100% مقابل اشتراك ثابت.',
        'prices' => [
            'title' => 'ابدأ اليوم',
            'items' => ['خطة مجانية للبدء من غير بطاقة.', 'ترقية اختيارية لما مطعمك يكبر.'],
        ],
        'faq_title' => 'أسئلة شائعة — المنيو الإلكتروني في مصر',
        'faqs' => [
            ['q' => 'إزاي أعمل منيو الكتروني وبرنامج كاشير لمطعمي أو متجري في مصر؟', 'a' => 'اعمل حساب مجاني، ضيف أصنافك وأسعارك بالجنيه، هتحصل على باركود QR ورابط يفتح المنيو على موبايل الزبون في دقائق.'],
            ['q' => 'هل الإيصال متوافق مع الإيصال الإلكتروني ETA؟', 'a' => 'نعم — النظام يصدر إيصالات جاهزة للامتثال مع منظومة مصلحة الضرائب المصرية. راجع المصلحة لتأكيد نطاق إلزامك.'],
            ['q' => 'هل في عمولة على الأوردرات؟', 'a' => 'لا — اشتراك ثابت وتحتفظ بكامل قيمة كل أوردر.'],
        ],
    ],

    'en' => [
        'region_name' => 'Egypt',
        'name' => 'Egypt',
        'eyebrow' => 'QR menu',
        'title' => 'QR Menu Egypt — ETA E-Receipt Ready',
        'meta' => 'QR code menu and cashier for restaurants and cafés in Egypt: start free with the QR menu and WhatsApp orders; the cashier and ETA-ready e-receipts are on paid plans — with no commission on your orders.',
        'sub' => 'A QR menu and cashier for restaurants and shops, WhatsApp orders, and e-receipts ready for Egypt’s ETA system — priced in EGP, with no commission.',
        'intro' => [
            'Turn your restaurant or shop in Cairo, Alexandria or Giza into a digital menu and cashier that runs from the customer’s phone — and, as Egypt moves to e-receipts, issues compliance-ready receipts for the ETA.',
        ],
        'cta_primary' => 'Start free',
        'cta_demo' => 'See a live menu',
        'demo_slug' => 'vivid-demo',
        'gateways_title' => 'Payments',
        'gateways' => ['Fawry', 'Mobile wallet', 'Card', 'InstaPay', 'Cash on delivery'],
        'compliance' => [
            'title' => 'ETA e-receipt ready',
            'body' => 'The e-receipt is the Egyptian Tax Authority system for issuing consumer receipts electronically. Your system issues compliance-ready receipts.',
            'points' => ['E-receipts ready for the ETA system', 'Full cashier + QR menu in one place', 'Confirm scope and dates with the Egyptian Tax Authority'],
        ],
        'pain_title' => 'No commission on your orders',
        'pain' => 'Delivery apps take a cut of every order. With your own channel you keep 100% for a flat subscription.',
        'prices' => [
            'title' => 'Start today',
            'items' => ['Free plan, no card', 'Upgrade only when you grow'],
        ],
        'faq_title' => 'Egypt restaurant QR menu FAQ',
        'faqs' => [
            ['q' => 'How do I make a QR menu and cashier for my restaurant or shop in Egypt?', 'a' => 'Create a free account, add your items and prices in EGP, and you get a QR code and link that opens your menu on the customer’s phone in minutes.'],
            ['q' => 'Is the receipt ETA compliant?', 'a' => 'Yes — the system issues compliance-ready receipts for the Egyptian Tax Authority e-receipt system. Confirm your scope with the ETA.'],
            ['q' => 'Is there commission on orders?', 'a' => 'No — a flat subscription, and you keep the full value of every order.'],
        ],
    ],
];
