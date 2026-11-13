<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Reservations\Services\Rwg;

use App\Models\Reservation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * GOOGLE-PARTNER Lane C — Reserve-with-Google real-time updates (us → Google).
 *
 * Pushes booking-status changes (and an availability-replace hint for the affected
 * slot) so Google's cached inventory/booking state stays fresh between daily feeds.
 * Authenticates with a service-account RS256 JWT (the FcmV1Client pattern), exchanged
 * for an OAuth access token against Google's token endpoint.
 *
 * DARK by default: with no service-account JSON or no RTU endpoint configured,
 * enabled() is false and every push is a silent no-op — zero HTTP. Only Google-sourced
 * reservations are ever pushed (the caller gates on rwg_booking_id).
 */
class RwgRealtimeClient
{
    private const OAUTH_URL = 'https://oauth2.googleapis.com/token';
    private const TOKEN_TTL = 3300;

    /** Fully configured to talk to Google? */
    public function enabled(): bool
    {
        if (! RwgService::enabled()) {
            return false;
        }
        if (RwgService::rtuEndpoint() === '') {
            return false;
        }
        $sa = RwgService::serviceAccountJson();

        return $sa !== '' && is_file($sa);
    }

    /** Push a booking-status change for a Google-sourced reservation. Fail-soft. */
    public function pushBookingStatus(Reservation $reservation): void
    {
        if (! $this->enabled() || empty($reservation->rwg_booking_id)) {
            return; // dark / not a Google booking
        }

        $creds = $this->creds();
        if ($creds === null) {
            return;
        }
        $token = $this->accessToken($creds);
        if ($token === null) {
            return;
        }

        $storeId = (int) $reservation->store_id;
        $payload = [
            'booking' => [
                'booking_id' => (string) $reservation->rwg_booking_id,
                'status'     => $this->googleStatus((string) $reservation->status),
            ],
            // Availability-replace hint: the slot's covers changed, so Google should
            // re-pull availability for this merchant/service.
            'availability_stale' => [
                'merchant_id' => 'store-' . $storeId,
                'service_id'  => 'dining-' . $storeId,
            ],
        ];

        try {
            $res = Http::withToken($token)->timeout(10)->post(RwgService::rtuEndpoint(), $payload);
            if ($res->failed()) {
                Log::info('[rwg] rtu push failed', ['status' => $res->status(), 'booking' => $reservation->rwg_booking_id]);
            }
        } catch (\Throwable $e) {
            Log::info('[rwg] rtu transport error', ['booking' => $reservation->rwg_booking_id]);
        }
    }

    /** reservations.status → Google BookingStatus (kept in sync with the booking server). */
    private function googleStatus(string $status): string
    {
        return match ($status) {
            'confirmed', 'seated' => 'CONFIRMED',
            'cancelled'           => 'CANCELED',
            'no_show'             => 'NO_SHOW',
            default               => 'PENDING_MERCHANT_CONFIRMATION',
        };
    }

    /**
     * Decode the service-account JSON key file. Null when unreadable.
     *
     * @return array<string,mixed>|null
     */
    private function creds(): ?array
    {
        $path = RwgService::serviceAccountJson();
        if ($path === '' || ! is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * OAuth2 access token for the service account, cached for just under an hour.
     *
     * @param array<string,mixed> $creds
     */
    private function accessToken(array $creds): ?string
    {
        $clientEmail = (string) ($creds['client_email'] ?? '');
        if ($clientEmail === '' || empty($creds['private_key'])) {
            return null;
        }
        $cacheKey = 'rwg:rtu_token:' . hash('sha256', $clientEmail);

        try {
            $cached = Cache::get($cacheKey);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        } catch (\Throwable $e) {
            // broken cache must not stop a push
        }

        $assertion = $this->assertion($creds);
        if ($assertion === null) {
            return null;
        }

        try {
            $res = Http::asForm()->timeout(10)->post(self::OAUTH_URL, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $assertion,
            ]);
            $token = (string) $res->json('access_token', '');
            if ($res->failed() || $token === '') {
                Log::info('[rwg] rtu oauth failed', ['status' => $res->status()]);

                return null;
            }
            try {
                Cache::put($cacheKey, $token, self::TOKEN_TTL);
            } catch (\Throwable $e) {
                // cache is an optimisation
            }

            return $token;
        } catch (\Throwable $e) {
            Log::info('[rwg] rtu oauth transport error');

            return null;
        }
    }

    /**
     * Signed RS256 JWT assertion (FcmV1Client pattern). Null when the key will not load.
     *
     * @param array<string,mixed> $creds
     */
    private function assertion(array $creds): ?string
    {
        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss'   => $creds['client_email'],
            'scope' => RwgService::rtuScope(),
            'aud'   => self::OAUTH_URL,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];
        $input = $this->b64((string) json_encode($header)) . '.' . $this->b64((string) json_encode($claims));

        try {
            $key = openssl_pkey_get_private((string) $creds['private_key']);
            if ($key === false) {
                return null;
            }
            $signature = '';
            if (! openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) {
                return null;
            }
        } catch (\Throwable $e) {
            return null;
        }

        return $input . '.' . $this->b64($signature);
    }

    private function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
