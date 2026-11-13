<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * P1-23: the cookie list page (/cookies) — Juwri\Core\Legal\CookieRegistry, resources/views/legal/cookies.blade.php.
 * Flat dotted keys: __('cookies.purpose.session'). `purpose.*` = one key per registry purpose. Inline [text](/path)
 * links are rendered by Juwri\Core\Legal\LegalText::inline(). Keep every locale's keys aligned with lang/en/cookies.php.
 */

return [
    'title' => 'Çerezler',
    'list_link' => 'Kullandığımız tüm çerezleri görün',
    'intro' => 'Bu sayfa, bu sitenin yerleştirebileceği tüm çerezleri listeler: ne işe yaradıklarını, izninizi gerektirip gerektirmediklerini ve ne kadar süre kaldıklarını.',
    'intro_juwri' => 'juwri.com ve juwri paneli JUWRI LTD tarafından işletilir. [Gizlilik Politikamızın](/privacy) 5. bölümü çerezleri nasıl kullandığımızı açıklar.',
    'consent_note' => 'Zorunlu çerezler her zaman açıktır. Analiz ve pazarlama çerezleri yalnızca siz izin verdikten sonra yerleştirilir ve seçiminizi istediğiniz zaman değiştirebilirsiniz.',
    'category.essential' => 'Zorunlu',
    'category.analytics' => 'Analiz',
    'category.marketing' => 'Pazarlama',
    'category_note.essential' => 'Sitenin çalışması için gereklidir. İzninizi gerektirmez.',
    'category_note.analytics' => 'Yalnızca analiz çerezlerine izin verirseniz yerleştirilir.',
    'category_note.marketing' => 'Yalnızca pazarlama çerezlerine izin verirseniz yerleştirilir.',
    'col.name' => 'Ad',
    'col.purpose' => 'Amaç',
    'col.lifetime' => 'Süre',
    'col.party' => 'Yerleştiren',
    'party.first' => 'Bu site',
    'party.third' => ':provider (üçüncü taraf)',
    'lifetime.session' => 'Tarayıcıyı kapatana kadar',
    'stores' => 'Bir mağazanın sayfaları, mağazanın kendi analiz veya pazarlama araçlarını da kullanabilir; örneğin Google Analytics, Google Tag Manager veya Meta Pixel. Siz izin verene kadar engelli kalırlar ve bunlardan mağaza sorumludur.',
    'purpose.session' => 'Oturumunuzu açık tutar ve sayfadan sayfaya işlemlerinizi hatırlar.',
    'purpose.csrf' => 'Formları siteler arası istek sahteciliğine karşı korur.',
    'purpose.remember' => '"Beni hatırla"yı seçtiğinizde bu cihazda oturumunuzu açık tutar.',
    'purpose.two_factor' => 'İstediğinizde, iki adımlı girişten sonra bu cihazı hatırlar.',
    'purpose.consent' => 'Çerez tercihlerinizi hatırlar.',
    'purpose.language' => 'Seçtiğiniz dili hatırlar.',
    'purpose.language_prompt' => 'Dil önerisini kapattığınızı hatırlar.',
    'purpose.theme' => 'Seçtiğiniz açık veya koyu görünümü hatırlar.',
    'purpose.owner_app' => 'Siteye, yüklü işletme sahibi uygulamasında açık olduğunu bildirir.',
    'purpose.table' => 'Siparişinizi, QR kodunu taradığınız masayla ilişkilendirir.',
    'purpose.cart' => 'Gezinirken alışveriş sepetinizi korur.',
    'purpose.wishlist' => 'Bu cihazda istek listenize eklediğiniz ürünleri saklar (yalnızca rastgele bir belirteç; liste mağazada tutulur).',
    'purpose.studio' => 'Kaydolana kadar Menu Studio taslağınızı korur.',
    'purpose.popup' => 'Mağazanın açılır penceresini kapattığınızı bir gün boyunca hatırlar.',
    'purpose.referral' => 'Sizi kimin yönlendirdiğini hatırlar, böylece yönlendirme hak sahibine yazılır.',
    'purpose.ga' => 'Google Analytics: ziyaretleri sayar ve sayfaların nasıl kullanıldığını gösterir.',
    'purpose.ga_throttle' => 'Google Analytics: verilerin ne sıklıkla gönderildiğini sınırlar.',
    'purpose.ads' => 'Google Ads: hangi reklamların kayıt veya siparişe yol açtığını ölçer.',
    'purpose.meta' => 'Meta Pixel: Facebook ve Instagram\'daki reklamları ve kampanyaları ölçer.',
    'purpose.chat' => 'Tawk.to canlı sohbet: sohbet görüşmenizi korur ve sohbet ziyaretçilerini sayar.',
    'purpose.marketplace' => 'Göz attığınız pazar yeri bölgesini hatırlar.',
    'purpose.announcement' => 'Kapattığınız duyuruları hatırlar.',
];
