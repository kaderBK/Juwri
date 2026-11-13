<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Mail;

use Juwri\Core\Models\User;

/** Account details sent after sign-up or when an admin creates an account. */
class UserDetails extends SettingsTemplateMail
{
    public function __construct(public User $user) {}

    /**
     * The account tags shared by the user-facing template mails.
     *
     * @return array<string, mixed>
     */
    public static function accountValues(User $account): array
    {
        return [
            'USER_ID' => $account->id,
            'USERNAME' => $account->username,
            'USER_FULLNAME' => $account->name,
            'EMAIL' => $account->email,
        ];
    }

    protected function template(): string
    {
        return 'signup_details';
    }

    /** @return list<string> */
    protected function untrustedTags(): array
    {
        return ['USERNAME', 'USER_FULLNAME', 'EMAIL'];
    }

    protected function mergeValues(): array
    {
        return $this->siteValues() + self::accountValues($this->user);
    }
}
