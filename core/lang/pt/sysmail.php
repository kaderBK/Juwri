<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * P1-23 system e-mail bodies (Community). Flat dotted keys: __('sysmail.terms_changed.intro'). Subjects live in
 * site.php (mail.*.subject). Keep every locale's keys and :placeholders aligned with lang/en/sysmail.php.
 */

return [
    'greeting' => 'Olá :name,',
    'terms_changed.intro' => 'Estamos atualizando os Termos do :brand e os documentos que os acompanham. A nova versão entra em vigor em :date.',
    'terms_changed.notice' => 'Avisamos com pelo menos 30 dias de antecedência sobre mudanças relevantes. Elas valem a partir da sua próxima renovação, ou antes, se a lei exigir ou se você as aceitar. Se você discordar de uma mudança relevante, pode cancelar antes de ela entrar em vigor.',
    'terms_changed.cta' => 'Ler as mudanças',
    'app_overdue.failed_subject' => 'A renovação de :app falhou',
    'app_overdue.failed_body' => 'Não conseguimos cobrar :price pela renovação de :app (saldo da carteira insuficiente). A app continua a funcionar. Carregue a sua carteira e tentaremos de novo automaticamente todos os dias.',
    'app_overdue.notice_subject' => ':app será pausada em :date',
    'app_overdue.notice_body' => 'A renovação de :app no valor de :price está em atraso há mais de 14 dias. Se continuar por pagar em :date, daqui a 7 dias, vamos pausar a app. Nenhum dado é apagado, e a app volta a funcionar assim que o pagamento for concluído.',
];
