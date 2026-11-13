<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * SEO Growth W5.2 — JoFotara (Jordan) national e-invoicing guide for restaurants.
 * Slug: jofotara-einvoicing. Bilingual (ar canonical keyword + en mirror).
 * Facts cite-checked 2026-08-30 (ISTD / JoFotara guidance and public summaries):
 * Phase 2 mandatory since 1 Apr 2025 (B2B/B2C/B2G); local restaurants under
 * 75,000 JOD annual sales are exempt, above are required to register/integrate;
 * fines up to JOD 500 per violation. Confirm current rules with the ISTD.
 */

return [
    'country' => 'jo',

    'ar' => [
        'eyebrow' => 'دليل امتثال',
        'title' => 'دليل جوفوترة للمطاعم في الأردن 2026',
        'meta_title' => 'دليل جوفوترة للمطاعم في الأردن 2026 — الفوترة الوطنية',
        'meta' => 'دليل جوفوترة (نظام الفوترة الوطني) للمطاعم في الأردن: من يجب أن يسجّل، إعفاء المطاعم دون 75,000 دينار، الغرامات، وكيفية الربط.',
        'sub' => 'نظام الفوترة الوطني (جوفوترة) أصبح إلزامياً — إليك ما يعنيه لمطعمك أو أي نشاط تجاري، ومتى تكون معفياً، وكيف تربط نظامك.',
        'intro' => [
            'أطلقت دائرة ضريبة الدخل والمبيعات نظام الفوترة الوطني «جوفوترة» (JoFotara). منذ 1 نيسان/أبريل 2025 أصبحت الفوترة الإلكترونية إلزامية لمعاملات الأعمال كافة (B2B وB2C وB2G) في الأردن.',
            'لكن ليست كل المطاعم والمنشآت ملزمة فوراً — يعتمد الأمر على مبيعاتك السنوية. هذا الدليل يوضح متى تُلزَم، ومتى تكون معفياً، وكيف تجهّز مطعمك.',
        ],
        'sections' => [
            [
                'heading' => 'ما هو نظام جوفوترة؟',
                'body' => [
                    'جوفوترة هو نظام الفوترة الإلكترونية الوطني في الأردن، يتيح إصدار الفواتير وإبلاغها للدائرة إلكترونياً. الهدف توحيد الفواتير ومكافحة التهرب الضريبي.',
                ],
            ],
            [
                'heading' => 'إعفاء المطاعم والمنشآت الصغيرة دون 75,000 دينار',
                'body' => [
                    'المطاعم والمنشآت الصغيرة التي تقل مبيعاتها السنوية عن 75,000 دينار أردني معفاة من إصدار الفواتير إلكترونياً. أما التي تتجاوز مبيعاتها 75,000 دينار فهي ملزمة بالتسجيل والربط مع نظام الفوترة الوطني.',
                ],
            ],
            [
                'heading' => 'الغرامات وعدم الامتثال',
                'body' => [
                    'وفق تعليمات الفوترة والرقابة قد تصل الغرامة إلى 500 دينار لكل مخالفة. الامتثال المبكر يجنّبك هذه الغرامات ويبسّط تدقيقك المحاسبي.',
                ],
            ],
            [
                'heading' => 'كيف تربط مطعمك أو نشاطك مع جوفوترة',
                'body' => [
                    'يصدر نظام القائمة ونقاط البيع لديك الفواتير إلكترونياً ويبلّغها للنظام الوطني تلقائياً، مع سلسلة فواتير جاهزة للتدقيق — دون إدخال يدوي.',
                ],
                'list' => [
                    'سجّل منشأتك في نظام جوفوترة عبر دائرة ضريبة الدخل والمبيعات.',
                    'اربط نقاط البيع/نظام الطلبات لإصدار الفواتير وإبلاغها آلياً.',
                    'تحقق من متطلباتك وتاريخ إلزامك مع الدائرة.',
                ],
            ],
        ],
        'faq_title' => 'أسئلة شائعة عن جوفوترة للمطاعم',
        'faqs' => [
            ['q' => 'هل مطعمي أو نشاطي في الأردن ملزم بجوفوترة؟', 'a' => 'إذا تجاوزت مبيعاتك السنوية 75,000 دينار فأنت ملزم بالتسجيل والربط؛ أما دون 75,000 دينار فالمطاعم معفاة من الفوترة الإلكترونية.'],
            ['q' => 'منذ متى أصبحت الفوترة إلزامية؟', 'a' => 'منذ 1 نيسان/أبريل 2025 أصبحت المرحلة الثانية إلزامية لمعاملات الأعمال كافة (B2B وB2C وB2G).'],
            ['q' => 'ما مقدار الغرامة عند عدم الامتثال؟', 'a' => 'قد تصل الغرامة إلى 500 دينار لكل مخالفة وفق تعليمات الفوترة والرقابة.'],
            ['q' => 'كيف أربط نقاط البيع مع جوفوترة؟', 'a' => 'باستخدام نظام يصدر الفواتير إلكترونياً ويبلّغها للنظام الوطني تلقائياً، مع سلسلة فواتير جاهزة للتدقيق.'],
        ],
        'cta_title' => 'ابدأ منيو ونظام طلبات جاهز لجوفوترة',
        'cta_sub' => 'قائمة رقمية وطلبات وفوترة إلكترونية — بدون عمولة على طلباتك.',
    ],

    'en' => [
        'eyebrow' => 'Compliance guide',
        'title' => 'JoFotara E-Invoicing Guide for Restaurants in Jordan (2026)',
        'meta' => 'JoFotara (Jordan’s national e-invoicing system) for restaurants: who must register, the under-75,000 JOD restaurant exemption, penalties and how to integrate.',
        'sub' => 'Jordan’s national e-invoicing system (JoFotara) is now mandatory — here is what it means for your restaurant or business, when you are exempt, and how to connect.',
        'intro' => [
            'The Income and Sales Tax Department launched JoFotara, Jordan’s national e-invoicing system. Since 1 April 2025, e-invoicing has been mandatory for all business transactions (B2B, B2C and B2G) in Jordan.',
            'Not every restaurant or business is captured immediately, though — it depends on your annual sales. This guide explains when you are in scope, when you are exempt, and how to get ready.',
        ],
        'sections' => [
            [
                'heading' => 'What JoFotara is',
                'body' => [
                    'JoFotara is Jordan’s national electronic invoicing system, letting businesses issue and report invoices electronically. The goal is to standardise invoices and curb tax evasion.',
                ],
            ],
            [
                'heading' => 'The under-75,000 JOD restaurant and small-business exemption',
                'body' => [
                    'Local restaurants and other businesses with annual sales below 75,000 JOD are exempt from issuing invoices electronically. Restaurants whose annual sales exceed 75,000 JOD are not exempt and must register and integrate with the national e-invoicing system.',
                ],
            ],
            [
                'heading' => 'Penalties for non-compliance',
                'body' => [
                    'Under the Billing and Control Regulation, fines can reach up to JOD 500 per violation. Getting compliant early avoids the fines and simplifies your bookkeeping and audits.',
                ],
            ],
            [
                'heading' => 'How to connect your restaurant or business to JoFotara',
                'body' => [
                    'Your menu and POS issue invoices electronically and report them to the national system automatically, with an audit-ready invoice chain — no manual entry.',
                ],
                'list' => [
                    'Register your business with JoFotara via the Income and Sales Tax Department.',
                    'Connect your POS / ordering system to issue and report invoices automatically.',
                    'Confirm your exact requirements and start date with the ISTD.',
                ],
            ],
        ],
        'faq_title' => 'JoFotara for restaurants FAQ',
        'faqs' => [
            ['q' => 'Does my restaurant or business in Jordan need JoFotara?', 'a' => 'If your annual sales exceed 75,000 JOD you must register and integrate; below 75,000 JOD, restaurants are exempt from e-invoicing.'],
            ['q' => 'Since when is e-invoicing mandatory?', 'a' => 'Since 1 April 2025 Phase 2 has been mandatory for all business transactions (B2B, B2C and B2G).'],
            ['q' => 'What is the penalty for non-compliance?', 'a' => 'Fines can reach up to JOD 500 per violation under the Billing and Control Regulation.'],
            ['q' => 'How do I connect my POS to JoFotara?', 'a' => 'Use a system that issues invoices electronically and reports them to the national system automatically, with an audit-ready chain.'],
        ],
        'cta_title' => 'Launch a JoFotara-ready menu and ordering system',
        'cta_sub' => 'Digital menu, ordering and e-invoicing — with no commission on your orders.',
    ],
];
