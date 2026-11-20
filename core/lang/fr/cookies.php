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
    'list_link' => 'Voir tous les cookies que nous utilisons',
    'intro' => 'Cette page liste tous les cookies que ce site peut déposer : leur rôle, s\'ils nécessitent votre consentement et leur durée de conservation.',
    'intro_juwri' => 'juwri.com et le tableau de bord juwri sont exploités par JUWRI LTD. La section 5 de notre [Politique de confidentialité](/privacy) explique comment nous utilisons les cookies.',
    'consent_note' => 'Les cookies essentiels sont toujours actifs. Les cookies d\'analyse et de marketing ne sont déposés qu\'après votre accord, et vous pouvez modifier votre choix à tout moment.',
    'category.essential' => 'Essentiels',
    'category.analytics' => 'Analyse',
    'category.marketing' => 'Marketing',
    'category_note.essential' => 'Nécessaires au fonctionnement du site. Ils ne nécessitent pas votre consentement.',
    'category_note.analytics' => 'Déposés uniquement si vous autorisez les cookies d\'analyse.',
    'category_note.marketing' => 'Déposés uniquement si vous autorisez les cookies marketing.',
    'col.name' => 'Nom',
    'col.purpose' => 'Finalité',
    'col.lifetime' => 'Durée',
    'col.party' => 'Déposé par',
    'party.first' => 'Ce site',
    'party.third' => ':provider (tiers)',
    'lifetime.session' => 'Jusqu\'à la fermeture du navigateur',
    'stores' => 'Les pages d\'une boutique peuvent aussi utiliser ses propres outils d\'analyse ou de marketing, comme Google Analytics, Google Tag Manager ou Meta Pixel. Ils restent bloqués tant que vous ne les autorisez pas, et la boutique en est responsable.',
    'purpose.session' => 'Vous garde connecté et mémorise vos actions d\'une page à l\'autre.',
    'purpose.csrf' => 'Protège les formulaires contre la falsification de requêtes intersites.',
    'purpose.remember' => 'Vous garde connecté sur cet appareil lorsque vous choisissez « Se souvenir de moi ».',
    'purpose.two_factor' => 'Mémorise cet appareil après la connexion en deux étapes, si vous le demandez.',
    'purpose.social_login' => 'Vérifie qu’une réponse « Se connecter avec Apple » correspond à la connexion que vous avez lancée.',
    'purpose.consent' => 'Mémorise vos choix en matière de cookies.',
    'purpose.language' => 'Mémorise la langue que vous avez choisie.',
    'purpose.language_prompt' => 'Mémorise que vous avez fermé la suggestion de langue.',
    'purpose.theme' => 'Mémorise l\'apparence claire ou sombre que vous avez choisie.',
    'purpose.owner_app' => 'Indique au site qu\'il est ouvert dans l\'application propriétaire installée.',
    'purpose.table' => 'Associe votre commande à la table dont vous avez scanné le QR code.',
    'purpose.cart' => 'Conserve votre panier pendant votre navigation.',
    'purpose.wishlist' => 'Conserve sur cet appareil les articles de votre liste de souhaits (un simple jeton aléatoire ; la liste est stockée par la boutique).',
    'purpose.studio' => 'Conserve votre brouillon Menu Studio jusqu\'à votre inscription.',
    'purpose.popup' => 'Mémorise pendant un jour que vous avez fermé la fenêtre pop-up de la boutique.',
    'purpose.referral' => 'Mémorise qui vous a recommandé, pour que le parrainage soit crédité.',
    'purpose.ga' => 'Google Analytics : compte les visites et montre comment les pages sont utilisées.',
    'purpose.ga_throttle' => 'Google Analytics : limite la fréquence d\'envoi des données.',
    'purpose.ads' => 'Google Ads : mesure quelles annonces mènent à des inscriptions ou des commandes.',
    'purpose.meta' => 'Meta Pixel : mesure les annonces et campagnes sur Facebook et Instagram.',
    'purpose.chat' => 'Chat en direct Tawk.to : conserve votre conversation et compte les visiteurs du chat.',
    'purpose.marketplace' => 'Mémorise la zone de la marketplace que vous parcourez.',
    'purpose.announcement' => 'Mémorise les annonces que vous avez masquées.',
];
