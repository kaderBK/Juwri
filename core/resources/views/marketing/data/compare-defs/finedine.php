<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * SEO Growth W4.3 — FineDine comparison page. TR-focused (tr primary + en mirror).
 * Slug: finedine.
 *
 * Cite-check (2026-08-30, finedinemenu.com + /en/pricing/):
 * - NO free plan — paid tiers only (Starter/Growth/Premium), plus a free TRIAL.
 * - Starter plan limited to 1 language & currency; multiple/unlimited on higher
 *   tiers. Yearly billing -20%. No per-order commission disclosed.
 * - Advertises QR menu, Order & Pay, FineDine POS Lite, reservations, CRM.
 * - WhatsApp ordering and e-invoicing / ZATCA tax compliance are NOT advertised
 *   on their site (checked 2026-08-30) — hence the "—" cells.
 * KEYWORDS §Compare finedine angle: TR market, no free plan.
 */

return [
    'name' => 'FineDine',

    'tr' => [
        'eyebrow' => 'Karşılaştırma',
        'title' => 'FineDine Alternatifi (2026)',
        'meta_title' => 'FineDine Alternatifi (2026)',
        'meta' => 'FineDine’a ücretsiz planı olan, komisyonsuz bir alternatif: QR menü ve WhatsApp siparişini, ayrıca ücretli planlardaki e-faturayı karşılaştırın. Ücretsiz plan var mı?',
        'sub' => 'FineDine’ın ücretsiz planı yok, yalnızca deneme sürümü var. İşte ücretsiz planı olan, komisyonsuz bir alternatifle dürüst karşılaştırma.',
        'intro' => [
            'FineDine; QR menü, masada öde ve tablet menü konusunda güçlü, cilalı bir üründür. Ancak kalıcı bir ücretsiz planı yoktur — yalnızca ücretli planlar (Başlangıç/Growth/Premium) ve bir deneme sürümü sunar.',
            'Başlangıç planı tek dille sınırlıdır; çok dil için üst plana geçmeniz gerekir. Bu sayfa iki tarafı da dürüstçe karşılaştırır.',
        ],
        'groups' => [
            [
                'group' => 'Fiyatlandırma',
                'rows' => [
                    ['label' => 'Ücretsiz plan', 'us' => true, 'them' => 'Sadece deneme'],
                    ['label' => 'Sipariş başına komisyon', 'us' => 'Yok', 'them' => 'Yok'],
                    ['label' => 'Menü dili (temel plan)', 'us' => 'Sınırsız', 'them' => 'Başlangıç: 1 dil'],
                ],
            ],
            [
                'group' => 'Özellikler',
                'rows' => [
                    ['label' => 'QR menü', 'us' => true, 'them' => true],
                    ['label' => 'Masada sipariş & ödeme', 'us' => true, 'them' => true],
                    ['label' => 'Dahili POS', 'us' => true, 'them' => true],
                    ['label' => 'WhatsApp sipariş', 'us' => true, 'them' => false],
                    ['label' => 'E-fatura (ZATCA / JoFotara / ETA)', 'us' => true, 'them' => false],
                ],
            ],
        ],
        'better_fit' => [
            'title' => 'FineDine ne zaman daha uygun',
            'points' => [
                'Üst düzey restoranlar için cilalı tablet/QR menü, masada öde, rezervasyon ve CRM istiyorsanız.',
                'WhatsApp siparişe veya bölgesel e-faturaya ihtiyacınız yoksa ve ücretli plan sizin için sorun değilse.',
            ],
        ],
        'verdict_title' => 'Sonuç',
        'verdict' => 'Cilalı bir tablet/QR menü ve masada ödeme istiyorsanız FineDine güçlü bir seçenektir. Ücretsiz bir planla başlamak, WhatsApp sipariş almak ve Türkiye/Körfez için e-fatura istiyorsanız, alternatif tek sabit abonelikte daha fazlasını kapsar.',
        'faq_title' => 'FineDine karşılaştırma SSS',
        'faqs' => [
            ['q' => 'FineDine’ın ücretsiz planı var mı?', 'a' => 'Kalıcı ücretsiz plan yok; yalnızca deneme sürümü var ve planlar ücretlidir. Alternatifin ücretsiz bir planı vardır.'],
            ['q' => 'FineDine WhatsApp sipariş destekliyor mu?', 'a' => 'WhatsApp sipariş FineDine sitesinde belirtilmiyor; alternatif, masadan doğrudan WhatsApp’a sipariş almayı sağlar.'],
            ['q' => 'Komisyon alıyorlar mı?', 'a' => 'FineDine sayfasında sipariş başına komisyon belirtilmiyor; alternatif de komisyonsuzdur, sabit abonelik ödersiniz.'],
        ],
    ],

    'en' => [
        'eyebrow' => 'Comparison',
        'title' => 'FineDine Alternative (2026)',
        'meta_title' => 'Free FineDine Alternative: QR Menu & WhatsApp Ordering',
        'meta' => 'A commission-free alternative to FineDine with a free plan: compare QR menus and WhatsApp ordering, plus e-invoicing on paid plans. Does FineDine have a free plan?',
        'sub' => 'FineDine has no free plan — only a trial. Here is an honest comparison with a commission-free alternative that does have a free plan.',
        'intro' => [
            'FineDine is a strong, polished product for QR menus, order & pay and tablet menus. But it has no permanent free plan — only paid tiers (Starter/Growth/Premium) and a trial.',
            'The Starter plan is limited to a single language; multiple languages need a higher tier. This page compares both sides honestly.',
        ],
        'groups' => [
            [
                'group' => 'Pricing',
                'rows' => [
                    ['label' => 'Free plan', 'us' => true, 'them' => 'Trial only'],
                    ['label' => 'Commission per order', 'us' => 'None', 'them' => 'None'],
                    ['label' => 'Menu languages (base plan)', 'us' => 'Unlimited', 'them' => 'Starter: 1 language'],
                ],
            ],
            [
                'group' => 'Features',
                'rows' => [
                    ['label' => 'QR code menu', 'us' => true, 'them' => true],
                    ['label' => 'Order & pay at table', 'us' => true, 'them' => true],
                    ['label' => 'Built-in POS', 'us' => true, 'them' => true],
                    ['label' => 'WhatsApp ordering', 'us' => true, 'them' => false],
                    ['label' => 'E-invoicing (ZATCA / JoFotara / ETA)', 'us' => true, 'them' => false],
                ],
            ],
        ],
        'better_fit' => [
            'title' => 'When FineDine is the better fit',
            'points' => [
                'You want a polished tablet/QR menu, order & pay, reservations and CRM for an upscale dine-in venue.',
                'You do not need WhatsApp ordering or regional e-invoicing, and paid-only plans are fine for you.',
            ],
        ],
        'verdict_title' => 'The verdict',
        'verdict' => 'For a polished tablet/QR menu with order & pay, FineDine is a strong option. If you want to start on a free plan, take WhatsApp orders and add e-invoicing for Turkey and the Gulf on a paid plan, the alternative covers more on one flat subscription.',
        'faq_title' => 'FineDine comparison FAQ',
        'faqs' => [
            ['q' => 'Does FineDine have a free plan?', 'a' => 'No permanent free plan — only a trial, and the plans are paid. The alternative offers a free plan.'],
            ['q' => 'Does FineDine support WhatsApp ordering?', 'a' => 'WhatsApp ordering is not advertised on FineDine’s site; the alternative takes orders straight to WhatsApp from the table.'],
            ['q' => 'Do they charge commission?', 'a' => 'FineDine’s site does not disclose a per-order commission; the alternative is also commission-free on a flat subscription.'],
        ],
    ],
];
