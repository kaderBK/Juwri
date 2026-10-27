{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    rev/multi-vertical convert+duplicate: CONVERT this store's vertical in place,
    or DUPLICATE it as a new store under a different vertical.

    Lives inside edit.blade.php's outer <form> (the "Save changes" form for the
    rest of the store settings), so every control here is intentionally
    type="button" + Alpine @click/fetch — never type="submit" — and no field
    here carries a `name=` attribute, so nothing leaks into that form's POST.
    Server-side guard/transaction logic is StoreVerticalConversionService
    (convert) and StoreCloneService (duplicate); this view only renders the
    picker + confirms + posts JSON to StoreVerticalController.
--}}
@php
    $registry = app(\App\Services\Verticals\VerticalRegistry::class);
    $themeSets = app(\App\Services\Theme\VerticalThemeSets::class);
    $availableTemplates = app(\App\Services\StorefrontThemeService::class)->available();
    $currentType = $store->store_type ?: $registry->default();
    $currentGroup = (string) $registry->capability($currentType, 'group');
    $currentLabel = ___((string) $registry->capability($currentType, 'label', $currentType));
    $multiVerticalAllowed = \App\Services\Entitlements::allows($user, 'multi_vertical');

    // Hotel is excluded from convert/duplicate entirely (v1 — no menu<->room
    // bridge), so it never appears as a pickable target either direction.
    $verticalTypes = \Juwri\Core\Support\Views\StoreViewHelpers::convertPickerTypes($registry->all(), $themeSets, $availableTemplates, $registry);
@endphp

<div
    x-data="{
        token: @js(csrf_token()),
        types: @js($verticalTypes),
        currentType: @js($currentType),
        currentGroup: @js($currentGroup),
        allowed: @js($multiVerticalAllowed),
        convertUrl: @js(route('stores.convert', $store->id)),
        duplicateUrl: @js(route('stores.duplicateVertical', $store->id)),
        menuUrlTemplate: @js(route('stores.menu', ['store' => '__ID__'])),

        convertTarget: @js($currentType),
        convertTemplate: '',
        convertState: 'idle',
        convertMessage: '',
        convertWarnings: [],

        duplicateTarget: @js($currentType),
        duplicateTemplate: '',
        duplicateState: 'confirm',
        duplicateMessage: '',
        duplicateResult: null,

        typeInfo(t) { return this.types.find(function (x) { return x.type === t; }); },
        crossGroup(t) { var info = this.typeInfo(t); return !!info && info.group !== this.currentGroup; },
        templatesFor(t) { var info = this.typeInfo(t); return info ? info.templates : []; },
        isPremium(t) { var info = this.typeInfo(t); return !!info && info.premium; },
        isLocked(t) { return this.isPremium(t) && !this.allowed; },

        askConvert() {
            if (this.convertTarget === this.currentType || this.isLocked(this.convertTarget) || this.convertState === 'busy') return;
            this.convertMessage = ''; this.convertWarnings = [];
            if (this.crossGroup(this.convertTarget)) {
                this.convertTemplate = '';
                this.convertState = 'confirm';
                this.$refs.convertDialog.showModal();
            } else {
                this.runConvert();
            }
        },
        runConvert() {
            if (this.convertState === 'busy') return;
            this.convertState = 'busy';
            var self = this;
            fetch(this.convertUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.token },
                body: JSON.stringify({ store_type: this.convertTarget, store_template: this.convertTemplate || null })
            }).then(function (response) {
                return response.json().then(function (data) { return { ok: response.ok, data: data }; });
            }).then(function (r) {
                if (r.ok && r.data.success) {
                    self.convertState = 'done';
                    self.convertMessage = r.data.message;
                    self.convertWarnings = r.data.warnings || [];
                    self.currentType = r.data.store_type;
                    self.currentGroup = self.typeInfo(r.data.store_type) ? self.typeInfo(r.data.store_type).group : self.currentGroup;
                    window.setTimeout(function () { window.location.reload(); }, 1600);
                } else {
                    self.convertState = 'error';
                    self.convertMessage = (r.data && (r.data.error || r.data.message)) || @js(___('Something went wrong.'));
                }
            }).catch(function () {
                self.convertState = 'error';
                self.convertMessage = @js(___('Something went wrong.'));
            });
        },
        closeConvertDialog() {
            if (this.convertState === 'busy') return;
            if (this.$refs.convertDialog.open) this.$refs.convertDialog.close();
        },

        askDuplicate() {
            if (this.isLocked(this.duplicateTarget) || this.duplicateState === 'busy') return;
            this.duplicateState = 'confirm';
            this.duplicateMessage = ''; this.duplicateResult = null;
            this.$refs.duplicateDialog.showModal();
        },
        runDuplicate() {
            this.duplicateState = 'busy';
            var self = this;
            fetch(this.duplicateUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.token },
                body: JSON.stringify({ store_type: this.duplicateTarget, store_template: this.duplicateTemplate || null })
            }).then(function (response) {
                return response.json().then(function (data) { return { ok: response.ok, data: data }; });
            }).then(function (r) {
                if (r.ok && r.data.success) {
                    self.duplicateState = 'done';
                    self.duplicateResult = r.data;
                } else {
                    self.duplicateState = 'error';
                    self.duplicateMessage = (r.data && (r.data.error || r.data.message)) || @js(___('Something went wrong.'));
                }
            }).catch(function () {
                self.duplicateState = 'error';
                self.duplicateMessage = @js(___('Something went wrong.'));
            });
        },
        closeDuplicateDialog() {
            if (this.duplicateState === 'busy') return;
            if (this.$refs.duplicateDialog.open) this.$refs.duplicateDialog.close();
            if (this.duplicateState === 'done') window.location.reload();
        },
        duplicateOpenNew() {
            if (this.duplicateResult && this.duplicateResult.id) {
                window.location.href = this.menuUrlTemplate.replace('__ID__', this.duplicateResult.id);
            }
        }
    }"
