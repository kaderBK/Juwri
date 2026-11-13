{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- majlis_gold thumbnail — "بيت المندي" Arabic RTL luxury: emerald & gold, Amiri display,
     geometric lattice ground, gold-bordered link cards. Inline-styled mini-mockup
     (STYLE_ATTR policy-allowed). Mirrors the .d6 artifact design. --}}
<div class="bio-thumb bio-thumb--majlis_gold" data-preset="majlis_gold" data-tier="basic" role="img" aria-label="Majlis Gold" dir="rtl" lang="ar"
     style="display:block;width:100%;max-width:150px;aspect-ratio:118/168;margin:0 auto;border-radius:16px;overflow:hidden;box-sizing:border-box;
     position:relative;background:#0d3229;color:#f3ecd9;font-family:'Tajawal',system-ui,sans-serif;
     background-image:radial-gradient(circle at 50% 50%,transparent 5px,rgba(212,175,55,.13) 5.5px,transparent 6.5px),radial-gradient(200px 150px at 50% 0%,rgba(212,175,55,.18),transparent 70%);
     background-size:26px 26px,100% 100%;padding:14px 12px 0;text-align:center">
    <div style="width:36px;height:36px;margin:0 auto 8px;border-radius:50%;display:grid;place-items:center;background:#0a2820;border:1.5px solid #d4af37;box-shadow:0 0 0 4px rgba(212,175,55,.14),0 0 22px rgba(212,175,55,.2);font-family:'Amiri',serif;font-size:17px;color:#d4af37">م</div>
    <div style="font-family:'Amiri','Tajawal',serif;font-weight:700;font-size:17px;color:#f7f0dd;margin-bottom:2px">بيت المندي</div>
    <div style="font-size:7px;color:#c9bf9f;margin-bottom:8px">مندي وحنيذ حضرمي · الرياض</div>
    <div style="display:flex;align-items:center;gap:8px;margin:0 auto 11px;color:#d4af37">
        <span style="flex:1;height:1px;background:linear-gradient(90deg,transparent,rgba(212,175,55,.6),transparent)"></span>
        <span style="font-size:9px">&#10054;</span>
        <span style="flex:1;height:1px;background:linear-gradient(90deg,transparent,rgba(212,175,55,.6),transparent)"></span>
    </div>
    @foreach (['قائمة الطعام'=>'مندي · مظبي · حنيذ','اطلب توصيل'=>'خلال ٤٥ دقيقة'] as $b=>$s)
        <div style="display:flex;align-items:center;gap:9px;margin-bottom:10px;padding:8px 10px;text-align:right;border-radius:11px;background:rgba(212,175,55,.06);border:1px solid rgba(212,175,55,.34)">
            <span style="width:20px;height:20px;flex:none;display:grid;place-items:center;border-radius:7px;background:rgba(212,175,55,.12);border:1px solid rgba(212,175,55,.4)"></span>
            <span><b style="display:block;font-size:9.5px;font-weight:800">{{ $b }}</b><span style="font-size:6.5px;color:#c9bf9f">{{ $s }}</span></span>
            <span style="margin-right:auto;color:#d4af37;font-size:11px;transform:scaleX(-1)">&rarr;</span>
        </div>
    @endforeach
    <div style="display:flex;gap:6px;margin-top:2px">
        @foreach (['الغداء'=>'١٢–٤','العشاء'=>'٦–١١:٣٠'] as $b=>$h)
            <div style="flex:1;border:1px solid rgba(212,175,55,.25);border-radius:9px;padding:6px 3px;background:rgba(212,175,55,.04)">
                <b style="display:block;font-size:7px;color:#d4af37;margin-bottom:1px">{{ $b }}</b>
                <span style="font-size:7px;color:#e9dfc2">{{ $h }}</span>
            </div>
        @endforeach
    </div>
</div>
