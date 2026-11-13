{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  Theme Studio — "Describe your store" AI builder panel (spec rev/theme-studio §4 Phase D,
  contract rev/theme-studio/AI-BUILDER-CONTRACT.md). Included by theme-studio.blade.php
  only when caps.ai_generate is on and the ai_* urls are present. Behaviour lives in
  /assets/theme-studio/studio-ai.js; the strings it needs ship as a JSON data block.

  Expects: $tsAiImageCost (?int), $tsAiRemaining (?int), $tsAiEditor (bool).
--}}
@php
    $tsAiVibes = [
        'warm' => ___('Warm'), 'minimal' => ___('Minimal'), 'bold' => ___('Striking'), 'luxury' => ___('Luxury'),
        'playful' => ___('Playful'), 'traditional' => ___('Traditional'), 'modern' => ___('Modern'), 'family' => ___('Family'),
    ];
    $tsAiPriorities = [
        '' => [___('Let the AI decide'), ___('A balanced layout for your kind of store.')],
        'fast_ordering' => [___('Fast ordering'), ___('Menu and order button first.')],
        'brand_story' => [___('Brand story'), ___('Big imagery and your story up front.')],
        'browse' => [___('Browse the catalogue'), ___('Categories and products to explore.')],
        'bookings' => [___('Bookings'), ___('Reservations and appointments first.')],
    ];
    $tsAiJsStrings = [
        'Reading your store and menu…', 'Choosing layouts for your kind of store…', 'Picking colours and fonts…',
        'Writing your headlines…', 'Checking that every design works on phones…', 'Almost there…',
        'This is taking longer than usual. You can keep waiting or cancel.',
        'Cancelled. Nothing was changed.', 'Your 3 designs are ready.',
        'Design :n of :total', 'Preview of :name', 'Open full preview', 'Use this', 'Loading preview…',
        'Replace my draft', 'Keep my draft', 'Your current draft is replaced by this design. Your live storefront does not change until you publish.',
        'Opening the editor…', 'Could not use this design. Try again.',
        'These designs have expired. Generate new ones.',
        'You have reached today’s limit for AI designs. Try again tomorrow.',
        'Please check your answers.', 'Could not reach the server. Check your connection and try again.',
        'Your session has ended. Reload the page and sign in again.',
        'Something went wrong while creating your designs. Try again.',
        'You can create :n more designs today.', 'This was your last AI design for today.',
        'Colours', 'Fonts', 'Remove colour :n', 'Colour :n', ':n of :max characters',
        'AI hero image included',
    ];
    $tsAiI18n = [];
    foreach ($tsAiJsStrings as $s) {
        $tsAiI18n[$s] = ___($s);
    }
    $tsAiCostNote = $tsAiImageCost !== null && $tsAiImageCost > 0
        ? ___('Costs :n Studio credits per generation. Without it we use your cover and menu photos.', ['n' => $tsAiImageCost])
        : ___('Uses Studio credits from your balance. Without it we use your cover and menu photos.');
@endphp
<script type="application/json" id="theme-studio-ai-i18n">@json(['strings' => $tsAiI18n, 'editor' => (bool) $tsAiEditor, 'remaining' => $tsAiRemaining])</script>