>
    {{-- CONVERT --}}
    <div>
        <p class="ui-muted ui-caps">{{ ___('Store type') }}</p>
        <h3 class="ui-section-title">{{ ___('Convert this :noun to a different type', ['noun' => store_noun($store, 'lower')]) }}</h3>
        <p class="ui-muted">
            {{ ___('Converting within the same category is instant. Converting to a different category needs a new theme, and existing data that does not apply anymore will be hidden.') }}
        </p>
    </div>

    <div class="ui-form-grid">
        <div class="ui-form-grid__full">
            <label class="ui-label">{{ ___('Current type') }}</label>
            <input type="text" class="ui-input" value="{{ $currentLabel }}" disabled>
        </div>
        <div class="ui-form-grid__full">
            <label class="ui-label" for="store-vertical-convert-target">{{ ___('New type') }}</label>
            <select class="ui-input" id="store-vertical-convert-target" x-model="convertTarget">
                @foreach($verticalTypes as $t)
                    <option value="{{ $t['type'] }}" @disabled($t['type'] === $currentType || ($t['premium'] && ! $multiVerticalAllowed))>
                        {{ $t['label'] }}@if($t['type'] === $currentType) — {{ ___('Current type') }}@elseif($t['premium'] && ! $multiVerticalAllowed) — {{ ___('Upgrade required') }}@endif
                    </option>
                @endforeach
            </select>
        </div>
        <div class="ui-form-grid__full">
            <x-ui.button type="button" icon="refresh" @click="askConvert()" x-bind:disabled="convertTarget === currentType || isLocked(convertTarget) || convertState === 'busy'">
                {{ ___('Convert store') }}
            </x-ui.button>
        </div>

        <template x-if="crossGroup(convertTarget) && !isLocked(convertTarget)">
            <div class="ui-form-grid__full">
                <label class="ui-label" for="store-vertical-convert-template">{{ ___('Theme for the new type') }}</label>
                <select class="ui-input" id="store-vertical-convert-template" x-model="convertTemplate">
                    <option value="">{{ ___('Choose') }}</option>
                    <template x-for="tpl in templatesFor(convertTarget)" :key="tpl.slug">
                        <option :value="tpl.slug" x-text="tpl.name"></option>
                    </template>
                </select>
            </div>
        </template>

        <template x-if="isLocked(convertTarget)">
            <p class="ui-muted ui-form-grid__full">{{ ___('Non-restaurant stores are a premium feature. Please upgrade to create them.') }}</p>
        </template>

        <template x-if="convertState === 'busy'">
            <p class="ui-cluster ui-form-grid__full ui-text-accent" aria-live="polite">
                <span class="ui-spin" role="status" aria-hidden="true"></span>
                {{ ___('Converting your store…') }}
            </p>
        </template>
        <template x-if="convertState === 'done'">
            <div class="ui-form-grid__full">
                <p class="ui-text-success ui-strong" x-text="convertMessage"></p>
                <ul class="ui-muted" x-show="convertWarnings.length">
                    <template x-for="w in convertWarnings" :key="w">
                        <li x-text="w"></li>
                    </template>
                </ul>
            </div>
        </template>
        <template x-if="convertState === 'error'">
            <p class="ui-form-grid__full ui-text-danger" x-text="convertMessage" aria-live="polite"></p>
        </template>
    </div>

    {{-- Cross-group convert confirmation --}}
    <dialog class="ui-dialog ui-dialog--flat" x-ref="convertDialog" style="max-width:460px;">
        <div class="ui-cluster ui-cluster--between ui-rule-bottom">
            <h3 class="ui-section-title">{{ ___('Convert') }}</h3>
            <button type="button" class="ui-icon-btn ui-icon-btn--ghost" @click="closeConvertDialog()" aria-label="{{ ___('Close') }}"><x-ui.icon name="x" size="18" /></button>
        </div>
        <div>
            <div x-show="convertState === 'confirm'">
                <p>{{ ___('This will change what customers see and use. Existing data that does not apply to the new type will be hidden, not deleted.') }}</p>
            </div>
            <div x-show="convertState === 'busy'">
                <p class="ui-cluster ui-text-accent" aria-live="polite">
                    <span class="ui-spin" role="status" aria-hidden="true"></span>
                    {{ ___('Converting your store…') }}
                </p>
            </div>
            <div x-show="convertState === 'done'">
                <div>
                    <p class="ui-text-success ui-strong" x-text="convertMessage"></p>
                    <ul class="ui-muted">
                        <template x-for="w in convertWarnings" :key="w">
                            <li x-text="w"></li>
                        </template>
                    </ul>
                </div>
            </div>
            <div x-show="convertState === 'error'">
                <p class="ui-text-danger" x-text="convertMessage" aria-live="polite"></p>
            </div>
        </div>
        <div class="ui-cluster ui-cluster--end ui-rule-top">
            <div x-show="convertState === 'confirm'">
                <div class="ui-cluster">
                    <x-ui.button type="button" variant="ghost" @click="closeConvertDialog()">{{ ___('Cancel') }}</x-ui.button>
                    <x-ui.button type="button" icon="refresh" @click="runConvert()">{{ ___('Convert store') }}</x-ui.button>
                </div>
            </div>
            <div x-show="convertState === 'done' || convertState === 'error'">
                <x-ui.button type="button" variant="ghost" @click="closeConvertDialog()">{{ ___('Close') }}</x-ui.button>
            </div>
        </div>
    </dialog>

    <hr>

    {{-- DUPLICATE AS A DIFFERENT TYPE --}}
    <div>
        <p class="ui-muted ui-caps">{{ ___('Store type') }}</p>
        <h3 class="ui-section-title">{{ ___('Duplicate this :noun as a different type', ['noun' => store_noun($store, 'lower')]) }}</h3>
        <p class="ui-muted">
            {{ ___('Creates a brand new store with its own copy of your menu and settings. This :noun is never changed.', ['noun' => store_noun($store, 'lower')]) }}
        </p>
    </div>

    <div class="ui-form-grid">
        <div class="ui-form-grid__full">
            <label class="ui-label" for="store-vertical-duplicate-target">{{ ___('New type') }}</label>
            <select class="ui-input" id="store-vertical-duplicate-target" x-model="duplicateTarget">
                @foreach($verticalTypes as $t)
                    <option value="{{ $t['type'] }}" @disabled($t['premium'] && ! $multiVerticalAllowed)>
                        {{ $t['label'] }}@if($t['premium'] && ! $multiVerticalAllowed) — {{ ___('Upgrade required') }}@endif
                    </option>
                @endforeach
            </select>
        </div>
        <div class="ui-form-grid__full">
            <x-ui.button type="button" variant="secondary" icon="share" @click="askDuplicate()" x-bind:disabled="isLocked(duplicateTarget)">
                {{ ___('Duplicate store') }}
            </x-ui.button>
        </div>

        <template x-if="crossGroup(duplicateTarget) && !isLocked(duplicateTarget)">
            <div class="ui-form-grid__full">
                <label class="ui-label" for="store-vertical-duplicate-template">{{ ___('Theme for the new type') }}</label>
                <select class="ui-input" id="store-vertical-duplicate-template" x-model="duplicateTemplate">
                    <option value="">{{ ___('Choose') }}</option>
                    <template x-for="tpl in templatesFor(duplicateTarget)" :key="tpl.slug">
                        <option :value="tpl.slug" x-text="tpl.name"></option>
                    </template>
                </select>
            </div>
        </template>

        <template x-if="isLocked(duplicateTarget)">
            <p class="ui-muted ui-form-grid__full">{{ ___('Non-restaurant stores are a premium feature. Please upgrade to create them.') }}</p>
        </template>
    </div>

    <dialog class="ui-dialog ui-dialog--flat" x-ref="duplicateDialog" style="max-width:420px;">
        <div class="ui-cluster ui-cluster--between ui-rule-bottom">
            <h3 class="ui-section-title">{{ ___('Duplicate') }}</h3>
            <button type="button" class="ui-icon-btn ui-icon-btn--ghost" @click="closeDuplicateDialog()" aria-label="{{ ___('Close') }}"><x-ui.icon name="x" size="18" /></button>
        </div>
        <div>
            <div x-show="duplicateState === 'confirm'">
                <p>{{ ___('Creates a brand new store with its own copy of your menu and settings. This :noun is never changed.', ['noun' => store_noun($store, 'lower')]) }}</p>
            </div>
            <div x-show="duplicateState === 'busy'">
                <p class="ui-cluster ui-text-accent" aria-live="polite">
                    <span class="ui-spin" role="status" aria-hidden="true"></span>
                    {{ ___('Duplicating your store… this can take a few seconds for large menus.') }}
                </p>
            </div>
            <div x-show="duplicateState === 'done'">
                <p class="ui-text-success ui-strong">{{ ___('Store duplicated successfully.') }}</p>
            </div>
            <div x-show="duplicateState === 'error'">
                <p class="ui-text-danger" x-text="duplicateMessage" aria-live="polite"></p>
            </div>
        </div>
        <div class="ui-cluster ui-cluster--end ui-rule-top">
            <div x-show="duplicateState === 'confirm'">
                <div class="ui-cluster">
                    <x-ui.button type="button" variant="ghost" @click="closeDuplicateDialog()">{{ ___('Cancel') }}</x-ui.button>
                    <x-ui.button type="button" icon="share" @click="runDuplicate()">{{ ___('Duplicate store') }}</x-ui.button>
                </div>
            </div>
            <div x-show="duplicateState === 'done'">
                <div class="ui-cluster">
                    <x-ui.button type="button" variant="ghost" @click="closeDuplicateDialog()">{{ ___('Close') }}</x-ui.button>
                    <x-ui.button type="button" icon="chevron" @click="duplicateOpenNew()">{{ ___('Open the copy') }}</x-ui.button>
                </div>
            </div>
            <div x-show="duplicateState === 'error'">
                <x-ui.button type="button" variant="ghost" @click="closeDuplicateDialog()">{{ ___('Close') }}</x-ui.button>
            </div>
        </div>
    </dialog>
</div>
