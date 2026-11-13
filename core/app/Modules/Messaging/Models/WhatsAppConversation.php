<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/** SYSTEMS-RUN S-P2: per-contact WhatsApp conversation state. */
class WhatsAppConversation extends Model
{
    use BelongsToAccount;

    protected $table = 'whatsapp_conversations';
    protected $guarded = [];
}
