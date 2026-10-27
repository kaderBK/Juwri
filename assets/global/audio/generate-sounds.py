#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-only
# Copyright (c) JUWRI LTD

"""Generate juwri's own notification sounds (no third-party audio).

Every sound is synthesised from sine partials with a short attack and an exponential
decay, rendered to a 44.1 kHz mono WAV with the Python standard library and encoded to
MP3 with ffmpeg (libmp3lame, metadata stripped). Deterministic: re-running produces the
same audio. File names are kept from the earlier sound pack so stored settings
(`notification_sounds` ids) keep resolving.

Usage:  python3 generate-sounds.py [out_dir]     (default: this folder; needs ffmpeg)
"""

import array
import math
import os
import subprocess
import sys
import tempfile
import wave

RATE = 44100

# Timbres: (partial ratio, relative amplitude, extra decay factor for that partial).
TIMBRES = {
    'bell': [(1.0, 1.0, 1.0), (2.0, 0.40, 1.4), (3.0, 0.15, 2.0), (4.2, 0.08, 2.6)],
    'glass': [(1.0, 1.0, 1.0), (2.76, 0.30, 1.8), (5.40, 0.10, 2.8)],
    'marimba': [(1.0, 1.0, 1.0), (4.0, 0.25, 3.0), (10.0, 0.05, 5.0)],
    'soft': [(1.0, 1.0, 1.0), (2.0, 0.15, 1.5)],
    'pluck': [(1.0, 1.0, 1.0), (2.0, 0.50, 1.5), (3.0, 0.33, 2.0), (4.0, 0.25, 2.5)],
    'organ': [(1.0, 1.0, 1.0), (2.0, 0.50, 1.0), (3.0, 0.25, 1.0), (0.5, 0.20, 1.0)],
}


def hz(midi):
    return 440.0 * 2 ** ((midi - 69) / 12.0)


def render(notes, length, timbre, decay, sweep=None):
    """notes: list of (start_s, midi, amp). sweep: (from_hz, to_hz, seconds) glide per note."""
    n = int(length * RATE)
    buf = [0.0] * n
    partials = TIMBRES[timbre]
    attack = 0.006
    for start, midi, amp in notes:
        s0 = int(start * RATE)
        f0 = hz(midi)
        # Tail until the envelope is below -60 dB.
        tail = min(n - s0, int((6.9 / decay + attack) * RATE))
        for ratio, pamp, pdec in partials:
            phase = 0.0
            d = decay * pdec
            for k in range(tail):
                t = k / RATE
                f = f0 * ratio
                if sweep:
                    a, b, dur = sweep
                    g = min(t / dur, 1.0)
                    f = (a + (b - a) * g) * ratio
                phase += 2 * math.pi * f / RATE
                env = (t / attack) if t < attack else math.exp(-d * (t - attack))
                buf[s0 + k] += amp * pamp * env * math.sin(phase)
    # Fade the last 150 ms and normalise to -2 dBFS.
    fade = int(0.15 * RATE)
    for k in range(fade):
        buf[n - fade + k] *= 1 - k / fade
    peak = max(abs(x) for x in buf) or 1.0
    gain = 0.79 / peak
    return array.array('h', (int(x * gain * 32767) for x in buf))


def seq(pattern, step, start=0.0, amp=1.0, accents=()):
    """pattern: list of midi numbers (None = rest) played every `step` seconds."""
    out = []
    for i, m in enumerate(pattern):
        if m is None:
            continue
        out.append((start + i * step, m, amp * (1.0 if i in accents or not accents else 0.75)))
    return out


def twice(pattern, step, gap, **kw):
    first = seq(pattern, step, **kw)
    second = seq(pattern, step, start=len(pattern) * step + gap, **kw)
    return first + second, (len(pattern) * step) * 2 + gap


SOUNDS = {}

# --- The five defaults: short, clearly different chimes -------------------------------
# order_new (repeats while an order waits): bright rising four-note bell arpeggio.
SOUNDS['notification_message-notification-alert-7-331719.mp3'] = dict(
    notes=seq([79, 84, 88, 91], 0.11) + [(0.44, 96, 0.6)], length=1.6, timbre='bell', decay=4.5)
# review: soft marimba "tu-dum", falling fourth.
SOUNDS['universfield-new-notification-036-485897.mp3'] = dict(
    notes=[(0.0, 81, 1.0), (0.16, 76, 0.9)], length=1.0, timbre='marimba', decay=7.0)
# order_ready + dispute: glassy rising fifth, two strikes.
SOUNDS['universfield-new-notification-061-494263.mp3'] = dict(
    notes=[(0.0, 74, 1.0), (0.2, 81, 1.0)], length=1.3, timbre='glass', decay=5.0)
