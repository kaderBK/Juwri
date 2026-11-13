<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * rev/mv-marketing — Clothing & apparel industry landing page (industries/clothing). EN + AR.
 * Positions the platform as an online store builder for clothing/apparel:
 * products with size and colour variants, size guides, lookbooks,
 * live stock, and ship/pickup checkout. Live demo: clothing-demo.
 */

return [
    '_default' => 'en',

    'en' => [
        'eyebrow' => 'Fashion store',
        'title' => 'Online store builder for clothing & apparel',
        'meta_title' => 'Clothing online store builder — sizes, colours & lookbooks',
        'meta' => 'Build a clothing online store with a QR catalog, size and colour variants, size guides, lookbooks, live stock, and ship or pickup checkout — no commission, live in minutes.',
        'sub' => 'A fashion catalog with size and colour variants, size guides and lookbooks, a QR storefront your shoppers scan, and ship-to-address or pickup checkout — on a flat subscription, no commission per order.',
        'intro' => [
            'Sell clothing the way people shop it. Give every piece its sizes and colours as real variants — each with its own SKU, price and stock — and let shoppers browse lookbooks, check the size guide, and check out for shipping or in-store pickup.',
            'The same engine that runs thousands of ordering storefronts, tuned for apparel: a QR catalog, cart and checkout, an order board, and inventory that hides a sold-out size automatically.',
        ],
        'cta_primary' => 'Start free',
        'cta_demo' => 'See a live clothing demo',
        'demo_slug' => 'clothing-demo',
        'features_title' => 'Everything a clothing store needs',
        'features' => [
            ['icon' => '👕', 'title' => 'Size & colour variants', 'body' => 'Every size and colour as a real variant — each with its own SKU, price and stock, picked before checkout.'],
            ['icon' => '📏', 'title' => 'Size guides', 'body' => 'Attach a size chart to each product so shoppers pick the right fit and you cut returns.'],
            ['icon' => '📸', 'title' => 'Lookbooks & galleries', 'body' => 'Show every piece with multiple photos and styled lookbooks so shoppers see the full look.'],
            ['icon' => '📦', 'title' => 'Live inventory', 'body' => 'Track stock per size and colour; a depleted size removes its own buy button at checkout.'],
            ['icon' => '🚚', 'title' => 'Ship or pickup', 'body' => 'Ship to a customer address or offer store pickup and time slots — the checkout follows the fulfillment you choose.'],
            ['icon' => '🧾', 'title' => '0% commission', 'body' => 'Keep every sale on a flat subscription instead of losing a cut of each order to a marketplace.'],
        ],
        'faqs_title' => 'Clothing store questions',
        'faqs' => [
            ['q' => 'Can I sell items in multiple sizes and colours?', 'a' => 'Yes — each size and colour is a variant with its own SKU, price and stock, and shoppers pick the combination before adding to cart.'],
            ['q' => 'Can I show a size guide?', 'a' => 'Yes. Attach a size chart to each product so shoppers pick the right fit, which cuts returns and exchanges.'],
            ['q' => 'Can customers get orders shipped?', 'a' => 'Yes — clothing checkout supports ship-to-address as well as in-store pickup and pickup time slots.'],
        ],
        'cta_title' => 'Open your clothing store — live in minutes',
    ],

    'ar' => [
        'eyebrow' => 'متجر أزياء',
        'title' => 'منشئ متجر إلكتروني للملابس والأزياء',
        'meta_title' => 'منشئ متجر ملابس إلكتروني — مقاسات وألوان ولوك بوك',
        'meta' => 'أنشئ متجر ملابس إلكتروني بكتالوج QR ومقاسات وألوان متعددة ودليل مقاسات ولوك بوك ومخزون مباشر ودفع بالشحن أو الاستلام — بدون عمولة، وجاهز خلال دقائق.',
        'sub' => 'كتالوج أزياء بمقاسات وألوان متعددة ودليل مقاسات ولوك بوك، وواجهة متجر بباركود QR يمسحها زبائنك، ودفع بالشحن إلى العنوان أو الاستلام — باشتراك ثابت وبدون عمولة على الطلبات.',
        'intro' => [
            'بِع الملابس كما يتسوّقها الناس. امنح كل قطعة مقاساتها وألوانها كمتغيّرات حقيقية — لكل منها SKU وسعر ومخزون — ودع الزبائن يتصفحون اللوك بوك ويطّلعون على دليل المقاسات ويكملون الدفع للشحن أو الاستلام.',
            'المحرّك نفسه الذي يشغّل آلاف متاجر الطلبات، مضبوط للأزياء: كتالوج QR وسلة ودفع، ولوحة طلبات، ومخزون يخفي المقاس الذي ينفد تلقائيًا.',
        ],
        'cta_primary' => 'ابدأ مجاناً',
        'cta_demo' => 'شاهد عرض ملابس مباشراً',
        'demo_slug' => 'clothing-demo',
        'features_title' => 'كل ما يحتاجه متجر الملابس',
        'features' => [
            ['icon' => '👕', 'title' => 'مقاسات وألوان', 'body' => 'كل مقاس ولون كمتغيّر حقيقي — لكل منها SKU وسعر ومخزون، يختارها الزبون قبل الدفع.'],
            ['icon' => '📏', 'title' => 'دليل المقاسات', 'body' => 'أرفق جدول مقاسات بكل منتج ليختار الزبون المقاس الصحيح فتقلّ الإرجاعات.'],
            ['icon' => '📸', 'title' => 'لوك بوك ومعارض', 'body' => 'اعرض كل قطعة بعدة صور ولوك بوك منسّق ليرى الزبائن الإطلالة كاملة.'],
            ['icon' => '📦', 'title' => 'مخزون مباشر', 'body' => 'تتبّع المخزون لكل مقاس ولون؛ والمقاس الذي ينفد يخفي زر الشراء تلقائيًا.'],
            ['icon' => '🚚', 'title' => 'شحن أو استلام', 'body' => 'الشحن إلى عنوان الزبون أو الاستلام من المتجر مع مواعيد — يتبع الدفع طريقة التسليم التي تختارها.'],
            ['icon' => '🧾', 'title' => 'بدون عمولة', 'body' => 'احتفظ بكامل قيمة كل عملية بيع باشتراك ثابت بدلاً من خسارة حصة لكل طلب.'],
        ],
        'faqs_title' => 'أسئلة متجر الملابس',
        'faqs' => [
            ['q' => 'هل يمكنني بيع قطع بمقاسات وألوان متعددة؟', 'a' => 'نعم — كل مقاس ولون متغيّر له SKU وسعر ومخزون خاص، ويختار الزبون التركيبة قبل الإضافة للسلة.'],
            ['q' => 'هل يمكنني عرض دليل مقاسات؟', 'a' => 'نعم. أرفق جدول مقاسات بكل منتج ليختار الزبون المقاس الصحيح، ما يقلّل الإرجاع والاستبدال.'],
            ['q' => 'هل يمكن شحن الطلبات للزبائن؟', 'a' => 'نعم — يدعم دفع الملابس الشحن إلى العنوان إضافة إلى الاستلام من المتجر ومواعيد الاستلام.'],
        ],
        'cta_title' => 'افتح متجر الملابس — جاهز خلال دقائق',
    ],
];
