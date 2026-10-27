{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<div class="ui-field">
    <label class="ui-field__label" for="company_bank_info">{{ ___('Bank Information') }} *</label>
    <textarea id="company_bank_info" name="company_bank_info" rows="8" data-ui-rich required>{{ $settings->company_bank_info ?? '' }}</textarea>
</div>
