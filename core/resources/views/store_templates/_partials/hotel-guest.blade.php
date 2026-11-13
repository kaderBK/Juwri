{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    T1 · H8 — Hotel guest-facing layer (shared across every post_template that
    extends layout.blade.php). Renders ONLY when:
      • hotel_mode is enabled for the restaurant, AND
      • the visitor arrived via a room QR (?table=<key>) that resolves to an
        area_type='room' StoreTable row.

    Two pieces, both theme-neutral (inherit palette via --brand / --theme-color-*):
      1. A room greeting banner ("Room 204 · Welcome[, guest]"), dismissible,
         shown once per session.
      2. A "Charge to room" tender affordance — only when an active stay exists on
         the room. Toggling it writes a hidden `tender=charge_to_room` field (plus
         the resolved `room` key); the shared order-field-forwarder carries both to
         /send-order, where eligibility is re-validated server-side (H6/H8).

    Zero DOM/behaviour impact for non-hotel stores (the outer guard renders nothing).
--}}
@php
    /* Security (J52b review): the room is the one the page controller already resolved through the GUARDED,
       query-only, key-only lookup (StoreController::scannedTable + TableKeyGuard), passed as $table. This partial
       never runs its own query and never matches a room by numeric id from the URL (that let `?table=1,2,3…` walk
       every room). The current stay's requests need the visitor's stashed room token (`rt` from the printed room
       QR) to verify for this room. The guest's name (also as a prefill) and the folio link need MORE (SF2): the
       printed room token is permanent and every past guest holds it, so they show only when the visitor also holds
       the CURRENT stay's token (stashed when the guest opens their folio link). */
    $__hgRoom = null;
    $__hgStay = null;
    $__hgEnabled = false;
    $__hgVerified = false;
    $__hgStayVerified = false;
    try {
        if (isset($store) && $store && isset($table) && $table instanceof \Illuminate\Database\Eloquent\Model
            && $table->getAttribute('area_type') === 'room'
            && (int) $table->getAttribute('store_id') === (int) $store->id
            && \app(\Juwri\Core\Contracts\Hotel\RoomCharge::class)->enabled($store->id)) {
            $__hgRoom = $table;
            $__hgEnabled = true;
            $__hgStay = \app(\Juwri\Core\Contracts\Hotel\RoomCharge::class)->activeStayForRoom((int) $store->id, (int) $__hgRoom->id);
            // Computed ONCE by the page controller (StoreController::roomTokenVerified) for every theme.
            $__hgVerified = ($roomVerified ?? false) === true;
            $__hgStayVerified = $__hgVerified && ($guestStayVerified ?? false) === true;
        }
    } catch (\Throwable $e) {
        $__hgEnabled = false;
    }
@endphp
@if($__hgEnabled)
@php
    $__hgLabel   = $__hgRoom->label ?: (string) $__hgRoom->table_no;
    $__hgKeyOut  = (string) $__hgRoom->key; // the key the visitor scanned, never the row id
    $__hgGuest   = ($__hgStay && $__hgStayVerified) ? trim((string) $__hgStay->guest_name) : '';

    /* C6 — the room's recent service requests, shown as status chips so a guest sees
       progress ("Received / In progress / Done") on re-scan. Scoped to this room, a
       handful, newest first. Guarded — a request query must never break the menu page. */
    $__hgRequests = collect();
    if ($__hgVerified) { // the current stay's requests are shown to a verified room only
        try {
            $__hgRequests = \app(\Juwri\Core\Contracts\Hotel\RoomCharge::class)->serviceRequests((int) $store->id, (int) $__hgRoom->id, 6);
        } catch (\Throwable $e) {
        }
    }
    $__hgReqTypeLabels = [
        'housekeeping' => ___('Housekeeping'),
        'amenity'      => ___('Extra towels / amenities'),
        'maintenance'  => ___('Maintenance'),
        'staff_call'   => ___('Call staff'),
        'custom'       => ___('Request'),
    ];
    $__hgReqStatusLabels = [
        'open'        => ___('Received'),
        'in_progress' => ___('In progress'),
        'done'        => ___('Done'),
    ];
    $__hgReqJs = ['title' => ___('Your requests'), 'types' => $__hgReqTypeLabels, 'statuses' => $__hgReqStatusLabels];

    /* WIRING-RUN D1 (spec H4) — scheduled room service / breakfast pre-order.
       Every slot below comes from SchedulingService::slotsFor(), the same engine the
       /schedule-slots endpoint and checkout validation use — no time maths is
       re-implemented here. The picker only renders when the owner enabled scheduling
       for the room_service type. */
    $__hgSched       = app(\Juwri\Modules\Ordering\Services\Orders\SchedulingService::class);
    $__hgSchedOn     = false;
    $__hgDates       = [];
    $__hgDateLabels  = [];
    $__hgSlots       = [];
    $__hgDate        = null;
    $__hgBranchId    = 0;
    $__hgBfDate      = null;   // first day whose breakfast window is still bookable
    $__hgBfSlots     = [];     // its in-window slot values ('Y-m-d H:i')
    $__hgBfLate      = false;  // an earlier window was already past its cutoff
    $__hgBfFull      = false;  // C9: an earlier window was fully booked (distinct from late)
    $__hgBfHours     = '';
    try {
        if ($__hgSched->enabled((int) $store->id, 'room_service')) {
            /* Branch = the one on the QR/room, so hours + capacity match the order. */
            $__hgBid = (int) (request('branch_id') ?: $__hgRoom->branch_id);
            // P9: hoisted to the memoized storefront context (store-scoped branch lookup).
            $__hgBranch = storefront()->hotelBranch($__hgBid, $store);
            $__hgBranchId = $__hgBranch ? (int) $__hgBranch->id : 0;
            $__hgTz  = $__hgSched->timezone((int) $store->id, $__hgBranch);
            $__hgNow = \Carbon\Carbon::now($__hgTz);

            /* Same day list StoreController@scheduleSlots builds: every day inside the
               horizon that still has at least one bookable slot. */
            $__hgDayCache = [];
            for ($__hgI = 0; $__hgI <= $__hgSched->maxDays((int) $store->id); $__hgI++) {
                $__hgD = $__hgNow->copy()->addDays($__hgI)->format('Y-m-d');
                $__hgS = $__hgSched->slotsFor((int) $store->id, $__hgBranch, 'room_service', $__hgD);
                if (!empty($__hgS)) {
                    $__hgDates[] = $__hgD;
                    $__hgDayCache[$__hgD] = $__hgS;
                    $__hgC = \Carbon\Carbon::parse($__hgD, $__hgTz);
                    $__hgDateLabels[$__hgD] = $__hgC->isSameDay($__hgNow)
                        ? ___('Today')
                        : ($__hgC->isSameDay($__hgNow->copy()->addDay())
                            ? ___('Tomorrow')
                            : $__hgC->isoFormat('ddd D MMM'));
                }
            }
            $__hgSchedOn = !empty($__hgDates);
            $__hgDate    = $__hgDates[0] ?? null;
            $__hgSlots   = $__hgDate ? $__hgDayCache[$__hgDate] : [];

            /* Breakfast pre-order. hotel_settings.breakfast.{hours,cutoff} are free
               text the owner types ("07:00 – 10:30" / "22:00"), so parse defensively
               and simply skip the feature when they are not time-shaped. */
            $__hgBf      = \app(\Juwri\Core\Contracts\Hotel\RoomCharge::class)->settings((int) $store->id)['breakfast'] ?? [];
            $__hgBfHours = trim((string) ($__hgBf['hours'] ?? ''));
            $__hgCut     = null;
            if (preg_match('/(\d{1,2}):(\d{2})/', (string) ($__hgBf['cutoff'] ?? ''), $__hgCm)) {
                $__hgCut = sprintf('%02d:%02d', (int) $__hgCm[1], (int) $__hgCm[2]);
            }
            if (preg_match_all('/(\d{1,2}):(\d{2})/', $__hgBfHours, $__hgHm) && count($__hgHm[0]) >= 2) {
                $__hgFrom = sprintf('%02d:%02d', (int) $__hgHm[1][0], (int) $__hgHm[2][0]);
                $__hgTo   = sprintf('%02d:%02d', (int) $__hgHm[1][1], (int) $__hgHm[2][1]);
                foreach ($__hgDates as $__hgD) {
                    /* A cutoff later than the window itself is the "order the night
                       before" kind, so day D's deadline falls on D-1; an earlier one
                       (e.g. 09:00 for a 07:00–10:30 window) belongs to D itself. */
                    $__hgDeadDay = ($__hgCut !== null && $__hgCut > $__hgTo)
                        ? \Carbon\Carbon::parse($__hgD, $__hgTz)->subDay()
                        : \Carbon\Carbon::parse($__hgD, $__hgTz);
                    $__hgDead = $__hgCut !== null ? $__hgDeadDay->setTimeFromTimeString($__hgCut) : null;

                    $__hgWin = [];
                    foreach ($__hgDayCache[$__hgD] as $__hgS) {
                        if ($__hgS['label'] >= $__hgFrom && $__hgS['label'] <= $__hgTo && empty($__hgS['full'])) {
                            $__hgWin[] = $__hgS['at']->format('Y-m-d H:i');
                        }
                    }
                    /* C9 — separate the two skip reasons so a fully-booked morning is not
                       reported as "cutoff has passed". The cutoff is checked first: once it
                       is past, that is why the day is closed regardless of capacity. */
                    if ($__hgDead !== null && $__hgNow->gt($__hgDead)) {
                        $__hgBfLate = true;   // cutoff passed → roll forward
                        continue;
                    }
                    if (empty($__hgWin)) {
                        $__hgBfFull = true;   // all in-window slots full → roll forward
                        continue;
                    }
                    $__hgBfDate  = $__hgD;
                    $__hgBfSlots = $__hgWin;
                    break;
                }
            }
        }
    } catch (\Throwable $e) {
        $__hgSchedOn = false;
    }

    /* Pre-built script payloads — @json takes a single-line argument only. */
    $__hgSlotsUrl = $__hgSchedOn ? route('store.scheduleSlots', $store->slug) : '';
    $__hgBfJs = ['date' => $__hgBfDate, 'slots' => $__hgBfSlots, 'late' => $__hgBfLate, 'full' => $__hgBfFull, 'hours' => $__hgBfHours];
