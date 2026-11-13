<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Listeners;

use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Juwri\Core\Contracts\Tenancy\ResellerContext;
use Juwri\Core\Models\User;
use Juwri\Modules\Auth\Mail\NewDeviceLogin;
use Juwri\Modules\Auth\Models\LoginAudit;

/**
 * A1 (upstream superadmin hardening, 185d3adb0): audit sign-ins to superadmin and
 * reseller-owner accounts and email the account when a sign-in comes from a
 * device it has not used before. Runs once per session (Login also fires
 * for remember-me re-authentication).
 */
class RecordPrivilegedLogin
{
    public function handle(Login $event): void
    {
        $user = $event->user;
        if (! $user instanceof User || $event->guard !== 'web') {
            return;
        }
        $request = request();
        $session = $request->hasSession() ? $request->session() : null;
        if ($session && (int) $session->get('login_audited_for') === (int) $user->id) {
            return;
        }
        if (! $user->isAdmin() && ! app(ResellerContext::class)->ownsReseller((int) $user->id)) {
            return;
        }

        try {
            $ua = mb_substr((string) $request->userAgent(), 0, 255);
            $hash = hash('sha256', mb_strtolower(trim($ua)));
            $seenBefore = LoginAudit::where('user_id', $user->id)->exists();
            $knownDevice = LoginAudit::where('user_id', $user->id)->where('device_hash', $hash)->exists();

            LoginAudit::create([
                'user_id' => $user->id,
                'ip' => $request->ip(),
                'user_agent' => $ua,
                'device_hash' => $hash,
                'new_device' => ! $knownDevice,
                'created_at' => now(),
            ]);
            $session?->put('login_audited_for', $user->id);

            // The very first recorded sign-in is the baseline, not an alert.
            if ($seenBefore && ! $knownDevice && $user->email) {
                // P6-193: queued after commit — SMTP never delays or breaks the sign-in.
                Mail::to($user->email)->queue((new NewDeviceLogin($user, (string) $request->ip(), $ua, now()->toDayDateTimeString().' UTC'))->afterCommit());
            }
        } catch (\Throwable $e) {
            Log::warning('[login-audit] '.$e->getMessage());
        }
    }
}
