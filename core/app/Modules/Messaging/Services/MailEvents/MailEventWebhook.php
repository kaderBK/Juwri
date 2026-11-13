<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Services\MailEvents;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Juwri\Modules\Messaging\Models\EmailSuppression;

/**
 * P6-249: authenticate and read one bounce/complaint webhook from a mail provider. Every provider REFUSES when its
 * secret is not configured (a blank HMAC key or an empty allow-list would let anyone forge "this address bounced").
 *
 *  - ses:      Amazon SNS. RSA signature over the SNS canonical string with the AWS cert (https://sns.<region>.amazonaws.com/*.pem),
 *              and the TopicArn must equal services.mail_events.ses_topic_arn.
 *  - mailgun:  HMAC-SHA256(timestamp.token) with the webhook signing key; timestamp within 15 minutes; token used once.
 *  - postmark: HTTP Basic auth with the configured user + password (Postmark does not sign its webhooks).
 *  - sendgrid: ECDSA P-256 / SHA-256 over timestamp.body with the Event Webhook verification key; timestamp within 15 minutes.
 */
final class MailEventWebhook
{
    public const PROVIDERS = ['ses', 'mailgun', 'postmark', 'sendgrid'];

    private const MAX_SKEW = 900;

    /** @return bool true when the request is authentic */
    public function verify(string $provider, Request $request): bool
    {
        return match ($provider) {
            'ses' => $this->verifySes($request),
            'mailgun' => $this->verifyMailgun($request),
            'postmark' => $this->verifyPostmark($request),
            'sendgrid' => $this->verifySendgrid($request),
            default => false,
        };
    }

    /**
     * The hard bounces and complaints in an authenticated payload.
     *
     * @return list<array{email: string, reason: string, detail: ?string}>
     */
    public function events(string $provider, Request $request): array
    {
        return match ($provider) {
            'ses' => $this->sesEvents($request),
            'mailgun' => $this->mailgunEvents($request),
            'postmark' => $this->postmarkEvents($request),
            'sendgrid' => $this->sendgridEvents($request),
            default => [],
        };
    }

    /** @return int how many addresses were recorded */
    public function record(string $provider, Request $request): int
    {
        $n = 0;
        foreach ($this->events($provider, $request) as $e) {
            if (EmailSuppression::record($e['email'], $e['reason'], $provider, $e['detail']) !== null) {
                $n++;
            }
        }

        return $n;
    }

    /** SNS subscription handshake: confirm only an authenticated message from our topic, only on an AWS SNS host. */
    public function confirmSesSubscription(Request $request): bool
    {
        $msg = $this->json($request);
        if (($msg['Type'] ?? '') !== 'SubscriptionConfirmation') {
            return false;
        }
        $url = (string) ($msg['SubscribeURL'] ?? '');
        if (! self::isSnsHost($url)) {
            return false;
        }

        return Http::timeout(10)->get($url)->successful();
    }

    // ---- SES / SNS -------------------------------------------------------------------------------------------

    private function verifySes(Request $request): bool
    {
        $topic = trim((string) config('services.mail_events.ses_topic_arn'));
        $msg = $this->json($request);
        if ($topic === '' || $msg === [] || ! hash_equals($topic, (string) ($msg['TopicArn'] ?? ''))) {
            return false;
        }
        $type = (string) ($msg['Type'] ?? '');
        $fields = match ($type) {
            'Notification' => ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'],
            'SubscriptionConfirmation', 'UnsubscribeConfirmation' => ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'],
            default => null,
        };
        if ($fields === null) {
            return false;
        }
        $ts = strtotime((string) ($msg['Timestamp'] ?? ''));
        if ($ts === false || abs(time() - $ts) > 3600) {
            return false; // SNS retries for up to an hour
        }

        $string = '';
        foreach ($fields as $f) {
            if ($f === 'Subject' && ! array_key_exists('Subject', $msg)) {
                continue;
            }
            if (! isset($msg[$f]) || ! is_string($msg[$f])) {
                return false;
            }
            $string .= $f."\n".$msg[$f]."\n";
        }

        $algo = match ((string) ($msg['SignatureVersion'] ?? '')) {
            '1' => OPENSSL_ALGO_SHA1,
            '2' => OPENSSL_ALGO_SHA256,
            default => null,
        };
        $signature = base64_decode((string) ($msg['Signature'] ?? ''), true);
        $certUrl = (string) ($msg['SigningCertURL'] ?? '');
        if ($algo === null || $signature === false || $signature === '' || ! self::isSnsHost($certUrl) || ! str_ends_with((string) parse_url($certUrl, PHP_URL_PATH), '.pem')) {
            return false;
        }
        $cert = $this->snsCertificate($certUrl);
        if ($cert === null) {
            return false;
        }
        $key = openssl_pkey_get_public($cert);

        return $key !== false && openssl_verify($string, $signature, $key, $algo) === 1;
    }

