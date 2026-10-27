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
    <style nonce="{{ csp_nonce() }}">
        :root{
            --paper:#FBF8F2; --paper-deep:#F4EFE5;
            --ink:#211B15; --ink-soft:#6E6357;
            --line:#E6DECF;
            --accent:#C2401C; --accent-deep:#9A3315;
            --board:#26211B; --board-ink:#F3EDE2;
            --gold:#B08D3E; --ok:#3E7A4E;
            --display:"Didot","Bodoni MT","Playfair Display","Iowan Old Style",Georgia,serif;
            --body:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
            --mono:ui-monospace,"SF Mono","Cascadia Code",Menlo,Consolas,monospace;
            --hand:"Bradley Hand","Segoe Script","Comic Sans MS",cursive;
        }
        html{scroll-behavior:smooth}
        @media (prefers-reduced-motion: reduce){ html{scroll-behavior:auto} *{animation:none!important;transition:none!important} }
        body{background:var(--paper);color:var(--ink);font-family:var(--body);line-height:1.55;margin:0}
        .wrap{max-width:1080px;margin:0 auto;padding:0 24px}
        h1,h2,h3{text-wrap:balance;margin:0}
        .eyebrow{font-family:var(--mono);font-size:.72rem;letter-spacing:.18em;text-transform:uppercase;color:var(--accent);display:block;margin-bottom:14px}
        section{padding:72px 0}
        a{color:inherit}
        button{font:inherit}
        :focus-visible{outline:2px solid var(--accent);outline-offset:3px;border-radius:4px}

        nav{display:flex;justify-content:space-between;align-items:center;padding:22px 0;border-bottom:1px solid var(--line)}
        .brand{font-family:var(--display);font-size:1.35rem;font-weight:700;letter-spacing:.01em;display:flex;align-items:center;gap:10px;text-decoration:none}
        .brand .mark{width:30px;height:30px;border:1.5px solid var(--ink);border-radius:6px;display:grid;place-items:center;font-size:.9rem;background:#fff}
        .nav-links{display:flex;gap:20px;align-items:center;font-size:.9rem;color:var(--ink-soft)}
        .nav-links > a{text-decoration:none}
        .nav-links > a:hover{color:var(--ink)}
        .btn{display:inline-flex;align-items:center;gap:9px;background:var(--ink);color:var(--paper);border:0;border-radius:8px;padding:12px 22px;font-weight:600;font-size:.95rem;text-decoration:none;cursor:pointer;transition:background .15s ease}
        .btn:hover{background:#000}
        .btn.accent{background:var(--accent)}
        .btn.accent:hover{background:var(--accent-deep)}
        .lang{position:relative}
        .lang summary{list-style:none;cursor:pointer;color:var(--ink-soft);padding:9px 12px;border:1px solid var(--line);border-radius:999px;font-size:.85rem;background:#fff}
        .lang summary::-webkit-details-marker{display:none}
        .lang .menu{position:absolute;inset-inline-end:0;top:120%;background:#fff;border:1px solid var(--line);border-radius:12px;padding:6px;min-width:190px;max-height:60vh;overflow:auto;z-index:20;box-shadow:0 18px 40px rgba(60,40,20,.14)}
        .lang .menu a{display:flex;gap:8px;padding:9px 10px;border-radius:8px;text-decoration:none;font-size:.9rem}
        .lang .menu a:hover,.lang .menu a.on{background:var(--paper-deep)}

        .hero{padding:76px 0 64px;text-align:center;position:relative}
        .stamp{display:inline-block;font-family:var(--mono);font-size:.7rem;letter-spacing:.14em;text-transform:uppercase;color:var(--accent);border:1.5px solid var(--accent);border-radius:4px;padding:6px 12px;transform:rotate(-2deg);background:rgba(194,64,28,.04)}
        .hero h1{font-family:var(--display);font-weight:700;font-size:clamp(2.6rem,6vw,4.3rem);line-height:1.04;letter-spacing:-.015em;margin:26px auto 18px;max-width:17ch}
        .hero h1 em{font-style:italic;color:var(--accent)}
        .hero .sub{color:var(--ink-soft);font-size:1.12rem;max-width:56ch;margin:0 auto 36px}

        .composer{max-width:640px;margin:0 auto;background:#fff;border:1px solid var(--line);border-radius:16px;box-shadow:0 24px 60px -30px rgba(60,40,20,.25), 0 2px 6px rgba(60,40,20,.06);padding:14px;text-align:start}
        .dropzone{border:1.5px dashed #D5C9B4;border-radius:11px;padding:34px 24px;text-align:center;cursor:pointer;transition:border-color .15s ease,background .15s ease;display:block;text-decoration:none}
        .dropzone:hover{border-color:var(--accent);background:#FFFDF9}
        .dropzone .di{font-size:1.6rem}
        .dropzone p{margin:10px 0 2px;font-weight:600}
        .dropzone small{color:var(--ink-soft)}
        .chips{display:flex;gap:8px;flex-wrap:wrap;justify-content:center;padding:14px 6px 6px}
        .chip{border:1px solid var(--line);background:var(--paper);border-radius:999px;padding:8px 15px;font-size:.85rem;cursor:pointer;color:var(--ink);transition:all .12s ease;text-decoration:none}
        .chip:hover{border-color:var(--accent);color:var(--accent)}
        .composer-foot{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 8px 4px;flex-wrap:wrap}
        .samples{font-size:.83rem;color:var(--ink-soft)}
        .samples a{color:var(--accent);text-decoration:none;font-weight:600}
        .samples a:hover{text-decoration:underline}
        .micro{font-family:var(--mono);font-size:.72rem;color:var(--ink-soft);margin-top:22px;letter-spacing:.02em}
        .micro b{color:var(--ok);font-weight:600}

        .trust{border-top:1px solid var(--line);border-bottom:1px solid var(--line);background:var(--paper-deep);padding:26px 0}
        .trust .row{display:grid;grid-template-columns:repeat(4,1fr);gap:18px;text-align:center}
        .tstat .n{font-family:var(--display);font-size:1.9rem;font-weight:700;font-variant-numeric:tabular-nums}
        .tstat .l{font-family:var(--mono);font-size:.7rem;letter-spacing:.12em;text-transform:uppercase;color:var(--ink-soft);margin-top:2px}
        @media(max-width:720px){.trust .row{grid-template-columns:repeat(2,1fr);row-gap:22px}}

        .ba-stage{display:grid;grid-template-columns:1fr auto 1fr;gap:28px;align-items:center;margin-top:44px}
        @media(max-width:860px){.ba-stage{grid-template-columns:1fr;gap:18px}.ba-mid{transform:rotate(90deg)}}
        .menu-card{border-radius:12px;padding:28px 26px;min-height:360px;box-shadow:0 20px 45px -22px rgba(60,40,20,.35)}
        .before{background:repeating-linear-gradient(#FFFEF9,#FFFEF9 27px,#E9E4D2 28px);border:1px solid #E0D8C4;transform:rotate(-1.6deg);font-family:var(--hand);color:#3A342B;position:relative}
        .before::after{content:"";position:absolute;top:-9px;left:50%;transform:translateX(-50%) rotate(2deg);width:88px;height:22px;background:rgba(214,203,175,.6);border:1px solid rgba(0,0,0,.05)}
        .before h3{font-family:var(--hand);font-size:1.5rem;text-align:center;margin-bottom:14px;text-decoration:underline}
        .before ul{list-style:none;padding:0;margin:0;font-size:1.05rem;line-height:2.05}
        .before .scratch{text-decoration:line-through;opacity:.55}
        .after{background:#fff;border:1px solid var(--line);transform:rotate(.8deg)}
        .after .rest{font-family:var(--display);text-align:center;font-size:1.45rem;font-weight:700;letter-spacing:.02em}
        .after .rest-sub{text-align:center;font-family:var(--mono);font-size:.65rem;letter-spacing:.22em;text-transform:uppercase;color:var(--gold);margin:4px 0 18px}
        .m-section{font-family:var(--mono);font-size:.68rem;letter-spacing:.18em;text-transform:uppercase;color:var(--accent);border-bottom:1px solid var(--line);padding-bottom:5px;margin:16px 0 10px}
        .m-item{display:flex;align-items:baseline;gap:8px;font-size:.92rem;margin:7px 0}
        .m-item .nm{font-weight:600;white-space:nowrap}
        .m-item .dots{flex:1;border-bottom:1.5px dotted #C9BFA9;transform:translateY(-4px)}
        .m-item .pr{font-family:var(--mono);font-variant-numeric:tabular-nums;font-size:.85rem}
        .m-desc{font-size:.78rem;color:var(--ink-soft);margin:-3px 0 6px;font-style:italic}
        .ba-mid{text-align:center}
        .ba-mid .arrow{font-size:1.7rem;color:var(--accent)}
        .ba-mid .tag{font-family:var(--mono);font-size:.68rem;color:var(--ink-soft);letter-spacing:.05em;display:block;margin-top:8px;max-width:130px}
        .chat-demo{max-width:560px;margin:38px auto 0;background:#fff;border:1px solid var(--line);border-radius:12px;padding:16px 18px;display:flex;flex-direction:column;gap:10px}
        .bubble{border-radius:10px;padding:9px 14px;font-size:.88rem;max-width:80%}
        .bubble.user{align-self:flex-end;background:var(--ink);color:var(--paper)}
        .bubble.ai{align-self:flex-start;background:var(--paper-deep);color:var(--ink)}
        .chat-cap{text-align:center;font-family:var(--mono);font-size:.72rem;color:var(--ink-soft);margin-top:12px;letter-spacing:.04em}

        .sec-head{text-align:center;max-width:60ch;margin:0 auto 8px}
        .sec-head h2{font-family:var(--display);font-weight:700;font-size:clamp(1.8rem,3.6vw,2.5rem);letter-spacing:-.01em}
        .sec-head p{color:var(--ink-soft);margin:12px auto 0;max-width:52ch}

        .outputs{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:44px}
        @media(max-width:820px){.outputs{grid-template-columns:1fr}}
        .out-card{border:1px solid var(--line);background:#fff;border-radius:14px;padding:30px;position:relative;overflow:hidden}
        .out-card .oc-ico{font-size:1.5rem}
        .out-card h3{font-family:var(--display);font-size:1.5rem;margin:12px 0 8px}
        .out-card p{color:var(--ink-soft);font-size:.95rem;margin:0 0 18px}
        .out-card ul{list-style:none;margin:0;padding:0;font-size:.9rem}
        .out-card li{padding:7px 0;border-top:1px dotted var(--line);display:flex;gap:10px;align-items:baseline}
        .out-card li::before{content:"—";color:var(--accent);font-weight:700}
        .out-gate{margin-top:18px;font-family:var(--mono);font-size:.72rem;letter-spacing:.04em;color:var(--ink-soft);border-inline-start:3px solid var(--gold);padding:6px 12px;background:var(--paper)}

        .presets{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-top:44px}
        @media(max-width:820px){.presets{grid-template-columns:repeat(2,1fr)}}
        .preset{border:1px solid var(--line);border-radius:12px;overflow:hidden;background:#fff;cursor:pointer;transition:transform .18s ease,box-shadow .18s ease;text-align:start;text-decoration:none;display:block}
        .preset:hover{transform:translateY(-4px);box-shadow:0 18px 38px -20px rgba(60,40,20,.35)}
        .preset .shot{display:block;width:100%;height:230px;object-fit:cover;object-position:top;border-bottom:1px solid var(--line);transition:object-position 2.5s ease}
        .preset:hover .shot{object-position:bottom}
        .preset .cap{padding:12px 15px;display:block}
        .preset .cap b{font-size:.9rem;display:block;color:var(--ink)}
        .preset .cap span{font-size:.76rem;color:var(--ink-soft)}

        .course{max-width:620px;margin:44px auto 0}
        .course-row{display:flex;align-items:baseline;gap:12px;padding:20px 0;border-bottom:1px solid var(--line);flex-wrap:wrap}
        .course-row:first-child{border-top:1px solid var(--line)}
        .course-row .cn{font-family:var(--mono);color:var(--accent);font-size:.78rem;letter-spacing:.1em}
        .course-row .ct{font-family:var(--display);font-size:1.35rem;font-weight:700;white-space:nowrap}
        .course-row .dots{flex:1;border-bottom:1.5px dotted #C9BFA9;transform:translateY(-5px)}
        .course-row .time{font-family:var(--mono);font-size:.82rem;font-variant-numeric:tabular-nums;color:var(--ink);white-space:nowrap}
        .course-row .cd{flex-basis:100%;color:var(--ink-soft);font-size:.9rem;margin-top:-8px}

        .credits{display:grid;grid-template-columns:1.1fr .9fr;gap:44px;align-items:center;margin-top:44px}
        @media(max-width:820px){.credits{grid-template-columns:1fr}}
        .credits h3{font-family:var(--display);font-size:1.6rem;margin-bottom:12px}
        .credits p{color:var(--ink-soft);font-size:.97rem}
        .ledger{background:#fff;border:1px solid var(--line);border-radius:12px;padding:24px;font-family:var(--mono);font-size:.85rem}
        .ledger .lr{display:flex;justify-content:space-between;padding:9px 0;border-bottom:1px dotted var(--line)}
        .ledger .lr:last-child{border-bottom:0;font-weight:700}
        .ledger .plus{color:var(--ok)}
        .ledger .head{letter-spacing:.14em;text-transform:uppercase;font-size:.68rem;color:var(--ink-soft);border-bottom:1px solid var(--line);padding-bottom:8px;margin-bottom:4px;display:flex;justify-content:space-between}

        .quotes{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:44px}
        @media(max-width:720px){.quotes{grid-template-columns:1fr}}
        .quote{background:#fff;border:1px solid var(--line);border-radius:12px;padding:26px}
        .quote p{font-family:var(--display);font-size:1.15rem;font-style:italic;line-height:1.5;margin:0 0 16px}
        .quote .who{font-family:var(--mono);font-size:.75rem;color:var(--ink-soft);letter-spacing:.04em}

        .faq{max-width:640px;margin:40px auto 0}
        .faq details{border-bottom:1px solid var(--line)}
        .faq details:first-child{border-top:1px solid var(--line)}
        .faq summary{cursor:pointer;padding:18px 4px;font-weight:600;font-size:.98rem;list-style:none;display:flex;justify-content:space-between;align-items:center}
        .faq summary::-webkit-details-marker{display:none}
        .faq summary::after{content:"+";font-family:var(--mono);color:var(--accent);font-size:1.1rem}
        .faq details[open] summary::after{content:"–"}
        .faq .ans{padding:0 4px 18px;color:var(--ink-soft);font-size:.92rem;max-width:60ch}

        .board{background:var(--board);color:var(--board-ink);border-radius:18px;padding:64px 32px;text-align:center;position:relative;margin:40px 0 0}
        .board::before{content:"";position:absolute;inset:12px;border:1.5px dashed rgba(243,237,226,.28);border-radius:12px;pointer-events:none}
        .board .b-eyebrow{font-family:var(--mono);font-size:.7rem;letter-spacing:.24em;text-transform:uppercase;color:var(--gold)}
        .board h2{font-family:var(--display);font-size:clamp(1.9rem,4.4vw,3rem);font-weight:700;margin:16px auto 12px;max-width:20ch}
        .board p{color:rgba(243,237,226,.72);max-width:46ch;margin:0 auto 30px}
        .board .btn{background:var(--board-ink);color:var(--board)}
        .board .btn:hover{background:#fff}
        .board .micro{color:rgba(243,237,226,.55)}

        footer{padding:44px 0 56px;text-align:center;color:var(--ink-soft);font-size:.83rem}
        footer a{text-decoration:none;color:var(--ink-soft)}
        footer a:hover{color:var(--ink)}
    </style>
</head>
<body data-guest-session="{{ $session->guest_token }}">
<div class="wrap">
    <nav>
        <a class="brand" href="{{ route('studio.landing.v1') }}"><span class="mark">&#10022;</span>{{ ___('Menu Studio') }}</a>
        <span class="nav-links">
            <a href="#showcase">{{ ___('Examples') }}</a>
            <a href="#how">{{ ___('How it works') }}</a>
            <a href="#faq">{{ ___('FAQ') }}</a>
            @if(($languages ?? collect())->count() > 1)
            <details class="lang">
                <summary>&#127760; {{ strtoupper(get_lang()) }}</summary>
                <div class="menu">
                    @foreach($languages as $lang)
                        <a class="{{ $lang->code === get_lang() ? 'on' : '' }}" href="{{ lang_url($lang->code) }}">
                            <span style="font-family:var(--mono);color:var(--ink-soft)">{{ strtoupper($lang->code) }}</span> {{ $lang->name }}
                        </a>
                    @endforeach
                </div>
            </details>
            @endif
            <a class="btn" href="{{ route('studio.app.shell') }}">{{ ___('Open Studio') }}</a>
        </span>
    </nav>

    <header class="hero">
        <span class="stamp">{{ ___('Free · No signup to try') }}</span>
        <h1>{{ ___('Your paper menu,') }} <em>{{ ___('reborn') }}</em> {{ ___('in 60 seconds') }}</h1>
        <p class="sub">{{ ___('Drop a photo, a PDF, or a handwritten page. Menu Studio reads every dish and price, then builds an online-ordering menu — or a print-ready, beautifully designed PDF.') }}</p>

        <div class="composer" role="group" aria-label="{{ ___('Upload your menu') }}">
            <a class="dropzone" href="{{ route('studio.app.shell') }}">
                <span class="di">&#128196;</span>
                <p>{{ ___('Drop your menu here') }}</p>
                <small>{{ ___('photo · PDF · handwritten page — or click to browse') }}</small>
            </a>
            <div class="chips">
                <a class="chip" href="{{ route('studio.app.shell') }}">&#128247; {{ ___('Photo of my menu') }}</a>
                <a class="chip" href="{{ route('studio.app.shell') }}">&#128196; {{ ___('PDF price list') }}</a>
                <a class="chip" href="{{ route('studio.app.shell') }}">&#9997;&#65039; {{ ___('Handwritten page') }}</a>
                <a class="chip" href="{{ route('studio.app.shell') }}">&#9000;&#65039; {{ ___('Type it instead') }}</a>
            </div>
            <div class="composer-foot">
                <span class="samples">{{ ___('No menu handy? Try a sample:') }} <a href="{{ route('studio.app.shell') }}">{{ ___('Trattoria') }}</a> · <a href="{{ route('studio.app.shell') }}">{{ ___('Sushi bar') }}</a> · <a href="{{ route('studio.app.shell') }}">{{ ___('Café') }}</a></span>
                <a class="btn accent" href="{{ route('studio.app.shell') }}">{{ ___('Convert my menu') }} &rarr;</a>
            </div>
        </div>
        <p class="micro"><b>&#10003;</b> {{ ___('No signup') }} &nbsp;·&nbsp; {{ ___('uploads auto-delete within 48 hours') }} &nbsp;·&nbsp; {{ ___('never used to train models') }}</p>
    </header>
</div>

<div class="trust">
    <div class="wrap">
        <div class="row">
            <div class="tstat"><div class="n">4,208</div><div class="l">{{ ___('menus converted') }}</div></div>
            <div class="tstat"><div class="n">61,540</div><div class="l">{{ ___('dishes extracted') }}</div></div>
            <div class="tstat"><div class="n">{{ ($languages ?? collect())->count() ?: 24 }}</div><div class="l">{{ ___('languages · Arabic native') }}</div></div>
            <div class="tstat"><div class="n">&#9733; 4.9</div><div class="l">{{ ___('from restaurant owners') }}</div></div>
        </div>
    </div>
</div>

<div class="wrap">
    <section id="showcase">
        <div class="sec-head">
            <span class="eyebrow">{{ ___('Proof, not promises') }}</span>
            <h2>{{ ___('From the page on your counter to the menu on their phone') }}</h2>
            <p>{{ ___('A real conversion: one photographed, handwritten menu — read, structured, and re-designed by the AI.') }}</p>
        </div>

        <div class="ba-stage">
            <div class="menu-card before" aria-label="{{ ___('Before: handwritten menu') }}">
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

            <div class="ba-mid">
                <span class="arrow" aria-hidden="true">&#10140;</span>
                <span class="tag">12 {{ ___('dishes') }} · 4 {{ ___('sections') }} · {{ ___('prices & notes kept') }} · 38 s</span>
            </div>

            <div class="menu-card after" aria-label="{{ ___('After: designed menu') }}">
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

        <div class="chat-demo" aria-label="{{ ___('Refine by chat example') }}">
            <span class="bubble user">{{ ___('make all prices 10% higher and hide the wine') }}</span>
            <span class="bubble ai">{{ ___('Done — 11 prices updated, “House red” hidden. Anything else?') }}</span>
        </div>
        <p class="chat-cap">{{ ___('After the upload, you refine it by chatting — no menu editor to learn.') }}</p>
    </section>

    <section>
        <div class="sec-head">
            <span class="eyebrow">{{ ___('One upload, two endings') }}</span>
            <h2>{{ ___('Take it online, or take it to print') }}</h2>
        </div>
        <div class="outputs">
            <article class="out-card">
                <span class="oc-ico">&#128241;</span>
                <h3>{{ ___('Online-ordering menu') }}</h3>
                <p>{{ ___('A live, hosted menu your guests open by QR code — and order from.') }}</p>
                <ul>
                    <li>{{ ___('Every dish, price and description, editable any time') }}</li>
                    <li>{{ ___('Cart, checkout and orders straight to the kitchen') }}</li>
                    <li>{{ ___('Preview free, refine free, for as long as you like') }}</li>
                </ul>
                <div class="out-gate">{{ ___("Publish it → sign in with Google. That's the whole gate.") }}</div>
            </article>
            <article class="out-card">
                <span class="oc-ico">&#128424;&#65039;</span>
                <h3>{{ ___('Designed PDF menu') }}</h3>
                <p>{{ ___('The AI art-directs a print-ready menu around your dishes — pick a style, it does the layout.') }}</p>
                <ul>
                    <li>{{ ___('Fine dining, café, street food or minimal presets') }}</li>
                    <li>{{ ___('Watermarked preview is free, always') }}</li>
                    <li>{{ ___('Regenerate until it looks like your restaurant') }}</li>
                </ul>
                <div class="out-gate">{{ ___('Clean download → a few credits. You start with free ones.') }}</div>
            </article>
        </div>
    </section>

    <section id="templates">
        <div class="sec-head">
            <span class="eyebrow">{{ ___('À la carte styles') }}</span>
            <h2>{{ ___('Pick a style — the AI designs around it') }}</h2>
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
                <a class="preset" href="{{ route('studio.app.shell') }}">
                    <img class="shot" src="{{ $m['img'] }}" alt="{{ $m['cap'] }} — {{ ___('real menu made with Menu Studio') }}" loading="lazy">
                    <span class="cap"><b>{{ $m['cap'] }}</b><span>{{ $m['d'] }}</span></span>
                </a>
            @endforeach
        </div>
    </section>

    <section id="how">
        <div class="sec-head">
            <span class="eyebrow">{{ ___("Tonight's tasting course") }}</span>
            <h2>{{ ___('How it works') }}</h2>
        </div>
        <div class="course">
            <div class="course-row">
                <span class="cn">I.</span><span class="ct">{{ ___('Upload') }}</span><span class="dots"></span><span class="time">~10 s</span>
                <span class="cd">{{ ___('A photo, a PDF, a handwritten page — even the laminated one with the coffee stain.') }}</span>
            </div>
            <div class="course-row">
                <span class="cn">II.</span><span class="ct">{{ ___('Review & refine') }}</span><span class="dots"></span><span class="time">~1 {{ ___('min') }}</span>
                <span class="cd">{{ ___('The AI extracts dishes, prices and sections. Fix anything by chatting: “move desserts up”, “translate to Arabic”.') }}</span>
            </div>
            <div class="course-row">
                <span class="cn">III.</span><span class="ct">{{ ___('Publish or download') }}</span><span class="dots"></span><span class="time">{{ ___('1 tap') }}</span>
                <span class="cd">{{ ___('Go live with a QR ordering menu, or download the designed PDF for print.') }}</span>
            </div>
        </div>
    </section>

    <section>
        <div class="credits">
            <div>
                <span class="eyebrow">{{ ___('On the house') }}</span>
                <h3>{{ ___('Start with free credits. Keep them forever.') }}</h3>
                <p>{{ ___('Exploring, uploading, previewing and refining cost nothing — no card, no account. Credits only come in when you download a clean, print-ready PDF. You get a starter balance free, sign in with Google to top it up, and bundles never expire.') }}</p>
                <p>{{ ___("And when you're ready for the full restaurant — QR ordering, tables, kitchen screens — your studio menu comes with you, already built.") }}</p>
            </div>
            <div class="ledger" aria-label="{{ ___('Example credit ledger') }}">
                <div class="head"><span>{{ ___('Your tab') }}</span><span>{{ ___('credits') }}</span></div>
                <div class="lr"><span>{{ ___('Welcome balance') }}</span><span class="plus">+10</span></div>
                <div class="lr"><span>{{ ___('Signed in with Google') }}</span><span class="plus">+15</span></div>
                <div class="lr"><span>{{ ___('Styled PDF download') }}</span><span>&minus;8</span></div>
                <div class="lr"><span>{{ ___('Balance') }}</span><span>17</span></div>
            </div>
        </div>
    </section>

    <section>
        <div class="quotes">
            <article class="quote">
                <p>“{{ ___('I photographed the menu taped to my register. Twenty minutes later customers were ordering from their phones.') }}”</p>
                <span class="who">— SARA K. · {{ ___('CAFÉ OWNER') }}, AMMAN</span>
            </article>
            <article class="quote">
                <p>“{{ ___('The PDF it designed looked better than the one we paid a designer for last year. We printed it the same week.') }}”</p>
                <span class="who">— MARCO T. · {{ ___('TRATTORIA') }}, LISBON</span>
            </article>
        </div>
    </section>

    <section id="faq">
        <div class="sec-head">
            <span class="eyebrow">{{ ___('Before you ask') }}</span>
            <h2>{{ ___('Questions, answered') }}</h2>
        </div>
        <div class="faq">
            <details><summary>{{ ___('Is it really free?') }}</summary><div class="ans">{{ ___('Yes — uploading, extracting, previewing and refining are free with no account. You pay only to download a clean designed PDF (credits) or when you upgrade to the full ordering platform. Publishing a basic online menu just needs a Google sign-in.') }}</div></details>
            <details><summary>{{ ___('What happens to my uploads?') }}</summary><div class="ans">{{ ___('Guest uploads auto-delete within 48 hours unless you sign in to keep them. Your menus are never used to train AI models.') }}</div></details>
            <details><summary>{{ ___('Can it read handwriting? Arabic?') }}</summary><div class="ans">{{ ___('Yes to both. It reads handwritten pages, photos and PDFs, and works in many languages including full right-to-left Arabic menus.') }}</div></details>
            <details><summary>{{ ___('What if it gets a dish or price wrong?') }}</summary><div class="ans">{{ ___("You review everything before publishing, and fix mistakes by chatting — “that's 12.50 not 1250” — no forms, no menu editor to learn.") }}</div></details>
        </div>
    </section>

    <section style="padding-top:24px">
        <div class="board">
            <span class="b-eyebrow">{{ ___("Tonight's special") }}</span>
            <h2>{{ ___("Your menu, remade — while the coffee's still hot") }}</h2>
            <p>{{ ___("One photo. Sixty seconds. An online menu your guests can order from, or a print-ready design you'll actually be proud to laminate.") }}</p>
            <a class="btn" href="{{ route('studio.app.shell') }}">{{ ___('Convert my menu — free') }} &rarr;</a>
            <p class="micro">{{ ___('No signup · no card · uploads auto-delete in 48 h') }}</p>
        </div>
    </section>

    <footer>
        {{ ___('Menu Studio') }} — {{ ___('Part of') }} <a href="{{ url('/') }}"><b>{{ config('settings.website_title', config('app.name')) }}</b></a>
    </footer>
</div>
</body>
</html>
