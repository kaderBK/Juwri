<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Services\Telephony\Bridge;

use Juwri\Modules\Agents\Models\PhoneLine;
use Juwri\Modules\Agents\Services\Telephony\VoiceSession;

/**
 * V-05: Telnyx AI text-relay codec (Mode A). Pure and socket-free: it turns one
 * inbound text/event JSON frame into at most one outbound reply frame, delegating
 * caller utterances to {@see VoiceSession::turn()}.
 *
 * Documented shapes (read 2026-10-12):
 *  - Media Streaming over WebSockets:
 *    https://developers.telnyx.com/docs/voice/programmable-voice/media-streaming
 *  - AI Assistants (realtime conversations + conversation event stream):
 *    https://developers.telnyx.com/docs/voice/ai-assistants
 *
 * Frames are bare JSON discriminated by `type` (AI assistant vocabulary) or `event`
 * (media-streaming vocabulary); both are accepted. The AI stream is beta and its event
 * set grows over time, so malformed JSON, unknown types and non-user items are ignored
 * by returning null — decode() never throws.
 */
final class TelnyxRelayCodec
{
    /** Outbound frame type used to hand the agent's reply back to Telnyx. */
    public const REPLY_TYPE = 'conversation.item.create';

    private ?string $callControlId = null;

    private ?string $from = null;

    private bool $started = false;

    private bool $ended = false;

    public function __construct(
        private PhoneLine $line,
        private ?string $callSid = null,
    ) {}

    /** The Telnyx call_control_id captured from a call-initiated frame, if any. */
    public function callControlId(): ?string
    {
        return $this->callControlId;
    }

    /** The caller's number captured from a call-initiated frame, if any. */
    public function callerNumber(): ?string
    {
        return $this->from;
    }

    /** True once a call-initiated frame has been seen. */
    public function started(): bool
    {
        return $this->started;
    }

    /**
     * Decode one inbound frame and return the outbound reply frame, or null when the
     * frame is malformed, unknown, informational, or terminal with nothing to send.
     */
    public function decode(string $raw): ?string
    {
        $frame = json_decode($raw, true);
        if (! is_array($frame)) {
            return null; // malformed JSON (or non-object JSON) is ignored, never throws
        }

        $type = $frame['type'] ?? $frame['event'] ?? null;
        if (! is_string($type) || $type === '') {
            return null;
        }

        return match ($type) {
            // Call-initiated: media-streaming `start`, AI `telnyx.call.answered`, or
            // the AI event stream `session.created` (call ids live in the payload).
            'start', 'telnyx.call.answered', 'session.created' => $this->start($frame, $type),
            // Caller utterance: AI event-stream item, realtime transcription, or a
            // generic text-relay transcript frame (shape assumed, see extractUtterance()).
            'conversation.item.created',
            'conversation.item.input_audio_transcription.completed',
            'transcript',
            'transcription' => $this->utterance($frame, $type),
            // Call end: media-streaming `stop` or the AI hangup / session-end events.
            'stop', 'telnyx.call.hangup', 'session.ended' => $this->stop(),
            default => null, // beta stream: ignore event types we do not know
        };
    }

    /**
     * Capture call state. Media streaming nests the payload under `start`, the AI event
     * stream under `session` (the docs say it carries the call identifiers), and the
     * `telnyx.call.answered` shape (assumed) at the top level.
     *
     * @param  array<string, mixed>  $frame
     */
    private function start(array $frame, string $type): null
    {
        $payload = match ($type) {
            'start' => is_array($frame['start'] ?? null) ? $frame['start'] : [],
            'session.created' => is_array($frame['session'] ?? null) ? $frame['session'] : [],
            default => $frame,
        };

        $callId = $payload['call_control_id'] ?? $frame['call_control_id'] ?? null;
        if (is_string($callId) && $callId !== '') {
            $this->callControlId = $callId;
        }
        $from = $payload['from'] ?? $frame['from'] ?? null;
        if (is_string($from) && $from !== '') {
            $this->from = $from;
        }
        $this->started = true;

        return null; // a call-initiated frame has no reply
    }

    /**
     * Drive the agent for one caller utterance and wrap the reply.
     *
     * @param  array<string, mixed>  $frame
     */
    private function utterance(array $frame, string $type): ?string
    {
        $text = $this->extractUtterance($frame, $type);
        if ($text === null || trim($text) === '') {
            return null;
        }

        $callSid = $this->callSid ?? $this->callControlId;
        if ($callSid === null || $callSid === '') {
            return null; // no call context yet — drop rather than misattribute
        }

        // TODO(V-09): transcript digit filter hook
        $reply = VoiceSession::turn($this->line, $callSid, $this->from, $text);

        return $this->replyFrame($reply);
    }

    /**
     * Pull caller text out of the accepted transcript frames. Only `user` items are
     * read; assistant/tool items are ignored so the bridge never loops its own replies.
     *
     * @param  array<string, mixed>  $frame
     */
    private function extractUtterance(array $frame, string $type): ?string
    {
        return match ($type) {
            'conversation.item.created' => $this->itemText(
                is_array($frame['item'] ?? null) ? $frame['item'] : []
            ),
            // Realtime conversations: { "type": "...", "transcript": "..." }.
            'conversation.item.input_audio_transcription.completed' => $this->stringField($frame, 'transcript'),
            // Assumed text-relay transcript shapes (the docs do not fix one): the text
            // sits either at the top level or nested under `transcript`/`transcription`.
            'transcript', 'transcription' => $this->stringField($frame, 'text')
                ?? $this->nestedText($frame, 'transcript')
                ?? $this->nestedText($frame, 'transcription'),
            default => null,
        };
    }

    /**
     * Concatenate the text parts of a `user` message item.
     *
     * @param  array<string, mixed>  $item
     */
    private function itemText(array $item): ?string
    {
        if (($item['role'] ?? null) !== 'user') {
            return null;
        }
        $content = $item['content'] ?? null;
        if (! is_array($content)) {
            return null;
        }
        $text = '';
        foreach ($content as $part) {
            if (is_array($part) && is_string($part['text'] ?? null)) {
                $text .= $part['text'];
            }
        }

        return $text;
    }

    /**
     * @param  array<string, mixed>  $frame
     */
    private function stringField(array $frame, string $key): ?string
    {
        $value = $frame[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $frame
     */
    private function nestedText(array $frame, string $key): ?string
    {
        $nested = $frame[$key] ?? null;

        return is_array($nested) && is_string($nested['text'] ?? null) ? $nested['text'] : null;
    }

    /**
     * Wrap the agent reply as the outbound text frame. Assumed shape, modelled on the
     * documented `conversation.item.create` injection (the assistant event stream reads
     * `text` content parts):
     * {"type":"conversation.item.create","item":{"type":"message","role":"assistant",
     *  "content":[{"type":"text","text":"<reply>"}]}}.
     */
    private function replyFrame(string $reply): ?string
    {
        $frame = json_encode([
            'type' => self::REPLY_TYPE,
            'item' => [
                'type' => 'message',
                'role' => 'assistant',
                'content' => [
                    ['type' => 'text', 'text' => $reply],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $frame === false ? null : $frame;
    }

    /**
     * End the call record. The call SID is captured from the start frame, so a stop may
     * arrive even if the explicit bridge token claim was absent.
     */
    private function stop(): null
    {
        $callSid = $this->callSid ?? $this->callControlId;
        if (! $this->ended && $callSid !== null && $callSid !== '') {
            $this->ended = true;
            VoiceSession::finalize($callSid, 'hangup');
        }

        return null; // nothing to send on hangup
    }
}
