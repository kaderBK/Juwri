{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- lumiere — soft AI-skincare beauty storefront styling.

     Design tokens per handoff/03-cosmetic-lumiere.md: Poppins, purple/lavender
     palette, generous radii, soft shadows. Logical properties (inset-inline-*,
     margin-inline-*) throughout for RTL; light + dark tokens with a
     localStorage-backed manual override, same pattern as shelf's
     `data-theme` attribute. --}}
<style nonce="{{ csp_nonce() }}">
:root{
    --lm-ground:#F6F1FB; --lm-canvas:#EDE6F5; --lm-card:#FFFFFF; --lm-card-2:#F6F6F6;
    --lm-ink:#2F2F30; --lm-muted:#6E6A75; --lm-ink-3:#A49FAD; --lm-hair:#EFEAF5; --lm-hair-2:#E3DBEC;
    --lm-accent:#A377D2; --lm-accent-2:#8A5FB8; --lm-accent-bg:#F1E9FB; --lm-sale:#E26D8A; --lm-star:#E0A43C;
    --lm-ok:#2f9e63;
    --lm-radius:16px; --lm-radius-lg:26px;
    --lm-shadow:0 22px 60px -26px rgba(90,60,140,.35);
    --lm-shadow-card:0 12px 30px -18px rgba(90,60,140,.22);
    --lm-font:"Saudi Riyal","Poppins",system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
    --lm-nav-h:70px;
}
@media (prefers-color-scheme:dark){
    :root:not([data-theme="light"]){
        --lm-ground:#171320; --lm-canvas:#0F0C16; --lm-card:#211C2B; --lm-card-2:#292233;
        --lm-ink:#F3EFFA; --lm-muted:#B4ACC2; --lm-ink-3:#7C7490; --lm-hair:#2C2637; --lm-hair-2:#372F45;
        --lm-accent:#BC93E6; --lm-accent-2:#9A6FCC; --lm-accent-bg:rgba(188,147,230,.16); --lm-sale:#E68AA3; --lm-star:#E0A43C;
        --lm-shadow:0 26px 64px -22px rgba(0,0,0,.7); --lm-shadow-card:0 14px 32px -18px rgba(0,0,0,.55);
    }
}
:root[data-theme="dark"]{
    --lm-ground:#171320; --lm-canvas:#0F0C16; --lm-card:#211C2B; --lm-card-2:#292233;
    --lm-ink:#F3EFFA; --lm-muted:#B4ACC2; --lm-ink-3:#7C7490; --lm-hair:#2C2637; --lm-hair-2:#372F45;
    --lm-accent:#BC93E6; --lm-accent-2:#9A6FCC; --lm-accent-bg:rgba(188,147,230,.16); --lm-sale:#E68AA3; --lm-star:#E0A43C;
    --lm-shadow:0 26px 64px -22px rgba(0,0,0,.7); --lm-shadow-card:0 14px 32px -18px rgba(0,0,0,.55);
}
.lm-app *{box-sizing:border-box}
.lumiere-body{margin:0;background:var(--lm-canvas)}
.lm-skip{position:absolute;inset-inline-start:-999px;top:0;background:#000;color:#fff;padding:8px 16px;z-index:99}
.lm-skip:focus{inset-inline-start:0}
.lm-app{max-width:560px;margin:0 auto;min-height:100vh;overflow-x:clip;background:var(--lm-ground);color:var(--lm-ink);
    font-family:var(--lm-font);-webkit-font-smoothing:antialiased;position:relative;isolation:isolate;
    box-shadow:0 0 80px rgba(90,60,140,.08)}
/* :where() = 0 specificity, so any themed .lm-* button/link class wins
   its own background/border/color instead of being stripped by this reset */
:where(.lm-app) button{font-family:inherit;cursor:pointer;border:none;background:none;color:inherit}
:where(.lm-app) a{color:inherit;text-decoration:none}
.lm-app img{display:block;max-width:100%}
.lm-app :focus-visible{outline:2px solid var(--lm-accent);outline-offset:2px}
.lm-app [hidden]{display:none !important}
.lm-sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}

.lm-disclaimer{margin:0 20px;padding:10px 14px;border-radius:12px;font-size:.78rem;line-height:1.4;
    color:var(--lm-muted);background:var(--lm-card-2);border:1px solid var(--lm-hair)}

/* ---------- screens ---------- */
.lm-screen{padding:18px 20px 24px}
.lm-screen.has-tabbar{padding-bottom:calc(var(--lm-nav-h) + 20px)}
.lm-screen.has-actionbar{padding-bottom:112px}
@keyframes lmFade{from{opacity:0}to{opacity:1}}
.lm-screen{animation:lmFade .22s ease}
@media (prefers-reduced-motion:reduce){.lm-app *{animation-duration:.001s !important;transition-duration:.001s !important}}

/* ---------- header ---------- */
.lm-hdr{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:6px}
.lm-hdr .lm-title{font-size:17px;font-weight:600;letter-spacing:-.01em}
.lm-iconbtn{width:42px;height:42px;border-radius:50%;background:var(--lm-card);display:flex;align-items:center;
    justify-content:center;color:var(--lm-ink);flex:none;transition:transform .15s,background .15s;border:1px solid var(--lm-hair);position:relative}
.lm-iconbtn:hover{background:var(--lm-card-2)}
.lm-iconbtn:active{transform:scale(.92)}
.lm-iconbtn svg{width:19px;height:19px}
.lm-avatar{width:44px;height:44px;border-radius:50%;object-fit:cover;background:var(--lm-card-2);border:1px solid var(--lm-hair);flex:none}
.lm-avatar-fallback{width:44px;height:44px;border-radius:50%;background:var(--lm-accent-bg);color:var(--lm-accent-2);
    display:flex;align-items:center;justify-content:center;font-weight:700;flex:none}
