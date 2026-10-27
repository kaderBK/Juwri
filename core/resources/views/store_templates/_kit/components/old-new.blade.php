{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- old total (struck through) and new total, before the shopper confirms again --}}
<dl class="c-oldnew"><div><dt>{{ $kit->s('Old total') }}</dt><dd class="c-oldnew__old">{{ $kit->money($old) }}</dd></div><div><dt>{{ $kit->s('New total') }}</dt><dd>{{ $kit->money($new) }}</dd></div></dl>
