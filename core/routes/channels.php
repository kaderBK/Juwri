<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Support\Facades\Broadcast;
use Juwri\Core\Broadcasting\LiveUpdates;
use Juwri\Core\Broadcasting\StoreChannelAuthorizer;
use Juwri\Core\Models\User;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

// The owner's own notification channel. Web guard and the User model only (P6-57): a staff-only login (StaffMember,
// own id sequence) or a staff session on the owner's login must never match an owner's id.
Broadcast::channel(
    'App.Models.User.{id}',
    fn ($user, $id) => $user instanceof User && staff_session() === null
        && ctype_digit((string) $id) && (int) $user->id === (int) $id,
    ['guards' => ['web']],
);

/*
| Live updates (Reverb, ROADMAP-LATER item 17): one private channel per store and screen, plus one per branch and
| screen (P5-19 G1). Access follows the dashboard's own store rules (see StoreChannelAuthorizer): the store's owner, or
| that store's staff holding the orders / kds / pos permission; staff limited to one branch get only that branch's
| channels. Guests never reach this: the auth route needs a login on the web or staff guard. Ids must be plain digits
| (P6-57): "store.1x.orders" or "store.1e3.orders" is refused instead of being cast to a store id.
*/
foreach (array_keys(LiveUpdates::PERMISSIONS) as $suffix) {
    Broadcast::channel(
        'store.{storeId}.'.$suffix,
        fn ($user, $storeId) => ctype_digit((string) $storeId) && app(StoreChannelAuthorizer::class)->authorize($user, (int) $storeId, $suffix),
        ['guards' => ['web', 'staff']],
    );
    Broadcast::channel(
        'store.{storeId}.branch.{branchId}.'.$suffix,
        fn ($user, $storeId, $branchId) => ctype_digit((string) $storeId) && ctype_digit((string) $branchId) && app(StoreChannelAuthorizer::class)->authorizeBranch($user, (int) $storeId, (int) $branchId, $suffix),
        ['guards' => ['web', 'staff']],
    );
}
