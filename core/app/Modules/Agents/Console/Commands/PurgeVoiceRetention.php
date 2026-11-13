<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Console\Commands;

use Illuminate\Console\Command;
use Juwri\Modules\Agents\Services\Telephony\VoiceRetention;

/**
 * P6-09 V-18: run the voice retention purge (transcript / audio of call logs older than their line's effective
 * retention window). Platform-wide: every account's calls are judged by their own line settings.
 */
class PurgeVoiceRetention extends Command
{
    protected $signature = 'voice:purge-retention';

    protected $description = 'Purge call transcripts and recorded audio older than their retention window';

    public function handle(): int
    {
        $purged = app(VoiceRetention::class)->purge();

        $this->info('Purged '.$purged['transcripts'].' transcript(s) and '.$purged['audio'].' audio file(s).');

        return self::SUCCESS;
    }
}