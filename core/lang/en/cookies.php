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
    'list_link' => 'See every cookie we use',
    'intro' => 'This page lists every cookie this site can set: what it does, whether it needs your consent and how long it lasts.',
    'intro_juwri' => 'juwri.com and the juwri dashboard are run by JUWRI LTD. Section 5 of our [Privacy Policy](/privacy) explains how we use cookies.',
    'consent_note' => 'Essential cookies are always on. Analytics and marketing cookies are set only after you allow them, and you can change your choice at any time.',
    'category.essential' => 'Essential',
    'category.analytics' => 'Analytics',
    'category.marketing' => 'Marketing',
    'category_note.essential' => 'Needed for the site to work. They do not need your consent.',
    'category_note.analytics' => 'Set only if you allow analytics cookies.',
    'category_note.marketing' => 'Set only if you allow marketing cookies.',
    'col.name' => 'Name',
    'col.purpose' => 'Purpose',
    'col.lifetime' => 'Lifetime',
    'col.party' => 'Set by',
    'party.first' => 'This site',
    'party.third' => ':provider (third party)',
    'lifetime.session' => 'Until you close the browser',
    'stores' => 'A store\'s own pages may also use the store\'s analytics or marketing tools, such as Google Analytics, Google Tag Manager or Meta Pixel. They stay blocked until you allow them, and the store is responsible for them.',
    'purpose.session' => 'Keeps you signed in and remembers your actions from page to page.',
    'purpose.csrf' => 'Protects forms against cross-site request forgery.',
    'purpose.remember' => 'Keeps you signed in on this device when you choose "Remember me".',
    'purpose.two_factor' => 'Remembers this device after two-step sign-in, when you ask it to.',
    'purpose.consent' => 'Remembers your cookie choices.',
    'purpose.language' => 'Remembers the language you chose.',
    'purpose.language_prompt' => 'Remembers that you closed the language suggestion.',
    'purpose.theme' => 'Remembers the light or dark appearance you chose.',
    'purpose.owner_app' => 'Tells the site it is open in the installed owner app.',
    'purpose.table' => 'Links your order to the table whose QR code you scanned.',
    'purpose.cart' => 'Keeps your shopping cart while you browse.',
    'purpose.wishlist' => 'Keeps the items you saved to your wishlist on this device (only a random token; the list is stored by the shop).',
    'purpose.studio' => 'Keeps your Menu Studio draft until you sign up.',
    'purpose.popup' => 'Remembers for a day that you closed the store\'s pop-up.',
    'purpose.referral' => 'Remembers who referred you, so the referral can be credited.',
    'purpose.ga' => 'Google Analytics: counts visits and shows how pages are used.',
    'purpose.ga_throttle' => 'Google Analytics: limits how often data is sent.',
    'purpose.ads' => 'Google Ads: measures which ads lead to sign-ups or orders.',
    'purpose.meta' => 'Meta Pixel: measures ads and campaigns on Facebook and Instagram.',
    'purpose.chat' => 'Tawk.to live chat: keeps your chat conversation and counts chat visitors.',
    'purpose.marketplace' => 'Remembers the marketplace area you are browsing.',
    'purpose.announcement' => 'Remembers the announcements you dismissed.',
];
