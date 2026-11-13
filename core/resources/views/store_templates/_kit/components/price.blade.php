{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{ ! empty($item['from_price']) ? $kit->t('from :amount', ['amount' => $kit->money($item['from_price'])]) : $kit->money($item['price']) }}