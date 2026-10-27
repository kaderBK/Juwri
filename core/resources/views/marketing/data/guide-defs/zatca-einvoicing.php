<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * SEO Growth W5.2 — ZATCA (Saudi Arabia) e-invoicing guide for restaurants.
 * Slug: zatca-einvoicing. Bilingual (ar canonical keyword + en mirror).
 * Facts cite-checked 2026-08-30 from ZATCA/Fatoora guidance and public
 * summaries; regulatory specifics change — the copy tells readers to confirm
 * their wave/date on the ZATCA portal and consult a tax advisor.
 */

return [
    'country' => 'sa',

    'ar' => [
        'eyebrow' => 'دليل امتثال',
        'title' => 'دليل الفاتورة الإلكترونية للمطاعم في السعودية 2026',
        'meta_title' => 'دليل الفاتورة الإلكترونية للمطاعم في السعودية 2026 — زاتكا',
        'meta' => 'كل ما يحتاجه المطعم أو الكافيه عن الفاتورة الإلكترونية في السعودية: مرحلتا زاتكا، رمز QR على الفاتورة المبسطة، الربط مع منصة فاتورة، والمواعيد.',
        'sub' => 'كيف تتكامل قائمتك الرقمية ونقاط البيع مع منصة فاتورة (Fatoora) — وما الذي يحتاجه مطعمك أو نشاطك فعلياً لإصدار فواتير إلكترونية متوافقة مع زاتكا.',
        'intro' => [
            'ألزمت هيئة الزكاة والضريبة والجمارك (زاتكا) المنشآت المسجلة في ضريبة القيمة المضافة بإصدار الفواتير إلكترونياً. بالنسبة للمطاعم والكافيهات والمتاجر التي تصدر فواتير مبسطة للمستهلك، هذا يعني فاتورة إلكترونية تحمل رمز QR على كل إيصال.',
            'هذا الدليل يشرح الفرق بين المرحلتين، وما الذي يجب أن يفعله مطعمك أو نشاطك، وكيف يتولى النظام هذه المتطلبات نيابةً عنك.',
        ],
        'sections' => [
            [
                'heading' => 'ما هي الفاتورة الإلكترونية (فاتورة)؟',
                'body' => [
                    'الفاتورة الإلكترونية نظام من زاتكا لإصدار الفواتير ومشاركتها إلكترونياً بدل الورق. تنقسم إلى مرحلتين: مرحلة الإصدار (المرحلة الأولى) التي بدأت في ديسمبر 2021، ومرحلة الربط والتكامل (المرحلة الثانية) التي تُطبَّق على دفعات (Waves) بحسب حجم الإيرادات.',
                ],
            ],
            [
                'heading' => 'ماذا تتطلب المرحلة الثانية من المطعم أو المنشأة؟',
                'body' => [
                    'في مرحلة الربط والتكامل يجب أن يصدر نظامك الفاتورة بصيغة XML متوافقة (UBL 2.1) مع ختم تشفيري ومُعرّف فريد (UUID) ورمز QR، وأن يتكامل مع منصة فاتورة.',
                ],
                'list' => [
                    'الفواتير المبسطة (للمستهلك B2C) تُبلَّغ لزاتكا خلال 24 ساعة من إصدارها.',
                    'يبدأ الربط بحسب دفعتك (Wave)، وتشمل الدفعات المنشآت التي تجاوزت إيراداتها 375,000 ريال.',
                    'تحقق من خطاب دفعتك وتاريخ إلزامك عبر بوابة زاتكا.',
                ],
            ],
            [
                'heading' => 'رمز QR على كل إيصال',
                'body' => [
                    'الفاتورة المبسطة التي تسلّمها للزبون يجب أن تحمل رمز QR يتضمن ختماً تشفيرياً حتى يتمكن الزبون وزاتكا من التحقق من صحتها. هذا الرمز يُطبَع تلقائياً على الإيصال عند التكامل الصحيح.',
                ],
            ],
            [
                'heading' => 'كيف يبقيك النظام متوافقاً',
                'body' => [
                    'الفاتورة الإلكترونية لزاتكا مشمولة في خطتي Pro وChain (ومتاحة كإضافة لخطة Starter). عند تفعيلها يصدر نظام القائمة ونقاط البيع لديك فواتير إلكترونية موقّعة ومترابطة تلقائياً، مع رمز QR للمرحلة الثانية على كل إيصال، وسلسلة فواتير جاهزة للتدقيق. يوجد رابط مباشر (Driver) للسعودية عبر زاتكا.',
                ],
            ],
            [
                'heading' => 'المواعيد ومن يشمله الإلزام',
                'body' => [
                    'يُطبَّق الربط على دفعات متتالية خلال 2026. تاريخ إلزام منشأتك يعتمد على دفعتك وإيراداتك؛ راجع بوابة زاتكا أو مستشارك الضريبي لتأكيد تاريخك الدقيق ومتطلباتك.',
                ],
            ],
        ],
        'faq_title' => 'أسئلة شائعة عن الفاتورة الإلكترونية للمطاعم',
        'faqs' => [
            ['q' => 'هل يحتاج المطعم أو أي منشأة في السعودية إلى فاتورة إلكترونية؟', 'a' => 'نعم — المنشآت المسجلة في ضريبة القيمة المضافة ملزمة بإصدار الفواتير إلكترونياً، والمطاعم التي تصدر فواتير مبسطة تحتاج رمز QR على كل إيصال.'],
            ['q' => 'ما الفرق بين المرحلة الأولى والثانية؟', 'a' => 'المرحلة الأولى هي إصدار الفواتير إلكترونياً (منذ ديسمبر 2021)، والمرحلة الثانية هي الربط والتكامل مع منصة فاتورة بصيغة XML وختم تشفيري.'],
            ['q' => 'هل يجب أن تحمل كل فاتورة رمز QR؟', 'a' => 'الفواتير المبسطة الموجهة للمستهلك يجب أن تحمل رمز QR للتحقق؛ ويُطبَع تلقائياً عند التكامل الصحيح.'],
            ['q' => 'كيف أربط نقاط البيع مع زاتكا؟', 'a' => 'باستخدام نظام متوافق يتكامل مع منصة فاتورة ويصدر الفواتير الموقّعة تلقائياً — دون إعداد يدوي معقّد.'],
        ],
        'cta_title' => 'ابدأ منيو ونقاط بيع متوافقة مع زاتكا',
        'cta_sub' => 'ابدأ مجاناً بمنيو رقمي وطلبات أونلاين بدون عمولة؛ والفاتورة الإلكترونية المتوافقة مشمولة في خطتي Pro وChain (ومتاحة كإضافة لخطة Starter).',
    ],

    'en' => [
        'eyebrow' => 'Compliance guide',
        'title' => 'ZATCA E-Invoicing Guide for Restaurants in Saudi Arabia (2026)',
        'meta' => 'What a restaurant or café in Saudi Arabia needs for ZATCA e-invoicing: Phase 1 vs Phase 2, the QR code on simplified invoices, Fatoora integration and deadlines.',
        'sub' => 'How your digital menu and POS connect to the Fatoora platform — and what a restaurant or business actually needs to issue ZATCA-compliant e-invoices.',
        'intro' => [
            'ZATCA (the Zakat, Tax and Customs Authority) requires VAT-registered businesses in Saudi Arabia to issue invoices electronically. For restaurants, cafés and shops that issue simplified consumer invoices, that means an electronic invoice with a QR code on every receipt.',
            'This guide explains the two phases, what your restaurant or business must do, and how the system handles the requirements for you.',
        ],
        'sections' => [
            [
                'heading' => 'What ZATCA e-invoicing (Fatoora) is',
                'body' => [
                    'E-invoicing is ZATCA’s system for generating and exchanging invoices electronically instead of on paper. It runs in two phases: the Generation phase (Phase 1), live since December 2021, and the Integration phase (Phase 2), rolled out in waves by revenue size.',
                ],
            ],
            [
                'heading' => 'What Phase 2 requires from a restaurant or business',
                'body' => [
                    'In the Integration phase your system must produce a compliant XML invoice (UBL 2.1) carrying a cryptographic stamp, a UUID and a QR code, and must integrate with the Fatoora platform.',
                ],
                'list' => [
                    'Simplified (B2C) invoices are reported to ZATCA within 24 hours of issuance.',
                    'Integration starts by wave; waves cover businesses whose turnover exceeded SAR 375,000.',
                    'Check your wave letter and your enforcement date on the ZATCA portal.',
                ],
            ],
            [
                'heading' => 'The QR code on every receipt',
                'body' => [
                    'The simplified invoice you hand a customer must carry a QR code containing a cryptographic stamp so both the customer and ZATCA can validate it. With correct integration, that code is printed on the receipt automatically.',
                ],
            ],
            [
                'heading' => 'How the system keeps you compliant',
                'body' => [
                    'ZATCA e-invoicing is included on Pro and Chain plans (and available as an add-on for Starter). Once it is on, your menu and POS issue signed, chained e-invoices automatically, with a Phase-2 QR on every receipt and an invoice chain your auditor will accept. A live driver connects you directly to ZATCA in Saudi Arabia.',
                ],
            ],
            [
                'heading' => 'Deadlines and who is in scope',
                'body' => [
                    'Integration is phased in waves through 2026. Your enforcement date depends on your wave and revenue — confirm your exact date and requirements on the ZATCA portal or with your tax advisor.',
                ],
            ],
        ],
        'faq_title' => 'Restaurant e-invoicing FAQ',
        'faqs' => [
            ['q' => 'Do restaurants and other businesses in Saudi Arabia need e-invoicing?', 'a' => 'Yes — VAT-registered businesses must issue invoices electronically, and restaurants issuing simplified invoices need a QR code on every receipt.'],
            ['q' => 'What is the difference between Phase 1 and Phase 2?', 'a' => 'Phase 1 is generating invoices electronically (since December 2021); Phase 2 is integrating with the Fatoora platform using compliant XML and a cryptographic stamp.'],
            ['q' => 'Does every receipt need a QR code?', 'a' => 'Simplified consumer invoices must carry a QR code for validation; it is printed automatically once integration is in place.'],
            ['q' => 'How do I integrate my POS with ZATCA?', 'a' => 'Use a compliant system that integrates with the Fatoora platform and issues signed invoices automatically — no complex manual setup.'],
        ],
        'cta_title' => 'Launch a ZATCA-ready menu and POS',
        'cta_sub' => 'Start free with a digital menu and online ordering, with no commission on your orders; compliant e-invoicing is included on Pro and Chain plans (and available as an add-on for Starter).',
    ],
];