    public static function isSnsHost(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && ($parts['scheme'] ?? '') === 'https'
            && ! isset($parts['user']) && ! isset($parts['port'])
            && preg_match('/^sns\.[a-z0-9-]+\.amazonaws\.com(\.cn)?$/', strtolower((string) ($parts['host'] ?? ''))) === 1;
    }

    private function snsCertificate(string $url): ?string
    {
        $pem = Cache::remember('mail-events:sns-cert:'.hash('sha256', $url), 86400, function () use ($url) {
            $r = Http::timeout(10)->withOptions(['allow_redirects' => false])->get($url);
            $body = $r->successful() ? (string) $r->body() : '';

            return str_contains($body, 'BEGIN CERTIFICATE') ? $body : '';
        });

        return $pem === '' ? null : $pem;
    }

    /** @return list<array{email: string, reason: string, detail: ?string}> */
    private function sesEvents(Request $request): array
    {
        $outer = $this->json($request);
        if (($outer['Type'] ?? '') !== 'Notification') {
            return [];
        }
        $m = json_decode((string) ($outer['Message'] ?? ''), true);
        if (! is_array($m)) {
            return [];
        }
        $type = $m['notificationType'] ?? $m['eventType'] ?? null;
        $out = [];
        if ($type === 'Bounce' && (($m['bounce']['bounceType'] ?? '') === 'Permanent')) {
            foreach ((array) ($m['bounce']['bouncedRecipients'] ?? []) as $r) {
                $out[] = ['email' => (string) ($r['emailAddress'] ?? ''), 'reason' => EmailSuppression::BOUNCE,
                    'detail' => isset($r['diagnosticCode']) ? (string) $r['diagnosticCode'] : (string) ($m['bounce']['bounceSubType'] ?? '')];
            }
        } elseif ($type === 'Complaint') {
            foreach ((array) ($m['complaint']['complainedRecipients'] ?? []) as $r) {
                $out[] = ['email' => (string) ($r['emailAddress'] ?? ''), 'reason' => EmailSuppression::COMPLAINT,
                    'detail' => isset($m['complaint']['complaintFeedbackType']) ? (string) $m['complaint']['complaintFeedbackType'] : null];
            }
        }

        return $out;
    }

    // ---- Mailgun ---------------------------------------------------------------------------------------------

    private function verifyMailgun(Request $request): bool
    {
        $key = (string) config('services.mail_events.mailgun_signing_key');
        $sig = $request->input('signature');
        if (trim($key) === '' || ! is_array($sig)) {
            return false;
        }
        $ts = (string) ($sig['timestamp'] ?? '');
        $token = (string) ($sig['token'] ?? '');
        $given = (string) ($sig['signature'] ?? '');
        if (! ctype_digit($ts) || abs(time() - (int) $ts) > self::MAX_SKEW || $token === '' || $given === '') {
            return false;
        }
        if (! hash_equals(hash_hmac('sha256', $ts.$token, $key), strtolower($given))) {
            return false;
        }

        // Each signed token is accepted once (replay of a captured request).
        return Cache::add('mail-events:mailgun-token:'.hash('sha256', $token), 1, self::MAX_SKEW * 2);
    }

