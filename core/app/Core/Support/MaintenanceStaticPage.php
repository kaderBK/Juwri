<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

/**
 * P6-112: the pre-rendered `maintenance.html` served by the web server (Apache/LiteSpeed ErrorDocument, Caddy
 * handle_errors) when PHP itself is broken or switched off. One file carries every language; a few lines of
 * inline script show the visitor's (navigator.language), and with scripts off the English block shows. No
 * external asset, no request, no PHP. Rebuilt by `php artisan juwri:maintenance-page` (kept in sync by a test).
 */
final class MaintenanceStaticPage
{
    public static function path(): string
    {
        return dirname(base_path()).DIRECTORY_SEPARATOR.'maintenance.html';
    }

    public static function build(): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $name = (string) config('app.name', 'Juwri');
        $locales = ErrorPage::supportedLocales();
        $blocks = '';
        foreach ($locales as $code) {
            [$title, $description] = ErrorPage::text(503, $code);
            $blocks .= '<section lang="'.$e($code).'" dir="'.(ErrorPage::isRtl($code) ? 'rtl' : 'ltr').'"'
                .($code === 'en' ? '' : ' hidden').'>'
                .'<p class="o">503</p><h1>'.$e($title).'</h1><p class="d">'.$e($description).'</p></section>'."\n";
        }
        $list = json_encode($locales, JSON_UNESCAPED_SLASHES);

        return '<!DOCTYPE html>'."\n"
            .'<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<meta name="color-scheme" content="light dark"><meta name="robots" content="noindex, nofollow">'
            .'<title>'.$e($name).'</title><style>'.ErrorPage::css()
            .'section[hidden]{display:none}.n{font-weight:700;margin:0 0 24px}h1{font-size:1.5rem;margin:0 0 8px}</style></head>'."\n"
            .'<body class="err"><main><p class="n">'.$e($name).'</p>'."\n".$blocks
            .'</main><script>(function(){var s='.$list.',l=(navigator.languages||[navigator.language||"en"]),c="en",i,t;'
            .'for(i=0;i<l.length;i++){t=String(l[i]).toLowerCase();if(s.indexOf(t)>-1){c=t;break}t=t.split("-")[0];if(s.indexOf(t)>-1){c=t;break}}'
            .'var a=document.querySelectorAll("section"),j;for(j=0;j<a.length;j++){a[j].hidden=a[j].lang!==c}'
            .'document.documentElement.lang=c;document.documentElement.dir=document.querySelector("section:not([hidden])").dir})();</script>'
            .'</body></html>'."\n";
    }
}