@endphp

<style nonce="{{ csp_nonce() }}">
    /* ── Token contract (SPEC 2.4) ──────────────────────────────
       Every layer style below consumes these vars; a per-theme skin (in the
       theme's OWN stylesheet, scoped to the layer ids) remaps them onto the
       theme's real tokens. Neutral defaults reproduce the pre-skin look
       (translucent brand tint, brand-fill CTAs) so an unskinned or legacy theme
       stays safe. No opaque background of our own: --mbh-surface is a
       translucent tint until a skin sets a solid surface, and ink is adopted
       from --mb-ink after placement (mbAnchor.ink). Do NOT source these from the
       customizer --tc-* layer — it is emitted only when a template is set and
       customized; the theme-local mapping happens in each skin. */
    #mbHotelChip, #mbHotelOrderCtrls, #mbHotelHub{
        --mbh-accent:    var(--brand, var(--theme-color-1, #2563eb));
        --mbh-on-accent: #ffffff;
        --mbh-ink:       var(--mb-ink, var(--theme-text, inherit));
        --mbh-muted:     var(--mbh-ink);
        --mbh-surface:   color-mix(in srgb, var(--mbh-accent) 12%, transparent);
        --mbh-border:    color-mix(in srgb, var(--mbh-accent) 35%, transparent);
        --mbh-radius:    14px;
        --mbh-font:      var(--menu-font, inherit);
    }
    .mb-hotel-greet{
        position:relative;margin:10px auto 0;max-width:640px;
        display:flex;align-items:center;gap:12px;
        padding:12px 16px;border-radius:var(--mbh-radius);
        background:var(--mbh-surface);
        border:1px solid var(--mbh-border);
        color:var(--mbh-ink);font-family:var(--mbh-font);
    }
    .mb-hotel-greet__ic{flex:0 0 auto;width:34px;height:34px;border-radius:10px;
        display:flex;align-items:center;justify-content:center;
        background:var(--mbh-accent);color:var(--mbh-on-accent);font-size:18px;line-height:1;}
    .mb-hotel-greet__txt{flex:1 1 auto;min-width:0;}
    .mb-hotel-greet__room{font-weight:800;font-size:.95rem;}
    .mb-hotel-greet__welcome{color:var(--mbh-muted);opacity:.8;font-size:.82rem;}

    /* Charge-to-room card grows a name field when the toggle is on. Column layout so
       the field sits UNDER the toggle row (the base .mb-hotel-greet is a flex row). */
    .mb-hotel-greet--charge{flex-direction:column;align-items:stretch;gap:10px;}
    .mb-hotel-charge__row{display:flex;align-items:center;gap:12px;}
    .mb-hotel-charge__name{display:flex;flex-direction:column;gap:5px;}
    .mb-hotel-charge__name>label{font-weight:700;font-size:.82rem;}
    .mb-hotel-charge__name input{width:100%;box-sizing:border-box;padding:9px 12px;
        border-radius:10px;border:1px solid var(--mbh-border);
        background:var(--mbh-surface);color:var(--mbh-ink);font:inherit;}
    .mb-hotel-charge__name input:focus{outline:2px solid var(--mbh-accent);outline-offset:1px;}
    .mb-hotel-charge__hint{color:var(--mbh-muted);opacity:.8;font-size:.76rem;line-height:1.4;}

    /* Room chip (SPEC 2.1) — the compact entry surface; a per-theme skin restyles it into
       the theme's header/brand row. Neutral pill on the token contract. Logical props for
       RTL; guest name appears only on wider viewports. */
    #mbHotelChip{
        display:flex;align-items:center;gap:8px;
        inline-size:max-content;max-inline-size:100%;margin:10px auto 0;
        padding:7px 12px;border-radius:999px;text-decoration:none;cursor:pointer;
        background:var(--mbh-surface);border:1px solid var(--mbh-border);
        color:var(--mbh-ink);font-family:var(--mbh-font);
        font-size:.85rem;font-weight:700;line-height:1.2;
    }
    #mbHotelChip .mb-hotel-roomchip__ic{flex:0 0 auto;display:inline-flex;align-items:center;justify-content:center;color:var(--mbh-accent);}
    #mbHotelChip .mb-hotel-roomchip__ic svg{display:block;inline-size:17px;block-size:17px;}
    #mbHotelChip .mb-hotel-roomchip__room{white-space:nowrap;}
    #mbHotelChip .mb-hotel-roomchip__guest{
        display:none;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
        color:var(--mbh-muted);font-weight:500;
    }
    #mbHotelChip .mb-hotel-roomchip__guest::before{content:"·";margin-inline-end:6px;opacity:.6;}
    #mbHotelChip.mb-inflow{margin-block:8px;}
    /* Hidden until mbAnchor relocates it — into a theme's brand/meta row
       (.mb-slotted) or inline below it (.mb-inflow). Without this the chip paints
       at its in-body-flow position (centered, near the page foot) and then jumps
       up on settle, which reads as a flash on load. Same hidden-until-placed guard
       #mbHotelOrderCtrls and #mbHotelBook already carry. */
    #mbHotelChip:not(.mb-slotted):not(.mb-inflow){display:none;}
    @media (min-width: 560px){
        #mbHotelChip .mb-hotel-roomchip__guest{display:inline;}
    }
    /* Slotted into a theme's brand/meta row → break onto its own full-width row
       (consistent across every skin; matches the crave reference). The slot is a
       flex row, so allowing it to wrap + giving the chip a full flex-basis drops
       the chip onto the next line below the logo/name/follow controls. A theme skin
       still paints the pill's surface; this only governs its placement. */
    [data-mb-hotel-chip-slot]{flex-wrap:wrap;}
    #mbHotelChip.mb-slotted{
        flex:1 1 100%;inline-size:100%;max-inline-size:none;
        justify-content:flex-start;margin:10px 0 0;
    }

    /* D1 scheduling picker + D2 services sheet. Logical properties throughout so the
       card mirrors correctly inside RTL themes. Same surface contract as the greet. */
    .mb-hotel-card{
        position:relative;margin:8px auto 0;max-width:640px;
        padding:12px 16px;border-radius:var(--mbh-radius);text-align:start;
        background:var(--mbh-surface);
        border:1px solid var(--mbh-border);
        color:var(--mbh-ink);font-family:var(--mbh-font);
    }
    .mb-hotel-card__t{font-weight:800;font-size:.95rem;display:block;}
    .mb-hotel-card__h{color:var(--mbh-muted);opacity:.8;font-size:.82rem;display:block;margin-block-start:2px;}
    .mb-hotel-card summary{cursor:pointer;list-style:none;font-weight:800;font-size:.95rem;}
    .mb-hotel-card summary::-webkit-details-marker{display:none;}
    .mb-hotel-modes{display:flex;flex-wrap:wrap;gap:12px;margin-block-start:8px;font-size:.85rem;}
    .mb-hotel-modes label{display:flex;align-items:center;gap:6px;cursor:pointer;margin:0;font-weight:500;}
    .mb-hotel-pick{display:flex;flex-wrap:wrap;gap:8px;margin-block-start:8px;}
    .mb-hotel-field{
        flex:1 1 140px;min-width:0;padding:9px 10px;font:inherit;font-size:.88rem;
        color:inherit;background:var(--theme-bg, #fff);
        border:1px solid color-mix(in srgb, currentColor 22%, transparent);border-radius:10px;
    }
    .mb-hotel-btn{
        appearance:none;cursor:pointer;font:inherit;font-size:.88rem;font-weight:700;
        padding:9px 18px;border:0;border-radius:10px;margin-block-start:8px;
        background:var(--mbh-accent);color:var(--mbh-on-accent);
    }
    .mb-hotel-note{display:block;margin-block-start:6px;font-size:.8rem;opacity:.85;}
    /* Footer links as tappable list rows (icon · label · chevron) rather than bare
       underlined text — a clearer, more native affordance inside the sheet. */
    .mb-hotel-info-link{
        display:flex;align-items:center;gap:12px;margin:0;
        padding:11px 13px;border-radius:12px;text-decoration:none;
        background:rgba(128,128,128,.08);border:1px solid var(--mbh-border);
        color:var(--mbh-ink);font-size:.9rem;font-weight:600;line-height:1.2;
        transition:background .15s ease, border-color .15s ease, transform .08s ease;
    }
    .mb-hotel-info-link:hover{background:rgba(128,128,128,.14);border-color:var(--mbh-accent);}
    .mb-hotel-info-link:active{transform:scale(.99);}
    .mb-hotel-info-link__ic{flex:0 0 auto;display:inline-flex;align-items:center;color:var(--mbh-accent);}
    .mb-hotel-info-link__ic svg{display:block;inline-size:20px;block-size:20px;}
    .mb-hotel-info-link__t{flex:1 1 auto;min-inline-size:0;}
    .mb-hotel-info-link__go{flex:0 0 auto;display:inline-flex;align-items:center;color:var(--mbh-muted);opacity:.75;}
    .mb-hotel-info-link__go svg{display:block;inline-size:16px;block-size:16px;}
    [dir="rtl"] .mb-hotel-info-link__go{transform:scaleX(-1);}
    /* Once placed in-flow (placeTop/placeCheckout) the cards behave as full-width
       content blocks; this marker lets a theme opt into extra spacing if needed. */
    #mbHotelOrderCtrls.mb-inflow{margin-block:8px;}
    /* Hidden until placeCheckout actually lands it inside the checkout. Unplaced it
       sits at its server-rendered spot in body flow, where full-width themes (feast,
       velvet) painted it as a stray "Charge to my room" banner at the top of the home
       page. A checkout control belongs in the checkout — nowhere else. */
    #mbHotelOrderCtrls:not(.mb-inflow){display:none;}
    #mbHotelOrderCtrls #mbHotelSched{margin-block-start:8px;}
    /* C6 — service-request status chips. Colour by status via currentColor so the
       dot + tint both track it; no opaque fill of our own. */
    .mb-hotel-chips{display:flex;flex-wrap:wrap;gap:6px;margin-block-start:8px;}
    .mb-hotel-chip{
        display:inline-flex;align-items:center;gap:6px;font-size:.78rem;font-weight:600;
        padding:4px 10px;border-radius:999px;
        background:color-mix(in srgb, currentColor 12%, transparent);
    }
    .mb-hotel-chip::before{content:"";width:7px;height:7px;border-radius:50%;background:currentColor;opacity:.75;}
    .mb-hotel-chip--open{color:#b45309;}
    .mb-hotel-chip--in_progress{color:#2563eb;}
    .mb-hotel-chip--done{color:#137a3a;}

    /* ── Hotel hub sheet (SPEC 2.2) ─────────────────────────────
       A modal sheet, so it DOES paint a solid surface (the "no opaque background"
       rule is for the injected strips, not a dialog). Neutral surface + ink follow
       the OS scheme so an unskinned/legacy theme reads cleanly; a per-theme skin
       (R0.6 + lanes) overrides --mbh-surface/-ink and the sheet geometry. Logical
       properties throughout for RTL. Placed inside the theme root by the script so
       the token vars resolve; a fixed overlay is not clipped by the root. */
    #mbHotelHub{
        --mbh-surface:#ffffff;
        --mbh-ink:#14181d;
        --mbh-muted:color-mix(in srgb, var(--mbh-ink) 62%, transparent);
        --mbh-border:color-mix(in srgb, var(--mbh-ink) 12%, transparent);
    }
    @media (prefers-color-scheme: dark){
        #mbHotelHub{ --mbh-surface:#1c1f23; --mbh-ink:#f4f6f5; }
    }
    #mbHotelHubScrim{
        position:fixed;inset:0;z-index:99998;
        background:rgba(6,8,12,.5);opacity:0;transition:opacity .22s ease;
    }
    #mbHotelHubScrim.is-open{opacity:1;}
    #mbHotelHub{
        position:fixed;inset-inline:0;inset-block-end:0;z-index:99999;
        margin-inline:auto;max-inline-size:640px;max-block-size:86dvh;
        display:flex;flex-direction:column;
        background:var(--mbh-surface);color:var(--mbh-ink);font-family:var(--mbh-font);
        border-start-start-radius:20px;border-start-end-radius:20px;
        box-shadow:0 -8px 40px rgba(0,0,0,.28);
        transform:translateY(100%);transition:transform .28s cubic-bezier(.32,.72,.24,1);
    }
    #mbHotelHub.is-open{transform:none;}
    /* Framed hub — confined to a narrow "phone-frame" theme root (the relocation
       script adds .mb-hub-framed when the theme renders as a positioned frame
       narrower than the viewport, e.g. crave/matcha on desktop). Anchoring to the
       frame instead of the viewport keeps the sheet from spilling past the frame
       edges; the base insets/max-width already size it to the frame. On mobile or
       full-width themes the class is absent, so the sheet stays viewport-fixed. */
    #mbHotelHubScrim.mb-hub-framed{position:absolute;}
    #mbHotelHub.mb-hub-framed{position:absolute;}
    #mbHotelHub .mb-hotel-hub__grab{
        flex:0 0 auto;inline-size:42px;block-size:5px;border-radius:999px;
        background:color-mix(in srgb, currentColor 22%, transparent);
        margin:8px auto 2px;
    }
    #mbHotelHub .mb-hotel-hub__head{
        flex:0 0 auto;display:flex;align-items:center;gap:12px;
        padding:6px 18px 10px;
    }
    #mbHotelHub .mb-hotel-hub__title{
        flex:1 1 auto;min-inline-size:0;margin:0;font-size:1.05rem;font-weight:800;
    }
    #mbHotelHub .mb-hotel-hub__x{
        flex:0 0 auto;appearance:none;background:transparent;border:0;cursor:pointer;
        color:inherit;opacity:.7;font-size:1.5rem;line-height:1;padding:4px 8px;border-radius:10px;
    }
    #mbHotelHub .mb-hotel-hub__x:hover{opacity:1;}
    #mbHotelHub .mb-hotel-hub__body{
        flex:1 1 auto;min-block-size:0;overflow-y:auto;
        padding:0 18px calc(18px + env(safe-area-inset-bottom, 0px));
        text-align:start;
    }
    #mbHotelHub .mb-hotel-hub__greet{margin:0 0 8px;color:var(--mbh-muted);font-size:.9rem;}
    /* Inside the solid sheet the cards drop their own tint — they sit ON the surface. */
    #mbHotelHub .mb-hotel-card{
        margin-block-start:8px;background:transparent;
        border:1px solid var(--mbh-border);
    }
    #mbHotelHub .mb-hotel-hub__links{
        display:flex;flex-direction:column;gap:8px;margin-block-start:14px;
    }
