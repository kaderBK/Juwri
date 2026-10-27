<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * P3-08 / N5: a shopper's "tell me when it's back" request for an item (or one variant of it). Taken only with the
 * shopper's marketing opt-in (`opted_in_at`) and only while the store has back-in-stock messages on; sent once, when
 * the item or variant is back in stock, then `sent`. The contact (email address or phone) is stored encrypted, with a
 * hash for "one request per contact and item".
 *
 * @property int $id
 * @property string|null $public_id
 * @property int $store_id
 * @property int $menu_id
 * @property int|null $variant_id
 * @property int|null $customer_id
 * @property string $channel
 * @property string $contact
 * @property string $contact_hash
 * @property string $target
 * @property string|null $locale
 * @property string $status
 * @property Carbon $opted_in_at
 * @property Carbon|null $notified_at
 */
class StockAlert extends Model
{
    use BelongsToAccount;
    use HasUuids;

    protected $table = 'stock_alerts';

    public const CHANNELS = ['email', 'whatsapp', 'sms'];

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'contact' => 'encrypted',
        'opted_in_at' => 'datetime',
        'notified_at' => 'datetime',
    ];

    /** @var list<string> */
    protected $hidden = ['contact', 'contact_hash'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public static function target(int $menuId, ?int $variantId): string
    {
        return 'm'.$menuId.':v'.(int) $variantId;
    }

    public static function hashContact(string $channel, string $contact): string
    {
        $normal = $channel === 'email' ? mb_strtolower(trim($contact)) : preg_replace('/[^\d+]/', '', $contact);

        return hash('sha256', $channel.'|'.$normal);
    }
}
