{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- bio-t15 C1/C4 · Scheduling, recurring visibility & pin — extracted verbatim
     from builder.blade.php's <form data-editor-form> body (was the last section
     before the Cancel/Save buttons), then extended for A12 (recurring schedule
     window) and A13 (pinned/spotlight highlight). Included inside the parent
     form. --}}

{{-- scheduling & visibility (all types) — the block's one-time on/off window. --}}
<div class="ui-form-grid__full">
    <hr>
    <div class="ui-text-2 ui-caps ui-strong" style="font-size:.7rem;letter-spacing:.05em;">{{ ___('Scheduling & visibility') }}</div>
    <div class="ui-muted">{{ ___('Optional — leave the dates blank to keep the block always on.') }}</div>
</div>
<div data-group>
    <label class="ui-label">{{ ___('Show from') }}</label>
    <input type="datetime-local" class="ui-input" name="visible_from" data-field="visible_from">
</div>
<div data-group>
    <label class="ui-label">{{ ___('Show until') }}</label>
    <input type="datetime-local" class="ui-input" name="visible_until" data-field="visible_until">
</div>

{{-- bio-t15 A12 · recurring happy-hour / daily-special window (SPEC §2 Tier 3).
     Distinct from visible_from/visible_until above (a one-time window) — this is
     a repeating time-of-day + day-of-week window stored at config.schedule, so a
     block can e.g. only show 17:00–19:00 on Fri/Sat every week. Days follow the
     JS `Date.getDay()` convention: 0=Sun … 6=Sat (flagged in the LEDGER for Lane
     A to confirm the render gate uses the same convention). --}}
<div class="ui-form-grid__full" data-group>
    <label class="ui-label">{{ ___('Repeat window (e.g. happy hour)') }}</label>
    <div class="ui-form-grid">
        <div>
            <input type="time" class="ui-input" name="config[schedule][start_at]" data-field="config.schedule.start_at"
                   aria-label="{{ ___('Repeat window start time') }}" placeholder="{{ ___('Start time') }}">
        </div>
        <div>
            <input type="time" class="ui-input" name="config[schedule][end_at]" data-field="config.schedule.end_at"
                   aria-label="{{ ___('Repeat window end time') }}" placeholder="{{ ___('End time') }}">
        </div>
    </div>
    <div class="ui-cluster" data-schedule-days>
        @foreach([0 => ___('Sun'), 1 => ___('Mon'), 2 => ___('Tue'), 3 => ___('Wed'), 4 => ___('Thu'), 5 => ___('Fri'), 6 => ___('Sat')] as $dayNum => $dayLabel)
            <div class="ui-check">
                <input type="checkbox" id="schedule-day-{{ $dayNum }}"
                       name="config[schedule][days][]" value="{{ $dayNum }}" data-field="config.schedule.days.{{ $dayNum }}">
                <label class="ui-small" for="schedule-day-{{ $dayNum }}">{{ $dayLabel }}</label>
            </div>
        @endforeach
    </div>
    <small class="ui-text-2">{{ ___('Optional — leave the times and days blank to skip a repeating window. Outside the window the block is hidden, not just dimmed.') }}</small>
</div>

{{-- bio-t15 A13 · pin to the top of the page + an optional highlight animation
     (SPEC §2 Tier 3). Applies to any block type. --}}
<div class="ui-form-grid__full">
    <hr>
    <div class="ui-text-2 ui-caps ui-strong" style="font-size:.7rem;letter-spacing:.05em;">{{ ___('Pin & highlight') }}</div>
</div>
<div class="ui-check" data-group>
    <input type="checkbox" role="switch" id="editor-pinned" name="config[pinned]" value="1" data-field="config.pinned">
    <label for="editor-pinned">{{ ___('Pin to the top of the page') }}</label>
</div>
<div data-group>
    <label class="ui-label">{{ ___('Highlight') }}</label>
    <select class="ui-input" name="config[highlight]" data-field="config.highlight">
        <option value="none">{{ ___('None') }}</option>
        <option value="pulse">{{ ___('Pulse') }}</option>
        <option value="glow">{{ ___('Glow') }}</option>
    </select>
</div>

<div class="ui-form-grid__full ui-check">
    <input type="hidden" name="active" value="0">
    <input type="checkbox" role="switch" id="editor-active" name="active" value="1" data-field="active" checked>
    <label for="editor-active">{{ ___('Visible on the page') }}</label>
</div>
