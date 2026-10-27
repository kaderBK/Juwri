<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Support;

/**
 * The transactional e-mails whose subject and body the superadmin can edit.
 *
 * Each entry names the two settings (options table) that hold its subject and
 * message, and the placeholders the sending code replaces in them. Only the
 * settings named here can be written from the e-mail template screen.
 */
final class MailTemplateCatalog
{
    /** @return list<array{title: string, code: string}> placeholders every template understands */
    public static function commonShortcodes(): array
    {
        return [
            self::code('{SITE_TITLE}', ___('Site Title')),
            self::code('{SITE_URL}', ___('Site URL')),
        ];
    }

    /**
     * @return list<array{id: string, title: string, subject: string, message: string, shortcodes: list<array{title: string, code: string}>}>
     */
    public static function templates(): array
    {
        $account = [
            self::code('{USER_ID}', ___('User ID')),
            self::code('{USERNAME}', ___('Username')),
            self::code('{USER_FULLNAME}', ___('User Full Name')),
            self::code('{EMAIL}', ___('User Email')),
        ];

        return [
            self::template('new-order', ___('New Order Email to Restaurant Owners'), 'new_order', [
                self::code('{RESTAURANT_NAME}', ___('Store name')),
                self::code('{ORDER_TYPE}', ___('Order Type')),
                self::code('{CUSTOMER_NAME}', ___('Customer Name')),
                self::code('{TABLE_NUMBER}', ___('Table Number')),
                self::code('{PHONE_NUMBER}', ___('Phone Number')),
                self::code('{ADDRESS}', ___('Address')),
                self::code('{ORDER}', ___('Order Details')),
                self::code('{MESSAGE}', ___('Customer Message')),
            ]),
            self::template('signup-details', ___('New User Account Details Email'), 'signup_details', $account),
            self::template('create-account', ___('New User Confirmation Email'), 'signup_confirm', [
                ...$account,
                self::code('{CONFIRMATION_LINK}', ___('Confirmation Link')),
            ]),
            self::template('forgot-pass', ___('Forgot Password Email'), 'forgot_pass', [
                ...$account,
                self::code('{FORGET_PASSWORD_LINK}', ___('Password Reset Link')),
                self::code('{EXPIRY_TIME}', ___('Link Expire Time in Minutes')),
            ]),
            self::template('contact_us', ___('Contact Us Email'), 'contact', [
                self::code('{NAME}', ___('Sender Full Name')),
                self::code('{EMAIL}', ___('Sender Email')),
                self::code('{CONTACT_SUBJECT}', ___('Contact Subject')),
                self::code('{MESSAGE}', ___('Contact Message')),
            ]),
            self::template('feedback', ___('Feedback Email'), 'feedback', [
                self::code('{NAME}', ___('Sender Full Name')),
                self::code('{EMAIL}', ___('Sender Email')),
                self::code('{PHONE}', ___('Sender Phone Number')),
                self::code('{FEEDBACK_SUBJECT}', ___('Feedback Subject')),
                self::code('{MESSAGE}', ___('Feedback Message')),
            ]),
            // App trial reminders (T-3, T-1, expiry); AppTrialReminderMail has
            // stage-aware defaults until an operator saves a copy here.
            self::template('app-trial', ___('App Trial Reminder'), 'app_trial', [
                self::code('{OWNER_NAME}', ___('Owner Name')),
                self::code('{APP_NAME}', ___('App Name')),
                self::code('{DAYS_LEFT}', ___('Days Left')),
                self::code('{MANAGE_URL}', ___('Manage App URL')),
                self::code('{UNSUBSCRIBE_URL}', ___('Unsubscribe URL')),
            ]),
        ];
    }

    /** @return list<string> the option names the template screen may write */
    public static function settingKeys(): array
    {
        $keys = [];
        foreach (self::templates() as $template) {
            $keys[] = $template['subject'];
            $keys[] = $template['message'];
        }

        return $keys;
    }

    /**
     * @param  list<array{title: string, code: string}>  $own
     * @return array{id: string, title: string, subject: string, message: string, shortcodes: list<array{title: string, code: string}>}
     */
    private static function template(string $id, string $title, string $setting, array $own): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'subject' => 'email_sub_'.$setting,
            'message' => 'email_message_'.$setting,
            'shortcodes' => [...self::commonShortcodes(), ...$own],
        ];
    }

    /** @return array{title: string, code: string} */
    private static function code(string $code, string $title): array
    {
        return ['title' => $title, 'code' => $code];
    }
}
