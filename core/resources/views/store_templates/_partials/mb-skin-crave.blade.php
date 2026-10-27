{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Shared .mba-* sheet skin for the crave-lineage themes (crave/glow/lime/
     scoop/ember/matcha/picnic/dough/soiree/crumb/orbit/velora…). They share the
     crave CSS var vocabulary (--ink/--stroke/--elev/--cta-bg/--card-2), so one
     skin with fallback chains adapts to each theme's own values (light or dark).
     Include right after the sheet partials on those themes' account pages. --}}
<style nonce="{{ csp_nonce() }}">
    .mba-sheet{
        --mba-surface:var(--elev, var(--card, var(--panel, #1c1f23)));
        --mba-ink:var(--ink, #f4f6f8);
        --mba-muted:var(--ink-2, var(--muted, var(--ink-3, #adb6ba)));
        --mba-border:var(--stroke, var(--line, var(--hair, rgba(0,0,0,.12))));
        --mba-hair:var(--stroke, var(--line, rgba(0,0,0,.06)));
        --mba-in-bg:var(--card-2, var(--card, var(--elev, #2a2f35)));
        --mba-accent:var(--cta-bg, var(--accent, var(--brand, #6b4ea8)));
        --mba-on-accent:var(--cta-ink, #ffffff);
        --mba-accent-ring:var(--stroke, var(--line, rgba(0,0,0,.12)));
        --mba-radius:18px;
    }
</style>