# waitlist: single "bubble" pluck gliding upwards.
SOUNDS['universfield-new-notification-066-494545.mp3'] = dict(
    notes=[(0.0, 72, 1.0)], length=0.9, timbre='soft', decay=6.0, sweep=(420.0, 980.0, 0.18))

# --- Other short tones ------------------------------------------------------------------
SOUNDS['universfield-clean-mobile-tone-454836.mp3'] = dict(
    notes=[(0.0, 88, 1.0), (0.09, 88, 0.8), (0.18, 93, 1.0)], length=1.0, timbre='soft', decay=9.0)
_notes, _dur = twice([62, 65, 69, 68, None, 65, 62, None], 0.26, 0.4)
SOUNDS['universfield-mysterious-ringtone-494551.mp3'] = dict(notes=_notes, length=_dur + 1.0, timbre='glass', decay=2.2)

# --- Ringtones: two phrases, each tone its own melody / timbre / tempo --------------------
RINGTONES = {
    'universfield-ringtone-023-376906.mp3': ([72, 76, 79, 76, 72, 76, 79, 84], 0.16, 'marimba', 6.0),
    'universfield-ringtone-030-437513.mp3': ([67, 71, 74, 79, None, 74, 79, None], 0.22, 'bell', 3.5),
    'universfield-ringtone-031-437514.mp3': ([79, 74, 71, 67, None, 71, 74, 79], 0.2, 'pluck', 5.0),
    'universfield-ringtone-038-485905.mp3': ([69, 72, 76, 72, 69, 72, 76, 81], 0.18, 'glass', 4.0),
    'universfield-ringtone-047-494553.mp3': ([60, 64, 67, 72, 67, 64, 60, None], 0.2, 'organ', 3.0),
    'universfield-ringtone-055-494939.mp3': ([76, 76, None, 76, 72, 76, 79, None], 0.17, 'pluck', 6.0),
    'universfield-ringtone-062-496262.mp3': ([74, 77, 81, 77, 74, 72, 74, None], 0.19, 'marimba', 5.0),
    'universfield-ringtone-065-496263.mp3': ([84, 81, 79, 76, 79, 81, 84, None], 0.14, 'bell', 5.0),
    'universfield-ringtone-066-496266.mp3': ([65, 69, 72, 77, None, 72, 77, 81], 0.21, 'soft', 3.0),
    'universfield-ringtone-067-496273.mp3': ([71, 74, 78, 83, 78, 74, 71, None], 0.15, 'glass', 5.0),
    'universfield-ringtone-070-496271.mp3': ([64, 67, 71, 67, 64, 62, 64, None], 0.23, 'organ', 2.5),
    'universfield-ringtone-071-496270.mp3': ([88, None, 83, None, 88, 91, 88, None], 0.15, 'bell', 6.0),
    'universfield-ringtone-072-496297.mp3': ([70, 74, 77, 82, 77, 74, 70, None], 0.18, 'pluck', 4.5),
    'universfield-ringtone-073-496299.mp3': ([81, 79, 76, 74, 76, 79, 81, 86], 0.13, 'marimba', 7.0),
    'universfield-ringtone-083-496368.mp3': ([63, 67, 70, 75, None, 70, 67, 63], 0.24, 'soft', 2.8),
}
for name, (pattern, step, timbre, decay) in RINGTONES.items():
    notes, dur = twice(pattern, step, 0.35)
    SOUNDS[name] = dict(notes=notes, length=dur + 1.2, timbre=timbre, decay=decay)


def main():
    out_dir = sys.argv[1] if len(sys.argv) > 1 else os.path.dirname(os.path.abspath(__file__))
    for name, spec in sorted(SOUNDS.items()):
        pcm = render(spec['notes'], spec['length'], spec['timbre'], spec['decay'], spec.get('sweep'))
        with tempfile.NamedTemporaryFile(suffix='.wav', delete=False) as tmp:
            wav_path = tmp.name
        with wave.open(wav_path, 'wb') as w:
            w.setnchannels(1)
            w.setsampwidth(2)
            w.setframerate(RATE)
            w.writeframes(pcm.tobytes())
        subprocess.run(
            ['ffmpeg', '-y', '-loglevel', 'error', '-i', wav_path, '-c:a', 'libmp3lame', '-b:a', '96k',
             '-map_metadata', '-1', '-id3v2_version', '0', '-write_xing', '0', os.path.join(out_dir, name)],
            check=True)
        os.unlink(wav_path)
        print(name)


if __name__ == '__main__':
    main()
