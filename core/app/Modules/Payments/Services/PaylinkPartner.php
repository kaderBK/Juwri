<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services;

use App\Models\StoreOption;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Models\Store;
use Juwri\Modules\Payments\Models\PaylinkOnboarding;

/**
 * PAYMENTS-RUN G10 — Paylink Partner API client + provisioning service.
 *
 * Platform (partner) credentials are PLATFORM-level (issued to us by Paylink),
 * never per-restaurant. The provisioning flow registers a sub-merchant, then —
 * on activation — pulls that merchant's API keys and writes them into the SAME
 * `restaurant_paylink_*` store_options the manual BYOK form uses, so there is ONE
 * credential read path (PaylinkController::creds()).
 *
 * SECURITY: the activation webhook is unsigned (Paylink's model), so activation
 * ALWAYS re-fetches merchant keys server-side with our partner creds before
 * flipping a restaurant live. The onboarding row is resolved from OUR stored
 * provider_merchant_id, never from the webhook body's tenant claim.
 */
class PaylinkPartner
{
    private const BASE_SANDBOX = 'https://restpilot.paylink.sa';

    private const BASE_LIVE = 'https://restapi.paylink.sa';

    /** @return array{api_id:?string, secret:?string, base:string} */
    public static function partnerCreds(): array
    {
        $sandbox = gateway_is_sandbox(config('settings.paylink_partner_sandbox', true));

        return [
            'api_id' => config('settings.paylink_partner_api_id'),
            'secret' => config('settings.paylink_partner_secret'),
            'base' => $sandbox ? self::BASE_SANDBOX : self::BASE_LIVE,
        ];
    }

    public static function configured(): bool
    {
        $c = self::partnerCreds();

        return ! empty($c['api_id']) && ! empty($c['secret']);
    }

    /** Partner Bearer token. */
    private static function token(array $creds): ?string
    {
        try {
            $resp = Http::acceptJson()->post($creds['base'].'/api/partner/auth', [
                'apiId' => $creds['api_id'],
                'secretKey' => $creds['secret'],
                'persistToken' => false,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[paylink-partner] auth failed: '.$e->getMessage());

            return null;
        }

        return $resp->successful() ? ($resp->json('id_token') ?: null) : null;
    }

    /**
     * Submit a merchant registration (Nafath-verified) for a restaurant. Creates
     * or updates the onboarding row and returns it. Idempotent per restaurant.
     *
     * @param  array{cr_number:string,owner_id_number:string,iban:string,mobile?:string,email?:string}  $data
     */
    public static function submitRegistration(int $storeId, array $data): PaylinkOnboarding
    {
        $store = Store::find($storeId);
        $onboarding = PaylinkOnboarding::firstOrNew(['store_id' => $storeId]);

        // Already live — do not re-register.
        if ($onboarding->exists && $onboarding->status === PaylinkOnboarding::STATUS_ACTIVE) {
            return $onboarding;
        }

        $onboarding->fill([
            'reseller_id' => $store->user->reseller_id ?? null,
            'cr_number' => $data['cr_number'] ?? null,
            'owner_id_number' => $data['owner_id_number'] ?? null,
            'iban' => $data['iban'] ?? null,
            'status' => PaylinkOnboarding::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);
        $onboarding->save();

        $creds = self::partnerCreds();
        $token = self::configured() ? self::token($creds) : null;

        if ($token) {
            try {
                $resp = Http::withToken($token)->acceptJson()->post($creds['base'].'/api/partner/merchant/register', [
                    'merchantReference' => (string) $storeId,
                    'crNumber' => $data['cr_number'] ?? null,
                    'ownerIdNumber' => $data['owner_id_number'] ?? null,
                    'iban' => $data['iban'] ?? null,
                    'mobile' => $data['mobile'] ?? ($store->phone ?? null),
                    'email' => $data['email'] ?? null,
                    'callBackUrl' => route('paylink.partner.webhook'),
                ]);

                if ($resp->successful()) {
                    $merchantId = $resp->json('merchantId') ?: $resp->json('merchant_id');
                    $onboarding->update([
                        'provider_merchant_id' => $merchantId,
                        'provider_status' => $resp->json('status') ?: 'pending',
                        'status' => PaylinkOnboarding::STATUS_PENDING_NAFATH,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('[paylink-partner] register failed: '.$e->getMessage());
            }
        }

        return $onboarding->fresh();
    }

    /**
     * Handle an activation signal (webhook or manual poll). Re-fetches merchant
     * keys server-side and, when active, provisions them onto the restaurant.
     * Idempotent: an already-active onboarding is a no-op.
     *
     * @return bool true when the restaurant was provisioned live in this call.
     */
    public static function handleActivation(string $providerMerchantId): bool
    {
        $onboarding = PaylinkOnboarding::where('provider_merchant_id', $providerMerchantId)->first();
        // Tenant resolved from OUR stored merchant id, never the webhook body.
        if (! $onboarding) {
            return false;
        }
        if ($onboarding->status === PaylinkOnboarding::STATUS_ACTIVE) {
            return false; // idempotent
        }

        $creds = self::partnerCreds();
        $token = self::configured() ? self::token($creds) : null;
        if (! $token) {
            return false;
        }

        try {
            $resp = Http::withToken($token)->acceptJson()
                ->get($creds['base'].'/api/partner/merchant/'.$providerMerchantId.'/keys');
        } catch (\Throwable $e) {
            Log::warning('[paylink-partner] getKeys failed: '.$e->getMessage());

            return false;
        }

        if (! $resp->successful()) {
            return false;
        }

        $status = strtolower((string) ($resp->json('status') ?? ''));
        if ($status === 'rejected') {
            $onboarding->update([
                'status' => PaylinkOnboarding::STATUS_REJECTED,
                'provider_status' => 'rejected',
                'reject_reason' => $resp->json('reason') ?: 'Rejected by Paylink',
            ]);

            return false;
        }

        $apiId = $resp->json('apiId') ?: $resp->json('api_id');
        $secret = $resp->json('secretKey') ?: $resp->json('secret_key');
        if (empty($apiId) || empty($secret)) {
            return false; // not yet active / keys not issued
        }

        // Provision onto the SAME store_options the manual BYOK form writes.
        $rid = $onboarding->store_id;
        StoreOption::updateStoreOption($rid, 'restaurant_paylink_api_id', $apiId);
        StoreOption::updateStoreOption($rid, 'restaurant_paylink_secret_key', $secret);
        StoreOption::updateStoreOption($rid, 'restaurant_paylink_sandbox_mode', gateway_is_sandbox(config('settings.paylink_partner_sandbox', true)) ? 'Yes' : 'No');
        StoreOption::updateStoreOption($rid, 'restaurant_paylink_install', '1');

        $onboarding->update([
            'status' => PaylinkOnboarding::STATUS_ACTIVE,
            'provider_status' => 'active',
            'activated_at' => now(),
        ]);

        return true;
    }
}
