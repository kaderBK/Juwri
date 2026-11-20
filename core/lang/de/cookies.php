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
    'list_link' => 'Alle von uns verwendeten Cookies ansehen',
    'intro' => 'Diese Seite listet alle Cookies auf, die diese Website setzen kann: wofür sie dienen, ob sie Ihre Einwilligung brauchen und wie lange sie gespeichert bleiben.',
    'intro_juwri' => 'juwri.com und das juwri-Dashboard werden von JUWRI LTD betrieben. Abschnitt 5 unserer [Datenschutzerklärung](/privacy) erklärt, wie wir Cookies verwenden.',
    'consent_note' => 'Notwendige Cookies sind immer aktiv. Analyse- und Marketing-Cookies setzen wir erst, wenn Sie sie erlauben, und Sie können Ihre Wahl jederzeit ändern.',
    'category.essential' => 'Notwendig',
    'category.analytics' => 'Analyse',
    'category.marketing' => 'Marketing',
    'category_note.essential' => 'Für den Betrieb der Website erforderlich. Sie benötigen keine Einwilligung.',
    'category_note.analytics' => 'Werden nur gesetzt, wenn Sie Analyse-Cookies erlauben.',
    'category_note.marketing' => 'Werden nur gesetzt, wenn Sie Marketing-Cookies erlauben.',
    'col.name' => 'Cookie-Name',
    'col.purpose' => 'Zweck',
    'col.lifetime' => 'Speicherdauer',
    'col.party' => 'Gesetzt von',
    'party.first' => 'Diese Website',
    'party.third' => ':provider (Drittanbieter)',
    'lifetime.session' => 'Bis Sie den Browser schließen',
    'stores' => 'Die Seiten eines Shops können auch dessen eigene Analyse- oder Marketing-Tools nutzen, etwa Google Analytics, Google Tag Manager oder Meta Pixel. Sie bleiben blockiert, bis Sie sie erlauben; verantwortlich dafür ist der Shop.',
    'purpose.session' => 'Hält Sie angemeldet und merkt sich Ihre Aktionen von Seite zu Seite.',
    'purpose.csrf' => 'Schützt Formulare vor Cross-Site-Request-Forgery.',
    'purpose.remember' => 'Hält Sie auf diesem Gerät angemeldet, wenn Sie „Angemeldet bleiben“ wählen.',
    'purpose.two_factor' => 'Merkt sich dieses Gerät nach der Anmeldung in zwei Schritten, wenn Sie das wünschen.',
    'purpose.social_login' => 'Prüft, ob eine Antwort von „Mit Apple anmelden“ zu der von Ihnen gestarteten Anmeldung gehört.',
    'purpose.consent' => 'Speichert Ihre Cookie-Einstellungen.',
    'purpose.language' => 'Merkt sich die gewählte Sprache.',
    'purpose.language_prompt' => 'Merkt sich, dass Sie den Sprachvorschlag geschlossen haben.',
    'purpose.theme' => 'Merkt sich die gewählte helle oder dunkle Darstellung.',
    'purpose.owner_app' => 'Zeigt der Website, dass sie in der installierten Inhaber-App geöffnet ist.',
    'purpose.table' => 'Verknüpft Ihre Bestellung mit dem Tisch, dessen QR-Code Sie gescannt haben.',
    'purpose.cart' => 'Bewahrt Ihren Warenkorb, während Sie stöbern.',
    'purpose.wishlist' => 'Speichert die Artikel auf Ihrer Wunschliste auf diesem Gerät (nur ein zufälliges Token; die Liste speichert der Shop).',
    'purpose.studio' => 'Bewahrt Ihren Menu-Studio-Entwurf, bis Sie sich registrieren.',
    'purpose.popup' => 'Merkt sich einen Tag lang, dass Sie das Pop-up des Shops geschlossen haben.',
    'purpose.referral' => 'Merkt sich, wer Sie empfohlen hat, damit die Empfehlung gutgeschrieben wird.',
    'purpose.ga' => 'Google Analytics: zählt Besuche und zeigt, wie Seiten genutzt werden.',
    'purpose.ga_throttle' => 'Google Analytics: begrenzt, wie oft Daten gesendet werden.',
    'purpose.ads' => 'Google Ads: misst, welche Anzeigen zu Registrierungen oder Bestellungen führen.',
    'purpose.meta' => 'Meta Pixel: misst Anzeigen und Kampagnen auf Facebook und Instagram.',
    'purpose.chat' => 'Tawk.to-Live-Chat: bewahrt Ihr Chat-Gespräch und zählt Chat-Besucher.',
    'purpose.marketplace' => 'Merkt sich den Marktplatz-Bereich, in dem Sie stöbern.',
    'purpose.announcement' => 'Merkt sich die Mitteilungen, die Sie ausgeblendet haben.',
];
