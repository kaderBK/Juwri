{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- basket: the server quote — rows in the server's order (items, delivery with its time, what weighed items may add), the total
     under its server label ("Estimated total" while a weighed item is still to be weighed), the tax note. Core JS fills it. --}}
<dl class="c-quote c-quote--basket" data-juwri-rows>
    <template data-juwri-row><div class="c-quote__row"><dt data-juwri-row-label></dt><dd data-juwri-row-amount></dd></div></template>
</dl>
<dl class="c-quote c-quote--basket c-quote--total"><div class="c-quote__row c-quote__row--total"><dt data-juwri-total-label>{{ $kit->s('Total') }}</dt><dd data-juwri-total></dd></div></dl>
<p class="c-quote__note" data-juwri-tax-note hidden></p>
