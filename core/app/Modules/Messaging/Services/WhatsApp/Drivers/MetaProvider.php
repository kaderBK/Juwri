<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Services\WhatsApp\Drivers;

use Juwri\Modules\Messaging\Services\WhatsApp\AbstractWhatsAppProvider;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppProviderException;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppResult;
use Illuminate\Support\Facades\Http;

/**
 * WhatsApp Cloud API (Meta) driver. Sends via the Graph API and parses Meta's
 * nested webhook envelope. Supports text, approved templates, interactive
 * (buttons/list), catalog and Flow messages.
 *
 * Secrets (Credential vault, feature='whatsapp', provider='meta'):
 *   {access_token, phone_number_id, business_account_id?, webhook_verify_token?, app_secret?}
 *
 * See rev/whatsapp/IMPLEMENTATION-SPEC.md §5.3 / §14.4 / §15.2.
 */
class MetaProvider extends AbstractWhatsAppProvider
{
    public function name(): string
    {
        return 'meta';
    }

    public function isConfigured(): bool
    {
        return $this->token() !== '' && $this->phoneNumberId() !== '';
    }

    public function capabilities(): array
    {
        return [
            'send' => true, 'template' => true, 'interactive' => true,
            'catalog' => true, 'flow' => true, 'inbound' => true,
            'status' => true, 'media' => true,
        ];
    }

    public function sendText(string $to, string $body, array $opts = []): WhatsAppResult
    {
        return $this->post([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->e164($to),
            'type' => 'text',
            'text' => ['body' => $body, 'preview_url' => (bool) ($opts['preview_url'] ?? false)],
        ]);
    }

    public function sendTemplate(string $to, string $template, array $vars = [], array $opts = []): WhatsAppResult
    {
        $tpl = [
            'name' => $template,
            'language' => ['code' => $opts['language'] ?? 'en'],
        ];
        if ($vars) {
            $tpl['components'] = [[
                'type' => 'body',
                'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => (string) $v], array_values($vars)),
            ]];
        }

        return $this->post([
            'messaging_product' => 'whatsapp',
            'to' => $this->e164($to),
            'type' => 'template',
            'template' => $tpl,
        ]);
    }

    public function sendInteractive(string $to, array $interactive, array $opts = []): WhatsAppResult
    {
        return $this->post([
            'messaging_product' => 'whatsapp',
            'to' => $this->e164($to),
            'type' => 'interactive',
            'interactive' => $interactive,
        ]);
    }

    public function sendCatalog(string $to, array $products, array $opts = []): WhatsAppResult
    {
        return $this->post([
            'messaging_product' => 'whatsapp',
            'to' => $this->e164($to),
            'type' => 'interactive',
            'interactive' => [
                'type' => 'product_list',
                'header' => ['type' => 'text', 'text' => $opts['header'] ?? 'Menu'],
                'body' => ['text' => $opts['body'] ?? 'Browse and order'],
                'action' => [
                    'catalog_id' => $opts['catalog_id'] ?? '',
                    'product_retailer_id' => $opts['thumbnail'] ?? ($products[0]['product_retailer_id'] ?? ''),
                    'sections' => $opts['sections'] ?? [['title' => 'Items', 'product_items' => $products]],
                ],
            ],
        ]);
    }

    public function sendFlow(string $to, string $flowId, array $data = [], array $opts = []): WhatsAppResult
    {
        return $this->post([
            'messaging_product' => 'whatsapp',
            'to' => $this->e164($to),
            'type' => 'interactive',
            'interactive' => [
                'type' => 'flow',
                'body' => ['text' => $opts['body'] ?? 'Tap to continue'],
                'action' => [
                    'name' => 'flow',
                    'parameters' => array_merge([
                        'flow_message_version' => '3',
                        'flow_id' => $flowId,
                        'flow_cta' => $opts['cta'] ?? 'Open',
                    ], $data ? ['flow_action_payload' => ['data' => $data]] : []),
                ],
            ],
        ]);
    }

    public function verifyWebhook(array $query, array $headers, string $raw): bool|string
    {
        $token = (string) ($this->config['webhook_verify_token'] ?? '');
        if (($query['hub_mode'] ?? $query['hub.mode'] ?? '') === 'subscribe'
            && $token !== ''
            && ($query['hub_verify_token'] ?? $query['hub.verify_token'] ?? '') === $token) {
            return (string) ($query['hub_challenge'] ?? $query['hub.challenge'] ?? '');
        }

        return false;
    }

