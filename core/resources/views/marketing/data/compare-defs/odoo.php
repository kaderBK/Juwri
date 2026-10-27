<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * RB-10 — Odoo comparison page. Bilingual en + ar. Slug: odoo.
 *
 * Only claims that PRICING.md already records (strategy §1: "Odoo's free POS-only plan"; sources:
 * odoo.com/documentation/19.0, oec.sh/odoo-pricing) or that describe juwri itself are stated.
 * Everything else about Odoo is deliberately left out of the public text.
 *
 * TODO(owner) — verify on odoo.com before adding any of these (NOT claimed on the page; last-checked
 * date below only covers the facts that ARE on the page):
 *   - Odoo POS price per user, minimum users, Standard/Custom plan prices and the free-plan limits.
 *   - Whether Odoo POS ships a customer-facing QR/online-ordering storefront and which edition has it.
 *   - Whether Odoo's Saudi/ZATCA localisation is in Community or Enterprise only.
 *   - Odoo WhatsApp integration availability/edition, and Odoo's own per-user (not per-store) pricing.
 *   - Hosting terms (Odoo Online vs Odoo.sh vs on-premise).
 * RB-10b FINDINGS (official odoo.com only; format: fact — source — accessed 2026-10-12 — quote). Owner signs off; NOT on page.
 *   - Standard plan: US$16.90 / US$21.10 per user per month (yearly/monthly toggle; which is which unclear) — https://www.odoo.com/pricing — 2026-10-12 — "The discount is valid for 12 months, for initial users ordered."
 *   - Custom plan: US$32.00 / US$40.00 per user per month — https://www.odoo.com/pricing — 2026-10-12 — "Cost for Odoo.sh hosting not included."
 *   - Free plan: US$0, one app, unlimited users, Odoo Online; POS not explicitly listed on pricing page — https://www.odoo.com/pricing — 2026-10-12.
 *   - POS free: "Your first app is always free." — https://www.odoo.com/app/point-of-sale-restaurant — 2026-10-12 (POS-only free plan holds if POS is the one app).
 *   - POS online ordering: yes — same URL — 2026-10-12 — "your kiosk, website, dining room, or third-party delivery apps"; edition: not found on official site.
 *   - QR self-ordering: not found on official site (page says "pay instantly from a kiosk or their own phone", no QR mention); docs self_order page returned empty, unverified.
 *   - ZATCA: modules l10n_sa_edi (Phase 2) and l10n_sa_edi_pos (POS) — https://www.odoo.com/documentation/19.0/applications/finance/fiscal_localizations/saudi_arabia.html — 2026-10-12 — "Enables ZATCA Phase 2 API integration for e-invoicing compliance." Community vs Enterprise: not found on official site.
 *   - WhatsApp: Enterprise only — https://www.odoo.com/documentation/19.0/applications/productivity/whatsapp.html — 2026-10-12 — "WhatsApp is an Odoo Enterprise-only application"
 *   - Hosting: Standard = Odoo Online; Custom = Odoo Online, Odoo.sh or On-premise — https://www.odoo.com/pricing — 2026-10-12; "Odoo Online hosting is free" with storage up to 100GB (paraphrase, verify). Minimum users, free-plan limits: not found on official site.
 * Last checked: 2026-10-12 (competitor sources: their public pricing pages).
 */

