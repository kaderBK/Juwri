<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * rev/mv-marketing — Retail industry landing page (industries/retail). EN + AR.
 * Positions the platform as an online store builder for retail/general goods:
 * products with variants, stock, and ship/pickup checkout. Live demo: retail-demo.
 */

return [
    '_default' => 'en',

    'en' => [
        'eyebrow' => 'Online store',
        'title' => 'Online store builder for retail',
        'meta_title' => 'Retail online store builder — products, variants & stock',
        'meta' => 'Build a retail online store with a QR catalog, product variants and SKUs, live stock, and ship or pickup checkout — no commission, live in minutes.',
        'sub' => 'A product catalog with variants, SKUs and live stock, a QR storefront your shoppers scan, and ship-to-address or pickup checkout — on a flat subscription, no commission per order.',
        'intro' => [
            'Sell more than a menu. Give every product its sizes, colours and options as real variants — each with its own SKU, price and stock — and let shoppers browse, filter by brand, and check out for shipping or in-store pickup.',
            'The same engine that runs thousands of ordering storefronts, tuned for goods: a QR catalog, cart and checkout, an order board, and inventory that hides a sold-out item automatically.',
        ],
        'cta_primary' => 'Start free',
        'cta_demo' => 'See a live retail demo',
        'demo_slug' => 'retail-demo',
        'features_title' => 'Everything a retail store needs',
        'features' => [
            ['icon' => '🏷️', 'title' => 'Products, variants & SKUs', 'body' => 'Sizes, colours and options as real variants — each with its own SKU, barcode, price and stock.'],
            ['icon' => '📦', 'title' => 'Live inventory', 'body' => 'Track stock per product or per variant; a depleted item removes its own buy button at checkout.'],
            ['icon' => '🚚', 'title' => 'Ship or pickup', 'body' => 'Ship to a customer address or offer store pickup and time slots — the checkout follows the fulfillment you choose.'],
            ['icon' => '🔖', 'title' => 'Brands & categories', 'body' => 'Group products by brand and category so shoppers filter down to what they want fast.'],
            ['icon' => '📱', 'title' => 'QR storefront', 'body' => 'A scannable QR catalog for your shelf, window or receipt — shoppers browse and buy from their phone.'],
            ['icon' => '🧾', 'title' => '0% commission', 'body' => 'Keep every sale on a flat subscription instead of losing a cut of each order to a marketplace.'],
        ],
        'faqs_title' => 'Retail store questions',
        'faqs' => [
            ['q' => 'Can I sell products with sizes and colours?', 'a' => 'Yes — each combination is a variant with its own SKU, price, barcode and stock, and shoppers pick the combination before adding to cart.'],
            ['q' => 'Does it track inventory?', 'a' => 'Yes. Stock is tracked per product or per variant, and a sold-out item automatically hides its buy button so you never oversell.'],
            ['q' => 'Can customers get products shipped?', 'a' => 'Yes — retail checkout supports ship-to-address as well as in-store pickup and pickup time slots.'],
        ],
        'cta_title' => 'Open your retail store — live in minutes',
    ],

    'ar' => [
        'eyebrow' => 'متجر إلكتروني',
        'title' => 'منشئ متجر إلكتروني للتجزئة',
        'meta_title' => 'منشئ متجر تجزئة إلكتروني — منتجات وخيارات ومخزون',
        'meta' => 'أنشئ متجر تجزئة إلكتروني بكتالوج QR وخيارات منتجات وأكواد SKU ومخزون مباشر ودفع بالشحن أو الاستلام — بدون عمولة، وجاهز خلال دقائق.',
        'sub' => 'كتالوج منتجات بخيارات وأكواد SKU ومخزون مباشر، وواجهة متجر بباركود QR يمسحها زبائنك، ودفع بالشحن إلى العنوان أو الاستلام — باشتراك ثابت وبدون عمولة على الطلبات.',
        'intro' => [
            'بِع أكثر من مجرد قائمة. امنح كل منتج مقاساته وألوانه وخياراته كمتغيّرات حقيقية — لكل منها SKU وسعر ومخزون — ودع الزبائن يتصفحون ويصفّون حسب العلامة ويكملون الدفع للشحن أو الاستلام.',
            'المحرّك نفسه الذي يشغّل آلاف متاجر الطلبات، مضبوط للسلع: كتالوج QR وسلة ودفع، ولوحة طلبات، ومخزون يخفي الصنف الذي ينفد تلقائيًا.',
        ],
        'cta_primary' => 'ابدأ مجاناً',
        'cta_demo' => 'شاهد عرض تجزئة مباشراً',
        'demo_slug' => 'retail-demo',
        'features_title' => 'كل ما يحتاجه متجر التجزئة',
        'features' => [
            ['icon' => '🏷️', 'title' => 'منتجات وخيارات وأكواد SKU', 'body' => 'مقاسات وألوان وخيارات كمتغيّرات حقيقية — لكل منها SKU وباركود وسعر ومخزون.'],
            ['icon' => '📦', 'title' => 'مخزون مباشر', 'body' => 'تتبّع المخزون لكل منتج أو لكل خيار؛ والصنف الذي ينفد يخفي زر الشراء تلقائيًا.'],
            ['icon' => '🚚', 'title' => 'شحن أو استلام', 'body' => 'الشحن إلى عنوان الزبون أو الاستلام من المتجر مع مواعيد — يتبع الدفع طريقة التسليم التي تختارها.'],
            ['icon' => '🔖', 'title' => 'علامات وفئات', 'body' => 'جمّع المنتجات حسب العلامة والفئة ليصل الزبائن لما يريدونه بسرعة.'],
            ['icon' => '📱', 'title' => 'واجهة QR', 'body' => 'كتالوج QR للرف أو الواجهة أو الإيصال — يتصفح الزبائن ويشترون من هواتفهم.'],
            ['icon' => '🧾', 'title' => 'بدون عمولة', 'body' => 'احتفظ بكامل قيمة كل عملية بيع باشتراك ثابت بدلاً من خسارة حصة لكل طلب.'],
        ],
        'faqs_title' => 'أسئلة متجر التجزئة',
        'faqs' => [
            ['q' => 'هل يمكنني بيع منتجات بمقاسات وألوان؟', 'a' => 'نعم — كل تركيبة هي متغيّر له SKU وسعر وباركود ومخزون خاص، ويختار الزبون التركيبة قبل الإضافة للسلة.'],
            ['q' => 'هل يتتبّع المخزون؟', 'a' => 'نعم. يُتتبّع المخزون لكل منتج أو لكل خيار، والصنف الذي ينفد يخفي زر الشراء تلقائيًا فلا تبيع أكثر من المتاح.'],
            ['q' => 'هل يمكن شحن المنتجات للزبائن؟', 'a' => 'نعم — يدعم دفع التجزئة الشحن إلى العنوان إضافة إلى الاستلام من المتجر ومواعيد الاستلام.'],
        ],
        'cta_title' => 'افتح متجر التجزئة — جاهز خلال دقائق',
    ],
];
