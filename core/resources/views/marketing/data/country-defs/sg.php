<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * SEO Growth W3.8 — Singapore country landing page (cc: sg). ZH hero
 * (扫码点餐系统) + EN. Prices in SGD (never RMB). Light compliance (GST-ready;
 * InvoiceNow is B2B) — no fabricated B2C e-invoice mandate.
 */

return [
    '_default' => 'en',

    'zh' => [
        'region_name' => '新加坡',
        'name' => '新加坡',
        'eyebrow' => '扫码点餐',
        'title' => '扫码点餐系统 — 新加坡',
        'meta' => '面向新加坡餐厅与咖啡馆的免费二维码菜单与扫码点餐系统：WhatsApp 点餐、PayNow 收款，零佣金。',
        'sub' => '二维码数字菜单、直达 WhatsApp 的订单，以及顾客常用的本地支付方式——全部零佣金。',
        'intro' => [
            '把你在新加坡的餐厅或咖啡馆变成一套从顾客手机运行的数字菜单：扫描二维码、浏览菜单，订单直接发送到 WhatsApp。',
            '无需安装应用，也没有聚合平台佣金——菜单、订单与顾客关系都归你。',
        ],
        'cta_primary' => '免费开始',
        'cta_demo' => '查看在线菜单',
        'demo_slug' => 'crave-demo',
        'gateways_title' => '支付方式',
        'gateways' => ['PayNow', '银行卡', 'GrabPay', '货到付款'],
        'compliance' => [
            'title' => '你的渠道，你的利润',
            'body' => '每笔订单都以整洁的 WhatsApp 消息送达，包含菜品、数量与地址；价格与收据支持 GST。',
            'points' => ['桌面、海报与主页链接上的二维码。', '堂食、自取与外送共用一个号码。', '收据支持 GST 显示。'],
        ],
        'pain_title' => '零佣金',
        'pain' => '外卖聚合平台会从每笔订单抽成。使用你自己的渠道，只需固定订阅费即可保留 100%。',
        'prices' => [
            'title' => '立即开始',
            'items' => ['免费方案，无需信用卡（价格以 SGD 计）。', '成长后再升级。'],
        ],
        'faq_title' => '新加坡餐厅扫码点餐常见问题',
        'faqs' => [
            ['q' => '扫码点餐系统多少钱？', 'a' => '可以免费开始；在定价页可查看以 SGD 计的方案价格。订单零佣金，只收固定订阅费。'],
            ['q' => '支持 PayNow 吗？', 'a' => '支持——你可以提供 PayNow、银行卡与货到付款等方式。'],
        ],
    ],

    'en' => [
        'region_name' => 'Singapore',
        'name' => 'Singapore',
        'eyebrow' => 'QR ordering',
        'title' => 'QR Code Ordering System — Singapore',
        'meta' => 'Free QR code menu and scan-to-order system for restaurants and cafés in Singapore: WhatsApp orders, PayNow, and zero commission. Prices in SGD.',
        'sub' => 'A QR digital menu, orders straight to WhatsApp, and the local payments your customers use — all with zero commission.',
        'intro' => [
            'Turn your restaurant, shop or café in Singapore into a digital menu that runs from the customer’s phone: scan a QR, browse, and the order lands on WhatsApp — no aggregator commission.',
        ],
        'cta_primary' => 'Start free',
        'cta_demo' => 'See a live menu',
        'demo_slug' => 'crave-demo',
        'gateways_title' => 'Payments',
        'gateways' => ['PayNow', 'Card', 'GrabPay', 'Cash on delivery'],
        'compliance' => [
            'title' => 'Your channel, your margin',
            'body' => 'Every order arrives as a tidy WhatsApp message; prices and receipts support GST.',
            'points' => ['QR at the table, poster and bio link', 'Dine-in, pickup and delivery from one number', 'GST-ready receipts'],
        ],
        'pain_title' => 'Zero commission',
        'pain' => 'Delivery aggregators take a cut of every order. On your own channel you keep 100% for a flat subscription.',
        'prices' => [
            'title' => 'Start today',
            'items' => ['Free plan, no card (prices in SGD)', 'Upgrade only when you grow'],
        ],
        'faq_title' => 'Singapore restaurant QR ordering FAQ',
        'faqs' => [
            ['q' => 'How much does a scan-to-order system cost?', 'a' => 'You can start free and see plan pricing in SGD on the pricing page. Orders are zero-commission — just a flat subscription.'],
            ['q' => 'Do you support PayNow?', 'a' => 'Yes — you can offer PayNow, card and cash on delivery.'],
        ],
    ],
];
