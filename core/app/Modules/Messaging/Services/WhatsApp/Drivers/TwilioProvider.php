<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Services\WhatsApp\Drivers;

use Juwri\Modules\Messaging\Services\WhatsApp\AbstractWhatsAppProvider;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppProviderException;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppResult;
use Illuminate\Support\Facades\Http;

/**
 * Twilio WhatsApp driver. Sends via the Twilio Messages API using the
 * `whatsapp:` channel prefix. Free-form text is allowed inside the 24h session
 * window; outside it Twilio requires an approved Content template (ContentSid).
 *
 * Secrets (from the Credential vault, feature='whatsapp', provider='twilio'):
 *   {sid|twilio_sid, auth_token|twilio_token, from_number|twilio_from}
 *
 * See rev/whatsapp/IMPLEMENTATION-SPEC.md §5.2 / §15.4.
 */
class TwilioProvider extends AbstractWhatsAppProvider
{
    public function name(): string
    {
        return 'twilio';
    }

    public function isConfigured(): bool
    {
        return $this->sid() !== '' && $this->token() !== '' && $this->from() !== '';
    }

    public function capabilities(): array
    {
        return array_merge(parent::capabilities(), [
            'send' => true, 'template' => true, 'inbound' => true, 'status' => true, 'media' => true,
        ]);
    }

    public function sendText(string $to, string $body, array $opts = []): WhatsAppResult
    {
        return $this->post([
            'To' => $this->wa($to),
            'From' => $this->wa($this->from()),
            'Body' => $body,
        ]);
    }

    public function sendTemplate(string $to, string $template, array $vars = [], array $opts = []): WhatsAppResult
    {
        // Twilio approved templates are addressed by ContentSid; positional
        // variables are passed as a JSON map {"1":"..","2":".."}.
        $payload = [
            'To' => $this->wa($to),
            'From' => $this->wa($this->from()),
            'ContentSid' => $template,
        ];
        if ($vars) {
            $payload['ContentVariables'] = json_encode($this->positional($vars));
        }

        return $this->post($payload);
    }

    /**
     * Twilio signature: base64(HMAC-SHA1(authToken, url + sorted param k+v)).
     * Rejects when no token is configured, except in the local environment (C1).
     */
    public function authenticate(array $headers, string $rawBody, string $url, array $params): bool
    {
        $token = $this->token();
        if ($token === '') {
            return app()->environment('local'); // C1: fail closed outside local
        }
        $signature = $headers['x-twilio-signature'][0] ?? $headers['X-Twilio-Signature'][0] ?? '';
        if ($signature === '') {
            return false;
        }

        ksort($params);
        $data = $url;
        foreach ($params as $k => $v) {
            $data .= $k.$v;
        }
        $expected = base64_encode(hash_hmac('sha1', $data, $token, true));

        return hash_equals($expected, $signature);
    }

    public function parseInbound(array $payload): ?array
    {
        if (empty($payload['From']) || ! isset($payload['Body'])) {
            return null;
        }

        return [
            'from' => $this->stripWa((string) $payload['From']),
            'body' => (string) $payload['Body'],
            'message_id' => $payload['MessageSid'] ?? $payload['SmsMessageSid'] ?? null,
            'media' => [],
            'to' => isset($payload['To']) ? $this->stripWa((string) $payload['To']) : null,
        ];
    }

    public function parseStatus(array $payload): ?array
    {
        if (empty($payload['MessageStatus']) || empty($payload['MessageSid'])) {
            return null;
        }

        // queued|sent|delivered|read|failed|undelivered
        return [
            'provider_msg_id' => $payload['MessageSid'],
            'status' => $payload['MessageStatus'] === 'undelivered' ? 'failed' : $payload['MessageStatus'],
            'error' => $payload['ErrorCode'] ?? null,
        ];
    }

    /**
     * C1: Twilio posts `To` on inbound AND on status callbacks.
     *
     * @param  array<string, mixed>  $payload
     */
    public function tenantHint(array $payload): ?string
    {
        return empty($payload['To']) ? null : $this->stripWa((string) $payload['To']);
    }

    // --- internals --------------------------------------------------------

    protected function post(array $form): WhatsAppResult
    {
        $url = "https://api.twilio.com/2010-04-01/Accounts/{$this->sid()}/Messages.json";

        try {
            $res = Http::asForm()
                ->withBasicAuth($this->sid(), $this->token())
                ->timeout((int) config('whatsapp.timeout', 15))
                ->post($url, $form);
        } catch (\Throwable $e) {
            throw new WhatsAppProviderException($e->getMessage(), 'twilio', retryable: true, previous: $e);
        }

        if ($res->successful()) {
            $json = $res->json();

            return WhatsAppResult::sent('twilio', $json['sid'] ?? null);
        }

        // 429/5xx are retryable; other 4xx are terminal (bad number, bad template…).
        $retryable = $res->status() === 429 || $res->serverError();
        $code = (string) ($res->json('code') ?? $res->status());
        throw new WhatsAppProviderException(
            'Twilio send failed: '.$res->body(),
            'twilio',
            retryable: $retryable,
            errorCode: $code,
        );
    }

    /** Map [a,b] or ['1'=>a] to Twilio positional {"1":a,"2":b}. */
    protected function positional(array $vars): array
    {
        if (array_is_list($vars)) {
            $out = [];
            foreach ($vars as $i => $v) {
                $out[(string) ($i + 1)] = $v;
            }

            return $out;
        }

        return $vars;
    }

    protected function wa(string $number): string
    {
        $number = trim($number);

        return str_starts_with($number, 'whatsapp:') ? $number : 'whatsapp:'.$number;
    }

    protected function stripWa(string $number): string
    {
        return str_starts_with($number, 'whatsapp:') ? substr($number, 9) : $number;
    }

    protected function sid(): string
    {
        return (string) ($this->config['sid'] ?? $this->config['twilio_sid'] ?? '');
    }

    protected function token(): string
    {
        return (string) ($this->config['auth_token'] ?? $this->config['twilio_token'] ?? '');
    }

    protected function from(): string
    {
        return (string) ($this->config['from_number'] ?? $this->config['twilio_from'] ?? $this->config['from'] ?? '');
    }
}