return [
    'name' => 'Odoo',

    'en' => [
        'eyebrow' => 'Comparison',
        'title' => 'Odoo Alternative for Restaurants (2026)',
        'meta_title' => 'Odoo Alternative for Restaurants: QR Menu, Online Ordering & POS',
        'meta' => 'Comparing juwri with Odoo for a restaurant? juwri is priced per store, not per user, with online ordering included, no setup fee and no commission on orders. Plans start free.',
        'sub' => 'Odoo is a broad open-source business suite with a POS app. juwri is built for restaurants: a QR menu and online ordering on every plan, priced per store with unlimited staff and devices.',
        'intro' => [
            'Odoo is a modular business suite, and its POS app is one of many apps. If you already run your accounting, inventory and HR in Odoo, staying in one system has real value.',
            'juwri takes the opposite approach: one product for restaurant ordering and operations, priced per store, with unlimited staff and devices and an Odoo connector on the Chain plan. This page lists what juwri includes so you can check Odoo against your own needs.',
        ],
        'groups' => [
            [
                'group' => 'Pricing model',
                'rows' => [
                    ['label' => 'Free plan', 'us' => true, 'them' => 'POS-only free plan'],
                    ['label' => 'Priced per', 'us' => 'Store (unlimited staff and devices)', 'them' => 'See odoo.com/pricing'],
                    ['label' => 'Setup fee', 'us' => 'None', 'them' => 'See odoo.com/pricing'],
                    ['label' => 'Commission per order', 'us' => 'None', 'them' => 'None'],
                ],
            ],
            [
                'group' => 'What juwri includes',
                'rows' => [
                    ['label' => 'QR menu and online ordering on every plan', 'us' => true, 'them' => 'Check your Odoo edition'],
                    ['label' => 'ZATCA e-invoicing', 'us' => 'Included from Pro; add-on on Starter', 'them' => 'Check your Odoo edition'],
                    ['label' => 'Odoo connector', 'us' => 'Chain plan', 'them' => 'Not applicable'],
                ],
            ],
        ],
        'better_fit' => [
            'title' => 'When Odoo is the better fit',
            'points' => [
                'You want accounting, inventory, HR and manufacturing in the same system as your POS.',
                'You have an Odoo partner or in-house team to customise and maintain it.',
            ],
        ],
        'verdict_title' => 'The verdict',
        'verdict' => 'If you want one business suite and are happy to configure it, Odoo is a sound choice. If you mainly want to take orders and run restaurant service, start with juwri on the free plan and connect it to Odoo later on the Chain plan if you need to.',
        'notes' => [
            'Odoo information reflects odoo.com documentation and pricing pages, last checked 12 October 2026. Check odoo.com for current terms. juwri prices exclude VAT.',
        ],
        'faq_title' => 'Odoo comparison FAQ',
        'faqs' => [
            ['q' => 'Can I use juwri with Odoo?', 'a' => 'Yes. The Chain plan includes an Odoo connector, so you can keep Odoo for back-office work.'],
            ['q' => 'How is juwri priced?', 'a' => 'Per store, not per user or device. Monthly, cancel any time, with no setup fee and no commission on orders.'],
            ['q' => 'Is there a free plan?', 'a' => 'Yes. The free plan includes the QR menu and online ordering, up to 200 orders per month.'],
        ],
    ],

    'ar' => [
        'eyebrow' => 'مقارنة',
        'title' => 'بديل Odoo للمطاعم (2026)',
        'meta_title' => 'بديل Odoo للمطاعم: منيو باركود وطلبات أونلاين ونقاط بيع',
        'meta' => 'تقارن بين juwri وOdoo لمطعمك؟ التسعير في juwri لكل فرع وليس لكل مستخدم، والطلبات أونلاين مشمولة، بدون رسوم إعداد وبدون عمولة على الطلبات. الباقات تبدأ مجاناً.',
        'sub' => 'Odoo منصة أعمال مفتوحة المصدر واسعة ولها تطبيق نقاط بيع. أما juwri فمبني للمطاعم: منيو باركود وطلبات أونلاين في كل باقة، بسعر لكل فرع مع موظفين وأجهزة بلا حد.',
        'intro' => [
            'Odoo منصة أعمال متعددة الوحدات، وتطبيق نقاط البيع فيها واحد من تطبيقات كثيرة. إذا كنت تدير المحاسبة والمخزون والموارد البشرية في Odoo أصلاً، فبقاؤك في نظام واحد له قيمة حقيقية.',
            'يتبع juwri نهجاً مختلفاً: منتج واحد لطلبات المطعم وتشغيله، بسعر لكل فرع، مع موظفين وأجهزة بلا حد وموصّل Odoo في باقة Chain. تعرض هذه الصفحة ما يشمله juwri لتتحقق بنفسك مما تحتاجه من Odoo.',
        ],
        'groups' => [
            [
                'group' => 'نموذج التسعير',
                'rows' => [
                    ['label' => 'باقة مجانية', 'us' => true, 'them' => 'باقة مجانية لنقاط البيع فقط'],
                    ['label' => 'التسعير حسب', 'us' => 'الفرع (موظفون وأجهزة بلا حد)', 'them' => 'راجع odoo.com/pricing'],
                    ['label' => 'رسوم الإعداد', 'us' => 'لا يوجد', 'them' => 'راجع odoo.com/pricing'],
                    ['label' => 'عمولة على الطلب', 'us' => 'لا يوجد', 'them' => 'لا يوجد'],
                ],
            ],
            [
                'group' => 'ما يشمله juwri',
                'rows' => [
                    ['label' => 'منيو باركود وطلبات أونلاين في كل باقة', 'us' => true, 'them' => 'راجع إصدار Odoo لديك'],
                    ['label' => 'فاتورة إلكترونية (زاتكا)', 'us' => 'مشمولة من باقة Pro؛ إضافة في Starter', 'them' => 'راجع إصدار Odoo لديك'],
                    ['label' => 'موصّل Odoo', 'us' => 'باقة Chain', 'them' => 'لا ينطبق'],
                ],
            ],
        ],
        'better_fit' => [
            'title' => 'متى يكون Odoo هو الأنسب',
            'points' => [
                'تريد المحاسبة والمخزون والموارد البشرية والتصنيع في نفس نظام نقاط البيع.',
                'لديك شريك Odoo أو فريق داخلي لتخصيصه وصيانته.',
            ],
        ],
        'verdict_title' => 'الخلاصة',
        'verdict' => 'إذا أردت منصة أعمال واحدة ولا تمانع ضبطها، فـ Odoo خيار سليم. وإذا كان هدفك الأساسي استقبال الطلبات وتشغيل خدمة المطعم، فابدأ مع juwri بالباقة المجانية ثم اربطه بـ Odoo لاحقاً في باقة Chain إن احتجت.',
        'notes' => [
            'معلومات Odoo مأخوذة من وثائق وصفحات أسعار odoo.com، وآخر تحقق منها في 12 أكتوبر 2026. راجع odoo.com للشروط الحالية. أسعار juwri لا تشمل ضريبة القيمة المضافة.',
        ],
        'faq_title' => 'أسئلة شائعة عن مقارنة Odoo',
        'faqs' => [
            ['q' => 'هل أستطيع استخدام juwri مع Odoo؟', 'a' => 'نعم. تشمل باقة Chain موصّل Odoo، فتحتفظ بـ Odoo للأعمال الخلفية.'],
            ['q' => 'كيف يُسعَّر juwri؟', 'a' => 'لكل فرع وليس لكل مستخدم أو جهاز. شهرياً مع إمكانية الإلغاء في أي وقت، بدون رسوم إعداد وبدون عمولة على الطلبات.'],
            ['q' => 'هل توجد باقة مجانية؟', 'a' => 'نعم. تشمل الباقة المجانية المنيو بالباركود والطلبات أونلاين حتى 200 طلب شهرياً.'],
        ],
    ],
];
