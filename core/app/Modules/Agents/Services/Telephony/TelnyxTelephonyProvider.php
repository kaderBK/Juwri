<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Services\Telephony;

use Illuminate\Http\Request;
use Juwri\Core\Models\Credential;
use Juwri\Modules\Agents\Models\PhoneLine;

/**
 * T6-P4 Telnyx driver — EXPERIMENTAL stub proving the TelephonyProvider abstraction is
 * not Twilio-shaped. Telnyx speaks TeXML (a TwiML-compatible dialect) and offers its own
 * text-over-WebSocket relay, so answer() mirrors the Twilio ConversationRelay shape. Live
 * number is DEFERRED; webhooks are verified with Telnyx's Ed25519 public-key scheme (V-15).
 */
class TelnyxTelephonyProvider implements TelephonyProvider
{
    public function key(): string
    {
        return 'telnyx';
    }

    public function dialledNumber(Request $request): ?string
    {
        return $request->input('To') ?: $request->input('to');
    }

    public function callerNumber(Request $request): ?string
    {
        return $request->input('From') ?: $request->input('from');
    }

    public function verifyWebhook(Request $request, PhoneLine $line): bool
    {
        // P6-09 V-15: Telnyx signs `{timestamp}|{raw body}` with Ed25519; the base64 signature arrives in
        // `telnyx-signature-ed25519`, the unix timestamp in `telnyx-timestamp`. The account public key (base64)
        // comes from the line's store credential, else config. Stale (> 5 min) or unsigned → reject.
        $creds = Credential::for($this->key(), (int) $line->store_id) ?? [];
        $publicKey = base64_decode((string) ($creds['public_key'] ?? config('services.telnyx.public_key', '')), true);
        $signature = base64_decode((string) $request->header('telnyx-signature-ed25519', ''), true);
        $timestamp = (string) $request->header('telnyx-timestamp', '');
        if ($publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || ! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($signature, $timestamp.'|'.$request->getContent(), $publicKey);
    }

    public function answer(PhoneLine $line, string $wsUrl): array
    {
        // TeXML is TwiML-compatible → same ConversationRelay-style connect.
        $greeting = htmlspecialchars(VoiceConsent::greeting($line), ENT_QUOTES);
        $body = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Response><Connect>'
            .'<ConversationRelay url="'.htmlspecialchars($wsUrl, ENT_QUOTES).'" welcomeGreeting="'.$greeting.'" />'
            .'</Connect></Response>';

        return [$body, 'text/xml'];
    }

    public function unavailable(PhoneLine $line): array
    {
        // TeXML mirrors TwiML, so the graceful-answer shape is identical.
        $body = '<?xml version="1.0" encoding="UTF-8"?><Response>';
        if ($line->handoff_number) {
            $body .= '<Dial>'.htmlspecialchars((string) $line->handoff_number, ENT_QUOTES).'</Dial>';
        } else {
            $body .= '<Say>'.htmlspecialchars((string) VoiceLocale::with($line, fn (): string => __('Sorry, automated ordering is not available on this line right now. Please call back later.')), ENT_QUOTES).'</Say><Hangup/>';
        }

        return [$body.'</Response>', 'text/xml'];
    }

    /**
     * P6-09 V-13: TeXML equivalent of the Twilio warm transfer — `<Dial><Number url>`
     * where TeXML plays the whisper URL's markup to the answering staff member.
     *
     * @return array{0: string, 1: string}
     */
    public function transfer(PhoneLine $line, string $number, string $summary): array
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Response><Dial>'
            .'<Number url="'.htmlspecialchars($this->whisperUrl($line, $summary), ENT_QUOTES).'">'
            .htmlspecialchars($number, ENT_QUOTES)
            .'</Number></Dial></Response>';

        return [$body, 'text/xml'];
    }

    private function whisperUrl(PhoneLine $line, string $summary): string
    {
        $base = (string) (config('services.telephony.whisper_base') ?: url('/'));

        return rtrim($base, '/').'/voice/'.$this->key().'/whisper/'.$line->id
            .'?summary='.rawurlencode($summary);
    }
}
