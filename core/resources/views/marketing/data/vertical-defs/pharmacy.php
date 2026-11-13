<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * rev/mv-marketing — OTC pharmacy industry landing page (industries/pharmacy). EN + AR.
 * Positions the platform as an online store builder for over-the-counter pharmacy:
 * OTC catalog ONLY (no prescriptions / Rx), batch numbers, expiry dates,
 * an age-check gate at checkout, live stock, and ship/pickup. Live demo: pharmacy-demo.
 */

return [
    '_default' => 'en',

    'en' => [
        'eyebrow' => 'OTC pharmacy',
        'title' => 'Online store builder for OTC pharmacy',
        'meta_title' => 'OTC pharmacy online store builder — batches, expiry & age check',
        'meta' => 'Build an over-the-counter pharmacy store with a QR catalog, batch numbers and expiry tracking, an age-check gate at checkout, live stock and ship or pickup — no commission, live in minutes.',
        'sub' => 'An over-the-counter catalog with batch numbers and expiry dates, an age-check gate at checkout, live stock, and ship-to-address or pickup — on a flat subscription, no commission per order.',
        'intro' => [
            'Sell your over-the-counter range online with care. List OTC products with their batch numbers and expiry dates, gate age-restricted items behind a checkout age check, and let customers order for shipping or in-store pickup.',
            'The same engine that runs thousands of ordering storefronts, tuned for OTC retail — no prescriptions, no Rx handling: a QR catalog, cart and checkout, an order board, and inventory that hides an out-of-stock or expired item automatically.',
        ],
        'cta_primary' => 'Start free',
        'cta_demo' => 'See a live pharmacy demo',
        'demo_slug' => 'pharmacy-demo',
        'features_title' => 'Everything an OTC store needs',
        'features' => [
            ['icon' => '💊', 'title' => 'OTC catalog only', 'body' => 'List your over-the-counter range — no prescriptions and no Rx handling — with clear categories shoppers browse fast.'],
            ['icon' => '🔢', 'title' => 'Batch numbers', 'body' => 'Record a batch or lot number against each product so you can trace and reconcile every unit sold.'],
            ['icon' => '📅', 'title' => 'Expiry tracking', 'body' => 'Track expiry dates per batch; an expired item removes its own buy button so it never reaches a customer.'],
            ['icon' => '🔞', 'title' => 'Age-check gate', 'body' => 'Age-restricted items ask the shopper to confirm eligibility at checkout before the order goes through.'],
            ['icon' => '🚚', 'title' => 'Ship or pickup', 'body' => 'Ship to a customer address or offer counter pickup and time slots — the checkout follows the fulfillment you choose.'],
            ['icon' => '🧾', 'title' => '0% commission', 'body' => 'Keep every sale on a flat subscription instead of losing a cut of each order to a marketplace.'],
        ],
        'faqs_title' => 'OTC store questions',
        'faqs' => [
            ['q' => 'Does this handle prescriptions?', 'a' => 'No — the store is over-the-counter only. It sells OTC products and never handles prescriptions or Rx dispensing.'],
            ['q' => 'Can I track batch numbers and expiry?', 'a' => 'Yes. Record a batch number and expiry date per product, and an expired item automatically hides its buy button so it never reaches a customer.'],
            ['q' => 'Can I restrict age-limited products?', 'a' => 'Yes — age-restricted items present an age-check gate at checkout so the shopper confirms eligibility before the order is placed.'],
        ],
        'cta_title' => 'Open your OTC store — live in minutes',
    ],

    'ar' => [
        'eyebrow' => 'صيدلية OTC',
        'title' => 'منشئ متجر إلكتروني للأدوية بدون وصفة (OTC)',
        'meta_title' => 'منشئ متجر OTC إلكتروني — تشغيلات وصلاحية وتحقق عمر',
        'meta' => 'أنشئ متجر أدوية بدون وصفة (OTC) بكتالوج QR وأرقام تشغيلات وتواريخ صلاحية وبوابة تحقق من العمر عند الدفع ومخزون مباشر وشحن أو استلام — بدون عمولة، وجاهز خلال دقائق.',
        'sub' => 'كتالوج للأدوية بدون وصفة بأرقام تشغيلات وتواريخ صلاحية، وبوابة تحقق من العمر عند الدفع، ومخزون مباشر، وشحن إلى العنوان أو استلام — باشتراك ثابت وبدون عمولة على الطلبات.',
        'intro' => [
            'بِع منتجاتك المتاحة بدون وصفة عبر الإنترنت بعناية. أدرج منتجات OTC بأرقام تشغيلاتها وتواريخ صلاحيتها، واضبط الأصناف المقيّدة بالعمر خلف بوابة تحقق عند الدفع، ودع الزبائن يطلبون للشحن أو الاستلام.',
            'المحرّك نفسه الذي يشغّل آلاف متاجر الطلبات، مضبوط لبيع OTC — دون وصفات ودون التعامل مع Rx: كتالوج QR وسلة ودفع، ولوحة طلبات، ومخزون يخفي الصنف الذي ينفد أو تنتهي صلاحيته تلقائيًا.',
        ],
        'cta_primary' => 'ابدأ مجاناً',
        'cta_demo' => 'شاهد عرض صيدلية مباشراً',
        'demo_slug' => 'pharmacy-demo',
        'features_title' => 'كل ما يحتاجه متجر OTC',
        'features' => [
            ['icon' => '💊', 'title' => 'كتالوج OTC فقط', 'body' => 'أدرج منتجاتك بدون وصفة — دون وصفات ودون التعامل مع Rx — بفئات واضحة يتصفحها الزبائن بسرعة.'],
            ['icon' => '🔢', 'title' => 'أرقام التشغيلات', 'body' => 'سجّل رقم التشغيلة لكل منتج لتتمكن من تتبّع كل وحدة مباعة ومطابقتها.'],
            ['icon' => '📅', 'title' => 'تتبّع الصلاحية', 'body' => 'تتبّع تواريخ الصلاحية لكل تشغيلة؛ والصنف المنتهي يخفي زر الشراء فلا يصل إلى زبون.'],
            ['icon' => '🔞', 'title' => 'بوابة تحقق العمر', 'body' => 'الأصناف المقيّدة بالعمر تطلب من الزبون تأكيد أهليته عند الدفع قبل إتمام الطلب.'],
            ['icon' => '🚚', 'title' => 'شحن أو استلام', 'body' => 'الشحن إلى عنوان الزبون أو الاستلام من المتجر مع مواعيد — يتبع الدفع طريقة التسليم التي تختارها.'],
            ['icon' => '🧾', 'title' => 'بدون عمولة', 'body' => 'احتفظ بكامل قيمة كل عملية بيع باشتراك ثابت بدلاً من خسارة حصة لكل طلب.'],
        ],
        'faqs_title' => 'أسئلة متجر OTC',
        'faqs' => [
            ['q' => 'هل يتعامل المتجر مع الوصفات الطبية؟', 'a' => 'لا — المتجر للأدوية بدون وصفة فقط. يبيع منتجات OTC ولا يتعامل إطلاقًا مع الوصفات أو صرف Rx.'],
            ['q' => 'هل يمكنني تتبّع أرقام التشغيلات والصلاحية؟', 'a' => 'نعم. سجّل رقم تشغيلة وتاريخ صلاحية لكل منتج، والصنف المنتهي يخفي زر الشراء تلقائيًا فلا يصل إلى زبون.'],
            ['q' => 'هل يمكنني تقييد المنتجات حسب العمر؟', 'a' => 'نعم — تعرض الأصناف المقيّدة بالعمر بوابة تحقق عند الدفع ليؤكد الزبون أهليته قبل إتمام الطلب.'],
        ],
        'cta_title' => 'افتح متجر OTC — جاهز خلال دقائق',
    ],
];