<x-ui.sheet id="ts-ai" class="ts-sheet ts-sheet--wide ts-ai" :title="___('Design my theme with AI')" :description="___('Tell us about your store and get 3 complete designs in about a minute. Nothing goes live until you publish.')">
    {{-- ── 1. Brief ─────────────────────────────────────────────────── --}}
    <div class="ts-ai__view" data-ts-ai-view="form">
        <form class="ts-ai__form" data-ts-ai-form novalidate>
            <div class="ui-banner ts-ai__alert" data-tone="danger" role="alert" data-ts-ai-error hidden>
                <div class="ui-banner__body" data-ts-ai-error-text></div>
            </div>

            <div class="ui-field ts-ai__field" data-ts-ai-field="text">
                <label class="ui-field__label" for="ts-ai-text">{{ ___('Describe your store (optional)') }}</label>
                <div class="ui-field__control">
                    <textarea id="ts-ai-text" name="text" rows="3" maxlength="500" data-ts-ai-text
                              placeholder="{{ ___('For example: a family bakery known for fresh bread and Arabic sweets, busy in the mornings.') }}"
                              aria-describedby="ts-ai-text-count ts-ai-text-help"></textarea>
                </div>
                <div class="ts-ai__fieldfoot">
                    <span class="ui-field__help" id="ts-ai-text-help" data-ts-ai-field-error="text">{{ ___('What you sell, who comes, what makes you different.') }}</span>
                    <span class="ts-ai__count" id="ts-ai-text-count" data-ts-ai-count>0 / 500</span>
                </div>
            </div>

            <fieldset class="ts-ai__group" data-ts-ai-field="vibes">
                <legend class="ui-field__label">{{ ___('Pick a feel (optional)') }}</legend>
                <div class="ts-ai__chips" role="group">
                    @foreach ($tsAiVibes as $key => $label)
                        <button type="button" class="ui-chip" aria-pressed="false" data-ts-ai-vibe="{{ $key }}">{{ $label }}</button>
                    @endforeach
                </div>
            </fieldset>

            <fieldset class="ts-ai__group" data-ts-ai-field="priority">
                <legend class="ui-field__label">{{ ___('What matters most?') }}</legend>
                <div class="ts-ai__options">
                    @foreach ($tsAiPriorities as $key => [$label, $hint])
                        <label class="ui-check ts-ai__option">
                            <input type="radio" name="priority" value="{{ $key }}" @checked($key === '') data-ts-ai-priority>
                            <span class="ts-ai__option-text">
                                <span class="ts-ai__option-label">{{ $label }}</span>
                                <span class="ts-ai__option-hint">{{ $hint }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <fieldset class="ts-ai__group" data-ts-ai-field="colors">
                <legend class="ui-field__label">{{ ___('Brand colours (optional, up to 3)') }}</legend>
                <div class="ts-ai__colors" data-ts-ai-colors></div>
                <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm ts-ai__addcolor" data-ts-ai-add-color>
                    <span class="ui-btn__spinner" aria-hidden="true"></span><span class="ui-btn__icon"><x-ui.icon name="plus" /></span>{{ ___('Add a colour') }}
                </button>
                <span class="ui-field__help" data-ts-ai-field-error="colors">{{ ___('We use your logo colours when you leave this empty.') }}</span>
            </fieldset>

            <div class="ts-ai__group">
                <x-ui.switch id="ts-ai-images" name="images" :label="___('Generate an AI hero image (uses Studio credits)')" :description="$tsAiCostNote" data-ts-ai-images />
            </div>

            <div class="ts-ai__actions">
                <p class="ts-ai__remaining" data-ts-ai-remaining @if ($tsAiRemaining === null) hidden @endif>
                    @if ($tsAiRemaining !== null){{ ___('You can create :n more designs today.', ['n' => $tsAiRemaining]) }}@endif
                </p>
                <x-ui.button variant="secondary" data-ui-close>{{ ___('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" data-ts-ai-generate>{{ ___('Create 3 designs') }}</x-ui.button>
            </div>
        </form>
    </div>

    {{-- ── 2. Working ───────────────────────────────────────────────── --}}
    <div class="ts-ai__view ts-ai__progress" data-ts-ai-view="progress" hidden>
        <h3 class="ts-ai__h" tabindex="-1" data-ts-ai-focus>{{ ___('Creating your designs') }}</h3>
        <div class="ts-ai__bar" role="progressbar" aria-label="{{ ___('Creating your designs') }}" aria-busy="true" aria-valuetext="{{ ___('Working…') }}" data-ts-ai-bar>
            <span class="ts-ai__bar-fill"></span>
        </div>
        <p class="ts-ai__stage" role="status" aria-live="polite" data-ts-ai-stage>{{ ___('Reading your store and menu…') }}</p>
        <p class="ts-ai__note">{{ ___('This can take up to a minute. You can keep this window open while we work.') }}</p>
        <div class="ts-ai__actions">
            <x-ui.button variant="secondary" data-ts-ai-cancel>{{ ___('Cancel') }}</x-ui.button>
        </div>
    </div>

    {{-- ── 3. Proposals ─────────────────────────────────────────────── --}}
    <div class="ts-ai__view" data-ts-ai-view="results" hidden>
        <h3 class="ts-ai__h" tabindex="-1" data-ts-ai-focus>{{ ___('Pick the design you like') }}</h3>
        <div class="ui-banner" role="status" data-ts-ai-fallback hidden>
            <div class="ui-banner__body">{{ ___('Our AI designer was not available just now, so these start from ready-made designs for your kind of store. You can still change everything in the editor.') }}</div>
        </div>
        @if ($tsAiEditor)
            <p class="ts-ai__note">{{ ___('Using a design replaces your current draft. Your live storefront does not change until you publish.') }}</p>
        @endif
        <ul class="ts-ai__cards" role="list" data-ts-ai-cards></ul>
        <p class="ts-ai__pager" aria-live="polite" data-ts-ai-pager></p>
        <div class="ts-ai__actions">
            <p class="ts-ai__remaining" data-ts-ai-remaining hidden></p>
            <x-ui.button variant="ghost" data-ts-ai-back>{{ ___('Change my answers') }}</x-ui.button>
            <x-ui.button variant="secondary" data-ts-ai-regenerate>{{ ___('Show me 3 new designs') }}</x-ui.button>
        </div>
    </div>
</x-ui.sheet>
