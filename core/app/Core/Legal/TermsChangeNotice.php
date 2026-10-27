<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Legal;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Mail\TermsChangedMail;
use Juwri\Core\Models\User;
use Juwri\Core\Models\UserOption;
use Juwri\Core\Settings\UserSettings;

/**
 * P1-23: "We're updating the Terms on <date>" (Terms 14.2) driven by the legal version tracking (config/legal.php
 * `documents.terms.versions`). When a new Terms version is published with an effective date, every active account
 * owner gets the notice once, in their own language, queued; owners who join before that date get it on the next
 * run. Each owner is stamped with the effective date (user option {@see STAMP}), so re-runs reach nobody twice and a
 * version moved to a new date is announced again.
 *
 * A material change needs at least {@see NOTICE_DAYS} days' notice: a version whose effective date is closer when its
 * announcement would start is refused (logged as an error, `legal:check` fails) unless config marks it non-material
 * (`documents.terms.non_material`). An announcement already under way keeps reaching new owners.
 *
 * JUWRI LTD's own install only: nothing is sent unless LEGAL_PAGES is on ({@see LegalDocuments::enabled()}); a
 * reseller's customers (users.reseller_id) contract with the reseller (Terms 14.1) and are never notified.
 */
final class TermsChangeNotice
{
    public const STAMP = 'terms_change_notice';

    public const NOTICE_DAYS = 30;

    public const DOCUMENT = 'terms';

    /**
     * The announced (not yet in force) Terms version, if any: the newest configured version after the first one,
     * effective today or later.
     *
     * @return array{version: string, effective: CarbonImmutable, material: bool}|null
     */
    public static function announced(?CarbonImmutable $today = null): ?array
    {
        if (! LegalDocuments::has(self::DOCUMENT)) {
            return null;
        }
        $versions = LegalDocuments::all()[self::DOCUMENT]['versions'];
        $version = LegalDocuments::latestVersion(self::DOCUMENT);
        $effective = LegalDocuments::effectiveDate(self::DOCUMENT, $version);
        $today ??= CarbonImmutable::now('UTC')->startOfDay();
        if (count($versions) < 2 || $effective === null || $effective->lessThan($today)) {
            return null;
        }

        return ['version' => $version, 'effective' => $effective, 'material' => LegalDocuments::material(self::DOCUMENT, $version)];
    }

    /**
     * Why an announcement for $effective may not start: a material change with less than 30 days' notice, or a date
     * already past. Null = fine (or already under way).
     */
    public static function problem(CarbonImmutable $effective, bool $material, ?CarbonImmutable $today = null): ?string
    {
        $today ??= CarbonImmutable::now('UTC')->startOfDay();
        if ($effective->lessThan($today)) {
            return 'the effective date '.$effective->format('Y-m-d').' has passed';
        }
        if (self::started($effective)) {
            return null;
        }
        $days = (int) $today->diffInDays($effective);
        if ($material && $days < self::NOTICE_DAYS) {
            return 'a material change needs at least '.self::NOTICE_DAYS.' days\' notice (Terms 14.2); '
                .$effective->format('Y-m-d').' is '.$days.' day(s) away — move the date or mark the version non-material';
        }

        return null;
    }

    /** Some owner already got the notice for this effective date (the announcement is under way). */
    public static function started(CarbonImmutable $effective): bool
    {
        $stamp = $effective->format('Y-m-d');

        return UserSettings::userIdsWhere(self::STAMP, fn (string $value): bool => $value === $stamp) !== [];
    }

    /**
     * Active account owners on JUWRI LTD's own terms (not admins, not a reseller's customers).
     *
     * @return Builder<User>
     */
    public static function recipients(): Builder
    {
        return User::query()->where('user_type', 'user')->where('status', 1)
            ->whereNotNull('email')->where('email', '!=', '')
            ->where(fn (Builder $q) => $q->whereNull('reseller_id')->orWhere('reseller_id', 0));
    }

    /**
     * Queue the notice for $effective to every recipient not stamped with it yet.
     *
     * @param  (callable(User): void)|null  $each  called per recipient (dry run listing)
     * @return int notices queued (or, on a dry run, that would be)
     */
    public static function send(CarbonImmutable $effective, string $url, bool $dryRun = false, ?callable $each = null): int
    {
        $stamp = $effective->format('Y-m-d');
        $sent = 0;
        self::recipients()->orderBy('id')->chunkById(200, function ($owners) use ($stamp, $effective, $url, $dryRun, $each, &$sent) {
            /** @var User $owner */
            foreach ($owners as $owner) {
                if ((string) UserOption::getUserOption($owner->id, self::STAMP) === $stamp) {
                    continue;
                }
                if ($each !== null) {
                    $each($owner);
                }
                if ($dryRun) {
                    $sent++;

                    continue;
                }
                $mail = (new TermsChangedMail((string) ($owner->name ?: $owner->email), $effective, $url))
                    ->locale($owner->preferredLocale());
                if ($owner->sendMail($mail) === true) {
                    UserOption::updateUserOption($owner->id, self::STAMP, $stamp);
                    $sent++;
                }
            }
        });

        return $sent;
    }

    /**
     * The scheduled hook: announce the configured upcoming Terms version. Returns what happened, for the command.
     *
     * @return array{status: 'off'|'none'|'refused'|'sent', sent: int, version?: string, effective?: string, reason?: string}
     */
    public static function run(bool $dryRun = false, ?callable $each = null): array
    {
        if (! LegalDocuments::enabled()) {
            return ['status' => 'off', 'sent' => 0];
        }
        $announced = self::announced();
        if ($announced === null) {
            return ['status' => 'none', 'sent' => 0];
        }
        $info = ['version' => $announced['version'], 'effective' => $announced['effective']->format('Y-m-d')];
        if (($reason = self::problem($announced['effective'], $announced['material'])) !== null) {
            Log::error('legal.terms_change_refused', $info + ['reason' => $reason]);

            return ['status' => 'refused', 'sent' => 0, 'reason' => $reason] + $info;
        }
        $sent = self::send($announced['effective'], LegalDocuments::url(self::DOCUMENT), $dryRun, $each);
        if ($sent > 0 && ! $dryRun) {
            Log::info('legal.terms_change_notified', $info + ['sent' => $sent]);
        }

        return ['status' => 'sent', 'sent' => $sent] + $info;
    }
}
