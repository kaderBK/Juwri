{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Subscriber field shared by the create and edit panels. $subscriber is null on create. --}}
<x-ui.field type="email" name="email" :label="___('Email').' *'" :value="$subscriber?->email" required />
