<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Juwri\Core\Models\Store;
use Juwri\Modules\Ai\Contracts\Ai;
use Juwri\Modules\Ai\Contracts\AiProviderChain;
use Juwri\Modules\Ordering\Models\OrderVoiceNote;

/**
 * ZERO-TOUCH Track B3 — fill the features-5 `order_voice_notes.transcript`
 * seam: after a customer voice note attaches to an order, transcribe it via
 * the STT-capable provider chain (Groq/OpenAI BYOK) so staff can READ the
 * request under the audio player (dashboard, KDS, POS hub, staff PWA).
 *
 * Absolutely best-effort: a missing provider, deleted file, disabled toggle or
 * upstream failure is a silent no-op — transcription must NEVER block or fail
 * an order (the audio player stays either way).
 */
class TranscribeVoiceNote implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    /** Keep staff surfaces sane: transcripts are short utterances, not essays. */
    public const MAX_TRANSCRIPT_CHARS = 2000;

    public function __construct(public int $voiceNoteId)
    {
        $this->onQueue('heavy'); // same dedicated queue as the other AI jobs
    }

    /**
     * Owner toggle `voice_note_transcription` — default ON (missing option
     * counts as enabled); the capability check below makes the EFFECTIVE
     * default "on when a transcription-capable provider is configured".
     */
    public static function enabledFor(int $storeId): bool
    {
        return (string) (store_options($storeId, 'voice_note_transcription') ?? '') !== '0';
    }

    public function handle(Ai $ai, AiProviderChain $factory): void
    {
        // Survive the dispatchAfterResponse fallback (no-worker hosts) where
        // web max_execution_time would kill us mid-call. CLI already unlimited.
        if (PHP_SAPI !== 'cli') {
            @set_time_limit($this->timeout);
        }

        try {
            $note = OrderVoiceNote::find($this->voiceNoteId);
            if (! $note || trim((string) $note->transcript) !== '') {
                return; // gone or already transcribed
            }

            if (! self::enabledFor((int) $note->store_id)) {
                return; // owner turned voice-note transcription off
            }

            if (empty($factory->transcribeChain((int) $note->store_id))) {
                return; // no capable provider — clean no-op, the seam stays open
            }

            $disk = Storage::disk('local');
            $rel = $note->relativePath();
            if (! $disk->exists($rel)) {
                return; // pruned/missing file
            }

            $ownerId = (int) (Store::whereKey($note->store_id)->value('user_id') ?: 0);

            $result = $ai->transcribe(
                $disk->path($rel),
                array_filter([
                    'filename' => $note->filename,
                    'mime' => $note->mime,
                    'duration_seconds' => $note->duration_seconds,
                ]),
                userId: $ownerId ?: null,
                storeId: (int) $note->store_id,
                feature: 'voice_note',
            );

            $text = trim($result->text);
            if ($text === '') {
                return; // silence/unintelligible — leave the seam null
            }

            $note->forceFill(['transcript' => mb_substr($text, 0, self::MAX_TRANSCRIPT_CHARS)])->save();
        } catch (\Throwable $e) {
            // Failures are logged, never rethrown — orders must never notice.
            Log::warning('[voice-note] transcription failed: '.$e->getMessage(), [
                'voice_note_id' => $this->voiceNoteId,
            ]);
        }
    }
}
