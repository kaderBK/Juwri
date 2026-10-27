<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Concerns\NormalisesEmail;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;
use Juwri\Modules\Auth\Models\StaffPasskey;
use Juwri\Modules\Messaging\Contracts\MobilePush;
use Juwri\Modules\Team\Models\StaffToken;

/**
 * P1-05: `hourly_rate` / `base_wage` are stored as `*_minor` in the row's `currency_code` (the store's; a catalogue
 * row — an edited wage moves to the store's current currency). They keep the store-precision string shape of the
 * old StoreMoney cast. Query with the `*_minor` columns.
 *
 * @property string|float|null $hourly_rate major amount (store-precision string on read)
 * @property string|float|null $base_wage major amount (store-precision string on read)
 * @property int|null $hourly_rate_minor
 * @property int|null $base_wage_minor
 * @property string|null $currency_code
 */
class StaffMember extends Authenticatable implements MinorMoneyModel
{
    /** BL-036 re-review: fields whose change stamps security_changed_at (never last_login_at or profile data). */
    public const SECURITY_FIELDS = ['email', 'password', 'pin', 'pin_hash', 'pin_offline_kdf', 'permissions', 'role', 'active', 'owner_id', 'account_id', 'store_id', 'branch_id'];

    /**
     * The column arrives with a migration; saves made before it (older migrations, seeders) skip the stamp. Memoised
     * per app instance (a container binding), never in a process static.
     */
    private static function hasSecurityStamp(): bool
    {
        if (! app()->bound('__staff_security_stamp')) {
            app()->instance('__staff_security_stamp', Schema::hasColumn('staff_members', 'security_changed_at'));
        }

        return (bool) app('__staff_security_stamp');
    }

    protected static function booted(): void
    {
        static::saving(function (self $staff) {
            if ((! $staff->exists || $staff->isDirty(self::SECURITY_FIELDS)) && self::hasSecurityStamp()) {
                $staff->security_changed_at = now();
            }
        });
        // P6-269: a new password, a deactivation or a removal stops push to every
        // device of the staff member (removal also ends their app sign-ins).
        static::saved(function (self $staff) {
            // wasChanged() is only filled by an UPDATE (an insert leaves it empty);
            // wasRecentlyCreated stays true on the instance, so it is no guard.
            if ($staff->wasChanged('password') || ($staff->wasChanged('active') && ! $staff->active)) {
                app(MobilePush::class)->revokeDevices(['staff_id' => (int) $staff->id]);
            }
        });
        static::deleted(function (self $staff) {
            StaffToken::withoutGlobalScopes()->where('staff_id', $staff->id)->delete();
            app(MobilePush::class)->revokeDevices(['staff_id' => (int) $staff->id]);
        });
    }

    use BelongsToAccount;
    use HasMinorMoney;
    use NormalisesEmail;
    use Notifiable; // P6-40: emails stored lowercased

    protected $table = 'staff_members';

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $minorMoney = ['hourly_rate' => 'hourly_rate_minor,store', 'base_wage' => 'base_wage_minor,store'];

    protected $hidden = ['password', 'remember_token', 'pin', 'pin_hash', 'pin_offline_salt', 'pin_offline_kdf', 'two_factor_secret', 'two_factor_recovery_codes'];

    protected $casts = [
        'permissions' => 'array',
        'active' => 'boolean',
        'last_login_at' => 'datetime',
        'security_changed_at' => 'datetime',
        // HPMS E12(b): encrypted TOTP secret + recovery codes.
        'two_factor_secret' => 'encrypted',
        'two_factor_recovery_codes' => 'encrypted:array',
        'two_factor_confirmed_at' => 'datetime',
    ];

