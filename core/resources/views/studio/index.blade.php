{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php $cur = optional(current_language()); @endphp
<!DOCTYPE html>
<html lang="{{ get_lang() }}" dir="{{ $cur->direction ?? 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php
        $seoTitle = ___('Menu Studio') . ' — ' . ___('Turn any menu into a') . ' ' . ___('full ordering app') . ' ' . ___('in 60 seconds') . ' | ' . config('settings.website_title', config('app.name'));
        $seoDesc = ___('Turn a photo, PDF or handwritten menu into a full online-ordering app or a print-ready designed PDF — free, no signup.');
        $seoImage = url('/assets/store_templates/vivid/preview.png');
    @endphp
    <title>{{ $seoTitle }}</title>
    <meta name="description" content="{{ $seoDesc }}">
    <meta name="robots" content="index,follow">
    <link rel="canonical" href="{{ route('studio') }}">
    {!! seo_hreflang_tags() !!}
    @if(!empty(config('settings.site_favicon')))
    <link rel="icon" href="{{ asset('storage/logo/'.config('settings.site_favicon')) }}">
    @endif

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ config('settings.website_title', config('app.name')) }}">
    <meta property="og:title" content="{{ $seoTitle }}">
    <meta property="og:description" content="{{ $seoDesc }}">
    <meta property="og:url" content="{{ route('studio') }}">
    <meta property="og:image" content="{{ $seoImage }}">
    <meta property="og:locale" content="{{ get_lang() }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $seoTitle }}">
    <meta name="twitter:description" content="{{ $seoDesc }}">
    <meta name="twitter:image" content="{{ $seoImage }}">

    @php
        $studioLd = [
            '@context' => 'https://schema.org',
            '@type' => 'WebApplication',
            'name' => ___('Menu Studio'),
            'applicationCategory' => 'BusinessApplication',
            'operatingSystem' => 'Web',
            'url' => route('studio'),
            'description' => $seoDesc,
            'image' => $seoImage,
            'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'USD'],
            'isPartOf' => ['@type' => 'WebSite', 'name' => config('settings.website_title', config('app.name')), 'url' => url('/')],
        ];
        $studioFaqLd = [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => [
                ['@type' => 'Question', 'name' => ___('Is it really free?'), 'acceptedAnswer' => ['@type' => 'Answer', 'text' => ___('Yes — uploading, extracting, previewing and refining are free with no account. You pay only to download a clean designed PDF (credits) or when you upgrade to the full ordering platform. Publishing a basic online menu just needs a Google sign-in.')]],
                ['@type' => 'Question', 'name' => ___('Can guests really order and pay through it?'), 'acceptedAnswer' => ['@type' => 'Answer', 'text' => ___("Yes. The published menu is a full ordering app: cart, table or pickup, checkout, and orders flowing to a kitchen screen. It's the same engine behind the whole restaurant platform.")]],
                ['@type' => 'Question', 'name' => ___('What happens to my uploads?'), 'acceptedAnswer' => ['@type' => 'Answer', 'text' => ___('Guest uploads auto-delete within 48 hours unless you sign in to keep them. Your menus are never used to train AI models.')]],
                ['@type' => 'Question', 'name' => ___('Can it read handwriting? Arabic?'), 'acceptedAnswer' => ['@type' => 'Answer', 'text' => ___('Yes to both. It reads handwritten pages, photos and PDFs, and works in many languages including full right-to-left Arabic menus.')]],
            ],
        ];
    @endphp
    <script type="application/ld+json">{!! json_ld($studioLd) !!}</script>
    <script type="application/ld+json">{!! json_ld($studioFaqLd) !!}</script>

    <script nonce="{{ csp_nonce() }}">
        (function () {
            try {
                var t = localStorage.getItem('landing-theme');
                if (!t) { t = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light'; }
                document.documentElement.setAttribute('data-theme', t);
            } catch (e) {}
        })();
    </script>

    <link rel="preload" href="{{ asset('assets/fonts/instrument-sans-latin.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="{{ asset('assets/fonts/syne-latin.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ asset('assets/fonts/fonts.css') }}">

    <style nonce="{{ csp_nonce() }}">
        /* Own inline tokens (the retired marketing landing theme they once mirrored is gone) */
        :root{
            --ss-primary:#8b3dff; --ss-primary-hover:#7634d9;
            --ss-body-bg:#f9fafb; --ss-body-color:#6c6b70; --ss-heading-color:#0a0910;
            --ss-border-color:#e1e2e4; --ss-light-2:#f4f5f8; --ss-card:#ffffff;
            --ss-dark:#0a0910; --ss-warning:#ffb400;
            --sh-sm:0 .125rem .25rem rgba(16,24,40,.06); --sh:0 .5rem 1rem rgba(16,24,40,.08); --sh-lg:0 1rem 3rem rgba(16,24,40,.12);
            --font-head:'Syne',system-ui,-apple-system,'Segoe UI',sans-serif;
            --font-body:'Instrument Sans',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
            --font-accent:'Tropiline',Georgia,'Times New Roman',serif;
        }
        html[data-theme="dark"]{
            --ss-body-bg:#0c0b12; --ss-body-color:#a7a5b3; --ss-heading-color:#f3f2f7;
            --ss-border-color:#2a2733; --ss-light-2:#15131d; --ss-card:#17151f;
            --sh-sm:0 .125rem .25rem rgba(0,0,0,.45); --sh:0 .5rem 1rem rgba(0,0,0,.55); --sh-lg:0 1rem 3rem rgba(0,0,0,.65);
            color-scheme:dark;
        }
        html{scroll-behavior:smooth}
        body{margin:0;background:var(--ss-body-bg);color:var(--ss-body-color);font-family:var(--font-body);font-size:16px;line-height:1.65;transition:background .3s ease,color .3s ease;overflow-x:hidden}
        h1,h2,h3,h4,h5{font-family:var(--font-head);color:var(--ss-heading-color);margin:0;line-height:1.15;text-wrap:balance;font-weight:700}
        .accent-i{font-family:var(--font-accent);font-style:italic;font-weight:600;color:var(--ss-primary)}
        .container{max-width:1200px;margin:0 auto;padding:0 24px}
        button{font:inherit}
        :focus-visible{outline:2px solid var(--ss-primary);outline-offset:3px;border-radius:6px}

        .reveal{opacity:0;transform:translateY(50px);transition:opacity .8s cubic-bezier(.16,1,.3,1),transform .8s cubic-bezier(.16,1,.3,1)}
        .reveal.in{opacity:1;transform:none}
        @keyframes word-blur{from{opacity:0;filter:blur(10px);transform:translateY(14px)}to{opacity:1;filter:blur(0);transform:none}}
        .w-blur{display:inline-block;opacity:0;animation:word-blur .7s cubic-bezier(.16,1,.3,1) forwards}
        @keyframes floaty{0%,100%{transform:translateY(0) rotate(0deg)}50%{transform:translateY(-18px) rotate(4deg)}}
        @keyframes floaty2{0%,100%{transform:translateY(0) rotate(0deg)}50%{transform:translateY(14px) rotate(-5deg)}}
        @keyframes marquee{to{transform:translateX(-50%)}}
        /* Marquees are visual strips: pin them LTR so the translateX loop is seamless
           in RTL too, then restore RTL inside each item for the Arabic copy. */
        [dir="rtl"] .marquee,[dir="rtl"] .reviews{direction:ltr}
        [dir="rtl"] .marquee .m-item,[dir="rtl"] .review{direction:rtl}
        @keyframes glow-pulse{0%,100%{box-shadow:0 0 0 0 rgba(139,61,255,.25),var(--sh-lg)}50%{box-shadow:0 0 0 9px rgba(139,61,255,0),var(--sh-lg)}}
        @keyframes pop{from{opacity:0;transform:scale(.6)}to{opacity:1;transform:scale(1)}}
        @media(prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}.reveal,.w-blur{opacity:1;transform:none;filter:none}html{scroll-behavior:auto}}

        .preloader{position:fixed;inset:0;background:var(--ss-dark);color:#fff;display:grid;place-items:center;z-index:999;transition:opacity .5s ease,visibility .5s ease}
        .preloader.done{opacity:0;visibility:hidden}
        .preloader__inner{display:grid;gap:18px;width:min(320px,80vw)}
        .preloader__mark{display:flex;gap:6px}
        .preloader__mark span{width:10px;height:10px;border-radius:50%;background:var(--ss-primary);animation:floaty 1s ease-in-out infinite}
        .preloader__mark span:nth-child(2){animation-delay:.15s}
        .preloader__mark span:nth-child(3){animation-delay:.3s}
        .preloader__row{display:flex;justify-content:space-between;align-items:baseline;font-family:var(--font-head);font-weight:700;font-size:18px}
        .preloader__row b{color:var(--ss-primary)}
        .preloader__bar{height:4px;background:rgba(255,255,255,.15);border-radius:99px;overflow:hidden}
        .preloader__bar i{display:block;height:100%;width:0;background:var(--ss-primary);border-radius:99px;transition:width .15s ease}

        header{position:fixed;top:0;left:0;right:0;z-index:100;transition:background .3s ease,box-shadow .3s ease;padding:14px 0}
        header.scrolled{background:var(--ss-card);box-shadow:var(--sh-sm)}
        .nav-row{display:flex;align-items:center;justify-content:space-between;gap:16px}
        .logo{display:flex;align-items:center;gap:10px;text-decoration:none;font-family:var(--font-head);font-weight:800;font-size:20px;color:var(--ss-heading-color)}
        .logo .mk{width:34px;height:34px;border-radius:10px;background:var(--ss-primary);color:#fff;display:grid;place-items:center;font-size:16px;box-shadow:0 8px 18px rgba(139,61,255,.32)}
        .nav-links{display:flex;gap:28px;font-weight:500;font-size:15px}
        .nav-links a{color:inherit;text-decoration:none;transition:color .2s}
        .nav-links a:hover{color:var(--ss-primary)}
        @media(max-width:860px){.nav-links{display:none}}
        .nav-tools{display:flex;align-items:center;gap:10px}
        .btn{display:inline-flex;align-items:center;gap:9px;border-radius:999px;padding:13px 26px;font-weight:600;font-size:15px;text-decoration:none;cursor:pointer;border:1px solid transparent;transition:all .22s ease}
        .btn-primary{background:var(--ss-primary);color:#fff;box-shadow:0 8px 18px rgba(139,61,255,.32)}
        .btn-primary:hover{background:var(--ss-primary-hover);transform:translateY(-2px)}
        .pill-btn{display:inline-flex;align-items:center;gap:7px;height:40px;padding:0 14px;border-radius:999px;border:1px solid var(--ss-border-color);background:transparent;color:var(--ss-heading-color);font-weight:600;font-size:14px;cursor:pointer;transition:all .2s}
        .pill-btn:hover{border-color:var(--ss-primary);color:var(--ss-primary)}
        .lang{position:relative}
        .lang>summary{list-style:none}
        .lang>summary::-webkit-details-marker{display:none}
        .lang .menu{position:absolute;inset-inline-end:0;top:calc(100% + 8px);background:var(--ss-card);border:1px solid var(--ss-border-color);border-radius:16px;padding:8px;min-width:190px;max-height:60vh;overflow:auto;z-index:200;box-shadow:var(--sh-lg);animation:pop .18s ease}
        .lang .menu a{display:flex;width:100%;gap:10px;align-items:center;padding:9px 11px;border-radius:10px;font-size:14px;font-weight:500;color:var(--ss-heading-color);text-align:start;text-decoration:none;box-sizing:border-box}
        .lang .menu a:hover{background:var(--ss-light-2)}
        .lang .menu a.on{background:var(--ss-primary);color:#fff}
        .lang .cc{display:inline-flex;justify-content:center;min-width:30px;padding:2px 5px;border-radius:6px;font-size:11px;font-weight:700;letter-spacing:.04em;background:var(--ss-light-2);color:var(--ss-body-color)}
        .lang .menu a.on .cc{background:rgba(255,255,255,.22);color:#fff}

        .hero{min-height:100vh;display:flex;flex-direction:column;justify-content:center;position:relative;padding:130px 0 60px;text-align:center;overflow:hidden}
        .curve{position:absolute;inset:0;pointer-events:none;opacity:.6;width:100%;height:100%}
        .shape{position:absolute;border-radius:50%;pointer-events:none;filter:blur(2px)}
        .shape-1{left:6%;top:16%;width:70px;height:70px;background:radial-gradient(circle at 30% 30%,#c9a2ff,#8b3dff);animation:floaty 7s ease-in-out infinite;opacity:.85}
        .shape-2{right:8%;top:22%;width:46px;height:46px;background:radial-gradient(circle at 30% 30%,#ffd88a,#ffb400);animation:floaty2 6s ease-in-out infinite;opacity:.9}
        .shape-3{left:12%;bottom:18%;width:34px;height:34px;background:radial-gradient(circle at 30% 30%,#9ef0c9,#20c997);animation:floaty2 8s ease-in-out infinite;opacity:.8}
        .shape-4{right:11%;bottom:24%;width:58px;height:58px;background:radial-gradient(circle at 30% 30%,#ffb3d1,#d63384);animation:floaty 9s ease-in-out infinite;opacity:.7}
        @media(max-width:760px){.shape{display:none}}
        .hero-badge{display:inline-flex;align-items:center;gap:12px;background:var(--ss-card);border:1px solid var(--ss-border-color);border-radius:14px;padding:10px 20px;text-align:start;box-shadow:var(--sh-sm)}
        .hero-badge .ic{font-size:24px}
        .hero-badge small{display:block;font-size:13px;color:var(--ss-body-color)}
        .hero-badge b{font-size:14.5px;color:var(--ss-heading-color);font-weight:600}
        .hero h1{font-size:clamp(3rem,2.0909rem + 2.9091vw,5rem);font-weight:800;letter-spacing:-.02em;margin:28px auto 0;max-width:17ch}
        .hero .sub{font-size:17.5px;max-width:60ch;margin:24px auto 0}

        .composer-wrap{max-width:680px;margin:44px auto 0;text-align:start;position:relative}
        .composer{background:var(--ss-card);border:1.5px solid var(--ss-border-color);border-radius:24px;padding:18px 18px 14px;box-shadow:var(--sh-lg);animation:glow-pulse 3.2s ease-in-out infinite;transition:border-color .25s}
        .composer:focus-within{border-color:var(--ss-primary)}
        .composer textarea{width:100%;min-height:78px;resize:none;background:transparent;border:0;outline:0;color:var(--ss-heading-color);font:400 16px/1.6 var(--font-body);padding:2px 4px;box-sizing:border-box}
        .composer textarea::placeholder{color:var(--ss-body-color);opacity:.75}
        .composer-bar{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:10px;flex-wrap:wrap}
        .attach-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
        .attach{display:inline-flex;align-items:center;gap:7px;border:1px solid var(--ss-border-color);background:var(--ss-light-2);color:var(--ss-body-color);border-radius:999px;padding:8px 15px;font-size:13.5px;font-weight:500;cursor:pointer;transition:all .2s;text-decoration:none}
        .attach:hover{border-color:var(--ss-primary);color:var(--ss-primary)}
        .mode-toggle{display:inline-flex;background:var(--ss-light-2);border-radius:999px;padding:4px;gap:2px}
        .mode-toggle button{border:0;background:transparent;color:var(--ss-body-color);font-size:12.5px;font-weight:600;padding:7px 14px;border-radius:999px;cursor:pointer;transition:all .22s;white-space:nowrap}
        .mode-toggle button.on{background:var(--ss-primary);color:#fff;box-shadow:0 6px 14px rgba(139,61,255,.3)}
        .send{width:44px;height:44px;border-radius:50%;border:0;background:var(--ss-primary);color:#fff;font-size:18px;cursor:pointer;display:grid;place-items:center;box-shadow:0 8px 18px rgba(139,61,255,.32);transition:all .22s;text-decoration:none}
        .send:hover{background:var(--ss-primary-hover);transform:scale(1.08)}
        .suggest-row{display:flex;gap:9px;justify-content:center;flex-wrap:wrap;margin-top:18px}
        .suggest{border:1px solid var(--ss-border-color);background:var(--ss-card);color:var(--ss-body-color);border-radius:999px;padding:8px 16px;font-size:13.5px;font-weight:500;cursor:pointer;transition:all .2s;box-shadow:var(--sh-sm);text-decoration:none}
        .suggest:hover{border-color:var(--ss-primary);color:var(--ss-primary);transform:translateY(-2px)}
        .hero-note{font-size:13px;margin-top:20px;opacity:.85}
        .hero-note b{color:#198754;font-weight:700}

        .marquee{overflow:hidden;position:relative;padding:18px 0;-webkit-mask-image:linear-gradient(90deg,transparent,#000 12%,#000 88%,transparent);mask-image:linear-gradient(90deg,transparent,#000 12%,#000 88%,transparent)}
        .marquee-track{display:flex;gap:16px;width:max-content;animation:marquee 36s linear infinite}
        .marquee:hover .marquee-track{animation-play-state:paused}
        .marquee .m-item{display:inline-flex;align-items:center;gap:10px;background:var(--ss-light-2);border-radius:16px;padding:14px 26px;font-weight:600;font-size:15px;color:var(--ss-heading-color);white-space:nowrap}
        .marquee .m-item .n{font-family:var(--font-head);font-weight:800;color:var(--ss-primary)}

        /* before / after showcase */
        .ba-stage{display:grid;grid-template-columns:1fr auto 1fr;gap:28px;align-items:center;margin-top:56px}
        @media(max-width:860px){.ba-stage{grid-template-columns:1fr;gap:18px}.ba-mid{transform:rotate(90deg)}}
        .menu-card{border-radius:22px;padding:28px 26px;min-height:360px;box-shadow:var(--sh-lg)}
        .before{background:repeating-linear-gradient(#FFFEF9,#FFFEF9 27px,#E9E4D2 28px);border:1px solid #E0D8C4;transform:rotate(-1.6deg);font-family:"Bradley Hand","Segoe Script","Comic Sans MS",cursive;color:#3A342B;position:relative}
        .before::after{content:"";position:absolute;top:-9px;left:50%;transform:translateX(-50%) rotate(2deg);width:88px;height:22px;background:rgba(214,203,175,.6);border:1px solid rgba(0,0,0,.05)}
        .before h3{font-family:inherit;font-size:1.5rem;color:#3A342B;text-align:center;margin-bottom:14px;text-decoration:underline;font-weight:700}
        .before ul{list-style:none;padding:0;margin:0;font-size:1.05rem;line-height:2.05}
        .before .scratch{text-decoration:line-through;opacity:.55}
        .after{background:#fff;border:1px solid #ece9f0;transform:rotate(.8deg);color:#211B15}
        .after .rest{font-family:Georgia,'Times New Roman',serif;text-align:center;font-size:1.45rem;font-weight:700;letter-spacing:.02em;color:#211B15}
        .after .rest-sub{text-align:center;font-size:.65rem;letter-spacing:.22em;text-transform:uppercase;color:#B08D3E;margin:4px 0 18px;font-weight:600}
        .m-section{font-size:.68rem;letter-spacing:.18em;text-transform:uppercase;color:var(--ss-primary);border-bottom:1px solid #ece9f0;padding-bottom:5px;margin:16px 0 10px;font-weight:700}
        .m-item{display:flex;align-items:baseline;gap:8px;font-size:.92rem;margin:7px 0}
        .m-item .nm{font-weight:600;white-space:nowrap}
        .m-item .dots{flex:1;border-bottom:1.5px dotted #C9BFA9;transform:translateY(-4px)}
        .m-item .pr{font-variant-numeric:tabular-nums;font-size:.85rem;font-weight:600}
        .m-desc{font-size:.78rem;color:#6E6357;margin:-3px 0 6px;font-style:italic}
        .ba-mid{text-align:center}
        .ba-mid .arrow{font-size:1.7rem;color:var(--ss-primary);display:inline-block}
        [dir="rtl"] .ba-mid .arrow{transform:scaleX(-1)}
        .ba-mid .ba-tag{font-size:.72rem;color:var(--ss-body-color);letter-spacing:.03em;display:block;margin-top:8px;max-width:140px;font-weight:600}

        .section{padding:110px 0 0}
        .sec-badge{display:inline-block;background:rgba(139,61,255,.1);color:var(--ss-primary);border-radius:999px;padding:8px 18px;font-size:13px;font-weight:700;letter-spacing:.02em;margin-bottom:18px}
        .sec-title{font-size:clamp(2rem,1.5rem + 1.6vw,3.1rem);font-weight:800;letter-spacing:-.015em;max-width:22ch}
        .sec-center{text-align:center}
        .sec-center .sec-title{margin:0 auto}
        .sec-sub{font-size:16.5px;max-width:58ch;margin:18px auto 0}

        .app-demo{display:grid;grid-template-columns:1fr 1.1fr;gap:64px;align-items:center;margin-top:56px}
        @media(max-width:900px){.app-demo{grid-template-columns:1fr;gap:40px}}
        .phone{width:300px;margin:0 auto;background:var(--ss-card);border:1px solid var(--ss-border-color);border-radius:44px;padding:12px;box-shadow:var(--sh-lg);position:relative}
        .phone .notch{width:100px;height:24px;background:var(--ss-card);border:1px solid var(--ss-border-color);border-top:0;border-radius:0 0 16px 16px;position:absolute;top:12px;left:50%;transform:translateX(-50%);z-index:3}
        .screen{position:relative;height:540px;border-radius:34px;overflow:hidden;background:var(--ss-light-2)}
        .scene{position:absolute;inset:0;padding:38px 16px 16px;opacity:0;transform:translateX(30px);transition:opacity .5s cubic-bezier(.16,1,.3,1),transform .5s cubic-bezier(.16,1,.3,1);pointer-events:none;display:flex;flex-direction:column;box-sizing:border-box}
        [dir="rtl"] .scene{transform:translateX(-30px)}
        .scene.on{opacity:1;transform:none;pointer-events:auto}
        .sc-head{font-family:var(--font-head);font-weight:700;font-size:16px;color:var(--ss-heading-color);display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
        .cart-pill{font-size:11px;font-weight:700;background:var(--ss-primary);color:#fff;border-radius:999px;padding:5px 11px;font-family:var(--font-body)}
        .cat-row{display:flex;gap:6px;margin-bottom:12px;flex-wrap:wrap}
        .cat{font-size:11px;font-weight:600;border:1px solid var(--ss-border-color);border-radius:999px;padding:5px 11px}
        .cat.on{background:var(--ss-heading-color);color:var(--ss-body-bg);border-color:var(--ss-heading-color)}
        .dish{display:flex;align-items:center;gap:10px;background:var(--ss-card);border:1px solid var(--ss-border-color);border-radius:16px;padding:9px 10px;margin-bottom:8px;box-shadow:var(--sh-sm)}
        .dish .ph{width:44px;height:44px;border-radius:12px;flex:0 0 auto;display:grid;place-items:center;font-size:20px;background:rgba(139,61,255,.1)}
        .dish .nm{font-size:12.5px;font-weight:600;color:var(--ss-heading-color);flex:1;line-height:1.3}
        .dish .nm small{display:block;font-weight:400;color:var(--ss-body-color);font-size:10.5px}
        .dish .pr{font-weight:700;font-size:12.5px;color:var(--ss-heading-color)}
        .dish .add{width:28px;height:28px;border-radius:9px;border:0;background:var(--ss-primary);color:#fff;font-size:15px;cursor:pointer;display:grid;place-items:center}
        .line{display:flex;justify-content:space-between;font-size:13px;padding:10px 2px;border-bottom:1px dashed var(--ss-border-color)}
        .line b{color:var(--ss-heading-color)}
        .line.total{border-bottom:0;font-weight:700;font-size:14.5px;margin-top:4px;color:var(--ss-heading-color)}
        .payrow{display:flex;gap:8px;margin:12px 0}
        .paychip{flex:1;border:1px solid var(--ss-border-color);border-radius:12px;padding:10px 0;text-align:center;font-size:11.5px;font-weight:700}
        .paychip.on{border-color:var(--ss-primary);color:var(--ss-primary);background:rgba(139,61,255,.08)}
        .paybtn{margin-top:auto;border:0;border-radius:999px;background:var(--ss-primary);color:#fff;font-weight:700;font-size:14px;padding:14px 0;width:100%;cursor:pointer;box-shadow:0 8px 18px rgba(139,61,255,.32)}
        .success{margin:auto;text-align:center}
        .success .ok{width:70px;height:70px;border-radius:50%;background:rgba(25,135,84,.12);color:#198754;display:grid;place-items:center;font-size:30px;margin:0 auto 16px;animation:pop .5s cubic-bezier(.34,1.56,.64,1)}
        .success b{font-size:15.5px;color:var(--ss-heading-color);font-family:var(--font-head)}
        .success p{font-size:12.5px;margin:8px 0 0}
        .steps-dots{display:flex;gap:8px;justify-content:center;margin-top:20px}
        .sdot{width:36px;height:6px;border-radius:99px;background:var(--ss-border-color);cursor:pointer;border:0;padding:0;transition:background .2s}
        .sdot.on{background:var(--ss-primary)}
        .app-copy .sec-title{max-width:none}
        .feat-list{list-style:none;padding:0;margin:28px 0 0;display:grid;gap:0}
        .feat-list li{display:flex;gap:14px;align-items:flex-start;padding:16px 0;border-top:1px solid var(--ss-border-color);font-size:15.5px}
        .feat-list .tick{width:26px;height:26px;border-radius:50%;background:rgba(139,61,255,.12);color:var(--ss-primary);display:grid;place-items:center;font-size:13px;font-weight:800;flex:0 0 auto}
        .feat-list b{color:var(--ss-heading-color);font-weight:700}

        .refine{max-width:640px;margin:56px auto 0;background:var(--ss-card);border:1px solid var(--ss-border-color);border-radius:24px;padding:22px 24px;display:flex;flex-direction:column;gap:12px;box-shadow:var(--sh)}
        .bub{border-radius:16px;padding:11px 17px;font-size:14.5px;max-width:82%}
        .bub.u{align-self:flex-end;background:var(--ss-primary);color:#fff;border-end-end-radius:5px}
        .bub.a{align-self:flex-start;background:var(--ss-light-2);color:var(--ss-heading-color);border-end-start-radius:5px}
        .refine-cap{text-align:center;font-size:13.5px;margin-top:16px}

        .outputs{display:grid;grid-template-columns:1.12fr .88fr;gap:20px;margin-top:56px}
        @media(max-width:860px){.outputs{grid-template-columns:1fr}}
        .out-card{background:var(--ss-light-2);border-radius:28px;padding:38px;transition:transform .35s cubic-bezier(.16,1,.3,1),box-shadow .35s}
        .out-card:hover{transform:translateY(-6px);box-shadow:var(--sh-lg)}
        .out-card .oi{width:52px;height:52px;border-radius:16px;background:var(--ss-card);display:grid;place-items:center;font-size:23px;margin-bottom:20px;box-shadow:var(--sh-sm)}
        .out-card h3{font-size:23px;margin-bottom:10px}
        .out-card p{font-size:15px;margin:0 0 18px}
        .tagrow{display:flex;flex-wrap:wrap;gap:8px}
        .tag{font-size:12.5px;font-weight:600;border-radius:999px;padding:7px 14px;background:var(--ss-card);color:var(--ss-heading-color);box-shadow:var(--sh-sm)}
        .gate{margin-top:20px;font-size:13px;font-weight:600;color:var(--ss-heading-color);border-inline-start:3px solid var(--ss-primary);padding:8px 14px;background:var(--ss-card);border-radius:0 12px 12px 0}
        [dir="rtl"] .gate{border-radius:12px 0 0 12px}

        .presets{display:grid;grid-template-columns:repeat(4,1fr);gap:18px;margin-top:56px}
        @media(max-width:860px){.presets{grid-template-columns:repeat(2,1fr)}}
        .preset{border:0;border-radius:22px;overflow:hidden;background:var(--ss-card);cursor:pointer;padding:0;text-align:start;box-shadow:var(--sh-sm);transition:transform .35s cubic-bezier(.16,1,.3,1),box-shadow .35s;text-decoration:none;display:block}
        .preset:hover{transform:translateY(-6px) rotate(-.5deg);box-shadow:var(--sh-lg)}
        .preset .shot{display:block;width:100%;height:240px;object-fit:cover;object-position:top;border-bottom:1px solid var(--ss-border-color);transition:object-position 2.5s ease}
        .preset:hover .shot{object-position:bottom}
        .preset .cap{padding:14px 17px;display:block}
        .preset .cap b{font-size:14.5px;font-weight:700;display:block;color:var(--ss-heading-color)}
        .preset .cap span{font-size:12.5px;color:var(--ss-body-color)}

        .steps{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;margin-top:56px}
        @media(max-width:860px){.steps{grid-template-columns:1fr}}
        .step{background:var(--ss-card);border-radius:26px;padding:34px;box-shadow:var(--sh-sm);position:relative;transition:transform .35s cubic-bezier(.16,1,.3,1)}
        .step:hover{transform:translateY(-5px)}
        .step .sn{font-family:var(--font-head);font-weight:800;font-size:44px;color:rgba(139,61,255,.18);line-height:1}
        .step h3{font-size:19px;margin:14px 0 10px}
        .step p{font-size:14.5px;margin:0}
        .step .tm{display:inline-block;margin-top:18px;font-size:12px;font-weight:700;background:rgba(139,61,255,.1);color:var(--ss-primary);border-radius:999px;padding:6px 13px}

        .credits{display:grid;grid-template-columns:1.05fr .95fr;gap:56px;align-items:center;margin-top:56px}
        @media(max-width:860px){.credits{grid-template-columns:1fr}}
        .credits .sec-title{font-size:clamp(1.7rem,1.3rem + 1.2vw,2.4rem)}
        .credits p{font-size:15.5px}
        .ledger{background:var(--ss-card);border-radius:28px;padding:30px;box-shadow:var(--sh-lg);font-size:14.5px}
        .ledger .head{font-family:var(--font-head);font-weight:700;letter-spacing:.02em;font-size:13px;text-transform:uppercase;color:var(--ss-body-color);border-bottom:1px solid var(--ss-border-color);padding-bottom:12px;margin-bottom:6px;display:flex;justify-content:space-between}
        .ledger .lr{display:flex;justify-content:space-between;padding:12px 0;border-bottom:1px dashed var(--ss-border-color);color:var(--ss-heading-color)}
        .ledger .lr:last-child{border-bottom:0;font-weight:800;font-family:var(--font-head)}
        .ledger .plus{color:#198754;font-weight:700}

        .reviews{overflow:hidden;margin-top:56px;-webkit-mask-image:linear-gradient(90deg,transparent,#000 8%,#000 92%,transparent);mask-image:linear-gradient(90deg,transparent,#000 8%,#000 92%,transparent)}
        .reviews-track{display:flex;gap:20px;width:max-content;animation:marquee 46s linear infinite}
        .reviews:hover .reviews-track{animation-play-state:paused}
        .review{width:340px;background:var(--ss-card);border-radius:24px;padding:28px;box-shadow:var(--sh-sm);flex:0 0 auto;white-space:normal}
        .review .stars{color:var(--ss-warning);letter-spacing:2px;font-size:14px;margin-bottom:12px}
        .review p{font-size:14.5px;color:var(--ss-heading-color);margin:0 0 18px;line-height:1.6}
        .review .who{display:flex;align-items:center;gap:11px}
        .review .av{width:38px;height:38px;border-radius:50%;background:rgba(139,61,255,.14);color:var(--ss-primary);display:grid;place-items:center;font-weight:800;font-size:14px}
        .review .who b{display:block;font-size:13.5px;color:var(--ss-heading-color)}
        .review .who small{font-size:12px;color:var(--ss-body-color)}

        .faq{max-width:680px;margin:48px auto 0}
        .faq details{background:var(--ss-card);border-radius:18px;margin-bottom:12px;box-shadow:var(--sh-sm);overflow:hidden}
        .faq summary{cursor:pointer;padding:20px 24px;font-family:var(--font-head);font-weight:700;font-size:16px;color:var(--ss-heading-color);list-style:none;display:flex;justify-content:space-between;align-items:center;gap:12px}
        .faq summary::-webkit-details-marker{display:none}
        .faq summary::after{content:"+";color:var(--ss-primary);font-size:22px;font-weight:400;transition:transform .25s}
        .faq details[open] summary::after{transform:rotate(45deg)}
        .faq .ans{padding:0 24px 20px;font-size:14.5px;max-width:62ch}

        .cta-band{position:relative;background:linear-gradient(135deg,#8b3dff,#6f2bd4 60%,#4c1d95);color:#fff;border-radius:36px;overflow:hidden;padding:76px 36px;text-align:center;margin-top:110px}
        .cta-band::before{content:"";position:absolute;width:340px;height:340px;border-radius:50%;background:rgba(255,255,255,.09);top:-140px;inset-inline-end:-80px;animation:floaty 10s ease-in-out infinite}
        .cta-band::after{content:"";position:absolute;width:220px;height:220px;border-radius:50%;background:rgba(255,255,255,.07);bottom:-90px;inset-inline-start:-40px;animation:floaty2 8s ease-in-out infinite}
        .cta-band>*{position:relative;z-index:1}
        .cta-band h2{color:#fff;font-size:clamp(2rem,1.5rem + 1.8vw,3.2rem);font-weight:800;max-width:22ch;margin:0 auto 16px}
        .cta-band p{color:rgba(255,255,255,.85);max-width:50ch;margin:0 auto 32px;font-size:16.5px}
        .cta-band .btn{background:#fff;color:var(--ss-primary);box-shadow:0 14px 30px rgba(0,0,0,.25);font-weight:700}
        .cta-band .btn:hover{transform:translateY(-2px) scale(1.02)}
        .cta-band .mini{font-size:13px;color:rgba(255,255,255,.7);margin-top:18px}

        footer{margin-top:90px;background:var(--ss-dark);color:#a7a5b3;padding:44px 0}
        html[data-theme="dark"] footer{background:#08070d}
        .foot-row{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;align-items:center;font-size:14px}
        .foot-row .fb{font-family:var(--font-head);font-weight:800;color:#fff;font-size:17px}
        footer a{color:inherit;text-decoration:none}
        footer a:hover{color:#fff}
    </style>
</head>
<body data-guest-session="{{ $session->guest_token }}">

<div class="preloader" id="preloader" role="status" aria-live="polite" aria-label="{{ ___('Loading') }}">
    <div class="preloader__inner">
        <div class="preloader__mark"><span></span><span></span><span></span></div>
        <div class="preloader__row"><span>{{ ___('Loading Studio') }}</span><b id="plCount">0%</b></div>
        <div class="preloader__bar"><i id="plBar"></i></div>
    </div>
</div>

<header id="hdr">
    <div class="container nav-row">
        <a class="logo" href="{{ route('studio') }}"><span class="mk">&#10022;</span><span>{{ ___('Menu Studio') }}</span></a>
        <nav class="nav-links">
            <a href="#showcase">{{ ___('Examples') }}</a>
            <a href="#app">{{ ___('Ordering app') }}</a>
            <a href="#how">{{ ___('How it works') }}</a>
            <a href="#pricing">{{ ___('Credits') }}</a>
            <a href="#faq">{{ ___('FAQ') }}</a>
        </nav>
        <div class="nav-tools">
            @if(($languages ?? collect())->count() > 1)
            <details class="lang" id="langBox">
                <summary class="pill-btn">&#127760; {{ strtoupper(get_lang()) }}</summary>
                <div class="menu">
                    @foreach($languages as $lang)
                        <a class="{{ $lang->code === get_lang() ? 'on' : '' }}" href="{{ lang_url($lang->code) }}">
                            <span class="cc">{{ strtoupper($lang->code) }}</span>{{ $lang->name }}
                        </a>
                    @endforeach
                </div>
            </details>
            @endif
            <button class="pill-btn" id="themeBtn" aria-label="{{ ___('Toggle dark mode') }}" style="width:40px;padding:0;justify-content:center">&#9790;</button>
            <a class="btn btn-primary" href="{{ route('studio.app.shell') }}" style="padding:11px 22px">{{ ___('Open Studio') }}</a>
        </div>
    </div>
</header>

<section class="hero">
    <svg class="curve" viewBox="0 0 1440 800" preserveAspectRatio="none" aria-hidden="true">
        <path d="M0,620 C300,520 520,760 780,660 C1040,560 1180,700 1440,600 L1440,800 L0,800 Z" fill="rgba(139,61,255,.05)"/>
        <path d="M0,700 C360,620 620,800 940,720 C1180,660 1300,740 1440,690 L1440,800 L0,800 Z" fill="rgba(139,61,255,.07)"/>
    </svg>
    <span class="shape shape-1" data-para="14"></span>
    <span class="shape shape-2" data-para="26"></span>
    <span class="shape shape-3" data-para="20"></span>
    <span class="shape shape-4" data-para="34"></span>

    <div class="container" style="position:relative;z-index:1">
        <div class="hero-badge">
            <span class="ic">&#129668;</span>
            <span><small>{{ ___('AI menu maker — free, no signup to try') }}</small><b>{{ ___('Part of the platform trusted by restaurants worldwide') }}</b></span>
        </div>

        <h1 id="heroTitle">{{ ___('Any menu becomes a') }} <span class="accent-i">{{ ___('full ordering app') }}</span></h1>

        <p class="sub">{{ ___('Drop a photo, PDF, or handwritten page. The AI reads every dish and builds a live menu your guests browse, order and pay from — or a print-ready designed PDF. In about sixty seconds.') }}</p>

        <div class="composer-wrap">
            <div class="composer">
                <textarea id="promptBox" rows="3" placeholder=""></textarea>
                <div class="composer-bar">
                    <span class="attach-row">
                        <a class="attach" href="{{ route('studio.app.shell') }}">&#128206; {{ ___('Attach menu') }}</a>
                        <a class="attach" href="{{ route('studio.app.shell') }}">&#128247; {{ ___('Photo') }}</a>
                        <a class="attach" href="{{ route('studio.app.shell') }}">&#128196; PDF</a>
                        <span class="mode-toggle" role="group">
                            <button class="on" data-mode="ordering">{{ ___('Ordering app') }}</button>
                            <button data-mode="pdf">{{ ___('PDF menu') }}</button>
                        </span>
                    </span>
                    <a class="send" id="sendBtn" href="{{ route('studio.app.shell') }}" aria-label="{{ ___('Convert') }}">&#8593;</a>
                </div>
            </div>
        </div>

        <div class="suggest-row">
            <a class="suggest" href="{{ route('studio.app.shell') }}">&#127829; {{ ___('Try the trattoria sample') }}</a>
            <a class="suggest" href="{{ route('studio.app.shell') }}">&#127843; {{ ___('Sushi bar sample') }}</a>
            <a class="suggest" href="{{ route('studio.app.shell') }}">&#9997;&#65039; {{ ___('Handwritten menu') }}</a>
        </div>
        <p class="hero-note"><b>&#10003;</b> {{ ___('No signup · uploads auto-delete in 48 h · never used to train models') }}</p>
    </div>
</section>

<div class="marquee" aria-label="{{ ___('Live stats') }}">
    <div class="marquee-track" id="statTrack">
        <span class="m-item">&#128196; <span class="n">4,208+</span>&nbsp;{{ ___('menus converted') }}</span>
        <span class="m-item">&#127837; <span class="n">61,540+</span>&nbsp;{{ ___('dishes extracted') }}</span>
        <span class="m-item">&#127757; <span class="n">{{ ($languages ?? collect())->count() ?: 24 }}</span>&nbsp;{{ ___('languages · RTL native') }}</span>
        <span class="m-item">&#11088; <span class="n">4.9</span>&nbsp;{{ ___('from restaurant owners') }}</span>
        <span class="m-item">&#9889; <span class="n">~60s</span>&nbsp;{{ ___('photo → live menu') }}</span>
    </div>
</div>

<div class="container">
    <section class="section" id="showcase">
        <div class="sec-center reveal">
            <span class="sec-badge">{{ ___('Proof, not promises') }}</span>
            <h2 class="sec-title">{{ ___('From the page on your counter to the') }} <span class="accent-i">{{ ___('menu on their phone') }}</span></h2>
            <p class="sec-sub">{{ ___('A real conversion: one photographed, handwritten menu — read, structured, and re-designed by the AI.') }}</p>
        </div>

        <div class="ba-stage">
            <div class="menu-card before reveal" aria-label="{{ ___('Before: handwritten menu') }}">
                <h3>Nonna's Kitchen</h3>
                <ul>
                    <li>Bruschetta ..... 6</li>
                    <li>Caprese salad ... 8.5</li>
                    <li class="scratch">Minestrone — 7</li>
                    <li>Rigatoni al forno .. 14</li>
                    <li>Osso buco (ask!) . 22</li>
                    <li>Tiramisu ....... 7</li>
                    <li>House red / glass . 6</li>
                </ul>
            </div>

            <div class="ba-mid reveal">
                <span class="arrow" aria-hidden="true">&#10140;</span>
                <span class="ba-tag">12 {{ ___('dishes') }} · 4 {{ ___('sections') }} · {{ ___('prices & notes kept') }} · 38 s</span>
            </div>

            <div class="menu-card after reveal" aria-label="{{ ___('After: designed menu') }}">
                <div class="rest">Nonna's Kitchen</div>
                <div class="rest-sub">Cucina Casalinga · Est. 1987</div>
                <div class="m-section">Antipasti</div>
                <div class="m-item"><span class="nm">Bruschetta</span><span class="dots"></span><span class="pr">6.00</span></div>
                <div class="m-desc">{{ ___('grilled bread, tomato, basil, olive oil') }}</div>
                <div class="m-item"><span class="nm">Caprese Salad</span><span class="dots"></span><span class="pr">8.50</span></div>
                <div class="m-section">Primi &amp; Secondi</div>
                <div class="m-item"><span class="nm">Rigatoni al Forno</span><span class="dots"></span><span class="pr">14.00</span></div>
                <div class="m-item"><span class="nm">Osso Buco</span><span class="dots"></span><span class="pr">22.00</span></div>
                <div class="m-desc">{{ ___("chef's choice — ask for today's preparation") }}</div>
                <div class="m-section">Dolci</div>
                <div class="m-item"><span class="nm">Tiramisu</span><span class="dots"></span><span class="pr">7.00</span></div>
            </div>
        </div>
    </section>

    <section class="section" id="app">
        <div class="sec-center reveal">
            <span class="sec-badge">{{ ___('Not just a pretty menu') }}</span>
            <h2 class="sec-title">{{ ___('Your photo becomes a') }} <span class="accent-i">{{ ___('real app') }}</span> — {{ ___('cart, checkout and all') }}</h2>
            <p class="sec-sub">{{ ___('Guests scan a QR code and order straight from their phone. You get the order in the kitchen, they pay how they like.') }}</p>
        </div>

        <div class="app-demo">
            <div class="reveal">
                <div class="phone">
                    <span class="notch"></span>
                    <div class="screen" id="phoneScreen">
                        <div class="scene on">
                            <div class="sc-head"><span>{{ ___("Nonna's Kitchen") }}</span><span class="cart-pill">&#128722; 2</span></div>
                            <div class="cat-row"><span class="cat on">{{ ___('Starters') }}</span><span class="cat">{{ ___('Mains') }}</span><span class="cat">{{ ___('Desserts') }}</span><span class="cat">{{ ___('Drinks') }}</span></div>
                            <div class="dish"><span class="ph">&#127813;</span><span class="nm">Bruschetta<small>{{ ___('grilled bread, tomato, basil') }}</small></span><span class="pr">6.00</span><button class="add" type="button">+</button></div>
                            <div class="dish"><span class="ph">&#129472;</span><span class="nm">Caprese<small>{{ ___('buffalo mozzarella, basil') }}</small></span><span class="pr">8.50</span><button class="add" type="button">+</button></div>
                            <div class="dish"><span class="ph">&#127837;</span><span class="nm">Rigatoni al Forno<small>{{ ___('baked, smoked scamorza') }}</small></span><span class="pr">14.00</span><button class="add" type="button">+</button></div>
                            <div class="dish"><span class="ph">&#127854;</span><span class="nm">Tiramisu<small>{{ ___('house-made, espresso') }}</small></span><span class="pr">7.00</span><button class="add" type="button">+</button></div>
                        </div>
                        <div class="scene">
                            <div class="sc-head"><span>{{ ___('Your order') }}</span><span class="cart-pill">{{ ___('Table') }} 12</span></div>
                            <div class="line"><span>1 × Bruschetta</span><b>6.00</b></div>
                            <div class="line"><span>1 × Rigatoni al Forno<br><small>+ {{ ___('extra scamorza') }}</small></span><b>15.50</b></div>
                            <div class="line"><span>1 × Tiramisu</span><b>7.00</b></div>
                            <div class="line"><span>{{ ___('Service') }}</span><b>1.40</b></div>
                            <div class="line total"><span>{{ ___('Total') }}</span><b>29.90</b></div>
                            <button class="paybtn" type="button">{{ ___('Go to checkout') }} &rarr;</button>
                        </div>
                        <div class="scene">
                            <div class="sc-head"><span>{{ ___('Checkout') }}</span><b>29.90</b></div>
                            <div class="payrow"><span class="paychip on">&#63743; Pay</span><span class="paychip">{{ ___('Card') }}</span><span class="paychip">{{ ___('Cash') }}</span></div>
                            <div class="line"><span>{{ ___('Table') }}</span><b>12</b></div>
                            <div class="line"><span>{{ ___('Notes') }}</span><span>{{ ___('no onions') }} &#128591;</span></div>
                            <div class="line"><span>{{ ___('Tip') }}</span><b>10%</b></div>
                            <button class="paybtn" type="button">{{ ___('Pay 32.90 — send to kitchen') }}</button>
                        </div>
                        <div class="scene">
                            <div class="success">
                                <div class="ok">&#10003;</div>
                                <b>{{ ___('Order sent to the kitchen') }}</b>
                                <p>#184 · {{ ___('Table') }} 12 · ~15 {{ ___('min') }}. {{ ___("You'll get a ping when it's ready.") }}</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="steps-dots" id="sceneDots">
                    <button class="sdot on" type="button" aria-label="{{ ___('Menu') }}"></button>
                    <button class="sdot" type="button" aria-label="{{ ___('Cart') }}"></button>
                    <button class="sdot" type="button" aria-label="{{ ___('Checkout') }}"></button>
                    <button class="sdot" type="button" aria-label="{{ ___('Confirmation') }}"></button>
                </div>
            </div>

            <div class="app-copy reveal">
                <span class="sec-badge">{{ ___('The same upload powers it all') }}</span>
                <h3 class="sec-title" style="font-size:clamp(1.6rem,1.2rem + 1.3vw,2.3rem)">{{ ___('From coffee-stained paper to “order sent to kitchen”') }}</h3>
                <p style="margin-top:14px;font-size:15.5px">{{ ___("Everything below ships with the menu the AI builds — it's the same engine that runs the full restaurant platform.") }}</p>
                <ul class="feat-list">
                    <li><span class="tick">&#10003;</span><span><b>{{ ___('QR ordering & cart') }}</b> — {{ ___('guests order from the table, delivery or pickup') }}</span></li>
                    <li><span class="tick">&#10003;</span><span><b>{{ ___('Checkout & payments') }}</b> — {{ ___('Apple Pay, cards, or cash at the counter') }}</span></li>
                    <li><span class="tick">&#10003;</span><span><b>{{ ___('Live kitchen orders') }}</b> — {{ ___('orders land on a kitchen screen, not a printer tray') }}</span></li>
                    <li><span class="tick">&#10003;</span><span><b>{{ ___('Edit any time') }}</b> — {{ ___('pause a dish or change a price from your phone') }}</span></li>
                </ul>
            </div>
        </div>

        <div class="refine reveal">
            <span class="bub u">{{ ___('make all prices 10% higher and translate to Arabic') }}</span>
            <span class="bub a">{{ ___('Done — 11 prices updated, Arabic version added with RTL layout. Anything else?') }}</span>
        </div>
        <p class="refine-cap reveal">{{ ___('After the upload, you refine everything by chatting — no menu editor to learn.') }}</p>
    </section>

    <section class="section">
        <div class="sec-center reveal">
            <span class="sec-badge">{{ ___('One upload, two endings') }}</span>
            <h2 class="sec-title">{{ ___('Take it') }} <span class="accent-i">{{ ___('online') }}</span>, {{ ___('or take it to') }} <span class="accent-i">{{ ___('print') }}</span></h2>
        </div>
        <div class="outputs">
            <article class="out-card reveal">
                <span class="oi">&#128241;</span>
                <h3>{{ ___('Full ordering app') }}</h3>
                <p>{{ ___('A live, hosted menu with cart and checkout — guests scan, order and pay. Preview and refine free for as long as you like.') }}</p>
                <span class="tagrow"><span class="tag">{{ ___('QR code') }}</span><span class="tag">{{ ___('Cart + checkout') }}</span><span class="tag">{{ ___('Multi-language') }}</span><span class="tag">{{ ___('Edit anytime') }}</span></span>
                <div class="gate">{{ ___("Publish → sign in with Google. That's the whole gate.") }}</div>
            </article>
            <article class="out-card reveal" style="transition-delay:.12s">
                <span class="oi">&#128424;&#65039;</span>
                <h3>{{ ___('Designed PDF menu') }}</h3>
                <p>{{ ___('The AI art-directs a print-ready menu around your dishes. Watermarked preview is always free; regenerate until it fits.') }}</p>
                <span class="tagrow"><span class="tag">{{ ___('Style presets') }}</span><span class="tag">{{ ___('Print-ready') }}</span><span class="tag">{{ ___('Unlimited previews') }}</span></span>
                <div class="gate">{{ ___('Clean download → a few credits. You start with free ones.') }}</div>
            </article>
        </div>
    </section>

    <section class="section">
        <div class="sec-center reveal">
            <span class="sec-badge">{{ ___('À la carte styles') }}</span>
            <h2 class="sec-title">{{ ___('Pick a style — the AI') }} <span class="accent-i">{{ ___('designs around it') }}</span></h2>
        </div>
        <div class="presets">
            @php
                $presetMeta = [
                    'fine_dining' => ['img' => '/assets/store_templates/velvet/preview.png',   'cap' => ___('Fine dining'), 'd' => ___('dark, elegant, photo-led')],
                    'cafe'        => ['img' => '/assets/store_templates/matcha/preview.png', 'cap' => ___('Café'),        'd' => ___('soft, warm, friendly')],
                    'street_food' => ['img' => '/assets/store_templates/lime/preview.png',   'cap' => ___('Street food'), 'd' => ___('bold, fast, loud')],
                    'minimal'     => ['img' => '/assets/store_templates/pandabite/preview.png',    'cap' => ___('Minimal'),     'd' => ___('clean, airy, modern')],
                ];
            @endphp
            @foreach(($presets ?? []) as $key => $seed)
                @php $m = $presetMeta[$key] ?? ['img' => '/assets/store_templates/minimal/preview.png', 'cap' => ___(ucwords(str_replace('_',' ',$key))), 'd' => ___('Style preset')]; @endphp
                <a class="preset reveal" href="{{ route('studio.app.shell') }}">
                    <img class="shot" src="{{ $m['img'] }}" alt="{{ $m['cap'] }} — {{ ___('real menu made with Menu Studio') }}" loading="lazy">
                    <span class="cap"><b>{{ $m['cap'] }}</b><span>{{ $m['d'] }}</span></span>
                </a>
            @endforeach
        </div>
    </section>

    <section class="section" id="how">
        <div class="sec-center reveal">
            <span class="sec-badge">{{ ___('Sixty seconds, three moves') }}</span>
            <h2 class="sec-title">{{ ___('How it works') }}</h2>
        </div>
        <div class="steps">
            <div class="step reveal"><span class="sn">01</span><h3>{{ ___('Upload') }}</h3><p>{{ ___('A photo, a PDF, a handwritten page — even the laminated one with the coffee stain.') }}</p><span class="tm">~10 {{ ___('sec') }}</span></div>
            <div class="step reveal" style="transition-delay:.1s"><span class="sn">02</span><h3>{{ ___('Review & refine') }}</h3><p>{{ ___('The AI extracts dishes, prices and sections. Fix anything by chatting: “move desserts up”, “translate to Arabic”.') }}</p><span class="tm">~1 {{ ___('min') }}</span></div>
            <div class="step reveal" style="transition-delay:.2s"><span class="sn">03</span><h3>{{ ___('Publish or download') }}</h3><p>{{ ___('Go live with the QR ordering app, or download the designed PDF for print.') }}</p><span class="tm">{{ ___('1 tap') }}</span></div>
        </div>
    </section>

    <section class="section" id="pricing">
        <div class="credits">
            <div class="reveal">
                <span class="sec-badge">{{ ___('On the house') }}</span>
                <h3 class="sec-title">{{ ___('Start with') }} <span class="accent-i">{{ ___('free credits') }}</span>. {{ ___('Keep them forever.') }}</h3>
                <p style="margin-top:16px">{{ ___('Exploring, uploading, previewing and refining cost nothing — no card, no account. Credits only come in when you download a clean, print-ready PDF. Sign in with Google to top up; bundles never expire.') }}</p>
                <p>{{ ___("And when you're ready for the full restaurant — tables, kitchen screens, analytics — your studio menu comes with you, already built.") }}</p>
            </div>
            <div class="ledger reveal" aria-label="{{ ___('Example credit ledger') }}">
                <div class="head"><span>{{ ___('Your tab') }}</span><span>{{ ___('credits') }}</span></div>
                <div class="lr"><span>{{ ___('Welcome balance') }}</span><span class="plus">+10</span></div>
                <div class="lr"><span>{{ ___('Signed in with Google') }}</span><span class="plus">+15</span></div>
                <div class="lr"><span>{{ ___('Styled PDF download') }}</span><span>&minus;8</span></div>
                <div class="lr"><span>{{ ___('Balance') }}</span><span>17</span></div>
            </div>
        </div>
    </section>

    <section class="section">
        <div class="sec-center reveal">
            <span class="sec-badge">{{ ___('Word of mouth') }}</span>
            <h2 class="sec-title">{{ ___('Owners who') }} <span class="accent-i">{{ ___('already remade') }}</span> {{ ___('their menus') }}</h2>
        </div>
        <div class="reviews reveal">
            <div class="reviews-track" id="rvTrack">
                <article class="review"><div class="stars">&#9733;&#9733;&#9733;&#9733;&#9733;</div><p>“{{ ___('I photographed the menu taped to my register. Twenty minutes later customers were ordering from their phones.') }}”</p><div class="who"><span class="av">S</span><span><b>Sara K.</b><small>{{ ___('Café owner') }} · Amman</small></span></div></article>
                <article class="review"><div class="stars">&#9733;&#9733;&#9733;&#9733;&#9733;</div><p>“{{ ___('The PDF it designed looked better than the one we paid a designer for last year. We printed it the same week.') }}”</p><div class="who"><span class="av">M</span><span><b>Marco T.</b><small>{{ ___('Trattoria') }} · Lisbon</small></span></div></article>
                <article class="review"><div class="stars">&#9733;&#9733;&#9733;&#9733;&#9733;</div><p>“{{ ___('Handwritten Arabic menu, read perfectly — prices, sections, everything. The RTL layout just worked.') }}”</p><div class="who"><span class="av">H</span><span><b>Huda A.</b><small>{{ ___('Grill house') }} · Riyadh</small></span></div></article>
                <article class="review"><div class="stars">&#9733;&#9733;&#9733;&#9733;&#9733;</div><p>“{{ ___('We went from a laminated card to QR ordering with Apple Pay in one afternoon. Orders go straight to the kitchen screen.') }}”</p><div class="who"><span class="av">D</span><span><b>Diego R.</b><small>{{ ___('Taquería') }} · Mexico City</small></span></div></article>
            </div>
        </div>
    </section>

    <section class="section" id="faq">
        <div class="sec-center reveal">
            <span class="sec-badge">{{ ___('Before you ask') }}</span>
            <h2 class="sec-title">{{ ___('Questions, answered') }}</h2>
        </div>
        <div class="faq reveal">
            <details><summary>{{ ___('Is it really free?') }}</summary><div class="ans">{{ ___('Yes — uploading, extracting, previewing and refining are free with no account. You pay only to download a clean designed PDF (credits) or when you upgrade to the full ordering platform. Publishing a basic online menu just needs a Google sign-in.') }}</div></details>
            <details><summary>{{ ___('Can guests really order and pay through it?') }}</summary><div class="ans">{{ ___("Yes. The published menu is a full ordering app: cart, table or pickup, checkout, and orders flowing to a kitchen screen. It's the same engine behind the whole restaurant platform.") }}</div></details>
            <details><summary>{{ ___('What happens to my uploads?') }}</summary><div class="ans">{{ ___('Guest uploads auto-delete within 48 hours unless you sign in to keep them. Your menus are never used to train AI models.') }}</div></details>
            <details><summary>{{ ___('Can it read handwriting? Arabic?') }}</summary><div class="ans">{{ ___('Yes to both. It reads handwritten pages, photos and PDFs, and works in many languages including full right-to-left Arabic menus.') }}</div></details>
        </div>
    </section>

    <div class="cta-band reveal">
        <h2>{{ ___("Your menu, remade — while the coffee's still hot") }}</h2>
        <p>{{ ___("One photo. Sixty seconds. An ordering app your guests can pay through, or a print-ready design you'll be proud to laminate.") }}</p>
        <a class="btn" href="{{ route('studio.app.shell') }}">&#10022; {{ ___('Convert my menu — free') }}</a>
        <p class="mini">{{ ___('No signup · no card · uploads auto-delete in 48 h') }}</p>
    </div>
</div>

<footer>
    <div class="container foot-row">
        <span class="fb">{{ ___('Menu Studio') }}</span>
        <span>{{ ___('Part of') }} <a href="{{ url('/') }}"><b>{{ config('settings.website_title', config('app.name')) }}</b></a></span>
    </div>
</footer>

<script nonce="{{ csp_nonce() }}">
(function(){
    var reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* preloader */
    var pl = document.getElementById('preloader'), plc = document.getElementById('plCount'), plb = document.getElementById('plBar');
    if (reduced){ pl.classList.add('done'); revealHero(); }
    else {
        var p = 0;
        var iv = setInterval(function(){
            p = Math.min(100, p + Math.ceil(Math.random()*22));
            plc.textContent = p + '%'; plb.style.width = p + '%';
            if (p >= 100){ clearInterval(iv); setTimeout(function(){ pl.classList.add('done'); revealHero(); }, 250); }
        }, 120);
    }

    /* hero word-by-word blur reveal */
    function splitWords(el){
        var parts = [];
        el.childNodes.forEach(function(n){
            if (n.nodeType === 3){
                n.textContent.split(/\s+/).filter(Boolean).forEach(function(w){
                    var s = document.createElement('span'); s.className = 'w-blur'; s.textContent = w; parts.push(s);
                });
            } else if (n.nodeType === 1){
                var s = document.createElement('span'); s.className = 'w-blur'; s.appendChild(n.cloneNode(true)); parts.push(s);
            }
        });
        el.textContent = '';
        parts.forEach(function(s,i){
            s.style.animationDelay = (0.08*i) + 's';
            el.appendChild(s); el.appendChild(document.createTextNode(' '));
        });
    }
    function revealHero(){ if (!reduced) splitWords(document.getElementById('heroTitle')); }

    /* sticky header */
    var hdr = document.getElementById('hdr');
    addEventListener('scroll', function(){ hdr.classList.toggle('scrolled', scrollY > 30); }, {passive:true});

    /* parallax shapes */
    if (!reduced){
        var shapes = document.querySelectorAll('[data-para]');
        addEventListener('scroll', function(){
            var y = scrollY;
            shapes.forEach(function(s){ s.style.marginTop = -(y / +s.dataset.para) + 'px'; });
        }, {passive:true});
    }

    /* theme toggle — same localStorage key as the home landing */
    var themeBtn = document.getElementById('themeBtn');
    if (document.documentElement.getAttribute('data-theme') === 'dark') themeBtn.innerHTML = '&#9788;';
    themeBtn.addEventListener('click', function(){
        var dark = document.documentElement.getAttribute('data-theme') === 'dark';
        document.documentElement.setAttribute('data-theme', dark ? 'light' : 'dark');
        themeBtn.innerHTML = dark ? '&#9790;' : '&#9788;';
        try { localStorage.setItem('landing-theme', dark ? 'light' : 'dark'); } catch(e){}
    });

    /* scroll reveals */
    var io = new IntersectionObserver(function(es){
        es.forEach(function(e){ if(e.isIntersecting){ e.target.classList.add('in'); io.unobserve(e.target); } });
    },{threshold:.1});
    document.querySelectorAll('.reveal').forEach(function(el){ io.observe(el); });

    /* marquee: duplicate tracks for seamless loop */
    ['statTrack','rvTrack'].forEach(function(id){
        var t = document.getElementById(id); t.innerHTML += t.innerHTML;
    });

    /* composer typing placeholder */
    var box = document.getElementById('promptBox');
    var lines = [
        @json(___('Here’s a photo of my menu — make it an online ordering menu…')),
        @json(___('Convert this PDF price list, keep my prices exactly…')),
        @json(___('Read this handwritten page and design a fine-dining PDF…'))
    ];
    var li=0, ci=0, dir=1, timer;
    function typeLoop(){
        if (reduced){ box.placeholder = lines[0]; return; }
        var line = lines[li];
        ci += dir;
        box.placeholder = line.slice(0, ci);
        var d = dir>0 ? 36 : 13;
        if (dir>0 && ci>=line.length){ d=2200; dir=-1; }
        if (dir<0 && ci<=0){ dir=1; li=(li+1)%lines.length; d=500; }
        timer = setTimeout(typeLoop, d);
    }
    typeLoop();
    box.addEventListener('focus', function(){ clearTimeout(timer); box.placeholder = lines[0]; });

    /* mode toggle + send: carry text & mode into the studio app */
    var mode = 'ordering';
    document.querySelectorAll('.mode-toggle button').forEach(function(b){
        b.addEventListener('click', function(){
            b.parentElement.querySelectorAll('button').forEach(function(x){ x.classList.remove('on'); });
            b.classList.add('on'); mode = b.dataset.mode;
        });
    });
    var sendBtn = document.getElementById('sendBtn');
    sendBtn.addEventListener('click', function(e){
        var url = new URL(sendBtn.href);
        url.searchParams.set('output', mode);
        if (box.value.trim()) url.searchParams.set('prompt', box.value.trim());
        sendBtn.href = url.toString();
    });

    /* phone scenes */
    var scenes = document.querySelectorAll('#phoneScreen .scene');
    var dots = document.querySelectorAll('#sceneDots .sdot');
    var cur = 0, auto;
    function show(i){ cur=i;
        scenes.forEach(function(s,k){ s.classList.toggle('on',k===i); });
        dots.forEach(function(d,k){ d.classList.toggle('on',k===i); });
    }
    dots.forEach(function(d,i){ d.addEventListener('click', function(){ show(i); restart(); }); });
    function restart(){ clearInterval(auto); if(!reduced) auto=setInterval(function(){ show((cur+1)%scenes.length); },3400); }
    restart();
    var phone = document.querySelector('.phone');
    phone.addEventListener('mouseenter', function(){ clearInterval(auto); });
    phone.addEventListener('mouseleave', restart);
})();
</script>
</body>
</html>
