<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * SEO Growth W3.1 — Syria country landing page (cc: sy). AR-first (uncontested
 * SERP) + a short EN block. Wallet-first market: Syriatel Cash / MTN Cash /
 * Sham Cash. Facts kept conservative; no tax-mandate claims for Syria.
 */

return [
    '_default' => 'ar',

    'ar' => [
        'region_name' => 'سوريا',
        'name' => 'سوريا',
        'eyebrow' => 'منيو الكتروني',
        'title' => 'منيو الكتروني في سوريا — كاش سيرياتيل و MTN وشام كاش',
        'meta' => 'منيو الكتروني QR للمطاعم والكافيهات في سوريا: أنشئ منيو باركود مجاناً، استقبل الطلبات عبر واتساب، وادفع عبر سيرياتيل كاش و MTN كاش وشام كاش — بدون عمولة.',
        'sub' => 'قائمة رقمية بباركود QR، طلبات مباشرة على واتساب، ومحافظ الدفع المحلية التي يستخدمها زبائنك — كل ذلك دون عمولة على طلباتك.',
        'intro' => [
            'حوّل مطعمك أو متجرك أو كافيهك في دمشق أو حلب أو اللاذقية إلى منيو الكتروني يعمل من هاتف الزبون: يمسح رمز QR، يتصفح الأصناف، ويرسل طلبه مباشرة عبر واتساب.',
            'لا حاجة لتطبيق ولا لعمولات المنصّات — أنت تملك القائمة والطلب وعلاقتك بالزبون بالكامل.',
        ],
        'cta_primary' => 'ابدأ مجاناً',
        'cta_demo' => 'شاهد منيو مباشراً',
        'demo_slug' => 'crave-demo',
        'gateways_title' => 'وسائل الدفع المحلية',
        'gateways' => ['سيرياتيل كاش', 'MTN كاش', 'شام كاش', 'الدفع عند الاستلام'],
        'compliance' => [
            'title' => 'قناتك الخاصة، لا وسيط',
            'body' => 'بدل الاعتماد على منصّات محدودة أو مكالمات هاتفية، يصلك كل طلب كرسالة واتساب منظمة مع الأصناف والكميات والعنوان.',
            'points' => [
                'رمز QR على الطاولة والملصق ورابط الحساب.',
                'طلبات داخل المطعم والاستلام والتوصيل من رقم واحد.',
                'قائمة ثنائية اللغة (عربي/إنجليزي) تلقائياً.',
            ],
        ],
        'pain_title' => 'بدون عمولة على طلباتك',
        'pain' => 'تحتفظ بـ 100% من قيمة كل طلب — تدفع اشتراكاً ثابتاً فقط، والليالي المزدحمة تبقى لك.',
        'prices' => [
            'title' => 'ابدأ اليوم',
            'items' => [
                'خطة مجانية للبدء دون بطاقة.',
                'ترقية اختيارية عندما ينمو مطعمك.',
            ],
        ],
        'faq_title' => 'أسئلة شائعة — المنيو الإلكتروني في سوريا',
        'faqs' => [
            ['q' => 'كيف أعمل منيو الكتروني لمطعمي أو متجري في سوريا؟', 'a' => 'أنشئ حساباً مجاناً، أضف أصنافك وأسعارك، فتحصل على رمز QR ورابط يفتح قائمتك على هاتف الزبون خلال دقائق.'],
            ['q' => 'هل يمكن الدفع عبر سيرياتيل كاش أو MTN كاش أو شام كاش؟', 'a' => 'نعم، يمكنك عرض محافظ الدفع المحلية مثل سيرياتيل كاش و MTN كاش وشام كاش إلى جانب الدفع عند الاستلام.'],
            ['q' => 'هل هناك عمولة على الطلبات؟', 'a' => 'لا — أنت تدفع اشتراكاً ثابتاً وتحتفظ بكامل قيمة كل طلب، دون أي عمولة.'],
        ],
    ],

    'en' => [
        'region_name' => 'Syria',
        'name' => 'Syria',
        'eyebrow' => 'QR menu',
        'title' => 'QR Menu for Restaurants in Syria — WhatsApp Orders, Local Wallets',
        'meta' => 'Free QR code menu for restaurants and cafés in Syria: take WhatsApp orders and accept Syriatel Cash, MTN Cash and Sham Cash — with no commission.',
        'sub' => 'A QR digital menu, orders straight to WhatsApp, and the local wallets your customers already use — with no commission on your orders.',
        'intro' => [
            'Turn your restaurant, shop or café in Damascus, Aleppo or Latakia into a digital menu that runs from the customer’s phone: scan a QR, browse, and send the order over WhatsApp.',
        ],
        'cta_primary' => 'Start free',
        'cta_demo' => 'See a live menu',
        'demo_slug' => 'crave-demo',
        'gateways_title' => 'Local payments',
        'gateways' => ['Syriatel Cash', 'MTN Cash', 'Sham Cash', 'Cash on delivery'],
        'compliance' => [
            'title' => 'Your own channel, no middleman',
            'body' => 'Every order arrives as a tidy WhatsApp message with items, quantities and address — no app to install.',
            'points' => ['QR at the table, poster and bio link', 'Dine-in, pickup and delivery from one number', 'Bilingual Arabic/English menu'],
        ],
        'pain_title' => 'No commission on your orders',
        'pain' => 'Keep 100% of every order — a flat subscription, so busy nights stay yours.',
        'prices' => [
            'title' => 'Start today',
            'items' => ['Free plan, no card', 'Upgrade only when you grow'],
        ],
        'faq_title' => 'Syria restaurant QR menu FAQ',
        'faqs' => [
            ['q' => 'How do I make a QR menu for my restaurant or shop in Syria?', 'a' => 'Create a free account, add your items and prices, and you get a QR code and link that opens your menu on the customer’s phone in minutes.'],
            ['q' => 'Can customers pay with Syriatel Cash, MTN Cash or Sham Cash?', 'a' => 'Yes — you can present local wallets like Syriatel Cash, MTN Cash and Sham Cash alongside cash on delivery.'],
            ['q' => 'Is there commission on orders?', 'a' => 'No — you pay a flat subscription and keep the full value of every order.'],
        ],
    ],
];
