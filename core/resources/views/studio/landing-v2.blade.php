{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php $cur = optional(current_language()); @endphp
<!DOCTYPE html>
<html lang="{{ get_lang() }}" dir="{{ $cur->direction ?? 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ ___('Menu Studio') }} — {{ config('settings.website_title', config('app.name')) }}</title>
    <meta name="description" content="{{ ___('Turn a photo, PDF or handwritten menu into a full online-ordering app or a print-ready designed PDF — free, no signup.') }}">
    {{-- Alternate design of /studio: keep out of the index, point crawlers at the canonical page. --}}
    <meta name="robots" content="noindex,follow">
    <link rel="canonical" href="{{ route('studio') }}">
    @if(!empty(config('settings.site_favicon')))
    <link rel="icon" href="{{ asset('storage/logo/'.config('settings.site_favicon')) }}">
    @endif

    <script nonce="{{ csp_nonce() }}">
        (function () {
            try {
                var t = localStorage.getItem('landing-theme');
                if (!t) { t = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light'; }
                document.documentElement.setAttribute('data-theme', t);
            } catch (e) {}
        })();
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400..700&display=swap" rel="stylesheet">

    <style nonce="{{ csp_nonce() }}">
        /* Linen design-system tokens (own inline copy; the theme file they mirrored is gone) */
        :root{
            --c-brand:#10b981; --c-brand-hi:#059669; --c-brand-lo:#34d399;
            --c-brand-soft:#ecfdf5; --c-brand-ring:rgba(16,185,129,.18); --c-brand-text:#065f46; --c-on-brand:#fff;
            --c-bg:#ffffff; --c-bg-subtle:#fafafa; --c-bg-muted:#f5f5f5;
            --c-border:#e7e7e9; --c-border-hi:#d4d4d6; --c-border-strong:#a3a3a3;
            --c-text:#0a0a0a; --c-text-muted:#525252; --c-text-faint:#a3a3a3;
            --c-warning:#f59e0b;
            --r-sm:8px; --r-md:10px; --r-lg:14px; --r-xl:20px; --r-2xl:28px; --r-pill:999px;
            --sh-md:0 4px 12px rgba(10,10,10,.06),0 1px 2px rgba(10,10,10,.04);
            --sh-lg:0 12px 28px rgba(10,10,10,.08),0 2px 4px rgba(10,10,10,.04);
            --sh-xl:0 24px 48px rgba(10,10,10,.12),0 8px 16px rgba(10,10,10,.04);
            --ease-out:cubic-bezier(.16,1,.3,1); --ease-overshoot:cubic-bezier(.34,1.56,.64,1);
            --dur:200ms; --dur-slow:320ms;
            --container:1140px;
            --font-sans:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
            --font-mono:'Geist Mono','SF Mono',Menlo,monospace;
        }
        [data-theme="dark"]{
            --c-brand:#10b981; --c-brand-hi:#34d399; --c-brand-soft:rgba(16,185,129,.10);
            --c-brand-ring:rgba(52,211,153,.24); --c-brand-text:#6ee7b7; --c-on-brand:#052e1c;
            --c-bg:#0a0a0a; --c-bg-subtle:#111111; --c-bg-muted:#1a1a1a;
            --c-border:#1f1f1f; --c-border-hi:#2e2e2e; --c-border-strong:#525252;
            --c-text:#fafafa; --c-text-muted:#a3a3a3; --c-text-faint:#525252;
            --sh-md:0 4px 12px rgba(0,0,0,.4); --sh-lg:0 12px 28px rgba(0,0,0,.5); --sh-xl:0 24px 48px rgba(0,0,0,.6);
        }
        html{scroll-behavior:smooth}
        body{margin:0;background:var(--c-bg);color:var(--c-text);font-family:var(--font-sans);line-height:1.55;transition:background var(--dur-slow) var(--ease-out),color var(--dur-slow) var(--ease-out)}
        .container{max-width:var(--container);margin:0 auto;padding:0 24px}
        h1,h2,h3{text-wrap:balance;margin:0}
        button{font:inherit}
        :focus-visible{outline:2px solid var(--c-brand);outline-offset:3px;border-radius:4px}

        .reveal{opacity:0;transform:translateY(26px);transition:opacity .7s var(--ease-out),transform .7s var(--ease-out)}
        .reveal.in{opacity:1;transform:none}
        @keyframes mesh-drift{0%,100%{transform:translate3d(0,0,0) scale(1)}50%{transform:translate3d(2%,-3%,0) scale(1.06)}}
        @keyframes underline-grow{to{transform:scaleX(1)}}
        @keyframes rise{from{opacity:0;transform:translateY(18px)}to{opacity:1;transform:none}}
        @keyframes glow-spin{to{transform:rotate(1turn)}}
        @media (prefers-reduced-motion:reduce){
            *{animation:none!important;transition:none!important}
            .reveal{opacity:1;transform:none}
            html{scroll-behavior:auto}
        }

        .topnav{display:flex;align-items:center;justify-content:space-between;padding:18px 0;gap:16px}
        .brand{display:flex;align-items:center;gap:10px;font-weight:600;font-size:17px;letter-spacing:-.015em;color:var(--c-text);text-decoration:none}
        .brand .mark{width:28px;height:28px;border-radius:8px;background:var(--c-brand);color:var(--c-on-brand);display:grid;place-items:center;font-size:14px}
        .nav-right{display:flex;align-items:center;gap:10px}
        .nav-links{display:flex;gap:22px;font-size:14px;color:var(--c-text-muted);margin-inline-end:8px}
        .nav-links a{text-decoration:none;color:inherit;transition:color var(--dur)}
        .nav-links a:hover{color:var(--c-text)}
        @media(max-width:760px){.nav-links{display:none}}
        .btn{display:inline-flex;align-items:center;gap:8px;border:1px solid transparent;border-radius:var(--r-md);padding:10px 18px;font-weight:500;font-size:14px;text-decoration:none;cursor:pointer;transition:all var(--dur) var(--ease-out)}
        .btn--primary{background:var(--c-brand);color:var(--c-on-brand)}
        .btn--primary:hover{background:var(--c-brand-hi);transform:translateY(-1px)}
        .icon-btn{width:38px;height:38px;border-radius:var(--r-md);border:1px solid var(--c-border);background:var(--c-bg);color:var(--c-text);display:grid;place-items:center;cursor:pointer;font-size:15px;transition:all var(--dur)}
        .icon-btn:hover{border-color:var(--c-border-strong)}
        .lang{position:relative}
        .lang > summary{list-style:none;cursor:pointer;display:inline-flex;align-items:center;gap:7px;height:38px;padding:0 13px;border:1px solid var(--c-border);border-radius:var(--r-md);font-size:13px;font-weight:500;background:var(--c-bg);color:var(--c-text)}
        .lang > summary::-webkit-details-marker{display:none}
        .lang .menu{position:absolute;inset-inline-end:0;top:calc(100% + 6px);background:var(--c-bg);border:1px solid var(--c-border);border-radius:var(--r-lg);padding:6px;min-width:180px;max-height:60vh;overflow:auto;z-index:200;box-shadow:var(--sh-lg);animation:rise .18s var(--ease-out)}
        .lang .menu a{display:flex;width:100%;gap:10px;align-items:center;padding:9px 11px;border-radius:var(--r-sm);font-size:13.5px;color:var(--c-text);text-align:start;text-decoration:none;box-sizing:border-box}
        .lang .menu a:hover{background:var(--c-bg-muted)}
        .lang .menu a.on{background:var(--c-brand-soft);color:var(--c-brand-text);font-weight:600}
        .lang .cc{font-family:var(--font-mono);font-size:11px;color:var(--c-text-faint);width:22px}

        /* Hero — always-dark band (linen .hero) */
        .hero{position:relative;background:#0a0a0a;color:#fff;border-radius:var(--r-2xl);overflow:hidden;padding:80px 48px 72px;margin:16px 0 24px;text-align:center}
        .hero::before{content:"";position:absolute;inset:-20%;background:
                radial-gradient(at 18% 22%,rgb(16 185 129/.26),transparent 42%),
                radial-gradient(at 88% 84%,rgb(52 211 153/.15),transparent 46%);
            animation:mesh-drift 14s ease-in-out infinite;pointer-events:none}
        .hero::after{content:"";position:absolute;inset:0;background-image:radial-gradient(circle at 1px 1px,rgb(255 255 255/.05) 1px,transparent 0);background-size:26px 26px;pointer-events:none}
        .hero>*{position:relative;z-index:1}
        .hero__eyebrow{display:inline-flex;align-items:center;gap:8px;font-size:12px;font-weight:500;color:var(--c-brand-lo);text-transform:uppercase;letter-spacing:.1em;background:rgb(16 185 129/.12);padding:6px 14px;border-radius:var(--r-pill);animation:rise .6s var(--ease-out) both}
        .hero__title{font-weight:500;font-size:clamp(2.5rem,4vw + 1rem,4rem);line-height:1.05;letter-spacing:-.03em;margin:22px auto 16px;max-width:19ch;color:#fff;animation:rise .6s var(--ease-out) .1s both}
        .hero__title em{color:var(--c-brand-lo);font-style:normal;position:relative}
        .hero__title em::after{content:"";position:absolute;bottom:-3px;inset-inline:0;height:2px;background:var(--c-brand);transform-origin:left;transform:scaleX(.4);animation:underline-grow 1s var(--ease-overshoot) .7s both}
        .hero__sub{font-size:17px;line-height:1.6;color:rgb(255 255 255/.68);max-width:58ch;margin:0 auto 36px;animation:rise .6s var(--ease-out) .2s both}

        .composer-wrap{position:relative;max-width:660px;margin:0 auto;animation:rise .7s var(--ease-out) .3s both;text-align:start}
        .composer-glow{position:absolute;inset:-1.5px;border-radius:calc(var(--r-xl) + 2px);overflow:hidden;pointer-events:none}
        .composer-glow::before{content:"";position:absolute;inset:-120%;background:conic-gradient(from 0deg,transparent 0 60%,rgba(16,185,129,.9) 78%,rgba(52,211,153,.9) 86%,transparent 96%);animation:glow-spin 5.5s linear infinite}
        .composer{position:relative;background:#141414;border:1px solid rgb(255 255 255/.14);border-radius:var(--r-xl);padding:16px 16px 12px;box-shadow:0 32px 64px rgb(0 0 0/.45),0 8px 16px rgb(0 0 0/.25)}
        .composer textarea{width:100%;min-height:76px;resize:none;background:transparent;border:0;outline:0;color:#fafafa;font:400 15.5px/1.55 var(--font-sans);padding:2px 4px;box-sizing:border-box}
        .composer textarea::placeholder{color:#8b8b8b}
        .composer-bar{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:8px;flex-wrap:wrap}
        .attach-row{display:flex;gap:7px;flex-wrap:wrap;align-items:center}
        .attach{display:inline-flex;align-items:center;gap:7px;border:1px solid rgb(255 255 255/.14);background:rgb(255 255 255/.05);color:#d8d8d8;border-radius:var(--r-pill);padding:7px 13px;font-size:12.5px;cursor:pointer;transition:all var(--dur);text-decoration:none}
        .attach:hover{border-color:var(--c-brand);color:#fff;background:rgb(16 185 129/.12)}
        .send{width:40px;height:40px;border-radius:12px;border:0;background:var(--c-brand);color:var(--c-on-brand);font-size:17px;cursor:pointer;display:grid;place-items:center;transition:all var(--dur) var(--ease-out);text-decoration:none}
        .send:hover{background:var(--c-brand-hi);transform:scale(1.06)}
        .mode-toggle{display:inline-flex;background:rgb(255 255 255/.06);border:1px solid rgb(255 255 255/.1);border-radius:var(--r-pill);padding:3px;gap:2px}
        .mode-toggle button{border:0;background:transparent;color:#a3a3a3;font-size:12px;font-weight:500;padding:6px 13px;border-radius:var(--r-pill);cursor:pointer;transition:all var(--dur)}
        .mode-toggle button.on{background:var(--c-brand);color:var(--c-on-brand)}
        .hero__note{font-size:12px;color:rgb(255 255 255/.5);margin-top:18px;animation:rise .7s var(--ease-out) .45s both}
        .hero__note b{color:var(--c-brand-lo);font-weight:600}
        .suggest-row{display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin-top:16px;animation:rise .7s var(--ease-out) .4s both}
        .suggest{border:1px solid rgb(255 255 255/.14);background:transparent;color:#c9c9c9;border-radius:var(--r-pill);padding:7px 14px;font-size:12.5px;cursor:pointer;transition:all var(--dur);text-decoration:none}
        .suggest:hover{border-color:var(--c-brand);color:#fff}
        @media(max-width:640px){.hero{padding:56px 22px 48px}}

        .proof-band{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;padding:26px 0;border-bottom:1px solid var(--c-border)}
        @media(max-width:820px){.proof-band{grid-template-columns:repeat(2,1fr);row-gap:22px}}
        .proof-item{text-align:center}
        .proof-item .num{font:600 26px/1 var(--font-mono);letter-spacing:-.02em;font-variant-numeric:tabular-nums}
        .proof-item .num .plus{color:var(--c-brand)}
        .proof-item .lbl{font-size:12.5px;color:var(--c-text-muted);margin-top:6px}

        .section{padding:88px 0 0}
        .section__eyebrow{font-size:12px;font-weight:500;color:var(--c-brand);text-transform:uppercase;letter-spacing:.08em;text-align:center;margin:0 0 12px}
        .section__title{font-weight:500;font-size:clamp(1.75rem,2.5vw + 1rem,2.75rem);line-height:1.15;letter-spacing:-.025em;text-align:center;margin:0 auto 12px;max-width:680px}
        .section__sub{text-align:center;color:var(--c-text-muted);max-width:56ch;margin:0 auto;font-size:15.5px}

        /* before / after showcase */
        .ba-stage{display:grid;grid-template-columns:1fr auto 1fr;gap:28px;align-items:center;margin-top:44px}
        @media(max-width:860px){.ba-stage{grid-template-columns:1fr;gap:18px}.ba-mid{transform:rotate(90deg)}}
        .menu-card{border-radius:var(--r-lg);padding:28px 26px;min-height:360px;box-shadow:var(--sh-xl)}
        .before{background:repeating-linear-gradient(#FFFEF9,#FFFEF9 27px,#E9E4D2 28px);border:1px solid #E0D8C4;transform:rotate(-1.6deg);font-family:"Bradley Hand","Segoe Script","Comic Sans MS",cursive;color:#3A342B;position:relative}
        .before::after{content:"";position:absolute;top:-9px;left:50%;transform:translateX(-50%) rotate(2deg);width:88px;height:22px;background:rgba(214,203,175,.6);border:1px solid rgba(0,0,0,.05)}
        .before h3{font-family:inherit;font-size:1.5rem;color:#3A342B;text-align:center;margin-bottom:14px;text-decoration:underline;font-weight:700}
        .before ul{list-style:none;padding:0;margin:0;font-size:1.05rem;line-height:2.05}
        .before .scratch{text-decoration:line-through;opacity:.55}
        .after{background:#fff;border:1px solid #e7e7e9;transform:rotate(.8deg);color:#0a0a0a}
        .after .rest{font-family:Georgia,'Times New Roman',serif;text-align:center;font-size:1.45rem;font-weight:700;letter-spacing:.02em;color:#0a0a0a}
        .after .rest-sub{text-align:center;font-family:var(--font-mono);font-size:.65rem;letter-spacing:.22em;text-transform:uppercase;color:#B08D3E;margin:4px 0 18px}
        .m-section{font-family:var(--font-mono);font-size:.68rem;letter-spacing:.18em;text-transform:uppercase;color:var(--c-brand-hi);border-bottom:1px solid #e7e7e9;padding-bottom:5px;margin:16px 0 10px}
        .m-item{display:flex;align-items:baseline;gap:8px;font-size:.92rem;margin:7px 0}
        .m-item .nm{font-weight:600;white-space:nowrap}
        .m-item .dots{flex:1;border-bottom:1.5px dotted #C9BFA9;transform:translateY(-4px)}
        .m-item .pr{font-family:var(--font-mono);font-variant-numeric:tabular-nums;font-size:.85rem}
        .m-desc{font-size:.78rem;color:#525252;margin:-3px 0 6px;font-style:italic}
        .ba-mid{text-align:center}
        .ba-mid .arrow{font-size:1.7rem;color:var(--c-brand);display:inline-block}
        [dir="rtl"] .ba-mid .arrow{transform:scaleX(-1)}
        .ba-mid .ba-tag{font-family:var(--font-mono);font-size:.68rem;color:var(--c-text-muted);letter-spacing:.05em;display:block;margin-top:8px;max-width:140px}

        .app-demo{display:grid;grid-template-columns:1fr 1fr;gap:56px;align-items:center;margin-top:52px}
        @media(max-width:880px){.app-demo{grid-template-columns:1fr;gap:36px}}
        .phone{width:290px;margin:0 auto;background:var(--c-bg);border:1px solid var(--c-border-hi);border-radius:38px;padding:12px;box-shadow:var(--sh-xl);position:relative}
        .phone .notch{width:96px;height:22px;background:var(--c-bg);border:1px solid var(--c-border);border-top:0;border-radius:0 0 14px 14px;position:absolute;top:12px;left:50%;transform:translateX(-50%);z-index:3}
        .screen{position:relative;height:520px;border-radius:28px;overflow:hidden;background:var(--c-bg-subtle);border:1px solid var(--c-border)}
        .scene{position:absolute;inset:0;padding:34px 16px 14px;opacity:0;transform:translateX(28px);transition:opacity .5s var(--ease-out),transform .5s var(--ease-out);pointer-events:none;display:flex;flex-direction:column;box-sizing:border-box}
        [dir="rtl"] .scene{transform:translateX(-28px)}
        .scene.on{opacity:1;transform:none;pointer-events:auto}
        .sc-head{font-weight:600;font-size:15px;letter-spacing:-.01em;display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
        .cart-pill{font:600 11px/1 var(--font-mono);background:var(--c-brand);color:var(--c-on-brand);border-radius:var(--r-pill);padding:5px 9px}
        .dish{display:flex;align-items:center;gap:10px;background:var(--c-bg);border:1px solid var(--c-border);border-radius:var(--r-lg);padding:9px 10px;margin-bottom:8px}
        .dish .ph{width:42px;height:42px;border-radius:10px;flex:0 0 auto;display:grid;place-items:center;font-size:19px;background:var(--c-brand-soft)}
        .dish .nm{font-size:12.5px;font-weight:600;flex:1;line-height:1.3}
        .dish .nm small{display:block;font-weight:400;color:var(--c-text-muted);font-size:10.5px}
        .dish .pr{font:600 12px/1 var(--font-mono)}
        .dish .add{width:26px;height:26px;border-radius:8px;border:0;background:var(--c-brand);color:var(--c-on-brand);font-size:14px;cursor:pointer;display:grid;place-items:center}
        .cat-row{display:flex;gap:6px;margin-bottom:12px;flex-wrap:wrap}
        .cat{font-size:10.5px;font-weight:500;border:1px solid var(--c-border);border-radius:var(--r-pill);padding:5px 10px;color:var(--c-text-muted)}
        .cat.on{background:var(--c-text);color:var(--c-bg);border-color:var(--c-text)}
        .line{display:flex;justify-content:space-between;font-size:12.5px;padding:9px 2px;border-bottom:1px dashed var(--c-border)}
        .line b{font-family:var(--font-mono);font-weight:600}
        .line.total{border-bottom:0;font-weight:600;font-size:13.5px;margin-top:4px}
        .payrow{display:flex;gap:8px;margin:12px 0}
        .paychip{flex:1;border:1px solid var(--c-border);border-radius:var(--r-md);padding:9px 0;text-align:center;font-size:11px;font-weight:600;color:var(--c-text-muted)}
        .paychip.on{border-color:var(--c-brand);color:var(--c-brand-text);background:var(--c-brand-soft)}
        .paybtn{margin-top:auto;border:0;border-radius:var(--r-lg);background:var(--c-brand);color:var(--c-on-brand);font-weight:600;font-size:13.5px;padding:13px 0;width:100%;cursor:pointer}
        .success{margin:auto;text-align:center}
        .success .ok{width:64px;height:64px;border-radius:50%;background:var(--c-brand-soft);color:var(--c-brand);display:grid;place-items:center;font-size:28px;margin:0 auto 14px;animation:rise .5s var(--ease-overshoot)}
        .success b{font-size:15px}
        .success p{font-size:12px;color:var(--c-text-muted);margin:6px 0 0}
        .steps-dots{display:flex;gap:8px;justify-content:center;margin-top:18px}
        .sdot{width:34px;height:5px;border-radius:99px;background:var(--c-border);cursor:pointer;border:0;padding:0;transition:background var(--dur)}
        .sdot.on{background:var(--c-brand)}
        .app-copy .kicker{font-size:12px;font-weight:500;color:var(--c-brand);text-transform:uppercase;letter-spacing:.08em;margin-bottom:12px}
        .app-copy h3{font-weight:500;font-size:clamp(1.5rem,2vw + .6rem,2.1rem);letter-spacing:-.02em;line-height:1.15;margin-bottom:14px}
        .app-copy>p{color:var(--c-text-muted);font-size:15.5px;max-width:46ch}
        .feat-list{list-style:none;padding:0;margin:22px 0 0}
        .feat-list li{display:flex;gap:12px;align-items:flex-start;padding:11px 0;border-top:1px solid var(--c-border);font-size:14.5px}
        .feat-list li .tick{color:var(--c-brand);font-weight:700;flex:0 0 auto}
        .feat-list li b{font-weight:600}
        .feat-list li span.d{color:var(--c-text-muted)}

        .refine{max-width:620px;margin:52px auto 0;background:var(--c-bg-subtle);border:1px solid var(--c-border);border-radius:var(--r-xl);padding:20px 22px;display:flex;flex-direction:column;gap:10px}
        .bub{border-radius:14px;padding:10px 15px;font-size:13.5px;max-width:82%;box-shadow:var(--sh-md)}
        .bub.u{align-self:flex-end;background:var(--c-text);color:var(--c-bg);border-end-end-radius:4px}
        .bub.a{align-self:flex-start;background:var(--c-bg);border:1px solid var(--c-border);border-end-start-radius:4px}
        .refine-cap{text-align:center;font-size:12.5px;color:var(--c-text-muted);margin-top:14px}

        .outputs{display:grid;grid-template-columns:1.15fr .85fr;gap:16px;margin-top:52px}
        @media(max-width:820px){.outputs{grid-template-columns:1fr}}
        .out-card{border:1px solid var(--c-border);background:var(--c-bg-subtle);border-radius:var(--r-xl);padding:30px;transition:transform var(--dur-slow) var(--ease-out),box-shadow var(--dur-slow) var(--ease-out)}
        .out-card:hover{transform:translateY(-4px);box-shadow:var(--sh-lg)}
        .out-card .oi{width:42px;height:42px;border-radius:12px;background:var(--c-brand-soft);color:var(--c-brand-text);display:grid;place-items:center;font-size:19px;margin-bottom:16px}
        .out-card h3{font-weight:600;font-size:19px;letter-spacing:-.015em;margin-bottom:8px}
        .out-card p{color:var(--c-text-muted);font-size:14.5px;margin:0 0 16px}
        .tagrow{display:flex;flex-wrap:wrap;gap:7px}
        .tag{font-size:11.5px;font-weight:500;border:1px solid var(--c-border);border-radius:var(--r-pill);padding:5px 11px;color:var(--c-text-muted);background:var(--c-bg)}
        .gate{margin-top:18px;font:500 12px/1.5 var(--font-mono);color:var(--c-text-muted);border-inline-start:3px solid var(--c-brand);padding:7px 13px;background:var(--c-bg);border-radius:0 var(--r-sm) var(--r-sm) 0}
        [dir="rtl"] .gate{border-radius:var(--r-sm) 0 0 var(--r-sm)}

        .presets{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-top:52px}
        @media(max-width:820px){.presets{grid-template-columns:repeat(2,1fr)}}
        .preset{border:1px solid var(--c-border);border-radius:var(--r-lg);overflow:hidden;background:var(--c-bg);cursor:pointer;padding:0;text-align:start;transition:transform var(--dur-slow) var(--ease-out),box-shadow var(--dur-slow) var(--ease-out);text-decoration:none;display:block}
        .preset:hover{transform:translateY(-4px) scale(1.01);box-shadow:var(--sh-lg)}
        .preset .shot{display:block;width:100%;height:220px;object-fit:cover;object-position:top;border-bottom:1px solid var(--c-border);transition:object-position 2.5s ease}
        .preset:hover .shot{object-position:bottom}
        .preset .cap{padding:12px 14px;display:block}
        .preset .cap b{font-size:13.5px;font-weight:600;display:block;color:var(--c-text)}
        .preset .cap span{font-size:12px;color:var(--c-text-muted)}

        .steps{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-top:52px}
        @media(max-width:820px){.steps{grid-template-columns:1fr}}
        .step{border:1px solid var(--c-border);border-radius:var(--r-xl);padding:26px;background:var(--c-bg-subtle)}
        .step .sn{font:600 12px/1 var(--font-mono);color:var(--c-brand);letter-spacing:.06em}
        .step h3{font-weight:600;font-size:17px;margin:12px 0 8px}
        .step p{color:var(--c-text-muted);font-size:13.5px;margin:0}
        .step .tm{display:inline-block;margin-top:14px;font:500 11px/1 var(--font-mono);background:var(--c-brand-soft);color:var(--c-brand-text);border-radius:var(--r-pill);padding:5px 10px}

        .credits{display:grid;grid-template-columns:1.05fr .95fr;gap:48px;align-items:center;margin-top:52px}
        @media(max-width:820px){.credits{grid-template-columns:1fr}}
        .credits h3{font-weight:500;font-size:clamp(1.4rem,1.8vw + .6rem,1.9rem);letter-spacing:-.02em;margin-bottom:12px}
        .credits p{color:var(--c-text-muted);font-size:15px}
        .ledger{background:var(--c-bg-subtle);border:1px solid var(--c-border);border-radius:var(--r-xl);padding:24px;font-family:var(--font-mono);font-size:13px}
        .ledger .lr{display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px dashed var(--c-border)}
        .ledger .lr:last-child{border-bottom:0;font-weight:700}
        .ledger .plus{color:var(--c-brand)}
        .ledger .head{letter-spacing:.14em;text-transform:uppercase;font-size:10.5px;color:var(--c-text-faint);border-bottom:1px solid var(--c-border);padding-bottom:9px;margin-bottom:4px;display:flex;justify-content:space-between}

        .quotes{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:44px}
        @media(max-width:720px){.quotes{grid-template-columns:1fr}}
        .quote{background:var(--c-bg-subtle);border:1px solid var(--c-border);border-radius:var(--r-xl);padding:26px}
        .quote p{font-size:15.5px;font-style:italic;line-height:1.6;margin:0 0 16px;color:var(--c-text)}
        .quote .who{font-family:var(--font-mono);font-size:11.5px;color:var(--c-text-muted);letter-spacing:.04em}

        .faq{max-width:640px;margin:44px auto 0}
        .faq details{border-bottom:1px solid var(--c-border)}
        .faq details:first-child{border-top:1px solid var(--c-border)}
        .faq summary{cursor:pointer;padding:18px 4px;font-weight:500;font-size:15px;list-style:none;display:flex;justify-content:space-between;align-items:center;gap:12px}
        .faq summary::-webkit-details-marker{display:none}
        .faq summary::after{content:"+";font-family:var(--font-mono);color:var(--c-brand);font-size:17px;transition:transform var(--dur)}
        .faq details[open] summary::after{transform:rotate(45deg)}
        .faq .ans{padding:0 4px 18px;color:var(--c-text-muted);font-size:14px;max-width:60ch}

        .cta-band{position:relative;background:#0a0a0a;color:#fff;border-radius:var(--r-2xl);overflow:hidden;padding:64px 32px;text-align:center;margin-top:88px}
        .cta-band::before{content:"";position:absolute;inset:-20%;background:radial-gradient(at 50% 120%,rgb(16 185 129/.3),transparent 55%);animation:mesh-drift 12s ease-in-out infinite;pointer-events:none}
        .cta-band>*{position:relative}
        .cta-band h2{font-weight:500;font-size:clamp(1.8rem,3.2vw,2.7rem);letter-spacing:-.025em;max-width:22ch;margin:0 auto 12px;color:#fff}
        .cta-band p{color:rgb(255 255 255/.65);max-width:48ch;margin:0 auto 28px;font-size:15.5px}
        .cta-band .btn--primary{font-size:15px;padding:13px 26px}
        .cta-band .mini{font-size:12px;color:rgb(255 255 255/.5);margin-top:16px}

        footer{padding:44px 0 52px;display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;color:var(--c-text-faint);font-size:13px}
        footer a{color:inherit;text-decoration:none}
        footer a:hover{color:var(--c-text)}
    </style>
</head>
<body data-guest-session="{{ $session->guest_token }}">

<div class="container">
    <nav class="topnav">
        <a class="brand" href="{{ route('studio.landing.v2') }}"><span class="mark">&#10022;</span><span>{{ ___('Menu Studio') }}</span></a>
        <span class="nav-right">
            <span class="nav-links">
                <a href="#showcase">{{ ___('Examples') }}</a>
                <a href="#app">{{ ___('Ordering app') }}</a>
                <a href="#how">{{ ___('How it works') }}</a>
                <a href="#faq">{{ ___('FAQ') }}</a>
            </span>
            @if(($languages ?? collect())->count() > 1)
            <details class="lang">
                <summary>&#127760; {{ strtoupper(get_lang()) }}</summary>
                <div class="menu">
                    @foreach($languages as $lang)
                        <a class="{{ $lang->code === get_lang() ? 'on' : '' }}" href="{{ lang_url($lang->code) }}">
                            <span class="cc">{{ strtoupper($lang->code) }}</span>{{ $lang->name }}
                        </a>
                    @endforeach
                </div>
            </details>
            @endif
            <button class="icon-btn" id="themeBtn" aria-label="{{ ___('Toggle dark mode') }}">&#9790;</button>
            <a class="btn btn--primary" href="{{ route('studio.app.shell') }}">{{ ___('Open Studio') }}</a>
        </span>
    </nav>

    <header class="hero">
        <span class="hero__eyebrow">&#10022; {{ ___('AI Menu Studio — free · no signup to try') }}</span>
        <h1 class="hero__title">{{ ___('Turn any menu into a') }} <em>{{ ___('full ordering app') }}</em> {{ ___('in 60 seconds') }}</h1>
        <p class="hero__sub">{{ ___('Drop a photo, PDF, or handwritten page. The AI reads every dish and builds a live menu your guests browse, order and pay from — or a print-ready designed PDF.') }}</p>

        <div class="composer-wrap">
            <div class="composer-glow" aria-hidden="true"></div>
            <div class="composer">
                <textarea id="promptBox" rows="3" placeholder=""></textarea>
                <div class="composer-bar">
                    <span class="attach-row">
                        <a class="attach" href="{{ route('studio.app.shell') }}">&#128206; {{ ___('Attach menu') }}</a>
                        <a class="attach" href="{{ route('studio.app.shell') }}">&#128247; {{ ___('Photo') }}</a>
                        <a class="attach" href="{{ route('studio.app.shell') }}">&#128196; PDF</a>
                        <span class="mode-toggle" role="group">
                            <button class="on" data-mode="ordering" type="button">{{ ___('Ordering app') }}</button>
                            <button data-mode="pdf" type="button">{{ ___('PDF menu') }}</button>
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
        <p class="hero__note"><b>&#10003;</b> {{ ___('No signup · uploads auto-delete in 48 h · never used to train models') }}</p>
    </header>

    <div class="proof-band reveal">
        <div class="proof-item"><div class="num"><span class="count" data-to="4208">0</span><span class="plus">+</span></div><div class="lbl">{{ ___('menus converted') }}</div></div>
        <div class="proof-item"><div class="num"><span class="count" data-to="61540">0</span><span class="plus">+</span></div><div class="lbl">{{ ___('dishes extracted') }}</div></div>
        <div class="proof-item"><div class="num"><span class="count" data-to="{{ ($languages ?? collect())->count() ?: 24 }}">0</span></div><div class="lbl">{{ ___('languages · RTL native') }}</div></div>
        <div class="proof-item"><div class="num">&#9733; 4.9</div><div class="lbl">{{ ___('from restaurant owners') }}</div></div>
    </div>

    <section class="section" id="showcase">
        <p class="section__eyebrow reveal">{{ ___('Proof, not promises') }}</p>
        <h2 class="section__title reveal">{{ ___('From the page on your counter to the menu on their phone') }}</h2>
        <p class="section__sub reveal">{{ ___('A real conversion: one photographed, handwritten menu — read, structured, and re-designed by the AI.') }}</p>

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
        <p class="section__eyebrow reveal">{{ ___('Not just a pretty menu') }}</p>
        <h2 class="section__title reveal">{{ ___('Your photo becomes a real ordering app — cart, checkout and all') }}</h2>
        <p class="section__sub reveal">{{ ___('Guests scan a QR code and order straight from their phone. You get the order in the kitchen, they pay how they like.') }}</p>

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
                            <div class="line"><span>1 × Rigatoni al Forno<br><small style="color:var(--c-text-muted)">+ {{ ___('extra scamorza') }}</small></span><b>15.50</b></div>
                            <div class="line"><span>1 × Tiramisu</span><b>7.00</b></div>
                            <div class="line"><span>{{ ___('Service') }}</span><b>1.40</b></div>
                            <div class="line total"><span>{{ ___('Total') }}</span><b>29.90</b></div>
                            <button class="paybtn" type="button">{{ ___('Go to checkout') }} &rarr;</button>
                        </div>
                        <div class="scene">
                            <div class="sc-head"><span>{{ ___('Checkout') }}</span><b style="font-family:var(--font-mono)">29.90</b></div>
                            <div class="payrow"><span class="paychip on">&#63743; Pay</span><span class="paychip">{{ ___('Card') }}</span><span class="paychip">{{ ___('Cash') }}</span></div>
                            <div class="line"><span>{{ ___('Table') }}</span><b>12</b></div>
                            <div class="line"><span>{{ ___('Notes') }}</span><span style="color:var(--c-text-muted)">{{ ___('no onions') }} &#128591;</span></div>
                            <div class="line"><span>{{ ___('Tip') }}</span><b>10%</b></div>
                            <button class="paybtn" type="button">{{ ___('Pay 32.90 — send to kitchen') }}</button>
                        </div>
                        <div class="scene">
                            <div class="success">
                                <div class="ok">&#10003;</div>
                                <b>{{ ___('Order sent to the kitchen') }}</b>
                                <p>#184 · {{ ___('Table') }} 12 · ~15 {{ ___('min') }}<br>{{ ___("You'll get a ping when it's ready.") }}</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="steps-dots" id="sceneDots" role="tablist">
                    <button class="sdot on" type="button" aria-label="{{ ___('Menu') }}"></button>
                    <button class="sdot" type="button" aria-label="{{ ___('Cart') }}"></button>
                    <button class="sdot" type="button" aria-label="{{ ___('Checkout') }}"></button>
                    <button class="sdot" type="button" aria-label="{{ ___('Confirmation') }}"></button>
                </div>
            </div>

            <div class="app-copy reveal">
                <p class="kicker">{{ ___('The same upload powers it all') }}</p>
                <h3>{{ ___('From coffee-stained paper to “order sent to kitchen”') }}</h3>
                <p>{{ ___("Everything below ships with the menu the AI builds — it's the same engine that runs the full restaurant platform.") }}</p>
                <ul class="feat-list">
                    <li><span class="tick">&#10003;</span><span><b>{{ ___('QR ordering & cart') }}</b> — <span class="d">{{ ___('guests order from the table, delivery or pickup') }}</span></span></li>
                    <li><span class="tick">&#10003;</span><span><b>{{ ___('Checkout & payments') }}</b> — <span class="d">{{ ___('Apple Pay, cards, or cash at the counter') }}</span></span></li>
                    <li><span class="tick">&#10003;</span><span><b>{{ ___('Live kitchen orders') }}</b> — <span class="d">{{ ___('orders land on a kitchen screen, not a printer tray') }}</span></span></li>
                    <li><span class="tick">&#10003;</span><span><b>{{ ___('Edit any time') }}</b> — <span class="d">{{ ___('pause a dish or change a price from your phone') }}</span></span></li>
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
        <p class="section__eyebrow reveal">{{ ___('One upload, two endings') }}</p>
        <h2 class="section__title reveal">{{ ___('Take it online, or take it to print') }}</h2>
        <div class="outputs">
            <article class="out-card reveal">
                <span class="oi">&#128241;</span>
                <h3>{{ ___('Full ordering app') }}</h3>
                <p>{{ ___('A live, hosted menu with cart and checkout — guests scan, order and pay. Preview and refine free for as long as you like.') }}</p>
                <span class="tagrow"><span class="tag">{{ ___('QR code') }}</span><span class="tag">{{ ___('Cart + checkout') }}</span><span class="tag">{{ ___('Multi-language') }}</span><span class="tag">{{ ___('Edit anytime') }}</span></span>
                <div class="gate">{{ ___("Publish → sign in with Google. That's the whole gate.") }}</div>
            </article>
            <article class="out-card reveal" style="transition-delay:.1s">
                <span class="oi">&#128424;&#65039;</span>
                <h3>{{ ___('Designed PDF menu') }}</h3>
                <p>{{ ___('The AI art-directs a print-ready menu around your dishes. Watermarked preview is always free; regenerate until it fits.') }}</p>
                <span class="tagrow"><span class="tag">{{ ___('Style presets') }}</span><span class="tag">{{ ___('Print-ready') }}</span><span class="tag">{{ ___('Unlimited previews') }}</span></span>
                <div class="gate">{{ ___('Clean download → a few credits. You start with free ones.') }}</div>
            </article>
        </div>
    </section>

    <section class="section">
        <p class="section__eyebrow reveal">{{ ___('À la carte styles') }}</p>
        <h2 class="section__title reveal">{{ ___('Pick a style — the AI designs around it') }}</h2>
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
        <p class="section__eyebrow reveal">{{ ___('Sixty seconds, three moves') }}</p>
        <h2 class="section__title reveal">{{ ___('How it works') }}</h2>
        <div class="steps">
            <div class="step reveal"><span class="sn">01</span><h3>{{ ___('Upload') }}</h3><p>{{ ___('A photo, a PDF, a handwritten page — even the laminated one with the coffee stain.') }}</p><span class="tm">~10 {{ ___('sec') }}</span></div>
            <div class="step reveal" style="transition-delay:.08s"><span class="sn">02</span><h3>{{ ___('Review & refine') }}</h3><p>{{ ___('The AI extracts dishes, prices and sections. Fix anything by chatting: “move desserts up”, “translate to Arabic”.') }}</p><span class="tm">~1 {{ ___('min') }}</span></div>
            <div class="step reveal" style="transition-delay:.16s"><span class="sn">03</span><h3>{{ ___('Publish or download') }}</h3><p>{{ ___('Go live with the QR ordering app, or download the designed PDF for print.') }}</p><span class="tm">{{ ___('1 tap') }}</span></div>
        </div>
    </section>

    <section class="section">
        <div class="credits">
            <div class="reveal">
                <p class="section__eyebrow" style="text-align:start">{{ ___('On the house') }}</p>
                <h3>{{ ___('Start with free credits. Keep them forever.') }}</h3>
                <p>{{ ___('Exploring, uploading, previewing and refining cost nothing — no card, no account. Credits only come in when you download a clean, print-ready PDF. Sign in with Google to top up; bundles never expire.') }}</p>
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
        <div class="quotes">
            <article class="quote reveal">
                <p>“{{ ___('I photographed the menu taped to my register. Twenty minutes later customers were ordering from their phones.') }}”</p>
                <span class="who">— SARA K. · {{ ___('CAFÉ OWNER') }}, AMMAN</span>
            </article>
            <article class="quote reveal" style="transition-delay:.08s">
                <p>“{{ ___('The PDF it designed looked better than the one we paid a designer for last year. We printed it the same week.') }}”</p>
                <span class="who">— MARCO T. · {{ ___('TRATTORIA') }}, LISBON</span>
            </article>
        </div>
    </section>

    <section class="section" id="faq">
        <p class="section__eyebrow reveal">{{ ___('Before you ask') }}</p>
        <h2 class="section__title reveal">{{ ___('Questions, answered') }}</h2>
        <div class="faq reveal">
            <details><summary>{{ ___('Is it really free?') }}</summary><div class="ans">{{ ___('Yes — uploading, extracting, previewing and refining are free with no account. You pay only to download a clean designed PDF (credits) or when you upgrade to the full ordering platform. Publishing a basic online menu just needs a Google sign-in.') }}</div></details>
            <details><summary>{{ ___('Can guests really order and pay through it?') }}</summary><div class="ans">{{ ___("Yes. The published menu is a full ordering app: cart, table or pickup, checkout, and orders flowing to a kitchen screen. It's the same engine behind the whole restaurant platform.") }}</div></details>
            <details><summary>{{ ___('What happens to my uploads?') }}</summary><div class="ans">{{ ___('Guest uploads auto-delete within 48 hours unless you sign in to keep them. Your menus are never used to train AI models.') }}</div></details>
            <details><summary>{{ ___('Can it read handwriting? Arabic?') }}</summary><div class="ans">{{ ___('Yes to both. It reads handwritten pages, photos and PDFs, and works in many languages including full right-to-left Arabic menus.') }}</div></details>
        </div>
    </section>

    <section style="padding-top:24px">
        <div class="cta-band reveal">
            <h2>{{ ___("Your menu, remade — while the coffee's still hot") }}</h2>
            <p>{{ ___("One photo. Sixty seconds. An ordering app your guests can pay through, or a print-ready design you'll be proud to laminate.") }}</p>
            <a class="btn btn--primary" href="{{ route('studio.app.shell') }}">{{ ___('Convert my menu — free') }} &#8593;</a>
            <p class="mini">{{ ___('No signup · no card · uploads auto-delete in 48 h') }}</p>
        </div>
    </section>

    <footer>
        <span>{{ ___('Menu Studio') }} — {{ ___('Part of') }} <a href="{{ url('/') }}"><b>{{ config('settings.website_title', config('app.name')) }}</b></a></span>
        <span><a href="{{ route('studio') }}">{{ ___('Main studio page') }}</a></span>
    </footer>
</div>

<script nonce="{{ csp_nonce() }}">
(function(){
    var reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* theme toggle */
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
    },{threshold:.12});
    document.querySelectorAll('.reveal').forEach(function(el){ io.observe(el); });

    /* count-up */
    function countUp(el){
        var to = +el.dataset.to, t0 = null, dur = 1400;
        if (reduced){ el.textContent = to.toLocaleString(); return; }
        function tick(ts){ if(!t0) t0 = ts; var p = Math.min((ts-t0)/dur,1); p = 1-Math.pow(1-p,3);
            el.textContent = Math.round(to*p).toLocaleString(); if(p<1) requestAnimationFrame(tick); }
        requestAnimationFrame(tick);
    }
    var cio = new IntersectionObserver(function(es){
        es.forEach(function(e){ if(e.isIntersecting){ countUp(e.target); cio.unobserve(e.target); } });
    },{threshold:.5});
    document.querySelectorAll('.count').forEach(function(el){ cio.observe(el); });

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
        var d = dir>0 ? 38 : 14;
        if (dir>0 && ci>=line.length){ d=2200; dir=-1; }
        if (dir<0 && ci<=0){ dir=1; li=(li+1)%lines.length; d=500; }
        timer = setTimeout(typeLoop, d);
    }
    typeLoop();
    box.addEventListener('focus', function(){ clearTimeout(timer); box.placeholder = lines[0]; });

    /* mode toggle + send deep-link */
    var mode = 'ordering';
    document.querySelectorAll('.mode-toggle button').forEach(function(b){
        b.addEventListener('click', function(){
            b.parentElement.querySelectorAll('button').forEach(function(x){ x.classList.remove('on'); });
            b.classList.add('on'); mode = b.dataset.mode;
        });
    });
    var sendBtn = document.getElementById('sendBtn');
    sendBtn.addEventListener('click', function(){
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
        scenes.forEach(function(s,k){ s.classList.toggle('on', k===i); });
        dots.forEach(function(d,k){ d.classList.toggle('on', k===i); });
    }
    dots.forEach(function(d,i){ d.addEventListener('click', function(){ show(i); restart(); }); });
    function restart(){ clearInterval(auto); if (!reduced) auto = setInterval(function(){ show((cur+1)%scenes.length); }, 3400); }
    restart();
    var phone = document.querySelector('.phone');
    phone.addEventListener('mouseenter', function(){ clearInterval(auto); });
    phone.addEventListener('mouseleave', restart);
})();
</script>
</body>
</html>
