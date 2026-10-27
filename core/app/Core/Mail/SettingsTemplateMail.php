<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * A mail whose subject and HTML body are admin-edited settings
 * (`settings.email_sub_<template>` / `settings.email_message_<template>`) with
 * `{TAG}` merge codes. Subclasses name the template and supply the tag values;
 * the body renders through `emails.default` (fills the codes, wrapped in the x-mail.frame shell).
 */
abstract class SettingsTemplateMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /** Queue retry policy shared by every template mail. */
    public int $tries = 3;

    /** P6-64: below queue retry_after. */
    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    /** Settings suffix, e.g. `signup_confirm` → `email_sub_signup_confirm`. */
    abstract protected function template(): string;

    /**
     * Merge values keyed by bare tag name (`SITE_TITLE`, not `{SITE_TITLE}`).
     *
     * @return array<string, mixed>
     */
    abstract protected function mergeValues(): array;

    /**
     * Values used in the HTML body; defaults to the subject values. Override to
     * escape fields that come from untrusted input.
     *
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    protected function bodyValues(array $values): array
    {
        foreach ($this->untrustedTags() as $tag) {
            if (isset($values[$tag])) {
                $values[$tag] = strtr(e($values[$tag]), ['://' => '&#58;//']);
            }
        }

        return $values;
    }

    /**
     * Tags whose value a visitor typed (account name, username, email): escaped in the HTML body
     * (and autolink-neutralised) so a name like `<a href=…>` cannot become a live link.
     *
     * @return list<string>
     */
    protected function untrustedTags(): array
    {
        return [];
    }

    /**
     * Tags every template mail offers.
     *
     * @return array<string, mixed>
     */
    protected function siteValues(): array
    {
        return ['SITE_TITLE' => config('settings.site_title'), 'SITE_URL' => route('home')];
    }

    public function build(): static
    {
        $values = array_map(fn ($v) => (string) $v, $this->mergeValues());
        $braced = fn (array $list) => array_combine(array_map(fn ($tag) => '{'.$tag.'}', array_keys($list)), $list);

        $subjectCodes = $braced($values);
        $this->subject(str_replace(array_keys($subjectCodes), $subjectCodes, (string) config('settings.email_sub_'.$this->template())));

        $bodyValues = $this->bodyValues($values);
        $body = (string) config('settings.email_message_'.$this->template());

        // An unseeded / emptied template must never fail the mail (the CSS inliner rejects an empty
        // document): fall back to a plain list of the merge values so the recipient still gets the facts.
        if (trim(strip_tags($body)) === '') {
            $body = $this->fallbackBody($bodyValues);
        }

        $data = ['body' => $body, 'short_codes' => $braced($bodyValues)];

        // P6-314: the framed view (not Laravel's markdown theme) so dark mode, color-scheme and RTL apply.
        return $this->view('emails.default', $data)->text('emails.default-text', $data);
    }

    /**
     * Default body used when the admin-edited template is empty: one line per non-empty merge value.
     *
     * @param  array<string, string>  $values  already prepared for the HTML body
     */
    protected function fallbackBody(array $values): string
    {
        $lines = [];
        foreach ($values as $tag => $value) {
            if (str_starts_with((string) $tag, 'SITE_') || trim((string) $value) === '') {
                continue;
            }
            $lines[] = '<p><strong>'.e(ucfirst(strtolower(str_replace('_', ' ', (string) $tag)))).':</strong> '.$value.'</p>';
        }

        return $lines === [] ? '<p>'.e((string) config('settings.site_title')).'</p>' : implode("\n", $lines);
    }
}
