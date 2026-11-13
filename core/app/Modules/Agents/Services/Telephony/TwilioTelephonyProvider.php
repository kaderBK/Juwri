<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Services\Telephony;

use Illuminate\Http\Request;
use Juwri\Core\Models\Credential;
use Juwri\Modules\Agents\Models\PhoneLine;

/**
 * T6 Twilio Programmable Voice via ConversationRelay (text-over-WebSocket): Twilio does
 * STT/TTS + barge-in; our bridge exchanges text. Inbound-call webhooks are verified with
 * the X-Twilio-Signature scheme (HMAC-SHA1 of the full URL + sorted POST params, base64,
 * keyed with the auth token). Live number + auth token are BYOK (deferred).
 */
class TwilioTelephonyProvider implements TelephonyProvider
{
    public function key(): string
    {
        return 'twilio';
    }

    public function dialledNumber(Request $request): ?string
    {
        return $request->input('To') ?: $request->input('Called');
    }

    public function callerNumber(Request $request): ?string
    {
        return $request->input('From') ?: $request->input('Caller');
    }

    public function verifyWebhook(Request $request, PhoneLine $line): bool
    {
        $creds = Credential::for($this->key(), (int) $line->store_id) ?? [];
        $token = $creds['auth_token'] ?? null;
        if (! $token) {
            return false;
        }
        $signature = (string) $request->header('X-Twilio-Signature', '');
        if ($signature === '') {
            return false;
        }
        // Twilio: base64(HMAC-SHA1(fullUrl + sorted key.value POST params, authToken)).
        $data = $request->fullUrl();
        $params = $request->post();
        ksort($params);
        foreach ($params as $k => $v) {
            $data .= $k.$v;
        }
        $expected = base64_encode(hash_hmac('sha1', $data, $token, true));

        return hash_equals($expected, $signature);
    }

    public function answer(PhoneLine $line, string $wsUrl): array
    {
        $greeting = htmlspecialchars(VoiceConsent::greeting($line), ENT_QUOTES);
        // V-19: the relay's STT/TTS and the welcome line speak the line's language.
        $code = VoiceLocale::forLine($line) === 'ar' ? 'ar-SA' : 'en-US';
        $body = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Response><Connect>'
            .'<ConversationRelay url="'.htmlspecialchars($wsUrl, ENT_QUOTES).'"'
            .' welcomeGreeting="'.$greeting.'"'
            .' language="'.$code.'"'
            .' transcriptionLanguage="'.$code.'"'
            .' ttsLanguage="'.$code.'"'
            .' ttsProvider="ElevenLabs" transcriptionProvider="Deepgram" interruptible="speech" />'
            .'</Connect></Response>';

        return [$body, 'text/xml'];
    }

    public function unavailable(PhoneLine $line): array
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?><Response>';
        if ($line->handoff_number) {
            $body .= '<Dial>'.htmlspecialchars((string) $line->handoff_number, ENT_QUOTES).'</Dial>';
        } else {
            $say = (string) VoiceLocale::with($line, fn (): string => __('Sorry, automated ordering is not available on this line right now. Please call back later.'));
            $body .= '<Say>'.htmlspecialchars($say, ENT_QUOTES).'</Say><Hangup/>';
        }

        return [$body.'</Response>', 'text/xml'];
    }

    /**
     * P6-09 V-13: warm transfer. Twilio plays the TwiML served at the `<Number url>`
     * to the agent who answers (the documented "whisper" pattern) before bridging the
     * caller; the summary travels on that whisper URL. All interpolated text is
     * escaped. The whisper endpoint itself is served by the voice bridge (V-03+).
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
