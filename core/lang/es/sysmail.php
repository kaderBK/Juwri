<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * P1-23 system e-mail bodies (Community). Flat dotted keys: __('sysmail.terms_changed.intro'). Subjects live in
 * site.php (mail.*.subject). Keep every locale's keys and :placeholders aligned with lang/en/sysmail.php.
 */

return [
    'greeting' => 'Hola :name,',
    'terms_changed.intro' => 'Estamos actualizando las Condiciones de :brand y los documentos que las acompañan. La nueva versión entra en vigor el :date.',
    'terms_changed.notice' => 'Avisamos con al menos 30 días de antelación de los cambios importantes. Se aplican a partir de tu próxima renovación, o antes si la ley lo exige o los aceptas. Si no estás de acuerdo con un cambio importante, puedes cancelar antes de que entre en vigor.',
    'terms_changed.cta' => 'Leer los cambios',
    'app_overdue.failed_subject' => 'Falló la renovación de :app',
    'app_overdue.failed_body' => 'No pudimos cobrar :price por la renovación de :app (saldo insuficiente en el monedero). La app sigue funcionando. Recarga tu monedero y lo reintentaremos automáticamente cada día.',
    'app_overdue.notice_subject' => ':app se pausará el :date',
    'app_overdue.notice_body' => 'La renovación de :app por :price lleva más de 14 días vencida. Si sigue sin pagarse el :date, dentro de 7 días, pausaremos la app. No se borra ningún dato y la app vuelve a funcionar en cuanto se complete el pago.',
];
