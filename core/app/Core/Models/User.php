<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Models;

use App\Models\Upgrade;
use App\Services\GatewaySubscriptionCanceller;
use Cviebrock\EloquentSluggable\Sluggable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Notifiable;
// P3-1: Passport's trait replaces the vestigial Sanctum one (audit 2026-07-05:
// zero createToken()/tokens() call sites on User — the custom ApiToken system
// is the first-party surface; Passport owns the third-party OAuth grant flow).
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Juwri\Core\Contracts\Billing\PlanCatalog;
use Juwri\Core\Mail\ResetPassword;
use Juwri\Core\Mail\VerifyEmail;
use Juwri\Core\Models\Concerns\NormalisesEmail;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\PasskeyAuthenticatable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;
use Stripe\Stripe;
use Stripe\Subscription;

/**
 * P1-08b: transactions() (the owner's platform payments) is registered by the Payments module (resolveRelationUsing).
 * P1-13: subscriptions() (all subscription rows, history included) is registered by the Billing module (ee);
 * reseller() (the reseller the owner signed up under) by the Reseller module (ee).
 *
 * @method \Illuminate\Database\Eloquent\Relations\HasMany<\Illuminate\Database\Eloquent\Model, $this> transactions()
 * @method \Illuminate\Database\Eloquent\Relations\HasMany<\Illuminate\Database\Eloquent\Model, $this> subscriptions()
 * @method \Illuminate\Database\Eloquent\Relations\BelongsTo<\Illuminate\Database\Eloquent\Model, $this> reseller()
 */
class User extends Authenticatable implements HasLocalePreference, MinorMoneyModel, MustVerifyEmail, OAuthenticatable, PasskeyUser
{
    use HasApiTokens, HasFactory, Notifiable, Sluggable;
    use HasMinorMoney;
    use HasUuids;
    use NormalisesEmail; // P6-40: emails stored lowercased
    use PasskeyAuthenticatable; // P1-19: WebAuthn passkeys (laravel/passkeys)

    protected static function boot()
    {
        parent::boot();

        // fusion-reseller FR-11: a change to a user's reseller_id cascades to every
        // post they own, so posts.mk_reseller_id (the denormalized listing fence)
        // never drifts from the owner's marketplace. The Post saving hook covers the
        // create + owner-reassignment direction; this covers the owner moving between
        // resellers.
        static::saved(function (self $user) {
            if ($user->wasChanged('reseller_id')) {
                Store::where('user_id', $user->id)
                    ->update(['mk_reseller_id' => (int) ($user->reseller_id ?? 0)]);
                // P1-04: the owner's account follows the owner's reseller.
                Account::where('owner_user_id', $user->id)->update(['reseller_id' => $user->reseller_id ?: null]);
            }
        });

        // P6-267: a password or email change by ANY path (settings, admin reset,
        // reset form) kills every outstanding reset link for the old and new address.
        static::saved(function (self $user) {
            if ($user->wasChanged('password') || $user->wasChanged('email')) {
                self::purgePasswordResetTokens([
                    (string) $user->email,
                    (string) ($user->getPrevious()['email'] ?? ''),
                ]);
            }
        });

        // P1-04 slice 5: no account on sign-up — AccountProvisioner creates it with the user's first store
        // (Post saving hook). Users that never own a store (reseller team members, partners) stay without one;
        // staff, customers and drivers are separate models that take their store's account.
    }

    /**
     * P6-267: delete every outstanding password-reset token for these addresses
     * (as given and lower-cased).
     *
     * @param  array<int, string|null>  $emails
     */
    public static function purgePasswordResetTokens(array $emails): void
    {
        $clean = [];
        foreach ($emails as $e) {
            $e = trim((string) $e);
            if ($e !== '') {
                $clean[] = $e;
                $clean[] = mb_strtolower($e);
            }
        }
        if ($clean === []) {
            return;
        }

        $table = (string) config('auth.passwords.'.config('auth.defaults.passwords', 'users').'.table', 'password_reset_tokens');
        DB::table($table)->whereIn('email', array_values(array_unique($clean)))->delete();
    }