    public function authenticate(array $headers, string $rawBody, string $url, array $params): bool
    {
        $secret = (string) ($this->config['app_secret'] ?? '');
        if ($secret === '') {
            /* C1: fail CLOSED. This used to `return true`, which meant any
               unsigned POST was accepted whenever no app secret was resolved —
               and the webhook controller resolved secrets with a null tenant, so
               a BYOK tenant's own secret was never even consulted. The permissive
               branch survives for local fixture work only. */
            return app()->environment('local');
        }
        $header = $headers['x-hub-signature-256'][0] ?? $headers['X-Hub-Signature-256'][0] ?? '';
        if (! str_starts_with($header, 'sha256=')) {
            return false;
        }
        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $header);
    }

    public function parseInbound(array $payload): ?array
    {
        $value = $this->firstChangeValue($payload);
        $msg = $value['messages'][0] ?? null;
        if (! $msg) {
            return null;
        }
        $body = $msg['text']['body']
            ?? $msg['button']['text']
            ?? $msg['interactive']['button_reply']['title']
            ?? $msg['interactive']['list_reply']['title']
            ?? '';
        $name = $value['contacts'][0]['profile']['name'] ?? null;

        return [
            'from' => '+'.ltrim((string) ($msg['from'] ?? ''), '+'),
            'body' => $body,
            'message_id' => $msg['id'] ?? null,
            'contact_name' => $name,
            'media' => [],
            'to' => $value['metadata']['phone_number_id'] ?? null,
        ];
    }

    public function parseStatus(array $payload): ?array
    {
        $value = $this->firstChangeValue($payload);
        $st = $value['statuses'][0] ?? null;
        if (! $st) {
            return null;
        }

        return [
            'provider_msg_id' => $st['id'] ?? null,
            'status' => $st['status'] ?? 'sent', // sent|delivered|read|failed
            'error' => $st['errors'][0]['code'] ?? null,
        ];
    }

    public function parseHealth(array $payload): ?array
    {
        $change = $payload['entry'][0]['changes'][0] ?? [];
        $field = $change['field'] ?? '';
        if (! in_array($field, ['phone_number_quality_update', 'account_update', 'account_alerts'], true)) {
            return null;
        }
        $v = $change['value'] ?? [];
        $sender = $v['display_phone_number'] ?? $v['phone_number'] ?? null;
        if (! $sender) {
            return null;
        }

        // event: FLAGGED | UNFLAGGED | RESTRICTED (varies by field)
        $event = strtoupper((string) ($v['event'] ?? ''));
        [$status, $quality] = match ($event) {
            'FLAGGED' => ['flagged', 'red'],
            'RESTRICTED' => ['restricted', 'red'],
            'UNFLAGGED', 'RESOLVED' => ['connected', 'green'],
            default => ['connected', $this->qualityWord($v['current_quality_score'] ?? $v['quality'] ?? null)],
        };

        return [
            'sender_id' => $sender,
            'status' => $status,
            'quality' => $quality,
            'messaging_tier' => $v['current_limit'] ?? $v['max_daily_conversation_per_phone'] ?? null,
        ];
    }

    /**
     * C1: metadata.phone_number_id is present on inbound AND status envelopes.
     *
     * @param  array<string, mixed>  $payload
     */
    public function tenantHint(array $payload): ?string
    {
        $id = $this->firstChangeValue($payload)['metadata']['phone_number_id'] ?? null;

        return $id ? (string) $id : parent::tenantHint($payload);
    }

    protected function qualityWord($raw): string
    {
        $r = strtoupper((string) $raw);

        return match ($r) {
            'GREEN', 'HIGH' => 'green',
            'YELLOW', 'MEDIUM' => 'yellow',
            'RED', 'LOW' => 'red',
            default => 'unknown',
        };
    }

    // --- internals --------------------------------------------------------

    protected function firstChangeValue(array $payload): array
    {
        return $payload['entry'][0]['changes'][0]['value'] ?? [];
    }

    protected function post(array $json): WhatsAppResult
    {
        $version = config('whatsapp.meta_version', 'v21.0');
        $url = "https://graph.facebook.com/{$version}/{$this->phoneNumberId()}/messages";

        try {
            $res = Http::withToken($this->token())
                ->timeout((int) config('whatsapp.timeout', 15))
                ->post($url, $json);
        } catch (\Throwable $e) {
            throw new WhatsAppProviderException($e->getMessage(), 'meta', retryable: true, previous: $e);
        }

        if ($res->successful()) {
            return WhatsAppResult::sent('meta', $res->json('messages.0.id'));
        }

        $code = (string) ($res->json('error.code') ?? $res->status());
        // 429 rate-limit, 131048 spam-rate, 133x throttles and 5xx are retryable.
        $retryable = $res->status() === 429 || $res->serverError() || in_array($code, ['130429', '131048', '131056'], true);
        throw new WhatsAppProviderException(
            'Meta send failed: '.(string) ($res->json('error.message') ?? $res->body()),
            'meta',
            retryable: $retryable,
            errorCode: $code,
        );
    }

    protected function e164(string $number): string
    {
        return ltrim(preg_replace('/\D+/', '', $number), '0') ?: preg_replace('/\D+/', '', $number);
    }

    protected function token(): string
    {
        return (string) ($this->config['access_token'] ?? '');
    }

    protected function phoneNumberId(): string
    {
        return (string) ($this->config['phone_number_id'] ?? '');
    }
}