    /** @return list<array{email: string, reason: string, detail: ?string}> */
    private function mailgunEvents(Request $request): array
    {
        $e = $request->input('event-data');
        if (! is_array($e)) {
            return [];
        }
        $email = (string) ($e['recipient'] ?? '');
        $event = (string) ($e['event'] ?? '');
        if ($event === 'failed' && ($e['severity'] ?? '') === 'permanent') {
            return [['email' => $email, 'reason' => EmailSuppression::BOUNCE, 'detail' => (string) ($e['delivery-status']['description'] ?? $e['reason'] ?? '')]];
        }
        if ($event === 'complained') {
            return [['email' => $email, 'reason' => EmailSuppression::COMPLAINT, 'detail' => null]];
        }

        return [];
    }

    // ---- Postmark --------------------------------------------------------------------------------------------

    private function verifyPostmark(Request $request): bool
    {
        $user = (string) config('services.mail_events.postmark_user');
        $pass = (string) config('services.mail_events.postmark_password');
        if (trim($user) === '' || trim($pass) === '') {
            return false;
        }

        return hash_equals($user, (string) $request->getUser()) && hash_equals($pass, (string) $request->getPassword());
    }

    /** @return list<array{email: string, reason: string, detail: ?string}> */
    private function postmarkEvents(Request $request): array
    {
        $type = (string) $request->input('RecordType');
        $email = (string) $request->input('Email');
        if ($type === 'Bounce' && in_array((string) $request->input('Type'), ['HardBounce', 'BadEmailAddress', 'ManuallyDeactivated'], true)) {
            return [['email' => $email, 'reason' => EmailSuppression::BOUNCE, 'detail' => (string) $request->input('Description', '')]];
        }
        if ($type === 'SpamComplaint') {
            return [['email' => $email, 'reason' => EmailSuppression::COMPLAINT, 'detail' => null]];
        }

        return [];
    }

    // ---- SendGrid --------------------------------------------------------------------------------------------

    private function verifySendgrid(Request $request): bool
    {
        $pub = trim((string) config('services.mail_events.sendgrid_public_key'));
        $sig = base64_decode((string) $request->header('X-Twilio-Email-Event-Webhook-Signature'), true);
        $ts = (string) $request->header('X-Twilio-Email-Event-Webhook-Timestamp');
        if ($pub === '' || $sig === false || $sig === '' || ! ctype_digit($ts) || abs(time() - (int) $ts) > self::MAX_SKEW) {
            return false;
        }
        $pem = str_contains($pub, 'BEGIN PUBLIC KEY') ? $pub
            : "-----BEGIN PUBLIC KEY-----\n".chunk_split($pub, 64, "\n")."-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);

        return $key !== false && openssl_verify($ts.$request->getContent(), $sig, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    /** @return list<array{email: string, reason: string, detail: ?string}> */
    private function sendgridEvents(Request $request): array
    {
        $list = json_decode($request->getContent(), true);
        $out = [];
        foreach (is_array($list) ? $list : [] as $e) {
            if (! is_array($e)) {
                continue;
            }
            $email = (string) ($e['email'] ?? '');
            $event = (string) ($e['event'] ?? '');
            // 'bounce' with type 'blocked' is a temporary block, not a dead address.
            if ($event === 'bounce' && ($e['type'] ?? 'bounce') === 'bounce') {
                $out[] = ['email' => $email, 'reason' => EmailSuppression::BOUNCE, 'detail' => (string) ($e['reason'] ?? '')];
            } elseif ($event === 'spamreport') {
                $out[] = ['email' => $email, 'reason' => EmailSuppression::COMPLAINT, 'detail' => null];
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function json(Request $request): array
    {
        $d = json_decode($request->getContent(), true);

        return is_array($d) ? $d : [];
    }
}
