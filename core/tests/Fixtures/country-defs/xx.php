<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * Test fixture for CountryTemplateTest — a stub country ("xx") that exercises
 * every block of home/country.blade.php WITHOUT shipping a fabricated country
 * into the live defs directory. The real countries land in W3.1 (sy) … W3.8.
 * Bilingual (en + ar) so the localize() fallback path is covered too.
 */

return [
    '_default' => 'en',

    'en' => [
        'region_name' => 'Exampleland',
        'name' => 'Exampleland',
        'eyebrow' => 'QR menu',
        'title' => 'QR Menu Exampleland — Fixture Country Page',
        'meta' => 'Fixture country page for Exampleland used by the template test.',
        'sub' => 'A stub country used only to prove the country template renders end-to-end.',
        'intro' => [
            'Exampleland restaurants can take QR and WhatsApp orders with zero commission.',
        ],
        'cta_primary' => 'Start free',
        'cta_demo' => 'See a live demo',
        'demo_slug' => 'crave-demo',
        'gateways_title' => 'Local payments',
        'gateways' => ['Example Pay', 'Cash on delivery', 'Card'],
        'compliance' => [
            'title' => 'Tax compliance',
            'body' => 'Issue compliant receipts for the Example Tax Authority.',
            'points' => ['Compliant e-receipts', 'QR on every invoice'],
        ],
        'pain_title' => 'Keep your margin',
        'pain' => 'Delivery apps in Exampleland take a big cut of every order.',
        'prices' => [
            'title' => 'Example pricing',
            'items' => ['From 0 EX/month', 'No commission on orders'],
        ],
        'faq_title' => 'Exampleland restaurant FAQ',
        'faqs' => [
            ['q' => 'Is there a QR menu for Exampleland restaurants?', 'a' => 'Yes — build one free and take orders with no commission.'],
            ['q' => 'Do you support local payments?', 'a' => 'Yes, including Example Pay and cash on delivery.'],
            ['q' => 'Is it compliant with the Example Tax Authority?', 'a' => 'Yes, receipts carry a compliant QR code.'],
        ],
    ],

    'ar' => [
        'region_name' => 'بلد المثال',
        'name' => 'بلد المثال',
        'eyebrow' => 'منيو الكتروني',
        'title' => 'منيو الكتروني في بلد المثال — صفحة تجريبية',
        'meta' => 'صفحة دولة تجريبية تستخدمها اختبارات القالب.',
        'sub' => 'دولة تجريبية للتحقق من عرض قالب صفحات الدول.',
        'intro' => [
            'مطاعم بلد المثال تستقبل طلبات QR وواتساب بدون عمولة.',
        ],
        'cta_primary' => 'ابدأ مجاناً',
        'cta_demo' => 'شاهد عرضاً مباشراً',
        'demo_slug' => 'crave-demo',
        'gateways_title' => 'وسائل الدفع المحلية',
        'gateways' => ['بطاقة', 'الدفع عند الاستلام'],
        'compliance' => [
            'title' => 'الامتثال الضريبي',
            'body' => 'أصدر إيصالات متوافقة مع هيئة الضرائب.',
            'points' => ['إيصالات إلكترونية متوافقة', 'رمز QR على كل فاتورة'],
        ],
        'pain_title' => 'احتفظ بهامش ربحك',
        'pain' => 'تأخذ تطبيقات التوصيل حصة كبيرة من كل طلب.',
        'prices' => [
            'title' => 'أمثلة الأسعار',
            'items' => ['من 0 وحدة/شهر', 'بدون عمولة على الطلبات'],
        ],
        'faq_title' => 'أسئلة شائعة',
        'faqs' => [
            ['q' => 'هل يوجد منيو الكتروني لمطاعم بلد المثال؟', 'a' => 'نعم — أنشئه مجاناً واستقبل الطلبات دون عمولة.'],
        ],
    ],
];
