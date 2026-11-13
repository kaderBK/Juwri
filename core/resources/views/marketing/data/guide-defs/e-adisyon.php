<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * SEO Growth W5.3 — e-Adisyon (Turkey) guide for restaurants. TR (single-locale).
 * Slug: e-adisyon. Title per KEYWORDS §Compliance.
 * country=null: no TR country page (W3.x), so the CTA falls back to register.
 *
 * Cite-check (2026-08-30, GİB/e-Belge guidance + public summaries):
 * - e-Adisyon = the electronic version of the paper adisyon (order slip/check)
 *   for table-service businesses (restoran/kafe/bar), shared with GİB.
 * - Businesses on e-Fatura / e-Arşiv in food & beverage are automatically in
 *   scope for e-Adisyon; GİB gives at least 3 months to transition, announced
 *   via ebelge.gib.gov.tr.
 * - 2026 penalty: 17.000 TL per document when a required e-document is not
 *   issued in the proper format. Confirm current figures with GİB.
 */

return [
    'country' => null,

    'tr' => [
        'eyebrow' => 'Uyumluluk rehberi',
        'title' => 'e-Adisyon Nedir? Restoranlar için 2026 Rehberi',
        'meta_title' => 'e-Adisyon Nedir? Restoranlar için 2026 Rehberi',
        'meta' => 'e-Adisyon nedir, kimler için zorunlu, GİB’e geçiş süreci ve 2026 ceza tutarı (belge başına 17.000 TL). Restoran ve kafeler için pratik rehber.',
        'sub' => 'Kağıt adisyonun GİB uyumlu dijital hali. Kimler zorunlu, nasıl geçilir ve cezadan nasıl kaçınılır — restoranlar için sade bir rehber.',
        'intro' => [
            'e-Adisyon; restoran, kafe ve bar gibi masa servisi yapan işletmelerde kullanılan kağıt adisyonun elektronik ve mali mevzuata uygun karşılığıdır. Sipariş ve hesap bilgisi yapılandırılmış biçimde dijital olarak saklanır ve gerektiğinde Gelir İdaresi Başkanlığı (GİB) ile paylaşılır.',
            'Bu rehber; e-Adisyonun ne olduğunu, kimler için zorunlu olduğunu, geçiş sürecini ve sistemin bunu sizin için nasıl hallettiğini açıklar.',
        ],
        'sections' => [
            [
                'heading' => 'e-Adisyon nedir?',
                'body' => [
                    'e-Adisyon, kağıt adisyonun dijital versiyonudur. Masadaki sipariş ve hesap bilgisi elektronik olarak düzenlenir, saklanır ve GİB’in e-Belge sistemine uygun formatta hazır tutulur.',
                ],
            ],
            [
                'heading' => 'Kimler için zorunlu?',
                'body' => [
                    'Gerçek usulde vergilendirilen ve adisyon düzenleme zorunluluğu bulunan yeme-içme işletmeleri kapsamdadır. e-Fatura veya e-Arşiv Fatura uygulamasına geçmiş lokanta, restoran ve kafeler otomatik olarak e-Adisyon kapsamına girer.',
                ],
            ],
            [
                'heading' => 'Geçiş süreci',
                'body' => [
                    'GİB, işletmelerin satış hasılatını dikkate alarak e-Adisyona geçiş için en az 3 ay süre tanır ve bildirimi e-Belge sitesi (ebelge.gib.gov.tr) üzerinden duyurur. Süreyi kaçırmamak için erken hazırlık önemlidir.',
                ],
            ],
            [
                'heading' => '2026 ceza tutarı',
                'body' => [
                    '2026’da elektronik ortamda düzenlenmesi gereken bir belgenin uygun formatta düzenlenmemesi durumunda belge başına 17.000 TL ceza uygulanır. Erken uyum, bu cezaları ve denetim sürprizlerini önler.',
                ],
            ],
            [
                'heading' => 'Sistem bunu sizin için nasıl halleder',
                'body' => [
                    'Menü ve POS sisteminiz siparişleri alırken e-Adisyonu uygun formatta otomatik oluşturur ve saklar; denetime hazır bir belge zinciri kalır — elle giriş yok.',
                ],
                'list' => [
                    'e-Fatura/e-Arşiv durumunuzu kontrol edin; kapsamdaysanız e-Adisyon da gereklidir.',
                    'GİB e-Belge bildiriminizdeki geçiş tarihini takip edin.',
                    'POS/menü sisteminizi e-Adisyonu otomatik üretecek şekilde bağlayın.',
                ],
            ],
        ],
        'faq_title' => 'e-Adisyon SSS',
        'faqs' => [
            ['q' => 'e-Adisyon kimler için zorunlu?', 'a' => 'Adisyon düzenleme zorunluluğu olan yeme-içme işletmeleri; e-Fatura veya e-Arşiv’e geçen restoran ve kafeler otomatik olarak kapsama girer.'],
            ['q' => 'e-Adisyona geçiş için ne kadar sürem var?', 'a' => 'GİB en az 3 aylık bir süre tanır ve bildirimi ebelge.gib.gov.tr üzerinden yapar.'],
            ['q' => 'Uymazsam ceza ne kadar?', 'a' => '2026’da uygun formatta düzenlenmeyen belge başına 17.000 TL ceza uygulanır.'],
        ],
        'cta_eyebrow' => 'Başlayın',
        'cta_title' => 'e-Adisyona hazır bir menü ve POS başlatın',
        'cta_sub' => 'QR menü, WhatsApp sipariş ve GİB uyumlu e-belgeler — sipariş başına komisyonsuz.',
        'cta_label' => 'Ücretsiz başla',
    ],
];
