<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Reservations\Services\Rwg;

/**
 * GOOGLE-PARTNER Lane C — Reserve with Google config gate.
 *
 * Single source of truth for the `services.reserve_with_google` block. Every
 * external effect (booking-server auth, feed SFTP upload, RTU push) sits behind
 * these checks so a missing-creds environment produces zero HTTP, zero jobs, and
 * a 503 on every booking-server call (dark discipline, RUNBOOK §4).
 */
class RwgService
{
    /** Master switch AND kill-switch. Off ⇒ booking server 503, no feeds, no RTU. */
    public static function enabled(): bool
    {
        return (bool) config('services.reserve_with_google.enabled', false);
    }

    /** fake|real. `real` lets the feeds command attempt SFTP and the RTU client attempt HTTP. */
    public static function driver(): string
    {
        return (string) config('services.reserve_with_google.driver', 'fake');
    }

    public static function isReal(): bool
    {
        return self::driver() === 'real';
    }

    public static function basicUser(): string
    {
        return (string) config('services.reserve_with_google.basic_user', '');
    }

    public static function basicPass(): string
    {
        return (string) config('services.reserve_with_google.basic_pass', '');
    }

    /** Are the booking-server Basic credentials both present? */
    public static function credentialsConfigured(): bool
    {
        return self::basicUser() !== '' && self::basicPass() !== '';
    }

    /** Absolute path to the RTU service-account key file (empty ⇒ RTU no-ops). */
    public static function serviceAccountJson(): string
    {
        return (string) config('services.reserve_with_google.service_account_json', '');
    }

    public static function rtuEndpoint(): string
    {
        return (string) config('services.reserve_with_google.rtu_endpoint', '');
    }

    public static function rtuScope(): string
    {
        return (string) config('services.reserve_with_google.rtu_scope', '');
    }

    /**
     * SFTP config for the daily feed drop. Empty host ⇒ feeds are written to
     * storage/app/rwg/ for inspection but never uploaded.
     *
     * @return array{host:string,port:int,user:string,key:string,path:string}
     */
    public static function sftp(): array
    {
        return [
            'host' => (string) config('services.reserve_with_google.sftp_host', ''),
            'port' => (int) config('services.reserve_with_google.sftp_port', 22),
            'user' => (string) config('services.reserve_with_google.sftp_user', ''),
            'key'  => (string) config('services.reserve_with_google.sftp_key', ''),
            'path' => (string) config('services.reserve_with_google.sftp_path', '/'),
        ];
    }

    public static function sftpConfigured(): bool
    {
        $s = self::sftp();
        return $s['host'] !== '' && $s['user'] !== '';
    }
}
