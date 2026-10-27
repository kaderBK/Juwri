<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Listeners;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Juwri\Core\Models\UserOption;
use Juwri\Modules\Auth\Events\CredentialsChanged;
use Juwri\Modules\Integrations\Mail\ApiTokensReviewMail;
use Juwri\Modules\Integrations\Models\ApiToken;
use Juwri\Modules\Messaging\Contracts\MobilePush;

/**
 * P6-75: what a credential change does to the user's api_tokens rows, by kind:
 *
 *  - kind=app (owner mobile app, 180-day device tokens): revoked, except the
 *    current device when the user chose to keep it (settings password change only).
 *  - kind=api (personal integration tokens): NOT revoked silently — an
 *    integration breaking without warning is its own outage. The user gets a
 *    notice on the API page listing them with one-click revoke, and an email.
 */
class RevokeTokensOnCredentialChange
{
    public const REVIEW_OPTION = 'api_tokens_review_since';

    public function handle(CredentialsChanged $event): void
    {
        $userId = (int) $event->user->id;

        ApiToken::where('user_id', $userId)
            ->where('kind', 'app')
            ->when($event->keepAppTokenId, fn ($q, $keep) => $q->whereKeyNot($keep))
            ->delete();

        // P6-269: the signed-out devices stop receiving order pushes too.
        app(MobilePush::class)->revokeDevices(['owner_user_id' => $userId, 'keep_api_token_id' => $event->keepAppTokenId]);

        $personal = self::personalTokens($userId)->pluck('name')->all();
        if ($personal === []) {
            return;
        }

        UserOption::updateUserOption($userId, self::REVIEW_OPTION, now()->toIso8601String());

        try {
            if ($event->user->email) {
                Mail::to($event->user->email)->send(new ApiTokensReviewMail($event->user, $event->reason, $personal));
            }
        } catch (\Throwable $e) {
            Log::warning('[p6-75] api token review mail failed: '.$e->getMessage());
        }
    }

    /**
     * The user's integration (non-app) tokens.
     *
     * @return Builder<ApiToken>
     */
    public static function personalTokens(int $userId): Builder
    {
        return ApiToken::where('user_id', $userId)
            ->where(fn ($q) => $q->where('kind', '!=', 'app')->orWhereNull('kind'));
    }
}
