<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * SEO Growth W4.5 — Anota AI comparison page. PT-BR primary + en mirror.
 * Slug: anota-ai. KEYWORDS §Compare: "alternativa ao Anota AI".
 * NEGATIVE KEYWORD: never title/target "robô de whatsapp" (Anota AI owns it) —
 * we use the "alternativa" framing.
 *
 * Cite-check (2026-08-30, anota.ai official prices via corroborating sources,
 * "site oficial julho de 2026"):
 * - No free plan; entry is the Start plan from R$ 299,99/mês (Gestão Avançada
 *   R$ 399,99/mês). Fixed monthly, NO per-order fee / commission; unlimited orders.
 * - Product = WhatsApp AI attendant (robô) + cardápio digital + PDV; integrates
 *   iFood/Rappi. Those (AI robô + marketplace integration) are their strengths —
 *   credited honestly.
 */

return [
    'name' => 'Anota AI',

    'pt' => [
        'eyebrow' => 'Comparação',
        'title' => 'Alternativa ao Anota AI (2026)',
        'meta_title' => 'Alternativa ao Anota AI (2026)',
        'meta' => 'Alternativa ao Anota AI: compare cardápio digital, pedidos pelo WhatsApp e preço. Tem plano gratuito? Comparação honesta e sem comissão.',
        'sub' => 'O Anota AI não tem plano gratuito (começa em R$ 299,99/mês). Veja uma alternativa com plano gratuito e sem comissão — comparação honesta.',
        'intro' => [
            'O Anota AI é especialista em atendimento no WhatsApp com robô de IA, cardápio digital e PDV, com integração ao iFood e Rappi. É uma ótima escolha se você quer um bot de WhatsApp maduro.',
            'A diferença principal é o ponto de partida: o Anota AI é pago a partir de R$ 299,99/mês, enquanto esta alternativa tem um plano gratuito. Veja os dois lados.',
        ],
        'groups' => [
            [
                'group' => 'Preços',
                'rows' => [
                    ['label' => 'Plano gratuito', 'us' => true, 'them' => false],
                    ['label' => 'Preço inicial', 'us' => 'Grátis / plano fixo', 'them' => 'A partir de R$ 299,99/mês'],
                    ['label' => 'Comissão por pedido', 'us' => 'Não', 'them' => 'Não'],
                ],
            ],
            [
                'group' => 'Recursos',
                'rows' => [
                    ['label' => 'Cardápio digital + QR', 'us' => true, 'them' => true],
                    ['label' => 'Pedidos pelo WhatsApp', 'us' => true, 'them' => true],
                    ['label' => 'Robô de IA no WhatsApp', 'us' => false, 'them' => true],
                    ['label' => 'Integração iFood / Rappi', 'us' => false, 'them' => true],
                ],
            ],
        ],
        'better_fit' => [
            'title' => 'Quando o Anota AI é a melhor escolha',
            'points' => [
                'Você quer um robô de atendimento no WhatsApp com IA maduro e automações avançadas.',
                'Você depende do iFood/Rappi e quer a integração com esses marketplaces.',
            ],
        ],
        'verdict_title' => 'Conclusão',
        'verdict' => 'Para um robô de WhatsApp com IA e integração com marketplaces, o Anota AI é forte. Se você quer começar com um plano gratuito e ter seu próprio canal de pedidos com cardápio digital, QR e WhatsApp — sem comissão — a alternativa é o melhor ponto de partida.',
        'faq_title' => 'Perguntas frequentes — alternativa ao Anota AI',
        'faqs' => [
            ['q' => 'O Anota AI tem plano gratuito?', 'a' => 'Não — o plano de entrada é o Start, a partir de R$ 299,99/mês. A alternativa tem um plano gratuito.'],
            ['q' => 'Os dois cobram comissão por pedido?', 'a' => 'Não. Tanto o Anota AI quanto a alternativa são sem comissão por pedido; você paga uma mensalidade fixa.'],
            ['q' => 'A alternativa tem pedidos pelo WhatsApp?', 'a' => 'Sim — cardápio digital com pedidos direto pelo WhatsApp e QR na mesa, começando de graça.'],
        ],
    ],

    'en' => [
        'eyebrow' => 'Comparison',
        'title' => 'Anota AI Alternative (2026)',
        'meta_title' => 'Free Anota AI Alternative: WhatsApp Ordering, No Commission',
        'meta' => 'An alternative to Anota AI with a free plan and no commission: compare digital menu, WhatsApp ordering and price for your Brazilian restaurant or business.',
        'sub' => 'Anota AI has no free plan (from R$ 299.99/mo). Here is an honest comparison with a commission-free alternative that does have a free plan.',
        'intro' => [
            'Anota AI specialises in a WhatsApp AI attendant plus a digital menu and POS, with iFood/Rappi integration — a great pick if you want a mature WhatsApp bot.',
            'The main difference is the starting point: Anota AI is paid from R$ 299.99/mo, while this alternative has a free plan. Here are both sides.',
        ],
        'groups' => [
            [
                'group' => 'Pricing',
                'rows' => [
                    ['label' => 'Free plan', 'us' => true, 'them' => false],
                    ['label' => 'Starting price', 'us' => 'Free / flat plan', 'them' => 'From R$ 299.99/mo'],
                    ['label' => 'Commission per order', 'us' => 'None', 'them' => 'None'],
                ],
            ],
            [
                'group' => 'Features',
                'rows' => [
                    ['label' => 'Digital menu + QR', 'us' => true, 'them' => true],
                    ['label' => 'WhatsApp ordering', 'us' => true, 'them' => true],
                    ['label' => 'WhatsApp AI attendant', 'us' => false, 'them' => true],
                    ['label' => 'iFood / Rappi integration', 'us' => false, 'them' => true],
                ],
            ],
        ],
        'better_fit' => [
            'title' => 'When Anota AI is the better fit',
            'points' => [
                'You want a mature, automated WhatsApp AI attendant.',
                'You rely on iFood/Rappi and want that marketplace integration.',
            ],
        ],
        'verdict_title' => 'The verdict',
        'verdict' => 'For a WhatsApp AI bot with marketplace integration, Anota AI is strong. If you want to start on a free plan with your own commission-free ordering channel — digital menu, QR and WhatsApp — the alternative is the better starting point.',
        'faq_title' => 'Anota AI alternative FAQ',
        'faqs' => [
            ['q' => 'Does Anota AI have a free plan?', 'a' => 'No — the entry plan is Start, from R$ 299.99/mo. The alternative offers a free plan.'],
            ['q' => 'Do both charge commission per order?', 'a' => 'No. Both Anota AI and the alternative are commission-free; you pay a flat monthly subscription.'],
            ['q' => 'Does the alternative support WhatsApp ordering?', 'a' => 'Yes — a digital menu with ordering straight to WhatsApp and a table QR, starting free.'],
        ],
    ],
];