</style>

{{-- Hidden fields ride along on the /send-order POST via order-field-forwarder.
     They MUST stay in body flow where server-rendered: the forwarder resolves each
     by the FIRST [name=] match, and the visible cards below are relocated by JS
     (placeTop / placeCheckout) out of this flow to escape the app-shell themes' fixed
     panes. `room` always carries the resolved room key so a room_service order knows
     its room even if the theme added no room picker; `tender` is toggled by the
     charge-to-room control; `scheduled_at` (mbHotelSchedAt) is named only while a slot
     is actually picked so an empty input never shadows a theme's own scheduled_at. --}}
<input type="hidden" name="room" id="mbHotelRoomKey" value="{{ $__hgKeyOut }}">
<input type="hidden" name="tender" id="mbHotelTender" value="">
@if($__hgSchedOn)
<input type="hidden" id="mbHotelSchedAt" value="">
@endif

{{-- C1 / SPEC 2.1 — room chip, the single entry surface. Server-rendered as an <a> to
     the guest-info page so it works with NO JS (QR-web, no-download model preserved); the
     script below upgrades the click to open the in-theme hotel hub (#mbHotelHub, R0.4).
     Relocated into the theme header via mbAnchor.placeTop() — per-theme header slotting is
     a lane job; the L0 default is the placeTop position. No opaque background of our own:
     the chip keeps a translucent tint and adopts readable ink from mbAnchor.ink() once
     placed. The greeting that used to live in a dismissible banner now moves into the hub. --}}
<a class="mb-hotel-roomchip" id="mbHotelChip"
   href="{{ route('hotel.guest.info', $store->slug) }}@if(!empty($__hgRoom->key))?table={{ $__hgRoom->key }}@endif">
    <span class="mb-hotel-roomchip__ic" aria-hidden="true"><svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 4v16"/><path d="M2 9h18a2 2 0 0 1 2 2v9"/><path d="M2 15h20"/><path d="M6 9V7a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg></span>
    <span class="mb-hotel-roomchip__room">{{ ___('Room') }} {{ $__hgLabel }}</span>
    @if($__hgGuest !== '')
        <span class="mb-hotel-roomchip__guest">{{ $__hgGuest }}</span>
    @endif
</a>

{{-- SPEC 2.2 — hotel hub. A shared sheet skinned per theme (R0.6 + lanes), opened by the
     chip's JS upgrade. The a11y controller in the script below moves focus in and traps it,
     closes on Esc / backdrop / the visible close button, and marks the panel role=dialog +
     aria-modal, labelled by its title. A modal legitimately paints a SOLID surface (unlike
     the injected strips), so the neutral skin follows the OS colour scheme to stay readable
     on an unskinned or legacy theme. Contents in SPEC order: greeting, request chips (C6),
     services form (D2, same endpoint + inline-submit upgrade + no-JS fallback), guest-info
     link, folio link when an active stay token exists. --}}
<div class="mb-hotel-hub-scrim" id="mbHotelHubScrim" hidden></div>
<div class="mb-hotel-hub" id="mbHotelHub" role="dialog" aria-modal="true" aria-labelledby="mbHotelHubTitle" hidden>
    <div class="mb-hotel-hub__grab" aria-hidden="true"></div>
    <div class="mb-hotel-hub__head">
        <h2 class="mb-hotel-hub__title" id="mbHotelHubTitle">{{ ___('Room') }} {{ $__hgLabel }}</h2>
        <button type="button" class="mb-hotel-hub__x" id="mbHotelHubClose" aria-label="{{ ___('Close') }}">&times;</button>
    </div>
    <div class="mb-hotel-hub__body" id="mbHotelHubBody">
        <p class="mb-hotel-hub__greet">@if($__hgGuest !== ''){{ ___('Welcome') }}, {{ $__hgGuest }}@else{{ ___('Welcome') }}@endif</p>

        {{-- C6 — the room's open/recent service requests with status chips, so the guest sees
             progress on re-scan. Present whenever any request exists; the services form's JS
             prepends a "Received" chip for a freshly submitted one. --}}
        @if($__hgRequests->isNotEmpty())
        <div class="mb-hotel-card" id="mbHotelRequests">
            <span class="mb-hotel-card__t">{{ ___('Your requests') }}</span>
            <div class="mb-hotel-chips" id="mbHotelReqList">
                @foreach($__hgRequests as $__hgReq)
                    <span class="mb-hotel-chip mb-hotel-chip--{{ $__hgReq->status }}">{{ $__hgReqTypeLabels[$__hgReq->type] ?? ___('Request') }} · {{ $__hgReqStatusLabels[$__hgReq->status] ?? $__hgReq->status }}</span>
                @endforeach
            </div>
        </div>
        @endif

        {{-- D2 / H5 — services request form: same public endpoint (hotel.guest.request) as the
             standalone guest-info page. Plain form first (works with no JS); the script below
             upgrades it to an inline submit that adds a "Received" chip without a reload. --}}
        <details class="mb-hotel-card" id="mbHotelSvc" open>
            <summary>{{ ___('Hotel services') }}</summary>
            <span class="mb-hotel-card__h">{{ ___('Request housekeeping, extra towels, maintenance or a staff visit.') }}</span>
            <form method="post" action="{{ route('hotel.guest.request', $store->slug) }}" id="mbHotelSvcForm">
                @csrf
                @if(!empty($__hgRoom->key))
                    <input type="hidden" name="table" value="{{ $__hgRoom->key }}">
                @endif
                <div class="mb-hotel-pick">
                    <select class="mb-hotel-field" name="type" required aria-label="{{ ___('Request a service') }}">
                        <option value="housekeeping">{{ ___('Housekeeping') }}</option>
                        <option value="amenity">{{ ___('Extra towels / amenities') }}</option>
                        <option value="maintenance">{{ ___('Maintenance') }}</option>
                        <option value="staff_call">{{ ___('Call staff') }}</option>
                        <option value="custom">{{ ___('Something else') }}</option>
                    </select>
                </div>
                <div class="mb-hotel-pick">
                    <textarea class="mb-hotel-field" name="note" rows="2" placeholder="{{ ___('Add a note (optional)') }}"></textarea>
                </div>
                <button type="submit" class="mb-hotel-btn" id="mbHotelSvcSend">{{ ___('Send request') }}</button>
                <span class="mb-hotel-note" id="mbHotelSvcMsg" role="status" hidden></span>
            </form>
        </details>

        {{-- C5 guest-info link + C3 folio link (folio only when an active stay token exists). --}}
        <div class="mb-hotel-hub__links">
            <a class="mb-hotel-info-link" href="{{ route('hotel.guest.info', $store->slug) }}@if(!empty($__hgRoom->key))?table={{ $__hgRoom->key }}@endif">
                <span class="mb-hotel-info-link__ic" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.55a11 11 0 0 1 14 0"/><path d="M1.5 8.8a16 16 0 0 1 21 0"/><path d="M8.5 16.3a6 6 0 0 1 7 0"/><line x1="12" y1="20" x2="12.01" y2="20"/></svg></span>
                <span class="mb-hotel-info-link__t">{{ ___('Hotel information & Wi-Fi') }}</span>
                <span class="mb-hotel-info-link__go" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg></span>
            </a>
            @if($__hgStay && $__hgStayVerified && !empty($__hgStay->token))
                <a class="mb-hotel-info-link" href="{{ route('hotel.guest.folio', ['slug' => $store->slug, 'token' => $__hgStay->token]) }}">
                    <span class="mb-hotel-info-link__ic" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M5 3v18l2.5-1.5L10 21l2-1.5L14 21l2.5-1.5L19 21V3l-2.5 1.5L14 3l-2 1.5L10 3 7.5 4.5 5 3z"/><path d="M9 8h6"/><path d="M9 12h6"/></svg></span>
                    <span class="mb-hotel-info-link__t">{{ ___('View my room bill') }}</span>
                    <span class="mb-hotel-info-link__go" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg></span>
                </a>
            @endif
        </div>
    </div>
</div>

{{-- C1 / D1 — order-flow controls, grouped and relocated INTO the theme's checkout beside the
     tender/amount controls via mbAnchor.placeCheckout(). ONE wrapper, ONE placeCheckout call:
     two separate checkout placements would fight over the same insertion slot and flip-flop on
     every settle. The schedule picker configures WHEN a room-service order arrives; the
     charge-to-room toggle sets the tender. Both bind hidden fields (mbHotelSchedAt / tender)
     that stay in body flow by id, so relocation never breaks the forwarder contract. --}}
@if($__hgSchedOn || $__hgStay)
<div id="mbHotelOrderCtrls">

@if($__hgSchedOn)
{{-- D1 / H4 — "Deliver now vs schedule" for room service. The chosen slot is written
     to the hidden mbHotelSchedAt field above (kept in body flow), whose `name` is only
     set to scheduled_at while a slot is actually selected. --}}
<div class="mb-hotel-card" id="mbHotelSched">
    <span class="mb-hotel-card__t">{{ ___('When would you like this?') }}</span>
    <div class="mb-hotel-modes">
        <label><input type="radio" name="mb_hotel_when" value="now" checked> {{ ___('Deliver now') }}</label>
        <label><input type="radio" name="mb_hotel_when" value="later"> {{ ___('Schedule for later') }}</label>
        @if($__hgBfDate)
            <label><input type="radio" name="mb_hotel_when" value="breakfast"> {{ ___('Pre-order breakfast') }}</label>
        @endif
    </div>
    <div class="mb-hotel-pick" id="mbHotelSchedPick" hidden>
        <select class="mb-hotel-field" id="mbHotelSchedDate" aria-label="{{ ___('Delivery day') }}">
            @foreach($__hgDates as $__hgD)
                <option value="{{ $__hgD }}" @if($__hgD === $__hgDate) selected @endif>{{ $__hgDateLabels[$__hgD] ?? $__hgD }}</option>
            @endforeach
        </select>
        <select class="mb-hotel-field" id="mbHotelSchedTime" aria-label="{{ ___('Delivery time') }}">
            <option value="">{{ ___('Select a time') }}</option>
            @foreach($__hgSlots as $__hgS)
                <option value="{{ $__hgS['at']->format('Y-m-d H:i') }}" @if($__hgS['full']) disabled @endif>
                    {{ $__hgS['label'] }}@if($__hgS['full']) — {{ ___('Full') }}@endif
                </option>
            @endforeach
        </select>
    </div>
    <span class="mb-hotel-note" id="mbHotelSchedNote" hidden></span>
</div>
@endif

@if($__hgStay)
{{-- C1 — charge-to-room toggle. Sits in the checkout beside the tender/amount controls; the
     checkbox binds the hidden `tender` field (above, in body flow) by id, so relocation never
     breaks it. --}}
<div class="mb-hotel-greet mb-hotel-greet--charge" id="mbHotelCharge">
    <div class="mb-hotel-charge__row">
        <span class="mb-hotel-greet__ic" aria-hidden="true">&#128179;</span>
        <label class="mb-hotel-greet__txt" style="display:flex;align-items:center;gap:10px;cursor:pointer;margin:0;">
            <input type="checkbox" id="mbHotelChargeToggle" style="width:18px;height:18px;flex:0 0 auto;">
            <span>
                <span class="mb-hotel-greet__room">{{ ___('Charge to my room') }}</span>
                <span class="mb-hotel-greet__welcome">{{ ___('Add this order to your folio, settle at checkout.') }}</span>
            </span>
        </label>
    </div>
    {{-- Reservation-name field, revealed when the toggle is on. Prefilled with the
         stay's registered guest name ONLY when the visitor holds the current stay's token (SF2; otherwise
         empty: the guest types the full name) so charge-to-room "just works", while the hint tells a different
         guest to correct it. The server re-validates this name against the stay
         (guestNameMatches) — this field is UX, not the authorization itself. Kept
         `disabled` until the toggle is on so a native-<form> theme never submits it
         (and never leaks the guest name) on a non-charge order. --}}
    <div class="mb-hotel-charge__name" id="mbHotelChargeName" hidden>
        <label for="mbHotelChargeNameInput">{{ ___('Name on the reservation') }}</label>
        <input type="text" id="mbHotelChargeNameInput" name="charge_room_name"
               value="{{ $__hgGuest }}" maxlength="120" autocomplete="off" disabled
               placeholder="{{ ___('Name on the reservation') }}">
        <span class="mb-hotel-charge__hint">{{ ___('This must match the guest checked in to this room.') }}</span>
    </div>
</div>
@endif

</div>{{-- /#mbHotelOrderCtrls --}}
@endif

<script nonce="{{ csp_nonce() }}">
(function(){
    /* charge-to-room: bind the checkbox to the hidden tender field, and reveal the
       reservation-name field (prefilled from the stay). The field is disabled while
       hidden so it is never serialized on a non-charge order. */
    var t = document.getElementById('mbHotelTender');
    var cb = document.getElementById('mbHotelChargeToggle');
    var nameBox = document.getElementById('mbHotelChargeName');
    var nameInput = document.getElementById('mbHotelChargeNameInput');
    if (t && cb) {
        cb.addEventListener('change', function(){
            var on = cb.checked;
            t.value = on ? 'charge_to_room' : '';
            if (nameBox) nameBox.hidden = !on;
            if (nameInput) nameInput.disabled = !on;
            if (on && nameInput) { try { nameInput.focus(); nameInput.select(); } catch(e){} }
        });
    }

    /* ---- hotel hub: open/close + focus trap (SPEC 2.2 a11y) -------------
       The chip is a real <a> to the info page (no-JS fallback); here we upgrade its
       click to open the in-theme hub. On open, focus moves into the sheet and is
       trapped; Escape, a backdrop click and the visible close button all dismiss it,
       and focus returns to the chip. role=dialog + aria-modal + aria-labelledby are
       server-rendered on the panel. */
    var hub      = document.getElementById('mbHotelHub');
    var hubScrim = document.getElementById('mbHotelHubScrim');
    var hubClose = document.getElementById('mbHotelHubClose');
    var chipEl   = document.getElementById('mbHotelChip');
    var hubOpener = null;
    function hubFocusables(){
        return hub ? hub.querySelectorAll('a[href],button:not([disabled]),select,textarea,input:not([type="hidden"]),summary,[tabindex]:not([tabindex="-1"])') : [];
    }
    function hubKey(e){
        var k = e.key || '';
        if (k === 'Escape' || e.keyCode === 27){ e.preventDefault(); closeHub(); return; }
        if (k === 'Tab' || e.keyCode === 9){
            var f = hubFocusables(); if (!f.length) return;
            var first = f[0], last = f[f.length - 1];
            if (e.shiftKey && document.activeElement === first){ e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last){ e.preventDefault(); first.focus(); }
        }
    }
    function openHub(opener){
        if (!hub) return;
        hubOpener = opener || document.activeElement;
        hub.hidden = false; if (hubScrim) hubScrim.hidden = false;
        /* reflow so the transform transition runs from the hidden state */
        void hub.offsetWidth;
        hub.classList.add('is-open'); if (hubScrim) hubScrim.classList.add('is-open');
        document.addEventListener('keydown', hubKey, true);
        var f = hubFocusables();
        (hubClose || f[0] || hub).focus();
    }
    function closeHub(){
        if (!hub || hub.hidden) return;
        hub.classList.remove('is-open'); if (hubScrim) hubScrim.classList.remove('is-open');
        document.removeEventListener('keydown', hubKey, true);
        var restore = hubOpener; hubOpener = null;
        window.setTimeout(function(){ hub.hidden = true; if (hubScrim) hubScrim.hidden = true; }, 300);
        if (restore && restore.focus){ try { restore.focus(); } catch(e){} }
    }
    if (chipEl && hub){
        chipEl.addEventListener('click', function(e){ e.preventDefault(); openHub(chipEl); });
    }
    if (hubClose) hubClose.addEventListener('click', closeHub);
    if (hubScrim) hubScrim.addEventListener('click', closeHub);

    /* ---- D1: deliver-now / schedule / breakfast pre-order --------------- */
    var pick  = document.getElementById('mbHotelSchedPick');
    var dSel  = document.getElementById('mbHotelSchedDate');
    var tSel  = document.getElementById('mbHotelSchedTime');
    var at    = document.getElementById('mbHotelSchedAt');
    var note  = document.getElementById('mbHotelSchedNote');
    if (pick && dSel && tSel && at) {
        var SLOTS_URL = @json($__hgSlotsUrl);
        var BRANCH_ID = @json($__hgBranchId);
        var BF = @json($__hgBfJs);
        var L = {
            select: @json(___('Select a time')),
            full:   @json(___('Full')),
            none:   @json(___('No times available on that day.')),
            late:   @json(___('Today’s breakfast cutoff has passed — showing the next available morning.')),
            booked: @json(___('Breakfast is fully booked today — showing the next available morning.')),
            window: @json(___('Breakfast is served')),
        };

        /* The hidden field is only named while a slot is picked (see markup note). */
        function setAt(v) {
            if (v) { at.value = v; at.setAttribute('name', 'scheduled_at'); }
            else   { at.value = ''; at.removeAttribute('name'); }
        }
        function option(value, label, disabled) {
            var o = document.createElement('option');
            o.value = value; o.textContent = label;
            if (disabled) o.disabled = true;
            return o;
        }
        function fill(slots) {
            tSel.textContent = '';
            tSel.appendChild(option('', L.select, false));
            (slots || []).forEach(function (s) {
                tSel.appendChild(option(s.value, s.full ? (s.label + ' — ' + L.full) : s.label, !!s.full));
            });
            setAt('');
            if (!slots || !slots.length) { showNote(L.none); } else { showNote(''); }
        }
        function showNote(txt) {
            if (!note) return;
            note.textContent = txt || '';
            note.hidden = !txt;
        }
        function mode() {
            var r = document.querySelector('input[name="mb_hotel_when"]:checked');
            return r ? r.value : 'now';
        }
        function loadDay(date) {
            if (!SLOTS_URL) return;
            var url = SLOTS_URL + '?type=room_service&date=' + encodeURIComponent(date)
                + (BRANCH_ID ? '&branch_id=' + BRANCH_ID : '');
            fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) { fill(j && j.enabled ? j.slots : []); })
                .catch(function () {});
        }

        Array.prototype.forEach.call(document.querySelectorAll('input[name="mb_hotel_when"]'), function (r) {
            r.addEventListener('change', function () {
                var m = mode();
                pick.hidden = (m === 'now');
                setAt('');
                /* C9 — while breakfast is selected the day is LOCKED to the breakfast
                   date: the day <select> stays disabled so a change can't fetch the full
                   (dinner-inclusive) day list and let a "breakfast" order be placed for
                   21:30. Re-enabled for the plain schedule mode. */
                dSel.disabled = (m === 'breakfast');
                if (m === 'now') { showNote(''); return; }
                if (m === 'breakfast' && BF && BF.date) {
                    /* Breakfast = the pre-computed in-window slots of the first day whose
                       cutoff has not passed; the day select follows so it stays honest. */
                    dSel.value = BF.date;
                    fill((BF.slots || []).map(function (v) {
                        return { value: v, label: v.slice(11), full: false };
                    }));
                    /* C9 — distinct reasons: a passed cutoff vs a fully-booked morning. */
                    var msg = BF.late ? L.late : (BF.full ? L.booked : '');
                    if (BF.hours) { msg = (msg ? msg + ' ' : '') + L.window + ' ' + BF.hours + '.'; }
                    showNote(msg);
                    return;
                }
                loadDay(dSel.value);
            });
        });
        /* C9 — ignore day changes while breakfast is active (the select is disabled, but
           guard anyway) so the breakfast window can never be swapped for a full day. */
        dSel.addEventListener('change', function () {
            if (mode() === 'breakfast') { dSel.value = BF && BF.date ? BF.date : dSel.value; return; }
            loadDay(dSel.value);
        });
        tSel.addEventListener('change', function () { setAt(tSel.value); });
    }

    /* ---- D2: hotel services sheet (inline submit, form still works w/o JS) */
    var svcForm = document.getElementById('mbHotelSvcForm');
    var svcMsg  = document.getElementById('mbHotelSvcMsg');
    var svcBtn  = document.getElementById('mbHotelSvcSend');
    if (svcForm && svcMsg && window.FormData && window.fetch) {
        var SVC = {
            sending: @json(___('Sending…')),
            sent:    @json(___('Your request has been sent.')),
            failed:  @json(___('Could not send your request. Please try again.')),
            send:    @json(___('Send request')),
        };
        /* C6 — the requests card + a "Received" chip for a just-submitted request, so
           the guest gets immediate status without a reload. Server-rendered chips cover
           re-scan; this creates the card on the fly when the room had no prior request. */
        var REQ = @json($__hgReqJs);
        function ensureReqCard() {
            var card = document.getElementById('mbHotelRequests');
            if (card) return card;
            /* The requests card lives in the hub body, above the services form. */
            var body = document.getElementById('mbHotelHubBody');
            if (!body) return null;
            card = document.createElement('div');
            card.className = 'mb-hotel-card';
            card.id = 'mbHotelRequests';
            var t = document.createElement('span');
            t.className = 'mb-hotel-card__t';
            t.textContent = REQ.title;
            var list = document.createElement('div');
            list.className = 'mb-hotel-chips';
            list.id = 'mbHotelReqList';
            card.appendChild(t);
            card.appendChild(list);
            var svc = document.getElementById('mbHotelSvc');
            if (svc && svc.parentNode === body) { body.insertBefore(card, svc); }
            else { body.insertBefore(card, body.firstChild); }
            return card;
        }
        function addChip(type, status) {
            ensureReqCard();
            var list = document.getElementById('mbHotelReqList');
            if (!list) return;
            var st = status || 'open';
            var chip = document.createElement('span');
            chip.className = 'mb-hotel-chip mb-hotel-chip--' + st;
            chip.textContent = ((REQ.types && REQ.types[type]) || type) + ' · ' + ((REQ.statuses && REQ.statuses[st]) || st);
            list.insertBefore(chip, list.firstChild);
        }
        svcForm.addEventListener('submit', function (e) {
            e.preventDefault();
            svcMsg.hidden = false;
            svcMsg.textContent = SVC.sending;
            if (svcBtn) svcBtn.disabled = true;
            fetch(svcForm.action, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: new FormData(svcForm)
            }).then(function (r) {
                if (!r.ok) throw new Error('http');
                svcMsg.textContent = SVC.sent;
                svcForm.reset();
                return r.json().catch(function () { return null; });
            }).then(function (j) {
                if (j && j.request) { addChip(j.request.type, j.request.status); }
            }).catch(function () {
                svcMsg.textContent = SVC.failed;
            }).then(function () {
                if (svcBtn) { svcBtn.disabled = false; svcBtn.textContent = SVC.send; }
            });
        });
    }

    /* ---- C1: placement across themes ------------------------------------
       The layer is server-rendered in body flow above the theme's yielded content
       section (NOTE: do not write that as a literal Blade directive here — Blade
       compiles directives even inside a JS comment, and re-emitting the content
       section into this script closes it early on app-shell themes whose content
       carries an inline script tag, dumping the rest of this block as page text),
       which the 12 app-shell themes paint UNDER their fixed panes (invisible +
       untappable) and
       the two 100dvh-stage themes push off the bottom. Relocate the room chip to the top
       of the theme's scroll content (its per-theme header slot is a lane job), the hub sheet
       into the theme root as a fixed overlay, and the order-flow controls (schedule picker +
       charge-to-room toggle) INTO the checkout beside the other tender controls — the same
       mbAnchor pattern the offers strip, account banner and loyalty strip use. mbAnchor is
       defined LOWER in the layout than this include, so poll for it. onSettled re-runs the
       placement + re-inks after the theme finishes building and on every light/dark flip.
       Hidden room/tender/scheduled_at inputs stay in body flow, untouched. */
    (function tryAnchor(tries) {
        if (!window.mbAnchor) { if (tries < 40) { setTimeout(function () { tryAnchor(tries + 1); }, 50); } return; }
        var chip   = document.getElementById('mbHotelChip');
        var ctrls  = document.getElementById('mbHotelOrderCtrls');
        if (chip) {
            mbAnchor.onSettled(function () {
                /* Per-theme header slot: a theme marks its brand/meta row element with
                   data-mb-hotel-chip-slot and the chip mounts natively there (the copy-adapt
                   hook every skin uses — see the crave skin). Until the theme's chrome is
                   built the slot may not exist yet, so fall back to placeTop and let a later
                   settle move the chip into the slot once it appears. */
                /* A theme whose brand row lives INSIDE its cover/hero band (velvet's
                   .v-follow-row sits over the cover art) instead marks the element to
                   mount the chip AFTER, so the chip gets its own row below the band
                   rather than being nested over the artwork. Takes precedence. */
                var after = document.querySelector('[data-mb-hotel-chip-after]');
                var slot = document.querySelector('[data-mb-hotel-chip-slot]');
                if (after && after.parentElement && !after.contains(chip)) {
                    if (after.nextElementSibling !== chip) {
                        after.parentElement.insertBefore(chip, after.nextElementSibling);
                    }
                    chip.classList.add('mb-slotted');
                    chip.classList.remove('mb-inflow');
                } else if (slot) {
                    if (!slot.contains(chip)) { slot.appendChild(chip); }
                    chip.classList.add('mb-slotted');
                    chip.classList.remove('mb-inflow');
                } else if (mbAnchor.placeTop(chip)) {
                    chip.classList.add('mb-inflow');
                }
                mbAnchor.ink(chip);
            }, chip);
        }
        /* Schedule picker + charge-to-room toggle ride together in ONE wrapper into the
           checkout (a single placeCheckout, so the two never contend for the tip slot). */
        if (ctrls && mbAnchor.placeCheckout) {
            var placeCtrls = function () {
                if (mbAnchor.placeCheckout(ctrls)) { ctrls.classList.add('mb-inflow'); }
                mbAnchor.ink(ctrls);
            };
            mbAnchor.onSettled(placeCtrls, ctrls);
            /* onSettled only ticks at load (+ scheme flips), but some themes (feast,
               velvet) mount their checkout DOM later, when the diner opens the cart —
               by then the ticks are spent and the controls would stay unplaced (and,
               via the :not(.mb-inflow) rule, hidden). Views in these storefronts mount
               on taps, so retry shortly after any click while the controls are still
               unplaced — or were unmounted again by an SPA tearing its checkout down
               (we keep the JS reference, so placeCheckout can re-insert it). Cheap:
               one debounced timer, no-op once placed and still connected. */
            var ctrlsRetry = null;
            document.addEventListener('click', function () {
                if (ctrls.isConnected && ctrls.classList.contains('mb-inflow')) { return; }
                if (ctrlsRetry) { window.clearTimeout(ctrlsRetry); }
                ctrlsRetry = window.setTimeout(function () {
                    ctrlsRetry = null;
                    if (!ctrls.isConnected) { ctrls.classList.remove('mb-inflow'); }
                    if (!ctrls.classList.contains('mb-inflow')) { placeCtrls(); }
                }, 420);
            }, true);
        }
        /* The hub is a fixed overlay; relocate it (and its scrim) to a direct child of
           the theme ROOT — not a transformed inner view — so its token vars resolve and
           a per-theme skin can map --mbh-* onto the theme's own tokens. Idempotent. */
        if (hub) {
            mbAnchor.onSettled(function () {
                var root = mbAnchor.menuGrid ? mbAnchor.menuGrid(hub) : null;
                /* Fallback when the menu grid can't be detected at settle time — an
                   intro splash hiding it, or a grid whose cards lack the data-* the
                   detector keys on (velvet). Home the hub to the same theme root the
                   chip slotted into, so the theme's own --* tokens cascade onto it and
                   the per-theme skin applies instead of the neutral default. */
                if (!root) {
                    var slotEl = document.querySelector('[data-mb-hotel-chip-slot]');
                    if (slotEl) { root = slotEl; }
                }
                if (root) {
                    /* Walk to the OUTERMOST theme container — the direct child of
                       <body> or the shared #main-content wrapper — and append the hub
                       there as a top-level child of the theme root. A nested inner view
                       (e.g. crave's main.cv-views) clamps the hub's z-index below the
                       theme's own floating dock, which is a higher sibling of that view,
                       so the dock paints over the sheet. Homing to the root keeps the
                       hub inside the theme-scoped element (so --mbh-* / theme tokens
                       still cascade and the skin applies) while its z-index now beats
                       the dock. Idempotent. */
                    while (root.parentElement
                           && root.parentElement.tagName !== 'BODY'
                           && root.parentElement.tagName !== 'HTML'
                           && root.parentElement.id !== 'main-content') {
                        root = root.parentElement;
                    }
                }
                root = root || document.body;
                /* Now that the hub shares the theme root's stacking context with the
                   dock, its z-index (99999) beats the dock (theme --z-nav ~30)
                   regardless of sibling order — no need to force last-child. */
                if (hub.parentElement !== root) {
                    if (hubScrim) root.appendChild(hubScrim);
                    root.appendChild(hub);
                }
                /* Confine the sheet to a narrow phone-frame root. Some themes render as a
                   positioned, viewport-sized "phone" frame centered on a wide desktop
                   (crave/matcha: position:fixed, ~430px wide, ~viewport tall, overflow
                   clipped). A viewport-fixed sheet is centred on the whole window, so it
                   spills past such a frame on both sides. Re-anchor it to the frame with
                   position:absolute (.mb-hub-framed) so it matches the frame's width and
                   stays clipped inside it. Gate strictly on a real frame — positioned AND
                   both narrower and no taller than the viewport — so a full-width, scroll-
                   tall theme root (e.g. aurora, thousands of px tall) is never mistaken
                   for a frame (absolute there would pin the sheet to the page bottom, not
                   the viewport). On mobile the frame spans the viewport, so it fails the
                   width test and the sheet stays fixed. Re-run each settle so a resize
                   across the breakpoint self-corrects. */
                try {
                    var rr = root.getBoundingClientRect();
                    var rp = getComputedStyle(root).position;
                    var framed = (rp === 'fixed' || rp === 'absolute' || rp === 'relative')
                                 && rr.width  < (window.innerWidth  - 24)
                                 && rr.height < (window.innerHeight + 40);
                    hub.classList.toggle('mb-hub-framed', framed);
                    if (hubScrim) { hubScrim.classList.toggle('mb-hub-framed', framed); }
                } catch (e) {}
            }, hub);
        }
    })(0);
})();
</script>
@endif

{{-- C3 — public "Book a room" entry. Independent of the room-QR guest layer above: any
     storefront visitor of a hotel that opened online booking (hotel_online_booking ≠ off)
     sees a CTA to the public booking page. Rendered only when NOT already inside a room
     (the in-room guest has the full ordering layer, not a "book a room" prompt). Guarded so
     a read can never break the menu; relocated to the top of the theme's scroll content via
     the same mbAnchor pattern the greeting stack uses. --}}
@php
    $__hbOn  = false;
    $__hbUrl = '';
    try {
        if (isset($store) && $store && empty($__hgEnabled) && \app(\Juwri\Core\Contracts\Hotel\RoomCharge::class)->enabled($store->id)) {
            $__hbMode = strtolower(trim((string) store_options($store->id, 'hotel_online_booking')));
            if (in_array($__hbMode, ['request', 'instant'], true) && \Illuminate\Support\Facades\Route::has('hotel.guest.book')) {
                $__hbOn  = true;
                $__hbUrl = route('hotel.guest.book', $store->slug);
            }
        }
    } catch (\Throwable $e) {
        $__hbOn = false;
    }
@endphp
@if($__hbOn)
<style nonce="{{ csp_nonce() }}">
    /* Book-a-room CTA renders in its own no-room branch (no room QR), so it carries
       an independent copy of the token contract scoped to its root. Same neutral
       defaults as the in-room layer above. */
    .mb-hotel-book{
        --mbh-accent:    var(--brand, var(--theme-color-1, #2563eb));
        --mbh-on-accent: #ffffff;
        --mbh-ink:       var(--mb-ink, var(--theme-text, inherit));
        --mbh-muted:     var(--mbh-ink);
        --mbh-surface:   color-mix(in srgb, var(--mbh-accent) 12%, transparent);
        --mbh-border:    color-mix(in srgb, var(--mbh-accent) 35%, transparent);
        --mbh-radius:    14px;
        --mbh-font:      var(--menu-font, inherit);
        /* Slim single-line row (icon · label · small book button) — sits in the
           content flow below the brand row, not a chunky banner. */
        position:relative;margin:10px auto 0;max-width:640px;
        display:flex;align-items:center;gap:10px;
        padding:8px 10px 8px 12px;border-radius:var(--mbh-radius);text-align:start;
        background:var(--mbh-surface);
        border:1px solid var(--mbh-border);
        color:var(--mbh-ink);font-family:var(--mbh-font);
    }
    .mb-hotel-book__ic{flex:0 0 auto;display:inline-flex;align-items:center;justify-content:center;color:var(--mbh-accent);}
    .mb-hotel-book__ic svg{display:block;inline-size:18px;block-size:18px;}
    .mb-hotel-book__t{flex:1 1 auto;min-width:0;font-weight:600;font-size:.88rem;line-height:1.25;
        overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
    .mb-hotel-book__cta{flex:0 0 auto;appearance:none;text-decoration:none;font:inherit;font-weight:700;
        font-size:.8rem;padding:6px 13px;border-radius:9px;white-space:nowrap;
        background:var(--mbh-accent);color:var(--mbh-on-accent);}
    #mbHotelBook.mb-inflow{margin-block:8px;}
    /* Hidden until it is actually relocated below the brand row (.mb-inflow is
       added only once placed). Without this the CTA is left at its server-rendered
       spot in the outer <main> on any page with no brand row to anchor to — the
       order-track and info pages — where on an app-shell theme that paints a fixed
       frame (crave/matcha/…) it sits OUTSIDE the frame. The "book a room" prompt
       belongs on the menu/home, so suppressing it elsewhere is right, not just safe. */
    #mbHotelBook:not(.mb-inflow){display:none;}
</style>
<div class="mb-hotel-book" id="mbHotelBook">
    <span class="mb-hotel-book__ic" aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 4v16"/><path d="M2 9h18a2 2 0 0 1 2 2v9"/><path d="M2 15h20"/><path d="M6 9V7a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg></span>
    <span class="mb-hotel-book__t">{{ ___('Stay with us') }}</span>
    <a class="mb-hotel-book__cta" href="{{ $__hbUrl }}">{{ ___('Book a room') }}</a>
</div>
<script nonce="{{ csp_nonce() }}">
(function () {
    (function tryAnchor(tries) {
        if (!window.mbAnchor) { if (tries < 40) { setTimeout(function () { tryAnchor(tries + 1); }, 50); } return; }
        var el = document.getElementById('mbHotelBook');
        if (el) {
            mbAnchor.onSettled(function () {
                /* Sit just AFTER the brand row — the row holding the store name / logo /
                   follow (#cvBrandRow and its per-theme equivalents), NOT the chip slot,
                   which on some themes (ember) is a top bar sitting up in the cover. Find
                   it from the store name element so the id/class differences across the 17
                   skins don't matter, then, if that row is itself inside a hero / cover
                   section, climb to the OUTERMOST cover/hero ancestor and drop the CTA
                   after that so it never lands over the cover art. Falls back to the chip
                   slot when no brand row is found; pages with neither (order-track / info)
                   never place it, so it stays hidden via :not(.mb-inflow). Idempotent. */
                /* A theme can mark its preferred anchor with data-mb-hotel-book-slot
                   (the CTA mounts right AFTER that element — velvet marks the hero
                   band's content block). Explicit marker wins; no cover-climb, the
                   theme chose the spot deliberately. */
                var explicit = document.querySelector('[data-mb-hotel-book-slot]');
                var nameEl = document.querySelector('.brand-name');
                var brandRow = nameEl && nameEl.closest('#cvBrandRow, [data-tc-section="brand_row"], [id$="randRow"], [id$="rand-row"], [class*="brand-row"], [class*="brand_row"], [class*="follow-row"]');
                var slot = document.querySelector('[data-mb-hotel-chip-slot]');
                /* Heuristic otherwise: anchor after whichever of the brand-name row and
                   the chip slot comes LATER in the document — on crave the slot is the
                   "welcome/open" meta row BELOW the name (the approved spot); on ember
                   the slot is a top bar ABOVE the name up in the cover, so the name row
                   is the later, correct one. */
                var base = brandRow || slot;
                if (brandRow && slot && brandRow !== slot) {
                    base = (brandRow.compareDocumentPosition(slot) & Node.DOCUMENT_POSITION_FOLLOWING) ? slot : brandRow;
                }
                base = explicit || base;
                if (base && !base.contains(el) && base !== el) {
                    var anchor = base, COVER = /\b(cover|hero)\b/i;
                    if (!explicit) {
                        for (var p = base.parentElement;
                             p && p !== document.body && p.tagName !== 'HTML' && p.id !== 'main-content';
                             p = p.parentElement) {
                            if (COVER.test(p.className || '')) { anchor = p; }
                        }
                    }
                    if (anchor.parentElement && !anchor.contains(el)) {
                        if (anchor.nextElementSibling !== el) {
                            anchor.parentElement.insertBefore(el, anchor.nextElementSibling);
                        }
                        el.classList.add('mb-inflow');
                    }
                }
                mbAnchor.ink(el);
            }, el);
        }
    })(0);
})();
</script>
@endif
