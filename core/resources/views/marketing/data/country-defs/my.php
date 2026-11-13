<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * SEO Growth W3.8 — Malaysia country landing page (cc: my). ZH hero
 * (扫码点餐系统) + EN. Prices in MYR (never RMB). Compliance hook: LHDN
 * MyInvois e-invoicing (phased by turnover) — confirm dates with LHDN.
 */

return [
    '_default' => 'en',

    'zh' => [
        'region_name' => '马来西亚',
        'name' => '马来西亚',
        'eyebrow' => '扫码点餐',
        'title' => '扫码点餐系统 — 马来西亚',
        'meta' => '面向马来西亚餐厅与咖啡馆的二维码菜单与扫码点餐系统：免费开始使用，WhatsApp 点餐、DuitNow 收款；LHDN MyInvois 电子发票属于付费套餐，零佣金。',
        'sub' => '二维码数字菜单、直达 WhatsApp 的订单、本地支付，并支持 LHDN MyInvois 电子发票——全部零佣金。',
        'intro' => [
            '把你在吉隆坡、槟城或新山的餐厅变成一套从顾客手机运行的数字菜单：扫描二维码、浏览菜单，订单直接发送到 WhatsApp。',
            '随着马来西亚推行 MyInvois 电子发票，你的系统可自动开具合规发票。',
        ],
        'cta_primary' => '免费开始',
        'cta_demo' => '查看在线菜单',
        'demo_slug' => 'crave-demo',
        'gateways_title' => '支付方式',
        'gateways' => ['DuitNow', "Touch 'n Go", '银行卡', 'GrabPay', '货到付款'],
        'compliance' => [
            'title' => '支持 LHDN MyInvois 电子发票',
            'body' => 'MyInvois 是马来西亚税务局（LHDN）的电子发票系统，按营业额分阶段推行。你的系统可开具合规发票。',
            'points' => ['为 MyInvois 电子发票做好准备。', '二维码菜单、点餐与收银一体化。', '请向 LHDN 确认你的适用阶段与时间。'],
        ],
        'pain_title' => '零佣金',
        'pain' => '外卖聚合平台会从每笔订单抽成。使用你自己的渠道，只需固定订阅费即可保留 100%。',
        'prices' => [
            'title' => '立即开始',
            'items' => ['免费方案，无需信用卡（价格以 MYR 计）。', '成长后再升级。'],
        ],
        'faq_title' => '马来西亚餐厅扫码点餐常见问题',
        'faqs' => [
            ['q' => '扫码点餐系统多少钱？', 'a' => '可以免费开始；在定价页可查看以 MYR 计的方案价格。订单零佣金，只收固定订阅费。'],
            ['q' => '支持 MyInvois 电子发票吗？', 'a' => '支持——系统可开具合规发票；请向 LHDN 确认你的适用阶段。'],
        ],
    ],

    'en' => [
        'region_name' => 'Malaysia',
        'name' => 'Malaysia',
        'eyebrow' => 'QR ordering',
        'title' => 'QR Code Ordering System — Malaysia (MyInvois Ready)',
        'meta' => 'QR code menu and scan-to-order system for restaurants and cafés in Malaysia: start free with WhatsApp orders and DuitNow; LHDN MyInvois e-invoicing is on paid plans. Zero commission, prices in MYR.',
        'sub' => 'A QR digital menu, WhatsApp orders, local payments, and LHDN MyInvois-ready e-invoicing — all with zero commission.',
        'intro' => [
            'Turn your restaurant or shop in Kuala Lumpur, Penang or Johor Bahru into a digital menu that runs from the customer’s phone — and, as Malaysia rolls out MyInvois, issues compliance-ready e-invoices automatically.',
        ],
        'cta_primary' => 'Start free',
        'cta_demo' => 'See a live menu',
        'demo_slug' => 'crave-demo',
        'gateways_title' => 'Payments',
        'gateways' => ['DuitNow', "Touch 'n Go", 'Card', 'GrabPay', 'Cash on delivery'],
        'compliance' => [
            'title' => 'LHDN MyInvois ready',
            'body' => 'MyInvois is the LHDN (Inland Revenue) e-invoicing system, rolled out in phases by turnover. Your system issues compliance-ready invoices.',
            'points' => ['Ready for MyInvois e-invoicing.', 'One system for QR menu, ordering and cashier.', 'Confirm your phase and dates with LHDN.'],
        ],
        'pain_title' => 'Zero commission',
        'pain' => 'Delivery aggregators take a cut of every order. On your own channel you keep 100% for a flat subscription.',
        'prices' => [
            'title' => 'Start today',
            'items' => ['Free plan, no card (prices in MYR)', 'Upgrade only when you grow'],
        ],
        'faq_title' => 'Malaysia restaurant QR ordering FAQ',
        'faqs' => [
            ['q' => 'How much does a scan-to-order system cost?', 'a' => 'You can start free and see plan pricing in MYR on the pricing page. Orders are zero-commission — just a flat subscription.'],
            ['q' => 'Does it support MyInvois e-invoicing?', 'a' => 'Yes — the system issues compliance-ready invoices; confirm your phase with LHDN.'],
        ],
    ],
];
