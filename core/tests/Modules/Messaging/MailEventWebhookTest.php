<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Messaging;

use App\Http\Middleware\Installed;
use App\Mails\CampaignMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Models\User;
use Juwri\Modules\Messaging\Models\EmailSuppression;
use Juwri\Modules\Payments\Contracts\PurchaseReceipts;
use Juwri\Modules\Payments\Services\WalletPurchaseReceipt;
use Tests\TestCase;

/**
 * P6-249: bounce / complaint webhooks per provider (valid → suppressed; bad signature, blank secret → 403, nothing
 * recorded) and the send-time rule (bulk mail skips a suppressed address, transactional mail still goes).
 * No real provider is called: SNS certificate and subscribe URL fetches are Http::fake'd.
 */
class MailEventWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const TOPIC = 'arn:aws:sns:eu-west-1:123456789012:ses-events';

    private const CERT_URL = 'https://sns.eu-west-1.amazonaws.com/SimpleNotificationService-test.pem';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->withoutMiddleware([Installed::class]);
        Http::preventStrayRequests();
        config(['services.mail_events' => [
            'ses_topic_arn' => self::TOPIC,
            'mailgun_signing_key' => 'mg-key-123',
            'postmark_user' => 'pm-user',
            'postmark_password' => 'pm-pass-long',
            'sendgrid_public_key' => null,
        ]]);
    }

    private function assertNothingSuppressed(): void
    {
        $this->assertSame(0, EmailSuppression::query()->count());
    }

    // ---- SES / SNS -------------------------------------------------------------------------------------------

    /** @return array{0: \OpenSSLAsymmetricKey, 1: string} key + self-signed cert PEM */
    private function snsKey(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'sns.amazonaws.com'], $key);
        openssl_x509_export(openssl_csr_sign($csr, null, $key, 1), $pem);

        return [$key, $pem];
    }

    private function snsMessage(\OpenSSLAsymmetricKey $key, array $msg, string $version = '1'): array
    {
        $msg += ['MessageId' => 'm-'.uniqid(), 'Timestamp' => gmdate('Y-m-d\TH:i:s.000\Z'), 'TopicArn' => self::TOPIC,
            'SignatureVersion' => $version, 'SigningCertURL' => self::CERT_URL];
        $fields = $msg['Type'] === 'Notification'
            ? ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type']
            : ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];
        $s = '';
        foreach ($fields as $f) {
            if (isset($msg[$f])) {
                $s .= $f."\n".$msg[$f]."\n";
            }
        }
        openssl_sign($s, $sig, $key, $version === '2' ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1);
        $msg['Signature'] = base64_encode($sig);

        return $msg;
    }

    private function sesBounce(string $email, string $type = 'Permanent'): array
    {
        return ['Type' => 'Notification', 'Message' => json_encode([
            'notificationType' => 'Bounce',
            'bounce' => ['bounceType' => $type, 'bounceSubType' => 'General', 'bouncedRecipients' => [['emailAddress' => $email, 'diagnosticCode' => 'smtp; 550 5.1.1 user unknown']]],
        ])];
    }

    private function postSes(array $msg)
    {
        return $this->call('POST', '/webhook/mail/ses', [], [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode($msg));
    }

    public function test_ses_signed_permanent_bounce_and_complaint_are_suppressed(): void
    {
        [$key, $pem] = $this->snsKey();
        Http::fake([self::CERT_URL => Http::response($pem)]);

        $this->postSes($this->snsMessage($key, $this->sesBounce('Gone@Example.com')))->assertOk();
        $this->postSes($this->snsMessage($key, ['Type' => 'Notification', 'Subject' => 'x', 'Message' => json_encode([
            'notificationType' => 'Complaint', 'complaint' => ['complainedRecipients' => [['emailAddress' => 'angry@example.com']], 'complaintFeedbackType' => 'abuse'],
        ])], '2'))->assertOk();
        $this->postSes($this->snsMessage($key, $this->sesBounce('soft@example.com', 'Transient')))->assertOk();

        $this->assertSame('bounce', EmailSuppression::reasonFor('gone@example.com'));
        $this->assertSame('complaint', EmailSuppression::reasonFor('angry@example.com'));
        $this->assertNull(EmailSuppression::reasonFor('soft@example.com'), 'a soft bounce is not suppressed');
    }

    public function test_ses_bad_signature_wrong_topic_foreign_cert_host_and_blank_topic_are_refused(): void
    {
        [$key, $pem] = $this->snsKey();
        Http::fake([self::CERT_URL => Http::response($pem), 'https://evil.example/*' => Http::response($pem)]);

        $tampered = $this->snsMessage($key, $this->sesBounce('a@example.com'));
        $tampered['Message'] = str_replace('a@example.com', 'victim@example.com', $tampered['Message']);
        $this->postSes($tampered)->assertForbidden();

        $this->postSes($this->snsMessage($key, $this->sesBounce('a@example.com') + ['TopicArn' => 'arn:aws:sns:eu-west-1:999:other']))->assertForbidden();
        $this->postSes($this->snsMessage($key, $this->sesBounce('a@example.com') + ['SigningCertURL' => 'https://evil.example/cert.pem']))->assertForbidden();
        $this->postSes($this->snsMessage($key, $this->sesBounce('a@example.com') + ['Timestamp' => gmdate('Y-m-d\TH:i:s.000\Z', time() - 7200)]))->assertForbidden();

        config(['services.mail_events.ses_topic_arn' => '']);
        $this->postSes($this->snsMessage($key, $this->sesBounce('a@example.com')))->assertForbidden();
        $this->assertNothingSuppressed();
    }

    public function test_ses_subscription_is_confirmed_only_when_signed(): void
    {
        [$key, $pem] = $this->snsKey();
        $subscribe = 'https://sns.eu-west-1.amazonaws.com/?Action=ConfirmSubscription&Token=t1';
        Http::fake([self::CERT_URL => Http::response($pem), 'https://sns.eu-west-1.amazonaws.com/?*' => Http::response('ok')]);

        $msg = $this->snsMessage($key, ['Type' => 'SubscriptionConfirmation', 'Message' => 'confirm', 'Token' => 't1', 'SubscribeURL' => $subscribe]);
        $this->postSes($msg)->assertOk();
        Http::assertSent(fn ($r) => $r->url() === $subscribe);

        $forged = $msg;
        $forged['Signature'] = base64_encode('nope');
        $this->postSes($forged)->assertForbidden();
    }

    // ---- Mailgun ---------------------------------------------------------------------------------------------

    private function mailgun(array $event, string $key = 'mg-key-123', ?string $token = null): array
    {
        $ts = (string) time();
        $token ??= bin2hex(random_bytes(16));

        return ['signature' => ['timestamp' => $ts, 'token' => $token, 'signature' => hash_hmac('sha256', $ts.$token, $key)], 'event-data' => $event];
    }

    public function test_mailgun_signed_permanent_failure_and_complaint_are_suppressed_and_replay_is_refused(): void
    {
        $body = $this->mailgun(['event' => 'failed', 'severity' => 'permanent', 'recipient' => 'gone@example.com']);
        $this->postJson('/webhook/mail/mailgun', $body)->assertOk();
        $this->postJson('/webhook/mail/mailgun', $body)->assertForbidden(); // same token replayed
        $this->postJson('/webhook/mail/mailgun', $this->mailgun(['event' => 'complained', 'recipient' => 'angry@example.com']))->assertOk();
        $this->postJson('/webhook/mail/mailgun', $this->mailgun(['event' => 'failed', 'severity' => 'temporary', 'recipient' => 'soft@example.com']))->assertOk();

        $this->assertSame('bounce', EmailSuppression::reasonFor('gone@example.com'));
        $this->assertSame('complaint', EmailSuppression::reasonFor('angry@example.com'));
        $this->assertNull(EmailSuppression::reasonFor('soft@example.com'));
    }

    public function test_mailgun_bad_signature_and_blank_key_are_refused(): void
    {
        $event = ['event' => 'failed', 'severity' => 'permanent', 'recipient' => 'x@example.com'];
        $this->postJson('/webhook/mail/mailgun', $this->mailgun($event, 'wrong-key'))->assertForbidden();
        $this->postJson('/webhook/mail/mailgun', ['event-data' => $event])->assertForbidden();

        config(['services.mail_events.mailgun_signing_key' => '']);
        $this->postJson('/webhook/mail/mailgun', $this->mailgun($event, ''))->assertForbidden(); // HMAC with '' is forgeable
        $this->assertNothingSuppressed();
    }

    // ---- Postmark --------------------------------------------------------------------------------------------

    public function test_postmark_basic_auth_hard_bounce_and_complaint(): void
    {
        $auth = ['PHP_AUTH_USER' => 'pm-user', 'PHP_AUTH_PW' => 'pm-pass-long'];
        $this->call('POST', '/webhook/mail/postmark', ['RecordType' => 'Bounce', 'Type' => 'HardBounce', 'Email' => 'gone@example.com'], [], [], $auth)->assertOk();
        $this->call('POST', '/webhook/mail/postmark', ['RecordType' => 'SpamComplaint', 'Email' => 'angry@example.com'], [], [], $auth)->assertOk();
        $this->call('POST', '/webhook/mail/postmark', ['RecordType' => 'Bounce', 'Type' => 'SoftBounce', 'Email' => 'soft@example.com'], [], [], $auth)->assertOk();

        $this->assertSame('bounce', EmailSuppression::reasonFor('gone@example.com'));
        $this->assertSame('complaint', EmailSuppression::reasonFor('angry@example.com'));
        $this->assertNull(EmailSuppression::reasonFor('soft@example.com'));
    }

    public function test_postmark_wrong_or_missing_credentials_and_blank_config_are_refused(): void
    {
        $body = ['RecordType' => 'Bounce', 'Type' => 'HardBounce', 'Email' => 'x@example.com'];
        $this->call('POST', '/webhook/mail/postmark', $body, [], [], ['PHP_AUTH_USER' => 'pm-user', 'PHP_AUTH_PW' => 'nope'])->assertForbidden();
        $this->call('POST', '/webhook/mail/postmark', $body)->assertForbidden();

        config(['services.mail_events.postmark_user' => '', 'services.mail_events.postmark_password' => '']);
        $this->call('POST', '/webhook/mail/postmark', $body, [], [], ['PHP_AUTH_USER' => '', 'PHP_AUTH_PW' => ''])->assertForbidden();
        $this->assertNothingSuppressed();
    }

    // ---- SendGrid --------------------------------------------------------------------------------------------

    /** @return array{0: \OpenSSLAsymmetricKey, 1: string} private key + base64 SPKI public key (as SendGrid shows it) */
    private function sendgridKey(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $pub = openssl_pkey_get_details($key)['key'];

        return [$key, preg_replace('/-----[^-]+-----|\s/', '', $pub)];
    }

    private function postSendgrid(\OpenSSLAsymmetricKey $key, array $events, ?int $ts = null)
    {
        $body = json_encode($events);
        $ts = (string) ($ts ?? time());
        openssl_sign($ts.$body, $sig, $key, OPENSSL_ALGO_SHA256);

        return $this->call('POST', '/webhook/mail/sendgrid', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_TWILIO_EMAIL_EVENT_WEBHOOK_SIGNATURE' => base64_encode($sig),
            'HTTP_X_TWILIO_EMAIL_EVENT_WEBHOOK_TIMESTAMP' => $ts,
        ], $body);
    }

    public function test_sendgrid_signed_bounce_and_spamreport_are_suppressed(): void
    {
        [$key, $pub] = $this->sendgridKey();
        config(['services.mail_events.sendgrid_public_key' => $pub]);

        $this->postSendgrid($key, [
            ['event' => 'bounce', 'type' => 'bounce', 'email' => 'gone@example.com', 'reason' => '550 no such user'],
            ['event' => 'bounce', 'type' => 'blocked', 'email' => 'blocked@example.com'],
            ['event' => 'spamreport', 'email' => 'angry@example.com'],
            ['event' => 'delivered', 'email' => 'fine@example.com'],
        ])->assertOk();

        $this->assertSame('bounce', EmailSuppression::reasonFor('gone@example.com'));
        $this->assertSame('complaint', EmailSuppression::reasonFor('angry@example.com'));
        $this->assertNull(EmailSuppression::reasonFor('blocked@example.com'));
        $this->assertNull(EmailSuppression::reasonFor('fine@example.com'));
    }

    public function test_sendgrid_wrong_key_stale_timestamp_and_blank_key_are_refused(): void
    {
        [$key, $pub] = $this->sendgridKey();
        [$otherKey] = $this->sendgridKey();
        $events = [['event' => 'bounce', 'type' => 'bounce', 'email' => 'x@example.com']];

        config(['services.mail_events.sendgrid_public_key' => $pub]);
        $this->postSendgrid($otherKey, $events)->assertForbidden();
        $this->postSendgrid($key, $events, time() - 3600)->assertForbidden();

        config(['services.mail_events.sendgrid_public_key' => '']);
        $this->postSendgrid($key, $events)->assertForbidden();
        $this->assertNothingSuppressed();
    }

    public function test_unknown_provider_is_not_routed(): void
    {
        $this->postJson('/webhook/mail/sparkpost', [])->assertNotFound();
    }

    // ---- model + send-time rule --------------------------------------------------------------------------------

    public function test_a_complaint_is_never_downgraded_by_a_later_bounce(): void
    {
        EmailSuppression::record('a@example.com', 'complaint', 'ses');
        EmailSuppression::record('A@example.com ', 'bounce', 'ses');

        $row = EmailSuppression::query()->sole();
        $this->assertSame('complaint', $row->reason);
        $this->assertSame(2, $row->events);
        $this->assertNull(EmailSuppression::record('not-an-email', 'bounce', 'ses'));
    }

    /** @return list<string> recipients of the messages the array transport captured */
    private function sentTo(): array
    {
        $out = [];
        foreach (Mail::mailer('array')->getSymfonyTransport()->messages() as $m) {
            foreach ($m->getOriginalMessage()->getTo() as $a) {
                $out[] = $a->getAddress();
            }
            $this->assertFalse($m->getOriginalMessage()->getHeaders()->has('X-Juwri-Mail-Class'), 'internal marker never leaves');
        }

        return $out;
    }

    public function test_bulk_mail_skips_suppressed_recipients_and_transactional_mail_still_sends(): void
    {
        config(['mail.default' => 'array']);
        EmailSuppression::record('dead@example.com', 'bounce', 'mailgun');

        // Bulk, only a suppressed recipient → cancelled.
        (new CampaignMail('Hi', 'Offer', 'https://x.test/u'))->to('dead@example.com')->send(Mail::mailer('array'));
        $this->assertSame([], $this->sentTo());

        // Bulk with a mix → the live address only.
        (new CampaignMail('Hi', 'Offer', 'https://x.test/u'))->to(['dead@example.com', 'live@example.com'])->send(Mail::mailer('array'));
        $this->assertSame(['live@example.com'], $this->sentTo());

        // Transactional → still delivered to the suppressed address.
        $receipt = new class extends Mailable
        {
            public function build()
            {
                return $this->subject('Your receipt')->html('<p>Paid</p>');
            }
        };
        $receipt->to('dead@example.com')->send(Mail::mailer('array'));
        $this->assertSame(['live@example.com', 'dead@example.com'], $this->sentTo());
    }

    /** p5q int: the suppression lookup failing (DB error / table missing) never blocks mail. */
    public function test_a_failing_suppression_lookup_still_sends(): void
    {
        config(['mail.default' => 'array']);
        Schema::drop('email_suppressions');

        (new CampaignMail('Hi', 'Offer', 'https://x.test/u'))->to('live@example.com')->send(Mail::mailer('array'));
        $this->assertSame(['live@example.com'], $this->sentTo());
    }

    /** p5q int: modules above Payments issue wallet receipts through the PurchaseReceipts contract. */
    public function test_purchase_receipts_contract_resolves_to_the_wallet_receipt(): void
    {
        $this->assertInstanceOf(
            WalletPurchaseReceipt::class,
            app(PurchaseReceipts::class),
        );
    }

    public function test_admin_sees_and_removes_a_suppressed_address(): void
    {
        $admin = User::factory()->create(['group_id' => '1', 'user_type' => 'admin', 'status' => 1, 'email_verified_at' => now()]);
        $row = EmailSuppression::record('gone@example.com', 'bounce', 'postmark', '550 unknown');

        $this->actingAs($admin)->get(route('admin.email-suppressions.index'))->assertOk()->assertSee('gone@example.com');
        $this->actingAs($admin)->delete(route('admin.email-suppressions.destroy', $row->id))->assertRedirect();
        $this->assertSame(0, EmailSuppression::query()->count());

        $owner = User::factory()->create(['user_type' => 'user', 'status' => 1, 'email_verified_at' => now()]);
        $this->assertNotSame(200, $this->actingAs($owner)->get(route('admin.email-suppressions.index'))->status());
    }
}
