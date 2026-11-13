{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<form method="post" action="{{ $action }}" class="ui-form-grid">
    @csrf
    <x-ui.field :id="$k.'-name'" name="name" :label="___('Name')" :value="$s?->name" required maxlength="120" />
    <x-ui.field :id="$k.'-price'" name="price" type="number" step="any" min="0" :label="___('Price')" :prefix="$currency" :value="$row['price'] ?? null" required />
    <x-ui.field :id="$k.'-dur'" name="duration_min" type="number" step="5" min="5" max="720" :label="___('Duration (minutes)')" :value="$s?->duration_min ?? 60" required />
    <x-ui.field :id="$k.'-bb'" name="buffer_before_min" type="number" step="5" min="0" max="240" :label="___('Buffer before (minutes)')" :value="$s?->buffer_before_min ?? 0" />
    <x-ui.field :id="$k.'-ba'" name="buffer_after_min" type="number" step="5" min="0" max="240" :label="___('Buffer after (minutes)')" :value="$s?->buffer_after_min ?? 0" />
    <x-ui.field :id="$k.'-dt'" name="deposit_type" type="select" :label="___('Deposit')" :options="$depositTypes" :value="$s?->deposit_type ?? 'none'" />
    <x-ui.field :id="$k.'-da'" name="deposit_amount" type="number" step="any" min="0" :label="___('Deposit amount')" :prefix="$currency" :value="$row['deposit_amount'] ?? null" :help="___('Used with a fixed amount.')" />
    <x-ui.field :id="$k.'-dp'" name="deposit_percent" type="number" min="1" max="100" :label="___('Deposit percent')" :value="$row['deposit_percent'] ?? null" :help="___('Used with a percent of the price.')" />
    <x-ui.field :id="$k.'-desc'" name="description" type="textarea" :label="___('Description')" :value="$s?->description" class="ui-form-grid__full" />
    <fieldset class="ui-form-grid__full">
        <legend class="ui-label">{{ ___('Location') }}</legend>
        <x-ui.check name="venue" value="1" :label="___('At your place')" :checked="$s?->venue ?? true" />
        <x-ui.check name="at_home" value="1" :label="___('At the customer\'s home')" :checked="$s?->at_home ?? false" />
        @unless($atHome)<span class="ui-field__help">{{ ___('Visits at home also need to be turned on in Settings.') }}</span>@endunless
    </fieldset>
    @unless($isNew)
        <div class="ui-form-grid__full"><x-ui.check name="active" value="1" :label="___('Can be booked')" :checked="$s->active" /></div>
    @endunless
    <div class="ui-form-grid__full"><x-ui.button type="submit" :icon="$isNew ? 'plus' : null">{{ $isNew ? ___('Add service') : ___('Save') }}</x-ui.button></div>
</form>
