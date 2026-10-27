<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * P1-23 system e-mail bodies (Community). Flat dotted keys: __('sysmail.terms_changed.intro'). Subjects live in
 * site.php (mail.*.subject). Keep every locale's keys and :placeholders aligned with lang/en/sysmail.php.
 */

return [
    'greeting' => 'Здравствуйте, :name!',
    'terms_changed.intro' => 'Мы обновляем Условия :brand и сопутствующие документы. Новая версия вступает в силу :date.',
    'terms_changed.notice' => 'О существенных изменениях мы сообщаем не менее чем за 30 дней. Они применяются с вашего следующего продления, а раньше — только если этого требует закон или вы их принимаете. Если вы не согласны с существенным изменением, вы можете отказаться от подписки до его вступления в силу.',
    'terms_changed.cta' => 'Прочитать изменения',
    'app_overdue.failed_subject' => 'Не удалось продлить :app',
    'app_overdue.failed_body' => 'Мы не смогли списать :price за продление :app (недостаточно средств в кошельке). Приложение продолжает работать. Пополните кошелёк — мы будем автоматически повторять попытку каждый день.',
    'app_overdue.notice_subject' => ':app будет приостановлено :date',
    'app_overdue.notice_body' => 'Оплата продления :app на сумму :price просрочена более чем на 14 дней. Если она не поступит до :date, то есть через 7 дней, мы приостановим приложение. Данные не удаляются, и приложение снова заработает сразу после оплаты.',
];
