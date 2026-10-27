<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Modules\Messaging\Services\MessageCostLedger;

/**
 * P1-08b follow-up (J27): one line of the message cost ledger (`message_costs`), written by
 * {@see MessageCostLedger} for every sent WhatsApp/SMS/email message.
 * Append-only. `unit_cost_minor`/`cost_minor`: decimal strings in minor units of `currency` (sub-cent rates kept
 * exactly); null = cost unknown (BYOK SMS, email).
 *
 * @property int $id
 * @property int|null $account_id
 * @property int|null $store_id
 * @property string $channel
 * @property string $category
 * @property int $units
 * @property string|null $unit_cost_minor
 * @property string|null $cost_minor
 * @property string|null $currency
 */
class MessageCost extends Model
{
    use BelongsToAccount;

    public const CHANNELS = ['whatsapp', 'sms', 'email', 'voice']; // P6-09 V-12: voice = phone-agent minutes

    protected $table = 'message_costs';

    protected $guarded = [];

    protected $casts = [
        'units' => 'integer',
        'unit_cost_minor' => 'decimal:6',
        'cost_minor' => 'decimal:6',
    ];
}
