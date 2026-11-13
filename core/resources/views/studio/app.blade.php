{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    $cur = optional(current_language());
    $siteName = config('settings.website_title', config('app.name'));
    $draftJson = null;
    $hasDesign = false;
    if (isset($session)) {
        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        $draftPath = $session->storagePath('draft.json');
        if ($disk->exists($draftPath)) {
            $draftJson = json_decode($disk->get($draftPath), true) ?: null;
        }
        $hasDesign = $disk->exists($session->storagePath('design.png'));
    }
@endphp
<!DOCTYPE html>
<html lang="{{ get_lang() }}" dir="{{ $cur->direction ?? 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="dark">
    <meta name="theme-color" content="#0e0f13">
    <meta name="description" content="{{ ___('Turn a photo, PDF or handwritten menu into a designed, print-ready menu — free to try, no signup.') }}">
    <meta name="robots" content="noindex,follow">
    <title>{{ ___('Menu Studio') }} — {{ $siteName }}</title>
    @if(!empty(config('settings.site_favicon')))
    <link rel="icon" href="{{ asset('storage/logo/'.config('settings.site_favicon')) }}">
    @endif
    <style nonce="{{ csp_nonce() }}">
        :root{
            --bg:#0e0f13; --panel:#16181f; --panel-2:#1d2029; --line:#272b37; --line-2:#333849;
            --ink:#f4f5f7; --muted:#aab1bf; --faint:#818897;
            --accent:#7c5cff; --accent-soft:rgba(124,92,255,.14); --accent-2:#4f8cff;
            --ok:#34d399; --warn:#fbbf24; --err:#f87171;
            --radius:16px; --radius-s:10px;
            --shadow-1:0 1px 2px rgba(0,0,0,.4);
            --shadow-2:0 10px 30px -12px rgba(0,0,0,.65);
            --glow:0 0 0 1px rgba(124,92,255,.35), 0 4px 24px -6px rgba(124,92,255,.35);
            --z-drawer:30; --z-backdrop:50; --z-modal:51; --z-toast:60;
            --dur:.18s; --ease:cubic-bezier(.22,1,.36,1);
        }
        *{box-sizing:border-box}
        html,body{height:100%}
        body{margin:0;overflow:hidden;background:var(--bg);color:var(--ink);
            font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,"Noto Sans",sans-serif;
            font-size:15px;line-height:1.55;}
        body::before{content:"";position:fixed;inset:0;pointer-events:none;z-index:0;
            background:
                radial-gradient(600px 420px at 12% -6%, rgba(124,92,255,.12), transparent 60%),
                radial-gradient(720px 520px at 105% 110%, rgba(79,140,255,.08), transparent 60%);}
        ::placeholder{color:var(--faint);opacity:1}
        :focus-visible{outline:2px solid var(--accent);outline-offset:2px;border-radius:6px}
        button{font:inherit}
        .ic{width:19px;height:19px;flex:0 0 auto;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
        [hidden]{display:none !important}

        .ws{display:grid;grid-template-columns:64px 1fr;height:100dvh;position:relative;z-index:1}
        /* ---- left rail ---- */
        .rail{background:var(--panel);border-inline-end:1px solid var(--line);display:flex;flex-direction:column;
            align-items:center;gap:8px;padding:14px 0}
        .rail button,.rail a{width:44px;height:44px;border:0;background:transparent;color:var(--muted);border-radius:12px;
            cursor:pointer;display:flex;align-items:center;justify-content:center;text-decoration:none;
            transition:background var(--dur) var(--ease),color var(--dur) var(--ease)}
        .rail button:hover,.rail a:hover{background:var(--panel-2);color:var(--ink)}
        .rail .spacer{flex:1}
        .rail .new{background:var(--accent);color:#fff;box-shadow:var(--shadow-1)}
        .rail .new:hover{background:#8d70ff;color:#fff}

        /* ---- canvas ---- */
        .canvas{display:flex;flex-direction:column;min-width:0;min-height:0}
        .topbar{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 22px;border-bottom:1px solid var(--line)}
        .brand{font-weight:700;letter-spacing:-.01em;white-space:nowrap}
        .topbar-right{display:flex;align-items:center;gap:10px;min-width:0}
        .mob-only{display:none}
        .lang{position:relative}
        .lang summary{list-style:none;cursor:pointer;color:var(--muted);padding:8px 12px;border:1px solid var(--line);border-radius:999px;font-size:.85rem;display:flex;align-items:center;gap:6px}
        .lang summary::-webkit-details-marker{display:none}
        .lang .menu{position:absolute;inset-inline-end:0;top:110%;background:var(--panel-2);border:1px solid var(--line);
            border-radius:12px;padding:6px;min-width:190px;max-height:60vh;overflow:auto;z-index:var(--z-drawer);box-shadow:var(--shadow-2)}
        .lang .menu a{display:flex;gap:8px;padding:8px 10px;border-radius:8px;color:var(--ink);text-decoration:none;font-size:.9rem}
        .lang .menu a:hover{background:var(--panel)}
        .lang .menu a.on{background:var(--accent-soft);color:var(--ink)}
        .topup{display:flex;align-items:center;gap:8px;background:linear-gradient(90deg,var(--accent),var(--accent-2));
            color:#fff;border:0;border-radius:999px;padding:9px 16px;font-weight:700;cursor:pointer;white-space:nowrap;
            transition:filter var(--dur) var(--ease);box-shadow:var(--shadow-1)}
        .topup:hover{filter:brightness(1.1)}
        .topup .ic{width:16px;height:16px}

        /* ---- guest save/convert bar (W1-CTA) — guests only ---- */
        .savebar{display:flex;align-items:center;gap:12px;flex-wrap:wrap;flex:0 0 auto;
            padding:11px 22px;border-bottom:1px solid var(--line);
            background:linear-gradient(90deg,var(--accent-soft),transparent)}
        [dir=rtl] .savebar{background:linear-gradient(270deg,var(--accent-soft),transparent)}
        .savebar>.ic{color:var(--accent);width:20px;height:20px}
        .savebar .sb-txt{margin:0;color:var(--muted);font-size:.88rem;min-width:0}
        .savebar .sb-txt strong{color:var(--ink);font-weight:700;margin-inline-end:6px}
        .savebar .sb-actions{display:flex;gap:8px;margin-inline-start:auto;flex:0 0 auto}
        .savebar .sb-actions .btn{text-decoration:none;white-space:nowrap}
        @media (max-width:640px){
            .savebar{padding:10px 14px;gap:8px}
            .savebar .sb-txt{flex:1 1 100%}
            .savebar .sb-actions{margin-inline-start:0;width:100%}
            .savebar .sb-actions .btn{flex:1;justify-content:center}
        }

        /* ---- thread ---- */
        .thread{flex:1;min-height:0;overflow:auto;padding:28px 22px 12px;max-width:900px;width:100%;margin:0 auto;scroll-behavior:smooth}
        .hero{margin:4vh 0 30px}
        .hero h1{font-size:clamp(1.7rem,4.5vw,2.6rem);line-height:1.15;letter-spacing:-.02em;margin:0 0 10px;text-wrap:balance}
        .hero h1 em{color:var(--accent);font-style:normal}
        .hero p{color:var(--muted);margin:0;max-width:56ch}
        .hero .try{margin-top:16px;display:inline-flex;align-items:center;gap:8px;background:var(--panel);border:1px solid var(--line-2);
            color:var(--ink);border-radius:999px;padding:10px 18px;cursor:pointer;font-weight:600;
            transition:border-color var(--dur) var(--ease),box-shadow var(--dur) var(--ease),transform var(--dur) var(--ease)}
        .hero .try:hover{border-color:var(--accent);box-shadow:var(--glow);transform:translateY(-1px)}
        .empty h2{font-size:.95rem;margin:26px 0 12px;color:var(--muted);font-weight:600}
        .tasks,.templates{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:12px}
        .tile{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:16px;text-align:start;
            cursor:pointer;color:var(--ink);min-height:92px;display:flex;flex-direction:column;justify-content:space-between;gap:10px;
            transition:border-color var(--dur) var(--ease),transform var(--dur) var(--ease),box-shadow var(--dur) var(--ease)}
        .tile:hover{border-color:var(--accent);transform:translateY(-2px);box-shadow:var(--shadow-2)}
        .tile .ic{color:var(--accent);width:22px;height:22px}
        .tile .t{font-weight:600}
        .tile .d{color:var(--faint);font-size:.82rem}
        .tile[aria-pressed=true]{border-color:var(--accent);box-shadow:var(--glow)}
        .tpl{flex-direction:row;align-items:center;min-height:0}
        /* studio-templates P1-7: sandboxed template preview iframe */
        .tplprev{display:flex;flex-direction:column;gap:8px}
        .tplframe{width:100%;aspect-ratio:210/297;max-height:70vh;border:1px solid var(--line);border-radius:var(--radius);background:#fff}
        .tplframe.wide{aspect-ratio:auto;height:420px}
        .tplsel{background:var(--panel);color:var(--text);border:1px solid var(--line);border-radius:8px;padding:6px 10px;font:inherit}
        .tplbar{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
        .tplcolors{display:inline-flex;gap:4px}
        .tplcolors input{inline-size:30px;block-size:30px;border:1px solid var(--line);border-radius:6px;background:none;padding:2px}
        .override{display:flex;align-items:center;gap:8px;margin:-4px 0 6px;padding-inline-start:10px;font-size:.82rem;color:var(--faint)}
        .override .ovtxt{font-style:italic;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-inline-size:60%}
        .tpl .sw{width:34px;height:44px;border-radius:6px;flex:0 0 auto;box-shadow:var(--shadow-1)}
        .tpl img.thumb{object-fit:cover;object-position:top;border:1px solid var(--line)}
        /* Template tiles: card layout with a big page preview (thumbs are 794px wide). */
        .studio-template{flex-direction:column;align-items:stretch;gap:10px;padding:10px}
        .studio-template img.thumb{width:100%;height:170px;border-radius:8px;box-shadow:var(--shadow-1)}
        .studio-template:hover img.thumb{box-shadow:var(--shadow-2)}
        .trust{color:var(--faint);font-size:.8rem;margin-top:28px;display:flex;align-items:center;gap:8px}
        .trust .ic{width:15px;height:15px}

        .msg{margin:16px 0;display:flex;gap:12px;animation:rise .28s var(--ease)}
        @keyframes rise{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
        .msg .who{width:30px;height:30px;border-radius:50%;flex:0 0 auto;display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:700}
        .msg.user .who{background:var(--panel-2);color:var(--muted)}
        .msg.ai .who{background:var(--accent);color:#fff}
        .bubble{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:14px 16px;flex:1;min-width:0;box-shadow:var(--shadow-1)}
        .bubble p{margin:0}
        .bubble .note{color:var(--muted);font-size:.88rem}
        .bubble .act{margin-top:12px;display:flex;gap:8px;flex-wrap:wrap}
        .btn{display:inline-flex;align-items:center;gap:7px;background:var(--panel-2);border:1px solid var(--line-2);color:var(--ink);
            border-radius:var(--radius-s);padding:9px 14px;cursor:pointer;font-weight:600;font-size:.9rem;
            transition:border-color var(--dur) var(--ease),background var(--dur) var(--ease)}
        .btn:hover{border-color:var(--accent)}
        .btn .ic{width:16px;height:16px}
        .btn.primary{background:var(--accent);border-color:var(--accent);color:#fff}
        .btn.primary:hover{background:#8d70ff}
        .btn.danger{color:var(--err)}
        .btn.danger:hover{border-color:var(--err)}
        .btn:disabled{opacity:.5;cursor:default;pointer-events:none}
        .cost{font-weight:400;color:rgba(255,255,255,.75);font-size:.8rem}
        .btn:not(.primary) .cost{color:var(--faint)}

        /* extracted draft editor */
        .sum{display:flex;align-items:center;gap:8px;font-weight:600;margin-bottom:4px}
        .sum .ic{color:var(--ok);width:17px;height:17px}
        .flagline{display:flex;align-items:center;gap:8px;color:var(--warn);font-size:.85rem;margin:6px 0}
        .flagline .ic{width:15px;height:15px}
        .draft{margin-top:10px}
        .cat-h{display:flex;align-items:center;gap:8px;margin:14px 0 4px}
        .cat-h input{flex:1;background:transparent;border:0;border-bottom:1px solid transparent;color:var(--accent);
            font-weight:700;font-size:1rem;padding:4px 2px;min-width:0}
        .cat-h input:hover,.cat-h input:focus{border-bottom-color:var(--line-2);outline:none}
        .item{display:flex;align-items:center;gap:8px;padding:2px 0}
        .item input{background:transparent;border:0;border-bottom:1px solid transparent;color:var(--ink);padding:6px 2px;min-width:0}
        .item input:hover,.item input:focus{border-bottom-color:var(--line-2);outline:none}
        .item .nm{flex:1}
        .item .pr{width:90px;text-align:end;color:var(--muted);font-variant-numeric:tabular-nums}
        .item .pr.miss{border-bottom-color:var(--warn)}
        .item .pr.outlier{color:var(--warn)}
        .item .rm{background:transparent;border:0;color:var(--faint);cursor:pointer;padding:4px;border-radius:6px;display:flex}
        .item .rm:hover{color:var(--err);background:var(--panel-2)}
        .item .rm .ic{width:15px;height:15px}
        .toolbar{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;padding-top:12px;border-top:1px dashed var(--line)}
        .chip{display:inline-flex;align-items:center;gap:6px;background:var(--panel-2);border:1px solid var(--line);color:var(--muted);
            border-radius:999px;padding:6px 12px;cursor:pointer;font-size:.82rem;transition:border-color var(--dur) var(--ease),color var(--dur) var(--ease)}
        .chip:hover{border-color:var(--accent);color:var(--ink)}
        .chip .ic{width:14px;height:14px}
        .pctform{display:none;align-items:center;gap:6px}
        .pctform.open{display:inline-flex}
        .pctform input{width:70px;background:var(--panel-2);border:1px solid var(--line-2);color:var(--ink);border-radius:8px;padding:6px 8px}
        .savestate{color:var(--faint);font-size:.78rem;margin-inline-start:auto;align-self:center}

        /* design preview */
        .skel{border-radius:12px;background:linear-gradient(100deg,var(--panel-2) 40%,var(--line) 50%,var(--panel-2) 60%);
            background-size:200% 100%;animation:shimmer 1.3s linear infinite;margin-top:10px}
        @keyframes shimmer{to{background-position:-200% 0}}
        .design-img{max-width:min(420px,100%);border-radius:12px;margin-top:10px;display:block;box-shadow:var(--shadow-2);border:1px solid var(--line-2)}

        /* ---- composer ---- */
        .composer-wrap{padding:10px 22px calc(16px + env(safe-area-inset-bottom))}
        .modebar{max-width:880px;margin:0 auto 8px;display:flex;align-items:center;gap:8px}
        .seg{display:inline-flex;background:var(--panel);border:1px solid var(--line);border-radius:999px;padding:3px}
        .seg button{border:0;background:transparent;color:var(--muted);border-radius:999px;padding:5px 14px;cursor:pointer;font-size:.82rem;font-weight:600}
        .seg button[aria-pressed=true]{background:var(--accent);color:#fff}
        .modehint{color:var(--faint);font-size:.78rem}
        .composer{max-width:880px;margin:0 auto;background:linear-gradient(var(--panel),var(--panel)) padding-box,
            linear-gradient(120deg,rgba(124,92,255,.55),rgba(79,140,255,.22)) border-box;
            border:1px solid transparent;border-radius:22px;padding:10px 10px 10px 16px;display:flex;align-items:flex-end;gap:10px;
            transition:box-shadow var(--dur) var(--ease)}
        .composer:focus-within{box-shadow:var(--glow)}
        .composer.drag{box-shadow:var(--glow)}
        .composer .attach{width:38px;height:38px;border-radius:50%;border:1px solid var(--line-2);background:var(--panel-2);
            color:var(--ink);cursor:pointer;flex:0 0 auto;display:flex;align-items:center;justify-content:center;margin-bottom:1px}
        .composer .attach:hover{border-color:var(--accent)}
        .composer textarea{flex:1;background:transparent;border:0;color:var(--ink);font:inherit;outline:none;min-width:0;
            resize:none;max-height:160px;padding:8px 0;line-height:1.45}
        .opts{display:flex;align-items:center;gap:6px;flex:0 0 auto;margin-bottom:2px}
        .opts select{background:var(--panel-2);border:1px solid var(--line);color:var(--muted);border-radius:999px;padding:6px 10px;font-size:.82rem}
        .send{width:40px;height:40px;border-radius:50%;border:0;background:linear-gradient(90deg,var(--accent),var(--accent-2));
            color:#fff;cursor:pointer;flex:0 0 auto;display:flex;align-items:center;justify-content:center;
            transition:filter var(--dur) var(--ease)}
        .send:hover{filter:brightness(1.12)}
        .send:disabled{opacity:.55;cursor:default}
        .send.busy .ic{animation:spin 1s linear infinite}
        @keyframes spin{to{transform:rotate(360deg)}}
        [dir=rtl] .send .ic-send{transform:scaleX(-1)}
        .filesrow{max-width:880px;margin:8px auto 0;display:none;align-items:center;gap:8px;color:var(--muted);font-size:.82rem}
        .filesrow.on{display:flex}
        .filesrow .clear{background:transparent;border:0;color:var(--faint);cursor:pointer;display:flex;padding:3px;border-radius:6px}
        .filesrow .clear:hover{color:var(--err)}
        .filesrow .clear .ic{width:14px;height:14px}

        /* ---- drawer ---- */
        .backdrop{position:fixed;inset:0;background:rgba(6,7,10,.6);z-index:var(--z-backdrop);opacity:0;
            transition:opacity var(--dur) var(--ease)}
        .backdrop.on{opacity:1}
        .drawer{position:fixed;top:0;bottom:0;inset-inline-end:0;width:min(400px,94vw);background:var(--panel);
            border-inline-start:1px solid var(--line);z-index:var(--z-modal);padding:20px;overflow:auto;
            transform:translateX(calc(var(--dirflip,1) * 100%));transition:transform .24s var(--ease);box-shadow:var(--shadow-2)}
        [dir=rtl] .drawer{--dirflip:-1}
        .drawer.on{transform:none}
        .drawer h2{margin:0 0 4px;font-size:1.1rem;display:flex;align-items:center;justify-content:space-between}
        .drawer h3{margin:22px 0 8px;font-size:.85rem;color:var(--muted);font-weight:600}
        .drawer .x{background:transparent;border:0;color:var(--muted);cursor:pointer;padding:6px;border-radius:8px;display:flex}
        .drawer .x:hover{background:var(--panel-2);color:var(--ink)}
        .kv{display:flex;justify-content:space-between;gap:10px;padding:7px 0;border-bottom:1px dashed var(--line);font-size:.88rem}
        .kv .k{color:var(--faint)}
        .drawer .row{display:flex;gap:8px;margin-top:8px}
        .drawer input[type=text]{flex:1;background:var(--panel-2);border:1px solid var(--line-2);color:var(--ink);border-radius:var(--radius-s);padding:9px 12px;min-width:0}
        .drawer .hint{color:var(--faint);font-size:.78rem;margin:6px 0 0}
        .drawer .warnhint{color:var(--warn)}

        /* ---- pricing modal ---- */
        .modal{position:fixed;inset:0;background:rgba(6,7,10,.86);display:flex;align-items:center;justify-content:center;z-index:var(--z-modal);padding:20px}
        .modal-card{background:linear-gradient(180deg,#191b22,#111318);border:1px solid var(--line);border-radius:20px;
            width:min(940px,96vw);max-height:92vh;overflow:auto;padding:34px 28px;position:relative;text-align:center;
            animation:pop .22s var(--ease)}
        @keyframes pop{from{opacity:0;transform:scale(.97)}to{opacity:1;transform:none}}
        .modal-card h1{font-size:1.8rem;margin:0 0 6px;letter-spacing:-.02em}
        .modal-card .sub{color:var(--muted);margin:0 0 8px}
        .modal-card .costnote{color:var(--faint);font-size:.85rem;margin:0 0 22px}
        .modal-card .x{position:absolute;inset-inline-end:14px;top:12px;background:transparent;border:0;color:var(--muted);cursor:pointer;padding:8px;border-radius:8px;display:flex}
        .modal-card .x:hover{background:var(--panel-2);color:var(--ink)}
        .tiers{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;text-align:start}
        .tier{background:var(--panel);border:1px solid var(--line);border-radius:16px;padding:18px;cursor:pointer;position:relative;display:block;
            transition:border-color var(--dur) var(--ease),transform var(--dur) var(--ease)}
        .tier:hover{transform:translateY(-2px)}
        .tier input{position:absolute;opacity:0;pointer-events:none}
        .tier.on{border-color:var(--accent);box-shadow:var(--glow)}
        .tier .rec-badge{position:absolute;inset-inline-end:12px;top:12px;background:var(--accent);color:#fff;font-size:.65rem;padding:3px 8px;border-radius:999px;font-weight:700}
        .tier .nm{font-weight:600}
        .tier .pr{font-size:1.7rem;font-weight:800;margin:8px 0 2px;font-variant-numeric:tabular-nums}
        .tier .pr span{font-size:.9rem;color:var(--muted);font-weight:600}
        .tier .cr{color:var(--accent);font-weight:700;font-size:.9rem;display:flex;align-items:center;gap:5px}
        .tier .cr .ic{width:14px;height:14px}
        .tier .tag{color:var(--faint);font-size:.8rem;margin:2px 0 12px}
        .tier .row{color:var(--muted);font-size:.85rem;padding:5px 0;border-top:1px dashed var(--line)}
        .topup-cta{margin:22px auto 6px;display:flex;align-items:center;gap:10px;justify-content:center;background:#fff;color:#111;
            border:0;border-radius:999px;padding:14px 26px;font-weight:700;cursor:pointer;transition:filter var(--dur) var(--ease)}
        .topup-cta:hover{filter:brightness(.92)}
        .topup-cta:disabled{opacity:.6;cursor:default}
        .foot{color:var(--faint);font-size:.8rem}

        /* ---- toasts ---- */
        .toasts{position:fixed;bottom:calc(16px + env(safe-area-inset-bottom));inset-inline-start:50%;transform:translateX(calc(var(--dirflip,1) * -50%));
            z-index:var(--z-toast);display:flex;flex-direction:column;gap:8px;align-items:center;pointer-events:none}
        [dir=rtl] .toasts{--dirflip:-1}
        .toast{background:var(--panel-2);border:1px solid var(--line-2);color:var(--ink);border-radius:12px;padding:11px 18px;
            font-size:.9rem;box-shadow:var(--shadow-2);animation:rise .25s var(--ease);max-width:min(480px,92vw);pointer-events:auto}
        .toast.ok{border-color:rgba(52,211,153,.4)}
        .toast.err{border-color:rgba(248,113,113,.45)}

        @media (prefers-reduced-motion: reduce){
            *,*::before,*::after{animation-duration:.01ms !important;animation-iteration-count:1 !important;transition-duration:.01ms !important}
            .thread{scroll-behavior:auto}
        }
        @media (max-width:640px){
            .ws{grid-template-columns:1fr}
            .rail{display:none}
            .mob-only{display:flex}
            .topbar{padding:10px 14px}
            .topbar .brand{font-size:.95rem}
            .topup .label{display:none}
            #wizard-cta{display:none}
            .thread{padding:20px 14px 8px}
            .composer-wrap{padding:8px 12px calc(12px + env(safe-area-inset-bottom))}
            .tasks,.templates{grid-template-columns:repeat(2,1fr)}
            .modehint{display:none}
            .modal{padding:0;align-items:flex-end}
            .modal-card{width:100%;max-height:88vh;border-radius:20px 20px 0 0;padding:26px 18px}
            .tiers{display:flex;overflow-x:auto;scroll-snap-type:x mandatory;padding-bottom:6px}
            .tier{flex:0 0 78%;scroll-snap-align:center}
        }
    </style>
</head>
<body data-output="{{ $session->output_type ?: 'pdf' }}">
{{-- Inline SVG icon sprite (Lucide-style, stroke=currentColor) --}}
<svg hidden xmlns="http://www.w3.org/2000/svg">
    <symbol id="i-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
    <symbol id="i-panel" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M15 4v16"/></symbol>
    <symbol id="i-grid" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></symbol>
    <symbol id="i-info" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 8h.01M11 12h1v5h1"/></symbol>
    <symbol id="i-clip" viewBox="0 0 24 24"><path d="M21 12.5 12.7 20.8a5.5 5.5 0 0 1-7.8-7.8L13.2 4.7a3.7 3.7 0 0 1 5.2 5.2L10.1 18a1.8 1.8 0 0 1-2.6-2.6l7.8-7.7"/></symbol>
    <symbol id="i-send" viewBox="0 0 24 24"><path d="M5 12h13M13 6l6 6-6 6"/></symbol>
    <symbol id="i-spark" viewBox="0 0 24 24"><path d="M12 3l1.9 5.6L19.5 10l-5.6 1.9L12 17.5l-1.9-5.6L4.5 10l5.6-1.4z"/></symbol>
    <symbol id="i-x" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></symbol>
    <symbol id="i-copy" viewBox="0 0 24 24"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></symbol>
    <symbol id="i-share" viewBox="0 0 24 24"><circle cx="6" cy="12" r="3"/><circle cx="18" cy="6" r="3"/><circle cx="18" cy="18" r="3"/><path d="M8.7 10.6l6.6-3.2M8.7 13.4l6.6 3.2"/></symbol>
    <symbol id="i-down" viewBox="0 0 24 24"><path d="M12 4v11M6 11l6 6 6-6M4 20h16"/></symbol>
    <symbol id="i-redo" viewBox="0 0 24 24"><path d="M21 8a9 9 0 1 0 2 6"/><path d="M21 3v5h-5"/></symbol>
    <symbol id="i-trash" viewBox="0 0 24 24"><path d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M6 7l1 13h10l1-13M10 11v6M14 11v6"/></symbol>
    <symbol id="i-undo" viewBox="0 0 24 24"><path d="M3 8a9 9 0 1 1-2 6"/><path d="M3 3v5h5"/></symbol>
    <symbol id="i-img" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M21 16l-5-5-9 9"/></symbol>
    <symbol id="i-pen" viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></symbol>
    <symbol id="i-file" viewBox="0 0 24 24"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/></symbol>
    <symbol id="i-check" viewBox="0 0 24 24"><path d="M4 12.5l5 5L20 6.5"/></symbol>
    <symbol id="i-warn" viewBox="0 0 24 24"><path d="M12 3 2.5 20h19z"/><path d="M12 10v4M12 17h.01"/></symbol>
    <symbol id="i-ext" viewBox="0 0 24 24"><path d="M14 4h6v6M20 4l-9 9M20 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h5"/></symbol>
    <symbol id="i-globe" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18 15 15 0 0 1 0-18"/></symbol>
    <symbol id="i-shield" viewBox="0 0 24 24"><path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/></symbol>
    <symbol id="i-tag" viewBox="0 0 24 24"><path d="M3 12V4a1 1 0 0 1 1-1h8l9 9-9 9z"/><circle cx="8" cy="8" r="1.5"/></symbol>
    <symbol id="i-percent" viewBox="0 0 24 24"><path d="M19 5 5 19"/><circle cx="7" cy="7" r="2.5"/><circle cx="17" cy="17" r="2.5"/></symbol>
    <symbol id="i-loader" viewBox="0 0 24 24"><path d="M12 3a9 9 0 1 1-9 9"/></symbol>
</svg>

<div class="ws">
    <aside class="rail" aria-label="{{ ___('Studio') }}">
        <button class="new" id="rail-new" title="{{ ___('Start over') }}" aria-label="{{ ___('Start over') }}"><svg class="ic"><use href="#i-plus"/></svg></button>
        <button id="rail-history" title="{{ ___('Session & sharing') }}" aria-label="{{ ___('Session & sharing') }}" aria-expanded="false" aria-controls="drawer"><svg class="ic"><use href="#i-panel"/></svg></button>
        {{-- Returns to the dashboard (or sign-in). Uses a grid/dashboard glyph, not
             a person icon — a user avatar reads as "my account" and made this link
             feel like it mis-navigated when it dropped you on the dashboard. --}}
        <a href="{{ $authed ? url('/dashboard') : route('login') }}" title="{{ $authed ? ___('Dashboard') : ___('Sign in') }}" aria-label="{{ $authed ? ___('Dashboard') : ___('Sign in') }}"><svg class="ic"><use href="#i-grid"/></svg></a>
        <span class="spacer"></span>
        <a href="{{ route('studio') }}" title="{{ ___('About Menu Studio') }}" aria-label="{{ ___('About Menu Studio') }}"><svg class="ic"><use href="#i-info"/></svg></a>
    </aside>

    <main class="canvas">
        <header class="topbar">
            <div style="display:flex;align-items:center;gap:8px;min-width:0">
                <button class="rail new mob-only" id="mob-new" title="{{ ___('Start over') }}" aria-label="{{ ___('Start over') }}"
                    style="width:36px;height:36px;border:0;border-radius:10px;background:var(--accent);color:#fff;align-items:center;justify-content:center;cursor:pointer"><svg class="ic" style="width:17px;height:17px"><use href="#i-plus"/></svg></button>
                <div class="brand">{{ ___('Menu Studio') }}</div>
            </div>
            <div class="topbar-right">
                <button class="mob-only" id="mob-history" aria-label="{{ ___('Session & sharing') }}" aria-expanded="false" aria-controls="drawer"
                    style="width:36px;height:36px;border:1px solid var(--line);border-radius:10px;background:transparent;color:var(--muted);align-items:center;justify-content:center;cursor:pointer"><svg class="ic" style="width:17px;height:17px"><use href="#i-panel"/></svg></button>
                @if($languages->count() > 1)
                <details class="lang">
                    <summary aria-label="{{ ___('Language') }}"><svg class="ic" style="width:15px;height:15px"><use href="#i-globe"/></svg> {{ strtoupper(get_lang()) }}</summary>
                    <div class="menu">
                        @foreach($languages as $lang)
                            <a class="{{ $lang->code === get_lang() ? 'on' : '' }}" @if($lang->code === get_lang()) aria-current="true" @endif href="{{ lang_url($lang->code) }}">
                                <span style="font-family:monospace;color:var(--faint)">{{ strtoupper($lang->code) }}</span> {{ $lang->name }}
                            </a>
                        @endforeach
                    </div>
                </details>
                @endif
                @if($authed)
                <a class="btn" id="wizard-cta" href="{{ route('stores.create') }}" style="text-decoration:none;white-space:nowrap">
                    <svg class="ic" style="width:15px;height:15px"><use href="#i-ext"/></svg> {{ ___('Create a full store') }}
                </a>
                @endif
                <button class="topup" id="topup-btn">
                    <svg class="ic"><use href="#i-spark"/></svg>
                    <span id="balance" aria-live="polite" aria-label="{{ ___('Credit balance') }}">{{ $balance }}</span>
                    <span class="label">· {{ ___('Top up credits') }}</span>
                </button>
            </div>
        </header>

        @unless($authed)
        {{-- W1-CTA: guest → owner conversion prompt. Presentation only — a visible
             invitation to save the current (cookie-scoped) session by creating an
             account or signing in. Renders ONLY for guests; an authed owner never
             sees it. The auth links carry a ?redirect back to studio.app.shell so
             the user lands here again after authenticating.
             REALITY DELTA: the shared LoginController currently sends a fresh login
             to the dashboard rather than honoring redirect()->intended()/?redirect
             (documented in the Enterprise package's Marketplace DirectoryClaimController
             ~L38). The ?redirect param is the forward-looking contract; actually
             honoring it belongs to a controller lane, outside this view's fence. --}}
        <section class="savebar" aria-label="{{ ___('Save my work') }}">
            <svg class="ic" aria-hidden="true"><use href="#i-shield"/></svg>
            <p class="sb-txt">
                <strong>{{ ___('Save my work') }}</strong>
                <span>{{ ___('Create a free account to keep your menu, drafts and credits on any device.') }}</span>
            </p>
            <div class="sb-actions">
                <a class="btn primary" href="{{ route('register', ['redirect' => route('studio.app.shell')]) }}">{{ ___('Create account') }}</a>
                <a class="btn" href="{{ route('login', ['redirect' => route('studio.app.shell')]) }}">{{ ___('Sign in') }}</a>
            </div>
        </section>
        @endunless

        <div class="thread" id="thread">
            <div class="empty" id="empty">
                <div class="hero">
                    <h1>{{ ___('Bring your menu.') }} <em>{{ ___('Leave with a design.') }}</em></h1>
                    <p>{{ ___('Snap a photo, drop a PDF, or paste the text. The AI reads every dish, you fix anything it missed, then generate a print-ready designed menu.') }}</p>
                    {{-- AI transparency: Studio image generation is platform-only (Nano Banana / Gemini image). --}}
                    <p class="ai-badge-static" style="display:inline-flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:.85rem;color:var(--muted,#8a8a8a);margin-top:.35rem">
                        <span aria-hidden="true">⚡</span>
                        <span>{{ ___('Runs on') }} <strong>Gemini</strong> · {{ config('studio.gemini.model', 'gemini-3.1-flash-lite-image') }}</span>
                        <span style="display:inline-block;padding:1px 8px;border-radius:999px;background:rgba(124,92,255,.15);color:var(--accent,#7c5cff);font-size:.75rem">{{ ___('Platform key') }}</span>
                    </p>
                    <button class="try" id="try-sample"><svg class="ic" style="width:16px;height:16px;color:var(--accent)"><use href="#i-spark"/></svg> {{ ___('Try it with a sample menu') }}</button>
                </div>
                <h2>{{ ___('Popular tasks') }}</h2>
                <div class="tasks">
                    <button class="tile" data-task="new"><svg class="ic"><use href="#i-img"/></svg><span><span class="t">{{ ___('Start new menu') }}</span><br><span class="d">{{ ___('Upload a photo or PDF') }}</span></span></button>
                    <button class="tile" data-task="handwriting"><svg class="ic"><use href="#i-pen"/></svg><span><span class="t">{{ ___('Handwriting to menu') }}</span><br><span class="d">{{ ___('Snap a handwritten menu') }}</span></span></button>
                    <button class="tile" data-task="refresh"><svg class="ic"><use href="#i-file"/></svg><span><span class="t">{{ ___('Refresh old menu') }}</span><br><span class="d">{{ ___('Restyle an existing PDF') }}</span></span></button>
                </div>
                @if (!empty($templatePackages))
                <h2>{{ ___('Menu templates') }}</h2>
                <div class="templates" id="tpl-gallery">
                    @foreach ($templatePackages as $tp)
                        <button class="tile tpl studio-template" data-template="{{ $tp['slug'] }}" data-aspects="{{ implode(',', $tp['aspects']) }}" aria-pressed="false">
                            @if (!empty($tp['thumb']))
                                <img class="sw thumb" src="{{ $tp['thumb'] }}" alt="" loading="lazy">
                            @else
                                <span class="sw" style="background:linear-gradient(150deg, {{ $tp['colors']['accent'] ?? '#7c5cff' }}, {{ $tp['colors']['bg'] ?? '#4f8cff' }})"></span>
                            @endif
                            <span><span class="t">{{ ___($tp['name']) }}</span><br><span class="d">{{ ___('Editable template') }}</span></span>
                        </button>
                    @endforeach
                </div>
                @endif
                <h2>{{ ___('Pick a style, then describe the details') }}</h2>
                <div class="templates">
                    @php
                        $swatches = [
                            'fine_dining' => 'linear-gradient(150deg,#c9a24b,#6d5320)',
                            'cafe'        => 'linear-gradient(150deg,#c97b4a,#7a3f1d)',
                            'street_food' => 'linear-gradient(150deg,#e2453d,#8d1f1a)',
                            'minimal'     => 'linear-gradient(150deg,#e8e6e0,#9d9b94)',
                        ];
                    @endphp
                    @foreach($presets as $key => $seed)
                        <button class="tile tpl studio-preset" data-preset="{{ $key }}" aria-pressed="false" title="{{ $seed }}">
                            <span class="sw" style="background:{{ $swatches[$key] ?? 'linear-gradient(150deg,#7c5cff,#4f8cff)' }}"></span>
                            <span><span class="t">{{ ___(ucwords(str_replace('_',' ',$key))) }}</span><br><span class="d">{{ ___('Style preset') }}</span></span>
                        </button>
                    @endforeach
                </div>
                <p class="trust"><svg class="ic"><use href="#i-shield"/></svg> {{ ___('No signup needed. Uploads auto-delete after 48 hours and are never used to train AI.') }}</p>
            </div>
        </div>

        <div class="composer-wrap">
            <div class="modebar" id="modebar" hidden>
                <div class="seg" role="group" aria-label="{{ ___('Composer mode') }}">
                    <button id="mode-extract" aria-pressed="true">{{ ___('Read a menu') }}</button>
                    <button id="mode-style" aria-pressed="false">{{ ___('Describe the look') }}</button>
                </div>
                <span class="modehint" id="modehint"></span>
            </div>
            <div class="composer" id="composer">
                <button class="attach" id="attach" aria-label="{{ ___('Attach a menu photo or PDF') }}"><svg class="ic"><use href="#i-clip"/></svg></button>
                <input type="file" id="files" hidden multiple accept="image/*,application/pdf">
                <textarea id="prompt" rows="1" placeholder="{{ ___('Paste your menu, or describe the look…') }}" aria-label="{{ ___('Message') }}"></textarea>
                <div class="opts">
                    <select id="aspect" aria-label="{{ ___('Page size') }}">
                        <option value="a4">{{ ___('A4') }}</option>
                        <option value="letter">{{ ___('Letter') }}</option>
                        <option value="square">{{ ___('Square') }}</option>
                        <option value="story">{{ ___('Story (9:16)') }}</option>
                    </select>
                </div>
                <button class="send" id="send" aria-label="{{ ___('Send') }}"><svg class="ic ic-send"><use href="#i-send"/></svg></button>
            </div>
            <div class="filesrow" id="filesrow">
                <svg class="ic" style="width:15px;height:15px"><use href="#i-clip"/></svg>
                <span id="fileschip"></span>
                <button class="clear" id="files-clear" aria-label="{{ ___('Remove attachments') }}"><svg class="ic"><use href="#i-x"/></svg></button>
            </div>
        </div>
    </main>
</div>

{{-- Session / sharing / privacy drawer --}}
<div class="backdrop" id="drawer-backdrop" hidden></div>
<aside class="drawer" id="drawer" role="dialog" aria-modal="true" aria-label="{{ ___('Session & sharing') }}">
    <h2>{{ ___('Your session') }} <button class="x" id="drawer-x" aria-label="{{ ___('Close') }}"><svg class="ic"><use href="#i-x"/></svg></button></h2>
    <div class="kv"><span class="k">{{ ___('Status') }}</span><span id="d-status">{{ ___(ucfirst($session->status ?: 'exploring')) }}</span></div>
    <div class="kv"><span class="k">{{ ___('Credits') }}</span><span id="d-balance">{{ $balance }}</span></div>
    <div class="kv"><span class="k">{{ ___('Auto-deletes') }}</span><span>{{ optional($session->expires_at)->diffForHumans() ?: '—' }}</span></div>

    <h3>{{ ___('Continue on another device') }}</h3>
    <div class="row"><button class="btn" id="copy-resume"><svg class="ic"><use href="#i-copy"/></svg> {{ ___('Copy private resume link') }}</button></div>
    <p class="hint warnhint">{{ ___('This link IS your session — anyone with it can spend your credits. Never post it publicly.') }}</p>

    <h3>{{ ___('Share a preview') }}</h3>
    <div class="row">
        <button class="btn" id="mint-share"><svg class="ic"><use href="#i-share"/></svg> {{ ___('Copy public share link') }}</button>
        <a class="btn" id="open-share" target="_blank" rel="noopener" hidden><svg class="ic"><use href="#i-ext"/></svg> {{ ___('Open') }}</a>
    </div>
    <p class="hint">{{ ___('A read-only, watermarked preview of your extracted menu. Safe to post anywhere.') }}</p>

    <h3>{{ ___('Give credits, get credits') }}</h3>
    <div class="row"><button class="btn" id="copy-referral"><svg class="ic"><use href="#i-copy"/></svg> {{ ___('Copy my referral code') }}</button></div>
    <div class="row">
        <input type="text" id="referral-input" placeholder="{{ ___('Paste a friend’s code') }}" aria-label="{{ ___('Referral code') }}">
        <button class="btn" id="apply-referral">{{ ___('Apply') }}</button>
    </div>
    <p class="hint">{{ ___('You both get :n bonus credits.', ['n' => config('studio.referral_bonus', 5)]) }}</p>

    <h3>{{ ___('Privacy') }}</h3>
    <div class="row">
        <a class="btn" href="{{ route('studio.export') }}"><svg class="ic"><use href="#i-down"/></svg> {{ ___('Export my data') }}</a>
        <button class="btn danger" id="forget-btn"><svg class="ic"><use href="#i-trash"/></svg> {{ ___('Delete everything now') }}</button>
    </div>
    <p class="hint">{{ ___('Everything is auto-deleted 48 hours after your last visit either way.') }}</p>
</aside>

{{-- Pricing modal --}}
<div class="modal" id="pricing" hidden role="dialog" aria-modal="true" aria-label="{{ ___('Top up credits') }}">
    <div class="modal-card">
        <button class="x" id="pricing-x" aria-label="{{ ___('Close') }}"><svg class="ic"><use href="#i-x"/></svg></button>
        <h1>{{ ___('Recharge your Studio.') }}</h1>
        <p class="sub">{{ ___('Buy once. No subscription. Then keep designing right away.') }}</p>
        <p class="costnote" id="costnote">{{ ___('One designed menu costs :n credits.', ['n' => config('studio.credit_cost.pdf_design', 5)]) }}</p>
        <div class="tiers" role="radiogroup" aria-label="{{ ___('Credit bundles') }}">
            @foreach($bundles as $i => $b)
                <label class="tier {{ !empty($b['recommended']) ? 'on' : '' }}" data-credits="{{ $b['credits'] }}" data-key="{{ $b['key'] }}">
                    <input type="radio" name="bundle" value="{{ $b['key'] }}" {{ !empty($b['recommended']) ? 'checked' : '' }}>
                    @if(!empty($b['recommended']))<span class="rec-badge">{{ ___('Recommended') }}</span>@endif
                    <span class="nm">{{ ___($b['name']) }}</span>
                    <div class="pr">{{ $b['price'] }} <span>{{ $currency }}</span></div>
                    <div class="cr"><svg class="ic"><use href="#i-spark"/></svg> {{ $b['credits'] }} {{ ___('credits') }}</div>
                    <div class="tag">{{ ___($b['tagline']) }}</div>
                    <div class="row">≈ {{ $b['quality'] }} {{ ___('designed menus') }}</div>
                </label>
            @endforeach
        </div>
        <button class="topup-cta" id="topup-cta"><svg class="ic" style="width:16px;height:16px"><use href="#i-spark"/></svg> <span id="cta-credits">{{ collect($bundles)->firstWhere('recommended', true)['credits'] ?? ($bundles[0]['credits'] ?? '') }}</span> {{ ___('top up') }}</button>
        <p class="foot">{{ ___('One-time payment · no subscription · credits never expire') }}</p>
    </div>
</div>

<div class="toasts" id="toasts" aria-live="polite"></div>

<script src="{{ asset('assets/global/pdfjs/render-pdf.js') }}"></script>
<script nonce="{{ csp_nonce() }}">
(function(){
    'use strict';
    var token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    var thread = document.getElementById('thread');
    var empty = document.getElementById('empty');
    var filesEl = document.getElementById('files');
    var filesrow = document.getElementById('filesrow');
    var fileschip = document.getElementById('fileschip');
    var promptEl = document.getElementById('prompt');
    var aspectEl = document.getElementById('aspect');
    var balanceEl = document.getElementById('balance');
    var sendBtn = document.getElementById('send');
    var composer = document.getElementById('composer');

    var URLS = {
        upload: @js(route('studio.upload')), design: @js(route('studio.design')),
        pricing: @js(route('studio.pricing')), referral: @js(route('studio.referral')),
        checkout: @js(route('studio.checkout')), download: @js(route('studio.design.download')),
        preview: @js(route('studio.design.preview')), publish: @js(route('studio.publish')),
        saveDraft: @js(route('studio.savedraft')), rollback: @js(route('studio.rollback')),
        command: @js(route('studio.command')), shareLink: @js(route('studio.sharelink')),
        forget: @js(route('studio.forget')), login: @js(route('login')),
        resume: @js(route('studio').'?resume='.$session->guest_token),
        appShell: @js(route('studio.app.shell')),
        tplPreview: @js(route('studio.template.preview')),
        tplOptions: @js(route('studio.template.options')),
        tplExport: @js(route('studio.template.export')),
        tplFit: @js(route('studio.template.fit')),
        tplBg: @js(route('studio.template.bg'))
    };
    var TPL_EXPORT_OK = @js((bool) ($templateExportAvailable ?? true));
    var STR = @js([
        'you'          => ___('You'),
        'tplExportOff' => ___('PDF and image export is not available on this server. Use your browser\'s print function instead.'),
        'reading'      => ___('Reading your menu…'),
        'renderingPage'=> ___('Rendering page :a of :b…'),
        'uploadedFiles'=> ___('Sent :n page(s) for reading'),
        'genericErr'   => ___('Something went wrong.'),
        'couldNotRead' => ___('Could not read that file.'),
        'foundSummary' => ___('Found :items dishes in :cats categories.'),
        'missingPrices'=> ___(':n items have no price — tap a price field to fix it.'),
        'oddPrices'    => ___(':n prices look unusual — highlighted below.'),
        'generateBtn'  => ___('Generate designed menu'),
        'regenerate'   => ___('Regenerate'),
        'downloadPdf'  => ___('Download PDF'),
        'publishBtn'   => ___('Publish as ordering menu'),
        'shareBtn'     => ___('Share preview'),
        'generating'   => ___('Designing your menu — usually 10–30 seconds…'),
        'designDone'   => ___('Your designed menu is ready.'),
        'needCredits'  => ___('Not enough credits — top up to generate.'),
        'notCharged'   => ___('Your credits were not charged.'),
        'saved'        => ___('Saved'),
        'saving'       => ___('Saving…'),
        'undo'         => ___('Undo'),
        'undone'       => ___('Reverted to the previous version.'),
        'nothingUndo'  => ___('Nothing to undo yet.'),
        'pricesPct'    => ___('Adjust all prices'),
        'apply'        => ___('Apply'),
        'tagDietary'   => ___('Tag dietary'),
        'tagged'       => ___('Dietary tags added where detected.'),
        'pricesDone'   => ___('All prices adjusted by :n%.'),
        'copied'       => ___('Copied to clipboard.'),
        'copyFailed'   => ___('Could not copy — long-press to copy manually.'),
        'shareReady'   => ___('Public share link copied — safe to post.'),
        'referralOk'   => ___('Referral applied — bonus credits added.'),
        'confirmForget'=> ___('Delete this session and all uploads now? This cannot be undone.'),
        'deleted'      => ___('Deleted. Starting fresh.'),
        'confirmNew'   => ___('Start over? Your current menu stays saved in this session.'),
        'paidOk'       => ___('Payment received — credits are on the way.'),
        'paidCancel'   => ___('Checkout cancelled — nothing was charged.'),
        'checkoutOff'  => ___('Card checkout is not configured yet.'),
        'loginToPublish' => ___('Sign in with Google to publish — your menu is saved and will be right here.'),
        'publishTitle' => ___('Restaurant name for the published menu:'),
        'published'    => ___('Published! Opening your live menu…'),
        'styleHint'    => ___('Describing the look — Enter generates a design (:n credits).'),
        'extractHint'  => ___('Reading mode — paste menu text or attach files.'),
        'placeholderStyle' => ___('e.g. dark marble, gold serif, Italian trattoria…'),
        'placeholderExtract' => ___('Paste your menu, or describe the look…'),
        'nFiles'       => ___(':n file(s) attached'),
        'deleteItem'   => ___('Remove item'),
        'catName'      => ___('Category name'),
        'itemName'     => ___('Item name'),
        'price'        => ___('Price'),
        'restored'     => ___('Welcome back — your menu is restored.'),
        'sampleTitle'  => ___('Sample menu'),
        'tplNeedsMenu' => ___('Add your menu first — the template fills itself.'),
        'tplPreviewLabel' => ___('Template preview — your text stays editable.'),
        'tplPickLabel' => ___('Choose a template'),
        'tplPreviewBtn' => ___('Preview template'),
        'downloadPng'  => ___('Download PNG'),
        'tplPrintFallback' => ___('Export service unavailable — opening your browser print dialog instead.'),
        'restoreOriginal' => ___('Restore original'),
        'fitMenu'  => ___('Fit my menu'),
        'fitDone'  => ___('Fitted — :n descriptions trimmed for print. Originals kept.'),
        'aiBackground' => ___('AI background'),
        'bgDone'   => ___('Background art added.'),
        'bgReused' => ___('Using your existing background — regenerate to spend credits on a new one.'),
    ]);
    var TEMPLATES = @js($templatePackages ?? []);
    var SAMPLE_MENU = 'Starters\nHummus with warm pita 18\nHalloumi fries, hot honey 24\nLentil soup 15\n\nMains\nChicken shawarma plate 38\nLamb kofta, saffron rice 52\nGrilled sea bass, herb butter 64\nWild mushroom risotto 42\n\nDesserts\nKunafa, pistachio 26\nDark chocolate tart 22\n\nDrinks\nFresh mint lemonade 12\nKarak chai 8';
    var COSTS = @js($costs ?? config('studio.credit_cost'));
    var AUTHED = @js((bool) ($authed ?? false));
    var INITIAL_DRAFT = @js($draftJson);
    var HAS_DESIGN = @js($hasDesign);
    var nf = new Intl.NumberFormat(@js(get_lang()));
    var designCost = (COSTS && COSTS.pdf_design) ? COSTS.pdf_design : 5;

    var draft = null;          // client copy of the extracted menu
    var chosenPreset = null;
    var mode = 'extract';      // extract | style
    var busy = false;
    var saveTimer = null, saveSeq = 0;
    var shareUrl = null, shareCode = null;

    /* ---------- tiny helpers ---------- */
    function tpl(s, vars){ return String(s).replace(/:([a-z]+)/g, function(m, k){ return (vars && k in vars) ? vars[k] : m; }); }
    function el(tag, cls, text){ var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
    function icon(id, extra){ var s = '<svg class="ic'+(extra ? ' '+extra : '')+'"><use href="#'+id+'"/></svg>'; var d = document.createElement('div'); d.innerHTML = s; return d.firstChild; }
    function toast(msg, kind){ var t = el('div', 'toast'+(kind ? ' '+kind : ''), msg);
        document.getElementById('toasts').appendChild(t);
        setTimeout(function(){ t.style.opacity = '0'; t.style.transition = 'opacity .3s'; setTimeout(function(){ t.remove(); }, 320); }, 4200); }
    function post(url, fd){ return fetch(url, { method:'POST', headers:{ 'X-CSRF-TOKEN': token, 'Accept':'application/json' }, body: fd })
        .then(function(r){ return r.json().then(function(d){ return { s: r.status, d: d }; }).catch(function(){ return { s: r.status, d: {} }; }); }); }
    function copyText(text){
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text).then(function(){ toast(STR.copied, 'ok'); }, function(){ fallbackCopy(text); });
        }
        fallbackCopy(text); return Promise.resolve();
    }
    function fallbackCopy(text){
        var ta = document.createElement('textarea'); ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
        document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); toast(STR.copied, 'ok'); } catch (e) { toast(STR.copyFailed, 'err'); }
        ta.remove();
    }
    function loadPricing(){ return fetch(URLS.pricing, { headers:{ 'X-CSRF-TOKEN': token, 'Accept':'application/json' } })
        .then(function(r){ return r.json(); })
        .then(function(d){ if (d && typeof d.balance !== 'undefined') {
            balanceEl.textContent = nf.format(d.balance);
            var db = document.getElementById('d-balance'); if (db) db.textContent = nf.format(d.balance);
            if (d.costs && d.costs.pdf_design) designCost = d.costs.pdf_design;
            return d.balance;
        } }).catch(function(){}); }

    function addMsg(role, node){
        empty.hidden = true;
        var m = el('div', 'msg ' + role);
        var who = el('div', 'who', role === 'user' ? String(STR.you).slice(0, 2) : 'AI');
        var bubble = el('div', 'bubble');
        if (typeof node === 'string') bubble.appendChild(el('p', null, node)); else bubble.appendChild(node);
        m.appendChild(who); m.appendChild(bubble);
        thread.appendChild(m); thread.scrollTop = thread.scrollHeight;
        return bubble;
    }

    /* ---------- composer mode ---------- */
    var modebar = document.getElementById('modebar');
    var modehint = document.getElementById('modehint');
    function setMode(m){
        mode = m;
        document.getElementById('mode-extract').setAttribute('aria-pressed', m === 'extract' ? 'true' : 'false');
        document.getElementById('mode-style').setAttribute('aria-pressed', m === 'style' ? 'true' : 'false');
        promptEl.placeholder = m === 'style' ? STR.placeholderStyle : STR.placeholderExtract;
        modehint.textContent = m === 'style' ? tpl(STR.styleHint, { n: designCost }) : STR.extractHint;
    }
    document.getElementById('mode-extract').addEventListener('click', function(){ setMode('extract'); promptEl.focus(); });
    document.getElementById('mode-style').addEventListener('click', function(){ setMode('style'); promptEl.focus(); });

    // Auto-grow textarea.
    function autoGrow(){ promptEl.style.height = 'auto'; promptEl.style.height = Math.min(promptEl.scrollHeight, 160) + 'px'; }
    promptEl.addEventListener('input', autoGrow);

    /* ---------- extraction ---------- */
    function setBusy(b){ busy = b; sendBtn.disabled = b; sendBtn.classList.toggle('busy', b);
        sendBtn.innerHTML = ''; sendBtn.appendChild(icon(b ? 'i-loader' : 'i-send', b ? '' : 'ic-send')); }

    function submit(){
        if (busy) return;
        if (mode === 'style' && draft) { generateDesign(null); return; }
        var files = filesEl.files, txt = promptEl.value.trim();
        if ((!files || !files.length) && !txt) return;
        setBusy(true);
        var label = (files && files.length) ? tpl(STR.nFiles, { n: files.length }) : txt;
        addMsg('user', label.length > 400 ? label.slice(0, 400) + '…' : label);
        var bubble = addMsg('ai', STR.reading);
        var statusP = bubble.querySelector('p');
        bubble.parentElement.setAttribute('aria-live', 'polite');

        function done(){ setBusy(false); }
        function fail(msg){ statusP.textContent = msg || STR.genericErr; done(); }
        function extract(fd){
            post(URLS.upload, fd).then(function(res){
                if (res.d && res.d.success && res.d.data) { renderDraft(bubble, res.d.data, res.d.flags); loadPricing(); done();
                    if (chosenTemplate) showTemplatePreview(); } // template picked before the menu arrived
                else fail(res.d && res.d.error);
            }).catch(function(){ fail(); });
        }

        if (files && files.length) {
            if (!window.renderFilesToImages) { fail(STR.couldNotRead); return; }
            // Server accepts at most 10 page images per request — cap PDF rendering to match.
            window.renderFilesToImages(Array.prototype.slice.call(files), {
                maxPages: 10,
                onProgress: function(a, b){ statusP.textContent = tpl(STR.renderingPage, { a: a, b: b }); }
            }).then(function(imgs){
                statusP.textContent = STR.reading;
                var fd = new FormData();
                imgs.slice(0, 10).forEach(function(im, i){ fd.append('images[]', im, 'p' + i + '.png'); });
                clearFiles(); extract(fd);
            }).catch(function(){ fail(STR.couldNotRead); });
        } else {
            promptEl.value = ''; autoGrow();
            var fd = new FormData(); fd.append('text', txt); extract(fd);
        }
    }

    /* ---------- draft rendering + inline editing ---------- */
    function draftStats(d){
        var cats = (d.categories || []).length, items = 0;
        (d.categories || []).forEach(function(c){ items += (c.items || []).length; });
        return { cats: cats, items: items };
    }
    function renderDraft(bubble, data, flags){
        draft = data;
        bubble.innerHTML = '';
        var stats = draftStats(data);
        var sum = el('div', 'sum'); sum.appendChild(icon('i-check'));
        sum.appendChild(el('span', null, tpl(STR.foundSummary, { items: nf.format(stats.items), cats: nf.format(stats.cats) })));
        bubble.appendChild(sum);
        if (flags && flags.missing_price > 0) {
            var f1 = el('div', 'flagline'); f1.appendChild(icon('i-warn'));
            f1.appendChild(el('span', null, tpl(STR.missingPrices, { n: nf.format(flags.missing_price) })));
            bubble.appendChild(f1);
        }
        if (flags && flags.outliers && flags.outliers.length) {
            var f2 = el('div', 'flagline'); f2.appendChild(icon('i-warn'));
            f2.appendChild(el('span', null, tpl(STR.oddPrices, { n: nf.format(flags.outliers.length) })));
            bubble.appendChild(f2);
        }
        lastDraftBubble = bubble; // P3-3: fit re-renders the newest draft editor
        var wrap = el('div', 'draft');
        var outlierSet = {};
        ((flags && flags.outliers) || []).forEach(function(o){ outlierSet[o.cat + ':' + o.item] = true; });
        (data.categories || []).forEach(function(cat, ci){
            var h = el('div', 'cat-h');
            var cin = document.createElement('input');
            cin.value = cat.name || ''; cin.setAttribute('aria-label', STR.catName);
            cin.addEventListener('input', function(){ draft.categories[ci].name = cin.value; queueSave(bubble); });
            h.appendChild(cin); wrap.appendChild(h);
            (cat.items || []).forEach(function(item, ii){
                var row = el('div', 'item');
                var nm = document.createElement('input');
                nm.className = 'nm'; nm.value = item.name || ''; nm.setAttribute('aria-label', STR.itemName); nm.dir = 'auto';
                nm.dataset.path = ci + '.' + ii; // P5-3: click-to-edit target
                nm.addEventListener('input', function(){ draft.categories[ci].items[ii].name = nm.value; queueSave(bubble); });
                var pr = document.createElement('input');
                pr.className = 'pr' + (item.price == null ? ' miss' : '') + (outlierSet[ci + ':' + ii] ? ' outlier' : '');
                pr.value = item.price == null ? '' : item.price; pr.inputMode = 'decimal'; pr.setAttribute('aria-label', STR.price);
                pr.addEventListener('input', function(){
                    var v = pr.value.trim().replace(',', '.');
                    draft.categories[ci].items[ii].price = v === '' ? null : (isNaN(parseFloat(v)) ? null : parseFloat(v));
                    pr.classList.toggle('miss', v === ''); queueSave(bubble);
                });
                var rm = el('button', 'rm'); rm.type = 'button'; rm.setAttribute('aria-label', STR.deleteItem); rm.appendChild(icon('i-trash'));
                rm.addEventListener('click', function(){ draft.categories[ci].items.splice(ii, 1); queueSave(bubble); renderDraft(bubble, draft, null); });
                row.appendChild(nm); row.appendChild(pr); row.appendChild(rm);
                wrap.appendChild(row);
                // P3-2: a fit trim shows as a reversible display override.
                if (item.display_description) {
                    var ov = el('div', 'override');
                    ov.appendChild(el('span', 'ovtxt', item.display_description));
                    var rb = el('button', 'chip', STR.restoreOriginal); rb.type = 'button';
                    rb.addEventListener('click', function(){
                        delete draft.categories[ci].items[ii].display_description;
                        queueSave(bubble); renderDraft(bubble, draft, null); refreshTemplatePreview();
                    });
                    ov.appendChild(rb);
                    wrap.appendChild(ov);
                }
            });
        });
        bubble.appendChild(wrap);

        // Bulk-command toolbar.
        var bar = el('div', 'toolbar');
        var pctChip = el('button', 'chip'); pctChip.type = 'button'; pctChip.appendChild(icon('i-percent')); pctChip.appendChild(document.createTextNode(' ' + STR.pricesPct));
        var pctForm = el('span', 'pctform');
        var pctIn = document.createElement('input'); pctIn.type = 'number'; pctIn.step = 'any'; pctIn.placeholder = '+10%'; pctIn.setAttribute('aria-label', STR.pricesPct);
        var pctGo = el('button', 'chip', STR.apply); pctGo.type = 'button';
        pctForm.appendChild(pctIn); pctForm.appendChild(pctGo);
        pctChip.addEventListener('click', function(){ pctForm.classList.toggle('open'); pctIn.focus(); });
        pctGo.addEventListener('click', function(){
            var p = parseFloat(pctIn.value); if (isNaN(p)) return;
            var fd = new FormData(); fd.append('command', 'price_adjust'); fd.append('percent', p);
            post(URLS.command, fd).then(function(res){
                if (res.d.success) { toast(tpl(STR.pricesDone, { n: p }), 'ok'); renderDraft(bubble, res.d.data, null); }
                else toast(res.d.error || STR.genericErr, 'err');
            }).catch(function(){ toast(STR.genericErr, 'err'); });
        });
        var tagChip = el('button', 'chip'); tagChip.type = 'button'; tagChip.appendChild(icon('i-tag')); tagChip.appendChild(document.createTextNode(' ' + STR.tagDietary));
        tagChip.addEventListener('click', function(){
            var fd = new FormData(); fd.append('command', 'tag_dietary');
            post(URLS.command, fd).then(function(res){
                if (res.d.success) { toast(STR.tagged, 'ok'); draft = res.d.data; }
                else toast(res.d.error || STR.genericErr, 'err');
            }).catch(function(){ toast(STR.genericErr, 'err'); });
        });
        var undoChip = el('button', 'chip'); undoChip.type = 'button'; undoChip.appendChild(icon('i-undo')); undoChip.appendChild(document.createTextNode(' ' + STR.undo));
        undoChip.addEventListener('click', function(){
            post(URLS.rollback, new FormData()).then(function(res){
                if (res.d.success) { toast(STR.undone, 'ok'); renderDraft(bubble, res.d.data, null); }
                else toast(res.d.error || STR.nothingUndo, 'err');
            }).catch(function(){ toast(STR.genericErr, 'err'); });
        });
        var save = el('span', 'savestate'); save.id = '';
        bar.appendChild(pctChip); bar.appendChild(pctForm); bar.appendChild(tagChip); bar.appendChild(undoChip); bar.appendChild(save);
        bubble.appendChild(bar);
        bubble._saveState = save;

        // Primary actions.
        var act = el('div', 'act');
        var gen = el('button', 'btn primary'); gen.appendChild(icon('i-spark'));
        gen.appendChild(document.createTextNode(' ' + STR.generateBtn + ' '));
        var costSpan = el('span', 'cost', '· ' + nf.format(designCost) + ' ✦'); gen.appendChild(costSpan);
        gen.addEventListener('click', function(){ generateDesign(gen); });
        var shareB = el('button', 'btn'); shareB.appendChild(icon('i-share')); shareB.appendChild(document.createTextNode(' ' + STR.shareBtn));
        shareB.addEventListener('click', mintShare);
        var pub = el('button', 'btn'); pub.appendChild(icon('i-ext')); pub.appendChild(document.createTextNode(' ' + STR.publishBtn));
        pub.addEventListener('click', function(){ publish(pub); });
        act.appendChild(gen); act.appendChild(shareB); act.appendChild(pub);
        // studio-templates P1-7: the hero gallery hides once messages exist, so the
        // draft bubble carries its own template picker (free preview).
        if (TEMPLATES.length) {
            var tsel = document.createElement('select'); tsel.className = 'tplsel';
            tsel.setAttribute('aria-label', STR.tplPreviewLabel);
            var t0 = document.createElement('option'); t0.value = ''; t0.textContent = STR.tplPickLabel; tsel.appendChild(t0);
            TEMPLATES.forEach(function(t){ var o = document.createElement('option'); o.value = t.slug; o.textContent = t.name; tsel.appendChild(o); });
            if (chosenTemplate) tsel.value = chosenTemplate;
            var tbtn = el('button', 'btn'); tbtn.appendChild(icon('i-img')); tbtn.appendChild(document.createTextNode(' ' + STR.tplPreviewBtn));
            tbtn.addEventListener('click', function(){
                if (!tsel.value) { toast(STR.tplPickLabel); return; }
                pickTemplate(tsel.value);
            });
            act.appendChild(tsel); act.appendChild(tbtn);
        }
        bubble.appendChild(act);

        setMode('style'); modebar.hidden = false;
        thread.scrollTop = thread.scrollHeight;
    }

    function queueSave(bubble){
        if (bubble._saveState) bubble._saveState.textContent = STR.saving;
        clearTimeout(saveTimer);
        saveTimer = setTimeout(function(){
            var seq = ++saveSeq;
            var fd = new FormData();
            (draft.categories || []).forEach(function(cat, ci){
                fd.append('categories[' + ci + '][name]', cat.name || '');
                (cat.items || []).forEach(function(item, ii){
                    fd.append('categories[' + ci + '][items][' + ii + '][name]', item.name || '');
                    if (item.description) fd.append('categories[' + ci + '][items][' + ii + '][description]', item.description);
                    if (item.price != null) fd.append('categories[' + ci + '][items][' + ii + '][price]', item.price);
                    // P3-2: overrides + dietary tags survive the autosave round-trip.
                    if (item.display_description) fd.append('categories[' + ci + '][items][' + ii + '][display_description]', item.display_description);
                    (item.tags || []).forEach(function(t, ti){ fd.append('categories[' + ci + '][items][' + ii + '][tags][' + ti + ']', t); });
                });
            });
            post(URLS.saveDraft, fd).then(function(res){
                if (seq !== saveSeq) return;
                if (bubble._saveState) bubble._saveState.textContent = res.d.success ? STR.saved : STR.genericErr;
                if (res.d.success) refreshTemplatePreview(); // live template re-render on edit
            }).catch(function(){ if (bubble._saveState) bubble._saveState.textContent = STR.genericErr; });
        }, 900);
    }

    /* ---------- design generation ---------- */
    var genBusy = false;
    function generateDesign(btn){
        if (genBusy) return;
        genBusy = true; if (btn) btn.disabled = true;
        var look = promptEl.value.trim();
        if (mode === 'style' && look) { promptEl.value = ''; autoGrow(); }
        var bubble = addMsg('ai', STR.generating);
        bubble.parentElement.setAttribute('aria-live', 'polite');
        var skel = el('div', 'skel'); skel.style.width = 'min(360px,100%)'; skel.style.aspectRatio = aspectEl.value === 'story' ? '9/16' : (aspectEl.value === 'square' ? '1/1' : '3/4');
        skel.style.maxHeight = '300px';
        bubble.appendChild(skel);

        var fd = new FormData();
        if (look) fd.append('described_look', look);
        if (chosenPreset) fd.append('preset', chosenPreset);
        fd.append('aspect', aspectEl.value);
        post(URLS.design, fd).then(function(res){
            genBusy = false; if (btn) btn.disabled = false;
            skel.remove();
            var p = bubble.querySelector('p');
            if (res.d.success) {
                p.textContent = STR.designDone;
                showDesign(bubble);
                loadPricing();
            } else if (res.s === 402) {
                p.textContent = STR.needCredits; openPricing();
            } else {
                p.textContent = (res.d.error || STR.genericErr);
            }
        }).catch(function(){ genBusy = false; if (btn) btn.disabled = false; skel.remove(); bubble.querySelector('p').textContent = STR.genericErr; });
    }
    function showDesign(bubble){
        var img = document.createElement('img');
        img.className = 'design-img'; img.alt = STR.designDone;
        img.src = URLS.preview + '?t=' + Date.now();
        bubble.appendChild(img);
        var act = el('div', 'act');
        var dl = document.createElement('a'); dl.className = 'btn primary'; dl.href = URLS.download;
        dl.appendChild(icon('i-down')); dl.appendChild(document.createTextNode(' ' + STR.downloadPdf));
        var re = el('button', 'btn'); re.appendChild(icon('i-redo'));
        re.appendChild(document.createTextNode(' ' + STR.regenerate + ' '));
        re.appendChild(el('span', 'cost', '· ' + nf.format(designCost) + ' ✦'));
        re.addEventListener('click', function(){ generateDesign(re); });
        var shareB = el('button', 'btn'); shareB.appendChild(icon('i-share')); shareB.appendChild(document.createTextNode(' ' + STR.shareBtn));
        shareB.addEventListener('click', mintShare);
        act.appendChild(dl); act.appendChild(re); act.appendChild(shareB);
        bubble.appendChild(act);
        thread.scrollTop = thread.scrollHeight;
    }

    /* ---------- publish ---------- */
    function publish(btn){
        if (!AUTHED) {
            toast(STR.loginToPublish);
            setTimeout(function(){ window.location = URLS.login; }, 1400);
            return;
        }
        var title = window.prompt(STR.publishTitle, '');
        if (title === null) return;
        btn.disabled = true;
        var fd = new FormData(); if (title.trim()) fd.append('title', title.trim());
        post(URLS.publish, fd).then(function(res){
            btn.disabled = false;
            if (res.d.success && res.d.url) { toast(STR.published, 'ok'); window.open(res.d.url, '_blank'); }
            else toast(res.d.error || STR.genericErr, 'err');
        }).catch(function(){ btn.disabled = false; toast(STR.genericErr, 'err'); });
    }

    /* ---------- share + referral ---------- */
    function mintShare(){
        post(URLS.shareLink, new FormData()).then(function(res){
            if (res.d.success && res.d.url) {
                shareUrl = res.d.url; shareCode = res.d.code;
                var open = document.getElementById('open-share'); open.href = shareUrl; open.hidden = false;
                copyText(shareUrl).then(function(){ toast(STR.shareReady, 'ok'); });
            } else toast(res.d.error || STR.genericErr, 'err');
        }).catch(function(){ toast(STR.genericErr, 'err'); });
    }
    document.getElementById('mint-share').addEventListener('click', mintShare);
    document.getElementById('copy-referral').addEventListener('click', function(){
        if (shareCode) { copyText(shareCode); return; }
        post(URLS.shareLink, new FormData()).then(function(res){
            if (res.d.success) { shareCode = res.d.code; shareUrl = res.d.url; copyText(shareCode); }
            else toast(res.d.error || STR.genericErr, 'err');
        }).catch(function(){ toast(STR.genericErr, 'err'); });
    });
    document.getElementById('apply-referral').addEventListener('click', function(){
        var code = document.getElementById('referral-input').value.trim();
        if (!code) return;
        var fd = new FormData(); fd.append('code', code);
        post(URLS.referral, fd).then(function(res){
            if (res.d.success) { toast(STR.referralOk, 'ok'); loadPricing(); document.getElementById('referral-input').value = ''; }
            else toast(res.d.error || STR.genericErr, 'err');
        }).catch(function(){ toast(STR.genericErr, 'err'); });
    });
    document.getElementById('copy-resume').addEventListener('click', function(){ copyText(URLS.resume); });
    document.getElementById('forget-btn').addEventListener('click', function(){
        if (!window.confirm(STR.confirmForget)) return;
        post(URLS.forget, new FormData()).then(function(){ toast(STR.deleted, 'ok'); setTimeout(function(){ window.location = URLS.appShell; }, 900); })
            .catch(function(){ toast(STR.genericErr, 'err'); });
    });

    /* ---------- composer events ---------- */
    sendBtn.addEventListener('click', submit);
    promptEl.addEventListener('keydown', function(e){
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && e.keyCode !== 229) { e.preventDefault(); submit(); }
    });
    document.getElementById('attach').addEventListener('click', function(){ filesEl.click(); });
    function syncFilesChip(){
        var n = filesEl.files ? filesEl.files.length : 0;
        filesrow.classList.toggle('on', n > 0);
        fileschip.textContent = n ? tpl(STR.nFiles, { n: n }) : '';
        if (n) setMode('extract');
    }
    function clearFiles(){ filesEl.value = ''; syncFilesChip(); }
    filesEl.addEventListener('change', syncFilesChip);
    document.getElementById('files-clear').addEventListener('click', clearFiles);

    // Drag & drop + paste images.
    ;['dragover', 'dragenter'].forEach(function(ev){ composer.addEventListener(ev, function(e){ e.preventDefault(); composer.classList.add('drag'); }); });
    ;['dragleave', 'drop'].forEach(function(ev){ composer.addEventListener(ev, function(e){ e.preventDefault(); composer.classList.remove('drag'); }); });
    composer.addEventListener('drop', function(e){
        if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
            filesEl.files = e.dataTransfer.files; syncFilesChip();
        }
    });
    promptEl.addEventListener('paste', function(e){
        var items = (e.clipboardData || {}).items || [];
        for (var i = 0; i < items.length; i++) {
            if (items[i].kind === 'file' && items[i].type.indexOf('image/') === 0) {
                var f = items[i].getAsFile();
                var dt = new DataTransfer(); dt.items.add(f);
                filesEl.files = dt.files; syncFilesChip(); e.preventDefault(); return;
            }
        }
    });

    /* ---------- tasks + templates ---------- */
    document.querySelectorAll('[data-task]').forEach(function(t){ t.addEventListener('click', function(){ setMode('extract'); filesEl.click(); }); });
    document.querySelectorAll('.studio-preset').forEach(function(p){ p.addEventListener('click', function(){
        var was = p.getAttribute('aria-pressed') === 'true';
        chosenPreset = was ? null : p.getAttribute('data-preset');
        document.querySelectorAll('.studio-preset').forEach(function(x){ x.setAttribute('aria-pressed', (!was && x === p) ? 'true' : 'false'); });
        promptEl.focus();
    }); });
    document.getElementById('try-sample').addEventListener('click', function(){
        setMode('extract'); promptEl.value = SAMPLE_MENU; autoGrow(); submit();
    });

    /* studio-templates P1-7: pick an HTML template -> persist choice -> live
       preview iframe (free, no credits — the text stays data). */
    var chosenTemplate = null;
    var tplAspect = 'a4';
    var tplColors = {};
    var activeFrame = null;       // the live preview iframe (one at a time)
    var tplOptTimer = null;
    var lastDraftBubble = null;   // newest draft editor bubble (fit re-renders it)
    function tplMeta(slug){ for (var i = 0; i < TEMPLATES.length; i++) if (TEMPLATES[i].slug === slug) return TEMPLATES[i]; return null; }
    function refreshTemplatePreview(){
        if (!activeFrame || !chosenTemplate) return;
        activeFrame.className = 'tplframe' + ((tplAspect === 'square' || tplAspect === 'story') ? ' wide' : '');
        activeFrame.src = URLS.tplPreview + '?slug=' + encodeURIComponent(chosenTemplate) + '&t=' + Date.now();
    }
    function saveTemplateOptions(then){
        if (!chosenTemplate) return;
        var fd = new FormData();
        fd.append('slug', chosenTemplate);
        fd.append('aspect', tplAspect);
        Object.keys(tplColors).forEach(function(k){ fd.append('colors[' + k + ']', tplColors[k]); });
        post(URLS.tplOptions, fd).then(function(res){
            if (!(res.d && res.d.success)) { toast(STR.genericErr, 'err'); return; }
            if (then) then();
        });
    }
    function queueTplOptions(){ clearTimeout(tplOptTimer); tplOptTimer = setTimeout(function(){ saveTemplateOptions(refreshTemplatePreview); }, 350); }
    function pickTemplate(slug){
        chosenTemplate = slug;
        var meta = tplMeta(slug);
        tplAspect = meta && meta.aspects.indexOf(tplAspect) >= 0 ? tplAspect : (meta ? meta.aspects[0] : 'a4');
        tplColors = meta ? Object.assign({}, meta.colors) : {};
        document.querySelectorAll('.studio-template').forEach(function(x){ x.setAttribute('aria-pressed', x.getAttribute('data-template') === slug ? 'true' : 'false'); });
        saveTemplateOptions(function(){
            if (draft && draft.categories && draft.categories.length) { showTemplatePreview(); }
            else { toast(STR.tplNeedsMenu, 'ok'); setMode('extract'); promptEl.focus(); }
        });
    }
    function showTemplatePreview(){
        if (!chosenTemplate) return;
        if (activeFrame && document.body.contains(activeFrame)) { refreshTemplatePreview(); return; }
        var wrap = el('div', 'tplprev');
        wrap.appendChild(el('p', null, STR.tplPreviewLabel));

        // Toolbar: template switch, aspect, manifest color slots.
        var bar = el('div', 'tplbar');
        var tsel = document.createElement('select'); tsel.className = 'tplsel';
        TEMPLATES.forEach(function(t){ var o = document.createElement('option'); o.value = t.slug; o.textContent = t.name; tsel.appendChild(o); });
        tsel.value = chosenTemplate;
        tsel.addEventListener('change', function(){
            chosenTemplate = tsel.value;
            var meta = tplMeta(chosenTemplate);
            tplAspect = meta && meta.aspects.indexOf(tplAspect) >= 0 ? tplAspect : (meta ? meta.aspects[0] : 'a4');
            tplColors = meta ? Object.assign({}, meta.colors) : {};
            rebuildAspects(); rebuildColors(); queueTplOptions();
        });
        var asel = document.createElement('select'); asel.className = 'tplsel';
        function rebuildAspects(){
            asel.innerHTML = '';
            var meta = tplMeta(chosenTemplate);
            (meta ? meta.aspects : ['a4']).forEach(function(a){ var o = document.createElement('option'); o.value = a; o.textContent = a.toUpperCase(); asel.appendChild(o); });
            asel.value = tplAspect;
        }
        asel.addEventListener('change', function(){ tplAspect = asel.value; queueTplOptions(); });
        var colors = el('span', 'tplcolors');
        function rebuildColors(){
            colors.innerHTML = '';
            Object.keys(tplColors).forEach(function(k){
                var c = document.createElement('input'); c.type = 'color'; c.value = tplColors[k];
                c.setAttribute('aria-label', k); c.title = k;
                c.addEventListener('input', function(){ tplColors[k] = c.value; queueTplOptions(); });
                colors.appendChild(c);
            });
        }
        // P2-5: paid clean export (PDF for print aspects, PNG for social ones).
        var ebtn = el('button', 'btn primary');
        var elabel = el('span', null, '');
        ebtn.appendChild(icon('i-file')); ebtn.appendChild(elabel);
        var ecost = el('span', 'cost', ' · ' + nf.format(designCost) + ' ✦'); ebtn.appendChild(ecost);
        function updateExportLabel(){ elabel.textContent = ' ' + ((tplAspect === 'a4' || tplAspect === 'letter') ? STR.downloadPdf : STR.downloadPng); }
        updateExportLabel();
        ebtn.addEventListener('click', function(){ exportTemplate(ebtn); });
        if (!TPL_EXPORT_OK) { // P6-119d: no Node/Chrome on this host: disable with a plain message (browser print stays)
            ebtn.disabled = true; ebtn.title = STR.tplExportOff;
            ebtn.appendChild(el('span', 'cost', ' ' + STR.tplExportOff));
        }
        asel.addEventListener('change', updateExportLabel);
        tsel.addEventListener('change', updateExportLabel);

        // P3-3: free AI fitting pass (trims/featured/accent).
        var fbtn = el('button', 'btn');
        fbtn.appendChild(icon('i-spark')); fbtn.appendChild(document.createTextNode(' ' + STR.fitMenu));
        fbtn.addEventListener('click', function(){
            fbtn.disabled = true;
            post(URLS.tplFit, new FormData()).then(function(res){
                fbtn.disabled = false;
                if (!(res.d && res.d.success)) { toast((res.d && res.d.error) || STR.genericErr, 'err'); return; }
                draft = res.d.data;
                if (res.d.plan && res.d.plan.accent && !('accent-touched' in tplColors) && tplColors.accent) {
                    tplColors.accent = res.d.plan.accent; rebuildColors();
                }
                if (lastDraftBubble) renderDraft(lastDraftBubble, draft, null);
                refreshTemplatePreview();
                toast(tpl(STR.fitDone, { n: nf.format((res.d.plan && res.d.plan.trims) || 0) }), 'ok');
            }).catch(function(){ fbtn.disabled = false; toast(STR.genericErr, 'err'); });
        });

        // P4-2: AI background art (credit-gated; cached reuse is free).
        var bbtn = el('button', 'btn');
        bbtn.appendChild(icon('i-img')); bbtn.appendChild(document.createTextNode(' ' + STR.aiBackground + ' '));
        bbtn.appendChild(el('span', 'cost', '· ' + nf.format(designCost) + ' ✦'));
        bbtn.addEventListener('click', function(){
            bbtn.disabled = true;
            var fd = new FormData();
            if (activeFrame && activeFrame.dataset.hasBg === '1') fd.append('force', '1'); // regenerate
            post(URLS.tplBg, fd).then(function(res){
                bbtn.disabled = false;
                if (res.s === 402) { toast(STR.needCredits, 'err'); var tb = document.getElementById('topup-btn'); if (tb) tb.click(); return; }
                if (!(res.d && res.d.success)) { toast((res.d && res.d.error) || STR.genericErr, 'err'); return; }
                if (activeFrame) activeFrame.dataset.hasBg = '1';
                refreshTemplatePreview(); loadPricing();
                toast(res.d.cached ? STR.bgReused : STR.bgDone, 'ok');
            }).catch(function(){ bbtn.disabled = false; toast(STR.genericErr, 'err'); });
        });

        rebuildAspects(); rebuildColors();
        bar.appendChild(tsel); bar.appendChild(asel); bar.appendChild(colors); bar.appendChild(fbtn); bar.appendChild(bbtn); bar.appendChild(ebtn);
        wrap.appendChild(bar);

        var frame = document.createElement('iframe');
        frame.className = 'tplframe' + ((tplAspect === 'square' || tplAspect === 'story') ? ' wide' : '');
        // No scripts inside — templates are static pages; same-origin so the
        // guest cookie scopes which draft renders.
        frame.setAttribute('sandbox', 'allow-same-origin allow-modals'); // modals: window.print fallback
        // P5-3: click-to-edit — the iframe stays script-free; the PARENT reads the
        // same-origin document and maps [data-path] clicks to draft editor fields.
        frame.addEventListener('load', function(){
            try {
                frame.contentDocument.addEventListener('click', function(e){
                    var t = e.target.closest ? e.target.closest('[data-path]') : null;
                    if (!t || !lastDraftBubble) return;
                    var input = lastDraftBubble.querySelector('input[data-path="' + t.getAttribute('data-path') + '"]');
                    if (input) { input.scrollIntoView({ block: 'center', behavior: 'smooth' }); input.focus(); }
                });
            } catch (err) { /* cross-origin or detached — bridge is best-effort */ }
        });
        frame.setAttribute('title', STR.tplPreviewLabel);
        frame.src = URLS.tplPreview + '?slug=' + encodeURIComponent(chosenTemplate) + '&t=' + Date.now();
        wrap.appendChild(frame);
        activeFrame = frame;
        addMsg('ai', wrap);
    }
    function exportTemplate(btn){
        if (!chosenTemplate) return;
        btn.disabled = true;
        var fd = new FormData(); // slug/aspect/colors come from the stored options
        fetch(URLS.tplExport, { method: 'POST', headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/pdf,image/png,application/json' }, body: fd })
            .then(function(r){
                if (r.status === 402) { toast(STR.needCredits, 'err'); var tb = document.getElementById('topup-btn'); if (tb) tb.click(); return; }
                if (r.status === 501) { // no export toolchain: browser print fallback
                    toast(STR.tplPrintFallback);
                    if (activeFrame && activeFrame.contentWindow) activeFrame.contentWindow.print();
                    return;
                }
                if (!r.ok) { return r.json().then(function(d){ toast((d && d.error) || STR.genericErr, 'err'); }, function(){ toast(STR.genericErr, 'err'); }); }
                var isPdf = (r.headers.get('Content-Type') || '').indexOf('pdf') >= 0;
                return r.blob().then(function(b){
                    var a = document.createElement('a');
                    a.href = URL.createObjectURL(b);
                    a.download = 'menu-' + chosenTemplate + '.' + (isPdf ? 'pdf' : 'png');
                    document.body.appendChild(a); a.click(); a.remove();
                    setTimeout(function(){ URL.revokeObjectURL(a.href); }, 4000);
                    loadPricing();
                });
            })
            .catch(function(){ toast(STR.genericErr, 'err'); })
            .then(function(){ btn.disabled = false; });
    }
    document.querySelectorAll('.studio-template').forEach(function(p){ p.addEventListener('click', function(){
        var was = p.getAttribute('aria-pressed') === 'true';
        if (was) { chosenTemplate = null; p.setAttribute('aria-pressed', 'false'); return; }
        pickTemplate(p.getAttribute('data-template'));
    }); });

    /* ---------- drawer ---------- */
    var drawer = document.getElementById('drawer');
    var backdrop = document.getElementById('drawer-backdrop');
    var drawerReturn = null;
    function openDrawer(from){
        drawerReturn = from || document.activeElement;
        backdrop.hidden = false; requestAnimationFrame(function(){ backdrop.classList.add('on'); drawer.classList.add('on'); });
        document.getElementById('rail-history').setAttribute('aria-expanded', 'true');
        document.getElementById('drawer-x').focus();
    }
    function closeDrawer(){
        backdrop.classList.remove('on'); drawer.classList.remove('on');
        document.getElementById('rail-history').setAttribute('aria-expanded', 'false');
        setTimeout(function(){ backdrop.hidden = true; }, 260);
        if (drawerReturn && drawerReturn.focus) drawerReturn.focus();
    }
    document.getElementById('rail-history').addEventListener('click', function(){ openDrawer(this); });
    document.getElementById('mob-history').addEventListener('click', function(){ openDrawer(this); });
    document.getElementById('drawer-x').addEventListener('click', closeDrawer);
    backdrop.addEventListener('click', closeDrawer);

    /* ---------- start over ---------- */
    function startOver(){
        if (draft && !window.confirm(STR.confirmNew)) return;
        window.location = URLS.appShell;
    }
    document.getElementById('rail-new').addEventListener('click', startOver);
    document.getElementById('mob-new').addEventListener('click', startOver);

    /* ---------- pricing modal ---------- */
    var modal = document.getElementById('pricing');
    var modalReturn = null;
    var selectedBundle = @js(collect($bundles)->firstWhere('recommended', true)['key'] ?? ($bundles[0]['key'] ?? ''));
    function openPricing(){
        modalReturn = document.activeElement;
        modal.hidden = false; document.body.style.overflow = 'hidden';
        document.getElementById('pricing-x').focus();
    }
    function closePricing(){
        modal.hidden = true; document.body.style.overflow = '';
        if (modalReturn && modalReturn.focus) modalReturn.focus();
    }
    document.getElementById('topup-btn').addEventListener('click', openPricing);
    document.getElementById('pricing-x').addEventListener('click', closePricing);
    modal.addEventListener('click', function(e){ if (e.target === modal) closePricing(); });
    document.addEventListener('keydown', function(e){
        if (e.key !== 'Escape') {
            // Focus trap for the modal.
            if (e.key === 'Tab' && !modal.hidden) {
                var f = modal.querySelectorAll('button, [href], input, select, textarea, label.tier');
                var list = Array.prototype.filter.call(f, function(x){ return x.offsetParent !== null; });
                if (!list.length) return;
                var first = list[0], last = list[list.length - 1];
                if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
                else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
            }
            return;
        }
        if (!modal.hidden) { closePricing(); return; }
        if (drawer.classList.contains('on')) closeDrawer();
    });
    document.querySelectorAll('.tier').forEach(function(t){
        t.addEventListener('click', function(){
            document.querySelectorAll('.tier').forEach(function(x){ x.classList.remove('on'); });
            t.classList.add('on');
            var radio = t.querySelector('input'); if (radio) radio.checked = true;
            selectedBundle = t.getAttribute('data-key');
            document.getElementById('cta-credits').textContent = nf.format(t.getAttribute('data-credits'));
        });
    });
    document.getElementById('topup-cta').addEventListener('click', function(){
        var btn = this; btn.disabled = true;
        var fd = new FormData(); fd.append('bundle', selectedBundle);
        post(URLS.checkout, fd).then(function(res){
            if (res.d.success && res.d.url) { window.location = res.d.url; return; } // -> Stripe Checkout (stay disabled)
            btn.disabled = false;
            if (res.s === 503) toast(STR.checkoutOff, 'err');
            else toast(res.d.error || STR.genericErr, 'err');
        }).catch(function(){ btn.disabled = false; toast(STR.genericErr, 'err'); });
    });

    /* ---------- boot: rehydrate + payment return ---------- */
    loadPricing();
    if (INITIAL_DRAFT && INITIAL_DRAFT.categories && INITIAL_DRAFT.categories.length) {
        var b = addMsg('ai', '');
        renderDraft(b, INITIAL_DRAFT, null);
        if (HAS_DESIGN) { var db = addMsg('ai', STR.designDone); showDesign(db); }
        toast(STR.restored);
    }
    if (/[?&]paid=1/.test(location.search)) {
        toast(STR.paidOk, 'ok');
        var before = null, tries = 0;
        loadPricing().then(function(v){ before = v; });
        var poll = setInterval(function(){
            tries++;
            loadPricing().then(function(v){ if ((before != null && v > before) || tries >= 8) clearInterval(poll); });
        }, 1500);
        history.replaceState(null, '', location.pathname);
    } else if (/[?&]cancelled=1/.test(location.search)) {
        toast(STR.paidCancel);
        history.replaceState(null, '', location.pathname);
    }
    setMode(draft ? 'style' : 'extract');
})();
</script>
</body>
</html>
