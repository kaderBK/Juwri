<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Listeners;

use App\Models\WebhookEndpoint;
use Juwri\Modules\Auth\Events\EmailChangeUndone;
use Juwri\Modules\Integrations\Models\ApiToken;

/**
 * BL-036 A1a/A1b: an undone email change means whoever held the new inbox had the account since the
 * change. Every API token of the user goes (integration keys and owner-app devices alike; the owner
 * issues new ones after the reset). Outgoing webhook endpoints created or edited since the change could
 * be sending orders and customers to the taker: switched off (never deleted, so the owner can review and
 * resume the legitimate ones); their ids go on the undo row.
 */
class RevokeAccessOnEmailChangeUndo
{
    public function handle(EmailChangeUndone $event): void
    {
        $removed = ApiToken::where('user_id', (int) $event->user->id)->delete();
        if ($removed > 0) {
            $event->cleared[] = 'api_tokens';
        }

        $paused = WebhookEndpoint::where('user_id', (int) $event->user->id)->where('active', true)
            ->where(fn ($q) => $q->where('created_at', '>=', $event->since)->orWhere('updated_at', '>=', $event->since))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($paused !== []) {
            WebhookEndpoint::whereIn('id', $paused)->update([
                'active' => false,
                'disabled_reason' => mb_substr((string) ___('Paused: created or changed after an email change that was undone. Review before resuming.'), 0, 190),
                'updated_at' => now(),
            ]);
            $event->cleared[] = 'webhooks';
            $event->meta['deactivated_webhooks'] = array_values($paused);
        }
    }
}
