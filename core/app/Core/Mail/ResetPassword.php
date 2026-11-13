<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Mail;

use Juwri\Core\Models\User;

/** Forgot-password mail with the reset link and its lifetime in minutes. */
class ResetPassword extends SettingsTemplateMail
{
    public function __construct(public User $user, public string $token) {}

    protected function template(): string
    {
        return 'forgot_pass';
    }

    /** @return list<string> */
    protected function untrustedTags(): array
    {
        return ['USERNAME', 'USER_FULLNAME', 'EMAIL'];
    }

    protected function mergeValues(): array
    {
        // Links are rooted at the configured app URL, never the request Host, so a
        // forged Host header cannot poison the reset link (S3).
        $root = rtrim((string) config('app.url'), '/');
        $path = route('password.reset', ['token' => $this->token, 'email' => $this->user->getEmailForPasswordReset()], false);
        $broker = config('auth.defaults.passwords');

        return ['SITE_TITLE' => config('settings.site_title'), 'SITE_URL' => $root]
            + UserDetails::accountValues($this->user)
            + ['FORGET_PASSWORD_LINK' => $root.$path, 'EXPIRY_TIME' => config("auth.passwords.{$broker}.expire")];
    }
}
