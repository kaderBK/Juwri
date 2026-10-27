<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * rev/mv-marketing — Cosmetics & beauty industry landing page (industries/cosmetic). EN + AR.
 * Positions the platform as an online store builder for cosmetics/beauty:
 * products with shade and size variants, brands, skincare routines and bundles,
 * live stock, and ship/pickup checkout. Live demo: cosmetic-demo.
 */

return [
    '_default' => 'en',

    'en' => [
        'eyebrow' => 'Beauty store',
        'title' => 'Online store builder for cosmetics & beauty',
        'meta_title' => 'Cosmetics online store builder — shades, brands & bundles',
        'meta' => 'Build a beauty online store with a QR catalog, shade and size variants, brands, skincare routine bundles, live stock, and ship or pickup checkout — no commission, live in minutes.',
        'sub' => 'A beauty catalog with shade and size variants, brands and skincare routines, a QR storefront your customers scan, and ship-to-address or pickup checkout — on a flat subscription, no commission per order.',
        'intro' => [
            'Sell makeup and skincare the way shoppers buy it. Give every product its shades, sizes and finishes as real variants — each with its own SKU, price and stock — and let customers browse by brand, build a routine, and check out for shipping or in-store pickup.',
            'The same engine that runs thousands of ordering storefronts, tuned for beauty: a QR catalog, cart and checkout, an order board, and inventory that hides a sold-out shade automatically.',
        ],
        'cta_primary' => 'Start free',
        'cta_demo' => 'See a live beauty demo',
        'demo_slug' => 'cosmetic-demo',
        'features_title' => 'Everything a beauty store needs',
        'features' => [
            ['icon' => '💄', 'title' => 'Shades, sizes & finishes', 'body' => 'Every shade, size and finish as a real variant — each with its own SKU, price and stock, picked before checkout.'],
            ['icon' => '🧴', 'title' => 'Skincare routines & bundles', 'body' => 'Package a cleanser, serum and moisturiser into a routine bundle so customers add the full set in one tap.'],
            ['icon' => '🔖', 'title' => 'Brands & categories', 'body' => 'Group products by brand and category — makeup, skincare, fragrance — so shoppers filter down fast.'],
            ['icon' => '📦', 'title' => 'Live inventory', 'body' => 'Track stock per product or per shade; a depleted shade removes its own buy button at checkout.'],
            ['icon' => '🚚', 'title' => 'Ship or pickup', 'body' => 'Ship to a customer address or offer store pickup and time slots — the checkout follows the fulfillment you choose.'],
            ['icon' => '🧾', 'title' => '0% commission', 'body' => 'Keep every sale on a flat subscription instead of losing a cut of each order to a marketplace.'],
        ],
        'faqs_title' => 'Beauty store questions',
        'faqs' => [
            ['q' => 'Can I sell products with different shades and sizes?', 'a' => 'Yes — each shade and size is a variant with its own SKU, price and stock, and shoppers pick the combination before adding to cart.'],
            ['q' => 'Can I sell skincare routines as a set?', 'a' => 'Yes. Bundle several products into a routine or gift set so customers add the whole set in one tap, with its own price.'],
            ['q' => 'Can customers get products shipped?', 'a' => 'Yes — beauty checkout supports ship-to-address as well as in-store pickup and pickup time slots.'],
        ],
        'cta_title' => 'Open your beauty store — live in minutes',
    ],

    'ar' => [
        'eyebrow' => 'متجر تجميل',
        'title' => 'منشئ متجر إلكتروني لمستحضرات التجميل والعناية',
        'meta_title' => 'منشئ متجر تجميل إلكتروني — درجات وعلامات وباقات',
        'meta' => 'أنشئ متجر تجميل إلكتروني بكتالوج QR ودرجات وأحجام متعددة وعلامات وباقات روتين للعناية ومخزون مباشر ودفع بالشحن أو الاستلام — بدون عمولة، وجاهز خلال دقائق.',
        'sub' => 'كتالوج تجميل بدرجات وأحجام متعددة وعلامات وروتين للعناية، وواجهة متجر بباركود QR يمسحها زبائنك، ودفع بالشحن إلى العنوان أو الاستلام — باشتراك ثابت وبدون عمولة على الطلبات.',
        'intro' => [
            'بِع المكياج ومنتجات العناية كما يشتريها الزبائن. امنح كل منتج درجاته وأحجامه ولمساته كمتغيّرات حقيقية — لكل منها SKU وسعر ومخزون — ودع الزبائن يتصفحون حسب العلامة ويبنون روتينهم ويكملون الدفع للشحن أو الاستلام.',
            'المحرّك نفسه الذي يشغّل آلاف متاجر الطلبات، مضبوط للتجميل: كتالوج QR وسلة ودفع، ولوحة طلبات، ومخزون يخفي الدرجة التي تنفد تلقائيًا.',
        ],
        'cta_primary' => 'ابدأ مجاناً',
        'cta_demo' => 'شاهد عرض تجميل مباشراً',
        'demo_slug' => 'cosmetic-demo',
        'features_title' => 'كل ما يحتاجه متجر التجميل',
        'features' => [
            ['icon' => '💄', 'title' => 'درجات وأحجام ولمسات', 'body' => 'كل درجة وحجم ولمسة كمتغيّر حقيقي — لكل منها SKU وسعر ومخزون، يختارها الزبون قبل الدفع.'],
            ['icon' => '🧴', 'title' => 'روتين عناية وباقات', 'body' => 'اجمع غسولاً وسيرومًا ومرطبًا في باقة روتين ليضيف الزبون المجموعة كاملة بلمسة واحدة.'],
            ['icon' => '🔖', 'title' => 'علامات وفئات', 'body' => 'جمّع المنتجات حسب العلامة والفئة — مكياج وعناية وعطور — ليصفّي الزبائن بسرعة.'],
            ['icon' => '📦', 'title' => 'مخزون مباشر', 'body' => 'تتبّع المخزون لكل منتج أو لكل درجة؛ والدرجة التي تنفد تخفي زر الشراء تلقائيًا.'],
            ['icon' => '🚚', 'title' => 'شحن أو استلام', 'body' => 'الشحن إلى عنوان الزبون أو الاستلام من المتجر مع مواعيد — يتبع الدفع طريقة التسليم التي تختارها.'],
            ['icon' => '🧾', 'title' => 'بدون عمولة', 'body' => 'احتفظ بكامل قيمة كل عملية بيع باشتراك ثابت بدلاً من خسارة حصة لكل طلب.'],
        ],
        'faqs_title' => 'أسئلة متجر التجميل',
        'faqs' => [
            ['q' => 'هل يمكنني بيع منتجات بدرجات وأحجام مختلفة؟', 'a' => 'نعم — كل درجة وحجم متغيّر له SKU وسعر ومخزون خاص، ويختار الزبون التركيبة قبل الإضافة للسلة.'],
            ['q' => 'هل يمكنني بيع روتين العناية كمجموعة؟', 'a' => 'نعم. اجمع عدة منتجات في باقة روتين أو مجموعة هدايا ليضيفها الزبون كاملة بلمسة واحدة وبسعر خاص بها.'],
            ['q' => 'هل يمكن شحن المنتجات للزبائن؟', 'a' => 'نعم — يدعم دفع التجميل الشحن إلى العنوان إضافة إلى الاستلام من المتجر ومواعيد الاستلام.'],
        ],
        'cta_title' => 'افتح متجر التجميل — جاهز خلال دقائق',
    ],
];
