<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * P1-23 system e-mail bodies (Community). Flat dotted keys: __('sysmail.terms_changed.intro'). Subjects live in
 * site.php (mail.*.subject). Keep every locale's keys and :placeholders aligned with lang/en/sysmail.php.
 */

return [
    'greeting' => 'Bonjour :name,',
    'terms_changed.intro' => 'Nous mettons à jour les Conditions de :brand et les documents qui les accompagnent. La nouvelle version prend effet le :date.',
    'terms_changed.notice' => 'Nous prévenons au moins 30 jours à l\'avance des changements importants. Ils s\'appliquent à partir de votre prochain renouvellement, ou plus tôt si la loi l\'exige ou si vous les acceptez. Si vous vous opposez à un changement important, vous pouvez résilier avant son entrée en vigueur.',
    'terms_changed.cta' => 'Lire les changements',
    'app_overdue.failed_subject' => 'Échec du renouvellement de :app',
    'app_overdue.failed_body' => 'Nous n’avons pas pu prélever :price pour le renouvellement de :app (solde du portefeuille insuffisant). L’app continue de fonctionner. Rechargez votre portefeuille : nous réessaierons automatiquement chaque jour.',
    'app_overdue.notice_subject' => ':app sera mise en pause le :date',
    'app_overdue.notice_body' => 'Le renouvellement de :app de :price est en retard de plus de 14 jours. S’il n’est toujours pas payé le :date, dans 7 jours, nous mettrons l’app en pause. Aucune donnée n’est supprimée, et l’app refonctionne dès que le paiement passe.',
];
