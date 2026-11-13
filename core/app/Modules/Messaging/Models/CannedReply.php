<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/** SYSTEMS-RUN S-P2: an owner-managed canned reply with {placeholder} support. */
class CannedReply extends Model
{
    use BelongsToAccount;

    protected $guarded = [];

    public function render(array $vars = []): string
    {
        $body = $this->body;
        foreach ($vars as $k => $v) {
            $body = str_replace('{' . $k . '}', (string) $v, $body);
        }
        return $body;
    }
}
