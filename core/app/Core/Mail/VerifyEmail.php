<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Mail;

use Juwri\Core\Models\User;
use Illuminate\Support\Facades\URL;

/** Sign-up confirmation: carries a signed, expiring link to `verification.verify`. */
class VerifyEmail extends SettingsTemplateMail
{
    public function __construct(public User $user) {}

    protected function template(): string
    {
        return 'signup_confirm';
    }

    /** @return list<string> */
    protected function untrustedTags(): array
    {
        return ['USERNAME', 'USER_FULLNAME', 'EMAIL'];
    }

    protected function mergeValues(): array
    {
        $minutes = (int) config('auth.verification.expire', 60);
        $user = $this->user;
        $link = URL::temporarySignedRoute('verification.verify', now()->addMinutes($minutes), ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())]);

        return $this->siteValues() + UserDetails::accountValues($this->user) + ['CONFIRMATION_LINK' => $link];
    }
}
