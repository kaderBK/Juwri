<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * SEO Growth W3.2 — Jordan country landing page (cc: jo). AR + EN. JoFotara
 * (Jordan's national e-invoicing system) is the compliance hook; the quotable
 * detail is the small-business turnover exemption. Regulatory specifics change
 * — copy tells readers to confirm with the ISTD / a tax advisor.
 */

return [
    '_default' => 'ar',

    'ar' => [
        'region_name' => 'الأردن',
        'name' => 'الأردن',
        'eyebrow' => 'منيو الكتروني',
        'title' => 'منيو الكتروني في الأردن — متوافق مع جوفوترة',
        'meta' => 'منيو الكتروني QR للمطاعم والكافيهات في الأردن: ابدأ مجاناً بمنيو باركود وطلبات واتساب؛ والفوترة المتوافقة مع جوفوترة في الخطط المدفوعة — بدون عمولة على طلباتك.',
        'sub' => 'قائمة رقمية بباركود QR، طلبات مباشرة على واتساب، وفواتير جاهزة لمنظومة جوفوترة الوطنية — دون عمولة.',
        'intro' => [
            'حوّل مطعمك أو متجرك في عمّان أو إربد أو الزرقاء إلى منيو الكتروني يعمل من هاتف الزبون: مسح رمز QR، تصفّح القائمة، وإرسال الطلب عبر واتساب.',
            'ومع تحوّل الأردن إلى الفوترة الإلكترونية عبر منظومة جوفوترة، يصدر نظامك فواتير جاهزة للامتثال تلقائياً.',
        ],
        'cta_primary' => 'ابدأ مجاناً',
        'cta_demo' => 'شاهد منيو مباشراً',
        'demo_slug' => 'velora-demo',
        'gateways_title' => 'وسائل الدفع',
        'gateways' => ['بطاقة', 'محفظة إلكترونية', 'CliQ', 'الدفع عند الاستلام'],
        'compliance' => [
            'title' => 'متوافق مع جوفوترة (الفوترة الوطنية)',
            'body' => 'جوفوترة هي المنظومة الوطنية للفوترة الإلكترونية في الأردن. يصدر نظامك الفواتير بصيغة جاهزة للامتثال، مع رمز QR على الإيصال.',
            'points' => [
                'المطاعم الصغيرة ذات الإيرادات دون 75,000 دينار قد تكون خارج نطاق الإلزام — تحقق من هيئة الضرائب.',
                'فواتير موقّعة ومتسلسلة تلقائياً لتسهيل التدقيق.',
                'راجع دائرة ضريبة الدخل والمبيعات لتأكيد موعد إلزامك.',
            ],
        ],
        'pain_title' => 'بدون عمولة على طلباتك',
        'pain' => 'تطبيقات التوصيل تقتطع حصة من كل طلب. مع قناتك الخاصة تحتفظ بـ 100% مقابل اشتراك ثابت.',
        'prices' => [
            'title' => 'ابدأ اليوم',
            'items' => ['خطة مجانية للبدء دون بطاقة.', 'ترقية اختيارية مع نمو مطعمك.'],
        ],
        'faq_title' => 'أسئلة شائعة — المنيو الإلكتروني في الأردن',
        'faqs' => [
            ['q' => 'هل يحتاج المطعم في الأردن إلى فاتورة متوافقة مع جوفوترة؟', 'a' => 'المنشآت ضمن نطاق الإلزام تصدر فواتيرها عبر منظومة جوفوترة. المطاعم الصغيرة دون حد الإيرادات المحدد قد تكون مستثناة — تحقق من هيئة الضرائب أو مستشارك.'],
            ['q' => 'كيف أعمل منيو الكتروني بباركود في الأردن؟', 'a' => 'أنشئ حساباً مجاناً، أضف أصنافك، فتحصل على رمز QR ورابط يفتح قائمتك على هاتف الزبون خلال دقائق.'],
            ['q' => 'هل هناك عمولة على الطلبات؟', 'a' => 'لا — اشتراك ثابت وتحتفظ بكامل قيمة كل طلب.'],
        ],
    ],

    'en' => [
        'region_name' => 'Jordan',
        'name' => 'Jordan',
        'eyebrow' => 'QR menu',
        'title' => 'QR Menu for Restaurants in Jordan — JoFotara Ready',
        'meta' => 'QR code menu for restaurants and cafés in Jordan: start free with WhatsApp orders; JoFotara-ready e-invoicing is on paid plans — with no commission on your orders.',
        'sub' => 'A QR digital menu, WhatsApp orders, and invoices ready for Jordan’s national JoFotara system — with no commission.',
        'intro' => [
            'Turn your restaurant or shop in Amman, Irbid or Zarqa into a digital menu that runs from the customer’s phone — and, as Jordan moves to e-invoicing via JoFotara, issues compliance-ready invoices automatically.',
        ],
        'cta_primary' => 'Start free',
        'cta_demo' => 'See a live menu',
        'demo_slug' => 'velora-demo',
        'gateways_title' => 'Payments',
        'gateways' => ['Card', 'e-wallet', 'CliQ', 'Cash on delivery'],
        'compliance' => [
            'title' => 'JoFotara-ready invoicing',
            'body' => 'JoFotara is Jordan’s national e-invoicing system. Your system issues compliance-ready invoices with a QR code on the receipt.',
            'points' => [
                'Small restaurants and other businesses under the JOD 75,000 turnover threshold may be out of scope — confirm with the ISTD.',
                'Signed, chained invoices generated automatically.',
                'Check the Income and Sales Tax Department for your enforcement date.',
            ],
        ],
        'pain_title' => 'No commission on your orders',
        'pain' => 'Delivery apps take a cut of every order. With your own channel you keep 100% for a flat subscription.',
        'prices' => [
            'title' => 'Start today',
            'items' => ['Free plan, no card', 'Upgrade only when you grow'],
        ],
        'faq_title' => 'Jordan restaurant QR menu FAQ',
        'faqs' => [
            ['q' => 'Do restaurants and other businesses in Jordan need JoFotara-compliant invoices?', 'a' => 'Businesses in scope issue invoices through JoFotara. Small restaurants under the turnover threshold may be exempt — confirm with the ISTD or your advisor.'],
            ['q' => 'How do I make a QR code menu in Jordan?', 'a' => 'Create a free account, add your items, and you get a QR code and link that opens your menu on the customer’s phone in minutes.'],
            ['q' => 'Is there commission on orders?', 'a' => 'No — a flat subscription, and you keep the full value of every order.'],
        ],
    ],
];