    /**
     * I2: Laravel auto-sets this locale for any Mail::to($user)/notification
     * addressed to a User, so queued transactional mail renders in the
     * recipient's language. NULL falls back to the platform default.
     */
    public function preferredLocale(): ?string
    {
        return $this->preferred_locale
            ?: (config('settings.default_lang') ?: config('app.locale'));
    }

    /** Owners, admins and resellers' users live in `users` (P1-04: was the singular `user`). */
    public $table = 'users';

    /**
     * P1-04: the tenant account this user belongs to (null for platform admins).
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    /** Every column is writable; callers pass explicit attribute lists. */
    protected $guarded = [];

    /**
     * P1-05: the legacy `balance` (the platform wallet is WalletService / ledger_entries; nothing reads or writes
     * this column today) is stored as `balance_minor` in the platform currency; `$user->balance` stays a number.
     *
     * @var array<string, string>
     */
    protected array $minorMoney = ['balance' => 'balance_minor'];

    /** No currency column: the platform currency. */
    protected ?string $minorMoneyCurrencyColumn = null;

    /** Never serialised: credentials and 2FA material. */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'lastactive' => 'datetime',
        // H8: 2FA secret + recovery codes are encrypted at rest.
        'two_factor_secret' => 'encrypted',
        'two_factor_recovery_codes' => 'encrypted:array',
        'two_factor_confirmed_at' => 'datetime',
    ];

    /** H8: true once the user has fully enrolled + confirmed a TOTP secret. */
    public function hasTwoFactorEnabled(): bool
    {
        return ! empty($this->two_factor_secret) && ! is_null($this->two_factor_confirmed_at);
    }

    /** P6-47: true once the user has a confirmed TOTP secret or at least one passkey. */
    public function hasSecondFactor(): bool
    {
        if ($this->hasTwoFactorEnabled()) {
            return true;
        }

        try {
            return $this->passkeys()->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * P1-19: the account name a passkey prompt shows under the display name — the email, else the
     * username (accounts signed up by username only), else the id.
     */
    public function getPasskeyUsername(): string
    {
        return (string) ($this->email ?: ($this->username ?: $this->getAuthIdentifier()));
    }

    /** P1-19: the name a passkey prompt shows for this account. */
    public function getPasskeyDisplayName(): string
    {
        return (string) ($this->name ?: $this->getPasskeyUsername());
    }

    /** A missing username is generated from the display name (cviebrock/sluggable). */
    public function sluggable(): array
    {
        return ['username' => ['source' => 'name']];
    }

    /** Laravel's reset hook: our own settings-template mail instead of the framework notification. */
    public function sendPasswordResetNotification($token)
    {
        $this->sendMail(new ResetPassword($this, $token));
    }

    /**
     * Queue a mail to this user, dispatched only once the surrounding DB
     * transaction commits. Returns true, or the exception message when the
     * mail could not be queued (callers show it instead of failing).
     *
     * @return bool|string
     */
    public function sendMail(Mailable $mailable)
    {
        try {
            Mail::to($this->email)->queue($mailable->afterCommit());

            return true;
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    /** Laravel's verify-email hook, via our own settings-template mail. */
    public function sendEmailVerificationNotification()
    {
        $this->sendMail(new VerifyEmail($this));
    }

    /** @var Model|false|null R1 per-request memo (false = looked up, none) */
    private $liveSubscriptionMemo = null;

    /** @var mixed PERF per-request memo of plan() (false = not yet resolved). */
    private $planMemo = false;

    /**
     * R1: the user's live subscription row, if any (free = none). Memoized per
     * model instance; ->fresh() naturally resets it. P1-13: subscriptions are
     * Billing's (ee) — read through {@see PlanCatalog} (none in Community). The
     * `subscriptions` relation (all rows, history included) is registered by
     * Billing as a dynamic relation.
     */
    public function liveSubscription(): ?Model
    {
        if ($this->liveSubscriptionMemo === null) {
            $this->liveSubscriptionMemo = app(PlanCatalog::class)->liveSubscription($this) ?? false;
        }

        return $this->liveSubscriptionMemo ?: null;
    }

    /**
     * The plan this account is on: a plan model, or the configured trial/free
     * plan objects (Billing, ee — subscriptions first, legacy group_id as the
     * fallback); in Community the edition plan with no limits set. P1-13:
     * resolved by {@see PlanCatalog}.
     *
     * @return mixed
     */
    public function plan()
    {
        // PERF: memoize per instance. The nav/entitlement resolution calls plan()
        // ~20× per dashboard render (legacy entitlement lane + planName), each
        // firing a Plan::find(); the plan does not change within a request.
        // ->fresh() naturally resets it, like liveSubscription().
        if ($this->planMemo !== false) {
            return $this->planMemo;
        }

        return $this->planMemo = app(PlanCatalog::class)->planFor($this);
    }

    /** @return bool true for platform administrators (user_type 'admin'). */
    public function isAdmin()
    {
        return (string) $this->user_type === 'admin';
    }

    /**
     * Stop a gateway-side recurring subscription and put the upgrade row back
     * on one-time billing. `upgrades.unique_id` is "<gateway>###<remote id>".
     * Stripe and PayPal are cancelled here; Paddle Billing / Lemon Squeezy
     * (M2, merchant-of-record) through GatewaySubscriptionCanceller, so the
     * renewal webhook stops re-activating the plan. Unknown gateways only get
     * the local reset.
     */
    public function cancelRecurringSubscription()
    {
        $upgrade = $this->upgrade;
        if (! $upgrade || ! $upgrade->unique_id) {
            return;
        }

        $parts = explode('###', (string) $upgrade->unique_id);
        $gateway = strtolower($parts[0]);
        $remoteId = $parts[1] ?? null;

        if ($remoteId !== null && $gateway === 'stripe') {
            Stripe::setApiKey(config('settings.stripe_secret_key'));
            Subscription::retrieve($remoteId)->cancel();
        } elseif ($remoteId !== null && $gateway === 'paypal') {
            GatewaySubscriptionCanceller::cancelPaypal($remoteId);
        } elseif ($remoteId !== null && GatewaySubscriptionCanceller::handles($gateway)) {
            GatewaySubscriptionCanceller::cancel($gateway, $remoteId);
        }

        $upgrade->forceFill(['unique_id' => null, 'pay_mode' => 'one_time'])->save();
    }

    /** The owner's stores, newest first. */
    public function stores()
    {
        return $this->hasMany(Store::class)->latest();
    }

    public function upgrade()
    {
        return $this->hasOne(Upgrade::class);
    }

    /**
     * The most recent upgrade row for this user (used by #66 dunning banner).
     */
    public function currentUpgrade(): ?Upgrade
    {
        return Upgrade::where('user_id', $this->id)->orderByDesc('upgrade_id')->first();
    }

    /**
     * P1-03 (J25-1): UUIDv7 public id (HasUuids) for URLs/APIs; the bigint id stays the primary key.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /**
     * Bug 4 (2026-10-01): users.country_code is a 2-letter ISO-3166 alpha-2 column (was char(50), which also let
     * the geo-absent registration sentinel 'Other' in). The ISO-2 form of any value, or null: trimmed (rows read
     * back from an old CHAR column carry PostgreSQL's space padding), upper-cased, and anything that is not exactly
     * two ASCII letters ('Other', '', a dial code, a country name) is "no country".
     */
    public static function isoCountryCode(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $code = strtoupper(trim($value));

        return preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : null;
    }

    /** Tolerant read: a legacy or padded value comes back as its ISO-2 code or null. */
    public function getCountryCodeAttribute(mixed $value): ?string
    {
        return self::isoCountryCode($value);
    }

    /** Every Eloquent writer stores the ISO-2 code or null, never a longer value. */
    public function setCountryCodeAttribute(mixed $value): void
    {
        $this->attributes['country_code'] = self::isoCountryCode($value);
    }
}
