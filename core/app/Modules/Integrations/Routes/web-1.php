<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Integrations/web-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// #99: API token + webhook management UI
            Route::get('api', '\Juwri\Modules\Integrations\Http\Controllers\User\ApiTokenController@index')->name('api.index');
            // MCP-expansion P4-1: Connect AI (MCP endpoints + client config)
            Route::get('connect-ai', '\Juwri\Modules\Integrations\Http\Controllers\User\ApiTokenController@connectAi')->name('connect-ai');
            Route::post('api/tokens', '\Juwri\Modules\Integrations\Http\Controllers\User\ApiTokenController@createToken')->middleware('password.confirm')->name('api.token.create'); // S8 re-auth
            Route::delete('api/tokens/{id}', '\Juwri\Modules\Integrations\Http\Controllers\User\ApiTokenController@deleteToken')->name('api.token.delete');
            Route::post('api/tokens/review/revoke', '\Juwri\Modules\Integrations\Http\Controllers\User\ApiTokenController@revokeReviewedTokens')->name('api.token.review.revoke'); // P6-75
            Route::post('api/tokens/review/dismiss', '\Juwri\Modules\Integrations\Http\Controllers\User\ApiTokenController@dismissTokenReview')->name('api.token.review.dismiss'); // P6-75
            Route::post('api/webhooks', '\Juwri\Modules\Integrations\Http\Controllers\User\ApiTokenController@createWebhook')->name('api.webhook.create');
            Route::delete('api/webhooks/{id}', '\Juwri\Modules\Integrations\Http\Controllers\User\ApiTokenController@deleteWebhook')->name('api.webhook.delete');
            Route::post('api/webhooks/{id}/toggle', '\Juwri\Modules\Integrations\Http\Controllers\User\ApiTokenController@toggleWebhook')->name('api.webhook.toggle');
            Route::post('api/webhooks/{id}/test', '\Juwri\Modules\Integrations\Http\Controllers\User\ApiTokenController@testWebhook')->name('api.webhook.test');
            // A4: re-queue a single past delivery (owner-scoped) with a fresh message id.
            Route::post('api/webhooks/deliveries/{id}/redeliver', '\Juwri\Modules\Integrations\Http\Controllers\User\ApiTokenController@redeliverDelivery')->name('api.webhook.redeliver');
