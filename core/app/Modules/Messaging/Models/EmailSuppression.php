<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * P6-249: one hard-bounced or complained address (platform-wide, see the migration). A complaint outranks a bounce:
 * once complained, a later bounce never downgrades the reason.
 *
 * @property string $email
 * @property string $reason
 * @property string $provider
 * @property string|null $detail
 * @property int $events
 */
class EmailSuppression extends Model
{
    public const BOUNCE = 'bounce';

    public const COMPLAINT = 'complaint';

    protected $table = 'email_suppressions';

    protected $fillable = ['email', 'reason', 'provider', 'detail', 'events', 'last_event_at'];

    protected $casts = ['last_event_at' => 'datetime', 'events' => 'integer'];

    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /** Record a provider event. Ignores anything that is not a plausible address. */
    public static function record(string $email, string $reason, string $provider, ?string $detail = null): ?self
    {
        $email = self::normalize($email);
        if ($email === '' || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }
        $detail = $detail !== null ? mb_substr(trim($detail), 0, 255) : null;

        $row = static::query()->where('email', $email)->first();
        if ($row === null) {
            try {
                return static::query()->create([
                    'email' => $email, 'reason' => $reason, 'provider' => $provider,
                    'detail' => $detail, 'events' => 1, 'last_event_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                $row = static::query()->where('email', $email)->firstOrFail(); // a concurrent event won
            }
        }

        // A complaint outranks a bounce; the counter is bumped in SQL so concurrent events all count.
        static::query()->whereKey($row->getKey())->update([
            'reason' => $row->reason === self::COMPLAINT ? self::COMPLAINT : $reason,
            'provider' => $provider,
            'detail' => $detail ?? $row->detail,
            'events' => DB::raw('events + 1'),
            'last_event_at' => now(),
            'updated_at' => now(),
        ]);

        return $row->refresh();
    }

    /**
     * @param  list<string>  $emails
     * @return array<string, string> normalized email => reason, for the suppressed ones only
     */
    public static function reasonsFor(array $emails): array
    {
        $emails = array_values(array_unique(array_filter(array_map(self::normalize(...), $emails))));
        if ($emails === []) {
            return [];
        }

        return static::query()->whereIn('email', $emails)->pluck('reason', 'email')->all();
    }

    public static function reasonFor(?string $email): ?string
    {
        return $email ? (self::reasonsFor([$email])[self::normalize($email)] ?? null) : null;
    }
}
