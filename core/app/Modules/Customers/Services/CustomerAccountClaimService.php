<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Customers\Services;

use App\Models\Customer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Juwri\Core\Models\Store;
use Juwri\Modules\Customers\Mail\CustomerClaimVerify;

/**
 * GOL A4 (rev/guest-order-linking): the P3 email-verified claim path. When a
 * guest registers with an email that already backs a passwordless "shadow"
 * Customer (i.e. it carries previous guest orders) and there is NO same-browser
 * session proof, we must NOT log them in on an unverified email — that would let
 * anyone type a stranger's address and inherit their order history. Instead we
 * park their chosen password hash and email a signed link; clicking it proves
 * inbox ownership, which the verify action (CustomerAuthController::verifyClaim)
 * then trusts to activate the password and claim the orders.
 */
class CustomerAccountClaimService
{
    /** Max verification emails per address per hour (anti email-bombing). */
    private const RL_MAX = 3;

    private const RL_DECAY = 3600;

    /** Signed link lifetime. */
    private const LINK_HOURS = 48;

    /**
     * Park $pendingPasswordHash on the shadow and email a signed verify link.
     * Returns [ok, message]. ok=false only on throttle (the caller still shows a
     * neutral "check your inbox" message so the endpoint is not an oracle for
     * which emails have orders).
     *
     * @return array{0:bool,1:string}
     */
    public static function sendClaimEmail(Customer $shadow, string $pendingPasswordHash): array
    {
        // Never send for a row that is already a real account.
        if (! empty($shadow->password)) {
            return [false, ___('This email is already registered. Please log in.')];
        }

        $email = (string) $shadow->email;
        if ($email === '') {
            return [false, ___('No email on file.')];
        }

        $rlKey = 'customer-claim-email:'.mb_strtolower($email).':'.$shadow->store_id;
        if (RateLimiter::tooManyAttempts($rlKey, self::RL_MAX)) {
            return [false, ___('Too many requests. Please try again later.')];
        }
        $shadow->forceFill(['pending_password' => $pendingPasswordHash])->save();

        $verifyUrl = URL::temporarySignedRoute(
            'customer.claim.verify',
            now()->addHours(self::LINK_HOURS),
            ['store' => $shadow->store_id, 'customer' => $shadow->id]
        );

        $restaurantName = (string) (optional(Store::find($shadow->store_id))->title ?? config('settings.site_title'));

        // P6-192: queue after commit; a mail/queue failure is a plain message, never a 500,
        // and the rate-limit attempt is only spent once the mail is actually handed off.
        try {
            Mail::to($email)->queue((new CustomerClaimVerify(
                $restaurantName,
                $verifyUrl,
                (string) ($shadow->name ?? '')
            ))->afterCommit());
        } catch (\Throwable $e) {
            Log::warning('[customer-claim] verify mail not sent: '.$e->getMessage(), ['customer_id' => $shadow->id]);

            return [false, ___('We could not send the email. Please try again.')];
        }
        RateLimiter::hit($rlKey, self::RL_DECAY);

        return [true, ___('Check your inbox to finish setting up your account.')];
    }
}
