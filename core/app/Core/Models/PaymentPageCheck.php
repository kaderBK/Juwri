<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * PCI DSS 4.0 11.6.1: one change-detection check of one payment page (see the payment_page_security migration).
 * `baseline` marks the page's accepted known-good row (pci:accept-baseline).
 *
 * @property int $id
 * @property string $page
 * @property string $url
 * @property string $status
 * @property array<int, array{src: string, integrity: string|null, content_hash: string|null}>|null $scripts
 * @property int $inline_scripts
 * @property array<string, string>|null $headers
 * @property string|null $script_hash
 * @property string|null $header_hash
 * @property array<int, string>|null $changes
 * @property bool $baseline
 * @property string|null $accepted_by
 * @property Carbon|null $accepted_at
 * @property string|null $error
 * @property Carbon $checked_at
 */
class PaymentPageCheck extends Model
{
    public const STATUS_BASELINE = 'baseline';

    public const STATUS_UNCHANGED = 'unchanged';

    public const STATUS_CHANGED = 'changed';

    public const STATUS_ERROR = 'error';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'scripts' => 'array',
        'headers' => 'array',
        'changes' => 'array',
        'inline_scripts' => 'integer',
        'baseline' => 'boolean',
        'accepted_at' => 'datetime',
        'checked_at' => 'datetime',
    ];

    /** The accepted known-good check of $page, or null. */
    public static function baselineOf(string $page): ?self
    {
        return self::query()->where('page', $page)->where('baseline', true)->orderByDesc('id')->first();
    }
}
