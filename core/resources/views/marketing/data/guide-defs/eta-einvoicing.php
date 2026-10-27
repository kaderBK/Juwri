<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * SEO Growth W5.2 — ETA (Egypt) electronic-receipt guide for restaurants.
 * Slug: eta-einvoicing. Bilingual (ar canonical keyword + en mirror).
 * Facts cite-checked 2026-08-30 (Egyptian Tax Authority / e-receipt guidance
 * and public summaries): the e-receipt system is the B2C consumer regime; retail
 * and restaurant consumer transactions are in scope from 1 Jan 2026
 * (Resolution 281/2025); receipts transmitted within 72 hours; QR on printed
 * receipts; VAT/e-invoice registration threshold reduced to EGP 250,000.
 * Confirm current rules with the ETA.
 */

return [
    'country' => 'eg',

    'ar' => [
        'eyebrow' => 'دليل امتثال',
        'title' => 'الإيصال الإلكتروني للمطاعم في مصر 2026',
        'meta_title' => 'الإيصال الإلكتروني للمطاعم في مصر 2026 — مصلحة الضرائب',
        'meta' => 'دليل الإيصال الإلكتروني للمطاعم والكافيهات في مصر: من يشمله الإلزام في 2026، الربط مع منظومة مصلحة الضرائب، رمز QR على الإيصال، والمهلة.',
        'sub' => 'منظومة الإيصال الإلكتروني أصبحت تشمل المطاعم والتجزئة وغيرها — إليك ما يحتاجه برنامج الكاشير لديك للربط مع مصلحة الضرائب المصرية.',
        'intro' => [
            'منظومة الإيصال الإلكتروني هي نظام مصلحة الضرائب المصرية (ETA) لإصدار إيصالات المستهلك إلكترونياً (B2C)، وهي منفصلة عن منظومة الفاتورة الإلكترونية الخاصة بمعاملات الشركات.',
            'اعتباراً من 1 يناير 2026 توسّعت المنظومة لتشمل معاملات التجزئة والمستهلك، ما يعني أن المطاعم والكافيهات والتجزئة صارت داخل نطاق الإلزام. هذا الدليل يشرح ما يجب فعله.',
        ],
        'sections' => [
            [
                'heading' => 'ما هو الإيصال الإلكتروني؟',
                'body' => [
                    'الإيصال الإلكتروني إيصال ضريبي يُصدَر للمستهلك النهائي ويُرسَل إلى منظومة مصلحة الضرائب. يُوجَّه للمعاملات مع المستهلك مباشرة (B2C)، بخلاف الفاتورة الإلكترونية الموجّهة لمعاملات الشركات.',
                ],
            ],
            [
                'heading' => 'من يشمله الإلزام في 2026',
                'body' => [
                    'بموجب قرار رقم 281 لسنة 2025 توسّعت المنظومة لتشمل التجزئة ومعاملات المستهلك اعتباراً من 1 يناير 2026. كما خُفِّض حد التسجيل الإلزامي في ضريبة القيمة المضافة والفوترة إلى 250,000 جنيه من الإيرادات السنوية.',
                ],
            ],
            [
                'heading' => 'الربط ورمز QR على الإيصال',
                'body' => [
                    'يجب ربط برنامج الكاشير أو نقاط البيع مباشرة بمنظومة مصلحة الضرائب، وإرسال الإيصالات خلال 72 ساعة من المعاملة، مع طباعة رمز QR على الإيصال المطبوع يتيح التحقق من صحته.',
                ],
            ],
            [
                'heading' => 'كيف يبقيك النظام متوافقاً',
                'body' => [
                    'نظام القائمة ونقاط البيع لديك يصدر الإيصالات الإلكترونية ويرسلها للمنظومة تلقائياً، مع رمز QR على كل إيصال وسلسلة جاهزة للتدقيق — دون إدخال يدوي.',
                ],
                'list' => [
                    'سجّل في منظومة الإيصال الإلكتروني عبر مصلحة الضرائب المصرية.',
                    'اربط برنامج الكاشير/نقاط البيع لإرسال الإيصالات آلياً خلال 72 ساعة.',
                    'راجع تاريخ إلزامك ومتطلباتك مع المصلحة.',
                ],
            ],
        ],
        'faq_title' => 'أسئلة شائعة عن الإيصال الإلكتروني للمطاعم',
        'faqs' => [
            ['q' => 'هل المطاعم وغيرها في مصر ملزمة بالإيصال الإلكتروني؟', 'a' => 'نعم — توسّعت المنظومة لتشمل التجزئة ومعاملات المستهلك اعتباراً من 1 يناير 2026، وتشمل المطاعم والكافيهات.'],
            ['q' => 'ما الفرق بين الإيصال الإلكتروني والفاتورة الإلكترونية؟', 'a' => 'الإيصال الإلكتروني للمعاملات مع المستهلك النهائي (B2C)، بينما الفاتورة الإلكترونية لمعاملات الشركات.'],
            ['q' => 'خلال كم من الوقت يجب إرسال الإيصال؟', 'a' => 'يجب إرسال الإيصالات إلى منظومة مصلحة الضرائب خلال 72 ساعة من المعاملة.'],
            ['q' => 'هل يجب طباعة رمز QR على الإيصال؟', 'a' => 'نعم — يُطبَع رمز QR على الإيصال يتيح التحقق من صحته عبر منظومة المصلحة.'],
        ],
        'cta_title' => 'ابدأ منيو وكاشير متوافق مع مصلحة الضرائب',
        'cta_sub' => 'قائمة رقمية وطلبات وإيصال إلكتروني — بدون عمولة على طلباتك.',
    ],

    'en' => [
        'eyebrow' => 'Compliance guide',
        'title' => 'Egypt Electronic Receipt (ETA) Guide for Restaurants (2026)',
        'meta' => 'The Egypt e-receipt system for restaurants and cafés: who is in scope in 2026, integrating with the ETA platform, the QR code on receipts, and the 72-hour window.',
        'sub' => 'Egypt’s e-receipt system now covers restaurants, retail and other businesses — here is what your POS needs to connect to the Egyptian Tax Authority.',
        'intro' => [
            'The e-receipt system is the Egyptian Tax Authority’s (ETA) regime for issuing consumer (B2C) tax receipts electronically. It is separate from the e-invoice system that covers business-to-business transactions.',
            'From 1 January 2026 the system expanded to cover retail and consumer transactions, bringing restaurants, cafés and retail into scope. This guide explains what to do.',
        ],
        'sections' => [
            [
                'heading' => 'What the e-receipt is',
                'body' => [
                    'An e-receipt is a tax receipt issued to a final consumer and transmitted to the ETA platform. It targets business-to-consumer (B2C) transactions, unlike the e-invoice, which covers business-to-business.',
                ],
            ],
            [
                'heading' => 'Who is in scope in 2026',
                'body' => [
                    'Under Resolution No. 281 of 2025 the system expanded to cover retail and consumer transactions from 1 January 2026. The mandatory VAT and e-invoicing registration threshold was also reduced to EGP 250,000 in annual revenue.',
                ],
            ],
            [
                'heading' => 'Integration and the QR code on receipts',
                'body' => [
                    'Your POS must connect directly to the ETA platform and transmit receipts within 72 hours of the transaction, with a QR code printed on the receipt so it can be validated.',
                ],
            ],
            [
                'heading' => 'How the system keeps you compliant',
                'body' => [
                    'Your menu and POS issue e-receipts and transmit them to the ETA automatically, with a QR code on every receipt and an audit-ready chain — no manual entry.',
                ],
                'list' => [
                    'Register for the e-receipt system with the Egyptian Tax Authority.',
                    'Connect your POS to transmit receipts automatically within 72 hours.',
                    'Confirm your enforcement date and requirements with the ETA.',
                ],
            ],
        ],
        'faq_title' => 'Restaurant e-receipt FAQ',
        'faqs' => [
            ['q' => 'Do restaurants and other businesses in Egypt need the e-receipt?', 'a' => 'Yes — the system expanded to cover retail and consumer transactions from 1 January 2026, which includes restaurants and cafés.'],
            ['q' => 'What is the difference between an e-receipt and an e-invoice?', 'a' => 'The e-receipt is for business-to-consumer (B2C) transactions, while the e-invoice covers business-to-business transactions.'],
            ['q' => 'How quickly must a receipt be transmitted?', 'a' => 'Receipts must be transmitted to the ETA platform within 72 hours of the transaction.'],
            ['q' => 'Does the receipt need a QR code?', 'a' => 'Yes — a QR code is printed on the receipt so it can be validated through the ETA platform.'],
        ],
        'cta_title' => 'Launch an ETA-ready menu and POS',
        'cta_sub' => 'Digital menu, ordering and e-receipts — with no commission on your orders.',
    ],
];
