<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\AppStore\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * B4 (rev/upsell-visibility): "your trial ends in 3 days".
 *
 * A silent trial expiry is the most expensive kind of churn — nobody decided,
 * they just stopped — so the reminder goes out on the channel the merchant will
 * actually see when they are not logged in.
 *
 * Subject and body come from the admin-editable template pair
 * (email_sub_app_trial / email_message_app_trial) through the same shortcode
 * pipeline every other transactional mail here uses, so an operator can reword
 * it without a deploy and the translations follow the site language.
 */
class AppTrialReminderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    /** P6-64: below queue retry_after. */
    public int $timeout = 60;

    public array $backoff = [60, 300, 900];

    public function __construct(
        public string $ownerName,
        public string $appName,
        public string $stage,          // t_minus_3 | t_minus_1 | ended
        public int $daysLeft,
        public string $manageUrl,
        public string $unsubscribeUrl,
    ) {}

    public function build()
    {
        $shortCodes = [
            '{SITE_TITLE}' => config('settings.site_title'),
            '{SITE_URL}' => route('home'),
            '{OWNER_NAME}' => $this->ownerName,
            '{APP_NAME}' => $this->appName,
            '{DAYS_LEFT}' => (string) max(0, $this->daysLeft),
            '{MANAGE_URL}' => $this->manageUrl,
            '{UNSUBSCRIBE_URL}' => $this->unsubscribeUrl,
        ];

        $subject = config('settings.email_sub_app_trial') ?: $this->fallbackSubject();
        $body = config('settings.email_message_app_trial') ?: $this->fallbackBody();

        // Subject is plain text (Mailer rejects header CRLF) — raw values are safe there.
        $this->subject(str_replace(array_keys($shortCodes), array_values($shortCodes), $subject));

        // Same {!! !!}+Markdown sink as StoreOrderMail: neutralize the app name
        // (developer-controlled, from a published marketplace app) and owner name so
        // neither can inject a link into the installing owner's email.
        $neutralize = fn ($v) => str_replace(['[', ']', '`', '://'], ['\\[', '\\]', '\\`', '&#58;//'], e((string) ($v ?? '')));
        $body_codes = $shortCodes;
        $body_codes['{APP_NAME}'] = $neutralize($shortCodes['{APP_NAME}']);
        $body_codes['{OWNER_NAME}'] = $neutralize($shortCodes['{OWNER_NAME}']);

        return $this->markdown('emails.default', [
            'body' => $body,
            'short_codes' => $body_codes,
        ]);
    }

    /**
     * Used only until an operator saves their own copy. Deliberately stage
     * aware: "ends tomorrow" and "has ended" are different messages, and one
     * generic line for both is the kind of mail people stop reading.
     */
    private function fallbackSubject(): string
    {
        return match ($this->stage) {
            'ended' => ___('Your :app trial has ended', ['app' => '{APP_NAME}']),
            't_minus_1' => ___('Your :app trial ends tomorrow', ['app' => '{APP_NAME}']),
            default => ___('Your :app trial ends in :days days', ['app' => '{APP_NAME}', 'days' => '{DAYS_LEFT}']),
        };
    }

    private function fallbackBody(): string
    {
        $lead = match ($this->stage) {
            'ended' => ___('Your free trial of {APP_NAME} has ended. Your data is still here — continue any time to pick up where you left off.'),
            't_minus_1' => ___('Your free trial of {APP_NAME} ends tomorrow. Continue to keep it switched on.'),
            default => ___('Your free trial of {APP_NAME} ends in {DAYS_LEFT} days. Continue to keep it switched on.'),
        };

        return $lead."\n\n[".___('Manage this app')."]({MANAGE_URL})\n\n"
            .___('Prefer not to get these reminders?').' ['.___('Unsubscribe').']({UNSUBSCRIBE_URL})';
    }
}
