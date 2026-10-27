<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * SEO Growth W3.6 — Tunisia country landing page (cc: tn). FR-first + AR.
 * Local payments (D17 / e-DINAR / card) + WhatsApp ordering. Plan price in TND
 * is linked from the pricing page rather than hard-coded here.
 */

return [
    '_default' => 'fr',

    'fr' => [
        'region_name' => 'Tunisie',
        'name' => 'Tunisie',
        'eyebrow' => 'Menu QR Code',
        'title' => 'Menu QR Code Tunisie — commandes WhatsApp, sans commission',
        'meta' => 'Menu QR code gratuit pour restaurants et cafés en Tunisie : commandes WhatsApp, paiement local (D17, e-DINAR, carte) et à la livraison — sans commission.',
        'sub' => 'Une carte digitale en QR code, des commandes directes sur WhatsApp et les paiements locaux que vos clients utilisent — sans commission sur vos commandes.',
        'intro' => [
            'Transformez votre restaurant ou café à Tunis, Sfax ou Sousse en une carte digitale qui fonctionne depuis le téléphone du client : il scanne un QR code, parcourt la carte et envoie sa commande sur WhatsApp.',
            'Aucune application à installer et aucune commission d’agrégateur.',
        ],
        'cta_primary' => 'Commencer gratuitement',
        'cta_demo' => 'Voir une carte en direct',
        'demo_slug' => 'crave-demo',
        'gateways_title' => 'Paiements',
        'gateways' => ['D17', 'e-DINAR', 'Carte', 'Paiement à la livraison'],
        'compliance' => [
            'title' => 'Votre canal, votre marge',
            'body' => 'Chaque commande arrive sous forme de message WhatsApp clair avec les articles, les quantités et l’adresse.',
            'points' => [
                'QR code à table, sur l’affiche et dans la bio.',
                'Sur place, à emporter et livraison depuis un seul numéro.',
                'Carte bilingue français / arabe automatiquement.',
            ],
        ],
        'pain_title' => 'Sans commission sur vos commandes',
        'pain' => 'Les applis de livraison prélèvent une part de chaque commande. Sur votre propre canal, vous gardez 100 % pour un abonnement fixe.',
        'prices' => [
            'title' => 'Commencez aujourd’hui',
            'items' => ['Offre gratuite pour démarrer, sans carte.', 'Voir la page des tarifs pour le prix en TND.'],
        ],
        'faq_title' => 'FAQ — Menu QR code en Tunisie',
        'faqs' => [
            ['q' => 'Comment créer un menu QR code pour mon restaurant en Tunisie ?', 'a' => 'Créez un compte gratuit, ajoutez vos articles et vos prix, et vous obtenez un QR code et un lien qui ouvrent votre carte sur le téléphone du client en quelques minutes.'],
            ['q' => 'Les paiements locaux (D17, e-DINAR) sont-ils pris en charge ?', 'a' => 'Oui, vous pouvez proposer les paiements locaux comme D17 et e-DINAR, aux côtés du paiement à la livraison.'],
            ['q' => 'Y a-t-il une commission sur les commandes ?', 'a' => 'Non — un abonnement fixe, et vous gardez la totalité de chaque commande.'],
        ],
    ],

    'ar' => [
        'region_name' => 'تونس',
        'name' => 'تونس',
        'eyebrow' => 'منيو الكتروني',
        'title' => 'منيو الكتروني بالباركود في تونس — طلبات واتساب بدون عمولة',
        'meta' => 'منيو الكتروني QR مجاني للمطاعم والمقاهي في تونس: طلبات واتساب، دفع محلي (D17 و e-DINAR والبطاقة) والدفع عند الاستلام — بدون عمولة.',
        'sub' => 'قائمة رقمية بباركود QR، طلبات مباشرة على واتساب، ووسائل الدفع المحلية — دون عمولة على طلباتك.',
        'intro' => [
            'حوّل مطعمك أو مقهاك في تونس أو صفاقس أو سوسة إلى منيو الكتروني يعمل من هاتف الزبون: مسح رمز QR، تصفّح القائمة، وإرسال الطلب على واتساب.',
        ],
        'cta_primary' => 'ابدأ مجاناً',
        'cta_demo' => 'شاهد قائمة مباشرة',
        'demo_slug' => 'crave-demo',
        'gateways_title' => 'وسائل الدفع',
        'gateways' => ['D17', 'e-DINAR', 'بطاقة', 'الدفع عند الاستلام'],
        'compliance' => [
            'title' => 'قناتك الخاصة، لا وسيط',
            'body' => 'يصلك كل طلب كرسالة واتساب منظمة مع الأصناف والكميات والعنوان.',
            'points' => ['رمز QR على الطاولة والملصق والحساب.', 'طلبات داخل المطعم والاستلام والتوصيل من رقم واحد.', 'قائمة بالفرنسية والعربية تلقائياً.'],
        ],
        'pain_title' => 'بدون عمولة على طلباتك',
        'pain' => 'تطبيقات التوصيل تقتطع حصة من كل طلب. مع قناتك الخاصة تحتفظ بـ 100% مقابل اشتراك ثابت.',
        'prices' => [
            'title' => 'ابدأ اليوم',
            'items' => ['خطة مجانية للبدء دون بطاقة.', 'راجع صفحة الأسعار للسعر بالدينار.'],
        ],
        'faq_title' => 'أسئلة شائعة — المنيو الإلكتروني في تونس',
        'faqs' => [
            ['q' => 'كيف أنشئ منيو الكتروني بالباركود لمطعمي في تونس؟', 'a' => 'أنشئ حساباً مجاناً، أضف أصنافك وأسعارك، فتحصل على رمز QR ورابط يفتح قائمتك على هاتف الزبون خلال دقائق.'],
            ['q' => 'هل الدفع المحلي (D17 و e-DINAR) مدعوم؟', 'a' => 'نعم، يمكنك عرض وسائل الدفع المحلية مثل D17 و e-DINAR إلى جانب الدفع عند الاستلام.'],
        ],
    ],
];
