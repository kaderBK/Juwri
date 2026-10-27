<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Services\Telephony;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Models\Credential;
use Juwri\Modules\Agents\Models\PhoneLine;

/**
 * P6-09 provider wiring: when an owner connects a line, point the provider's call-end / status callback at our
 * V-12 endpoint (`POST /voice/{provider}/status`). Twilio: the number's StatusCallback. Telnyx: the TeXML
 * application (connection) status callback. Idempotent (re-setting the same URL is a no-op provider side), and
 * best effort: a missing credential or a provider error never blocks saving the line. Secrets are never logged.
 */
class CallbackConfigurator
{
    public function configure(PhoneLine $line): bool
    {
        // Store's own credential only: never fall back to the platform default account.
        $creds = Credential::where('store_id', (int) $line->store_id)->where('feature', $line->provider)->where('active', true)->first()->secrets ?? [];
        $url = $this->statusUrl($line->provider);

        try {
            return match ($line->provider) {
                'twilio' => $this->twilio($line, $creds, $url),
                'telnyx' => $this->telnyx($creds, $url),
                default => false,
            };
        } catch (\Throwable $e) {
            Log::warning('phone-agent callback wiring failed', ['provider' => $line->provider, 'line' => $line->id, 'error' => get_class($e)]);

            return false;
        }
    }

    public function statusUrl(string $provider): string
    {
        $base = (string) (config('services.telephony.status_base') ?: config('app.url'));

        return rtrim($base, '/').'/voice/'.$provider.'/status';
    }

    /** @param array<string, mixed> $creds */
    private function twilio(PhoneLine $line, array $creds, string $url): bool
    {
        $sid = (string) ($creds['account_sid'] ?? '');
        $token = (string) ($creds['auth_token'] ?? '');
        if ($sid === '' || $token === '') {
            return false;
        }
        $api = 'https://api.twilio.com/2010-04-01/Accounts/'.rawurlencode($sid).'/IncomingPhoneNumbers';
        $found = Http::timeout(10)->withBasicAuth($sid, $token)->acceptJson()->get($api.'.json', ['PhoneNumber' => $line->number]);
        $numberSid = $found->successful() ? ($found->json('incoming_phone_numbers.0.sid') ?? null) : null;
        if (! $numberSid) {
            return false;
        }

        return Http::timeout(10)->withBasicAuth($sid, $token)->asForm()->acceptJson()
            ->post($api.'/'.rawurlencode($numberSid).'.json', [
                'StatusCallback' => $url,
                'StatusCallbackMethod' => 'POST',
            ])->successful();
    }

    /** @param array<string, mixed> $creds */
    private function telnyx(array $creds, string $url): bool
    {
        $key = (string) ($creds['api_key'] ?? '');
        $connection = (string) ($creds['connection_id'] ?? '');
        if ($key === '' || $connection === '') {
            return false;
        }

        return Http::timeout(10)->withToken($key)->acceptJson()
            ->patch('https://api.telnyx.com/v2/texml_applications/'.rawurlencode($connection), [
                'status_callback' => $url,
                'status_callback_method' => 'POST',
            ])->successful();
    }
}
