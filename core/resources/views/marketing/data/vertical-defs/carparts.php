<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * rev/mv-marketing — Auto parts industry landing page (industries/carparts). EN + AR.
 * Positions the platform as an online store builder for auto parts:
 * vehicle fitment lookup by year/make/model, OEM cross-references, SKUs and
 * live stock, and ship/pickup checkout. Live demo: carparts-demo.
 */

return [
    '_default' => 'en',

    'en' => [
        'eyebrow' => 'Parts store',
        'title' => 'Online store builder for auto parts',
        'meta_title' => 'Auto parts online store builder — fitment, OEM & stock',
        'meta' => 'Build an auto parts online store with a QR catalog, year/make/model fitment lookup, OEM cross-references, SKUs, live stock, and ship or pickup checkout — no commission, live in minutes.',
        'sub' => 'A parts catalog with year/make/model fitment, OEM cross-references, SKUs and live stock, a QR storefront your customers scan, and ship-to-address or pickup checkout — on a flat subscription, no commission per order.',
        'intro' => [
            'Sell the right part the first time. Let customers narrow by year, make and model, match an OEM number to your SKU, and check the exact stock — then check out for shipping or in-store pickup.',
            'The same engine that runs thousands of ordering storefronts, tuned for parts: a QR catalog, cart and checkout, an order board, and inventory that hides an out-of-stock SKU automatically.',
        ],
        'cta_primary' => 'Start free',
        'cta_demo' => 'See a live parts demo',
        'demo_slug' => 'carparts-demo',
        'features_title' => 'Everything a parts store needs',
        'features' => [
            ['icon' => '🚗', 'title' => 'Fitment lookup', 'body' => 'Customers filter by year, make and model so they only see parts that fit their vehicle.'],
            ['icon' => '🔧', 'title' => 'OEM cross-references', 'body' => 'Match an OEM or aftermarket number to your SKU so buyers confirm the exact part fast.'],
            ['icon' => '🏷️', 'title' => 'SKUs & barcodes', 'body' => 'Every part carries its own SKU, barcode, price and stock for clean picking and reorders.'],
            ['icon' => '📦', 'title' => 'Live inventory', 'body' => 'Track stock per SKU; an out-of-stock part removes its own buy button at checkout.'],
            ['icon' => '🚚', 'title' => 'Ship or pickup', 'body' => 'Ship to a customer address or offer counter pickup and time slots — the checkout follows the fulfillment you choose.'],
            ['icon' => '🧾', 'title' => '0% commission', 'body' => 'Keep every sale on a flat subscription instead of losing a cut of each order to a marketplace.'],
        ],
        'faqs_title' => 'Parts store questions',
        'faqs' => [
            ['q' => 'Can customers find parts that fit their vehicle?', 'a' => 'Yes — a year/make/model fitment lookup narrows the catalog so shoppers only see parts that fit their car.'],
            ['q' => 'Can I list OEM cross-references?', 'a' => 'Yes. Map an OEM or aftermarket number to your SKU so buyers confirm the exact part before adding to cart.'],
            ['q' => 'Can customers get parts shipped?', 'a' => 'Yes — parts checkout supports ship-to-address as well as counter pickup and pickup time slots.'],
        ],
        'cta_title' => 'Open your parts store — live in minutes',
    ],

    'ar' => [
        'eyebrow' => 'متجر قطع غيار',
        'title' => 'منشئ متجر إلكتروني لقطع غيار السيارات',
        'meta_title' => 'منشئ متجر قطع غيار إلكتروني — توافق وOEM ومخزون',
        'meta' => 'أنشئ متجر قطع غيار إلكتروني بكتالوج QR وبحث توافق حسب السنة والصنع والطراز ومراجع OEM وأكواد SKU ومخزون مباشر ودفع بالشحن أو الاستلام — بدون عمولة، وجاهز خلال دقائق.',
        'sub' => 'كتالوج قطع غيار بتوافق حسب السنة والصنع والطراز ومراجع OEM وأكواد SKU ومخزون مباشر، وواجهة متجر بباركود QR يمسحها زبائنك، ودفع بالشحن إلى العنوان أو الاستلام — باشتراك ثابت وبدون عمولة على الطلبات.',
        'intro' => [
            'بِع القطعة الصحيحة من أول مرة. دع الزبائن يصفّون حسب السنة والصنع والطراز، ويطابقون رقم OEM مع كود SKU لديك، ويتحققون من المخزون بدقة — ثم يكملون الدفع للشحن أو الاستلام.',
            'المحرّك نفسه الذي يشغّل آلاف متاجر الطلبات، مضبوط لقطع الغيار: كتالوج QR وسلة ودفع، ولوحة طلبات، ومخزون يخفي كود SKU الذي ينفد تلقائيًا.',
        ],
        'cta_primary' => 'ابدأ مجاناً',
        'cta_demo' => 'شاهد عرض قطع غيار مباشراً',
        'demo_slug' => 'carparts-demo',
        'features_title' => 'كل ما يحتاجه متجر قطع الغيار',
        'features' => [
            ['icon' => '🚗', 'title' => 'بحث التوافق', 'body' => 'يصفّي الزبائن حسب السنة والصنع والطراز فلا يظهر لهم إلا ما يناسب سيارتهم.'],
            ['icon' => '🔧', 'title' => 'مراجع OEM', 'body' => 'طابِق رقم OEM أو البديل التجاري مع كود SKU لديك ليؤكد الزبون القطعة الصحيحة بسرعة.'],
            ['icon' => '🏷️', 'title' => 'أكواد SKU وباركود', 'body' => 'كل قطعة لها SKU وباركود وسعر ومخزون خاص لتسهيل التجهيز وإعادة الطلب.'],
            ['icon' => '📦', 'title' => 'مخزون مباشر', 'body' => 'تتبّع المخزون لكل SKU؛ والقطعة التي تنفد تخفي زر الشراء تلقائيًا.'],
            ['icon' => '🚚', 'title' => 'شحن أو استلام', 'body' => 'الشحن إلى عنوان الزبون أو الاستلام من المتجر مع مواعيد — يتبع الدفع طريقة التسليم التي تختارها.'],
            ['icon' => '🧾', 'title' => 'بدون عمولة', 'body' => 'احتفظ بكامل قيمة كل عملية بيع باشتراك ثابت بدلاً من خسارة حصة لكل طلب.'],
        ],
        'faqs_title' => 'أسئلة متجر قطع الغيار',
        'faqs' => [
            ['q' => 'هل يستطيع الزبائن إيجاد القطع المناسبة لسياراتهم؟', 'a' => 'نعم — بحث التوافق حسب السنة والصنع والطراز يضيّق الكتالوج فلا يرى الزبون إلا ما يناسب سيارته.'],
            ['q' => 'هل يمكنني إدراج مراجع OEM؟', 'a' => 'نعم. اربط رقم OEM أو البديل التجاري بكود SKU لديك ليؤكد الزبون القطعة الصحيحة قبل الإضافة للسلة.'],
            ['q' => 'هل يمكن شحن القطع للزبائن؟', 'a' => 'نعم — يدعم دفع قطع الغيار الشحن إلى العنوان إضافة إلى الاستلام من المتجر ومواعيد الاستلام.'],
        ],
        'cta_title' => 'افتح متجر قطع الغيار — جاهز خلال دقائق',
    ],
];
