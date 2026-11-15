<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Mails;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\HtmlString;

/**
 * A bulk email from the platform to a merchant.
 *
 * The copy comes from the admin-editable template pair
 * (email_sub_merchant_campaign / email_message_merchant_campaign) through the
 * same shortcode pipeline the other transactional mail here uses, so an
 * operator can reword it without a deploy.
 *
 * Queued, with three tries and a backoff: a bulk segment goes out in waves
 * and a single SMTP hiccup must not fail the whole campaign.
 */
class MerchantCampaignMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    /** Below queue retry_after. */
    public int $timeout = 60;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public string $ownerName,
        public string $country,
        public string $plan,
        public ?string $subjectLine = null,
        // NOT named $body: Mailable::buildViewData() merges every public
        // property of the mailable into the view data, so a public $body
        // would override the view's `body` with null and the mail would
        // render empty. Same reason $subject is $subjectLine.
        public ?string $bodyCopy = null,
        public string $unsubscribeUrl = '',
        // Which campaign this mail belongs to. The queued job carries it in
        // its payload, which is how a campaign can be stopped again while
        // its mails are still waiting in the queue.
        public string $campaignKey = '',
    ) {}

    public function build(): static
    {
        $shortCodes = [
            '{SITE_TITLE}' => (string) config('settings.site_title'),
            '{SITE_URL}' => route('home'),
            '{OWNER_NAME}' => $this->ownerName,
            '{COUNTRY}' => $this->country,
            '{PLAN}' => $this->plan,
            '{UNSUBSCRIBE_URL}' => $this->unsubscribeUrl,
        ];

        $subject = $this->subjectLine ?: (string) config('settings.email_sub_merchant_campaign') ?: $this->fallbackSubject();
        $body = $this->bodyCopy ?: (string) config('settings.email_message_merchant_campaign') ?: $this->fallbackBody();

        // The subject is a header: raw values are safe there, but a newline
        // would break it, so strip any CRLF an operator pasted in.
        $this->subject(str_replace(["\r", "\n"], ' ', str_replace(array_keys($shortCodes), array_values($shortCodes), $subject)));

        // Same {!! !!}+Markdown sink as the other mail here: the merchant's own
        // name is not attacker-controlled, but the country and plan labels come
        // from their account, so they are neutralised like the trial mail does.
        $neutralize = static fn (?string $v): string => str_replace(['[', ']', '`', '://'], ['\\[', '\\]', '\\`', '&#58;//'], e((string) ($v ?? '')));
        $bodyCodes = $shortCodes;
        $bodyCodes['{OWNER_NAME}'] = $neutralize($shortCodes['{OWNER_NAME}']);
        $bodyCodes['{COUNTRY}'] = $neutralize($shortCodes['{COUNTRY}']);
        $bodyCodes['{PLAN}'] = $neutralize($shortCodes['{PLAN}']);

        // The plain-text alternative: the same content with the markup taken
        // out, so a client that shows the text part shows words and not tags.
        // The HTML part keeps the markup the operator wrote.
        //
        // It has to be an Htmlable: the mailer renders a text part that is a
        // plain string as if it were a view name.
        $plain = str_replace(array_keys($shortCodes), array_values($shortCodes), $body);
        $this->textView = new HtmlString(trim(html_entity_decode(strip_tags($plain))));

        if ($this->unsubscribeUrl !== '') {
            $url = $this->unsubscribeUrl;
            $this->withSymfonyMessage(static function ($message) use ($url): void {
                $headers = $message->getHeaders();
                $headers->addTextHeader('List-Unsubscribe', '<'.$url.'>');
                $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            });
        }

        return $this->markdown('emails.default', [
            'body' => $body,
            'short_codes' => $bodyCodes,
        ]);
    }

    private function fallbackSubject(): string
    {
        return ___('A note from :site', ['site' => '{SITE_TITLE}']);
    }

    private function fallbackBody(): string
    {
        // HTML, not markdown: the platform's mail sink (emails.default) is a
        // raw {!! !!} replace, so a markdown link would reach the inbox as
        // literal "[Unsubscribe](https://…)" text. Every other template in the
        // admin Email Templates screen is HTML for the same reason.
        return '<p>'.___('Hi {OWNER_NAME},').'</p>'
            .'<p>'.___('We have something for you on {SITE_URL}.').'</p>'
            .'<p>'.___('Prefer not to hear from us?').' <a href="{UNSUBSCRIBE_URL}">'.___('Unsubscribe').'</a></p>';
    }
}
