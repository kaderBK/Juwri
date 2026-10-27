// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* reorder-pickup.js — shared "previous-order" reorder handoff helper.
 *
 * Background
 * ----------
 * The account page (store_templates.<theme>/account.blade.php) POSTs to
 * CustomerAuthController::reorder, which returns a payload built by
 * BuildsReorderPayload (core/.../BuildsReorderPayload.php:57-72). The blade
 * persists that payload under localStorage key  cv_reorder_<slug>  and
 * redirects the diner to the menu index. The home page is then expected to
 * read the key and add the lines to its own cart.
 *
 * Before this helper every theme did that read in its own copy of
 * `pickupReorder()` — and every copy silently dropped two fields:
 *   1. `options`    (the choice-group selections on each line)
 *   2. `order_note` (the "no onions" note on each line)
 * Crumb / dough / ember / picnic / scoop never read the key at all, so the
 * diner arrived at an empty cart. Velora / glow / soirée / matcha read the
 * key but pushed the lines with `mods: []` and `note: ""`, which is the
 * "silent drop" case the spec calls out. Orbit already reads the key and
 * uses it; this helper takes its existing path over so orbit, velora, glow,
 * soirée, matcha and the previously broken themes share one well-tested
 * implementation.
 *
 * API
 * ---
 *   window.JuwriReorder.pickup(slug, addLine, opts)
 *
 *   slug     : store slug — the same one the account blade wrote into the key
 *   addLine  : function(payload) called for every successfully-resolved line.
 *              `payload` is:
 *                {
 *                  item_id : <int>,
 *                  qty     : <int>,
 *                  variant : <int>,            // variantId, 0 if none
 *                  options : <array>,          // raw choice-group data
 *                  extras  : <array>,          // [{id,title,price}]
 *                  note    : <string>,
 *                  key     : <string|null>,    // server-computed merge key
 *                  mods    : <array> | null,   // opts.mapMods(...)
 *                }
 *              `payload.options` and `payload.extras` are the raw values from
 *              the payload; themes that need their own `mods` shape (object,
 *              flat array, etc.) pass `opts.mapMods(item, options, line)`
 *              which returns that shape and is forwarded as `payload.mods`.
 *   opts     : object
 *                mapMods(item, options, line) : optional. Returns the theme's
 *                                              mods shape for the line. If
 *                                              omitted, `payload.mods` is
 *                                              left null and themes are
 *                                              expected to read `payload.options`.
 *                onUnavailable(names, msg)   : optional. Called once at the
 *                                              end with the dropped item names
 *                                              and a ready, translated message.
 *                toast(msg)                  : optional shortcut used when
 *                                              onUnavailable is not given.
 *
 *   Returns { added: <int>, unavailable: <Array<{id, name}>> }.
 *
 *   The key is removed on first read so the diner never sees the same order
 *   queued twice (back navigation, refresh). Themes that re-render the index
 *   are safe — pickup() short-circuits on an empty payload.
 *
 * Loaded once per theme index blade via
 *   <script src="{{ asset('assets/store_templates/_shared/js/reorder-pickup.js') }}?v=…"></script>
 */
