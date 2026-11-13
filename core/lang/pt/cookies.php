<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * P1-23: the cookie list page (/cookies) — Juwri\Core\Legal\CookieRegistry, resources/views/legal/cookies.blade.php.
 * Flat dotted keys: __('cookies.purpose.session'). `purpose.*` = one key per registry purpose. Inline [text](/path)
 * links are rendered by Juwri\Core\Legal\LegalText::inline(). Keep every locale's keys aligned with lang/en/cookies.php.
 */

return [
    'title' => 'Cookies',
    'list_link' => 'Ver todos os cookies que usamos',
    'intro' => 'Esta página lista todos os cookies que este site pode definir: para que servem, se precisam do seu consentimento e quanto tempo duram.',
    'intro_juwri' => 'O juwri.com e o painel do juwri são operados pela JUWRI LTD. A secção 5 da nossa [Política de Privacidade](/privacy) explica como usamos cookies.',
    'consent_note' => 'Os cookies essenciais estão sempre ativos. Os cookies de análise e de marketing só são definidos depois de os permitir, e pode alterar a sua escolha a qualquer momento.',
    'category.essential' => 'Essenciais',
    'category.analytics' => 'Análise',
    'category.marketing' => 'Marketing',
    'category_note.essential' => 'Necessários para o funcionamento do site. Não precisam do seu consentimento.',
    'category_note.analytics' => 'Definidos apenas se permitir cookies de análise.',
    'category_note.marketing' => 'Definidos apenas se permitir cookies de marketing.',
    'col.name' => 'Nome',
    'col.purpose' => 'Finalidade',
    'col.lifetime' => 'Duração',
    'col.party' => 'Definido por',
    'party.first' => 'Este site',
    'party.third' => ':provider (terceiro)',
    'lifetime.session' => 'Até fechar o navegador',
    'stores' => 'As páginas de uma loja também podem usar as ferramentas de análise ou marketing da própria loja, como Google Analytics, Google Tag Manager ou Meta Pixel. Ficam bloqueadas até que as permita, e a loja é responsável por elas.',
    'purpose.session' => 'Mantém a sua sessão iniciada e lembra as suas ações de página para página.',
    'purpose.csrf' => 'Protege os formulários contra a falsificação de pedidos entre sites.',
    'purpose.remember' => 'Mantém a sua sessão iniciada neste dispositivo quando escolhe "Lembrar-me".',
    'purpose.two_factor' => 'Lembra este dispositivo após o início de sessão em dois passos, quando o pede.',
    'purpose.consent' => 'Lembra as suas escolhas de cookies.',
    'purpose.language' => 'Lembra o idioma que escolheu.',
    'purpose.language_prompt' => 'Lembra que fechou a sugestão de idioma.',
    'purpose.theme' => 'Lembra a aparência clara ou escura que escolheu.',
    'purpose.owner_app' => 'Indica ao site que está aberto na app do proprietário instalada.',
    'purpose.table' => 'Liga o seu pedido à mesa cujo código QR digitalizou.',
    'purpose.cart' => 'Guarda o seu carrinho enquanto navega.',
    'purpose.wishlist' => 'Guarda neste dispositivo os artigos da sua lista de desejos (apenas um token aleatório; a lista fica guardada pela loja).',
    'purpose.studio' => 'Guarda o seu rascunho do Menu Studio até se registar.',
    'purpose.popup' => 'Lembra durante um dia que fechou o pop-up da loja.',
    'purpose.referral' => 'Lembra quem o recomendou, para que a recomendação seja creditada.',
    'purpose.ga' => 'Google Analytics: conta as visitas e mostra como as páginas são usadas.',
    'purpose.ga_throttle' => 'Google Analytics: limita a frequência de envio de dados.',
    'purpose.ads' => 'Google Ads: mede que anúncios levam a registos ou pedidos.',
    'purpose.meta' => 'Meta Pixel: mede anúncios e campanhas no Facebook e no Instagram.',
    'purpose.chat' => 'Chat ao vivo Tawk.to: guarda a sua conversa e conta os visitantes do chat.',
    'purpose.marketplace' => 'Lembra a zona do marketplace que está a ver.',
    'purpose.announcement' => 'Lembra os avisos que dispensou.',
];