    /** HPMS E12(b): has this staff member enrolled (and confirmed) TOTP 2FA? */
    public function twoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null && ! empty($this->two_factor_secret);
    }

    public const ROLE_PERMISSIONS = [
        // WI-H1: hotel_folio gates the guest-folio money endpoints (check-in, settle,
        // check-out/write-off) and the stays/statement screens. Manager-only by default,
        // like pos_refund / pos_shift_close; grant it per-staff from Setup → Staff.
        // HP-B1: hotel_requests = the service-request board (a floor tool — waiters get
        // it too); hotel_admin = rooms CRUD + guest-info/settings + hotel_mode toggle
        // (owner-level config, managers only). Grant/revoke per-staff from Setup → Staff.
        // D2: pos_drawer_open gates opening / handing over a cash drawer shift.
        // Manager + cashier hold it by default (they run the till); kitchen/waiter
        // do not. Revoke per-staff from Setup → Staff to bar a specific member.
        // BL-036 A2a: service-floor keys added to the roles that obviously run them.
        'manager' => ['orders', 'menu_edit', 'coupons', 'analytics', 'kds', 'customers', 'pos', 'pos_refund', 'pos_shift_close', 'pos_drawer_open', 'hotel_folio', 'hotel_requests', 'hotel_admin', 'reservations', 'waiter', 'dispatch', 'catering', 'food_safety', 'inventory'],
        'cashier' => ['orders', 'kds', 'pos', 'pos_drawer_open'],
        'kitchen' => ['kds', 'food_safety'],
        'waiter' => ['orders', 'pos', 'hotel_requests', 'reservations', 'waiter'],
    ];

    /**
     * BL-036 A2a: every permission an owner can grant per staff member, grouped for the Staff
     * page sheet. `prefixes` are dashboard route-name prefixes (always ending in a dot) and `routes`
     * exact route names the permission opens (EnsureStaffDashboardPermission; its map can close a
     * single route under a prefix). Every controller behind them scopes to dashboard_store_ids(),
     * so a staff member never reaches another store of the same owner (CrossStoreIdorSweepTest). `feature` hides the switch when the owner's plan lacks it;
     * `hotel` when the store has no hotel. Billing, staff, settings, integrations, domains and
     * backups are never grantable: they stay owner-only (default-deny).
     *
     * @var array<string, array<string, array{label: string, prefixes?: list<string>, routes?: list<string>, feature?: string, hotel?: bool}>>
     */
    public const PERMISSION_CATALOG = [
        'Service' => [
            'orders' => ['label' => 'Orders'],
            'kds' => ['label' => 'Kitchen display (KDS)', 'feature' => 'kds'],
            'reservations' => ['label' => 'Reservations, waitlist & floor map', 'prefixes' => ['reservations.', 'waitlist.', 'floor-map.']],
            'waiter' => ['label' => 'Waiter mode', 'prefixes' => ['stores.waiter.'], 'feature' => 'waiter_mode'],
            'dispatch' => ['label' => 'Delivery dispatch & drivers', 'prefixes' => ['stores.dispatch.', 'drivers.']],
            'catering' => ['label' => 'Catering', 'prefixes' => ['stores.catering.'], 'feature' => 'catering'],
            'food_safety' => ['label' => 'Food safety logs', 'prefixes' => ['stores.foodsafety.'], 'feature' => 'food_safety'],
        ],
        'POS' => [
            'pos' => ['label' => 'Use the POS register', 'feature' => 'pos_enabled'],
            'pos_drawer_open' => ['label' => 'Open cash drawer', 'feature' => 'pos_enabled'],
            'pos_refund' => ['label' => 'Refunds & voids', 'feature' => 'pos_enabled'],
            'pos_shift_close' => ['label' => 'Close shift', 'feature' => 'pos_enabled'],
        ],
        'Menu' => [
            'menu_edit' => ['label' => 'Edit menu, modifiers & combos', 'prefixes' => ['modifiers.', 'stores.schedules.', 'combos.', 'stores.translations.']],
            'inventory' => ['label' => 'Inventory & suppliers', 'prefixes' => ['inventory.'], 'feature' => 'inventory'],
            'design' => ['label' => 'Storefront design, banners & QR', 'prefixes' => ['banners.', 'stories.', 'stores.signage.', 'theme.customizer.', 'custom-blocks.', 'qr.', 'stores.kiosk.', 'media.']],
            'biolink' => ['label' => 'Bio page', 'feature' => 'biolink'],
        ],
        'Customers & marketing' => [
            'customers' => ['label' => 'Customers & CRM', 'prefixes' => ['crm.']],
            'coupons' => ['label' => 'Coupons, gift cards & loyalty', 'prefixes' => ['stores.giftcards.', 'loyalty.']],
            // The owner's referral programme (referral.*) is account-level and stays owner-only.
            'marketing' => ['label' => 'Campaigns, automations & WhatsApp inbox', 'prefixes' => ['campaigns.', 'stores.automations.', 'stores.whatsappInbox.', 'stores.whatsappCampaign.', 'stores.whatsappTemplates.'], 'routes' => ['stores.whatsappInbox', 'stores.whatsappCampaign', 'stores.whatsappTemplates']],
            'reviews' => ['label' => 'Reviews & feedback', 'prefixes' => ['dashboard.store-reviews.', 'stores.feedback.'], 'routes' => ['dashboard.store-reviews']],
        ],
        'Reports & money' => [
            'analytics' => ['label' => 'Analytics & reports', 'prefixes' => ['reports.', 'stores.deliveryInsights.']],
            'finance' => ['label' => 'Books, accounting, tax & disputes', 'prefixes' => ['stores.books.', 'stores.accounting.', 'fiscal.', 'stores.disputes.']],
        ],
        'Team' => [
            'team' => ['label' => 'Team chat, checklists & rota', 'prefixes' => ['stores.team.', 'stores.rota.', 'stores.rotaplus.', 'stores.leave.']],
            'payroll' => ['label' => 'Payroll', 'prefixes' => ['stores.payroll.'], 'feature' => 'payroll_suite'],
        ],
        'Hotel' => [
            'hotel_requests' => ['label' => 'Service requests', 'hotel' => true],
            'hotel_folio' => ['label' => 'Guest folios & check-out', 'hotel' => true],
            'hotel_admin' => ['label' => 'Rooms & hotel settings', 'hotel' => true],
        ],
    ];

    /**
     * Every key of PERMISSION_CATALOG (StaffController accepts nothing else).
     *
     * @return list<string>
     */
    public static function grantablePermissions(): array
    {
        return array_keys(array_merge(...array_values(self::PERMISSION_CATALOG)));
    }

    /**
     * P6-72: every per-staff permission key the owner may grant/revoke (Setup → Staff).
     * Unknown keys are refused (422) so the permissions map holds nothing but these.
     * BL-036 A2a: the keys of PERMISSION_CATALOG (StaffPermissionCatalogTest keeps the two equal).
     */
    public const PERMISSION_KEYS = [
        'orders', 'kds', 'reservations', 'waiter', 'dispatch', 'catering', 'food_safety',
        'pos', 'pos_drawer_open', 'pos_refund', 'pos_shift_close',
        'menu_edit', 'inventory', 'design', 'biolink',
        'customers', 'coupons', 'marketing', 'reviews',
        'analytics', 'finance', 'team', 'payroll',
        'hotel_requests', 'hotel_folio', 'hotel_admin',
    ];

    /**
     * S5 — resolve an active staff member of $storeId by PIN.
     *
     * Dual-read: prefer the bcrypt pin_hash; fall back to the legacy plaintext
     * pin for rows not yet backfilled (constant-time compare; a match is rehashed
     * and the plaintext nulled — P6-88 lazy migration). Returns null when
     * no active staff matches. PIN space is small — the callers keep the
     * throttle:10,1 route guard, and failures are also counted PER STORE (IP
     * rotation does not help): PIN_MAX_FAILURES wrong PINs in PIN_LOCK_SECONDS, or
     * PIN_MAX_DAILY_FAILURES in a day, lock PIN login for that store until the window passes.
     */
    public static function authenticateByPin(int $storeId, string $pin): ?self
    {
        if (static::pinLoginLocked($storeId)) {
            return null;
        }

        $match = self::matchPin($storeId, $pin);
        if ($match && $match->pin_offline_kdf === null) {
            $match->rememberOfflinePin($pin); // a PIN set before offline POS: its offline verifier source, on first use
            $match->save();
        }
        if (! $match) {
            foreach ([[self::pinFailureKey($storeId), self::PIN_LOCK_SECONDS, self::PIN_MAX_FAILURES],
                [self::pinDailyKey($storeId), self::PIN_DAY_SECONDS, self::PIN_MAX_DAILY_FAILURES]] as [$key, $decay, $max]) {
                RateLimiter::hit($key, $decay);
                if (RateLimiter::attempts($key) === $max) {
                    Log::warning("[staff-pin] PIN login locked for store {$storeId} after {$max} failures in {$decay} s");
                }
            }
        }

        return $match;
    }

    /**
     * P4-06 (coordinator default 2026-10-07): a PIN alone names no staff member, so failures can only be counted per
     * store. Before: 30 per 15 min (2,880 guesses a day against every active PIN of the store at once — a 4-digit PIN
     * of a 10-person team fell in about a day). Now 5 wrong PINs lock PIN sign-in for 15 minutes, and 20 in a day lock
     * it for the rest of that day (≤ 20 guesses a day). Email + password sign-in keeps working while locked.
     */
    public const PIN_MAX_FAILURES = 5;

    public const PIN_LOCK_SECONDS = 900;

    public const PIN_MAX_DAILY_FAILURES = 20;

    public const PIN_DAY_SECONDS = 86400;

    public static function pinLoginLocked(int $storeId): bool
    {
        return RateLimiter::tooManyAttempts(self::pinFailureKey($storeId), self::PIN_MAX_FAILURES)
            || RateLimiter::tooManyAttempts(self::pinDailyKey($storeId), self::PIN_MAX_DAILY_FAILURES);
    }

    private static function pinFailureKey(int $storeId): string
    {
        return 'staff-pin-failures:'.$storeId;
    }

    private static function pinDailyKey(int $storeId): string
    {
        return 'staff-pin-failures-day:'.$storeId;
    }

    private static function matchPin(int $storeId, string $pin): ?self
    {
        $candidates = static::where('store_id', $storeId)
            ->where('active', true)
            ->get();

        foreach ($candidates as $staff) {
            if (! empty($staff->pin_hash)) {
                if (Hash::check($pin, $staff->pin_hash)) {
                    return $staff;
                }

                continue;
            }

            // P6-88: legacy plaintext row (pin_hash never backfilled) — constant-time compare, then lazily migrate:
            // hash it and null the plaintext so the row is hash-only from here on (staff:hash-legacy-pins does the rest).
            if ($staff->pin !== null && $staff->pin !== '' && hash_equals((string) $staff->pin, $pin)) {
                $staff->forceFill(['pin_hash' => Hash::make($pin), 'pin' => null]);
                $staff->rememberOfflinePin($pin);
                $staff->save();

                return $staff;
            }
        }

        return null;
    }

    /** PBKDF2 rounds of the offline PIN verifier source (offline POS device PIN check). */
    public const OFFLINE_PIN_ROUNDS = 100000;

    /**
     * Offline POS: the source of the offline PIN verifier — PBKDF2-SHA256(PIN, own salt). Written whenever the plain PIN
     * is at hand (set, backfill, a successful PIN login); a till gets only HMAC(its own pepper, this) in its snapshot.
     */
    public function rememberOfflinePin(string $pin): void
    {
        $salt = bin2hex(random_bytes(16));
        $this->forceFill(['pin_offline_salt' => $salt, 'pin_offline_kdf' => hash_pbkdf2('sha256', $pin, $salt, self::OFFLINE_PIN_ROUNDS, 64)]);
    }

    public function hasPermission(string $permission): bool
    {
        $overrides = $this->permissions ?? [];
        if (array_key_exists($permission, $overrides)) {
            return (bool) $overrides[$permission];
        }

        return in_array($permission, static::ROLE_PERMISSIONS[$this->role] ?? []);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /**
     * P1-19b: this staff member's own passkeys.
     *
     * @return HasMany<StaffPasskey, $this>
     */
    public function passkeys(): HasMany
    {
        return $this->hasMany(StaffPasskey::class, 'staff_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function station()
    {
        return $this->belongsTo(KitchenStation::class, 'station_id');
    }

    public function scopeForOwner($query, int $ownerId)
    {
        return $query->where('owner_id', $ownerId);
    }
}