.lm-badge{position:absolute;top:-4px;inset-inline-end:-4px;min-width:18px;height:18px;padding:0 4px;border-radius:9px;
    background:var(--lm-sale);color:#fff;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center}
.lm-badge[hidden]{display:none}

.lm-eyebrow{font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:var(--lm-muted);font-weight:600}
.lm-display{font-family:var(--lm-font);font-weight:600;line-height:1.14;letter-spacing:-.015em}
.lm-display .lm-muted{color:var(--lm-muted);font-weight:500}

/* ---------- routine card + ring ---------- */
.lm-routinecard{display:flex;align-items:center;gap:16px;background:var(--lm-card);border:1px solid var(--lm-hair);
    border-radius:var(--lm-radius-lg);padding:18px;margin-top:18px;box-shadow:var(--lm-shadow-card)}
.lm-ringbox{position:relative;width:74px;height:74px;flex:none}
.lm-ringbox svg{transform:rotate(-90deg)}
.lm-ringbox .lm-pct{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:16px}
.lm-routinebody{flex:1;min-width:0}
.lm-routinebody .lm-rt{font-weight:600;font-size:15.5px}
.lm-routinebody .lm-rs{font-size:12.5px;color:var(--lm-muted);margin-top:2px}
.lm-rbtn{margin-top:10px;height:34px;padding:0 16px;border-radius:100px;background:var(--lm-accent-bg);
    color:var(--lm-accent-2);font-size:12.5px;font-weight:600;display:inline-flex;align-items:center;gap:6px}

/* ---------- section rows / product grid ---------- */
.lm-sectrow{display:flex;align-items:baseline;justify-content:space-between;margin-top:22px;margin-bottom:12px}
.lm-sectrow .lm-label{font-size:15.5px;font-weight:600;margin:0}
.lm-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media (min-width:460px){.lm-grid{grid-template-columns:1fr 1fr 1fr}}
.lm-pcard{position:relative;background:var(--lm-card);border-radius:20px;padding:10px 10px 12px;box-shadow:var(--lm-shadow-card);
    transition:transform .18s;text-align:start}
.lm-pcard:active{transform:scale(.98)}
.lm-ptilewrap{position:relative;border-radius:14px;overflow:hidden;aspect-ratio:1/1;background:#fff}
.lm-ptilewrap img{width:100%;height:100%;object-fit:cover}
.lm-ptilewrap-ph{width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:var(--lm-ink-3);background:var(--lm-card-2)}
.lm-media-link{display:block;width:100%;height:100%}
.lm-corner{position:absolute;width:30px;height:30px;border-radius:50%;background:rgba(255,255,255,.86);
    backdrop-filter:blur(6px);display:flex;align-items:center;justify-content:center;color:#2F2F30;
    box-shadow:0 4px 10px -4px rgba(0,0,0,.28);transition:transform .15s}
.lm-corner:active{transform:scale(.86)}
.lm-corner svg{width:15px;height:15px}
.lm-heart{top:8px;inset-inline-start:8px}
.lm-heart[aria-pressed="true"]{background:var(--lm-sale);color:#fff}
.lm-heart[aria-pressed="true"] svg{fill:#fff}
.lm-heart.lm-pop{animation:lmHeartPop .4s}
@keyframes lmHeartPop{0%{transform:scale(1)}35%{transform:scale(1.3)}100%{transform:scale(1)}}
.lm-openarrow{bottom:8px;inset-inline-end:8px}
.lm-pinfo{padding:10px 4px 0;display:block}
.lm-pbrand{font-size:11px;color:var(--lm-muted);text-transform:uppercase;letter-spacing:.05em;font-weight:600}
.lm-pname{font-size:14.5px;font-weight:600;margin-top:2px;line-height:1.25;display:-webkit-box;
    -webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.lm-prow{display:flex;align-items:center;gap:8px;margin-top:6px;flex-wrap:wrap}
.lm-price{font-weight:700;font-size:15px;font-variant-numeric:tabular-nums}
.lm-stock{font-size:11px;font-weight:600;margin-top:4px;display:block}
.lm-stock--low{color:#b45309}
.lm-stock--in{color:var(--lm-ok)}
.lm-stock--out{color:var(--lm-sale)}
.lm-soldout-tag{position:absolute;top:8px;inset-inline-start:8px;background:var(--lm-sale);color:#fff;
    font-size:10px;font-weight:700;padding:3px 9px;border-radius:100px;z-index:1}
.lm-empty{padding:60px 10px;text-align:center;color:var(--lm-muted)}

.lm-wrow{display:flex;gap:12px;overflow-x:auto;padding:2px 20px 6px;margin:0 -20px}
.lm-wrow::-webkit-scrollbar{height:0}
.lm-wcard{position:relative;flex:none;width:128px;background:var(--lm-card);border:1px solid var(--lm-hair);
    border-radius:16px;padding:10px;box-shadow:var(--lm-shadow-card);text-align:start}
.lm-wimg{display:block;width:100%;aspect-ratio:1;border-radius:12px;overflow:hidden;background:#fff;margin-bottom:8px}
.lm-wimg img{width:100%;height:100%;object-fit:cover}
.lm-wn{font-size:12.5px;font-weight:600;line-height:1.25;display:-webkit-box;-webkit-line-clamp:2;
    -webkit-box-orient:vertical;overflow:hidden}
.lm-wb{font-size:10.5px;color:var(--lm-muted);text-transform:uppercase;letter-spacing:.03em;margin-top:1px}
.lm-wp{font-weight:700;font-size:12.5px;margin-top:5px}
.lm-wadd{position:absolute;bottom:8px;inset-inline-end:8px;width:26px;height:26px;border-radius:50%;
    background:var(--lm-ink);color:var(--lm-ground);display:flex;align-items:center;justify-content:center}
.lm-wadd svg{width:13px;height:13px}
.lm-wadd:disabled{opacity:.4}

/* ---------- card polish (premium) ---------- */
.lm-pcard{border:1px solid var(--lm-hair);padding:12px 12px 14px;
    box-shadow:0 10px 26px -18px rgba(90,60,140,.28);
    transition:transform .2s ease, box-shadow .2s ease, border-color .2s ease}
.lm-pcard:hover{transform:translateY(-4px);box-shadow:0 22px 46px -22px rgba(90,60,140,.42);border-color:var(--lm-hair-2)}
.lm-ptilewrap{border-radius:16px;background:linear-gradient(160deg,var(--lm-card-2),var(--lm-card))}
.lm-ptilewrap img{transition:transform .35s ease}
.lm-pcard:hover .lm-ptilewrap img{transform:scale(1.05)}
.lm-pinfo{padding:13px 4px 0}
.lm-pbrand{font-size:10.5px;letter-spacing:.09em;color:var(--lm-accent-2)}
.lm-pname{font-size:15px;line-height:1.3;margin-top:3px}
.lm-price{font-size:16.5px;letter-spacing:-.01em}
.lm-corner{width:34px;height:34px;box-shadow:0 6px 16px -6px rgba(0,0,0,.32);backdrop-filter:blur(8px)}
.lm-wcard{border-radius:18px;padding:11px;box-shadow:0 10px 24px -18px rgba(90,60,140,.28);
    transition:transform .2s ease, box-shadow .2s ease}
.lm-wcard:hover{transform:translateY(-3px);box-shadow:0 18px 38px -22px rgba(90,60,140,.42)}
.lm-wimg{border-radius:14px;background:linear-gradient(160deg,var(--lm-card-2),var(--lm-card))}
.lm-wn{font-size:13px}.lm-wp{font-size:13.5px;margin-top:6px}.lm-wb{color:var(--lm-accent-2)}

/* ---------- header language switcher (desktop topnav) ---------- */
.lm-langsel{position:relative;display:inline-flex;align-items:center;height:42px;border-radius:100px;
    border:1px solid var(--lm-hair);background:var(--lm-card);color:var(--lm-ink);cursor:pointer;flex:none}
.lm-langsel:hover{background:var(--lm-card-2)}
.lm-langsel svg{position:absolute;inset-inline-start:12px;top:50%;transform:translateY(-50%);
    width:18px;height:18px;color:var(--lm-muted);pointer-events:none}
.lm-langsel select{-webkit-appearance:none;appearance:none;border:0;background:transparent;color:inherit;
    font:inherit;font-size:13px;font-weight:600;cursor:pointer;outline:none;height:100%;
    padding-inline-start:36px;padding-inline-end:26px}
.lm-langsel::after{content:"";position:absolute;inset-inline-end:12px;top:50%;width:8px;height:8px;
    border-inline-end:1.6px solid var(--lm-muted);border-bottom:1.6px solid var(--lm-muted);
    transform:translateY(-65%) rotate(45deg);pointer-events:none}

/* ---------- tabbar ---------- */
.lm-tabbar{position:fixed;inset-inline:0;bottom:0;z-index:50}
.lm-tabbar__inner{max-width:560px;margin:0 auto;height:var(--lm-nav-h);background:var(--lm-card);
    border-top:1px solid var(--lm-hair);display:flex;align-items:center;justify-content:space-around;padding:0 10px 8px}
.lm-tab{display:flex;flex-direction:column;align-items:center;gap:4px;font-size:11px;color:var(--lm-ink-3);font-weight:500;flex:1}
.lm-tab svg{width:22px;height:22px}
.lm-tab[aria-current="true"]{color:var(--lm-accent-2)}
.lm-fab{width:56px;height:56px;border-radius:50%;background:var(--lm-accent);color:#fff;display:flex;align-items:center;
    justify-content:center;margin-top:-26px;box-shadow:0 12px 24px -8px rgba(163,119,210,.55);transition:transform .15s;
    flex:none;position:relative}
.lm-fab:active{transform:scale(.9)}
.lm-fab svg{width:24px;height:24px}

/* ---------- scan ---------- */
.lm-scanframe{position:relative;margin:6px auto 0;border-radius:var(--lm-radius-lg);overflow:hidden;aspect-ratio:3/4;
    max-height:min(56vh,520px);width:auto;max-width:100%;height:min(56vh,520px);background:#000}
.lm-scanframe img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;opacity:.92}
.lm-scanframe-ph{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#fff;opacity:.5}
.lm-scan-corners{position:absolute;inset:26px;pointer-events:none}
.lm-scan-corners span{position:absolute;width:34px;height:34px;border:3px solid #fff}
.lm-scan-corners span:nth-child(1){top:0;inset-inline-start:0;border-inline-end:none;border-bottom:none;border-radius:10px 0 0 0}
.lm-scan-corners span:nth-child(2){top:0;inset-inline-end:0;border-inline-start:none;border-bottom:none;border-radius:0 10px 0 0}
.lm-scan-corners span:nth-child(3){bottom:0;inset-inline-start:0;border-inline-end:none;border-top:none;border-radius:0 0 0 10px}
.lm-scan-corners span:nth-child(4){bottom:0;inset-inline-end:0;border-inline-start:none;border-top:none;border-radius:0 0 10px 0}
.lm-scan-line{position:absolute;inset-inline:26px;height:3px;background:linear-gradient(90deg,transparent,var(--lm-accent),transparent);
    animation:lmScan 2.2s ease-in-out infinite}
@keyframes lmScan{0%{top:28px}50%{top:calc(100% - 28px)}100%{top:28px}}
.lm-scan-tag{position:absolute;top:14px;inset-inline-start:14px;background:rgba(16,15,13,.7);color:#fff;font-size:12px;
    font-weight:600;padding:6px 12px;border-radius:100px;backdrop-filter:blur(6px);display:inline-flex;align-items:center;gap:6px}
.lm-scan-demo-tag{position:absolute;top:14px;inset-inline-end:14px;background:rgba(255,255,255,.9);color:var(--lm-ink);
    font-size:11px;font-weight:700;padding:5px 11px;border-radius:100px}
.lm-pd-desc{color:var(--lm-muted);font-size:14px;line-height:1.6;margin-top:12px}

/* ---------- product detail ---------- */
.lm-pd-img{position:relative;border-radius:var(--lm-radius-lg);overflow:hidden;aspect-ratio:1/1;margin-top:6px;background:#fff}
.lm-pd-img img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
.lm-pd-img-ph{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:var(--lm-ink-3);background:var(--lm-card-2)}
.lm-pd-thumbs{display:flex;gap:8px;margin-top:10px;overflow-x:auto;-webkit-overflow-scrolling:touch;scrollbar-width:none}
.lm-pd-thumbs::-webkit-scrollbar{height:0}
.lm-app .lm-pd-thumb{flex:0 0 auto;width:58px;height:58px;border-radius:12px;overflow:hidden;padding:0;background:var(--lm-card-2);border:2px solid transparent}
.lm-pd-thumb img{width:100%;height:100%;object-fit:cover}
.lm-pd-thumb[aria-current="true"]{border-color:var(--lm-accent)}
.lm-pd-brand{font-size:12.5px;color:var(--lm-muted);text-transform:uppercase;letter-spacing:.05em;font-weight:600;margin-top:18px}
.lm-pd-title{font-family:var(--lm-font);font-size:24px;font-weight:600;line-height:1.15;letter-spacing:-.01em;margin-top:4px}
.lm-pd-prices{display:flex;align-items:baseline;gap:10px;margin-top:8px;flex-wrap:wrap}
.lm-pd-price{font-size:22px;font-weight:700;font-variant-numeric:tabular-nums}
.lm-rating{display:inline-flex;align-items:center;gap:4px;font-size:12.5px;font-weight:600;color:var(--lm-muted);margin-inline-start:auto}
.lm-rating svg{width:13px;height:13px;color:var(--lm-star);fill:var(--lm-star)}
.lm-label{font-size:13px;font-weight:600;letter-spacing:.02em;margin:20px 0 10px}
.lm-chipsrow{display:flex;gap:8px;flex-wrap:wrap}
.lm-benefitchip{font-size:12.5px;font-weight:500;color:var(--lm-accent-2);background:var(--lm-accent-bg);
    border:1px solid var(--lm-hair);padding:6px 14px;border-radius:100px}
.lm-app .lm-optchip{font-size:13px;font-weight:600;color:var(--lm-ink);background:var(--lm-card);border:1.5px solid var(--lm-hair-2);
    padding:8px 16px;border-radius:100px}
.lm-optchip[aria-pressed="true"]{border-color:var(--lm-accent);color:var(--lm-accent-2);background:var(--lm-accent-bg)}
.lm-optchip:disabled{opacity:.4;text-decoration:line-through}

/* ---------- action bar / buttons ---------- */
.lm-actionbar{position:fixed;inset-inline:0;bottom:0;z-index:40;display:flex;justify-content:center}
.lm-actionbar__inner{width:100%;max-width:560px;padding:14px 20px calc(14px + env(safe-area-inset-bottom));
    background:linear-gradient(to top,var(--lm-ground) 72%,transparent);display:flex;gap:12px}
.lm-btn{height:56px;border-radius:100px;font-size:15px;font-weight:600;display:flex;align-items:center;
    justify-content:center;gap:9px;transition:transform .14s}
.lm-btn:active{transform:scale(.98)}
.lm-app .lm-btn-dark{background:var(--lm-accent);color:#fff;flex:1}
.lm-btn-dark:disabled{opacity:.5}
.lm-app .lm-btn-ghost{background:var(--lm-card);color:var(--lm-ink);border:1px solid var(--lm-hair-2);flex:1}
/* A full-width block button must never flex-grow — inside a flex COLUMN
   (the auth overlay card) .lm-btn-dark's flex:1 stretched it to a huge
   square. Scoped under .lm-app + placed after the dark rule to win. */
.lm-app .lm-btn-block{width:100%;flex:none}
.lm-btn svg{width:19px;height:19px}
.lm-btn-sm{height:40px;border-radius:100px;padding:0 22px;font-size:13.5px;font-weight:600}

/* ---------- basket ---------- */
.lm-citem{background:var(--lm-card);border:1px solid var(--lm-hair);border-radius:var(--lm-radius);padding:14px;
    margin-top:14px;box-shadow:var(--lm-shadow-card)}
.lm-citem .lm-top{display:flex;gap:12px;align-items:flex-start}
.lm-cthumb{width:66px;height:66px;border-radius:12px;background:#fff;flex:none;overflow:hidden;border:1px solid var(--lm-hair)}
.lm-cthumb img{width:100%;height:100%;object-fit:cover}
.lm-cinfo{flex:1;min-width:0}
.lm-cname{font-weight:600;font-size:15px}
.lm-ccol{font-size:12.5px;color:var(--lm-muted);margin-top:1px}
.lm-cprice{font-weight:600;font-variant-numeric:tabular-nums;white-space:nowrap}
.lm-cfoot{display:flex;align-items:center;justify-content:flex-end;border-top:1px solid var(--lm-hair);margin-top:12px;padding-top:12px}
.lm-stepper{display:flex;align-items:center;gap:6px}
.lm-step{width:32px;height:32px;border-radius:9px;border:1px solid var(--lm-hair-2);display:flex;align-items:center;
    justify-content:center;font-size:17px;line-height:1}
.lm-qty{min-width:24px;text-align:center;font-weight:600;font-variant-numeric:tabular-nums}
.lm-trash{width:32px;height:32px;border-radius:9px;display:flex;align-items:center;justify-content:center;
    color:var(--lm-muted);margin-inline-end:auto}
.lm-trash svg{width:16px;height:16px}
.lm-summary{background:var(--lm-card);border:1px solid var(--lm-hair);border-radius:var(--lm-radius);padding:18px;
    margin-top:18px;box-shadow:var(--lm-shadow-card)}
.lm-summary h3{font-family:var(--lm-font);font-weight:600;font-size:18px;margin:0 0 12px}
.lm-srow{display:flex;justify-content:space-between;font-size:14px;color:var(--lm-muted);padding:6px 0;font-variant-numeric:tabular-nums}
.lm-srow .lm-freeval{color:var(--lm-accent-2);font-weight:600}
.lm-srow.lm-total{color:var(--lm-ink);font-weight:700;font-size:16px;border-top:1px solid var(--lm-hair);margin-top:8px;padding-top:12px}
.lm-eico{width:74px;height:74px;border-radius:50%;background:var(--lm-card);border:1px solid var(--lm-hair);display:flex;
    align-items:center;justify-content:center;color:var(--lm-ink-3);margin:0 auto 14px}
.lm-eico svg{width:30px;height:30px}
.lm-empty-wrap{display:flex;flex-direction:column;align-items:center;text-align:center;padding:60px 8px;color:var(--lm-muted)}
.lm-empty-wrap h3{font-family:var(--lm-font);color:var(--lm-ink);font-weight:600;font-size:19px;margin:4px 0 6px}

/* ---------- checkout ---------- */
.lm-sect{margin-top:18px}
.lm-optcard{width:100%;background:var(--lm-card);border:1px solid var(--lm-hair);border-radius:var(--lm-radius);
    padding:15px 16px;display:flex;align-items:center;gap:13px;margin-bottom:10px;transition:border-color .15s;text-align:start}
.lm-optcard[aria-pressed="true"]{border-color:var(--lm-accent)}
.lm-oico{width:40px;height:40px;border-radius:10px;background:var(--lm-card-2);display:flex;align-items:center;
    justify-content:center;flex:none;color:var(--lm-ink)}
.lm-oico svg{width:20px;height:20px}
.lm-obody{flex:1;min-width:0}
.lm-ot{font-weight:600;font-size:14.5px}
.lm-os{font-size:12.5px;color:var(--lm-muted);margin-top:1px}
.lm-radio{width:22px;height:22px;border-radius:50%;border:2px solid var(--lm-hair-2);flex:none;position:relative}
.lm-optcard[aria-pressed="true"] .lm-radio{border-color:var(--lm-accent)}
.lm-optcard[aria-pressed="true"] .lm-radio::after{content:"";position:absolute;inset:4px;border-radius:50%;background:var(--lm-accent)}
.lm-checkbox{width:24px;height:24px;border-radius:8px;border:2px solid var(--lm-hair-2);flex:none;position:relative;
    display:flex;align-items:center;justify-content:center;color:transparent}
.lm-checkbox svg{width:14px;height:14px}
.lm-optcard[aria-pressed="true"] .lm-checkbox{border-color:var(--lm-accent);background:var(--lm-accent);color:#fff}
.lm-field{margin-bottom:14px}
.lm-field label{display:block;font-size:12.5px;font-weight:600;color:var(--lm-muted);margin-bottom:6px}
.lm-field input,.lm-field select,.lm-field textarea{width:100%;box-sizing:border-box;border-radius:14px;
    border:1px solid var(--lm-hair-2);background:var(--lm-card);font-family:inherit;font-size:14.5px;color:var(--lm-ink)}
.lm-field input,.lm-field select{height:52px;padding:0 16px}
/* textarea was omitted from the width rule above, so the review box fell back to
   the browser's ~122px default while the name input beside it was full-width. */
.lm-field textarea{min-height:96px;padding:12px 16px;line-height:1.5;resize:vertical}
.lm-field input::placeholder,.lm-field textarea::placeholder{color:var(--lm-ink-3)}
/* Delivery-address autocomplete (crave-style suggest + use-my-location). */
.lm-addr{position:relative}
.lm-addr input{padding-inline-end:42px}
.lm-addr-loc{position:absolute;inset-inline-end:8px;top:9px;width:34px;height:34px;display:inline-flex;
    align-items:center;justify-content:center;border:0;background:transparent;color:var(--lm-muted);
    cursor:pointer;border-radius:10px;transition:background .15s,color .15s}
.lm-addr-loc:hover{background:var(--lm-card-2);color:var(--lm-accent)}
.lm-addr-loc.is-loading{opacity:.5;pointer-events:none}
.lm-addr-loc svg{width:19px;height:19px}
.lm-addr-suggest{position:absolute;inset-inline:0;top:calc(100% + 5px);z-index:40;background:var(--lm-card);
    border:1px solid var(--lm-hair);border-radius:14px;box-shadow:0 12px 30px rgba(0,0,0,.14);
    overflow:hidden;max-height:248px;overflow-y:auto}
.lm-addr-suggest .mb-addr-opt{display:block;width:100%;text-align:start;padding:11px 14px;background:none;
    border:0;border-block-end:1px solid var(--lm-hair-2);color:var(--lm-ink);font:inherit;font-size:.86rem;
    cursor:pointer;line-height:1.4}
.lm-addr-suggest .mb-addr-opt:last-child{border-block-end:0}
.lm-addr-suggest .mb-addr-opt:hover{background:var(--lm-card-2)}
.lm-slots{display:flex;flex-wrap:wrap;gap:8px;margin-top:6px}
.lm-slot{border:1px solid var(--lm-hair-2);background:var(--lm-card);color:inherit;border-radius:100px;
    padding:8px 14px;font-size:13px}
.lm-slot[aria-pressed="true"]{border-color:var(--lm-accent);background:var(--lm-accent-bg);font-weight:600}
.lm-slot:disabled{opacity:.4;text-decoration:line-through}
.lm-msg{font-size:13.5px;padding:10px 14px;border-radius:12px;margin-bottom:12px}
.lm-msg--error{background:color-mix(in srgb, var(--lm-sale) 16%, transparent);color:var(--lm-sale)}
.lm-msg--ok{background:color-mix(in srgb, var(--lm-ok) 16%, transparent);color:var(--lm-ok)}

/* ---------- scan result / metrics ---------- */
.lm-metricrow{margin-bottom:14px}
.lm-mhead{display:flex;justify-content:space-between;font-size:13.5px;font-weight:600;margin-bottom:6px}
.lm-mhead span:last-child{color:var(--lm-muted);font-weight:600;font-variant-numeric:tabular-nums}
.lm-mtrack{height:8px;border-radius:100px;background:var(--lm-hair);overflow:hidden}
.lm-mtrack i{display:block;height:100%;border-radius:100px;background:var(--lm-accent);transition:width .6s ease}
.lm-facepeek{display:flex;align-items:center;gap:14px;margin-top:4px}
.lm-facepeek img{width:58px;height:58px;border-radius:50%;object-fit:cover;border:2px solid var(--lm-accent-bg)}
.lm-facepeek-ph{width:58px;height:58px;border-radius:50%;background:var(--lm-accent-bg);color:var(--lm-accent-2);
    display:flex;align-items:center;justify-content:center;border:2px solid var(--lm-accent-bg);flex:none}
.lm-skinage{font-family:var(--lm-font);font-weight:700;font-size:32px;letter-spacing:-.01em}
.lm-skinagelabel{font-size:13px;color:var(--lm-muted);margin-top:2px}
.lm-reco-row{display:flex;align-items:center;gap:12px;background:var(--lm-card);border:1px solid var(--lm-hair);
    border-radius:var(--lm-radius);padding:10px;margin-bottom:10px;box-shadow:var(--lm-shadow-card);width:100%;text-align:start}
.lm-reco-row .lm-rimg{width:52px;height:52px;border-radius:10px;overflow:hidden;background:#fff;flex:none;border:1px solid var(--lm-hair)}
.lm-reco-row .lm-rimg img{width:100%;height:100%;object-fit:cover}
.lm-rinfo{flex:1;min-width:0}
.lm-rn{font-weight:600;font-size:13.5px}
.lm-rb{font-size:11.5px;color:var(--lm-muted)}
.lm-radd{width:34px;height:34px;border-radius:50%;background:var(--lm-accent-bg);color:var(--lm-accent-2);display:flex;
    align-items:center;justify-content:center;flex:none}
.lm-radd svg{width:16px;height:16px}
.lm-radd:disabled{opacity:.4}
.lm-progressstat{display:flex;align-items:center;gap:12px;background:var(--lm-accent-bg);border-radius:var(--lm-radius);
    padding:14px 16px;margin-top:16px}
.lm-pico{width:38px;height:38px;border-radius:50%;background:var(--lm-accent);color:#fff;display:flex;align-items:center;
    justify-content:center;flex:none}
.lm-pico svg{width:19px;height:19px}
.lm-pt{font-weight:700;font-size:15px;color:var(--lm-accent-2)}
.lm-ps{font-size:12px;color:var(--lm-muted)}
.lm-demo-note{font-size:11.5px;color:var(--lm-ink-3);text-align:center;margin-top:10px}

/* ---------- calendar ---------- */
.lm-segrow{display:flex;gap:8px;background:var(--lm-card-2);padding:4px;border-radius:100px;margin-top:4px}
.lm-segbtn{flex:1;height:38px;border-radius:100px;font-size:13.5px;font-weight:600;color:var(--lm-muted)}
.lm-segbtn[aria-pressed="true"]{background:var(--lm-card);color:var(--lm-ink);box-shadow:var(--lm-shadow-card)}
.lm-chartcard{background:var(--lm-card);border:1px solid var(--lm-hair);border-radius:var(--lm-radius-lg);padding:18px;
    margin-top:16px;box-shadow:var(--lm-shadow-card)}
.lm-chartcard .lm-cap{font-size:12.5px;color:var(--lm-muted);margin-top:10px;text-align:center}
.lm-barsrow{display:flex;align-items:flex-end;gap:8px;height:80px}
.lm-bcol{flex:1;display:flex;flex-direction:column;align-items:center;gap:6px;height:100%;justify-content:flex-end}
.lm-bfill{width:100%;border-radius:6px;background:var(--lm-accent-bg);height:100%;display:flex;align-items:flex-end}
.lm-bfill i{display:block;width:100%;border-radius:6px;background:var(--lm-accent)}
.lm-blabel{font-size:10.5px;color:var(--lm-ink-3)}
.lm-checklist{margin-top:16px}

/* ---------- confirm ---------- */
.lm-confwrap{display:flex;flex-direction:column;align-items:center;text-align:center;padding:40px 4px 24px}
.lm-checkc{width:96px;height:96px}
.lm-checkc circle{fill:none;stroke:var(--lm-accent);stroke-width:4;stroke-dasharray:283;stroke-dashoffset:283;
    animation:lmDraw .6s ease forwards}
.lm-checkc path{fill:none;stroke:var(--lm-ink);stroke-width:5;stroke-linecap:round;stroke-linejoin:round;
    stroke-dasharray:60;stroke-dashoffset:60;animation:lmDraw .4s .5s ease forwards}
@keyframes lmDraw{to{stroke-dashoffset:0}}
.lm-confwrap h2{font-family:var(--lm-font);font-weight:600;font-size:24px;margin:18px 0 4px}
.lm-confwrap p{color:var(--lm-muted);font-size:14px;margin:0}
.lm-orderno{margin-top:8px;font-weight:600;letter-spacing:.04em}
.lm-conf-actions{display:flex;flex-direction:column;gap:10px;width:100%;margin-top:28px}
.lm-conf-actions .lm-btn{width:100%}

/* ---------- profile ---------- */
.lm-profile{display:flex;align-items:center;gap:14px;background:var(--lm-card);border:1px solid var(--lm-hair);
    border-radius:var(--lm-radius);padding:16px;margin-top:8px;box-shadow:var(--lm-shadow-card)}
.lm-profile .lm-avatar,.lm-profile .lm-avatar-fallback{width:58px;height:58px;font-size:20px}
.lm-pn{font-family:var(--lm-font);font-weight:600;font-size:18px}
.lm-pe{font-size:13px;color:var(--lm-muted)}
.lm-acclist{margin-top:16px}
.lm-accrow{display:flex;align-items:center;gap:14px;padding:15px 4px;border-bottom:1px solid var(--lm-hair);width:100%;text-align:start}
.lm-aico{width:38px;height:38px;border-radius:10px;background:var(--lm-card-2);display:flex;align-items:center;
    justify-content:center;color:var(--lm-ink);flex:none}
.lm-aico svg{width:18px;height:18px}
.lm-at{flex:1;font-weight:500;font-size:15px}
.lm-chev{color:var(--lm-ink-3)}
.lm-langlist{display:flex;flex-wrap:wrap;gap:8px;padding:0 4px 15px;border-bottom:1px solid var(--lm-hair)}
.lm-langlist a{font-size:12.5px;font-weight:600;padding:6px 13px;border-radius:100px;border:1px solid var(--lm-hair-2);color:inherit;text-decoration:none}
.lm-langlist a.is-current{background:var(--lm-accent);border-color:var(--lm-accent);color:#fff}
.lm-switchrow{width:100%}
.lm-switch{width:44px;height:26px;border-radius:100px;background:var(--lm-hair-2);position:relative;flex:none;transition:background .15s}
.lm-switch i{position:absolute;top:3px;inset-inline-start:3px;width:20px;height:20px;border-radius:50%;background:#fff;
    transition:inset-inline-start .15s;box-shadow:0 2px 4px rgba(0,0,0,.2);display:block}
.lm-switch[aria-checked="true"]{background:var(--lm-accent)}
.lm-switch[aria-checked="true"] i{inset-inline-start:21px}

/* ---------- overlays: onboarding / signin ---------- */
/* position:FIXED (viewport), not absolute: the containing block .lm-app is as
   tall as the whole home screen, so an absolute overlay stretched to the full
   document height — its card grew to ~3900px and the body/heading fell far
   below the fold, leaving a mobile visitor a blank screen with only the Skip
   button. Fixed pins it to the viewport (the story viewer already does this;
   the >=768px override did too — mobile was the gap). */
.lm-overlay{position:fixed;inset:0;z-index:70;display:flex}
.lm-ob-card{position:relative;flex:1;display:flex;flex-direction:column;min-height:100%;width:100%;background:var(--lm-ground)}
.lm-ob-hero{flex:1;position:relative;overflow:hidden;background:var(--lm-card-2);min-height:220px}
.lm-ob-hero .lm-cluster{position:absolute;inset:0;display:grid;grid-template-columns:repeat(3,1fr);gap:12px;padding:26px;align-content:center}
.lm-cluster .lm-bub{aspect-ratio:1;border-radius:20px;overflow:hidden;background:var(--lm-card);border:1px solid var(--lm-hair);
    display:flex;align-items:center;justify-content:center;color:var(--lm-accent)}
.lm-cluster .lm-bub img{width:100%;height:100%;object-fit:cover}
.lm-cluster .lm-bub:nth-child(2){transform:translateY(-14px)}
.lm-cluster .lm-bub:nth-child(5){transform:translateY(-14px);border-radius:50%;background:var(--lm-accent);color:#fff}
.lm-cluster .lm-bub:nth-child(5) svg{width:40%}
.lm-ob-body{padding:30px 28px 34px}
.lm-ob-body h2{font-family:var(--lm-font);font-weight:600;font-size:28px;line-height:1.15;letter-spacing:-.01em;margin:0}
.lm-ob-body p{color:var(--lm-muted);font-size:15px;line-height:1.6;margin:12px 0 0}
.lm-ob-foot{display:flex;align-items:center;justify-content:space-between;margin-top:26px}
.lm-dots-nav{display:flex;gap:7px}
.lm-dots-nav i{width:7px;height:7px;border-radius:50%;background:var(--lm-hair-2);display:block}
.lm-dots-nav i.on{width:22px;border-radius:4px;background:var(--lm-accent)}
.lm-ob-arrow{width:64px;height:64px;border-radius:50%;background:var(--lm-accent);color:#fff;display:flex;align-items:center;
    justify-content:center;transition:transform .15s;flex:none}
.lm-ob-arrow:active{transform:scale(.9)}
.lm-ob-arrow svg{width:24px;height:24px}
.lm-ov-skip{position:absolute;top:24px;inset-inline-end:24px;z-index:5;font-size:13px;font-weight:600;color:var(--lm-muted);
    background:var(--lm-card);padding:8px 16px;border-radius:100px;border:1px solid var(--lm-hair)}
.lm-auth .lm-ob-card{padding:60px 28px 28px;overflow-y:auto}
.lm-auth h2{font-family:var(--lm-font);font-weight:600;font-size:26px;letter-spacing:-.01em;margin:0}
.lm-auth .lm-lead{color:var(--lm-muted);font-size:14px;margin:8px 0 22px}
.lm-social{display:flex;gap:12px;margin-bottom:20px}
.lm-social button{flex:1;height:52px;border-radius:14px;border:1px solid var(--lm-hair-2);background:var(--lm-card);
    display:flex;align-items:center;justify-content:center}
.lm-social svg{width:22px;height:22px}
.lm-divider{display:flex;align-items:center;gap:12px;color:var(--lm-ink-3);font-size:12px;margin:6px 0 18px}
.lm-divider::before,.lm-divider::after{content:"";flex:1;height:1px;background:var(--lm-hair)}
.lm-authswitch{text-align:center;font-size:13px;color:var(--lm-ink-3);margin-top:16px}

/* ---------- misc / fly-to-basket / toast ---------- */
.lm-fly{position:fixed;z-index:999;width:52px;height:60px;border-radius:12px;overflow:hidden;pointer-events:none;
    box-shadow:var(--lm-shadow);background:#fff}
.lm-fly img{width:100%;height:100%;object-fit:cover}
.lm-toast{position:fixed;inset-inline-start:50%;bottom:calc(var(--lm-nav-h) + 20px);transform:translateX(-50%) translateY(20px);
    background:var(--lm-ink);color:var(--lm-ground);padding:12px 20px;border-radius:100px;font-size:13.5px;font-weight:500;
    opacity:0;transition:opacity .25s,transform .25s;z-index:90;white-space:nowrap;display:flex;align-items:center;gap:8px;
    pointer-events:none}
.lm-toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
.lm-muted{color:var(--lm-muted)}

/* ---------- F3: stories rail + full-screen viewer ---------- */
.lm-stories{display:flex;gap:14px;overflow-x:auto;padding:14px 20px 2px;margin:0 -20px;scrollbar-width:none}
.lm-stories::-webkit-scrollbar{height:0}
/* Desktop mouse can't swipe a hidden rail — restore a slim bar on hover/fine so
   overflowing rails stay reachable; touch keeps the hidden-bar look. */
@media (hover:hover) and (pointer:fine){
    #lmApp .lm-stories,#lmApp .lm-catrow,#lmApp .lm-wrow{scrollbar-width:thin;scrollbar-color:var(--lm-hair-2) transparent}
    #lmApp .lm-stories::-webkit-scrollbar,#lmApp .lm-catrow::-webkit-scrollbar,#lmApp .lm-wrow::-webkit-scrollbar{display:block;height:8px}
    #lmApp .lm-stories::-webkit-scrollbar-thumb,#lmApp .lm-catrow::-webkit-scrollbar-thumb,#lmApp .lm-wrow::-webkit-scrollbar-thumb{background:var(--lm-hair-2);border-radius:999px;border:2px solid transparent;background-clip:content-box}
    #lmApp .lm-stories::-webkit-scrollbar-thumb:hover,#lmApp .lm-catrow::-webkit-scrollbar-thumb:hover,#lmApp .lm-wrow::-webkit-scrollbar-thumb:hover{background:var(--lm-accent)}
    #lmApp .lm-stories::-webkit-scrollbar-track,#lmApp .lm-catrow::-webkit-scrollbar-track,#lmApp .lm-wrow::-webkit-scrollbar-track{background:transparent}
}
.lm-story-item{display:flex;flex-direction:column;align-items:center;gap:6px;flex:none;width:64px}
.lm-story-ring{width:60px;height:60px;border-radius:50%;padding:2.5px;flex:none;
    background:linear-gradient(135deg,var(--lm-accent),var(--lm-sale));display:flex;transition:transform .15s}
.lm-story-item:active .lm-story-ring{transform:scale(.94)}
.lm-story-ring.is-viewed{background:var(--lm-hair-2)}
.lm-story-thumb{width:100%;height:100%;border-radius:50%;overflow:hidden;background:var(--lm-card-2);
    border:2.5px solid var(--lm-ground);display:flex;align-items:center;justify-content:center;color:var(--lm-ink-3)}
.lm-story-thumb img{width:100%;height:100%;object-fit:cover}
.lm-story-label{font-size:10.5px;color:var(--lm-muted);max-width:64px;overflow:hidden;text-overflow:ellipsis;
    white-space:nowrap;font-weight:500}

.lm-storyviewer{position:fixed;inset:0;z-index:200;background:#000;display:flex;flex-direction:column;color:#fff}
.lm-sv-bars{display:flex;gap:4px;padding:12px 12px 0;flex:none}
.lm-sv-seg{flex:1;height:3px;border-radius:2px;background:rgba(255,255,255,.32);overflow:hidden}
.lm-sv-seg i{display:block;height:100%;width:0%;background:#fff}
.lm-sv-top{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;flex:none}
.lm-sv-meta{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600}
.lm-sv-avatar{width:26px;height:26px;border-radius:50%;background:var(--lm-accent);color:#fff;font-size:11px;
    font-weight:700;display:inline-flex;align-items:center;justify-content:center;flex:none}
.lm-sv-close{width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.16);color:#fff;
    display:flex;align-items:center;justify-content:center;flex:none}
.lm-sv-close svg{width:17px;height:17px}
.lm-sv-stage{position:relative;flex:1;min-height:0;display:flex;align-items:center;justify-content:center;
    overflow:hidden;touch-action:pan-y}
.lm-sv-media{max-width:100%;max-height:100%;object-fit:contain}
.lm-sv-caption{position:absolute;inset-inline:0;bottom:0;padding:20px 16px;color:#fff;font-size:14px;line-height:1.5;
    background:linear-gradient(to top,rgba(0,0,0,.75),transparent)}
.lm-sv-foot{padding:14px 16px calc(14px + env(safe-area-inset-bottom));flex:none}
.lm-sv-cta{width:100%;text-decoration:none}

/* ---------- Search (client-side live filter) ---------- */
.lm-searchrow{position:relative;margin-top:18px}
.lm-search-ic{position:absolute;inset-inline-start:16px;top:50%;transform:translateY(-50%);color:var(--lm-ink-3);
    display:flex;pointer-events:none}
.lm-search-ic svg{width:18px;height:18px}
.lm-search{width:100%;height:50px;border-radius:100px;border:1px solid var(--lm-hair-2);background:var(--lm-card);
    color:var(--lm-ink);padding-inline-start:46px;padding-inline-end:16px;font-family:inherit;font-size:14px;
    -webkit-appearance:none;appearance:none}
.lm-search::placeholder{color:var(--lm-ink-3)}
.lm-search:focus{outline:2px solid var(--lm-accent);outline-offset:1px}
.lm-search::-webkit-search-cancel-button{-webkit-appearance:none}

/* ---------- F6: reviews ---------- */
.lm-pd-reviews{margin-top:26px;padding-top:22px;border-top:1px solid var(--lm-hair)}
.lm-rv-summary{display:flex;gap:26px;align-items:center;margin-bottom:16px;flex-wrap:wrap}
.lm-rv-avgblock{display:flex;flex-direction:column;align-items:center;min-width:66px;flex:none}
.lm-rv-avgnum{font-family:var(--lm-font);font-size:32px;font-weight:700;line-height:1}
.lm-rv-avgstars{margin-top:4px;display:flex;gap:1px}
.lm-rv-avgcount{font-size:11.5px;color:var(--lm-muted);margin-top:3px}
.lm-rv-dist{flex:1;min-width:170px;display:grid;gap:5px}
.lm-rv-bar{display:grid;grid-template-columns:12px 1fr 20px;align-items:center;gap:8px;font-size:11.5px;color:var(--lm-muted)}
.lm-rv-bar i{display:block;height:6px;border-radius:100px;background:var(--lm-hair);overflow:hidden}
.lm-rv-bar i s{display:block;height:100%;background:var(--lm-star);text-decoration:none}
.lm-rv-bar b{text-align:end;font-weight:600;color:var(--lm-ink)}
.lm-star-ic{font-size:13px;color:var(--lm-hair-2);line-height:1}
.lm-star-ic.is-on{color:var(--lm-star)}
.lm-rv-empty{padding:22px 14px;text-align:center;color:var(--lm-muted);background:var(--lm-card-2);
    border-radius:var(--lm-radius);margin-bottom:10px;font-size:13.5px}
.lm-rv-item{padding:14px 0;border-top:1px solid var(--lm-hair)}
.lm-rv-item:first-child{border-top:0;padding-top:0}
.lm-rv-item-top{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px}
.lm-rv-item-top .lm-rv-stars{display:flex;gap:1px}
.lm-rv-name{font-weight:600;font-size:13.5px}
.lm-rv-date{font-size:11.5px;color:var(--lm-ink-3);margin-inline-start:auto}
.lm-rv-body{font-size:13.5px;color:var(--lm-muted);line-height:1.55;margin:0}
.lm-rv-writebtn{margin-top:6px}
.lm-rv-form{margin-top:18px;display:grid;gap:2px}
.lm-rv-starsinput{display:flex;gap:8px;margin-bottom:16px}
.lm-rv-starbtn{font-size:28px;line-height:1;color:var(--lm-hair-2);padding:0;transition:transform .1s}
.lm-rv-starbtn.is-on{color:var(--lm-star)}
.lm-rv-starbtn:active{transform:scale(1.15)}
.lm-rv-msg{font-size:13px;min-height:1.2em;margin:2px 0 8px;color:var(--lm-muted)}
.lm-rv-msg.is-bad{color:var(--lm-sale)}
.lm-rv-formrow{display:flex;gap:10px}

/* ---------- K3: loyalty (checkout redeem + profile Rewards card) ---------- */
.lm-loyalty-teaser{background:var(--lm-accent-bg);border-radius:var(--lm-radius);padding:14px 16px}
.lm-loyalty-teaser .lm-lt-program{font-weight:700;font-size:14px;color:var(--lm-ink);margin-bottom:4px}
.lm-loyalty-teaser p{margin:2px 0 0;font-size:12.5px;color:var(--lm-accent-2)}
.lm-loyalty-teaser .lm-loyalty-hint{color:var(--lm-muted)}
.lm-loyalty-redeem{background:var(--lm-card);border:1px solid var(--lm-accent);border-radius:var(--lm-radius);
    padding:14px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px}
.lm-lr-info{min-width:0}
.lm-lr-bal{font-weight:700;font-size:14px}
.lm-lr-tier{font-size:11px;color:var(--lm-accent-2);font-weight:700;margin-inline-start:6px;
    background:var(--lm-accent-bg);padding:2px 8px;border-radius:100px}
.lm-lr-offer{font-size:12px;color:var(--lm-muted);margin-top:3px}
.lm-rewards-card{background:linear-gradient(135deg,var(--lm-accent),var(--lm-accent-2));border-radius:var(--lm-radius-lg);
    padding:20px;margin-top:16px;color:#fff;box-shadow:var(--lm-shadow-card)}
.lm-rewards-card .lm-rw-program{font-family:var(--lm-font);font-weight:700;font-size:16px}
.lm-rewards-card .lm-rw-line{font-size:12.5px;opacity:.92;margin-top:4px}
.lm-rw-tiers{display:flex;gap:8px;margin-top:16px}
.lm-rw-tier{flex:1;text-align:center;background:rgba(255,255,255,.18);border-radius:12px;padding:8px 4px;
    font-size:11.5px;font-weight:700}
.lm-rw-tier.is-current{background:#fff;color:var(--lm-accent-2)}

/* =====================================================================
   DESKTOP RESPONSIVE LAYER — additive only. Nothing above this line is
   modified; every rule below lives inside a min-width media query (per
   the shared responsive contract: 768 tablet / 1024 desktop / 1280 wide),
   so rendering under 768px is byte-identical to before. All new surfaces
   below reuse the existing --lm-* custom properties (never a new hardcoded
   color), so both the OS dark-scheme block and the manual [data-theme]
   override above already cover them — no extra dark-mode rules needed.
   Grid-based column placement (grid-column:1/2 etc.) also auto-mirrors for
   [dir="rtl"] stores with no extra rules, since CSS Grid tracks are laid
   out relative to inline-start/end, not literal left/right.
   ===================================================================== */
:root{ --lm-topnav-h:76px; }

/* Desktop top nav ships in the markup at every width; stays invisible
   (and thus fully inert for mobile/tablet) until the 1024 rule below
   flips it to display:flex. */
.lm-topnav{display:none}

/* ---------- tablet (≥768px): breathing room only, still one column ---------- */
@media (min-width:768px){
    .lm-app{max-width:680px}
    .lm-screen{padding-inline:clamp(20px,4vw,32px)}
    /* the fixed bottom bars are centered on their own max-width, so they must
       track .lm-app's widened tablet width or they sit narrower than the content */
    .lm-tabbar__inner,.lm-actionbar__inner{max-width:680px}
}

/* ---------- desktop (≥1024px) ---------- */
@media (min-width:1024px){
    /* shell: override (never edit) the base max-width cap; add an inner
       content wrapper on the existing #lm-main instead of a new element */
    .lm-app{max-width:none;box-shadow:none}
    #lm-main{max-width:min(1200px,92vw);margin-inline:auto;padding-inline:clamp(20px,5vw,48px)}
    .lm-screen{padding-inline:0;padding-block:28px 48px}
    .lm-screen.has-tabbar{padding-bottom:48px}
    .lm-screen.has-actionbar{padding-bottom:48px}
    .lm-empty{grid-column:1/-1}

    /* top nav replaces the bottom tabbar */
    .lm-tabbar{display:none}
    .lm-topnav{
        display:flex;position:sticky;top:0;z-index:60;
        background:var(--lm-ground);border-block-end:1px solid var(--lm-hair);
    }
    .lm-topnav__inner{
        width:100%;max-width:min(1200px,92vw);margin-inline:auto;
        padding-inline:clamp(20px,5vw,48px);height:var(--lm-topnav-h);
        display:flex;align-items:center;gap:28px;
    }
    .lm-topnav__brand{display:flex;align-items:center;gap:10px;font-weight:700;font-size:16.5px;flex:none;min-width:0}
    .lm-topnav__name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:220px}
    .lm-topnav__logo{width:36px;height:36px;border-radius:50%;background:var(--lm-accent);display:flex;
        align-items:center;justify-content:center;flex:none}
    .lm-topnav__links{display:flex;align-items:center;gap:4px;margin-inline-start:8px;flex:1}
    .lm-topnav .lm-tab{
        flex:none;flex-direction:row;gap:8px;font-size:14px;font-weight:600;color:var(--lm-muted);
        padding:10px 16px;border-radius:100px;transition:background .15s,color .15s;
    }
    .lm-topnav .lm-tab svg{width:18px;height:18px}
    .lm-topnav .lm-tab:hover{background:var(--lm-card-2);color:var(--lm-ink)}
    .lm-topnav .lm-tab[aria-current="true"]{background:var(--lm-accent-bg);color:var(--lm-accent-2)}
    .lm-topnav__actions{display:flex;align-items:center;gap:10px;flex:none;margin-inline-start:auto}

    /* action bar: no longer a fixed viewport-wide dock at desktop — it now
       flows inline inside whichever content column it lives in (buy-box /
       checkout sidebar / centered scanresult column) */
    .lm-actionbar{position:static;display:block}
    .lm-actionbar__inner{max-width:none;width:100%;padding:16px 0 0;background:none}

    /* ---------- home: hero band — the col-1 stack (header, status chip, stories
       rail, greeting, then the promo banner) fills the width beside a compact
       Daily Routine card, so there is NO dead gutter between them. Every child
       is placed explicitly: an unplaced child would auto-flow into the narrow
       routine-card column and clip (the 84%-wide banner did exactly that, worst
       in RTL). Search + categories + product grid + watchlist span full width
       below the hero. ---------- */
    [data-screen="home"]{display:grid;grid-template-columns:1fr 340px;column-gap:28px;align-items:start}
    [data-screen="home"] > .lm-hdr{grid-column:1;grid-row:1}
    /* desktop top-nav owns notifications → drop the duplicate mobile-header bell */
    .lm-hdr .lm-bellbtn{display:none}
    [data-screen="home"] > .lm-statusrow{grid-column:1;grid-row:2}
    [data-screen="home"] > .lm-stories{grid-column:1;grid-row:3}
    [data-screen="home"] > .lm-display{grid-column:1;grid-row:4;margin:4px 0 8px}
    /* compact routine card on the side, pinned to the top of the col-1 stack */
    [data-screen="home"] > .lm-routinecard{grid-column:2;grid-row:1/span 4;align-self:start;margin:0}
    /* the promo banner is a full-width band the SAME width as the search bar
       (grid-column:1/-1), sitting below the hero — NOT squeezed into the 340px
       routine-card column, which is what clipped it before. */
    [data-screen="home"] > .lm-banners{grid-column:1/-1;grid-row:5;margin:6px 0 0}
    /* full-width bands below the hero */
    [data-screen="home"] > .lm-catrow{grid-column:1/-1}
    [data-screen="home"] .lm-searchrow{grid-column:1/-1}
    [data-screen="home"] .lm-sectrow{grid-column:1/-1}
    [data-screen="home"] .lm-grid{grid-column:1/-1}
    [data-screen="home"] .lm-wrow{grid-column:1/-1}
    [data-screen="home"] #lmWatchlistSect{grid-column:1/-1}

    /* product grid: more columns, more generous cards (not tiny) */
    .lm-grid{grid-template-columns:repeat(4,minmax(0,1fr));gap:20px}
    .lm-pcard{padding:14px 14px 16px;border-radius:24px}
    .lm-pname{font-size:15.5px}
    .lm-price{font-size:16px}
    .lm-wcard{width:150px}

    /* ---------- product detail: image left / buy-box right ---------- */
    /* Explicit rows: the image (row 2) and thumbnails (row 3) stack in col-1, and
       the buy-box spans from the top of col-2 (grid-row:2) so it sits BESIDE the
       image top. Without the explicit row the body auto-flowed to row 3 next to
       the thumbnails, dropping all the product info to the bottom and leaving the
       whole top of the column empty. */
    [data-screen="product"]{display:grid;grid-template-columns:1fr 1fr;column-gap:56px;align-items:start}
    [data-screen="product"] .lm-hdr{grid-column:1/-1}
    [data-screen="product"] .lm-pd-img{grid-column:1;grid-row:2;margin-top:0;position:sticky;top:calc(var(--lm-topnav-h) + 24px)}
    [data-screen="product"] .lm-pd-thumbs{grid-column:1;grid-row:3}
    [data-screen="product"] .lm-pd-body{grid-column:2;grid-row:2/span 2;align-self:start;min-width:0}
    [data-screen="product"] .lm-pd-title{font-size:30px}

    /* ---------- basket: lines left / sticky summary right (reuses the
       existing #lmBasketView wrapper — no new element needed) ---------- */
    [data-screen="basket"] #lmBasketView{display:grid;grid-template-columns:1fr 360px;column-gap:40px;align-items:start}
    [data-screen="basket"] #lmBasketLines{grid-column:1}
    [data-screen="basket"] .lm-summary{grid-column:2;margin-top:0;position:sticky;top:calc(var(--lm-topnav-h) + 24px)}
    [data-screen="basket"] #lmBasketEmpty{max-width:480px;margin-inline:auto}

    /* ---------- checkout: form left / sticky summary+CTA right ---------- */
    [data-screen="checkout"]{display:grid;grid-template-columns:1fr 360px;column-gap:40px;align-items:start}
    [data-screen="checkout"] .lm-hdr{grid-column:1/-1}
    [data-screen="checkout"] .lm-checkout-body{grid-column:1;min-width:0}
    [data-screen="checkout"] .lm-checkout-side{grid-column:2;position:sticky;top:calc(var(--lm-topnav-h) + 24px)}
    [data-screen="checkout"] .lm-checkout-side .lm-summary{margin-top:0}

    /* ---------- scan / scanresult / confirm: centered content column —
       tasteful, not a stretched full-bleed camera UI ---------- */
    [data-screen="scan"],[data-screen="scanresult"],[data-screen="confirm"]{max-width:640px;margin-inline:auto}
    [data-screen="scan"] .lm-scanframe{max-width:420px;margin-inline:auto}

    /* ---------- calendar / profile: comfortable centered column (no
       dedicated 2-col pattern specified for these — this keeps them from
       looking like a lone phone card in a sea of grey without inventing
       columns/data the theme doesn't have) ---------- */
    [data-screen="calendar"],[data-screen="profile"]{max-width:760px;margin-inline:auto}

    /* ---------- onboarding / sign-in overlays: centered card instead of a
       full-bleed viewport-wide hero ---------- */
    .lm-overlay{
        position:fixed;inset:0;z-index:80;background:rgba(23,13,40,.55);
        display:grid;place-items:center;padding:28px;overflow:auto;
    }
    .lm-ob-card{
        flex:0 1 auto;min-height:0;width:min(440px,92vw);max-height:90vh;
        border-radius:28px;box-shadow:var(--lm-shadow);overflow:hidden;
    }
    .lm-auth .lm-ob-card{overflow-y:auto}

    /* ---------- F3 story viewer: a centered phone-shaped card instead of a
       full-bleed fullscreen takeover — same "centered modal" treatment the
       onboarding overlay just above gets ---------- */
    .lm-storyviewer{
        top:50%;bottom:auto;transform:translateY(-50%);inset-inline:0;
        width:min(420px,92vw);height:min(760px,88vh);margin-inline:auto;
        border-radius:24px;box-shadow:var(--lm-shadow);
    }
}

/* ---------- wide desktop (≥1280px) ---------- */
@media (min-width:1280px){
    #lm-main{max-width:1280px}
    .lm-topnav__inner{max-width:1280px}
    .lm-grid{grid-template-columns:repeat(5,minmax(0,1fr))}
}

/* ---------- coupon box ---------- */
.lm-couponbox{margin:4px 0 12px}
.lm-couponrow{display:flex;gap:8px}
.lm-coupon-input{flex:1;height:44px;border-radius:12px;border:1px solid var(--lm-hair-2);background:var(--lm-card);
    padding:0 14px;font:inherit;font-size:14px;color:var(--lm-ink)}
.lm-coupon-apply{flex:none;background:var(--lm-ink);color:var(--lm-ground)}
.lm-coupon-msg{font-size:12px;font-weight:600;margin-top:7px;color:var(--lm-ok)}
.lm-coupon-msg.is-bad{color:var(--lm-sale)}

/* ---------- order history ---------- */
.lm-order{background:var(--lm-card);border:1px solid var(--lm-hair);border-radius:16px;padding:14px 16px;margin-bottom:10px}
.lm-order-top{display:flex;align-items:center;justify-content:space-between;gap:10px}
.lm-order-top b{font-size:14.5px}
.lm-order-status{font-size:11px;font-weight:700;padding:3px 10px;border-radius:100px;background:var(--lm-accent-bg);color:var(--lm-accent-2)}
.lm-os--delivered,.lm-os--completed{background:color-mix(in srgb,var(--lm-ok) 16%,transparent);color:var(--lm-ok)}
.lm-os--cancelled{background:color-mix(in srgb,var(--lm-sale) 16%,transparent);color:var(--lm-sale)}
.lm-order-meta{font-size:12.5px;color:var(--lm-muted);margin-top:6px}
.lm-order-actions{display:flex;gap:8px;margin-top:12px}
.lm-order-btn{flex:1;text-align:center;height:38px;line-height:38px;border-radius:100px;font-size:13px;font-weight:600;
    text-decoration:none;color:var(--lm-ink);background:var(--lm-card);border:1px solid var(--lm-hair-2)}
.lm-order-btn--p{background:var(--lm-accent);color:#fff;border-color:var(--lm-accent)}

/* ---------- category chips ---------- */
.lm-catrow{display:flex;gap:8px;overflow-x:auto;padding:2px 20px 4px;margin:6px -20px 2px;-webkit-overflow-scrolling:touch}
.lm-catrow::-webkit-scrollbar{height:0}
.lm-catchip{flex:none;font-size:13px;font-weight:600;color:var(--lm-ink);background:var(--lm-card);
    border:1px solid var(--lm-hair-2);border-radius:100px;padding:8px 16px;white-space:nowrap;transition:background .15s,color .15s,border-color .15s}
.lm-catchip.is-on{background:var(--lm-accent);color:#fff;border-color:var(--lm-accent)}

/* ---------- status row: open-now + branch ---------- */
.lm-statusrow{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:2px 0 12px}
.lm-openchip{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;font-weight:600;color:var(--lm-ok);
    background:var(--lm-card);border:1px solid var(--lm-hair);border-radius:100px;padding:6px 12px}
.lm-openchip i{width:7px;height:7px;border-radius:50%;background:var(--lm-ok);flex:none}
.lm-openchip.is-closed{color:var(--lm-sale)}
.lm-openchip.is-closed i{background:var(--lm-sale)}
.lm-branchsel{position:relative;display:inline-flex;align-items:center;height:32px;border-radius:100px;
    border:1px solid var(--lm-hair);background:var(--lm-card);color:var(--lm-ink);cursor:pointer}
.lm-branchsel svg{position:absolute;inset-inline-start:10px;top:50%;transform:translateY(-50%);width:15px;height:15px;color:var(--lm-accent-2);pointer-events:none}
.lm-branchsel::after{content:"";position:absolute;inset-inline-end:11px;top:50%;width:6px;height:6px;
    border-inline-end:1.5px solid var(--lm-muted);border-bottom:1.5px solid var(--lm-muted);
    transform:translateY(-65%) rotate(45deg);pointer-events:none}
.lm-branchsel select{-webkit-appearance:none;appearance:none;border:0;background:transparent;color:inherit;
    font:inherit;font-size:12.5px;font-weight:600;cursor:pointer;outline:none;height:100%;
    padding-inline-start:30px;padding-inline-end:24px;max-width:170px}
/* focus ring follows the rounded pill, not the inner rectangular <select> */
.lm-app .lm-branchsel select:focus-visible,.lm-app .lm-langsel select:focus-visible{outline:none}
.lm-branchsel:focus-within,.lm-langsel:focus-within{outline:2px solid var(--lm-accent);outline-offset:2px}

/* ---------- promo banners ---------- */
.lm-banners{margin:2px 0 14px}
.lm-banner-track{display:flex;gap:12px;overflow-x:auto;scroll-snap-type:x mandatory;padding:2px 20px;margin:0 -20px;-webkit-overflow-scrolling:touch}
.lm-banner-track::-webkit-scrollbar{height:0}
.lm-banner{flex:0 0 84%;scroll-snap-align:center;position:relative;min-height:106px;aspect-ratio:16/9;border-radius:20px;overflow:hidden;
    display:flex;align-items:center;padding:18px 20px;text-decoration:none;
    background:linear-gradient(125deg,var(--lm-accent),var(--lm-accent-2));color:#fff}
.lm-banner:nth-child(even){background:linear-gradient(125deg,#E98BA8,var(--lm-sale))}
.lm-banner-img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;opacity:.92}
.lm-banner-copy{position:relative;display:flex;flex-direction:column;gap:3px;z-index:1}
.lm-banner-kick{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;opacity:.92}
.lm-banner-title{font-size:18px;font-weight:700;line-height:1.2}
.lm-banner-dots{display:flex;gap:6px;justify-content:center;margin-top:9px}
.lm-banner-dots i{width:6px;height:6px;border-radius:50%;background:var(--lm-hair-2);transition:width .2s,background .2s}
.lm-banner-dots i.on{background:var(--lm-accent);width:16px;border-radius:100px}

/* favorites count pill in the profile row */
.lm-fav-count{margin-inline-start:auto;min-width:20px;height:20px;padding:0 6px;border-radius:100px;
    background:var(--lm-accent-bg);color:var(--lm-accent-2);font-size:11px;font-weight:700;font-style:normal;
    display:inline-flex;align-items:center;justify-content:center}
.lm-fav-count[hidden]{display:none}

/* ---------- notification centre ---------- */
/* Hidden proxy bell the shared mb-notify.js engine binds; kept in the layout
   (not display:none) so it can still receive programmatic focus/click. */
.lm-nb-proxy{position:fixed;top:0;inset-inline-start:0;width:1px;height:1px;padding:0;margin:-1px;
    overflow:hidden;opacity:0;pointer-events:none;border:0}
/* Unread dot on each visible bell. */
.lm-bellbtn{position:relative}
.lm-bell-dot{position:absolute;top:7px;inset-inline-end:8px;width:9px;height:9px;border-radius:50%;
    background:var(--lm-sale);border:2px solid var(--lm-card);box-sizing:content-box}
.lm-bell-dot[hidden]{display:none}
/* Skin the shared panel in lumiere's palette (only sets --nb-* custom props). */
.mb-nb-card{--nb-surface:var(--lm-card);--nb-surface-2:var(--lm-accent-bg);--nb-ink:var(--lm-ink);
    --nb-muted:var(--lm-muted);--nb-line:var(--lm-hair);--nb-accent:var(--lm-accent);--nb-radius:22px}
/* Shared auth sheet (mb-auth-sheet) skinned to lumiere. --lm-* live on :root, so
   they resolve for the body-level fixed sheet too. */
.lm-order-refund{display:inline-flex;align-items:center;font-size:11.5px;font-weight:700;padding:5px 11px;border-radius:100px}
.lm-order-refund--requested{background:var(--lm-accent-bg);color:var(--lm-accent-2)}
.lm-order-refund--approved{background:rgba(22,163,74,.12);color:#16a34a}
.lm-order-refund--rejected{background:rgba(220,38,38,.1);color:#dc2626}
.mba-sheet{--mba-surface:var(--lm-card);--mba-ink:var(--lm-ink);--mba-muted:var(--lm-muted);
    --mba-border:var(--lm-hair-2);--mba-hair:var(--lm-hair);--mba-in-bg:var(--lm-card);
    --mba-accent:var(--lm-accent);--mba-on-accent:#fff;--mba-accent-ring:var(--lm-accent-bg);--mba-radius:22px}

/* ---------- address map picker + clear-saved-data (shared engines, lumiere-skinned) ---------- */
/* "Choose on map" trigger — a soft accent link under the delivery-address input. */
.lm-maplink{display:inline-flex;align-items:center;gap:7px;margin-top:10px;padding:0;border:0;background:none;
    font:inherit;font-size:13px;font-weight:600;letter-spacing:.01em;color:var(--lm-accent-2);cursor:pointer}
.lm-maplink svg{width:16px;height:16px}
.lm-maplink:hover{text-decoration:underline;text-underline-offset:3px}
/* Map sheet — lumiere-native bottom sheet (rounded top, soft shadow) + own scrim. */
.lm-scrim{position:fixed;inset:0;z-index:95;background:rgba(28,18,44,.5);opacity:0;transition:opacity .28s ease;
    -webkit-backdrop-filter:blur(2px);backdrop-filter:blur(2px)}
.lm-scrim.is-open{opacity:1}
.lm-msheet{position:fixed;inset-inline:0;bottom:0;z-index:96;margin:0 auto;max-width:560px;display:flex;flex-direction:column;
    max-height:88vh;background:var(--lm-card);color:var(--lm-ink);border-radius:var(--lm-radius-lg) var(--lm-radius-lg) 0 0;
    box-shadow:var(--lm-shadow);transform:translateY(100%);transition:transform .3s cubic-bezier(.22,.61,.36,1)}
.lm-msheet.is-open{transform:translateY(0)}
.lm-msheet__head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:18px 18px 12px;position:relative}
.lm-msheet__head::before{content:"";position:absolute;top:7px;left:50%;transform:translateX(-50%);width:38px;height:4px;
    border-radius:100px;background:var(--lm-hair-2)}
.lm-msheet__title{font-family:var(--lm-font);font-size:16px;font-weight:600;letter-spacing:-.01em;outline:none}
.lm-msheet__body{display:flex;flex-direction:column;overflow:hidden}
.lm-map-canvas{position:relative;height:min(52vh,360px);background:var(--lm-canvas)}
.lm-map-canvas .leaflet-container{width:100%;height:100%;font:inherit;background:var(--lm-canvas)}
.lm-map-preview{margin:0;padding:13px 18px;font-size:13px;line-height:1.45;color:var(--lm-muted);
    border-top:1px solid var(--lm-hair);text-align:center;min-height:20px}
.lm-msheet__foot{padding:14px 18px calc(14px + env(safe-area-inset-bottom))}
/* Teardrop pin dropped into the map centre (engine injects .mb-map-pin; theme skins it). */
.mb-map-pin{position:absolute;left:50%;top:50%;width:28px;height:36px;transform:translate(-50%,-100%);
    z-index:500;pointer-events:none;filter:drop-shadow(0 4px 6px rgba(90,60,140,.4))}
.mb-map-pin::before{content:"";position:absolute;left:50%;top:0;transform:translateX(-50%) rotate(45deg);
    width:24px;height:24px;border-radius:50% 50% 50% 0;background:var(--lm-accent);border:2px solid var(--lm-card)}
.mb-map-pin::after{content:"";position:absolute;left:50%;top:7px;transform:translateX(-50%);width:8px;height:8px;
    border-radius:50%;background:var(--lm-card)}
/* Clear-saved-data account row — danger-tinted icon/label + a muted hint. */
.lm-accrow--danger .lm-at{color:var(--lm-sale)}
.lm-accrow--danger .lm-aico{background:var(--lm-accent-bg)}
.lm-accrow--danger .lm-aico svg{color:var(--lm-sale)}
.lm-cleardata-hint{margin:10px 4px 0;font-size:12px;line-height:1.45;color:var(--lm-muted)}
</style>
