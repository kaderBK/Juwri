<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\Security;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Juwri\Core\Mail\BreachNoticeMail;
use Juwri\Core\Models\BreachNotice;

/**
 * P1-23: who must be told about a personal-data breach, by when, and the sending of the notice itself.
 *
 * Promises behind it: "We will notify you of a personal data breach affecting Customer Data without undue delay, and
 * in any case within 48 hours of confirming it" (Terms 9.2, DPA 6.1, security page 8) and 24 hours for a customer
 * established in Kuwait (DPA Schedule 4 D2). The customer is the ACCOUNT (its owner's email; DPA 6.2), so a breach
 * scoped to stores is resolved to the accounts that own them. The runbook is deploy/SECURITY-INCIDENT-RUNBOOK.md.
 *
 * @phpstan-type Recipient array{account_id: int, user_id: int|null, name: string, email: string, locale: string|null, country: string|null, kuwait: bool, hours: int, reseller_id: int|null}
 */
final class BreachNotices
{
    /** Values (upper-cased) that mean "established in Kuwait" in a billing country / user country field. */
    private const KUWAIT = ['KW', 'KWT', 'KUWAIT', 'الكويت'];

    /**
     * The customers to notify: one row per affected account, sorted by deadline (Kuwait first).
     *
     * @param  list<int>  $storeIds
     * @param  list<int>  $accountIds
     * @return Collection<int, Recipient>
     */
    public function recipients(array $storeIds = [], array $accountIds = [], bool $all = false): Collection
    {
        $ids = collect($accountIds)->map(fn ($id) => (int) $id);
        if ($storeIds !== []) {
            $ids = $ids->merge(DB::table('stores')->whereIn('id', array_map('intval', $storeIds))->pluck('account_id')->map(fn ($id) => (int) $id));
        }

        $accounts = DB::table('accounts')
            ->when(! $all, fn ($q) => $q->whereIn('id', $ids->unique()->values()->all()))
            ->when($all, fn ($q) => $q->where('status', '!=', 'closed'))
            ->orderBy('id')
            ->get(['id', 'owner_user_id', 'reseller_id', 'billing_profile', 'name']);

        return $accounts->map(function ($account) {
            $owner = $this->owner((int) $account->id, $account->owner_user_id !== null ? (int) $account->owner_user_id : null);
            $profile = is_string($account->billing_profile) ? (array) json_decode($account->billing_profile, true) : (array) $account->billing_profile;
            $country = self::country((string) ($profile['country'] ?? '')) ?? self::country((string) ($owner->country_code ?? ''));
            $kuwait = $country === 'KW';

            return [
                'account_id' => (int) $account->id,
                'user_id' => $owner ? (int) $owner->id : null,
                'name' => (string) ($owner->name ?? $account->name ?? ''),
                'email' => (string) ($owner->email ?? ''),
                'locale' => isset($owner->preferred_locale) ? (string) $owner->preferred_locale : null,
                'country' => $country,
                'kuwait' => $kuwait,
                'hours' => $kuwait ? (int) config('platform.breach_notice.hours_kuwait', 24) : (int) config('platform.breach_notice.hours', 48),
                'reseller_id' => $account->reseller_id !== null ? (int) $account->reseller_id : null,
            ];
        })->sortBy([['hours', 'asc'], ['account_id', 'asc']])->values();
    }

    /**
     * Send the notice to every recipient not yet notified for this incident and record it. Returns
     * ['sent' => n, 'skipped' => n (already notified), 'failed' => [email => reason]].
     *
     * @param  Collection<int, Recipient>  $recipients  from recipients()
     * @param  array{summary: string, data: string, measures: string, contact: string}  $details
     * @return array{sent: int, skipped: int, failed: array<string, string>}
     */
    public function send(string $incident, CarbonImmutable $confirmedAt, array $details, Collection $recipients): array
    {
        $out = ['sent' => 0, 'skipped' => 0, 'failed' => []];
        $bcc = trim((string) config('platform.breach_notice.bcc', ''));

        foreach ($recipients as $r) {
            $email = (string) $r['email'];
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $out['failed']['account '.$r['account_id']] = 'no valid owner email';

                continue;
            }
            $existing = BreachNotice::where('incident_ref', $incident)->where('recipient_user_id', $r['user_id'])->first();
            if ($existing?->sent_at !== null) {
                $out['skipped']++;

                continue;
            }

            $mail = (new BreachNoticeMail($incident, $details, $confirmedAt))->locale(self::locale($r['locale']));
            try {
                $pending = Mail::to($email);
                if ($bcc !== '' && filter_var($bcc, FILTER_VALIDATE_EMAIL)) {
                    $pending->bcc($bcc);
                }
                $pending->send($mail);
            } catch (\Throwable $e) {
                $out['failed'][$email] = mb_substr($e->getMessage(), 0, 190);

                continue;
            }

            BreachNotice::updateOrCreate(
                ['incident_ref' => $incident, 'recipient_user_id' => $r['user_id']],
                [
                    'target_account_id' => $r['account_id'],
                    'email' => $email,
                    'country' => $r['country'],
                    'notice_hours' => $r['hours'],
                    'confirmed_at' => $confirmedAt,
                    'deadline_at' => $confirmedAt->addHours((int) $r['hours']),
                    'sent_at' => now(),
                ]
            );
            $out['sent']++;
        }

        return $out;
    }

    /** ISO 3166 alpha-2 for a stored country value; Kuwait also from its name. Null when unknown. */
    public static function country(string $value): ?string
    {
        $v = mb_strtoupper(trim($value));
        if ($v === '') {
            return null;
        }
        if (in_array($v, self::KUWAIT, true)) {
            return 'KW';
        }

        return preg_match('/^[A-Z]{2}$/', $v) ? $v : null;
    }

    /** The account owner (accounts.owner_user_id), else the account's first active user. */
    private function owner(int $accountId, ?int $ownerUserId): ?object
    {
        $cols = ['id', 'name', 'email', 'preferred_locale', 'country_code'];
        if ($ownerUserId !== null && ($u = DB::table('users')->where('id', $ownerUserId)->first($cols))) {
            return $u;
        }

        return DB::table('users')->where('account_id', $accountId)->orderByDesc('status')->orderBy('id')->first($cols);
    }

    private static function locale(?string $locale): string
    {
        $locale = strtolower((string) $locale);

        return in_array($locale, ['ar', 'de', 'en', 'es', 'fr', 'hi', 'pt', 'ru', 'tr', 'ur', 'zh'], true) ? $locale : 'en';
    }
}
