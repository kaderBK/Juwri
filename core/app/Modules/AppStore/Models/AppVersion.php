<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\AppStore\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\App;

/** P5-1 (feature-platform): one immutable snapshot per app submission. */
class AppVersion extends Model
{
    protected $guarded = [];

    protected $casts = ['snapshot' => 'array'];

    /** Freeze the app's review-relevant state as the next version. */
    public static function freeze(App $app, string $status = 'submitted'): self
    {
        return static::create([
            'app_id' => $app->id,
            'version' => (int) static::where('app_id', $app->id)->max('version') + 1,
            'status' => $status,
            'snapshot' => [
                'name' => $app->name,
                'scopes' => $app->scopes,
                'webhook_url' => $app->webhook_url,
                'webhook_events' => $app->webhook_events,
                'billing_type' => $app->billing_type,
                'price' => $app->price,
                'billing_period_days' => $app->billing_period_days,
                'trial_days' => $app->trial_days,
                'usage_unit' => $app->usage_unit,
                'usage_price' => $app->usage_price,
                'grants_features' => $app->grants_features,
                'tagline' => $app->tagline,
                'category' => $app->category,
            ],
        ]);
    }
}
