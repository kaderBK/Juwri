<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * SEO Growth W3.6 — Morocco country landing page (cc: ma). FR-first + AR.
 * CMI (card payments) + WhatsApp ordering. Plan prices are not hard-coded here
 * (they vary / weren't verifiable at build) — the page links to the pricing
 * page for the exact MAD figure rather than inventing one.
 */

return [
    '_default' => 'fr',

    'fr' => [
        'region_name' => 'Maroc',
        'name' => 'Maroc',
        'eyebrow' => 'Menu QR Code',
        'title' => 'Menu QR Code Maroc — CMI & paiement WhatsApp',
        'meta' => 'Menu QR code gratuit pour restaurants et cafés au Maroc : commandes WhatsApp, paiement par carte (CMI) et à la livraison — sans commission sur vos commandes.',
        'sub' => 'Une carte digitale en QR code, des commandes directes sur WhatsApp et le paiement par carte via CMI — le tout sans commission sur vos commandes.',
        'intro' => [
            'Transformez votre restaurant ou café à Casablanca, Rabat ou Marrakech en une carte digitale qui fonctionne depuis le téléphone du client : il scanne un QR code, parcourt la carte et envoie sa commande sur WhatsApp.',
            'Aucune application à installer et aucune commission d’agrégateur — la carte, la commande et la relation client restent à vous.',
        ],
        'cta_primary' => 'Commencer gratuitement',
        'cta_demo' => 'Voir une carte en direct',
        'demo_slug' => 'crave-demo',
        'gateways_title' => 'Paiements',
        'gateways' => ['Carte (CMI)', 'Paiement à la livraison', 'Virement'],
        'compliance' => [
            'title' => 'Votre canal, votre marge',
            'body' => 'Chaque commande arrive sous forme de message WhatsApp clair avec les articles, les quantités et l’adresse — sans intermédiaire qui prend une part.',
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
            'items' => ['Offre gratuite pour démarrer, sans carte.', 'Voir la page des tarifs pour le prix en MAD.'],
        ],
        'faq_title' => 'FAQ — Menu QR code au Maroc',
        'faqs' => [
            ['q' => 'Comment créer un menu QR code pour mon restaurant au Maroc ?', 'a' => 'Créez un compte gratuit, ajoutez vos articles et vos prix, et vous obtenez un QR code et un lien qui ouvrent votre carte sur le téléphone du client en quelques minutes.'],
            ['q' => 'Le paiement par carte CMI est-il pris en charge ?', 'a' => 'Oui, vous pouvez proposer le paiement par carte via CMI, aux côtés du paiement à la livraison.'],
            ['q' => 'Y a-t-il une commission sur les commandes ?', 'a' => 'Non — un abonnement fixe, et vous gardez la totalité de chaque commande.'],
        ],
    ],

    'ar' => [
        'region_name' => 'المغرب',
        'name' => 'المغرب',
        'eyebrow' => 'منيو الكتروني',
        'title' => 'منيو الكتروني بالباركود في المغرب — CMI ودفع واتساب',
        'meta' => 'منيو الكتروني QR مجاني للمطاعم والمقاهي في المغرب: طلبات واتساب، دفع بالبطاقة عبر CMI والدفع عند الاستلام — بدون عمولة.',
        'sub' => 'قائمة رقمية بباركود QR، طلبات مباشرة على واتساب، ودفع بالبطاقة عبر CMI — دون عمولة على طلباتك.',
        'intro' => [
            'حوّل مطعمك أو مقهاك في الدار البيضاء أو الرباط أو مراكش إلى منيو الكتروني يعمل من هاتف الزبون: مسح رمز QR، تصفّح القائمة، وإرسال الطلب على واتساب.',
        ],
        'cta_primary' => 'ابدأ مجاناً',
        'cta_demo' => 'شاهد قائمة مباشرة',
        'demo_slug' => 'crave-demo',
        'gateways_title' => 'وسائل الدفع',
        'gateways' => ['بطاقة (CMI)', 'الدفع عند الاستلام', 'تحويل'],
        'compliance' => [
            'title' => 'قناتك الخاصة، لا وسيط',
            'body' => 'يصلك كل طلب كرسالة واتساب منظمة مع الأصناف والكميات والعنوان.',
            'points' => ['رمز QR على الطاولة والملصق والحساب.', 'طلبات داخل المطعم والاستلام والتوصيل من رقم واحد.', 'قائمة بالفرنسية والعربية تلقائياً.'],
        ],
        'pain_title' => 'بدون عمولة على طلباتك',
        'pain' => 'تطبيقات التوصيل تقتطع حصة من كل طلب. مع قناتك الخاصة تحتفظ بـ 100% مقابل اشتراك ثابت.',
        'prices' => [
            'title' => 'ابدأ اليوم',
            'items' => ['خطة مجانية للبدء دون بطاقة.', 'راجع صفحة الأسعار للسعر بالدرهم.'],
        ],
        'faq_title' => 'أسئلة شائعة — المنيو الإلكتروني في المغرب',
        'faqs' => [
            ['q' => 'كيف أنشئ منيو الكتروني بالباركود لمطعمي في المغرب؟', 'a' => 'أنشئ حساباً مجاناً، أضف أصنافك وأسعارك، فتحصل على رمز QR ورابط يفتح قائمتك على هاتف الزبون خلال دقائق.'],
            ['q' => 'هل الدفع بالبطاقة عبر CMI مدعوم؟', 'a' => 'نعم، يمكنك عرض الدفع بالبطاقة عبر CMI إلى جانب الدفع عند الاستلام.'],
        ],
    ],
];
