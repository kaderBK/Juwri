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
    'list_link' => 'Ver todas las cookies que usamos',
    'intro' => 'Esta página enumera todas las cookies que puede instalar este sitio: para qué sirven, si necesitan tu consentimiento y cuánto tiempo duran.',
    'intro_juwri' => 'juwri.com y el panel de juwri los gestiona JUWRI LTD. La sección 5 de nuestra [Política de privacidad](/privacy) explica cómo usamos las cookies.',
    'consent_note' => 'Las cookies esenciales están siempre activas. Las cookies de analítica y marketing solo se instalan si las permites, y puedes cambiar tu elección en cualquier momento.',
    'category.essential' => 'Esenciales',
    'category.analytics' => 'Analítica',
    'category.marketing' => 'Marketing',
    'category_note.essential' => 'Necesarias para que el sitio funcione. No requieren tu consentimiento.',
    'category_note.analytics' => 'Solo se instalan si permites las cookies de analítica.',
    'category_note.marketing' => 'Solo se instalan si permites las cookies de marketing.',
    'col.name' => 'Nombre',
    'col.purpose' => 'Finalidad',
    'col.lifetime' => 'Duración',
    'col.party' => 'Instalada por',
    'party.first' => 'Este sitio',
    'party.third' => ':provider (tercero)',
    'lifetime.session' => 'Hasta que cierres el navegador',
    'stores' => 'Las páginas de una tienda también pueden usar sus propias herramientas de analítica o marketing, como Google Analytics, Google Tag Manager o Meta Pixel. Permanecen bloqueadas hasta que las permitas, y la tienda es responsable de ellas.',
    'purpose.session' => 'Mantiene tu sesión iniciada y recuerda tus acciones de una página a otra.',
    'purpose.csrf' => 'Protege los formularios contra la falsificación de solicitudes entre sitios.',
    'purpose.remember' => 'Mantiene tu sesión iniciada en este dispositivo cuando eliges «Recordarme».',
    'purpose.two_factor' => 'Recuerda este dispositivo tras el inicio de sesión en dos pasos, cuando lo pides.',
    'purpose.social_login' => 'Comprueba que una respuesta de «Iniciar sesión con Apple» corresponde al inicio de sesión que tú empezaste.',
    'purpose.consent' => 'Recuerda tus preferencias de cookies.',
    'purpose.language' => 'Recuerda el idioma que elegiste.',
    'purpose.language_prompt' => 'Recuerda que cerraste la sugerencia de idioma.',
    'purpose.theme' => 'Recuerda el aspecto claro u oscuro que elegiste.',
    'purpose.owner_app' => 'Indica al sitio que está abierto en la app del propietario instalada.',
    'purpose.table' => 'Vincula tu pedido a la mesa cuyo código QR escaneaste.',
    'purpose.cart' => 'Conserva tu carrito mientras navegas.',
    'purpose.wishlist' => 'Guarda en este dispositivo los artículos de tu lista de deseos (solo un token aleatorio; la lista la guarda la tienda).',
    'purpose.studio' => 'Conserva tu borrador de Menu Studio hasta que te registres.',
    'purpose.popup' => 'Recuerda durante un día que cerraste la ventana emergente de la tienda.',
    'purpose.referral' => 'Recuerda quién te recomendó, para que se acredite la recomendación.',
    'purpose.ga' => 'Google Analytics: cuenta las visitas y muestra cómo se usan las páginas.',
    'purpose.ga_throttle' => 'Google Analytics: limita la frecuencia con la que se envían datos.',
    'purpose.ads' => 'Google Ads: mide qué anuncios generan registros o pedidos.',
    'purpose.meta' => 'Meta Pixel: mide anuncios y campañas en Facebook e Instagram.',
    'purpose.chat' => 'Chat en vivo de Tawk.to: conserva tu conversación y cuenta los visitantes del chat.',
    'purpose.marketplace' => 'Recuerda la zona del marketplace que estás viendo.',
    'purpose.announcement' => 'Recuerda los avisos que descartaste.',
];
