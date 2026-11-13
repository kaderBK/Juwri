<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Reservations\Services\Rwg\Feeds;


/**
 * GOOGLE-PARTNER Lane C — Reserve-with-Google Services feed.
 *
 * One dine-in reservation service per merchant. prepayment_type NOT_SUPPORTED — this
 * pack does not onboard to Google payments; deposit-required stores are handled by
 * the booking server (C5), not by advertising a prepayment here.
 */
class ServicesFeedBuilder implements RwgFeedContributor
{
    public function __construct(private RwgMerchantSource $source)
    {
    }

    public function filename(): string
    {
        return 'service_feed.json';
    }

    public function build(): array
    {
        // F0 WIRING (handshake D5→C): the Services feed is a SINGLE file, so Lane D's
        // waitlist service entries are appended here rather than shipped as a second
        // same-filename contributor. Each merchant emits its dining-reservation
        // service and, when the store opted its RwG waitlist in, an additional
        // waitlist service (distinct service_id). Waitlist-only stores (waitlist on,
        // reservations off) are not in the reservation-keyed merchant set yet — a
        // documented follow-up.
        $waitlist = new WaitlistServiceFeedBuilder();
        $services = [];
        foreach ($this->source->merchants() as $m) {
            $services[] = [
                'merchant_id'     => $m['merchant_id'],
                'service_id'      => $m['service_id'],
                'name'            => 'Table reservation',
                'description'     => 'Dine-in table reservation',
                'type'            => 'SERVICE_TYPE_DINING_RESERVATION',
                'prepayment_type' => 'NOT_SUPPORTED',
            ];

            $waitlistService = $waitlist->buildService($m['store'], $m['merchant_id']);
            if ($waitlistService !== null) {
                $services[] = $waitlistService;
            }
        }

        return [
            'metadata' => RwgFeedBuilder::metadata(),
            'service'  => $services,
        ];
    }
}
