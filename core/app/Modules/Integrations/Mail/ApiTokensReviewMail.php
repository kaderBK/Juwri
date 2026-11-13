<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Juwri\Core\Models\User;
use Juwri\Modules\Auth\Events\CredentialsChanged;

/**
 * P6-75: "your sign-in changed — review your API tokens". Integration tokens are
 * kept on a credential change; this tells the owner they still work and links
 * to the page where they can be revoked in one click.
 */
class ApiTokensReviewMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param  list<string>  $tokenNames */
    public function __construct(public User $user, public string $reason, public array $tokenNames) {}

    public function build(): static
    {
        $what = match ($this->reason) {
            CredentialsChanged::PASSWORD_RESET => ___('Your password was reset.'),
            CredentialsChanged::PASSWORD_CHANGED => ___('Your password was changed.'),
            CredentialsChanged::EMAIL_CHANGE_UNDONE => ___('The email change on your account was undone.'),
            default => ___('Two-factor authentication was turned off for your account.'),
        };

        $items = '';
        foreach ($this->tokenNames as $name) {
            $items .= '<li>'.e((string) $name).'</li>';
        }

        $body = '<p>'.e($what).' '.e(___('We signed out your mobile app devices.')).'</p>'
            .'<p>'.e(___('These API tokens still work:')).'</p><ul>'.$items.'</ul>'
            .'<p>'.e(___('If you did not make this change, or do not recognise a token, revoke it now.')).'</p>'
            .'<p><a href="'.e(route('api.index')).'">'.e(___('Review API tokens')).'</a></p>';

        return $this->subject(___('Review your API tokens'))
            ->view('emails.default', ['body' => $body, 'short_codes' => []]);
    }
}