(function (w, d) {
    'use strict';

    function pickup(slug, addLine, opts) {
        opts = opts || {};
        if (typeof slug !== 'string' || !slug) {
            return { added: 0, unavailable: [] };
        }
        if (typeof addLine !== 'function') {
            return { added: 0, unavailable: [] };
        }

        var key = 'cv_reorder_' + slug;
        var raw = null;
        try {
            raw = JSON.parse(w.localStorage.getItem(key) || 'null');
        } catch (e) {
            raw = null;
        }
        /* idempotent — account-blade writes the key, we consume it exactly once */
        try { w.localStorage.removeItem(key); } catch (e) { /* private mode: ignore */ }

        if (!Array.isArray(raw) || !raw.length) {
            return { added: 0, unavailable: [] };
        }

        var added = 0;
        var unavailable = [];
        var mapMods = (typeof opts.mapMods === 'function') ? opts.mapMods : null;

        for (var i = 0; i < raw.length; i++) {
            var line = raw[i];
            if (!line || typeof line !== 'object') { continue; }
            var item_id = Number(line.id);
            if (!item_id) { continue; }
            /* server pre-resolves the menu's still-on-sale status; if a line was
               filtered out it never makes it into `items` (see
               CustomerAuthController::reorder), so anything that lands here
               passed the "no longer available" check. If a theme's local copy
               of the menu has the item hidden (eg. branched stock), the theme
               reports it via addLine's return value. */
            var qty = Math.max(1, parseInt(line.qty || line.quantity, 10) || 1);
            var variant = parseInt(line.variantId, 10) || 0;
            var note = (line.order_note != null) ? String(line.order_note) : (line.note != null ? String(line.note) : '');
            var options = Array.isArray(line.options) ? line.options : [];
            var extras = Array.isArray(line.extras) ? line.extras : [];
            var mods = null;
            if (mapMods) {
                try {
                    mods = mapMods(item_id, options, line);
                } catch (e) { mods = null; }
            }
            var payload = {
                item_id: item_id,
                qty: qty,
                variant: variant,
                options: options,
                extras: extras,
                note: note,
                key: line.key || null,
                mods: mods,
            };
            var accepted = true;
            try {
                /* a theme may return false to mark the line as dropped (eg.
                   not in the local ITEMS map). Anything else counts as added. */
                var ret = addLine(payload);
                if (ret === false) { accepted = false; }
            } catch (e) { accepted = false; }
            if (accepted) { added++; }
            else { unavailable.push({ id: item_id, name: line.name || '' }); }
        }

        if (unavailable.length) {
            var names = unavailable.map(function (u) { return u.name; }).filter(Boolean);
            /* layout.blade.php sets the translated word; English only as a last resort */
            var msg = (names.length ? names.join(', ') + ' \u2014 ' : '') + (w.JUWRI_REORDER_UNAVAILABLE || 'Unavailable');
            try {
                if (typeof opts.onUnavailable === 'function') { opts.onUnavailable(names, msg); }
                else if (typeof opts.toast === 'function') { opts.toast(msg); }
            } catch (e) {}
        }

        return { added: added, unavailable: unavailable };
    }

    /* modsFromOptions(item, options) -> [{groupId, optIndex, label, price}]
       Resolves the server payload's choice-group selections
       ([{group_id, title, selected:[{option, price}]}]) against the theme's own
       item.optionGroups (g.id, g.options[{i,label,price}]) by group id + option
       label. Selections the menu no longer offers are dropped. */
    function modsFromOptions(item, options) {
        var out = [];
        var groups = (item && item.optionGroups) || [];
        (options || []).forEach(function (group) {
            if (!group) return;
            var grp = null;
            for (var gi = 0; gi < groups.length; gi++) {
                if (String(groups[gi].id) === String(group.group_id)) { grp = groups[gi]; break; }
            }
            if (!grp) return;
            (group.selected || []).forEach(function (s) {
                var opts = grp.options || [];
                for (var oi = 0; oi < opts.length; oi++) {
                    if (opts[oi].label === s.option) {
                        out.push({
                            groupId: Number(grp.id),
                            optIndex: Number(opts[oi].i != null ? opts[oi].i : oi),
                            label: opts[oi].label,
                            price: Number(opts[oi].price) || 0,
                        });
                        break;
                    }
                }
            });
        });
        return out;
    }

    /* requiredMissing(item, mods) -> true when a required / min-pick option
       group would be left short after the remap (the order would fail checkout
       with "Choose at least N"), so the line must be reported unavailable. */
    function requiredMissing(item, mods) {
        return ((item && item.optionGroups) || []).some(function (g) {
            var need = g.required ? Math.max(1, Number(g.min) || 0) : (Number(g.min) || 0);
            if (!need) return false;
            var n = 0;
            (mods || []).forEach(function (m) { if (Number(m.groupId) === Number(g.id)) n++; });
            return n < need;
        });
    }

    /* variantLabel(item, variantId) -> label of the DB variant, "" if none. */
    function variantLabel(item, variantId) {
        var vs = (item && item.variants) || [];
        for (var i = 0; i < vs.length; i++) {
            if (Number(vs[i].id) === Number(variantId)) return vs[i].label || "";
        }
        return "";
    }

    w.JuwriReorder = {
        pickup: pickup,
        modsFromOptions: modsFromOptions,
        requiredMissing: requiredMissing,
        variantLabel: variantLabel,
    };
})(window, document);