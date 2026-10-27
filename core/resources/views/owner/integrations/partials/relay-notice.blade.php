{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Owner decision A: a partner-credential connector (HungerStation, Jahez, Keeta, Uber Eats, Deliverect, Foodics, Marn)
     reaches its partner DIRECTLY in the Community build, with the owner's own partner credentials; juwri Cloud runs it
     through the juwri Cloud partner relay. Shown only while the direct transport is the bound one. --}}
@if(app(\Juwri\Modules\Channels\Contracts\PartnerTransport::class) instanceof \Juwri\Modules\Channels\Services\Transport\DirectPartnerTransport)
    <div class="ui-note" data-tone="info" data-testid="partner-relay-notice" role="note">{{ ___('Direct connection: this build reaches the partner with your own partner credentials. On juwri Cloud this connector runs through the juwri Cloud relay.') }}</div>
@endif
