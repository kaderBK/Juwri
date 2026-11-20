<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * P1-23: the cookie list page (/cookies) — Juwri\Core\Legal\CookieRegistry, resources/views/legal/cookies.blade.php.
 * Flat dotted keys: __('cookies.purpose.session'). `purpose.*` = one key per registry purpose. Inline [text](/path)
 * links are rendered by Juwri\Core\Legal\LegalText::inline(). Keep every locale's keys aligned with lang/en/cookies.php.
 */

return [
    'title' => 'Файлы cookie',
    'list_link' => 'Все файлы cookie, которые мы используем',
    'intro' => 'На этой странице перечислены все файлы cookie, которые может устанавливать этот сайт: для чего они нужны, требуется ли ваше согласие и как долго они хранятся.',
    'intro_juwri' => 'Сайтом juwri.com и панелью управления juwri управляет JUWRI LTD. Раздел 5 нашей [Политики конфиденциальности](/privacy) объясняет, как мы используем файлы cookie.',
    'consent_note' => 'Необходимые файлы cookie всегда включены. Аналитические и маркетинговые файлы cookie устанавливаются только после вашего разрешения, и вы можете изменить свой выбор в любое время.',
    'category.essential' => 'Необходимые',
    'category.analytics' => 'Аналитика',
    'category.marketing' => 'Маркетинг',
    'category_note.essential' => 'Нужны для работы сайта. Не требуют вашего согласия.',
    'category_note.analytics' => 'Устанавливаются, только если вы разрешили аналитические файлы cookie.',
    'category_note.marketing' => 'Устанавливаются, только если вы разрешили маркетинговые файлы cookie.',
    'col.name' => 'Название',
    'col.purpose' => 'Назначение',
    'col.lifetime' => 'Срок хранения',
    'col.party' => 'Кем устанавливается',
    'party.first' => 'Этот сайт',
    'party.third' => ':provider (третья сторона)',
    'lifetime.session' => 'До закрытия браузера',
    'stores' => 'Страницы магазина могут также использовать собственные инструменты аналитики или маркетинга магазина, например Google Analytics, Google Tag Manager или Meta Pixel. Они заблокированы, пока вы их не разрешите, и за них отвечает магазин.',
    'purpose.session' => 'Сохраняет вход в аккаунт и запоминает ваши действия при переходе между страницами.',
    'purpose.csrf' => 'Защищает формы от подделки межсайтовых запросов.',
    'purpose.remember' => 'Сохраняет вход на этом устройстве, если вы выбрали «Запомнить меня».',
    'purpose.two_factor' => 'Запоминает это устройство после двухэтапного входа, если вы об этом попросили.',
    'purpose.social_login' => 'Проверяет, что ответ «Вход с Apple» относится к начатому вами входу.',
    'purpose.consent' => 'Запоминает ваш выбор в отношении файлов cookie.',
    'purpose.language' => 'Запоминает выбранный вами язык.',
    'purpose.language_prompt' => 'Запоминает, что вы закрыли подсказку о языке.',
    'purpose.theme' => 'Запоминает выбранное светлое или тёмное оформление.',
    'purpose.owner_app' => 'Сообщает сайту, что он открыт в установленном приложении владельца.',
    'purpose.table' => 'Связывает ваш заказ со столиком, QR-код которого вы отсканировали.',
    'purpose.cart' => 'Сохраняет вашу корзину, пока вы просматриваете сайт.',
    'purpose.wishlist' => 'Сохраняет на этом устройстве товары из вашего списка желаний (только случайный токен; сам список хранит магазин).',
    'purpose.studio' => 'Сохраняет ваш черновик Menu Studio до регистрации.',
    'purpose.popup' => 'Запоминает на один день, что вы закрыли всплывающее окно магазина.',
    'purpose.referral' => 'Запоминает, кто вас пригласил, чтобы засчитать приглашение.',
    'purpose.ga' => 'Google Analytics: считает посещения и показывает, как используются страницы.',
    'purpose.ga_throttle' => 'Google Analytics: ограничивает частоту отправки данных.',
    'purpose.ads' => 'Google Ads: измеряет, какие объявления приводят к регистрациям или заказам.',
    'purpose.meta' => 'Meta Pixel: измеряет эффективность рекламы и кампаний в Facebook и Instagram.',
    'purpose.chat' => 'Онлайн-чат Tawk.to: сохраняет вашу переписку и считает посетителей чата.',
    'purpose.marketplace' => 'Запоминает раздел маркетплейса, который вы просматриваете.',
    'purpose.announcement' => 'Запоминает объявления, которые вы скрыли.',
];
