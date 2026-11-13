{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Footer of a settings form: the section marker (tells the server which section is saved) + Save. --}}
<div class="ui-card__foot">
    <input type="hidden" name="{{ $marker }}" value="1">
    <x-ui.button type="submit" icon="check">{{ $label ?? ___('Save Changes') }}</x-ui.button>
</div>
