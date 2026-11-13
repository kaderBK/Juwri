<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One platform setting row (P1-07; was `options`). `value` is the JSON text of the typed value
 * (secrets: an encrypted string). Read and write through Juwri\Core\Settings\PlatformSettings,
 * which types, encrypts and caches — never render `value` of a secret directly.
 *
 * @property int $id
 * @property string $name
 * @property string|null $value
 */
class PlatformSetting extends Model
{
    protected $table = 'platform_settings';

    protected $fillable = ['name', 'value'];

    /** A credential never leaves the model in toArray()/toJson(). */
    protected $hidden = ['value'];
}
